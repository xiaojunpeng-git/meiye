<?php

/** 本地只读跨报表冒烟：同一门店/业务日编译并执行各入口，禁止接触线上实例。 */
if (getenv('R29_LOCAL_TEST') !== '1') throw new RuntimeException('Explicit local flag required');
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
$fact = \think\facade\Db::name('cashier_v3_entitlement_service_fact')
    ->where('tenant_id', '0')->where('service_status', 'completed')
    ->order('id', 'desc')->field('store_id,business_date')->find();
if (!$fact) throw new RuntimeException('Local service fixture missing');
$store = (int)$fact['store_id'];
$day = (string)$fact['business_date'];
$memberFact = \think\facade\Db::name('cashier_v3_entitlement_service_fact')
    ->where('tenant_id', '0')->where('service_status', 'completed')->where('member_id', '>', 0)
    ->order('id', 'desc')->field('store_id,member_id')->find();
if (!$memberFact) throw new RuntimeException('Local member service fixture missing');
$memberVisitProbe = new class extends \app\services\query\provider\MemberUnifiedQueryProvider {
    public function __construct() {}
    public function visits(int $memberId, int $storeId): array
    {
        return $this->visitSummaries([$memberId], [$storeId], false, '0');
    }
};
$range = ['start' => $day, 'end' => $day];
$input = ['start_date' => $day, 'end_date' => $day, 'limit' => 20, '_report_scope' => ['mode' => 'stores']];
$phaseTwo = new \app\services\report\StoreUnifiedReportServices();
$phaseThree = new \app\services\report\StoreUnifiedReportPhaseThreeServices();
$phaseFour = new \app\services\report\StoreUnifiedReportPhaseFourServices();
$phaseSix = new \app\services\report\StoreUnifiedReportPhaseSixServices();
$cases = [
    'market detail' => static fn() => $phaseTwo->query([$store], $input + ['report' => 'market_detail']),
    'member visit analysis' => static fn() => $phaseTwo->query([$store], $input + ['report' => 'member_visit_analysis']),
    'member annual visits' => static fn() => $phaseTwo->query([$store], $input + ['report' => 'member_visit_annual_summary', 'mode' => 'count']),
    'field acquisition detail' => static fn() => $phaseTwo->query([$store], $input + ['report' => 'field_acquisition_detail']),
    'cross-industry detail' => static fn() => $phaseTwo->query([$store], $input + ['report' => 'cross_industry_customer_detail']),
    'phase-three experience' => static fn() => $phaseThree->query('six_dimension_item_deal_analysis', [$store], $range, $input),
    'phase-four operations health' => static fn() => $phaseFour->query('operations_health_data', [$store], $range, $input),
    'customer visit analytics' => static fn() => (new \app\services\report\CustomerAnalyticsServices())->read('customer_visit_analysis', [$store], $range, $input),
    'customer overview analytics' => static fn() => (new \app\services\report\CustomerAnalyticsServices())->read('customer_overview', [$store], $range, $input),
    'phase-six acquisition' => static fn() => $phaseSix->query('phase_six_acquisition_source', [$store], $range, $input),
    'phase-six health' => static fn() => $phaseSix->query('phase_six_human_store_health', [$store], $range, $input),
    'member dashboard' => static fn() => (new \app\services\report\MemberManagementDashboardServices())->dashboard(['tenant_id' => '0', 'store_ids' => [$store]], $input),
    'group dashboard' => static fn() => (new \app\services\report\GroupManagementDashboardServices())->dashboard(['tenant_id' => '0', 'store_ids' => [$store]], $input),
    'member list visit projection' => static fn() => $memberVisitProbe->visits((int)$memberFact['member_id'], (int)$memberFact['store_id']),
    'lifecycle visit projection' => static fn() => (new \app\services\report\CustomerLifecycleNormalReadServices())->forMembers('0', [(int)$memberFact['member_id']]),
];
$failures = [];
foreach ($cases as $name => $run) {
    try {
        if (!is_array($run())) throw new RuntimeException('Non-array report result');
        echo 'PASS ' . $name . PHP_EOL;
    } catch (\Throwable $error) {
        $failures[] = $name . ': ' . $error->getMessage();
        echo 'FAIL ' . end($failures) . PHP_EOL;
    }
}
if ($failures) throw new RuntimeException(count($failures) . ' cross-report smoke failures');
