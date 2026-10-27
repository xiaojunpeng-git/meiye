// Explicit round4 release: preserve shared checkout/index, immutable Git source,
// per-instance fingerprint guards/backups, and RH verification before any push.
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
const root=path.resolve(__dirname,'..');process.chdir(root);
const dir='tests/combination-group-list/release',hash=b=>crypto.createHash('sha256').update(b).digest('hex');
const run=(b,a,o={})=>cp.execFileSync(b,a,{maxBuffer:256e6,timeout:240000,...o});
const git=(a,input)=>run('git',a,{input}),load=n=>JSON.parse(fs.readFileSync(dir+'/'+n+'.json'));
const save=(n,v)=>fs.writeFileSync(dir+'/'+n+'.json',JSON.stringify(v,null,2)+'\n',{flag:'wx'});
const sites={rh:{host:'rh-server',domain:'rh.cc3798.com'},'008':{host:'mohe-server',domain:'008.cc3798.com'},'010':{host:'mohe-server',domain:'010.cc3798.com'}};
function ssh(site,command,input){if(!sites[site])throw Error('Explicit site required');return run('ssh',['-q','-o','BatchMode=yes','-o','ConnectTimeout=15',sites[site].host,command],{input});}
function probe(site,mode='snapshot'){return JSON.parse(ssh(site,'/www/server/php/74/bin/php -- '+site+' '+mode,fs.readFileSync('scripts/combination-group-release-probe.php')));}
function guard(){const p=load('trees');if(git(['rev-parse','HEAD']).toString().trim()!==p.head||hash(fs.readFileSync('.git/index'))!==p.sharedIndexBefore)throw Error('Shared HEAD/index changed');
 const fixed=JSON.parse(fs.readFileSync('tests/combination-group-list/evidence/fixed-version.json'));
 const files=fixed.files||fixed.fileSHA256||fixed.fileDigests; if(!files)throw Error('Accepted source manifest missing');
 for(const[f,h]of Object.entries(files))if(hash(fs.readFileSync(f))!==h)throw Error('Accepted file drift '+f);
 const build=load('candidate-admin-build');if(build.tree!==(p.buildInputTree||p.candidateTree)||build.result!=='PASS'||!build.sourceStable)throw Error('Build gate');
 // Archival migration/evidence additions cannot change the already tested executable source.
 const delta=git(['diff','--name-only',build.tree,p.candidateTree]).toString().trim().split('\n').filter(Boolean);
 if(delta.some(f=>f.startsWith('前端代码/')||(f.startsWith('后端代码/')&&!f.startsWith('后端代码/database/upgrades/'))))throw Error('Build source changed');
 if(!fs.readFileSync(dir+'/fixed-backend.txt','utf8').includes('COMBINATION_GROUP_LIST_BACKEND_PASS')||!fs.readFileSync(dir+'/fixed-r61.txt','utf8').includes('R61 ACCEPTANCE COMPLETE; all fabricated rows rolled back; no real provider requests.'))throw Error('Fixed backend regression gate');
 for(const[f,h]of Object.entries(build.assets))if(hash(fs.readFileSync(path.join(build.output,f)))!==h)throw Error('Build output drift '+f);
 return p;}
function fresh(site){const now=probe(site),before=load(site+'-before');for(const key of ['backend','entries','admin_assets','environment','migration'])if(JSON.stringify(now[key])!==JSON.stringify(before[key]))throw Error('Live baseline drift '+site+' '+key);return now;}
function commit(){const p=guard();for(const site of ['rh','008','010'])fresh(site);
 const branch='release/008-round4-group-20261027',ref='refs/heads/'+branch;
 if(git(['for-each-ref','--format=%(refname)',ref]).toString().trim())throw Error('Release branch exists');
 const pre=git(['commit-tree',p.prerequisiteTree,'-p',p.head],Buffer.from('008-R4-PRE 保留已验收上线的拼团履约及退款依赖\n\n从正本历史验收提交恢复18个文件，与三站现网逐文件指纹一致；源码基线修复，不重复计入本轮新增，不覆盖其他任务工作区。\n')).toString().trim();
 const c=git(['commit-tree',p.candidateTree,'-p',pre],Buffer.from('008-R4 拼团列表字段筛选导出及成团后整团退款\n\n产品经理已测试验收并授权commit、瑞昊验证后push及008/010部署。11列、精简筛选、导出同口径；任一成员不符合则整团零发起。保留现网三条工资路由。无数据库迁移、无真实退款、无小程序上传。\n')).toString().trim();
 git(['update-ref',ref,c,'0'.repeat(40)]);save('commit',{commit:c,prerequisiteCommit:pre,branch,tree:p.candidateTree,parent:p.head,sharedIndexUntouched:true});console.log('COMMIT',c,pre);}
