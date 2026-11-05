// Run canonical route/login and Vue handlers with local transport doubles.
// No test creates a real cart, order, payment, or member account.
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert/strict');
const root=process.env.MEMBER_GUEST_TEST_ROOT||path.resolve(__dirname,'../..');
const cli='/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const compiler=require(cli+'/vue-template-compiler'),parser=require(cli+'/@babel/parser');
const preprocess=require(cli+'/@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess').preprocess;
const read=p=>fs.readFileSync(root+'/前端代码/uniapp/'+p,'utf8');
const query=url=>Object.fromEntries(new URL(url,'https://local.invalid').searchParams);
const tick=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
let passed=0;function check(value,label){assert(value,label);passed++;console.log('PASS',label);}
async function scenario(mp){
 const flags=mp?{MP:true,MP_WEIXIN:true}:{H5:true},nav=[],cache=new Map(),interceptors={},calls=[];
 let pages=[],recovery=null;
 const store={getters:{isLogin:false},state:{app:{token:'',userInfo:{},uid:0}},commit(k,v){if(k==='LOGOUT'){this.getters.isLogin=false;this.state.app.userInfo={};}if(k==='UPDATE_USERINFO')this.state.app.userInfo=v;if(k==='SETUID')this.state.app.uid=v;}};
 const c=vm.createContext({console,Promise,Number,String,Object,Array,Math,JSON,encodeURIComponent,decodeURIComponent,
  LOGIN_STATUS:'token',USER_INFO:'user',Cache:{get:k=>cache.get(k),set:(k,v)=>cache.set(k,v),clear:k=>cache.delete(k),time:()=>0},
  getMemberSessionRecovery:()=>recovery,getCurrentPages:()=>pages,Debounce:fn=>fn,isWeixin:()=>false,
  location:{pathname:'/pages/activity/goods_details/index',search:'?id=11&type=3&spid=101'},
  window:{location:{origin:'https://local.invalid'}},app:{globalData:{}},mapGetters:()=>({}),needsMemberProfileCompletion:()=>false,
  uni:{addInterceptor:(k,v)=>interceptors[k]=v,getStorageSync:k=>cache.get(k)||'',showToast(){},showLoading(){},hideLoading(){},
   navigateTo:o=>nav.push(o.url),redirectTo:o=>nav.push(o.url),reLaunch:o=>{nav.push(o.url);o.complete&&o.complete();},switchTab:o=>nav.push(o.url)},
  postCartAdd:d=>{calls.push(d);return Promise.resolve({data:{cartId:'LOCAL_CART'}});},
  WX_AUTH:'wx',STATE_KEY:'state',wechatAuthLogin:()=>Promise.resolve({data:{token:'LOCAL_TOKEN',expires_time:9999}}),
  getUserInfo:()=>Promise.resolve({data:{uid:2,phone:'LOCAL_PHONE',belong_store_id:12}}),silenceBindingSpread(){},
  getCombinationDetail:()=>Promise.resolve({data:{storeInfo:{type:1,relation_id:0,specs:[],slider_image:[],delivery_type:[2],price:198,title:'Public Activity'},productAttr:[],productValue:{B:{unique:'sku-B',suk:'B',stock:10,quota:5}},pink_ok_list:[],pink:[]}}),
  getCombinationPublicActivity:id=>Promise.resolve({data:{pink_id:id,combination_id:11}}),
  getCombinationPink:()=>Promise.resolve({data:{store_combination:{type:1,relation_id:0,id:11,product_id:91,title:'Activity',num:5,delivery_type:[2],productAttr:[],productValue:{B:{unique:'sku-B',suk:'B',stock:10,quota:5,cart_num:2}}},pinkT:{stop_time:Math.floor(Date.now()/1000)+3600},pinkAll:[],count:1,userBool:0,pinkBool:0,is_ok:0,current_pink_order:'',userInfo:{uid:2},store_combination_host:[]}})
 });
 function run(p){return vm.runInContext(preprocess(read(p),flags,{type:'js'}).replace(/import[\s\S]*?from\s+['"][^'"]+['"];?/g,'').replace(/export /g,''),c);}
 run('utils/memberPhoneGate.js');c.installMemberPhoneGate(store);
 run('utils/memberCombinationWindow.js');c.resolveCombinationPhase=c.combinationPhase;c.setInterval=()=>null;c.clearInterval=()=>{};
 run('utils/memberLoginReturn.js');c.store=store;run('libs/login.js');
 function sfc(p){const parts=compiler.parseComponent(read(p));assert.deepEqual(compiler.compile(preprocess(parts.template.content,flags,{type:'html'})).errors,[]);const code=preprocess(parts.script.content,flags,{type:'js'});const tree=parser.parse(code,{sourceType:'module'});const obj=tree.program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;const get=name=>obj.properties.find(n=>n.key.name===name);
  const methods=vm.runInContext('('+code.slice(get('methods').value.start,get('methods').value.end)+')',c);
  const computed=get('computed').value;const cp=vm.runInContext('('+code.slice(computed.start,computed.end)+')',c);
  const onLoad=get('onLoad');return {methods,computed:cp,onLoad:vm.runInContext('({'+code.slice(onLoad.start,onLoad.end)+'}).onLoad',c)};
 }
 const appScript=preprocess(compiler.parseComponent(read('App.vue')).script.content,flags,{type:'js'});
 const appAst=parser.parse(appScript,{sourceType:'module'}).program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;
 const appMethods=appAst.properties.find(n=>n.key.name==='methods').value,show=appAst.properties.find(n=>n.key.name==='onShow');
 const appOwner={...vm.runInContext('('+appScript.slice(appMethods.start,appMethods.end)+')',c),$store:store,$Cache:c.Cache,globalData:{}};
 const appShow=vm.runInContext('({'+appScript.slice(show.start,show.end)+'}).onShow',c);let bindings=0;
 c.silenceBindingSpread=()=>{bindings++;};c.beginSurveyForeground=()=>{};c.setTimeout=()=>{};c.uni.getEnterOptionsSync=()=>({path:'pages/activity/goods_details/index',query:{spid:101}});
 appShow.call(appOwner,{path:'pages/activity/goods_details/index'});await tick();check(bindings===0&&cache.get('spid')===101,'actual App.onShow saves incoming promoter without guest binding request');
 let finishRecovery;recovery=new Promise(r=>finishRecovery=r);store.getters.isLogin=true;store.state.app.userInfo={phone:'LOCAL_PHONE'};
 appOwner.bindIncomingShare();check(bindings===0,'share binding waits for authoritative startup recovery');store.getters.isLogin=false;store.state.app.userInfo={};finishRecovery();await tick();recovery=null;check(bindings===0,'failed session recovery cannot trigger guest login via share binding');
 store.getters.isLogin=true;store.state.app.userInfo={phone:'LOCAL_PHONE'};appOwner.bindIncomingShare();await tick();check(bindings===1,'authorized members keep the existing share relationship binding');store.getters.isLogin=false;store.state.app.userInfo={};
 const detail=sfc('pages/activity/goods_details/index.vue'),group=sfc('pages/activity/goods_combination_status/index.vue'),login=sfc('pages/users/wechat_login/index.vue');
 const util={getUrlParams:x=>Object.fromEntries(new URLSearchParams(x)),Tips:(o,fn)=>{if(typeof fn==='function')fn();}};
 c.util=util;
 const sku={unique:'sku-B',suk:'B',stock:10,quota:5,cart_num:2};
 function owner(component){const p={...component.methods,$store:store,$Cache:c.Cache,$set:(o,k,v)=>o[k]=v,$util:util,isLogin:store.getters.isLogin,
  id:11,type:3,entryPinkId:0,spid:101,shareUid:101,pinkId:51,storeId:11,store_id:11,store_name:'Selected Store',time_id:'',promotions_type:0,
  attribute:{productSelect:{...sku},productAttr:[],cartAttr:false},attr:{productSelect:{...sku},productAttr:[]},productValue:{B:sku},skuArr:[sku],specsInfo:{},
  purchaseSelection:null,resumePurchase:false,isOpen:false,userData:{},getActivityDetail(){this.loaded=true;},getCombinationPink(){this.loaded=true;},loginReturnUrl:'',
  storeInfo:{product_id:91},storeCombination:{id:11,product_id:91},userInfo:{},memberStoreId:11};
  Object.keys(component.computed).forEach(key=>{if(typeof component.computed[key]==='function')Object.defineProperty(p,key,{get:()=>component.computed[key].call(p),configurable:true});});return p;}
 for(const route of ['pages/activity/goods_details/index','pages/activity/goods_combination_status/index','pages/store/list/index']){
  check(c.enforceMemberPhoneGate(store,route),`${mp?'MP':'H5'} guest cold landing ${route}`);
  check(interceptors.navigateTo.invoke({url:'/'+route+'?id=11'}), 'public navigation remains available');
 }
 check(nav.length===0,'public browsing does not request phone authorization');
 let release;recovery=new Promise(r=>release=r);c.enforceMemberPhoneGate(store,'pages/activity/goods_details/index');release();await tick();recovery=null;
 check(nav.length===0,'guest startup recovery does not redirect public activity');
 for(const route of ['pages/goods/order_confirm/index','pages/user/index','pages/goods/order_details/index'])check(interceptors.navigateTo.invoke({url:'/'+route})===false,'protected route still blocked: '+route);
 nav.length=0;pages=[{route:'pages/activity/goods_details/index'}];
 let p=owner(detail);detail.onLoad.call(p,mp?{scene:encodeURIComponent('id=11&type=3&spid=101')}:{id:'11',type:'3',spid:'101'});
 check(p.loaded&&p.id==='11'&&p.spid==='101'&&nav.length===0,'scan loads activity before authentication and retains promoter');
 let privatePreload=0;const preview=owner(detail);preview.$wechat={isWeixin:()=>false};preview.getImageBase64=()=>{privatePreload++;return Promise.resolve();};preview.downloadFilePromotionCode=()=>privatePreload++;preview.downloadFilestoreImage=()=>Promise.resolve();preview.setShare=()=>{};preview.preloadImage=()=>{};preview.restorePurchaseSelection=()=>{};preview.$util={Tips:o=>{throw new Error('Detail initialization: '+(o.title&&o.title.stack||o.title));}};detail.methods.getActivityDetail.call(preview);await tick();check(preview.showSkeleton===false&&privatePreload===0,'real detail initialization completes without protected poster preloads for guests');
 p.selecAttr();check(p.attribute.cartAttr&&nav.length===0,'guest can inspect specifications without login');
 p.goCat();let back=cache.get('login_back_url');
 check(query(back).id==='11'&&query(back).spid==='101'&&query(back).selected_unique==='sku-B'&&query(back).selected_store_id==='11','buy saves exact activity/promoter/store/SKU');
 check(nav.length===1&&calls.length===0,'buy requests login without creating cart');
 let l=owner(login);l.loginReturnUrl=back;check(l.publicActivityReturn===back,'cancel return is restricted to public activity');l.browseHome();check(nav.at(-1)===back&&!cache.has('login_back_url'),'cancel authorization returns to original activity');
 p=owner(detail);detail.onLoad.call(p,query(back));check(p.loaded&&nav.at(-1)===back,'cancelled authorization can keep browsing without a login loop');
 p.goCat();back=cache.get('login_back_url');store.getters.isLogin=true;store.state.app.token='LOCAL_TOKEN';
 l=owner(login);l.loginReturnUrl=back;if(mp)l.getUserInfo(0);else{store.state.app.userInfo={phone:'LOCAL_PHONE'};l.wechatAuthLogin({code:'LOCAL_CODE'},back);}await tick();check(nav.at(-1)===back&&store.state.app.userInfo.phone==='LOCAL_PHONE','actual login completion restores the saved activity');
 p=owner(detail);detail.onLoad.call(p,query(back));await tick();p.restorePurchaseSelection();check(p.attribute.cartAttr&&p.isOpen&&p.attribute.productSelect.unique==='sku-B'&&p.storeId===11,'authorized return restores chosen SKU/store and reopens confirmation');
 check(calls.length===0,'authorization return cannot create an order or cart');
 p.purchaseSelection={unique:'retired-sku',quantity:999};p.restorePurchaseSelection();check(p.attribute.productSelect.unique==='sku-B','missing old SKU cannot replace the current authoritative selection');
 p.purchaseSelection={unique:'sku-B',quantity:999};p.restorePurchaseSelection();check(p.attribute.productSelect.cart_num===5,'restored quantity is bounded by current availability');
 p.isOpen=true;p.attribute.cartAttr=true;p.store_id=11;p.goCat();await tick();check(calls.length===1&&calls[0].store_id===11&&calls[0].combinationId==='11','explicit confirmation continues original authenticated purchase');
 store.getters.isLogin=false;store.state.app.token='';store.state.app.userInfo={};cache.clear();nav.length=0;calls.length=0;
 p=owner(detail);p.spellBnt({id:51});back=cache.get('login_back_url');check(query(back).pinkId==='51'&&query(back).spid==='101','join authorization remembers the selected group and inviter');
 store.getters.isLogin=true;store.state.app.userInfo={phone:'LOCAL_PHONE'};p=owner(detail);detail.onLoad.call(p,query(back));await tick();p.restorePurchaseSelection();check(query(nav.at(-1)).id==='51'&&query(nav.at(-1)).spid==='101','join returns to the same group after authorization');
 const active=owner(group);active.resumePurchase=true;active.purchaseSelection={unique:'sku-B',quantity:2};active.$wechat={isWeixin:()=>false};active.setOpenShare=()=>{};active.count=0;active.attr.productSelect={};group.methods.getCombinationPink.call(active);await tick();check(active.attr.cartAttr&&active.isOpen&&active.attr.productSelect.unique==='sku-B'&&calls.length===0,'actual authenticated group response restores selection and opens join confirmation without cart write');
 store.getters.isLogin=false;store.state.app.userInfo={};nav.length=0;cache.clear();
 const g=owner(group);group.onLoad.call(g,mp?{scene:encodeURIComponent('id=51&spid=101')}:{id:'51',spid:'101'});await tick();
 check(query(nav.at(-1)).id==='11'&&query(nav.at(-1)).pinkId==='51'&&query(nav.at(-1)).spid==='101'&&!cache.has('login_back_url'),'guest group share resolves public activity without login or private group query');
 if(mp){store.getters.isLogin=true;store.state.app.userInfo={phone:''};p=owner(detail);nav.length=0;p.goCat();check(nav.length===1&&calls.length===0,'token without phone cannot bypass purchase authorization');}
 l=owner(login);l.loginReturnUrl='/pages/goods/order_confirm/index?cartId=private';check(l.publicActivityReturn==='','cancel cannot expose a private saved return');
}
(async()=>{await scenario(true);await scenario(false);console.log(JSON.stringify({result:'PASS',checks:passed,realOrdersCreated:0}));})().catch(e=>{console.error(e);process.exitCode=1;});
