// Real canonical Vue2 component, with read-only API mocks. Tests must never
// create appointments or mutate issued rights merely by opening a booking page.
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const root=path.resolve(__dirname,'../..'),deps='/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const Vue=require(deps+'/vue/dist/vue.common.js'),compiler=require(deps+'/vue-template-compiler'),parser=require(deps+'/@babel/parser');
const file='前端代码/uniapp/pages/users/user_card/index.vue',raw=fs.readFileSync(path.join(root,file),'utf8'),sfc=compiler.parseComponent(raw);
const template=compiler.compile(sfc.template.content);assert.deepEqual(template.errors,[]);
let script=sfc.script.content;const edits=parser.parse(script,{sourceType:'module'}).program.body.flatMap(n=>n.type==='ImportDeclaration'?[[n.start,n.end,'']]:n.type==='ExportDefaultDeclaration'?[[n.start,n.declaration.start,'return ']]:[]);
for(const[a,b,c]of edits.sort((a,b)=>b[0]-a[0]))script=script.slice(0,a)+c+script.slice(b);
let open=true,readResult=null,reads=[],navigations=[],tips=[],benefitReads=[],holderResult=null,benefitsResult=[];
const options=new Function('verifyModal','HTTP_REQUEST_URL','colors','cardHolder','cardOrderBenefits','getReservationOrderInfo','activityCodeApi','isMobileReservationOpen','goWithYuyueSubscribe',script)(
 {},'https://008.cc3798.com',{data:()=>({colorStyle:''})},()=>Promise.resolve({data:holderResult}),id=>{benefitReads.push(id);return Promise.resolve({data:benefitsResult})},
 (id,data)=>{reads.push([id,data]);return typeof readResult==='function'?readResult():Promise.resolve({data:readResult})},()=>Promise.resolve({data:{}}),()=>open,url=>navigations.push(url));
options.render=new Function(template.render);options.staticRenderFns=template.staticRenderFns.map(v=>new Function(v));
const vm=new Vue(options);vm.$util={Tips:x=>tips.push(x.title)};vm.$wechat={isWeixin:()=>false};
const source={id:6538,oid:6540,product_id:2768,product_type:6,card_name:'八倍水动力（单次）',write_times:1,write_surplus_times:1,status:1,write_valid:1,image:'/static/mall/project.jpg',card_cover_image:''};
const row={id:16651,product_id:2768,product_type:6,write_times:1,write_surplus_times:1,waiting_service:0,cart_info:{productInfo:{store_name:source.card_name,attrInfo:{image:'/attach/original.jpg'}}}};
const flush=()=>new Promise(r=>setImmediate(r));
function nodes(n){return [n,...(n.children||[]).flatMap(nodes)];}
(async()=>{
 vm.cardData={...source};vm.writeTimes=1;vm.writeSurplusTimes=1;vm.cardRelated=[JSON.parse(JSON.stringify(row))];
 const unchanged=JSON.stringify([vm.cardData,vm.cardRelated]);assert(vm.isStandaloneProject&&vm.canReserveStandalone);
 assert.equal(vm.projectCoverImage,'https://008.cc3798.com/attach/original.jpg');assert(vm.cardBackground['background-color'].includes('--view-theme'));
 let rendered=nodes(vm._render());assert(rendered.some(x=>x.tag==='image'&&x.data.attrs.src===vm.projectCoverImage));assert(rendered.some(x=>x.tag==='button'&&x.children.some(c=>c.text==='去预约')));
 readResult={cart_info_id:16651,cart_num:1};await vm.reserveStandaloneProject();assert.deepEqual(reads,[[6540,{cart_info_id:16651}]]);assert.equal(navigations[0],'/pages/activity/reservation/index?orderId=6540&cartInfoId=16651');
 assert.equal(JSON.stringify([vm.cardData,vm.cardRelated]),unchanged);
 // Quota changes or mismatched detail IDs never navigate to another right.
 for(const result of [{cart_info_id:16651,cart_num:0},{cart_info_id:99,cart_num:1}]){readResult=result;await vm.reserveStandaloneProject();}
 assert.equal(navigations.length,1);assert.equal(tips.length,2);
 readResult=()=>Promise.reject({msg:'无权预约'});await vm.reserveStandaloneProject();assert.equal(tips.at(-1),'无权预约');assert.equal(navigations.length,1);
 let resolve;readResult=()=>new Promise(r=>resolve=r);const pending=vm.reserveStandaloneProject();await vm.reserveStandaloneProject();assert(vm.reservationPreparing);resolve({data:{cart_info_id:16651,cart_num:1}});await pending;assert.equal(navigations.length,2);assert(!vm.reservationPreparing);
 for(const data of [{status:2},{status:0},{is_card_upgraded:true}]){vm.cardData={...source,...data};assert(!vm.canReserveStandalone);const n=reads.length;await vm.reserveStandaloneProject();assert.equal(reads.length,n);}
 vm.cardData={...source};vm.writeSurplusTimes=0;assert(!vm.canReserveStandalone);vm.writeSurplusTimes=1;
 vm.cardRelated=[];assert(!vm.canReserveStandalone);assert.equal(vm.projectCoverImage,'https://008.cc3798.com/static/mall/project.jpg');
 vm.cardRelated=[row,row];assert(!vm.canReserveStandalone);
 vm.cardRelated=[row];vm.cardData={...source,product_type:5,card_name:'赠送护理'};assert(vm.isStandaloneProject&&vm.canReserveStandalone);
 vm.cardData={...source,product_type:5,product_id:9000,card_name:'一次数体验卡',card_cover:1,card_cover_image:'/attach/card.jpg'};assert(!vm.isStandaloneProject);assert.equal(vm.cardBackground.background,'url(https://008.cc3798.com/attach/card.jpg) center/cover no-repeat');
 rendered=nodes(vm._render());assert(!rendered.some(x=>x.tag==='button'&&x.children?.some(c=>c.text==='去预约')));
 open=false;const closed=new Vue(options);closed.cardData={...source};closed.cardRelated=[row];closed.writeSurplusTimes=1;assert(!closed.canReserveStandalone);closed.$destroy();open=true;
 holderResult={...source,verify_code:'display-only'};benefitsResult=[row];vm.id=6538;vm.getCardHolder();await flush();await flush();assert(benefitReads.includes(6540));assert.equal(vm.standaloneProject.id,16651);
 vm.$destroy();console.log('PASS Vue2 cover/card/gift/standalone/detail identity/quota/status/switch/preflight errors/double-click/read-only navigation/original-card regression');
})().catch(e=>{console.error(e);process.exitCode=1});
