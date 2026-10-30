"""Release accepted Round 3 reports from a fixed canonical Git tree, site by site.
Only explicit report files and the cashier integrated frontend are installed. Source
coverage candidates remain unregistered and are not sent to customer runtimes.
"""
from pathlib import Path
import subprocess,os,json,hashlib,base64,shlex,re,tarfile,sys,time
from datetime import datetime,timezone
R=Path(__file__).resolve().parent.parent
E=R/'tests/report-basic/evidence/010-round3-release'
S={'rh':('rh-server','/etc/init.d/ruihao_swoole restart',"ss -lnt | grep -q ':20800 '"),
 '008':('mohe-server','systemctl restart mohe-008-swoole.service','systemctl is-active --quiet mohe-008-swoole.service'),
 '010':('mohe-server','systemctl restart mohe-010-swoole.service','systemctl is-active --quiet mohe-010-swoole.service')}
FILES=['app/services/report/BasicActivityReportServices.php','app/services/report/BasicReportHostServices.php',
 'app/services/report/query/BasicRegistrationQueryRegistrar.php','app/services/report/EmployeeItemSalesReportReadServices.php']
CHANGED={'basic_employee_item_sales','basic_employee_service_home_store','basic_employee_service_store','basic_employee_performance_detail'}
def sha(b):return hashlib.sha256(b).hexdigest()
def read(n):return json.loads((E/n).read_text())
def save(n,d):(E/n).write_text(json.dumps(d,ensure_ascii=False,indent=2)+'\n')
def run(args,data=None,timeout=90,env=None):
 p=subprocess.run(args,input=data,cwd=R,capture_output=True,timeout=timeout,env=env)
 if p.returncode:raise RuntimeError(str(args[0])+' failed: '+(p.stdout+p.stderr).decode(errors='replace')[-2000:])
 return p.stdout
def ssh(site,command,data=None,timeout=90):
 return run(['ssh','-o','BatchMode=yes','-o','ConnectTimeout=10','-o','LogLevel=ERROR',S[site][0],command],data,timeout)
def snapshot(site):
 code="""import pathlib,json,hashlib,re,sys
r=pathlib.Path('/www/wwwroot/'+sys.argv[1]+'.cc3798.com')
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
php={str(p.relative_to(r)):sha(p) for p in (r/'app/services/report').rglob('*.php')}
i=r/'public/view_cashier_v3/index.html';a=re.findall(r'assets/[\\w.-]+\\.(?:js|css)',i.read_text(encoding='utf8'))
print(json.dumps({'site':sys.argv[1],'reportFiles':php,'front':{'index.html':sha(i),**{x:sha(i.parent/x)for x in a}}}))
"""
 return json.loads(ssh(site,'python3 - '+site,code.encode()))
def probe(site,candidate=True):
 fixed=read('fixed.json');sources={}
 if candidate:
  for file in FILES:
   b=run(['git','show',fixed['candidateTree']+':后端代码/'+file]);sources[file.removeprefix('app/services/report/')]={'base64':base64.b64encode(b).decode(),'sha256':sha(b)}
 code='eval(substr(base64_decode("'+base64.b64encode((R/'tests/report-basic/php/round3-release-readonly.php').read_bytes()).decode()+'"),5));'
 command='cd /www/wwwroot/'+site+'.cc3798.com && /www/server/php/74/bin/php -r '+shlex.quote(code)
 return json.loads(ssh(site,command,json.dumps({'site':site,'sources':sources}).encode()))
def compare_probe(before,after):
 assert before['site']==after['site'] and before['database']==after['database'] and before['migration']==after['migration']
 assert before['salary_labels']==after['salary_labels']
 expected={'basic_employee_item_sales':['门店','员工','项目','销售数量'],
 'basic_employee_service_home_store':['门店','员工','项目','项目数'],
 'basic_employee_service_store':['门店','员工','项目','项目数'],
 'basic_employee_performance_detail':['门店','员工','项目','分类','现金业绩','订单号','销售日期']}
 for code,old in before['reports'].items():
  new=after['reports'][code]
  if code in CHANGED:
   assert 'error'not in new,(code,new)
   assert new['labels']==expected[code],(code,new['labels'])
   assert new['outside_business_date']==0,code
  else:assert new==old,('unrelated report changed',code)
 assert after['registration_labels'][4]=='劵码'
def preflight():
 for site in S:
  current=snapshot(site);expected=read(site+'-preflight.json')
  assert all(current[k]==expected[k]for k in ['reportFiles','front']),site+' live baseline advanced'
  baseline=probe(site,False);candidate=probe(site);compare_probe(baseline,candidate)
  save(site+'-baseline-readonly.json',baseline);save(site+'-candidate-readonly.json',candidate)
  print(site,'READONLY_PASS',{c:x.get('rows')for c,x in candidate['reports'].items()if c in CHANGED},flush=True)
