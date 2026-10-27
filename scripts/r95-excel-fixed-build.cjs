const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const crypto = require('crypto');
const root=process.cwd(),base='/private/tmp/mohe-r95-excel-release-20261024',variant=process.argv[2];
if(!['deployed','baseline','candidate'].includes(variant))throw Error('Explicit variant required');
const tree=JSON.parse(fs.readFileSync(path.join(base,'trees.json')))[variant+'Tree'];
const project='前端代码/admin',shared='前端代码/shared';
// Webpack includes the shared-module resolution location in one chunk filename.
// Reuse the verified, frozen original export for the deployed comparison only;
// candidate inputs remain the new canonical immutable tree, never old artifacts.
const snapshot=variant==='deployed'?'/private/tmp/mohe-r31-price-release/candidate-readonly':path.join(base,variant+'-readonly');
const input=path.join(snapshot,project),output=path.join(base,variant==='deployed'?'deployed-rebuilt-admin-dist':variant+'-admin-dist');
const git=args=>cp.execFileSync('git',args,{cwd:root,maxBuffer:128*1024*1024});
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
// Immutable exports are build inputs only, never a second editable business tree.
if(!fs.existsSync(input)){
    fs.mkdirSync(snapshot,{recursive:true});
    cp.execFileSync('tar',['-xf','-','-C',snapshot],{input:git(['archive',tree,project,shared])});
    fs.symlinkSync(path.join(root,project,'node_modules'),path.join(input,'node_modules'));
    const freeze=dir=>{for(const e of fs.readdirSync(dir,{withFileTypes:true})){const file=path.join(dir,e.name);if(e.isSymbolicLink())continue;if(e.isDirectory())freeze(file);else fs.chmodSync(file,0o444);}fs.chmodSync(dir,0o555);};freeze(snapshot);
}
const files=git(['ls-tree','-rz','--name-only',tree,'--',project,shared]).toString().split('\0').filter(Boolean);
for(const file of files)if(!fs.readFileSync(path.join(snapshot,file)).equals(git(['show',tree+':'+file])))throw Error('Snapshot drift '+file);
process.env.NODE_ENV='production';process.env.ANALYZE='false';process.env.BROWSERSLIST_IGNORE_OLD_DATA='true';process.env.BABEL_DISABLE_CACHE='1';process.env.VUE_CLI_CONTEXT=input;process.chdir(input);
const Service=require(path.join(root,project,'node_modules/@vue/cli-service/lib/Service'));const service=new Service(input);service.init('production');service.projectOptions.parallel=false;
// Match the deployed R31 pipeline exactly; lint is verified separately. Nested
// loader chains must also omit caches/workers to reproduce existing asset bytes.
service.webpackChainFns.push(config=>{const disable=rule=>{for(const key of ['cache-loader','thread-loader','eslint-loader'])if(rule.uses.has(key))rule.uses.delete(key);for(const nested of rule.oneOfs.values())disable(nested);};config.module.rules.values().forEach(disable);config.module.rules.delete('eslint');config.cache(false);});
service.webpackRawConfigFns.push(config=>{config.cache=false;for(const plugin of [...(config.plugins||[]),...((config.optimization||{}).minimizer||[])])if(plugin.options)for(const key of ['parallel','cache'])if(key in plugin.options)plugin.options[key]=false;});
service.run('build',{mode:'production',dest:output}).then(()=>{
    const result={tree,variant,source:root,input,output,node:process.version,inputFiles:files.length,htmlSha256:sha(fs.readFileSync(path.join(output,'system.html'))),result:'PASS'};
    for(const file of files)if(!fs.readFileSync(path.join(snapshot,file)).equals(git(['show',tree+':'+file])))throw Error('Post-build source drift '+file);
    fs.writeFileSync(path.join(base,variant+'-admin-build.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result));
}).catch(e=>{console.error(e);process.exitCode=1;});
