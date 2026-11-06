"""DATA-R5 scoped deployment: immutable payload, per-instance backups, drift guards and config-only migration."""
import pathlib,subprocess,json,hashlib,io,tarfile,shlex,sys,importlib.util,urllib.request,re,urllib.parse
R=pathlib.Path(__file__).resolve().parents[1];E=R/'tests/platform-document-edit-switch/evidence/release'
spec=importlib.util.spec_from_file_location('previous',R/'scripts/employee-dashboard-release.py');previous=importlib.util.module_from_spec(spec);spec.loader.exec_module(previous)
S=previous.S;php=previous.php
NOTIFY='app/services/cashier/v3/settlement/CashierV3CheckoutMemberNotificationServices.php'
PROTECTED=['public/system.html','public/view_inventory_v3/index.html','public/view_mobile_v3/index.html','public/view_fund_v3/index.html',NOTIFY]
def read(n):return json.loads((E/n).read_text())
def save(n,x):(E/n).write_text(json.dumps(x,ensure_ascii=False,indent=2)+'\n')
def sha(b):return hashlib.sha256(b).hexdigest()
def git(*a):return subprocess.check_output(['git',*a],cwd=R)
def payload():
 c=read('candidate.json');assert c['tree']=='05bfad481c8adb9152ab582c7f9843aff923ed65';out={}
 for p in read('rh-before.json')['files']:
  if not p.startswith('public/'):out[p]=git('show',c['releaseCommit']+':后端代码/'+p)
 d=pathlib.Path(c['buildInput'])/'compiled-cashier'
 for p,h in c['buildFiles'].items():
  b=(d/p).read_bytes();assert sha(b)==h,p;out['public/view_cashier_v3/'+p]=b
 return out
def probe(site,paths):
 return php(site,'$files=[];foreach('+json.dumps(sorted(set(paths+PROTECTED)))+" as $p)$files[$p]=is_file($p)?hash_file('sha256',$p):null;echo json_encode(['files'=>$files,'environment'=>hash_file('sha256','.env'),'config'=>think\\facade\\Db::name('system_config')->where('menu_name','platform_document_edit_enabled')->where('is_store',0)->field('id,value')->select()->toArray()]);")
def prepare(site):
 out=payload();before=read(site+'-before.json');now=probe(site,list(out))
 for p,h in before['files'].items():assert now['files'].get(p)==h,'LIVE_CHANGED '+site+' '+p
 assert now['config']==before['config'];assert now['files'][NOTIFY]=='7284be5ee086258e02eb38eda9a9a56173e4d76a03b26382296ea45428af625b'
 selected={p:b for p,b in out.items() if now['files'][p]!=sha(b)}
 for p in selected:
  if p.startswith('public/')and p!='public/view_cashier_v3/index.html'and now['files'][p]:raise RuntimeError('IMMUTABLE_ASSET_COLLISION '+p)
 save(site+'-preinstall.json',now);save(site+'-manifest.json',{p:sha(b)for p,b in selected.items()})
 with tarfile.open(E/(site+'-payload.tar.gz'),'w:gz')as t:
  for p,b in selected.items():f=tarfile.TarInfo(p);f.size=len(b);f.mode=0o644;t.addfile(f,io.BytesIO(b))
 return {'site':site,'changedFiles':len(selected),'backend':[p for p in selected if not p.startswith('public/')]}
def shell(site,command,log):
 r=subprocess.run(['ssh',S[site][0],'bash','-s'],input='set -e\n'+command,text=True,capture_output=True);(E/(site+'-'+log+'.log')).write_text(r.stdout+r.stderr);assert not r.returncode,'FAILED '+log+' '+r.stderr[-500:]
