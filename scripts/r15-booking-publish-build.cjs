// M014: fixed canonical tree, isolated compiler cache and explicit customer identity.
// 从固定Git树的只读导出构建，每客户独立环境输入；不写共享源码、不连接生产。
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
const root=path.resolve(__dirname,'..'),plan=JSON.parse(fs.readFileSync(path.join(root,'tests/r15-booking-release/plan.json')));
const target=process.argv[2],platform=process.argv[3]||'h5';if(!['rh','008','010'].includes(target)||!['h5','mp-weixin'].includes(platform)||platform==='mp-weixin'&&!['008','010'].includes(target))throw Error('Explicit target required');
const base='/private/tmp/r15-booking-publish-'+target+'-'+platform,source=path.join(base,'readonly'),input=path.join(source,'前端代码/uniapp'),output=path.join(base,'output'),tree=plan.candidateTree;
const cli='/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli',sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const git=(a)=>cp.execFileSync('git',a,{cwd:root,maxBuffer:128e6});
fs.mkdirSync(base,{recursive:true});if(fs.existsSync(input))throw Error('Build directory already exists');fs.mkdirSync(source,{recursive:true});
cp.execFileSync('tar',['-xf','-','-C',source],{input:git(['archive',tree,'前端代码/uniapp','前端代码/shared','scripts/build/member-mini-css-dedupe.cjs'])});
const tracked=git(['ls-tree','-rz','--name-only',tree,'--','前端代码/uniapp','前端代码/shared']).toString().split('\0').filter(Boolean);
for(const file of tracked)if(!fs.readFileSync(path.join(source,file)).equals(git(['show',tree+':'+file])))throw Error('Source mismatch '+file);
// 客户接口域名是固定构建参数；正式H5与小程序沿用已上线的客户隔离配置合同。
const config=git(['show',tree+':前端代码/uniapp/config/app.mp.template.js']);fs.writeFileSync(path.join(input,'config/app.js'),config);
const customer=path.join(input,'config/member-customer.json'),body=Buffer.from(JSON.stringify({apiOrigin:'https://'+(target==='rh'?'rh':target)+'.cc3798.com'}));
const rs=fs.readFileSync.bind(fs),ra=fs.readFile.bind(fs),rp=fs.promises.readFile.bind(fs.promises),convert=o=>(typeof o==='string'||o&&o.encoding)?body.toString(typeof o==='string'?o:o.encoding):Buffer.from(body);
fs.readFileSync=(f,o)=>typeof f==='string'&&path.resolve(f)===customer?convert(o):rs(f,o);
fs.readFile=(f,o,cb)=>{if(typeof o==='function'){cb=o;o=undefined;}if(typeof f==='string'&&path.resolve(f)===customer)return process.nextTick(()=>cb(null,convert(o)));return ra(f,o,cb);};
fs.promises.readFile=(f,o)=>typeof f==='string'&&path.resolve(f)===customer?Promise.resolve(convert(o)):rp(f,o);
// 仅小程序构建过程内覆盖AppID，原manifest不变，防止误上传其他客户。
if(platform==='mp-weixin'){
 const mf=path.join(input,'manifest.json'),raw=rs(mf,'utf8');const strip=require(path.join(cli,'node_modules/@dcloudio/uni-cli-shared/lib/json.js'));
 // manifest包含注释，交给HBuilderX自己的解析器处理后写生成输入。
 const parsed=strip.parseJson(raw);parsed['mp-weixin'].appid=plan.apps[target].appid;fs.writeFileSync(mf,JSON.stringify(parsed,null,2));fs.writeFileSync(path.join(input,'project.config.json'),JSON.stringify({appid:plan.apps[target].appid,compileType:'miniprogram',setting:parsed['mp-weixin'].setting,packOptions:parsed['mp-weixin'].packOptions}));
}
fs.symlinkSync(path.join(cli,'node_modules'),path.join(input,'node_modules'));
const freeze=dir=>{for(const x of fs.readdirSync(dir,{withFileTypes:true})){const p=path.join(dir,x.name);if(x.isSymbolicLink())continue;if(x.isDirectory())freeze(p);else fs.chmodSync(p,0o444);}fs.chmodSync(dir,0o555);};freeze(path.join(source,'前端代码'));
fs.writeFileSync(path.join(base,'input.json'),JSON.stringify({commit:plan.commit,tree,target,platform,origin:JSON.parse(body).apiOrigin,output,input,readonly:true,configSHA256:sha(config)},null,2));
Object.assign(process.env,{NODE_ENV:'production',UNI_PLATFORM:platform,UNI_INPUT_DIR:input,UNI_OUTPUT_DIR:output,UNI_MINIMIZE:'true',BROWSERSLIST_IGNORE_OLD_DATA:'true',VUE_CLI_CONTEXT:cli,UNI_HBUILDERX_PLUGINS:path.dirname(cli),HBUILDER_EXTENSIONS_DIR:path.dirname(cli)});

