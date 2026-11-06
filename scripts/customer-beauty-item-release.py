"""R52: deploy only accepted report files/assets; isolate sites and retain rollback.
Read-only smoke compares existing fact readers before/after and verifies grouped
query/export equality. It never changes business data, permissions or config.
"""
import pathlib, subprocess, json, hashlib, tarfile, io, shlex, sys, urllib.request
R=pathlib.Path(__file__).resolve().parents[1]
E=R.parent/'任务管理/第52轮-客户品相分析/release'
S={'rh':('rh-server','ruihao','/etc/init.d/ruihao_swoole restart'),'008':('mohe-server','mohe_008','systemctl restart mohe-008-swoole.service'),'010':('mohe-server','mohe_010_app','systemctl restart mohe-010-swoole.service')}
def read(n): return json.loads((E/n).read_text())
def save(n,x): (E/n).write_text(json.dumps(x,ensure_ascii=False,indent=2)+'\n')
def git(*a): return subprocess.check_output(['git',*a],cwd=R)
def sha(b): return hashlib.sha256(b).hexdigest()
def php(site,body):
 host,db,_=S[site]; root='/www/wwwroot/'+site+'.cc3798.com'
 code='<?php chdir('+json.dumps(root)+");require 'vendor/autoload.php';(new think\\App())->initialize();if(config('database.connections.mysql.database')!=="+json.dumps(db)+")throw new Exception('WRONG_INSTANCE');\n"+body
 p=subprocess.run(['ssh',host,'sudo -u www php'],input=code,text=True,capture_output=True,timeout=100)
 if p.returncode: raise RuntimeError(p.stderr+p.stdout)
 return json.loads(p.stdout)
def probe(site,paths):
 return php(site,'$files=[];foreach('+json.dumps(list(paths))+" as $p)$files[$p]=is_file($p)?hash_file('sha256',$p):null;echo json_encode(['files'=>$files,'environment'=>hash_file('sha256','.env')]);")
def payload():
 c=read('candidate.json'); out={}
 for p in c['files']:
  if p.startswith('后端代码/'): out[p.removeprefix('后端代码/')]=git('show',c['tree']+':'+p)
 for f in (pathlib.Path(c['buildInput'])/'compiled-cashier').rglob('*'):
  if f.is_file(): out['public/view_cashier_v3/'+f.relative_to(pathlib.Path(c['buildInput'])/'compiled-cashier').as_posix()]=f.read_bytes()
 return out
def smoke(site,phase):
 body=r'''
ini_set('memory_limit','512M');
think\facade\Db::execute('SET TRANSACTION READ ONLY');think\facade\Db::startTrans();
try {
 $stores=array_map('intval',think\facade\Db::name('system_store')->where('is_del',0)->order('id')->limit(1)->column('id'));
 $range=['start'=>'2026-10-01','end'=>'2026-10-01']; $reader=new app\services\report\StoreUnifiedReportPhaseFourServices();
 $factHashes=[];foreach(['cashRows','serviceRows'] as $name){$m=new ReflectionMethod($reader,$name);$m->setAccessible(true);$rows=$m->invoke($reader,$stores,$range,[]);$encoded=array_map(fn($r)=>json_encode($r),$rows);sort($encoded);$factHashes[$name]=['count'=>count($rows),'sha'=>hash('sha256',json_encode($encoded))];}
 $result=['stores'=>$stores,'range'=>$range,'existingReaders'=>$factHashes];
'''
 if phase=='after': body+=r'''
 $start=microtime(true);$s=new app\services\report\CustomerAnalyticsServices();$r=$s->read('customer_item_analysis',$stores,$range,[],true);$x=$s->export('customer_item_analysis',$stores,$range,[]);
 try{if($r['records']!==$x['records']||$r['summary_row']!==$x['summary_row']||$r['columns']!==$x['columns'])throw new Exception('EXPORT_MISMATCH');$zip=new ZipArchive();if($zip->open($x['path'])!==true)throw new Exception('INVALID_XLSX');$zip->close();}finally{unlink($x['path']);}
 $names=array_column($r['records'],'item_name');if(count($names)!==count(array_unique($names)))throw new Exception('DUPLICATE_NAMES');if(strpos($r['metric_version'],'beauty-item-name-v1')===false)throw new Exception('WRONG_METRIC');
 $result['report']=['records'=>count($names),'metricVersion'=>$r['metric_version'],'summary'=>$r['summary_row'],'queryExportEqual'=>true,'xlsx'=>true,'seconds'=>round(microtime(true)-$start,3)];
'''
 body+="echo json_encode($result,JSON_UNESCAPED_UNICODE); } finally {think\\facade\\Db::rollback();}"
 result=php(site,body);save(site+'-smoke-'+phase+'.json',result)
 if phase=='after': assert result['existingReaders']==read(site+'-smoke-before.json')['existingReaders'],'EXISTING_FACT_READERS_CHANGED'
 return result

