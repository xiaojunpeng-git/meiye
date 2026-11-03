#!/usr/bin/env python3
"""R6从唯一正本固定Git树只读构建；不修改共享HEAD、索引或其他任务工作文件。"""
import hashlib,io,json,os,pathlib,shutil,subprocess,sys,tarfile,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
E=ROOT.parent/'_local_evidence/2026-11-03-round6-report-xlsx/release'
def sha(b):return hashlib.sha256(b).hexdigest()
def git(*args):return subprocess.check_output(['git',*args],cwd=ROOT)
def save(name,value):(E/name).write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
def build(terminal,variant):
    m=json.loads((E/'candidate-merge.json').read_text());tree=m[variant+'Tree']
    projects=['前端代码/cashier-v3','前端代码/inventory-vue3','前端代码/shared','tests/cashier-v3/js']
    with tempfile.TemporaryDirectory(prefix='r6-readonly-'+terminal+'-') as temporary:
        snapshot=pathlib.Path(temporary)
        with tarfile.open(fileobj=io.BytesIO(git('archive',tree,*projects))) as archive:archive.extractall(snapshot)
        # 主线历史未携带R5的两份回归文件，使用唯一Git正本中已验收提交的只读测试；不补入业务源码。
        retained_ref='042e9e783a9d3618760db0f4470a93a23faa9a05'
        for test in ['receipt-personnel-display-contract.mjs','receipt-post-writeoff-times-contract.mjs','entitlement-experience-checkout-snapshot.mjs']:
            target=snapshot/'tests/cashier-v3/js'/test
            if not target.exists():target.write_bytes(git('show',retained_ref+':tests/cashier-v3/js/'+test))
        inputs={str(p.relative_to(snapshot)):sha(p.read_bytes()) for p in snapshot.rglob('*') if p.is_file()}
        for project in projects:
            modules=ROOT/project/'node_modules';target=snapshot/project/'node_modules'
            if modules.is_dir() and not target.exists():target.symlink_to(modules,target_is_directory=True)
        for p in snapshot.rglob('*'):
            if p.is_file() and not p.is_symlink() and 'node_modules' not in p.parts:p.chmod(0o444)
        app=snapshot/'前端代码/cashier-v3'
        output=E/(terminal+'-'+variant+'-dist');log=E/(terminal+'-'+variant+'-build.log')
        env={**os.environ,'NODE_OPTIONS':'--max-old-space-size=1536','ANALYZE':'false','BROWSERSLIST_IGNORE_OLD_DATA':'true'}
        command=['node',str(app/'node_modules/vite/bin/vite.js'),'build','--outDir',str(output),'--emptyOutDir']
        if variant=='candidate':
            # 共用包必须保留原小票/权益快照；这些现成回归使用同一固定输入，不扫描共享脏工作区。
            for test in ['receipt-personnel-display-contract.mjs','receipt-post-writeoff-times-contract.mjs','entitlement-experience-checkout-snapshot.mjs']:
                checked=subprocess.run(['node','tests/cashier-v3/js/'+test],cwd=snapshot,env=env,capture_output=True)
                (E/('retained-'+test+'.log')).write_bytes(checked.stdout+checked.stderr)
                if checked.returncode:raise RuntimeError('Retained feature check failed: '+test)
        with log.open('w') as stream:result=subprocess.run(command,cwd=app,env=env,stdout=stream,stderr=subprocess.STDOUT)
        if result.returncode:raise RuntimeError('Build failed: '+str(log))
        assert all(sha((snapshot/p).read_bytes())==digest for p,digest in inputs.items())
        files={str(p.relative_to(output)):sha(p.read_bytes()) for p in output.rglob('*') if p.is_file()}
        save(terminal+'-'+variant+'-build.json',{'tree':tree,'terminal':terminal,'variant':variant,'inputFingerprints':inputs,'files':files,'result':'PASS','source':'canonical Git tree readonly export; no editable source copy','retainedTestSource':retained_ref})
        print(terminal,variant,'BUILD_PASS',len(inputs),'inputs',len(files),'artifacts',flush=True)
if __name__=='__main__':
    terminal,variant=sys.argv[1:];assert terminal=='cashier';assert variant in ['baseline','candidate'];build(terminal,variant)