function restart(site){fs.writeFileSync(dir+'/'+site+'-restart.log',ssh(site,site==='rh'?'/etc/init.d/ruihao_swoole restart':'systemctl restart mohe-'+site+'-swoole.service'));ssh(site,site==='rh'?"ss -lnt | grep -q ':20800 '":'systemctl is-active --quiet mohe-'+site+'-swoole.service');}
function publicCheck(site){const base='https://'+sites[site].domain,b=load('candidate-admin-build');
 const html=run('curl',['-fsS','--max-time','40',base+'/admin/login']).toString();
 const refs=[...html.matchAll(/(?:src|href)=["']?([^"'\s>]+\.(?:js|css))/g)].map(x=>x[1]).filter(x=>x.startsWith('/view_admin/'));
 if(refs.length<4)throw Error('Platform assets missing');for(const ref of refs)if(hash(run('curl',['-fsS','--max-time','40',base+ref]))!==b.assets[ref.slice(1)])throw Error('Public asset mismatch '+ref);
 if(site==='rh')for(const ref of ['/preview-8080/view_cashier_v3/','/store-v3/'])if(run('curl',['-sS','-o','/dev/null','-w','%{http_code}','--max-time','20',base+ref]).toString()!=='410')throw Error('Retired route revived');
 // Read-only access check: never invoke the refund mutation during deployment.
 const auth=run('curl',['-sS','--max-time','30',base+'/adminapi/marketing/combination/group_list']).toString();
 return {result:'PASS',refs,unauthenticatedResponse:auth.slice(0,350),financialCommands:0};}
function deploy(site){const p=guard(),record=load('commit');if(site==='008'&&load('push').result!=='PASS')throw Error('Push first');if(site==='010'&&load('008-verified').result!=='PASS')throw Error('008 first');
 const before=fresh(site),b=load('candidate-admin-build'),payload='/private/tmp/combination-group-payload-'+site;
 fs.mkdirSync(payload,{recursive:false});const targets={},backendAllowlist=[];
 function add(rel,bytes,old){const after=hash(bytes);if(old===after)return;const key='formal/'+rel;fs.mkdirSync(path.dirname(payload+'/'+key),{recursive:true});fs.writeFileSync(payload+'/'+key,bytes);targets[key]={before:old??null,after};}
 for(const rel of ['app/services/activity/combination/CombinationGroupListServices.php','app/services/activity/combination/CombinationRefundServices.php','app/controller/admin/v1/marketing/combination/StoreCombination.php','route/admin.php']){backendAllowlist.push(rel);add(rel,git(['show',record.commit+':后端代码/'+rel]),before.backend[rel]);}
 for(const[rel,h]of Object.entries(b.assets)){if(rel!=='system.html'&&!rel.startsWith('view_admin/'))continue;add('public/'+rel,fs.readFileSync(b.output+'/'+rel),rel==='system.html'?before.entries['public/system.html']:before.admin_assets[rel]);}
 // Entry is switched last, after all new content-addressed resources exist.
 const entry=targets['formal/public/system.html'];delete targets['formal/public/system.html'];if(entry)targets['formal/public/system.html']=entry;
 const manifest={site,commit:record.commit,previewAuthorized:false,backendAllowlist,targets};fs.writeFileSync(payload+'/manifest.json',JSON.stringify(manifest));const mh=hash(fs.readFileSync(payload+'/manifest.json'));
 const directory='/www/backups/'+site+'/combination-group-'+record.commit.slice(0,8);ssh(site,'mkdir -p '+directory+'/payload && chmod 700 '+directory);
 const tar=run('tar',['-czf','-','-C',payload,'.']);ssh(site,'tar -xzf - -C '+directory+'/payload',tar);
 const installer=fs.readFileSync('scripts/combination-group-release-install.php'),invoke=mode=>ssh(site,'/www/server/php/74/bin/php -- '+directory+' '+mode+' '+mh,installer);let started=false;
 try{fs.writeFileSync(dir+'/'+site+'-backup.log',invoke('backup'));started=true;fs.writeFileSync(dir+'/'+site+'-install.log',invoke('install'));restart(site);fs.writeFileSync(dir+'/'+site+'-verify.log',invoke('verify'));
  const after=probe(site);for(const[k,row]of Object.entries(targets)){const rel=k.slice(7),actual=rel==='public/system.html'?after.entries[rel]:rel.startsWith('public/view_admin/')?after.admin_assets[rel.slice(7)]:after.backend[rel];if(actual!==row.after)throw Error('After fingerprint '+rel);}
  for(const rel of ['public/index.html','public/view_cashier_v3/index.html'])if(after.entries[rel]!==before.entries[rel])throw Error('Unrelated entry changed');if(after.environment!==before.environment)throw Error('Configuration changed');
  const runtime=probe(site,'runtime');if(runtime.result!=='PASS')throw Error('Runtime');save(site+'-runtime',runtime);
  const retained=JSON.parse(ssh(site,'/www/server/php/74/bin/php -- '+site,fs.readFileSync('scripts/r61-combination-runtime.php')));if(retained.result!=='PASS')throw Error('Existing R61 regression');save(site+'-retained',retained);
  save(site+'-public',publicCheck(site));save(site+'-after',after);save(site+'-verified',{result:'PASS',site,commit:record.commit,backup:directory,manifestSHA256:mh,files:targets,businessWrites:0,realRefund:false});console.log('VERIFIED',site,record.commit);
 }catch(error){save(site+'-failure',{error:error.message,started});if(started){try{fs.writeFileSync(dir+'/'+site+'-rollback.log',invoke('rollback'));restart(site);}catch(e){console.error('ROLLBACK FAILED',e.message);}}throw error;}}
function push(){guard();const r=load('commit');if(load('rh-verified').result!=='PASS')throw Error('RH not verified');const ref='refs/heads/'+r.branch;git(['push','origin',ref+':'+ref]);if(git(['ls-remote','origin',ref]).toString().split(/\s/)[0]!==r.commit)throw Error('Remote commit mismatch');save('push',{result:'PASS',...r});console.log('PUSH',r.commit);}
const mode=process.argv[2];if(mode==='commit')commit();else if(mode==='deploy')deploy(process.argv[3]);else if(mode==='push')push();else throw Error('commit|deploy site|push');
