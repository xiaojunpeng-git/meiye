import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';

// This release tool only accepts the reviewed R52 commit and two authorized sites.
// It stages immutable artifacts, checks live fingerprints twice, keeps old assets,
// and restores this instance's own files on a failed switch. No SQL writes occur.
const [mode, instance] = process.argv.slice(2);
const commit='56c8918955d789bc278af674219056b042048ea9';
const release='/private/tmp/mohe-r52-fixed-20261014-b';
const dist='/private/tmp/mohe-r52-front-candidate-b/前端代码/cashier-v3/dist';
const targets={rh:{host:'rh-server',domain:'rh.cc3798.com',preview:'/www/wwwroot/rh.cc3798.com-8080',restart:'/etc/init.d/ruihao_swoole restart',health:"ss -lnt | grep -q ':20800 '"},
  '008':{host:'mohe-server',domain:'008.cc3798.com',restart:'systemctl restart mohe-008-swoole.service',health:'systemctl is-active --quiet mohe-008-swoole.service'}};
const t=targets[instance];
if(!t || !['deploy','probe','http'].includes(mode))throw Error('Explicit mode and authorized instance required');
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const run=(bin,args,input)=>execFileSync(bin,args,{input,encoding:'utf8',maxBuffer:32*1024*1024});
const ssh=(command,input)=>run('ssh',['-o','ConnectTimeout=10',t.host,command],input);
const site='/www/wwwroot/'+t.domain;
const backup='/www/backups/'+t.domain+'/20261014-r52-56c89189';
const manifest=JSON.parse(fs.readFileSync(release+'/manifest.json'));
const sourceFiles=manifest.files.filter(f=>f.path.startsWith('后端代码/'));
const before={
  'app/services/report/StoreUnifiedReportPhaseTwoServices.php':'be853bfb2276d3efec401aa4655fb4980e53c28e0b9f66bd5155de9656a0f628',
  'app/services/report/StoreUnifiedReportServices.php':'983890c84e9c2c8076f39f8b590c08ed15bacab0bfa428ac526a0a8073b552aa',
  'app/controller/cashier/v3/Report.php':'11bd5461f41719f947643a4dfe862863502a65525a03dc3beeb8259126bdb57c'};
