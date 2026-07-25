<?php
namespace app\services\organization;

use app\services\BaseServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * I2 人员数据权限（多来源合并 + 订单列表范围）
 */
class EmployeeDataScopeServices extends BaseServices
{
    public const SOURCE_HQ = 'hq';
    public const SOURCE_STORE = 'store';

    public const MODE_PERSONAL = 'personal';
    public const MODE_ORG = 'org';
    public const MODE_STORE = 'store';
    public const MODE_STORE_SELF = 'store_self';

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveScope(
        int $employeeId,
        array $data,
        array $adminInfo,
        array $requestCtx,
        bool $requireSuperAdmin = true
    ): array {
        $sourceType = trim((string)($data['source_type'] ?? self::SOURCE_HQ));
        $sourceStoreId = (int)($data['source_store_id'] ?? 0);
        $scopeMode = trim((string)($data['scope_mode'] ?? self::MODE_PERSONAL));
        $orgIds = $this->normalizeIntIds($data['org_ids'] ?? []);
        $storeIds = $this->normalizeIntIds($data['store_ids'] ?? []);

        $payload = [
            'employee_id' => $employeeId,
            'source_type' => $sourceType,
            'source_store_id' => $sourceStoreId,
            'scope_mode' => $scopeMode,
            'org_ids' => $orgIds,
            'store_ids' => $storeIds,
        ];

        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'employee_data_scope_save',
            'employee:' . $employeeId . ':scope:' . $sourceType . ':' . $sourceStoreId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use (
                $employeeId,
                $sourceType,
                $sourceStoreId,
                $scopeMode,
                $orgIds,
                $storeIds,
                $adminInfo
            ) {
                $data = $this->saveScopeInTx(
                    $employeeId,
                    [
                        'source_type' => $sourceType,
                        'source_store_id' => $sourceStoreId,
                        'scope_mode' => $scopeMode,
                        'org_ids' => $orgIds,
                        'store_ids' => $storeIds,
                    ],
                    $adminInfo,
                    $auditMeta
                );
                return ['msg' => '保存成功', 'data' => $data];
            },
            $requireSuperAdmin
        );
    }

    /**
     * 外层事务内写数据权限（不再套幂等）
     * @return array{id:int,version:int,source_type:string,source_store_id:int,scope_mode:string,org_ids:int[],store_ids:int[]}
     */
    public function saveScopeInTx(
        int $employeeId,
        array $data,
        array $adminInfo,
        array $auditMeta = []
    ): array {
        $this->assertEmployee($employeeId);
        $sourceType = trim((string)($data['source_type'] ?? self::SOURCE_HQ));
        $sourceStoreId = (int)($data['source_store_id'] ?? 0);
        $scopeMode = trim((string)($data['scope_mode'] ?? self::MODE_PERSONAL));
        $orgIds = $this->normalizeIntIds($data['org_ids'] ?? []);
        $storeIds = $this->normalizeIntIds($data['store_ids'] ?? []);

        if (!in_array($sourceType, [self::SOURCE_HQ, self::SOURCE_STORE], true)) {
            throw new AdminException('数据权限来源无效');
        }

        if ($sourceType === self::SOURCE_STORE) {
            if ($sourceStoreId <= 0) {
                throw new AdminException('门店来源须指定本店');
            }
            if ($scopeMode === self::MODE_ORG) {
                throw new AdminException('门店不能设置组织数据范围');
            }
            if (!in_array($scopeMode, [self::MODE_PERSONAL, self::MODE_STORE_SELF], true)) {
                throw new AdminException('门店只能设置个人或本店数据范围');
            }
            $orgIds = [];
            if ($scopeMode === self::MODE_STORE_SELF) {
                $storeIds = [$sourceStoreId];
            } else {
                $storeIds = [];
            }
        } else {
            $sourceStoreId = 0;
            if (!in_array($scopeMode, [self::MODE_PERSONAL, self::MODE_ORG, self::MODE_STORE], true)) {
                throw new AdminException('总部数据范围类型无效');
            }
            if ($scopeMode === self::MODE_PERSONAL) {
                $orgIds = [];
                $storeIds = [];
            } elseif ($scopeMode === self::MODE_ORG) {
                if (!$orgIds) {
                    throw new AdminException('请选择可看组织');
                }
                $found = Db::name('organization')->whereIn('id', $orgIds)->where('is_del', 0)->column('id');
                $found = array_map('intval', $found);
                sort($found);
                $want = $orgIds;
                sort($want);
                if ($found !== $want) {
                    throw new AdminException('组织范围包含无效或已删除组织');
                }
                // 非超管：只能授权自己可管范围内的组织
                $allowedOrgIds = $this->resolveOperatorAllowedOrgIds($adminInfo);
                if ($allowedOrgIds !== null) {
                    $denied = array_values(array_diff($found, $allowedOrgIds));
                    if ($denied) {
                        throw new AdminException('组织范围超出可授权范围');
                    }
                }
                $orgIds = $found;
                $storeIds = [];
            } else {
                // 门店模式：服务端按当前有效任职自动计算，忽略前端 store_ids（防扩权）
                $clientStoreIds = $storeIds;
                $appointmentStoreId = (int)($data['appointment_store_id'] ?? 0);
                $currentStoreIds = $this->resolveCurrentActiveStoreIds($employeeId);
                if ($appointmentStoreId > 0 && !in_array($appointmentStoreId, $currentStoreIds, true)) {
                    // 同事务新建任职尚未落库可见时，允许以本次提交的任职门店为准
                    $currentStoreIds[] = $appointmentStoreId;
                    $currentStoreIds = array_values(array_unique(array_filter(array_map('intval', $currentStoreIds))));
                }
                if (!$currentStoreIds) {
                    throw new AdminException('该人员没有当前任职门店，不能选择「门店」数据权限。请先选择当前任职门店，或改用「个人」「组织」。');
                }
                // 不信任前端传来的任意 store_ids；持久化仅记空数组，解析时按任职自动计算
                $storeIds = [];
                $orgIds = [];
                unset($clientStoreIds);
            }
        }

        $now = time();
        $token = (string)($auditMeta['request_token'] ?? '');
        $opId = (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0);
        $opName = (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? '');

        $exist = Db::name('employee_data_scope')
            ->where('employee_id', $employeeId)
            ->where('source_type', $sourceType)
            ->where('source_store_id', $sourceStoreId)
            ->where('is_del', 0)
            ->lock(true)
            ->order('id', 'desc')
            ->find();

        $orgJson = json_encode($orgIds, JSON_UNESCAPED_UNICODE);
        $storeJson = json_encode($storeIds, JSON_UNESCAPED_UNICODE);
        if ($exist) {
            $ver = (int)($exist['version'] ?? 1) + 1;
            Db::name('employee_data_scope')->where('id', (int)$exist['id'])->update([
                'scope_mode' => $scopeMode,
                'org_ids' => $orgJson,
                'store_ids' => $storeJson,
                'status' => 1,
                'version' => $ver,
                'request_token' => $token !== '' ? $token : (string)($exist['request_token'] ?? ''),
                'operator_id' => $opId,
                'operator_name' => $opName,
                'update_time' => $now,
            ]);
            $id = (int)$exist['id'];
        } else {
            $id = (int)Db::name('employee_data_scope')->insertGetId([
                'employee_id' => $employeeId,
                'source_type' => $sourceType,
                'source_store_id' => $sourceStoreId,
                'scope_mode' => $scopeMode,
                'org_ids' => $orgJson,
                'store_ids' => $storeJson,
                'status' => 1,
                'version' => 1,
                'request_token' => $token !== '' ? $token : ('scope-' . $employeeId . '-' . $sourceType . '-' . $sourceStoreId . '-' . $now),
                'is_del' => 0,
                'operator_id' => $opId,
                'operator_name' => $opName,
                'add_time' => $now,
                'update_time' => $now,
            ]);
            $ver = 1;
        }

        $this->writeAudit(
            $employeeId,
            'employee_data_scope_save',
            $id,
            [
                'source_type' => $sourceType,
                'source_store_id' => $sourceStoreId,
                'scope_mode' => $scopeMode,
                'org_ids' => $orgIds,
                'store_ids' => $storeIds,
                'version' => $ver,
            ],
            $adminInfo,
            $auditMeta,
            '保存数据权限'
        );

        return [
            'id' => $id,
            'version' => $ver,
            'source_type' => $sourceType,
            'source_store_id' => $sourceStoreId,
            'scope_mode' => $scopeMode,
            'org_ids' => $orgIds,
            'store_ids' => $storeIds,
        ];
    }

    /**
     * 撤销某门店来源授权（软删）
     */
    public function revokeStoreSourceScope(int $employeeId, int $storeId, array $auditMeta = []): void
    {
        if ($employeeId <= 0 || $storeId <= 0) {
            return;
        }
        $now = time();
        Db::name('employee_data_scope')
            ->where('employee_id', $employeeId)
            ->where('source_type', self::SOURCE_STORE)
            ->where('source_store_id', $storeId)
            ->where('is_del', 0)
            ->update([
                'status' => 0,
                'is_del' => 1,
                'update_time' => $now,
                'operator_id' => (int)($auditMeta['operator_id'] ?? 0),
                'operator_name' => (string)($auditMeta['operator_name'] ?? ''),
            ]);
    }

    /**
     * @return array<int, array>
     */
    public function listScopes(int $employeeId, int $forStoreId = 0): array
    {
        $q = Db::name('employee_data_scope')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1);
        if ($forStoreId > 0) {
            // 门店端：可见总部 + 本店来源
            $q->where(function ($qq) use ($forStoreId) {
                $qq->where(function ($q1) {
                    $q1->where('source_type', self::SOURCE_HQ);
                })->whereOr(function ($q2) use ($forStoreId) {
                    $q2->where('source_type', self::SOURCE_STORE)->where('source_store_id', $forStoreId);
                });
            });
        }
        $rows = $q->order('id', 'asc')->select()->toArray();
        foreach ($rows as &$r) {
            $r['org_ids'] = json_decode((string)($r['org_ids'] ?? '[]'), true) ?: [];
            $r['store_ids'] = json_decode((string)($r['store_ids'] ?? '[]'), true) ?: [];
        }
        unset($r);
        return $rows;
    }

    /**
     * 解析有效门店 ID 集合；空数组表示「仅个人参与、无门店扩展」
     * 返回 null 表示超级管理员全量（不限制门店）
     * @return int[]|null
     */
    public function resolveEffectiveStoreIds(int $employeeId, int $contextStoreId = 0, array $adminInfo = [])
    {
        if ($this->isSuperAdmin($adminInfo)) {
            return null;
        }
        $scopes = Db::name('employee_data_scope')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->select()->toArray();

        $storeSet = [];
        $hasExtension = false;
        if (!$scopes) {
            // 默认个人：无门店扩展
            $hasExtension = false;
        } else {
            foreach ($scopes as $scope) {
                $mode = (string)($scope['scope_mode'] ?? self::MODE_PERSONAL);
                if ($mode === self::MODE_PERSONAL) {
                    continue;
                }
                $hasExtension = true;
                if ($mode === self::MODE_STORE_SELF) {
                    $sid = (int)($scope['source_store_id'] ?? 0);
                    if ($sid > 0) {
                        $storeSet[$sid] = true;
                    }
                    continue;
                }
                if ($mode === self::MODE_STORE) {
                    // 门店模式：仅当前有效任职门店进入「全量门店」集合；历史由 applyOrderListScope 按本人窗口补齐
                    foreach ($this->resolveCurrentActiveStoreIds($employeeId) as $sid) {
                        if ($sid > 0) {
                            $storeSet[$sid] = true;
                        }
                    }
                    // 兼容旧数据：若仍存有 store_ids，不并入（防历史扩权残留）
                    continue;
                }
                if ($mode === self::MODE_ORG) {
                    $orgIds = json_decode((string)($scope['org_ids'] ?? '[]'), true) ?: [];
                    foreach ($orgIds as $oid) {
                        foreach ($this->expandOrgToStoreIds((int)$oid) as $sid) {
                            $storeSet[$sid] = true;
                        }
                    }
                }
            }
        }

        $isolated = Db::name('employee_store_isolation')
            ->where('employee_id', $employeeId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->column('store_id');
        foreach ($isolated as $sid) {
            unset($storeSet[(int)$sid]);
        }

        if ($contextStoreId > 0) {
            // 门店端上下文：结果再与当前门店求交（门店端永远不能看其他店）
            if (!$hasExtension) {
                return [];
            }
            return isset($storeSet[$contextStoreId]) ? [$contextStoreId] : [];
        }

        if (!$hasExtension) {
            return [];
        }
        $ids = array_map('intval', array_keys($storeSet));
        sort($ids);
        return $ids;
    }

    /**
     * @return int[]
     */
    public function resolveStaffIdsForEmployee(int $employeeId): array
    {
        if ($employeeId <= 0) {
            return [];
        }
        $ids = Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->column('id');
        $ids = array_values(array_unique(array_map('intval', $ids ?: [])));
        sort($ids);
        return $ids;
    }

    /**
     * 变更订单列表 where：个人参与 / 门店范围 / 超管不限
     */
    public function applyOrderListScope(array &$where, int $employeeId, string $end, int $storeId, array $adminInfo): void
    {
        if ($this->isSuperAdmin($adminInfo)) {
            $where['employee_data_scope'] = ['mode' => 'all'];
            return;
        }
        if ($employeeId <= 0) {
            // 未关联员工：禁止默认全量
            $where['id'] = -1;
            $where['employee_data_scope'] = ['mode' => 'none', 'reason' => '未关联内部员工'];
            return;
        }

        $end = $end === 'store' ? 'store' : 'admin';
        $staffIds = $this->resolveStaffIdsForEmployee($employeeId);
        $effectiveStores = $this->resolveEffectiveStoreIds($employeeId, $end === 'store' ? $storeId : 0, $adminInfo);

        if ($end === 'store') {
            if ($storeId <= 0) {
                $where['id'] = -1;
                $where['employee_data_scope'] = ['mode' => 'none'];
                return;
            }
            $where['store_id'] = $storeId;
            // 本店扩展：当前门店全部 + 历史任职门店本人期间数据
            if (is_array($effectiveStores) && in_array($storeId, $effectiveStores, true)) {
                $historyWindows = $this->resolveTenureWindows($employeeId, $storeId);
                $where['employee_data_scope'] = [
                    'mode' => 'store_with_history',
                    'store_id' => $storeId,
                    'staff_ids' => $staffIds,
                    'history_windows' => $historyWindows,
                ];
                return;
            }
            // 个人：当前/历史任职期间本人参与
            $this->applyPersonalParticipate($where, $staffIds, $storeId, $this->resolveTenureWindows($employeeId));
            return;
        }

        // 平台端
        if ($effectiveStores === null) {
            $where['employee_data_scope'] = ['mode' => 'all'];
            return;
        }

        $hqMode = $this->resolvePrimaryHqScopeMode($employeeId);
        if ($hqMode === self::MODE_STORE) {
            $current = $this->resolveCurrentActiveStoreIds($employeeId);
            $curStore = (int)($current[0] ?? 0);
            if ($curStore <= 0) {
                $this->applyPersonalParticipate($where, $staffIds, 0, $this->resolveTenureWindows($employeeId));
                return;
            }
            $where['employee_data_scope'] = [
                'mode' => 'store_with_history',
                'store_id' => $curStore,
                'staff_ids' => $staffIds,
                'history_windows' => $this->resolveTenureWindows($employeeId, $curStore),
            ];
            return;
        }

        if ($effectiveStores) {
            // 组织范围：全量这些门店
            if (count($effectiveStores) === 1) {
                $where['store_id'] = $effectiveStores[0];
            } else {
                $where['store_id'] = $effectiveStores;
            }
            $where['employee_data_scope'] = [
                'mode' => 'stores',
                'store_ids' => $effectiveStores,
            ];
            return;
        }
        // 默认个人（含历史任职期间）
        $this->applyPersonalParticipate($where, $staffIds, 0, $this->resolveTenureWindows($employeeId));
    }

    /**
     * 当前有效任职门店（未删除、在职）
     * @return int[]
     */
    public function resolveCurrentActiveStoreIds(int $employeeId): array
    {
        if ($employeeId <= 0) {
            return [];
        }
        $ids = Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('store_id', '>', 0)
            ->column('store_id');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids ?: []))));
        sort($ids);
        return $ids;
    }

    /**
     * 总部来源主数据权限模式
     */
    public function resolvePrimaryHqScopeMode(int $employeeId): string
    {
        if ($employeeId <= 0) {
            return self::MODE_PERSONAL;
        }
        $row = Db::name('employee_data_scope')
            ->where('employee_id', $employeeId)
            ->where('source_type', self::SOURCE_HQ)
            ->where('source_store_id', 0)
            ->where('is_del', 0)
            ->where('status', 1)
            ->order('id', 'desc')
            ->find();
        $mode = (string)($row['scope_mode'] ?? self::MODE_PERSONAL);
        if (!in_array($mode, [self::MODE_PERSONAL, self::MODE_ORG, self::MODE_STORE], true)) {
            return self::MODE_PERSONAL;
        }
        return $mode;
    }

    /**
     * 操作者可授权组织；null=超管不限
     * @return int[]|null
     */
    protected function resolveOperatorAllowedOrgIds(array $adminInfo): ?array
    {
        if ($this->isSuperAdmin($adminInfo)) {
            return null;
        }
        $adminId = (int)($adminInfo['id'] ?? 0);
        if ($adminId <= 0) {
            return [];
        }
        $orgAdminIds = Db::name('organization_admin')
            ->where('admin_id', $adminId)
            ->where('is_del', 0)
            ->column('id');
        if (!$orgAdminIds) {
            return [];
        }
        /** @var OrganizationScopeService $scope */
        $scope = app()->make(OrganizationScopeService::class);
        $storeIds = [];
        foreach ($orgAdminIds as $oaId) {
            $storeIds = array_merge($storeIds, $scope->getAdminResolvedStoreIds((int)$oaId));
        }
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (!$storeIds) {
            return [];
        }
        $directOrgIds = Db::name('organization_store')
            ->whereIn('store_id', $storeIds)
            ->column('org_id');
        $directOrgIds = array_values(array_unique(array_filter(array_map('intval', $directOrgIds))));
        if (!$directOrgIds) {
            return [];
        }
        $pidMap = Db::name('organization')->where('is_del', 0)->column('pid', 'id');
        $allowed = [];
        foreach ($directOrgIds as $oid) {
            $cur = $oid;
            $guard = 0;
            while ($cur > 0 && $guard++ < 128) {
                $allowed[$cur] = true;
                $cur = (int)($pidMap[$cur] ?? 0);
            }
        }
        return array_map('intval', array_keys($allowed));
    }

    /**
     * 任职期间窗口：用于历史门店数据截断
     * @return array<int, array{store_id:int,staff_id:int,start_time:int,end_time:int}>
     */
    public function resolveTenureWindows(int $employeeId, int $excludeStoreId = 0): array
    {
        if ($employeeId <= 0) {
            return [];
        }
        $rows = Db::name('staff_tenure_period')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $out = [];
        foreach ($rows as $r) {
            $sid = (int)($r['store_id'] ?? 0);
            if ($sid <= 0 || ($excludeStoreId > 0 && $sid === $excludeStoreId)) {
                continue;
            }
            $out[] = [
                'store_id' => $sid,
                'staff_id' => (int)($r['staff_id'] ?? 0),
                'start_time' => (int)($r['start_time'] ?? 0),
                'end_time' => (int)($r['end_time'] ?? 0),
            ];
        }
        // 无 tenure 行时回退 staff_job_position / 有效 staff 的起止
        if (!$out) {
            $jobs = Db::name('staff_job_position')
                ->where('employee_id', $employeeId)
                ->where('is_del', 0)
                ->select()
                ->toArray();
            foreach ($jobs as $j) {
                $sid = (int)($j['store_id'] ?? 0);
                if ($sid <= 0 || ($excludeStoreId > 0 && $sid === $excludeStoreId)) {
                    continue;
                }
                $out[] = [
                    'store_id' => $sid,
                    'staff_id' => (int)($j['staff_id'] ?? 0),
                    'start_time' => (int)($j['start_time'] ?? 0),
                    'end_time' => (int)($j['end_time'] ?? 0),
                ];
            }
        }
        return $out;
    }

    /**
     * @param int[] $staffIds
     * @param array<int, array{store_id:int,staff_id:int,start_time:int,end_time:int}> $windows
     */
    protected function applyPersonalParticipate(array &$where, array $staffIds, int $forceStoreId, array $windows = []): void
    {
        if (!$staffIds) {
            $where['id'] = -1;
            $where['employee_data_scope'] = ['mode' => 'personal', 'staff_ids' => []];
            return;
        }
        if ($forceStoreId > 0 && !$windows) {
            $where['store_id'] = $forceStoreId;
        }
        $where['employee_data_scope'] = [
            'mode' => 'personal',
            'staff_ids' => $staffIds,
            'store_id' => $forceStoreId,
            'tenure_windows' => $windows,
        ];
        // 供现有 search 可消费的辅助键；订单 DAO 接入时优先读 employee_data_scope
        $where['data_scope_participate_staff_ids'] = $staffIds;
        $where['data_scope_participate_fields'] = ['staff_id', 'clerk_id', 'service_staff_id', 'gendan_staff_id'];
    }

    /**
     * @return int[]
     */
    public function expandOrgToStoreIds(int $orgId): array
    {
        if ($orgId <= 0) {
            return [];
        }
        $orgIds = $this->collectOrgIdsIncl($orgId);
        if (!$orgIds) {
            return [];
        }
        $ids = Db::name('organization_store')->alias('os')
            ->join('system_store st', 'st.id = os.store_id')
            ->whereIn('os.org_id', $orgIds)
            ->where('st.is_del', 0)
            ->column('os.store_id');
        $ids = array_values(array_unique(array_map('intval', $ids ?: [])));
        sort($ids);
        return $ids;
    }

    /**
     * @return int[]
     */
    protected function collectOrgIdsIncl(int $orgId): array
    {
        $rows = Db::name('organization')->where('is_del', 0)->field('id,pid')->select()->toArray();
        $children = [];
        foreach ($rows as $row) {
            $children[(int)($row['pid'] ?? 0)][] = (int)$row['id'];
        }
        $out = [];
        $stack = [$orgId];
        $guard = 0;
        while ($stack && $guard < 5000) {
            $cur = (int)array_pop($stack);
            if ($cur <= 0 || isset($out[$cur])) {
                $guard++;
                continue;
            }
            $out[$cur] = true;
            foreach ($children[$cur] ?? [] as $cid) {
                $stack[] = (int)$cid;
            }
            $guard++;
        }
        return array_map('intval', array_keys($out));
    }

    public function isSuperAdmin(array $adminInfo): bool
    {
        if (!is_array($adminInfo) || !array_key_exists('level', $adminInfo) || !array_key_exists('admin_type', $adminInfo)) {
            return false;
        }
        $level = (int)$adminInfo['level'];
        $adminType = (int)$adminInfo['admin_type'];
        return $level === 0 && $adminType !== 3;
    }

    /** 核销/共享写入口统一拒绝文案（人话） */
    public const DENY_ORDER_WRITEOFF_MSG = '无权查看或核销该订单';

    /**
     * 列表进入详情二次校验：与列表同一数据范围
     * @param array $orderRow 至少含 id/store_id/staff_id/clerk_id/service_staff_id/gendan_staff_id
     */
    public function assertOrderReadable(array $orderRow, int $employeeId, string $end, int $storeId, array $adminInfo): void
    {
        if ($this->isSuperAdmin($adminInfo)) {
            return;
        }
        if ($employeeId <= 0) {
            throw new AdminException('无权查看该订单');
        }
        $orderId = (int)($orderRow['id'] ?? 0);
        $orderStoreId = (int)($orderRow['store_id'] ?? 0);
        if ($orderId <= 0) {
            throw new AdminException('订单不存在');
        }
        $end = $end === 'store' ? 'store' : 'admin';
        if ($end === 'store') {
            if ($storeId <= 0 || $orderStoreId !== $storeId) {
                throw new AdminException('无权查看该订单');
            }
        }

        $isolated = Db::name('employee_store_isolation')
            ->where('employee_id', $employeeId)
            ->where('store_id', $orderStoreId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->value('id');
        if ((int)$isolated > 0) {
            throw new AdminException('无权查看该订单');
        }

        $staffIds = $this->resolveStaffIdsForEmployee($employeeId);
        $effectiveStores = $this->resolveEffectiveStoreIds($employeeId, $end === 'store' ? $storeId : 0, $adminInfo);

        if ($end === 'store') {
            if (is_array($effectiveStores) && in_array($storeId, $effectiveStores, true)) {
                return; // 本店范围
            }
            if ($this->orderParticipatedByStaff($orderRow, $staffIds)) {
                return;
            }
            throw new AdminException('无权查看该订单');
        }

        // 平台端
        if ($effectiveStores === null) {
            return;
        }
        if ($effectiveStores) {
            if (in_array($orderStoreId, $effectiveStores, true)) {
                return;
            }
            throw new AdminException('无权查看该订单');
        }
        if ($this->orderParticipatedByStaff($orderRow, $staffIds)) {
            return;
        }
        throw new AdminException('无权查看该订单');
    }

    /**
     * 平台端核销/写入口：解析员工后按数据范围校验订单
     */
    public function guardOrderAccessFromAdmin(array $adminInfo, array $orderRow): void
    {
        $employeeId = $this->resolveEmployeeIdFromOperator($adminInfo, 'admin');
        try {
            $this->assertOrderReadable($orderRow, $employeeId, 'admin', 0, $adminInfo);
        } catch (AdminException $e) {
            throw new AdminException(self::DENY_ORDER_WRITEOFF_MSG);
        }
    }

    /**
     * 门店端核销/写入口：解析员工后按本店数据范围校验订单
     */
    public function guardOrderAccessFromStoreStaff(array $staffInfo, int $storeId, array $orderRow): void
    {
        $employeeId = $this->resolveEmployeeIdFromOperator($staffInfo, 'store');
        $adminInfo = [
            'id' => (int)($staffInfo['id'] ?? 0),
            'level' => 1,
            'admin_type' => 3,
            'employee_id' => $employeeId,
        ];
        try {
            $this->assertOrderReadable($orderRow, $employeeId, 'store', $storeId, $adminInfo);
        } catch (AdminException $e) {
            throw new AdminException(self::DENY_ORDER_WRITEOFF_MSG);
        }
    }

    /**
     * 收银台核销/写入口：收银员同属门店店员体系
     */
    public function guardOrderAccessFromCashier(array $cashierInfo, int $storeId, array $orderRow): void
    {
        $employeeId = $this->resolveEmployeeIdFromOperator($cashierInfo, 'cashier');
        $adminInfo = [
            'id' => (int)($cashierInfo['id'] ?? 0),
            'level' => 1,
            'admin_type' => 3,
            'employee_id' => $employeeId,
        ];
        try {
            $this->assertOrderReadable($orderRow, $employeeId, 'store', $storeId, $adminInfo);
        } catch (AdminException $e) {
            throw new AdminException(self::DENY_ORDER_WRITEOFF_MSG);
        }
    }

    /**
     * 门店核销记录列表：锁定本店；个人模式叠加核销店员 staff_id 过滤
     */
    public function applyWriteOffRecordsScope(array &$where, array $staffInfo, int $storeId): void
    {
        $storeId = (int)$storeId;
        $where['relation_id'] = $storeId > 0 ? $storeId : -1;
        if ($storeId <= 0) {
            $where['scope_staff_ids'] = [-1];
            return;
        }
        $employeeId = $this->resolveEmployeeIdFromOperator($staffInfo, 'store');
        $adminInfo = [
            'id' => (int)($staffInfo['id'] ?? 0),
            'level' => 1,
            'admin_type' => 3,
            'employee_id' => $employeeId,
        ];
        $tmp = [];
        $this->applyOrderListScope($tmp, $employeeId, 'store', $storeId, $adminInfo);
        $mode = (string)($tmp['employee_data_scope']['mode'] ?? '');
        if ($mode === 'store_all' || $mode === 'all') {
            return;
        }
        $staffIds = array_values(array_filter(array_map('intval', (array)($tmp['employee_data_scope']['staff_ids'] ?? []))));
        $where['scope_staff_ids'] = $staffIds ?: [-1];
    }

    /**
     * @param int[] $staffIds
     */
    protected function orderParticipatedByStaff(array $orderRow, array $staffIds): bool
    {
        if (!$staffIds) {
            return false;
        }
        $set = array_fill_keys($staffIds, true);
        foreach (['staff_id', 'clerk_id', 'service_staff_id', 'gendan_staff_id'] as $field) {
            $v = (int)($orderRow[$field] ?? 0);
            if ($v > 0 && isset($set[$v])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 从 adminInfo / staffInfo / cashierInfo 解析 employee_id
     */
    public function resolveEmployeeIdFromOperator(array $operator, string $end = 'admin'): int
    {
        $employeeId = (int)($operator['employee_id'] ?? 0);
        if ($employeeId > 0) {
            return $employeeId;
        }
        $opId = (int)($operator['id'] ?? 0);
        if ($opId <= 0) {
            return 0;
        }
        if ($end === 'store' || $end === 'cashier') {
            return (int)Db::name('system_store_staff')->where('id', $opId)->where('is_del', 0)->value('employee_id');
        }
        return (int)Db::name('system_admin')->where('id', $opId)->where('is_del', 0)->value('employee_id');
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
            'target_type' => 'data_scope',
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
