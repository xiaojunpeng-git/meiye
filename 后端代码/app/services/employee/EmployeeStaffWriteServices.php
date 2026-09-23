<?php
namespace app\services\employee;

use app\services\BaseServices;
use app\services\mobile\merchant\MobileAuthRevocationServices;
use app\services\store\SystemStoreStaffServices;
use app\services\system\SystemRoleServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * I1 员工主档 + 门店任职统一写编排
 * 顺序：锁/建 employee → 校验手机号 →（仅明确资料编辑时）写 employee → 写 staff → 投影有效 staff → change_log
 * 禁止把 syncFromStaff 当作权威方向；禁止新写/清零/回写商城 UID
 */
class EmployeeStaffWriteServices extends BaseServices
{
    public const ACTION_GLOBAL_LEAVE = 'employee_global_leave';
    public const ACTION_GLOBAL_RESUME = 'employee_global_resume';
    public const PHONE_REGEX = EmployeeMigrateServices::PHONE_REGEX;
    public const DEFAULT_AVATAR = EmployeeMigrateServices::DEFAULT_AVATAR;

    /** 门店端禁止改写的总部字段（仅允许选择总部已配置的门店角色 roles） */
    public const STORE_FORBIDDEN_HQ_FIELDS = [
        'position', 'position_level', 'is_manager', 'is_butler', 'is_cashier',
        'order_status', 'verify_status', 'rules', 'cashier_rules', 'mall_rules',
    ];

