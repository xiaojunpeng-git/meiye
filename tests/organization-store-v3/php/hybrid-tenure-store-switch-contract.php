<?php
/**
 * 本地事务性回归：临时为一名有唯一任职的员工补齐组织岗位与组织范围，
 * 验证登录仍以任职店直入、切店候选包含组织授权门店，以及双向会话身份；
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

think\facade\Db::startTrans();
$issuedTokens = [];
try {
    $now = time();
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
    if ((int)($organizationSession['store_id'] ?? 0) !== $otherStoreId
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
    echo 'PASS direct_login_store=' . $directStoreId . ' organization_targets=' . count($others)
        . ' round_trip=' . $otherStoreId . '->' . $directStoreId . PHP_EOL;
} finally {
    // 令牌在事务外缓存，必须主动撤销；数据库中的临时授权和会话由事务回滚。
    foreach ($issuedTokens as $token) {
        if ($token !== '') {
            try { $login->logout($token); } catch (Throwable $ignored) {}
        }
    }
    think\facade\Db::rollback();
}
