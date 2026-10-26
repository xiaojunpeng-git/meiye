<?php
namespace app\services\store;

use app\dao\store\StoreStaffTransferApplyDao;
use app\services\BaseServices;
use app\services\organization\OrganizationWorkspaceWriteGate;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * I1 调店申请：门店申请/取消；总部驳回；总部批准+执行同事务
 * 状态：pending / executed / rejected / cancelled（无独立 approved）
 */
class StoreStaffTransferApplyServices extends BaseServices
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const MAX_LIMIT = 50;

    public function __construct(StoreStaffTransferApplyDao $dao)
    {
        $this->dao = $dao;
    }

    public function createByStore(array $data, array $operator): array
    {
        app()->make(OrganizationWorkspaceWriteGate::class)->assertCanWrite();

        $token = trim((string)($data['request_token'] ?? ''));
        if ($token === '' || strlen($token) > 64) {
            throw new AdminException('缺少有效的请求令牌');
        }
        $sourceStaffId = (int)($data['source_staff_id'] ?? 0);
        $toStoreId = (int)($data['to_store_id'] ?? 0);
        $reason = trim((string)($data['reason'] ?? ''));
        $applicantStoreId = (int)($operator['store_id'] ?? 0);
        if ($sourceStaffId <= 0 || $toStoreId <= 0 || $applicantStoreId <= 0) {
            throw new AdminException('参数有误');
        }
        if ($reason === '') {
            throw new AdminException('请填写调店原因');
        }

        return $this->createApplyCore([
            'request_token' => $token,
            'source_staff_id' => $sourceStaffId,
            'to_store_id' => $toStoreId,
            'reason' => $reason,
            'applicant_type' => 2,
            'applicant_store_id' => $applicantStoreId,
            'require_staff_store_match' => true,
            'audit_source' => 'store',
        ], $operator);
    }

    /**
     * 总部发起调店申请：沿用同一申请表与审批链，源门店取员工当前有效任职门店。
     */
    public function createByAdmin(array $data, array $operator): array
    {
        app()->make(OrganizationWorkspaceWriteGate::class)->assertCanWrite();

        $token = trim((string)($data['request_token'] ?? ''));
        if ($token === '' || strlen($token) > 64) {
            throw new AdminException('缺少有效的请求令牌');
        }
        $sourceStaffId = (int)($data['source_staff_id'] ?? 0);
        $toStoreId = (int)($data['to_store_id'] ?? 0);
        $reason = trim((string)($data['reason'] ?? ''));
        if ($sourceStaffId <= 0 || $toStoreId <= 0) {
            throw new AdminException('参数有误');
        }
        if ($reason === '') {
            throw new AdminException('请填写调店原因');
        }

        return $this->createApplyCore([
            'request_token' => $token,
            'source_staff_id' => $sourceStaffId,
            'to_store_id' => $toStoreId,
            'reason' => $reason,
            'applicant_type' => 1,
            'applicant_store_id' => 0,
            'require_staff_store_match' => false,
            'audit_source' => 'admin',
        ], $operator);
    }

    /**
     * @param array{
     *   request_token:string,
     *   source_staff_id:int,
     *   to_store_id:int,
     *   reason:string,
     *   applicant_type:int,
     *   applicant_store_id:int,
     *   require_staff_store_match:bool,
     *   audit_source:string
     * } $ctx
     */
    protected function createApplyCore(array $ctx, array $operator): array
    {
        $token = (string)$ctx['request_token'];
        $sourceStaffId = (int)$ctx['source_staff_id'];
        $toStoreId = (int)$ctx['to_store_id'];
        $reason = (string)$ctx['reason'];
        $applicantType = (int)$ctx['applicant_type'];
        $applicantStoreId = (int)$ctx['applicant_store_id'];
        $requireStaffStoreMatch = !empty($ctx['require_staff_store_match']);
        $auditSource = (string)$ctx['audit_source'];

        $hashPayload = [
            'source_staff_id' => $sourceStaffId,
            'to_store_id' => $toStoreId,
            'reason' => $reason,
            'applicant_type' => $applicantType,
            'applicant_store_id' => $applicantStoreId,
        ];
        $requestHash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE));

        // 同 token / pending 并发可能触发 InnoDB 1213；有限次整事务重试后仍失败才上抛
        $maxAttempts = 4;
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return Db::transaction(function () use (
                    $token,
                    $requestHash,
                    $sourceStaffId,
                    $toStoreId,
                    $reason,
                    $applicantType,
                    $applicantStoreId,
                    $requireStaffStoreMatch,
                    $auditSource,
                    $operator
                ) {
                    $exist = Db::name('store_staff_transfer_apply')->where('request_token', $token)->lock(true)->find();
                    if ($exist) {
                        if ((string)$exist['request_hash'] !== $requestHash) {
                            throw new AdminException('请求令牌与内容冲突，请勿复用令牌');
                        }
                        return $this->presentApply($exist);
                    }

                    $staff = Db::name('system_store_staff')->where('id', $sourceStaffId)->lock(true)->find();
                    if (!$staff || (int)($staff['is_del'] ?? 0) === 1) {
                        throw new AdminException('店员不存在');
                    }
                    $fromStoreId = (int)($staff['store_id'] ?? 0);
                    if ($fromStoreId <= 0) {
                        throw new AdminException('员工无当前任职门店，无法申请调店');
                    }
                    if ($requireStaffStoreMatch && $fromStoreId !== $applicantStoreId) {
                        throw new AdminException('只能为本店员工发起调店申请');
                    }
                    if ((int)($staff['status'] ?? 0) !== 1) {
                        throw new AdminException('员工在本店无有效任职，无法申请调店');
                    }
                    $employeeId = (int)($staff['employee_id'] ?? 0);
                    if ($employeeId <= 0) {
                        throw new AdminException('员工未关联主档，请先完善员工资料');
                    }
                    if ($fromStoreId === $toStoreId) {
                        throw new AdminException('目标门店与当前门店相同');
                    }
                    $toStore = Db::name('system_store')->where('id', $toStoreId)->where('is_del', 0)->find();
                    if (!$toStore || (int)($toStore['is_show'] ?? 0) !== 1) {
                        throw new AdminException('目标门店不存在或未启用');
                    }

                    $now = time();
                    try {
                        $id = (int)Db::name('store_staff_transfer_apply')->insertGetId([
                            'request_token' => $token,
                            'request_hash' => $requestHash,
                            'employee_id' => $employeeId,
                            'source_staff_id' => $sourceStaffId,
                            'from_store_id' => $fromStoreId,
                            'to_store_id' => $toStoreId,
                            'reason' => $reason,
                            'status' => self::STATUS_PENDING,
                            'applicant_id' => (int)($operator['id'] ?? 0),
                            'applicant_type' => $applicantType,
                            'applicant_store_id' => $applicantStoreId,
                            'apply_time' => $now,
                            'reviewer_id' => 0,
                            'reviewer_name' => '',
                            'review_time' => 0,
                            'reject_reason' => '',
                            'execute_staff_id' => 0,
                            'execute_time' => 0,
                            'add_time' => $now,
                            'update_time' => $now,
                        ]);
                    } catch (\Throwable $e) {
                        $msg = $e->getMessage();
                        if ($this->isMysqlDeadlock($e)) {
                            throw $e;
                        }
                        $isDup = stripos($msg, 'Duplicate') !== false || stripos($msg, '1062') !== false;
                        if (!$isDup) {
                            throw $e;
                        }
                        // 1) 同 token：回查区分同内容幂等 vs 令牌冲突
                        $byToken = Db::name('store_staff_transfer_apply')->where('request_token', $token)->lock(true)->find();
                        if ($byToken) {
                            if ((string)$byToken['request_hash'] === $requestHash) {
                                return $this->presentApply($byToken);
                            }
                            throw new AdminException('请求令牌与内容冲突，请勿复用令牌');
                        }
                        // 2) 异 token 抢占 pending_source_staff_id
                        if (stripos($msg, 'uk_pending') !== false || stripos($msg, 'pending_source') !== false) {
                            throw new AdminException('该员工已有待处理的调店申请');
                        }
                        $pending = Db::name('store_staff_transfer_apply')
                            ->where('source_staff_id', $sourceStaffId)
                            ->where('status', self::STATUS_PENDING)
                            ->lock(true)
                            ->find();
                        if ($pending) {
                            throw new AdminException('该员工已有待处理的调店申请');
                        }
                        throw $e;
                    }

                    $this->writeApplyAudit($employeeId, 'staff_transfer_apply', $id, [
                        'source_staff_id' => $sourceStaffId,
                        'from_store_id' => $fromStoreId,
                        'to_store_id' => $toStoreId,
                        'status' => self::STATUS_PENDING,
                        'applicant_type' => $applicantType,
                    ], (string)($operator['name'] ?? ''), (int)($operator['id'] ?? 0), $auditSource, $reason);

                    $row = Db::name('store_staff_transfer_apply')->where('id', $id)->find();
                    return $this->presentApply($row);
                });
            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts || !$this->isMysqlDeadlock($e)) {
                    throw $e;
                }
                usleep(20000 * $attempt);
            }
        }
    }

    protected function isMysqlDeadlock(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return stripos($msg, '1213') !== false
            || stripos($msg, 'Deadlock') !== false
            || stripos($msg, 'Serialization failure') !== false;
    }

    public function cancelByStore(int $applyId, int $storeId, array $operator): array
    {
        app()->make(OrganizationWorkspaceWriteGate::class)->assertCanWrite();
        return Db::transaction(function () use ($applyId, $storeId, $operator) {
            $apply = Db::name('store_staff_transfer_apply')->where('id', $applyId)->lock(true)->find();
            if (!$apply) {
                throw new AdminException('申请不存在');
            }
            if ((int)$apply['applicant_store_id'] !== $storeId) {
                throw new AdminException('只能取消本店发起的申请');
            }
            if ((string)$apply['status'] !== self::STATUS_PENDING) {
                throw new AdminException('仅待处理申请可取消');
            }
            $now = time();
            Db::name('store_staff_transfer_apply')->where('id', $applyId)->update([
                'status' => self::STATUS_CANCELLED,
                'update_time' => $now,
            ]);
            $this->writeApplyAudit((int)$apply['employee_id'], 'staff_transfer_cancel', $applyId, [
                'status' => self::STATUS_CANCELLED,
            ], (string)($operator['name'] ?? ''), (int)($operator['id'] ?? 0), 'store', '门店取消调店申请');
            $apply['status'] = self::STATUS_CANCELLED;
            return $this->presentApply($apply);
        });
    }

    public function rejectByAdmin(int $applyId, string $rejectReason, array $operator): array
    {
        app()->make(OrganizationWorkspaceWriteGate::class)->assertCanWrite();
        $rejectReason = trim($rejectReason);
        if ($rejectReason === '') {
            throw new AdminException('请填写驳回原因');
        }
        return Db::transaction(function () use ($applyId, $rejectReason, $operator) {
            $apply = Db::name('store_staff_transfer_apply')->where('id', $applyId)->lock(true)->find();
            if (!$apply) {
                throw new AdminException('申请不存在');
            }
            if ((string)$apply['status'] !== self::STATUS_PENDING) {
                throw new AdminException('仅待处理申请可驳回');
            }
            $now = time();
            Db::name('store_staff_transfer_apply')->where('id', $applyId)->update([
                'status' => self::STATUS_REJECTED,
                'reject_reason' => $rejectReason,
                'reviewer_id' => (int)($operator['id'] ?? 0),
                'reviewer_name' => (string)($operator['name'] ?? ''),
                'review_time' => $now,
                'update_time' => $now,
            ]);
            $this->writeApplyAudit((int)$apply['employee_id'], 'staff_transfer_reject', $applyId, [
                'status' => self::STATUS_REJECTED,
                'reject_reason' => $rejectReason,
            ], (string)($operator['name'] ?? ''), (int)($operator['id'] ?? 0), 'admin', $rejectReason);
            $apply['status'] = self::STATUS_REJECTED;
            return $this->presentApply($apply);
        });
    }

    public function approveAndExecute(int $applyId, array $targetConfig, array $operator): array
    {
        app()->make(OrganizationWorkspaceWriteGate::class)->assertCanWrite();

        return Db::transaction(function () use ($applyId, $targetConfig, $operator) {
            $apply = Db::name('store_staff_transfer_apply')->where('id', $applyId)->lock(true)->find();
            if (!$apply) {
                throw new AdminException('申请不存在');
            }
            if ((string)$apply['status'] !== self::STATUS_PENDING) {
                if ((string)$apply['status'] === self::STATUS_EXECUTED) {
                    return $this->presentApply($apply);
                }
                throw new AdminException('申请状态不可批准');
            }

            $roles = $targetConfig['roles'] ?? [];
            if (!is_array($roles)) {
                $roles = $roles !== '' ? explode(',', (string)$roles) : [];
            }
            $roles = array_values(array_unique(array_filter(array_map('intval', $roles))));
            // 岗位决定功能权限：审批可不传 roles，由调店内核沿用原任职岗位

            /** @var StoreStaffTransferServices $transfer */
            $transfer = app()->make(StoreStaffTransferServices::class);
            $exec = $transfer->executeTransferKernel([
                'source_staff_id' => (int)$apply['source_staff_id'],
                'to_store_id' => (int)$apply['to_store_id'],
                'expected_from_store_id' => (int)$apply['from_store_id'],
                'expected_employee_id' => (int)$apply['employee_id'],
                'roles' => $roles,
                'position' => (int)($targetConfig['position'] ?? 0),
                'position_level' => (int)($targetConfig['position_level'] ?? 0),
                'is_manager' => (int)($targetConfig['is_manager'] ?? 0),
                'is_cashier' => (int)($targetConfig['is_cashier'] ?? 0),
                'reason' => (string)$apply['reason'],
                'apply_id' => $applyId,
                'operator' => array_merge($operator, ['type' => 1]),
            ]);

            $now = time();
            Db::name('store_staff_transfer_apply')->where('id', $applyId)->update([
                'status' => self::STATUS_EXECUTED,
                'reviewer_id' => (int)($operator['id'] ?? 0),
                'reviewer_name' => (string)($operator['name'] ?? ''),
                'review_time' => $now,
                'execute_staff_id' => (int)$exec['execute_staff_id'],
                'execute_time' => $now,
                'update_time' => $now,
            ]);

            $apply = Db::name('store_staff_transfer_apply')->where('id', $applyId)->find();
            return $this->presentApply($apply);
        });
    }

    public function getList(array $where, int $page = 1, int $limit = 20): array
    {
        $page = max(1, (int)$page);
        $limit = $limit <= 0 ? 20 : min(self::MAX_LIMIT, (int)$limit);
        $query = Db::name('store_staff_transfer_apply');
        if (!empty($where['status'])) {
            $query->where('status', (string)$where['status']);
        }
        if (!empty($where['from_store_id'])) {
            $query->where('from_store_id', (int)$where['from_store_id']);
        }
        if (!empty($where['to_store_id'])) {
            $query->where('to_store_id', (int)$where['to_store_id']);
        }
        if (!empty($where['employee_id'])) {
            $query->where('employee_id', (int)$where['employee_id']);
        }
        if (!empty($where['applicant_store_id'])) {
            $query->where('applicant_store_id', (int)$where['applicant_store_id']);
        }
        $count = (clone $query)->count();
        $list = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return [
            'list' => array_map([$this, 'presentApply'], $list),
            'count' => (int)$count,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    protected function writeApplyAudit(
        int $employeeId,
        string $action,
        int $applyId,
        array $after,
        string $operatorName,
        int $operatorId,
        string $source,
        string $reason
    ): void {
        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId,
            'action' => $action,
            'target_type' => 'transfer_apply',
            'target_id' => $applyId,
            'source' => $source,
            'before_data' => '',
            'after_data' => json_encode($after, JSON_UNESCAPED_UNICODE) ?: '',
            'reason' => $reason,
            'operator_type' => $source === 'store' ? 'store' : 'admin',
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'operator_ip' => '',
            'request_id' => '',
            'add_time' => time(),
        ]);
    }

    protected function presentApply(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'request_token' => (string)($row['request_token'] ?? ''),
            'request_hash' => (string)($row['request_hash'] ?? ''),
            'employee_id' => (int)($row['employee_id'] ?? 0),
            'source_staff_id' => (int)($row['source_staff_id'] ?? 0),
            'from_store_id' => (int)($row['from_store_id'] ?? 0),
            'to_store_id' => (int)($row['to_store_id'] ?? 0),
            'reason' => (string)($row['reason'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'applicant_id' => (int)($row['applicant_id'] ?? 0),
            'applicant_type' => (int)($row['applicant_type'] ?? 0),
            'applicant_store_id' => (int)($row['applicant_store_id'] ?? 0),
            'apply_time' => (int)($row['apply_time'] ?? 0),
            'reviewer_id' => (int)($row['reviewer_id'] ?? 0),
            'reviewer_name' => (string)($row['reviewer_name'] ?? ''),
            'review_time' => (int)($row['review_time'] ?? 0),
            'reject_reason' => (string)($row['reject_reason'] ?? ''),
            'execute_staff_id' => (int)($row['execute_staff_id'] ?? 0),
            'execute_time' => (int)($row['execute_time'] ?? 0),
        ];
    }
}
