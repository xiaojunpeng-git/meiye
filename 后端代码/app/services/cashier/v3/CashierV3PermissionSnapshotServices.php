<?php
namespace app\services\cashier\v3;

use think\facade\Db;

/**
 * 事务内权限快照：锁定收银账号权威表 system_store_staff 及员工绑定／数据权限后重建 DataScope。
 *
 * 写命令禁止复用事务外旧 operatorProfile／DataScope；权限变更推进 permissionVersion，
 * 锁定后发现版本变化必须拒绝。
 */
class CashierV3PermissionSnapshotServices
{
    /** @var CashierV3DataScopeFactory */
    protected $factory;

    public function __construct(CashierV3DataScopeFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * 在业务事务内锁定收银账号权威源，用锁后数据重建 operatorProfile 与 DataScope。
     *
     * @param string|null $expectedPermissionVersion 非空时若重建后不一致则抛权限冲突
     * @return array{data_scope:CashierV3DataScopeContext,operator_profile:array}
     */
    public function lockAndBuild(
        CashierV3OperatorScope $operatorScope,
        array $operatorProfile,
        string $expectedPermissionVersion = null
    ): array {
        CashierV3TransactionGuard::assertInTransaction('permissionSnapshot');

        $operatorId = $operatorScope->operatorId();
        if ($operatorId <= 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号无效，已拒绝。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'operator_id_missing']
            );
        }

