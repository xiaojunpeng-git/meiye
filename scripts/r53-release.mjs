import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';

// Fixed R53 tooling: Git-derived immutable candidates only; never switch the shared checkout/index.
const [mode, target] = process.argv.slice(2);
const root = process.cwd();
const out = '/private/tmp/mohe-r53-release-20261015';
const base = '712942eccd0c4d30b929fccd8777fd1b7ba70e06';
const branch = 'refs/heads/release/r53-new-customer-source-20261015';
const phase = '后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php';
const front = '前端代码/cashier-v3/src/views/StoreBusinessReportView.vue';
const comboFiles = ['前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue', '前端代码/cashier-v3/src/services/cashierV3CombinationChoicePreflight.js', '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'];
const targets = {rh: {host:'rh-server',domain:'rh.cc3798.com',restart:'/etc/init.d/ruihao_swoole restart',health:"ss -lnt | grep -q ':20800 '"},
  '008':{host:'mohe-server',domain:'008.cc3798.com',restart:'systemctl restart mohe-008-swoole.service',health:'systemctl is-active --quiet mohe-008-swoole.service'}};
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const run = (bin,args,input,options={}) => execFileSync(bin,args,{input,encoding:'utf8',maxBuffer:64*1024*1024,...options});
const git = (args,input,env={}) => run('git',args,input,{env:{...process.env,...env}});
const save = (name,value) => fs.writeFileSync(path.join(out,name),JSON.stringify(value,null,2)+'\n');
const load = name => JSON.parse(fs.readFileSync(path.join(out,name)));
const replace = (s,before,after) => {if(!before || s.split(before).length!==2)throw Error('Ambiguous R53 anchor: '+before.slice(0,80));return s.replace(before,after);};
const ssh = (instance,command,input) => {if(!targets[instance])throw Error('Wrong instance');return run('ssh',['-o','ConnectTimeout=10',targets[instance].host,command],input);};
const probe = instance => JSON.parse(ssh(instance,`php -r 'eval("?>" . stream_get_contents(STDIN));' -- ${instance}`,fs.readFileSync('scripts/r53-release-probe.php')));
fs.mkdirSync(out,{recursive:true});
if(mode==='probe') {
  const data=probe(target);save(target+'-before.json',data);
  console.log(JSON.stringify({instance:target,migration:data.migration,files:data.files,fronts:data.fronts,reports:Object.fromEntries(Object.entries(data.reports).map(([k,v])=>[k,{count:v.count,hash:v.data_sha256}]))},null,2));
} else if(mode==='prepare') {
  if(fs.existsSync(out+'/manifest.json'))throw Error('Candidate already prepared');
  const accepted=JSON.parse(fs.readFileSync('tests/cashier-v3/evidence/R53-new-customer-source-local-2026-10-15.json'));
  for(const f of [accepted.frontend,accepted.backend,accepted.tests,accepted.engineering_script])if(sha(fs.readFileSync(f.path))!==f.sha256)throw Error('Accepted file changed: '+f.path);
  const phaseBase=git(['show',base+':'+phase]);
  for(const instance of Object.keys(targets))if(load(instance+'-before.json').files[phase.slice('后端代码/'.length)]!==sha(phaseBase))throw Error('Backend baseline changed');
  let phaseNext=phaseBase;
  const work=fs.readFileSync(phase,'utf8');
  // Only the four explanation lines and their method comment are accepted. Never copy the entire shared method/file.
  phaseNext=replace(phaseNext,'    /** 两张新客表的列名说明直接描述顾客能核对的单据和时间，不使用内部统计术语。 */','    /** 两张新客表共用说明：明确先选历史首笔、再排除来源，不能误解为筛选期内首笔或首次全款。 */');
  const start=phaseBase.indexOf('    private function newCustomerColumnExplanations(');
  const workStart=work.indexOf('    private function newCustomerColumnExplanations(');
  for(const prefix of ["            'business_date'=>", "            'customer'=>", "            'source'=>", "                    ?$month.'月"]){
    const old=phaseBase.slice(start).split('\n').find(l=>l.startsWith(prefix));
    const next=work.slice(workStart).split('\n').find(l=>l.startsWith(prefix));phaseNext=replace(phaseNext,old,next);
  }
  let frontNext=git(['show',base+':'+front]);
  const fw=fs.readFileSync(front,'utf8');
  const oldLine=frontNext.split('\n').find(l=>l.includes("return '顾客首次办理疗程卡即计入新客"));
  const newLine=fw.split('\n').find(l=>l.includes("return '本表不是全部销售单。先查顾客历史首次有效疗程卡订单"));
  frontNext=replace(frontNext,oldLine,'    // 与后端首次办卡资格一致：先查历史首笔，再排除来源，不能按查询期或首次全款重新认定。\n'+newLine);
  const idx=out+'/preview.index';const env={GIT_INDEX_FILE:idx};git(['read-tree',base],undefined,env);
  const files=[];
  const extras=['tests/cashier-v3/php/r53-new-customer-source-explanation.php','scripts/r53-new-customer-readonly.php','scripts/r53-release-probe.php','scripts/r53-release.mjs','tests/cashier-v3/evidence/R53-new-customer-source-local-2026-10-15.json','tests/cashier-v3/evidence/R53-new-customer-source-local-2026-10-15.png'];
  for(const [file,body] of [[phase,phaseNext],[front,frontNext],...extras.map(f=>[f,fs.readFileSync(f)])]) {
    const blob=git(['hash-object','-w','--stdin'],body).trim();git(['update-index','--add','--cacheinfo',`100644,${blob},${file}`],undefined,env);
    files.push({path:file,blob,sha256:sha(body)});
  }
  const previewTree=git(['write-tree'],undefined,env).trim();
  const comboPatch=git(['diff','108c60d9^','108c60d9','--',...comboFiles]);
  const fi=out+'/formal.index';const fe={GIT_INDEX_FILE:fi};git(['read-tree',previewTree],undefined,fe);git(['apply','--cached','--check','-'],comboPatch,fe);git(['apply','--cached','-'],comboPatch,fe);
  const formalTree=git(['write-tree'],undefined,fe).trim();
  const bi=out+'/formal-before.index';const be={GIT_INDEX_FILE:bi};git(['read-tree',base],undefined,be);git(['apply','--cached','-'],comboPatch,be);const formalBefore=git(['write-tree'],undefined,be).trim();
  const trees={preview_before:base,formal_before:formalBefore,preview:previewTree,formal:formalTree};
  for(const [name,tree] of Object.entries(trees)) {
    const dir=out+'/'+name;fs.mkdirSync(dir);
    const archive=execFileSync('git',['archive',tree,'前端代码/cashier-v3','前端代码/inventory-vue3','前端代码/shared','tests/cashier-v3/php','后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php'],{maxBuffer:128*1024*1024});
    run('tar',['-xf','-','-C',dir],archive);
    for(const sub of ['cashier-v3','inventory-vue3','shared/unified-query-vue3']) {
      const dest=dir+'/前端代码/'+sub+'/node_modules';
      const dep=root+'/前端代码/'+sub+'/node_modules';
      if(fs.existsSync(dep))fs.symlinkSync(dep,dest,'dir');
    }
  }
  const manifest={base,branch,trees,files,combo_files:comboFiles,combo_patch_sha256:sha(comboPatch),accepted,development_head:git(['rev-parse','HEAD']).trim(),shared_index_sha256:sha(fs.readFileSync(git(['rev-parse','--git-path','index']).trim()))};
  save('manifest.json',manifest);console.log(JSON.stringify({trees,files},null,2));
} else if(mode==='build') {
  const m=load('manifest.json');if(!m.trees[target])throw Error('Unknown build');
  const cwd=out+'/'+target+'/前端代码/cashier-v3';
  const result=run('node',[root+'/前端代码/cashier-v3/node_modules/vite/bin/vite.js','build'],undefined,{cwd});
  fs.writeFileSync(out+'/'+target+'-build.log',result);
  const dist=cwd+'/dist';const html=fs.readFileSync(dist+'/index.html','utf8');
  const assets=Object.fromEntries([...html.matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)].map(x=>{const p='assets/'+path.basename(x[1]);return[p,sha(fs.readFileSync(dist+'/'+p))];}));
  const data={tree:m.trees[target],dist,index_sha256:sha(html),assets};save(target+'-build.json',data);
  if(target.endsWith('_before'))for(const instance of Object.keys(targets))for(const f of load(instance+'-before.json').fronts){
    if((target==='preview_before')!==f.path.includes('-8080/'))continue;
    if(data.index_sha256!==f.index_sha256 || JSON.stringify(data.assets)!==JSON.stringify(f.assets))throw Error('Rebuilt baseline does not match '+f.path);
  }
  console.log(JSON.stringify(data,null,2));
} else {throw Error('Unknown mode');}
