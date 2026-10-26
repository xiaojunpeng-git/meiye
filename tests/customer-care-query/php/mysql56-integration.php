<?php
declare(strict_types=1);

require_once '/tests/cashier-v3/lib/boot-env.php';
require_once '/var/www/html/vendor/autoload.php';
require_once '/tests/cashier-v3/lib/_lib.php';

use app\services\customer\care\CustomerCareClock;
use app\services\customer\care\CustomerCareCommandService;
use app\services\customer\care\ThinkPhpCustomerCareRepository;
use app\services\customer\care\integration\CustomerCareActionInputMapper;
use app\services\customer\care\integration\CustomerCareWorkbenchActionAdapter;
use app\services\customer\care\query\CustomerCareCursorCodec;
use app\services\customer\care\query\CustomerCareWorkbenchQueryService;
use app\services\customer\care\query\ThinkPhpCustomerCareQueryRepository;
use think\facade\Db;

final class CareQueryMysqlClock implements CustomerCareClock
{
    public function now(): int { return 1785286800; }

    public function businessDate(int $timestamp, string $timezone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('Y-m-d');
    }
}

c1aBootThinkApp('/var/www/html/');

function cqAssert(string $name, bool $condition, string $detail = ''): void
{
    ok($name, $condition, $detail);
}

