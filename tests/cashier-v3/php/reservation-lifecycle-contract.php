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
$lifecycle = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationLifecycleServices.php');
$checkoutEntitlement = file_get_contents($root . '/后端代码/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php');
$projection = file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php');
$completionFacts = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationCompletionFactServices.php');
$member = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/MemberV3ReservationServices.php');
$mobileReservation = file_get_contents($root . '/后端代码/app/services/mobile/reservation/MobileReservationServices.php');
$mobileController = file_get_contents($root . '/后端代码/app/controller/mobile/merchant/Reservation.php');
$crossClientMigration = file_get_contents($root . '/后端代码/database/upgrades/2026-09-01-预约三端V3闭环/02-正式升级.sql');

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
reservationLifecycleOk('预约不冻结收银权益，结账只读取实时卡项余额',
    strpos($module, 'occupyLinesInTx($tenant') === false
    && strpos($member, 'occupyLinesInTx($tenantId') === false
    && strpos($projection, "'剩余次数已被预约占用'") === false
    && strpos($checkoutEntitlement, "->where('service_order.source_type', '<>', 'RESERVATION')") !== false);
reservationLifecycleOk('预约行保存并回显权益来源明细，旧行兼容为未付款来源',
    strpos($module, "'project_source' => \$isEntitlement ? 'ENTITLEMENT' : 'UNPAID'") !== false
    && strpos($module, "'entitlement_source_detail_id' => \$isEntitlement") !== false
    && strpos($module, "'entitlementSourceDetailId' => \$source === 'card'") !== false);
reservationLifecycleOk('历史不迁移也不展示，仅新代际进入三端生命周期',
    strpos($partition, 'CashierV3ReservationLifecycleServices::GENERATION') !== false
    && strpos($detail, 'CashierV3ReservationLifecycleServices::GENERATION') !== false
    && strpos($crossClientMigration, 'No historical rows are updated') !== false);
