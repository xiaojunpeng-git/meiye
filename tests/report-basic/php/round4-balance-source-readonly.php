<?php
declare(strict_types=1);

/**
 * 第4轮逐实例发布只读探针。在独立进程中优先加载已校验指纹的候选类和统一查询配置。
 * stdin 只提供实例代号、候选源码及报表代码；门店身份由本脚本固定，绝不接收浏览器范围。
 */
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$targets = [
    'rh' => ['database' => 'ruihao', 'port' => '3306', 'store' => 164],
    '008' => ['database' => 'mohe_008', 'port' => '3307', 'store' => 113],
    '010' => ['database' => 'mohe_010_app', 'port' => '3307', 'store' => 4],
];
$site = (string)($input['site'] ?? '');
if (!isset($targets[$site])) throw new RuntimeException('实例未授权');
$target = $targets[$site];
$root = getcwd();
if (!is_file($root . '/vendor/autoload.php')) throw new RuntimeException('后端运行目录错误');
require $root . '/vendor/autoload.php';

// sources={} 是部署前现网基线探针；有候选时必须同时固定目录、业务入口与配置。
$sources = $input['sources'] ?? null;
if (!is_array($sources)) throw new RuntimeException('候选来源格式错误');
$baseline = !$sources;
if (!$baseline && !isset($sources['config/unified_query.php'])) throw new RuntimeException('缺少统一查询候选配置');
$candidate = [];
$hashes = [];
foreach ($sources as $path => $source) {
    if (!is_string($path) || !preg_match('~^(?:app/[A-Za-z0-9_/]+|config/unified_query)\.php$~D', $path)
        || strpos($path, '//') !== false || !is_array($source)
        || !isset($source['base64'], $source['sha256'])) {
        throw new RuntimeException('候选来源路径或格式越界');
    }
    $bytes = base64_decode((string)$source['base64'], true);
    $hash = strtolower((string)$source['sha256']);
    if ($bytes === false || !preg_match('/^[0-9a-f]{64}$/D', $hash)
        || !hash_equals($hash, hash('sha256', $bytes))) {
        throw new RuntimeException('候选来源指纹错误');
    }
    $candidate[$path] = $bytes;
    $hashes[$path] = $hash;
}
if (!$baseline) foreach (['app/services/report/BasicReportCatalogServices.php',
    'app/services/report/BasicBusinessReportServices.php'] as $required) {
        if (!isset($candidate[$required])) throw new RuntimeException('缺少核心报表候选：' . $required);
    }
ksort($hashes);
$autoloaded = [];
spl_autoload_register(static function (string $class) use (&$candidate, &$autoloaded): void {
    if (strncmp($class, 'app\\', 4) !== 0) return;
    $path = str_replace('\\', '/', $class) . '.php';
    if (!isset($candidate[$path])) return;
    // 预置自动加载器比 Composer 先执行，确保本轮类取固定字节，而非本机共享工作区。
    $autoloaded[$path] = true;
    eval(substr($candidate[$path], 5));
}, true, true);

(new think\App($root . '/'))->initialize();
if (!$baseline) {
    $unifiedQuery = eval(substr($candidate['config/unified_query.php'], 5));
    if (!is_array($unifiedQuery)) throw new RuntimeException('统一查询候选配置无效');
    // 以整个命名空间替换，避免目标实例原有配置残留额外 registrar/provider。
    think\facade\Config::set(['unified_query' => $unifiedQuery]);
}
$db = config('database.connections.mysql');
if (!is_array($db) || ($db['database'] ?? '') !== $target['database']
    || (string)($db['hostport'] ?? '') !== $target['port']
    || ($db['prefix'] ?? '') !== 'eb_'
    || !in_array($db['hostname'] ?? '', ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('数据库或端口身份错误');
}


// 金额核对只输出汇总与原来源ID，不输出会员姓名/手机号；全程只读。
think\facade\Db::execute('SET SESSION MAX_EXECUTION_TIME=8000');
think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');think\facade\Db::startTrans();
try{
 $reader=new app\services\report\BalanceOrder007ReadServices();$range=['start'=>'2026-10-01','end'=>'2026-10-30'];
 $out=['site'=>$site,'source_sha256'=>$hashes,'business_writes'=>0];
 foreach(['balance'=>'loadBalanceFacts','recharge'=>'loadRechargeFacts'] as $kind=>$method){
  $m=new ReflectionMethod($reader,$method);$m->setAccessible(true);$rows=$m->invoke($reader,[$target['store']],$range);
  $sum=0;$oldSum=0;$oldIds=[];
  foreach($rows as $row){
   $amount=$kind==='balance'&&!isset($row['source_kind'])?-(int)$row['principal_delta_cents']-(int)$row['bonus_delta_cents']:(int)$row['amount_cents'];
   $sum+=$amount;if(($row['source_kind']??'')==='historical_007_original'){$oldSum+=$amount;$oldIds[]=$row['fact_id'];}
  }
  $out[$kind]=['count'=>count($rows),'total_cents'=>$sum,'legacy_count'=>count($oldIds),'legacy_cents'=>$oldSum,'legacy_ids'=>$oldIds];
 }
 $filter=['_report_scope'=>['mode'=>'stores'],'start_date'=>$range['start'],'end_date'=>$range['end']];
 foreach(app\services\report\BalanceOrder007ReadServices::codes() as $code){
  $r=$reader->read($code,[$target['store']],$filter);$values=[];
  foreach(['balance_amount','recharge_amount','actual_deduct'] as $key){$sum='0';foreach($r['rows'] as $row)$sum=bcadd($sum,(string)$row[$key],2);$values[$key]=$sum;}
  $out[$code]=['complete'=>$r['complete'],'row_count'=>count($r['rows']),'sums'=>$values];
 }
 echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
}finally{think\facade\Db::rollback();}
