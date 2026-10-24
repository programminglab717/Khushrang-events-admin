<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&!defined('KHUSHRANG_INTERNAL')){http_response_code(404);exit;}
final class IntegrationError extends RuntimeException {
    public function __construct(string $message, public readonly int $httpStatus=400) { parent::__construct($message); }
}
function integration_text(array $input,string $key,int $max=190,bool $required=true): string {
    $value=$input[$key]??'';
    if(!is_string($value)||strlen($value)>$max||($required&&trim($value)===''))throw new IntegrationError('Invalid '.$key.'.');
    return trim($value);
}
function integration_client(PDO $db,string $id): array {
    $q=$db->prepare("SELECT payload FROM records WHERE kind='clients' AND id=?");$q->execute([$id]);$row=$q->fetch();
    if(!$row)throw new IntegrationError('Client not found.',404);
    return json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
}
function integration_receive(PDO $db,string $source,string $action,array $input,string $raw): array {
    $eventId=integration_text($input,'event_id',128);
    $hash=hash('sha256',$raw);
    $db->beginTransaction();
    try {
        // Insert first: the unique key serializes simultaneous retries of one event.
        $q=$db->prepare('INSERT IGNORE INTO integration_inbox(source,event_id,body_hash,event_type,client_id) VALUES(?,?,?,?,?)');
        $q->execute([$source,$eventId,$hash,$action,'']);
        if($q->rowCount()===0){
            $q=$db->prepare('SELECT body_hash,event_type,client_id FROM integration_inbox WHERE source=? AND event_id=? FOR UPDATE');$q->execute([$source,$eventId]);$old=$q->fetch();
            if(!hash_equals($old['body_hash'],$hash)||$old['event_type']!==$action)throw new IntegrationError('Event ID already used for a different request.',409);
            $db->commit();return ['accepted'=>true,'duplicate'=>true,'client_id'=>$old['client_id']];
        }
        if($action==='clients') {
            $externalId=integration_text($input,'external_id',128);
            $id='EXT-'.hash('sha256',$source.':'.$externalId);
            $email=strtolower(integration_text($input,'email',254));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new IntegrationError('Invalid email.');
            if(array_key_exists('marketing_consent',$input)&&!is_bool($input['marketing_consent']))throw new IntegrationError('marketing_consent must be a boolean.');
            $q=$db->prepare("SELECT payload FROM records WHERE kind='clients' AND id=? FOR UPDATE");$q->execute([$id]);$old=$q->fetch();
            $client=$old?json_decode($old['payload'],true):['id'=>$id,'status'=>'Active','email_opt_in'=>'No'];
            $client=array_merge($client,['name'=>integration_text($input,'name',190),'email'=>$email,'phone'=>integration_text($input,'phone',80,false)]);
            // Missing consent never upgrades permission; a suppression is never cleared by an import.
            if(array_key_exists('marketing_consent',$input))$client['email_opt_in']=$input['marketing_consent']?'Yes':'No';
            $q=$db->prepare("INSERT INTO records(id,kind,payload) VALUES(?,'clients',?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)");$q->execute([$id,json_encode($client,JSON_THROW_ON_ERROR)]);
        } elseif($action==='outreach') {
            $id=integration_text($input,'client_id',80);$client=integration_client($db,$id);
            $recipient=strtolower(integration_text($input,'recipient_email',254));if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))throw new IntegrationError('Invalid recipient_email.');
            $status=integration_text($input,'status',32);
            if(!in_array($status,['queued','sent','delivered','opened','clicked','replied','bounced','unsubscribed','complained'],true))throw new IntegrationError('Invalid outreach status.');
            $time=integration_text($input,'occurred_at',40);
            if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',$time))throw new IntegrationError('occurred_at must be an RFC3339 timestamp.');
            $d=new DateTimeImmutable($time);$errors=DateTimeImmutable::getLastErrors();if($errors&&($errors['warning_count']||$errors['error_count']))throw new IntegrationError('Invalid occurred_at.');
            if($d->getTimestamp()>time()+300)throw new IntegrationError('occurred_at cannot be in the future.');
            $occurred=$d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $q=$db->prepare('INSERT INTO outreach_updates(id,source,client_id,recipient_email,message_id,status,occurred_at) VALUES(?,?,?,?,?,?,?)');$q->execute([bin2hex(random_bytes(16)),$source,$id,$recipient,integration_text($input,'message_id',190),$status,$occurred]);
            if(in_array($status,['bounced','unsubscribed','complained'],true)){
                $q=$db->prepare('INSERT INTO client_suppressions(email,reason) VALUES(?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)');$q->execute([$recipient,$status]);
            }
        } else throw new IntegrationError('Unknown endpoint.',404);
        $q=$db->prepare('UPDATE integration_inbox SET client_id=? WHERE source=? AND event_id=?');$q->execute([$id,$source,$eventId]);$db->commit();return ['accepted'=>true,'duplicate'=>false,'client_id'=>$id];
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function integration_eligible(PDO $db,array $client,string $purpose): void {
    if(($client['status']??'')!=='Active')throw new IntegrationError('Client is inactive.',409);
    if(!filter_var($client['email']??'',FILTER_VALIDATE_EMAIL))throw new IntegrationError('Client needs a valid email.');
    $q=$db->prepare('SELECT reason FROM client_suppressions WHERE email=?');$q->execute([strtolower($client['email'])]);if($q->fetch())throw new IntegrationError('Client is suppressed from outbound delivery.',409);
    if($purpose==='marketing'&&($client['email_opt_in']??'No')!=='Yes')throw new IntegrationError('Marketing consent is required.',409);
}
function integration_enqueue(PDO $db,array $input,array $config): array {
    if(empty($config['email_destination']['url']))throw new IntegrationError('Email destination is not configured.',503);
    $id=integration_text($input,'client_id',80);$key=integration_text($input,'idempotency_key',128);$purpose=integration_text($input,'purpose',20);
    if(!in_array($purpose,['transactional','marketing'],true))throw new IntegrationError('Invalid purpose.');
    $client=integration_client($db,$id);integration_eligible($db,$client,$purpose);
    $deliveryId=bin2hex(random_bytes(16));
    $payload=['event'=>'client.sync','delivery_id'=>$deliveryId,'purpose'=>$purpose,'client'=>array_intersect_key($client,array_flip(['id','name','email','phone','email_opt_in']))];
    $q=$db->prepare("INSERT IGNORE INTO integration_outbox(id,client_id,idempotency_key,purpose,payload) VALUES(?,?,?,?,?)");$q->execute([$deliveryId,$id,$key,$purpose,json_encode($payload,JSON_THROW_ON_ERROR)]);
    $q=$db->prepare('SELECT id,client_id,purpose,status FROM integration_outbox WHERE idempotency_key=?');$q->execute([$key]);$row=$q->fetch();if($row['client_id']!==$id||$row['purpose']!==$purpose)throw new IntegrationError('Idempotency key already used for another delivery.',409);
    return ['id'=>$row['id'],'status'=>$row['status']];
}
function integration_target(array $destination): array {
    $url=$destination['url']??'';$p=parse_url($url);
    if(!$p||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||isset($p['query'])||($p['port']??443)!==443)throw new IntegrationError('Destination must be an HTTPS URL without credentials, query, or fragment.');
    $host=strtolower($p['host']);
    if(!in_array($host,$destination['allowed_hosts']??[],true))throw new IntegrationError('Destination hostname is not explicitly allowed.');
    $ips=gethostbynamel($host);if(!$ips)throw new IntegrationError('Destination DNS resolution failed.');
    foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new IntegrationError('Destination must resolve only to public addresses.');
    return [$url,$host,$ips[0]];
}
function integration_http(array $destination,string $body,string $id): int {
    if(!function_exists('curl_init'))throw new IntegrationError('PHP cURL extension is required.');
    [$url,$host,$ip]=integration_target($destination);
    $token=$destination['bearer_token']??'';if(!is_string($token)||strpbrk($token,"\r\n")!==false)throw new IntegrationError('Invalid destination credential configuration.');
    $h=curl_init($url);$bytes=0;
    curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: application/json','Idempotency-Key: '.$id,'X-Khushrang-Delivery: '.$id,$token!==''?'Authorization: Bearer '.$token:'']),CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$bytes):int{$bytes+=strlen($chunk);return $bytes>65536?0:strlen($chunk);}]);
    $ok=curl_exec($h);$code=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
    // Never store provider response bodies: they can contain credentials or personal data.
    return $ok===false?0:$code;
}
function integration_work(PDO $db,array $config,int $limit=20,?callable $transport=null): int {
    $count=0;$transport??='integration_http';
    for($i=0;$i<$limit;$i++){
        $db->beginTransaction();
        // Reclaim abandoned leases. Replays retain the same delivery ID at the receiver.
        $db->exec("UPDATE integration_outbox SET status=IF(attempts>=5,'failed','retry'),locked_at=NULL,lock_token=NULL,last_error='Worker lease expired' WHERE status='processing' AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)");
        $q=$db->query("SELECT * FROM integration_outbox WHERE status IN ('pending','retry') AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY created_at LIMIT 1 FOR UPDATE");$row=$q->fetch();
        if(!$row){$db->commit();break;}
        $lease=bin2hex(random_bytes(16));$q=$db->prepare("UPDATE integration_outbox SET status='processing',locked_at=UTC_TIMESTAMP(),lock_token=?,attempts=attempts+1 WHERE id=?");$q->execute([$lease,$row['id']]);$db->commit();
        $code=null;$error=null;$status='failed';
        try{
            $client=integration_client($db,$row['client_id']);integration_eligible($db,$client,$row['purpose']);
            $payload=json_decode($row['payload'],true);if(strtolower($payload['client']['email'])!==strtolower($client['email']))throw new IntegrationError('Client email changed; queue a new delivery.',409);
            $code=$transport($config['email_destination']??[],$row['payload'],$row['id']);
            if($code>=200&&$code<300)$status='accepted';else{$error=$code?'Destination returned HTTP '.$code:'Network or TLS delivery failure';$attempt=(int)$row['attempts']+1;$status=($attempt<5&&($code===0||$code===408||$code===429||$code>=500))?'retry':'failed';}
        }catch(IntegrationError $e){$error=$e->getMessage();}catch(Throwable $e){$error='Internal delivery failure';}
        $delay=60*(2**min((int)$row['attempts'],6));
        $q=$db->prepare('UPDATE integration_outbox SET status=?,last_http_code=?,last_error=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),locked_at=NULL,lock_token=NULL WHERE id=? AND lock_token=?');$q->execute([$status,$code,$error,$delay,$row['id'],$lease]);$count++;
    }
    return $count;
}
