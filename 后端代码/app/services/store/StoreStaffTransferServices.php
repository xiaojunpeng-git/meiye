<?php
namespace app\services\store;

use app\dao\store\StoreStaffTransferLogDao;
use app\services\BaseServices;
use app\services\employee\EmployeeStaffWriteServices;
use app\services\organization\OrganizationWorkspaceWriteGate;
use app\services\system\SystemRoleServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * I1 调店：唯一执行内核 = 停用原任职 + 创建/启用目标任职（禁止改原 staff.store_id）
 * 总部直接调店与申请批准共用本内核。
 */
class StoreStaffTransferServices extends BaseServices
{
    public function __construct(
        StoreStaffTransferLogDao $dao,
        SystemStoreStaffServices $staffServices
    ) {
        $this->dao = $dao;
        $this->staffServices = $staffServices;
    }

    /** @var SystemStoreStaffServices */
    protected $staffServices;

    /**
     * 总部直接调店（立即执行内核）
     */
    public function transfer(
        int $staffId,
        int $targetStoreId,
        array $roles,
        string $reason,
        int $immediate,
        array $operator
    ): bool {
        app()->make(OrganizationWorkspaceWriteGate::class)->assertCanWrite();
        if ($staffId <= 0 || $targetStoreId <= 0) {
            throw new AdminException('参数有误');
        }
        // 岗位决定功能权限：roles 改为可选兼容字段；空则由内核按原任职岗位投影
        $reason = trim($reason);
        if ($reason === '') {
            $reason = '人员调店';
        }

        return (bool)Db::transaction(function () use ($staffId, $targetStoreId, $roles, $reason, $operator) {
            $result = $this->executeTransferKernel([
                'source_staff_id' => $staffId,
                'to_store_id' => $targetStoreId,
                'roles' => $roles,
                'position' => 0,
                'position_level' => 0,
                'is_manager' => 0,
                'is_cashier' => 0,
                'reason' => $reason,
                'apply_id' => 0,
                'operator' => $operator,
            ]);
            return !empty($result['execute_staff_id']);
        });
    }

