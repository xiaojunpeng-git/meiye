const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const crypto = require('crypto');
const root = process.cwd();
const dir = path.join(root, 'tests/target-scheme/evidence/R95-excel-names-2026-10-24/release');
const sites = { rh: 'rh-server', '008': 'mohe-server', '010': 'mohe-server' };
const sha = data => crypto.createHash('sha256').update(data).digest('hex');
fs.mkdirSync(dir, { recursive: true });
const run = (cmd,args,options={}) => cp.execFileSync(cmd,args,{cwd:root,maxBuffer:128*1024*1024,...options});
const git = (...args) => run('git',args);
function inspect(site, phase) {
    if (!sites[site] || !['before','recheck','after'].includes(phase)) throw Error('Explicit site/phase required');
    const probe = fs.readFileSync('scripts/r28-permissions-inspect.php');
    const bytes = run('ssh',['-q','-o','BatchMode=yes','-o','ConnectTimeout=15',sites[site],'/www/server/php/74/bin/php -- '+site],{input:probe});
    const value = JSON.parse(bytes);
    fs.writeFileSync(path.join(dir,site+'-'+phase+'.json'),JSON.stringify(value,null,2));
    console.log(JSON.stringify({site,phase,backendFiles:Object.keys(value.backend).length,frontends:value.frontends,upgrades:value.upgrades},null,2));
}
function compare() {
    const files=['app/controller/admin/v1/target/StoreTarget.php','app/services/target/TargetSchemeServices.php','app/services/report/GroupManagementDashboardTargetServices.php','route/admin.php'];
    const refs=['d4615e66','372b9283','HEAD'];
    for(const site of Object.keys(sites)){
        const value=JSON.parse(fs.readFileSync(path.join(dir,site+'-before.json')));
        for(const file of files){
            const matches=refs.filter(ref=>sha(git('show',ref+':后端代码/'+file))===value.backend[file]);
            console.log(JSON.stringify({site,file,sha256:value.backend[file],matches}));
        }
    }
}
if(process.argv[2]==='inspect')inspect(process.argv[3],process.argv[4]||'before');
else if(process.argv[2]==='targets'){
    const site=process.argv[3], phase=process.argv[4]||'before';
    if(!sites[site] || !['before','recheck','after'].includes(phase))throw Error('Explicit site/phase required');
    const data=JSON.parse(run('ssh',['-q','-o','BatchMode=yes','-o','ConnectTimeout=15',sites[site],'/www/server/php/74/bin/php -- '+site],{input:fs.readFileSync('scripts/r95-excel-target-inspect.php')}));
    fs.writeFileSync(path.join(dir,site+'-targets-'+phase+'.json'),JSON.stringify(data,null,2));
    console.log(JSON.stringify({site,tables:Object.fromEntries(Object.entries(data.tables).map(([key,v])=>[key,{rows:v.rows,sha256:v.sha256}])),assetCounts:Object.fromEntries(Object.entries(data.adminAssets).map(([key,v])=>[key,Object.keys(v).length]))},null,2));
}
else if(process.argv[2]==='compare')compare();
else if(process.argv[2]==='runtime'){
    const site=process.argv[3],phase=process.argv[4]||'before';
    if(!sites[site]||!['before','after'].includes(phase))throw Error('Explicit site/phase required');
    const value=JSON.parse(run('ssh',['-q','-o','BatchMode=yes',sites[site],'/www/server/php/74/bin/php -- '+site+' '+phase],{input:fs.readFileSync('scripts/r95-excel-runtime-check.php')}));
    fs.writeFileSync(path.join(dir,site+'-runtime-'+phase+'.json'),JSON.stringify(value,null,2));console.log(JSON.stringify(value));
}
else throw Error('Explicit action required');
