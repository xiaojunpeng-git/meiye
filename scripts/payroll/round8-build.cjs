/** 已验收Git源码只读构建；三客户身份仅构建期内存覆盖，不修改源码或其他客户配置。 */
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
// 固定补验补丁沿用同一构建链，独立证据和输出目录，禁止覆盖上一发布记录。
const supplement=process.argv.includes('--remove-net'),version=supplement?'0.68.1':'0.68.0',versionCode=supplement?'69':'68';
const root=path.resolve(__dirname,'../..'),commit=supplement?'0100bf16':'872b22e3',base='/private/tmp/beauty-round8-'+(supplement?'remove-net-release':'release'),input=base+'/readonly/前端代码/mobile-vue3',plugins='/Applications/HBuilderX.app/Contents/HBuilderX/plugins',e=root+'/tests/customer-010/evidence/round8-'+(supplement?'remove-net-release':'release');
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const git=a=>cp.execFileSync('git',a,{cwd:root,maxBuffer:128e6});
fs.mkdirSync(e,{recursive:true});
if(!fs.existsSync(input)){fs.mkdirSync(base+'/readonly',{recursive:true});cp.execFileSync('tar',['-xf','-','-C',base+'/readonly'],{input:git(['archive',commit,'前端代码/mobile-vue3'])});}
const files=git(['ls-tree','-rz','--name-only',commit,'--','前端代码/mobile-vue3']).toString().split('\0').filter(Boolean),hashes={};
for(const f of files){const p=base+'/readonly/'+f,bytes=git(['show',commit+':'+f]);if(sha(fs.readFileSync(p))!==sha(bytes))throw Error('Source mismatch '+f);hashes[f]=sha(bytes);fs.chmodSync(p,0o444);}
const tasks=[['h5',null,null],['rh','wx29d9b63c92555701','https://rh.cc3798.com'],['008','wx14666197911d052b','https://008.cc3798.com'],['010','wxd92c6d010bd6bf01','https://010.cc3798.com']];
for(const[site,appid,origin]of tasks){
 const h5=site==='h5',output=base+'/'+site,log=fs.openSync(e+'/'+site+'-build.log','w');
 const args=[plugins+'/uniapp-cli-vite/node_modules/@dcloudio/vite-plugin-uni/bin/uni.js','build','-p',h5?'h5':'mp-weixin',...(h5?['--base','/view_mobile_v3/']:[])];
 const env={...process.env,NODE_ENV:'production',UNI_INPUT_DIR:input,UNI_OUTPUT_DIR:output,UNI_HBUILDERX_PLUGINS:plugins,UNI_APP_X:'true',UNI_UTS_PLATFORM:h5?'web':'mp-weixin'};
 if(!h5)Object.assign(env,{VITE_MOBILE_API_ORIGIN:origin,PAYROLL_MINI_INPUT:input,PAYROLL_MINI_APPID:appid,PAYROLL_MINI_VERSION:version,PAYROLL_MINI_VERSION_CODE:versionCode,NODE_OPTIONS:'--require '+path.join(__dirname,'release-mini-overlay.cjs')});
 try{cp.execFileSync(plugins+'/node/node',args,{cwd:plugins+'/uniapp-cli-vite',env,stdio:['ignore',log,log],timeout:240000});}finally{fs.closeSync(log);}
 for(const[f,h]of Object.entries(hashes))if(sha(fs.readFileSync(base+'/readonly/'+f))!==h)throw Error('Fixed source drift');
 if(!h5){const config=JSON.parse(fs.readFileSync(output+'/project.config.json')),runtime=fs.readFileSync(output+'/src/shared/platform/mobile-runtime-config.js','utf8'),page=fs.readFileSync(output+'/src/merchant/pages/employee-performance/index.js','utf8');if(config.appid!==appid||!runtime.includes(origin)||!page.includes('displayProjectCount')||!page.includes('staff_labor_fee'))throw Error('Target or feature mismatch');}
 const assets={};const scan=d=>{for(const i of fs.readdirSync(d,{withFileTypes:true})){const p=path.join(d,i.name);if(i.isDirectory())scan(p);else assets[path.relative(output,p)]=sha(fs.readFileSync(p));}};scan(output);
 fs.writeFileSync(e+'/'+site+'-build.json',JSON.stringify({result:'PASS',site,appid,origin,version,commit:git(['rev-parse',commit]).toString().trim(),input,output,sourceHashes:hashes,assets},null,2));console.log(site+' BUILD_PASS');
}
