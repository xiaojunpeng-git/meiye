// One PM-accepted 008 member correction. No H5/backend/other customer publish.
const fs=require('fs'),cp=require('child_process'),crypto=require('crypto');
const d='tests/member-login-navigation/evidence',read=n=>JSON.parse(fs.readFileSync(d+'/'+n+'.json'));
const p=read('plan'),r=read('commit'),b=read('native-build'),project='/private/tmp/member-login-navigation-mini-008-20261028/008-member';
const sha=x=>crypto.createHash('sha256').update(x).digest('hex');
if(!p.acceptedByUser||read('verification').result!=='PASS'||b.sourceTree!==p.candidateTree)throw Error('Fixed acceptance/native verification gate');
for(const[f,h]of Object.entries(p.files))if(sha(fs.readFileSync(f))!==h)throw Error('Accepted source drift');
const config=JSON.parse(fs.readFileSync(project+'/project.config.json')),input=JSON.parse(fs.readFileSync('/private/tmp/member-login-navigation-mini-008-20261028/input.json'));
if(config.appid!=='wx85d4bfd0b4b6aafb'||input.target.origin!=='https://008.cc3798.com'||input.commit!==p.candidateTree)throw Error('Wrong customer/terminal/source');
fs.writeFileSync(d+'/upload-started.json',JSON.stringify({customer:'008',terminal:'member',appid:config.appid,apiOrigin:input.target.origin,commit:r.commit,sourceTree:p.candidateTree,version:p.version,project,startedAt:new Date().toISOString()},null,2)+'\n',{flag:'wx'});
const out=cp.execFileSync('/Applications/wechatwebdevtools.app/Contents/MacOS/wechatide',['-c','Codex','upload','--project',project,'--upload-version',p.version,'--desc','008会员端修正版：资料保存成功后进入会员端，避免重复返回；保留手机号授权门禁'],{timeout:120000,maxBuffer:16e6});
fs.writeFileSync(d+'/upload-response.json',out,{flag:'wx'});console.log(out.toString());
