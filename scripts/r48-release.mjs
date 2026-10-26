import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdirSync, mkdtempSync } from 'node:fs'
import { resolve } from 'node:path'
import { createHash } from 'node:crypto'

// A fixed, accepted release only. Each instance has independent identity guards,
// backup, migration, deployment receipts and rollback protection for new facts.
const root=process.cwd(), evidence=resolve('tests/cashier-v3/evidence/R48-release')
const accepted=JSON.parse(readFileSync(evidence+'/fixed-candidate.json'))
const receipt=JSON.parse(readFileSync(evidence+'/commit-receipt.json'))
const [mode, instance, directory]=process.argv.slice(2)
const run=(p,args,opts={})=>execFileSync(p,args,{encoding:'utf8',maxBuffer:64*1024*1024,...opts})
const git=args=>run('git',args).trim()
const sha=b=>createHash('sha256').update(b).digest('hex')
const q=v=>"'"+String(v).replaceAll("'","'\\''")+"'"
const checked=(file,hash)=>`test "$(sha256sum ${q(file)} | cut -d' ' -f1)" = '${hash}'`
const commit=receipt.commit, branch=receipt.branch
if (git(['rev-parse',commit+'^{tree}'])!==accepted.candidateTree || commit!=='c3625d2adeb67a01ca95e078517dcf45d503fef3') throw Error('Accepted commit changed')
const backend=accepted.scope.filter(p=>p.startsWith('后端代码/app/'))
const sql=accepted.scope.find(p=>p.endsWith('/01-schema.sql'))
const fingerprints=Object.fromEntries(backend.concat(sql).map(p=>[p,sha(run('git',['show',commit+':'+p],{encoding:null}))]))
const helper=resolve('scripts/r48-coupon-release.php')
const cashier=JSON.parse(readFileSync(evidence+'/candidate-cashier-v3-build.json'))
const platform=JSON.parse(readFileSync(evidence+'/candidate-admin-build.json'))
const platformAssets=Object.fromEntries(Object.entries(platform.assets).filter(([p])=>p==='system.html'||p.startsWith('view_admin/')))
if (cashier.tree!==accepted.candidateTree || platform.tree!==accepted.candidateTree) throw Error('Build tree mismatch')
if (mode==='prepare') {
  const dir=mkdtempSync('/private/tmp/mohe-r48-payload-')
  const backendTar=dir+'/backend.tar',cashierTar=dir+'/cashier.tar.gz',platformTar=dir+'/platform.tar.gz'
  writeFileSync(backendTar,run('git',['archive',commit,...backend,sql],{encoding:null}))
  for(const [build,assets,archive] of [[cashier,cashier.assets,cashierTar],[platform,platformAssets,platformTar]]) {
    for(const [p,hash] of Object.entries(assets)) if(sha(readFileSync(build.output+'/'+p))!==hash)throw Error('Build asset moved: '+p)
    run('tar',['-czf',archive,'-C',build.output,...Object.keys(assets)])
  }
  const manifest={commit,branch,tree:accepted.candidateTree,backend,sql,fingerprints,cashierAssets:cashier.assets,platformAssets,
    backendTar,cashierTar,platformTar,backendSha:sha(readFileSync(backendTar)),cashierSha:sha(readFileSync(cashierTar)),platformSha:sha(readFileSync(platformTar)),helperSha:sha(readFileSync(helper))}
  writeFileSync(dir+'/manifest.json',JSON.stringify(manifest,null,2)+'\n')
  writeFileSync(evidence+'/payload-location.json',JSON.stringify({directory:dir,commit,manifest:dir+'/manifest.json'},null,2)+'\n')
  console.log(JSON.stringify({directory:dir,commit,backendFiles:backend.length,platformFiles:Object.keys(platformAssets).length}));process.exit(0)
}
const sites={rh:{host:'47.96.103.107',domain:'rh.cc3798.com',restart:'/etc/init.d/ruihao_swoole restart',health:"ss -lnt | grep -q ':20800 '"},
  '008':{host:'121.41.109.138',domain:'008.cc3798.com',restart:'systemctl restart mohe-008-swoole.service',health:'systemctl is-active --quiet mohe-008-swoole.service'}}
