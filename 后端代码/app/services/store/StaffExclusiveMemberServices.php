<?php

namespace app\services\store;

use app\services\organization\OrganizationStrictIdempotencyServices;
use app\services\organization\OrganizationWorkspaceWriteGate;
use app\services\organization\EmployeeDataScopeServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use think\facade\Db;

/**
 * 平台人员管理只管理会员档案的专属服务人关系，不改 salesman_id、订单、预约或业绩。
 * 多人选择是权威来源；仅无有效多人记录的老档案读取单人兼容关系。
 */
class StaffExclusiveMemberServices
{
    /** 查询只返回四个展示字段；分页、日期筛选和计数使用同一个关系查询。 */
    public function members(int $staffId, array $filters, array $admin): array
    {
        $source = $this->source($staffId, $admin);
        $query = $this->memberQuery($source);
        $keyword = trim((string)($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('u.nickname|u.real_name|u.phone', '%' . $keyword . '%');
        }
        foreach (['date_from' => '>=', 'date_to' => '<'] as $key => $operator) {
            $date = trim((string)($filters[$key] ?? ''));
            if ($date === '') continue;
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new \RuntimeException('绑定时间格式无效');
            }
            $query->where('b.bound_at', $operator, $key === 'date_to'
                ? $parsed->modify('+1 day')->getTimestamp() : $parsed->getTimestamp());
        }
        $count = (clone $query)->count();
        $rows = $query->field('u.nickname,u.real_name,u.phone,b.bound_at')
            ->order('u.uid', 'asc')->page(max(1, (int)($filters['page'] ?? 1)),
                min(100, max(1, (int)($filters['limit'] ?? 20))))->select()->toArray();
        foreach ($rows as &$row) {
            $row['bound_at'] = (int)$row['bound_at'] > 0 ? date('Y-m-d H:i:s', (int)$row['bound_at']) : '-';
        }
        return ['list' => $rows, 'count' => $count];
    }

    /** 预览覆盖此人的全部专属会员，不受查看弹窗的搜索或分页条件影响。 */
    public function prepare(int $staffId, array $admin): array
    {
        return Db::transaction(function () use ($staffId, $admin): array {
            $source = $this->source($staffId, $admin);
            $snapshot = $this->snapshot($source);
            return [
                'source_name' => $source['staff_name'], 'store_name' => $source['store_name'],
                'count' => count($snapshot), 'snapshot' => $this->fingerprint($snapshot),
                'recipients' => $this->recipients($source),
            ];
        });
    }

