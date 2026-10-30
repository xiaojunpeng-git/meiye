"""Release accepted Round 4 reports from a fixed canonical Git tree, site by site.
Only explicit report files and the cashier integrated frontend are installed. Source
coverage gaps remain explicit; no historical business facts are rewritten.
"""
from pathlib import Path
import subprocess,os,json,hashlib,base64,shlex,re,tarfile,sys,time
from datetime import datetime,timezone
R=Path(__file__).resolve().parent.parent
E=R/'tests/report-basic/evidence/010-round4-release'
S={'rh':('rh-server','/etc/init.d/ruihao_swoole restart',"ss -lnt | grep -q ':20800 '"),
 '008':('mohe-server','systemctl restart mohe-008-swoole.service','systemctl is-active --quiet mohe-008-swoole.service'),
 '010':('mohe-server','systemctl restart mohe-010-swoole.service','systemctl is-active --quiet mohe-010-swoole.service')}
FIXED=json.loads((E/'fixed.json').read_text())
FILES=[x['path'].removeprefix('后端代码/') for x in FIXED['files'] if x['path'].startswith('后端代码/') and '/database/' not in x['path']]
CHANGED={'basic_sales_data','basic_sales_pk','basic_sales_analysis','basic_store_efficiency','basic_store_summary','basic_cross_store_settlement','basic_store_item_sales','basic_item_consumption','basic_writeoff_detail','basic_product_sales','basic_balance_order_detail','basic_balance_order_summary','basic_daily_reconciliation','basic_unconsumed_summary','basic_unconsumed_detail','basic_followup_employee_detail','basic_customer_entitlements','basic_unconsumed_as_of_detail'}
def sha(b):return hashlib.sha256(b).hexdigest()
def read(n):return json.loads((E/n).read_text())
def save(n,d):(E/n).write_text(json.dumps(d,ensure_ascii=False,indent=2)+'\n')
def run(args,data=None,timeout=90,env=None):
 try:p=subprocess.run(args,input=data,cwd=R,capture_output=True,timeout=timeout,env=env)
 except subprocess.TimeoutExpired as error:raise RuntimeError('Timed out; progress: '+(error.stderr or b'').decode(errors='replace')[-2500:]) from None
 if p.returncode:raise RuntimeError(str(args[0])+' failed: '+(p.stdout+p.stderr).decode(errors='replace')[-2000:])
 return p.stdout
def ssh(site,command,data=None,timeout=90):
 return run(['ssh','-o','BatchMode=yes','-o','ConnectTimeout=10','-o','LogLevel=ERROR',S[site][0],command],data,timeout)
def snapshot(site):
 # Hash every touched shared file and all baseline-only guards. No server
 # source is imported into the canonical repository.
 paths=sorted(set(FILES)|set(read(site+'-preflight.json')['backend']))
 code="""import pathlib,json,hashlib,re,sys
r=pathlib.Path('/www/wwwroot/'+sys.argv[1]+'.cc3798.com')
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else None
paths=json.loads(sys.argv[2]);i=r/'public/view_cashier_v3/index.html';a=re.findall(r'assets/[\\w.-]+\\.(?:js|css)',i.read_text(encoding='utf8'))
print(json.dumps({'site':sys.argv[1],'backend':{x:sha(r/x)for x in paths},'front':{'index.html':sha(i),**{x:sha(i.parent/x)for x in a}}}))
"""
 return json.loads(ssh(site,'python3 - '+site+' '+shlex.quote(json.dumps(paths)),code.encode()))
def probe(site,candidate=True,regression_only=False):
 fixed=read('fixed.json');sources={}
 if candidate:
  for file in FILES:
   b=run(['git','show',fixed['candidateTree']+':后端代码/'+file]);sources[file]={'base64':base64.b64encode(b).decode(),'sha256':sha(b)}
 code='eval(substr(base64_decode("'+base64.b64encode((R/'tests/report-basic/php/round4-release-readonly.php').read_bytes()).decode()+'"),5));'
 command='cd /www/wwwroot/'+site+'.cc3798.com && /www/server/php/74/bin/php -r '+shlex.quote(code)
 request={'site':site,'sources':sources}
 if regression_only:request['codes']=['basic_employee_item_sales','basic_employee_service_home_store','basic_employee_service_store','basic_employee_performance_detail','basic_salary_realtime','basic_employee_summary','basic_brush_registration','basic_store_reconciliation']
 return json.loads(ssh(site,command,json.dumps(request).encode(),timeout=240))
