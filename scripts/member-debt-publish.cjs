// Restore only the already accepted debt read controller. Runtime files from
// other tasks are guarded, never used as an editable source or downgraded.
const fs=require('fs'),cp=require('child_process'),path=require('path'),crypto=require('crypto');
const base='/private/tmp/member-debt-publish-20261026',accepted='e3a38f4a87dc720b5532d53360eed98caa2c269f';
const old='58a20362d8db5cec0f462c6a64e6c8744a0781e3110e3a02b088daae07d64ce3',target='d8e46605b440a1a049d9d10f621f14d89e0b2169ba012ae30a2a64cd5a2b9226';
const service='6c976323f33e6b81cb9a62da39d2b6ee62753f23a18167c8fcba13ac50d88d3a';
const controller='app/controller/api/v1/order/StoreDebt.php';
const sites={rh:{host:'rh-server',domain:'rh.cc3798.com',db:'ruihao',port:3306,restart:'/etc/init.d/ruihao_swoole restart'},'010':{host:'mohe-server',domain:'010.cc3798.com',db:'mohe_010_app',port:3307,restart:'systemctl restart mohe-010-swoole.service'}};
const run=(c,a,o={})=>cp.execFileSync(c,a,{encoding:'utf8',maxBuffer:32e6,...o});
const git=(args,o={})=>run('git',args,o).trim();
const blob=f=>cp.execFileSync('git',['show',accepted+':'+f],{maxBuffer:32e6});
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const assert=(v,m)=>{if(!v)throw Error(m);};
const load=n=>JSON.parse(fs.readFileSync(path.join(base,n)));
const save=(n,v)=>fs.writeFileSync(path.join(base,n),JSON.stringify(v,null,2)+'\n');
const remote=(key,cmd,input)=>run('ssh',['-q','-o','BatchMode=yes',sites[key].host,cmd],{input});
fs.mkdirSync(base,{recursive:true});
function probe(key,mode){
  assert(sites[key],'Explicit site required');
  return JSON.parse(remote(key,'env DEBT_PROBE_SITE='+key+' DEBT_PROBE_MODE='+mode+' /www/server/php/74/bin/php',fs.readFileSync('scripts/member-debt-sites-probe.php')));
}
function same(a,b,changingController=false){
  for(const k of ['site','database','databaseVersion','environment','assets','data','upgrade'])assert(JSON.stringify(a[k])===JSON.stringify(b[k]),'Baseline changed: '+k);
  for(const [f,h]of Object.entries(a.files)){if(changingController&&f===controller)continue;assert(b.files[f]===h,'Protected file changed: '+f);}
}
async function publicCheck(key){
  const paths=['/api/debt/list?page=1&limit=20','/pages/users/debt/index','/system.html','/view_cashier_v3/'];
  if(key==='rh')paths.push('/preview-8080/view_cashier_v3/','/store-v3/');
  const out={};for(const p of paths){const raw=run('curl',['-4','-sS','--max-time','25','-w','\n%{http_code}','https://'+sites[key].domain+p]);const n=raw.lastIndexOf('\n'),status=Number(raw.slice(n+1)),body=raw.slice(0,n);if(p.startsWith('/api/debt/')){const json=JSON.parse(body);assert([410000,410001,410002].includes(Number(json.status)),'Unauthenticated debt route did not reject access');out[p]={httpStatus:status,businessStatus:json.status};}else{const retired=p.startsWith('/preview-8080/')||p==='/store-v3/';assert(status===(retired?410:200),'Public entry failed: '+p);out[p]={httpStatus:status,sha256:sha(body)};}}
  return out;
}
async function main(){
  const [mode,key]=process.argv.slice(2);
  if(mode==='inspect'){
    assert(!fs.existsSync(path.join(base,key+'-before.json')),'Never overwrite original snapshot');
    const before=probe(key,'snapshot');assert([old,target].includes(before.files[controller]),'Unrecognized controller baseline');assert(before.files['app/services/order/StoreDebtServices.php']===service,'Unreviewed service baseline');
    save(key+'-before.json',before);save(key+'-public-before.json',await publicCheck(key));console.log(JSON.stringify({site:key,controller:before.files[controller],alreadyTarget:before.files[controller]===target,database:before.database,upgrade:before.upgrade}));
  }else if(mode==='deploy'){
    if(key==='010'){assert(load('rh-verified.json').result==='PASS','RH must pass first');const pushed=load('pushed.json');assert(git(['ls-remote','origin','refs/heads/'+pushed.branch]).split(/\s/)[0]===pushed.commit,'Push not verified');}
    const before=load(key+'-before.json'),now=probe(key,'snapshot');same(before,now);
    if(now.files[controller]===target){save(key+'-installed.json',{result:'ALREADY_TARGET',filesChanged:0,restart:false});console.log('ALREADY_TARGET: verified; no overwrite or restart');return;}
    assert(sha(blob('后端代码/'+controller))===target,'Accepted controller changed');
    let installer=blob('scripts/member-debt-list-008-install.php').toString();
    installer=installer.split('008.cc3798.com').join(sites[key].domain).split('mohe_008').join(sites[key].db).split('!==3307').join('!=='+sites[key].port).split('systemctl restart mohe-008-swoole.service').join(sites[key].restart);
    const directory='/www/backups/'+sites[key].domain+'/20261026-member-debt-list-40147d66';
    remote(key,'test ! -e '+directory+' && mkdir -p '+directory+' && chmod 700 '+directory);
    remote(key,'cat > '+directory+'/candidate.php',blob('后端代码/'+controller));remote(key,'cat > '+directory+'/install.php',installer);
    assert(remote(key,'sha256sum '+directory+'/candidate.php').split(/\s/)[0]===target,'Controller transfer differs');assert(remote(key,'sha256sum '+directory+'/install.php').split(/\s/)[0]===sha(installer),'Installer transfer differs');
    save(key+'-attempt.json',{accepted,controllerSha256:target,directory,installerSha256:sha(installer),status:'ATTEMPT_STARTED'});
    const result=JSON.parse(remote(key,'/www/server/php/74/bin/php '+directory+'/install.php install'));save(key+'-installed.json',{...result,directory});console.log(JSON.stringify(result));
  }else if(mode==='verify'){
    const before=load(key+'-before.json'),after=probe(key,'snapshot');assert(after.files[controller]===target,'Controller not restored');same(before,after,true);save(key+'-after.json',after);
    const behavior=probe(key,'behavior');assert(behavior.result==='PASS','Runtime behavior failed');save(key+'-behavior.json',behavior);save(key+'-public-after.json',await publicCheck(key));
    const result={site:key,result:'PASS',accepted,controllerSha256:target,serviceUnchanged:true,frontendUnchanged:true,debtDataUnchanged:true,configurationUnchanged:true,behavior:behavior.checks,action:load(key+'-installed.json').result};save(key+'-verified.json',result);console.log(JSON.stringify(result));
  }else if(mode==='push'){
    assert(load('rh-verified.json').result==='PASS','RH verification must precede push');
    // Preserve the already-published, accepted R65 baseline, which includes
    // R53's live repayment guard. The older member-only branch lacks that
    // unrelated accepted baseline and must not become a new full-release source.
    const parent='60f709d25c3d6c9b83812fe3993aea71dcf9faa3',parentBranch='release/r65-member-payment-return-20261026',branch='release/member-debt-20261026';
    assert(git(['ls-remote','origin','refs/heads/'+parentBranch]).split(/\s/)[0]===parent,'Published baseline changed');
    assert(git(['ls-remote','origin','refs/heads/'+branch])==='','Release branch already exists: inspect before resending');
    assert(sha(cp.execFileSync('git',['show',parent+':后端代码/'+controller]))===target,'Published controller baseline differs');
    assert(sha(cp.execFileSync('git',['show',parent+':后端代码/app/services/order/StoreDebtServices.php']))===service,'Published repayment guard missing');
    const indexPath=path.resolve(git(['rev-parse','--git-path','index'])),indexBefore=sha(fs.readFileSync(indexPath));
    const env={...process.env,GIT_INDEX_FILE:path.join(base,'push.index')};run('git',['read-tree',parent],{env});
    const files=run('git',['diff-tree','--no-commit-id','--name-only','-z','-r',accepted]).split('\0').filter(Boolean);assert(files.length===13,'Accepted archive boundary changed');
    for(const f of files){const m=/^(\d+) blob ([0-9a-f]+)\t/.exec(run('git',['ls-tree','-z',accepted,'--',f]));assert(m,'Accepted file missing');run('git',['update-index','--add','--cacheinfo',m[1],m[2],f],{env});}
    const tree=run('git',['write-tree'],{env}).trim();
    const changed=run('git',['diff-tree','--no-commit-id','--name-only','-r','-z',parent,tree]).split('\0').filter(Boolean);assert(changed.length>0&&changed.every(f=>files.includes(f)),'Unrelated push content');
    for(const f of files)assert(git(['rev-parse',tree+':'+f])===git(['rev-parse',accepted+':'+f]),'Accepted archive differs');
    const commit=run('git',['commit-tree',tree,'-p',parent],{input:'M008-MEMBER-DEBT-20261026 欠款入口恢复与008验证精准归档\n\nAccepted source '+accepted+'; existing controller already present in published R65. Preserve all published repayment guards.\n'}).trim();
    run('git',['update-ref','refs/heads/'+branch,commit,'0000000000000000000000000000000000000000']);
    run('git',['push','origin',commit+':refs/heads/'+branch]);assert(git(['ls-remote','origin','refs/heads/'+branch]).split(/\s/)[0]===commit,'Remote push not verified');
    assert(sha(fs.readFileSync(indexPath))===indexBefore,'Unrelated staging changed');
    const result={result:'PASS',branch,commit,parent,parentBranch,accepted,files,controllerAlreadyInPublishedParent:true,acceptedLiveRepaymentGuardPreserved:true,unrelatedStagingUnchanged:true};save('pushed.json',result);console.log(JSON.stringify(result));
  }else throw Error('Use inspect, deploy, verify or push');
}
main().catch(e=>{console.error(e);process.exitCode=1;});