const oldIndex='fdd9d618acb27cfe6fe69ff33ec2a7ab81bde205babb97a2eaafc91e3b9218d0';
const fronts=[site+'/public/view_cashier_v3',...(t.preview?[t.preview+'/public/view_cashier_v3']:[])];
const probe=phase=>JSON.parse(ssh(`php -r 'eval("?>" . stream_get_contents(STDIN));' -- ${instance} ${phase}`,fs.readFileSync('scripts/r52-release-readonly.php')));
const save=(name,data)=>fs.writeFileSync(`${release}/${instance}-${name}.json`,JSON.stringify(data,null,2)+'\n');
if(mode==='probe'){const result=probe('after');save('after',result);console.log(JSON.stringify(result,null,2));process.exit(0);}
if(mode==='http'){
  const outputs=[];
  for(const prefix of ['',...(t.preview?['/preview-8080']:[])]){
    const url=`https://${t.domain}${prefix}/view_cashier_v3/`;
    const body=run('curl',['--fail','--silent','--show-error','--max-time','45',url+'?r52=56c89189']);
    const references=[...body.matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)].map(m=>m[1]);
    if(references.length!==2)throw Error('Unexpected public asset references');
    const assets=[];
    for(const ref of references){
      const assetUrl=new URL(ref,url).href;
      const content=execFileSync('curl',['--fail','--silent','--show-error','--max-time','60',assetUrl],{maxBuffer:32*1024*1024});
      const expected=fs.readFileSync(path.join(dist,'assets',path.basename(ref)));
      if(sha(content)!==sha(expected))throw Error(`Public hash mismatch ${assetUrl}`);
      assets.push({url:assetUrl,sha256:sha(content),bytes:content.length});
    }
    outputs.push({url,html_sha256:sha(body),assets});
  }
  save('http',outputs);console.log(JSON.stringify(outputs,null,2));process.exit(0);
}
// Verify the deployed bytes originate at the fixed commit, not a moving worktree.
for(const file of manifest.files){
  const blob=execFileSync('git',['show',commit+':'+file.path],{maxBuffer:32*1024*1024});
  if(sha(blob)!==file.sha256)throw Error('Commit/source fingerprint mismatch '+file.path);
}
const preflight=probe('before');
for(const [file,hash] of Object.entries(before))if(preflight.files[file]!==hash)throw Error('Live backend changed '+file);
if(preflight.files['app/services/report/StoreLargeOrderInstallmentServices.php']!==null || preflight.files['public/view_cashier_v3/index.html']!==oldIndex)throw Error('Live baseline changed');
save('before',preflight);
const payload=release+'/payload';
if(!fs.existsSync(payload)){
  fs.mkdirSync(payload);
  for(const file of sourceFiles){const out=path.join(payload,file.path.slice('后端代码/'.length));fs.mkdirSync(path.dirname(out),{recursive:true});fs.copyFileSync(path.join(release,'candidate',file.path),out);}
  fs.cpSync(dist,payload+'/frontend',{recursive:true});
  fs.copyFileSync(release+'/manifest.json',payload+'/manifest.json');
  run('tar',['-czf',release+'/payload.tar.gz','-C',payload,'.']);
}
const guard=()=>[
  ...Object.entries(before).map(([file,hash])=>`test "$(hash '${site}/${file}')" = '${hash}'`),
  `test ! -e '${site}/app/services/report/StoreLargeOrderInstallmentServices.php'`,
  ...fronts.map(front=>`test "$(hash '${front}/index.html')" = '${oldIndex}'`)
].join('\n');
const prelude="set -euo pipefail\nhash() { sha256sum \"$1\" | cut -d' ' -f1; }\n";
const backups=fronts.map((front,i)=>`tar -czf '${backup}/frontend-${i}.tar.gz' -C '${front}' index.html assets/index-b76e0a3f.js assets/index-8d9ec27e.css`).join('\n');
const backupScript=prelude+guard()+`\n${t.health}\ntest ! -e '${backup}'\nmkdir -p '${backup}/stage'\ntar -czf '${backup}/backend.tar.gz' -C '${site}' ${Object.keys(before).join(' ')}\n${backups}\ngzip -t '${backup}'/*.tar.gz\nsha256sum '${backup}'/*.tar.gz\n`;
const backupResult=ssh('bash -s',backupScript);save('backup',{path:backup,output:backupResult});
run('scp',['-o','ConnectTimeout=10',release+'/payload.tar.gz',`${t.host}:${backup}/payload.tar.gz`]);
const payloadHash=sha(fs.readFileSync(release+'/payload.tar.gz'));
const stage=backup+'/stage';
const newHelperHash=sourceFiles.find(f=>f.path.endsWith('/StoreLargeOrderInstallmentServices.php')).sha256;
const restoreFronts=fronts.map((front,i)=>`tar -xzf '${backup}/frontend-${i}.tar.gz' -C '${front}' index.html`).join('\n');
const rollback=`rollback() {
  set +e
  tar -xzf '${backup}/backend.tar.gz' -C '${site}'
  if test "$(hash '${site}/app/services/report/StoreLargeOrderInstallmentServices.php')" = '${newHelperHash}'; then rm '${site}/app/services/report/StoreLargeOrderInstallmentServices.php'; fi
  ${restoreFronts}
  ${t.restart}
  echo 'R52 switch failed; instance-specific file rollback attempted' >&2
}
trap rollback ERR
`;
const verifyStage=sourceFiles.map(f=>{const rel=f.path.slice('后端代码/'.length);return `test "$(hash '${stage}/${rel}')" = '${f.sha256}'\nphp -l '${stage}/${rel}'`;}).join('\n');
const installBackend=sourceFiles.map(f=>{const rel=f.path.slice('后端代码/'.length);return `install -o www -g www -m 644 '${stage}/${rel}' '${site}/${rel}.r52'\nmv -f '${site}/${rel}.r52' '${site}/${rel}'`;}).join('\n');
const copyAssets=fronts.map(front=>`cp -n '${stage}/frontend/assets/'* '${front}/assets/'\nchown www:www '${front}/assets/index-2b144766.js' '${front}/assets/index-dc6742f8.css'`).join('\n');
const indexHash=sha(fs.readFileSync(dist+'/index.html'));
const installIndex=fronts.map(front=>`install -o www -g www -m 644 '${stage}/frontend/index.html' '${front}/index.html.r52'\nmv -f '${front}/index.html.r52' '${front}/index.html'\ntest "$(hash '${front}/index.html')" = '${indexHash}'`).join('\n');
const switchScript=prelude+`test "$(hash '${backup}/payload.tar.gz')" = '${payloadHash}'\ntar -xzf '${backup}/payload.tar.gz' -C '${stage}'\n`+verifyStage+'\n'+guard()+'\n'+rollback+copyAssets+'\n'+installBackend+`\n${t.restart}\n${t.health}\n`+installIndex+'\ntrap - ERR\necho R52_SWITCH_OK\n';
const result=ssh('bash -s',switchScript);
save('switch',{commit,backup,result,index_sha256:indexHash,payload_sha256:payloadHash,files:sourceFiles});
console.log(result);
