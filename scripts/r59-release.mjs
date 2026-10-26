import fs from 'node:fs'
import path from 'node:path'
import crypto from 'node:crypto'
import { execFileSync } from 'node:child_process'

// R59 uses a separate Git index, never the shared checkout/index. Only fixed
// accepted hunks are applied to Git blobs proven equal to live instance files.
const [mode, instance] = process.argv.slice(2)
const root = process.cwd(), out = '/private/tmp/mohe-r59-release-20261015'
const targets = { rh: { host: 'rh-server', domain: 'rh.cc3798.com' },
  '008': { host: 'mohe-server', domain: '008.cc3798.com' } }
const files = ['后端代码/app/services/report/StoreUnifiedReportPhaseFourServices.php',
  '后端代码/app/services/report/StoreMemberConsumptionRefundReadServices.php']
const patchFile = 'tests/report-phase-four/evidence/R59-category-refund-precise.patch'
const sha = b => crypto.createHash('sha256').update(b).digest('hex')
const run = (bin, args, options = {}) => execFileSync(bin, args, { cwd: root, encoding: 'utf8', maxBuffer: 128*1024*1024, ...options })
const git = (args, options = {}) => run('git', args, options)
const save = (n, x) => fs.writeFileSync(path.join(out, n+'.json'), JSON.stringify(x, null, 2)+'\n')
const load = n => JSON.parse(fs.readFileSync(path.join(out, n+'.json')))
fs.mkdirSync(out, { recursive: true })
const probe = (name, candidates) => {
  if (!targets[name]) throw Error('Unknown instance')
  const prefix = candidates ? '<?php $r59Candidates = json_decode(base64_decode("'+Buffer.from(JSON.stringify(candidates)).toString('base64')+'"),true); ?>' : ''
  return JSON.parse(run('ssh', ['-o','BatchMode=yes','-o','ConnectTimeout=10',targets[name].host,
    `php -r 'eval("?>" . stream_get_contents(STDIN));' -- ${name}`], {input:prefix+fs.readFileSync('scripts/r59-release-probe.php','utf8')}))
}
if (mode === 'probe') {
  if (fs.existsSync(out+'/'+instance+'-before.json')) throw Error('Preflight already frozen')
  const data = probe(instance); save(instance+'-before', data); console.log(JSON.stringify(data,null,2))
} else if (mode === 'prepare') {
  if (fs.existsSync(out+'/candidate.json')) throw Error('Candidate already frozen')
  const accepted = JSON.parse(fs.readFileSync('tests/report-phase-four/evidence/R59-category-refund-fixed-2026-10-15.json'))
  if (sha(fs.readFileSync(patchFile)) !== accepted.patchSha256) throw Error('Accepted patch changed')
  // The shared PhaseFour file may move independently. Its immutable accepted
  // patch must still reverse cleanly; never harvest newer workspace hunks.
  git(['apply','--reverse','--check',patchFile])
  for (const f of accepted.files.filter(f=>f.file!==files[0]))
    if (sha(fs.readFileSync(f.file)) !== f.sha256) throw Error('Accepted source changed: '+f.file)
  const before = load('rh-before'), other = load('008-before')
  if (JSON.stringify(before.files)!==JSON.stringify(other.files)) throw Error('Instance source mismatch')
  const head=git(['rev-parse','HEAD']).trim(), idx=out+'/candidate.index', env={...process.env,GIT_INDEX_FILE:idx}
  // The recorded R10 deployed tree contains already-published integrations not
  // reachable from its development commit; keep that verified release history.
  const deployedTree='a57a208bc8bd3ffe1c1a849f694618a380274458'
  git(['read-tree',deployedTree],{env})
  const baseline=[]
  for (const f of files) {
    const target=before.files[f.slice('后端代码/'.length)]
    const knownBlob=git(['rev-parse',deployedTree+':'+f]).trim()
    const objects=[knownBlob+' '+f,...git(['rev-list','--objects','--all','--',f]).trim().split('\n')]
    let found
    for(const line of objects) {
      const at=line.indexOf(' '); if(at<0 || line.slice(at+1)!==f) continue
      const blob=line.slice(0,at),body=git(['cat-file','blob',blob],{encoding:null})
      if(sha(body)===target){found={file:f,blob,sha256:target};break}
    }
    if(!found) {
      // R10's immutable local deployment artifact includes its already-released
      // integrations. Preserve it as a baseline object only after matching both
      // its recorded manifest and both live hashes; never import server source.
      const pkg='/private/tmp/mohe-r10-release-20261015/package',rel=f.slice('后端代码/'.length)
      const manifest=JSON.parse(fs.readFileSync(pkg+'/manifest.json'))
      if(manifest.backend[rel]!==target) throw Error('Unverified release baseline: '+f)
      const body=fs.readFileSync(pkg+'/backend/'+rel)
      if(sha(body)!==target) throw Error('Release artifact changed')
      found={file:f,blob:git(['hash-object','-w','--stdin'],{input:body}).trim(),sha256:target,
        provenance:'R10 immutable local release package, commit '+manifest.commit}
    }
    git(['update-index','--add','--cacheinfo',`100644,${found.blob},${f}`],{env});baseline.push(found)
  }
  const baseTree=git(['write-tree'],{env}).trim()
  git(['apply','--cached','--check',patchFile],{env})
  git(['apply','--cached',patchFile],{env})
  const tree=git(['write-tree'],{env}).trim()
  const source=Object.fromEntries(files.map(f=>[f,sha(git(['show',tree+':'+f],{encoding:null}))]))
  save('candidate',{head,deployedTree,baseTree,tree,baseline,source,patchSha256:accepted.patchSha256,
    sharedIndexSha256:sha(fs.readFileSync(git(['rev-parse','--git-path','index']).trim()))})
  fs.writeFileSync(out+'/candidate.diff',git(['diff',baseTree,tree,'--',...files]))
  console.log(JSON.stringify(load('candidate'),null,2))
} else if (mode === 'test') {
  const c=load('candidate')
  const candidates=files.slice().reverse().map(f=>Buffer.from(git(['show',c.tree+':'+f],{encoding:null})).toString('base64'))
  const data=probe(instance,candidates),before=load(instance+'-before')
  if(JSON.stringify(data.files)!==JSON.stringify(before.files)||JSON.stringify(data.dependencies)!==JSON.stringify(before.dependencies)) throw Error('Live changed during candidate test')
  for(const key of ['all_current_amount','all_actual_amount','all_previous_amount'])
    if(data.report.october[key]!==before.report.october[key]) throw Error('Cash/actual changed: '+key)
  if(instance==='rh' && (data.report.october.haomei_current_amount!=='-20000'
    || !data.report.detail.some(r=>r.order_no==='TK2610110001' && r.receipt_amount==='-20000'))) throw Error('Refund fixture missing')
  if(data.report.version!=='operations-comparison-category-refund-r59') throw Error('Wrong candidate version')
  save(instance+'-candidate-tested',data);console.log(JSON.stringify(data.report,null,2))
} else if (mode === 'local-test') {
  const c=load('candidate')
  const bodies=files.slice().reverse().map(f=>Buffer.from(git(['show',c.tree+':'+f],{encoding:null})).toString('base64'))
  const test=fs.readFileSync('tests/report-phase-four/php/r59-comparison-refund-local.php').toString('base64')
  const php='<?php require "/var/www/html/vendor/autoload.php"; '+bodies.map(b=>`eval(substr(base64_decode('${b}'),5));`).join('')+`eval(substr(base64_decode('${test}'),5));`
  const result=run('docker',['exec','-i','-e','R59_ROLLBACK=1','mohe-platform-app','php'],{input:php})
  const data=JSON.parse(result); if(data.result!=='PASS') throw Error('Local test failed')
  save('local-candidate-tested',data);console.log(result)
} else if (mode === 'commit') {
  const c=load('candidate')
  for(const n of ['rh-candidate-tested','008-candidate-tested','local-candidate-tested']) load(n)
  if(fs.existsSync(out+'/package.json')) throw Error('Already committed')
  const index=git(['rev-parse','--git-path','index']).trim(), indexBefore=sha(fs.readFileSync(index))
  const env={...process.env,GIT_INDEX_FILE:out+'/commit.index'}
  // R10 published its evidence after building. Preserve that later evidence in
  // both baseline and candidate, rather than deleting it with the build tree.
  const parent='3231956f'
  const priorEvidence='tests/report-phase-four/evidence/R10-operations-comparison-rh-008-release-2026-10-15.md'
  const priorBlob=git(['rev-parse',parent+':'+priorEvidence]).trim()
  git(['read-tree',c.baseTree],{env})
  git(['update-index','--add','--cacheinfo',`100644,${priorBlob},${priorEvidence}`],{env})
  const baselineTree=git(['write-tree'],{env}).trim()
  git(['read-tree',c.tree],{env})
  git(['update-index','--add','--cacheinfo',`100644,${priorBlob},${priorEvidence}`],{env})
  const extras=['scripts/r59-release.mjs','scripts/r59-release-probe.php',
    'tests/report-phase-four/php/r59-comparison-refund-local.php',patchFile,
    'tests/report-phase-four/evidence/R59-category-refund-fixed-2026-10-15.json',
    'tests/report-phase-four/evidence/R59-category-refund-selftest-2026-10-15.json',
    'tests/report-phase-four/evidence/R59-refund-drill-local-2026-10-15.jpg',
    'tests/report-phase-four/evidence/R59-release-scope-2026-10-15.md']
  for(const file of extras) {
    const blob=git(['hash-object','-w','--stdin'],{input:fs.readFileSync(file)}).trim()
    git(['update-index','--add','--cacheinfo',`100644,${blob},${file}`],{env})
  }
  const tree=git(['write-tree'],{env}).trim()
  // The fixed patch artifact intentionally retains blank context lines with a
  // leading space; lint source/evidence without rewriting that signed artifact.
  git(['diff','--check',baselineTree,tree,'--','.',':(exclude)'+patchFile])
  const changed=git(['-c','core.quotePath=false','diff','--name-only',baselineTree,tree]).trim().split('\n')
  if(changed.some(f=>![...files,...extras].includes(f))) throw Error('Out-of-scope commit')
  const baseline=git(['commit-tree',baselineTree,'-p',parent,'-m','R59-00000 核实双站已发布后端基线快照']).trim()
  const commit=git(['commit-tree',tree,'-p',baseline,'-m','R59-00001 业绩对比独立退款分类与下钻闭环']).trim()
  const branch='refs/heads/release/r59-comparison-refund-20261015'
  git(['update-ref',branch,commit,'0000000000000000000000000000000000000000'])
  if(sha(fs.readFileSync(index))!==indexBefore || git(['rev-parse','HEAD']).trim()!==c.head) throw Error('Shared checkout changed during commit')
  const dir=out+'/payload';fs.mkdirSync(dir)
  const backend={}
  for(const f of files) {
    const rel=f.slice('后端代码/'.length),body=git(['show',commit+':'+f],{encoding:null})
    const dest=dir+'/'+rel;fs.mkdirSync(path.dirname(dest),{recursive:true});fs.writeFileSync(dest,body)
    run('php',['-l',dest]);backend[rel]=sha(body)
  }
  run('tar',['-czf',out+'/payload.tar.gz','-C',dir,...Object.keys(backend)])
  save('package',{commit,baseline,tree,branch,backend,files:changed,payloadSha256:sha(fs.readFileSync(out+'/payload.tar.gz')),
    sharedHead:c.head,sharedIndexSha256:indexBefore})
  console.log(JSON.stringify(load('package'),null,2))
} else if (['deploy','verify'].includes(mode)) {
  if(!targets[instance]) throw Error('Unknown instance')
  const t=targets[instance],p=load('package'),b=load(instance+'-before'),site='/www/wwwroot/'+t.domain
  if(sha(fs.readFileSync(out+'/payload.tar.gz'))!==p.payloadSha256) throw Error('Payload changed')
  const ssh=script=>run('ssh',['-o','BatchMode=yes','-o','ConnectTimeout=10',t.host,'bash -s'],{input:script})
  const restart=instance==='rh'?'/etc/init.d/ruihao_swoole restart':'systemctl restart mohe-008-swoole.service'
  const health=instance==='rh'?"ss -lnt | grep -q ':20800 '":'systemctl is-active --quiet mohe-008-swoole.service'
  const prefix='set -euo pipefail\nexport PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH\n'
  const backup=`/www/backups/${t.domain}/20261015-r59-${p.commit.slice(0,8)}`
  if(mode==='deploy') {
    if(instance==='008' && !fs.existsSync(out+'/push.json')) throw Error('RH verification and push required first')
    const current=probe(instance)
    for(const key of ['files','dependencies','migration']) if(JSON.stringify(current[key])!==JSON.stringify(b[key])) throw Error('Concurrent live change: '+key)
    save(instance+'-switch-before',current)
    const guard=Object.entries(b.files).map(([f,h])=>`test "$(sha256sum '${site}/${f}' | cut -d' ' -f1)" = '${h}'`).join('\n')
    const backupResult=ssh(prefix+`${health}\n${guard}\ntest ! -e '${backup}'\nmkdir -p '${backup}/stage'\ntar -czf '${backup}/backend-before.tar.gz' -C '${site}' ${Object.keys(p.backend).map(f=>`'${f}'`).join(' ')}\ngzip -t '${backup}/backend-before.tar.gz'\nsha256sum '${backup}/backend-before.tar.gz'\n`)
    save(instance+'-backup',{backup,result:backupResult})
    run('scp',['-o','BatchMode=yes',out+'/payload.tar.gz',`${t.host}:${backup}/payload.tar.gz`])
    let script=prefix+`test "$(sha256sum '${backup}/payload.tar.gz' | cut -d' ' -f1)" = '${p.payloadSha256}'\ntar -xzf '${backup}/payload.tar.gz' -C '${backup}/stage'\n${guard}\n`
    for(const [f,h] of Object.entries(p.backend)) script+=`test "$(sha256sum '${backup}/stage/${f}' | cut -d' ' -f1)" = '${h}'\nphp -l '${backup}/stage/${f}'\n`
    script+=`rollback() { set +e; tar -xzf '${backup}/backend-before.tar.gz' -C '${site}'; ${restart}; echo R59_ROLLBACK_ATTEMPTED >&2; }\ntrap rollback ERR\n`
    for(const f of Object.keys(p.backend)) script+=`install -o www -g www -m 644 '${backup}/stage/${f}' '${site}/${f}.r59'\nmv -f '${site}/${f}.r59' '${site}/${f}'\n`
    script+=`${restart}\n${health}\ntrap - ERR\necho R59_SWITCH_OK\n`
    const result=ssh(script);save(instance+'-switch',{commit:p.commit,backup,result});console.log(result)
  } else {
    const after=probe(instance),sw=load(instance+'-switch-before'),tested=load(instance+'-candidate-tested')
    if(JSON.stringify(after.files)!==JSON.stringify(p.backend)) throw Error('Deployed bytes mismatch')
    for(const key of ['dependencies','migration','fronts']) if(JSON.stringify(after[key])!==JSON.stringify(sw[key])) throw Error('Unexpected change: '+key)
    for(const key of ['october','summary','detail','detail_summary','version']) if(JSON.stringify(after.report[key])!==JSON.stringify(tested.report[key])) throw Error('Live report mismatch: '+key)
    const process=ssh(prefix+`${health}\nps -eo args | grep '[s]woole: manager process'\n`)
    const publicEntries=[]
    for(let i=0;i<after.fronts.length;i++) {
      const front=after.fronts[i],url=`https://${t.domain}${i?'/preview-8080':''}/view_cashier_v3/`
      const html=run('curl',['--fail','--silent','--show-error','--max-time','45',url])
      const assets={}
      for(const m of html.matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)) {
        assets['assets/'+path.basename(m[1])]=sha(run('curl',['--fail','--silent','--show-error','--max-time','60',new URL(m[1],url).href],{encoding:null}))
      }
      if(JSON.stringify(assets)!==JSON.stringify(front.assets)) throw Error('Public resource mismatch')
      publicEntries.push({url,htmlSha256:sha(html),assets,frontendChanged:false})
    }
    const data={commit:p.commit,instance,backup,after,publicEntries,process,result:'PASS'}
    save(instance+'-verified',data);console.log(JSON.stringify(data,null,2))
  }
} else if (mode==='push') {
  const p=load('package');if(load('rh-verified').result!=='PASS') throw Error('Verify RH before push')
  const result=git(['push','origin',p.branch+':'+p.branch])
  const remote=git(['ls-remote','origin',p.branch]).trim().split(/\s/)[0]
  if(remote!==p.commit) throw Error('Remote commit mismatch')
  save('push',{commit:p.commit,branch:p.branch,remote,result});console.log(JSON.stringify(load('push')))
} else { throw Error('Unknown mode') }