    /**
     * 调店执行内核（须在已有事务内调用，或由本类 transfer/申请批准包裹）
     * @param array{
     *   source_staff_id:int,to_store_id:int,roles:array,position?:int,position_level?:int,
     *   is_manager?:int,is_cashier?:int,reason:string,apply_id?:int,operator:array,
     *   expected_from_store_id?:int,expected_employee_id?:int
     * } $ctx
     * @return array{execute_staff_id:int,source_staff_id:int,employee_id:int,from_store_id:int,to_store_id:int}
     */
    public function executeTransferKernel(array $ctx): array
    {
        $sourceStaffId = (int)($ctx['source_staff_id'] ?? 0);
        $toStoreId = (int)($ctx['to_store_id'] ?? 0);
        $applyId = (int)($ctx['apply_id'] ?? 0);
        $reason = trim((string)($ctx['reason'] ?? ''));
        $operator = is_array($ctx['operator'] ?? null) ? $ctx['operator'] : [];
        $roles = $ctx['roles'] ?? [];
        if (!is_array($roles)) {
            $roles = $roles !== '' ? explode(',', (string)$roles) : [];
        }
        $roles = array_values(array_unique(array_filter(array_map('intval', $roles))));
        if ($sourceStaffId <= 0 || $toStoreId <= 0) {
            throw new AdminException('调店参数有误');
        }

        $toStore = Db::name('system_store')->where('id', $toStoreId)->lock(true)->find();
        if (!$toStore || (int)($toStore['is_del'] ?? 0) === 1) {
            throw new AdminException('目标门店不存在');
        }
        if ((int)($toStore['is_show'] ?? 0) !== 1) {
            throw new AdminException('目标门店未启用');
        }

        $sourceStaff = Db::name('system_store_staff')->where('id', $sourceStaffId)->lock(true)->find();
        if (!$sourceStaff || (int)($sourceStaff['is_del'] ?? 0) === 1) {
            throw new AdminException('原门店任职不存在');
        }
        // 岗位决定功能权限：调店不再携带原店投影角色（relation_id 绑定原店会校验失败）
        // 目标店角色由到店后岗位投影重建；显式传入的 roles 仍可兼容旧审批
        if (!$roles) {
            $roles = [];
        }
        $fromStoreId = (int)$sourceStaff['store_id'];
        $employeeId = (int)($sourceStaff['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            throw new AdminException('员工未关联主档，无法调店');
        }
        if (!empty($ctx['expected_from_store_id']) && (int)$ctx['expected_from_store_id'] !== $fromStoreId) {
            throw new AdminException('原门店任职与申请不一致');
        }
        if (!empty($ctx['expected_employee_id']) && (int)$ctx['expected_employee_id'] !== $employeeId) {
            throw new AdminException('原门店任职与申请不一致');
        }
        if ($fromStoreId === $toStoreId) {
            throw new AdminException('目标门店与当前门店相同');
        }
        if ((int)($sourceStaff['status'] ?? 0) !== 1) {
            throw new AdminException('原门店任职已失效，无法调店');
        }

        $employee = Db::name('employee')->where('id', $employeeId)->lock(true)->find();
        if (!$employee || (int)($employee['is_del'] ?? 0) === 1 || (int)($employee['status'] ?? 0) !== 1) {
            throw new AdminException('员工主档无效或已离职');
        }

        $targetRows = Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('store_id', $toStoreId)
            ->where('is_del', 0)
            ->lock(true)
            ->select()
            ->toArray();
        $activeTargets = [];
        $inactiveTargets = [];
        foreach ($targetRows as $row) {
            if ((int)$row['status'] === 1) {
                $activeTargets[] = $row;
            } else {
                $inactiveTargets[] = $row;
            }
        }
        if (count($activeTargets) > 0) {
            throw new AdminException('目标门店已有有效任职，不能重复调店');
        }
        if (count($inactiveTargets) > 1) {
            throw new AdminException('目标门店存在多条历史任职，无法自动选择，请总部处理后再试');
        }

        /** @var EmployeeStaffWriteServices $write */
        $write = app()->make(EmployeeStaffWriteServices::class);
        if ($roles) {
            $write->assertStoreRolesUsable($roles, $toStoreId);
        }

        // 读取原任职有效岗位，调店后带到 B 店
        $sourcePosIds = Db::name('staff_job_position')
            ->where('staff_id', $sourceStaffId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('end_time', 0)
            ->column('position_id');
        $sourcePosIds = array_values(array_unique(array_filter(array_map('intval', $sourcePosIds ?: []))));

        $staffUpdate = [
            'roles' => $roles,
            'position' => (int)($ctx['position'] ?? 0),
            'position_level' => (int)($ctx['position_level'] ?? 0),
            'is_manager' => (int)($ctx['is_manager'] ?? 0) === 1 ? 1 : 0,
            'is_cashier' => (int)($ctx['is_cashier'] ?? 0) === 1 ? 1 : 0,
            'is_store' => 1,
            'status' => 1,
            'staff_name' => (string)($employee['name'] ?? $sourceStaff['staff_name'] ?? ''),
            'phone' => (string)($employee['phone'] ?? $sourceStaff['phone'] ?? ''),
            'avatar' => (string)($employee['avatar'] ?? $sourceStaff['avatar'] ?? ''),
            'employee_id' => $employeeId,
            'customer_phone' => (string)($employee['phone'] ?? $sourceStaff['phone'] ?? ''),
        ];
        // 总部调店允许按审批配置设置收银/店长；仍禁止写 uid
        if ($roles) {
            $this->staffServices->applyRolesFlags($staffUpdate);
        }
        // 若总部显式传了 is_cashier/is_manager，以总部配置为准（覆盖 flags）
        if (array_key_exists('is_cashier', $ctx)) {
            $staffUpdate['is_cashier'] = (int)$ctx['is_cashier'] === 1 ? 1 : 0;
        }
        if (array_key_exists('is_manager', $ctx)) {
            $staffUpdate['is_manager'] = (int)$ctx['is_manager'] === 1 ? 1 : 0;
        }

        $fromRoles = $sourceStaff['roles'] ?? [];
        if (!is_array($fromRoles)) {
            $fromRoles = $fromRoles !== '' ? explode(',', (string)$fromRoles) : [];
        }

        // 停用原任职：禁止修改 store_id
        Db::name('system_store_staff')->where('id', $sourceStaffId)->update([
            'status' => 0,
            'roles' => '',
            'order_status' => 0,
            'is_cashier' => 0,
            'is_manager' => 0,
            'is_store' => 0,
        ]);

        if (count($inactiveTargets) === 1) {
            $executeStaffId = (int)$inactiveTargets[0]['id'];
            $staffUpdate['store_id'] = $toStoreId;
            unset($staffUpdate['uid']);
            if (!$this->staffServices->update($executeStaffId, $staffUpdate)) {
                throw new AdminException('启用目标门店任职失败');
            }
        } else {
            $create = $staffUpdate;
            $create['store_id'] = $toStoreId;
            $create['account'] = '';
            $create['add_time'] = time();
            unset($create['uid']);
            $res = $this->staffServices->save($create);
            $executeStaffId = (int)(is_object($res) ? $res->id : ($res['id'] ?? 0));
            if ($executeStaffId <= 0) {
                throw new AdminException('创建目标门店任职失败');
            }
        }

        // 复核：原店 store_id 未变
        $recheck = Db::name('system_store_staff')->where('id', $sourceStaffId)->find();
        if (!$recheck || (int)$recheck['store_id'] !== $fromStoreId) {
            throw new AdminException('调店异常：原任职门店被改写');
        }

        $fromStore = Db::name('system_store')->where('id', $fromStoreId)->find();
        $now = time();
        $opId = (int)($operator['id'] ?? 0);
        $opName = (string)($operator['name'] ?? '');

        // 结束 A 店岗位与任职期间；B 店建立岗位与新任职期间
        Db::name('staff_job_position')
            ->where('staff_id', $sourceStaffId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('end_time', 0)
            ->update([
                'status' => 0,
                'end_time' => $now,
                'reason' => '调店结束原店岗位',
                'update_time' => $now,
                'operator_id' => $opId,
                'operator_name' => $opName,
            ]);
        $activeTenure = Db::name('staff_tenure_period')
            ->where('staff_id', $sourceStaffId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('end_time', 0)
            ->select()
            ->toArray();
        foreach ($activeTenure as $tr) {
            Db::name('staff_tenure_period')->where('id', (int)$tr['id'])->update([
                'status' => 0,
                'end_time' => $now,
                'action' => 'transfer',
                'reason' => $reason !== '' ? $reason : '调店',
                'update_time' => $now,
                'operator_id' => $opId,
                'operator_name' => $opName,
            ]);
        }
        if (!$activeTenure) {
            Db::name('staff_tenure_period')->insert([
                'employee_id' => $employeeId,
                'store_id' => $fromStoreId,
                'staff_id' => $sourceStaffId,
                'status' => 0,
                'start_time' => (int)($sourceStaff['add_time'] ?? $now),
                'end_time' => $now,
                'action' => 'transfer',
                'reason' => $reason !== '' ? $reason : '调店',
                'is_del' => 0,
                'request_token' => 'transfer-end-' . $sourceStaffId . '-' . $now,
                'operator_id' => $opId,
                'operator_name' => $opName,
                'add_time' => $now,
                'update_time' => $now,
            ]);
        }
        Db::name('staff_tenure_period')->insert([
            'employee_id' => $employeeId,
            'store_id' => $toStoreId,
            'staff_id' => $executeStaffId,
            'status' => 1,
            'start_time' => $now,
            'end_time' => 0,
            'action' => 'transfer',
            'reason' => $reason !== '' ? $reason : '调店入职',
            'is_del' => 0,
            'request_token' => 'transfer-start-' . $executeStaffId . '-' . $now,
            'operator_id' => $opId,
            'operator_name' => $opName,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($sourcePosIds) {
            foreach ($sourcePosIds as $pid) {
                Db::name('staff_job_position')->insert([
                    'employee_id' => $employeeId,
                    'store_id' => $toStoreId,
                    'staff_id' => $executeStaffId,
                    'position_id' => $pid,
                    'status' => 1,
                    'start_time' => $now,
                    'end_time' => 0,
                    'reason' => '调店带岗',
                    'is_del' => 0,
                    'request_token' => sprintf(
                        'trjob-%d-%d-%d-%s',
                        $executeStaffId,
                        $pid,
                        $now,
                        substr(md5((string)microtime(true)), 0, 8)
                    ),
                    'operator_id' => $opId,
                    'operator_name' => $opName,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
            }
            try {
                /** @var \app\services\organization\StaffJobPositionServices $jobSvc */
                $jobSvc = app()->make(\app\services\organization\StaffJobPositionServices::class);
                $jobSvc->projectStaffRoles($executeStaffId);
                $jobSvc->syncManagerFlagFromJobs($executeStaffId);
            } catch (\Throwable $e) {
            }
        }
        // 统一账号投影到新任职
        try {
            /** @var \app\services\employee\EmployeeInternalAccountServices $acctSvc */
            $acctSvc = app()->make(\app\services\employee\EmployeeInternalAccountServices::class);
            $acct = $acctSvc->getByEmployeeId($employeeId);
            if ($acct) {
                $acctSvc->projectCredentials(
                    $employeeId,
                    (string)$acct['account'],
                    (string)$acct['pwd'],
                    (int)$acct['status']
                );
            }
        } catch (\Throwable $e) {
            // 表未就绪时不阻断调店
        }
        // 数据权限：切到 B 店 store_self（有本店来源则更新；无则保持）
        try {
            Db::name('employee_data_scope')
                ->where('employee_id', $employeeId)
                ->where('source_type', 'store')
                ->where('source_store_id', $fromStoreId)
                ->where('is_del', 0)
                ->update([
                    'source_store_id' => $toStoreId,
                    'update_time' => $now,
                ]);
        } catch (\Throwable $e) {
        }

        $logRow = [
            'staff_id' => $sourceStaffId,
            'execute_staff_id' => $executeStaffId,
            'apply_id' => $applyId,
            'staff_name' => (string)($sourceStaff['staff_name'] ?? ''),
            'employee_id' => $employeeId,
            'from_store_id' => $fromStoreId,
            'from_store_name' => (string)($fromStore['name'] ?? ''),
            'to_store_id' => $toStoreId,
            'to_store_name' => (string)($toStore['name'] ?? ''),
            'from_roles' => json_encode(array_values($fromRoles), JSON_UNESCAPED_UNICODE),
            'to_roles' => json_encode(array_values($roles), JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
            'operator_id' => (int)($operator['id'] ?? 0),
            'operator_name' => (string)($operator['name'] ?? ''),
            'operator_type' => (int)($operator['type'] ?? 1),
            'immediate' => 1,
            'effective_time' => $now,
            'add_time' => $now,
        ];
        // 兼容未升级列
        $this->insertTransferLog($logRow);

        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId,
            'action' => 'staff_transfer_execute',
            'target_type' => $applyId > 0 ? 'transfer_apply' : 'staff',
            'target_id' => $applyId > 0 ? $applyId : $sourceStaffId,
            'source' => 'admin',
            'before_data' => json_encode([
                'source_staff_id' => $sourceStaffId,
                'from_store_id' => $fromStoreId,
                'source_store_id_kept' => $fromStoreId,
            ], JSON_UNESCAPED_UNICODE) ?: '',
            'after_data' => json_encode([
                'execute_staff_id' => $executeStaffId,
                'to_store_id' => $toStoreId,
                'roles' => $roles,
            ], JSON_UNESCAPED_UNICODE) ?: '',
            'reason' => $reason,
            'operator_type' => 'admin',
            'operator_id' => (int)($operator['id'] ?? 0),
            'operator_name' => (string)($operator['name'] ?? ''),
            'operator_ip' => (string)($operator['ip'] ?? ''),
            'request_id' => (string)($operator['request_id'] ?? ''),
            'add_time' => $now,
        ]);

        return [
            'execute_staff_id' => $executeStaffId,
            'source_staff_id' => $sourceStaffId,
            'employee_id' => $employeeId,
            'from_store_id' => $fromStoreId,
            'to_store_id' => $toStoreId,
        ];
    }

    protected function insertTransferLog(array $row): void
    {
        $cols = [];
        try {
            foreach (Db::query('SHOW COLUMNS FROM `eb_store_staff_transfer_log`') as $c) {
                $cols[$c['Field']] = true;
            }
        } catch (\Throwable $e) {
            throw new AdminException('调店记录表未就绪，请先执行升级包 20260722-027');
        }
        $data = [];
        foreach ($row as $k => $v) {
            if (isset($cols[$k])) {
                $data[$k] = $v;
            }
        }
        Db::name('store_staff_transfer_log')->insert($data);
    }

    public function getTransferLogList(array $where): array
    {
        [$page, $limit] = $this->getPageValue();
        $limit = min(50, max(1, (int)$limit));
        $page = max(1, (int)$page);
        $result = $this->dao->getList($where, $page, $limit);
        if (!empty($result['list'])) {
            /** @var SystemRoleServices $roleServices */
            $roleServices = app()->make(SystemRoleServices::class);
            foreach ($result['list'] as &$item) {
                $item['from_roles_text'] = $this->formatRolesText($item['from_roles'] ?? '', $roleServices);
                $item['to_roles_text'] = $this->formatRolesText($item['to_roles'] ?? '', $roleServices);
                $item['add_time_text'] = !empty($item['add_time']) ? date('Y-m-d H:i:s', (int)$item['add_time']) : '';
                $item['effective_time_text'] = !empty($item['effective_time']) ? date('Y-m-d H:i:s', (int)$item['effective_time']) : '';
            }
            unset($item);
        }
        return $result;
    }

    protected function formatRolesText($rolesValue, SystemRoleServices $roleServices): string
    {
        if ($rolesValue === '' || $rolesValue === null) {
            return '';
        }
        $roleIds = json_decode((string)$rolesValue, true);
        if (!is_array($roleIds)) {
            $roleIds = array_filter(array_map('intval', explode(',', (string)$rolesValue)));
        }
        if (!$roleIds) {
            return '';
        }
        $names = $roleServices->getColumn([['id', 'in', $roleIds]], 'role_name');
        return $names ? implode(',', $names) : '';
    }
}
