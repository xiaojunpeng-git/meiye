// Exercise the real global gate at cold start and warm resume; a stale scan path cannot grant access.
const fs=require('fs'),vm=require('vm'),path=require('path');const root=path.resolve(__dirname,'../../..');
let pages=[],redirects=[];const cached={};const context={LOGIN_STATUS:'token',USER_INFO:'user',Cache:{get:k=>cached[k]},getCurrentPages:()=>pages,uni:{reLaunch:o=>{redirects.push(o.url);o.complete();}}};vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(root,'前端代码/uniapp/utils/memberPhoneGate.js'),'utf8').replace(/import[\s\S]*?from\s+['"][^'"]+['"];?/g,'').replace(/export /g,''),context);
const store={getters:{isLogin:false},state:{app:{userInfo:{}}}};const checks=[];function check(ok,label){if(!ok)throw Error(label);checks.push(label);}
check(context.enforceMemberPhoneGate(store,'pages/users/survey/index')&&redirects.length===0,'冷启动页面栈为空时保留问卷落地页');
check(!context.enforceMemberPhoneGate(store,'pages/user/index')&&redirects.length===1,'其他未授权冷启动仍强制手机号登录');
pages=[{route:'pages/user/index'}];check(!context.enforceMemberPhoneGate(store,'pages/users/survey/index')&&redirects.length===2,'当前受保护页不能借旧问卷路径绕过门禁');
pages=[{route:'pages/users/survey/index'}];check(context.enforceMemberPhoneGate(store),'问卷页允许先填写后授权');
pages=[{route:'pages/user/index'}];store.getters.isLogin=true;store.state.app.userInfo={phone:'13800000000'};check(context.enforceMemberPhoneGate(store),'已授权手机号的既有业务仍可进入');
fs.writeFileSync(path.join(root,'tests/customer-010/evidence/round12-survey-miniprogram/phone-gate.json'),JSON.stringify({passed:true,checks},null,2));console.log('PHONE GATE PASS',checks.length);
