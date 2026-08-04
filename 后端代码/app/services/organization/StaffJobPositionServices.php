<?php
namespace app\services\organization;

use app\services\BaseServices;
use app\services\mobile\merchant\MobileAuthRevocationServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * I2 人员多岗位 + 渠道入口 + 系统角色投影
 */
class StaffJobPositionServices extends BaseServices
{
    /**
     * @param int[] $positionIds
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveStaffJobs(
        int $employeeId,
        int $staffId,
        int $storeId,
        array $positionIds,
        array $adminInfo,
        array $requestCtx,
        string $source = 'hq',
        bool $requireSuperAdmin = true
    ): array {
        $positionIds = $this->normalizeIntIds($positionIds);
        $payload = [
            'employee_id' => $employeeId,
            'staff_id' => $staffId,
            'store_id' => $storeId,
            'position_ids' => $positionIds,
            'source' => $source,
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'staff_jobs_save',
            'employee:' . $employeeId . ':staff:' . $staffId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($employeeId, $staffId, $storeId, $positionIds, $source, $adminInfo) {
                $data = $this->bindJobsInTx(
                    $employeeId,
                    $staffId,
                    $storeId,
                    $positionIds,
                    $adminInfo,
                    $auditMeta,
                    $source,
                    true
                );
                $this->writeAudit(
                    $employeeId,
                    'staff_jobs_save',
                    $staffId,
                    ['staff_id' => $staffId, 'store_id' => (int)$data['store_id'], 'position_ids' => $positionIds, 'source' => $source],
                    $adminInfo,
                    $auditMeta,
                    '保存人员岗位'
                );
                return ['msg' => '保存成功', 'data' => $data];
            },
            $requireSuperAdmin
        );
    }

    /**
     * 外层事务内绑岗（不再套幂等）。
     * 允许 staff_id=0 且 store_id=0：仅总部 source=hq；总部可选全部启用岗位（不与门店绑定）。
     * 门店 source=store：仅允许 allow_store_select=1 的岗位新增。
     *
     * @param int[] $positionIds
     * @param bool $runAuthProjection 为 true 时按渠道执行投影；编排可传 false 后自行 projectPlatformAdminRoles
     * @return array{staff_id:int,store_id:int,position_ids:int[],jobs:array}
     */
    public function bindJobsInTx(
        int $employeeId,
        int $staffId,
        int $storeId,
        array $positionIds,
        array $adminInfo,
        array $auditMeta = [],
        string $source = 'hq',
        bool $runAuthProjection = true
    ): array {
        $positionIds = $this->normalizeIntIds($positionIds);
        $this->assertEmployee($employeeId);

        $noStoreBind = ($staffId <= 0 && $storeId <= 0);
        if ($noStoreBind) {
            if ($source !== 'hq') {
                throw new AdminException('无店直属岗位仅总部可配置');
            }
            $staffId = 0;
            $storeId = 0;
        } else {
            if ($staffId <= 0) {
                throw new AdminException('请指定门店任职');
            }
            $staff = Db::name('system_store_staff')->where('id', $staffId)->where('is_del', 0)->lock(true)->find();
            if (!$staff || (int)$staff['employee_id'] !== $employeeId) {
                throw new AdminException('任职与员工不匹配');
            }
            $realStoreId = (int)$staff['store_id'];
            if ($realStoreId <= 0) {
                throw new AdminException('不允许 store_id=0 的伪造任职绑岗，请使用无店直属岗位绑定');
            }
            if ($storeId > 0 && $storeId !== $realStoreId) {
                throw new AdminException('越权门店任职');
            }
            $storeId = $realStoreId;
        }

        $existingQ = Db::name('staff_job_position')
            ->where('employee_id', $employeeId)
            ->where('staff_id', $staffId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('end_time', 0)
            ->lock(true);
        $existing = $existingQ->select()->toArray();
        $existingIds = [];
        foreach ($existing as $ex) {
            $existingIds[(int)$ex['position_id']] = (int)$ex['id'];
        }

        /** @var JobPositionPolicyServices $policy */
        $policy = app()->make(JobPositionPolicyServices::class);
        if ($source === 'store') {
            $selectable = $policy->listStoreSelectablePositions($storeId);
            $selectableIds = array_column($selectable, 'value');
            foreach ($positionIds as $pid) {
                if (isset($existingIds[$pid])) {
                    continue;
                }
                if (!in_array($pid, $selectableIds, true)) {
                    throw new AdminException('只能选择已启用且门店可用的岗位；关闭门店可用后不能新增');
                }
            }
        } elseif ($positionIds) {
            // 总部（含无店直属）：全部启用岗位可选，不因是否任职门店裁剪
            $found = Db::name('position')->whereIn('id', $positionIds)->where('status', 1)->column('id');
            $found = array_map('intval', $found);
            sort($found);
            $want = $positionIds;
            sort($want);
            if ($found !== $want) {
                throw new AdminException('岗位无效或已停用');
            }
        }

        $now = time();
        $token = (string)($auditMeta['request_token'] ?? '');
        $opId = (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0);
        $opName = (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? '');

        $keep = array_flip($positionIds);
        foreach ($existing as $ex) {
            $pid = (int)$ex['position_id'];
            if (!isset($keep[$pid])) {
                Db::name('staff_job_position')->where('id', (int)$ex['id'])->update([
                    'status' => 0,
                    'end_time' => $now,
                    'reason' => '岗位调整结束',
                    'update_time' => $now,
                    'operator_id' => $opId,
                    'operator_name' => $opName,
                ]);
            }
        }
        foreach ($positionIds as $pid) {
            if (isset($existingIds[$pid])) {
                continue;
            }
            $rowToken = $token !== ''
                ? ($token . '#pos#' . $pid)
                : ('sjp-' . $employeeId . '-' . $staffId . '-' . $pid . '-' . $now);
            Db::name('staff_job_position')->insert([
                'employee_id' => $employeeId,
                'store_id' => $storeId,
                'staff_id' => $staffId,
                'position_id' => $pid,
                'status' => 1,
                'start_time' => $now,
                'end_time' => 0,
                'reason' => '岗位调整',
                'is_del' => 0,
                'request_token' => $rowToken,
                'operator_id' => $opId,
                'operator_name' => $opName,
                'add_time' => $now,
                'update_time' => $now,
            ]);
        }

        if ($staffId > 0) {
            $this->projectStaffRoles($staffId);
            $this->syncManagerFlagFromJobs($staffId);
        }
        if ($runAuthProjection) {
            if ($staffId <= 0) {
                $this->projectPlatformAdminRoles($employeeId);
                $this->bumpEmployeeAuthVersion($employeeId);
                $this->invalidateMerchantSessions($employeeId);
            } elseif ($source === 'store') {
                $this->bumpEmployeeAuthVersion($employeeId);
                $this->projectMobileAuthRules($employeeId);
                $this->invalidateMerchantSessions($employeeId);
            } else {
                $this->afterEmployeeAuthChanged($employeeId);
            }
        }

        $jobs = $staffId > 0
            ? $this->listActiveJobs($staffId)
            : $this->listActiveJobs(0, $employeeId);

        return [
            'staff_id' => $staffId,
            'store_id' => $storeId,
            'position_ids' => $positionIds,
            'jobs' => $jobs,
        ];
    }

    /**
     * @return array<int, array>
     */
    public function listActiveJobs(int $staffId, int $employeeId = 0): array
    {
        $q = Db::name('staff_job_position')->alias('j')
            ->leftJoin('position p', 'p.id = j.position_id')
            ->where('j.is_del', 0)
            ->where('j.status', 1)
            ->where('j.end_time', 0)
            ->field('j.id,j.employee_id,j.store_id,j.staff_id,j.position_id,j.start_time,j.end_time,j.status,p.name AS position_name,p.allow_store_select,p.use_platform,p.use_store,p.use_cashier,p.use_mobile,p.status AS position_status')
            ->order('j.id', 'asc');
        if ($staffId > 0) {
            $q->where('j.staff_id', $staffId);
        } elseif ($employeeId > 0) {
            $q->where('j.employee_id', $employeeId)->where('j.staff_id', 0);
        } else {
            return [];
        }
        $rows = $q->select()->toArray();
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['position_id'] = (int)$r['position_id'];
            $r['allow_store_select'] = (int)($r['allow_store_select'] ?? 0);
            $r['use_platform'] = (int)($r['use_platform'] ?? 0);
            $r['use_store'] = (int)($r['use_store'] ?? 0);
            $r['use_cashier'] = (int)($r['use_cashier'] ?? 0);
            $r['use_mobile'] = (int)($r['use_mobile'] ?? 0);
            $r['position_status'] = (int)($r['position_status'] ?? 0);
            $r['keep_only'] = (int)($r['allow_store_select'] ?? 0) !== 1 || (int)($r['position_status'] ?? 0) !== 1;
        }
        unset($r);
        return $rows;
    }

