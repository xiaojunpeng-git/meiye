import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';
import { mountMoheAi } from '../../前端代码/shared/mohe-ai/browser-entry.mjs';

const require = createRequire(import.meta.url);
const { JSDOM } = require('../../前端代码/admin/node_modules/jsdom');
const dom = new JSDOM('<!doctype html><body></body>', { url:'https://fixture.test' });
dom.window.HTMLElement.prototype.attachShadow = function () {
  const root = dom.window.document.createElement('section'); this.appendChild(root);
  Object.defineProperty(this,'shadowRoot',{value:root}); return root;
};
globalThis.window=dom.window; globalThis.document=dom.window.document; globalThis.crypto=webcrypto;
const calls=[];
const request=async (method,path,payload) => {
  calls.push({method,path,payload});
  if (path==='/bootstrap') return {enabled:true,can_configure:true,identity_key:'fixture',window_token:'window',
    capabilities:{metric_codes:['cash_performance'],output_formats:['screen','screen_and_xlsx']}};
  if (path==='/runs') return {run_id:'r36',generation:1,run_delivery_token:'proof',status:'READY',execution_mode:'compatibility'};
  if (path==='/runs/r36/execute') return {run_id:'r36',generation:1,run_delivery_token:'proof',status:'COMPLETED',
    answer:{summary:'已校验现金业绩',cards:[]}};
  if (path==='/runs/r36/delivery') return {accepted:true};
  if (path==='/runs/r36/export' && method==='POST') return {status:'succeeded',filename:'经营数据.xlsx'};
  throw new Error(`unexpected ${method} ${path}`);
};
const flush=()=>new Promise(resolve=>setTimeout(resolve,20));
const dispose=mountMoheAi({request}); await flush();
const root=document.querySelector('[data-mohe-ai]').shadowRoot;
root.querySelector('.entry').click(); await flush();
const select=root.querySelector('select'); select.value='screen_and_xlsx';
root.querySelector('textarea').value='本月现金业绩多少';
Array.from(root.querySelectorAll('button')).find(button=>button.textContent==='发送').click();
await flush(); await flush();
assert.equal(calls.find(call=>call.path==='/runs').payload.output_format,'screen');
assert.equal(calls.find(call=>call.path==='/runs/r36/execute').payload.output_format,'screen');
assert.ok(calls.some(call=>call.method==='POST' && call.path==='/runs/r36/export'));
assert.match(root.textContent,/已校验现金业绩/);
assert.match(root.textContent,/Excel 已生成/);
assert.ok(Array.from(root.querySelectorAll('button')).some(button=>button.textContent==='下载 Excel'));
dispose();
console.log('Browser independent Excel after answer: PASS');