if(!sites[instance]||!directory?.startsWith('/private/tmp/mohe-r48-payload-')||!['preflight','backup','migrate','switch','verify','rollback','repair-static-permissions'].includes(mode))throw Error('Invalid release phase')
const manifest=JSON.parse(readFileSync(directory+'/manifest.json'))
if(manifest.commit!==commit||manifest.helperSha!==sha(readFileSync(helper)))throw Error('Release helper identity changed')
for(const [path,hash]of[[manifest.backendTar,manifest.backendSha],[manifest.cashierTar,manifest.cashierSha],[manifest.platformTar,manifest.platformSha]])if(sha(readFileSync(path))!==hash)throw Error('Release archive moved')
const site=sites[instance],base='/www/wwwroot/'+site.domain
// A successful restart may precede socket readiness; wait boundedly before
// treating an ordinary startup delay as a reason to invoke rollback.
const waitHealth=`for attempt in $(seq 1 30); do if ${site.health}; then break; fi; sleep 1; done\n${site.health}\n`
const backup='/www/backups/'+site.domain+'/20261022-r48-'+commit.slice(0,8),stage=backup+'/stage'
const helperRemote=['preflight','backup'].includes(mode)?'/tmp/r48-'+commit.slice(0,8)+'-release.php':backup+'/schema-release.php'
const frontDirs=[base+'/public/view_cashier_v3',...(instance==='rh'?['/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3']:[])]
const live=JSON.parse(readFileSync(evidence+'/'+instance+'-before.json'))
const ssh=['-i',process.env.HOME+'/.ssh/aliyun-ecs','-o','BatchMode=yes','-o','ConnectTimeout=12']
const remote=script=>{
  const input=Buffer.from('set -euo pipefail\numask 077\n'+script)
  run('bash',['-n'],{input})
  return run('ssh',[...ssh,'root@'+site.host,'bash -s'],{input})
}
const upload=(local,path)=>run('scp',[...ssh,local,'root@'+site.host+':'+path])
const save=(name,data)=>{writeFileSync(evidence+'/'+instance+'-'+name+'.json',JSON.stringify(data,null,2)+'\n');console.log(JSON.stringify(data))}
const schema=phase=>JSON.parse(remote(checked(helperRemote,manifest.helperSha)+`\nphp ${q(helperRemote)} ${q(instance)} ${q(phase)} ${q(commit)}\n`))
const sourceGuard=after=>Object.entries(live.files).map(([p,old])=>{
  const path='后端代码/'+p,expected=after&&backend.includes(path)?fingerprints[path]:old
  return expected?checked(base+'/'+p,expected):`test ! -e ${q(base+'/'+p)}`
}).join('\n')
const frontGuard=after=>frontDirs.map((dir,i)=>{
  const old=Object.values(live.front)[i],assets=after?manifest.cashierAssets:{'index.html':old.html,...old.assets}
  return Object.entries(assets).map(([p,h])=>checked(dir+'/'+p,h)).join('\n')
}).join('\n')
const platformGuard=after=>Object.entries(after?manifest.platformAssets:{'system.html':live.platform.html,...live.platform.assets}).map(([p,h])=>checked(base+'/public/'+p,h)).join('\n')
const guard=after=>site.health+'\n'+sourceGuard(after)+'\n'+frontGuard(after)+'\n'+platformGuard(after)+'\n'
const requirePreflight=()=>{const p=JSON.parse(readFileSync(evidence+'/'+instance+'-preflight.json'));if(p.commit!==commit||p.backup!==backup)throw Error('Preflight receipt missing')}
if(mode==='preflight') {
  // Revalidate every retained asset too, not only the HTML or main bundle.
  remote(guard(false)+`test ! -e ${q(backup)}\n`)
  upload(helper,helperRemote);remote(checked(helperRemote,manifest.helperSha)+`\nphp -l ${q(helperRemote)} >/dev/null\n`)
  const business=schema('probe');save('preflight',{instance,commit,backup,business,result:'PASS',checkedAt:new Date().toISOString()});process.exit(0)
}
requirePreflight()
// Build directories may inherit a restrictive local umask. Public static
// directories need traversal by nginx, regardless of source archive metadata.
const staticModes=dirs=>`find ${dirs.map(q).join(' ')} -type d -exec chmod 755 {} +\nfind ${dirs.map(q).join(' ')} -type f -exec chmod 644 {} +\n`
const webReadable=paths=>paths.map(file=>`su -s /bin/sh -c ${q('test -r '+q(file))} www\n`).join('')
if(mode==='repair-static-permissions') {
  remote(guard(true)+staticModes([...frontDirs.map(dir=>dir+'/assets'),base+'/public/view_admin']))
  save('static-permissions',{instance,commit,result:'PASS',directoryMode:'755',fileMode:'644',scope:'Public cashier assets and view_admin only; no private config or backup paths changed'});process.exit(0)
}
if(mode==='backup') {
  if(instance==='008') {
    if(JSON.parse(readFileSync(evidence+'/rh-verified.json')).result!=='PASS'||git(['ls-remote','origin','refs/heads/'+branch]).split(/\s+/)[0]!==commit)throw Error('RH verified and exact code pushed first')
  }
  remote(guard(false));save('database-backup',schema('backup'));upload(helper,backup+'/schema-release.php')
  const oldBackend=backend.filter(p=>live.files[p.slice('后端代码/'.length)])
  remote(`mkdir -p ${q(stage)}\ntar -czf ${q(backup+'/backend-before.tar.gz')} -C ${q(base)} ${oldBackend.map(p=>q(p.slice('后端代码/'.length))).join(' ')}\ngzip -t ${q(backup+'/backend-before.tar.gz')}\ncp -p ${q(base+'/public/system.html')} ${q(backup+'/system-before.html')}\n`
    +frontDirs.map((dir,i)=>`mkdir -p ${q(backup+'/front-'+i+'/assets')}\ncp -p ${q(dir+'/index.html')} ${q(backup+'/front-'+i+'/index.html')}\n`+Object.keys(Object.values(live.front)[i].assets).map(p=>`cp -p ${q(dir+'/'+p)} ${q(backup+'/front-'+i+'/'+p)}\n`).join('')).join(''))
  for(const [local,name,hash]of[[manifest.backendTar,'backend.tar',manifest.backendSha],[manifest.cashierTar,'cashier.tar.gz',manifest.cashierSha],[manifest.platformTar,'platform.tar.gz',manifest.platformSha]]) {
    upload(local,backup+'/'+name);remote(checked(backup+'/'+name,hash)+'\n')
  }
  remote(`tar -xf ${q(backup+'/backend.tar')} -C ${q(stage)}\nmkdir -p ${q(stage+'/cashier')} ${q(stage+'/platform')}\ntar -xzf ${q(backup+'/cashier.tar.gz')} -C ${q(stage+'/cashier')}\ntar -xzf ${q(backup+'/platform.tar.gz')} -C ${q(stage+'/platform')}\ncp ${q(stage+'/'+sql)} ${q(stage+'/schema.sql')}\n`
    +backend.map(p=>checked(stage+'/'+p,fingerprints[p])+`\nphp -l ${q(stage+'/'+p)} >/dev/null\n`).join('')
    +Object.entries(manifest.cashierAssets).map(([p,h])=>checked(stage+'/cashier/'+p,h)+'\n').join('')
    +Object.entries(manifest.platformAssets).map(([p,h])=>checked(stage+'/platform/'+p,h)+'\n').join(''))
  save('backup',{instance,commit,backup,result:'PASS',archives:{backend:manifest.backendSha,cashier:manifest.cashierSha,platform:manifest.platformSha}});process.exit(0)
}
if(mode==='migrate'){remote(guard(false));save('migration',schema('migrate'));process.exit(0)}
// Hashed resources are append-only, so restoring entries restores the old graph.
// Additive tables/log remain. Never revert to old code once new coupon facts exist.
const rollback=`tar -xzf ${q(backup+'/backend-before.tar.gz')} -C ${q(base)}\n`
  +backend.filter(p=>!live.files[p.slice('后端代码/'.length)]).map(p=>`rm -f ${q(base+'/'+p.slice('后端代码/'.length))}\n`).join('')
  +frontDirs.map((dir,i)=>`cp -p ${q(backup+'/front-'+i+'/index.html')} ${q(dir+'/index.html.r48-rollback')}\nmv -f ${q(dir+'/index.html.r48-rollback')} ${q(dir+'/index.html')}\n`).join('')
  +`cp -p ${q(backup+'/system-before.html')} ${q(base+'/public/system.html.r48-rollback')}\nmv -f ${q(base+'/public/system.html.r48-rollback')} ${q(base+'/public/system.html')}\n${site.restart}\n`
