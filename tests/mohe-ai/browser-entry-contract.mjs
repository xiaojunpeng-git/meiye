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
const calls = []; let finishExecute;
const request = async (method,path,payload) => {
  calls.push({method,path,payload});
  if (path === '/bootstrap') return {enabled:true,can_configure:true,identity_key:'fixture:user',window_token:'w',capabilities:{metric_codes:['consume_amount'],output_formats:['screen']}};
  if (path === '/runs') return {run_id:'r',generation:1,run_delivery_token:'delivery',status:'READY',progress:'已接纳'};
  if (path.endsWith('/execute')) return new Promise(resolve=>{finishExecute=resolve;});
  if (path === '/runs/r') return {run_id:'r',generation:1,run_delivery_token:'delivery',status:'WORKFLOW_EXECUTING',progress:'正在理解查询条件',version:2};
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
assert.equal(calls.find(c=>c.path.endsWith('/execute')).payload.run_delivery_token,'delivery');
assert.equal(root.querySelector('img'),null);
await new Promise(resolve=>setTimeout(resolve,1050));
assert.equal(root.querySelector('[role=status]').textContent,'正在理解查询条件');
Array.from(root.querySelectorAll('button')).find(b=>b.textContent==='关闭').click(); await flush();
assert.equal(calls.filter(c=>c.path.endsWith('/cancel')).length,1);
assert.equal(root.querySelector('.panel').hidden,true);
finishExecute({run_id:'r',generation:1,status:'COMPLETED',answer:{summary:'迟到结果',cards:[{metric_name:'现金业绩',display_value:'999',unit:'元'}]}}); await flush();
assert.equal(root.querySelector('.value'),null);
// Inspect delivered content, not random IDs/timestamps that may contain "999".
const savedRounds = JSON.parse(window.localStorage.getItem('mohe-ai:v1:fixture%3Auser')).flatMap(s => s.rounds);
assert.equal(savedRounds.some(r => r.answer.includes('迟到结果') || (r.presentation?.cards || []).some(c => c.display_value === '999')), false);
dispose(); assert.equal(document.querySelector('[data-mohe-ai]'),null);
assert.equal(calls.some(c=>c.path.startsWith('/config')),false);
console.log('Browser entry create/async execute/status polling/cancel/late result/XSS/capability/admin-no-config: 16 checks PASS');
