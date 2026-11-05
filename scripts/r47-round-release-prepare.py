"""R47 accepted-only candidate from canonical deployed sources. Shared source/index stay untouched."""
import os,json,hashlib,subprocess,tempfile,re
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
E=ROOT.parent/'任务管理/验收证据/R47-发布-2026-11-05'
sha=lambda x:hashlib.sha256(x).hexdigest()
def git(*a,**kw):return subprocess.check_output(['git',*a],cwd=ROOT,**kw)
def store(path,data,env):
 blob=git('hash-object','-w','--stdin',input=data).decode().strip()
 git('update-index','--add','--cacheinfo','100644',blob,path,env=env)
sets=[('R47-普通卡编辑金额-2026-11-05','R47-fixed.patch'),('R47-购买项目自动核销开关-2026-11-05','fixed.patch'),('R47-普通卡单一总价-2026-11-05','fixed.patch')]
scope={}
for name,_ in sets:
 m=json.loads((E.parent/name/'fixed-version.json').read_text())
 for f in m.get('files',[])or[{'path':p,'sha256':h}for p,h in m['file_sha256'].items()]:scope[f['path']]=f
for p,f in scope.items():
 q=ROOT/p;assert(sha(q.read_bytes())if q.exists()else None)==f.get('sha256'),'Accepted source changed: '+p
head=git('rev-parse','HEAD').decode().strip();shared=ROOT/git('rev-parse','--git-path','index').decode().strip();ih=sha(shared.read_bytes())
base='00b8d6c3f538b0f05745bc721dd42b2bb0d40baf'
assert git('rev-parse',base+'^{tree}').decode().strip()=='dc55e078c77128d6e63df52777f0cdb77060e5dc'
matches=json.loads((E/'published-source-matches.json').read_text());matches.update(json.loads((E/'010-source-matches.json').read_text()));preserved={}
fd,idx=tempfile.mkstemp(prefix='r47-release-index-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 git('read-tree',base,env=env)
 # Inventory's deployed shared Vue components are bundled into cashier too.
 # Preserve their canonical accepted revision before applying this round.
 for raw in git('diff','--name-only','-z','fa2f3425dfdd5f5a274d9fdca5bc4e2647f74d9f','bbc6d9d97eb9bfcf01d308ecb6d515afbfef613a').decode().split('\0'):
  if raw.startswith(('前端代码/inventory-vue3/','后端代码/app/services/product/inventory/','tests/inventory/')):
   store(raw,git('show','bbc6d9d97eb9bfcf01d308ecb6d515afbfef613a:'+raw),env)
 # Preserve these deployed backend files from exact canonical commits, never server source.
 for p,match in matches.items():
  c=match['canonicalMatches'][0];data=git('show',c+':'+p)
  assert sha(data)==match['live'];store(p,data,env);preserved[p]={'commit':c,'sha256':sha(data),'liveHashes':{site:json.loads((E/(site+'-before.json')).read_text())['backend'].get(p[len('后端代码/'):]) for site in ['rh','008','010']}}
 baseline=git('write-tree',env=env).decode().strip()
 for name,patch in sets:
  raw=re.sub(br'^diff --git .*\n',b'',(E.parent/name/patch).read_bytes(),flags=re.M)
  for block in re.split(br'(?=^--- )',raw,flags=re.M):
   if not block.strip():continue
   old,new=block.decode().splitlines()[:2];path=(new[4:]if new[4:]!='/dev/null'else old[4:])[2:]
   def once(text,a,b):
    assert text.count(a)==1,'Scoped anchor ambiguous: '+path+' '+a
    return text.replace(a,b)
   if name==sets[1][0] and path.endswith('CashierV3DisplayPolicyServices.php'):
    text=git('show',':'+path,env=env).decode()
    text=once(text,"            'laborFeeVisible' =>", "            // New purchases use this one shared fulfillment setting.\n            'projectAutoComplete' => \\app\\services\\cashier\\v3\\settlement\\CashierV3ProjectPurchasePolicy::enabled(),\n            'laborFeeVisible' =>")
    store(path,text.encode(),env)
   elif name==sets[1][0] and path.endswith('CashierV3RootDomainAssembler.php'):
    text=git('show',':'+path,env=env).decode()
    text=once(text,"            'storeDisplayOptions' => [", "            'storeDisplayOptions' => [\n                // Same policy as the display snapshot; no separate checkout configuration.\n                'projectAutoComplete' => \\app\\services\\cashier\\v3\\settlement\\CashierV3ProjectPurchasePolicy::enabled(),")
    store(path,text.encode(),env)
   elif name==sets[1][0] and path.endswith('cashierV3Bridge.js'):
    text=git('show',':'+path,env=env).decode()
    text=once(text,'storeDisplayOptions: { preSaleEnabled:', 'storeDisplayOptions: { projectAutoComplete: true, preSaleEnabled:')
    text=once(text,'    storeDisplayOptions: {\n','    storeDisplayOptions: {\n      // Missing settings keep the original immediate project fulfillment.\n      projectAutoComplete: raw.storeDisplayOptions?.projectAutoComplete !== false,\n')
    store(path,text.encode(),env)
   else:
    if name==sets[1][0] and path.endswith('MemberSelectorOverlay.vue'):
     # Keep the deployed fragment-search wording; add only required-field guidance.
     block=block.replace('完整手机号或会员编号'.encode(),'手机号片段或会员编号片段'.encode())
    x=subprocess.run(['git','apply','--cached','--whitespace=nowarn','-'],cwd=ROOT,env=env,input=block,capture_output=True)
    if x.returncode:print(x.stderr.decode());raise RuntimeError('Scoped patch conflict: '+name+' '+path)
 # Release tools are canonical engineering sources, included for repeatable rollback and verification.
 for helper in sorted((ROOT/'scripts').glob('r47-round-*')):
  if helper.is_file() and helper.suffix in ['.php','.py','.cjs']:
   rel=str(helper.relative_to(ROOT));data=helper.read_bytes();scope[rel]={'path':rel,'sha256':sha(data),'group':'engineering'};store(rel,data,env)
 tree=git('write-tree',env=env).decode().strip();git('diff','--check',baseline,tree)
 paths=[p for p in git('diff','--no-renames','--name-only','-z',baseline,tree).decode().split('\0')if p];assert set(paths).issubset(scope),'Foreign source change'
 changed=[]
 for p in scope:
  try:data=git('show',tree+':'+p,stderr=subprocess.DEVNULL)
  except subprocess.CalledProcessError:data=None
  q=ROOT/p;working=q.read_bytes()if q.exists()else None
  if data!=working:changed.append({'path':p,'candidate':sha(data)if data else None,'acceptedWorking':sha(working)if working else None})
 plan={'canonicalGit':str(ROOT),'head':head,'baselineCommit':base,'baselineTree':baseline,'candidateTree':tree,'preserved':preserved,'paths':paths,'files':scope,'differentFromAcceptedWorking':changed,'sharedIndexSha256':ih,'sites':['rh','008','010'],'migrationAuthorization':False,'commitCreated':False}
 (E/'candidate.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2)+'\n');(E/'round-only.patch').write_bytes(git('diff','--binary',baseline,tree));assert sha(shared.read_bytes())==ih
 print(json.dumps({'tree':tree,'scope':len(paths),'differentFromAcceptedWorking':changed},ensure_ascii=False))
finally:Path(idx).unlink(missing_ok=True)
