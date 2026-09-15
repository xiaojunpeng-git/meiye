import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';
import { mountMoheAi } from '../../前端代码/shared/mohe-ai/browser-entry.mjs';
const require = createRequire(import.meta.url);
const { JSDOM } = require('../../前端代码/admin/node_modules/jsdom');
const dom = new JSDOM('<!doctype html><body></body>',{url:'https://fixture.test'});
// This project's legacy jsdom predates Shadow DOM. The shim tests behavior,
// not browser styling/isolation, which still requires a real browser check.
dom.window.HTMLElement.prototype.attachShadow = function () { const child = dom.window.document.createElement('section'); this.appendChild(child); Object.defineProperty(this,'shadowRoot',{value:child}); return child; };
globalThis.window = dom.window; globalThis.document = dom.window.document; globalThis.crypto = webcrypto;
const flush = () => new Promise(r => setTimeout(r,10));
const calls = []; let finishStatus, finishCreate, rejectStatus, asyncExecution = true, createExecutionMode = null, deferCreate = false, failStatus = false, collectStatusWaiters = false, identityKey = 'fixture:user';
const statusWaiters = [];
const request = async (method,path,payload) => {
  calls.push({method,path,payload});
  if (path === '/bootstrap') return {enabled:true,async_execution:asyncExecution,can_configure:true,identity_key:identityKey,window_token:'w',capabilities:{metric_codes:['consume_amount'],output_formats:['screen']}};
  if (path === '/runs') {
    if (deferCreate) return new Promise(resolve=>{finishCreate=resolve;});
    return {run_id:'r',generation:1,run_delivery_token:'delivery',status:'READY',progress:'已接纳',...(createExecutionMode ? {execution_mode:createExecutionMode} : {})};
  }
  if (path.endsWith('/execute')) return {run_id:'r',generation:1,run_delivery_token:'delivery',status:'COMPLETED',answer:{summary:'兼容结果',cards:[]}};
  if (path.endsWith('/delivery')) return {accepted:true};
  if (path === '/runs/r') {
    if (failStatus) return new Promise((_,reject)=>{rejectStatus=reject;});
    if (collectStatusWaiters) return new Promise(resolve=>{statusWaiters.push(resolve);});
    return new Promise(resolve=>{finishStatus=resolve;});
  }
  if (path.endsWith('/cancel')) return {run_id:'r',generation:1,status:'CANCELLED'};
  throw new Error('Unexpected path');
};
const dispose = mountMoheAi({request}); await flush();
const root = document.querySelector('[data-mohe-ai]').shadowRoot;
root.querySelector('.entry').click(); await flush();
assert.ok(root.querySelector('[role=dialog]'));
assert.equal(Array.from(root.querySelectorAll('button')).some(b=>b.textContent==='配置'),false);
assert.equal(root.querySelector('input[type=password]'),null);
assert.equal(root.querySelector('textarea').placeholder,'例如：今天本店消耗业绩多少？');
assert.equal(root.querySelector('option[value="screen_and_xlsx"]').disabled,true);
const input = root.querySelector('textarea'); input.value = '<img src=x onerror=alert(1)>今天现金业绩';
Array.from(root.querySelectorAll('button')).find(b=>b.textContent==='发送').click(); await flush();
assert.ok(calls.find(c=>c.path==='/runs').payload.history);
assert.ok(calls.filter(c=>c.path==='/bootstrap').every(c=>c.payload.client_session_id===calls.find(r=>r.path==='/runs').payload.client_session_id));
assert.equal(calls.some(c=>c.path.endsWith('/execute')),false);
assert.equal(root.querySelector('img'),null);
await new Promise(resolve=>setTimeout(resolve,1050));
assert.equal(typeof finishStatus,'function');
Array.from(root.querySelectorAll('button')).find(b=>b.textContent==='关闭').click(); await flush();
assert.equal(calls.filter(c=>c.path.endsWith('/cancel')).length,1);
assert.equal(root.querySelector('.panel').hidden,true);
finishStatus({run_id:'r',generation:1,status:'COMPLETED',answer:{summary:'迟到结果',cards:[{metric_name:'现金业绩',display_value:'999',unit:'元'}]}}); await flush();
assert.equal(root.querySelector('.value'),null);
// Inspect delivered content, not random IDs/timestamps that may contain "999".
const savedRounds = JSON.parse(window.localStorage.getItem('mohe-ai:v1:fixture%3Auser')).flatMap(s => s.rounds);
assert.equal(savedRounds.some(r => r.answer.includes('迟到结果') || (r.presentation?.cards || []).some(c => c.display_value === '999')), false);
dispose(); assert.equal(document.querySelector('[data-mohe-ai]'),null);
assert.equal(calls.some(c=>c.path.startsWith('/config')),false);
// Staged instances keep synchronous execution until their queue release gate
// is enabled.  This catches a reference typo that would otherwise leave a
// newly accepted Run polling forever without sending /execute.
asyncExecution = false;
const compatibility = mountMoheAi({request}); await flush();
const compatibilityRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
compatibilityRoot.querySelector('.entry').click(); await flush();
compatibilityRoot.querySelector('textarea').value = '兼容模式问题';
Array.from(compatibilityRoot.querySelectorAll('button')).find(b=>b.textContent==='发送').click(); await flush();
assert.ok(calls.filter(c=>c.path.endsWith('/execute')).length >= 1);
const deliveryCall=calls.find(c=>c.path.endsWith('/delivery'));
assert.deepEqual(Object.keys(deliveryCall.payload).sort(),['client_elapsed_ms','client_session_id','generation','run_delivery_token']);
assert.equal(Number.isSafeInteger(deliveryCall.payload.client_elapsed_ms),true);
compatibility();
// A bootstrap snapshot may turn async while this particular create request
// was admitted in compatibility mode. The Run response must win, otherwise
// the customer sees a permanently accepted-but-never-executed question.
window.localStorage.clear(); identityKey = 'fixture:create-mode-race'; asyncExecution = true; createExecutionMode = 'compatibility';
const createModeRace = mountMoheAi({request}); await flush();
const createModeRaceRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
createModeRaceRoot.querySelector('.entry').click(); await flush();
const executeBeforeModeRace = calls.filter(c=>c.path.endsWith('/execute')).length;
createModeRaceRoot.querySelector('textarea').value = '创建模式竞争';
Array.from(createModeRaceRoot.querySelectorAll('button')).find(b=>b.textContent==='发送').click(); await flush();
assert.equal(calls.filter(c=>c.path.endsWith('/execute')).length,executeBeforeModeRace + 1);
createModeRace(); createExecutionMode = null;
// A browser adapter can be replaced while navigating between business pages.
// That lifecycle event preserves the active Run; it is not a customer Stop.
window.localStorage.clear(); identityKey = 'fixture:user'; asyncExecution = true;
const cancelCount = calls.filter(c=>c.path.endsWith('/cancel')).length;
const recovery = mountMoheAi({request}); await flush();
const recoveryRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
recoveryRoot.querySelector('.entry').click(); await flush();
  recoveryRoot.querySelector('textarea').value = '页面切换后仍应继续';
  Array.from(recoveryRoot.querySelectorAll('button')).find(b=>b.textContent==='发送').click(); await flush();
  const originalSession = calls.filter(c=>c.path==='/runs').at(-1).payload.client_session_id;
  // A real refresh triggers pagehide before the framework disposer. The
  // active request must remain recoverable in either lifecycle ordering.
  window.dispatchEvent(new window.Event('pagehide'));
  recovery();
