// Use only a DISPOSABLE test database: this test creates and deletes records.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const base=process.env.API_TEST_URL||'http://127.0.0.1:8080';
if(!process.env.API_TEST_CREDENTIALS_FILE)throw Error('Set API_TEST_CREDENTIALS_FILE to a private JSON file with test email/password.');
const credentials=JSON.parse(readFileSync(process.env.API_TEST_CREDENTIALS_FILE,'utf8'));
let cookie='';
async function request(action,body,expected=200,origin=base){const form=body instanceof FormData;const response=await fetch(`${base}/api.php?action=${action}`,{method:body?'POST':'GET',headers:{...(cookie?{Cookie:cookie}:{}),...(body?{Origin:origin}:{}),...(!form&&body?{'Content-Type':'application/json'}:{})},body:body?(form?body:JSON.stringify(body)):undefined});for(const c of response.headers.getSetCookie())cookie=c.split(';')[0];assert.equal(response.status,expected,`${action}: expected ${expected}, got ${response.status}`);return response;}
await request('data',null,401);
await request('login',credentials,403,'https://untrusted.example');
await request('login',{email:credentials.email,password:'incorrect-test-password'},401);
await request('login',credentials);
const before=await (await request('data')).json();assert.equal(Object.keys(before).length,8);
const stamp=Date.now();const entries={events:{type:'Wedding',location:'Test venue',client:'Test client',amount:'1250.50',date:'2026-12-12',status:'Confirmed'},clients:{email:'client@example.test',phone:'+9200000000',status:'Active'},vendors:{type:'Catering',phone:'',email:'vendor@example.test',status:'Preferred'},invoices:{client:'Test client',amount:'1250.50',date:'2026-12-01',status:'Sent'},payments:{client:'Test client',amount:'500',date:'2026-11-01',status:'Received'},expenses:{type:'Catering',amount:'100',date:'2026-11-01',status:'Paid'},tasks:{client:'Test client',date:'2026-11-01',status:'To do'}};
for(const [kind,fields] of Object.entries(entries)){const row={id:`test-${kind}-${stamp}`,name:'API integration test',...fields};await request('save',{kind,row});await request('save',{kind,row:{...row,name:'Edited integration test'}});}
const after=await (await request('data')).json();for(const kind of Object.keys(entries)){assert(after[kind].some(r=>r.id===`test-${kind}-${stamp}`&&r.name==='Edited integration test'));}
await request('save',{kind:'expenses',row:{id:'invalid',name:'Invalid',amount:'-20',date:'2026-11-01',status:'Paid'}},400);
const file=new FormData();file.append('file',new Blob([Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a8L8AAAAASUVORK5CYII=','base64')],{type:'image/png'}),'test-image.png');
const upload=await (await request('upload',file)).json();const image=await request('file&id='+upload.id);assert.equal(image.headers.get('content-type'),'image/png');assert((await image.arrayBuffer()).byteLength>0);
const bad=new FormData();bad.append('file',new Blob(['<?php echo "unsafe";'],{type:'text/plain'}),'script.php');await request('upload',bad,400);
await request('logout',{});await request('data',null,401);await request('file&id='+upload.id,null,401);await request('login',credentials);
for(const kind of Object.keys(entries))await request('delete',{kind,id:`test-${kind}-${stamp}`});await request('delete',{kind:'media',id:upload.id});await request('file&id='+upload.id,null,404);
await request('logout',{});
console.log('PASS: authentication, CSRF rejection, all seven record types create/edit/read/delete, amount validation, MIME validation, private upload/read/delete, logged-out access denied.');