    /**
     * @param array $staffPayload 已规范化的 staff 字段（含 store_id/roles/phone/staff_name 等）
     * @param array{operator_id?:int,operator_name?:string,operator_ip?:string,request_id?:string,source?:string,reason?:string,channel?:string,allow_profile_update?:bool} $operatorContext
     * @param array{use_outer_transaction?:bool} $options 编排外层事务内调用时传 use_outer_transaction=true，不再套 Db::transaction
     * @return array{staff_id:int,employee_id:int}
     */
    public function saveStaffAssignment(int $staffId, array $staffPayload, array $operatorContext = [], array $options = []): array
    {
        $runner = function () use ($staffId, $staffPayload, $operatorContext) {
            $storeId = (int)($staffPayload['store_id'] ?? 0);
            if ($storeId <= 0) {
                throw new AdminException('请选择门店');
            }
            $source = (string)($operatorContext['source'] ?? 'admin');
            $isStoreChannel = ($source === 'store');

            $phone = $this->assertStrictPhone((string)($staffPayload['phone'] ?? ''));
            $name = trim((string)($staffPayload['staff_name'] ?? ''));
            if ($name === '') {
                throw new AdminException('请填写员工姓名');
            }
            $avatar = trim((string)($staffPayload['avatar'] ?? ''));
            if ($avatar === '') {
                $avatar = self::DEFAULT_AVATAR;
            }
            $staffStatus = (int)($staffPayload['status'] ?? 1) === 1 ? 1 : 0;

            // 禁止接口写入权限正文与商城 UID
            unset(
                $staffPayload['rules'],
                $staffPayload['cashier_rules'],
                $staffPayload['mall_rules'],
                $staffPayload['uid']
            );

            // 人员功能权限只由岗位投影；档案保存不得改写 roles / 手工店长
            $clientRoles = $staffPayload['roles'] ?? [];
            if (!is_array($clientRoles)) {
                $clientRoles = $clientRoles !== '' ? explode(',', (string)$clientRoles) : [];
            }
            $clientRoles = array_values(array_unique(array_filter(array_map('intval', $clientRoles))));
            if ($clientRoles) {
                throw new AdminException('人员功能权限由岗位决定，不能单独选择角色或店员权限');
            }
            unset($staffPayload['roles']);
            unset($staffPayload['is_manager'], $staffPayload['is_butler']);
            $staffPayload['phone'] = $phone;
            $staffPayload['customer_phone'] = $phone;
            $staffPayload['staff_name'] = $name;
            $staffPayload['avatar'] = $avatar;
            $staffPayload['status'] = $staffStatus;

            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            $staffServices->normalizeStaffAvatar($staffPayload);
            $staffServices->normalizeStaffDates($staffPayload);

            $existingStaff = null;
            $linkedEmployeeId = 0;
            if ($staffId > 0) {
                $existingStaff = Db::name('system_store_staff')->where('id', $staffId)->lock(true)->find();
                if (!$existingStaff || (int)($existingStaff['is_del'] ?? 0) === 1) {
                    throw new AdminException('店员不存在');
                }
                if ((int)$existingStaff['store_id'] !== $storeId) {
                    throw new AdminException('不能通过本接口修改门店归属，请走调店流程');
                }
                $linkedEmployeeId = (int)($existingStaff['employee_id'] ?? 0);
            } else {
                $staffPayload['roles'] = [];
                $staffPayload['is_manager'] = 0;
                $staffPayload['is_butler'] = 0;
            }

            if ($isStoreChannel) {
                $this->applyStoreChannelHqFieldPolicy($staffPayload, $existingStaff);
                if ($staffId <= 0) {
                    $staffPayload['roles'] = [];
                } else {
                    // 编辑档案不碰岗位投影后的 roles
                    unset($staffPayload['roles']);
                }
            } else {
                // 总部：收银标识仍可按档案字段保存；店长身份仅岗位投影，roles 不在此写入
                $forceCashier = array_key_exists('is_cashier', $staffPayload);
                $cashierVal = (int)($staffPayload['is_cashier'] ?? 0) === 1 ? 1 : 0;
                $staffPayload['roles'] = $staffId > 0
                    ? (isset($existingStaff['roles']) ? $existingStaff['roles'] : [])
                    : [];
                if (!is_array($staffPayload['roles'])) {
                    $staffPayload['roles'] = $staffPayload['roles'] !== ''
                        ? array_values(array_filter(array_map('intval', explode(',', (string)$staffPayload['roles']))))
                        : [];
                }
                $staffServices->applyRolesFlags($staffPayload);
                if ($forceCashier) {
                    $staffPayload['is_cashier'] = $cashierVal;
                }
                // 编辑时不覆盖已有店长标记（改岗后由 syncManagerFlagFromJobs 重算）
                unset($staffPayload['is_manager'], $staffPayload['is_butler']);
                if ($staffId <= 0) {
                    $staffPayload['is_manager'] = 0;
                    $staffPayload['is_butler'] = 0;
                }
            }

            // 明确资料编辑：已有任职且已关联 employee，或调用方显式允许
            $allowProfileUpdate = !empty($operatorContext['allow_profile_update'])
                || ($linkedEmployeeId > 0 && $staffId > 0);

            $employeeResult = $this->lockOrCreateEmployee(
                $linkedEmployeeId,
                $phone,
                $name,
                $avatar,
                $operatorContext,
                $allowProfileUpdate
            );
            $employeeId = (int)$employeeResult['employee_id'];
            $profileUpdated = (bool)$employeeResult['profile_updated'];
            $employeeProfile = $employeeResult['profile'];

            // 手机号命中已有主档且未做资料编辑：任职资料以主档为准，避免用门店提交值覆盖投影
            if (!$profileUpdated && $employeeProfile) {
                $staffPayload['staff_name'] = (string)$employeeProfile['name'];
                $staffPayload['avatar'] = (string)$employeeProfile['avatar'];
                $staffPayload['phone'] = (string)$employeeProfile['phone'];
                $staffPayload['customer_phone'] = (string)$employeeProfile['phone'];
                $name = $staffPayload['staff_name'];
                $avatar = $staffPayload['avatar'];
                $phone = $staffPayload['phone'];
            }

            $this->assertSingleActiveStoreAssignment($employeeId, $staffId, $staffStatus);

            $staffPayload['employee_id'] = $employeeId;

            if ($staffId > 0) {
                $update = $staffPayload;
                unset($update['uid'], $update['add_time']);
                if (!$staffServices->update($staffId, $update)) {
                    throw new AdminException('保存门店任职失败');
                }
            } else {
                $staffPayload['add_time'] = time();
                unset($staffPayload['uid']);
                $res = $staffServices->save($staffPayload);
                $staffId = (int)(is_object($res) ? $res->id : ($res['id'] ?? 0));
                if ($staffId <= 0) {
                    throw new AdminException('创建门店任职失败');
                }
            }

            // 仅在明确更新了 employee 后，才投影全部有效 staff
            if ($profileUpdated) {
                $this->projectEmployeeToActiveStaff($employeeId, [
                    'name' => $name,
                    'phone' => $phone,
                    'avatar' => $avatar,
                ]);
            }

            $this->writeChangeLog($employeeId, $staffId > 0 && $linkedEmployeeId > 0 ? 'staff_assignment_save' : 'staff_assignment_create', 'staff', $staffId, [
                'employee_id' => $employeeId,
                'staff_id' => $staffId,
                'store_id' => $storeId,
                'phone' => $phone,
                'name' => $name,
                'profile_updated' => $profileUpdated,
                'channel' => $source,
            ], $operatorContext);

            return ['staff_id' => $staffId, 'employee_id' => $employeeId];
        };
        if (!empty($options['use_outer_transaction'])) {
            return $runner();
        }
        return Db::transaction($runner);
    }

