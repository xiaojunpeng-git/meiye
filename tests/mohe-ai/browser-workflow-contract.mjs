import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';
import { mountMoheAi } from '../../前端代码/shared/mohe-ai/browser-entry.mjs';
import { browserTransport } from '../../前端代码/shared/mohe-ai/browser-transport.mjs';
const require=createRequire(import.meta.url);
const {JSDOM}=require('../../前端代码/admin/node_modules/jsdom');
const flush=()=>new Promise(r=>setTimeout(r,12));let checks=0;
function eq(a,b){assert.deepEqual(a,b);checks++;}
function setup({delayCreate=false,clarification=false}={}) {
  const dom=new JSDOM('<!doctype html><body></body>',{url:'https://fixture.test'});
  // Old jsdom has no native shadow root: actual DOM events + real transport,
  // shim only supplies the root. This is not a browser layout or live API test.
  dom.window.HTMLElement.prototype.attachShadow=function(){const root=dom.window.document.createElement('section');this.appendChild(root);Object.defineProperty(this,'shadowRoot',{value:root});return root;};
  globalThis.window=dom.window;globalThis.document=dom.window.document;globalThis.crypto=webcrypto;
  let configured=true,sequence=0,releaseCreate;const calls=[];
  const json=data=>({ok:true,status:200,json:async()=>({status:200,data})});
  globalThis.fetch=async(url,options)=>{
    const body=options.body?JSON.parse(options.body):null;calls.push({url,method:options.method,body,headers:options.headers});
    if(url.endsWith('/bootstrap'))return json({enabled:configured,can_configure:true,identity_key:'account-one',window_token:'window',capabilities:{metric_codes:['consume_amount'],output_formats:configured?['screen','screen_and_xlsx']:['screen']}});
    if(url.endsWith('/runs')){const result=json({run_id:'run'+(++sequence),generation:1,run_delivery_token:'delivery',status:'RECEIVED'});return delayCreate?new Promise(resolve=>{releaseCreate=()=>resolve(result);}):result;}
    const id=url.match(/\/runs\/(run\d+)/)?.[1];
    if(clarification && (url.endsWith('/execute') || options.method==='GET')) {
      const result=json({run_id:id,generation:1,status:'WAITING_CLARIFICATION',clarification:{id:'clarify-one',fields:[{key:'metric',label:'业绩指标',options:[{label:'现金业绩',value:'cash'}]}]}});
      return url.endsWith('/execute')?new Promise(resolve=>setTimeout(()=>resolve(result),1150)):result;
    }
    if(url.endsWith('/execute'))return json({run_id:id,generation:1,status:'COMPLETED',answer:{summary:'已校验答案'+sequence,context_ref:'fixture-context-'+sequence,cards:[{metric_name:'消耗业绩',display_value:'123',unit:'元'}]}});
    if(url.endsWith('/cancel'))return json({run_id:id,generation:1,status:'CANCELLED'});
    throw new Error('unexpected fixture endpoint');
  };
  const dispose=mountMoheAi({request:browserTransport('https://fixture.test/adminapi/ai',()=> 'fixture-token')});
  const root=()=>document.querySelector('[data-mohe-ai]').shadowRoot;
  const click=(text)=>{const node=Array.from(root().querySelectorAll('button')).find(n=>n.textContent===text);assert.ok(node,'button '+text);node.click();};
  const enable=async()=>{await flush();click('魔核 AI');await flush();};
  const send=async(text)=>{root().querySelector('textarea').value=text;click('发送');await flush();};
  return {root,click,enable,send,calls,dispose,release:()=>releaseCreate()};
}
{
  const f=setup();await f.enable();
  eq(Array.from(f.root().querySelectorAll('button')).some(n=>n.textContent==='配置'),false);
  eq(f.calls.some(c=>c.url.endsWith('/config')),false);
  eq(f.calls.some(c=>c.url.endsWith('/config/check')),false);
  eq(f.root().querySelector('input[type=password]'),null);
  eq(f.root().querySelector('option[value=screen_and_xlsx]').disabled,false);
  await f.send('今天消耗业绩多少？');await f.send('昨天消耗业绩多少？');
  const creates=f.calls.filter(c=>c.url.endsWith('/runs'));
  eq(creates.length,2);eq(creates[0].body.history,[]);
  eq(creates[1].body.history,[{question:'今天消耗业绩多少？',answer:'已校验答案1'}]);
  eq(creates[1].body.conversation_id,creates[0].body.conversation_id);
  eq(Object.prototype.hasOwnProperty.call(creates[0].body,'context_ref'),false);
  eq(creates[1].body.context_ref,'fixture-context-1');
  eq(f.calls.filter(c=>c.url.endsWith('/execute'))[1].body.context_ref,'fixture-context-1');
  f.click('新对话');await f.send('本月消耗业绩多少？');
  const third=f.calls.filter(c=>c.url.endsWith('/runs'))[2];eq(third.body.history,[]);assert.notEqual(third.body.conversation_id,creates[0].body.conversation_id);checks++;
  eq(Object.prototype.hasOwnProperty.call(third.body,'context_ref'),false);
  f.click('历史');f.click('今天消耗业绩多少？');
  eq(f.root().querySelector('[role=status]').textContent,'');
  eq(f.root().querySelectorAll('.value').length,2);
  await f.send('前天消耗业绩多少？');
  const fourth=f.calls.filter(c=>c.url.endsWith('/runs'))[3];eq(fourth.body.conversation_id,creates[0].body.conversation_id);eq(fourth.body.history.length,2);
  eq(fourth.body.context_ref,'fixture-context-2'); // not the last answer in another conversation
  eq(f.calls.filter(c=>c.url.endsWith('/execute'))[3].body.context_ref,'fixture-context-2');
  eq(window.localStorage.getItem('mohe-ai:v1:account-one').includes('fixture-key-not-real'),false);
  f.dispose();
}
{
  const f=setup({clarification:true});await f.enable();await f.send('今天业绩多少？');
  await new Promise(resolve=>setTimeout(resolve,1300));
  eq(Array.from(f.root().querySelectorAll('button')).filter(n=>n.textContent==='确认查询').length,1);
  f.click('停止');await flush();
  eq(Array.from(f.root().querySelectorAll('button')).filter(n=>n.textContent==='确认查询').length,0);
  f.dispose();
}
for(const action of ['close','dispose']) {
  const f=setup({delayCreate:true});await f.enable();await f.send('今天消耗业绩多少？');
  if(action==='close')f.click('关闭');else f.dispose();
  eq(f.calls.filter(c=>c.url.endsWith('/cancel')).length,0);
  f.release();await flush();await flush();
  eq(f.calls.filter(c=>c.url.endsWith('/cancel')).length,1);
  eq(f.calls.filter(c=>c.url.endsWith('/execute')).length,0);
  if(action==='close'){eq(f.root().querySelector('.panel').hidden,true);f.dispose();}
}
console.log(`Browser workflow + transport: ${checks} checks PASS (DOM/HTTP fixtures, no live account)`);