reservationLifecycleOk('预约新建和编辑不依赖工作台或预约资源版本，状态动作仍保持单据上下文',
    strpos($module, "foreach (['create-reservation', 'update-reservation'] as \$action)") !== false
    && strpos($module, "new CashierV3ContextPolicy(\$action, [], [], null, [], [], [], true)") !== false
    && strpos($module, "'confirm-reservation', 'reject-reservation', 'start-reservation-service'") !== false
    && strpos($contextPolicy, 'allowsEmptyContexts') !== false
    && strpos($contextValidator, "if (!\$rawContexts && !empty(\$contract['allows_empty_contexts']))") !== false
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
reservationLifecycleOk('开始服务写服务单，结束服务按实时权益余额处理',
    strpos($module, "'status' => self::STATUS_IN_SERVICE") !== false
    && strpos($module, "'status' => self::STATUS_COMPLETED") !== false
    && strpos($lifecycle, "Db::name('cashier_v3_service_order')->insertGetId") !== false
    && strpos($lifecycle, 'actual_service_started_at') === false
    && strpos($lifecycle, 'liveEntitlementCandidatesInTx') !== false
    && strpos($lifecycle, "'insufficientEntitlements'") !== false);
reservationLifecycleOk('结束服务同一事务写核销、服务、消耗与劳动业绩事实',
    strpos($lifecycle, 'CashierV3ReservationCompletionFactServices') !== false
    && strpos($completionFacts, "cashier_v3_entitlement_writeoff_fact") !== false
    && strpos($completionFacts, "cashier_v3_entitlement_service_fact") !== false
    && strpos($completionFacts, "consumption_performance_recorded") !== false
    && strpos($completionFacts, "labor_performance_allocated") !== false
    && strpos($completionFacts, 'CustomerLifecycleFactServices') !== false);
reservationLifecycleOk('结束服务遇到来源权益欠款时完成服务但不直接核销',
    strpos($lifecycle, 'debtBlockedOccupationIdsInTx') !== false
    && strpos($lifecycle, 'debtBlockedEntitlementSnapshots') !== false
    && strpos($lifecycle, "'debtBlockedEntitlements'") !== false
    && strpos($lifecycle, "\$facts['manualWriteoffRequired']") !== false
    && strpos($module, '该顾客有欠款，无法直接核销权益，请手动操作。') !== false
    && strpos($module, '卡项「') !== false
    && strpos($module, '」有欠款，项目「') !== false
    && strpos($module, "'persistent' => true") !== false
    && strpos($mobileReservation, "'idempotencyKey', 'requiresRefresh', 'feedback'") !== false);
reservationLifecycleOk('预约详情逐项目返回登记、已扣权益、欠款和余额不足结果',
    strpos($detail, 'private function projectOutcomes') !== false
    && strpos($detail, "'processingStatus' => 'registration_only'") !== false
    && strpos($detail, "'processingStatus' => 'entitlement_deducted'") !== false
    && strpos($detail, "'processingStatus' => 'debt_blocked'") !== false
    && strpos($detail, "'processingStatus' => 'insufficient_entitlement'") !== false
    && strpos($detail, "'processingStatusLabel' => '仅预约登记'") !== false
    && strpos($detail, "'processingStatusLabel' => '已扣权益'") !== false
    && strpos($detail, "'processingStatusLabel' => '欠款未扣权益'") !== false
    && strpos($detail, "'processingStatusLabel' => '权益不足未扣'") !== false);
reservationLifecycleOk('会员待确认预约不能绕过确认直接开始服务',
    strpos($module, "(string)\$header['status'] !== self::STATUS_UNSTARTED") !== false
    && strpos($module, '会员预约须先确认') !== false);
reservationLifecycleOk('预约写入使用事务行锁与 CAS，但不对用户暴露版本管理',
    strpos($module, "ensureRegistered(CashierV3ResourceScope") === false
    && strpos($module, 'private static function reservationHeader') !== false
    && strpos($module, '->lock(true)->find()') !== false
    && strpos($module, "->where('version', \$before)") !== false
    && strpos($module, "['version' => \$nextVersion]") !== false
    && strpos($provider, 'return $current;') !== false
    && strpos($provider, 'reservation_authority_version_bump_conflict') === false);
reservationLifecycleOk('会员与门店预约均不占用权益，门店创建直接待服务',
    strpos($member, "'status' => 'PENDING_CONFIRMATION'") !== false
    && strpos($module, "'status' => self::STATUS_UNSTARTED") !== false
    && strpos($member, 'occupyLinesInTx($tenantId') === false
    && strpos($module, 'occupyLinesInTx($tenant') === false);
reservationLifecycleOk('会员 V3 DTO 直接兼容原列表和详情展示契约',
    strpos($member, "'project_list' => \$projectList") !== false
    && strpos($member, "'cart_info' => \$firstCartInfo") !== false
    && strpos($member, "'reservation_show_time'") !== false
    && strpos($member, "'reservation_name'") !== false
    && strpos($member, "'store_order_id'") !== false
    && strpos($member, "'service_images' => []") !== false
    && strpos($member, "Db::name('store_reservation") === false
    && strpos($member, '->getOrderInfo(') === false);
reservationLifecycleOk('会员增项已购项目与主项目一起在结束服务时结算',
    strpos($member, 'private function addonDetails') !== false
    && strpos($member, "'role_code' => 'ADDON'") !== false
    && strpos($member, "'entitlement_source_detail_id' => (int)\$addon['id']") !== false
    && strpos($lifecycle, 'liveEntitlementCandidatesInTx') !== false);
reservationLifecycleOk('会员已取消或已拒绝新单仅软删除展示，不删权威事实',
    strpos($member, "['CANCELLED', 'REJECTED']") !== false
    && strpos($member, "'member_deleted_at' => \$now") !== false
    && strpos($member, "'operation_type' => 'MEMBER_HIDE'") !== false
    && strpos($member, "->where('member_deleted_at', 0)") !== false);
reservationLifecycleOk('商家确认与服务工作流透传筛选和分页',
    strpos($mobileController, 'readPayload') !== false
    && strpos($mobileReservation, "\$workflow === 'confirmation'") !== false
    && strpos($mobileReservation, "\$workflow === 'service' && \$quickFilter === ''") !== false
    && strpos($partition, "\$quickFilter === 'pending_confirmation'") !== false
    && strpos($partition, "\$quickFilter === 'ended'") !== false
    && strpos($partition, "'total' => \$total, 'page' => \$page, 'pageSize' => \$pageSize") !== false);
reservationLifecycleOk('确认、拒绝和占用释放的事件合同已激活',
    strpos($events, "'required_event_types' => ['reservation.confirmed']") !== false
    && strpos($events, "'required_event_types' => ['reservation.rejected']") !== false
    && strpos($module, 'releaseInTx') !== false);
reservationLifecycleOk('确认时可在同一事务修改预约但绝不允许更换客户或占用权益',
    strpos($module, 'editedDuringConfirmation') !== false
    && strpos($module, 'assertSameMember($header, $reservation)') !== false
    && strpos($module, '确认预约时不能修改客户') !== false
    && strpos($module, 'occupyLinesInTx($dataScope->tenantId(), (int)$header[\'id\']') === false
    && strpos($module, "'status' => self::STATUS_UNSTARTED") !== false
    && strpos($mobileReservation, "if (is_array(\$payload['reservation'] ?? null)) \$actionPayload['reservation']") !== false
    && strpos($mobileController, 'openEditor($merchant, $this->payload())') !== false);
reservationLifecycleOk('修改时间和房间时串行校验同房间时段冲突',
    strpos($lifecycle, 'assertRoomAvailabilityInTx') !== false
    && strpos($lifecycle, "->where('appointment_start_at', '<', \$endAt)->where('appointment_end_at', '>', \$startAt)") !== false
    && strpos($lifecycle, '房间在该时段已被预约') !== false
    && substr_count($module, 'assertRoomAvailabilityInTx') >= 3);
reservationLifecycleOk('新迁移包只加代际、来源、生命周期时间和权益占用表',
    strpos($crossClientMigration, '20260901-001-reservation-v3-cross-client-lifecycle') !== false
    && strpos($crossClientMigration, 'lifecycle_generation') !== false
    && strpos($crossClientMigration, 'cashier_v3_reservation_entitlement_occupation') !== false
    && strpos($crossClientMigration, "INSERT INTO `eb_database_upgrade_log`") !== false
    && stripos($crossClientMigration, 'UPDATE `eb_cashier_v3_reservation`') === false);
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
