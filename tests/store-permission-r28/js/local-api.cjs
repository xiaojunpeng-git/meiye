/** 真实本地HTTP接口：只保存原值验证权限配置提交，不操作收银/资金或会员业务。 */
const fs=require('fs');const assert=require('assert/strict');
const session=JSON.parse(fs.readFileSync('/tmp/r28-browser-sessions.json','utf8'));
const url='http://127.0.0.1:18081/adminapi/setting/store-permissions';
async function api(token,body){const response=await fetch(url,{method:body?'POST':'GET',headers:{'Authori-zation':'Bearer '+(token||''),'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined});return response.json();}
(async()=>{
 assert.notEqual((await api('')).status,200);console.log('PASS unauthenticated configuration read denied');
 assert.notEqual((await api(session.cashier)).status,200);console.log('PASS cashier token cannot manage platform permissions');
 const before=await api(session.admin);assert.equal(before.status,200);
 const stale=await api(session.admin,{revision:before.data.revision-1,rules:before.data.rules});assert.notEqual(stale.status,200);console.log('PASS stale config rejected via real HTTP');
 const saved=await api(session.admin,before.data);assert.equal(saved.status,200);assert.deepEqual(saved.data.rules,before.data.rules);
 assert.equal(saved.data.revision,before.data.revision+1);const after=await api(session.admin);assert.deepEqual(after.data,saved.data);console.log('PASS real authenticated save and fresh GET preserve exact document');
 const menus=await(await fetch('http://127.0.0.1:18081/adminapi/menus',{headers:{'Authori-zation':'Bearer '+session.admin}})).json();assert.equal(menus.status,200);assert.ok(JSON.stringify(menus.data).includes('门店端权限规则'));console.log('PASS existing platform menu API includes independent navigation');
 console.log('R28 API PASS');
})().catch(e=>{console.error(e.message);process.exitCode=1});