def compare_probe(before,after):
 assert before['site']==after['site'] and before['database']==after['database']
 assert before['migration']==after['migration']
 for code,old in before['reports'].items():
  new=after['reports'][code]
  if code in CHANGED:
   assert 'error' not in new,(code,new)
  else:assert new==old,('unrelated report changed',code,old,new)
 for code in CHANGED:assert code in after['reports'] and 'error' not in after['reports'][code],(code,after['reports'].get(code))
 # 008 already lacks the manual-registration source configuration. This
 # unchanged, out-of-scope source gap is recorded, never repaired or hidden.
 for code,result in after['reports'].items():
  if 'error' in result:
   assert after['site']=='008' and code=='basic_brush_registration' and result==before['reports'].get(code) and result.get('message')=='刷单登记来源配置缺失或不唯一',(code,result)
def preflight():
 for site in S:
  current=snapshot(site);expected=read(site+'-preflight.json')
  assert all(current[k]==expected[k]for k in ['backend','front']),site+' live baseline advanced'
  baseline=probe(site,False,True);candidate=probe(site)
  save(site+'-baseline-readonly.json',baseline);save(site+'-candidate-readonly.json',candidate)
  compare_probe(baseline,candidate)
  print(site,'READONLY_PASS',{c:x.get('rows')for c,x in candidate['reports'].items()if c in CHANGED},flush=True)
def commit():
 supplement=read('008-gap-supplemental-acceptance.json')
 assert supplement['status']=='accepted','008 four-report gap display awaits PM supplemental acceptance'
 fixed=read('fixed.json');assert run(['git','rev-parse','HEAD']).decode().strip()==fixed['mainHead']
 assert sha((R/'.git/index').read_bytes())==fixed['sharedIndexSha256'],'shared index advanced'
 for x in fixed['files']:assert sha((R/x['path']).read_bytes())==x.get('worktreeSha256',x['sha256']),x['path']
 for site in S:compare_probe(read(site+'-baseline-readonly.json'),read(site+'-candidate-readonly.json'))
 tests=read('fixed-tests.json')
 assert not run(['git','diff','--name-only',tests['tree'],fixed['candidateTree'],'--','后端代码/app','后端代码/config','前端代码/cashier-v3']+[x['file'] for x in tests['results']]) and len(tests['results'])>=37
 assert all(x['exit']==0 for x in tests['results'])
 # Engineering evidence can change the outer tree after a build; the three
 # frontend source subtrees must remain byte-for-byte identical to that build.
 builds=read('builds.json')
 assert not run(['git','diff','--name-only',builds['candidate']['sourceTree'],fixed['candidateTree'],
     '--','前端代码/cashier-v3','前端代码/inventory-vue3','前端代码/shared'])
 # The accepted Round 3 commit already contains main HEAD. Reuse that
 # traceable baseline directly; create only the requested Round 4 commit.
 run(['git','merge-base','--is-ancestor',fixed['mainHead'],fixed['acceptedBaseline']])
 assert run(['git','rev-parse',fixed['acceptedBaseline']+'^{tree}']).decode().strip()==fixed['baselineTree']
 base=fixed['acceptedBaseline']
 c=run(['git','commit-tree',fixed['candidateTree'],'-p',base],('010-R4 剩余18报表007口径、业务日期及独立菜单接入\n\n'+
 '发布本轮18报表与菜单、合作方分配及PK店内调整。历史事实缺口保持明确，不补写业务事实。\n').encode()).decode().strip()
 branch='release/010-r4-report-parity-20261030';run(['git','update-ref','refs/heads/'+branch,c,'0'*40])
 save('commit.json',{'commit':c,'baselineCommit':base,'tree':fixed['candidateTree'],'branch':branch,'pmAccepted':True,
 'authorization':'用户2026-10-30确认本轮测试无问题，并授权commit→瑞昊→push→008→010','files':fixed['files']})
 print('COMMIT_PASS',c,flush=True)
