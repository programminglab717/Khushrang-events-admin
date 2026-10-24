<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&!defined('KHUSHRANG_INTERNAL')){http_response_code(404);exit;}
require_once __DIR__.'/integrations-lib.php';
require_once __DIR__.'/records-service.php';
require_once __DIR__.'/connections.php';
if(str_starts_with($action,'integration_')) {
 try {
  if($action==='integration_overview'){
   if($_SERVER['REQUEST_METHOD']!=='GET')throw new IntegrationError('GET required.',405);
   $runtime=integration_runtime_config($db,$config);$tokens=[];
   foreach(connection_rows($db,'integration_token') as $t){unset($t['token_hash'],$t['_record_id']);$t['status']=$t['revoked_at']!==''?'Revoked':(strtotime($t['expires_at'])<=time()?'Expired':'Active');$q=$db->prepare('SELECT active,must_change_password,session_version,role FROM users WHERE id=?');$q->execute([$t['owner_id']]);$owner=$q->fetch();if($t['status']==='Active'&&(!$owner||!$owner['active']||$owner['must_change_password']||(int)$owner['session_version']!==(int)$t['owner_version']||!auth_policy($owner['role'])['integrations']))$t['status']='Owner changed';unset($t['owner_version']);$tokens[]=$t;}
   $hooks=[];foreach(connection_rows($db,'integration_webhook') as $h){$d=$runtime['webhooks'][$h['id']];$hooks[]=['id'=>$h['id'],'name'=>$h['name'],'url'=>$d['url']??'','host'=>$h['host'],'active'=>(bool)$h['active'],'has_secret'=>$h['has_secret'],'problem'=>$d['problem'],'last_test'=>$h['last_test']??null];}
   $legacy=array_map(static fn($c)=>['source'=>$c['source']??'','scopes'=>$c['scopes']??[],'configured'=>!empty($c['token_sha256']),'legacy'=>true],$config['inbound_connectors']??[]);
   $destination=$config['email_destination']??[];
   echo json_encode(['tokens'=>$tokens,'webhooks'=>$hooks,'connectors'=>$legacy,'destination'=>['configured'=>!empty($destination['url']),'host'=>parse_url($destination['url']??'',PHP_URL_HOST)?:''],'health'=>['worker_command'=>connection_worker_command(),'curl'=>function_exists('curl_init'),'private_writable'=>is_writable($config['private_directory']??''),'worker'=>($db->query("SELECT payload FROM records WHERE id='integration-runtime-worker'")->fetchColumn()?:null)],'deliveries'=>array_map(static function($r){$r['status']=connection_delivery_status($r['status']);return $r;},$db->query('SELECT id,client_id,destination,purpose,status,attempts,last_http_code,last_error,created_at,next_attempt_at FROM integration_outbox ORDER BY created_at DESC LIMIT 100')->fetchAll()),'inbound'=>$db->query('SELECT source,event_id,event_type,client_id,received_at FROM integration_inbox ORDER BY received_at DESC LIMIT 100')->fetchAll(),'outreach'=>$db->query('SELECT id,source,client_id,recipient_email,message_id,status,occurred_at,received_at FROM outreach_updates ORDER BY occurred_at DESC LIMIT 100')->fetchAll(),'mcp_activity'=>array_map(static fn($r)=>array_diff_key($r,['_record_id'=>true]),connection_rows($db,'mcp_audit',100)),'suppressed_count'=>(int)$db->query('SELECT COUNT(*) FROM client_suppressions')->fetchColumn()]);exit;
  }
  if($_SERVER['REQUEST_METHOD']!=='POST')throw new IntegrationError('POST required.',405);
  $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))throw new IntegrationError('Invalid JSON.');
  if($action==='integration_token_create'){echo json_encode(connection_token_create($db,$user,$input));exit;}
  if($action==='integration_token_revoke'){$id=integration_text($input,'id',32);$q=$db->prepare("SELECT id FROM records WHERE id=? AND kind='integration_token'");$q->execute(['integration-token-'.$id]);if(!$q->fetch())throw new IntegrationError('Token not found.',404);$q=$db->prepare("UPDATE records SET payload=JSON_SET(payload,'$.revoked_at',?) WHERE id=? AND kind='integration_token' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.revoked_at'))=''");$q->execute([gmdate('c'),'integration-token-'.$id]);echo '{}';exit;}
  if($action==='integration_webhook_save'){echo json_encode(connection_webhook_save($db,$config,$user,$input));exit;}
  if($action==='integration_webhook_disable'){$id=integration_text($input,'id',32);$q=$db->prepare("UPDATE records SET payload=JSON_SET(payload,'$.active',false,'$.version',?) WHERE id=? AND kind='integration_webhook'");$q->execute([bin2hex(random_bytes(16)),'integration-webhook-'.$id]);if(!$q->rowCount())throw new IntegrationError('Webhook not found.',404);echo '{}';exit;}
  if($action==='integration_webhook_test'){
   $id=integration_text($input,'id',32);$runtime=integration_runtime_config($db,$config);$d=connection_destination($runtime,$id);$requestId=bin2hex(random_bytes(16));$error=null;$code=0;
   try{$diagnostic=null;$code=integration_http($d,json_encode(['event'=>'integration.test','delivery_id'=>$requestId,'sent_at'=>gmdate('c'),'message'=>'Khushrang connection test; no customer data.']),$requestId,$diagnostic);if($code<200||$code>=300)$error=$code?'Receiver returned HTTP '.$code:($diagnostic??'Network, TLS or response-size error');}catch(IntegrationError $e){$error=$e->getMessage();}
   $test=['ok'=>$error===null,'http_code'=>$code,'error'=>$error,'at'=>gmdate('c')];$q=$db->prepare("UPDATE records SET payload=JSON_SET(payload,'$.last_test',JSON_EXTRACT(?, '$')) WHERE id=? AND kind='integration_webhook'");$q->execute([json_encode($test),'integration-webhook-'.$id]);echo json_encode($test);exit;
  }
  $runtime=integration_runtime_config($db,$config);
  if($action==='integration_enqueue'){echo json_encode(integration_enqueue($db,$input,$runtime));exit;}
  $id=integration_text($input,'id',32);
  if($action==='integration_send'){
   $q=$db->prepare("UPDATE integration_outbox SET status='waiting_retry',next_attempt_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('pending','retry','queued','waiting_retry','failed')");$q->execute([$id]);$q=$db->prepare('SELECT status FROM integration_outbox WHERE id=?');$q->execute([$id]);$state=$q->fetchColumn();if($state!=='waiting_retry')throw new IntegrationError('Only pending/retry/failed jobs may be sent.',409);
   integration_work($db,$runtime,1,null,$id);$q=$db->prepare('SELECT status,last_http_code,last_error FROM integration_outbox WHERE id=?');$q->execute([$id]);$result=$q->fetch();$result['status']=connection_delivery_status($result['status']);echo json_encode($result);exit;
  }
  if($action==='integration_retry'){$q=$db->prepare("UPDATE integration_outbox SET status='waiting_retry',next_attempt_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=? AND status IN ('retry','waiting_retry','failed')");$q->execute([$id]);}
  elseif($action==='integration_cancel'){$q=$db->prepare("UPDATE integration_outbox SET status='cancelled',last_error='Cancelled by administrator' WHERE id=? AND status IN ('pending','retry','queued','waiting_retry','failed')");$q->execute([$id]);}
  else throw new IntegrationError('Unknown action.',404);
  if(!$q->rowCount())throw new IntegrationError('Delivery cannot be changed in its current state.',409);echo '{}';exit;
 }catch(IntegrationError $e){fail($e->getMessage(),$e->httpStatus);}
}
