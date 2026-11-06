"""Freeze only R52's accepted delta onto traced live sources, without touching the shared index."""
import pathlib,subprocess,tempfile,os,json,hashlib,difflib,tarfile,io
R=pathlib.Path(__file__).resolve().parents[1];os.chdir(R)
E=R.parent/'任务管理/第52轮-客户品相分析';D=E/'release';D.mkdir(exist_ok=True)
def g(*a,input=None,env=None):return subprocess.check_output(['git',*a],input=input,env=env)
def idx():return dict(os.environ,GIT_INDEX_FILE=tempfile.mktemp(prefix='r52-index-'))
def put(env,p,b):
 blob=g('hash-object','-w','--stdin',input=b).decode().strip();g('update-index','--add','--cacheinfo','100644',blob,p,env=env)
b=json.loads((E/'baseline.json').read_text());original=idx();g('read-tree',b['head'],env=original);g('apply','--cached','-',input=(E/'baseline.diff').read_bytes(),env=original)
originalTree=g('write-tree',env=original).decode().strip();patch=b''
for p,h in b['files'].items():
 old=g('show',originalTree+':'+p);assert hashlib.sha256(old).hexdigest()==h
 patch+=''.join(difflib.unified_diff(old.decode().splitlines(True),(R/p).read_text().splitlines(True),fromfile='a/'+p,tofile='b/'+p)).encode()
(D/'round52.patch').write_bytes(patch)
env=idx();base='c75e0d1a1886c0b508aeab806994f0cec0475b8c';g('read-tree',base,env=env)
p='后端代码/app/services/report/StoreUnifiedReportPhaseFourServices.php';ref='10ac3688728bb83f5587c1fc77d7a7a9263bcf47';put(env,p,g('show',ref+':'+p))
baseTree=g('write-tree',env=env).decode().strip();g('apply','--cached','--check','-',input=patch,env=env);g('apply','--cached','-',input=patch,env=env)
for p in ['tests/customer-analytics/php/beauty-item-regression.php','tests/customer-analytics/php/item-analysis-benchmark.php']:put(env,p,(R/p).read_bytes())
tree=g('write-tree',env=env).decode().strip();d=pathlib.Path(tempfile.mkdtemp(prefix='r52-fixed-build-'))
paths=['前端代码/cashier-v3','前端代码/inventory-vue3','前端代码/shared','tests/cashier-v3/js']
with tarfile.open(fileobj=io.BytesIO(g('archive',tree,*paths))) as t:t.extractall(d)
for project in ['cashier-v3','inventory-vue3']:(d/'前端代码'/project/'node_modules').symlink_to(R/'前端代码'/project/'node_modules',target_is_directory=True)
for f in d.rglob('*'):
 if f.is_file() and not f.is_symlink():f.chmod(0o444)
c={'base':base,'baselineTree':baseTree,'preservedPhaseFourSource':ref,'tree':tree,'buildInput':str(d),'patchSHA256':hashlib.sha256(patch).hexdigest(),'files':list(b['files'])+['tests/customer-analytics/php/beauty-item-regression.php','tests/customer-analytics/php/item-analysis-benchmark.php']}
(D/'candidate.json').write_text(json.dumps(c,ensure_ascii=False,indent=2));print(json.dumps(c,ensure_ascii=False));print(g('diff','--stat',baseTree,tree).decode())
for item in [original,env]:pathlib.Path(item['GIT_INDEX_FILE']).unlink(missing_ok=True)
