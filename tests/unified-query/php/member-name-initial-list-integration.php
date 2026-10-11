<?php
declare(strict_types=1);

/** 会员列表拼音首字母检索只读回归：索引命中、门店权限和统一执行器口径必须一致。 */
require '/var/www/html/vendor/autoload.php';

use app\services\query\StructuredExpressionEvaluator;
use app\services\query\StructuredExpressionValidator;
use app\services\query\UnifiedQueryAccessPolicy;
use app\services\query\UnifiedQueryCustomFieldServices;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryFieldReferenceServices;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\UnifiedQueryPreferenceServices;
use app\services\query\provider\MemberUnifiedQueryProvider;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->initialize();

final class MemberInitialListProbe extends MemberUnifiedQueryProvider
{
    public function __construct() {}

    public function userQuery(array $context, array $payload): array
    {
        return $this->authorizedUserQuery($context, $payload, true);
    }

    public function matcher(array $payload): ?callable
    {
        return $this->memberNameInitialMatcher($payload);
    }
}

function checkInitialList(string $name, bool $passed): void
{
    if (!$passed) {
        throw new \RuntimeException('FAIL ' . $name);
    }
    echo 'PASS ' . $name . PHP_EOL;
}

function keywordFilters(string $keyword): array
{
    return array_map(static function (string $field) use ($keyword): array {
        return ['field_key' => $field, 'operator' => 'contains', 'value' => $keyword];
    }, ['member_name', 'phone', 'member_no']);
}

$probe = new MemberInitialListProbe();
$sample = Db::query("SELECT u.uid,su.store_id,u.real_name,u.nickname,s.initials
    FROM eb_user u
    JOIN eb_store_user su ON su.uid=u.uid AND su.status=1
    JOIN eb_member_name_initial_search s ON s.uid=u.uid
    WHERE u.is_del=0 AND u.status=1 AND s.initials REGEXP '^[A-Z]{2,60}$'
      AND COALESCE(NULLIF(TRIM(u.real_name),''),TRIM(u.nickname)) REGEXP '^[^A-Za-z]'
    ORDER BY u.uid LIMIT 1");
if (!$sample) {
    throw new \RuntimeException('缺少可供只读验证的汉字姓名会员');
}
$member = $sample[0];
$prefix = substr((string)$member['initials'], 0, 2);
$scope = ['visible_store_ids' => [(int)$member['store_id']]];
$payload = ['keywordFilters' => keywordFilters(strtolower($prefix))];
list($query) = $probe->userQuery($scope, $payload);
$found = $query->where('u.uid', (int)$member['uid'])->find();
checkInitialList('姓名首字母索引可命中已授权会员',
    (int)($found['uid'] ?? 0) === (int)$member['uid']
    && (string)($found['member_name_initials'] ?? '') === (string)$member['initials']);
list($forbidden) = $probe->userQuery(['visible_store_ids' => [999999999]], $payload);
checkInitialList('同一关键词不能越过门店可见范围',
    $forbidden->where('u.uid', (int)$member['uid'])->find() === null);

$registry = UnifiedQueryPageRegistry::withDefaults();
$execution = new UnifiedQueryExecutionServices(
    $registry,
    new StructuredExpressionValidator($registry),
    new StructuredExpressionEvaluator()
);
$queryPayload = ['pageCode' => 'member_list', 'keyword' => strtolower($prefix), 'page' => 1, 'limit' => 20];
$rows = [
    ['member_id' => 1, 'member_name' => '汉字甲', 'phone' => '', 'member_no' => '', '_member_name_initials' => $prefix . 'A'],
    ['member_id' => 2, 'member_name' => '汉字乙', 'phone' => '', 'member_no' => '', '_member_name_initials' => $prefix . 'B'],
    ['member_id' => 3, 'member_name' => '汉字丙', 'phone' => '', 'member_no' => '', '_member_name_initials' => ''],
    ['member_id' => 4, 'member_name' => '普通会员', 'phone' => '', 'member_no' => $prefix . '-1', '_member_name_initials' => ''],
];
$result = $execution->execute('member_list', $rows, [], $queryPayload,
    ['query_cutoff_date' => date('Y-m-d'), 'permissions' => []],
    static function (array $row): bool { return (int)$row['member_id'] !== 2; },
    $probe->matcher($payload));
checkInitialList('二次过滤保留首字母及原会员编号匹配，但仍执行 DataScope',
    array_column($result['rows'], 'member_id') === [1, 4]
    && (int)$result['pagination']['total'] === 2);
checkInitialList('非纯字母关键词不启用首字母匹配',
    $probe->matcher(['keywordFilters' => keywordFilters('张1')]) === null);

// 真实 provider 必须把 SQL 候选和统一执行器串起来；只测二者单独通过会漏掉分页丢行。
$access = new UnifiedQueryAccessPolicy();
$references = new UnifiedQueryFieldReferenceServices();
$customFields = new UnifiedQueryCustomFieldServices(
    $registry, new StructuredExpressionValidator($registry), $access, $references
);
$preferences = new UnifiedQueryPreferenceServices(
    $registry, $customFields, $execution, $access, $references
);
$provider = new MemberUnifiedQueryProvider($execution, $customFields, $preferences);
$context = [
    'tenant_id' => '0',
    'account_id' => 1,
    'store_id' => (int)$member['store_id'],
    'visible_store_ids' => [(int)$member['store_id']],
    'permissions' => [UnifiedQueryAccessPolicy::PAGE_POLICY],
    'query_cutoff_date' => date('Y-m-d'),
    'data_as_of' => time(),
];
$list = $provider->query($context, [
    'keyword' => strtolower($prefix), 'page' => 1, 'limit' => 20,
]);
checkInitialList('会员列表 SQL 总数与二次筛选页数一致',
    (int)$list['total'] > 0 && count($list['records']) > 0
    && (int)$list['total'] >= count($list['records'])
    && !array_key_exists('_member_name_initials', $list['records'][0]));

$fallback = $provider->query($context, [
    'keyword' => strtolower($prefix), 'page' => 1, 'limit' => 20,
    'filters' => [['field' => 'member_status', 'operator' => 'is_not_null']],
]);
checkInitialList('高级筛选路径保留首字母匹配且总数一致',
    (int)$fallback['total'] === (int)$list['total']
    && count($fallback['records']) === count($list['records']));

$plan = $execution->validatedPlan('member_list', [], [
    'pageCode' => 'member_list', 'keyword' => strtolower($prefix), 'page' => 1, 'limit' => 20,
], $context);
$export = $provider->executeFrozenPlan($context, $plan, 'query', ['member_name']);
checkInitialList('冻结计划导出与列表共用首字母口径',
    count($export['exportRows']) === (int)$list['total']);
