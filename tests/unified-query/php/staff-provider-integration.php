<?php

/**
 * 门店员工统一查询真实 Provider 集成门禁。
 */
require __DIR__ . '/../../cashier-v3/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../../cashier-v3/lib/_lib.php';
require __DIR__ . '/../../cashier-v3/lib/TestGraphFactory.php';
require __DIR__ . '/../../cashier-v3/lib/MemberIntegrationFixture.php';

use app\services\query\UnifiedQueryAccessPolicy;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryRuntime;
use app\services\query\provider\StaffUnifiedQueryProvider;
use C1A\CashierV3\Test\MemberIntegrationFixture;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');
date_default_timezone_set('Asia/Shanghai');

function uqStaffEnsureColumn(string $table, string $column, string $definition): void
{
    $exists = (int)Db::query(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
        [$table, $column]
    )[0]['c'];
    if ($exists === 0) {
        Db::execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function uqStaffContext(int $storeId, array $visibleStoreIds): array
{
    return [
        'tenant_id' => 'test-tenant',
        'account_id' => 1,
        'operator_id' => 1,
        'store_id' => $storeId,
        'organization_id' => '3',
        'page_code' => StaffUnifiedQueryProvider::PAGE_CODE,
        'visible_store_ids' => $visibleStoreIds,
        'ancestor_organization_ids' => ['1', '2', '3'],
        'shareable_store_ids' => $visibleStoreIds,
        'shareable_organization_ids' => ['3'],
        'permissions' => [UnifiedQueryAccessPolicy::PAGE_POLICY],
        'query_cutoff_date' => '2026-08-01',
        'data_as_of' => 1785542400,
        'permission_version' => 'staff-permission-1',
        'query_preference_version' => 1,
    ];
}

MemberIntegrationFixture::ensureLegacySchema();
MemberIntegrationFixture::resetAndSeed();

foreach ([
    'uid' => 'int unsigned NOT NULL DEFAULT 0',
    'phone' => "varchar(15) NOT NULL DEFAULT ''",
    'pwd' => "varchar(100) NOT NULL DEFAULT ''",
    'position' => 'int NOT NULL DEFAULT 0',
    'position_level' => 'int NOT NULL DEFAULT 0',
    'is_manager' => 'tinyint NOT NULL DEFAULT 0',
    'is_fencheng' => 'tinyint NOT NULL DEFAULT 0',
    'employee_number' => "varchar(64) NOT NULL DEFAULT ''",
    'join_date' => 'date DEFAULT NULL',
    'id_card' => "varchar(64) NOT NULL DEFAULT ''",
    'birthday_date' => 'date DEFAULT NULL',
    'age' => 'int NOT NULL DEFAULT 0',
    'join_area' => "varchar(128) NOT NULL DEFAULT ''",
    'birthday_area' => "varchar(128) NOT NULL DEFAULT ''",
    'now_area' => "varchar(128) NOT NULL DEFAULT ''",
    'contract_begin' => 'date DEFAULT NULL',
    'contract_end' => 'date DEFAULT NULL',
    'is_customer' => 'tinyint NOT NULL DEFAULT 0',
    'is_reservable' => 'tinyint NOT NULL DEFAULT 1',
    'department' => "varchar(128) NOT NULL DEFAULT ''",
    'salary_status' => 'tinyint NOT NULL DEFAULT 1',
    'birthday_type' => 'tinyint NOT NULL DEFAULT 0',
] as $column => $definition) {
    uqStaffEnsureColumn('eb_system_store_staff', $column, $definition);
}
uqStaffEnsureColumn('eb_user', 'salesman_id', 'int unsigned NOT NULL DEFAULT 0');

Db::execute("CREATE TABLE IF NOT EXISTS `eb_position` (
  `id` int unsigned NOT NULL,
  `name` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
Db::execute("CREATE TABLE IF NOT EXISTS `eb_position_level` (
  `id` int unsigned NOT NULL,
  `name` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
Db::execute("CREATE TABLE IF NOT EXISTS `eb_system_role` (
  `id` int unsigned NOT NULL,
  `role_name` varchar(64) NOT NULL DEFAULT '',
  `type` tinyint NOT NULL DEFAULT 1,
  `relation_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

Db::execute("REPLACE INTO `eb_position` (`id`,`name`) VALUES (1,'美容师')");
Db::execute("REPLACE INTO `eb_position_level` (`id`,`name`) VALUES (1,'高级')");
Db::execute("REPLACE INTO `eb_system_role`
  (`id`,`role_name`,`type`,`relation_id`,`status`)
  VALUES (1,'门店员工',1,8,1)");

Db::name('system_store_staff')->where('id', 30)->update([
    'uid' => 930,
    'phone' => '13900000930',
    'position' => 1,
    'position_level' => 1,
    'employee_number' => 'YG0030',
    'cashier_salesperson_enabled' => 1,
    'cashier_craftsman_enabled' => 0,
]);
Db::name('system_store_staff')->where('id', 31)->update([
    'uid' => 931,
    'phone' => '13900000931',
    'cashier_salesperson_enabled' => 0,
    'cashier_craftsman_enabled' => 1,
]);
Db::name('system_store_staff')->where('id', 33)->update([
    'cashier_salesperson_enabled' => 1,
    'cashier_craftsman_enabled' => 1,
]);
Db::name('user')->insertAll([
    [
        'uid' => 930,
        'nickname' => '员工会员账号',
        'phone' => '13900000930',
        'bar_code' => 'STAFF930',
        'belong_store_id' => 8,
        'status' => 1,
        'is_del' => 0,
        'add_time' => time(),
        'salesman_id' => 30,
    ],
    [
        'uid' => 932,
        'nickname' => '专属客户',
        'phone' => '13900000932',
        'bar_code' => 'STAFF932',
        'belong_store_id' => 8,
        'status' => 1,
        'is_del' => 0,
        'add_time' => time(),
        'salesman_id' => 30,
    ],
]);

UnifiedQueryRuntime::resetForTests();
$runtime = UnifiedQueryRuntime::runtime();
$provider = $runtime['providers']->resolve(StaffUnifiedQueryProvider::PAGE_CODE);
$normal = $provider->query(uqStaffContext(8, [8]), [
    'pageCode' => StaffUnifiedQueryProvider::PAGE_CODE,
    'page' => 1,
    'limit' => 50,
    'keyword' => '',
    'dataScope' => 'normal',
    'businessStatus' => '',
    'filters' => [],
    'sorts' => [['fieldKey' => 'staff_id', 'direction' => 'asc']],
]);

$records = (array)($normal['records'] ?? []);
$ids = array_map('intval', array_column($records, 'staffId'));
$staff30 = [];
$staff31 = [];
foreach ($records as $record) {
    if ((int)($record['staffId'] ?? 0) === 30) {
        $staff30 = $record;
    }
    if ((int)($record['staffId'] ?? 0) === 31) {
        $staff31 = $record;
    }
}

ok(
    '员工统一查询只返回当前门店且正常范围排除离职员工',
    in_array(30, $ids, true)
        && in_array(31, $ids, true)
        && !in_array(32, $ids, true)
        && !in_array(33, $ids, true),
    json_encode($ids),
    'UQ-STAFF-SCOPE-01'
);
ok(
    '员工统一查询保留旧列表字段与两个独立任职开关',
    (int)($staff30['uid'] ?? 0) === 930
        && (string)($staff30['employee_number'] ?? '') === 'YG0030'
        && (string)($staff30['position_label'] ?? '') === '美容师'
        && (string)($staff30['position_level_label'] ?? '') === '高级'
        && ($staff30['salespersonEnabled'] ?? false) === true
        && ($staff30['craftsmanEnabled'] ?? true) === false
        && ($staff31['salespersonEnabled'] ?? true) === false
        && ($staff31['craftsmanEnabled'] ?? false) === true,
    json_encode(compact('staff30', 'staff31'), JSON_UNESCAPED_UNICODE),
    'UQ-STAFF-FIELDS-01'
);
ok(
    '专属客户数使用员工维度聚合结果',
    (int)($staff30['customer_num'] ?? -1) === 2,
    json_encode($staff30, JSON_UNESCAPED_UNICODE),
    'UQ-STAFF-CUSTOMER-COUNT-01'
);

$denied = '';
try {
    $provider->query(uqStaffContext(8, [9]), [
        'pageCode' => StaffUnifiedQueryProvider::PAGE_CODE,
        'page' => 1,
        'limit' => 20,
        'dataScope' => 'normal',
    ]);
} catch (UnifiedQueryException $exception) {
    $denied = $exception->getErrorCode();
}
ok(
    '员工统一查询拒绝不在可见门店范围内的当前门店',
    $denied === 'UNIFIED_QUERY_PERMISSION_DENIED',
    $denied,
    'UQ-STAFF-SCOPE-02'
);

ok(
    '员工统一查询导出工作线程已登记专属上下文恢复器',
    $runtime['workerContextResolvers']->resolve(StaffUnifiedQueryProvider::PAGE_CODE)
        ->pageCode() === StaffUnifiedQueryProvider::PAGE_CODE,
    '',
    'UQ-STAFF-WORKER-01'
);

echo "STAFF_UNIFIED_QUERY_PROVIDER=PASS\n";
