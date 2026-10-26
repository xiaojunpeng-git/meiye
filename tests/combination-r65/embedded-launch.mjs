// Exercise the entire production goPay -> provider-launch -> authoritative-read
// path. No page-return event is fabricated unless a case explicitly tests it.
// All network/provider calls are local fakes; no order or charge is created.
import fs from 'node:fs';
import vm from 'node:vm';
import {execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const require=createRequire('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/package.json');
const parser=require('@babel/parser');
// A released ref may be selected read-only to prove the same case failed before this fix.
const source=process.env.R65_SOURCE_REF?execFileSync('git',['show',process.env.R65_SOURCE_REF+':前端代码/uniapp/pages/goods/cashier/index.vue'],{encoding:'utf8'}):fs.readFileSync('前端代码/uniapp/pages/goods/cashier/index.vue','utf8');
const script=source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/\/\/\s*#ifdef (?:APP-PLUS|H5)[\s\S]*?\/\/\s*#endif/g,'');
const component=parser.parse(script,{sourceType:'module'}).program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;
const methods=component.properties.find(n=>n.key?.name==='methods').value;
const flush=async()=>{for(let i=0;i<20;i++)await Promise.resolve();};
function fixture(read){
 const timers=new Map(),notices=[],destinations=[];let n=0,pays=0,reads=0,launch=null;
 const ctx={Promise,Number,Error,encodeURIComponent,
  setTimeout:(fn,delay)=>{timers.set(++n,{fn,delay});return n;},clearTimeout:id=>timers.delete(id),
  uni:{showLoading(){},hideLoading(){},setStorageSync(){}},
  wx:{openEmbeddedMiniProgram:options=>{launch=options;}},
  orderPay:async()=>{pays++;return {data:{status:'WECHAT_PAY',result:{jsConfig:{qr_code:'LOCAL-ONLY',app_id:'LOCAL-ONLY'}}}};},
  getOrderDetail:async()=>{reads++;return read(reads);}
 };
 const state={orderId:'LOCAL-UNPAID',fromType:'order',active:0,paytype:'weixin',paying:false,is_bak:0,awaitingPaymentConfirmation:false,checkingPayment:false,paymentCheckTimer:null,paymentCheckRun:0,paymentPageVisible:true,
  $util:{Tips:n=>notices.push(n)},pageReject:(msg,url)=>destinations.push({msg,url})};
 const compile=p=>vm.runInNewContext('(function('+p.params.map(v=>script.slice(v.start,v.end)).join(',')+')'+script.slice(p.body.start,p.body.end)+')',ctx);
 for(const name of ['goPay','stopPaymentCheck','checkPaymentAfterReturn'])state[name]=compile(methods.properties.find(p=>p.key?.name===name));
 const hook=name=>compile(component.properties.find(p=>p.key?.name===name)).call(state);
 return {state,hook,timers,notices,destinations,get launch(){return launch;},get pays(){return pays;},get reads(){return reads;},async tick(){const entry=[...timers].find(([,v])=>v.delay===1500);assert(entry,'expected scheduled status read');timers.delete(entry[0]);entry[1].fn();await flush();}};
}
let f=fixture(n=>({data:{paid:n>=3?1:0}}));f.state.goPay();await flush();assert(f.launch);assert.equal(f.state.paying,false,'Provider preparation MUST clear the launch spinner without onShow');assert.equal(f.state.awaitingPaymentConfirmation,true);f.launch.success();await flush();assert.equal(f.reads,1,'Opening success must start status reads without onShow');await f.tick();await f.tick();assert.equal(f.destinations.length,1);assert.equal(f.pays,1);console.log('PASS no page lifecycle event: launch clears spinner and delayed paid navigates');
f=fixture(()=>({data:{paid:1}}));f.state.goPay();await flush();assert.equal(f.state.paying,false);assert.equal(f.reads,0);await f.state.goPay();assert.equal(f.pays,1);assert.equal(f.destinations.length,1);console.log('PASS no native callbacks: button queries status instead of charging again');
f=fixture(()=>({data:{paid:1}}));f.state.goPay();await flush();f.hook('onHide');f.launch.success();await flush();assert.equal(f.reads,0,'No background read after hide');f.hook('onShow');await flush();assert.equal(f.destinations.length,1);assert.equal(f.pays,1);console.log('PASS full-screen hide/show resumes same order without duplicate charge');
f=fixture(()=>({data:{paid:1}}));f.state.goPay();await flush();f.launch.fail({errMsg:'LOCAL launch rejected'});assert.equal(f.state.paying,false);assert.equal(f.state.awaitingPaymentConfirmation,false);assert.equal(f.reads,0);assert.equal(f.destinations.length,0);console.log('PASS rejected provider launch restores the original payment entry');
f=fixture(()=>({data:{paid:0}}));f.state.goPay();await flush();f.launch.success();await flush();for(let i=0;i<7;i++)await f.tick();assert.equal(f.state.checkingPayment,false);assert.equal(f.state.awaitingPaymentConfirmation,true);assert.equal(f.destinations.length,0);assert.equal(f.pays,1);console.log('PASS opening success is never payment success; pending remains query-only');
