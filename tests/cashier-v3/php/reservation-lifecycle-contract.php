<?php

$root = dirname(__DIR__, 3);
$module = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php');
$partition = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationPartitionProvider.php');
$detail = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationDetailQueryServices.php');
$provider = file_get_contents($root . '/后端代码/app/services/cashier/v3/checkout/provider/CashierV3EntitlementOccupationContributorVersionProvider.php');
$manifest = file_get_contents($root . '/后端代码/app/services/cashier/v3/manifest/CashierV3C3ServiceModule.php');
$events = file_get_contents($root . '/后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
$contextPolicy = file_get_contents($root . '/后端代码/app/services/cashier/v3/registry/CashierV3ContextPolicy.php');
$contextValidator = file_get_contents($root . '/后端代码/app/services/cashier/v3/CashierV3CommandContextServices.php');
$gateway = file_get_contents($root . '/后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php');
$bridge = file_get_contents($root . '/前端代码/cashier-v3/src/services/cashierV3Bridge.js');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-08-10-收银V3预约生命周期/02-正式升级.sql');
$entitlementMigration = file_get_contents($root . '/后端代码/database/upgrades/2026-08-10-收银V3预约已购项目来源/02-正式升级.sql');
$independentDocumentMigration = file_get_contents($root . '/后端代码/database/upgrades/2026-08-10-收银V3预约独立单据索引/02-正式升级.sql');

function reservationLifecycleOk(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

reservationLifecycleOk('新建预约使用未开始状态并保存项目时长',
    strpos($module, "'status' => self::STATUS_UNSTARTED") !== false
    && strpos($module, 'StoreProductReservationServices') !== false
    && strpos($module, "'service_duration_minutes' => (int)\$plan['duration']") !== false);
reservationLifecycleOk('预约项目选择器投影权威分类，且仍只接受项目品类',
    strpos($module, "(int)(\$item['productType'] ?? -1) !== 6") !== false
    && strpos($module, "'categoryName' =>") !== false
    && strpos($module, "'categoryNames' => \$categoryNames") !== false);
reservationLifecycleOk('预约项目选择器同时投影已购权益项目及全部可售项目',
    strpos($module, 'private static function purchasedProjectOptions') !== false
    && strpos($module, "'source' => 'card'") !== false
    && strpos($module, "'source' => 'unpaid'") !== false
    && strpos($module, "'entitlementSourceDetailId' => \$detailId") !== false
    && strpos($module, "->where('cart_type', 2)->where('product_type', 6)") !== false);
reservationLifecycleOk('预约保存仅保留已购项目的权益明细标识校验',
    strpos($module, 'private static function projectPlans(array $projects)') !== false
    && strpos($module, "['entitlementSourceDetailId'] ?? \$project['sourceDetailId']") !== false
    && strpos($module, '已购项目缺少权益明细') !== false
    && strpos($module, '已购项目剩余次数不足') === false);
reservationLifecycleOk('预约行保存并回显权益来源明细，旧行兼容为未付款来源',
    strpos($module, "'project_source' => \$isEntitlement ? 'ENTITLEMENT' : 'UNPAID'") !== false
    && strpos($module, "'entitlement_source_detail_id' => \$isEntitlement") !== false
    && strpos($module, "'entitlementSourceDetailId' => \$source === 'card'") !== false);
reservationLifecycleOk('旧待确认和已确认不迁移、不展示、不允许进入新生命周期',
    strpos($partition, "->whereIn('status', ['UNSTARTED', 'IN_SERVICE', 'COMPLETED'])") !== false
    && strpos($detail, "['UNSTARTED', 'IN_SERVICE', 'COMPLETED']") !== false
    && strpos($module, "[self::STATUS_UNSTARTED, 'PENDING_CONFIRMATION', 'CONFIRMED']") === false);
reservationLifecycleOk('预约新建和编辑不依赖工作台或预约资源版本，状态动作仍保持单据上下文',
    strpos($module, "foreach (['create-reservation', 'update-reservation'] as \$action)") !== false
    && strpos($module, "new CashierV3ContextPolicy(\$action, [], [], null, [], [], [], true)") !== false
    && strpos($module, "foreach (['cancel-reservation', 'start-reservation-service', 'end-reservation-service'] as \$action)") !== false
    && strpos($contextPolicy, 'allowsEmptyContexts') !== false
    && strpos($contextValidator, "if (!\$rawContexts && !empty(\$contract['allows_empty_contexts']))") !== false
    && strpos($gateway, "if (!empty(\$contract['allows_empty_contexts']) && !\$contexts)") !== false
    && strpos($bridge, "const reservationDataWrite = ['create-reservation', 'update-reservation'].includes(canonicalAction)") !== false);
reservationLifecycleOk('预约创建不以操作员员工档案作为功能门槛',
    strpos($module, "\$dataScope->employeeId() <= 0") === false);
reservationLifecycleOk('预约保存不进入原请求查询恢复流程',
    strpos($events, "'create-reservation' => 'query-reservation-result'") === false
    && strpos($events, "'update-reservation' => 'query-reservation-result'") === false);
reservationLifecycleOk('取消为可追溯的逻辑取消，不删除预约主表',
    strpos($module, "'status' => self::STATUS_CANCELLED") !== false
    && strpos($module, "'logicalCancel' => true") !== false
    && strpos($module, "Db::name('cashier_v3_reservation')->where('id', (int)\$header['id'])->delete") === false);
reservationLifecycleOk('编辑替换项目明细前后均写入预约事件快照',
    strpos($module, "'beforeLines' => \$beforeLines") !== false
    && strpos($module, "'afterLines' => self::planSnapshot") !== false
    && strpos($module, 'private static function lineSnapshot') !== false);
reservationLifecycleOk('开始和结束服务仅切换预约状态，不占用房间或写服务单',
    strpos($module, "'status' => self::STATUS_IN_SERVICE") !== false
    && strpos($module, "'status' => self::STATUS_COMPLETED") !== false
    && strpos($module, 'cashier_v3_service_order') === false
    && strpos($module, 'RoomOpenServiceGuardAuthority') === false
    && strpos($module, "cashier_v3_sales_order')->insert") === false);
reservationLifecycleOk('预约保存不注册资源版本、不使用行锁或 CAS 条件更新',
    strpos($module, "ensureRegistered(CashierV3ResourceScope") === false
    && strpos($module, 'private static function reservationHeader') !== false
    && strpos($module, '->lock(true)->find()') === false
    && strpos($module, "->where('version', \$before)") === false
    && strpos($module, "['version' => \$nextVersion]") !== false
    && strpos($provider, 'return $current;') !== false
    && strpos($provider, 'reservation_authority_version_bump_conflict') === false);
reservationLifecycleOk('结束服务已登记为无操作级权限门禁的正式事件命令',
    strpos($manifest, "'end-reservation-service'") !== false
    && strpos($manifest, 'POLICY_RESERVATION_OPERATION') !== false
    && strpos($events, "'required_event_types' => ['reservation.completed']") !== false);
reservationLifecycleOk('编辑精确项目规格所需 SKU 列通过兼容迁移新增',
    strpos($migration, '20260810-003-cashier-v3-reservation-lifecycle-v1') !== false
    && strpos($migration, 'ADD COLUMN `sku_id`') !== false
    && strpos($migration, 'information_schema.COLUMNS') !== false
    && strpos($migration, 'PREPARE reservation_lifecycle_stmt') !== false);
reservationLifecycleOk('权益来源明细列通过独立兼容迁移新增并保留旧行',
    strpos($entitlementMigration, '20260810-004-cashier-v3-reservation-entitlement-source-v1') !== false
    && strpos($entitlementMigration, 'ADD COLUMN `entitlement_source_detail_id`') !== false
    && strpos($entitlementMigration, "DEFAULT ''0''") !== false
    && strpos($entitlementMigration, 'idx_entitlement_source_detail') !== false);
reservationLifecycleOk('独立预约不允许服务单关联唯一索引阻塞连续保存',
    strpos($independentDocumentMigration, 'DROP INDEX `uk_tenant_service_order`') !== false
    && strpos($independentDocumentMigration, 'ADD KEY `idx_tenant_service_order`') !== false);

echo "RESERVATION_LIFECYCLE_CONTRACT=PASS\n";
