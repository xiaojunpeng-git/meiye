<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/** 统一岗位编制维护。当前值是配置投影，不覆盖员工、销售或服务事实。 */
final class StaffingQuotaServices
{
    public const PERMISSION = 'admin-organization-staffing';

    public function list(array $input): array
    {
        $scopeType = (string)($input['scope_type'] ?? 'store');
        if (!in_array($scopeType, ['organization', 'store'], true)) {
            throw new \InvalidArgumentException('范围类型不正确');
        }
        $page = 1;
        $limit = min(100000, max(1, (int)($input['limit'] ?? 100000)));
        $keyword = trim((string)($input['keyword'] ?? ''));
        // 岗位表没有排序字段；按主键保持配置表中岗位顺序稳定。
        $positions = Db::name('position')->where('status', 1)->field('id,name')->order('id asc')->select()->toArray();
        $scopes = $scopeType === 'store' ? $this->stores($keyword) : $this->organizations($keyword);
        $scopeIds = array_map(static fn(array $row): int => (int)$row['id'], $scopes);
        $positionIds = array_map(static fn(array $row): int => (int)$row['id'], $positions);
        $quotaMap = $this->quotas($scopeType, $scopeIds, $positionIds);
        $counts = $this->activeCounts($scopeType, $scopeIds, $positionIds);
        $rows = [];
        foreach ($scopes as $scope) {
            foreach ($positions as $position) {
                $scopeId = (int)$scope['id'];
                $positionId = (int)$position['id'];
                $key = $scopeId . ':' . $positionId;
                $quota = $quotaMap[$key] ?? ['quota_count' => 0, 'version' => 0];
                $active = (int)($counts[$key] ?? 0);
                $quotaCount = (int)$quota['quota_count'];
                $rows[] = [
                    'row_key' => $scopeType . ':' . $scopeId . ':' . $positionId,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'scope_name' => (string)$scope['name'],
                    'position_id' => $positionId,
                    'position_name' => (string)$position['name'],
                    'active_count' => $active,
                    'quota_count' => $quotaCount,
                    'gap' => $quotaCount - $active,
                    'fill_rate' => $quotaCount > 0 ? round($active * 100 / $quotaCount, 1) : null,
                    'version' => (int)$quota['version'],
                ];
            }
        }
        $total = count($rows);
        $offset = ($page - 1) * $limit;
        return [
            'rows' => array_slice($rows, $offset, $limit),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'scope_type' => $scopeType,
            'positions' => $positions,
            'data_as_of' => date('Y-m-d H:i:s'),
            'source_explanations' => [
                'active_count' => '在职且启用员工按当前岗位关系统计。',
                'quota_count' => '岗位编制功能中保存的当前编制人数。',
                'gap' => '岗位编制人数减当前在职人数。',
                'fill_rate' => '当前在职人数除以岗位编制人数，编制为零显示“--”。',
            ],
        ];
    }