def package():
 fixed=read('fixed.json');commit=read('commit.json');assert commit['tree']==fixed['candidateTree']
 build=read('builds.json')['candidate'];folder=Path('/private/tmp')/('010-r4-payload-'+commit['commit'][:12]);folder.mkdir();payload=folder/'payload';payload.mkdir()
 hashes={}
 for f in FILES:
  b=run(['git','show',commit['commit']+':后端代码/'+f]);p=payload/'backend'/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b);hashes['backend/'+f]=sha(b)
 output=Path(build['output'])
 for p in output.rglob('*'):
  if not p.is_file():continue
  rel=p.relative_to(output);b=p.read_bytes();q=payload/'frontend'/rel;q.parent.mkdir(parents=True,exist_ok=True);q.write_bytes(b);hashes['frontend/'+str(rel)]=sha(b)
 for name in ['plan.php','execute.php']:
  data=run(['git','show',commit['commit']+':'+MENU_DIR+name]);p=payload/'migration'/name;p.parent.mkdir(exist_ok=True);p.write_bytes(data);hashes['migration/'+name]=sha(data)
 (payload/'SHA256SUMS').write_text(''.join(h+'  '+p+'\n'for p,h in hashes.items()))
 archive=folder/'payload.tar.gz'
 with tarfile.open(archive,'w:gz')as t:t.add(payload,arcname='.')
 save('payload.json',{'commit':commit['commit'],'archive':str(archive),'sha256':sha(archive.read_bytes()),'files':hashes,'frontend':build['files']})
 print('PACKAGE_PASS',archive,flush=True)
MENU_DIR='后端代码/database/upgrades/2026-10-30-侧栏四表菜单接入/'
def menu_preflight(site):
 # Candidate metadata is staged outside the live app, then read without writes.
 fixed=read('fixed.json');stage='/www/backups/'+site+'/010-r4-menu-preflight-'+fixed['candidateTree'][:12]
 import io
 stream=io.BytesIO()
 with tarfile.open(fileobj=stream,mode='w:gz') as archive:
  for source,target in [(MENU_DIR+'plan.php','migration/plan.php'),(MENU_DIR+'execute.php','migration/execute.php')]+[('后端代码/'+f,'backend/'+f) for f in ['app/services/report/BasicReportCatalogServices.php','app/services/organization/StorePermissionCatalogServices.php']]:
   data=run(['git','show',fixed['candidateTree']+':'+source]);entry=tarfile.TarInfo(target);entry.size=len(data);entry.mode=0o444;archive.addfile(entry,io.BytesIO(data))
 ssh(site,'umask 077; mkdir -p '+shlex.quote(stage)+'; tar -xzf - -C '+shlex.quote(stage),stream.getvalue())
 command='/www/server/php/74/bin/php '+shlex.quote(stage+'/migration/execute.php')+' --site='+site+' --dry-run --candidate-root='+shlex.quote(stage+'/backend')
 result=json.loads(ssh(site,command))
 assert result['preview']['legacy_parent_roles_to_update']==0,'unexpected legacy role migration'
 save(site+'-menu-plan.json',result)
 print(site,'MENU_DRY_RUN_PASS',result['preview'],flush=True)
def menu_command(site,backup,mode):
 script=backup+'/candidate/migration/execute.php'
 command='/www/server/php/74/bin/php '+shlex.quote(script)+' --site='+site
 if mode=='apply':
  plan=read(site+'-menu-plan.json')
  command+=' --apply --expected-source-sha='+plan['source_sha256']+' --expected-plan-sha='+plan['plan_sha256']+' --backup='+shlex.quote(backup+'/menu-backup.json')
 else:command+=' --rollback='+shlex.quote(backup+'/menu-backup.json')
 result=json.loads(ssh(site,command));save(site+'-menu-'+mode+'.json',result);return result
