"""第11轮发布准备/只读核验；逐实例隔离，候选代码只在远程CLI内存中验证。"""
from pathlib import Path
import subprocess,json,hashlib,base64,shlex,sys,importlib
ROOT=Path(__file__).resolve().parents[2]
E=ROOT/'tests/customer-010/evidence/round11-release'
HOSTS={'rh':'rh-server','008':'mohe-server','010':'mohe-server'}
def sha(data):return hashlib.sha256(data).hexdigest()
def read(name):return json.loads((E/name).read_text())
def write(name,value):(E/name).write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
def run(args,**kw):return subprocess.check_output(args,cwd=ROOT,stderr=subprocess.PIPE,**kw)
def ssh(site,command,data=None):return run(['ssh','-q','-o','BatchMode=yes',HOSTS[site],command],input=data,timeout=180)
def probe(site,candidate):
 body={}
 if candidate:
  original=json.loads((ROOT/'tests/customer-010/evidence/round11-store-reconciliation/fixed-candidate.json').read_text())
  body['classes']=[]
  for f in original['files']:
   if f['group']!='backend':continue
   p=f['path'];data=(ROOT/p).read_bytes();entry={'body':base64.b64encode(data).decode(),'sha256':sha(data)}
   if p.endswith('/config/unified_query.php'):body['config']=entry
   else:entry['class']='app\\'+p.split('/app/')[1][:-4].replace('/','\\');body['classes'].append(entry)
 code=base64.b64encode((ROOT/'scripts/payroll/round11-release-probe.php').read_bytes()).decode()
 command='/www/server/php/74/bin/php -r '+shlex.quote('eval(substr(base64_decode("'+code+'"),5));')+' '+site
 try:result=json.loads(ssh(site,command,json.dumps(body).encode()))
 except subprocess.CalledProcessError as error:raise RuntimeError(site+' probe: '+(error.stderr or b'').decode()[-5000:]+(error.output or b'').decode()[-1000:])from None
 assert result['result']=='PASS';write(site+('-candidate-probe.json'if candidate else'-live-probe.json'),result);return result
if __name__=='__main__':
 if sys.argv[1]=='probe'and sys.argv[2]in HOSTS:print(json.dumps(probe(sys.argv[2],sys.argv[3]=='candidate'),ensure_ascii=False))
 else:raise RuntimeError('Explicit phase/instance required')