    /**
     * 明确的员工资料编辑（须由调用方完成权限校验）
     */
    public function updateEmployeeProfile(int $employeeId, array $profile, array $operatorContext = []): void
    {
        Db::transaction(function () use ($employeeId, $profile, $operatorContext) {
            $employee = Db::name('employee')->where('id', $employeeId)->lock(true)->find();
            if (!$employee || (int)($employee['is_del'] ?? 0) === 1) {
                throw new AdminException('员工不存在');
            }
            $name = array_key_exists('name', $profile) ? trim((string)$profile['name']) : (string)$employee['name'];
            $phone = array_key_exists('phone', $profile)
                ? $this->assertStrictPhone((string)$profile['phone'])
                : (string)$employee['phone'];
            $avatar = array_key_exists('avatar', $profile) ? trim((string)$profile['avatar']) : (string)$employee['avatar'];
            if ($name === '') {
                throw new AdminException('请填写员工姓名');
            }
            if ($avatar === '') {
                $avatar = self::DEFAULT_AVATAR;
            }
            $owner = Db::name('employee')->where('phone', $phone)->where('id', '<>', $employeeId)->lock(true)->find();
            if ($owner) {
                throw new AdminException('该手机号已被其他员工使用');
            }
            $now = time();
            Db::name('employee')->where('id', $employeeId)->update([
                'name' => $name,
                'phone' => $phone,
                'avatar' => $avatar,
                'avatar_type' => $this->resolveAvatarType($avatar),
                'update_time' => $now,
            ]);
            $this->projectEmployeeToActiveStaff($employeeId, [
                'name' => $name,
                'phone' => $phone,
                'avatar' => $avatar,
            ]);
            $this->writeChangeLog($employeeId, 'employee_profile_update', 'employee', $employeeId, [
                'name' => $name,
                'phone' => $phone,
                'avatar' => $avatar,
            ], $operatorContext);
        });
    }

    /**
     * 受控恢复已软删除的员工主档。
     *
     * 只恢复 employee 主档本身，不恢复历史门店任职；调用方随后必须按本次
     * 请求创建或恢复目标组织/门店关系。手机号是唯一定位条件，且整段调用须
     * 处于外层事务内，避免并发新建重复员工。
     *
     * @return array{employee_id:int,name:string,phone:string,avatar:string}|null
     */
    public function restoreDeletedEmployeeByPhone(string $phone, array $operatorContext = []): ?array
    {
        $phone = $this->assertStrictPhone($phone);
        $employee = Db::name('employee')->where('phone', $phone)->lock(true)->find();
        if (!$employee || (int)($employee['is_del'] ?? 0) !== 1) {
            return null;
        }
        $now = time();
        Db::name('employee')->where('id', (int)$employee['id'])->update([
            'is_del' => 0,
            'status' => 1,
            'update_time' => $now,
        ]);
        $this->writeChangeLog((int)$employee['id'], 'employee_archive_restore', 'employee', (int)$employee['id'], [
            'restored' => true,
            'phone' => $phone,
            'previous_is_del' => (int)($employee['is_del'] ?? 1),
            'previous_status' => (int)($employee['status'] ?? 0),
            'current_is_del' => 0,
            'current_status' => 1,
            'assignment_scope' => 'current_request_only',
        ], $operatorContext);
        return [
            'employee_id' => (int)$employee['id'],
            'name' => (string)($employee['name'] ?? ''),
            'phone' => (string)($employee['phone'] ?? $phone),
            'avatar' => (string)($employee['avatar'] ?? self::DEFAULT_AVATAR),
        ];
    }

    /**
     * 单店离职/关闭/软删统一内核：
     * - 始终 status=0，清本店角色/收银/手机订单标识
     * - 不改 store_id，不物理删除
     * - softDelete=false：is_del 保持不变（set_show=0）
     * - softDelete=true：再软删 is_del=1（delete）
     */
    public function leaveStoreAssignment(int $staffId, array $operatorContext = [], bool $softDelete = false): void
    {
        Db::transaction(function () use ($staffId, $operatorContext, $softDelete) {
            $staff = Db::name('system_store_staff')->where('id', $staffId)->lock(true)->find();
            if (!$staff || (int)($staff['is_del'] ?? 0) === 1) {
                throw new AdminException('店员不存在');
            }
            $fromStoreId = (int)$staff['store_id'];
            $employeeId = (int)($staff['employee_id'] ?? 0);
            $update = [
                'status' => 0,
                'roles' => '',
                'order_status' => 0,
                'is_cashier' => 0,
                'is_manager' => 0,
                'is_store' => 0,
            ];
            if ($softDelete) {
                $update['is_del'] = 1;
            }
            Db::name('system_store_staff')->where('id', $staffId)->update($update);
            $recheck = Db::name('system_store_staff')->where('id', $staffId)->find();
            if (!$recheck || (int)$recheck['store_id'] !== $fromStoreId) {
                throw new AdminException('操作异常：原任职门店被改写');
            }
            if ($softDelete && (int)($recheck['is_del'] ?? 0) !== 1) {
                throw new AdminException('软删除失败');
            }
            if ($employeeId > 0) {
                $this->writeChangeLog(
                    $employeeId,
                    $softDelete ? 'staff_store_delete' : 'staff_store_leave',
                    'staff',
                    $staffId,
                    [
                        'store_id' => $fromStoreId,
                        'status' => 0,
                        'is_del' => $softDelete ? 1 : (int)($staff['is_del'] ?? 0),
                        'store_id_kept' => $fromStoreId,
                    ],
                    $operatorContext
                );
            }
        });
    }

