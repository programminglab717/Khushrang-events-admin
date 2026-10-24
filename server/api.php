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
$recordId=$action==='delete'?($input['id']??''):($input['row']['id']??'');
if($recordId==='invoice-template-default'||str_starts_with((string)$recordId,'invoice-number-'))fail('That record ID is reserved for invoice settings.',409);
if((str_starts_with((string)$recordId,'INV-GEN-')&&$kind!=='invoices')||(str_starts_with((string)$recordId,'PAY-GEN-')&&$kind!=='payments'))fail('That record ID belongs to another record type.',409);
if($action==='delete') {
    $db->beginTransaction();
    $q=$db->prepare('SELECT payload FROM records WHERE id=? AND kind=? FOR UPDATE');$q->execute([$input['id']??'',$kind]);$old=$q->fetch();
    if(!$old){$db->rollBack();fail('Record not found.',404);}
    $row=json_decode($old['payload'],true);
    if(($row['managed_by']??'')==='invoice_generator'){$db->rollBack();fail('Edit the amount paid in the linked invoice generator instead.',409);}
    if($kind==='invoices'&&isset($row['generator_details'])){$q=$db->prepare("DELETE FROM records WHERE id=? AND kind='payments'");$q->execute(['PAY-GEN-'.hash('sha256',$input['id'])]);}
    $q=$db->prepare('DELETE FROM records WHERE id=? AND kind=?');$q->execute([$input['id'],$kind]);$db->commit();
    if($kind==='media'){$file=$storage.'/'.basename($row['stored']);if(is_file($file))unlink($file);}echo '{}';exit;
}
if($action==='save') {
    if(($kind==='invoices'&&str_starts_with($input['row']['id']??'','INV-GEN-'))||($kind==='payments'&&str_starts_with($input['row']['id']??'','PAY-GEN-')))fail('Use the invoice generator to update generated invoices and their linked payments.',409);
    $q=$db->prepare('SELECT payload FROM records WHERE id=? AND kind=?');$q->execute([$input['row']['id']??'',$kind]);$protected=$q->fetch();$protected=$protected?json_decode($protected['payload'],true):[];
    if(isset($protected['generator_details']))fail('Edit this invoice using the invoice generator.',409);
    if(($protected['managed_by']??'')==='invoice_generator')fail('Edit the amount paid in the linked invoice generator instead.',409);
    if($kind==='media')fail('Use the upload endpoint.');
    $row=$input['row']??[];$id=$row['id']??'';if(!is_string($id)||!preg_match('/^[A-Za-z0-9-]{1,80}$/',$id))fail('Invalid record ID.');
    $fields=['events'=>['name','type','date','location','client','amount','status'],'clients'=>['name','email','phone','status','email_opt_in'],'vendors'=>['name','type','phone','email','status'],'invoices'=>['name','client','date','amount','status'],'payments'=>['name','client','date','amount','status'],'expenses'=>['name','type','date','amount','status'],'tasks'=>['name','client','date','status']];
    $states=['events'=>['Inquiry','Planning','Confirmed','Completed','Cancelled'],'clients'=>['Active','Inactive'],'vendors'=>['Active','Preferred','Inactive'],'invoices'=>['Draft','Sent','Paid','Overdue','Cancelled'],'payments'=>['Received','Pending'],'expenses'=>['Pending','Paid'],'tasks'=>['To do','In progress','Done']];
    $clean=['id'=>$id];foreach($fields[$kind] as $f){$v=$row[$f]??'';if(!is_string($v)||strlen($v)>2000)fail('Invalid field: '.$f);$clean[$f]=trim($v);}
    if($kind==='clients'){if($clean['email_opt_in']==='')$clean['email_opt_in']='No';if(!in_array($clean['email_opt_in'],['Yes','No'],true))fail('Invalid email consent.');}
    if($clean['name']==='')fail('Name is required.');
    if(!in_array($clean['status'],$states[$kind],true))fail('Invalid status.');
    if(isset($clean['amount'])&&(!is_numeric($clean['amount'])||(float)$clean['amount']<0||(float)$clean['amount']>999999999999))fail('Invalid amount.');
    if(isset($clean['date'])){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$clean['date']);if(!$d||$d->format('Y-m-d')!==$clean['date'])fail('Invalid date.');}
    if(!empty($clean['email'])&&!filter_var($clean['email'],FILTER_VALIDATE_EMAIL))fail('Invalid email.');
    $q=$db->prepare('INSERT INTO records(id,kind,payload) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=IF(kind=VALUES(kind),VALUES(payload),payload),updated_at=NOW()');$q->execute([$id,$kind,json_encode($clean)]);echo json_encode(['id'=>$id]);exit;
}
fail('Unknown action.',404);
} catch(Throwable $e) { [$code,$message,$status]=khushrang_failure($e);error_log('Khushrang API ['.$code.']: '.$message);fail($message,$status,$code); }
