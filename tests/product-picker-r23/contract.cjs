// 执行共用选择器真实方法，检查项目锁定、商品主键/规格主键和跨页选择边界。
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync('前端代码/admin/src/components/goodsAttr/index.vue','utf8');
const script=source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/import[\s\S]*?;/g,'').replace('export default','module.exports=');
const sandbox={module:{exports:{}},mapState:()=>({}),cascaderListApi:()=>Promise.resolve({data:[]}),changeListApi:()=>Promise.resolve({data:{list:[],count:0}}),allLabelApi:()=>Promise.resolve({data:[]})};
vm.runInNewContext(script,sandbox);const c=sandbox.module.exports;
const make=chooseType=>{const state={chooseType,is_new:'',flatSelect:chooseType===98,isCard:false,$Message:{warning:()=>{}},$emit:(name,rows)=>{state.result=rows;}};Object.assign(state,c.data.call(state));for(const[k,v]of Object.entries(c.methods))state[k]=v.bind(state);return state;};
const card=make(97);card.formValidate.product_type=0;card.applyInventoryProductTypeLock();assert.equal(card.formValidate.product_type,6);assert.equal(card.formValidate.limit,20);
card.$refs={xTree:{getCheckboxRecords:()=>[{id:10,product_type:6,attrValue:[{id:100,product_id:10,product_type:6,unique:'sku'}]},{id:20,product_type:0,attrValue:[{id:200,product_id:20,product_type:0}]}],getCheckboxReserveRecords:()=>[]}};card.ok();assert.equal(card.result.length,1);assert.equal(card.result[0].unique,'sku');
const coupon=make(98);coupon.$refs={xTree:{getCheckboxRecords:()=>[{id:10,product_type:6},{id:11,product_type:5},{id:12,product_type:0},{id:99,product_type:1},{id:100,product_id:10,product_type:6}],getCheckboxReserveRecords:()=>[{id:10,product_type:6},{id:13,product_type:4}]}};coupon.ok();assert.deepEqual(Array.from(coupon.result,r=>r.id),[10,11,12,13]);assert.equal(coupon.businessProductTypeLabel(6),'项目');assert.equal(coupon.businessProductTypeLabel(4),'卡项');
coupon.getList=()=>{};coupon.formValidate.page=3;coupon.pageSizeChange(50);assert.equal(coupon.formValidate.page,1);assert.equal(coupon.formValidate.limit,50);
// 优惠券入口继续兼容旧 product_id，同时把共用选择器的商品 id 转成相同保存身份。
const parent=fs.readFileSync('前端代码/admin/src/pages/marketing/storeCouponIssue/create.vue','utf8');assert(parent.includes(':chooseType="98"')&&parent.includes(':flatSelect="true"'));
const method=parent.slice(parent.indexOf('    getProductId(productList) {')+'    getProductId(productList) {'.length,parent.indexOf('    cancel() {')).replace(/},\s*$/,'');
const owner={productList:[],formData:{},unique:arr=>Array.from(new Map(arr.map(r=>[r.product_id,r])).values())};new Function('productList',method).call(owner,[{id:10,product_type:6},{id:11,product_type:5},{id:12,product_type:0}]);assert.equal(owner.formData.product_id,'10,11,12');
console.log('PASS shared picker: project lock/SKU preservation/product identity/selection dedup/types/page size/coupon wiring');
// 后发类型查询先返回时，较慢的旧响应不能覆盖当前选择器，也不能解锁错误的提交状态。
const pending=[];sandbox.changeListApi=()=>new Promise((resolve,reject)=>pending.push({resolve,reject}));
const race=make(98);race.getList();race.formValidate.product_type=6;race.getList();
pending[1].resolve({data:{list:[{id:2,product_type:6,attrValue:[]}],count:1}});
setImmediate(()=>{pending[0].resolve({data:{list:[{id:1,product_type:0,attrValue:[]}],count:1}});setImmediate(()=>{assert.equal(race.tableList[0].id,2);assert.equal(race.loading,false);console.log('PASS latest type query wins over stale response');});});