def commit():
 fixed=read('fixed.json');assert run(['git','rev-parse','HEAD']).decode().strip()==fixed['mainHead']
 assert sha((R/'.git/index').read_bytes())==fixed['sharedIndexSha256'],'shared index advanced'
 for x in fixed['files']:assert sha((R/x['path']).read_bytes())==x['sha256'],x['path']
 for site in S:compare_probe(read(site+'-baseline-readonly.json'),read(site+'-candidate-readonly.json'))
 tests=read('fixed-tests.json')
 assert tests['tree']==fixed['candidateTree'] and len(tests['results'])==2
 assert all(x['exit']==0 for x in tests['results'])
 # Engineering evidence can change the outer tree after a build; the three
 # frontend source subtrees must remain byte-for-byte identical to that build.
 builds=read('builds.json')
 assert not run(['git','diff','--name-only',builds['candidate']['sourceTree'],fixed['candidateTree'],
     '--','前端代码/cashier-v3','前端代码/inventory-vue3','前端代码/shared'])
 base=run(['git','commit-tree',fixed['baselineTree'],'-p',fixed['mainHead'],'-p',fixed['acceptedBaseline']],b'010-R3-BASE merge accepted live report and frontend baseline\n').decode().strip()
 c=run(['git','commit-tree',fixed['candidateTree'],'-p',base],('010-R3 员工报表对齐007、业务日期口径及23表审计\n\n'+
 '发布员工销售数、服务数、业绩明细和刷单列名。未注册候选未纳入本次提交或部署，历史事实缺口见审计。\n').encode()).decode().strip()
 branch='release/010-r3-employee-parity-20261030';run(['git','update-ref','refs/heads/'+branch,c,'0'*40])
 save('commit.json',{'commit':c,'baselineCommit':base,'tree':fixed['candidateTree'],'branch':branch,'pmAccepted':True,
 'authorization':'用户2026-10-30确认本轮测试无问题，并授权commit→瑞昊→push→008→010','files':fixed['files']})
 print('COMMIT_PASS',c,flush=True)
