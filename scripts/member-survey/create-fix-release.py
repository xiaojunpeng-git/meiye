"""Approved query-only fix: one PHP file, guarded baseline, no migrations or data writes."""
from pathlib import Path
from datetime import datetime
import subprocess,json,hashlib,base64,shlex,sys
ROOT=Path(__file__).resolve().parents[2];E=ROOT/'tests/customer-010/evidence/round12-member-survey-create-fix';P='app/services/user/MemberSurveyServices.php'
HOSTS={'rh':'rh-server','008':'mohe-server','010':'mohe-server'}
def sha(b):return hashlib.sha256(b).hexdigest()
def run(args,**kw):return subprocess.check_output(args,cwd=ROOT,stderr=subprocess.PIPE,**kw)
def read(n):return json.loads((E/n).read_text())
def write(n,d):(E/n).write_text(json.dumps(d,ensure_ascii=False,indent=2))
def ssh(t,cmd,data=None):return run(['ssh','-q','-o','BatchMode=yes',HOSTS[t],cmd],input=data,timeout=150)
def guard():
 f=read('fixed-candidate.json');a=read('acceptance.json');assert a['accepted']and a['fingerprint']==f['fingerprint'];c=read('commit.json')['commit'];assert run(['git','rev-parse','HEAD']).decode().strip()==c
 for p in f['files']:assert sha((ROOT/p['path']).read_bytes())==p['sha256']
 return c
def probe(t,expected):
 source=(ROOT/('后端代码/'+P)).read_bytes();code=base64.b64encode((ROOT/'scripts/member-survey/create-fix-probe.php').read_bytes()).decode()
 data={'expected_before_sha':expected,'candidate_source':base64.b64encode(source).decode(),'candidate_sha':sha(source)}
 cmd='/www/server/php/74/bin/php -r '+shlex.quote('eval(substr(base64_decode("'+code+'"),5));')+' '+t
 out=json.loads(ssh(t,cmd,json.dumps(data).encode()));assert out['result']=='PASS';return out
def deploy(t):
 commit=guard()
 if t!='rh':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if t=='010':assert read('008-verified.json')['result']=='PASS'
 old=read(t+'-candidate-readonly.json')['before_service_sha256'];source=ROOT/('后端代码/'+P);new=sha(source.read_bytes());before=probe(t,old);write(t+'-before-release.json',before)
 backup='/www/backups/'+t+'/round12-create-fix-'+commit[:12]+'-'+datetime.now().strftime('%Y%m%d%H%M%S');root='/www/wwwroot/'+t+'.cc3798.com'
 ssh(t,'umask 077; mkdir -p '+shlex.quote(backup));run(['scp','-q','-O',str(source),HOSTS[t]+':'+backup+'/candidate.php'],timeout=150)
 # Only this fixed path is replaced; backup bytes/hash and file ownership precede the atomic rename.
 install=f'''set -euo pipefail
umask 077
cd {shlex.quote(backup)}
test "$(sha256sum candidate.php | cut -d' ' -f1)" = '{new}'
/www/server/php/74/bin/php -l candidate.php
test "$(sha256sum {shlex.quote(root+'/'+P)} | cut -d' ' -f1)" = '{old}'
cp -p {shlex.quote(root+'/'+P)} original.php
test "$(sha256sum original.php | cut -d' ' -f1)" = '{old}'
cp candidate.php {shlex.quote(root+'/'+P+'.r12-create-fix')}
chmod --reference={shlex.quote(root+'/'+P)} {shlex.quote(root+'/'+P+'.r12-create-fix')}
chown --reference={shlex.quote(root+'/'+P)} {shlex.quote(root+'/'+P+'.r12-create-fix')}
mv {shlex.quote(root+'/'+P+'.r12-create-fix')} {shlex.quote(root+'/'+P)}
'''
 restart='/etc/init.d/ruihao_swoole restart'if t=='rh'else'systemctl restart mohe-'+t+'-swoole.service'
 write(t+'-started.json',{'backup':backup,'commit':commit,'old_sha256':old,'new_sha256':new})
 installed=False
 try:
  (E/(t+'-install.log')).write_bytes(ssh(t,'bash -s',install.encode()));installed=True;ssh(t,restart)
  after=probe(t,new)
  for k in ['survey_data_sha256','platform_entry_sha256','member_entry_sha256']:assert before[k]==after[k],k
  body=run(['curl','-fsS','--max-time','30','https://'+t+'.cc3798.com/adminapi/user/survey'],timeout=40);assert json.loads(body)['status']==410000
  after.update(result='PASS',source_commit=commit,backup=backup,only_one_backend_file_deployed=True,existing_survey_data_unchanged=True,frontend_entries_unchanged=True,unauthenticated_api_denied=True)
  write(t+'-verified.json',after);print(t,'DEPLOYED_VERIFIED',after['task_total'],flush=True)
 except BaseException:
  if installed:
   rollback=f'''set -euo pipefail
test "$(sha256sum {shlex.quote(root+'/'+P)} | cut -d' ' -f1)" = '{new}'
test "$(sha256sum {shlex.quote(backup+'/original.php')} | cut -d' ' -f1)" = '{old}'
cp -p {shlex.quote(backup+'/original.php')} {shlex.quote(root+'/'+P+'.r12-create-fix')}
mv {shlex.quote(root+'/'+P+'.r12-create-fix')} {shlex.quote(root+'/'+P)}
{restart}
'''
   try:ssh(t,'bash -s',rollback.encode());write(t+'-rollback.json',{'result':'ROLLED_BACK','backup':backup})
   except BaseException as err:write(t+'-rollback-error.json',{'error':str(err)})
  raise
def push():
 commit=guard();assert read('rh-verified.json')['result']=='PASS';run(['git','push','origin',commit+':refs/heads/010/basic-report-menu'],timeout=180);assert run(['git','ls-remote','origin','refs/heads/010/basic-report-menu'],timeout=60).decode().split()[0]==commit
 write('push.json',{'result':'PASS','commit':commit,'remote':'origin','branch':'010/basic-report-menu'});print('PUSH_VERIFIED',commit,flush=True)
if __name__=='__main__':
 try:
  if sys.argv[1]=='deploy'and sys.argv[2]in HOSTS:deploy(sys.argv[2])
  elif sys.argv[1]=='push':push()
  else:raise RuntimeError('Explicit target or push required')
 except subprocess.CalledProcessError as e:
  print((e.stderr or b'').decode()[-1800:],file=sys.stderr);raise