    /**
     * 全局离职（仅总部）
     */
    public function leaveEmployeeGlobally(int $employeeId, array $operatorContext = []): void
    {
        Db::transaction(function () use ($employeeId, $operatorContext) {
            $this->leaveEmployeeGloballyCore($employeeId, $operatorContext);
        });
    }

    /**
     * 人员完整保存事务内同步在职状态。
     *
     * expectedVersion 防止两个编辑窗口静默覆盖；从在职切到离职时，员工主档、
     * 任职、岗位、入口、账号和离职事实必须在同一事务中落定。重复保存同一状态
     * 不递增版本，也不重复生成离职记录。
     *
     * @return array{status:int,status_version:int,changed:bool}
     */
    public function syncEmploymentStatusInCurrentTransaction(
        int $employeeId,
        int $targetStatus,
        int $expectedVersion,
        array $operatorContext = []
    ): array {
        $targetStatus = $targetStatus === 1 ? 1 : 0;
        $employee = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->lock(true)->find();
        if (!$employee) {
            throw new AdminException('员工不存在');
        }
        $currentVersion = (int)($employee['status_version'] ?? 0);
        if ($currentVersion !== $expectedVersion) {
            throw new AdminException('在职状态已被其他人修改，请刷新员工档案后重试');
        }
        $currentStatus = (int)($employee['status'] ?? 1) === 1 ? 1 : 0;
        if ($targetStatus === 0) {
            // 即使员工原本已离职，也重新收拢本次完整保存可能临时恢复的关联状态；
            // leaveEmployeeGloballyCore 只在真实 1→0 时追加离职事实。
            $changed = $this->leaveEmployeeGloballyCore($employeeId, $operatorContext);
            return [
                'status' => 0,
                'status_version' => $changed ? $currentVersion + 1 : $currentVersion,
                'changed' => $changed,
            ];
        }
        if ($currentStatus === 1) {
            return ['status' => 1, 'status_version' => $currentVersion, 'changed' => false];
        }

        $nextVersion = $currentVersion + 1;
        $updated = Db::name('employee')->where('id', $employeeId)->where('status_version', $currentVersion)->update([
            'status' => 1,
            'status_version' => $nextVersion,
            'update_time' => time(),
        ]);
        if ($updated !== 1) {
            throw new AdminException('在职状态已被其他人修改，请刷新员工档案后重试');
        }
        $this->openTenureForGlobalResume($employeeId, $operatorContext);
        $this->writeChangeLog($employeeId, self::ACTION_GLOBAL_RESUME, 'employee', $employeeId, [
            'status' => 1,
            'status_version' => $nextVersion,
        ], array_merge($operatorContext, ['reason' => (string)($operatorContext['reason'] ?? '人员编辑恢复在职')]));
        return ['status' => 1, 'status_version' => $nextVersion, 'changed' => true];
    }

