"""Explicit sequential R31 release: fixed commit, RH verify, push, 008, 010; no DB writes."""
import subprocess,json,hashlib,os,tempfile,tarfile,io,base64
from pathlib import Path
R=Path('tests/cashier-v3/evidence/R31-sale-price-range/release');SITES={'rh':('rh-server','rh.cc3798.com','/etc/init.d/ruihao_swoole restart',"ss -lnt | grep -q ':20800 '"),'008':('mohe-server','008.cc3798.com','systemctl restart mohe-008-swoole.service','systemctl is-active --quiet mohe-008-swoole.service'),'010':('mohe-server','010.cc3798.com','systemctl restart mohe-010-swoole.service','systemctl is-active --quiet mohe-010-swoole.service')}
def run(a,**kw):return subprocess.check_output(a,**kw)
def git(*a,**kw):return run(['git',*a],**kw)
def sha(v):return hashlib.sha256(v).hexdigest()
def read(n):return json.loads((R/n).read_text())
def save(n,v):(R/n).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def ssh(s,cmd,data=None):return run(['ssh','-q','-o','BatchMode=yes',SITES[s][0],cmd],input=data,stderr=subprocess.PIPE)
def probe(s):return json.loads(ssh(s,'/www/server/php/74/bin/php -- '+s,Path('scripts/r28-permissions-inspect.php').read_bytes()))
def commit():
 t=read('trees.json');assert git('rev-parse','HEAD').decode().strip()==t['head']
 for terminal in ['admin','cashier-v3']:
  b=read('candidate-'+terminal+'-build.json');assert b['result']=='PASS' and b['sourceStable'] and b['tree']==t['candidateTree']
 for s in SITES:assert probe(s)==read(s+'-before.json'),'live baseline changed '+s
 idx=Path(git('rev-parse','--git-path','index').decode().strip());ih=sha(idx.read_bytes());fd,private=tempfile.mkstemp(prefix='r31-commit-');os.close(fd);os.unlink(private);env=dict(os.environ,GIT_INDEX_FILE=private)
 try:
  git('read-tree',t['candidateTree'],env=env)
  for p in list(R.parent.rglob('*'))+list(Path('scripts').glob('r31-price-*')):
   if p.is_file():
    blob=git('hash-object','-w','--stdin',input=p.read_bytes()).decode().strip();git('update-index','--add','--cacheinfo','100644',blob,str(p),env=env)
  tree=git('write-tree',env=env).decode().strip();c=git('commit-tree',tree,'-p',t['baselineCommit'],input='R31-00001 可售价区间接入三类商品单价改价校验\n\n产品经理验收通过；只应用本轮范围补丁，保留R51已上线前端及R49/R29/R30既有能力。\n'.encode()).decode().strip();branch='release/r31-sale-price-range-20261024';git('update-ref','refs/heads/'+branch,c,'0'*40)
  assert sha(idx.read_bytes())==ih and git('rev-parse','HEAD').decode().strip()==t['head']
  save('commit.json',{'commit':c,'branch':branch,'tree':tree,'baselineCommit':t['baselineCommit'],'candidateTree':t['candidateTree'],'sharedIndexUntouched':True,'pmAccepted':True});print('COMMITTED',c)
 finally:Path(private).unlink(missing_ok=True)