        $isDelegated = !empty($operatorProfile['_cashier_v3_delegated']);
        // 常规会话锁 system_store_staff；数据权限选店会话锁定自己的会话投影，
        // 不把它伪造成员工任职，也不恢复历史任职行。
        $staff = null;
        if ($isDelegated) {
            $staff = $operatorProfile;
        } else {
            try {
                $staff = Db::name('system_store_staff')
                    ->where('id', $operatorId)
                    ->lock(true)
                    ->find();
            } catch (\Throwable $e) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                    '收银账号权威源不可用，已拒绝。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'system_store_staff_lock_failed', 'error' => $e->getMessage()]
                );
            }

            if (!$staff || !is_array($staff)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::PERMISSION_DENIED,
                    '当前账号不存在或已解绑，已拒绝。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'staff_not_found']
                );
            }
        }

        $isDel = (int)($staff['is_del'] ?? 1);
        $status = (int)($staff['status'] ?? 0);
        $staffStoreId = (int)($staff['store_id'] ?? 0);
        $employeeId = (int)($staff['employee_id'] ?? 0);
        $internalAccount = '';

        if (!$isDelegated && ($isDel !== 0 || $status !== 1)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号已停用或删除，已拒绝。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'staff_disabled', 'status' => $status, 'is_del' => $isDel]
            );
        }

        if (!$isDelegated && $staffStoreId > 0 && $staffStoreId !== $operatorScope->storeId()) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号门店已变更，请刷新工作台后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                [
                    'reason' => 'staff_store_mismatch',
                    'staff_store_id' => $staffStoreId,
                    'session_store_id' => $operatorScope->storeId(),
                ]
            );
        }

        // 平台通过“进入门店”签发的 level=0 门店管理员会话沿用旧逻辑：
        // 它仅可在令牌绑定的当前门店操作，不能因历史员工档案未绑定而退化成
        // 一个只能浏览、不能执行命令的半可用会话。DataScopeFactory 会把该身份
        // 固定为 forcedStoreId 的 MODE_STORES，绝不扩大成跨门店权限。
        $isCurrentStoreMenuSuper = (int)($staff['level'] ?? -1) === 0;
        if ($employeeId <= 0 && !$isCurrentStoreMenuSuper) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号未绑定有效员工档案，已拒绝。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'employee_binding_missing']
            );
        }

        // 锁定员工档案（任职）
        if ($employeeId > 0) {
            try {
                $employee = Db::name('employee')
                    ->where('id', $employeeId)
                    ->lock(true)
                    ->find();
                if (!$employee
                    || (int)($employee['is_del'] ?? 1) === 1
                    || (int)($employee['status'] ?? 0) !== 1) {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::PERMISSION_DENIED,
                        '当前员工档案已停用或解绑，已拒绝。',
                        CashierV3ResultCode::STATUS_FAILED,
                        ['reason' => 'employee_disabled']
                    );
                }
                $accountRow = Db::name('employee_internal_account')
                    ->where('employee_id', $employeeId)
                    ->where('is_del', 0)
                    ->lock(true)
                    ->find();
                if ($accountRow && (int)($accountRow['status'] ?? 0) === 1) {
                    $internalAccount = trim((string)($accountRow['account'] ?? ''));
                }
            } catch (CashierV3CommandException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // employee 表缺失时 fail-closed
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                    '员工档案权威源不可用，已拒绝。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'employee_lock_failed', 'error' => $e->getMessage()]
                );
            }
        }

        // 锁定员工数据权限／门店隔离行
        if ($employeeId > 0) {
            try {
                Db::name('employee_data_scope')
                    ->where('employee_id', $employeeId)
                    ->where('is_del', 0)
                    ->lock(true)
                    ->select()
                    ->toArray();
                Db::name('employee_store_isolation')
                    ->where('employee_id', $employeeId)
                    ->where('is_del', 0)
                    ->lock(true)
                    ->select()
                    ->toArray();
                // 个人门店端功能覆盖与员工权限版本是同一权限快照的一部分。
                // 表尚未升级时不影响历史环境登录，实际写接口会在升级缺失时失败。
                try {
                    Db::name('staff_store_v3_feature_override')
                        ->where('employee_id', $employeeId)
                        ->where('is_del', 0)
                        ->lock(true)
                        ->select()
                        ->toArray();
                } catch (\Throwable $ignore) {
                }
            } catch (\Throwable $e) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                    '员工数据权限权威源不可用，已拒绝。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'employee_data_scope_lock_failed', 'error' => $e->getMessage()]
                );
            }
        }

        // 用锁后权威行重建 profile；禁止继续使用事务外旧 operatorProfile
        $rebuiltProfile = $this->rebuildOperatorProfile($staff, $operatorProfile, $internalAccount);

        $dataScope = $this->factory->build(
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            $rebuiltProfile,
            $operatorScope->tenantId(),
            $operatorScope->organizationId()
        );

        if ($expectedPermissionVersion !== null
            && $expectedPermissionVersion !== ''
            && $dataScope->permissionVersion() !== $expectedPermissionVersion) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号权限已变更，请刷新当前工作台后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                [
                    'reason' => 'permission_version_changed',
                    'expected' => $expectedPermissionVersion,
                    'actual' => $dataScope->permissionVersion(),
                ]
            );
        }

        return [
            'data_scope' => $dataScope,
            'operator_profile' => $rebuiltProfile,
        ];
    }

    /**
     * 兼容旧调用：仅返回 DataScope。
     */
    public function lockAndBuildDataScope(
        CashierV3OperatorScope $operatorScope,
        array $operatorProfile,
        string $expectedPermissionVersion = null
    ): CashierV3DataScopeContext {
        $pack = $this->lockAndBuild($operatorScope, $operatorProfile, $expectedPermissionVersion);
        return $pack['data_scope'];
    }

    /**
     * 计算当前权限版本（测试／并发探针用；生产写路径必须走 lockAndBuild）。
     */
    public function computeVersion(CashierV3OperatorScope $operatorScope, array $operatorProfile): string
    {
        return $this->factory->build(
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            $operatorProfile,
            $operatorScope->tenantId(),
            $operatorScope->organizationId()
        )->permissionVersion();
    }

    /**
     * 从锁定的 system_store_staff 行重建 operatorProfile（parseToken 形状）。
     */
    protected function rebuildOperatorProfile(array $staff, array $sessionHint = [], string $internalAccount = ''): array
    {
        $roles = $staff['roles'] ?? [];
        if (is_string($roles)) {
            $roles = $roles === '' ? [] : (strpos($roles, '[') === 0
                ? (json_decode($roles, true) ?: array_filter(array_map('intval', explode(',', trim($roles, '[]')))))
                : array_values(array_filter(array_map('intval', explode(',', $roles)))));
        }
        if (!is_array($roles)) {
            $roles = [];
        }

        $profile = [
            'id' => (int)($staff['id'] ?? 0),
            'account' => $internalAccount !== ''
                ? $internalAccount
                : trim((string)($staff['account'] ?? '')),
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'store_id' => (int)($staff['store_id'] ?? 0),
            'employee_id' => (int)($staff['employee_id'] ?? 0),
            'roles' => array_values($roles),
            'level' => (int)($staff['level'] ?? 1),
        ];

        if (!empty($sessionHint['_cashier_v3_delegated'])) {
            $profile['_cashier_v3_delegated'] = 1;
        }

        // 仅透传测试菜单探针；生产不得从旧会话信任 unique_auth／deny_all
        if (isset($sessionHint['__menus_unique_auth'])) {
            $profile['__menus_unique_auth'] = $sessionHint['__menus_unique_auth'];
        }

        return $profile;
    }
}
