// Execute the canonical confirmation methods for both H5 and WeChat. Only the
// native navigation/payment boundary is replaced; these tests never place orders.
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert/strict');
const root=process.env.MEMBER_CONTACT_TEST_ROOT||path.resolve(__dirname,'../..');
const cli='/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const compiler=require(cli+'/vue-template-compiler'),parser=require(cli+'/@babel/parser');
const preprocess=require(cli+'/@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess').preprocess;
const source=fs.readFileSync(root+'/前端代码/uniapp/pages/goods/order_confirm/index.vue','utf8');
const sfc=compiler.parseComponent(source);
async function scenario(mp){
 const flags=mp?{MP:true,MP_WEIXIN:true}:{H5:true};
 const template=preprocess(sfc.template.content,flags,{type:'html'});
 assert.deepEqual(compiler.compile(template).errors,[]);
 assert.doesNotMatch(template,/v-model="contacts|请填写姓名|请填写电话|contactShow/);
 const script=preprocess(sfc.script.content,flags,{type:'js'});
 const ast=parser.parse(script,{sourceType:'module'}),obj=ast.program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;
 const methods=obj.properties.find(n=>n.key.name==='methods').value;
 const sent=[],tips=[];
 const context={uni:{showLoading(){}},location:{protocol:'https:',hostname:'local.invalid'},openPaySubscribe:()=>Promise.resolve()};
 const method=name=>{const node=methods.properties.find(n=>n.key.name===name);return vm.runInNewContext('({'+script.slice(node.start,node.end)+'}).'+name,context);};
 const verify=method('formVerify'),submit=method('SubOrder');
 const page=()=>({tableId:0,addressId:0,shippingType:2,product_type:1,productTypeNum:0,confirmShow:false,type:3,storeId:11,
  priceGroup:{couponPrice:0},payType:'weixin',totalPrice:198,system_store:{},payment:data=>sent.push(data),$util:{Tips:x=>tips.push(x.title)}});
 for(const type of [0,3])for(const productTypeNum of [0,6]){
  let submitted=0;const p={...page(),type,productTypeNum,SubOrder(){submitted++;}};
  // Empty, short or unusual member names/phones cannot resurrect the deleted form gate.
  verify.call(p);assert.equal(submitted,1);assert.equal(tips.length,0);
  submit.call({...page(),type,productTypeNum});await Promise.resolve();
  const data=sent.pop();assert.ok(data);assert.equal(data.shipping_type,2);assert.equal(data.store_id,11);
  assert.equal(Object.hasOwn(data,'real_name'),false);assert.equal(Object.hasOwn(data,'phone'),false);
 }
 submit.call({...page(),storeId:0});assert.match(tips.pop(),/选择购买门店/);assert.equal(sent.length,0);
 let submitted=0;verify.call({...page(),confirmShow:true,confirm:[[{name:'texts',valConfig:{tabVal:0},titleShow:{val:true},titleConfig:{value:'业务资料'},value:''}]],SubOrder(){submitted++;}});
 assert.equal(submitted,0);assert.match(tips.pop(),/业务资料/);
 console.log('PASS '+(mp?'WeChat':'H5')+': group/ordinary cards and projects submit without contact; payload omits duplicate identity; store and business-form gates remain');
}
(async()=>{await scenario(false);await scenario(true);})().catch(e=>{console.error(e);process.exitCode=1;});