def deploy(s):
 info=read('commit.json');c=info['commit'];host,domain,restart,health=SITES[s];root='/www/wwwroot/'+domain;directory='/www/backups/'+s+'/r31-price-'+c[:8]
 if s!='rh':
  assert read('push.json')['result']=='PASS'
  for p in (['rh']if s=='008'else['rh','008']):assert read(p+'-verified.json')['result']=='PASS'
 before=read(s+'-before.json');assert probe(s)==before,'changed before deploy'
 files={};backend=[]
 for f in read('trees.json')['paths']:
  if f.startswith('后端代码/'):
   rel=f.removeprefix('后端代码/');files[rel]=git('show',c+':'+f);backend.append(rel)
 assert len(backend)==4
 for term,target in [('cashier-v3','public/view_cashier_v3/'),('admin','public/')]:
  build=read('candidate-'+term+'-build.json')
  for rel,h in build['assets'].items():
   p=Path(build['output'])/rel;data=p.read_bytes();assert sha(data)==h;files[target+rel]=data
 # Actual old hashes are read only, including reused hashed assets. Unknown differences never get overwritten.
 paths=list(files);encoded=base64.b64encode(json.dumps(paths).encode()).decode();php="<?php $r='"+root+"';$p=json_decode(base64_decode('"+encoded+"'),true);$a=[];foreach($p as $f)$a[$f]=is_file($r.'/'.$f)?hash_file('sha256',$r.'/'.$f):null;echo json_encode($a);"
 olds=json.loads(ssh(s,'/www/server/php/74/bin/php',php.encode()))
 for f in backend:assert olds[f]==before['backend'][f]
 for front in before['frontends']:
  if front['root']==root:assert olds[front['entry']]==front['sha256']
 targets={f:{'before':olds[f],'after':sha(b)}for f,b in files.items()}
 targets=dict(sorted(targets.items(),key=lambda kv:(kv[0].endswith(('index.html','system.html')),kv[0])))
 manifest={'site':s,'commit':c,'sourceTree':info['candidateTree'],'targets':targets,'preview':'unchanged; formal only','database':'no migration'}
 payload=io.BytesIO()
 with tarfile.open(fileobj=payload,mode='w:gz')as tf:
  for name,b in [('payload/'+f,b)for f,b in files.items()]+[('payload/manifest.json',json.dumps(manifest).encode()),('install.php',Path('scripts/r31-price-install.php').read_bytes()),('smoke.php',Path('scripts/r31-price-smoke.php').read_bytes())]:
   entry=tarfile.TarInfo(name);entry.size=len(b);entry.mode=0o600;tf.addfile(entry,io.BytesIO(b))
 ssh(s,"test ! -e '"+directory+"' && mkdir -m 700 -p '"+directory+"'")
 ssh(s,'tar -xzf - -C '+directory,payload.getvalue());assert probe(s)==before,'changed during upload'
 shell=f'''set -euo pipefail
rollback() {{ set +e; /www/server/php/74/bin/php '{directory}/install.php' '{directory}' rollback; {restart}; rm -f /tmp/r31-price-smoke-{s}.php; exit 1; }}
/www/server/php/74/bin/php '{directory}/install.php' '{directory}' install
trap rollback ERR
{restart}
ready=0
for attempt in 1 2 3 4 5 6 7 8 9 10; do if {health}; then ready=1; break; fi; sleep 1; done
test "$ready" = 1
install -m 600 -o www -g www '{directory}/smoke.php' /tmp/r31-price-smoke-{s}.php
su -s /bin/sh www -c '/www/server/php/74/bin/php /tmp/r31-price-smoke-{s}.php {s}'
rm -f /tmp/r31-price-smoke-{s}.php
trap - ERR
echo R31_INSTANCE_PASS
'''
 try:ssh(s,'bash -s > '+directory+'/release.log 2>&1',shell.encode())
 finally:(R/(s+'-deployment.log')).write_bytes(ssh(s,'cat '+directory+'/release.log'))
 assert b'R31_INSTANCE_PASS' in (R/(s+'-deployment.log')).read_bytes()
 after=probe(s);expected=json.loads(json.dumps(before))
 for f in backend:expected['backend'][f]=sha(files[f])
 assert after['backend']==expected['backend'] and after['protected']==before['protected'] and after['channelRules']==before['channelRules'] and after['upgrades']==before['upgrades']
 for prev,now in zip(before['frontends'],after['frontends']):
  if prev['root']!=root:assert prev==now
  else:
   assert now['sha256']==sha(files[now['entry']])
   for path,h in now['assets'].items():assert sha(files['public/'+path.lstrip('/')])==h
 save(s+'-after.json',after);save(s+'-manifest.json',manifest);save(s+'-verified.json',{'site':s,'commit':c,'result':'PASS','backup':directory,'frontend':'formal admin and cashier','preview':'unchanged','database':'unchanged'});print('DEPLOY_VERIFIED',s,c)
def push():
 i=read('commit.json');assert read('rh-verified.json')['result']=='PASS';assert all(x['result']=='PASS' for x in read('rh-public-browser.json'));out=git('push','origin','refs/heads/'+i['branch']+':refs/heads/'+i['branch'],stderr=subprocess.STDOUT);(R/'push.log').write_bytes(out);assert git('ls-remote','origin','refs/heads/'+i['branch']).decode().split()[0]==i['commit'];save('push.json',{'result':'PASS','commit':i['commit'],'branch':i['branch']});print('PUSH_VERIFIED',i['commit'])
if __name__=='__main__':
 import sys
 if sys.argv[1]=='commit':commit()
 elif sys.argv[1]=='deploy':deploy(sys.argv[2])
 elif sys.argv[1]=='push':push()
