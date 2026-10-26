const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
const root=process.cwd(),base='/private/tmp/mohe-r95-excel-release-20261024';
const evidence='tests/target-scheme/evidence/R95-excel-names-2026-10-24',release=path.join(evidence,'release');
const branch='release/r95-target-excel-names-20261024';
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const run=(cmd,args,options={})=>cp.execFileSync(cmd,args,{cwd:root,maxBuffer:128*1024*1024,...options});
const git=(...args)=>run('git',args);
const read=file=>JSON.parse(fs.readFileSync(file));
const save=(file,data)=>fs.writeFileSync(file,JSON.stringify(data,null,2));
const plan=read(path.join(base,'trees.json'));
const sites={rh:{host:'rh-server',root:'/www/wwwroot/rh.cc3798.com'},'008':{host:'mohe-server',root:'/www/wwwroot/008.cc3798.com'},'010':{host:'mohe-server',root:'/www/wwwroot/010.cc3798.com'}};
const walk=dir=>fs.readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?walk(path.join(dir,e.name)):[path.join(dir,e.name)]);
function approved(){
    const approval=read(path.join(release,'release-approval.json'));
    if(approval.candidateTree!==plan.candidateTree||approval.supplementalAccepted!==true||approval.preview!==true||approval.push!==true||approval.retain010!==true)throw Error('Fixed release acceptance required');
    const proof=read(path.join(release,'build-proof.json'));
    if(proof.result!=='PASS'||proof.builds.candidate.tree!==plan.candidateTree)throw Error('Reconstructed baseline and fixed build proof required');
}
function commit(){
    approved();if(fs.existsSync(path.join(release,'commit.json')))throw Error('Commit already recorded');
    const sharedIndex=path.resolve(root,git('rev-parse','--git-path','index').toString().trim()),original=sha(fs.readFileSync(sharedIndex));
    const env={...process.env,GIT_INDEX_FILE:path.join(base,'commit.index')};
    const privateGit=(...args)=>run('git',args,{env});
    const prior=git('commit-tree',plan.baselineTree,'-p',plan.source,'-m','R95-00095 发布前保留已验收目标方案逐行生效与作废删除基线\n\n来源372b9283，与瑞昊和008现网一致；010已明确授权补齐。非本轮新增业务。').toString().trim();
    privateGit('read-tree',plan.candidateTree);
    const files=[...read(path.join(evidence,'fixed-candidate.json')).files.map(x=>x.file).filter(x=>!x.startsWith('前端代码/')&&!x.startsWith('后端代码/')),
        ...walk('scripts').filter(f=>/^scripts\/r95-excel-[^/]+$/.test(f)),
        'tests/target-scheme/js/r95-excel-installer.cjs','tests/target-scheme/js/r95-retained-filters.mjs',
        ...walk(evidence)];
    // Only this round's explicit paths are staged. Business blobs are already
    // fixed in the accepted tree; never read the dirty shared controller here.
    for(const file of [...new Set(files)]){const blob=git('hash-object','-w',file).toString().trim();privateGit('update-index','--add','--cacheinfo',`100644,${blob},${file}`);}
    const tree=privateGit('write-tree').toString().trim();
    // Preserve the accepted patch byte-for-byte: unified diff blank context
    // lines contain one space by design, not source trailing whitespace.
    privateGit('diff','--cached','--check',prior,'--','.',':(exclude)'+evidence+'/business.patch');
    if(sha(fs.readFileSync(sharedIndex))!==original)throw Error('Shared index changed; inspect before committing');
    const current=git('commit-tree',tree,'-p',prior,'-m','R95-00095 目标方案中文Excel模板与名称导入闭环\n\n仅新增中文模板、精确名称导入和相称测试；保留旧模板、权限、草稿保存及报表目标口径。固定业务补丁eb48c976，产品经理验收及发布补验通过。').toString().trim();
    git('update-ref','refs/heads/'+branch,current,'0000000000000000000000000000000000000000');
    const result={prerequisite:prior,commit:current,tree,branch,businessTree:plan.candidateTree,sharedHead:git('rev-parse','HEAD').toString().trim(),sharedIndexSha256:original,files:[...new Set(files)]};save(path.join(release,'commit.json'),result);console.log(JSON.stringify({prerequisite:prior,commit:current,tree,branch}));
}
function payload(site){
    approved();if(!sites[site])throw Error('Explicit site required');
    const record=read(path.join(release,'commit.json')),before=read(path.join(release,site+'-recheck.json')),targetsBefore=read(path.join(release,site+'-targets-recheck.json'));
    const initial=read(path.join(release,site+'-before.json')),initialTargets=read(path.join(release,site+'-targets-before.json'));
    if(JSON.stringify(before.frontends.filter(x=>x.kind==='admin'))!==JSON.stringify(initial.frontends.filter(x=>x.kind==='admin'))||JSON.stringify(targetsBefore.adminAssets)!==JSON.stringify(initialTargets.adminAssets))throw Error('Shared platform baseline changed; supplemental acceptance required');
    for(const file of ['app/controller/admin/v1/target/StoreTarget.php','app/services/target/TargetSchemeServices.php','app/services/report/GroupManagementDashboardTargetServices.php','route/admin.php'])if(before.backend[file]!==initial.backend[file])throw Error('Shared backend baseline changed '+file);
    if(site==='010'&&Object.values(targetsBefore.tables).some(t=>t.rows!==0))throw Error('010 now has target data; recheck compatibility before deployment');
    const directory=path.join(base,site+'-payload');if(fs.existsSync(directory))throw Error('Payload already exists');fs.mkdirSync(directory);
    const targets={};
    const put=(key,bytes,old)=>{const after=sha(bytes);if(old===after)return;const dest=path.join(directory,key);fs.mkdirSync(path.dirname(dest),{recursive:true});fs.writeFileSync(dest,bytes);targets[key]={before:old??null,after};};
    const dist=path.join(base,'candidate-admin-dist'),assets=walk(path.join(dist,'view_admin'));
    for(const [file,expected]of Object.entries(read(path.join(release,'build-proof.json')).candidateAssets))if(sha(fs.readFileSync(path.join(dist,file)))!==expected)throw Error('Fixed artifact drift '+file);
    const roots=site==='rh'?['formal','preview']:['formal'];
    for(const kind of roots){const onlineRoot=sites[site].root+(kind==='preview'?'-8080':'');const hashes=targetsBefore.adminAssets[onlineRoot];if(!hashes)throw Error('Missing asset baseline');
        for(const file of assets){const rel=path.relative(dist,file);put(kind+'/public/'+rel,fs.readFileSync(file),hashes[rel]);}
    }
    for(const file of ['app/controller/admin/v1/target/StoreTarget.php','app/services/target/TargetSchemeServices.php','app/services/report/GroupManagementDashboardTargetServices.php'])put('formal/'+file,git('show',record.commit+':后端代码/'+file),before.backend[file]);
    // Entrypoints switch last, after all hashed assets and compatible backend.
    for(const kind of roots){const onlineRoot=sites[site].root+(kind==='preview'?'-8080':'');const entry=before.frontends.find(f=>f.root===onlineRoot&&f.kind==='admin');put(kind+'/public/system.html',fs.readFileSync(path.join(dist,'system.html')),entry.sha256);}
    const manifest={site,commit:record.commit,previewAuthorized:site==='rh',targets};save(path.join(directory,'manifest.json'),manifest);save(path.join(release,site+'-manifest.json'),manifest);
    fs.copyFileSync('scripts/r95-excel-install.php',path.join(directory,'install.php'));
    run('tar',['-czf',path.join(base,site+'-payload.tgz'),'-C',directory,'.']);
    console.log(JSON.stringify({site,commit:record.commit,targets:Object.keys(targets).length,bytes:fs.statSync(path.join(base,site+'-payload.tgz')).size,backup:'/www/backups/'+site+'/r95-excel-'+record.commit.slice(0,8)}));
}
function deploy(site){
    approved();if(!sites[site])throw Error('Explicit site required');
    if(site==='008'&&!fs.existsSync(path.join(release,'push.json')))throw Error('Push first');
    if(site==='010'&&!fs.existsSync(path.join(release,'008-verified.json')))throw Error('008 verification first');
    const record=read(path.join(release,'commit.json')),manifest=read(path.join(release,site+'-manifest.json'));
    if(manifest.commit!==record.commit)throw Error('Commit drift');
    const remote='/www/backups/'+site+'/r95-excel-'+record.commit.slice(0,8),ssh=args=>run('ssh',['-q','-o','BatchMode=yes',sites[site].host,...args]);
    ssh(['mkdir -p '+remote+'/payload']);run('scp',['-q',path.join(base,site+'-payload.tgz'),sites[site].host+':'+remote+'/payload.tgz']);
    ssh(['tar -xzf '+remote+'/payload.tgz -C '+remote+'/payload']);
    const output=ssh(['/www/server/php/74/bin/php '+remote+'/payload/install.php '+remote+' install']).toString();console.log(output);
    const restart=site==='rh'?'/etc/init.d/ruihao_swoole restart':'systemctl restart mohe-'+site+'-swoole.service';
    console.log(ssh([restart]).toString());save(path.join(release,site+'-installed.json'),{commit:record.commit,remote,result:output,time:new Date().toISOString()});
}
function push(){approved();if(!fs.existsSync(path.join(release,'rh-verified.json')))throw Error('RH verification first');const record=read(path.join(release,'commit.json'));const result=run('git',['push','git@gitee.com:mhfz/rh.git',record.commit+':refs/heads/'+branch]).toString();const remote=git('ls-remote','git@gitee.com:mhfz/rh.git','refs/heads/'+branch).toString().trim();if(remote.split(/\s/)[0]!==record.commit)throw Error('Remote mismatch');save(path.join(release,'push.json'),{commit:record.commit,branch,remote:'git@gitee.com:mhfz/rh.git',verified:true,result});console.log(remote);}
function verify(site){
    approved();if(!sites[site])throw Error('Explicit site required');
    for(const action of ['inspect','targets'])run('node',['scripts/r95-excel-release.cjs',action,site,'after']);
    const before=read(path.join(release,site+'-recheck.json')),after=read(path.join(release,site+'-after.json'));
    const oldTargets=read(path.join(release,site+'-targets-recheck.json')),now=read(path.join(release,site+'-targets-after.json')),manifest=read(path.join(release,site+'-manifest.json'));
    const backendChanges=new Set(Object.keys(manifest.targets).filter(k=>k.startsWith('formal/app/')).map(k=>k.slice(7)));
    for(const [file,hash]of Object.entries(before.backend))if(!backendChanges.has(file)&&after.backend[file]!==hash)throw Error('Unrelated backend drift '+file);
    if(JSON.stringify(before.protected)!==JSON.stringify(after.protected))throw Error('Protected configuration/terminal drift');
    for(const original of before.frontends.filter(f=>f.kind==='cashier'))if(JSON.stringify(after.frontends.find(f=>f.root===original.root&&f.kind==='cashier'))!==JSON.stringify(original))throw Error('Cashier frontend drift');
    for(const [key,value]of Object.entries(manifest.targets)){
        const preview=key.startsWith('preview/'),relative=key.slice(preview?8:7),online=sites[site].root+(preview?'-8080':'');
        const actual=relative==='public/system.html'?after.frontends.find(f=>f.root===online&&f.kind==='admin').sha256:relative.startsWith('public/')?now.adminAssets[online][relative.slice(7)]:after.backend[relative];
        if(actual!==value.after)throw Error('Installed hash mismatch '+key);
    }
    for(const [table,original]of Object.entries(oldTargets.tables))if(now.tables[table]?.sha256!==original.sha256)throw Error('Target data changed; investigate concurrent activity '+table);
    const runtime=JSON.parse(run('ssh',['-q','-o','BatchMode=yes',sites[site].host,'/www/server/php/74/bin/php -- '+site],{input:fs.readFileSync('scripts/r95-excel-runtime-check.php')}));
    save(path.join(release,site+'-runtime.json'),runtime);
    if(JSON.stringify(runtime.reports)!==JSON.stringify(read(path.join(release,site+'-runtime-before.json')).reports))throw Error('Report target reader changed; investigate before continuing');
    run('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node',['scripts/r95-excel-public-browser.cjs',site,'after']);
    const result={site,commit:manifest.commit,targets:Object.keys(manifest.targets).length,unrelatedBackendPreserved:true,otherTerminalsPreserved:true,targetDataUnchanged:true,runtime,publicBrowser:read(path.join(release,site+'-after-public.json')),result:'PASS',time:new Date().toISOString()};
    save(path.join(release,site+'-verified.json'),result);console.log(JSON.stringify(result,null,2));
}
const action=process.argv[2];
if(action==='commit')commit();else if(action==='payload')payload(process.argv[3]);else if(action==='deploy')deploy(process.argv[3]);else if(action==='verify')verify(process.argv[3]);else if(action==='push')push();else throw Error('Explicit action required');
