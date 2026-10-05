<?php
/**
 * 本地事务性回归：先只扩展唯一任职员工的数据范围，再补齐组织直属岗位，
 * 验证两种切店会话均不改变首次任职店登录，也不借目标店任职；
 * 无论结果如何均撤销测试令牌并回滚数据库写入。
 * 运行时由环境变量指定本地库的员工、岗位和组织 ID，不连接或写入客户线上库。
 */
require '/var/www/html/vendor/autoload.php';

$app = new think\App('/var/www/html/');
$app->initialize();

$employeeId = (int)(getenv('HYBRID_SWITCH_EMPLOYEE_ID') ?: 0);
$positionId = (int)(getenv('HYBRID_SWITCH_POSITION_ID') ?: 0);
$orgId = (int)(getenv('HYBRID_SWITCH_ORG_ID') ?: 0);
if ($employeeId <= 0 || $positionId <= 0 || $orgId <= 0) {
    throw new RuntimeException('请指定本地回归库的 HYBRID_SWITCH_EMPLOYEE_ID、POSITION_ID、ORG_ID');
}

$employees = app()->make(app\services\employee\EmployeeInternalLoginServices::class);
$login = app()->make(app\services\cashier\v3\CashierV3StoreLoginServices::class);
$direct = $employees->resolveUniqueStoreV3Staff($employeeId);
if (!$direct || (int)$direct['store_id'] <= 0) {
    throw new RuntimeException('测试员工须先有唯一有效门店任职和门店端入口');
}
$directStoreId = (int)$direct['store_id'];
if ($employees->employeeHasStoreV3Entry($employeeId)) {
    throw new RuntimeException('测试员工初始状态不能已有组织直属门店端岗位');
}

