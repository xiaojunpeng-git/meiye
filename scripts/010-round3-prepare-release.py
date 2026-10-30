"""Freeze only Round 3 files in a private Git index; derive read-only build/test exports.
The shared worktree and index are never reset or staged. Online files are evidence only.
"""
from pathlib import Path
import subprocess,os,json,hashlib,tempfile,sys,tarfile,io
ROOT=Path(__file__).resolve().parent.parent
E=ROOT/'tests/report-basic/evidence/010-round3-release'
def run(args,**kw):
 p=subprocess.run(args,cwd=ROOT,capture_output=True,**kw)
 if p.returncode:raise RuntimeError(p.stderr.decode(errors='replace')[-2500:])
 return p.stdout
def sha(b):return hashlib.sha256(b).hexdigest()
def save(n,d):(E/n).write_text(json.dumps(d,ensure_ascii=False,indent=2)+'\n')
HEAD=run(['git','rev-parse','HEAD']).decode().strip()
ACCEPTED='c02ced91ea1162b37048a4023cdfd33fda35c31b'
BASE=run(['git','merge-tree','--write-tree',HEAD,ACCEPTED]).decode().strip()
# This merge must preserve the exact accepted/live cashier frontend before Round 3.
assert not run(['git','diff','--name-only',ACCEPTED,BASE,'--','前端代码/cashier-v3','前端代码/inventory-vue3','前端代码/shared'])
files=['前端代码/cashier-v3/src/views/BasicReportsView.vue',
 '后端代码/app/services/report/BasicActivityReportServices.php',
 '后端代码/app/services/report/BasicReportHostServices.php',
 '后端代码/app/services/report/EmployeeItemSalesReportReadServices.php',
 '后端代码/app/services/report/query/BasicRegistrationQueryRegistrar.php']
# Unregistered readers with unresolved historical sources remain in the worktree;
# they are not part of this accepted, independently releasable employee closure.
files+=['tests/report-basic/php/activity-local.php','tests/report-basic/php/activity-host-local.php',
 'tests/report-basic/js/browser.mjs','tests/report-basic/php/round3-release-readonly.php',
 'tests/report-basic/evidence/010-round3-007-23-report-parity-audit.md',
 'tests/report-basic/evidence/010-round3-balance-date-audit-2026-10-30.md',
 'scripts/010-round3-prepare-release.py','scripts/010-round3-release.py']
index=Path(tempfile.mkdtemp(prefix='010-r3-index-'))/'index'
env={**os.environ,'GIT_INDEX_FILE':str(index)}
run(['git','read-tree',BASE],env=env)
manifest=[]
for f in files:
 b=(ROOT/f).read_bytes();blob=run(['git','hash-object','-w','--stdin'],input=b).decode().strip()
 run(['git','update-index','--add','--cacheinfo','100644,'+blob+','+f],env=env)
 manifest.append({'path':f,'sha256':sha(b),'blob':blob})
TREE=run(['git','write-tree'],env=env).decode().strip()
run(['git','diff','--check',BASE,TREE])
info={'mainHead':HEAD,'acceptedBaseline':ACCEPTED,'baselineTree':BASE,'candidateTree':TREE,
 'files':manifest,'sharedIndexSha256':sha((ROOT/'.git/index').read_bytes())}
save('fixed.json',info)
# Read-only derived exports are not alternative editable business sources.
for label,tree in [('baseline',BASE),('candidate',TREE)]:
 target=Path.home()/'.cache/mohe-010-r3-release'/tree[:12]
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
