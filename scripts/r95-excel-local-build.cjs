const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const cp = require('child_process');
const root = process.cwd();
const app = path.join(root, '前端代码/admin');
const output = '/private/tmp/mohe-r95-excel-local-build-20261024';
// 本地编译唯一正本，关闭额外编译进程以控制内存；产物不作为发布候选，不连接远程。
process.env.NODE_ENV = 'production'; process.env.ANALYZE = 'false'; process.env.VUE_CLI_CONTEXT = app;
process.chdir(app);
const Service = require(path.join(app,'node_modules/@vue/cli-service/lib/Service'));
const service = new Service(app); service.init('production');service.projectOptions.parallel=false;
service.webpackChainFns.push(config=>{
    config.module.rules.values().forEach(rule=>{for(const name of ['cache-loader','thread-loader'])if(rule.uses.has(name))rule.uses.delete(name);});config.cache(false);
});
service.webpackRawConfigFns.push(config=>{
    for(const plugin of [...(config.plugins||[]),...((config.optimization||{}).minimizer||[])])if(plugin.options)for(const key of ['parallel','cache'])if(key in plugin.options)plugin.options[key]=false;
});
service.run('build',{mode:'production',dest:output}).then(()=>{
    const result={result:'PASS',head:cp.execFileSync('git',['rev-parse','HEAD'],{cwd:root}).toString().trim(),source:app,hotEntry:'http://127.0.0.1:18081/admin/login',output,htmlSha256:crypto.createHash('sha256').update(fs.readFileSync(path.join(output,'system.html'))).digest('hex'),release:false};
    fs.writeFileSync(path.join(root,'tests/target-scheme/evidence/R95-excel-names-2026-10-24/build.json'),JSON.stringify(result,null,2)); console.log(JSON.stringify(result));
}).catch(e=>{console.error(e);process.exitCode=1;});