    /**
     * 全局离职内核：不自行开事务，由调用方事务包裹。
     * - leaveEmployeeGlobally：自开事务后调用
     * - softDeleteEmployeeArchive：与软删同事务调用，保证原子性
     */
    protected function leaveEmployeeGloballyCore(int $employeeId, array $operatorContext = []): bool
    {
        $employee = Db::name('employee')->where('id', $employeeId)->lock(true)->find();
        if (!$employee || (int)($employee['is_del'] ?? 0) === 1) {
            throw new AdminException('员工不存在');
        }
        $now = time();
        $wasActive = (int)($employee['status'] ?? 1) === 1;
        $currentStatusVersion = (int)($employee['status_version'] ?? 0);
        $activeStaff = Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->lock(true)
            ->select()->toArray();
        $activeStaffIds = array_values(array_map(static function (array $row): int {
            return (int)$row['id'];
        }, $activeStaff));
        $storeIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
            return (int)($row['store_id'] ?? 0);
        }, $activeStaff))));

        // 任职期间是期间流失人数的权威事实；按员工一次离职、每个有效门店任职一条结束记录。
        if ($wasActive) {
            foreach ($activeStaff as $staff) {
                $this->closeTenureForGlobalLeave($staff, $now, $operatorContext);
            }
        }
        Db::name('employee')->where('id', $employeeId)->update([
            'status' => 0,
            'status_version' => $wasActive ? $currentStatusVersion + 1 : $currentStatusVersion,
            'update_time' => $now,
        ]);
        /** @var MobileAuthRevocationServices $mobileSessions */
        $mobileSessions = app()->make(MobileAuthRevocationServices::class);
        $mobileSessions->revokeAllInCurrentTransaction(
            $employeeId,
            'EMPLOYEE_DISABLED',
            (int)($operatorContext['operator_id'] ?? 0),
            (string)($operatorContext['request_id'] ?? '')
        );
        Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->update([
                'status' => 0,
                'roles' => '',
                'order_status' => 0,
                'is_cashier' => 0,
                'is_manager' => 0,
                'is_store' => 0,
            ]);
        if ($activeStaffIds) {
            Db::name('staff_job_position')
                ->whereIn('staff_id', $activeStaffIds)
                ->where('is_del', 0)
                ->where('status', 1)
                ->where('end_time', 0)
                ->update([
                    'status' => 0,
                    'end_time' => $now,
                    'reason' => (string)($operatorContext['reason'] ?? '员工离职'),
                    'operator_id' => (int)($operatorContext['operator_id'] ?? 0),
                    'operator_name' => (string)($operatorContext['operator_name'] ?? ''),
                    'update_time' => $now,
                ]);
            Db::name('staff_channel_entry')
                ->whereIn('staff_id', $activeStaffIds)
                ->where('is_del', 0)
                ->where('status', 1)
                ->update([
                    'status' => 0,
                    'operator_id' => (int)($operatorContext['operator_id'] ?? 0),
                    'operator_name' => (string)($operatorContext['operator_name'] ?? ''),
                    'update_time' => $now,
                ]);
        }
        $oeUpdate = [
            'is_del' => 1,
            'update_time' => $now,
        ];
        try {
            $hasStatus = Db::query("SHOW COLUMNS FROM `eb_organization_employee` LIKE 'status'");
            if (!empty($hasStatus)) {
                $oeUpdate['status'] = 0;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        Db::name('organization_employee')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->update($oeUpdate);
        $adminIds = Db::name('system_admin')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->column('id');
        if ($adminIds) {
            Db::name('system_admin')->whereIn('id', $adminIds)->update(['status' => 0]);
            Db::name('organization_admin')
                ->whereIn('admin_id', $adminIds)
                ->where('is_del', 0)
                ->update(['is_del' => 1, 'update_time' => $now]);
        }
        if ($wasActive) {
            $storeNames = $storeIds
                ? Db::name('system_store')->whereIn('id', $storeIds)->column('name', 'id')
                : [];
            $this->writeChangeLog($employeeId, self::ACTION_GLOBAL_LEAVE, 'employee', $employeeId, [
                'status' => 0,
                'status_version' => $currentStatusVersion + 1,
                'staff_closed' => true,
                'org_employee_closed' => true,
                'admin_disabled' => (bool)$adminIds,
                'store_ids' => $storeIds,
                'store_names' => array_values(array_map(static function (int $storeId) use ($storeNames): string {
                    return (string)($storeNames[$storeId] ?? ('门店 #' . $storeId));
                }, $storeIds)),
            ], $operatorContext);
        }
        return $wasActive;
    }

    /**
     * 关闭门店任职期间；历史尚无进行中期间时补一条已结束记录，确保离职可统计、可追溯。
     */
    protected function closeTenureForGlobalLeave(array $staff, int $now, array $operatorContext): void
    {
        $staffId = (int)($staff['id'] ?? 0);
        if ($staffId <= 0) {
            return;
        }
        $operatorId = (int)($operatorContext['operator_id'] ?? 0);
        $operatorName = (string)($operatorContext['operator_name'] ?? '');
        $reason = trim((string)($operatorContext['reason'] ?? '员工离职')) ?: '员工离职';
        $active = Db::name('staff_tenure_period')
            ->where('staff_id', $staffId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('end_time', 0)
            ->lock(true)
            ->select()->toArray();
        if ($active) {
            foreach ($active as $period) {
                Db::name('staff_tenure_period')->where('id', (int)$period['id'])->update([
                    'status' => 0,
                    'end_time' => $now,
                    'action' => 'leave',
                    'reason' => $reason,
                    'operator_id' => $operatorId,
                    'operator_name' => $operatorName,
                    'update_time' => $now,
                ]);
            }
            return;
        }
        $joinDate = trim((string)($staff['join_date'] ?? ''));
        $startTime = $joinDate !== '' ? (int)strtotime($joinDate . ' 00:00:00') : (int)($staff['add_time'] ?? 0);
        if ($startTime <= 0 || $startTime > $now) {
            $startTime = $now;
        }
        $requestId = trim((string)($operatorContext['request_id'] ?? ''));
        $tokenSeed = ($requestId !== '' ? $requestId : (string)$now) . '|leave|' . $staffId;
        Db::name('staff_tenure_period')->insert([
            'employee_id' => (int)($staff['employee_id'] ?? 0),
            'store_id' => (int)($staff['store_id'] ?? 0),
            'staff_id' => $staffId,
            'status' => 0,
            'start_time' => $startTime,
            'end_time' => $now,
            'action' => 'leave',
            'reason' => $reason,
            'is_del' => 0,
            'request_token' => 'leave-' . substr(hash('sha256', $tokenSeed), 0, 52),
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'add_time' => $now,
            'update_time' => $now,
        ]);
    }

    /** 恢复在职时为当前有效任职开启新期间；已有进行中期间时保持不变。 */
    protected function openTenureForGlobalResume(int $employeeId, array $operatorContext): void
    {
        $now = time();
        $staffRows = Db::name('system_store_staff')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->lock(true)->select()->toArray();
        foreach ($staffRows as $staff) {
            $staffId = (int)$staff['id'];
            $exists = Db::name('staff_tenure_period')->where('staff_id', $staffId)
                ->where('status', 1)->where('end_time', 0)->where('is_del', 0)->lock(true)->find();
            if ($exists) {
                continue;
            }
            $requestId = trim((string)($operatorContext['request_id'] ?? ''));
            $tokenSeed = ($requestId !== '' ? $requestId : (string)$now) . '|resume|' . $staffId;
            Db::name('staff_tenure_period')->insert([
                'employee_id' => $employeeId,
                'store_id' => (int)($staff['store_id'] ?? 0),
                'staff_id' => $staffId,
                'status' => 1,
                'start_time' => $now,
                'end_time' => 0,
                'action' => 'resume',
                'reason' => trim((string)($operatorContext['reason'] ?? '人员编辑恢复在职')) ?: '人员编辑恢复在职',
                'is_del' => 0,
                'request_token' => 'resume-' . substr(hash('sha256', $tokenSeed), 0, 51),
                'operator_id' => (int)($operatorContext['operator_id'] ?? 0),
                'operator_name' => (string)($operatorContext['operator_name'] ?? ''),
                'add_time' => $now,
                'update_time' => $now,
            ]);
        }
    }

    /**
     * 软删除人员档案（数据清理）：全局离职 + 主档/任职软删必须同事务。
     * 不物理删除订单、工资、任职历史等业务依据。
     */
    public function softDeleteEmployeeArchive(int $employeeId, array $operatorContext = []): void
    {
        if ($employeeId <= 0) {
            throw new AdminException('参数有误');
        }
        // 固定验收主档禁止误删
        if (in_array($employeeId, [789], true)) {
            throw new AdminException('该人员为固定验收数据，禁止删除档案');
        }

        Db::transaction(function () use ($employeeId, $operatorContext) {
            $leaveCtx = array_merge($operatorContext, [
                'reason' => (string)($operatorContext['reason'] ?? '删除人员档案前先办理离职'),
            ]);
            $this->leaveEmployeeGloballyCore($employeeId, $leaveCtx);

            // 本地 smoke 注入点：离职内核已执行后强制失败，验证整单回滚
            $this->maybeFailInject($operatorContext, 'archive_soft_delete');

            $now = time();
            $employee = Db::name('employee')->where('id', $employeeId)->lock(true)->find();
            if (!$employee) {
                throw new AdminException('员工不存在');
            }
            if ((int)($employee['is_del'] ?? 0) === 1) {
                return;
            }
            Db::name('employee')->where('id', $employeeId)->update([
                'is_del' => 1,
                'status' => 0,
                'update_time' => $now,
            ]);
            Db::name('system_store_staff')
                ->where('employee_id', $employeeId)
                ->where('is_del', 0)
                ->update([
                    'is_del' => 1,
                    'status' => 0,
                ]);
            $this->writeChangeLog($employeeId, 'employee_archive_soft_delete', 'employee', $employeeId, [
                'is_del' => 1,
                'keep_orders' => true,
                'keep_payroll_basis' => true,
                'keep_tenure_history' => true,
            ], $operatorContext);
        });
    }

    /**
     * 测试注入：仅当 operatorContext.__fail_at 命中时抛错（供本地 smoke 验证事务回滚）。
     */
    protected function maybeFailInject(array $operatorContext, string $at): void
    {
        if ((string)($operatorContext['__fail_at'] ?? '') === $at) {
            throw new AdminException('FAIL_INJECT:' . $at);
        }
    }

    /**
     * employee 权威资料批量投影到全部有效 staff（不含 uid）
     */
    public function projectEmployeeToActiveStaff(int $employeeId, array $profile): void
    {
        $update = [];
        if (isset($profile['name'])) {
            $update['staff_name'] = (string)$profile['name'];
        }
        if (isset($profile['phone'])) {
            $phone = $this->assertStrictPhone((string)$profile['phone']);
            $update['phone'] = $phone;
            $update['customer_phone'] = $phone;
        }
        if (isset($profile['avatar'])) {
            $update['avatar'] = (string)$profile['avatar'];
        }
        if (!$update) {
            return;
        }
        Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->update($update);
    }

    public function assertStrictPhone(string $phone): string
    {
        if ($phone === '' || $phone !== trim($phone) || strpos($phone, ' ') !== false || strpos($phone, '+') !== false) {
            throw new AdminException('手机号格式不正确，须为11位中国大陆手机号，不能含空格或+86');
        }
        if (!preg_match(self::PHONE_REGEX, $phone)) {
            throw new AdminException('手机号格式不正确，须为11位中国大陆手机号');
        }
        return $phone;
    }

    /**
     * @return array{employee_id:int,profile_updated:bool,profile:?array}
     */
    protected function lockOrCreateEmployee(
        int $linkedEmployeeId,
        string $phone,
        string $name,
        string $avatar,
        array $operatorContext,
        bool $allowProfileUpdate
    ): array {
        $now = time();
        $avatarType = $this->resolveAvatarType($avatar);

        if ($linkedEmployeeId > 0) {
            $employee = Db::name('employee')->where('id', $linkedEmployeeId)->lock(true)->find();
            if (!$employee || (int)($employee['is_del'] ?? 0) === 1) {
                throw new AdminException('关联的员工主档不存在或已删除');
            }
            $owner = Db::name('employee')->where('phone', $phone)->where('id', '<>', $linkedEmployeeId)->lock(true)->find();
            if ($owner) {
                throw new AdminException('该手机号已被其他员工使用');
            }
            if ($allowProfileUpdate) {
                Db::name('employee')->where('id', $linkedEmployeeId)->update([
                    'name' => $name,
                    'phone' => $phone,
                    'avatar' => $avatar,
                    'avatar_type' => $avatarType,
                    'update_time' => $now,
                ]);
                return [
                    'employee_id' => $linkedEmployeeId,
                    'profile_updated' => true,
                    'profile' => ['name' => $name, 'phone' => $phone, 'avatar' => $avatar],
                ];
            }
            return [
                'employee_id' => $linkedEmployeeId,
                'profile_updated' => false,
                'profile' => [
                    'name' => (string)$employee['name'],
                    'phone' => (string)$employee['phone'],
                    'avatar' => (string)$employee['avatar'],
                ],
            ];
        }

        // 未关联：按严格手机号查找/创建；命中已有主档时只建任职，不覆盖主档
        $employee = Db::name('employee')->where('phone', $phone)->lock(true)->find();
        if ($employee) {
            if ((int)($employee['is_del'] ?? 0) === 1) {
                $restored = $this->restoreDeletedEmployeeByPhone($phone, $operatorContext);
                if (!$restored) {
                    throw new AdminException('员工主档状态已变化，请重试');
                }
                return [
                    'employee_id' => (int)$restored['employee_id'],
                    'profile_updated' => true,
                    'profile' => [
                        'name' => (string)$restored['name'],
                        'phone' => (string)$restored['phone'],
                        'avatar' => (string)$restored['avatar'],
                    ],
                ];
            }
            return [
                'employee_id' => (int)$employee['id'],
                'profile_updated' => false,
                'profile' => [
                    'name' => (string)$employee['name'],
                    'phone' => (string)$employee['phone'],
                    'avatar' => (string)$employee['avatar'],
                ],
            ];
        }

        $employeeId = (int)Db::name('employee')->insertGetId([
            'name' => $name,
            'phone' => $phone,
            'avatar' => $avatar,
            'avatar_type' => $avatarType,
            'uid' => null,
            'status' => 1,
            'is_del' => 0,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($employeeId <= 0) {
            throw new AdminException('创建员工主档失败');
        }
        $this->writeChangeLog($employeeId, 'employee_create', 'employee', $employeeId, [
            'phone' => $phone,
            'name' => $name,
            'uid' => null,
        ], $operatorContext);
        return [
            'employee_id' => $employeeId,
            'profile_updated' => true,
            'profile' => ['name' => $name, 'phone' => $phone, 'avatar' => $avatar],
        ];
    }

    /**
     * 门店渠道：新建强制安全默认；编辑以库内总部字段覆盖客户端
     * 且禁止 applyRolesFlags 间接开收银/手机订单
     */
    protected function applyStoreChannelHqFieldPolicy(array &$staffPayload, ?array $existingStaff): void
    {
        foreach (self::STORE_FORBIDDEN_HQ_FIELDS as $field) {
            unset($staffPayload[$field]);
        }
        if ($existingStaff) {
            // J1：编辑已有任职时全部总部字段必须用数据库原值覆盖（含 is_butler，不得强制清零）
            $staffPayload['position'] = (int)($existingStaff['position'] ?? 0);
            $staffPayload['position_level'] = (int)($existingStaff['position_level'] ?? 0);
            $staffPayload['is_manager'] = (int)($existingStaff['is_manager'] ?? 0) === 1 ? 1 : 0;
            $staffPayload['is_butler'] = (int)($existingStaff['is_butler'] ?? 0) === 1 ? 1 : 0;
            $staffPayload['is_cashier'] = (int)($existingStaff['is_cashier'] ?? 0) === 1 ? 1 : 0;
            $staffPayload['order_status'] = (int)($existingStaff['order_status'] ?? 0) === 1 ? 1 : 0;
            $staffPayload['verify_status'] = (int)($existingStaff['verify_status'] ?? 0) === 1 ? 1 : 0;
        } else {
            $staffPayload['position'] = 0;
            $staffPayload['position_level'] = 0;
            $staffPayload['is_manager'] = 0;
            $staffPayload['is_butler'] = 0;
            $staffPayload['is_cashier'] = 0;
            $staffPayload['order_status'] = 0;
            $staffPayload['verify_status'] = 0;
        }
    }

    /**
     * 一个员工同时只能有一条有效门店任职。跨店变更必须走调店事务，
     * 不能通过新增或重新启用任职绕过原任职停用与任职历史记录。
     */
    protected function assertSingleActiveStoreAssignment(int $employeeId, int $excludeStaffId, int $targetStatus): void
    {
        if ($targetStatus !== 1) {
            return;
        }
        $q = Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->lock(true);
        if ($excludeStaffId > 0) {
            $q->where('id', '<>', $excludeStaffId);
        }
        $dup = $q->find();
        if ($dup) {
            throw new AdminException('该员工已有有效门店任职，如需变更门店请使用调店功能');
        }
    }

    /**
     * 门店角色校验。
     * - 门店通道：必须是总部已发布、本店有效、允许门店选择的 store_backend 角色，且不得含收银/手机规则
     * - 总部通道：type=1 且 relation_id 为本店或 0，status=1（兼容历史角色）
     */
    public function assertStoreRolesUsable(array $roleIds, int $storeId, string $channel = 'admin'): void
    {
        if (!$roleIds) {
            return;
        }
        if ($channel === 'store') {
            app()->make(\app\services\organization\SystemRolePublishServices::class)
                ->assertStoreSelectableRoleIds($roleIds, $storeId);
            return;
        }
        /** @var SystemRoleServices $roleServices */
        $roleServices = app()->make(SystemRoleServices::class);
        $roles = $roleServices->getColumn(['id' => $roleIds], '*');
        if (count($roles) !== count($roleIds)) {
            throw new AdminException('所选角色不存在或不可用');
        }
        foreach ($roles as $role) {
            if ((int)($role['type'] ?? 0) !== 1) {
                throw new AdminException('只能选择门店端角色');
            }
            $relationId = (int)($role['relation_id'] ?? 0);
            if ($relationId > 0 && $relationId !== $storeId) {
                throw new AdminException('不能选择其他门店的角色');
            }
            if ((int)($role['status'] ?? 1) !== 1) {
                throw new AdminException('角色已停用，请重新选择');
            }
        }
    }

    protected function resolveAvatarType(string $avatar): int
    {
        if ($avatar === '' || $avatar === self::DEFAULT_AVATAR || preg_match('/avatar_male\.(png|svg)/i', $avatar)) {
            return 1;
        }
        if (preg_match('/avatar_female\.(png|svg)/i', $avatar)) {
            return 2;
        }
        return 3;
    }

    protected function writeChangeLog(int $employeeId, string $action, string $targetType, int $targetId, array $after, array $operatorContext): void
    {
        $afterJson = json_encode($after, JSON_UNESCAPED_UNICODE);
        if ($afterJson === false) {
            throw new AdminException('员工审计序列化失败');
        }
        $source = (string)($operatorContext['source'] ?? 'admin');
        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'source' => $source,
            'before_data' => '',
            'after_data' => $afterJson,
            'reason' => (string)($operatorContext['reason'] ?? ''),
            'operator_type' => $source === 'store' ? 'store' : 'admin',
            'operator_id' => (int)($operatorContext['operator_id'] ?? 0),
            'operator_name' => (string)($operatorContext['operator_name'] ?? ''),
            'operator_ip' => (string)($operatorContext['operator_ip'] ?? ''),
            'request_id' => (string)($operatorContext['request_id'] ?? ''),
            'add_time' => time(),
        ]);
    }
}