def public(site,expected):
 base='https://'+site+'.cc3798.com/view_cashier_v3/'
 for f,h in expected.items():
  b=run(['curl','-fsS','--max-time','45','--retry','2','--retry-all-errors',base+(''if f=='index.html'else f)],timeout=150)
  assert sha(b)==h,(site,f,'public fingerprint mismatch')
 if site=='rh':
  for p in ['preview-8080/','store-v3/']:
   assert run(['curl','-sS','--max-time','15','-o','/dev/null','-w','%{http_code}','https://rh.cc3798.com/'+p]).decode()=='410'
def restart(site):
 ssh(site,'set -eu\n'+S[site][1]+'\n'+S[site][2]+'\n',timeout=90)
def rollback_failed_switch(site,b,r,before):
 # Treat remote state as authoritative: apply may have committed before its SSH
 # receipt disappeared. Each recovery leg runs even if another leg fails.
 errors=[]
 try:
  menu_backup=b+'/menu-backup.json'
  present=ssh(site,'if test -f '+shlex.quote(menu_backup)+'; then printf present; else printf absent; fi').decode().strip()
  if present=='present':menu_command(site,b,'rollback')
  elif present!='absent':raise RuntimeError('menu backup presence unknown')
 except Exception as error:errors.append('menu rollback: '+str(error)[:1000])
 shell='set -eu\nr='+shlex.quote(r)+'\nb='+shlex.quote(b)+'\n'
 for f in FILES:
  shell+=('cp -p "$b/original/backend/'+f+'" "$r/'+f+'"\n'if before['backend'].get(f)else'rm -f "$r/'+f+'"\n')
 shell+='cp -p "$b/original/frontend/index.html" "$r/public/view_cashier_v3/index.html"\n'
 try:ssh(site,shell)
 except Exception as error:errors.append('file rollback: '+str(error)[:1000])
 try:restart(site)
 except Exception as error:errors.append('service restart: '+str(error)[:1000])
 try:
  restored=snapshot(site)
  if not all(restored[k]==before[k]for k in ['backend','front']):
   raise RuntimeError('restored fingerprints differ from preflight')
 except Exception as error:errors.append('fingerprint verification: '+str(error)[:1000])
 return errors
