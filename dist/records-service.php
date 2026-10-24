<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&!defined('KHUSHRANG_INTERNAL')){http_response_code(404);exit;}
function business_kinds(): array{return ['events','clients','vendors','invoices','payments','expenses','tasks','media'];}
function business_namespace(string $kind,string $id): void {
 if(!preg_match('/^[A-Za-z0-9-]{1,80}$/',$id))throw new IntegrationError('Invalid record ID.');
 if(strtolower($id)==='invoice-template-default'||preg_match('/^(invoice-number-|integration-token-|integration-webhook-|integration-probe-|integration-runtime-|mcp-op-|mcp-audit-)/i',$id))throw new IntegrationError('That ID is reserved for system records.',409);
 if((str_starts_with(strtoupper($id),'INV-GEN-')&&$kind!=='invoices')||(str_starts_with(strtoupper($id),'PAY-GEN-')&&$kind!=='payments'))throw new IntegrationError('That ID belongs to another record type.',409);
}
function business_get(PDO $db,string $kind,string $id,bool $lock=false): array {
 if(!in_array($kind,business_kinds(),true))throw new IntegrationError('Unknown record type.');
 $q=$db->prepare('SELECT kind,payload FROM records WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$r=$q->fetch();if(!$r||$r['kind']!==$kind)throw new IntegrationError('Record not found.',404);return json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);
}
function business_save(PDO $db,string $kind,array $row,?string $expectedRevision=null,bool $requireRevision=false): array {
 if(!in_array($kind,business_kinds(),true)||$kind==='media')throw new IntegrationError('Use the media upload tool for files.');
 $id=$row['id']??'';if(!is_string($id))throw new IntegrationError('Invalid record ID.');business_namespace($kind,$id);
 if(str_starts_with(strtoupper($id),'INV-GEN-')||str_starts_with(strtoupper($id),'PAY-GEN-'))throw new IntegrationError('Use the invoice generator for generated invoices and linked receipts.',409);
 $own=!$db->inTransaction();if($own)$db->beginTransaction();
 try{
 $q=$db->prepare('SELECT kind,payload FROM records WHERE id=? FOR UPDATE');$q->execute([$id]);$existing=$q->fetch();if($existing&&$existing['kind']!==$kind)throw new IntegrationError('ID belongs to another record type.',409);$old=$existing?json_decode($existing['payload'],true):[];
 if(isset($old['generator_details'])||($old['managed_by']??'')==='invoice_generator')throw new IntegrationError('Edit this record through the invoice generator.',409);
 if($existing&&($requireRevision||$expectedRevision!==null)&&$expectedRevision!==(string)($old['_revision']??'0'))throw new IntegrationError('Record changed or revision missing. Read the record and retry with its current revision.',409);
 $fields=['events'=>['name','type','date','location','client','amount','status'],'clients'=>['name','email','phone','status','email_opt_in'],'vendors'=>['name','type','phone','email','status'],'invoices'=>['name','client','date','amount','status'],'payments'=>['name','client','date','amount','status'],'expenses'=>['name','type','date','amount','status'],'tasks'=>['name','client','date','status']];
 $states=['events'=>['Inquiry','Planning','Confirmed','Completed','Cancelled'],'clients'=>['Active','Inactive'],'vendors'=>['Active','Preferred','Inactive'],'invoices'=>['Draft','Sent','Paid','Overdue','Cancelled'],'payments'=>['Received','Pending'],'expenses'=>['Pending','Paid'],'tasks'=>['To do','In progress','Done']];
 $clean=['id'=>$id];foreach($fields[$kind] as $f){$v=$row[$f]??$old[$f]??($f==='status'?$states[$kind][0]:($f==='email_opt_in'?'No':''));if($f==='email_opt_in'&&is_bool($v))$v=$v?'Yes':'No';if(is_int($v)||is_float($v))$v=(string)$v;if(!is_string($v)||strlen($v)>2000)throw new IntegrationError('Invalid field: '.$f);$clean[$f]=trim($v);}
 if($clean['name']==='')throw new IntegrationError('Name is required.');if(!in_array($clean['status'],$states[$kind],true))throw new IntegrationError('Invalid status.');
 if($kind==='clients'&&!in_array($clean['email_opt_in'],['Yes','No'],true))throw new IntegrationError('Invalid email consent.');
 if(isset($clean['amount'])){if(!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/',$clean['amount'])||(float)$clean['amount']>999999999999)throw new IntegrationError('Invalid amount; use a nonnegative amount with up to two decimal places.');$currency=$row['currency']??$old['currency']??'PKR';if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/',$currency))throw new IntegrationError('Use a three-letter currency code.');$clean['currency']=$currency;}
 if(isset($clean['date'])){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$clean['date']);if(!$d||$d->format('Y-m-d')!==$clean['date'])throw new IntegrationError('Invalid date.');}
 if(!empty($clean['email'])&&!filter_var($clean['email'],FILTER_VALIDATE_EMAIL))throw new IntegrationError('Invalid email.');
 $clean['_revision']=(string)((int)($old['_revision']??0)+1);$q=$db->prepare('INSERT INTO records(id,kind,payload) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),updated_at=UTC_TIMESTAMP()');$q->execute([$id,$kind,json_encode($clean,JSON_THROW_ON_ERROR)]);if($own)$db->commit();return $clean;
 }catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
}
function business_delete(PDO $db,string $kind,string $id,array $config,?array &$pendingFiles=null,?string $expectedRevision=null): array {
 business_namespace($kind,$id);$own=!$db->inTransaction();if($own)$db->beginTransaction();$files=[];
 try{$row=business_get($db,$kind,$id,true);if($expectedRevision!==null&&$expectedRevision!==(string)($row['version']??$row['_revision']??'0'))throw new IntegrationError('Record changed. Read it again before deleting.',409);
 if(($row['managed_by']??'')==='invoice_generator')throw new IntegrationError('Edit the amount paid in the linked invoice instead.',409);
 if($kind==='invoices'&&isset($row['generator_details'])){$q=$db->prepare("DELETE FROM records WHERE id=? AND kind='payments'");$q->execute(['PAY-GEN-'.hash('sha256',$id)]);}
 if($kind==='media')$files[]=rtrim($config['storage_path'],'/').'/'.basename($row['stored']);
 $q=$db->prepare('DELETE FROM records WHERE id=? AND kind=?');$q->execute([$id,$kind]);if($own){$db->commit();foreach($files as $file)if(is_file($file))unlink($file);}elseif($pendingFiles!==null)$pendingFiles=array_merge($pendingFiles,$files);else throw new IntegrationError('Missing file cleanup context.');return ['id'=>$id,'deleted'=>true];
 }catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
}
function business_public(array $row,bool $details=true): array {
 unset($row['stored'],$row['generator_request_hash']);if(isset($row['generator_details'])){if($details){$d=json_decode($row['generator_details'],true);if(is_array($d)){if(!empty($d['template']['logo']))$d['template']['has_logo']=true;unset($d['template']['logo']);$row['details']=$d;}}unset($row['generator_details']);}return $row;
}
