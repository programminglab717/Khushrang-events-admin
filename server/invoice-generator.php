<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&!defined('KHUSHRANG_INTERNAL')){http_response_code(404);exit;}
function invoice_default_template(): array {
 return ['bName'=>'Khushrang Events','bSite'=>'khushrangevents.com','bPhone'=>'','bAddr'=>'','prefix'=>'KE-','nextNo'=>'1001','cur'=>'PKR','defTax'=>'0','notes'=>'Dear customer, please send a screenshot of the transfer receipt on WhatsApp after paying the invoice. Thank you!','pay'=>"Bank transfer:\nBank:\nAccount title:\nAccount number:\n\nEasypaisa / JazzCash / SadaPay:\nAccount #:\nTitle: ",'logo'=>''];
}
function invoice_text(array $data,string $key,int $max,bool $required=false): string {
 $v=$data[$key]??'';if(!is_string($v)||strlen($v)>$max||($required&&trim($v)===''))throw new IntegrationError('Invalid '.$key.'.');return trim($v);
}
function invoice_number_value(mixed $v,int $precision,float $max): int {
 if(is_int($v)||is_float($v))$v=(string)$v;
 if(!is_string($v)||!preg_match('/^\d{1,12}(?:\.\d{1,'.$precision.'})?$/',$v)||(float)$v>$max)throw new IntegrationError('Invalid numeric value or precision.');
 [$whole,$fraction]=array_pad(explode('.',$v,2),2,'');return (int)$whole*(10**$precision)+(int)str_pad($fraction,$precision,'0');
}
function invoice_money(int $cents): string {return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);}
function invoice_template(array $data): array {
 $out=[];foreach(['bName'=>190,'bSite'=>250,'bPhone'=>80,'bAddr'=>500,'prefix'=>20,'cur'=>3,'notes'=>6000,'pay'=>6000] as $key=>$max)$out[$key]=invoice_text($data,$key,$max,in_array($key,['bName','prefix','cur'],true));
 if(!preg_match('/^[A-Za-z0-9_-]{1,20}$/',$out['prefix']))throw new IntegrationError('Invoice prefix may contain letters, digits, hyphens and underscores only.');
 $out['cur']=strtoupper($out['cur']);if(!preg_match('/^[A-Z]{3}$/',$out['cur']))throw new IntegrationError('Use a three-letter currency code.');
 $next=$data['nextNo']??'1001';if(!is_scalar($next)||!preg_match('/^[1-9][0-9]{0,8}$/',(string)$next))throw new IntegrationError('Next invoice number must be between 1 and 999999999.');$out['nextNo']=(string)$next;
 $tax=invoice_number_value($data['defTax']??'0',3,100);$out['defTax']=(string)($tax/1000);
 $logo=$data['logo']??'';if(!is_string($logo)||strlen($logo)>300000)throw new IntegrationError('Logo must be at most 200 KB.');
 if($logo!==''){
  if(!preg_match('~^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=]+)$~',$logo,$m))throw new IntegrationError('Logo must be a PNG, JPEG or WebP data image.');
  $bytes=base64_decode($m[2],true);if($bytes===false||strlen($bytes)>200000||(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)!==$m[1])throw new IntegrationError('Invalid logo image.');
  $size=@getimagesizefromstring($bytes);if(!$size||$size[0]>4096||$size[1]>4096)throw new IntegrationError('Logo dimensions must not exceed 4096 pixels.');
 }
 $out['logo']=$logo;return $out;
}
function invoice_settings(PDO $db,bool $lock=false): array {
 if($lock){$q=$db->prepare("INSERT IGNORE INTO records(id,kind,payload) VALUES('invoice-template-default','invoice_template',?)");$q->execute([json_encode(invoice_default_template(),JSON_THROW_ON_ERROR)]);}
 $q=$db->query("SELECT payload FROM records WHERE id='invoice-template-default' AND kind='invoice_template'".($lock?' FOR UPDATE':''));$r=$q->fetch();if($lock&&!$r)throw new IntegrationError('Invoice settings ID is occupied by another record.',409);return $r?json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR):invoice_default_template();
}
function invoice_details(array $input): array {
 if(!is_array($input['template']??null))throw new IntegrationError('Invalid invoice template.');
 $tpl=invoice_template($input['template']);$client=invoice_text($input,'client',190,true);$extra=invoice_text($input,'extra',500);
 foreach(['date','due'] as $key){$value=invoice_text($input,$key,10,true);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new IntegrationError('Invalid '.$key.'.');$dates[$key]=$value;}
 if($dates['due']<$dates['date'])throw new IntegrationError('Due date cannot be before invoice date.');
 $items=$input['items']??null;if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>100)throw new IntegrationError('Add 1–100 invoice items.');
 $clean=[];$subtotal=0;foreach($items as $item){if(!is_array($item))throw new IntegrationError('Invalid line item.');$description=invoice_text($item,'d',1000,true);$quantity=invoice_number_value($item['q']??'',3,100000);$rate=invoice_number_value($item['r']??'',2,10000000);if($quantity<=0)throw new IntegrationError('Item quantity must be greater than zero.');$amount=intdiv($quantity*$rate+500,1000);$subtotal+=$amount;$clean[]=['d'=>$description,'q'=>(string)($quantity/1000),'r'=>invoice_money($rate),'amount'=>invoice_money($amount)];}
 $taxRate=invoice_number_value($input['tax']??'0',3,100);$tax=(int)round($subtotal*($taxRate/100000),0,PHP_ROUND_HALF_UP);$total=$subtotal+$tax;
 if($total<=0||$total>99999999999900)throw new IntegrationError('Invoice total must be positive and within the supported limit.');
 $paid=invoice_number_value($input['paid']??'0',2,999999999999);if($paid>$total)throw new IntegrationError('Amount paid cannot exceed the invoice total.');
 return ['template'=>$tpl,'client'=>$client,'extra'=>$extra,'date'=>$dates['date'],'due'=>$dates['due'],'items'=>$clean,'tax'=>(string)($taxRate/1000),'subtotal'=>invoice_money($subtotal),'tax_amount'=>invoice_money($tax),'total'=>invoice_money($total),'paid'=>invoice_money($paid),'balance'=>invoice_money($total-$paid)];
}
if(str_starts_with($action,'invoice_')){
 try{
  auth_require($user,'invoices',$action==='invoice_template_get'?'read':'write');
  if($action==='invoice_template_get'){echo json_encode(invoice_settings($db));exit;}
  if($_SERVER['REQUEST_METHOD']!=='POST')throw new IntegrationError('POST required.',405);
  $raw=file_get_contents('php://input',false,null,0,1500001);if(strlen($raw)>1500000)throw new IntegrationError('Invoice request is too large.',413);
  $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($input))throw new IntegrationError('Invalid JSON.');
  if($action==='invoice_template_save'){
   $template=invoice_template($input);$db->beginTransaction();$old=invoice_settings($db,true);
   if($template['prefix']===$old['prefix']&&(int)$template['nextNo']<(int)$old['nextNo'])throw new IntegrationError('Next invoice number cannot move backwards for the same prefix.',409);
   $q=$db->prepare("UPDATE records SET payload=? WHERE id='invoice-template-default' AND kind='invoice_template'");$q->execute([json_encode($template,JSON_THROW_ON_ERROR)]);$db->commit();echo json_encode($template);exit;
  }
  if($action!=='invoice_save')throw new IntegrationError('Unknown invoice action.',404);
  $id=invoice_text($input,'id',80,true);if(!preg_match('/^INV-GEN-[a-f0-9-]{36}$/',$id))throw new IntegrationError('Invalid generated invoice ID.');
  if(!is_array($input['details']??null))throw new IntegrationError('Invalid invoice details.');
  $details=invoice_details($input['details']);$status=$input['status']??'Draft';if(!is_string($status)||!in_array($status,['Draft','Sent','Overdue','Cancelled'],true))throw new IntegrationError('Invalid invoice status.');
  if(isset($input['version'])&&(!is_scalar($input['version'])||!preg_match('/^[1-9][0-9]{0,8}$/',(string)$input['version'])))throw new IntegrationError('Invalid invoice version.');
  $hash=hash('sha256',$raw);$db->beginTransaction();$settings=invoice_settings($db,true);
  $q=$db->prepare("SELECT payload,kind FROM records WHERE id=? FOR UPDATE");$q->execute([$id]);$old=$q->fetch();if($old&&$old['kind']!=='invoices')throw new IntegrationError('Invoice ID is occupied by another record.',409);$old=$old?json_decode($old['payload'],true):null;
  if($old){
   if(hash_equals($old['generator_request_hash']??'',$hash)){$db->commit();echo json_encode(['invoice'=>$old,'template'=>$settings,'duplicate'=>true]);exit;}
   if(!isset($input['version']))throw new IntegrationError('Invoice already exists. Reopen it from Invoices.',409);
   if((string)$input['version']!==($old['version']??''))throw new IntegrationError('This invoice was changed elsewhere. Reopen it before saving.',409);
   $number=$old['invoice_number'];$version=(int)$old['version']+1;
  }else{
   if(isset($input['version']))throw new IntegrationError('Invoice no longer exists. Start a new invoice.',409);
   // Allocate from a locked shared counter, never the preview/browser counter.
   if($details['template']['prefix']!==$settings['prefix'])throw new IntegrationError('Save the template before using a different invoice prefix.',409);
   if((int)$settings['nextNo']>=999999999)throw new IntegrationError('Invoice sequence is exhausted. Choose another prefix.');
   $number=$settings['prefix'].$settings['nextNo'];$reservation='invoice-number-'.hash('sha256',$number);
   $q=$db->prepare("INSERT IGNORE INTO records(id,kind,payload) VALUES(?,'invoice_number',?)");$q->execute([$reservation,json_encode(['number'=>$number])]);if(!$q->rowCount())throw new IntegrationError('That invoice number is already used. Increase the next number in template settings.',409);
   $settings['nextNo']=(string)((int)$settings['nextNo']+1);$q=$db->prepare("UPDATE records SET payload=? WHERE id='invoice-template-default' AND kind='invoice_template'");$q->execute([json_encode($settings,JSON_THROW_ON_ERROR)]);$version=1;
  }
  if($details['balance']==='0.00'&&$status!=='Cancelled')$status='Paid';
  $row=['id'=>$id,'name'=>mb_substr(implode(' · ',array_column($details['items'],'d')),0,500),'client'=>$details['client'],'date'=>$details['due'],'amount'=>$details['total'],'currency'=>$details['template']['cur'],'status'=>$status,'invoice_number'=>$number,'amount_paid'=>$details['paid'],'balance'=>$details['balance'],'version'=>(string)$version,'generator_details'=>json_encode($details,JSON_THROW_ON_ERROR),'generator_request_hash'=>$hash];
  $q=$db->prepare("INSERT INTO records(id,kind,payload) VALUES(?,'invoices',?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)");$q->execute([$id,json_encode($row,JSON_THROW_ON_ERROR)]);
  // One managed receipt per generated invoice; edits reconcile rather than duplicate it.
  $paymentId='PAY-GEN-'.hash('sha256',$id);
  if($details['paid']!=='0.00'){$payment=['id'=>$paymentId,'name'=>'Payment for '.$number,'client'=>$details['client'],'date'=>$details['date'],'amount'=>$details['paid'],'currency'=>$row['currency'],'status'=>'Received','invoice_id'=>$id,'managed_by'=>'invoice_generator'];$q=$db->prepare("INSERT INTO records(id,kind,payload) VALUES(?,'payments',?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)");$q->execute([$paymentId,json_encode($payment,JSON_THROW_ON_ERROR)]);}else{$q=$db->prepare("DELETE FROM records WHERE id=? AND kind='payments'");$q->execute([$paymentId]);}
  $db->commit();echo json_encode(['invoice'=>$row,'template'=>$settings,'duplicate'=>false]);exit;
 }catch(IntegrationError $e){if($db->inTransaction())$db->rollBack();fail($e->getMessage(),$e->httpStatus);}catch(JsonException $e){if($db->inTransaction())$db->rollBack();fail('Invalid invoice JSON.');}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
