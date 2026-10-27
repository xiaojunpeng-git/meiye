// One explicit 008 member upload. Keep the original pending receipt; never
// resubmit silently if the developer tool requires an upload confirmation.
const fs=require('fs'),cp=require('child_process'),crypto=require('crypto');
const d='tests/store-address-layout/release',read=n=>JSON.parse(fs.readFileSync(d+'/'+n+'.json'));
const target={customer:'008',terminal:'member',appid:'wx85d4bfd0b4b6aafb',apiOrigin:'https://008.cc3798.com',version:'3.0.69',project:'/private/tmp/store-address-mini-008-20261027/008-member'};
for(const site of ['rh','008','010'])if(read(site+'-verified').result!=='PASS')throw Error('Site not verified');
const config=JSON.parse(fs.readFileSync(target.project+'/project.config.json')),input=JSON.parse(fs.readFileSync('/private/tmp/store-address-mini-008-20261027/input.json'));
if(config.appid!==target.appid||input.target.origin!==target.apiOrigin||input.commit!==read('plan').candidateTree||read('mini-verification').result!=='PASS')throw Error('Wrong source/customer/terminal');
fs.writeFileSync(d+'/mini-upload-started.json',JSON.stringify({...target,commit:read('commit').commit,sourceTree:input.commit,startedAt:new Date().toISOString(),inputSHA256:crypto.createHash('sha256').update(fs.readFileSync('/private/tmp/store-address-mini-008-20261027/input.json')).digest('hex')},null,2)+'\n',{flag:'wx'});
const out=cp.execFileSync('/Applications/wechatwebdevtools.app/Contents/MacOS/wechatide',['-c','Codex','upload','--project',target.project,'--upload-version',target.version,'--desc','008第5轮：门店地址完整换行、卡片随内容增高，地图客服保持对齐'],{timeout:120000,maxBuffer:16e6});
fs.writeFileSync(d+'/mini-upload-response.json',out,{flag:'wx'});console.log(out.toString());