    public function save(array $payload, array $operator): array
    {
        $rows = $payload['rows'] ?? [];
        if (is_string($rows)) $rows = json_decode($rows, true);
        if (!is_array($rows) || !$rows) throw new \InvalidArgumentException('没有需要保存的岗位编制');
        $requestId = trim((string)($payload['request_id'] ?? ''));
        if ($requestId === '') throw new \InvalidArgumentException('保存请求编号不能为空');
        $tenantId = '0';
        $old = Db::name('staffing_quota_request')->where(['tenant_id' => $tenantId, 'request_id' => $requestId])->find();
        if ($old) return json_decode((string)$old['response_json'], true) ?: ['saved' => 0];
        $validPositions = array_flip(array_map('intval', Db::name('position')->where('status', 1)->column('id')));
        $now = time();
        $saved = 0;
        $result = Db::transaction(function () use ($rows, $validPositions, $tenantId, $operator, $requestId, $now, &$saved): array {
            foreach ($rows as $row) {
                $scopeType = (string)($row['scope_type'] ?? '');
                $scopeId = (int)($row['scope_id'] ?? 0);
                $positionId = (int)($row['position_id'] ?? 0);
                $quota = filter_var($row['quota_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if (!in_array($scopeType, ['organization', 'store'], true) || $scopeId <= 0 || $positionId <= 0 || $quota === false || !isset($validPositions[$positionId])) {
                    throw new \InvalidArgumentException('岗位编制数据不正确');
                }
                $where = ['tenant_id' => $tenantId, 'scope_type' => $scopeType, 'scope_id' => $scopeId, 'position_id' => $positionId];
                $existing = Db::name('staffing_quota')->where($where)->lock(true)->find();
                $expected = (int)($row['version'] ?? 0);
                if ($existing && $expected !== (int)$existing['version']) {
                    throw new \InvalidArgumentException('岗位编制已被其他人修改，请刷新后重试');
                }
                $version = $existing ? (int)$existing['version'] + 1 : 1;
                $data = $where + ['quota_count' => (int)$quota, 'version' => $version, 'updated_by' => (int)($operator['id'] ?? 0), 'updated_by_name_snapshot' => (string)($operator['name'] ?? ''), 'created_at' => $existing ? (int)$existing['created_at'] : $now, 'updated_at' => $now];
                if ($existing) Db::name('staffing_quota')->where('id', (int)$existing['id'])->update($data);
                else Db::name('staffing_quota')->insert($data);
                Db::name('staffing_quota_audit')->insert($where + ['before_count' => $existing ? (int)$existing['quota_count'] : null, 'after_count' => (int)$quota, 'version' => $version, 'operator_id' => (int)($operator['id'] ?? 0), 'operator_name_snapshot' => (string)($operator['name'] ?? ''), 'request_id' => $requestId, 'occurred_at' => $now]);
                $saved++;
            }
            $response = ['saved' => $saved, 'request_id' => $requestId, 'saved_at' => date('Y-m-d H:i:s', $now)];
            Db::name('staffing_quota_request')->insert(['tenant_id' => $tenantId, 'request_id' => $requestId, 'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE), 'created_at' => $now]);
            return $response;
        });
        return $result;
    }

    private function stores(string $keyword): array
    {
        $q = Db::name('system_store')->where('is_del', 0)->field('id,name')->order('id asc');
        if ($keyword !== '') $q->whereLike('name', '%' . $keyword . '%');
        return $q->select()->toArray();
    }

    private function organizations(string $keyword): array
    {
        $q = Db::name('organization')->where('is_del', 0)->field('id,name')->order('sort desc,id asc');
        if ($keyword !== '') $q->whereLike('name', '%' . $keyword . '%');
        return $q->select()->toArray();
    }

    private function quotas(string $scopeType, array $scopeIds, array $positionIds): array
    {
        if (!$scopeIds || !$positionIds) return [];
        $out = [];
        $rows = Db::name('staffing_quota')->where('tenant_id', '0')->where('scope_type', $scopeType)->whereIn('scope_id', $scopeIds)->whereIn('position_id', $positionIds)->select()->toArray();
        foreach ($rows as $row) $out[(int)$row['scope_id'] . ':' . (int)$row['position_id']] = $row;
        return $out;
    }

    private function activeCounts(string $scopeType, array $scopeIds, array $positionIds): array
    {
        if (!$scopeIds || !$positionIds) return [];
        $rows = Db::name('system_store_staff')->alias('ss')->join('staff_job_position jp', 'jp.staff_id=ss.id AND jp.is_del=0 AND jp.status=1')->join('employee e', 'e.id=ss.employee_id AND e.is_del=0 AND e.status=1')->where('ss.is_del', 0)->where('ss.status', 1)->whereIn('jp.position_id', $positionIds)->field('ss.store_id,jp.position_id,ss.employee_id')->select()->toArray();
        if ($scopeType === 'store') {
            $allowed = array_flip($scopeIds); $seen = [];
            foreach ($rows as $row) if (isset($allowed[(int)$row['store_id']])) $seen[(int)$row['store_id'] . ':' . (int)$row['position_id'] . ':' . (int)$row['employee_id']] = true;
            $out = []; foreach (array_keys($seen) as $key) { [$sid, $pid] = array_map('intval', explode(':', $key)); $out[$sid . ':' . $pid] = ($out[$sid . ':' . $pid] ?? 0) + 1; } return $out;
        }
        $orgRows = Db::name('organization')->where('is_del', 0)->field('id,pid')->select()->toArray();
        $children = []; foreach ($orgRows as $org) $children[(int)$org['pid']][] = (int)$org['id'];
        $expanded = [];
        foreach ($scopeIds as $root) { $stack = [$root]; while ($stack) { $id = array_pop($stack); if (isset($expanded[$root][$id])) continue; $expanded[$root][$id] = true; foreach ($children[$id] ?? [] as $child) $stack[] = $child; } }
        $allOrgIds = []; foreach ($expanded as $ids) foreach (array_keys($ids) as $id) $allOrgIds[(int)$id] = true;
        $bindings = $allOrgIds ? Db::name('organization_store')->whereIn('org_id', array_keys($allOrgIds))->field('org_id,store_id')->select()->toArray() : [];
        $storeOrg = []; foreach ($bindings as $bind) { $boundOrg = (int)$bind['org_id']; foreach ($expanded as $root => $ids) if (isset($ids[$boundOrg])) $storeOrg[(int)$bind['store_id']][] = (int)$root; }
        $seen = []; foreach ($rows as $row) foreach ($storeOrg[(int)$row['store_id']] ?? [] as $oid) $seen[$oid . ':' . (int)$row['position_id'] . ':' . (int)$row['employee_id']] = true;
        $out = []; foreach (array_keys($seen) as $key) { [$oid, $pid] = array_map('intval', explode(':', $key)); $out[$oid . ':' . $pid] = ($out[$oid . ':' . $pid] ?? 0) + 1; } return $out;
    }
}
