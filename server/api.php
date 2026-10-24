<?php
/** Khushrang API. Requires PHP 8.1+, PDO MySQL, fileinfo. Configuration is outside the web root. */
declare(strict_types=1);
define('KHUSHRANG_INTERNAL',true);
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
$action = $_GET['action'] ?? '';
function fail(string $message, int $code = 400,?string $errorCode=null): never { http_response_code($code); echo json_encode(array_filter(['error'=>$message,'code'=>$errorCode],static fn($v)=>$v!==null)); exit; }
if (($_SERVER['HTTPS'] ?? '') !== 'on' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) fail('HTTPS is required.', 403);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin !== ((($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . $host)) fail('Same-origin requests required.',403);
}
session_set_cookie_params(['httponly'=>true,'secure'=>(($_SERVER['HTTPS'] ?? '') === 'on'),'samesite'=>'Strict','path'=>'/']);
if(!session_start())fail('PHP session storage is unavailable. Check PHP session settings in Hostinger.',503,'SESSION_UNAVAILABLE');
require __DIR__.'/config-loader.php';
$configPath=khushrang_config_path(__DIR__);
try {
$config = khushrang_load_config($configPath);
$db = new PDO($config['dsn'], $config['user'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET time_zone='+00:00'");
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required.',405);
    $input = json_decode(file_get_contents('php://input'),true) ?: [];
    // A shared MySQL rate limit also applies when cookies are discarded.
    $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $q=$db->prepare('SELECT attempts, last_attempt FROM login_attempts WHERE ip_hash=?');$q->execute([$key]);$attempt=$q->fetch();
    if ($attempt && (int)$attempt['attempts'] >= 8 && strtotime($attempt['last_attempt']) > time()-900) fail('Too many attempts. Try again in 15 minutes.',429);
    $q=$db->prepare('SELECT id,password_hash,name,email,role,must_change_password,session_version FROM users WHERE email=? AND active=1');$q->execute([strtolower(trim((string)($input['email']??'')))]);$user=$q->fetch();
    if (!$user || !password_verify((string)($input['password']??''),$user['password_hash'])) {
        $q=$db->prepare('INSERT INTO login_attempts(ip_hash,attempts,last_attempt) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt < DATE_SUB(NOW(), INTERVAL 15 MINUTE),1,attempts+1),last_attempt=NOW()');$q->execute([$key]);fail('Invalid email or password.',401);
    }
    $q=$db->prepare('DELETE FROM login_attempts WHERE ip_hash=?');$q->execute([$key]);session_regenerate_id(true);$_SESSION['user_id']=$user['id'];$_SESSION['last_seen']=time();$_SESSION['session_version']=(int)$user['session_version'];
    require_once __DIR__.'/auth-policy.php';echo json_encode(auth_public_user($user));exit;
}
if (empty($_SESSION['user_id']) || (int)($_SESSION['last_seen']??0)<time()-3600) { session_destroy();fail('Please sign in.',401); }
$q=$db->prepare('SELECT id,name,email,password_hash,role,must_change_password,session_version FROM users WHERE id=? AND active=1');$q->execute([$_SESSION['user_id']]);$user=$q->fetch();if(!$user||(int)$user['session_version']!==(int)($_SESSION['session_version']??-1)){session_destroy();fail('Account changed or disabled. Sign in again.',401);}
$_SESSION['last_seen']=time();
if ($action==='logout') {if($_SERVER['REQUEST_METHOD']!=='POST')fail('POST required.',405);session_destroy();echo '{}';exit;}
require_once __DIR__.'/auth-policy.php';
require __DIR__.'/auth.php';
if(str_starts_with($action,'integration_')&&!auth_policy($user['role'])['integrations'])fail('Integration administrator permission required.',403);
require __DIR__.'/integrations-admin.php';
require __DIR__.'/invoice-generator.php';
require_once __DIR__.'/records-service.php';
$kinds=['events','clients','vendors','invoices','payments','expenses','tasks','media'];
if ($action==='data') {
    $out=array_fill_keys($kinds,[]);
    foreach($db->query('SELECT id, kind, payload FROM records ORDER BY created_at DESC') as $r) {if(isset($out[$r['kind']])&&in_array($r['kind'],auth_policy($user['role'])['read'],true))$out[$r['kind']][]=array_merge(json_decode($r['payload'],true),['id'=>$r['id']]);}
    echo json_encode($out);exit;
}
$storage=rtrim($config['storage_path'],'/');
if($action==='file') {
    auth_require($user,'media');
    $q=$db->prepare("SELECT payload FROM records WHERE kind='media' AND id=?");$q->execute([$_GET['id']??'']);$r=$q->fetch();if(!$r)fail('File not found.',404);
    $m=json_decode($r['payload'],true);$file=$storage.'/'.basename($m['stored']);if(!is_file($file))fail('File not found.',404);
    header('Content-Type: '.$m['type']);header('Content-Disposition: inline; filename="'.preg_replace('/[^a-zA-Z0-9._-]/','_',$m['name']).'"');header('Content-Length: '.filesize($file));header("Content-Security-Policy: default-src 'none'; sandbox");readfile($file);exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST')fail('POST required.',405);
if($action==='upload') {
    auth_require($user,'media','write');
    $f=$_FILES['file']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK)fail('Upload failed. Check PHP upload limits.');
    if($f['size']>25*1024*1024)fail('Maximum file size is 25 MB.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','application/pdf'=>'pdf'];if(!isset($types[$mime]))fail('Unsupported file type.');
    if(!is_dir($storage)||!is_writable($storage))fail('Private storage is not writable.',503);
    $id=bin2hex(random_bytes(16));$stored=$id.'.'.$types[$mime];
    if(!move_uploaded_file($f['tmp_name'],$storage.'/'.$stored))fail('Could not store file.',500);
    $row=['id'=>$id,'name'=>mb_substr(basename($f['name']),0,200),'type'=>$mime,'stored'=>$stored,'size'=>(string)$f['size'],'date'=>date('Y-m-d')];
    try{$q=$db->prepare("INSERT INTO records(id,kind,payload) VALUES(?,'media',?)");$q->execute([$id,json_encode($row)]);}catch(Throwable $e){unlink($storage.'/'.$stored);throw $e;}echo json_encode(['id'=>$id]);exit;
}
$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))fail('Invalid JSON.');
$kind=$input['kind']??'';if(!in_array($kind,$kinds,true))fail('Unknown record type.');
auth_require($user,$kind,'write');
try {
 if($action==='delete'){echo json_encode(business_delete($db,$kind,(string)($input['id']??''),$config));exit;}
 if($action==='save'){if(!is_array($input['row']??null))fail('Invalid record.');$row=business_save($db,$kind,$input['row']);echo json_encode(['id'=>$row['id']]);exit;}
}catch(IntegrationError $e){fail($e->getMessage(),$e->httpStatus);}
fail('Unknown action.',404);
} catch(Throwable $e) { [$code,$message,$status]=khushrang_failure($e);error_log('Khushrang API ['.$code.']: '.$message);fail($message,$status,$code); }
