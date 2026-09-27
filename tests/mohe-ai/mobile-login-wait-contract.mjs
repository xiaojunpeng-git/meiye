// Execute the production UTS functions with deterministic transport/timers.
// This is a state regression, not a native phone rendering acceptance test.
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const ts = require(process.env.MOHE_AI_TYPESCRIPT || '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-uts-v1/node_modules/@dcloudio/uni-uts-v1/lib/typescript/lib/typescript.js');
function compile(code) {
  const result = ts.transpileModule(code,{reportDiagnostics:true,compilerOptions:{target:ts.ScriptTarget.ES2020,module:ts.ModuleKind.CommonJS}});
  assert.deepEqual((result.diagnostics || []).filter(d=>d.category===ts.DiagnosticCategory.Error),[]);
  return result.outputText;
}
const source = fs.readFileSync(new URL('../../前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue', import.meta.url), 'utf8');
compile(source.match(/<script[^>]*>([\s\S]*?)<\/script>/)[1]);
function extract(name) {
  const start = source.indexOf(`function ${name}(`);
  assert.ok(start >= 0, name);
  const next = source.indexOf('\nfunction ', start + 1);
  // Compile the selected production functions using HBuilderX's bundled TS
  // compiler. No implementation is substituted by a test algorithm.
  return compile(source.slice(start, next));
}
const functions = ['finishActiveRunStatus', 'stopQuestionWait', 'pauseForExpiredLogin', 'submit', 'schedulePoll'];
function fixture() {
  const s = { lifecycle:1, pending:null, run:null, timer:null, elapsedTimer:9, stopped:[], requests:[], saved:null,
    entryId:'entry', conversation:'conversation', sessionId:'session', guidanceSchema:'v1', activeGuidanceSchema:'',
    bootstrap:{window_token:'window'}, closeRequested:false, clientDeliveryStartedAt:0,
    queuedClarificationId:'', compatibilityExecuting:false };
  for (const name of ['question','progress','activeQuestion','activeQuestionAnchor','activeRunState']) s[name] = {value:''};
  for (const name of ['busy','guidanceBusy','closeRequestedState','composerOptionsOpen','wantExcel']) s[name] = {value:false};
  s.activeElapsedSeconds = {value:20}; s.clarification = {value:null};
  Object.assign(s, {
    clearInterval:x=>s.stopped.push(x), clearTimeout:x=>s.stopped.push(x),
    setTimeout:callback=>{s.pollCallback=callback; return 8;},
    activeRecord:()=>s.saved, ownsActiveRecord:()=>true, validResolvedRun:()=>false, load:()=>[], id:()=> 'request-1',
    persistActive:()=>{s.saved={owner_id:s.entryId,pending:s.pending,run:s.run};},
    clearActive:()=>{s.saved=null;}, resetComposerSize:()=>{},
    samePendingRecord:(a,b)=>a.pending===b,
    showActiveQuestion:q=>{s.activeQuestion.value=q;s.elapsedTimer=9;},
    mobileAiRequest:(method,path,body,callback)=>s.requests.push({method,path,body,callback}),
    terminal:()=>false, binding:()=>({}), unavailable:()=>false,
  });
  vm.createContext(s); vm.runInContext(functions.map(extract).join('\n'),s);
  s.question.value='今天现金业绩是多少';
  return s;
}
function checkStopped(s) {
  assert.equal(s.busy.value,false); assert.equal(s.elapsedTimer,null);
  assert.equal(s.activeQuestion.value,''); assert.ok(s.stopped.includes(9));
  assert.equal(s.question.value,'今天现金业绩是多少');
}
let checks=0;
for (const response of [
  {ok:false,responseKnown:true,reason:'MERCHANT_SESSION_EXPIRED',message:'expired'},
  {ok:false,responseKnown:true,reason:'AI_REQUEST_FAILED',message:'请求失败'},
  {ok:true,data:{accepted:false,message:'请稍后再问'}},
]) {
  const s=fixture(); s.submit(); s.requests[0].callback(response); checkStopped(s);
  assert.equal(s.pending,null); assert.equal(s.saved,null);
  assert.ok(s.progress.value.length>0);
  if (response.reason==='MERCHANT_SESSION_EXPIRED') assert.match(s.progress.value,/重新登录/);
  checks++;
}
// Network uncertainty must keep exactly the original envelope, not duplicate
// a potentially accepted server task when the customer presses send again.
{
  const s=fixture(); s.submit(); const original=s.pending;
  s.requests[0].callback({ok:false,responseKnown:false}); checkStopped(s);
  assert.equal(s.pending,original); assert.equal(s.saved.pending,original);
  s.submit(); assert.equal(s.requests[1].body.client_request_id,s.requests[0].body.client_request_id);
  assert.equal(s.activeQuestion.value,original.question); checks++;
}
// Expiry during status polling stops the loop without pretending that an
// accepted server query was cancelled, or deleting its recovery envelope.
{
  const s=fixture(); s.pending={question:s.question.value}; s.run={run_id:'run',generation:1};
  s.busy.value=true; s.activeQuestion.value=s.question.value;
  s.schedulePoll(); s.pollCallback();
  s.requests[0].callback({ok:false,responseKnown:true,reason:'MERCHANT_SESSION_EXPIRED'});
  checkStopped(s); assert.equal(s.timer,null); assert.equal(s.lifecycle,2);
  assert.equal(s.saved.run,s.run); assert.equal(s.saved.pending,s.pending); checks++;
}
// A callback from a previous page lifecycle cannot stop a newer question.
{
  const s=fixture(); s.submit(); s.lifecycle++;
  s.requests[0].callback({ok:false,responseKnown:true,reason:'MERCHANT_SESSION_EXPIRED'});
  assert.equal(s.busy.value,true); assert.equal(s.elapsedTimer,9); checks++;
}
// Check local admission and HTTP classification in the real adapter as well.
{
  const adapter=fs.readFileSync(new URL('../../前端代码/mobile-vue3/src/shared/api/mobile-ai-client.uts',import.meta.url),'utf8');
  compile(adapter);
  const body=adapter.slice(adapter.indexOf('export function mobileAiRequest'),adapter.indexOf('export function downloadMobileAi'));
  const s={exports:{},session:null,plan:null,response:null,calls:0,result:null,
    currentMerchantRoot:()=>({})};
  s.merchantRequestSession=()=>s.session;
  s.buildMobileRequestPlan=()=>s.plan;
  s.sendMobileRequest=(_plan,_body,done)=>{s.calls++;done(s.response);};
  vm.createContext(s);vm.runInContext(compile(body),s);
  const request=()=>s.exports.mobileAiRequest('POST','/runs',{},r=>{s.result=r;});
  request(); assert.equal(s.result.reason,'MERCHANT_SESSION_EXPIRED');assert.equal(s.result.responseKnown,true);assert.equal(s.calls,0); checks++;
  s.session={}; request();assert.equal(s.result.responseKnown,true);assert.equal(s.calls,0);checks++;
  s.plan={headers:{}};s.response={ok:false,statusCode:401,body:{errorCode:'MERCHANT_SESSION_EXPIRED',message:'登录已失效'}};
  request();assert.equal(s.result.reason,'MERCHANT_SESSION_EXPIRED');assert.equal(s.result.responseKnown,true);checks++;
  s.response={ok:false,statusCode:0};request();assert.equal(s.result.responseKnown,false);checks++;
}
console.log(`PASS mobile login/wait production state: ${checks} scenarios; both UTS scripts parsed`);
