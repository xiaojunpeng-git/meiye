"""Install the accepted employee dashboard/export tree with per-instance guards and rollback archives."""
import pathlib,subprocess,json,hashlib,io,tarfile,shlex,sys
R=pathlib.Path(__file__).resolve().parents[1];E=R/'tests/employee-dashboard-drilldown/evidence/release'
S={'rh':('rh-server','ruihao','/etc/init.d/ruihao_swoole restart'),'008':('mohe-server','mohe_008','systemctl restart mohe-008-swoole.service'),'010':('mohe-server','mohe_010_app','systemctl restart mohe-010-swoole.service')}
def read(n):return json.loads((E/n).read_text())
def save(n,x):(E/n).write_text(json.dumps(x,ensure_ascii=False,indent=2)+'\n')
def sha(b):return hashlib.sha256(b).hexdigest()
def git(*a):return subprocess.check_output(['git',*a],cwd=R)
def php(site,body):
 host,db,_=S[site];root='/www/wwwroot/'+site+'.cc3798.com'
 code='<?php chdir('+json.dumps(root)+");require 'vendor/autoload.php';(new think\\App())->initialize();if(config('database.connections.mysql.database')!=="+json.dumps(db)+")throw new Exception('WRONG_INSTANCE');\n"+body
 p=subprocess.run(['ssh',host,'php'],input=code,text=True,capture_output=True)
 if p.returncode:raise RuntimeError(p.stderr+p.stdout)
 return json.loads(p.stdout)
def payload():
 c=read('unified-candidate.json');tree=c['tree'];d=pathlib.Path(c['buildInput']);out={}
 for p in read('rh-backend-before.json'):out[p]=git('show',tree+':后端代码/'+p)
 for folder,prefix in [(d/'compiled-admin','public/'),(d/'compiled-cashier','public/view_cashier_v3/')]:
  for f in folder.rglob('*'):
   if not f.is_file():continue
   rel=f.relative_to(folder).as_posix()
   if prefix=='public/' and rel!='system.html' and not rel.startswith('view_admin/'):continue
   out[prefix+rel]=f.read_bytes()
 return out
def probe(site,paths):
 paths=list(set(paths+['public/system.html','public/view_cashier_v3/index.html','public/view_inventory_v3/index.html','public/view_mobile_v3/index.html','public/view_fund_v3/index.html']))
 return php(site,'$files=[];foreach('+json.dumps(paths)+" as $p)$files[$p]=is_file($p)?hash_file('sha256',$p):null;$menus=think\\facade\\Db::name('system_menus')->whereIn('unique_auth',['setting-staff-index','admin-organizational-structure'])->where('type',1)->where('is_del',0)->field('id,pid,menu_name,menu_path,path,is_show,header')->select()->toArray();echo json_encode(['files'=>$files,'environment'=>hash_file('sha256','.env'),'menus'=>$menus]);")
def prepare(site):
 out=payload();now=probe(site,list(out));old=read(site+'-backend-before.json')
 for p,h in old.items():assert now['files'].get(p)==h,'LIVE_BACKEND_CHANGED '+site+' '+p
 for p,h in read(site+'-before.json').items():
  if p.startswith('public/'):assert now['files'].get(p)==h,'LIVE_FRONTEND_CHANGED '+site+' '+p
 selected={p:b for p,b in out.items()if now['files'].get(p)!=sha(b)}
 for p in selected:
  if p.startswith('public/') and p not in ['public/system.html','public/view_cashier_v3/index.html'] and now['files'].get(p):raise RuntimeError('IMMUTABLE_ASSET_COLLISION '+p)
 save(site+'-preinstall.json',now);save(site+'-manifest.json',{p:sha(b)for p,b in selected.items()})
 with tarfile.open(E/(site+'-payload.tar.gz'),'w:gz')as t:
  for p,b in selected.items():
   f=tarfile.TarInfo(p);f.size=len(b);f.mode=0o644;t.addfile(f,io.BytesIO(b))
 return {'site':site,'files':len(selected),'backend':[p for p in selected if not p.startswith('public/')]}
