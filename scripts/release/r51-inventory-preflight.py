"""Read-only site-bound R51 schema and release fingerprints; never returns credentials or business rows."""
import subprocess,json,pathlib,sys
ROOT=pathlib.Path(__file__).resolve().parents[2]
E=ROOT.parent/'任务管理/验收证据/R51-库存发布-2026-11-06'
site=sys.argv[1]
assert site in ['rh','008','010']
host='rh-server' if site=='rh' else 'mohe-server'
root='/www/wwwroot/'+site+'.cc3798.com'
db='ruihao' if site=='rh' else 'mohe_008' if site=='008' else 'mohe_010_app'
port='3306' if site=='rh' else '3307'
def ssh(command,data):return subprocess.check_output(['ssh','-q','-o','BatchMode=yes','-o','ConnectTimeout=12',host,command],input=data.encode(),stderr=subprocess.PIPE)
code=r'''import pathlib,hashlib,json,re
r=pathlib.Path(ROOT);out={'files':{},'front':{},'releaseFiles':[]}
for directory in ['app/services/product/inventory','app/controller/store/product/inventory','app/controller/admin/v1/product/inventory']:
 for p in (r/directory).rglob('*.php'):
  out['files'][str(p.relative_to(r))]=hashlib.sha256(p.read_bytes()).hexdigest()
for name in ['view_cashier_v3','view_inventory_v3']:
 d=r/'public'/name;h=(d/'index.html').read_text(encoding='utf-8');assets={}
 for p in (d/'assets').glob('*'):
  if p.is_file():assets['assets/'+p.name]=hashlib.sha256(p.read_bytes()).hexdigest()
 out['front'][name]={'html':hashlib.sha256(h.encode()).hexdigest(),'entry':re.findall(r'(?:src|href)="([^" ]+\.(?:js|css))"',h),'assets':assets}
 out['releaseFiles']+=list(str(p.relative_to(r)) for p in d.glob('*manifest*'))
print(json.dumps(out))
'''.replace('ROOT',repr(root))
result=json.loads(ssh('python3 -',code))
php=r'''<?php
$r=ROOT;chdir($r);require $r.'/vendor/autoload.php';(new think\App($r.'/'))->initialize();
$c=config('database.connections.mysql');if($c['database']!==DB || (string)$c['hostport']!==PORT)throw new RuntimeException('INSTANCE_MISMATCH');
$p=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec('SET TRANSACTION READ ONLY');$p->beginTransaction();
try {$o=['database'=>$c['database'],'port'=>$c['hostport']];
$o['columns']=$p->query("SELECT column_name,column_type,column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_cross_transfer_document' AND column_name IN ('created_by_operator_id','created_by_operator_type')")->fetchAll(PDO::FETCH_ASSOC);
$o['upgrade']=$p->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($o,JSON_THROW_ON_ERROR);}finally{$p->rollBack();}
'''.replace('ROOT',json.dumps(root)).replace('DB',json.dumps(db)).replace('PORT',json.dumps(port))
result['schema']=json.loads(ssh('/www/server/php/74/bin/php',php))
f=E/(site+'-before.json')
if f.exists():raise RuntimeError('Baseline already recorded')
f.write_text(json.dumps(result,ensure_ascii=False,indent=2))
print(json.dumps({'site':site,'backend':len(result['files']),'front':{n:{'entry':v['entry'],'html':v['html']}for n,v in result['front'].items()},'schema':result['schema'],'releaseFiles':result['releaseFiles']},ensure_ascii=False))
