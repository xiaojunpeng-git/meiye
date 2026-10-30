// Execute the canonical handler with explicit API outcomes; no customer writes.
const assert=require('assert/strict'),fs=require('fs'),load=require('../r15-reservation-remove/harness.cjs');
const successRoutes=[],tips=[];let calls=[],respond=()=>Promise.resolve({data:{reservationId:99}});
const h=load({postReservationOrderCreate:(...args)=>{calls.push(args);return respond();},openYuyueSubscribe:()=>Promise.resolve(),uni:{navigateTo:x=>successRoutes.push(x)}});
const make=()=>h.create({orderId:6558,cartInfoId:16711,storeId:108,cartNum:1,confirm:[],bookUid:0,verify:()=>true,selectedTimeRange:{begin:'2026-11-01 09:00',end:'2026-11-01 09:50'},targetDay:'2026-11-01',$util:{Tips:x=>tips.push(x)}});
(async()=>{
 for(const error of [{status:400,msg:'预约次数超过剩余权益。'},'网络请求失败',new Error('服务繁忙'),{}, {msg:{oops:1}}, {msg:'SQLSTATE[23000] secret'}]){
  const p=make();respond=()=>Promise.reject(error);await p.reservationOrderCreate();assert.equal(typeof tips.at(-1).title,'string');assert(p.bookingSubmitError);assert.equal(p.bookingSubmitting,false);assert(!p.bookingSubmitError.includes('SQLSTATE'));
 }
 let settle;const p=make();respond=()=>new Promise(r=>settle=r);const before=calls.length;const first=p.reservationOrderCreate();assert.equal(p.bookingSubmitting,true);await p.reservationOrderCreate();assert.equal(calls.length,before+1,'double click prevented');settle({data:{reservationId:99}});await first;assert.equal(p.bookingSubmitting,false);assert.equal(successRoutes.at(-1).url,'/pages/goods/reservation_status/index?orderId=6558&reservationId=99');
 const a=calls.at(-1);assert.equal(a[0],6558);assert.equal(a[1].cart_info_id,16711);assert.equal(a[1].store_id,108);assert.equal(a[1].reservation_start,'09:00');assert.equal(a[1].cart_num,1);assert.deepEqual(a[1].staff_choose,undefined);
 respond=()=>{throw Error('同步请求异常');};await p.reservationOrderCreate();assert.equal(p.bookingSubmitError,'同步请求异常');assert.equal(p.bookingSubmitting,false);
 respond=()=>Promise.resolve({data:{reservationId:100}});await p.reservationOrderCreate();assert.equal(p.bookingSubmitError,'');assert(successRoutes.at(-1).url.endsWith('reservationId=100'));
 const invalid=make();invalid.verify=()=>false;const n=calls.length;await invalid.reservationOrderCreate();assert.equal(calls.length,n);invalid.selectedTimeRange=null;await invalid.reservationOrderCreate();assert.equal(calls.length,n);
 fs.writeFileSync(__dirname+'/evidence/model.json',JSON.stringify({result:'PASS',structuredErrors:true,stringAndNetworkErrors:true,safeFallback:true,inFlightGuard:true,successRoute:true,synchronousFailure:true,retry:true,validationAndPayloadPreserved:true,productionRequests:0},null,2));console.log('PASS structured/string/error fallback, busy/double click, unchanged payload, validation, sync failure, retry and success navigation');
})().catch(e=>{console.error(e);process.exitCode=1;});
