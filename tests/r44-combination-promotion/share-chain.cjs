/** Execute canonical Vue methods and canonical login helper for each supported member build.
 * API/network/Uni renderer are test adapters; no copied business implementation is maintained.
 */
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert');
const root=path.resolve(__dirname,'../..');
const parser=require(path.join(root,'前端代码/admin/node_modules/@babel/parser'));
const compiler=require(path.join(root,'前端代码/admin/node_modules/vue-template-compiler'));
const paths={activity:'前端代码/uniapp/pages/activity/goods_details/index.vue',group:'前端代码/uniapp/pages/activity/goods_combination_status/index.vue',confirm:'前端代码/uniapp/pages/goods/order_confirm/index.vue',wechat:'前端代码/uniapp/pages/users/wechat_login/index.vue',phone:'前端代码/uniapp/pages/users/binding_phone/index.vue'};
let passes=0;const ok=(value,label)=>{assert(value,label);passes++;console.log('PASS '+label);};
function preprocess(source,platform){
 const stack=[true]; return source.split('\n').filter(line=>{
  const m=line.match(/^\s*\/\/\s*#(ifdef|ifndef|else|endif)\s*(.*)$/);
  if(!m)return stack.every(Boolean);
  if(m[1]==='endif')stack.pop();else if(m[1]==='else')stack[stack.length-1]=!stack.at(-1);
  else {const yes=m[2].trim().split(/\s*\|\|\s*/).some(x=>x===platform||x==='MP'&&platform==='MP-WEIXIN');stack.push(m[1]==='ifdef'?yes:!yes);}return false;
 }).join('\n');
}
function source(file,platform){const raw=fs.readFileSync(path.join(root,file),'utf8');return preprocess(file.endsWith('.vue')?compiler.parseComponent(raw).script.content:raw,platform);}
function vueMethods(file,platform,ctx){
 const code=source(file,platform),tree=parser.parse(code,{sourceType:'module'});
 const component=tree.program.body.find(x=>x.type==='ExportDefaultDeclaration').declaration;
 const all=[...component.properties.filter(x=>x.key&&x.key.name!=='methods'),...component.properties.find(x=>x.key.name==='methods').value.properties];
 const methods={};for(const p of all){if(!p.key||(!['ObjectMethod'].includes(p.type)&&p.value?.type!=='FunctionExpression'))continue;
  const k=p.key.name;methods[k]=vm.runInContext('({'+code.slice(p.start,p.end)+'}).'+k,ctx,{filename:file});}return methods;
}
const flush=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
const query=url=>Object.fromEntries(new URL(url,'https://local.invalid').searchParams);
function environment(platform){
 const nav=[],cache=new Map([['BASIC_CONFIG',{wechat_auth_switch:1}]]),outgoing=[],calls=[];
 const authState={bindPhone:false,needsProfile:false};
 const app={globalData:{spid:999999,openPages:'/foreign?spid=999999'}};
 const store={state:{app:{uid:202}},getters:{isLogin:false},commit(){}};
 const location={origin:'https://local.invalid',pathname:'/pages/activity/goods_details/index',search:'?id=40&type=3&spid=101',href:'https://local.invalid/pages/activity/goods_details/index?id=40&type=3&spid=101&spid=909&code=oauth'};
 const util={getUrlParams:val=>Object.fromEntries(new URLSearchParams(val)),Tips:(arg,then)=>{if(arg.title==='登录成功'){if(typeof then==='function')then();else if(then?.url)nav.push(then.url);return;}throw new Error(JSON.stringify(arg));}};
 const uni={navigateTo:x=>nav.push(x.url),redirectTo:x=>nav.push(x.url),reLaunch:x=>nav.push(x.url),navigateBack:()=>nav.push('BACK'),getStorageSync:k=>cache.get(k)||false,setStorageSync:(k,v)=>cache.set(k,v),showLoading(){},showToast(){},hideLoading(){},removeStorageSync:k=>cache.delete(k),downloadFile:x=>x.success({tempFilePath:x.url})};
 const ctx=vm.createContext({console,Number,String,Object,Array,Date,JSON,encodeURIComponent,decodeURIComponent,window:{location},location,uni,app,store,util,
  Cache:{set:(k,v)=>cache.set(k,v),get:k=>cache.get(k),has:k=>cache.has(k),clear:k=>cache.delete(k),time:()=>1000},getCurrentPages:()=>[{route:location.pathname.slice(1)}],isWeixin:()=>true,
  wx:{},WX_AUTH:'WX_AUTH',STATE_KEY:'wx_authorize_state',BACK_URL:'login_back_url',needsMemberProfileCompletion:()=>authState.needsProfile,
  wechatAuthLogin:()=>Promise.resolve({data:{bindPhone:authState.bindPhone,key:'LOCAL_AUTH',token:'LOCAL_TOKEN',expires_time:2000}}),
  phoneLogin:()=>Promise.resolve({data:{token:'LOCAL_TOKEN',expires_time:2000}}),wechatBindingPhone:()=>Promise.resolve({data:{token:'LOCAL_TOKEN',expires_time:2000}}),
  getUserInfo:()=>Promise.resolve({data:{uid:202,belong_store_id:301}}),silenceBindingSpread(){},postCartAdd:data=>{calls.push({kind:'cart',data});return Promise.resolve({data:{cartId:'cart-local-44'}});},
  orderCreate:(key,data)=>{calls.push({kind:'order',key,data:{...data}});return Promise.resolve({data:{result:{order_id:'NO_PROVIDER_CALLED'}}});},
  activityCodeApi:(type,id,data)=>{calls.push({kind:'qr',type,id,data});return Promise.resolve({data:{routineUrl:'https://local.invalid/qr/202',wechatUrl:'https://local.invalid/qr/202'}});}
 });
 const login=source('前端代码/uniapp/libs/login.js',platform),ast=parser.parse(login,{sourceType:'module'});
 for(const name of ['prePage','_toLogin']){const n=ast.program.body.find(x=>x.type==='FunctionDeclaration'&&x.id.name===name);vm.runInContext(login.slice(n.start,n.end),ctx);}
 ctx.toLogin=ctx._toLogin;
 const helper=source('前端代码/uniapp/utils/memberLoginReturn.js',platform);vm.runInContext(helper.replace(/export /g,''),ctx);
 const wxsource=source('前端代码/uniapp/libs/wechat.js','H5'),wxast=parser.parse(wxsource,{sourceType:'module'});
 const wxmethod=wxast.program.body.find(x=>x.type==='ClassDeclaration').body.body.find(x=>x.key.name==='getAuthUrl');
 ctx.actualOAuthUrl=vm.runInContext('({'+wxsource.slice(wxmethod.start,wxmethod.end)+'}).getAuthUrl',ctx);
 const methods=Object.fromEntries(Object.entries(paths).map(([key,file])=>[key,vueMethods(file,platform,ctx)]));
 function page(kind,logged=false){
  store.getters.isLogin=logged;const result={...methods[kind],$store:store,$Cache:ctx.Cache,isLogin:logged,$util:util,$set:(obj,k,v)=>obj[k]=v,$wechat:{isWeixin:()=>true,wechatEvevt:(_,cfg)=>{outgoing.push(cfg);return Promise.resolve();}},
   loginReturnUrl:'',backUrl:'',configData:{wechat_auth_switch:1},colorData(){},userLogin(){},
   spid:0,shareUid:0,id:0,type:3,pinkId:0,time_id:'',promotions_type:0,storeId:301,store_id:301,store_name:"门店O'Brien &spid=909",addressId:0,delivery_type:2,
   userData:{belong_store_id:301},userInfo:{uid:202,nickname:'B'},storeInfo:{product_id:91,title:'测试活动',image:'',info:''},storeCombination:{id:40,product_id:91,title:'测试团'},
   attribute:{cartAttr:false,productAttr:[],productSelect:{cart_num:1}},attr:{productSelect:{cart_num:1,unique:'sku'}},productValue:{},isOpen:true,
   getActivityDetail(){},getCombinationPink(){},getCheckShipping(){},getNearbyStore(){},orderKey:'local-confirm-key'};
  return result;
 }
 return {nav,cache,outgoing,calls,app,store,ctx,page,authState};
}
(async()=>{
 const backendCases=[];
 for(const platform of ['H5','MP-WEIXIN']){
  const e=environment(platform),a=e.page('activity');
  a.onLoad({id:'40',type:'3',spid:'101'});a.changeLogin();
  const ret=e.cache.get('login_back_url');ok(query(ret).spid==='101'&&query(ret).id==='40'&&query(ret).type==='3',platform+' activity guest login retains incoming share and activity');
  const a2=e.page('activity',true);a2.onLoad(query(ret));await flush();e.app.globalData.spid=999999;a2.goCat();await flush();
  const confirmUrl=e.nav.at(-1);ok(query(confirmUrl).spid==='101'&&query(confirmUrl).store_name==="门店O'Brien &spid=909",platform+' actual cart navigation carries local share, escapes store name');
  const confirmGuest=e.page('confirm');await confirmGuest.onLoad(query(confirmUrl));
  const confirmReturn=e.cache.get('login_back_url');ok(query(confirmReturn).cartId==='cart-local-44'&&query(confirmReturn).spid==='101'&&query(confirmReturn).store_name==="门店O'Brien &spid=909",platform+' confirm expired session retains cart and all URL parameters');
  const confirm=e.page('confirm',true);await confirm.onLoad(query(confirmReturn));confirm.payment({pinkId:confirm.pinkId});await flush();
  ok(e.calls.at(-1).kind==='order'&&e.calls.at(-1).data.spid==='101',platform+' actual confirm payment sends inbound share to create API');
  backendCases.push({case:platform+'-activity',spid:Number(e.calls.at(-1).data.spid),pinkId:0});
  a2.spellBnt({id:51});const g=e.page('group');g.onLoad(query(e.nav.at(-1)));
  ok(query(e.cache.get('login_back_url')).spid==='101'&&query(e.cache.get('login_back_url')).id==='51',platform+' activity to group to login retains invite owner');
  const g2=e.page('group',true);g2.onLoad(query(e.cache.get('login_back_url')));e.app.globalData.spid=888888;g2.goPay();await flush();
  const joinOptions=query(e.nav.at(-1));ok(joinOptions.spid==='101'&&joinOptions.pinkId==='51'&&joinOptions.store_name==="门店O'Brien &spid=909",platform+' joining preserves invite owner and group id');
  const jg=e.page('confirm');await jg.onLoad(joinOptions);const j=e.page('confirm',true);await j.onLoad(query(e.cache.get('login_back_url')));j.payment({pinkId:j.pinkId});await flush();
  ok(e.calls.at(-1).data.spid==='101'&&e.calls.at(-1).data.pinkId===51,platform+' joining confirm login and actual payment preserve group/share');
  backendCases.push({case:platform+'-join',spid:Number(e.calls.at(-1).data.spid),pinkId:e.calls.at(-1).data.pinkId});
  g2.goDetail(40);ok(query(e.nav.at(-1)).spid==='101'&&g2.pinkId==='51',platform+' return from group to activity retains share without rewriting group identity');
  const plain=e.page('activity',true);plain.onLoad({id:'40',type:'3'});await flush();plain.goCat();await flush();const pc=e.page('confirm',true);await pc.onLoad(query(e.nav.at(-1)));pc.payment({pinkId:0});await flush();
  ok(Number(e.calls.at(-1).data.spid)===0,platform+' no incoming share never borrows global permanent referrer');
  backendCases.push({case:platform+'-unattributed',spid:0,pinkId:0});
  const gp=e.page('group',true);gp.onLoad({id:'51'});gp.goPay();await flush();ok(query(e.nav.at(-1)).spid==='0',platform+' unshared group also never borrows permanent referrer');
  if(platform==='MP-WEIXIN'){
   const scene=e.page('activity');scene.onLoad({scene:encodeURIComponent('id=40&type=3&spid=101')});scene.changeLogin();ok(query(e.cache.get('login_back_url')).spid==='101'&&query(e.cache.get('login_back_url')).type==='3','MP scanned activity scene survives login');
   const sg=e.page('group');sg.onLoad({scene:encodeURIComponent('id=51&spid=101')});ok(query(e.cache.get('login_back_url')).spid==='101'&&query(e.cache.get('login_back_url')).id==='51','MP scanned group scene survives login');
   ok(query(a2.onShareAppMessage().path).spid==='202'&&a2.spid==='101','MP reshare uses current member while own purchase keeps incoming share');
   ok(new URLSearchParams(a2.onShareTimeline().query).getAll('spid').join()==='202','MP timeline has exactly one current-member spid');
   ok(query(g2.onShareAppMessage().path).spid==='202'&&g2.shareUid===101,'MP group reshare uses current member independent of incoming owner');
   a2.downloadFilePromotionCode();await flush();ok(e.calls.at(-1).kind==='qr'&&e.calls.at(-1).type==='3'&&e.calls.at(-1).id==='40'&&!('spid' in e.calls.at(-1).data),'MP poster requests authenticated QR without allowing incoming share override');
  }else{
   a2.setShare();await flush();let url=e.outgoing.at(-1).link;ok(new URL(url).searchParams.getAll('spid').join()==='202'&&!url.includes('oauth'),'H5 WeChat share replaces received spid and excludes login tokens');
   a2.setShareInfoStatus();await flush();url=e.outgoing.at(-1).link;ok(new URL(url).searchParams.getAll('spid').join()==='202','H5 explicit share uses one current-member spid');
   a2.onReady();ok(query(a2.codeVal).spid==='202','H5 poster QR identifies current sharing member');
   g2.setOpenShare();await flush();ok(query(e.outgoing.at(-1).link).spid==='202','H5 group share identifies current sharing member');
  }

  const protectedRoute='/pages/goods/order_confirm/index?new=1&cartId=cart-local-44&pinkId=51&spid=101&group_purchase=1&store_id=301';
  e.cache.set('login_back_url',protectedRoute);
  const loginPage=e.page('wechat');loginPage.onLoad({});loginPage.finishLogin();
  ok(e.nav.at(-1)===protectedRoute&&!e.cache.has('login_back_url'),platform+' actual finishLogin consumes saved route and reinitializes checkout');
  if(platform==='H5'){
   e.cache.set('login_back_url',protectedRoute);const oauth=e.ctx.actualOAuthUrl('LOCAL_APP','snsapi_userinfo','/pages/users/wechat_login/index');
   const callback=new URL(oauth).searchParams.get('redirect_uri');
   const callbackOptions=query(callback);callbackOptions.code='LOCAL_OAUTH';
   const callbackPage=e.page('wechat');callbackPage.onLoad(callbackOptions);await flush();
   ok(e.nav.at(-1)===protectedRoute,'H5 real OAuth URL and real auth callback restore encoded share/cart/group deep link');
   e.authState.bindPhone=true;e.cache.set('login_back_url',protectedRoute);
   const registration=e.page('wechat');registration.wechatAuthLogin({code:'LOCAL_NEW'},encodeURIComponent(protectedRoute));await flush();
   const phoneOptions=query(e.nav.at(-1));ok(phoneOptions.backUrl===protectedRoute,'H5 registration carries entire return URL as one encoded phone-binding parameter');
   const phone=e.page('phone');phone.onLoad(phoneOptions);phone.phoneAuth('LOCAL_AUTH');await flush();
   ok(e.nav.at(-1)===protectedRoute,'H5 actual phone auth and user-info callback return to shared purchase');
  }else{
   e.cache.set('login_back_url',protectedRoute);const phone=e.page('phone');phone.onLoad({pageType:'0'});phone.phoneAuth('LOCAL_AUTH');await flush();
   ok(e.nav.at(-1)===protectedRoute,'MP actual phone auth and user-info callback return to shared purchase');
  }
  e.cache.set('login_back_url',protectedRoute);e.authState.needsProfile=true;
  const profile=e.page('phone');profile.onLoad({});profile.getUserInfo(true);await flush();
  ok(profile.isShow===true&&e.cache.get('login_back_url')===protectedRoute,platform+' required profile completion retains route until completion');
  profile.editSuccess();ok(e.nav.at(-1)===protectedRoute&&!e.cache.has('login_back_url'),platform+' completed profile consumes same shared checkout return');
  const invalid=['https://evil.invalid/','//evil.invalid','/pages/../admin','/pages/%2e%2e/admin','/pages/%252e%252e/admin','/pages/users/wechat_login/index','/pages/users/binding_phone/index','%2F%2Fevil.invalid'];
  ok(invalid.every(url=>e.ctx.normalizeMemberLoginReturn(url)===''),platform+' return policy rejects external/traversal/login-loop routes');
  e.cache.clear();const phoneFallback=e.page('phone');phoneFallback.onLoad({backUrl:'https://evil.invalid/'});phoneFallback.wechatPhone();
  ok(e.nav.at(-1)==='/pages/user/index',platform+' phone fallback cannot use invalid or stale alternate return');
  if(platform==='H5'){const rejected=e.page('wechat');rejected.onLoad({back_url:'https://evil.invalid/'});rejected.wechatPhone();ok(e.nav.at(-1)==='/pages/user/index','H5 rejected return cannot fall through to unchecked browser navigation');}
  e.cache.clear();const fallback=e.page('wechat');fallback.finishLogin();ok(e.nav.at(-1)==='/pages/user/index',platform+' no saved return keeps existing member-home fallback');
 }
 // The backend creation regression reads this actual frontend output; fixture UIDs map by role.
 fs.writeFileSync(path.join(__dirname,'share-chain-payloads.json'),JSON.stringify(backendCases,null,2)+'\n');
 console.log('R44 SHARE CHAIN PASS '+passes);
})().catch(e=>{console.error(e);process.exit(1);});
