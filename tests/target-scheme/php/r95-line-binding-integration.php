<?php
declare(strict_types=1);

/** 本地隔离表测试：复制表结构与只读目录，所有方案写入随机前缀，最后清理；不连接生产。 */
if (getenv('R95_LOCAL_TEST') !== '1') throw new RuntimeException('R95_LOCAL_TEST=1 required');
$backend = getenv('BACKEND_ROOT') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';
$app = new think\App($backend . '/');
$app->initialize();
use think\facade\Db;
use think\facade\Config;
use app\services\target\TargetSchemeServices;
use app\services\target\TargetSchemeReportServices;
use app\services\report\GroupManagementDashboardTargetServices;

$config = config('database');
$connection = $config['connections'][$config['default']];
if ($connection['hostname'] !== 'mysql' || $connection['database'] !== 'ruihao') throw new RuntimeException('local mirror only');
$source = Db::connect();
$prefix = 'r95_' . bin2hex(random_bytes(5)) . '_';
$tables = ['target_scheme', 'target_scheme_line', 'target_scheme_command', 'target_scheme_audit',
    'target_report_binding', 'target_effective_line', 'system_store', 'store_product', 'store_product_category'];
$created = []; $checks = 0; $failed = false;
function check95(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS $label\n"; }
function denied95(callable $fn, string $label): void { try { $fn(); } catch (InvalidArgumentException $e) { check95(true, $label); return; } throw new RuntimeException('Expected rejection: ' . $label); }
function key95(): string { return bin2hex(random_bytes(16)); }
try {
    foreach ($tables as $table) {
        $source->execute("CREATE TABLE `{$prefix}{$table}` LIKE `eb_{$table}`");
        $created[] = $prefix . $table;
    }
    foreach (['system_store', 'store_product_category'] as $table) $source->execute("INSERT INTO `{$prefix}{$table}` SELECT * FROM `eb_{$table}`");
    $dbConfig = $config;
    $dbConfig['connections']['r95'] = array_merge($connection, ['prefix' => $prefix]);
    $dbConfig['default'] = 'r95';
    Config::set($dbConfig, 'database');
    $stores = array_map('intval', Db::name('system_store')->where('is_del', 0)->where('name', '<>', '总部')->limit(2)->column('id'));
    if (count($stores) !== 2) throw new RuntimeException('Two local stores required');
    $ctx = ['tenant_id' => 'r95', 'actor_id' => 99, 'store_ids' => $stores];
    $service = new TargetSchemeServices();
    $line = static fn(string $month, int $value = 10000, string $metric = 'actual_performance', int $store = 0) => [
        'store_id' => $store ?: $stores[0], 'month_key' => $month, 'metric_code' => $metric,
        'scope_type' => 'store', 'scope_id' => 0, 'target_value' => $value];
    $draft = static function (string $name, array $lines) use ($service, $ctx): int {
        $id = $service->create($ctx, ['name' => $name, 'idempotency_key' => key95()])['id'];
        $service->replaceLines($ctx, $id, ['lines' => $lines, 'expected_version' => 1, 'idempotency_key' => key95()]);
        return $id;
    };
    $activate = static function (int $id, array $reports, array $extra = []) use ($service, $ctx): array {
        $version = (int)Db::name('target_scheme')->where('id', $id)->value('version');
        return $service->activate($ctx, $id, $extra + ['report_codes' => $reports, 'expected_version' => $version, 'idempotency_key' => key95()]);
    };
    $allReports = array_keys(TargetSchemeServices::REPORTS);
    $legacy = $draft('legacy current', [$line('2026-10')]);
    $l = Db::name('target_scheme_line')->where('scheme_id', $legacy)->find();
    Db::name('target_scheme')->where('id', $legacy)->update(['status' => 'active']);
    Db::name('target_effective_line')->insert(array_intersect_key($l, array_flip(['tenant_id','scheme_id','store_id','month_key','metric_code','scope_type','scope_id'])) + ['line_id' => $l['id'], 'activated_at' => time()]);
    Db::name('target_report_binding')->insert(['tenant_id' => 'r95', 'report_code' => 'group_dashboard', 'store_id' => $stores[0], 'scheme_id' => $legacy, 'version' => 1, 'updated_by' => 99, 'updated_at' => time()]);
    $unused = $draft('unbound draft', [$line('2026-09')]);
    $sql = file_get_contents($backend . '/database/upgrades/2026-10-20-目标方案逐行生效/02-正式升级.sql');
    $sql = str_replace('eb_', $prefix, preg_replace('/^--.*$/m', '', $sql));
    $created[] = $prefix . 'target_report_line_binding';
    foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $source->execute($statement);
    check95(count($service->effective($ctx, 'group_dashboard')['lines']) === 1, 'migration preserves only current effective lines');
    check95($service->effective($ctx, 'group_dashboard')['lines'][0]['id'] == $l['id'], 'migration preserves line identity and value');
    $nov = $draft('November', [$line('2026-11', 20000)]);
    $activate($nov, $allReports);
    check95(count($service->effective($ctx, 'group_dashboard')['lines']) === 2, 'different months coexist');
    check95($service->detail($ctx, $legacy)['scheme']['status'] === 'active', 'October stays active after November activation');
    $mixed = $draft('other metric and store', [$line('2026-10', 30000, 'cash_performance'), $line('2026-10', 40000, 'actual_performance', $stores[1])]);
    $activate($mixed, $allReports);
    check95(count($service->effective($ctx, 'group_dashboard')['lines']) === 4, 'same month other metric and store coexist');
    $category = (int)Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)->value('id');
    $categoryLine = array_merge($line('2026-10', 90000), ['scope_type' => 'category', 'scope_id' => $category]);
    $scoped = $draft('category', [$categoryLine]);
    $activate($scoped, $allReports);
    check95(count($service->effective($ctx, 'group_dashboard')['lines']) === 5, 'category and full-store goals do not conflict');
    $group = new TargetSchemeReportServices();
    $totals = $group->groupTotals($ctx, 2026, [10,11]);
    check95($totals[$stores[0]] === 30000 && $totals[$stores[1]] === 40000, 'annual dashboard sums months only, excludes category and other metric');
    $year = (new GroupManagementDashboardTargetServices())->readYear($ctx, 2026);
    $first = array_values(array_filter($year['stores'], static fn($s) => $s['store_id'] === $stores[0]))[0];
    check95(strpos($first['months'][9]['subject_key'], 'scheme:' . $legacy . ':') === 0 && strpos($first['months'][10]['subject_key'], 'scheme:' . $nov . ':') === 0, 'dashboard cell IDs identify each owning scheme');
    $replacement = $draft('October replacement', [$line('2026-10', 55500)]);
    denied95(static fn() => $activate($replacement, ['group_dashboard']), 'old clients cannot silently replace a conflict');
    $preview = $activate($replacement, ['group_dashboard'], ['conflict_preview' => true]);
    check95($preview['status'] === 'confirmation_required' && count($preview['conflicts']) === 1, 'preview reports exact conflict');
    $oldGoal = array_values(array_filter($service->effective($ctx, 'group_dashboard', ['2026-10'])['lines'],
        static fn($row) => $row['store_id'] == $stores[0] && $row['scope_type'] === 'store' && $row['metric_code'] === 'actual_performance'));
    check95(count($oldGoal) === 1 && $oldGoal[0]['scheme_id'] == $legacy, 'preview and cancel leave old target effective');
    $competitor = $draft('concurrent new owner', [$line('2026-10', 60000)]);
    $otherPreview = $activate($competitor, ['group_dashboard'], ['conflict_preview' => true]);
    $activate($competitor, ['group_dashboard'], ['conflict_token' => $otherPreview['conflict_token']]);
    denied95(static fn() => $activate($replacement, ['group_dashboard'], ['conflict_token' => $preview['conflict_token']]), 'stale conflict confirmation cannot replace changed owner');
    $newPreview = $activate($replacement, ['group_dashboard'], ['conflict_preview' => true]);
    $receiptKey = key95();
    $input = ['conflict_token' => $newPreview['conflict_token'], 'idempotency_key' => $receiptKey, 'expected_version' => 2];
    $accepted = $activate($replacement, ['group_dashboard'], $input);
    check95($activate($replacement, ['group_dashboard'], $input) === $accepted, 'activation response replay is idempotent');
    check95($service->detail($ctx, $competitor)['scheme']['status'] === 'draft', 'fully replaced scheme has no effective status');
    check95(count($service->effective($ctx, 'group_dashboard', ['2026-11'])['lines']) === 1, 'replacement leaves November intact');
    foreach ($allReports as $report) check95(count($service->effective($ctx, $report, ['2026-11'])['lines']) === 1, 'unified reader ' . $report);
    check95($service->effective($ctx, 'invalid')['lines'] === [], 'invalid report reads no targets');
    check95($service->effective(array_merge($ctx, ['store_ids' => []]), 'group_dashboard')['lines'] === [], 'empty authorization reads no targets');
    check95($service->effective(array_merge($ctx, ['tenant_id' => 'other']), 'group_dashboard')['lines'] === [], 'tenant isolation');
    $partial = array_merge($ctx, ['store_ids' => [$stores[0]]]);
    denied95(static fn() => $service->void($partial, $mixed, ['expected_version' => 3, 'reason' => 'test', 'idempotency_key' => key95()]), 'partial permissions cannot void whole scheme');
    denied95(static fn() => $service->deleteVoided($ctx, $replacement, ['expected_version' => 3, 'idempotency_key' => key95()]), 'active scheme cannot be deleted');
    denied95(static fn() => $service->deleteVoided($ctx, $unused, ['expected_version' => 2, 'idempotency_key' => key95()]), 'draft cannot be deleted');
    $service->void($ctx, $replacement, ['expected_version' => 3, 'reason' => 'test void', 'idempotency_key' => key95()]);
    check95(count($service->effective($ctx, 'group_dashboard', ['2026-10'])['lines']) === 3, 'void removes only current scheme, no historical resurrection');
    $deleteInput = ['expected_version' => 4, 'idempotency_key' => key95()];
    $deleted = $service->deleteVoided($ctx, $replacement, $deleteInput);
    check95($service->deleteVoided($ctx, $replacement, $deleteInput) === $deleted, 'delete replay is idempotent');
    denied95(static fn() => $service->detail($ctx, $replacement), 'deleted detail inaccessible');
    check95(!in_array($replacement, array_map('intval', array_column($service->listing($ctx), 'id')), true), 'deleted scheme absent from listing');
    check95(Db::name('target_scheme_line')->where('scheme_id', $replacement)->count() === 1, 'delete preserves historical target lines');
    check95(Db::name('target_scheme_audit')->where('scheme_id', $replacement)->whereIn('action', ['voided','deleted'])->count() === 2, 'void and delete are independently audited');
    check95($group->groupTotals($ctx, 2026, [11])[$stores[0]] === 20000, 'deletion does not change other month target');
    $past = $draft('historical reporting', [$line('2025-01', 700), $line('2025-02', 800)]);
    $activate($past, $allReports);
    foreach (['target_analysis','target_monthly','target_store','merchant_target_analysis'] as $report) {
        $rows = $group->read($ctx, ['start_month'=>'2025-01', 'end_month'=>'2025-02'], $report);
        check95($rows['row_count'] === 2 && array_sum(array_column($rows['rows'], 'target_value')) === 1500, 'report month projection ' . $report);
        check95($rows['rows'][0]['actual_value'] === null, 'uncovered actual remains null ' . $report);
    }
    $empty = $service->create($ctx, ['name'=>'empty','idempotency_key'=>key95()])['id'];
    $service->void($ctx, $empty, ['expected_version'=>1,'reason'=>'empty','idempotency_key'=>key95()]);
    denied95(static fn() => $service->deleteVoided(array_merge($ctx,['actor_id'=>100]), $empty, ['expected_version'=>2,'idempotency_key'=>key95()]), 'other actor cannot delete empty scheme');
    denied95(static fn() => $service->deleteVoided($ctx, $empty, ['expected_version'=>1,'idempotency_key'=>key95()]), 'stale delete version rejected');
    $service->deleteVoided($ctx, $empty, ['expected_version'=>2,'idempotency_key'=>key95()]);
    check95($service->effective($ctx, 'group_dashboard', ['2026-11'])['lines'][0]['target_value'] == 20000, 'empty deletion leaves all effective targets unchanged');

    $separate = $draft('separate report owner', [$line('2026-11', 33000)]);
    $p = $activate($separate, ['target_monthly'], ['conflict_preview'=>true]);
    $activate($separate, ['target_monthly'], ['conflict_token'=>$p['conflict_token']]);
    check95($service->detail($ctx,$nov)['scheme']['status']==='active', 'partially replaced scheme remains active for other reports');
    check95($service->detail($ctx,$nov)['lines'][0]['active_report_codes']===array_values(array_diff($allReports,['target_monthly'])) || count($service->detail($ctx,$nov)['lines'][0]['active_report_codes'])===6, 'detail exposes remaining per-line report references');
    $service->void($ctx,$nov,['expected_version'=>(int)$service->detail($ctx,$nov)['scheme']['version'],'reason'=>'partial owner','idempotency_key'=>key95()]);
    check95($service->effective($ctx,'target_monthly',['2026-11'])['lines'][0]['scheme_id']==$separate, 'voiding previous owner leaves replacement in another report');

    // 两个独立 PHP/数据库连接同时抢占空目标，必须只有一个成功，另一个必须明确冲突。
    $contenders=[$draft('race one',[$line('2026-12')]),$draft('race two',[$line('2026-12',20000)])];
    $childCode = <<<'CHILD'
require '/var/www/html/vendor/autoload.php'; $a=new think\App('/var/www/html/');$a->initialize();
$cfg=config('database');$base=$cfg['connections'][$cfg['default']];
if($base['hostname']!=='mysql'||$base['database']!=='ruihao'||!preg_match('/^r95_[a-f0-9]{10}_$/D',$argv[1]))exit(4);
$cfg['connections']['race']=array_merge($base,['prefix'=>$argv[1]]);$cfg['default']='race';think\facade\Config::set($cfg,'database');
echo "READY\n";fgets(STDIN);
try{$result=(new app\services\target\TargetSchemeServices())->activate(['tenant_id'=>'r95','actor_id'=>99,'store_ids'=>[(int)$argv[3]]],(int)$argv[2],['expected_version'=>2,'report_codes'=>['group_dashboard'],'idempotency_key'=>'race-'.$argv[2]]);echo $result['status']."\n";}
catch(InvalidArgumentException $e){echo strpos($e->getMessage(),'相同目标')!==false?"conflict\n":"unexpected: ".$e->getMessage()."\n";}
catch(Throwable $e){echo "error: ".$e->getMessage()."\n";exit(5);}
CHILD;
    $jobs=[];
    foreach($contenders as $id){$pipes=[];$proc=proc_open([PHP_BINARY,'-r',$childCode,$prefix,(string)$id,(string)$stores[0]],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('child launch failed');$jobs[]=[$proc,$pipes];}
    foreach($jobs as [$proc,$pipes]) if(trim((string)fgets($pipes[1]))!=='READY')throw new RuntimeException('child not ready');
    foreach($jobs as [$proc,$pipes]){fwrite($pipes[0],"go\n");fclose($pipes[0]);}
    $outcomes=[];
    foreach($jobs as [$proc,$pipes]){$outcomes[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);if($exit!==0)throw new RuntimeException('child failed '.$err);}
    sort($outcomes);check95($outcomes===['active','conflict'], 'concurrent empty-target activation produces one winner and one explicit conflict');
    check95(count($service->effective($ctx,'group_dashboard',['2026-12'])['lines'])===1,'concurrent activation never duplicates dashboard targets');
    if (getenv('R95_MAX_CONFLICTS') === '1') {
        // 覆盖已公布的 3000 行 × 7 报表边界，验证 TEXT 扩容与 PHP 128MB 默认预算。
        $largeIds=[];
        foreach(['large old','large new'] as $name) $largeIds[]=$service->create($ctx,['name'=>$name,'idempotency_key'=>key95()])['id'];
        $metricCodes=['actual_performance','cash_performance','refund_performance'];
        foreach($largeIds as $id){$batch=[];for($i=0;$i<3000;$i++){
            $n=intdiv($i,3);$row=$l;unset($row['id']);$row['scheme_id']=$id;$row['store_id']=$stores[1];
            $row['scope_type']='category';$row['scope_id']=$category;$row['scope_category_ids_json']=json_encode([$category]);
            $row['month_key']=sprintf('%04d-%02d',2000+intdiv($n,12),1+$n%12);$row['metric_code']=$metricCodes[$i%3];$batch[]=$row;
            if(count($batch)===100){Db::name('target_scheme_line')->insertAll($batch);$batch=[];}
        }}
        Db::name('target_scheme')->where('id',$largeIds[0])->update(['status'=>'active']);
        $largeLines=Db::name('target_scheme_line')->where('scheme_id',$largeIds[0])->select()->toArray();$batch=[];
        foreach($largeLines as $row)foreach($allReports as $report){$batch[]=array_intersect_key($row,array_flip(['tenant_id','scheme_id','store_id','month_key','metric_code','scope_type','scope_id']))+['report_code'=>$report,'line_id'=>$row['id'],'version'=>1,'updated_by'=>99,'updated_at'=>time()];if(count($batch)===100){Db::name('target_report_line_binding')->insertAll($batch);$batch=[];}}
        unset($largeLines,$batch);
        $started=microtime(true);$large=$activate($largeIds[1],$allReports,['conflict_preview'=>true]);
        check95(count($large['conflicts'])===21000,'3000 targets across 7 reports return full conflict list without truncation');
        check95(Db::name('target_scheme')->where('id',$largeIds[1])->value('status')==='draft','maximum preview has no business mutation');
        // 发布前与实例 max_allowed_packet 比较；TEXT 扩容不代表连接报文也能承载完整确认清单。
        echo 'MAX_PREVIEW_JSON_BYTES='.strlen(json_encode($large, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))."\n";
        echo 'MAX_PREVIEW_SECONDS='.round(microtime(true)-$started,3).' PEAK_BYTES='.memory_get_peak_usage(true)."\n";
        $largeToken=$large['conflict_token'];unset($large);
        $started=microtime(true);$activate($largeIds[1],$allReports,['conflict_token'=>$largeToken]);
        check95(Db::name('target_report_line_binding')->where('scheme_id',$largeIds[1])->count()===21000,'maximum confirmed replacement commits all target references');
        $largeDetail=$service->detail($ctx,$largeIds[1]);
        check95(count($largeDetail['lines'])===3000 && count($largeDetail['lines'][0]['active_report_codes'])===7,'maximum detail groups references without quadratic scan');
        echo 'MAX_ACTIVATE_AND_DETAIL_SECONDS='.round(microtime(true)-$started,3).' PEAK_BYTES='.memory_get_peak_usage(true)."\n";
    }
    echo "R95_CHECKS=$checks\n";
} catch (Throwable $e) {
    $failed = true;
    echo 'FAIL ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    Config::set($config, 'database');
    foreach (array_reverse($created) as $table) {
        if (strpos($table, $prefix) !== 0) throw new RuntimeException('cleanup prefix mismatch');
        $source->execute("DROP TABLE IF EXISTS `$table`");
    }
    echo "ISOLATED_TABLES_REMOVED\n";
}
exit($failed ? 1 : 0);
