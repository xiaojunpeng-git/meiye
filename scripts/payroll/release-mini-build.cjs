// 三个已点名商家端从同一已验收提交的只读源码构建；逐包校验身份和域名。
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
const root=path.resolve(__dirname,'../..'),commit='931edeac5220c1eab53c62d104a1d97f8aebe9e1',base='/private/tmp/payroll-mini-931edeac',input=base+'/readonly/前端代码/mobile-vue3',plugins='/Applications/HBuilderX.app/Contents/HBuilderX/plugins',e=root+'/tests/customer-010/evidence/round6-payroll-release';
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const git=a=>cp.execFileSync('git',a,{cwd:root,maxBuffer:128e6});
if(!fs.existsSync(input)){fs.mkdirSync(base+'/readonly',{recursive:true});cp.execFileSync('tar',['-xf','-','-C',base+'/readonly'],{input:git(['archive',commit,'前端代码/mobile-vue3'])});}
const files=git(['ls-tree','-rz','--name-only',commit,'--','前端代码/mobile-vue3']).toString().split('\0').filter(Boolean);const hashes={};for(const f of files){const p=base+'/readonly/'+f;hashes[f]=sha(fs.readFileSync(p));fs.chmodSync(p,0o444);}
for(const[site,appid]of[['rh','wx29d9b63c92555701'],['008','wx14666197911d052b'],['010','wxd92c6d010bd6bf01']]){
 const origin='https://'+site+'.cc3798.com',output=base+'/mini-'+site,log=fs.openSync(e+'/'+site+'-mini-build.log','w');
 try{cp.execFileSync(plugins+'/node/node',[plugins+'/uniapp-cli-vite/node_modules/@dcloudio/vite-plugin-uni/bin/uni.js','build','-p','mp-weixin'],{cwd:plugins+'/uniapp-cli-vite',env:{...process.env,NODE_ENV:'production',UNI_INPUT_DIR:input,UNI_OUTPUT_DIR:output,UNI_HBUILDERX_PLUGINS:plugins,UNI_APP_X:'true',UNI_UTS_PLATFORM:'mp-weixin',VITE_MOBILE_API_ORIGIN:origin,PAYROLL_MINI_INPUT:input,PAYROLL_MINI_APPID:appid,NODE_OPTIONS:'--require '+path.join(__dirname,'release-mini-overlay.cjs')},stdio:['ignore',log,log],timeout:240000});}finally{fs.closeSync(log);}
 for(const[f,h]of Object.entries(hashes))if(sha(fs.readFileSync(base+'/readonly/'+f))!==h)throw Error('Source drift');
 const config=JSON.parse(fs.readFileSync(output+'/project.config.json')),app=JSON.parse(fs.readFileSync(output+'/app.json')),runtime=fs.readFileSync(output+'/src/shared/platform/mobile-runtime-config.js','utf8'),page=fs.readFileSync(output+'/src/merchant/pages/employee-performance/index.js','utf8');
 if(config.appid!==appid||app.pages.length!==31||!runtime.includes(origin)||!page.includes('project_tiers'))throw Error('Wrong target or missing accepted capability');
 const assets={};const scan=d=>{for(const i of fs.readdirSync(d,{withFileTypes:true})){const p=path.join(d,i.name);if(i.isDirectory())scan(p);else assets[path.relative(output,p)]=sha(fs.readFileSync(p));}};scan(output);
 fs.writeFileSync(e+'/'+site+'-mini-build.json',JSON.stringify({result:'PASS',site,appid,origin,version:'0.66.0',commit,input,output,pages:app.pages.length,sourceHashes:hashes,assets},null,2));console.log(site+' MINI_BUILD_PASS');
}
