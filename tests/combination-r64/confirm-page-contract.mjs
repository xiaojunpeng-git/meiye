import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const require=createRequire('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/package.json');
const parser=require('@babel/parser');
const vue=fs.readFileSync('前端代码/uniapp/pages/goods/order_confirm/index.vue','utf8');
// H5 and APP-PLUS declare alternative object properties without a shared comma.
// Exclude the APP branch exactly as the H5 compiler does before parsing.
const script=vue.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/\/\/ #ifdef APP-PLUS[\s\S]*?\/\/ #endif/g,'');
const ast=parser.parse(script,{sourceType:'module'});
const component=ast.program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;
const methods=component.properties.find(n=>n.key?.name==='methods').value;
const source=name=>{const p=methods.properties.find(n=>n.key?.name===name);assert(p,'Missing production method '+name);if(p.value)return '('+script.slice(p.value.start,p.value.end)+')';return '('+(p.async?'async ':'')+'function('+p.params.map(v=>script.slice(v.start,v.end)).join(',')+')'+script.slice(p.body.start,p.body.end)+')';};
// Real controller quotes are generated from rollback-only fixtures. Execute the
// production hydration and form validator instead of duplicating either algorithm.
const quotes=JSON.parse(fs.readFileSync(process.argv[2]||'tests/combination-r64/evidence/confirm-responses.json','utf8'));
for(const fixture of quotes){
 const notices=[];let submitted=0;
 const context={
  orderConfirm:async()=>({data:fixture.quote}),CACHE_CITY:{},dayjs:()=>({format:()=>''}),
  console,parseInt,parseFloat,Number,Promise
 };
 const getConfirm=vm.runInNewContext(source('getConfirm'),context);
 const objToArr=vm.runInNewContext(source('objToArr'),context);
 const formVerify=vm.runInNewContext(source('formVerify'),context);
 const state={shippingType:2,deliveryType:2,storeId:fixture.storeId,cartId:fixture.cartId,news:1,
  addressId:0,couponId:0,luckRecordId:'',isStoreDeliveryType:0,groupCartId:[],totalPrice:0,confirm:[],
  orderErr:false,confirmShow:false,tableId:0,
  $set(target,key,value){target[key]=value;},$util:{Tips:notice=>notices.push(notice),$h:{Add:(a,b)=>a+b}},
  objToArr,getCouponList(){},computedPrice(){},productCardRelated(){},SubOrder(){submitted++;}};
 getConfirm.call(state,'',1);
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(state.orderErr,false,'Quote hydration threw: '+JSON.stringify(notices));
 assert.equal(state.cartInfo.length,1);assert.equal(state.valid_count,1);
 assert.equal(Number(state.totalPrice),fixture.expectedPrice??399);assert.equal(state.combinationId,fixture.quote.combination_id);
 assert.equal(state.contacts,'本地测试');assert.equal(state.contactsTel,'19900000064');
 formVerify.call(state);assert.equal(submitted,1,'A valid quote must pass the production submit validator');
 assert.deepEqual(notices,[]);
 console.log('PASS type '+fixture.productType+' actual confirmation page retains item/amount/contact and permits submit');
}