def deploy(site):
 assert site in S
 receipt=read('commit.json');payload=read('payload.json');commit=receipt['commit'];assert payload['commit']==commit
 if site!='rh':
  rh_verified=read('rh-verified.json')
  assert rh_verified['result']=='PASS' and rh_verified['commit']==commit and read('push.json')['commit']==commit
  if site=='010':
   prior=read('008-verified.json');assert prior['result']=='PASS' and prior['commit']==commit
 assert run(['git','rev-parse','refs/heads/'+receipt['branch']]).decode().strip()==commit
 before=read(site+'-preflight.json');now=snapshot(site)
 assert all(now[k]==before[k]for k in ['backend','front']),site+' baseline changed'
 bprobe=probe(site,False,True);cprobe=probe(site);compare_probe(bprobe,cprobe)
 public(site,before['front'])
 r='/www/wwwroot/'+site+'.cc3798.com';b='/www/backups/'+site+'/010-r4-'+commit[:8]+'-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
 # Backup every replaced file and the complete old frontend before uploading or switching.
 shell='set -eu\numask 077\nr='+shlex.quote(r)+'\nb='+shlex.quote(b)+'\ntest ! -e "$b"\nmkdir -p "$b/original/backend" "$b/candidate"\ncp -a "$r/public/view_cashier_v3" "$b/original/frontend"\n'
 for f in FILES:
  shell+='mkdir -p "$b/original/backend/'+str(Path(f).parent)+'"\n'
  if before['backend'].get(f):shell+='cp -p "$r/'+f+'" "$b/original/backend/'+f+'"\n'
 ssh(site,shell)
 archive=Path(payload['archive']);assert sha(archive.read_bytes())==payload['sha256']
 run(['scp','-q','-O',str(archive),S[site][0]+':'+b+'/payload.tar.gz'],timeout=90)
 verify='set -eu\ncd '+shlex.quote(b)+'\nprintf \'%s  payload.tar.gz\\n\' '+shlex.quote(payload['sha256'])+' | sha256sum -c -\ntar -xzf payload.tar.gz -C candidate\ncd candidate\nsha256sum -c SHA256SUMS\n'
 for f in FILES:verify+='/www/server/php/74/bin/php -l '+shlex.quote('backend/'+f)+' >/dev/null\n'
 ssh(site,verify);save(site+'-backup.json',{'path':b,'commit':commit,'packageSha256':payload['sha256']})
 assert all(snapshot(site)[k]==before[k]for k in ['backend','front']),site+' changed before switch'
 started=False
 try:
  started=True
  shell='set -eu\nr='+shlex.quote(r)+'\nb='+shlex.quote(b)+'\ncp -p "$b"/candidate/frontend/assets/* "$r/public/view_cashier_v3/assets/"\n'
  for f in FILES:
   reference=f if before['backend'].get(f)else'app/services/report/BasicActivityReportServices.php'
   shell+='cp "$b/candidate/backend/'+f+'" "$r/'+f+'.010r4.tmp"\nchown --reference="$r/'+reference+'" "$r/'+f+'.010r4.tmp"\nchmod --reference="$r/'+reference+'" "$r/'+f+'.010r4.tmp"\nmv -f "$r/'+f+'.010r4.tmp" "$r/'+f+'"\n'
  ssh(site,shell)
  menu_command(site,b,'apply')
  restart(site)
  ssh(site,'set -eu\ncp '+shlex.quote(b+'/candidate/frontend/index.html')+' '+shlex.quote(r+'/public/view_cashier_v3/index.html.010r4.tmp')+'\nchown --reference='+shlex.quote(r+'/public/view_cashier_v3/index.html')+' '+shlex.quote(r+'/public/view_cashier_v3/index.html.010r4.tmp')+'\nmv -f '+shlex.quote(r+'/public/view_cashier_v3/index.html.010r4.tmp')+' '+shlex.quote(r+'/public/view_cashier_v3/index.html')+'\n')
  after=snapshot(site);expected=dict(before['backend'])
  for f in FILES:expected[f]=payload['files']['backend/'+f]
  assert after['backend']==expected and after['front']==payload['frontend'],'installed fingerprint or unrelated backend changed'
  actual=probe(site,False);compare_probe(bprobe,actual)
  assert actual['reports']==cprobe['reports'],'installed report output differs from preflight candidate'
  public(site,payload['frontend'])
  save(site+'-after.json',after);save(site+'-post-readonly.json',actual)
  save(site+'-verified.json',{'result':'PASS','commit':commit,'backup':b,'businessWrites':0,'databaseMigration':'unchanged','unrelatedReportRegression':'PASS'})
  print('DEPLOY_PASS',site,commit,b,flush=True)
 except Exception as failure:
  if started:
   errors=rollback_failed_switch(site,b,r,before)
   save(site+'-rollback.json',{'result':'FAIL'if errors else'PASS','backup':b,'errors':errors})
   if errors:raise RuntimeError('deploy failed; rollback incomplete: '+'; '.join(errors)) from failure
  raise
def push():
 c=read('commit.json');verified=read('rh-verified.json')
 assert verified['result']=='PASS' and verified['commit']==c['commit']
 env={**os.environ,'GIT_SSH_COMMAND':'ssh -i /Users/xiaojunpeng/.ssh/id_ed25519_gitee -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15'}
 ref='refs/heads/'+c['branch'];run(['git','push','origin',ref+':'+ref],timeout=150,env=env)
 assert run(['git','ls-remote','origin',ref],env=env).decode().split()[0]==c['commit']
 save('push.json',{'result':'PASS','commit':c['commit'],'ref':ref});print('PUSH_PASS',c['commit'],flush=True)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='preflight':preflight()
 elif mode=='menu-preflight':menu_preflight(sys.argv[2])
 elif mode=='commit':commit()
 elif mode=='package':package()
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='push':push()
 else:raise SystemExit('Choose preflight, commit, package, deploy SITE, push')
