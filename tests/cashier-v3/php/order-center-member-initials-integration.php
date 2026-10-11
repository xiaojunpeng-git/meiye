<?php
declare(strict_types=1);

/** 订单中心综合查询只读回归：八类记录沿用会员索引，历史快照、日期和导出口径不变。 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\order\AbstractCashierV3OrderCenterUnifiedQueryProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3OrderCenterQueryExecutionServices;
use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract;
use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryRegistrar;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use app\services\cashier\v3\query\UnifiedQueryModule;
use app\services\query\StructuredExpressionEvaluator;
use app\services\query\StructuredExpressionValidator;
use app\services\query\UnifiedQueryPageRegistry;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->initialize();

final class OrderCenterInitialProbe extends AbstractCashierV3OrderCenterUnifiedQueryProvider
{
    private $code;
    private $fixture;

    public function __construct(CashierV3OrderCenterQueryExecutionServices $execution, string $code, array $fixture)
    {
        $this->execution = $execution;
        $this->code = $code;
        $this->fixture = $fixture;
    }

    public function pageCode(): string { return $this->code; }

    protected function authorizedSourceRows(array $context): array { return $this->fixture; }

    public function probe(array $context, array $payload): array { return $this->run($context, $payload, []); }

    public function initialsKeyword(array $payload): string { return $this->memberInitialKeyword($payload); }
}

function assertOrderInitial(bool $condition, string $message): void
{
    if (!$condition) throw new \RuntimeException('FAIL ' . $message);
    echo 'PASS ' . $message . PHP_EOL;
}

$sample = Db::query("SELECT u.uid,s.initials FROM eb_user u
    JOIN eb_member_name_initial_search s ON s.uid=u.uid
    WHERE u.is_del=0 AND s.initials REGEXP '^[A-Z]{2,60}$'
    ORDER BY u.uid LIMIT 1");
if (!$sample) throw new \RuntimeException('缺少可供只读验证的会员姓名首字母索引');
$memberId = (int)$sample[0]['uid'];
$keyword = strtolower(substr((string)$sample[0]['initials'], 0, 2));
$registry = UnifiedQueryPageRegistry::withDefaults([], [new CashierV3OrderCenterUnifiedQueryRegistrar()]);
$execution = new CashierV3OrderCenterQueryExecutionServices(
    $registry, new StructuredExpressionValidator($registry), new StructuredExpressionEvaluator()
);
$context = ['query_cutoff_date' => date('Y-m-d'), 'permissions' => []];
$dateFields = [
    'sales' => 'payment_completed_at', 'recharge' => 'payment_completed_at',
    'refund' => 'refund_completed_at', 'debt' => 'created_at',
    'service' => 'service_completed_at', 'supplement' => 'payment_completed_at',
    'gift' => 'created_at', 'card_operation' => 'completed_at',
];

foreach (CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE as $type => $pageCode) {
    $fields = CashierV3OrderCenterUnifiedQueryContract::definition($pageCode)['keywordFields'];
    $date = $dateFields[$type];
    $rows = [
        ['record_id' => $type . ':1', 'memberId' => $memberId, 'member_name' => '历史姓名快照',
            'business_date' => '2026-10-11', $date => '2026-10-11 12:00:00'],
        ['record_id' => $type . ':2', 'memberId' => 0, 'member_name' => '游客',
            $fields[0] => strtoupper($keyword) . '-0001', 'business_date' => '2026-10-11', $date => '2026-10-11 11:00:00'],
        ['record_id' => $type . ':3', 'memberId' => 0, 'member_name' => '游客',
            'business_date' => '2026-10-11', $date => '2026-10-11 10:00:00'],
    ];
    $probe = new OrderCenterInitialProbe($execution, $pageCode, $rows);
    $payload = ['keyword' => $keyword, 'page' => 1, 'limit' => 20,
        'topFilters' => [['field' => 'business_date', 'operator' => 'eq', 'value' => '2026-10-11']]];
    $result = $probe->probe($context, $payload);
    assertOrderInitial((int)$result['pagination']['total'] === 2
        && array_column($result['rows'], 'record_id') === [$type . ':1', $type . ':2'],
        $type . ' 首字母和原单号匹配均保留');
    assertOrderInitial((int)$probe->probe($context, array_replace($payload, [
        'topFilters' => [['field' => 'business_date', 'operator' => 'eq', 'value' => '2026-10-10']],
    ]))['pagination']['total'] === 0, $type . ' 日期筛选不被绕过');
    $filters = array_map(static function (string $field) use ($keyword): array {
        return ['field_key' => $field, 'operator' => 'contains', 'value' => $keyword];
    }, $fields);
    $export = $probe->probe($context, ['keywordFilters' => $filters,
        'export' => ['scope' => 'query', 'fields' => ['member_name']]]);
    assertOrderInitial((int)$export['pagination']['total'] === 2
        && count($export['exportRows']) === 2
        && $export['exportRows'][0]['member_name'] === '历史姓名快照',
        $type . ' 冻结关键词导出保持历史姓名快照');
    assertOrderInitial($probe->initialsKeyword(['keyword' => 'ab1']) === ''
        && $probe->initialsKeyword(['keywordFilters' => array_slice($filters, 1)]) === '',
        $type . ' 非纯字母和非标准关键词组不启用索引');
}

// 从本地既有订单中挑选一条可见正常单，验证权威 Reader 到统一查询的完整链路。
$candidates = Db::query("SELECT o.store_id,o.member_id,o.business_date,s.initials
    FROM eb_cashier_v3_sales_order o
    JOIN eb_member_name_initial_search s ON s.uid=o.member_id
    WHERE o.member_id>0 AND s.initials REGEXP '^[A-Z]{2,60}$'
    ORDER BY o.order_id DESC LIMIT 50");
$reader = new CashierV3SalesOrderQueryServices();
$live = null;
foreach ($candidates as $candidate) {
    $storeId = (int)$candidate['store_id'];
    $operator = new CashierV3OperatorScope($storeId, 71, 'organization:test', '0');
    $scope = new CashierV3DataScopeContext($storeId, 71, $storeId, '0', 'organization:test',
        [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false, '', 'permission-v2',
        ['cashier.v3.order_center', 'cashier.v3.order.service_detail'], []);
    $date = (string)$candidate['business_date'];
    $page = $reader->querySalesOrders(['recordType' => 'sales', 'dateFrom' => $date, 'dateTo' => $date,
        'memberId' => (int)$candidate['member_id'], 'status' => 'normal'], $operator, $scope);
    if (!empty($page['records'])) {
        $live = [$candidate, $operator, $scope];
        break;
    }
}
if ($live === null) throw new \RuntimeException('本地没有可见正常销售单用于只读端到端验证');
[$candidate, $operator, $scope] = $live;
$runtime = UnifiedQueryModule::runtime();
$provider = clone $runtime['providers']->resolve('order_center_sales');
$liveContext = $runtime['contextFactory']->make($operator, $scope, ['pageCode' => 'order_center_sales']);
$liveResult = $provider->queryPage($liveContext, [
    'recordType' => 'sales', 'dateFrom' => $candidate['business_date'],
    'dateTo' => $candidate['business_date'], 'dataScope' => 'normal',
    'keyword' => strtolower((string)$candidate['initials']), 'page' => 1, 'pageSize' => 100,
], $operator, $scope);
assertOrderInitial(in_array((int)$candidate['member_id'], array_map('intval', array_column($liveResult['records'], 'memberId')), true),
    '真实销售单在门店权限与日期范围内被会员首字母找到');