def install(site):
 host,db,restart=S[site];before=read(site+'-preinstall.json');assert probe(site,list(before['files']))==before,'LIVE_CHANGED_BEFORE_SWITCH'
 m=read(site+'-manifest.json');commit=read('commit.json')['commit'];backup='/www/backup/employee-dashboard-20261106-'+commit[:8]+'-'+site;root='/www/wwwroot/'+site+'.cc3798.com';remote='/tmp/employee-dashboard-'+site+'.tar.gz'
 existing=[p for p in m if before['files'].get(p)]
 subprocess.run(['scp',str(E/(site+'-payload.tar.gz')),host+':'+remote],check=True,capture_output=True)
 cmd='set -e\numask 077\nmkdir '+backup+'\ncd '+root+'\ntar -czf '+backup+'/before.tar.gz -- '+' '.join(map(shlex.quote,existing))+'\ntest -s '+backup+'/before.tar.gz\nmkdir '+backup+'/payload\ntar -xzf '+remote+' -C '+backup+'/payload\n'
 for p in m:
  if p.endswith('.php'):cmd+='php -l '+shlex.quote(backup+'/payload/'+p)+'\n'
 r=subprocess.run(['ssh',host,'bash','-s'],input=cmd,text=True,capture_output=True);(E/(site+'-stage.log')).write_text(r.stdout+r.stderr);assert r.returncode==0,'STAGING_FAILED'
 # Menu backup is private and per-instance; grant sets are never changed by this release.
 saved=php(site,"$rows=think\\facade\\Db::name('system_menus')->where('type',1)->select()->toArray();file_put_contents("+json.dumps(backup+'/menus-before.json')+",json_encode($rows));chmod("+json.dumps(backup+'/menus-before.json')+",0600);echo json_encode(['backup'=>"+json.dumps(backup)+"]);")
 cmd='set -e\n'
 for p in sorted(m,key=lambda p:p in ['public/system.html','public/view_cashier_v3/index.html']):
  dest=root+'/'+p;cmd+='mkdir -p '+shlex.quote(str(pathlib.PurePosixPath(dest).parent))+'\ncp '+shlex.quote(backup+'/payload/'+p)+' '+shlex.quote(dest+'.employee-new')+'\nchmod 644 '+shlex.quote(dest+'.employee-new')+'\nmv '+shlex.quote(dest+'.employee-new')+' '+shlex.quote(dest)+'\n'
 r=subprocess.run(['ssh',host,'bash','-s'],input=cmd,text=True,capture_output=True);(E/(site+'-install.log')).write_text(r.stdout+r.stderr);assert r.returncode==0,'INSTALL_FAILED rollback '+backup
 migration=php(site,"$grants=function(){return hash('sha256',json_encode([think\\facade\\Db::name('system_role')->order('id')->select()->toArray(),think\\facade\\Db::name('job_position_channel_rule')->order('id')->select()->toArray()]));};$before=$grants();$m=require 'database/upgrades/20261106_platform_staff_management_menu.php';$r=think\\facade\\Db::transaction(function()use($m,$grants,$before){$r=$m();if($before!==$grants())throw new Exception('GRANTS_CHANGED');return $r;});echo json_encode($r);")
 save(site+'-migration.json',migration)
 r=subprocess.run(['ssh',host,restart],text=True,capture_output=True);(E/(site+'-restart.log')).write_text(r.stdout+r.stderr);assert r.returncode==0,'RESTART_FAILED rollback '+backup
 after=probe(site,list(before['files']));save(site+'-after.json',after)
 for p,h in m.items():assert after['files'][p]==h,p
 for p,h in before['files'].items():
  if p not in m:assert after['files'][p]==h,'PROTECTED_CHANGED '+p
 assert after['environment']==before['environment'];save(site+'-installed.json',{'commit':commit,'backup':backup,'fingerprints':'PASS','configurationUnchanged':True,'menuMigration':migration});return {'site':site,'backup':backup,'fingerprints':'PASS'}
if __name__=='__main__':print(json.dumps((prepare if sys.argv[1]=='prepare'else install)(sys.argv[2]),ensure_ascii=False))
