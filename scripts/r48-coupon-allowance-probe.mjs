import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

// Instance-bound read-only preflight. Never uploads helpers, changes files,
// prints credentials, or performs a production checkout/refund/void.
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const sites = { rh: ['47.96.103.107', 'rh.cc3798.com', 'ruihao', '3306'],
  '008': ['121.41.109.138', '008.cc3798.com', 'mohe_008', '3307'] }
const instance = process.argv[2]
if (!sites[instance]) throw Error('Expected rh or 008')
const [host, domain, database, port] = sites[instance]
const accepted = JSON.parse(readFileSync(resolve(root, 'tests/cashier-v3/evidence/R48-fixed-files.json')))
const prior = JSON.parse(readFileSync(resolve(root, 'tests/cashier-v3/evidence/R47-release-candidate-2026-10-21.json')))
const paths = [...new Set([...accepted.files.map(f => f.path), ...prior.files])]
  .filter(p => p.startsWith('后端代码/app/')).map(p => p.slice('后端代码/'.length))
const source = `<?php
// Only paths and hashes leave the target; all business queries run READ ONLY.
$root='/www/wwwroot/${domain}'; chdir($root);
require $root.'/vendor/autoload.php'; (new think\\App($root.'/'))->initialize();
$config=config('database.connections.mysql');
if($config['database']!=='${database}' || (string)$config['hostport']!=='${port}'
 || $config['prefix']!=='eb_' || !in_array($config['hostname'],['127.0.0.1','localhost'],true)) throw new RuntimeException('INSTANCE_DATABASE_MISMATCH');
$db=new PDO('mysql:host='.$config['hostname'].';port=${port};dbname=${database};charset=utf8mb4',
 $config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction();
try {
 $out=['instance'=>'${instance}','database'=>'${database}','port'=>'${port}','files'=>[],'front'=>[]];
 foreach(json_decode('${JSON.stringify(paths)}',true) as $p) $out['files'][$p]=is_file($root.'/'.$p)?hash_file('sha256',$root.'/'.$p):null;
 $dirs=['formal'=>$root.'/public/view_cashier_v3'];
 if('${instance}'==='rh') $dirs['preview']='/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3';
 foreach($dirs as $key=>$dir) {
  $html=file_get_contents($dir.'/index.html'); $assets=[];
  preg_match_all('~(?:src|href)="([^"]+\\.(?:js|css))"~',$html,$m);
  foreach($m[1] as $url) { $p='assets/'.basename(parse_url($url,PHP_URL_PATH)); $assets[$p]=hash_file('sha256',$dir.'/'.$p); }
  $out['front'][$key]=['directory'=>$dir,'html'=>hash('sha256',$html),'assets'=>$assets];
 }
 $out['platform']=['html'=>hash_file('sha256',$root.'/public/system.html'),'assets'=>[]];
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/view_admin',FilesystemIterator::SKIP_DOTS));
 foreach($iterator as $file) if($file->isFile()) $out['platform']['assets'][substr($file->getPathname(),strlen($root.'/public/'))]=hash_file('sha256',$file->getPathname());
 ksort($out['platform']['assets']);
 $out['couponTypes']=$db->query('SELECT coupon_type,COUNT(*) count FROM eb_store_coupon_issue GROUP BY coupon_type')->fetchAll();
 $out['tables']=[];
 foreach(['eb_coupon_allowance_account','eb_coupon_allowance_movement','eb_cashier_v3_debt_repayment_item_settlement'] as $table) {
  $exists=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".$db->quote($table))->fetchColumn();
  $out['tables'][$table]=$exists?['count'=>(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),'schema'=>$db->query('SHOW CREATE TABLE '.$table)->fetch()]:null;
 }
 $out['upgrades']=$db->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 8')->fetchAll();
 echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
} finally { $db->rollBack(); }
`
const result = execFileSync('ssh', ['-i', process.env.HOME + '/.ssh/aliyun-ecs', '-o', 'BatchMode=yes',
  '-o', 'ConnectTimeout=12', 'root@' + host, 'php'], { input: source, encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 })
const value = JSON.parse(result)
if (value.instance !== instance || value.database !== database) throw Error('Probe identity mismatch')
const evidence = resolve(root, 'tests/cashier-v3/evidence/R48-release')
mkdirSync(evidence, { recursive: true })
// Subsequent read-only checks retain the original baseline receipt unchanged.
const phase = process.argv[3] || 'before'
if (!/^[a-z0-9-]+$/.test(phase)) throw Error('Invalid probe receipt name')
writeFileSync(resolve(evidence, instance + '-' + phase + '.json'), JSON.stringify(value, null, 2))
console.log(JSON.stringify({ instance, front: value.front, platformHtml: value.platform.html,
  platformAssetCount: Object.keys(value.platform.assets).length, couponTypes: value.couponTypes,
  tables: Object.fromEntries(Object.entries(value.tables).map(([k, v]) => [k, v?.count ?? null])) }))
