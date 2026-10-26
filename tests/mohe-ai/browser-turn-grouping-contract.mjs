import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';
import { mountMoheAi } from '../../前端代码/shared/mohe-ai/browser-entry.mjs';

const require=createRequire(import.meta.url);
const {JSDOM}=require('../../前端代码/admin/node_modules/jsdom');
const dom=new JSDOM('<!doctype html><body></body>',{url:'https://fixture.test'});
dom.window.HTMLElement.prototype.attachShadow=function(){const root=dom.window.document.createElement('section');this.appendChild(root);Object.defineProperty(this,'shadowRoot',{value:root});return root;};
globalThis.window=dom.window;globalThis.document=dom.window.document;globalThis.crypto=webcrypto;
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
let sequence=0;
const request=async (method,path)=>{
  if(path==='/bootstrap') return {enabled:true,async_execution:true,can_configure:true,identity_key:'fixture:turns',window_token:'w',capabilities:{metric_codes:['metric'],output_formats:['screen']}};
  if(path==='/runs') return {run_id:'run-'+(++sequence),generation:1,run_delivery_token:'delivery',status:'RECEIVED',progress:'已接纳'};
  if(path.startsWith('/runs/run-') && method==='GET') return {run_id:path.split('/')[2],generation:1,run_delivery_token:'delivery',status:'FAILED',message:'本次未能完成'};
  throw new Error('unexpected request '+method+' '+path);
};
const dispose=mountMoheAi({request}); await pause(12);
const root=document.querySelector('[data-mohe-ai]').shadowRoot;
Array.from(root.querySelectorAll('button')).find(button=>button.textContent==='魔核 AI').click(); await pause(12);
for(let i=0;i<2;i++) {
  root.querySelector('textarea').value='同一句问题';
  Array.from(root.querySelectorAll('button')).find(button=>button.textContent==='发送').click();
  await pause(1100);
}
const questions=Array.from(root.querySelectorAll('.question')).map(node=>node.textContent);
assert.deepEqual(questions,['同一句问题','同一句问题']);
assert.equal(root.textContent.split('本次未能完成').length-1,2);
dispose();
console.log('Browser terminal turn grouping: 2 checks PASS');
