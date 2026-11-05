// Execute the real navigation/onLoad methods; activity identity must survive search and region queries.
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const detail=fs.readFileSync('前端代码/uniapp/pages/activity/goods_details/index.vue','utf8');
let start=detail.indexOf('showStoreList: function() {'),end=detail.indexOf('\n\t\t\t},',start)+7;
const calls=[];const fn=vm.runInNewContext('({'+detail.slice(start,end)+'}).showStoreList',{uni:{navigateTo:x=>calls.push(x.url)},encodeURIComponent});
fn.call({storeShow:true,type:3,id:4,storeInfo:{id:4,product_id:8996},storeList:{id:1}});let u=new URL('https://local.invalid'+calls.pop());assert.equal(u.searchParams.get('combination_id'),'4');assert.equal(u.searchParams.get('product_id'),'8996');
fn.call({storeShow:true,type:1,id:4,shopType:1,belongShow:true,storeInfo:{id:99,pid:88},storeList:{id:1}});u=new URL('https://local.invalid'+calls.pop());assert.equal(u.searchParams.get('product_id'),'88');assert.equal(u.searchParams.has('combination_id'),false);
fn.call({storeShow:true,type:1,shopType:2,belongShow:true,storeInfo:{id:99},storeList:{id:1}});assert.equal(calls.length,0);
const list=fs.readFileSync('前端代码/uniapp/pages/store/list/index.vue','utf8');start=list.indexOf('onLoad(options) {');end=list.indexOf('\n\t\tonShow()',start);let method=list.slice(start,end).replace(/,\s*$/,'');
const onLoad=vm.runInNewContext('({'+method+'}).onLoad');let query;
const page={content:{},isReservationMode:false,getlist(){query={...this.content}}};onLoad.call(page,{type:1,storeId:1,isCollage:1,product_id:8996,combination_id:4});assert.equal(query.combination_id,4);assert.equal(query.product_id,8996);
console.log('COMBINATION STORES FRONTEND PASS: activity/source identity, ordinary mode, original permission gate, list query inheritance');
const windowSource=fs.readFileSync('前端代码/uniapp/components/productWindow/index.vue','utf8');
start=windowSource.indexOf('getList: function(id) {');end=windowSource.indexOf('\n\t\t\tdefaultStore()',start);const getMethod=windowSource.slice(start,end).replace(/,\s*$/,'');
const requests=[],events=[];const rows=[{id:1,name:'万象城店'},{id:2,name:'滨北店'}];
const getList=vm.runInNewContext('({'+getMethod+'}).getList',{getSelectableStores:q=>{requests.push(['activity',q]);return Promise.resolve({data:rows})},storeListApi:q=>{requests.push(['ordinary',q]);return Promise.resolve({data:{list:{list:rows,count:2}}})}});
const selection=windowSource.slice(windowSource.indexOf('defaultStore(){'),windowSource.indexOf('\n\t\t\tshowLocation()',windowSource.indexOf('defaultStore(){'))).replace(/,\s*$/,'');
const defaultStore=vm.runInNewContext('({'+selection+'}).defaultStore');
(async()=>{
 const p={type:3,storeInfo:{id:4},productId:8996,storeId:2,nearbyStore:1,defaultStore,$emit:(...x)=>events.push(x),$util:{Tips:x=>{throw Error(JSON.stringify(x))}}};
 getList.call(p);await Promise.resolve();await Promise.resolve();assert.equal(requests[0][0],'activity');assert.equal(requests[0][1].combination_id,4);assert.equal(requests[0][1].product_id,8996);assert.equal(p.active,1);assert.equal(events.find(x=>x[0]==='onstoreId')[1].id,2);
 p.type=1;getList.call(p);await Promise.resolve();await Promise.resolve();assert.equal(requests[1][0],'ordinary');assert.equal(requests[1][1].type,1);
 p.type=3;p.storeInfo={};getList.call(p);assert.equal(requests.length,2);
 console.log('PRODUCT WINDOW PASS: same activity query, chosen cross-region store retained, ordinary API unchanged, incomplete activity waits');
})().catch(e=>{console.error(e);process.exitCode=1});