assert.equal(calls.filter(c=>c.path.endsWith('/cancel')).length,cancelCount);
assert.equal(JSON.parse(window.localStorage.getItem('mohe-ai:v1:fixture%3Auser:runtime')).run.run_id,'r');
const resumed = mountMoheAi({request}); await flush();
const resumedRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
  resumedRoot.querySelector('.entry').click(); await flush();
  assert.equal(calls.filter(c=>c.path==='/bootstrap').at(-1).payload.client_session_id,originalSession);
  assert.match(resumedRoot.textContent,/页面切换后仍应继续/);
assert.equal(typeof finishStatus,'function');
finishStatus({run_id:'r',generation:1,run_delivery_token:'delivery',status:'READY',progress:'已接纳'}); await flush();
resumed();
// A create response can arrive only after navigation has replaced the entry.
// It must become recoverable work, never an implicit cancellation.
window.localStorage.clear(); identityKey = 'fixture:late-navigation'; asyncExecution = true; deferCreate = true; finishCreate = null;
const cancelBeforeLateNavigation = calls.filter(c=>c.path.endsWith('/cancel')).length;
const lateNavigation = mountMoheAi({request}); await flush();
const lateNavigationRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
lateNavigationRoot.querySelector('.entry').click(); await flush();
lateNavigationRoot.querySelector('textarea').value = '创建响应晚到';
Array.from(lateNavigationRoot.querySelectorAll('button')).find(b=>b.textContent==='发送').click(); await flush();
assert.equal(typeof finishCreate,'function');
lateNavigation();
finishCreate({run_id:'r',generation:1,run_delivery_token:'delivery',status:'READY',progress:'已接纳'}); await flush();
assert.equal(calls.filter(c=>c.path.endsWith('/cancel')).length,cancelBeforeLateNavigation);
const lateRecord = JSON.parse(window.localStorage.getItem('mohe-ai:v1:fixture%3Alate-navigation:runtime'));
assert.equal(lateRecord.run.run_id,'r'); assert.equal(lateRecord.pending_create.question,'创建响应晚到');
const lateResumed = mountMoheAi({request}); await flush();
const lateResumedRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
  lateResumedRoot.querySelector('.entry').click(); await flush();
  assert.equal(calls.filter(c=>c.path==='/bootstrap').at(-1).payload.client_session_id,lateRecord.session_id);
  assert.match(lateResumedRoot.textContent,/创建响应晚到/);
