// Disposable database only; uses configured test tokens, never contacts an email service.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
if(process.env.INTEGRATION_TEST_ALLOW_MUTATIONS!=='1')throw Error('Set INTEGRATION_TEST_ALLOW_MUTATIONS=1 only for a disposable database.');
const base=process.env.API_TEST_URL||'http://127.0.0.1:8080';
const credentials=JSON.parse(readFileSync(process.env.API_TEST_CREDENTIALS_FILE,'utf8'));
const token=credentials.inbound_tokens;let cookie='';
async function hook(action,body,bearer,status=202){const r=await fetch(`${base}/integrations.php?action=${action}`,{method:'POST',headers:{'Content-Type':'application/json',...(bearer?{Authorization:`Bearer ${bearer}`}:{})},body:JSON.stringify(body)});assert.equal(r.status,status,`${action}: ${await r.clone().text()}`);return r.json();}
async function admin(action,body,status=200){const r=await fetch(`${base}/api.php?action=${action}`,{method:body?'POST':'GET',headers:{Origin:base,Cookie:cookie,...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined});for(const c of r.headers.getSetCookie())cookie=c.split(';')[0];assert.equal(r.status,status,`${action}: ${await r.clone().text()}`);return r.json();}
const marker='http-'+Date.now();const input={event_id:marker,external_id:marker,name:'HTTP integration fixture',email:marker+'@example.test',phone:'+9200000000',marketing_consent:true};
await hook('clients',input,null,401);await hook('clients',input,'a'.repeat(64),401);await hook('outreach',{},token.clients,403);
const incoming=await hook('clients',input,token.clients);assert.equal((await hook('clients',input,token.clients,200)).duplicate,true);
await hook('clients',{...input,name:'Conflicting event'},token.clients,409);
await hook('clients',{...input,event_id:marker+'-invalid',marketing_consent:'yes'},token.clients,400);
await admin('integration_overview',null,401);await admin('login',{email:credentials.email,password:credentials.password});
const before=await admin('integration_overview');assert(before.connectors.length>=2);assert(!JSON.stringify(before).includes(token.clients));
const queued=await admin('integration_enqueue',{client_id:incoming.client_id,purpose:'marketing',idempotency_key:marker});const duplicate=await admin('integration_enqueue',{client_id:incoming.client_id,purpose:'marketing',idempotency_key:marker});assert.equal(queued.id,duplicate.id);
await admin('integration_retry',{id:queued.id},409);await admin('integration_cancel',{id:queued.id});await admin('integration_cancel',{id:queued.id},409);
const update={event_id:marker+'-delivered',client_id:incoming.client_id,recipient_email:input.email,message_id:'message-'+marker,status:'delivered',occurred_at:'2026-01-01T10:00:00Z'};await hook('outreach',update,token.outreach);await hook('outreach',update,token.outreach,200);
await hook('outreach',{...update,event_id:marker+'-unsubscribe',status:'unsubscribed'},token.outreach);await admin('integration_enqueue',{client_id:incoming.client_id,purpose:'marketing',idempotency_key:marker+'-blocked'},409);
const after=await admin('integration_overview');assert(after.deliveries.some(r=>r.id===queued.id&&r.status==='cancelled'));assert(after.outreach.some(r=>r.client_id===incoming.client_id&&r.status==='unsubscribed'));
await admin('delete',{kind:'clients',id:incoming.client_id});await admin('logout',{});
console.log('PASS: HTTP token authentication, scopes, deduplication/conflicts, consent validation, admin auth, queue/cancel state guards, outreach callbacks, suppression, safe integration status response.');
