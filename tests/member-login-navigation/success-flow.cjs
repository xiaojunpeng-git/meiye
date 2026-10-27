// Run the actual Vue2 login/profile methods, Tips helper and phone interceptor.
// All API promises, storage and navigation are local fakes; no user credentials or backend writes.
const fs=require('fs'),vm=require('vm'),assert=require('assert'),path=require('path');
const root=path.resolve(__dirname,'../..'),cli='/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const parser=require(cli+'/@babel/parser');
function platform(source,mp){const active=[true];return source.split('\n').filter(line=>{const m=line.match(/(?:\/\/|<!--)\s*#(ifdef|ifndef|endif|else)\s*(.*)/);if(!m)return active[active.length-1];if(m[1]==='endif'){active.pop();return false;}if(m[1]==='else'){active[active.length-1]=active[active.length-2]&&!active[active.length-1];return false;}const symbols=m[2].replace(/-->.*/,'').trim().split(/\s*\|\|\s*/);let yes=symbols.some(x=>mp?x==='MP'||x==='MP-WEIXIN':x==='H5');if(m[1]==='ifndef')yes=!yes;active.push(active[active.length-1]&&yes);return false;}).join('\n');}
function objectSource(file,property,mp){const raw=platform(fs.readFileSync(root+'/'+file,'utf8'),mp),s=raw.includes('<script>')?raw.split('<script>')[1].split('</script>')[0]:raw,ast=parser.parse(s,{sourceType:'module'}),obj=ast.program.body.find(x=>x.type==='ExportDefaultDeclaration').declaration;if(!property)return s.slice(obj.start,obj.end);const node=obj.properties.find(x=>x.key.name===property).value;return s.slice(node.start,node.end);}
const login='前端代码/uniapp/pages/users/wechat_login/index.vue',modal='前端代码/uniapp/components/eidtUserModal/index.vue';
async function scenario({mp=true,depth=1,failBack=false,legacy=false,authorized=true,cancel=false,saveFailure=false}={}){
 const timers=[],nav=[],events=[],pages=Array.from({length:depth},(_,i)=>({route:i===depth-1?'pages/users/wechat_login/index':'pages/store/home/index'})),interceptors={};
 const state={userInfo:authorized?{phone:'LOCAL_TEST_PHONE'}:{phone:''}},store={state:{app:state},getters:{isLogin:authorized},commit(k,v){if(k==='LOGIN')this.getters.isLogin=true;if(k==='UPDATE_USERINFO')state.userInfo=v;}};
 const context={app:{globalData:{spid:0,code:''}},history:{back:()=>navigate('historyBack',{})},setTimeout:fn=>timers.push(fn),getCurrentPages:()=>pages,console,Cache:{get:()=>undefined},LOGIN_STATUS:'TOKEN',USER_INFO:'USER',
  getUserInfo:()=>Promise.resolve({data:{uid:1,phone:authorized?'LOCAL_TEST_PHONE':''}}),routineBindingPhone:()=>Promise.resolve({data:{token:'LOCAL_FAKE_ONLY',expires_time:9999,store_user_avatar:1}}),
  userEdit:()=>saveFailure?Promise.reject('LOCAL_SAVE_FAILED'):Promise.resolve({msg:'LOCAL_SAVED'})};
 context.uni={addInterceptor:(k,h)=>interceptors[k]=h,hideLoading(){},showToast(){},
  reLaunch:o=>navigate('reLaunch',o),navigateBack:o=>navigate('navigateBack',o),navigateTo:o=>navigate('navigateTo',o),redirectTo:o=>navigate('redirectTo',o)};
 function navigate(k,o){if(interceptors[k]&&interceptors[k].invoke(o)===false)return;if(k==='navigateBack'&&(failBack||pages.length===1)){o.fail&&o.fail();return;}nav.push({method:k,url:o.url,delta:o.delta});}
 const gate=fs.readFileSync(root+'/前端代码/uniapp/utils/memberPhoneGate.js','utf8').replace(/^import .*;\r?\n/gm,'').replace(/export /g,'');vm.runInNewContext(gate+'\ninstallMemberPhoneGate(store);',{...context,store});
 const methods=vm.runInNewContext('('+objectSource(login,'methods',mp)+')',context),computed=vm.runInNewContext('('+objectSource(login,'computed',mp)+')',context),props=vm.runInNewContext('('+objectSource(modal,'props',mp)+')',context);
 const util=vm.runInNewContext('('+objectSource('前端代码/uniapp/utils/util.js',null,mp)+')',context);
 const owner={...methods,$store:store,$Cache:{time:()=>0,clear(){}},$util:util,userInfo:state.userInfo,isShow:true,authKey:'LOCAL_ONLY'};
 const child={...vm.runInNewContext('('+objectSource(modal,'methods',mp)+')',context),$util:util,userInfo:{avatar:'https://example.invalid/local.png'},configData:{wechat_auth_switch:1},
  navigateOnSuccess:legacy?(props.navigateOnSuccess?props.navigateOnSuccess.default:true):(computed.profileEditNavigatesBack?computed.profileEditNavigatesBack.call(owner):true),$emit(name){events.push(name);if(!legacy&&name==='editSuccess')owner.editSuccess();}};
 if(cancel)owner.closeEdit();else child.formSubmit({detail:{value:{nickname:'LOCAL_NICK'}}});
 await new Promise(resolve=>setImmediate(resolve));while(timers.length)timers.shift()();
 return {nav,events,isShow:owner.isShow};
}
(async()=>{
 let r=await scenario();assert.strictEqual(r.isShow,false);assert.strictEqual(r.nav.length,1,'one navigation after profile save');assert.strictEqual(r.nav[0].method,'reLaunch');assert.strictEqual(r.nav[0].url,'/pages/user/index');console.log('PASS MP single-page login save enters member centre once');
 r=await scenario({depth:2});assert.strictEqual(r.nav.length,1);assert.strictEqual(r.nav[0].method,'navigateBack');assert.strictEqual(r.nav[0].delta,1);console.log('PASS MP deep-link save returns to its caller once');
 r=await scenario({depth:2,failBack:true});assert.strictEqual(r.nav.length,1);assert.strictEqual(r.nav[0].method,'reLaunch');console.log('PASS failed return falls back to member centre');
 r=await scenario({legacy:true,depth:3});assert.strictEqual(r.nav.length,1);assert.strictEqual(r.nav[0].delta,2);console.log('PASS unrelated profile modal keeps its existing configured return');
 r=await scenario({mp:false,depth:3});assert.strictEqual(r.nav.length,1);assert.strictEqual(r.nav[0].method,'historyBack');console.log('PASS H5 existing profile return remains unchanged');
 r=await scenario({saveFailure:true});assert.strictEqual(r.nav.length,0);assert.strictEqual(r.isShow,true);console.log('PASS failed save stays on profile form');
 r=await scenario({authorized:false});assert.strictEqual(r.nav.length,0);console.log('PASS missing phone authorization still blocks business navigation');
 r=await scenario({cancel:true});assert.strictEqual(r.nav.length,1);console.log('PASS close-profile existing success path retained');
 console.log('LOGIN_NAVIGATION_FLOW_PASS');
})().catch(e=>{console.error(e);process.exitCode=1;});