const canRollback=`php ${q(helperRemote)} ${q(instance)} rollback-guard ${q(commit)}`
// Never overwrite a concurrent publisher during error recovery. Every observed
// source and entry must still be ours or its exact captured predecessor.
const ownedChecks=Object.entries(live.files).map(([p,old])=>{
  const current=backend.includes('后端代码/'+p)?fingerprints['后端代码/'+p]:old
  const file=base+'/'+p,choices=[old,current].filter(Boolean)
  return `( ${choices.map(hash=>checked(file,hash)).concat(!old?[`test ! -e ${q(file)}`]:[]).join(' || ')} )`
})
ownedChecks.push(`( ${checked(base+'/public/system.html',live.platform.html)} || ${checked(base+'/public/system.html',manifest.platformAssets['system.html'])} )`)
frontDirs.forEach((dir,i)=>ownedChecks.push(`( ${checked(dir+'/index.html',Object.values(live.front)[i].html)} || ${checked(dir+'/index.html',manifest.cashierAssets['index.html'])} )`))
const ownedGuard=ownedChecks.join(' &&\n')
if(mode==='rollback'){remote(canRollback+'\n'+ownedGuard+'\n'+rollback+sourceGuard(false)+'\n'+frontGuard(false)+'\n');console.log('R48_ROLLBACK_PASS');process.exit(0)}
if(mode==='switch') {
  schema('verify')
  remote(staticModes([stage+'/cashier',stage+'/platform']))
  const writes=backend.map(p=>{const file=base+'/'+p.slice('后端代码/'.length);return `install -D -o www -g www -m 644 ${q(stage+'/'+p)} ${q(file+'.r48-new')}\nmv -f ${q(file+'.r48-new')} ${q(file)}\n`}).join('')
  const fronts=frontDirs.map(dir=>`cp -an ${q(stage+'/cashier/assets/.')} ${q(dir+'/assets/')}\n`
    +Object.entries(manifest.cashierAssets).filter(([p])=>p!=='index.html').map(([p,h])=>checked(dir+'/'+p,h)+'\n').join('')
    +webReadable(Object.keys(manifest.cashierAssets).filter(p=>p!=='index.html').map(p=>dir+'/'+p))
    +`install -o www -g www -m 644 ${q(stage+'/cashier/index.html')} ${q(dir+'/index.html.r48-new')}\nmv -f ${q(dir+'/index.html.r48-new')} ${q(dir+'/index.html')}\n`).join('')
  const platformSwitch=`cp -an ${q(stage+'/platform/view_admin/.')} ${q(base+'/public/view_admin/')}\n`
    +Object.entries(manifest.platformAssets).filter(([p])=>p!=='system.html').map(([p,h])=>checked(base+'/public/'+p,h)+'\n').join('')
    +webReadable(JSON.parse(readFileSync(evidence+'/platform-resource-diff.json')).newBusinessBytes.filter(x=>x.file!=='system.html').map(x=>base+'/public/'+x.file))
    +`install -o www -g www -m 644 ${q(stage+'/platform/system.html')} ${q(base+'/public/system.html.r48-new')}\nmv -f ${q(base+'/public/system.html.r48-new')} ${q(base+'/public/system.html')}\n`
  const trap=`rollback_r48() { set +e; if ${canRollback} && ${ownedGuard}; then ${rollback}\necho R48_ROLLBACK_ATTEMPTED >&2; else echo R48_NEW_FACTS_OR_CONCURRENT_CODE_RECOVERY_REQUIRES_RECONCILIATION >&2; fi; }\ntrap rollback_r48 ERR\n`
  remote(guard(false)+trap+writes+site.restart+'\n'+waitHealth+fronts+platformSwitch+guard(true)+'trap - ERR\necho R48_SWITCH_PASS\n')
  save('switched',{instance,commit,backup,result:'PASS'});process.exit(0)
}
remote(guard(true));const migration=schema('verify'),business=schema('probe')
const before=JSON.parse(readFileSync(evidence+'/'+instance+'-preflight.json')).business
if(JSON.stringify(before.checks)!==JSON.stringify(business.checks))throw Error('Historical business results changed; inspect before push/next site')
const publicChecks=[]
// Retry transport failures only; successful responses must still match bytes.
const curl=['-fsS','--retry','2','--retry-all-errors','--retry-delay','1','--connect-timeout','20','--max-time','180']
for(let i=0;i<frontDirs.length;i++) {
  const prefix=i?'/preview-8080':'',url='https://'+site.domain+prefix+'/view_cashier_v3/'
  const html=run('curl',[...curl,url+'?release=r48-'+commit.slice(0,8)])
  const normalized=prefix?html.replaceAll('/preview-8080/view_cashier_v3/','/view_cashier_v3/'):html
  if(sha(Buffer.from(normalized))!==manifest.cashierAssets['index.html'])throw Error('Public cashier entry mismatch')
  for(const[p,h]of Object.entries(manifest.cashierAssets).filter(([p])=>p!=='index.html'))if(sha(run('curl',[...curl,url+p],{encoding:null}))!==h)throw Error('Public cashier asset mismatch: '+p)
  publicChecks.push({url,result:'PASS',html:manifest.cashierAssets['index.html']})
}
const adminUrl='https://'+site.domain+'/system.html'
if(sha(run('curl',[...curl,adminUrl+'?release=r48-'+commit.slice(0,8)],{encoding:null}))!==manifest.platformAssets['system.html'])throw Error('Public platform entry mismatch')
// Main graph and all changed coupon chunks must be publicly fetchable, not just present on disk.
const differences=JSON.parse(readFileSync(evidence+'/platform-resource-diff.json')).newBusinessBytes.map(x=>x.file)
for(const p of differences.filter(p=>p!=='system.html'))if(sha(run('curl',[...curl,'https://'+site.domain+'/'+p],{encoding:null}))!==manifest.platformAssets[p])throw Error('Public coupon asset mismatch: '+p)
publicChecks.push({url:adminUrl,result:'PASS',html:manifest.platformAssets['system.html'],changedResources:differences.length-1})
save('verified',{instance,commit,backup,result:'PASS',migration,business,publicChecks,verifiedAt:new Date().toISOString(),limitations:'Read-only production verification; no real checkout, refund or void submitted.'})
