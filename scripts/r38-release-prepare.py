"""R38只把固定补丁叠到三站已上线统一源码；私有索引不碰共享工作区。"""
import subprocess,json,hashlib,tempfile,os
from pathlib import Path
E=Path('tests/inventory/evidence/R38-warehouse-labels-expiry');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
f=json.loads((E/'fixed-version.json').read_text());assert all(sha(Path(p).read_bytes())==h for p,h in f['files'].items());assert sha((E/'scope.patch').read_bytes())==f['patchSHA256']
base='e888c787e5d921284d18879167015d3431ce1a5c';idxPath=Path(g('rev-parse','--git-path','index').decode().strip());ih=sha(idxPath.read_bytes());head=g('rev-parse','HEAD').decode().strip()
for site in ['rh','008','010']:
 live=json.loads((R/(site+'-before.json')).read_text())
 for p in [p for p in f['paths']if p.startswith('后端代码/')]:assert sha(g('show',base+':'+p))==live['backend'][p.removeprefix('后端代码/')],site+':'+p
fd,idx=tempfile.mkstemp(prefix='r38-fix-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',base,env=env);baseline=g('write-tree',env=env).decode().strip();g('apply','--cached','--whitespace=nowarn','-',input=(E/'scope.patch').read_bytes(),env=env);candidate=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',baseline,candidate).decode().splitlines();assert sorted(paths)==sorted(f['paths']);g('diff','--check',baseline,candidate)
 # 报表控制器保留已上线工资能力，本轮只在原商品看板方法补两个查询字段行。
 n='后端代码/app/controller/admin/v1/report/UnifiedReport.php';a=g('show',base+':'+n).decode();b=g('show',candidate+':'+n).decode();orig=g('cat-file','blob',json.loads((E/'initial.json').read_text())['files'][n]['blob']).decode()
 def method(s):return s[s.index('    public function productDashboard('):s.index('\n    }',s.index('    public function productDashboard('))+6]
 assert method(a)==method(orig);assert method(b)==method(Path(n).read_text())
 plan={'canonicalGit':str(Path.cwd()),'baselineCommit':base,'baselineTree':baseline,'candidateTree':candidate,'paths':paths,'backendPaths':[p for p in paths if p.startswith('后端代码/')],'sourceParents':['60a57824','345e4590','166f095f'],'acceptedPatch':f['patchSHA256'],'controllerPreservation':'保留三站已上线基础报表/工资读写与权限；商品看板方法与用户验收逻辑逐字一致','head':head,'sharedIndexSHA256':ih}
 (R/'trees.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2));assert sha(idxPath.read_bytes())==ih;print(json.dumps({'baseline':baseline,'candidate':candidate,'scope':len(paths)}))
finally:Path(idx).unlink(missing_ok=True)
