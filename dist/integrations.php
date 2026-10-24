<?php
// Server-to-server inbound webhooks. Never put the bearer token in website JavaScript.
declare(strict_types=1);
define('KHUSHRANG_INTERNAL',true);
ini_set('display_errors','0');header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
require __DIR__.'/integrations-lib.php';
require __DIR__.'/config-loader.php';
require __DIR__.'/auth-policy.php';
require __DIR__.'/records-service.php';
require __DIR__.'/connections.php';
try {
    if(($_SERVER['HTTPS']??'')!=='on'&&!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true))throw new IntegrationError('HTTPS required.',403);
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new IntegrationError('POST required.',405);
    $path=khushrang_config_path(__DIR__);$config=khushrang_load_config($path);
    $auth=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if(!preg_match('/^Bearer ([A-Za-z0-9_-]{32,256})$/i',$auth,$m))throw new IntegrationError('Invalid integration credentials.',401);
    $hash=hash('sha256',$m[1]);$connector=null;
    foreach($config['inbound_connectors']??[] as $c)if(!empty($c['token_sha256'])&&hash_equals($c['token_sha256'],$hash)){$connector=$c;break;}

    $db=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec("SET time_zone='+00:00'");
    if(!$connector){$token=connection_auth($db,$m[1],'api');$connector=['source'=>$token['source'],'scopes'=>$token['scopes']];}
    $source=$connector['source']??'';if(!preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$source))throw new IntegrationError('Invalid connector configuration.',503);
    $action=$_GET['action']??'';if(!in_array($action,['clients','outreach'],true))throw new IntegrationError('Unknown endpoint.',404);
    if(!in_array($action,$connector['scopes']??[],true))throw new IntegrationError('Connector scope denied.',403);
    if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')throw new IntegrationError('Content-Type must be application/json.',415);
    $raw=file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536)throw new IntegrationError('Maximum request size is 64 KB.',413);
    try{$input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new IntegrationError('Invalid JSON.');}if(!is_array($input)||array_is_list($input))throw new IntegrationError('JSON object required.');
    $bucket=(int)floor(time()/60);$q=$db->prepare('INSERT INTO integration_rate_limits(source,minute_bucket) VALUES(?,?) ON DUPLICATE KEY UPDATE requests=requests+1');$q->execute([$source,$bucket]);$q=$db->prepare('SELECT requests FROM integration_rate_limits WHERE source=? AND minute_bucket=?');$q->execute([$source,$bucket]);if((int)$q->fetchColumn()>120){header('Retry-After: 60');throw new IntegrationError('Connector request limit exceeded.',429);}
    $result=integration_receive($db,$source,$action,$input,$raw);http_response_code($result['duplicate']?200:202);echo json_encode($result);
}catch(IntegrationError $e){http_response_code($e->httpStatus);echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){[$code,$message,$status]=khushrang_failure($e);error_log('Khushrang inbound ['.$code.']: '.$message);http_response_code($status);echo json_encode(['error'=>$message,'code'=>$code]);}
