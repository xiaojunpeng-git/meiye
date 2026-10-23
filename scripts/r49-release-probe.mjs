import { execFileSync } from 'node:child_process'
import { mkdirSync, writeFileSync, readFileSync } from 'node:fs'

// Read-only, instance-bound fingerprints and migration state. No source text,
// secrets, writes, production checkouts or service changes leave this probe.
const sites = { rh: ['47.96.103.107', 'rh.cc3798.com', 'ruihao', '3306'],
  '008': ['121.41.109.138', '008.cc3798.com', 'mohe_008', '3307'] }
const [instance, phase = 'before'] = process.argv.slice(2)
if (!sites[instance] || !/^[a-z0-9-]+$/.test(phase)) throw Error('Invalid instance or phase')
const [host, domain, database, port] = sites[instance]
const evidence = 'tests/cashier-v3/evidence/R49-release'
mkdirSync(evidence, { recursive: true })
const script = `<?php
$root='/www/wwwroot/${domain}'; chdir($root);
require $root.'/vendor/autoload.php'; (new think\\App($root.'/'))->initialize();
$cfg=config('database.connections.mysql');
if($cfg['database']!=='${database}' || (string)$cfg['hostport']!=='${port}' || $cfg['prefix']!=='eb_'
 || !in_array($cfg['hostname'],['127.0.0.1','localhost'],true)) throw new RuntimeException('INSTANCE_IDENTITY_MISMATCH');
$db=new PDO('mysql:host='.$cfg['hostname'].';port=${port};dbname=${database};charset=utf8mb4',
 $cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction();
try {
 $out=['instance'=>'${instance}','database'=>'${database}','port'=>'${port}','files'=>[],'front'=>[]];
 foreach(['app/services/cashier/v3','app/services/report','app/services/system/config'] as $dir) {
  foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $file)
   if($file->isFile() && $file->getExtension()==='php') $out['files'][substr($file->getPathname(),strlen($root.'/'))]=hash_file('sha256',$file->getPathname());
 }
 $out['files']['app/services/cashier/v3/card/CashierV3OrdinaryCardSaleAdjustment.php']=is_file($root.'/app/services/cashier/v3/card/CashierV3OrdinaryCardSaleAdjustment.php')?hash_file('sha256',$root.'/app/services/cashier/v3/card/CashierV3OrdinaryCardSaleAdjustment.php'):null;
 ksort($out['files']);
 $dirs=['formal'=>$root.'/public/view_cashier_v3'];
 if('${instance}'==='rh') $dirs['preview']='/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3';
 foreach($dirs as $name=>$dir) {
  $html=file_get_contents($dir.'/index.html'); $assets=[];
  preg_match_all('~(?:src|href)="([^"]+\\.(?:js|css))"~',$html,$m);
  foreach($m[1] as $url){$p='assets/'.basename(parse_url($url,PHP_URL_PATH));$assets[$p]=hash_file('sha256',$dir.'/'.$p);}
  $out['front'][$name]=['directory'=>$dir,'html'=>hash('sha256',$html),'assets'=>$assets];
 }
 $out['platform']=['html'=>hash_file('sha256',$root.'/public/system.html'),'assets'=>[]];
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/view_admin',FilesystemIterator::SKIP_DOTS)) as $file)
  if($file->isFile())$out['platform']['assets'][substr($file->getPathname(),strlen($root.'/public/'))]=hash_file('sha256',$file->getPathname());
 ksort($out['platform']['assets']);
 $out['switch']=$db->query("SELECT id,menu_name,value,is_store FROM eb_system_config WHERE menu_name='cashier_ordinary_card_sale_edit_switch'")->fetchAll();
 $out['upgrades']=$db->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 12')->fetchAll();
 echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
} finally {$db->rollBack();}
`
const output = execFileSync('ssh', ['-i', process.env.HOME + '/.ssh/aliyun-ecs', '-o', 'BatchMode=yes',
  '-o', 'ConnectTimeout=12', 'root@' + host, 'php'], { input: script, encoding: 'utf8', maxBuffer: 12 * 1024 * 1024 })
const result = JSON.parse(output)
if (result.instance !== instance || result.database !== database) throw Error('Probe response mismatch')
const file = `${evidence}/${instance}-${phase}.json`
if (phase === 'before') {
  try { readFileSync(file); throw Error('Never replace original instance baseline') }
  catch (e) { if (e.code !== 'ENOENT') throw e }
}
writeFileSync(file, JSON.stringify(result, null, 2) + '\n')
console.log(JSON.stringify({ instance, backendFiles: Object.keys(result.files).length,
  front: result.front, platformHtml: result.platform.html, switch: result.switch, latestUpgrade: result.upgrades[0] }))
