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


// 同一只读事务核对完整日桶，不导出会员/单号；默认Reader与业务日模式分别读取。
think\facade\Db::execute('SET SESSION MAX_EXECUTION_TIME=8000');
think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');
think\facade\Db::startTrans();
try {
    $reader=new app\services\report\StoreReconciliationReceiptReader();
    $methods=$reader->methods();$range=['start_date'=>'2026-10-01','end_date'=>'2026-10-30'];
    $actual=$reader->receipts([$target['store']],$methods,$range,'0',true);
    $default=$reader->receipts([$target['store']],$methods,$range,'0');
    $day=[];$ids=[];$total=0;$kinds=[];
    foreach($actual as $receipt){
        $id=$receipt['receipt_id'];if(isset($ids[$id]))throw new RuntimeException('Duplicate receipt');$ids[$id]=true;
        $date=$receipt['business_date'];$method=$receipt['method_code'];$amount=$receipt['amount_cents'];
        $day[$date][$method]=($day[$date][$method]??0)+$amount;$total+=$amount;
        $kind=$receipt['source_kind'];$kinds[$kind]=($kinds[$kind]??0)+1;
    }
    ksort($day);foreach($day as &$methodsByDay)ksort($methodsByDay);unset($methodsByDay);
    $normalTotal=array_sum(array_column($default,'amount_cents'));
    // 跨月单可使月总额不同；共同命中收款的金额必须逐笔不变。
    $defaultById=array_column($default,null,'receipt_id');
    foreach($actual as $receipt)if(isset($defaultById[$receipt['receipt_id']])
        && $defaultById[$receipt['receipt_id']]['amount_cents']!==$receipt['amount_cents'])
        throw new RuntimeException('Same receipt amount changed');
    // 该两笔已确认同月跨日样本须跟随批准的支付业务日，不落在创建日。
    $cross=[];
    if($site==='010')foreach([123795=>'2026-10-18',124359=>'2026-10-20'] as $order=>$expected){
        $sourceNo=(string)think\facade\Db::name('store_order')->where('id',$order)->value('order_id');
        foreach($actual as $row)if($row['document_no']===$sourceNo){
            if($row['business_date']!==$expected)throw new RuntimeException('Cross-day attribution mismatch');
            $cross[]=['source_order_id'=>$order,'business_date'=>$row['business_date'],'amount_cents'=>$row['amount_cents']];
        }
    }
    if($site==='010' && count(array_unique(array_column($cross,'source_order_id')))!==2)
        throw new RuntimeException('Both cross-day samples must appear');
    echo json_encode(['site'=>$site,'source_sha256'=>$hashes,'business_writes'=>0,'count'=>count($actual),
        'total_cents'=>$total,'default_total_cents'=>$normalTotal,'source_counts'=>$kinds,
        'daily_method_cents'=>$day,'cross_day'=>$cross],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
} finally {think\facade\Db::rollback();}
