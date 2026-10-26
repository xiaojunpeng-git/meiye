// Execute the production Vue methods with deterministic provider/network timing.
// No gateway or business database is contacted by this payment-return regression.
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const require=createRequire('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/package.json');
const parser=require('@babel/parser');
const text=fs.readFileSync('前端代码/uniapp/pages/goods/cashier/index.vue','utf8').match(/<script>([\s\S]*?)<\/script>/)[1].replace(/\/\/ #ifdef APP-PLUS[\s\S]*?\/\/ #endif/g,'');
const ast=parser.parse(text,{sourceType:'module'});
const component=ast.program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;
const methods=component.properties.find(n=>n.key?.name==='methods').value;
function compile(p,ctx){return vm.runInNewContext('(function('+p.params.map(p=>text.slice(p.start,p.end)).join(',')+')'+text.slice(p.body.start,p.body.end)+')',ctx);}
function fixture(read){
 let next=0;const timers=new Map(),notices=[],destinations=[];let reads=0,pays=0;
 const ctx={getOrderDetail:()=>{reads++;return read(reads);},orderPay:()=>{pays++;throw Error('must not charge');},
  setTimeout:(fn,delay)=>{timers.set(++next,{fn,delay});return next;},clearTimeout:id=>timers.delete(id),Promise,Error,Number,encodeURIComponent};
 const state={orderId:'wx-test',fromType:'order',paying:true,awaitingPaymentConfirmation:true,checkingPayment:false,paymentCheckTimer:null,paymentCheckRun:0,
  $util:{Tips:n=>notices.push(n.title)},pageReject:(msg,url)=>destinations.push({msg,url})};
 for(const name of ['stopPaymentCheck','checkPaymentAfterReturn','goPay'])state[name]=compile(methods.properties.find(p=>p.key?.name===name),ctx);
 const hooks={};for(const name of ['onShow','onHide','onUnload'])hooks[name]=compile(component.properties.find(p=>p.key?.name===name),ctx);
 return {state,timers,notices,destinations,hooks,get reads(){return reads;},get pays(){return pays;},async tick(delay){const entry=[...timers].find(([,v])=>v.delay===delay);assert(entry,'missing timer '+delay);timers.delete(entry[0]);entry[1].fn();await flush();}};
}
async function flush(){for(let i=0;i<12;i++)await Promise.resolve();}
let f=fixture(async()=>({data:{paid:1}}));f.hooks.onShow.call(f.state);await flush();assert.equal(f.destinations.length,1);assert.match(f.destinations[0].url,/order_pay_status/);assert.equal(f.state.paying,false);assert.equal(f.timers.size,0);assert.equal(f.pays,0);console.log('PASS server paid returns to result without second payment');
f=fixture(async n=>({data:{paid:n>=3?1:0}}));f.state.goPay();await flush();await f.tick(1500);await f.tick(1500);assert.equal(f.reads,3);assert.equal(f.destinations.length,1);console.log('PASS delayed callback is polled to paid');
f=fixture(async()=>({data:{paid:0}}));f.state.goPay();await flush();for(let i=0;i<7;i++)await f.tick(1500);assert.equal(f.reads,8);assert.equal(f.state.checkingPayment,false);assert.equal(f.state.awaitingPaymentConfirmation,true);assert.equal(f.destinations.length,0);f.state.goPay();await flush();assert.equal(f.reads,9);assert.equal(f.pays,0);console.log('PASS pending read is bounded and manual retry only checks status');
f=fixture(async()=>{throw Error('offline');});await f.state.checkPaymentAfterReturn();assert.equal(f.state.checkingPayment,false);assert.equal(f.destinations.length,0);await f.state.goPay();assert.equal(f.reads,2);assert.equal(f.pays,0);console.log('PASS network errors unlock query-only retry');
let resolve;f=fixture(()=>new Promise(r=>resolve=r));f.state.goPay();await flush();await f.tick(10000);assert.equal(f.state.checkingPayment,false);resolve({data:{paid:1}});await flush();assert.equal(f.destinations.length,0);console.log('PASS hung read times out and ignores late paid response');
for(const hook of ['onHide','onUnload']){let resolve;f=fixture(()=>new Promise(r=>resolve=r));f.state.goPay();await flush();f.hooks[hook].call(f.state);resolve({data:{paid:1}});await flush();assert.equal(f.timers.size,0);assert.equal(f.destinations.length,0);}console.log('PASS hidden/unloaded page cancels timers and late navigation');
f=fixture(async()=>({data:{paid:0}}));f.state.goPay();f.state.goPay();await flush();assert.equal(f.reads,1);assert.equal(f.pays,0);console.log('PASS repeated taps cannot duplicate payment or status run');
