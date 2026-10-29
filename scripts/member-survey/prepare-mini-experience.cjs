// Prepare only the explicitly authorized 010 member trial; no upload or production configuration change.
const fs=require('fs'),path=require('path'),crypto=require('crypto');const root=path.resolve(__dirname,'../..'),e=path.join(root,'tests/customer-010/evidence/round12-survey-miniprogram');
const proof=JSON.parse(fs.readFileSync(path.join(e,'member-build-mp-weixin-010.json'))),output=proof.output;
if(proof.apiOrigin!=='https://010.cc3798.com'||proof.target!=='010'||!output.startsWith('/tmp/r12-survey-member-mp-weixin-010-'))throw Error('Wrong trial build');
for(const[f,h]of Object.entries(proof.source_inputs))if(crypto.createHash('sha256').update(fs.readFileSync(path.join(proof.source,f))).digest('hex')!==h)throw Error('Member source changed');
const project=path.join(output,'project.config.json'),config=JSON.parse(fs.readFileSync(project));
if(config.appid!=='wxce4f61fc9c4eaaa6')throw Error('Wrong member AppID');
const vendor=fs.readFileSync(path.join(output,'common/vendor.js'),'utf8');if(!vendor.includes('https://010.cc3798.com'))throw Error('Missing fixed 010 origin');
// 本地原生验证也开启合法域名校验，不能用开发工具的跳过检查掩盖真机网络问题。
config.setting.urlCheck=true;config.projectname='010会员问卷体验版';config.condition={miniprogram:{current:0,list:[{id:0,name:'问卷扫码入口自测',pathName:'pages/users/survey/index',query:'scene='+'_'.repeat(32),scene:1047}]}};
fs.writeFileSync(project,JSON.stringify(config,null,2));fs.writeFileSync(path.join(e,'mini-project.json'),JSON.stringify({appid:config.appid,apiOrigin:proof.apiOrigin,project:output,version:'3.4.1',domainCheck:true,synthetic_test_scene:'_'.repeat(32),production_007_connection_unchanged:true,project_config_sha256:crypto.createHash('sha256').update(fs.readFileSync(project)).digest('hex')},null,2));console.log(output);
