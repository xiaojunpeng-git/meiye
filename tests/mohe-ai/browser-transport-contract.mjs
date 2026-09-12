import assert from 'node:assert/strict';
import { browserTransport } from '../../前端代码/shared/mohe-ai/browser-transport.mjs';
let sent;
globalThis.fetch = async (url,options) => { sent={url,options}; return {ok:true,json:async()=>({status:200,data:{ok:true}}),blob:async()=>({fixtureBlob:true})}; };
const request = browserTransport('/fixture/ai',()=> 'fixture-login');
await request('GET','/runs/id',{client_session_id:'device1',generation:2,run_delivery_token:'fixture-delivery'});
assert.equal(sent.url,'/fixture/ai/runs/id');
assert.equal(sent.options.headers['X-Mohe-Ai-Client-Session-Id'],'device1');
assert.equal(sent.options.headers['X-Mohe-Ai-Run-Delivery-Token'],'fixture-delivery');
assert.equal(sent.options.headers['X-Mohe-Ai-Generation'],'2');
assert.equal(sent.options.body,undefined);
await assert.rejects(()=>request('GET','/runs/id',{question:'not-in-url'}));
await request('POST','/runs',{question:'fixture-question'});
assert.equal(sent.options.body,JSON.stringify({question:'fixture-question'}));
assert.equal(sent.options.credentials,'omit');
assert.deepEqual(await request('GET','/runs/id/export',{client_session_id:'device1'},{binary:true}),{fixtureBlob:true});
globalThis.fetch = async () => ({ok:false,status:400,json:async()=>({status:400,msg:'当前账号没有此项操作权限。',data:{error_code:'AI_PERMISSION_DENIED'}})});
await assert.rejects(
  () => request('GET','/management'),
  error => error.responseKnown === true && error.httpStatus === 400 && error.reason === 'AI_PERMISSION_DENIED'
);
console.log('Browser transport no URL credentials: 10 checks PASS');
