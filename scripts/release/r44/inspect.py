"""R44 read-only instance evidence. No credentials or customer rows leave the host."""
import pathlib,json,subprocess,concurrent.futures
ROOT=pathlib.Path(__file__).resolve().parents[3]
E=ROOT.parent/'任务管理/进行中/第44轮-拼团大屏与推广/发布核对'
M=json.loads((E.parent/'复核修订候选清单.json').read_text())
PATHS=[x['path'].removeprefix('后端代码/') for x in M['files']['backend']]
PHP=r'''<?php
$s=$argv[1];$map=['rh'=>['rh.cc3798.com','ruihao',3306],'008'=>['008.cc3798.com','mohe_008',3307],'010'=>['010.cc3798.com','mohe_010_app',3307]];
if(!isset($map[$s]))throw new Exception('Unknown site');[$domain,$database,$port]=$map[$s];$root='/www/wwwroot/'.$domain;
$ini=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($ini['DATABASE']??$ini['database']??[]);
if(($c['database']??'')!==$database||(int)($c['hostport']??3306)!==$port)throw new Exception('Instance mismatch');
$p=new PDO('mysql:host='.$c['hostname'].';port='.$port.';dbname='.$database.';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec('START TRANSACTION READ ONLY');
$o=['site'=>$s,'database'=>$database,'env_sha256'=>hash_file('sha256',$root.'/.env')];
foreach(__PATHS__ as $f)$o['backend'][$f]=is_file($root.'/'.$f)?hash_file('sha256',$root.'/'.$f):null;
foreach(['app','route','config','mohe'] as $dir)if(is_dir($root.'/'.$dir))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile())$o['all_backend'][substr($f->getPathname(),strlen($root)+1)]=hash_file('sha256',$f->getPathname());
foreach(['public/system.html','public/index.html','public/view_cashier_v3/index.html','public/static/html/pc.html']as$f)$o['entries'][$f]=is_file($root.'/'.$f)?hash_file('sha256',$root.'/'.$f):null;
foreach(['public/view_admin','public/view_cashier_v3','public/static/js','public/static/css']as$d)if(is_dir($root.'/'.$d))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d,FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile())$o['assets'][substr($f->getPathname(),strlen($root)+1)]=hash_file('sha256',$f->getPathname());
$o['migrations']=$p->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)as$t)if(preg_match('/combination|store_pink|store_order$|member_order_account/',$t))$o['schema'][$t]=preg_replace('/AUTO_INCREMENT=\d+/','AUTO_INCREMENT=COUNTER',$p->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1]);
$p->exec('ROLLBACK');echo json_encode($o,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
'''.replace('__PATHS__',json.dumps(PATHS))
def inspect(s):
 h='rh-server' if s=='rh' else 'mohe-server'
 p=subprocess.run(['ssh','-T','-q','-o','BatchMode=yes','-o','ConnectTimeout=15','-o','ServerAliveInterval=10','-o','ServerAliveCountMax=2',h,'/www/server/php/74/bin/php -- '+s],input=PHP.encode(),capture_output=True,timeout=150)
 if p.returncode:raise RuntimeError(s+' inspection failed: '+p.stderr.decode()[-600:])
 x=json.loads(p.stdout);(E/(s+'-before.json')).write_text(json.dumps(x,ensure_ascii=False,indent=2)+'\n')
 return {'site':s,'backend_files':len(x['all_backend']),'assets':len(x['assets']),'latest_migration':x['migrations'][0]}
if __name__=='__main__':
 with concurrent.futures.ThreadPoolExecutor(3)as ex:
  for r in ex.map(inspect,['rh','008','010']):print(json.dumps(r,ensure_ascii=False),flush=True)