finishStatus({run_id:'r',generation:1,run_delivery_token:'delivery',status:'READY',progress:'已接纳'}); await flush();
lateResumed();
// Close is customer intent even if admission has not returned. Replaying its
// pending request must retain that intent and cancel only after it has a Run.
window.localStorage.clear(); identityKey = 'fixture:closed-pending'; deferCreate = true; finishCreate = null;
const closeBeforePending = calls.filter(c=>c.path.endsWith('/cancel')).length;
const closedPending = mountMoheAi({request}); await flush();
const closedPendingRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
closedPendingRoot.querySelector('.entry').click(); await flush();
closedPendingRoot.querySelector('textarea').value = '关闭中的请求';
Array.from(closedPendingRoot.querySelectorAll('button')).find(b=>b.textContent==='发送').click(); await flush();
Array.from(closedPendingRoot.querySelectorAll('button')).find(b=>b.textContent==='关闭').click(); await flush();
closedPending(); deferCreate = false;
const recoveredClosed = mountMoheAi({request}); await flush();
const recoveredClosedRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
recoveredClosedRoot.querySelector('.entry').click(); await flush();
assert.equal(calls.filter(c=>c.path.endsWith('/cancel')).length,closeBeforePending + 1);
recoveredClosed();
// In staged compatibility mode, a page replacement between admission and the
// synchronous trigger must send that exact durable create input once resumed.
window.localStorage.clear(); identityKey = 'fixture:compatibility-recovery'; asyncExecution = false; deferCreate = true; finishCreate = null;
const executeBeforeRecovery = calls.filter(c=>c.path.endsWith('/execute')).length;
const compatibilityRecovery = mountMoheAi({request}); await flush();
const compatibilityRecoveryRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
compatibilityRecoveryRoot.querySelector('.entry').click(); await flush();
compatibilityRecoveryRoot.querySelector('textarea').value = '兼容模式恢复';
Array.from(compatibilityRecoveryRoot.querySelectorAll('button')).find(b=>b.textContent==='发送').click(); await flush();
compatibilityRecovery(); finishCreate({run_id:'r',generation:1,run_delivery_token:'delivery',status:'READY',progress:'已接纳'}); await flush();
deferCreate = false;
const compatibilityResumed = mountMoheAi({request}); await flush();
const compatibilityResumedRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
compatibilityResumedRoot.querySelector('.entry').click(); await flush();
finishStatus({run_id:'r',generation:1,run_delivery_token:'delivery',status:'READY',progress:'已接纳'}); await flush();
assert.equal(calls.filter(c=>c.path.endsWith('/execute')).length,executeBeforeRecovery + 1);
compatibilityResumed();
// A known expiry is final client state, not a network outage. Clear recovery
// data and re-enable a new question instead of polling forever.
window.localStorage.clear(); identityKey = 'fixture:expired'; asyncExecution = true; failStatus = true;
const expired = mountMoheAi({request}); await flush();
const expiredRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
expiredRoot.querySelector('.entry').click(); await flush();
expiredRoot.querySelector('textarea').value = '会过期的任务';
Array.from(expiredRoot.querySelectorAll('button')).find(b=>b.textContent==='发送').click(); await new Promise(resolve=>setTimeout(resolve,1050));
assert.equal(typeof rejectStatus,'function');
const expiredError = new Error('expired'); expiredError.responseKnown = true; expiredError.reason = 'AI_RUN_EXPIRED'; rejectStatus(expiredError); await flush();
assert.equal(window.localStorage.getItem('mohe-ai:v1:fixture%3Aexpired:runtime'),null);
assert.equal(Array.from(expiredRoot.querySelectorAll('button')).find(b=>b.textContent==='发送').disabled,false);
assert.equal(expiredRoot.querySelector('[role=status]').textContent,'上一次任务已失效，请重新提问。');
expired();
// The old page and its replacement can both have an in-flight status request.
// Only the mounted entry may present/persist its terminal answer; otherwise
// the device transcript gets the same completed Run twice.
window.localStorage.clear(); identityKey = 'fixture:retired-terminal'; asyncExecution = true; failStatus = false; collectStatusWaiters = true; statusWaiters.length = 0;
const retired = mountMoheAi({request}); await flush();
const retiredRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
retiredRoot.querySelector('.entry').click(); await flush();
retiredRoot.querySelector('textarea').value = '页面切换期间的终态';
Array.from(retiredRoot.querySelectorAll('button')).find(b=>b.textContent==='发送').click(); await new Promise(resolve=>setTimeout(resolve,1050));
assert.equal(statusWaiters.length,1);
retired();
const replacement = mountMoheAi({request}); await flush();
const replacementRoot = document.querySelector('[data-mohe-ai]').shadowRoot;
  replacementRoot.querySelector('.entry').click(); await flush();
  assert.equal(statusWaiters.length,2);
  assert.match(replacementRoot.textContent,/页面切换期间的终态/);
const terminal = {run_id:'r',generation:1,run_delivery_token:'delivery',version:2,status:'COMPLETED',answer:{summary:'只应保存一次',cards:[]}};
statusWaiters.shift()(terminal); await flush();
statusWaiters.shift()(terminal); await flush();
const terminalRounds = JSON.parse(window.localStorage.getItem('mohe-ai:v1:fixture%3Aretired-terminal')).flatMap(s => s.rounds).filter(r => r.answer === '只应保存一次');
assert.equal(terminalRounds.length,1);
replacement();
collectStatusWaiters = false;
console.log('Browser entry queued create/status polling/cancel/late-admission/recovery-visible-question/expiry/retired-terminal/XSS/capability/admin-no-config: 39 checks PASS');
