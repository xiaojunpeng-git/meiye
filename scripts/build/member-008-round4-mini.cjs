const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');

// One immutable canonical release and one explicitly authorized customer/terminal.
// Customer configuration is a process-local build input, never an editable fork.
const root=path.resolve(__dirname,'../..');
const commit='2b912bd52b5191d9f3ce73d21292a9c5db5901c9';
const base='/private/tmp/m008-round4-mini-20261020-runtime-fixed';
const source=path.join(base,'readonly'),input=path.join(source,'前端代码/uniapp'),output=path.join(base,'008-member');
const target={customer:'008',terminal:'member',appid:'wx85d4bfd0b4b6aafb',origin:'https://008.cc3798.com'};
const cli='/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli';
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const git=args=>cp.execFileSync('git',args,{cwd:root,maxBuffer:128e6});
if(git(['rev-parse','HEAD']).toString().trim()!==commit)throw Error('Fixed release HEAD changed');
const dirty=git(['diff','--name-only','HEAD','--','前端代码/uniapp','前端代码/shared','scripts/build/member-mini-css-dedupe.cjs']).toString().trim();
if(dirty)throw Error('Unexpected affected source changes');
if(fs.existsSync(source)||fs.existsSync(output))throw Error('Build input/output already exists; preserve original artifacts');
fs.mkdirSync(source,{recursive:true});
const paths=['前端代码/uniapp','前端代码/shared','scripts/build/member-mini-css-dedupe.cjs'];
cp.execFileSync('tar',['-xf','-','-C',source],{input:git(['archive',commit,...paths])});
const files=git(['ls-tree','-rz','--name-only',commit,'--',...paths]).toString().split('\0').filter(Boolean);
for(const file of files)if(!fs.readFileSync(path.join(source,file)).equals(git(['show',commit+':'+file])))throw Error('Immutable input differs '+file);
fs.symlinkSync(path.join(cli,'node_modules'),path.join(input,'node_modules'));
// app.js and project.config.json are generated environment inputs, excluded from Git.
fs.writeFileSync(path.join(input,'config/app.js'),fs.readFileSync(path.join(input,'config/app.mp.template.js')));
const originalRead=fs.readFileSync.bind(fs);
const originalManifest=originalRead(path.join(input,'manifest.json'),'utf8');
const manifest=originalManifest.replace('"wxce4f61fc9c4eaaa6"',JSON.stringify(target.appid));
const parse=require(path.join(cli,'node_modules/@dcloudio/uni-cli-shared/lib/json')).parseJson;
const wx=parse(manifest)['mp-weixin'];if(wx.appid!==target.appid)throw Error('Manifest target mismatch');
fs.writeFileSync(path.join(input,'project.config.json'),JSON.stringify({appid:target.appid,compileType:'miniprogram',setting:wx.setting,packOptions:wx.packOptions}));
const freeze=dir=>{for(const e of fs.readdirSync(dir,{withFileTypes:true})){const f=path.join(dir,e.name);if(e.isSymbolicLink())continue;if(e.isDirectory())freeze(f);else fs.chmodSync(f,0o444);}fs.chmodSync(dir,0o555);};freeze(source);
const overlays=new Map([[path.join(input,'manifest.json'),Buffer.from(manifest)],[path.join(input,'config/member-customer.json'),Buffer.from(JSON.stringify({apiOrigin:target.origin}))]]);
const overlay=file=>typeof file==='string'?overlays.get(path.resolve(file)):undefined;
const convert=(b,o)=>{const encoding=typeof o==='string'?o:o&&o.encoding;return encoding?b.toString(encoding):Buffer.from(b);};
fs.readFileSync=function(file,options){const b=overlay(file);return b?convert(b,options):originalRead(file,options);};
const read=fs.readFile.bind(fs);fs.readFile=function(file,options,cb){if(typeof options==='function'){cb=options;options=undefined;}const b=overlay(file);return b?process.nextTick(()=>cb(null,convert(b,options))):read(file,options,cb);};
const promiseRead=fs.promises.readFile.bind(fs.promises);fs.promises.readFile=async(file,options)=>{const b=overlay(file);return b?convert(b,options):promiseRead(file,options);};
Object.assign(process.env,{NODE_ENV:'production',UNI_PLATFORM:'mp-weixin',UNI_INPUT_DIR:input,UNI_OUTPUT_DIR:output,UNI_MINIMIZE:'true',BROWSERSLIST_IGNORE_OLD_DATA:'true',VUE_CLI_CONTEXT:cli,UNI_HBUILDERX_PLUGINS:path.dirname(cli),HBUILDER_EXTENSIONS_DIR:path.dirname(cli)});
const Service=require(path.join(cli,'node_modules/@vue/cli-service/lib/Service')),init=Service.prototype.init,run=Service.prototype.run;
// Workers cannot inherit process-local identity overrides; compile without loader caches/workers.
Service.prototype.init=function(...args){const result=init.apply(this,args);this.webpackChainFns.push(config=>{config.module.rules.values().forEach(rule=>{for(const name of ['cache-loader','thread-loader'])if(rule.uses.has(name))rule.uses.delete(name);});config.cache(false);});this.webpackRawConfigFns.push(config=>{for(const m of config.optimization.minimizer||[])if(m.options){if('cache'in m.options)m.options.cache=false;if('parallel'in m.options)m.options.parallel=false;
 }
 config.plugins.push({apply(compiler){
 // DCloud's webpack-4 main loader appends createApp to its Babel options.
 // Without worker serialization, shared options would rewrite component $mount
 // as application registration too. Each module must own its mutable options.
 compiler.hooks.compilation.tap('MemberMiniLoaderIsolation',compilation=>{
   compilation.hooks.normalModuleLoader.tap('MemberMiniLoaderIsolation',(context,module)=>{
     for(const loader of module.loaders||[]){
       if(!loader.loader.includes('babel-loader')||!loader.options)continue;
       loader.options={...loader.options,plugins:(loader.options.plugins||[]).map(plugin=>Array.isArray(plugin)?[...plugin]:plugin)};
     }
   });
 });
 compiler.hooks.emit.tap({name:'MemberMiniBeforeFactoringProof',stage:9999},compilation=>{
   const capture=path.join(base,'before-factoring');fs.mkdirSync(capture,{recursive:true});
   for(const [file,asset]of Object.entries(compilation.assets)){const f=path.join(capture,file);fs.mkdirSync(path.dirname(f),{recursive:true});fs.writeFileSync(f,asset.source());}
 });}});
 });return result;};
Service.prototype.run=function(command,...args){const result=run.call(this,command,...args);if(command!=='uni-build')return result;return Promise.resolve(result).then(value=>{if(!fs.existsSync(path.join(output,'app.json')))throw Error('Compiler returned without output');fs.writeFileSync(base+'/complete.json',JSON.stringify({commit,target,result:'PASS',actualOutput:true})+'\n');process.exit(0);return value;});};
fs.writeFileSync(base+'/input.json',JSON.stringify({commit,tree:git(['rev-parse',commit+'^{tree}']).toString().trim(),target,input,output,node:process.version,readonly:true,compressionPasses:1,loaderOptionsIsolated:true,buildScriptSha256:sha(originalRead(__filename)),files:files.map(file=>({file,sha256:sha(originalRead(path.join(source,file)))})),overlays:[...overlays].map(([file,b])=>({file,sha256:sha(b)}))},null,2)+'\n');
process.chdir(cli);process.argv=[process.argv[0],path.join(cli,'bin/uniapp-cli.js')];require(path.join(cli,'bin/uniapp-cli.js'));