function cqCreateLegacySchema(): void
{
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_employee` (
      `id` int(10) unsigned NOT NULL,
      `name` varchar(64) NOT NULL DEFAULT '',
      `status` tinyint(1) NOT NULL DEFAULT '1',
      `is_del` tinyint(1) NOT NULL DEFAULT '0',
      PRIMARY KEY (`id`), KEY `idx_employee_status_del` (`status`,`is_del`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_system_store_staff` (
      `id` int(10) unsigned NOT NULL,
      `employee_id` int(10) unsigned DEFAULT NULL,
      `store_id` int(10) unsigned NOT NULL DEFAULT '0',
      `staff_name` varchar(64) NOT NULL DEFAULT '',
      `status` tinyint(1) NOT NULL DEFAULT '1',
      `is_del` tinyint(1) NOT NULL DEFAULT '0',
      PRIMARY KEY (`id`),
      KEY `idx_staff_employee_store` (`employee_id`,`store_id`,`is_del`),
      KEY `idx_staff_store_employee` (`store_id`,`employee_id`,`is_del`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_system_store` (
      `id` int(10) unsigned NOT NULL, `name` varchar(100) NOT NULL DEFAULT '',
      `is_del` tinyint(1) NOT NULL DEFAULT '0', `is_show` tinyint(1) NOT NULL DEFAULT '1',
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_user` (
      `uid` int(10) unsigned NOT NULL,
      `nickname` varchar(100) NOT NULL DEFAULT '', `real_name` varchar(100) NOT NULL DEFAULT '',
      `phone` varchar(20) NOT NULL DEFAULT '', `bar_code` varchar(32) NOT NULL DEFAULT '',
      `belong_store_id` int(10) unsigned NOT NULL DEFAULT '0',
      `level` int(10) unsigned NOT NULL DEFAULT '0',
      `status` tinyint(1) NOT NULL DEFAULT '1', `is_del` tinyint(1) NOT NULL DEFAULT '0',
      PRIMARY KEY (`uid`), KEY `idx_phone` (`phone`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_store_user` (
      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `store_id` int(10) unsigned NOT NULL, `uid` int(10) unsigned NOT NULL,
      `status` tinyint(1) NOT NULL DEFAULT '1', `add_time` int(10) unsigned NOT NULL DEFAULT '0',
      PRIMARY KEY (`id`), UNIQUE KEY `uk_store_uid` (`store_id`,`uid`), KEY `idx_uid` (`uid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_system_user_level` (
      `id` int(10) unsigned NOT NULL, `name` varchar(64) NOT NULL DEFAULT '',
      `is_show` tinyint(1) NOT NULL DEFAULT '1', `is_del` tinyint(1) NOT NULL DEFAULT '0',
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_user_label` (
      `id` int(10) unsigned NOT NULL, `label_name` varchar(64) NOT NULL DEFAULT '',
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_user_label_relation` (
      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `uid` int(10) unsigned NOT NULL, `label_id` int(10) unsigned NOT NULL,
      PRIMARY KEY (`id`), KEY `idx_uid` (`uid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_member_exclusive_service` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `member_id` int(10) unsigned NOT NULL, `staff_id` int(10) unsigned NOT NULL,
      `employee_id` int(10) unsigned NOT NULL, `store_id` int(10) unsigned NOT NULL,
      `staff_name` varchar(64) NOT NULL DEFAULT '', `status` tinyint(1) NOT NULL DEFAULT '1',
      PRIMARY KEY (`id`), UNIQUE KEY `uk_member` (`member_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function cqResetAndSeed(): void
{
    Db::execute('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'customer_care_operation', 'customer_care_record', 'customer_care_task',
        'member_exclusive_service', 'user_label_relation', 'user_label',
        'system_user_level', 'store_user', 'user', 'system_store_staff',
        'employee', 'system_store',
    ] as $table) {
        Db::name($table)->delete(true);
    }
    Db::name('cashier_v3_command_receipt')->whereLike('idempotency_key', 'CARE_RECEIPT-%')->delete();
    Db::execute('SET FOREIGN_KEY_CHECKS=1');

    Db::name('system_store')->insertAll([
        ['id' => 8, 'name' => '八号门店', 'is_del' => 0, 'is_show' => 1],
        ['id' => 9, 'name' => '九号门店', 'is_del' => 0, 'is_show' => 1],
    ]);
    foreach ([101, 102, 201] as $staffId) {
        $storeId = $staffId === 201 ? 9 : 8;
        Db::name('employee')->insert([
            'id' => $staffId + 1000, 'name' => '员工' . $staffId, 'status' => 1, 'is_del' => 0,
        ]);
        Db::name('system_store_staff')->insert([
            'id' => $staffId, 'employee_id' => $staffId + 1000, 'store_id' => $storeId,
            'staff_name' => '员工' . $staffId, 'status' => 1, 'is_del' => 0,
        ]);
    }
    Db::name('system_user_level')->insert(['id' => 1, 'name' => '金卡', 'is_show' => 1, 'is_del' => 0]);
    Db::name('user_label')->insert(['id' => 1, 'label_name' => '重点维护']);
    foreach ([501 => 8, 502 => 8, 503 => 8, 601 => 9] as $uid => $storeId) {
        Db::name('user')->insert([
            'uid' => $uid, 'nickname' => '', 'real_name' => '会员' . $uid,
            'phone' => '13800000' . $uid, 'bar_code' => '100000' . $uid,
            'belong_store_id' => $storeId, 'level' => 1, 'status' => 1, 'is_del' => 0,
        ]);
        Db::name('store_user')->insert([
            'store_id' => $storeId, 'uid' => $uid, 'status' => 1, 'add_time' => 1785200000,
        ]);
    }
    Db::name('user_label_relation')->insert(['uid' => 501, 'label_id' => 1]);
    Db::name('member_exclusive_service')->insert([
        'member_id' => 501, 'staff_id' => 102, 'employee_id' => 1102,
        'store_id' => 8, 'staff_name' => '员工102', 'status' => 1,
    ]);
}

function cqContext(
    string $tenant = 'TENANT_1',
    int $staffId = 101,
    int $storeId = 8,
    bool $manager = true
): array {
    return [
        'tenantId' => $tenant,
        'staffId' => $staffId,
        'employeeId' => $staffId + 1000,
        'staffName' => '员工' . $staffId,
        'operationStoreId' => $storeId,
        'operationStoreName' => $storeId === 8 ? '八号门店' : '九号门店',
        'operationOrganizationId' => 'ORG_' . $storeId,
        'operationOrganizationPath' => '/ROOT/ORG_' . $storeId . '/',
        'operationOrganizationName' => $storeId === 8 ? '八号组织' : '九号组织',
        'allowedBusinessStoreIds' => [$storeId],
        'businessTimezone' => 'Asia/Shanghai',
        'canViewAllTasks' => $manager,
        'canCreateTask' => true,
        'canCreateRecord' => true,
        'canReassign' => $manager,
        'canViewStatistics' => $manager,
    ];
}

function cqAction(
    CustomerCareWorkbenchActionAdapter $adapter,
    string $action,
    array $context,
    array $payload,
    int $sequence,
    array $workbench = []
): array {
    return $adapter->handle(
        $action,
        $context,
        $payload,
        'gateway-care-query-' . str_pad((string)$sequence, 8, '0', STR_PAD_LEFT),
        $workbench
    );
}

function cqReceipt(array $response): array
{
    return is_array($response['result']['commandReceipt'] ?? null)
        ? $response['result']['commandReceipt']
        : [];
}

cqCreateLegacySchema();
cqResetAndSeed();

$clock = new CareQueryMysqlClock();
$queryRepository = new ThinkPhpCustomerCareQueryRepository();
$queryService = new CustomerCareWorkbenchQueryService(
    $queryRepository,
    $clock,
    new CustomerCareCursorCodec('mysql56-customer-care-query-secret-000001')
);
$commandService = new CustomerCareCommandService(new ThinkPhpCustomerCareRepository(), $clock);
$adapter = new CustomerCareWorkbenchActionAdapter(
    $commandService,
    $queryService,
    new CustomerCareActionInputMapper($queryRepository, $clock)
);

$allWorkbench = ['view' => 'tasks', 'query' => ['scope' => 'all', 'bucket' => 'all']];
$context = cqContext();
$now = $clock->now();
$dayEnd = (new DateTimeImmutable('@' . $now))
    ->setTimezone(new DateTimeZone('Asia/Shanghai'))->setTime(23, 59, 59)->getTimestamp();

$createPayloads = [
    ['memberId' => '501', 'taskTypeCode' => 'DAILY_FOLLOWUP', 'plannedAt' => $now - 3600, 'ownerId' => '101', 'content' => '逾期任务'],
    ['memberId' => '501', 'taskTypeCode' => 'SERVICE_FEEDBACK', 'plannedAt' => $now + 3600, 'ownerId' => '101', 'content' => '今日任务'],
    ['memberId' => '502', 'taskTypeCode' => 'INVITATION', 'plannedAt' => $dayEnd + 3600, 'ownerId' => '101', 'content' => '未来任务'],
    ['memberId' => '502', 'taskTypeCode' => 'FOLLOWUP', 'plannedAt' => $now - 7200, 'ownerId' => '101', 'content' => '保留逾期任务'],
];
$created = [];
foreach ($createPayloads as $index => $payload) {
    $response = cqAction($adapter, 'create-care-task', $context, $payload, 100 + $index, $allWorkbench);
    cqAssert('create task returns complete projection ' . $index,
        ($response['result']['status'] ?? '') === 'success'
        && ($response['projection']['customerCare']['contractVersion'] ?? '') === 'customer-care.v1'
        && isset($response['projection']['customerCare']['settings']));
    $created[] = cqReceipt($response);
}

$store9 = cqAction($adapter, 'create-care-task', cqContext('TENANT_1', 201, 9), [
    'memberId' => '601', 'taskTypeCode' => 'DAILY_FOLLOWUP',
    'plannedAt' => $now + 1800, 'ownerId' => '201', 'content' => '九店任务',
], 200, ['view' => 'tasks', 'query' => ['scope' => 'my', 'bucket' => 'all']]);
cqAssert('other store task created in isolated scope', ($store9['result']['status'] ?? '') === 'success');
$tenant2 = cqAction($adapter, 'create-care-task', cqContext('TENANT_2'), [
    'memberId' => '501', 'taskTypeCode' => 'DAILY_FOLLOWUP',
    'plannedAt' => $now + 2400, 'ownerId' => '101', 'content' => '另一租户任务',
], 201, $allWorkbench);
cqAssert('other tenant task created in isolated scope', ($tenant2['result']['status'] ?? '') === 'success');

// Simulate a member who had a historical record in store 9 and now belongs only to store 8.
Db::name('store_user')->insert([
    'store_id' => 9, 'uid' => 501, 'status' => 1, 'add_time' => 1785100000,
]);
$historicalStore9Record = cqAction(
    $adapter,
    'create-care-record',
    cqContext('TENANT_1', 201, 9),
    [
        'memberId' => '501', 'recordTypeCode' => 'DAILY_FOLLOWUP',
        'followedAt' => $now - 500, 'methodCode' => 'OTHER',
        'content' => '会员历史九店客情', 'resultCode' => 'FOLLOW_UP',
    ],
    202,
    ['view' => 'records', 'query' => ['stream' => 'human', 'dataScope' => 'normal']]
);
cqAssert('historical other-store record created before member scope moved',
    ($historicalStore9Record['result']['status'] ?? '') === 'success');
Db::name('store_user')->where('store_id', 9)->where('uid', 501)->delete();

$inaccessibleStore9Record = cqAction($adapter, 'create-care-record', cqContext('TENANT_1', 201, 9), [
    'memberId' => '601', 'recordTypeCode' => 'DAILY_FOLLOWUP',
    'followedAt' => $now - 400, 'methodCode' => 'PHONE',
    'content' => '九店当前会员客情', 'resultCode' => 'SATISFIED',
], 203, ['view' => 'records', 'query' => ['stream' => 'human', 'dataScope' => 'normal']]);
cqAssert('other-store current member record created in isolated scope',
    ($inaccessibleStore9Record['result']['status'] ?? '') === 'success');

$tenant2Record = cqAction($adapter, 'create-care-record', cqContext('TENANT_2'), [
    'memberId' => '501', 'recordTypeCode' => 'DAILY_FOLLOWUP',
    'followedAt' => $now - 300, 'methodCode' => 'PHONE',
    'content' => '另一租户客情', 'resultCode' => 'SATISFIED',
], 204, ['view' => 'records', 'query' => ['stream' => 'human', 'dataScope' => 'normal']]);
cqAssert('other-tenant record created in isolated scope',
    ($tenant2Record['result']['status'] ?? '') === 'success');

$firstTaskId = (int)$created[0]['taskId'];
$start = cqAction($adapter, 'start-care-task', $context, [
    'taskId' => (string)$firstTaskId, 'expectedVersion' => '1',
], 300, $allWorkbench);
cqAssert('start action returns refreshed authoritative task', ($start['result']['status'] ?? '') === 'success'
    && (int)cqReceipt($start)['taskVersion'] === 2);

$completePayload = [
    'taskId' => (string)$firstTaskId, 'expectedVersion' => '2',
    'methodCode' => 'PHONE', 'content' => '客户反馈状态稳定',
    'resultCode' => 'SATISFIED', 'createNextTask' => false,
];
$complete = cqAction($adapter, 'complete-care-task', $context, $completePayload, 301, $allWorkbench);
$completeReceipt = cqReceipt($complete);
cqAssert('complete action writes task and formal record', ($complete['result']['status'] ?? '') === 'success'
    && (int)$completeReceipt['taskVersion'] === 3
    && (int)$completeReceipt['recordVersion'] === 1
    && (int)$completeReceipt['recordId'] > 0);
$completeReplay = cqAction($adapter, 'complete-care-task', $context, $completePayload, 301, $allWorkbench);
cqAssert('same gateway key replays same completion and still refreshes projection',
    ($completeReplay['result']['status'] ?? '') === 'success'
    && (cqReceipt($completeReplay)['replayed'] ?? false) === true
    && (int)cqReceipt($completeReplay)['recordId'] === (int)$completeReceipt['recordId']
    && isset($completeReplay['projection']['customerCare']['taskView']));

$memberScopedTaskDetail = $queryService->taskDetail(
    cqContext('TENANT_1', 102, 8, false),
    (int)$created[1]['taskId']
);
cqAssert('member DataScope permits non-owner read detail without granting task actions',
    is_array($memberScopedTaskDetail)
    && ($memberScopedTaskDetail['member']['memberId'] ?? '') === '501'
    && ($memberScopedTaskDetail['availableActions'] ?? null) === []);
$crossStoreTaskDetail = $queryService->taskDetail(
    cqContext('TENANT_1', 102, 8, false),
    (int)cqReceipt($store9)['taskId']
);
cqAssert('member DataScope rejects another-store member detail', $crossStoreTaskDetail === null);

$memberHistory = $adapter->handle('query-care-workbench', cqContext('TENANT_1', 102, 8, false), [
    'view' => 'records',
    'query' => ['stream' => 'human', 'dataScope' => 'normal'],
]);
$memberHistoryRows = $memberHistory['projection']['customerCare']['recordView']['records'] ?? [];
cqAssert('record history follows member DataScope, not creator or historical record store',
    (int)($memberHistory['projection']['customerCare']['recordView']['total'] ?? -1) === 2
    && array_column($memberHistoryRows, 'storeName') === ['八号门店', '九号门店']
    && array_filter($memberHistoryRows, static function (array $row): bool {
        return ($row['availableActions'] ?? []) !== [];
    }) === []);

$nonCreatorVoid = cqAction($adapter, 'void-care-record', cqContext('TENANT_1', 102, 8, false), [
    'recordId' => (string)$completeReceipt['recordId'],
    'expectedTaskVersion' => (string)$completeReceipt['taskVersion'],
    'expectedRecordVersion' => (string)$completeReceipt['recordVersion'],
    'reason' => '越权作废尝试',
], 399, $allWorkbench);
cqAssert('member read scope never grants record mutation ownership',
    ($nonCreatorVoid['result']['code'] ?? '') === 'CARE_OWNER_REQUIRED');

$conflict = cqAction($adapter, 'start-care-task', $context, [
    'taskId' => (string)$created[1]['taskId'], 'expectedVersion' => '99',
], 302, $allWorkbench);
cqAssert('version conflict includes current task summary', ($conflict['result']['status'] ?? '') === 'conflict'
    && ($conflict['result']['code'] ?? '') === 'CARE_VERSION_CONFLICT'
    && (int)($conflict['result']['latestTask']['taskVersion'] ?? 0) === 1);

$queryPage1 = $adapter->handle('query-care-workbench', $context, [
    'view' => 'tasks',
    'query' => ['scope' => 'all', 'bucket' => 'all', 'pageSize' => 2],
]);
$care1 = $queryPage1['projection']['customerCare'] ?? [];
$page1Ids = array_column($care1['taskView']['records'] ?? [], 'taskId');
$cursor = (string)($care1['taskView']['paginationCursor'] ?? '');
cqAssert('task keyset first page is bounded', ($queryPage1['result']['status'] ?? '') === 'success'
    && count($page1Ids) === 2 && $cursor !== '' && ($care1['taskView']['hasMore'] ?? false) === true);
$queryPage2 = $adapter->handle('query-care-workbench', $context, [
    'view' => 'tasks',
    'query' => ['scope' => 'all', 'bucket' => 'all', 'pageSize' => 2, 'cursor' => $cursor],
]);
$care2 = $queryPage2['projection']['customerCare'] ?? [];
$page2Ids = array_column($care2['taskView']['records'] ?? [], 'taskId');
cqAssert('task keyset pages do not overlap', $page2Ids !== []
    && array_intersect($page1Ids, $page2Ids) === []);
cqAssert('tenant and allowed store DataScope enforced', (int)($care1['taskView']['total'] ?? 0) === 4
    && !in_array((string)cqReceipt($store9)['taskId'], $page1Ids, true)
    && !in_array((string)cqReceipt($tenant2)['taskId'], $page1Ids, true));
cqAssert('server mutually exclusive counts after completion', ($care1['taskView']['bucketCounts'] ?? []) === [
    'today' => 1, 'overdue' => 1, 'future' => 1, 'completed' => 1, 'all' => 4,
]);
$statisticsDetail = $adapter->handle('query-care-workbench', $context, [
    'view' => 'statistics',
    'query' => ['metric' => 'care_open_workload'],
]);
$statisticsMetrics = [];
foreach (($statisticsDetail['projection']['customerCare']['statistics']['metrics'] ?? []) as $metric) {
    $statisticsMetrics[(string)($metric['code'] ?? '')] = (int)($metric['value'] ?? 0);
}
$openDetail = $statisticsDetail['projection']['customerCare']['statistics']['detail'] ?? [];
cqAssert('statistics open-workload detail uses the same authoritative task scope',
    ($openDetail['entityType'] ?? '') === 'task'
    && (int)($openDetail['total'] ?? -1) === ($statisticsMetrics['care_open_workload'] ?? -2)
    && count($openDetail['records'] ?? []) <= (int)($openDetail['total'] ?? 0));
$employeeCompletedDetail = $adapter->handle('query-care-workbench', $context, [
    'view' => 'statistics',
    'query' => ['metric' => 'employee_completed_by_actual_follower', 'staffId' => '101'],
]);
$employeeDetail = $employeeCompletedDetail['projection']['customerCare']['statistics']['detail'] ?? [];
cqAssert('employee completion detail filters by actual follower and keeps member DataScope',
    ($employeeDetail['entityType'] ?? '') === 'record'
    && ($employeeDetail['staffId'] ?? '') === '101'
    && array_filter($employeeDetail['records'] ?? [], static function (array $record): bool {
        return ($record['actualFollowerName'] ?? '') !== '员工101';
    }) === []);

$selfOnly = $adapter->handle('query-care-workbench', cqContext('TENANT_1', 102, 8, false), [
    'view' => 'tasks', 'query' => ['scope' => 'my', 'bucket' => 'all'],
]);
cqAssert('self scope cannot see another owner tasks', (int)($selfOnly['projection']['customerCare']['taskView']['total'] ?? -1) === 0);
$selfWiden = $adapter->handle('query-care-workbench', cqContext('TENANT_1', 102, 8, false), [
    'view' => 'tasks', 'query' => ['scope' => 'all', 'bucket' => 'all'],
]);
cqAssert('client cannot widen self scope to all', ($selfWiden['result']['code'] ?? '') === 'CARE_QUERY_FORBIDDEN'
    && !isset($selfWiden['projection']));

$customer = $adapter->handle('query-care-workbench', $context, [
    'view' => 'customers', 'query' => ['keyword' => '员工102'],
]);
$customerRows = $customer['projection']['customerCare']['customerView']['records'] ?? [];
cqAssert('customer summary uses authorized exclusive service snapshot', count($customerRows) === 1
    && ($customerRows[0]['memberId'] ?? '') === '501'
    && ($customerRows[0]['exclusiveServiceName'] ?? '') === '员工102');
$noHistoryCustomer = $adapter->handle('query-care-workbench', $context, [
    'view' => 'customers', 'query' => ['keyword' => '会员503'],
]);
cqAssert('customer view comes from member DataScope even without care activity',
    array_column(
        $noHistoryCustomer['projection']['customerCare']['customerView']['records'] ?? [],
        'memberId'
    ) === ['503']);
$crossStoreCustomer = $adapter->handle('query-care-workbench', $context, [
    'view' => 'customers', 'query' => ['keyword' => '会员601'],
]);
cqAssert('customer view excludes members outside current DataScope',
    ($crossStoreCustomer['projection']['customerCare']['customerView']['records'] ?? null) === []);

$appointment = $adapter->handle('query-care-workbench', $context, [
    'view' => 'records', 'query' => ['stream' => 'appointment', 'dataScope' => 'normal'],
]);
cqAssert('appointment stream fails closed without C3',
    ($appointment['projection']['customerCare']['recordView']['records'] ?? null) === []
    && ($appointment['projection']['customerCare']['recordView']['streamCapability']['enabled'] ?? true) === false);
$appointmentWrite = cqAction($adapter, 'create-care-record', $context, [
    'memberId' => '501', 'recordTypeCode' => 'FOLLOWUP', 'followedAt' => $now - 60,
    'methodCode' => 'PHONE', 'content' => '预约结果尝试', 'resultCode' => 'APPOINTMENT_SUCCESS',
], 400, $allWorkbench);
cqAssert('appointment success stays a pure customer-care result before appointment integration',
    ($appointmentWrite['result']['status'] ?? '') === 'success'
    && (int)(cqReceipt($appointmentWrite)['recordId'] ?? 0) > 0
    && (int)(cqReceipt($appointmentWrite)['taskId'] ?? 0) === 0);

$voidLinked = cqAction($adapter, 'void-care-record', $context, [
    'recordId' => (string)$completeReceipt['recordId'],
    'expectedTaskVersion' => (string)$completeReceipt['taskVersion'],
    'expectedRecordVersion' => (string)$completeReceipt['recordVersion'],
    'reason' => '跟进对象录入错误',
], 401, $allWorkbench);
cqAssert('linked record void consumes both explicit versions', ($voidLinked['result']['status'] ?? '') === 'success'
    && (int)cqReceipt($voidLinked)['taskVersion'] === 4
    && (int)cqReceipt($voidLinked)['recordVersion'] === 2);

$standalone = cqAction($adapter, 'create-care-record', $context, [
    'memberId' => '502', 'recordTypeCode' => 'DAILY_FOLLOWUP',
    'followedAt' => $now - 120, 'methodCode' => 'WECHAT',
    'content' => '独立客情记录', 'resultCode' => 'FOLLOW_UP',
], 402, $allWorkbench);
$standaloneReceipt = cqReceipt($standalone);
$voidStandalone = cqAction($adapter, 'void-care-record', $context, [
    'recordId' => (string)$standaloneReceipt['recordId'],
    'expectedVersion' => (string)$standaloneReceipt['recordVersion'],
    'reason' => '独立记录录入错误',
], 403, $allWorkbench);
cqAssert('standalone record void uses record version only', ($voidStandalone['result']['status'] ?? '') === 'success'
    && (int)cqReceipt($voidStandalone)['taskId'] === 0
    && (int)cqReceipt($voidStandalone)['recordVersion'] === 2);

$explain = Db::query("EXPLAIN SELECT id FROM eb_customer_care_task
  WHERE tenant_id='TENANT_1' AND business_store_id=8 AND status='UNSTARTED' AND is_visible=1
  ORDER BY planned_at,id LIMIT 100");
$possible = (string)($explain[0]['possible_keys'] ?? '');
$used = (string)($explain[0]['key'] ?? '');
cqAssert('MySQL56 task query exposes canonical covering candidate index',
    strpos($possible . ',' . $used, 'idx_store_status_plan') !== false,
    'possible=' . $possible . ' used=' . $used);

$memberScopeExplain = Db::query("EXPLAIN SELECT r.id
  FROM eb_customer_care_record r
  WHERE r.tenant_id='TENANT_1'
    AND EXISTS (
      SELECT su.uid FROM eb_store_user su
      WHERE su.uid=r.member_id AND su.store_id IN (8) AND su.status=1
    )
  ORDER BY r.followed_at DESC,r.id DESC LIMIT 100");
$memberScopeKeys = [];
foreach ($memberScopeExplain as $row) {
    $memberScopeKeys[] = (string)($row['possible_keys'] ?? '');
    $memberScopeKeys[] = (string)($row['key'] ?? '');
}
$memberScopeKeyText = implode(',', $memberScopeKeys);
cqAssert('MySQL56 member DataScope correlation exposes store-user index',
    strpos($memberScopeKeyText, 'idx_uid') !== false
        || strpos($memberScopeKeyText, 'uk_store_uid') !== false,
    'keys=' . $memberScopeKeyText);

finish('CUSTOMER_CARE_QUERY_MYSQL56');