def package():
 fixed=read('fixed.json');commit=read('commit.json');assert commit['tree']==fixed['candidateTree']
 build=read('builds.json')['candidate'];folder=Path('/private/tmp')/('010-r3-payload-'+commit['commit'][:12]);folder.mkdir();payload=folder/'payload';payload.mkdir()
 hashes={}
 for f in FILES:
  b=run(['git','show',commit['commit']+':后端代码/'+f]);p=payload/'backend'/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b);hashes['backend/'+f]=sha(b)
 output=Path(build['output'])
 for p in output.rglob('*'):
  if not p.is_file():continue
  rel=p.relative_to(output);b=p.read_bytes();q=payload/'frontend'/rel;q.parent.mkdir(parents=True,exist_ok=True);q.write_bytes(b);hashes['frontend/'+str(rel)]=sha(b)
 (payload/'SHA256SUMS').write_text(''.join(h+'  '+p+'\n'for p,h in hashes.items()))
 archive=folder/'payload.tar.gz'
 with tarfile.open(archive,'w:gz')as t:t.add(payload,arcname='.')
 save('payload.json',{'commit':commit['commit'],'archive':str(archive),'sha256':sha(archive.read_bytes()),'files':hashes,'frontend':build['files']})
 print('PACKAGE_PASS',archive,flush=True)
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
def deploy(site):
 assert site in S
 receipt=read('commit.json');payload=read('payload.json');commit=receipt['commit'];assert payload['commit']==commit
 if site!='rh':
  assert read('rh-verified.json')['result']=='PASS' and read('push.json')['commit']==commit
  if site=='010':assert read('008-verified.json')['result']=='PASS'
 assert run(['git','rev-parse','refs/heads/'+receipt['branch']]).decode().strip()==commit
 before=read(site+'-preflight.json');now=snapshot(site)
 assert all(now[k]==before[k]for k in ['reportFiles','front']),site+' baseline changed'
 bprobe=probe(site,False);cprobe=probe(site);compare_probe(bprobe,cprobe)
 public(site,before['front'])
 r='/www/wwwroot/'+site+'.cc3798.com';b='/www/backups/'+site+'/010-r3-'+commit[:8]+'-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
 # Backup every replaced file and the complete old frontend before uploading or switching.
 shell='set -eu\numask 077\nr='+shlex.quote(r)+'\nb='+shlex.quote(b)+'\ntest ! -e "$b"\nmkdir -p "$b/original/backend" "$b/candidate"\ncp -a "$r/public/view_cashier_v3" "$b/original/frontend"\n'
 for f in FILES:
  shell+='mkdir -p "$b/original/backend/'+str(Path(f).parent)+'"\n'
  if f in before['reportFiles']:shell+='cp -p "$r/'+f+'" "$b/original/backend/'+f+'"\n'
 ssh(site,shell)
 archive=Path(payload['archive']);assert sha(archive.read_bytes())==payload['sha256']
 run(['scp','-q','-O',str(archive),S[site][0]+':'+b+'/payload.tar.gz'],timeout=90)
 verify='set -eu\ncd '+shlex.quote(b)+'\nprintf \'%s  payload.tar.gz\\n\' '+shlex.quote(payload['sha256'])+' | sha256sum -c -\ntar -xzf payload.tar.gz -C candidate\ncd candidate\nsha256sum -c SHA256SUMS\n'
 for f in FILES:verify+='/www/server/php/74/bin/php -l '+shlex.quote('backend/'+f)+' >/dev/null\n'
 ssh(site,verify);save(site+'-backup.json',{'path':b,'commit':commit,'packageSha256':payload['sha256']})
 assert all(snapshot(site)[k]==before[k]for k in ['reportFiles','front']),site+' changed before switch'
 started=False
 try:
  started=True
  shell='set -eu\nr='+shlex.quote(r)+'\nb='+shlex.quote(b)+'\ncp -p "$b"/candidate/frontend/assets/* "$r/public/view_cashier_v3/assets/"\n'
  for f in FILES:
   reference=f if f in before['reportFiles']else'app/services/report/BasicActivityReportServices.php'
   shell+='cp "$b/candidate/backend/'+f+'" "$r/'+f+'.010r3.tmp"\nchown --reference="$r/'+reference+'" "$r/'+f+'.010r3.tmp"\nchmod --reference="$r/'+reference+'" "$r/'+f+'.010r3.tmp"\nmv -f "$r/'+f+'.010r3.tmp" "$r/'+f+'"\n'
  ssh(site,shell);restart(site)
  ssh(site,'set -eu\ncp '+shlex.quote(b+'/candidate/frontend/index.html')+' '+shlex.quote(r+'/public/view_cashier_v3/index.html.010r3.tmp')+'\nchown --reference='+shlex.quote(r+'/public/view_cashier_v3/index.html')+' '+shlex.quote(r+'/public/view_cashier_v3/index.html.010r3.tmp')+'\nmv -f '+shlex.quote(r+'/public/view_cashier_v3/index.html.010r3.tmp')+' '+shlex.quote(r+'/public/view_cashier_v3/index.html')+'\n')
  after=snapshot(site);expected=dict(before['reportFiles'])
  for f in FILES:expected[f]=payload['files']['backend/'+f]
  assert after['reportFiles']==expected and after['front']==payload['frontend'],'installed fingerprint or unrelated backend changed'
  actual=probe(site,False);compare_probe(bprobe,actual)
  assert actual==cprobe,'installed report output differs from preflight candidate'
  public(site,payload['frontend'])
  save(site+'-after.json',after);save(site+'-post-readonly.json',actual)
  save(site+'-verified.json',{'result':'PASS','commit':commit,'backup':b,'businessWrites':0,'databaseMigration':'unchanged','unrelatedReportRegression':'PASS'})
  print('DEPLOY_PASS',site,commit,b,flush=True)
 except Exception:
  if started:
   shell='set -eu\nr='+shlex.quote(r)+'\nb='+shlex.quote(b)+'\n'
   for f in FILES:
    shell+=('cp -p "$b/original/backend/'+f+'" "$r/'+f+'"\n'if f in before['reportFiles']else'rm -f "$r/'+f+'"\n')
   shell+='cp -p "$b/original/frontend/index.html" "$r/public/view_cashier_v3/index.html"\n';ssh(site,shell);restart(site)
   restored=snapshot(site);assert all(restored[k]==before[k]for k in ['reportFiles','front'])
   save(site+'-rollback.json',{'result':'PASS','backup':b})
  raise
def push():
 c=read('commit.json');assert read('rh-verified.json')['result']=='PASS'
 env={**os.environ,'GIT_SSH_COMMAND':'ssh -i /Users/xiaojunpeng/.ssh/id_ed25519_gitee -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15'}
 ref='refs/heads/'+c['branch'];run(['git','push','origin',ref+':'+ref],timeout=150,env=env)
 assert run(['git','ls-remote','origin',ref],env=env).decode().split()[0]==c['commit']
 save('push.json',{'result':'PASS','commit':c['commit'],'ref':ref});print('PUSH_PASS',c['commit'],flush=True)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='preflight':preflight()
 elif mode=='commit':commit()
 elif mode=='package':package()
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='push':push()
 else:raise SystemExit('Choose preflight, commit, package, deploy SITE, push')
