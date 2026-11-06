"""Prepare a traced release tree preserving the deployed baseline; never edit shared sources/index."""
import subprocess,pathlib,os,json,hashlib,tempfile,tarfile,io
R=pathlib.Path(__file__).resolve().parents[1];os.chdir(R);E=R/'tests/platform-document-edit-switch/evidence/release'
def g(*a,input=None,env=None):return subprocess.check_output(['git',*a],input=input,env=env)
base='16eba79a7';commit='185447d01ff47a19eacfd1308e33494a2a5a8d41';idx=tempfile.mktemp(prefix='r5-release-index-');env=dict(os.environ,GIT_INDEX_FILE=idx)
g('read-tree',base,env=env)
p='后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php';blob=g('rev-parse','d38109fe5:'+p).decode().strip();g('update-index','--cacheinfo','100644',blob,p,env=env)
for p,ref in [('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutMemberNotificationServices.php','6b85d3f1'),('tests/customer-010/round13-checkout-notification.php','6b85d3f1')]:
 blob=g('rev-parse',ref+':'+p).decode().strip();g('update-index','--add','--cacheinfo','100644',blob,p,env=env)
patch=g('diff','--binary',commit+'^',commit,'--','前端代码','后端代码','tests/platform-document-edit-switch',':(exclude)后端代码/app/controller/admin/v1/system/config/SystemConfig.php')
g('apply','--cached','--check','-',input=patch,env=env);g('apply','--cached','-',input=patch,env=env)
p='后端代码/app/controller/admin/v1/system/config/SystemConfig.php'
old=g('show',base+':'+p).decode();accepted=g('show',commit+':'+p).decode();start=accepted.index('        // 定义缺失或非二值参数');end=accepted.index('        // 排班已迁至',start);block=accepted[start:end];anchor='        $post = $this->request->post();\n';assert old.count(anchor)==1;merged=old.replace(anchor,anchor+block);blob=g('hash-object','-w','--stdin',input=merged.encode()).decode().strip();g('update-index','--cacheinfo','100644',blob,p,env=env)
tree=g('write-tree',env=env).decode().strip();d=pathlib.Path(tempfile.mkdtemp(prefix='r5-fixed-build-'))
paths=['前端代码/cashier-v3','前端代码/inventory-vue3','前端代码/shared','tests/cashier-v3/js','tests/platform-document-edit-switch']
with tarfile.open(fileobj=io.BytesIO(g('archive',tree,*paths))) as t:t.extractall(d)
for project in ['cashier-v3','inventory-vue3']:
 p=d/'前端代码'/project/'node_modules';p.symlink_to(R/'前端代码'/project/'node_modules',target_is_directory=True)
for p in d.rglob('*'):
 if p.is_file()and not p.is_symlink():p.chmod(0o444)
record={'base':base,'tree':tree,'patchCommit':commit,'preservedGatewaySource':'d38109fe5d7686052fa2c579f95d87ec40c7da3f','buildInput':str(d),'status':'preserve deployed notification/dashboard baseline; pending supplemental acceptance','patchSHA256':hashlib.sha256(patch).hexdigest()}
(E/'candidate.json').write_text(json.dumps(record,indent=2)+'\n');print(json.dumps(record))
