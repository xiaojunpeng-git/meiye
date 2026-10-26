const fs=require('fs');
const path=require('path');
const cp=require('child_process');
const crypto=require('crypto');

// Immutable Git exports are build inputs only, never another editable source.
// Output and compiler caches stay outside this read-only export.
const [terminal,variant='candidate']=process.argv.slice(2);
if(!['admin','member'].includes(terminal)||!['baseline','candidate'].includes(variant))throw Error('Invalid build target');
const root=process.cwd();
const commit=variant==='baseline'?'2ddff36f9cbb93f8006d0e23cf9a66d8067d5456':'5ba5da5526cbd23afb0ec4983fd04a3d7cdc80e9';
const base='/private/tmp/m008-round3-release-20261020';
const source=path.join(base,variant+'-readonly');
const output=path.join(base,terminal+'-'+variant);
const project='前端代码/'+(terminal==='admin'?'admin':'uniapp');
const input=path.join(source,project);
const cli='/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli';
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const git=(args,options={})=>cp.execFileSync('git',args,{maxBuffer:128*1024*1024,...options});
fs.mkdirSync(base,{recursive:true});
if(!fs.existsSync(input)){
 fs.mkdirSync(source,{recursive:true});
 cp.execFileSync('tar',['-xf','-','-C',source],{input:git(['archive',commit,project])});
 fs.symlinkSync(terminal==='admin'?path.join(root,project,'node_modules'):path.join(cli,'node_modules'),path.join(input,'node_modules'));
 const freeze=dir=>{for(const entry of fs.readdirSync(dir,{withFileTypes:true})){
  const file=path.join(dir,entry.name);if(entry.isSymbolicLink())continue;
  if(entry.isDirectory())freeze(file);else fs.chmodSync(file,0o444);
 }fs.chmodSync(dir,0o555);};
 freeze(input);
}
const shared='前端代码/shared';
if(!fs.existsSync(path.join(source,shared))){
 cp.execFileSync('tar',['-xf','-','-C',source],{input:git(['archive',commit,shared])});
 const freeze=dir=>{for(const entry of fs.readdirSync(dir,{withFileTypes:true})){
  const file=path.join(dir,entry.name);if(entry.isDirectory())freeze(file);else fs.chmodSync(file,0o444);
 }fs.chmodSync(dir,0o555);};freeze(path.join(source,shared));
}
const tracked=git(['ls-tree','-rz','--name-only',commit,'--',project,shared],{encoding:'utf8'}).split('\0').filter(Boolean);
for(const file of tracked){
 const actual=fs.readFileSync(path.join(source,file));const expected=git(['show',commit+':'+file]);
 if(!actual.equals(expected))throw Error('Readonly input differs from canonical Git: '+file);
}
let runtimeConfig=null;
if(terminal==='member'){
 // app.js is an intentionally ignored environment input. Freeze the same H5
 // config used by the tested canonical app; H5 resolves its own browser origin.
 const bytes=fs.readFileSync(path.join(root,project,'config/app.js'));
 const expected='a25d7e24e1009f635b1ad8d0f6040f627b2bd34c9e10b7d59864e296585859c4';
 if(sha(bytes)!==expected)throw Error('Member H5 runtime config changed');
 const file=path.join(input,'config/app.js');
 if(!fs.existsSync(file)){
  fs.chmodSync(path.dirname(file),0o755);fs.writeFileSync(file,bytes,{mode:0o444});fs.chmodSync(path.dirname(file),0o555);
 }
 if(sha(fs.readFileSync(file))!==expected)throw Error('Generated H5 environment input changed');
 runtimeConfig={source:'canonical ignored config/app.js',sha256:expected,h5ApiOrigin:'window.location origin'};
}
fs.writeFileSync(output+'-input.json',JSON.stringify({terminal,variant,commit,tree:git(['rev-parse',commit+'^{tree}'],{encoding:'utf8'}).trim(),
 canonical:root,input,output,inputFiles:tracked.length,readonly:true,node:process.version,runtimeConfig},null,2)+'\n');
process.env.NODE_ENV='production';process.env.ANALYZE='false';process.env.BROWSERSLIST_IGNORE_OLD_DATA='true';
process.env.VUE_CLI_CONTEXT=terminal==='admin'?input:cli;
const Service=require(path.join(terminal==='admin'?path.join(root,project):cli,'node_modules/@vue/cli-service/lib/Service'));
const serviceRun=Service.prototype.run;
Service.prototype.run=function(command,...args){
 const result=serviceRun.call(this,command,...args);
 if(command!=='uni-build')return result;
 // HBuilderX's IDE notification socket can keep a finished production build
 // alive. Exit only after its actual webpack command promise has resolved.
 return Promise.resolve(result).then(value=>{
  fs.writeFileSync(output+'-complete.json',JSON.stringify({commit,terminal,variant,result:'PASS'})+'\n');
  process.exit(0);return value;
 });
};
const init=Service.prototype.init;
Service.prototype.init=function(...args){
 const result=init.apply(this,args);
 this.webpackChainFns.push(config=>{
  config.module.rules.values().forEach(rule=>{for(const name of ['cache-loader','thread-loader'])if(rule.uses.has(name))rule.uses.delete(name);});
  config.cache(false);
 });
 this.webpackRawConfigFns.push(config=>{
  for(const optimizer of [...(config.optimization.minimizer||[]),...(config.plugins||[])]){
   if(optimizer.options){if('cache' in optimizer.options)optimizer.options.cache=false;if('parallel' in optimizer.options)optimizer.options.parallel=false;}
  }
 });
 return result;
};
if(terminal==='admin'){
 process.chdir(input);
 new Service(input).run('build',{dest:output,mode:'production'}).catch(error=>{console.error(error);process.exitCode=1;});
}else{
 process.env.UNI_PLATFORM='h5';process.env.UNI_INPUT_DIR=input;process.env.UNI_OUTPUT_DIR=output;
 process.env.UNI_HBUILDERX_PLUGINS='/Applications/HBuilderX.app/Contents/HBuilderX/plugins';
 process.env.HBUILDER_EXTENSIONS_DIR=process.env.UNI_HBUILDERX_PLUGINS;process.env.UNI_MINIMIZE='true';
 process.chdir(cli);process.argv=[process.argv[0],path.join(cli,'bin/uniapp-cli.js')];
 require(path.join(cli,'bin/uniapp-cli.js'));
}
