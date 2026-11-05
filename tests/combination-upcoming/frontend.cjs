// Real Vue2 handlers and time helpers, with only native/network/canvas boundaries
// replaced. Advancing the fake device clock never creates a business order.
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert/strict');
const root=process.env.COMBINATION_UPCOMING_ROOT||path.resolve(__dirname,'../..');
const cli='/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const compiler=require(cli+'/vue-template-compiler'),parser=require(cli+'/@babel/parser');
const preprocess=require(cli+'/@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess').preprocess;
const read=p=>fs.readFileSync(root+'/前端代码/uniapp/'+p,'utf8');
let checks=0;const check=(v,l)=>{assert(v,l);checks++;console.log('PASS',l);};
const tick=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
async function scenario(mp){
 const flags=mp?{MP:true,MP_WEIXIN:true}:{H5:true},nav=[],tips=[],requests=[],drawn=[],intervals=new Map();let device=900000,intervalId=0,releaseQr;
 const ctx=vm.createContext({console,Promise,Number,String,Object,Array,Math,Error,Date:{now:()=>device},setInterval:fn=>{intervals.set(++intervalId,fn);return intervalId;},clearInterval:id=>intervals.delete(id),setTimeout:(fn,delay)=>delay===1000?(Promise.resolve().then(fn),0):setTimeout(fn,delay),clearTimeout,
  Debounce:fn=>fn,mapGetters:()=>({}),uni:{navigateTo:o=>nav.push(o.url),downloadFile:o=>o.success({statusCode:200,tempFilePath:o.url}),getImageInfo:o=>o.success({}),removeStorageSync(){}},
  activityCodeApi:(type,id,data)=>{requests.push({type,id,data});return new Promise(r=>releaseQr=()=>r({data:{routineUrl:'https://local.invalid/qr.png',wechatUrl:'https://local.invalid/qr.png'}}));},
  imageBase64:async(image,code)=>({data:{image:image?'data:image/png;base64,PRODUCT':false,code:code?'data:image/png;base64,QR':false}}),openPinkSubscribe:()=>{throw Error('Preview must not request subscription');}
 });
 vm.runInContext(read('utils/memberCombinationWindow.js').replace(/export /g,''),ctx);ctx.resolveCombinationPhase=ctx.combinationPhase;
 function pageSource(file){const sfc=compiler.parseComponent(read(file));assert.deepEqual(compiler.compile(preprocess(sfc.template.content,flags,{type:'html'})).errors,[]);const code=preprocess(sfc.script.content,flags,{type:'js'});const obj=parser.parse(code,{sourceType:'module'}).program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;
  const methods=obj.properties.find(n=>n.key.name==='methods').value;const get=n=>vm.runInContext('({'+code.slice(n.start,n.end)+'}).'+n.key.name,ctx);return {methods:vm.runInContext('('+code.slice(methods.start,methods.end)+')',ctx),computed:vm.runInContext('('+code.slice(obj.properties.find(n=>n.key.name==='computed').value.start,obj.properties.find(n=>n.key.name==='computed').value.end)+')',ctx),hooks:Object.fromEntries(obj.properties.filter(n=>['onHide','onShow','onUnload'].includes(n.key.name)).map(n=>[n.key.name,get(n)]))};}
 const actual=pageSource('pages/activity/goods_details/index.vue');let logins=0;
 const p={...actual.methods,$set:(o,k,v)=>o[k]=v,$store:{state:{app:{uid:0}}},$util:{Tips:x=>tips.push(x.title),PosterCanvas:(...args)=>{drawn.push(args);args[8]('data:image/png;base64,POSTER');}},$nextTick:async fn=>{if(fn)fn();},
  type:3,id:11,storeInfo:{activity_status:'upcoming',start_time:1000,stop_time:2000,server_time:900,image:'https://local.invalid/product.png',title:'Future',price:198,product_price:298},activityNow:0,
  attribute:{productSelect:{price:198,cart_num:1,stock:10,quota:10},productAttr:[]},purchaseAuthorized:false,entryPinkId:0,PromotionCode:'',changeLogin(){logins++;},posters:true,posterbackgd:'BACKGROUND'};
 Object.keys(actual.computed).filter(k=>['combinationPhase','combinationCountdown'].includes(k)).forEach(k=>Object.defineProperty(p,k,{get:()=>actual.computed[k].call(p)}));
 p.startCombinationClock(true);check(p.combinationPhase==='upcoming'&&p.combinationCountdown==='00:01:40','server time determines upcoming countdown despite device clock');
 p.goCat();check(logins===0&&tips.pop()==='活动尚未开始','upcoming purchase stops before phone authorization');
 device+=99000;for(const fn of intervals.values())fn();check(p.combinationCountdown==='00:00:01'&&p.combinationPhase==='upcoming','last pre-start second remains preview');
 device+=1000;for(const fn of intervals.values())fn();check(p.combinationPhase==='running','start boundary enables purchase without reloading');p.goCat();check(logins===1,'running purchase reaches existing phone authorization');
 actual.hooks.onHide.call(p);check(intervals.size===0,'hidden page stops its timer');device+=1001000;actual.hooks.onShow.call(p);check(p.combinationPhase==='ended','resumed page accounts for elapsed background time');actual.hooks.onUnload.call(p);check(intervals.size===0,'destroyed page leaves no timer');
 check(ctx.combinationCountdown(86400+3661,0)==='1天 01:01:01','multi-day countdown keeps days');
 p.storeInfo.activity_status='upcoming';p.storeInfo.start_time=9999;p.storeInfo.stop_time=19999;p.posters=true;
 const preload=p.downloadFilePromotionCode(),active=p.downloadFilePromotionCode();check(requests.length===1,'active sharing reuses pending QR download');releaseQr();await Promise.all([preload,active]);
 check(p.PromotionCode==='https://local.invalid/qr.png'&&p.isDown===false,'upcoming QR resolves without purchase');
 p.PromotionCode='';const guestQr=p.downloadFilePromotionCode();p.$store.state.app.uid=7;const memberQr=p.downloadFilePromotionCode();releaseQr();await guestQr;await tick();check(requests.length===3,'identity change waits then requests the current member QR');releaseQr();await memberQr;check(p._promotionCodeKey==='3:11:7','guest QR cannot become current member sharing attribution');
 requests.length=0;
 if(mp){await p.goPoster();await tick();check(p.posterImageStatus===true&&drawn.length===1,'mini upcoming poster draws after actual product/QR assets are ready');}
 else {await p.generateH5Poster();check(p.posterImageStatus===true&&drawn.length===1,'H5 upcoming poster generates with prepared QR');}
 check(requests.length===0&&logins===1,'prepared sharing causes no extra login or QR requests');
 if(mp){ctx.uni.getImageInfo=o=>o.fail();p.posters=true;await p.goPoster();check(!p.canvasStatus&&!p.posters&&!p.PromotionCode,'invalid local QR ends loading and allows a fresh retry');ctx.uni.getImageInfo=o=>o.success({});}
 ctx.activityCodeApi=()=>Promise.reject(new Error('QR unavailable'));p.PromotionCode='';p.posters=true;if(mp)await p.goPoster();else await p.generateH5Poster();check(!p.posters&&!p.canvasStatus&&!p.isDown&&tips.pop()==='QR unavailable','failed QR generation clears loading state and reports the actual failure');
 const list=pageSource('pages/activity/goods_combination/index.vue');list.methods.openSubcribe({id:11,activity_status:'upcoming'});check(nav.at(-1).includes('id=11&type=3'),'upcoming list opens details without subscribing');
 const home=pageSource('pages/index/components/combination.vue');home.methods.goDetail({id:11,activity_status:'upcoming'});check(nav.at(-1).includes('id=11&type=3'),'home upcoming card opens details without subscribing');
 const api=preprocess(read('api/activity.js'),flags,{type:'js'}),ast=parser.parse(api,{sourceType:'module'});const n=ast.program.body.find(n=>n.type==='ExportNamedDeclaration'&&n.declaration?.id?.name==='activityCodeApi').declaration;
 ctx.request={get:(url,data,options)=>options};const qr=vm.runInContext('('+api.slice(n.start,n.end)+')',ctx);
 check(qr(3,11,{}).noAuth===true&&qr(90,11,{}).noAuth===false,'only public group poster QR permits guest request');
 console.log(mp?'MP UPCOMING PASS':'H5 UPCOMING PASS');
}
(async()=>{await scenario(true);await scenario(false);console.log(JSON.stringify({result:'PASS',checks,realOrdersCreated:0}));})().catch(e=>{console.error(e);process.exitCode=1;});