    /**
     * 同请求令牌回放原结果；全批次锁定、检查预览版本后原子替换。
     * 失败全部回滚，不留下部分会员已转移的状态。
     */
    public function transfer(int $staffId, array $payload, array $admin): array
    {
        $this->source($staffId, $admin);
        $raw = $payload['recipient_ids'] ?? null;
        if (!is_array($raw) || !$raw || count($raw) > 30) {
            throw new \RuntimeException('请选择1至30名接收员工');
        }
        $ids = [];
        foreach ($raw as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string)$id)) {
                throw new \RuntimeException('接收员工标识无效');
            }
            $ids[] = (int)$id;
        }
        $ids = array_values(array_unique($ids));
        if (in_array($staffId, $ids, true)) throw new \RuntimeException('接收员工不能包含转出员工本人');
        $expected = (string)($payload['snapshot'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected)) throw new \RuntimeException('请重新打开转移窗口');
        $token = (string)($payload['request_token'] ?? '');
        return app()->make(OrganizationStrictIdempotencyServices::class)->run(
            'staff.exclusive-members.transfer', 'staff:' . $staffId,
            ['recipient_ids' => $ids, 'snapshot' => $expected], $admin, ['body_token' => $token],
            function (array $audit) use ($staffId, $ids, $expected, $admin): array {
                $source = $this->source($staffId, $admin, true);
                $eligible = array_column($this->recipients($source, true), null, 'staff_id');
                $targets = [];
                foreach ($ids as $id) {
                    if (!isset($eligible[$id])) {
                        throw new \RuntimeException('接收员工必须是同门店在职且可作为专属服务人的员工，请重新选择');
                    }
                    $targets[] = $eligible[$id];
                }
                $snapshot = $this->snapshot($source);
                $memberIds = array_keys($snapshot);
                if (!$memberIds) throw new \RuntimeException('该员工暂无可转移的专属会员');
                // 与会员编辑一致先锁会员主记录再锁 member 资源版本；旧编辑版本不能覆盖转移。
                Db::name('user')->whereIn('uid', $memberIds)->order('uid', 'asc')->lock(true)->select();
                $versions = Db::name(CashierV3EntitlementResourceVersionProvider::VERSION_TABLE)
                    ->where('resource_kind', 'member')->whereIn('resource_id', $memberIds)
                    ->order('resource_id', 'asc')->lock(true)->select()->toArray();
                Db::name('member_exclusive_service')->whereIn('member_id', $memberIds)
                    ->order('member_id', 'asc')->lock(true)->select();
                Db::name('member_exclusive_service_selection')->whereIn('member_id', $memberIds)
                    ->order('member_id asc,id asc')->lock(true)->select();
                $locked = $this->snapshot($source, true);
                if (!hash_equals($expected, $this->fingerprint($locked))) {
                    throw new \RuntimeException('专属会员绑定已变化，请重新打开转移窗口后确认');
                }
                $now = time();
                foreach ($locked as $memberId => $people) {
                    $after = $this->replaceSource($people, $staffId, $targets);
                    if (count($after) > 30) throw new \RuntimeException('转移后某会员的专属服务人超过30人，本次未转移');
                    $this->writeSelection((int)$memberId, $people, $after, $audit, $now);
                    $this->syncFirstPerson((int)$memberId, $after[0], $audit, $now);
                }
                foreach ($versions as $version) {
                    $changed = Db::name(CashierV3EntitlementResourceVersionProvider::VERSION_TABLE)
                        ->where('id', $version['id'])->where('current_version', $version['current_version'])
                        ->update(['current_version' => (int)$version['current_version'] + 1,
                            'last_action' => 'exclusive-member-transfer', 'update_time' => $now]);
                    if ($changed !== 1) throw new \RuntimeException('会员版本已变化，本次未转移');
                }
                return ['msg' => '专属会员转移成功', 'data' => ['count' => count($locked)]];
            }, false
        );
    }

    /** 权限由登录身份及组织范围解析；不接受前端传入门店或组织扩大范围。 */
    private function source(int $staffId, array $admin, bool $lock = false): array
    {
        $gate = app()->make(OrganizationWorkspaceWriteGate::class);
        $permission = $gate->assertPlatformStaffMaintainPermission($admin);
        if (!$permission['ok']) throw new \RuntimeException($permission['reason_text']);
        // 权限和数据范围使用数据库里的当前管理员身份，不能沿用会话中已撤回的超管标记。
        $admin = Db::name('system_admin')->where('id', (int)$admin['id'])->where('status', 1)->where('is_del', 0)->find();
        if (!$admin) throw new \RuntimeException('当前账号已失效');
        $query = Db::name('system_store_staff')->where('id', $staffId)->where('is_del', 0);
        if ($lock) $query->lock(true);
        $source = $query->find();
        if ($staffId <= 0 || !$source || (int)$source['store_id'] <= 0) {
            throw new \RuntimeException('该人员没有有效的门店任职，不能管理专属会员');
        }
        $orgId = (int)Db::name('organization_store')->where('store_id', $source['store_id'])->value('org_id');
        $permission = $gate->assertOrganizationManagePermission($orgId, $admin);
        $scope = app()->make(EmployeeDataScopeServices::class);
        $employeeId = $scope->resolveEmployeeIdFromOperator($admin, 'admin');
        if ($employeeId > 0) {
            // 统一员工范围优先于旧组织管理员关系，包括精确门店与门店隔离；不能取并集扩权。
            $stores = $scope->resolveEffectiveStoreIds($employeeId, 0, $admin);
            if ($stores !== null && !in_array((int)$source['store_id'], $stores, true)) {
                throw new \RuntimeException('超出当前账号的数据权限范围，不能管理该员工的专属会员');
            }
        } elseif (!$permission['ok']) {
            throw new \RuntimeException($permission['reason_text']);
        }
        $source['store_name'] = (string)Db::name('system_store')->where('id', $source['store_id'])
            ->where('is_del', 0)->value('name');
        if ($source['store_name'] === '') throw new \RuntimeException('所属门店不存在');
        return $source;
    }

    /** 与会员档案选人边界一致：同门店有效任职、有效员工、可作为销售人或手艺人。 */
    private function recipients(array $source, bool $lock = false): array
    {
        $query = Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')
            ->where('s.store_id', (int)$source['store_id'])->where('s.id', '<>', (int)$source['id'])
            ->where('s.status', 1)->where('s.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)
            ->where(function ($q) { $q->where('s.cashier_salesperson_enabled', 1)->whereOr('s.cashier_craftsman_enabled', 1); })
            ->field('s.id AS staff_id,s.employee_id,s.store_id,e.name AS staff_name')->order('s.id', 'asc');
        if ($lock) $query->lock(true);
        return $query->select()->toArray();
    }

    /**
     * 使用关联主键而非姓名。绑定时间取当前服务人最近一次加入的审计时点；
     * 旧数据无多人审计时保留 created_at/单人 bound_at，不以资料更新时间冒充。
     */
    private function memberQuery(array $source)
    {
        $selection = Db::name('member_exclusive_service_selection')->getTable();
        $history = Db::name('member_exclusive_service_selection_change')->getTable();
        $legacy = Db::name('member_exclusive_service')->getTable();
        $staffId = (int)$source['id'];
        $storeId = (int)$source['store_id'];
        $sql = "SELECT s.member_id,COALESCE((SELECT MAX(c.occurred_at) FROM {$history} c"
            . " WHERE c.member_id=s.member_id AND JSON_CONTAINS(c.current_json,CAST(s.staff_id AS CHAR))"
            . " AND NOT JSON_CONTAINS(c.previous_json,CAST(s.staff_id AS CHAR))),NULLIF(s.created_at,0),0) AS bound_at"
            . " FROM {$selection} s WHERE s.staff_id={$staffId} AND s.store_id={$storeId} AND s.status=1"
            . " UNION ALL SELECT l.member_id,l.bound_at FROM {$legacy} l"
            . " WHERE l.staff_id={$staffId} AND l.store_id={$storeId} AND l.status=1"
            . " AND NOT EXISTS(SELECT 1 FROM {$selection} a WHERE a.member_id=l.member_id AND a.status=1)";
        return Db::table('(' . $sql . ')')->alias('b')->join('user u', 'u.uid=b.member_id')
            ->where('u.is_del', 0)->where('u.status', 1)->where('u.delete_time', null)
            ->whereExists(function ($q) use ($storeId) {
                $q->name('store_user')->alias('su')->whereRaw('su.uid=u.uid')->where('su.store_id', $storeId)->where('su.status', 1);
            });
    }

    /** 固定整个当前关系和版本，不能在预览后悄悄多转、漏转或覆盖另一人的修改。 */
    private function snapshot(array $source, bool $lock = false): array
    {
        if ($lock) {
            // RR 隔离级别下不能用预览的一致性读重新判断成员集合。
            // 直接锁权威关系做当前读，覆盖期间新增/解除的绑定及 legacy -> multi 切换。
            $ids = Db::name('member_exclusive_service_selection')->where('staff_id', $source['id'])
                ->where('store_id', $source['store_id'])->where('status', 1)->lock(true)->column('member_id');
            $legacyIds = Db::name('member_exclusive_service')->where('staff_id', $source['id'])
                ->where('store_id', $source['store_id'])->where('status', 1)->lock(true)->column('member_id');
            $ids = array_values(array_unique(array_map('intval', array_merge($ids, $legacyIds))));
            $ids = $ids ? array_map('intval', Db::name('user')->whereIn('uid', $ids)->where('is_del', 0)
                ->where('status', 1)->where('delete_time', null)->order('uid', 'asc')->lock(true)->column('uid')) : [];
            $visible = $ids ? array_map('intval', Db::name('store_user')->whereIn('uid', $ids)
                ->where('store_id', $source['store_id'])->where('status', 1)->lock(true)->column('uid')) : [];
            $ids = array_values(array_intersect($ids, $visible));
        } else {
            $ids = array_map('intval', $this->memberQuery($source)->order('u.uid', 'asc')->column('u.uid'));
        }
        if (count($ids) > 5000) throw new \RuntimeException('专属会员超过单次转移上限，请联系管理员分批处理');
        if (!$ids) return [];
        $query = Db::name('member_exclusive_service_selection')->whereIn('member_id', $ids)
            ->where('status', 1)->order('member_id asc,sort_order asc,id asc');
        if ($lock) $query->lock(true);
        $rows = $query->select()->toArray();
        $grouped = array_fill_keys($ids, []);
        foreach ($rows as $row) $grouped[(int)$row['member_id']][] = $row;
        $legacyQuery = Db::name('member_exclusive_service')->whereIn('member_id', $ids)->where('status', 1);
        if ($lock) $legacyQuery->lock(true);
        $legacy = $legacyQuery->select()->toArray();
        foreach ($legacy as $row) {
            if (!$grouped[(int)$row['member_id']]) $grouped[(int)$row['member_id']] = [$row];
        }
        return array_filter($grouped, static function (array $people) use ($source): bool {
            foreach ($people as $person) {
                if ((int)$person['staff_id'] === (int)$source['id'] && (int)$person['store_id'] === (int)$source['store_id']) return true;
            }
            return false;
        });
    }

    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** 替换源人员所在位置，保留其他人员顺序；已绑定的接收人不重复添加。 */
    private function replaceSource(array $people, int $sourceId, array $targets): array
    {
        $out = [];
        $existing = array_column($people, null, 'staff_id');
        foreach ($people as $person) {
            if ((int)$person['staff_id'] !== $sourceId) {
                $out[(int)$person['staff_id']] = $person;
                continue;
            }
            foreach ($targets as $target) {
                $id = (int)$target['staff_id'];
                // 已存在接收人仍留在原位置，也保留其原绑定快照和时间。
                if (!isset($existing[$id])) $out[$id] = $target;
            }
        }
        return array_values($out);
    }

    /** 仅更新本批次关系；保留行不重置绑定时间，移除行软失效并追加完整前后审计。 */
    private function writeSelection(int $memberId, array $before, array $after, array $audit, int $now): void
    {
        $all = Db::name('member_exclusive_service_selection')->where('member_id', $memberId)->lock(true)->select()->toArray();
        $byStaff = array_column($all, null, 'staff_id');
        $afterIds = array_map('intval', array_column($after, 'staff_id'));
        foreach ($all as $row) {
            if ((int)$row['status'] !== 1 || in_array((int)$row['staff_id'], $afterIds, true)) continue;
            $changed = Db::name('member_exclusive_service_selection')->where('id', $row['id'])->where('version', $row['version'])
                ->update(['status' => 0, 'version' => (int)$row['version'] + 1, 'updated_at' => $now]);
            if ($changed !== 1) throw new \RuntimeException('绑定版本已变化，本次未转移');
        }
        foreach ($after as $index => $person) {
            $id = (int)$person['staff_id'];
            $row = $byStaff[$id] ?? null;
            $values = ['employee_id' => (int)$person['employee_id'], 'store_id' => (int)$person['store_id'],
                'staff_name' => (string)$person['staff_name'], 'sort_order' => $index + 1, 'status' => 1];
            if ($row && (int)$row['status'] === 1 && (int)$row['sort_order'] === $index + 1) continue;
            if ($row) {
                $changed = Db::name('member_exclusive_service_selection')->where('id', $row['id'])->where('version', $row['version'])
                    ->update($values + ['version' => (int)$row['version'] + 1, 'updated_at' => $now]);
            } else {
                $changed = Db::name('member_exclusive_service_selection')->insert($values + ['member_id' => $memberId,
                    'staff_id' => $id, 'version' => 1, 'created_at' => (int)($person['bound_at'] ?? $now), 'updated_at' => $now]);
            }
            if ($changed !== 1) throw new \RuntimeException('绑定保存失败，本次未转移');
        }
        Db::name('member_exclusive_service_selection_change')->insert([
            'change_key' => 'exclusive_transfer:' . $memberId . ':' . $audit['request_token'],
            'member_id' => $memberId, 'previous_json' => json_encode(array_map('intval', array_column($before, 'staff_id'))),
            'current_json' => json_encode($afterIds), 'operator_id' => $audit['operator_id'], 'occurred_at' => $now,
        ]);
    }

    /** 同事务同步旧首位投影及旧审计，其他消费/销售关系完全不触碰。 */
    private function syncFirstPerson(int $memberId, array $person, array $audit, int $now): void
    {
        $current = Db::name('member_exclusive_service')->where('member_id', $memberId)->lock(true)->find();
        if ($current && (int)$current['status'] === 1 && (int)$current['staff_id'] === (int)$person['staff_id']) return;
        $storeName = (string)Db::name('system_store')->where('id', $person['store_id'])->value('name');
        $values = ['staff_id' => (int)$person['staff_id'], 'employee_id' => (int)$person['employee_id'],
            'store_id' => (int)$person['store_id'], 'staff_name' => (string)$person['staff_name'], 'store_name' => $storeName,
            'source_type' => 'staff_transfer', 'source_business_type' => 'member', 'source_business_id' => (string)$memberId,
            'reason' => '人员管理转移专属会员', 'status' => 1, 'bound_at' => (int)($person['bound_at'] ?? $person['created_at'] ?? $now),
            'operator_id' => $audit['operator_id'], 'operator_name' => $audit['operator_name'],
            'idempotency_key' => $audit['request_token'], 'updated_at' => $now];
        if ($current) {
            $changed = Db::name('member_exclusive_service')->where('id', $current['id'])->where('version', $current['version'])
                ->update($values + ['version' => (int)$current['version'] + 1]);
        } else {
            $changed = Db::name('member_exclusive_service')->insert($values + ['member_id' => $memberId, 'version' => 1, 'created_at' => $now]);
        }
        if ($changed !== 1) throw new \RuntimeException('首位服务人同步失败，本次未转移');
        $history = ['change_key' => 'exclusive_transfer:' . $memberId . ':' . $audit['request_token'], 'member_id' => $memberId,
            'source_type' => $values['source_type'], 'source_business_type' => 'member', 'source_business_id' => (string)$memberId,
            'reason' => $values['reason'], 'operator_id' => $audit['operator_id'], 'operator_name' => $audit['operator_name'],
            'idempotency_key' => $audit['request_token'], 'occurred_at' => $now, 'recorded_at' => $now, 'created_at' => $now];
        foreach (['staff_id', 'employee_id', 'store_id', 'staff_name', 'store_name'] as $key) {
            $history['previous_' . $key] = $current[$key] ?? (substr($key, -3) === '_id' ? 0 : '');
            $history['current_' . $key] = $values[$key];
        }
        Db::name('member_exclusive_service_change')->insert($history);
    }
}
