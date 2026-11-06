"""Deploy only the accepted member authorization delta to Ruihao, with exact baseline guards."""
import hashlib,io,json,pathlib,subprocess,tarfile,sys
ROOT=pathlib.Path(__file__).resolve().parents[1]
E=ROOT/'tests/platform-member-access/evidence/release'
SITE='/www/wwwroot/rh.cc3798.com'
BACKUP='/www/backup/r3-member-permission-20261106'
def sha(b):return hashlib.sha256(b).hexdigest()
def ssh(code):
 r=subprocess.run(['ssh','rh-server','php'],input='<?php\n'+code,text=True,capture_output=True,check=True)
 return json.loads(r.stdout)
def git(*a):return subprocess.check_output(['git',*a],cwd=ROOT)
def save(n,r):(E/n).write_text(json.dumps(r,ensure_ascii=False,indent=2)+'\n')
def read(n):return json.loads((E/n).read_text())
def probe():
 return ssh("chdir("+json.dumps(SITE)+");require 'vendor/autoload.php';(new think\\App())->initialize();if(config('database.connections.mysql.database')!=='ruihao')throw new Exception('WRONG_DB');$files=[];foreach(['app/services/system/SystemMenusServices.php','app/services/user/PlatformMemberScopeServices.php','app/services/user/PlatformMemberAccessPolicy.php','public/system.html','public/view_cashier_v3/index.html','public/view_mobile_v3/index.html','public/view_inventory_v3/index.html','public/view_fund_v3/index.html']as$p)$files[$p]=is_file($p)?hash_file('sha256',$p):null;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator('public/view_admin',FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile())$files[$f->getPathname()]=hash_file('sha256',$f->getPathname());echo json_encode(['files'=>$files,'environment'=>hash_file('sha256','.env')]);")
if sys.argv[1]=='prepare':
 c=read('candidate.json');out=pathlib.Path(c['output']);before=probe();save('before.json',before)
 old=json.loads((ROOT.parent/'_local_evidence/2026-11-06-member-review-fix/release/post-login/rh-after.json').read_text())
 assert before['files']['public/system.html']==old['entries']['public/system.html'],'LIVE_FRONTEND_CHANGED'
 payload={}
 for f in c['files']:
  if not f.startswith('后端代码/'):continue
  p=f[len('后端代码/'):];b=git('show',c['tree']+':'+f)
  if p.endswith('PlatformMemberAccessPolicy.php'):assert before['files'][p] is None
  else:assert before['files'][p]==sha(git('show',c['base']+':'+f)),p+' BASELINE_CHANGED'
  payload[p]=b
 for f in out.rglob('*'):
  if not f.is_file():continue
  rel=f.relative_to(out).as_posix()
  if rel!='system.html' and not rel.startswith('view_admin/'):continue
  p='public/'+rel;b=f.read_bytes();prior=before['files'].get(p)
  if prior==sha(b):continue
  if prior and rel!='system.html':raise RuntimeError('IMMUTABLE_COLLISION '+p)
  payload[p]=b
 assert 'public/system.html' in payload
 manifest={p:sha(b) for p,b in payload.items()};save('manifest.json',manifest)
 with tarfile.open(E/'payload.tar.gz','w:gz')as t:
  for p,b in payload.items():
   x=tarfile.TarInfo(p);x.size=len(b);x.mode=0o644;t.addfile(x,io.BytesIO(b))
 print(json.dumps({'payloadFiles':len(payload),'backend':3,'frontendSourceChanges':2,'tree':c['tree']}))
elif sys.argv[1]=='install':
 before=read('before.json');assert probe()==before,'LIVE_BASELINE_CHANGED'
 manifest=read('manifest.json');existing=[p for p in manifest if before['files'].get(p)]
 # Back up only overwritten files; new hashed assets are harmless until the entry switches.
 script='set -e\nmkdir -p '+BACKUP+'\ncd '+SITE+'\n'
 script+='tar -czf '+BACKUP+'/before.tar.gz -- '+ ' '.join(existing)+'\n'
 script+='test -s '+BACKUP+'/before.tar.gz\nmkdir -p '+BACKUP+'/payload\ntar -xzf /tmp/r3-member-permission.tar.gz -C '+BACKUP+'/payload\n'
 # All PHP is syntax checked before any running path changes.
 for p in manifest:
  if p.endswith('.php'):script+='php -l '+BACKUP+'/payload/'+p+'\n'
 for p in sorted(manifest,key=lambda p:p=='public/system.html'):
  script+='mkdir -p '+str(pathlib.PurePosixPath(SITE+'/'+p).parent)+'\ncp '+BACKUP+'/payload/'+p+' '+SITE+'/'+p+'.r3-new\nchmod 644 '+SITE+'/'+p+'.r3-new\nmv '+SITE+'/'+p+'.r3-new '+SITE+'/'+p+'\n'
 script+='/etc/init.d/ruihao_swoole restart\n'
 subprocess.run(['scp',str(E/'payload.tar.gz'),'rh-server:/tmp/r3-member-permission.tar.gz'],check=True,capture_output=True)
 r=subprocess.run(['ssh','rh-server','bash','-s'],input=script,text=True,capture_output=True)
 (E/'install.log').write_text(r.stdout+r.stderr)
 if r.returncode:raise RuntimeError('INSTALL_FAILED: backup '+BACKUP)
 after=probe();save('after.json',after)
 for p,h in manifest.items():assert after['files'].get(p)==h,p
 for p,h in before['files'].items():
  if p not in manifest:assert after['files'].get(p)==h,'PROTECTED_CHANGED '+p
 assert after['environment']==before['environment']
 print('INSTALL_AND_FINGERPRINT_PASS backup='+BACKUP)
