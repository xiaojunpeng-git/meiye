import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';

// R53 only: publish reviewed immutable trees; guard each live entry independently and retain its own rollback.
const [mode,instance]=process.argv.slice(2);
const out='/private/tmp/mohe-r53-release-20261015';
const phase='后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php';
const rel=phase.slice('后端代码/'.length);
const branch='refs/heads/release/r53-new-customer-source-20261015';
const targets={rh:{host:'rh-server',domain:'rh.cc3798.com',restart:'/etc/init.d/ruihao_swoole restart',health:"ss -lnt | grep -q ':20800 '"},'008':{host:'mohe-server',domain:'008.cc3798.com',restart:'systemctl restart mohe-008-swoole.service',health:'systemctl is-active --quiet mohe-008-swoole.service'}};
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const run=(cmd,args,input,options={})=>execFileSync(cmd,args,{input,encoding:'utf8',maxBuffer:64*1024*1024,...options});
const git=(args,input,env={})=>run('git',args,input,{env:{...process.env,...env}});
const load=n=>JSON.parse(fs.readFileSync(out+'/'+n+'.json'));
const save=(n,v)=>fs.writeFileSync(out+'/'+n+'.json',JSON.stringify(v,null,2)+'\n');
const m=load('manifest');
// Archive generated, non-sensitive release evidence only after both instance verifications exist.
if(mode==='archive') {
  const commits=load('commits');
  const record={round:53,date:'2026-10-15',status:'deployed and verified; push pending',commits,
    acceptance:'Product manager tested and approved R53; isolated live-baseline integration and RH formal/preview both approved.',
    scope:'Explanation text and comments only; no eligibility, amount or business data changes.',
    candidate:m,builds:Object.fromEntries(['formal','preview','formal_before','preview_before'].map(k=>[k,load(k+'-build')])),
    instances:Object.fromEntries(Object.keys(targets).map(k=>[k,{before:load(k+'-before'),immediate_before:load(k+'-immediate-before'),backup:load(k+'-backup'),switch:load(k+'-switch'),after:load(k+'-after'),verified:load(k+'-verified')}])),
    verification:{focused_tests:'17 PASS for both immutable candidates',php_lint:'PASS locally and on each instance before switching',builds:'4 Vite builds PASS; pre-existing chunk size warning',
      browser:'Authenticated RH preview reload and explanation modal verified, console warnings/errors empty.',
      limits:'Sample new-customer queries returned empty rows on both instances. No authenticated 008 browser test; 008 PHP report/export and public asset verification passed. Existing shared large-order static assertion remains unrelated to R53.',
      rollback:'Before rollback recheck current hashes to avoid overwriting later releases. Restore each instance backend.tar.gz and each frontend-N.tar.gz index; retain old hashed assets; restart only that instance. No DB rollback needed.'},
    source_boundary:'R53 business diff touches only the explanation method and modal introduction. Formal build preserves the already-live R3 combination-card patch; preview keeps its previous baseline. No other working-tree edits were included.',
    screenshot:'tests/cashier-v3/evidence/R53-rh-online-source-2026-10-15.png'};
  const evidence='tests/cashier-v3/evidence/R53-rh-008-deployment-2026-10-15.json';fs.writeFileSync(evidence,JSON.stringify(record,null,2)+'\n');
  const env={GIT_INDEX_FILE:out+'/archive.index'};const current=git(['rev-parse',branch]).trim();
  if(current!==commits.tip)throw Error('Release branch advanced unexpectedly');
  const sharedPath=git(['rev-parse','--git-path','index']).trim();const sharedHash=sha(fs.readFileSync(sharedPath));
  git(['read-tree',current],undefined,env);
  const files=[evidence,record.screenshot,'scripts/r53-deploy.mjs'];
  for(const file of files){const blob=git(['hash-object','-w',file]).trim();git(['update-index','--add','--cacheinfo',`100644,${blob},${file}`],undefined,env);}
  console.log(git(['diff','--cached','--stat',current],undefined,env));
  const tree=git(['write-tree'],undefined,env).trim();const tip=git(['commit-tree',tree,'-p',current,'-m','R53-00004 瑞昊双入口与渼约008部署核验归档']).trim();
  git(['update-ref',branch,tip,current]);
  if(sha(fs.readFileSync(sharedPath))!==sharedHash)throw Error('Shared index unexpectedly changed');
  save('archive',{tip,branch,files});console.log(JSON.stringify(load('archive'),null,2));process.exit(0);
}
if(mode==='commit') {
  for(const kind of ['formal','preview','formal_before','preview_before'])load(kind+'-build');
  const indexPath=git(['rev-parse','--git-path','index']).trim();
  if(sha(fs.readFileSync(indexPath))!==m.shared_index_sha256)throw Error('Shared index changed; inspect before committing');
  for(const f of m.files)if(sha(run('git',['show',m.trees.preview+':'+f.path],undefined,{encoding:null}))!==f.sha256)throw Error('Candidate changed');
  const preview=git(['commit-tree',m.trees.preview,'-p',m.base,'-m','R53-00001 新客报表首次办卡取值说明补充']).trim();
  const formal=git(['commit-tree',m.trees.formal,'-p',preview,'-m','R53-00002 保留正式入口已上线组合卡基线']).trim();
  const env={GIT_INDEX_FILE:out+'/deployment.index'};git(['read-tree',formal],undefined,env);
  const file='scripts/r53-deploy.mjs';const blob=git(['hash-object','-w',file]).trim();git(['update-index','--add','--cacheinfo',`100644,${blob},${file}`],undefined,env);
  const tree=git(['write-tree'],undefined,env).trim();
  const tip=git(['commit-tree',tree,'-p',formal,'-m','R53-00003 固定版本逐实例发布与回滚脚本']).trim();
  git(['update-ref',branch,tip,'0000000000000000000000000000000000000000']);
  if(sha(fs.readFileSync(indexPath))!==m.shared_index_sha256)throw Error('Shared index unexpectedly changed');
  save('commits',{preview,formal,tip,branch});console.log(JSON.stringify(load('commits'),null,2));process.exit(0);
}
const t=targets[instance];if(!t||!['deploy','verify'].includes(mode))throw Error('Explicit authorized target required');
const commits=load('commits');
const ssh=(command,input)=>run('ssh',['-o','ConnectTimeout=10',t.host,command],input);
const probe=()=>JSON.parse(ssh(`php -r 'eval("?>" . stream_get_contents(STDIN));' -- ${instance}`,fs.readFileSync('scripts/r53-release-probe.php')));
const before=load(instance+'-before');
const site='/www/wwwroot/'+t.domain;
const backup='/www/backups/'+t.domain+'/20261015-r53-'+commits.preview.slice(0,8);
const builds={formal:load('formal-build'),preview:load('preview-build')};
const kind=f=>f.path.includes('-8080/')?'preview':'formal';
const phaseExpected=m.files.find(f=>f.path===phase).sha256;
if(mode==='deploy') {
  const fresh=probe();
  if(fresh.files[rel]!==before.files[rel]||JSON.stringify(fresh.fronts)!==JSON.stringify(before.fronts))throw Error('Target baseline changed');
  save(instance+'-immediate-before',fresh);
  const payload=out+'/payload';
  if(!fs.existsSync(payload)) {
    fs.mkdirSync(payload);
    fs.writeFileSync(payload+'/phase.php',run('git',['show',commits.preview+':'+phase],undefined,{encoding:null}));
    for(const k of ['formal','preview']) {
      const actual=run('git',['rev-parse',commits[k]+'^{tree}']).trim();if(actual!==m.trees[k])throw Error('Commit tree mismatch');
      fs.cpSync(builds[k].dist,payload+'/'+k,{recursive:true});
    }
    fs.copyFileSync(out+'/manifest.json',payload+'/manifest.json');run('tar',['-czf',out+'/payload.tar.gz','-C',payload,'.']);
  }
  if(sha(fs.readFileSync(payload+'/phase.php'))!==phaseExpected)throw Error('Backend payload mismatch');
  for(const k of ['formal','preview']) {
    if(sha(fs.readFileSync(payload+'/'+k+'/index.html'))!==builds[k].index_sha256)throw Error('HTML payload mismatch');
    for(const [f,h] of Object.entries(builds[k].assets))if(sha(fs.readFileSync(payload+'/'+k+'/'+f))!==h)throw Error('Asset payload mismatch');
  }
  const prelude='set -euo pipefail\nhash() { sha256sum "$1" | cut -d\' \' -f1; }\n';
  const guard=[`test "$(hash '${site}/${rel}')" = '${before.files[rel]}'`,...before.fronts.flatMap(f=>[
    `test "$(hash '${f.path}/index.html')" = '${f.index_sha256}'`,...Object.entries(f.assets).map(([a,h])=>`test "$(hash '${f.path}/${a}')" = '${h}'`)
  ])].join('\n');
  const backupCmd=prelude+guard+`\n${t.health}\ntest ! -e '${backup}'\nmkdir -p '${backup}/stage'\ntar -czf '${backup}/backend.tar.gz' -C '${site}' '${rel}'\n`+
    before.fronts.map((f,i)=>`tar -czf '${backup}/frontend-${i}.tar.gz' -C '${f.path}' index.html ${Object.keys(f.assets).join(' ')}`).join('\n')+`\ngzip -t '${backup}'/*.tar.gz\nsha256sum '${backup}'/*.tar.gz\n`;
  save(instance+'-backup',{path:backup,result:ssh('bash -s',backupCmd)});
  run('scp',[out+'/payload.tar.gz',t.host+':'+backup+'/payload.tar.gz']);
  const stage=backup+'/stage';const payloadHash=sha(fs.readFileSync(out+'/payload.tar.gz'));
  const rollback=`rollback() {\nset +e\ntar -xzf '${backup}/backend.tar.gz' -C '${site}'\n${before.fronts.map((f,i)=>`tar -xzf '${backup}/frontend-${i}.tar.gz' -C '${f.path}' index.html`).join('\n')}\n${t.restart}\necho R53_ROLLBACK_ATTEMPTED >&2\n}\ntrap rollback ERR\n`;
  let switchCmd=prelude+`test "$(hash '${backup}/payload.tar.gz')" = '${payloadHash}'\ntar -xzf '${backup}/payload.tar.gz' -C '${stage}'\ntest "$(hash '${stage}/phase.php')" = '${phaseExpected}'\nphp -l '${stage}/phase.php'\n`;
  for(const f of before.fronts) {
    const k=kind(f);switchCmd+=`test "$(hash '${stage}/${k}/index.html')" = '${builds[k].index_sha256}'\n`;
    for(const [a,h] of Object.entries(builds[k].assets))switchCmd+=`test "$(hash '${stage}/${k}/${a}')" = '${h}'\n`;
  }
  switchCmd+=guard+'\n'+rollback;
  for(const f of before.fronts) {
    const k=kind(f);
    for(const [a,h] of Object.entries(builds[k].assets))switchCmd+=`if test -e '${f.path}/${a}'; then test "$(hash '${f.path}/${a}')" = '${h}'; else install -o www -g www -m 644 '${stage}/${k}/${a}' '${f.path}/${a}'; fi\n`;
  }
  switchCmd+=`install -o www -g www -m 644 '${stage}/phase.php' '${site}/${rel}.r53'\nmv -f '${site}/${rel}.r53' '${site}/${rel}'\n${t.restart}\n${t.health}\n`;
  for(const f of before.fronts){const k=kind(f);switchCmd+=`install -o www -g www -m 644 '${stage}/${k}/index.html' '${f.path}/index.html.r53'\nmv -f '${f.path}/index.html.r53' '${f.path}/index.html'\ntest "$(hash '${f.path}/index.html')" = '${builds[k].index_sha256}'\n`;}
  switchCmd+=`test "$(hash '${site}/${rel}')" = '${phaseExpected}'\ntrap - ERR\necho R53_SWITCH_OK\n`;
  save(instance+'-switch',{commit:commits.tip,backup,payload_sha256:payloadHash,result:ssh('bash -s',switchCmd)});
  console.log(instance+' R53_SWITCH_OK '+backup);
} else {
  const after=probe();save(instance+'-after',after);
  if(after.files[rel]!==phaseExpected)throw Error('Backend not published');
  if(JSON.stringify(after.migration)!==JSON.stringify(before.migration))throw Error('Migration changed');
  const pre=load(instance+'-immediate-before');
  const concurrent=[];
  for(const [f,h]of Object.entries(pre.files))if(f!==rel&&after.files[f]!==h)concurrent.push({path:f,before:h,after:after.files[f]});
  for(const [name,r]of Object.entries(after.reports)) {
    if(r.data_sha256!==before.reports[name].data_sha256)throw Error('Report data changed: '+name);
    if(!r.explanations.source.includes('先确定历史首次办卡，再排除来源')||!r.explanations.source.includes('14日'))throw Error('New source explanation missing');
  }
  const http=[];
  for(const f of after.fronts) {
    const k=kind(f);if(f.index_sha256!==builds[k].index_sha256||JSON.stringify(f.assets)!==JSON.stringify(builds[k].assets))throw Error('Disk assets mismatch');
    const url='https://'+t.domain+(k==='preview'?'/preview-8080':'')+'/view_cashier_v3/';
    const body=run('curl',['--fail','--silent','--show-error','--max-time','45',url]);
    const refs=[...body.matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)].map(x=>x[1]);
    if(refs.length!==2)throw Error('Unexpected public entry references');
    const assets=[];
    for(const ref of refs){const u=new URL(ref,url).href;const bytes=run('curl',['--fail','--silent','--show-error','--max-time','60',u],undefined,{encoding:null});const h=sha(bytes);if(h!==builds[k].assets['assets/'+path.basename(ref)])throw Error('Public hash mismatch');assets.push({url:u,sha256:h,bytes:bytes.length});}
    http.push({url,html_sha256:sha(body),assets});
  }
  save(instance+'-verified',{http,concurrent_other_files:concurrent,report_data_unchanged:true,explanations_verified:true,export_parity:true,migration_unchanged:true});
  console.log(JSON.stringify(load(instance+'-verified'),null,2));
}
