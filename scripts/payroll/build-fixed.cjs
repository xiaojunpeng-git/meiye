/* 只读Git固定树构建；使用现有编译链，不生成第二份可编辑业务源码。 */
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
const root=path.resolve(__dirname,'../..'),e=path.join(root,'tests/customer-010/evidence/round6-payroll-algorithm');
const [variant,terminal]=process.argv.slice(2);
if(terminal==='admin' && !process.version.startsWith('v16.')){const r=cp.spawnSync('/Users/xiaojunpeng/.local/node-v16.20.2-darwin-arm64/bin/node',[__filename,...process.argv.slice(2)],{cwd:root,stdio:'inherit'});process.exit(r.status==null?1:r.status);}
if(!['baseline','candidate'].includes(variant)||!['admin','cashier-v3','mobile-vue3'].includes(terminal))throw Error('Explicit fixed variant/terminal required');
const fixed=JSON.parse(fs.readFileSync(path.join(e,'fixed-source.json')));
const tree=variant==='baseline' && terminal==='admin'?fixed.acceptedTerminalSources.admin:fixed[variant+'Tree'];
const base='/private/tmp/mohe010-payroll-'+tree.slice(0,12)+'-'+terminal,snapshot=terminal==='admin' && variant==='baseline'?'/private/tmp/mohe-r37-card-labor-final010/candidate-readonly':terminal==='admin'?path.join(base,variant+'-readonly'):base,input=path.join(snapshot,'前端代码',terminal),output=path.join(base,'output');
const git=args=>cp.execFileSync('git',args,{cwd:root,maxBuffer:256*1024*1024});
const packages=['前端代码/admin','前端代码/cashier-v3','前端代码/shared','前端代码/inventory-vue3',...(terminal==='mobile-vue3'?['前端代码/mobile-vue3']:[])];
if(!fs.existsSync(input)){
 fs.mkdirSync(snapshot,{recursive:true});cp.execFileSync('tar',['-xf','-','-C',snapshot],{input:git(['archive',tree,...packages])});
 for(const name of ['admin','cashier-v3','inventory-vue3'])fs.symlinkSync(fs.realpathSync(path.join(root,'前端代码',name,'node_modules')),path.join(snapshot,'前端代码',name,'node_modules'));
 const freeze=d=>{for(const x of fs.readdirSync(d,{withFileTypes:true})){let p=path.join(d,x.name);if(x.isSymbolicLink())continue;if(x.isDirectory())freeze(p);else fs.chmodSync(p,0o444);}};freeze(snapshot);
}
// 与已上线构建保持相同的依赖绝对路径；双层worktree符号链接会改变webpack模块标识。
for(const name of ['admin','cashier-v3','inventory-vue3']){const link=path.join(snapshot,'前端代码',name,'node_modules'),target=fs.realpathSync(path.join(root,'前端代码',name,'node_modules'));if(fs.readlinkSync(link)!==target){fs.unlinkSync(link);fs.symlinkSync(target,link);}}
const sourceEntries=git(['ls-tree','-rz',tree,'--',...packages]).toString().split('\0').filter(Boolean).map(line=>{const [meta,file]=line.split('\t');return {file,blob:meta.split(' ')[2]};});
const verify=()=>{for(const {file,blob} of sourceEntries){const bytes=fs.readFileSync(path.join(snapshot,file));const actual=crypto.createHash('sha1').update('blob '+bytes.length+'\0').update(bytes).digest('hex');if(actual!==blob)throw Error('Fixed source drift '+file);}};verify();
process.env.BABEL_DISABLE_CACHE='1';process.env.NODE_ENV='production';process.env.ANALYZE='false';process.env.BROWSERSLIST_IGNORE_OLD_DATA='true';process.chdir(input);
function finish(){verify();const assets={};const scan=d=>{for(const x of fs.readdirSync(d,{withFileTypes:true})){const p=path.join(d,x.name);if(x.isDirectory())scan(p);else assets[path.relative(output,p)]=crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');}};scan(output);fs.writeFileSync(path.join(e,variant+'-'+terminal+'-build.json'),JSON.stringify({tree,input,output,assets,result:'PASS'},null,2));console.log(JSON.stringify({variant,terminal,tree,assets:Object.fromEntries(Object.entries(assets).filter(([p])=>p==='index.html'||p==='system.html')),result:'PASS'}));}
if(terminal==='cashier-v3'){cp.execFileSync(process.execPath,[path.join(root,'前端代码/cashier-v3/node_modules/vite/bin/vite.js'),'build','--outDir',output],{stdio:'inherit'});finish();}
else if(terminal==='mobile-vue3'){
 const plugins='/Applications/HBuilderX.app/Contents/HBuilderX/plugins';
 cp.execFileSync(path.join(plugins,'node/node'),[path.join(plugins,'uniapp-cli-vite/node_modules/@dcloudio/vite-plugin-uni/bin/uni.js'),'build','-p','h5','--base','/view_mobile_v3/'],{cwd:path.join(plugins,'uniapp-cli-vite'),env:{...process.env,UNI_INPUT_DIR:input,UNI_OUTPUT_DIR:output,UNI_HBUILDERX_PLUGINS:plugins,UNI_APP_X:'true',UNI_UTS_PLATFORM:'web'},stdio:'inherit'});finish();
}else{
 process.env.VUE_CLI_CONTEXT=input;const Service=require(path.join(root,'前端代码/admin/node_modules/@vue/cli-service/lib/Service'));const service=new Service(input);service.init('production');service.projectOptions.parallel=false;
 service.webpackChainFns.push(config=>{const disable=rule=>{for(const name of ['cache-loader','thread-loader','eslint-loader'])if(rule.uses.has(name))rule.uses.delete(name);for(const nested of rule.oneOfs.values())disable(nested);};config.module.rules.values().forEach(disable);config.module.rules.delete('eslint');config.cache(false);});
 service.webpackRawConfigFns.push(config=>{config.cache=false;for(const p of [...(config.plugins||[]),...((config.optimization||{}).minimizer||[])])if(p.options)for(const k of ['parallel','cache'])if(k in p.options)p.options[k]=false;});
 service.run('build',{mode:'production',dest:output}).then(finish).catch(error=>{console.error(error);process.exitCode=1;});
}
