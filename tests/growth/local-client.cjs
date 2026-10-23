/* 仅供成长隔离环境验收；所有写入均通过真实HTTP业务入口。 */
const assert = require('node:assert/strict');
const {randomUUID}=require('node:crypto');
const {execFileSync}=require('node:child_process');
const path=require('node:path');
const base='http://127.0.0.1:18115';
const key=()=>`growth-check-${randomUUID()}`;
async function raw(p,data,headers={}){const response=await fetch(base+p,{method:data?'POST':'GET',headers:{'Content-Type':'application/json',...headers},body:data?JSON.stringify(data):undefined});const text=await response.text();try{return JSON.parse(text);}catch{throw Error(p+' 返回非JSON，HTTP '+response.status+'，响应长度 '+text.length);}}
async function admin(account='growth_admin'){const r=await raw('/adminapi/login',{account,pwd:'GrowthLocal!2026'});assert.equal(r.status,200,JSON.stringify(r));const headers={'Authori-zation':'Bearer '+r.data.token,'X-Source':'f76d38d0ee4f854f'};return {headers,call:async(p,d,fail=false)=>{const r=await raw('/adminapi/growth/'+p,d?{...d,idempotency_key:d.idempotency_key||key()}:undefined,headers);if(!fail)assert.equal(r.status,200,p+': '+JSON.stringify(r));return fail?r:r.data;}};}
async function mobile(account='emp91002'){const headers={'X-Mobile-Contract-Version':'mobile-merchant-v1','X-Mobile-Client-Session-Id':key(),'X-Mobile-Request-Id':key(),'X-Mobile-Platform':'WEB'};const login=await raw('/api/mobile/merchant/password-login',{account,pwd:'GrowthLocal!2026',installationId:key(),idempotencyKey:key()},headers);assert.ok(login.merchantToken,JSON.stringify(login));Object.assign(headers,{Authorization:'Bearer '+login.merchantToken,'X-Mobile-Active-Context-Id':login.activeContextId,'X-Mobile-State-Context-Id':login.stateContextId});return {headers,login,call:async(p,d,fail=false)=>{const r=await raw('/api/mobile/merchant/growth/'+p,d?{...d,idempotency_key:d.idempotency_key||key()}:undefined,headers);if(!fail)assert.ok(r.growth,p+': '+JSON.stringify(r));return fail?r:r.growth;},bootstrap:()=>raw('/api/mobile/merchant/bootstrap',undefined,headers)};}
function fixture(input){return JSON.parse(execFileSync('docker',['exec','-i','mohe-growth-local-app','php','/workspace/tests/growth/scope-fixtures.php'],{input:JSON.stringify(input),encoding:'utf8'}));}
module.exports={assert,key,raw,base,admin,mobile,fixture};