    /**
     * 当前有效岗位在指定渠道的功能规则并集
     * @return int[]
     */
    public function computeChannelRulesUnion(int $staffId, string $channel): array
    {
        if ($staffId <= 0 || !in_array($channel, [
            JobPositionPolicyServices::CHANNEL_PLATFORM,
            JobPositionPolicyServices::CHANNEL_STORE_V3,
            JobPositionPolicyServices::CHANNEL_STORE_BACKEND,
            JobPositionPolicyServices::CHANNEL_CASHIER,
            JobPositionPolicyServices::CHANNEL_MOBILE,
        ], true)) {
            return [];
        }
        /** @var JobPositionPolicyServices $policy */
        $policy = app()->make(JobPositionPolicyServices::class);
        $flagCol = $policy->useFlagColumn($channel);
        $jobs = Db::name('staff_job_position')->alias('j')
            ->join('position p', 'p.id = j.position_id')
            ->where('j.staff_id', $staffId)
            ->where('j.is_del', 0)
            ->where('j.status', 1)
            ->where('j.end_time', 0)
            ->where('p.status', 1)
            ->where('p.' . $flagCol, 1)
            ->field('j.position_id')
            ->select()->toArray();
        if (!$jobs) {
            return [];
        }
        $positionIds = array_values(array_unique(array_map(static function ($r) {
            return (int)$r['position_id'];
        }, $jobs)));
        $ruleRows = Db::name('job_position_channel_rule')
            ->whereIn('position_id', $positionIds)
            ->where('channel', $channel)
            ->where('status', 1)
            ->column('rules');
        $union = [];
        foreach ($ruleRows as $rules) {
            $rules = trim((string)$rules);
            if ($rules === '') {
                continue;
            }
            foreach (explode(',', $rules) as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $union[$id] = true;
                }
            }
        }
        $ids = array_map('intval', array_keys($union));
        sort($ids);
        return $ids;
    }

    /**
     * 开通入口前校验：当前有效岗位须覆盖该端且规则非空
     */
    public function assertChannelCoveredByJobs(int $staffId, string $channel, int $employeeId = 0): void
    {
        /** @var JobPositionPolicyServices $policy */
        $policy = app()->make(JobPositionPolicyServices::class);
        $label = $policy->channelLabel($channel);

        // 手机端由“当前任职 + 员工总开关”共同决定。即使未来存在多任职，
        // 也不能用另一门店的岗位功能替当前门店开启手机入口。
        $employeeLevel = $channel === JobPositionPolicyServices::CHANNEL_PLATFORM
            && ($staffId <= 0 || $channel === JobPositionPolicyServices::CHANNEL_PLATFORM);

        if ($employeeLevel) {
            if ($employeeId <= 0 && $staffId > 0) {
                $employeeId = (int)Db::name('system_store_staff')->where('id', $staffId)->value('employee_id');
            }
            if ($employeeId <= 0) {
                throw new AdminException('当前有效岗位未包含' . $label . '功能，请先配置岗位后再开通入口');
            }
            $staffIds = Db::name('system_store_staff')
                ->where('employee_id', $employeeId)
                ->where('is_del', 0)
                ->column('id');
            $union = [];
            foreach ($staffIds as $sid) {
                $union = array_merge($union, $this->computeChannelRulesUnion((int)$sid, $channel));
            }
            $union = array_values(array_unique(array_map('intval', $union)));
            if (!$union) {
                throw new AdminException('当前有效岗位未包含' . $label . '功能，请先配置岗位后再开通入口');
            }
            return;
        }

        $union = $this->computeChannelRulesUnion($staffId, $channel);
        if (!$union) {
            throw new AdminException('当前有效岗位未包含' . $label . '功能，请先为本店任职配置对应岗位后再开通入口');
        }
    }

    /**
     * 已绑定的总部发布角色在指定渠道的规则并集（非岗位投影）
     * @return int[]
     */
    public function computePublishedRoleChannelRules(int $staffId, string $channel): array
    {
        if ($staffId <= 0) {
            return [];
        }
        $staff = Db::name('system_store_staff')->where('id', $staffId)->where('is_del', 0)->find();
        if (!$staff) {
            return [];
        }
        $storeId = (int)$staff['store_id'];
        $roleIds = array_values(array_filter(array_map('intval', explode(',', (string)($staff['roles'] ?? '')))));
        if (!$roleIds) {
            return [];
        }
        $projectedNames = [
            '岗位投影-' . $staffId . '-store',
            '岗位投影-' . $staffId . '-cash',
        ];
        $rows = Db::name('system_role')
            ->whereIn('id', $roleIds)
            ->where('type', 1)
            ->where('relation_id', $storeId)
            ->where('status', 1)
            ->whereNotIn('role_name', $projectedNames)
            ->field('id,rules,cashier_rules,mall_rules')
            ->select()->toArray();
        $union = [];
        foreach ($rows as $row) {
            $raw = '';
            if ($channel === JobPositionPolicyServices::CHANNEL_STORE_BACKEND) {
                $raw = (string)($row['rules'] ?? '');
            } elseif ($channel === JobPositionPolicyServices::CHANNEL_CASHIER) {
                $raw = (string)($row['cashier_rules'] ?? '');
            } elseif ($channel === JobPositionPolicyServices::CHANNEL_MOBILE) {
                $raw = (string)($row['mall_rules'] ?? '');
            }
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            foreach (explode(',', $raw) as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $union[$id] = true;
                }
            }
        }
        $ids = array_map('intval', array_keys($union));
        sort($ids);
        return $ids;
    }

    /**
     * 投影门店后台/收银角色到 staff.roles / is_store / is_cashier
     */
    public function projectStaffRoles(int $staffId): void
    {
        $staff = Db::name('system_store_staff')->where('id', $staffId)->where('is_del', 0)->lock(true)->find();
        if (!$staff) {
            return;
        }
        $storeId = (int)$staff['store_id'];
        $now = time();

        $storeRules = $this->computeChannelRulesUnion($staffId, JobPositionPolicyServices::CHANNEL_STORE_BACKEND);
        $cashierRules = $this->computeChannelRulesUnion($staffId, JobPositionPolicyServices::CHANNEL_CASHIER);

        $projectedIds = [];
        if ($storeRules) {
            $projectedIds[] = $this->upsertProjectedRole(
                $staffId,
                $storeId,
                JobPositionPolicyServices::CHANNEL_STORE_BACKEND,
                $storeRules,
                [],
                $now
            );
        } else {
            $this->disableProjectedRole($staffId, $storeId, JobPositionPolicyServices::CHANNEL_STORE_BACKEND);
        }
        if ($cashierRules) {
            $projectedIds[] = $this->upsertProjectedRole(
                $staffId,
                $storeId,
                JobPositionPolicyServices::CHANNEL_CASHIER,
                [],
                $cashierRules,
                $now
            );
        } else {
            $this->disableProjectedRole($staffId, $storeId, JobPositionPolicyServices::CHANNEL_CASHIER);
        }

        // 人员只选岗位：staff.roles 仅保留岗位投影角色，不再合并手动选择的角色模板
        $merged = array_values(array_filter(array_map('intval', $projectedIds)));
        sort($merged);

        $entries = Db::name('staff_channel_entry')
            ->where('staff_id', $staffId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->column('channel');
        $entrySet = array_flip(array_map('strval', $entries ?: []));

        $isStore = isset($entrySet[JobPositionPolicyServices::CHANNEL_STORE_BACKEND]) && $storeRules ? 1 : 0;
        $isCashier = isset($entrySet[JobPositionPolicyServices::CHANNEL_CASHIER]) && $cashierRules ? 1 : 0;
        // 无入口表时：以岗位规则为准（入口开关关闭时规则已空）
        if (!$entries) {
            $isStore = $storeRules ? 1 : 0;
            $isCashier = $cashierRules ? 1 : 0;
        }

        Db::name('system_store_staff')->where('id', $staffId)->update([
            'roles' => implode(',', $merged),
            'is_store' => $isStore,
            'is_cashier' => $isCashier,
        ]);
    }

    /**
     * 店长身份由岗位属性决定：任一有效岗位 is_store_manager=1 → staff.is_manager=1
     * 历史数据在未重新绑岗前不主动清理；本方法仅在岗位保存后调用。
     */
    public function syncManagerFlagFromJobs(int $staffId): void
    {
        if ($staffId <= 0) {
            return;
        }
        $staff = Db::name('system_store_staff')->where('id', $staffId)->where('is_del', 0)->find();
        if (!$staff) {
            return;
        }
        $positionIds = Db::name('staff_job_position')
            ->where('staff_id', $staffId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('end_time', 0)
            ->column('position_id');
        $positionIds = array_values(array_unique(array_filter(array_map('intval', $positionIds ?: []))));
        $isManager = 0;
        if ($positionIds) {
            $isManager = (int)Db::name('position')
                ->whereIn('id', $positionIds)
                ->where('status', 1)
                ->where('is_store_manager', 1)
                ->count() > 0 ? 1 : 0;
        }
        Db::name('system_store_staff')->where('id', $staffId)->update([
            'is_manager' => $isManager,
            // 显式降级时清历史管家标记，避免 staffIsManager 仍因 is_butler 判真
            'is_butler' => 0,
        ]);
    }

    /**
     * 按员工全部有效岗位的 platform channel rules 并集，投影到 system_admin.roles（type=0）
     * @return array{employee_id:int,role_id:int,rules:string,rule_ids:int[],admin_ids:int[],auth_version:int}
     */
    public function projectPlatformAdminRoles(int $employeeId): array
    {
        if ($employeeId <= 0) {
            throw new AdminException('员工无效');
        }
        $this->assertEmployee($employeeId);
        $now = time();
        $ruleIds = $this->computeEmployeeChannelRulesUnion($employeeId, JobPositionPolicyServices::CHANNEL_PLATFORM);
        $roleId = 0;
        if ($ruleIds) {
            $roleId = $this->upsertPlatformProjectedRole($employeeId, $ruleIds, $now);
        } else {
            $this->disablePlatformProjectedRole($employeeId);
        }

        $projectedIds = $this->listPlatformProjectedRoleIds($employeeId);
        $admins = Db::name('system_admin')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->lock(true)
            ->select()->toArray();
        $adminIds = [];
        foreach ($admins as $admin) {
            $adminId = (int)$admin['id'];
            $oldRoles = array_values(array_filter(array_map('intval', explode(',', (string)($admin['roles'] ?? '')))));
            $kept = array_values(array_filter($oldRoles, static function ($rid) use ($projectedIds) {
                return !in_array((int)$rid, $projectedIds, true);
            }));
            $merged = $roleId > 0
                ? array_values(array_unique(array_merge($kept, [$roleId])))
                : $kept;
            sort($merged);
            Db::name('system_admin')->where('id', $adminId)->update([
                'roles' => implode(',', $merged),
            ]);
            $adminIds[] = $adminId;
        }

        return [
            'employee_id' => $employeeId,
            'role_id' => $roleId,
            'rules' => implode(',', $ruleIds),
            'rule_ids' => $ruleIds,
            'admin_ids' => $adminIds,
            'auth_version' => (int)(Db::name('employee')->where('id', $employeeId)->value('auth_version') ?: 1),
        ];
    }

    /**
     * dry-run：检测平台投影与实际 roles/规则是否一致（不写库）
     * @return array{consistent:bool,employee_id:int,expected_rule_ids:int[],actual_rule_ids:int[],expected_role_id:int,diffs:string[]}
     */
    public function dryRunPlatformProjection(int $employeeId): array
    {
        $expected = $this->computeEmployeeChannelRulesUnion($employeeId, JobPositionPolicyServices::CHANNEL_PLATFORM);
        $roleName = $this->platformProjectedRoleName($employeeId);
        $exist = Db::name('system_role')
            ->where('type', 0)
            ->where('relation_id', 0)
            ->where('role_name', $roleName)
            ->find();
        $actual = [];
        $roleId = 0;
        $roleStatus = 0;
        if ($exist) {
            $roleId = (int)$exist['id'];
            $roleStatus = (int)($exist['status'] ?? 0);
            $actual = $this->normalizeIntIds($exist['rules'] ?? '');
        }
        $diffs = [];
        if ($expected !== $actual) {
            $diffs[] = 'rules_mismatch';
        }
        if ($expected && (!$exist || $roleStatus !== 1)) {
            $diffs[] = 'projected_role_missing_or_disabled';
        }
        if (!$expected && $exist && $roleStatus === 1) {
            $diffs[] = 'projected_role_should_disable';
        }

        $projectedIds = $this->listPlatformProjectedRoleIds($employeeId);
        $admins = Db::name('system_admin')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->field('id,roles')
            ->select()->toArray();
        foreach ($admins as $admin) {
            $roles = array_values(array_filter(array_map('intval', explode(',', (string)($admin['roles'] ?? '')))));
            $hasProj = $roleId > 0 && in_array($roleId, $roles, true);
            $stale = array_values(array_filter($roles, static function ($rid) use ($projectedIds, $roleId) {
                return in_array((int)$rid, $projectedIds, true) && (int)$rid !== (int)$roleId;
            }));
            if ($expected && !$hasProj) {
                $diffs[] = 'admin_' . (int)$admin['id'] . '_missing_projected_role';
            }
            if ($stale) {
                $diffs[] = 'admin_' . (int)$admin['id'] . '_stale_projected_roles';
            }
            if (!$expected) {
                foreach ($roles as $rid) {
                    if (in_array((int)$rid, $projectedIds, true)) {
                        $diffs[] = 'admin_' . (int)$admin['id'] . '_has_orphan_projected_role';
                        break;
                    }
                }
            }
        }

        return [
            'consistent' => $diffs === [],
            'employee_id' => $employeeId,
            'expected_rule_ids' => $expected,
            'actual_rule_ids' => $actual,
            'expected_role_id' => $roleId,
            'diffs' => array_values(array_unique($diffs)),
        ];
    }

    /**
     * sync：写入平台投影（与 projectPlatformAdminRoles 同义，供运维/对账调用）
     * @return array{employee_id:int,role_id:int,rules:string,rule_ids:int[],admin_ids:int[],auth_version:int}
     */
    public function syncPlatformProjection(int $employeeId): array
    {
        return $this->projectPlatformAdminRoles($employeeId);
    }

    /**
     * reconcile：扫描不一致并可选同步
     * @return array{checked:int,inconsistent:int,items:array,synced:int}
     */
    public function reconcilePlatformProjection(?int $employeeId = null, bool $autoSync = false): array
    {
        $ids = [];
        if ($employeeId !== null && $employeeId > 0) {
            $ids = [$employeeId];
        } else {
            $fromJobs = Db::name('staff_job_position')->alias('j')
                ->join('position p', 'p.id = j.position_id')
                ->where('j.is_del', 0)
                ->where('j.status', 1)
                ->where('j.end_time', 0)
                ->where('p.status', 1)
                ->where('p.use_platform', 1)
                ->column('j.employee_id');
            $fromAdmin = Db::name('system_admin')->where('is_del', 0)->where('employee_id', '>', 0)->column('employee_id');
            $ids = array_values(array_unique(array_filter(array_map('intval', array_merge($fromJobs ?: [], $fromAdmin ?: [])))));
            sort($ids);
        }

        $items = [];
        $synced = 0;
        foreach ($ids as $eid) {
            $dry = $this->dryRunPlatformProjection((int)$eid);
            if (!$dry['consistent']) {
                $row = $dry;
                if ($autoSync) {
                    $row['sync'] = $this->syncPlatformProjection((int)$eid);
                    $row['after'] = $this->dryRunPlatformProjection((int)$eid);
                    $synced++;
                }
                $items[] = $row;
            }
        }
        return [
            'checked' => count($ids),
            'inconsistent' => count($items),
            'items' => $items,
            'synced' => $synced,
        ];
    }

    /**
     * 手机端 rules 由岗位 mobile channel 并集投影写入 employee_mobile_auth
     * @return array{employee_id:int,rules:string,rule_ids:int[],id:int,status:int}
     */
    public function projectMobileAuthRules(int $employeeId, ?int $status = null): array
    {
        if ($employeeId <= 0) {
            throw new AdminException('员工无效');
        }
        $this->assertEmployee($employeeId);
        $ruleIds = $this->computeEmployeeChannelRulesUnion($employeeId, JobPositionPolicyServices::CHANNEL_MOBILE);
        $rules = implode(',', $ruleIds);
        $now = time();
        $row = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)->lock(true)->find();
        $newStatus = $status;
        if ($newStatus === null) {
            $newStatus = $row ? (int)($row['status'] ?? 0) : 0;
            // 无规则时强制关闭
            if (!$ruleIds) {
                $newStatus = 0;
            }
        } else {
            $newStatus = (int)$newStatus === 1 ? 1 : 0;
            if ($newStatus === 1 && !$ruleIds) {
                throw new AdminException('当前有效岗位未包含手机端功能，无法开通手机授权');
            }
        }

        if ($row) {
            $ver = (int)$row['version'] + 1;
            Db::name('employee_mobile_auth')->where('id', (int)$row['id'])->update([
                'rules' => $rules,
                'role_name' => (string)($row['role_name'] ?? '') !== '' ? (string)$row['role_name'] : '岗位投影-手机',
                'status' => $newStatus,
                'version' => $ver,
                'is_del' => 0,
                'update_time' => $now,
            ]);
            $id = (int)$row['id'];
        } else {
            // 无历史行且无规则：不强制建行
            if (!$ruleIds && $newStatus !== 1) {
                return [
                    'employee_id' => $employeeId,
                    'rules' => '',
                    'rule_ids' => [],
                    'id' => 0,
                    'status' => 0,
                ];
            }
            $id = (int)Db::name('employee_mobile_auth')->insertGetId([
                'employee_id' => $employeeId,
                'status' => $newStatus,
                'scope_mode' => 'all',
                'org_ids' => '[]',
                'store_ids' => '[]',
                'role_name' => '岗位投影-手机',
                'rules' => $rules,
                'version' => 1,
                'request_token' => 'mob-proj-' . $employeeId . '-' . $now,
                'is_del' => 0,
                'add_time' => $now,
                'update_time' => $now,
            ]);
        }
        return [
            'employee_id' => $employeeId,
            'rules' => $rules,
            'rule_ids' => $ruleIds,
            'id' => $id,
            'status' => $newStatus,
        ];
    }

    /**
     * 权限变更后：bump auth_version + 平台/手机投影 + 失效商家会话
     */
    public function afterEmployeeAuthChanged(int $employeeId): array
    {
        $ver = $this->bumpEmployeeAuthVersion($employeeId);
        $platform = $this->projectPlatformAdminRoles($employeeId);
        $mobile = $this->projectMobileAuthRules($employeeId);
        $this->invalidateMerchantSessions($employeeId);
        return [
            'auth_version' => $ver,
            'platform' => $platform,
            'mobile' => $mobile,
        ];
    }

    public function bumpEmployeeAuthVersion(int $employeeId): int
    {
        if ($employeeId <= 0) {
            return 0;
        }
        $now = time();
        $row = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->lock(true)->find();
        if (!$row) {
            throw new AdminException('员工不存在');
        }
        $ver = max(1, (int)($row['auth_version'] ?? 1)) + 1;
        Db::name('employee')->where('id', $employeeId)->update([
            'auth_version' => $ver,
            'update_time' => $now,
        ]);
        return $ver;
    }

    /**
     * 将某员工全部商家短期会话置为失效
     */
    public function invalidateMerchantSessions(int $employeeId): int
    {
        if ($employeeId <= 0) {
            return 0;
        }
        $legacyInvalidated = (int)Db::name('employee_merchant_session')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->update([
                'status' => 0,
                'update_time' => time(),
            ]);
        /** @var MobileAuthRevocationServices $mobileSessions */
        $mobileSessions = app()->make(MobileAuthRevocationServices::class);
        $mobileSessions->revokeAllInCurrentTransaction($employeeId, 'AUTH_CHANGED');
        return $legacyInvalidated;
    }

    /**
     * 岗位策略变更后：对该岗位关联的全部员工重算投影
     * @return array{employee_ids:int[],count:int}
     */
    public function reprojectEmployeesByPosition(int $positionId): array
    {
        if ($positionId <= 0) {
            return ['employee_ids' => [], 'count' => 0];
        }
        $employeeIds = Db::name('staff_job_position')
            ->where('position_id', $positionId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('end_time', 0)
            ->column('employee_id');
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds ?: []))));
        sort($employeeIds);
        foreach ($employeeIds as $eid) {
            $staffIds = Db::name('system_store_staff')
                ->where('employee_id', $eid)
                ->where('is_del', 0)
                ->column('id');
            foreach ($staffIds as $sid) {
                $this->projectStaffRoles((int)$sid);
            }
            $this->afterEmployeeAuthChanged((int)$eid);
        }
        return ['employee_ids' => $employeeIds, 'count' => count($employeeIds)];
    }

    /**
     * 员工级渠道功能规则并集（跨全部有效任职，含 staff_id=0 无店直属岗）
     * @return int[]
     */
    public function computeEmployeeChannelRulesUnion(int $employeeId, string $channel): array
    {
        if ($employeeId <= 0 || !in_array($channel, [
            JobPositionPolicyServices::CHANNEL_PLATFORM,
            JobPositionPolicyServices::CHANNEL_STORE_V3,
            JobPositionPolicyServices::CHANNEL_STORE_BACKEND,
            JobPositionPolicyServices::CHANNEL_CASHIER,
            JobPositionPolicyServices::CHANNEL_MOBILE,
        ], true)) {
            return [];
        }
        /** @var JobPositionPolicyServices $policy */
        $policy = app()->make(JobPositionPolicyServices::class);
        $flagCol = $policy->useFlagColumn($channel);
        $jobs = Db::name('staff_job_position')->alias('j')
            ->join('position p', 'p.id = j.position_id')
            ->where('j.employee_id', $employeeId)
            ->where('j.is_del', 0)
            ->where('j.status', 1)
            ->where('j.end_time', 0)
            ->where('p.status', 1)
            ->where('p.' . $flagCol, 1)
            ->field('j.position_id')
            ->select()->toArray();
        if (!$jobs) {
            return [];
        }
        $positionIds = array_values(array_unique(array_map(static function ($r) {
            return (int)$r['position_id'];
        }, $jobs)));
        $ruleRows = Db::name('job_position_channel_rule')
            ->whereIn('position_id', $positionIds)
            ->where('channel', $channel)
            ->where('status', 1)
            ->column('rules');
        $union = [];
        foreach ($ruleRows as $rules) {
            $rules = trim((string)$rules);
            if ($rules === '') {
                continue;
            }
            foreach (explode(',', $rules) as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $union[$id] = true;
                }
            }
        }
        $ids = array_map('intval', array_keys($union));
        sort($ids);
        return $ids;
    }

    /**
     * 保存本店渠道入口（store_v3|mobile）；历史渠道不再参与新授权。
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveChannelEntries(
        int $employeeId,
        int $staffId,
        array $entries,
        array $adminInfo,
        array $requestCtx,
        string $source = 'hq',
        bool $requireSuperAdmin = true
    ): array {
        $normalized = $this->normalizeChannelEntries($entries, $source);
        $payload = [
            'employee_id' => $employeeId,
            'staff_id' => $staffId,
            'entries' => $normalized,
            'source' => $source,
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'staff_channel_entries_save',
            'employee:' . $employeeId . ':staff:' . $staffId . ':entries',
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($employeeId, $staffId, $normalized, $adminInfo, $source) {
                $data = $this->bindChannelEntriesInTx(
                    $employeeId,
                    $staffId,
                    $normalized,
                    $adminInfo,
                    $auditMeta,
                    $source,
                    true
                );
                $this->writeAudit(
                    $employeeId,
                    'staff_channel_entries_save',
                    $staffId,
                    ['staff_id' => $staffId, 'entries' => $normalized],
                    $adminInfo,
                    $auditMeta,
                    '保存渠道入口'
                );
                return ['msg' => '保存成功', 'data' => $data];
            },
            $requireSuperAdmin
        );
    }

    /**
     * @return array<string,int>
     */
    public function normalizeChannelEntries(array $entries, string $source = 'hq'): array
    {
        $normalized = [];
        foreach ($entries as $e) {
            if (!is_array($e)) {
                continue;
            }
            $ch = trim((string)($e['channel'] ?? ''));
            $st = (int)($e['status'] ?? 0) === 1 ? 1 : 0;
            if (!in_array($ch, [
                JobPositionPolicyServices::CHANNEL_STORE_V3,
                JobPositionPolicyServices::CHANNEL_MOBILE,
            ], true)) {
                throw new AdminException('不支持的渠道入口');
            }
            if ($source === 'store' && $ch === JobPositionPolicyServices::CHANNEL_PLATFORM) {
                throw new AdminException('门店不能操作平台后台入口');
            }
            $normalized[$ch] = $st;
        }
        ksort($normalized);
        return $normalized;
    }

    /**
     * 外层事务内写渠道入口（不再套幂等）
     * @param array<string,int> $normalized
     * @return array{staff_id:int,entries:array}
     */
    public function bindChannelEntriesInTx(
        int $employeeId,
        int $staffId,
        array $normalized,
        array $adminInfo,
        array $auditMeta = [],
        string $source = 'hq',
        bool $runAuthProjection = true
    ): array {
        $this->assertEmployee($employeeId);
        if ($staffId <= 0) {
            throw new AdminException('请指定门店任职');
        }
        $staff = Db::name('system_store_staff')->where('id', $staffId)->where('is_del', 0)->lock(true)->find();
        if (!$staff || (int)$staff['employee_id'] !== $employeeId) {
            throw new AdminException('任职与员工不匹配');
        }
        $storeId = (int)$staff['store_id'];
        if ($storeId <= 0) {
            throw new AdminException('无店任职不能配置渠道入口');
        }
        $now = time();
        $token = (string)($auditMeta['request_token'] ?? '');
        $opId = (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0);
        $opName = (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? '');

        foreach ($normalized as $channel => $status) {
            if ($status === 1) {
                $this->assertChannelCoveredByJobs($staffId, $channel, $employeeId);
            }
            $exist = Db::name('staff_channel_entry')
                ->where('staff_id', $staffId)
                ->where('channel', $channel)
                ->lock(true)
                ->find();
            if ($exist) {
                Db::name('staff_channel_entry')->where('id', (int)$exist['id'])->update([
                    'status' => $status,
                    'is_del' => 0,
                    'employee_id' => $employeeId,
                    'store_id' => $storeId,
                    'request_token' => $token !== '' ? ($token . '#ch#' . $channel) : (string)($exist['request_token'] ?? ''),
                    'operator_id' => $opId,
                    'operator_name' => $opName,
                    'update_time' => $now,
                ]);
            } else {
                Db::name('staff_channel_entry')->insert([
                    'employee_id' => $employeeId,
                    'store_id' => $storeId,
                    'staff_id' => $staffId,
                    'channel' => $channel,
                    'status' => $status,
                    'request_token' => $token !== '' ? ($token . '#ch#' . $channel) : ('entry-' . $staffId . '-' . $channel . '-' . $now),
                    'is_del' => 0,
                    'operator_id' => $opId,
                    'operator_name' => $opName,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
            }
        }
        if ($runAuthProjection) {
            $this->projectStaffRoles($staffId);
            $this->afterEmployeeAuthChanged($employeeId);
        } else {
            $this->projectStaffRoles($staffId);
        }
        return [
            'staff_id' => $staffId,
            'entries' => $this->listEntries($staffId),
        ];
    }

    /**
     * 员工档案中的唯一“手机端”开关。
     *
     * 任职入口和员工手机授权是同一业务结果的两份权威投影，必须在同一事务内
     * 一起更新，避免页面显示已开通而登录会话被另一层拒绝。
     *
     * @return array{staff_id:int,enabled:int,entries:array,mobile:array,auth_version:int}
     */
    public function setEmployeeMobileAccessInTx(
        int $employeeId,
        int $staffId,
        int $enabled,
        array $adminInfo,
        array $auditMeta = [],
        string $source = 'hq'
    ): array {
        $enabled = $enabled === 1 ? 1 : 0;
        $this->assertEmployee($employeeId);
        $staff = Db::name('system_store_staff')
            ->where('id', $staffId)->where('employee_id', $employeeId)->where('is_del', 0)
            ->lock(true)->find();
        if (!$staff || (int)($staff['status'] ?? 0) !== 1 || (int)($staff['store_id'] ?? 0) <= 0) {
            throw new AdminException('手机端授权需要有效的门店任职');
        }

        $entry = $this->bindChannelEntriesInTx(
            $employeeId,
            $staffId,
            [JobPositionPolicyServices::CHANNEL_MOBILE => $enabled],
            $adminInfo,
            $auditMeta,
            $source,
            false
        );
        // 开启时 project 会再次校验岗位能力；关闭时保留 status=0 记录，
        // 防止之后岗位再次具备手机端能力时把员工手工关闭的授权自动打开。
        $mobile = $this->projectMobileAuthRules($employeeId, $enabled);
        $after = $this->afterEmployeeAuthChanged($employeeId);

        return [
            'staff_id' => $staffId,
            'enabled' => $enabled,
            'entries' => $entry['entries'],
            'mobile' => $mobile,
            'auth_version' => (int)($after['auth_version'] ?? 0),
        ];
    }

    /**
     * @return array<int, array>
     */
    public function listEntries(int $staffId): array
    {
        if ($staffId <= 0) {
            return [];
        }
        return Db::name('staff_channel_entry')
            ->where('staff_id', $staffId)
            ->where('is_del', 0)
            ->order('id', 'asc')
            ->select()->toArray();
    }

    /**
     * 功能权限只读预览（多岗位并集）
     */
    public function buildFunctionPreview(int $staffId): array
    {
        $channels = [
            JobPositionPolicyServices::CHANNEL_STORE_V3,
            JobPositionPolicyServices::CHANNEL_MOBILE,
        ];
        $out = [
            'jobs' => $this->listActiveJobs($staffId),
            'channels' => [],
        ];
        foreach ($channels as $ch) {
            $ids = $this->computeChannelRulesUnion($staffId, $ch);
            $out['channels'][$ch] = [
                'rule_ids' => $ids,
                'rules' => implode(',', $ids),
                'covered' => !empty($ids),
            ];
        }
        return $out;
    }

    /**
     * 员工级平台功能预览（跨任职并集）
     */
    public function buildEmployeePlatformPreview(int $employeeId): array
    {
        $staffIds = Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->column('id');
        $union = [];
        foreach ($staffIds as $sid) {
            foreach ($this->computeChannelRulesUnion((int)$sid, JobPositionPolicyServices::CHANNEL_PLATFORM) as $id) {
                $union[$id] = true;
            }
        }
        $ids = array_map('intval', array_keys($union));
        sort($ids);
        return [
            'rule_ids' => $ids,
            'rules' => implode(',', $ids),
            'covered' => !empty($ids),
        ];
    }

    protected function upsertProjectedRole(
        int $staffId,
        int $storeId,
        string $channel,
        array $rules,
        array $cashierRules,
        int $now
    ): int {
        $roleName = $this->projectedRoleName($staffId, $channel);
        $exist = Db::name('system_role')
            ->where('type', 1)
            ->where('relation_id', $storeId)
            ->where('role_name', $roleName)
            ->lock(true)
            ->find();
        $rulesStr = implode(',', $rules);
        $cashierStr = implode(',', $cashierRules);
        if ($exist) {
            Db::name('system_role')->where('id', (int)$exist['id'])->update([
                'rules' => $channel === JobPositionPolicyServices::CHANNEL_STORE_BACKEND ? $rulesStr : '',
                'cashier_rules' => $channel === JobPositionPolicyServices::CHANNEL_CASHIER ? $cashierStr : '',
                'mall_rules' => '',
                'status' => 1,
            ]);
            return (int)$exist['id'];
        }
        return (int)Db::name('system_role')->insertGetId([
            'type' => 1,
            'relation_id' => $storeId,
            'role_name' => $roleName,
            'rules' => $channel === JobPositionPolicyServices::CHANNEL_STORE_BACKEND ? $rulesStr : '',
            'cashier_rules' => $channel === JobPositionPolicyServices::CHANNEL_CASHIER ? $cashierStr : '',
            'mall_rules' => '',
            'level' => 1,
            'status' => 1,
            'add_time' => $now,
        ]);
    }

    protected function upsertPlatformProjectedRole(int $employeeId, array $ruleIds, int $now): int
    {
        $roleName = $this->platformProjectedRoleName($employeeId);
        $rulesStr = implode(',', $ruleIds);
        $exist = Db::name('system_role')
            ->where('type', 0)
            ->where('relation_id', 0)
            ->where('role_name', $roleName)
            ->lock(true)
            ->find();
        if ($exist) {
            Db::name('system_role')->where('id', (int)$exist['id'])->update([
                'rules' => $rulesStr,
                'cashier_rules' => '',
                'mall_rules' => '',
                'status' => 1,
            ]);
            return (int)$exist['id'];
        }
        return (int)Db::name('system_role')->insertGetId([
            'type' => 0,
            'relation_id' => 0,
            'role_name' => $roleName,
            'rules' => $rulesStr,
            'cashier_rules' => '',
            'mall_rules' => '',
            'level' => 1,
            'status' => 1,
            'add_time' => $now,
        ]);
    }

    protected function disablePlatformProjectedRole(int $employeeId): void
    {
        $roleName = $this->platformProjectedRoleName($employeeId);
        Db::name('system_role')
            ->where('type', 0)
            ->where('relation_id', 0)
            ->where('role_name', $roleName)
            ->update(['status' => 0]);
    }

    /**
     * @return int[]
     */
    protected function listPlatformProjectedRoleIds(int $employeeId): array
    {
        $names = [
            $this->platformProjectedRoleName($employeeId),
            // 兼容可能的短名
            mb_substr('JP-plat-E' . $employeeId, 0, 32),
        ];
        $ids = Db::name('system_role')
            ->where('type', 0)
            ->where('relation_id', 0)
            ->whereIn('role_name', $names)
            ->column('id');
        return array_values(array_unique(array_map('intval', $ids ?: [])));
    }

    protected function platformProjectedRoleName(int $employeeId): string
    {
        // system_role.role_name varchar(32)；命名：岗位投影-平台-E{employeeId}
        $name = '岗位投影-平台-E' . $employeeId;
        if (mb_strlen($name) > 32) {
            $name = 'JP-plat-E' . $employeeId;
        }
        return mb_substr($name, 0, 32);
    }

    protected function disableProjectedRole(int $staffId, int $storeId, string $channel): void
    {
        $roleName = $this->projectedRoleName($staffId, $channel);
        Db::name('system_role')
            ->where('type', 1)
            ->where('relation_id', $storeId)
            ->where('role_name', $roleName)
            ->update(['status' => 0]);
    }

    /**
     * @return int[]
     */
    protected function listProjectedRoleIdsForStaff(int $staffId, int $storeId): array
    {
        $names = [
            $this->projectedRoleName($staffId, JobPositionPolicyServices::CHANNEL_STORE_BACKEND),
            $this->projectedRoleName($staffId, JobPositionPolicyServices::CHANNEL_CASHIER),
        ];
        $ids = Db::name('system_role')
            ->where('type', 1)
            ->where('relation_id', $storeId)
            ->whereIn('role_name', $names)
            ->column('id');
        return array_map('intval', $ids ?: []);
    }

    protected function projectedRoleName(int $staffId, string $channel): string
    {
        // system_role.role_name varchar(32)
        $short = [
            JobPositionPolicyServices::CHANNEL_STORE_BACKEND => 'store',
            JobPositionPolicyServices::CHANNEL_CASHIER => 'cash',
            JobPositionPolicyServices::CHANNEL_MOBILE => 'mobi',
            JobPositionPolicyServices::CHANNEL_PLATFORM => 'plat',
        ];
        $ch = $short[$channel] ?? substr($channel, 0, 4);
        $name = '岗位投影-' . $staffId . '-' . $ch;
        if (mb_strlen($name) > 32) {
            $name = '岗-' . $staffId . '-' . $ch;
        }
        return mb_substr($name, 0, 32);
    }

    protected function assertEmployee(int $employeeId): void
    {
        $emp = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->find();
        if (!$emp) {
            throw new AdminException('员工不存在');
        }
    }

    /**
     * @param mixed $raw
     * @return int[]
     */
    protected function normalizeIntIds($raw): array
    {
        if (!is_array($raw)) {
            $raw = $raw !== '' && $raw !== null ? explode(',', (string)$raw) : [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));
        sort($ids);
        return $ids;
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
            'target_type' => 'staff_job',
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
