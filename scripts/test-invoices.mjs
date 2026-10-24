// Disposable database only. Exercises the actual generated-invoice API and linked receipts.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {randomUUID} from 'node:crypto';
if(process.env.INTEGRATION_TEST_ALLOW_MUTATIONS!=='1')throw Error('Use a disposable DB and INTEGRATION_TEST_ALLOW_MUTATIONS=1.');
const base=process.env.API_TEST_URL||'http://127.0.0.1:8080';const credentials=JSON.parse(readFileSync(process.env.API_TEST_CREDENTIALS_FILE,'utf8'));let cookie='';
async function api(action,body,status=200){const r=await fetch(`${base}/api.php?action=${action}`,{method:body?'POST':'GET',headers:{Origin:base,Cookie:cookie,...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined});for(const c of r.headers.getSetCookie())cookie=c.split(';')[0];const response=await r.json();assert.equal(r.status,status,`${action}: ${JSON.stringify(response)}`);return response;}
await api('invoice_template_get',null,401);await api('login',{email:credentials.email,password:credentials.password});const old=await api('invoice_template_get');const ids=[];let template;
try{
 template=await api('invoice_template_save',{...old,prefix:'TEST-'+Date.now()+'-',nextNo:'1001',cur:'PKR'});
 template=await api('invoice_template_save',{...template,logo:'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a8L8AAAAASUVORK5CYII='});assert(template.logo.startsWith('data:image/png;'));
 const details={template,client:'Invoice API fixture',extra:'Mehndi',date:'2026-11-01',due:'2026-11-08',tax:'10',paid:'1000',items:[{d:'Stage décor',q:'2',r:'1500.25'},{d:'Photography',q:'1',r:'2000'}],total:'0.01'};
 const id='INV-GEN-'+randomUUID();ids.push(id);const body={id,status:'Draft',details};const first=await api('invoice_save',body);assert.equal(first.invoice.amount,'5500.55');assert.equal(first.invoice.amount_paid,'1000.00');assert.equal(first.invoice.balance,'4500.55');assert.equal(first.invoice.invoice_number,template.prefix+'1001');assert.equal(first.invoice.version,'1');
 const replay=await api('invoice_save',body);assert.equal(replay.duplicate,true);assert.equal(replay.invoice.invoice_number,first.invoice.invoice_number);assert.equal((await api('invoice_template_get')).nextNo,'1002');
 let data=await api('data');assert.equal(data.invoices.filter(r=>r.id===id).length,1);const receipt=data.payments.find(r=>r.invoice_id===id);assert(receipt);assert.equal(receipt.amount,'1000.00');
 await api('save',{kind:'invoices',row:first.invoice},409);await api('delete',{kind:'payments',id:receipt.id},409);await api('save',{kind:'payments',row:receipt},409);
 const editedBody={id,version:'1',status:'Sent',details:{...details,paid:'2000'}};const edited=await api('invoice_save',editedBody);assert.equal(edited.invoice.version,'2');assert.equal(edited.invoice.balance,'3500.55');assert.equal((await api('invoice_save',editedBody)).duplicate,true);await api('invoice_save',{...editedBody,details:{...details,client:'Stale edit'}},409);
 data=await api('data');assert.equal(data.payments.filter(r=>r.invoice_id===id).length,1);assert.equal(data.payments.find(r=>r.invoice_id===id).amount,'2000.00');
 const paid=await api('invoice_save',{id,version:'2',status:'Sent',details:{...details,paid:'5500.55'}});assert.equal(paid.invoice.status,'Paid');assert.equal(paid.invoice.balance,'0.00');
 await api('invoice_save',{id:'INV-GEN-'+randomUUID(),details:'invalid'},400);await api('invoice_save',{id:'INV-GEN-'+randomUUID(),details:{...details,template:'invalid'}},400);
 await api('save',{kind:'clients',row:{id:'invoice-template-default',name:'Invalid'}},409);
 const nextBefore=(await api('invoice_template_get')).nextNo;
 for(const change of [{client:''},{items:[{d:'Bad',q:'-1',r:'10'}]},{items:[{d:'Bad',q:'0',r:'10'}]},{tax:'101'},{paid:'999999'},{due:'2026-01-01'}])await api('invoice_save',{id:'INV-GEN-'+randomUUID(),status:'Draft',details:{...details,...change}},400);
 assert.equal((await api('invoice_template_get')).nextNo,nextBefore);
 await api('invoice_template_save',{...template,nextNo:'1'},409);await api('invoice_template_save',{...template,nextNo:nextBefore,logo:'data:image/svg+xml;base64,PHN2Zz4='},400);
 // Distinct requests allocate different shared sequence numbers even from the same preview.
 for(let n=0;n<2;n++){const newId='INV-GEN-'+randomUUID();ids.push(newId);await api('invoice_save',{id:newId,status:'Draft',details});}
 data=await api('data');const numbers=data.invoices.filter(r=>ids.includes(r.id)).map(r=>r.invoice_number);assert.equal(new Set(numbers).size,3);
 const usd='INV-GEN-'+randomUUID();ids.push(usd);const foreign=await api('invoice_save',{id:usd,status:'Draft',details:{...details,template:{...template,cur:'USD'},paid:'10'}});assert.equal(foreign.invoice.currency,'USD');assert.equal((await api('data')).payments.find(r=>r.invoice_id===usd).currency,'USD');
 await api('delete',{kind:'invoices',id});ids.splice(ids.indexOf(id),1);data=await api('data');assert(!data.invoices.some(r=>r.id===id));assert(!data.payments.some(r=>r.invoice_id===id));
 console.log('PASS: item/tax totals recomputed server-side, advance receipt reconciliation, automatic invoice listing, paid status, create/edit idempotency, version conflicts, shared sequence numbers, validation, unsafe logos, currency labels and linked-payment cleanup.');
}finally{for(const id of ids)await api('delete',{kind:'invoices',id});if(template)await api('invoice_template_save',old);}
