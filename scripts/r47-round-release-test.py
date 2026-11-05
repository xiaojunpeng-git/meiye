"""Execute immutable candidate backend against local rollback fixtures; no live source switch."""
from pathlib import Path
import subprocess,json,tempfile,hashlib,os,tarfile,io
R=Path(__file__).resolve().parent.parent;E=R.parent/'任务管理/验收证据/R47-发布-2026-11-05';m=json.loads((E/'candidate.json').read_text());tree=m['candidateTree']
base=Path(tempfile.mkdtemp(prefix='r47-candidate-test-',dir=str(R/'.git')));snap=base/'snapshot';snap.mkdir();runtime=base/'runtime';runtime.mkdir();runtime.chmod(0o777)
data=subprocess.check_output(['git','archive',tree,'后端代码','tests/cashier-v3','tests/member-checkout/verify-local.mjs'],cwd=R)
with tarfile.open(fileobj=io.BytesIO(data))as t:t.extractall(snap)
# Reserve mountpoints before freezing the derived tree. Dependencies and local
# config are mounted read-only; runtime is the only writable application path.
for name in ['vendor','runtime']:(snap/'后端代码'/name).mkdir(exist_ok=True)
# Local-only configuration remains private (temporary parent is 0700).
(snap/'后端代码/.env').write_bytes((R/'后端代码/.env').read_bytes())
# Every application/test input is an immutable derivation of the canonical Git tree.
for p in snap.rglob('*'):
 if p.is_file():p.chmod(0o444)
for p in sorted((p for p in snap.rglob('*')if p.is_dir()),key=lambda p:len(p.parts),reverse=True):p.chmod(0o555)
snap.chmod(0o555)
cmd=['docker','run','--rm','--network','mohe-net','--entrypoint','php','--user','65534:65534','-v',str(snap/'后端代码')+':/var/www/html','-v',str(R/'后端代码/vendor')+':/var/www/html/vendor:ro','-v',str(runtime)+':/var/www/html/runtime','-e','R49_LOCAL_ONLY=1','-e','R49_CONFIG_FIXTURE=enabled','-w','/var/www/html','-i','mohe-app:local']
# Verify tracked Git bytes after execution; .env is local-only and removed afterwards.
results=[]
for name in ['r47-project-purchase-local.php','r49-checkout-cross-local.php']:
 test=snap/'tests/cashier-v3/php'/name
 x=subprocess.run(cmd,input=test.read_bytes(),capture_output=True,timeout=240)
 out=x.stdout+x.stderr;(E/('candidate-'+name+'.log')).write_bytes(out)
 results.append({'test':name,'exit':x.returncode,'passLines':out.count(b'PASS '),'tail':out.decode(errors='replace')[-500:]});print(name,x.returncode,results[-1]['tail'],flush=True)
 if x.returncode:break
records=subprocess.check_output(['git','ls-tree','-rz',tree,'--','后端代码','tests/cashier-v3','tests/member-checkout/verify-local.mjs'],cwd=R);count=0
for record in records.split(b'\0'):
 if not record:continue
 meta,name=record.split(b'\t',1);data=(snap/name.decode()).read_bytes();assert hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest().encode()==meta.split()[2];count+=1
config=snap/'后端代码/.env';config.parent.chmod(0o755);config.unlink();config.parent.chmod(0o555)
(E/'candidate-backend-tests.json').write_text(json.dumps({'tree':tree,'input':str(snap),'runtime':str(runtime),'sourceStable':True,'inputFilesVerifiedAfter':count,'localConfigRemoved':True,'results':results,'result':'PASS'if len(results)==2 and all(x['exit']==0 for x in results)else'FAIL'},indent=2));assert len(results)==2 and all(x['exit']==0 for x in results)
