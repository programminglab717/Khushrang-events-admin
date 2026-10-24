<?php
// CLI only; requires a disposable database. No real outbound traffic: transport is injected.
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('INTEGRATION_TEST_ALLOW_MUTATIONS')!=='1')exit("Use a disposable database and set INTEGRATION_TEST_ALLOW_MUTATIONS=1.\n");
require __DIR__.'/../server/integrations-lib.php';
$config=require getenv('KHUSHRANG_CONFIG');$db=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec("SET time_zone='+00:00'");
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejected(callable $fn,int $status):void{try{$fn();}catch(IntegrationError $e){check($e->httpStatus===$status,'Unexpected rejection status');return;}throw new RuntimeException('Expected rejection.');}
$source='unit-'.bin2hex(random_bytes(5));$ids=[];$deliveryIds=[];
$receive=function(string $type,array $input)use($db,$source){return integration_receive($db,$source,$type,$input,json_encode($input));};
$enqueue=function(string $client,string $purpose='transactional',?string $key=null)use($db,$config,&$deliveryIds){$r=integration_enqueue($db,['client_id'=>$client,'purpose'=>$purpose,'idempotency_key'=>$key??bin2hex(random_bytes(16))],$config);$deliveryIds[]=$r['id'];return $r;};
$state=function(string $id)use($db){$q=$db->prepare('SELECT * FROM integration_outbox WHERE id=?');$q->execute([$id]);return $q->fetch();};
try{
 $input=['event_id'=>'client-1','external_id'=>'contact-1','name'=>'Integration fixture','email'=>$source.'@example.test','marketing_consent'=>true];
 $a=$receive('clients',$input);$id=$a['client_id'];$ids[]=$id;check(!$a['duplicate'],'First delivery marked duplicate');check($receive('clients',$input)['duplicate'],'Replay not deduplicated');
 rejected(fn()=>$receive('clients',array_merge($input,['name'=>'Different'])),409);
 rejected(fn()=>$receive('clients',array_merge($input,['event_id'=>'bad-email','email'=>'invalid'])),400);
 rejected(fn()=>$receive('outreach',['event_id'=>'bad-time','client_id'=>$id,'recipient_email'=>$source.'@example.test','message_id'=>'msg','status'=>'sent','occurred_at'=>'2026-02-30T00:00:00Z']),400);
 $first=$enqueue($id,'marketing','dedupe-'.$source);check($enqueue($id,'marketing','dedupe-'.$source)['id']===$first['id'],'Outbound queue duplicated');rejected(fn()=>$enqueue($id,'transactional','dedupe-'.$source),409);
 $calls=0;$ok=function($destination,$body,$delivery)use(&$calls){$calls++;$p=json_decode($body,true);check($p['delivery_id']===$delivery,'Missing stable delivery ID');check(!isset($p['client']['status']),'Payload leaks nonallowlisted fields');return 202;};
 integration_work($db,$config,1,$ok);check($state($first['id'])['status']==='accepted','2xx not accepted');check($calls===1,'Unexpected call count');integration_work($db,$config,1,$ok);check($calls===1,'Delivered item resent');
 $retry=$enqueue($id);integration_work($db,$config,1,fn()=>503);check($state($retry['id'])['status']==='waiting_retry','5xx not retried');$q=$db->prepare('UPDATE integration_outbox SET next_attempt_at=UTC_TIMESTAMP() WHERE id=?');$q->execute([$retry['id']]);integration_work($db,$config,1,$ok);check($state($retry['id'])['status']==='accepted','Retry did not recover');
 $failed=$enqueue($id);integration_work($db,$config,1,fn()=>400);check($state($failed['id'])['status']==='failed','4xx not permanent');
 $lease=$enqueue($id);$q=$db->prepare("UPDATE integration_outbox SET status='processing',locked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),lock_token='old',attempts=1 WHERE id=?");$q->execute([$lease['id']]);integration_work($db,$config,1,$ok);check($state($lease['id'])['status']==='accepted','Abandoned lease not reclaimed');
 $blocked=$enqueue($id);$update=['event_id'=>'unsubscribe-1','client_id'=>$id,'recipient_email'=>$source.'@example.test','message_id'=>'msg-1','status'=>'unsubscribed','occurred_at'=>'2026-01-01T00:00:00Z'];$receive('outreach',$update);check($receive('outreach',$update)['duplicate'],'Outreach replay not deduplicated');
 $before=$calls;integration_work($db,$config,1,$ok);check($calls===$before&&$state($blocked['id'])['status']==='failed','Suppressed client sent');rejected(fn()=>$enqueue($id),409);
 $receive('clients',array_merge($input,['event_id'=>'client-2','marketing_consent'=>true]));rejected(fn()=>$enqueue($id,'marketing'),409);
 $late=$update;$late['event_id']='late-delivery';$late['status']='delivered';$late['occurred_at']='2025-12-31T00:00:00Z';$receive('outreach',$late);rejected(fn()=>$enqueue($id),409);
 $no=$receive('clients',['event_id'=>'no-consent','external_id'=>'contact-2','name'=>'No consent','email'=>$source.'-no@example.test']);$ids[]=$no['client_id'];rejected(fn()=>$enqueue($no['client_id'],'marketing'),409);
 $changed=$enqueue($no['client_id']);$c=integration_client($db,$no['client_id']);$c['email']=$source.'-changed@example.test';$q=$db->prepare('UPDATE records SET payload=? WHERE id=?');$q->execute([json_encode($c),$no['client_id']]);$before=$calls;integration_work($db,$config,1,$ok);check($calls===$before&&$state($changed['id'])['status']==='failed','Changed recipient sent');
 foreach(['http://example.com/hook','https://localhost/hook','https://127.0.0.1/hook','https://user:pass@example.com/hook'] as $url)rejected(fn()=>integration_target(['url'=>$url,'allowed_hosts'=>['example.com','localhost','127.0.0.1']]),400);
 echo "PASS: inbound/outbound idempotency, payload validation, marketing consent, suppression and late events, 2xx delivery, 5xx retry, permanent 4xx, lease recovery, changed recipients, and unsafe URL rejection. Transport mocked; no real emails sent.\n";
}finally{
 foreach(array_unique($deliveryIds) as $id){$q=$db->prepare('DELETE FROM integration_outbox WHERE id=?');$q->execute([$id]);}
 foreach($ids as $id){$q=$db->prepare('DELETE FROM records WHERE id=?');$q->execute([$id]);}
 $q=$db->prepare('DELETE FROM integration_inbox WHERE source=?');$q->execute([$source]);$q=$db->prepare('DELETE FROM outreach_updates WHERE source=?');$q->execute([$source]);$q=$db->prepare('DELETE FROM client_suppressions WHERE email=?');$q->execute([$source.'@example.test']);
}
