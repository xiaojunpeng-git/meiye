"""Freeze only Round 4 files in a private Git index; derive read-only build/test exports.
The shared worktree and index are never reset or staged. Online files are evidence only.
"""
from pathlib import Path
import subprocess,os,json,hashlib,tempfile,sys,tarfile,io
ROOT=Path(__file__).resolve().parent.parent
E=ROOT/'tests/report-basic/evidence/010-round4-release'
def run(args,**kw):
 p=subprocess.run(args,cwd=ROOT,capture_output=True,**kw)
 if p.returncode:raise RuntimeError(p.stderr.decode(errors='replace')[-2500:])
 return p.stdout
def sha(b):return hashlib.sha256(b).hexdigest()
def save(n,d):(E/n).write_text(json.dumps(d,ensure_ascii=False,indent=2)+'\n')
E.mkdir(parents=True,exist_ok=True)
HEAD=run(['git','rev-parse','HEAD']).decode().strip()
ACCEPTED='87b259936c14ed777e443eac81df9f580e936c16'
BASE=run(['git','merge-tree','--write-tree',HEAD,ACCEPTED]).decode().strip()
# This merge must preserve the exact accepted/live cashier frontend before Round 4.
assert not run(['git','diff','--name-only',ACCEPTED,BASE,'--','前端代码/cashier-v3','前端代码/inventory-vue3','前端代码/shared'])
# Only reviewed Round 4 groups are eligible; incidental shared-file rollbacks
# are explicitly excluded or reconstructed against the accepted baseline.
m=json.loads((ROOT/'tests/report-basic/evidence/010-round4-candidate-manifest.json').read_text())
files=list(dict.fromkeys(x['path'] for group in ['backend','frontend','migration_plan_not_executed','tests','scripts'] for x in m['files'][group]))
files+=['后端代码/app/controller/admin/v1/report/UnifiedReport.php','scripts/010-round4-prepare-release.py','scripts/010-round4-release.py','tests/report-basic/php/round4-release-readonly.php','tests/report-basic/php/round4-sidebar-menu-execute-plan.php','tests/report-basic/python/round4-release-rollback-contract.py','后端代码/database/upgrades/2026-10-30-侧栏四表菜单接入/execute.php']
import difflib

def selected_bytes(f):
 new=(ROOT/f).read_bytes()
 rules={
 '后端代码/app/services/order/store/WriteOffOrderServices.php':lambda i,j:1300<=i<1650,
 '后端代码/app/services/organization/StorePermissionCatalogServices.php':lambda i,j:False,
 '后端代码/app/controller/admin/v1/report/UnifiedReport.php':lambda i,j:i<600,
 }
 if f not in rules:return new
 old=run(['git','show',ACCEPTED+':'+f]).decode().splitlines(True);lines=new.decode().splitlines(True);out=[]
 for tag,i,j,k,l in difflib.SequenceMatcher(None,old,lines).get_opcodes():
  use=rules[f](i,j)
  if 'StorePermissionCatalogServices' in f:use=tag=='insert' and 'basic_sales_data' in ''.join(lines[k:l])
  if 'UnifiedReport.php' in f:use=tag!='equal' and '游客' not in ''.join(old[i:j])+''.join(lines[k:l])
  out.extend(lines[k:l] if tag!='equal' and use else old[i:j])
 return ''.join(out).encode()
index=Path(tempfile.mkdtemp(prefix='010-r4-index-'))/'index'
env={**os.environ,'GIT_INDEX_FILE':str(index)}
run(['git','read-tree',BASE],env=env)
manifest=[]
for f in files:
 b=selected_bytes(f);blob=run(['git','hash-object','-w','--stdin'],input=b).decode().strip()
 run(['git','update-index','--add','--cacheinfo','100644,'+blob+','+f],env=env)
 manifest.append({'path':f,'sha256':sha(b),'blob':blob,'worktreeSha256':sha((ROOT/f).read_bytes())})
TREE=run(['git','write-tree'],env=env).decode().strip()
run(['git','diff','--check',BASE,TREE])
info={'mainHead':HEAD,'acceptedBaseline':ACCEPTED,'baselineTree':BASE,'candidateTree':TREE,
 'files':manifest,'sharedIndexSha256':sha((ROOT/'.git/index').read_bytes())}
save('fixed.json',info)
# Read-only derived exports are not alternative editable business sources.
for label,tree in [('baseline',BASE),('candidate',TREE)]:
 target=Path.home()/'.cache/mohe-010-r4-release'/tree[:12]
 if not target.exists():
  target.mkdir(parents=True)
  archive=run(['git','archive',tree,'前端代码/cashier-v3','前端代码/inventory-vue3','前端代码/shared',
    '后端代码/app','后端代码/config','后端代码/route','后端代码/database','后端代码/composer.json','后端代码/mohe','tests/report-basic/php','tests/cashier-v3/js'])
  with tarfile.open(fileobj=io.BytesIO(archive))as t:t.extractall(target)
  for project in ['cashier-v3','inventory-vue3']:
   (target/'前端代码'/project/'node_modules').symlink_to(ROOT/'前端代码'/project/'node_modules',target_is_directory=True)
  for folder in ['vendor','runtime']:(target/'后端代码'/folder).mkdir(exist_ok=True)
  (target/'后端代码/.env').touch()
  for p in target.rglob('*'):
   if p.is_file() and not p.is_symlink():p.chmod(0o444)
 info[label+'Export']=str(target)
save('fixed.json',info)
print(json.dumps({k:v for k,v in info.items()if k!='files'},indent=2))
