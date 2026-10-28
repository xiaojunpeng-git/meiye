<?php
/** Instance-bound, read-only fingerprint of member assets and protected entry points. */
$site=$argv[1]??'';$sites=['rh'=>['rh.cc3798.com','ruihao',3306],'008'=>['008.cc3798.com','mohe_008',3307],'010'=>['010.cc3798.com','mohe_010_app',3307]];
if(!isset($sites[$site]))throw new RuntimeException('Explicit site required');
[$domain,$db,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;
$ini=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($ini['DATABASE']??$ini['database']??[]);
if($c['database']!==$db||(int)($c['hostport']??3306)!==$port)throw new RuntimeException('Wrong instance');
$pdo=new PDO('mysql:host='.$c['hostname'].';port='.$port.';dbname='.$db.';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('START TRANSACTION READ ONLY');
$o=['site'=>$site,'database'=>$db,'environment'=>hash_file('sha256',$root.'/.env'),'migration'=>$pdo->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC),'front'=>[],'assets'=>[]];
foreach(['member'=>'public/index.html','admin'=>'public/system.html','cashier'=>'public/view_cashier_v3/index.html','pc'=>'public/static/html/pc.html']as $key=>$f){
 $o['front'][$key]=['index'=>is_file($root.'/'.$f)?hash_file('sha256',$root.'/'.$f):null,'assets'=>[]];
 if(is_file($root.'/'.$f)){preg_match_all('~(?:src|href)=["\x27]?([^"\x27\s>]+\.(?:js|css))~',file_get_contents($root.'/'.$f),$m);foreach(array_unique($m[1])as $ref){if(preg_match('~^(https?:)?//~',$ref))continue;$p=$root.'/public/'.ltrim($ref,'/');$o['front'][$key]['assets'][$ref]=is_file($p)?hash_file('sha256',$p):null;}}
}
// Only compiled member code directories are enumerated; uploads and business records stay on the host.
foreach(['static/js','static/css']as $dir)if(is_dir($root.'/public/'.$dir))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/'.$dir,FilesystemIterator::SKIP_DOTS))as $f)if($f->isFile())$o['assets'][substr($f->getPathname(),strlen($root.'/public/'))]=hash_file('sha256',$f->getPathname());
$o['decoration']=hash('sha256',json_encode($pdo->query('SELECT * FROM eb_diy ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_UNESCAPED_UNICODE));
$pdo->exec('ROLLBACK');echo json_encode($o,JSON_UNESCAPED_SLASHES),PHP_EOL;