think\facade\Db::startTrans();
$issuedTokens = [];
try {
    $now = time();
    if (($login->switchableStores($employeeId, $directStoreId)['stores'] ?? []) !== []) {
        throw new RuntimeException('单店范围不应显示切店候选');
    }
    think\facade\Db::name('employee_data_scope')->insert([
        'employee_id' => $employeeId,
        'source_type' => 'hq',
        'source_store_id' => 0,
        'scope_mode' => 'org',
        'org_ids' => json_encode([$orgId]),
        'store_ids' => '[]',
        'status' => 1,
        'is_del' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);

    $staffCandidates = $login->switchableStores($employeeId, $directStoreId)['stores'] ?? [];
    $staffTargets = array_values(array_filter($staffCandidates, static function (array $store) use ($directStoreId): bool {
        return (int)$store['store_id'] !== $directStoreId && $store['source'] === 'data_scope';
    }));
    if (!$staffTargets) {
        throw new RuntimeException('任职岗位加其他门店数据权限后仍无切店候选');
    }
    $staffTargetId = (int)$staffTargets[0]['store_id'];
    $staffSession = $login->switchStore($employeeId, $directStoreId, $staffTargetId);
    $issuedTokens[] = (string)($staffSession['token'] ?? '');
    $staffSessionRow = think\facade\Db::name('cashier_v3_store_session')
        ->where('employee_id', $employeeId)->where('store_id', $staffTargetId)
        ->order('id', 'desc')->find();
    $resolver = app()->make(app\services\cashier\v3\permission\CashierV3FeatureResolver::class);
    $originalFeatures = $resolver->resolveGrantedFeatures([
        'id' => (int)$direct['id'], 'employee_id' => $employeeId, 'store_id' => $directStoreId,
    ]);
    if ((string)($staffSessionRow['source'] ?? '') !== 'staff_scope'
        || ($staffSession['session_mode'] ?? '') !== 'store_organization'
        || !$originalFeatures
        || array_diff((array)($staffSession['features'] ?? []), $originalFeatures)
        || array_diff($originalFeatures, (array)($staffSession['features'] ?? []))) {
        throw new RuntimeException('跨店会话未按原任职岗位实时授权');
    }
    $staffProfile = app()->make(app\services\cashier\LoginServices::class)
        ->parseToken((string)$staffSession['token']);
    if (empty($staffProfile['_cashier_v3_staff_scope'])
        || (int)($staffProfile['store_id'] ?? 0) !== $staffTargetId) {
        throw new RuntimeException('跨店令牌未保留目标门店和任职岗位授权标记');
    }
    // 业务写入会在事务内重建权限；这里验证重建后仍按原岗位判权且只绑定目标店。
    $operatorScope = new app\services\cashier\v3\CashierV3OperatorScope(
        $staffTargetId,
        (int)$staffProfile['id'],
        '',
        app\services\cashier\v3\CashierV3ScopeResolver::TENANT_SCOPE_ID
    );
    $snapshot = app()->make(app\services\cashier\v3\CashierV3PermissionSnapshotServices::class)
        ->lockAndBuild($operatorScope, $staffProfile);
    if (empty($snapshot['operator_profile']['_cashier_v3_staff_scope'])
        || (int)$snapshot['operator_profile']['store_id'] !== $staffTargetId
        || $resolver->resolveGrantedFeatures($snapshot['operator_profile']) !== $originalFeatures) {
        throw new RuntimeException('跨店写操作的事务内权限快照未保留原岗位授权');
    }
    $activeJobIds = think\facade\Db::name('staff_job_position')
        ->where('employee_id', $employeeId)->where('staff_id', (int)$direct['id'])
        ->where('status', 1)->where('is_del', 0)->where('end_time', 0)->column('id');
    if (!$activeJobIds) {
        throw new RuntimeException('测试员工缺少有效任职岗位');
    }
    think\facade\Db::name('staff_job_position')->whereIn('id', $activeJobIds)->update(['status' => 0]);
    if ($resolver->resolveGrantedFeatures($staffProfile) !== []
        || ($login->switchableStores($employeeId, $directStoreId)['stores'] ?? []) !== []) {
        throw new RuntimeException('任职岗位撤销后跨店授权仍未失效');
    }
    try {
        app()->make(app\services\cashier\LoginServices::class)->parseToken((string)$staffSession['token']);
        throw new RuntimeException('任职岗位撤销后旧跨店令牌仍可使用');
    } catch (mohe\exceptions\AuthException $expected) {
        // 令牌解析拒绝是预期结果；测试事务结束后会撤销全部测试令牌。
    }
    think\facade\Db::name('staff_job_position')->whereIn('id', $activeJobIds)->update(['status' => 1]);

    think\facade\Db::name('staff_job_position')->insert([
        'employee_id' => $employeeId,
        'store_id' => 0,
        'staff_id' => 0,
        'position_id' => $positionId,
        'status' => 1,
        'start_time' => $now,
        'end_time' => 0,
        'is_del' => 0,
        'request_token' => bin2hex(random_bytes(16)),
        'add_time' => $now,
        'update_time' => $now,
    ]);

    $stillDirect = $employees->resolveUniqueStoreV3Staff($employeeId);
    $candidates = $login->switchableStores($employeeId, $directStoreId)['stores'] ?? [];
    $own = array_values(array_filter($candidates, static function (array $store) use ($directStoreId): bool {
        return (int)$store['store_id'] === $directStoreId && $store['source'] === 'direct_tenure';
    }));
    $others = array_values(array_filter($candidates, static function (array $store) use ($directStoreId): bool {
        return (int)$store['store_id'] !== $directStoreId && $store['source'] === 'data_scope';
    }));
    if ((int)($stillDirect['store_id'] ?? 0) !== $directStoreId || count($own) !== 1 || !$others) {
        throw new RuntimeException('任职直入或额外组织授权门店候选不符合预期');
    }
    if (($login->switchableStores($employeeId, 0)['stores'] ?? []) !== []) {
        throw new RuntimeException('非当前授权门店不得读取切店候选');
    }
    // 真正签发双向切店会话：其他店只得组织身份，切回原店恢复任职身份。
    $otherStoreId = (int)$others[0]['store_id'];
    $organizationSession = $login->switchStore($employeeId, $directStoreId, $otherStoreId);
    $issuedTokens[] = (string)($organizationSession['token'] ?? '');
    $organizationSessionRow = think\facade\Db::name('cashier_v3_store_session')
        ->where('employee_id', $employeeId)->where('store_id', $otherStoreId)
        ->order('id', 'desc')->find();
    if ((int)($organizationSession['store_id'] ?? 0) !== $otherStoreId
        || (string)($organizationSessionRow['source'] ?? '') !== 'data_scope'
        || ($organizationSession['session_mode'] ?? '') !== 'store_organization'
        || strpos((string)($organizationSession['permission_version'] ?? ''), 'organization:') !== 0) {
        throw new RuntimeException('切往其他门店未按组织身份签发会话');
    }
    $staffSession = $login->switchStore($employeeId, $otherStoreId, $directStoreId);
    $issuedTokens[] = (string)($staffSession['token'] ?? '');
    if ((int)($staffSession['store_id'] ?? 0) !== $directStoreId
        || ($staffSession['session_mode'] ?? '') !== 'store_staff'
        || strpos((string)($staffSession['permission_version'] ?? ''), 'staff:') !== 0) {
        throw new RuntimeException('切回任职门店未恢复任职身份');
    }
    echo 'PASS direct_login_store=' . $directStoreId . ' staff_scope_target=' . $staffTargetId
        . ' organization_targets=' . count($others) . ' round_trip=' . $otherStoreId . '->' . $directStoreId . PHP_EOL;
} finally {
    // 令牌在事务外缓存，必须主动撤销；数据库中的临时授权和会话由事务回滚。
    foreach ($issuedTokens as $token) {
        if ($token !== '') {
            try { $login->logout($token); } catch (Throwable $ignored) {}
        }
    }
    think\facade\Db::rollback();
}