def prepare(site):
 out=payload(); old=read(site+'-before.json'); now=probe(site,set(out)|set(old['files']))
 for p,h in old['files'].items(): assert now['files'][p]==h,'LIVE_CHANGED '+site+' '+p
 assert old['environment']==now['environment'],'CONFIG_CHANGED'
 selected={p:b for p,b in out.items() if now['files'][p]!=sha(b)}
 for p in selected:
  if p.startswith('public/') and p!='public/view_cashier_v3/index.html': assert now['files'][p] is None,'ASSET_COLLISION '+p
 save(site+'-preinstall.json',now);save(site+'-manifest.json',{p:sha(b) for p,b in selected.items()})
 with tarfile.open(E/(site+'-payload.tar.gz'),'w:gz') as t:
  for p,b in selected.items():
   f=tarfile.TarInfo(p);f.size=len(b);f.mode=0o644;t.addfile(f,io.BytesIO(b))
 smoke(site,'before'); return {'site':site,'files':len(selected),'preflight':'PASS'}
def install(site):
 host,_,restart=S[site]; before=read(site+'-preinstall.json'); assert probe(site,before['files'])==before,'LIVE_CHANGED_BEFORE_SWITCH'
 m=read(site+'-manifest.json'); commit=read('commit.json')['commit']; root='/www/wwwroot/'+site+'.cc3798.com'; backup='/www/backup/r52-beauty-item-'+commit[:8]+'-'+site; remote='/tmp/r52-beauty-item-'+site+'.tar.gz'
 subprocess.run(['scp',str(E/(site+'-payload.tar.gz')),host+':'+remote],check=True,capture_output=True)
 existing=[p for p in m if before['files'][p]]
 cmd='set -e\numask 077\nmkdir '+backup+'\ncd '+root+'\ntar -czf '+backup+'/before.tar.gz -- '+' '.join(map(shlex.quote,existing))+'\ntest -s '+backup+'/before.tar.gz\nmkdir '+backup+'/payload\ntar -xzf '+remote+' -C '+backup+'/payload\n'
 for p in m:
  if p.endswith('.php'): cmd+='php -l '+shlex.quote(backup+'/payload/'+p)+'\n'
 rollback='set -e\ncd '+root+'\ntar -xzf '+backup+'/before.tar.gz\n'+restart+'\n'
 (E/(site+'-rollback.sh')).write_text(rollback)
 r=subprocess.run(['ssh',host,'bash','-s'],input=cmd,text=True,capture_output=True);(E/(site+'-stage.log')).write_text(r.stdout+r.stderr);assert r.returncode==0,'STAGING_FAILED'
 assert probe(site,before['files'])==before,'LIVE_CHANGED_DURING_STAGE'
 cmd='set -e\n'
 for p in sorted(m,key=lambda p:p=='public/view_cashier_v3/index.html'):
  dest=root+'/'+p;cmd+='mkdir -p '+shlex.quote(str(pathlib.PurePosixPath(dest).parent))+'\ncp '+shlex.quote(backup+'/payload/'+p)+' '+shlex.quote(dest+'.r52-new')+'\nchown www:www '+shlex.quote(dest+'.r52-new')+'\nchmod 644 '+shlex.quote(dest+'.r52-new')+'\nmv '+shlex.quote(dest+'.r52-new')+' '+shlex.quote(dest)+'\n'
 cmd+=restart+'\n'
 r=subprocess.run(['ssh',host,'bash','-s'],input=cmd,text=True,capture_output=True);(E/(site+'-install.log')).write_text(r.stdout+r.stderr);assert r.returncode==0,'INSTALL_FAILED rollback '+backup
 after=probe(site,before['files']);save(site+'-after.json',after)
 for p,h in m.items():assert after['files'][p]==h,p
 for p,h in before['files'].items():
  if p not in m:assert after['files'][p]==h,'PROTECTED_CHANGED '+p
 assert after['environment']==before['environment'];smoke(site,'after')
 # Verify the public entry and its content-hashed JS/CSS, not only disk hashes.
 public={}
 for p in m:
  if p.endswith(('.js','.css','.html')):
   url='https://'+site+'.cc3798.com/'+p.removeprefix('public/')
   b=urllib.request.urlopen(url,timeout=30).read();assert sha(b)==m[p],'PUBLIC_HASH '+p;public[p]=sha(b)
 save(site+'-public.json',public)
 result={'site':site,'commit':commit,'backup':backup,'fingerprints':'PASS','businessSmoke':'PASS','configurationUnchanged':True,'databaseMigration':False};save(site+'-installed.json',result);return result
if __name__=='__main__':print(json.dumps((prepare if sys.argv[1]=='prepare' else install)(sys.argv[2]),ensure_ascii=False))