def install(site):
 before=read(site+'-preinstall.json');assert probe(site,list(before['files']))==before,'DRIFT_BEFORE_SWITCH'
 c=read('candidate.json');m=read(site+'-manifest.json');root='/www/wwwroot/'+site+'.cc3798.com';backup='/www/backup/data-r5-'+c['releaseCommit'][:8]+'-'+site;remote='/tmp/data-r5-'+site+'.tar.gz';host,db,restart=S[site]
 subprocess.run(['scp',str(E/(site+'-payload.tar.gz')),host+':'+remote],check=True,capture_output=True)
 existing=[p for p in m if before['files'][p]]
 cmd='umask 077\nmkdir '+backup+'\ncd '+root+'\ntar -czf '+backup+'/before.tar.gz -- '+' '.join(map(shlex.quote,existing))+'\ntest -s '+backup+'/before.tar.gz\nmkdir '+backup+'/payload\ntar -xzf '+remote+' -C '+backup+'/payload\n'
 for p in m:
  if p.endswith('.php'):cmd+='php -l '+shlex.quote(backup+'/payload/'+p)+'\n'
 shell(site,cmd,'stage')
 php(site,"$rows=think\\facade\\Db::name('system_config')->where('menu_name','platform_document_edit_enabled')->where('is_store',0)->select()->toArray();file_put_contents("+json.dumps(backup+'/config-before.json')+",json_encode($rows));chmod("+json.dumps(backup+'/config-before.json')+",0600);echo json_encode(['backedUp'=>true]);")
 # Recheck after staging; only files and the single new definition are in scope.
 assert probe(site,list(before['files']))==before,'DRIFT_AFTER_BACKUP'
 cmd=''
 for p in sorted(m,key=lambda p:p=='public/view_cashier_v3/index.html'):
  dest=root+'/'+p;cmd+='mkdir -p '+shlex.quote(str(pathlib.PurePosixPath(dest).parent))+'\ncp '+shlex.quote(backup+'/payload/'+p)+' '+shlex.quote(dest+'.r5-new')+'\nchmod 644 '+shlex.quote(dest+'.r5-new')+'\nmv '+shlex.quote(dest+'.r5-new')+' '+shlex.quote(dest)+'\n'
 shell(site,cmd,'install')
 result=php(site,"$db=think\\facade\\Db::class;$before=$db::name('system_config')->where('menu_name','<>','platform_document_edit_enabled')->order('id')->select()->toArray();$result=$db::transaction(function()use($db){$db::execute(file_get_contents('database/upgrades/20261106_platform_document_edit_switch.sql'));$row=$db::name('system_config')->where('menu_name','platform_document_edit_enabled')->where('is_store',0)->find();if(!$row)throw new Exception('CONFIG_MIGRATION_FAILED');return ['id'=>(int)$row['id'],'value'=>$row['value']];});if($before!==$db::name('system_config')->where('menu_name','<>','platform_document_edit_enabled')->order('id')->select()->toArray())throw new Exception('UNRELATED_CONFIG_CHANGED');\\mohe\\services\\SystemConfigService::clear();echo json_encode($result);")
 save(site+'-migration.json',result);shell(site,restart+'\n','restart')
 after=probe(site,list(before['files']));save(site+'-after.json',after)
 for p,h in m.items():assert after['files'][p]==h,'INSTALLED_HASH '+p
 for p,h in before['files'].items():
  if p not in m:assert after['files'][p]==h,'PROTECTED_CHANGED '+p
 assert after['environment']==before['environment'];assert result['value']=='1'
 save(site+'-installed.json',{'commit':c['releaseCommit'],'backup':backup,'files':len(m),'defaultEnabled':True,'protectedFilesUnchanged':True});return {'site':site,'backup':backup,'installed':True}
def verify(site):
 c=read('candidate.json');out=payload();http={};url='https://'+site+'.cc3798.com/view_cashier_v3/index.html?r5='+c['releaseCommit'][:8]
 b=urllib.request.urlopen(url,timeout=30).read();assert sha(b)==sha(out['public/view_cashier_v3/index.html']);http['index']=sha(b)
 for asset in re.findall(r'(?:src|href)="([^"]+\.(?:js|css))"',b.decode()):
  b=urllib.request.urlopen(urllib.parse.urljoin(url,asset),timeout=30).read();assert sha(b)==sha(out['public'+asset]);http[asset]=sha(b)
 result=php(site,(R/'scripts/platform-document-edit-release-verify.php').read_text().replace('<?php','',1));assert result['result']=='PASS';save(site+'-runtime.json',result)
 preserved=php(site,(R/'scripts/employee-dashboard-release-verify.php').read_text().replace('<?php','',1));assert preserved['result']=='PASS';save(site+'-preserved.json',preserved)
 save(site+'-verified.json',{'result':'PASS','commit':c['releaseCommit'],'publicAssets':http,'runtime':result,'preserved':preserved,'backup':read(site+'-installed.json')['backup']});return {'site':site,'result':'PASS','runtime':result,'preserved':preserved}
if __name__=='__main__':print(json.dumps({'prepare':prepare,'install':install,'verify':verify}[sys.argv[1]](sys.argv[2]),ensure_ascii=False))
