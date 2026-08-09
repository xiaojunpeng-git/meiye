<?php
namespace app\services\organization;

use app\services\BaseServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * I2 单店任职期间：停职 / 复职 / 软删除
 */
class StaffTenureServices extends BaseServices
{
    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function suspendStore(
        int $employeeId,
        int $staffId,
        string $reason,
        array $adminInfo,
        array $requestCtx,
        bool $requireSuperAdmin = true
    ): array {
        $payload = [
            'employee_id' => $employeeId,
            'staff_id' => $staffId,
            'action' => 'suspend',
            'reason' => $reason,
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'staff_tenure_suspend',
            'employee:' . $employeeId . ':staff:' . $staffId . ':suspend',
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($employeeId, $staffId, $reason, $adminInfo) {
                $staff = $this->lockStaffForEmployee($employeeId, $staffId);
                $storeId = (int)$staff['store_id'];
                $now = time();
                $opId = (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0);
                $opName = (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? '');
                $token = (string)($auditMeta['request_token'] ?? '');
                $reason = trim($reason) !== '' ? trim($reason) : '单店停职';

                Db::name('system_store_staff')->where('id', $staffId)->update([
                    'status' => 0,
                ]);

                $this->closeChannelEntries($staffId, $now, $opId, $opName, $token, '停职关闭入口');
                $this->endActiveJobs($staffId, $now, $opId, $opName, $reason);
                $this->endActiveTenure($staffId, $now, $opId, $opName, 'suspend', $reason, $token);

                /** @var EmployeeDataScopeServices $scopeSvc */
                $scopeSvc = app()->make(EmployeeDataScopeServices::class);
                $scopeSvc->revokeStoreSourceScope($employeeId, $storeId, $auditMeta);

                $this->bumpMerchantAuthVersion($employeeId);

                $this->writeAudit(
                    $employeeId,
                    'staff_tenure_suspend',
                    $staffId,
                    ['staff_id' => $staffId, 'store_id' => $storeId, 'status' => 0],
                    $adminInfo,
                    $auditMeta,
                    $reason
                );
                return [
                    'msg' => '已办理本店停职',
                    'data' => [
                        'staff_id' => $staffId,
                        'store_id' => $storeId,
                        'status' => 0,
                        'help' => '仅关闭本店任职、岗位、入口和本店来源数据授权；其他门店与员工主档不受影响；历史订单仍保留。',
                    ],
                ];
            },
            $requireSuperAdmin
        );
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function resumeStore(
        int $employeeId,
        int $staffId,
        string $reason,
        array $adminInfo,
        array $requestCtx,
        bool $requireSuperAdmin = true
    ): array {
        $payload = [
            'employee_id' => $employeeId,
            'staff_id' => $staffId,
            'action' => 'resume',
            'reason' => $reason,
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'staff_tenure_resume',
            'employee:' . $employeeId . ':staff:' . $staffId . ':resume',
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($employeeId, $staffId, $reason, $adminInfo) {
                $staff = $this->lockStaffForEmployee($employeeId, $staffId);
                if ((int)($staff['is_del'] ?? 0) === 1) {
                    throw new AdminException('本店关系已删除，请重新入职，不能直接复职');
                }
                if ((int)($staff['status'] ?? 0) === 1) {
                    throw new AdminException('当前任职已生效，无需重复复职');
                }
                $storeId = (int)$staff['store_id'];
                $otherActiveStaff = Db::name('system_store_staff')
                    ->where('employee_id', $employeeId)
                    ->where('status', 1)
                    ->where('is_del', 0)
                    ->where('id', '<>', $staffId)
                    ->lock(true)
                    ->find();
                if ($otherActiveStaff) {
                    throw new AdminException('该员工已有有效门店任职，如需变更门店请使用调店功能');
                }
                $now = time();
                $opId = (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0);
                $opName = (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? '');
                $token = (string)($auditMeta['request_token'] ?? '');
                $reason = trim($reason) !== '' ? trim($reason) : '单店复职';

                Db::name('system_store_staff')->where('id', $staffId)->update([
                    'status' => 1,
                ]);

                // 新任职期间；不自动重开岗位与入口
                Db::name('staff_tenure_period')->insert([
                    'employee_id' => $employeeId,
                    'store_id' => $storeId,
                    'staff_id' => $staffId,
                    'status' => 1,
                    'start_time' => $now,
                    'end_time' => 0,
                    'action' => 'resume',
                    'reason' => $reason,
                    'is_del' => 0,
                    'request_token' => $token !== '' ? $token : ('tenure-resume-' . $staffId . '-' . $now),
                    'operator_id' => $opId,
                    'operator_name' => $opName,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);

                /** @var StaffJobPositionServices $jobSvc */
                $jobSvc = app()->make(StaffJobPositionServices::class);
                $jobSvc->afterEmployeeAuthChanged($employeeId);

                $this->writeAudit(
                    $employeeId,
                    'staff_tenure_resume',
                    $staffId,
                    ['staff_id' => $staffId, 'store_id' => $storeId, 'status' => 1],
                    $adminInfo,
                    $auditMeta,
                    $reason
                );
                return [
                    'msg' => '已办理本店复职',
                    'data' => [
                        'staff_id' => $staffId,
                        'store_id' => $storeId,
                        'status' => 1,
                        'help' => '已开启新的任职期间。岗位与入口不会自动恢复，请重新设置后再开通。',
                    ],
                ];
            },
            $requireSuperAdmin
        );
    }

    /**
     * 单店软删除：失效本店任职/岗位/入口/门店来源数据范围，建立隔离；保留员工与其他门店
     * @return array{msg:string,data:array,replay:bool}
     */
    public function softDeleteStore(
        int $employeeId,
        int $staffId,
        string $reason,
        array $adminInfo,
        array $requestCtx,
        bool $requireSuperAdmin = true
    ): array {
        $payload = [
            'employee_id' => $employeeId,
            'staff_id' => $staffId,
            'action' => 'delete',
            'reason' => $reason,
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'staff_tenure_delete',
            'employee:' . $employeeId . ':staff:' . $staffId . ':delete',
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($employeeId, $staffId, $reason, $adminInfo) {
                $staff = $this->lockStaffForEmployee($employeeId, $staffId, false);
                $storeId = (int)$staff['store_id'];
                $now = time();
                $opId = (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0);
                $opName = (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? '');
                $token = (string)($auditMeta['request_token'] ?? '');
                $reason = trim($reason) !== '' ? trim($reason) : '单店删除';

                Db::name('system_store_staff')->where('id', $staffId)->update([
                    'status' => 0,
                    'is_del' => 1,
                    'is_store' => 0,
                    'is_cashier' => 0,
                ]);

                $this->closeChannelEntries($staffId, $now, $opId, $opName, $token, '删除关闭入口', true);
                $this->endActiveJobs($staffId, $now, $opId, $opName, $reason, true);
                $this->endActiveTenure($staffId, $now, $opId, $opName, 'delete', $reason, $token);

                /** @var EmployeeDataScopeServices $scopeSvc */
                $scopeSvc = app()->make(EmployeeDataScopeServices::class);
                $scopeSvc->revokeStoreSourceScope($employeeId, $storeId, $auditMeta);

                $iso = Db::name('employee_store_isolation')
                    ->where('employee_id', $employeeId)
                    ->where('store_id', $storeId)
                    ->lock(true)
                    ->find();
                if ($iso) {
                    Db::name('employee_store_isolation')->where('id', (int)$iso['id'])->update([
                        'status' => 1,
                        'is_del' => 0,
                        'reason' => $reason,
                        'request_token' => $token !== '' ? $token : (string)($iso['request_token'] ?? ''),
                        'operator_id' => $opId,
                        'operator_name' => $opName,
                        'update_time' => $now,
                    ]);
                } else {
                    Db::name('employee_store_isolation')->insert([
                        'employee_id' => $employeeId,
                        'store_id' => $storeId,
                        'status' => 1,
                        'reason' => $reason,
                        'request_token' => $token !== '' ? $token : ('iso-' . $employeeId . '-' . $storeId . '-' . $now),
                        'is_del' => 0,
                        'operator_id' => $opId,
                        'operator_name' => $opName,
                        'add_time' => $now,
                        'update_time' => $now,
                    ]);
                }

                $this->bumpMerchantAuthVersion($employeeId);

                $this->writeAudit(
                    $employeeId,
                    'staff_tenure_delete',
                    $staffId,
                    ['staff_id' => $staffId, 'store_id' => $storeId, 'isolated' => 1],
                    $adminInfo,
                    $auditMeta,
                    $reason
                );
                return [
                    'msg' => '已删除本店关系',
                    'data' => [
                        'staff_id' => $staffId,
                        'store_id' => $storeId,
                        'isolated' => 1,
                        'help' => '本人将无法再看到本店相关历史；其他门店不受影响；门店订单和账务仍保留，总部/门店账务可追溯。',
                    ],
                ];
            },
            $requireSuperAdmin
        );
    }

    /**
     * 统一入口：action=suspend|resume|delete
     * @return array{msg:string,data:array,replay:bool}
     */
    public function runAction(
        int $employeeId,
        array $data,
        array $adminInfo,
        array $requestCtx,
        bool $requireSuperAdmin = true
    ): array {
        $action = trim((string)($data['action'] ?? ''));
        $staffId = (int)($data['staff_id'] ?? 0);
        $reason = (string)($data['reason'] ?? '');
        if ($staffId <= 0) {
            throw new AdminException('请指定门店任职');
        }
        if ($action === 'suspend') {
            return $this->suspendStore($employeeId, $staffId, $reason, $adminInfo, $requestCtx, $requireSuperAdmin);
        }
        if ($action === 'resume') {
            return $this->resumeStore($employeeId, $staffId, $reason, $adminInfo, $requestCtx, $requireSuperAdmin);
        }
        if ($action === 'delete') {
            return $this->softDeleteStore($employeeId, $staffId, $reason, $adminInfo, $requestCtx, $requireSuperAdmin);
        }
        throw new AdminException('不支持的任职操作');
    }

    /**
     * @return array<int, array>
     */
    public function listTenurePeriods(int $staffId): array
    {
        if ($staffId <= 0) {
            return [];
        }
        return Db::name('staff_tenure_period')
            ->where('staff_id', $staffId)
            ->where('is_del', 0)
            ->order('id', 'desc')
            ->limit(50)
            ->select()->toArray();
    }

    protected function lockStaffForEmployee(int $employeeId, int $staffId, bool $requireNotDeleted = true): array
    {
        $emp = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->lock(true)->find();
        if (!$emp) {
            throw new AdminException('员工不存在');
        }
        $q = Db::name('system_store_staff')->where('id', $staffId);
        if ($requireNotDeleted) {
            $q->where('is_del', 0);
        }
        $staff = $q->lock(true)->find();
        if (!$staff || (int)$staff['employee_id'] !== $employeeId) {
            throw new AdminException('任职与员工不匹配');
        }
        return $staff;
    }

    protected function closeChannelEntries(
        int $staffId,
        int $now,
        int $opId,
        string $opName,
        string $token,
        string $reason,
        bool $softDel = false
    ): void {
        $upd = [
            'status' => 0,
            'update_time' => $now,
            'operator_id' => $opId,
            'operator_name' => $opName,
        ];
        if ($softDel) {
            $upd['is_del'] = 1;
        }
        Db::name('staff_channel_entry')
            ->where('staff_id', $staffId)
            ->where('is_del', 0)
            ->update($upd);
    }

    protected function endActiveJobs(
        int $staffId,
        int $now,
        int $opId,
        string $opName,
        string $reason,
        bool $softDel = false
    ): void {
        $upd = [
            'status' => 0,
            'end_time' => $now,
            'reason' => $reason,
            'update_time' => $now,
            'operator_id' => $opId,
            'operator_name' => $opName,
        ];
        if ($softDel) {
            $upd['is_del'] = 1;
        }
        Db::name('staff_job_position')
            ->where('staff_id', $staffId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('end_time', 0)
            ->update($upd);
    }

    protected function endActiveTenure(
        int $staffId,
        int $now,
        int $opId,
        string $opName,
        string $action,
        string $reason,
        string $token
    ): void {
        $active = Db::name('staff_tenure_period')
            ->where('staff_id', $staffId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('end_time', 0)
            ->lock(true)
            ->select()->toArray();
        foreach ($active as $row) {
            Db::name('staff_tenure_period')->where('id', (int)$row['id'])->update([
                'status' => 0,
                'end_time' => $now,
                'action' => $action,
                'reason' => $reason,
                'update_time' => $now,
                'operator_id' => $opId,
                'operator_name' => $opName,
            ]);
        }
        // 若无进行中期间，补一条已结束期间便于审计
        if (!$active && in_array($action, ['suspend', 'delete'], true)) {
            $staff = Db::name('system_store_staff')->where('id', $staffId)->find();
            if ($staff) {
                Db::name('staff_tenure_period')->insert([
                    'employee_id' => (int)$staff['employee_id'],
                    'store_id' => (int)$staff['store_id'],
                    'staff_id' => $staffId,
                    'status' => 0,
                    'start_time' => $now,
                    'end_time' => $now,
                    'action' => $action,
                    'reason' => $reason,
                    'is_del' => 0,
                    'request_token' => $token !== '' ? ($token . '#tenure') : ('tenure-' . $action . '-' . $staffId . '-' . $now),
                    'operator_id' => $opId,
                    'operator_name' => $opName,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
            }
        }
    }

    /**
     * 任职变更：抬升 auth_version、重算平台/手机投影、失效商家会话
     */
    protected function bumpMerchantAuthVersion(int $employeeId): void
    {
        if ($employeeId <= 0) {
            return;
        }
        /** @var StaffJobPositionServices $jobSvc */
        $jobSvc = app()->make(StaffJobPositionServices::class);
        $staffIds = Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->column('id');
        foreach ($staffIds as $sid) {
            $jobSvc->projectStaffRoles((int)$sid);
        }
        $jobSvc->afterEmployeeAuthChanged($employeeId);
    }

    protected function writeAudit(
        int $employeeId,
        string $action,
        int $targetId,
        array $after,
        array $operator,
        array $auditMeta,
        string $reason
    ): void {
        $json = json_encode($after, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new AdminException('审计序列化失败');
        }
        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId,
            'action' => $action,
            'target_type' => 'staff_tenure',
            'target_id' => $targetId,
            'source' => 'admin',
            'before_data' => '',
            'after_data' => $json,
            'reason' => $reason,
            'operator_type' => 'admin',
            'operator_id' => (int)($auditMeta['operator_id'] ?? $operator['id'] ?? 0),
            'operator_name' => (string)($auditMeta['operator_name'] ?? $operator['real_name'] ?? $operator['account'] ?? ''),
            'operator_ip' => (string)($auditMeta['operator_ip'] ?? ''),
            'request_id' => (string)($auditMeta['request_id'] ?? ''),
            'add_time' => time(),
        ]);
    }
}