const isolatedTmp=path.join(base,'compiler/vue-cli-plugin-uni-optimize/.tmp');fs.mkdirSync(isolatedTmp,{recursive:true});
const nodeModule=require('module'),oldLoader=nodeModule._extensions['.js'];
const optimizeRoot=path.join(cli,'node_modules/@dcloudio/vue-cli-plugin-uni-optimize');
nodeModule._extensions['.js']=function(mod,file){
 if(file===path.join(optimizeRoot,'index.js')){
  let text=rs(file,'utf8'),needle="const resolve = dir => path.resolve(__dirname, './', dir)";
  if(text.split(needle).length!==2)throw Error('Optimizer alias drift');
  text=text.replace(needle,"const resolve = dir => dir.startsWith('.tmp/') ? require('path').join("+JSON.stringify(isolatedTmp)+", dir.slice(5)) : path.resolve(__dirname, './', dir)");
  return mod._compile(text,file);
 }
 if([path.join(optimizeRoot,'packages/webpack-optimize-plugin/api.js'),path.join(optimizeRoot,'packages/webpack-optimize-plugin/component.js')].includes(file)){
  let text=rs(file,'utf8'),needle="path.resolve(__dirname, '../../.tmp')";
  if(text.split(needle).length!==2)throw Error('Optimizer writer drift');
  return mod._compile(text.replace(needle,JSON.stringify(isolatedTmp)),file);
 }
 return oldLoader(mod,file);
};

const Service=require(path.join(cli,'node_modules/@vue/cli-service/lib/Service')),init=Service.prototype.init,run=Service.prototype.run;
Service.prototype.init=function(...args){const v=init.apply(this,args);this.webpackChainFns.push(c=>{c.module.rules.values().forEach(rule=>{for(const name of ['cache-loader','thread-loader'])if(rule.uses.has(name))rule.uses.delete(name);});c.cache(false);});this.webpackRawConfigFns.push(c=>{for(const x of [...(c.optimization.minimizer||[]),...(c.plugins||[])])if(x.options){if('cache'in x.options)x.options.cache=false;if('parallel'in x.options)x.options.parallel=false;}c.output.path=output;c.plugins.push({apply(compiler){compiler.hooks.compilation.tap('R12Isolation',compilation=>{compilation.hooks.normalModuleLoader.tap('R12Isolation',(context,module)=>{for(const loader of module.loaders||[])if(loader.loader.includes('babel-loader')&&loader.options)loader.options={...loader.options,plugins:(loader.options.plugins||[]).map(p=>Array.isArray(p)?[...p]:p)};});});}});});return v;};
Service.prototype.run=function(command,...args){const result=run.call(this,command,...args);if(command!=='uni-build')return result;return Promise.resolve(result).then(v=>{const entry=path.join(output,platform==='h5'?'index.html':'app.json');if(!fs.existsSync(entry))return v;fs.writeFileSync(path.join(base,'complete.json'),JSON.stringify({result:'PASS',tree,target,platform,entrySHA256:sha(rs(entry))}));console.log('R12_BUILD_PASS',target,platform);process.exit(0);return v;});};
process.chdir(cli);process.argv=[process.argv[0],path.join(cli,'bin/uniapp-cli.js')];require(path.join(cli,'bin/uniapp-cli.js'));
