<?php
if(PHP_SAPI!=='cli'&&!defined('KHUSHRANG_INTERNAL')){http_response_code(404);exit;}
// Included only after the main API's session authorization and same-origin checks.
require_once __DIR__.'/integrations-lib.php';
if(str_starts_with($action,'integration_')) {
    try {
        if($action==='integration_overview') {
            if($_SERVER['REQUEST_METHOD']!=='GET')throw new IntegrationError('GET required.',405);
            $connectors=array_map(static fn($c)=>['source'=>$c['source']??'','scopes'=>$c['scopes']??[],'configured'=>!empty($c['token_sha256'])],$config['inbound_connectors']??[]);
            $destination=$config['email_destination']??[];
            echo json_encode(['connectors'=>$connectors,'destination'=>['configured'=>!empty($destination['url']),'host'=>parse_url($destination['url']??'',PHP_URL_HOST)?:''],'deliveries'=>$db->query('SELECT id,client_id,purpose,status,attempts,last_http_code,last_error,created_at,next_attempt_at FROM integration_outbox ORDER BY created_at DESC LIMIT 100')->fetchAll(),'inbound'=>$db->query('SELECT source,event_id,event_type,client_id,received_at FROM integration_inbox ORDER BY received_at DESC LIMIT 100')->fetchAll(),'outreach'=>$db->query('SELECT id,source,client_id,recipient_email,message_id,status,occurred_at,received_at FROM outreach_updates ORDER BY occurred_at DESC LIMIT 100')->fetchAll(),'suppressed_count'=>(int)$db->query('SELECT COUNT(*) FROM client_suppressions')->fetchColumn()]);exit;
        }
        if($_SERVER['REQUEST_METHOD']!=='POST')throw new IntegrationError('POST required.',405);
        $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))throw new IntegrationError('Invalid JSON.');
        if($action==='integration_enqueue'){echo json_encode(integration_enqueue($db,$input,$config));exit;}
        $id=integration_text($input,'id',32);
        if($action==='integration_retry'){
            $q=$db->prepare("UPDATE integration_outbox SET status='retry',next_attempt_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=? AND status IN ('retry','failed')");$q->execute([$id]);
        }elseif($action==='integration_cancel'){
            $q=$db->prepare("UPDATE integration_outbox SET status='cancelled',last_error='Cancelled by administrator' WHERE id=? AND status IN ('pending','retry','failed')");$q->execute([$id]);
        }else throw new IntegrationError('Unknown action.',404);
        if(!$q->rowCount())throw new IntegrationError('Delivery cannot be changed in its current state.',409);echo '{}';exit;
    }catch(IntegrationError $e){fail($e->getMessage(),$e->httpStatus);}
}
