"""只在本地容器加载固定候选的只读类，验证发布树而非共享脏工作区；测试事务自行回滚。"""
import io,json,pathlib,shutil,subprocess,tarfile,tempfile,hashlib
ROOT=pathlib.Path(__file__).resolve().parents[2]
E=ROOT.parent/'_local_evidence/2026-11-03-round6-report-xlsx/release'
m=json.loads((E/'candidate-merge.json').read_text());tree=m['candidateTree']
def git(*args):return subprocess.check_output(['git',*args],cwd=ROOT)
folder=pathlib.Path(tempfile.mkdtemp(prefix='r6-fixed-',dir=ROOT/'后端代码/runtime'))
try:
    with tarfile.open(fileobj=io.BytesIO(git('archive',tree,'后端代码/app'))) as archive:
        archive.extractall(folder)
    backend=folder/'后端代码'
    for p in backend.rglob('*'):
        if p.is_file():p.chmod(0o444)
    inputs={str(p.relative_to(backend)):hashlib.sha256(p.read_bytes()).hexdigest() for p in backend.rglob('*') if p.is_file()}
    fixed='/var/www/html/runtime/'+folder.name+'/后端代码/'
    loader="\nspl_autoload_register(static function(string $class):void { $path="+json.dumps(fixed,ensure_ascii=False)+".str_replace('\\\\','/',$class).'.php'; if(is_file($path))require_once $path; }, true, true);\n"
    tests=[('tests/report-xlsx/php/r6-unified-workbook.php','PASS R6 unified XLSX checks=19',['mohe-platform-app']),
           ('tests/report-phase-six/php/r6-salary-detail-remark.php','PASS R6 salary detail remark checks=',['mohe-platform-app','mohe-cashier-app']),
           ('tests/report-phase-four/php/r73-unified-detail-xlsx.php','PASS R73 unified detail real XLSX',['mohe-platform-app']),
           ('tests/report-phase-four/php/r74-post-sale-member-day-local.php','PASS',['mohe-platform-app'])]
    results=[]
    for test,marker,containers in tests:
        code=git('show',tree+':'+test).decode() if test!='tests/report-phase-four/php/r74-post-sale-member-day-local.php' else (ROOT/test).read_text()
        lines=code.splitlines(True);position=next(i for i,line in enumerate(lines) if '/vendor/autoload.php' in line)
        lines.insert(position+1,loader);code=''.join(lines)
        for container in containers:
            result=subprocess.run(['docker','exec','-i','-e','CASHIER_V3_BACKEND_ROOT=/var/www/html',container,'php','/dev/stdin'],input=code.encode(),capture_output=True)
            log=result.stdout+result.stderr;(E/(pathlib.Path(test).stem+'-'+container+'.log')).write_bytes(log)
            ok=result.returncode==0 and marker.encode() in log and b'Fatal error' not in log and b'FAIL' not in log
            results.append({'test':test,'container':container,'pass':ok,'exit':result.returncode,'summary':log.decode(errors='replace').splitlines()[-1:]})
            print(test,container,'PASS' if ok else 'FAIL',results[-1]['summary'],flush=True)
    assert all(hashlib.sha256((backend/p).read_bytes()).hexdigest()==h for p,h in inputs.items())
    (E/'fixed-backend-tests.json').write_text(json.dumps({'tree':tree,'classes':len(inputs),'results':results,'readonlyFilesUnchanged':True},ensure_ascii=False,indent=2)+'\n')
    if not all(r['pass'] for r in results):raise RuntimeError('Inspect fixed backend test failure logs')
finally:shutil.rmtree(folder)
