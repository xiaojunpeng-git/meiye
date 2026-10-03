<?php
namespace app\services\store;

use app\services\organization\OrganizationOpsStatusServices;
use app\services\organization\OrganizationStrictIdempotencyServices;
use app\services\organization\StaffJobPositionServices;
use app\services\employee\EmployeeStaffWriteServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * 门店手机号调入：精确查员工主档，目标仅来自强制门店会话。
 * 功能权限与员工列表相同（由路由中间件核验）；不新增审批或独立权限。
 * 有原店任职时复用调店内核；只有主档时仅建立本店任职并交由编辑页补全资料。
 * 历史订单/业绩仍引用原任职，不借调店改写经营事实。
 */
class StoreStaffPhoneTransferServices
{
    /** 只返回调入核对所需信息，不开放跨店人员列表或敏感档案。 */
    public function preview(string $phone, int $storeId, int $operatorStaffId): array
    {
        $this->assertOperator($storeId, $operatorStaffId);
        return $this->candidate($this->phone($phone), $storeId);
    }

    /**
     * 查询快照必须带正版本和完整指纹；相同令牌回放原结果，网络未知可原请求重试。
     * 幂等回执、结束/建立任职、权限投影和审计均在统一组织锁内同事务提交。
     */
    public function confirm(array $input, int $storeId, array $operator, array $requestCtx): array
    {
        // 客户端不得指定目标门店、角色或任职配置；即使多传也不静默接受。
        $allowed = ['phone', 'source_staff_id', 'employee_id', 'expected_version', 'snapshot_hash', 'request_token'];
        if (array_diff(array_keys($input), $allowed)) throw new AdminException('调店只能输入手机号码，目标固定为本门店');
        $phone = $this->phone((string)($input['phone'] ?? ''));
        foreach (['employee_id', 'expected_version'] as $key) {
            if (!isset($input[$key]) || !preg_match('/^[1-9][0-9]*$/D', (string)$input[$key])) {
                throw new AdminException('请先通过手机号码查询员工后再确认调入');
            }
        }
        if (!isset($input['source_staff_id']) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string)$input['source_staff_id'])) {
            throw new AdminException('请先通过手机号码查询员工后再确认调入');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', (string)($input['snapshot_hash'] ?? ''))) {
            throw new AdminException('员工查询结果无效，请重新查询');
        }
        $payload = array_intersect_key($input, array_flip($allowed));
        $payload['phone'] = $phone;
        $this->assertOperator($storeId, (int)($operator['id'] ?? 0));
        return app()->make(OrganizationStrictIdempotencyServices::class)->run(
            'store_staff_phone_transfer', 'store:' . $storeId . ':employee:' . (int)$input['employee_id'],
            $payload, $operator, $requestCtx,
            function (array $audit) use ($payload, $storeId, $operator): array {
                $this->assertOperator($storeId, (int)$operator['id'], true);
                $current = $this->candidate($payload['phone'], $storeId, true);
                if ($current['source_staff_id'] !== (int)$payload['source_staff_id']
                    || $current['employee_id'] !== (int)$payload['employee_id']
                    || $current['expected_version'] !== (int)$payload['expected_version']
                    || !hash_equals($current['snapshot_hash'], $payload['snapshot_hash'])) {
                    throw new AdminException('员工任职或资料已变化，请重新查询后再调入');
                }
                if ($current['source_staff_id'] === 0) {
                    return $this->attachWithoutActiveStore($current, $storeId, $audit);
                }
                app()->make(OrganizationOpsStatusServices::class)->assertStoreBusinessWritable($current['from_store_id']);
                $entries = Db::name('staff_channel_entry')->where('staff_id', $current['source_staff_id'])
                    ->where('is_del', 0)->order('id')->lock(true)->select()->toArray();
                $result = app()->make(StoreStaffTransferServices::class)->executeTransferKernel([
                    'source_staff_id' => $current['source_staff_id'], 'to_store_id' => $storeId,
                    'expected_from_store_id' => $current['from_store_id'], 'expected_employee_id' => $current['employee_id'],
                    'roles' => [], 'reason' => '门店手机号确认调入', 'strict_projection' => true,
                    'inherit_staff_settings' => true,
                    'operator' => ['id' => (int)$operator['id'], 'name' => (string)($operator['real_name'] ?? ''),
                        'type' => 2, 'ip' => (string)$audit['operator_ip'], 'request_id' => (string)$audit['request_id']],
                ]);
                $this->moveEntries($entries, $result, $audit);
                // 调店使原店身份失效：重算两店岗位投影并抬升员工认证版本，撤销旧商家会话。
                $jobs = app()->make(StaffJobPositionServices::class);
                $jobs->projectStaffRoles($result['source_staff_id']);
                $jobs->projectStaffRoles($result['execute_staff_id']);
                $jobs->syncManagerFlagFromJobs($result['execute_staff_id']);
                $auth = $jobs->afterEmployeeAuthChanged($result['employee_id']);
                // 内核审计保存调店经过；此追加快照保存入口开关迁移后的最终权限和认证版本。
                // 不覆盖内核原日志，更不改写任何经营事实。
                $final = Db::name('system_store_staff')->where('id', $result['execute_staff_id'])
                    ->field('id,employee_id,store_id,status,roles,cashier_salesperson_enabled,cashier_craftsman_enabled,craftsman_performance_type')->find();
                Db::name('employee_change_log')->insert([
                    'employee_id' => $result['employee_id'], 'action' => 'staff_phone_transfer_confirm',
                    'target_type' => 'staff', 'target_id' => $result['execute_staff_id'], 'source' => 'store',
                    'before_data' => json_encode(['source_staff_id' => $result['source_staff_id'], 'from_store_id' => $result['from_store_id'],
                        'auth_version' => $current['expected_version']], JSON_UNESCAPED_UNICODE),
                    'after_data' => json_encode(['assignment' => $final, 'auth_version' => $auth['auth_version']], JSON_UNESCAPED_UNICODE),
                    'reason' => '手机号确认调入本门店', 'operator_type' => 'store', 'operator_id' => $audit['operator_id'],
                    'operator_name' => $audit['operator_name'], 'operator_ip' => $audit['operator_ip'], 'request_id' => $audit['request_id'], 'add_time' => time(),
                ]);
                return ['msg' => '调入本门店成功', 'data' => $result + ['staff_name' => $current['staff_name'], 'needs_profile_completion' => false]];
            }, false
        );
    }

    /** 主档手机号唯一匹配；零或一条有效门店任职均可查询，多条仍须人工修复。 */
    private function candidate(string $phone, int $storeId, bool $lock = false): array
    {
        $employees = Db::name('employee')->where('phone', $phone)->where('is_del', 0)->order('id')->limit(2)->lock($lock)->select()->toArray();
        if (!$employees) throw new AdminException('未找到该手机号码对应的员工');
        if (count($employees) !== 1) throw new AdminException('该手机号码对应多个员工，请先处理重复档案');
        $employee = $employees[0];
        if ((int)$employee['status'] !== 1) throw new AdminException('该员工已离职，不能调入');
        // 旧主档可能尚未初始化认证版本；指纹仍以完整主档保护并发，调入后再统一抬升版本。
        $version = max(1, (int)($employee['auth_version'] ?? 0));
        $staff = Db::name('system_store_staff')->where('employee_id', (int)$employee['id'])
            ->where('status', 1)->where('is_del', 0)->order('id')->lock($lock)->select()->toArray();
        if (count($staff) > 1) throw new AdminException('员工有效任职异常，需先处理为唯一有效门店任职');
        $source = $staff[0] ?? null;
        if ($source && (int)$source['store_id'] === $storeId) throw new AdminException('该员工已在本门店，无需调入');
        $from = $source ? Db::name('system_store')->where('id', (int)$source['store_id'])->where('is_del', 0)->find() : null;
        if ($source && !$from) throw new AdminException('员工原门店不存在，不能调入');
        $positions = $source ? Db::name('staff_job_position')->where('staff_id', (int)$source['id'])->where('status', 1)
            ->where('is_del', 0)->where('end_time', 0)->order('id')->select()->toArray() : [];
        $entries = $source ? Db::name('staff_channel_entry')->where('staff_id', (int)$source['id'])->where('is_del', 0)->order('id')->select()->toArray() : [];
        $targetHistory = $source ? [] : Db::name('system_store_staff')->where('employee_id', (int)$employee['id'])
            ->where('store_id', $storeId)->where('is_del', 0)->order('id')->lock($lock)->select()->toArray();
        if (count($targetHistory) > 1) throw new AdminException('本店存在多条历史任职，请先处理重复任职');
        if (!$source && Db::name('staff_tenure_period')->where('employee_id', (int)$employee['id'])
            ->where('status', 1)->where('is_del', 0)->where('end_time', 0)->lock($lock)->find()) {
            throw new AdminException('员工任职期间状态异常，请先处理后调入');
        }
        // 指纹不是权限令牌，而是完整候选快照的并发保护；含岗位避免查询后变岗被悄悄携带。
        $hash = hash('sha256', json_encode([$employee, $source, $positions, $entries, $targetHistory], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['employee_id' => (int)$employee['id'], 'source_staff_id' => (int)($source['id'] ?? 0),
            'staff_name' => (string)$employee['name'], 'phone' => $phone,
            'from_store_id' => (int)($source['store_id'] ?? 0), 'from_store_name' => (string)($from['name'] ?? '暂无有效任职'),
            'to_store_id' => $storeId, 'expected_version' => $version, 'snapshot_hash' => $hash];
    }

    /** 无有效门店任职时只恢复/建立本店关系；岗位及其他资料交由随后的员工编辑页填写。 */
    private function attachWithoutActiveStore(array $current, int $storeId, array $audit): array
    {
        $employeeId = $current['employee_id'];
        $history = Db::name('system_store_staff')->where('employee_id', $employeeId)
            ->where('store_id', $storeId)->where('is_del', 0)->order('id')->lock(true)->select()->toArray();
        if (count($history) > 1) throw new AdminException('本店存在多条历史任职，请先处理重复任职');
        $old = $history[0] ?? null;
        if ($old && Db::name('staff_job_position')->where('staff_id', (int)$old['id'])
            ->where('status', 1)->where('is_del', 0)->where('end_time', 0)->lock(true)->find()) {
            throw new AdminException('本店历史岗位状态异常，请先处理后调入');
        }
        $operatorContext = ['operator_id' => $audit['operator_id'], 'operator_name' => $audit['operator_name'],
            'operator_ip' => $audit['operator_ip'], 'request_id' => $audit['request_id'],
            'source' => 'store', 'reason' => '手机号调入无任职员工'];
        $assignment = app()->make(EmployeeStaffWriteServices::class)->saveStaffAssignment(
            $old ? (int)$old['id'] : 0,
            ['store_id' => $storeId, 'staff_name' => $current['staff_name'], 'phone' => $current['phone'],
                'avatar' => (string)Db::name('employee')->where('id', $employeeId)->value('avatar'),
                'status' => 1, 'roles' => [], 'cashier_salesperson_enabled' => 0,
                'cashier_craftsman_enabled' => 0],
            $operatorContext, ['use_outer_transaction' => true]
        );
        $staffId = (int)$assignment['staff_id'];
        // 历史任职的渠道/权限不能因重新调入而复活；新岗位由编辑保存后投影。
        Db::name('system_store_staff')->where('id', $staffId)->update([
            'roles' => '', 'is_cashier' => 0, 'is_store' => 0, 'is_manager' => 0, 'is_butler' => 0,
            'order_status' => 0, 'verify_status' => 0, 'account' => '', 'pwd' => '',
            'cashier_salesperson_enabled' => 0, 'cashier_craftsman_enabled' => 0,
        ]);
        Db::name('staff_channel_entry')->where('staff_id', $staffId)->where('is_del', 0)->update(['status' => 0, 'update_time' => time()]);
        $jobs = app()->make(StaffJobPositionServices::class);
        $jobs->projectStaffRoles($staffId);
        $jobs->syncManagerFlagFromJobs($staffId);
        $auth = $jobs->afterEmployeeAuthChanged($employeeId);
        $now = time();
        Db::name('staff_tenure_period')->insert([
            'employee_id' => $employeeId, 'store_id' => $storeId, 'staff_id' => $staffId,
            'status' => 1, 'start_time' => $now, 'end_time' => 0, 'action' => 'transfer',
            'reason' => '手机号调入本门店', 'is_del' => 0,
            'request_token' => $audit['request_token'] . '#tenure',
            'operator_id' => $audit['operator_id'], 'operator_name' => $audit['operator_name'],
            'add_time' => $now, 'update_time' => $now,
        ]);
        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId, 'action' => 'staff_phone_transfer_confirm',
            'target_type' => 'staff', 'target_id' => $staffId, 'source' => 'store',
            'before_data' => json_encode(['source_staff_id' => 0, 'from_store_id' => 0,
                'auth_version' => $current['expected_version']], JSON_UNESCAPED_UNICODE),
            'after_data' => json_encode(['execute_staff_id' => $staffId, 'to_store_id' => $storeId,
                'auth_version' => $auth['auth_version']], JSON_UNESCAPED_UNICODE),
            'reason' => '手机号确认调入本门店', 'operator_type' => 'store',
            'operator_id' => $audit['operator_id'], 'operator_name' => $audit['operator_name'],
            'operator_ip' => $audit['operator_ip'], 'request_id' => $audit['request_id'], 'add_time' => $now,
        ]);
        return ['msg' => '调入本门店成功，请完善员工资料', 'data' => [
            'execute_staff_id' => $staffId, 'source_staff_id' => 0, 'employee_id' => $employeeId,
            'from_store_id' => 0, 'to_store_id' => $storeId,
            'staff_name' => $current['staff_name'], 'needs_profile_completion' => true,
        ]];
    }

    /** 原任职的入口开关完整携带（包括关闭状态），不能复活目标店历史权限。 */
    private function moveEntries(array $entries, array $result, array $audit): void
    {
        $now = time();
        Db::name('staff_channel_entry')->where('staff_id', $result['execute_staff_id'])->update(['is_del' => 1, 'status' => 0, 'update_time' => $now]);
        foreach ($entries as $entry) {
            $existing = Db::name('staff_channel_entry')->where('staff_id', $result['execute_staff_id'])->where('channel', $entry['channel'])->lock(true)->find();
            $data = ['employee_id' => $result['employee_id'], 'store_id' => $result['to_store_id'],
                'staff_id' => $result['execute_staff_id'], 'channel' => $entry['channel'], 'status' => (int)$entry['status'],
                'is_del' => 0, 'request_token' => $audit['request_token'] . '#ch#' . $entry['channel'],
                'operator_id' => $audit['operator_id'], 'operator_name' => $audit['operator_name'], 'update_time' => $now];
            if ($existing) Db::name('staff_channel_entry')->where('id', (int)$existing['id'])->update($data);
            else Db::name('staff_channel_entry')->insert($data + ['add_time' => $now]);
        }
        Db::name('staff_channel_entry')->where('staff_id', $result['source_staff_id'])->where('is_del', 0)->update(['status' => 0, 'update_time' => $now]);
    }

    /** 仅限制有效登录任职和强制当前门店，不另设员工编辑/调店权限。 */
    private function assertOperator(int $storeId, int $staffId, bool $lock = false): void
    {
        if ($storeId <= 0 || $staffId <= 0 || !Db::name('system_store_staff')->where('id', $staffId)
            ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->lock($lock)->find()) {
            throw new AdminException('当前门店登录任职已失效，请重新登录');
        }
        app()->make(OrganizationOpsStatusServices::class)->assertStoreBusinessWritable($storeId);
    }

    /** 输入只允许完整大陆手机号码，不提供前缀枚举或模糊检索。 */
    private function phone(string $phone): string
    {
        $phone = trim($phone);
        if (!preg_match('/^1[3-9][0-9]{9}$/D', $phone)) throw new AdminException('请输入完整的11位手机号码');
        return $phone;
    }
}
