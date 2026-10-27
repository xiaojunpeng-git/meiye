<?php
declare(strict_types=1);

/** 仅本地同名镜像：显式允许时创建逐行投影，前后核对全部目标身份和金额，业务事实只读。 */
if (getenv('R95_LOCAL_PARITY') !== '1') throw new RuntimeException('local parity flag required');
$backend = getenv('BACKEND_ROOT') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';
$app = new think\App($backend . '/'); $app->initialize();
use think\facade\Db;
use app\services\target\TargetSchemeServices;
use app\services\target\TargetSchemeReportServices;
use app\services\query\metric\RegisteredMetricReadServices;
$config = config('database.connections.mysql');
if ($config['hostname'] !== 'mysql' || $config['database'] !== 'ruihao' || $config['prefix'] !== 'eb_') throw new RuntimeException('local mirror only');
function parity95(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
try {
    $before = Db::query("SELECT b.report_code,l.* FROM eb_target_report_binding b JOIN eb_target_effective_line e ON e.tenant_id=b.tenant_id AND e.scheme_id=b.scheme_id AND e.store_id=b.store_id JOIN eb_target_scheme_line l ON l.id=e.line_id AND l.tenant_id=e.tenant_id JOIN eb_target_scheme s ON s.id=l.scheme_id AND s.tenant_id=l.tenant_id AND s.status='active' ORDER BY b.report_code,l.id");
    $identity = static function (array $rows): array {
        $result = [];
        foreach ($rows as $r) $result[] = implode('|', [$r['report_code'],$r['tenant_id'],$r['id'],$r['scheme_id'],$r['store_id'],$r['month_key'],$r['metric_code'],$r['scope_type'],$r['scope_id'],$r['target_value'],$r['unit_code'],$r['metric_version'],$r['scope_category_ids_json']]);
        sort($result); return $result;
    };
    $tables = Db::query("SHOW TABLES LIKE 'eb_target_report_line_binding'");
    if (!$tables) {
        if (getenv('R95_APPLY_LOCAL_MIGRATION') !== '1') throw new RuntimeException('local migration not installed');
        $sql = preg_replace('/^--.*$/m', '', file_get_contents($backend . '/database/upgrades/2026-10-20-目标方案逐行生效/02-正式升级.sql'));
        foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') Db::execute($statement);
    }
    $after = Db::query('SELECT b.report_code,l.* FROM eb_target_report_line_binding b JOIN eb_target_scheme_line l ON l.id=b.line_id AND l.tenant_id=b.tenant_id AND l.scheme_id=b.scheme_id ORDER BY b.report_code,l.id');
    parity95($identity($before) === $identity($after), 'all existing report targets preserved across migration');
    echo 'BINDING_ROWS=' . count($after) . ' SHA256=' . hash('sha256', json_encode($identity($after))) . "\n";
    $service = new TargetSchemeServices(); $reports = new TargetSchemeReportServices();
    $stores = array_map('intval', Db::name('system_store')->where('is_del',0)->column('id'));
    $ctx = ['tenant_id'=>'0','store_ids'=>$stores];
    foreach (array_keys(TargetSchemeServices::REPORTS) as $code) {
        $expected = array_values(array_filter($before, static fn($r) => $r['tenant_id'] === '0' && $r['report_code'] === $code && in_array((int)$r['store_id'],$stores,true)));
        $actual = array_map(static fn($r) => $r + ['report_code'=>$code], $service->effective($ctx,$code)['lines']);
        parity95($identity($expected) === $identity($actual), 'real targets ' . $code);
    }
    $year = 2026; $expectedTotals = [];
    foreach ($before as $r) if ($r['tenant_id']==='0' && $r['report_code']==='group_dashboard' && $r['scope_type']==='store' && $r['metric_code']==='actual_performance' && substr($r['month_key'],0,4)===(string)$year && in_array((int)$r['store_id'],$stores,true)) {
        $sid=(int)$r['store_id']; $expectedTotals[$sid]=($expectedTotals[$sid]??0)+(int)$r['target_value'];
    }
    $actualTotals=$reports->groupTotals($ctx,$year,range(1,12)); ksort($expectedTotals); ksort($actualTotals);
    parity95($expectedTotals===$actualTotals,'real group annual totals unchanged');
    $sample = array_slice($service->effective($ctx,'target_analysis',['2026-10'])['lines'],0,2);
    foreach ($sample as $r) {
        if ($r['scope_type'] !== 'store') continue;
        $result=$reports->read(['tenant_id'=>'0','store_ids'=>[(int)$r['store_id']]],['start_month'=>'2026-10','end_month'=>'2026-10','metric_keys'=>$r['metric_code'],'scope_type'=>'store'],'target_analysis');
        $value=(new RegisteredMetricReadServices())->summary($r['metric_code'],'0',[(int)$r['store_id']],['start'=>'2026-10-01','end'=>'2026-10-31']);
        parity95(count($result['rows'])===1 && $result['rows'][0]['actual_value']===$value,'real completion uses unchanged registered metric reader');
    }
    echo "LOCAL_PARITY_COMPLETE\n";
} catch (Throwable $e) { echo 'FAIL ' . $e->getMessage() . "\n"; exit(1); }
