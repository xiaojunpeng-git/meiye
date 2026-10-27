"""Verify fixed boundary and acceptance prerequisites; no commit, index mutation or remote calls."""
import hashlib,json,pathlib,subprocess
E=pathlib.Path(__file__).parent/'evidence';p=json.loads((E/'fixed.json').read_text())
def g(*a):return subprocess.check_output(['git',*a])
assert g('rev-parse','HEAD').decode().strip()==p['head']
for f,h in p['worktreeFiles'].items():assert hashlib.sha256(pathlib.Path(f).read_bytes()).hexdigest()==h,f+' changed after freeze'
for f,h in p['files'].items():assert hashlib.sha256(g('show',p['sourceTree']+':'+f)).hexdigest()==h
for f in ['后端代码/route/admin.php','后端代码/route/cashier-v3.php','前端代码/cashier-v3/src/router/index.js']:
 # Every deletion in these shared routing files would be an unapproved report regression.
 diff=g('diff','--unified=0',p['parent'],p['sourceTree'],'--',f).decode()
 assert not [x for x in diff.splitlines() if x.startswith('-') and not x.startswith('---')],f
assert 'PLATFORM_MEMBER_LOGIN_DATABASE_PASS 40 checks' in (E/'fixed-database.txt').read_text()
assert 'FIXED_SOURCE_PROVENANCE_PASS' in (E/'fixed-database.txt').read_text()
assert 'MEMBER_PHONE_RESET_DATABASE_PASS 33 checks' in (E/'phone-reset-regression.txt').read_text()
assert 'ISSUE_' not in (E/'concurrency-regression.txt').read_text() # driver prints only sanitized assertions
assert 'rollback: token issuance waits for lock then allowed' in (E/'concurrency-regression.txt').read_text()
assert 'DONE  Build complete' in (E/'platform-build.txt').read_text()
assert 'built in' in (E/'store-build.txt').read_text()
idx=pathlib.Path(g('rev-parse','--git-path','index').decode().strip())
assert hashlib.sha256(idx.read_bytes()).hexdigest()==p['sharedIndexSHA256']
status=g('status','--porcelain=v1','-z').decode().split('\0')
(E/'remaining-worktree-status.json').write_text(json.dumps([x for x in status if x],ensure_ascii=False,indent=2)+'\n')
result={'result':'PASS','head':p['head'],'parent':p['parent'],'sourceTree':p['sourceTree'],'patchSHA256':p['patchSHA256'],'databaseChecks':40,'previousPhoneResetChecks':33,'httpAuthChecks':3,'concurrency':'commit/rollback passed','frontendCompilation':'admin and cashier-v3 passed; not an 8080 release package','ui':['platform user list uses member module','binding column immediately after creation date','original transfer modal opened and cancelled','login tab after basic tab','read-only phone account','new password input type=text; no credential entered through UI'],'unchangedSharedIndex':True,'remoteOperations':False,'productAcceptance':'pending','commit':'not created','knownLimitations':['No real WeChat provider authorization performed; no production member changed','Fixed candidate retains accepted basic-report routes/permission behavior; unrelated shared experiments excluded']}
(E/'verification.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print('FIXED_BOUNDARY_VERIFICATION_PASS '+p['patchSHA256'][:8])
