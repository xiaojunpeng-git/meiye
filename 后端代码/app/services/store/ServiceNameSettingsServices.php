<?php

namespace app\services\store;

use think\facade\Db;

/**
 * 门店服务名称基础资料的唯一读写入口。
 *
 * 该资料只保存名称；预约可引用其名称快照作为附加展示，不关联商品、计费或报表。每次写入
 * 使用门店范围、乐观版本、请求幂等键和同事务审计，保证刷新可恢复且不会
 * 因重复点击覆盖并发修改。
 */
final class ServiceNameSettingsServices
{
    private const NAME_TABLE = 'cashier_v3_service_name';
    private const SETTING_TABLE = 'cashier_v3_service_name_setting';
    private const AUDIT_TABLE = 'cashier_v3_service_name_audit';

    /** Public booking menu for one validated store, not management metadata.
     * Lock the switch and names in the booking transaction so concurrent
     * disabling cannot silently authorize a newly selected item.
     */
    public function reservationCatalog(int $storeId, bool $lock = false): array
    {
        $this->assertStore($storeId);
        if (!Db::query("SHOW TABLES LIKE 'eb_cashier_v3_service_name'")
            || !Db::query("SHOW TABLES LIKE 'eb_cashier_v3_service_name_setting'")) return ['enabled' => false, 'records' => []];
        $state = $this->switchState($storeId, $lock);
        if (!$state['enabled']) return ['enabled' => false, 'records' => []];
        $query = Db::name(self::NAME_TABLE)->where('store_id', $storeId)->where('is_enabled', 1);
        if ($lock) $query->lock(true);
        $rows = $this->rows($query->field('id,service_name,sort_order')->order('id asc')->select());
        usort($rows, static function (array $a, array $b): int { return ((int)$a['sort_order'] <=> (int)$b['sort_order']) ?: ((int)$a['id'] <=> (int)$b['id']); });
        return ['enabled' => true, 'records' => array_map(static function (array $row): array {
            return ['id' => (int)$row['id'], 'name' => (string)$row['service_name']];
        }, $rows)];
    }

    /** @return array{records:array,total:int,page:int,limit:int,switch:array} */
    public function page(int $storeId, array $filters): array
    {
        $this->assertStore($storeId);
        $page = max(1, min(100000, (int)($filters['page'] ?? 1)));
        $limit = max(1, min(100, (int)($filters['limit'] ?? 100)));
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $status = $filters['status'] ?? '';
        $query = Db::name(self::NAME_TABLE)->where('store_id', $storeId);
        if ($keyword !== '') {
            $query->whereLike('service_name', '%' . $keyword . '%');
        }
        if ($status !== '' && in_array((string)$status, ['0', '1'], true)) {
            $query->where('is_enabled', (int)$status);
        }
        $total = (int)(clone $query)->count();
        $rows = $this->rows($query
            ->field('id,service_name,sort_order,is_enabled,version,created_at,updated_at')
            ->order('sort_order asc,id asc')
            ->page($page, $limit)
            ->select());
        return [
            'records' => array_map([$this, 'record'], $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'switch' => $this->switchState($storeId),
        ];
    }

    /** @param array{id?:int,name?:string} $operator */
    public function create(
        int $storeId,
        array $operator,
        string $name,
        int $expectedVersion,
        string $requestToken
    ): array {
        $this->assertStore($storeId);
        if ($expectedVersion !== 0) {
            throw new \RuntimeException('新增服务名称的版本无效，请刷新后重试。');
        }
        $name = $this->serviceName($name);
        $requestToken = $this->requestToken($requestToken);
        $fingerprint = $this->fingerprint('create', 0, $name, 0);

        return Db::transaction(function () use ($storeId, $operator, $name, $requestToken, $fingerprint): array {
            $replay = $this->replayed($storeId, $requestToken, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $this->assertNameAvailable($storeId, $name, 0);
            $last = Db::name(self::NAME_TABLE)
                ->where('store_id', $storeId)
                ->order('sort_order desc,id desc')
                ->lock(true)
                ->find();
            $now = time();
            $id = (int)Db::name(self::NAME_TABLE)->insertGetId([
                'store_id' => $storeId,
                'service_name' => $name,
                'sort_order' => max(0, (int)($last['sort_order'] ?? 0)) + 1,
                'is_enabled' => 1,
                'version' => 1,
                'created_by' => (int)($operator['id'] ?? 0),
                'updated_by' => (int)($operator['id'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($id <= 0) {
                throw new \RuntimeException('服务名称新增失败，请稍后重试。');
            }
            $row = $this->lockedName($storeId, $id);
            $this->audit($storeId, $id, 'create', $requestToken, $fingerprint, 0, 1, [], $row, $operator, $now);
            return $this->record($row);
        });
    }

    /** @param array{id?:int,name?:string} $operator */
    public function setEnabled(
        int $storeId,
        array $operator,
        int $id,
        bool $enabled,
        int $expectedVersion,
        string $requestToken
    ): array {
        $this->assertStore($storeId);
        if ($expectedVersion <= 0) {
            throw new \RuntimeException('服务名称版本无效，请刷新后重试。');
        }
        $requestToken = $this->requestToken($requestToken);
        $fingerprint = $this->fingerprint('status', $id, $enabled ? '1' : '0', $expectedVersion);

        return Db::transaction(function () use ($storeId, $operator, $id, $enabled, $expectedVersion, $requestToken, $fingerprint): array {
            $replay = $this->replayed($storeId, $requestToken, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $old = $this->lockedName($storeId, $id);
            if ((int)$old['version'] !== $expectedVersion) {
                throw new \RuntimeException('服务名称已被其他人修改，请刷新后重试。');
            }
            $nextVersion = $expectedVersion + 1;
            $now = time();
            $affected = (int)Db::name(self::NAME_TABLE)
                ->where('id', $id)
                ->where('store_id', $storeId)
                ->where('version', $expectedVersion)
                ->update([
                    'is_enabled' => $enabled ? 1 : 0,
                    'version' => $nextVersion,
                    'updated_by' => (int)($operator['id'] ?? 0),
                    'updated_at' => $now,
                ]);
            if ($affected !== 1) {
                throw new \RuntimeException('服务名称状态已变化，请刷新后重试。');
            }
            $row = $this->lockedName($storeId, $id);
            $this->audit($storeId, $id, 'status', $requestToken, $fingerprint, $expectedVersion, $nextVersion, $old, $row, $operator, $now);
            return $this->record($row);
        });
    }

    /**
     * @param array<int,array{id?:int,expected_version?:int}> $items
     * @param array{id?:int,name?:string} $operator
     * @return array{saved:int}
     */
    public function reorder(int $storeId, array $operator, array $items, string $requestToken): array
    {
        $this->assertStore($storeId);
        $normalized = [];
        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            $version = (int)($item['expected_version'] ?? 0);
            if ($id <= 0 || $version <= 0 || isset($normalized[$id])) {
                throw new \RuntimeException('服务名称排序参数无效，请刷新后重试。');
            }
            $normalized[$id] = $version;
        }
        if (!$normalized) {
            throw new \RuntimeException('服务名称排序不能为空。');
        }
        $requestToken = $this->requestToken($requestToken);
        $orderedIds = array_keys($normalized);
        $fingerprint = hash('sha256', 'reorder|' . json_encode($normalized));

        return Db::transaction(function () use ($storeId, $operator, $normalized, $orderedIds, $requestToken, $fingerprint): array {
            $existingAudit = $this->existingAudit($storeId, $requestToken);
            if ($existingAudit !== null) {
                if (!hash_equals((string)$existingAudit['request_fingerprint'], $fingerprint)) {
                    throw new \RuntimeException('保存请求标识已用于其他内容，请重新提交。');
                }
                return ['saved' => count($orderedIds)];
            }
            $rows = $this->rows(Db::name(self::NAME_TABLE)
                ->where('store_id', $storeId)
                ->field('id,service_name,sort_order,is_enabled,version,created_at,updated_at')
                ->order('sort_order asc,id asc')
                ->lock(true)
                ->select());
            $actualIds = array_map(static function (array $row): int {
                return (int)$row['id'];
            }, $rows);
            $sortedActual = $actualIds;
            $sortedSubmitted = $orderedIds;
            sort($sortedActual, SORT_NUMERIC);
            sort($sortedSubmitted, SORT_NUMERIC);
            if ($sortedActual !== $sortedSubmitted) {
                throw new \RuntimeException('服务名称列表已变化，请刷新后重新排序。');
            }
            $rowsById = [];
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                if ((int)$row['version'] !== $normalized[$id]) {
                    throw new \RuntimeException('服务名称已被其他人修改，请刷新后重新排序。');
                }
                $rowsById[$id] = $row;
            }
            $now = time();
            foreach ($orderedIds as $index => $id) {
                $nextSort = $index + 1;
                $row = $rowsById[$id];
                if ((int)$row['sort_order'] === $nextSort) {
                    continue;
                }
                $affected = (int)Db::name(self::NAME_TABLE)
                    ->where('id', $id)
                    ->where('store_id', $storeId)
                    ->where('version', (int)$row['version'])
                    ->update([
                        'sort_order' => $nextSort,
                        'version' => (int)$row['version'] + 1,
                        'updated_by' => (int)($operator['id'] ?? 0),
                        'updated_at' => $now,
                    ]);
                if ($affected !== 1) {
                    throw new \RuntimeException('服务名称排序时数据已变化，请刷新后重试。');
                }
            }
            $newRows = $this->rows(Db::name(self::NAME_TABLE)
                ->where('store_id', $storeId)
                ->field('id,service_name,sort_order,is_enabled,version,created_at,updated_at')
                ->order('sort_order asc,id asc')
                ->lock(true)
                ->select());
            $this->audit($storeId, 0, 'sort', $requestToken, $fingerprint, 0, 0, $rows, $newRows, $operator, $now);
            return ['saved' => count($orderedIds)];
        });
    }

    /**
     * 保存当前门店的独立展示开关。只控制新预约的定制服务选择，不能改变
     * 已保存预约快照、收银、商品或服务名称行本身的启停状态。
     *
     * @param array{id?:int,name?:string} $operator
     */
    public function setSwitch(
        int $storeId,
        array $operator,
        bool $enabled,
        int $expectedVersion,
        string $requestToken
    ): array {
        $this->assertStore($storeId);
        if ($expectedVersion < 0) {
            throw new \RuntimeException('服务开关版本无效，请刷新后重试。');
        }
        $requestToken = $this->requestToken($requestToken);
        $fingerprint = $this->fingerprint('switch', 0, $enabled ? '1' : '0', $expectedVersion);

        return Db::transaction(function () use ($storeId, $operator, $enabled, $expectedVersion, $requestToken, $fingerprint): array {
            $existingAudit = $this->existingAudit($storeId, $requestToken);
            if ($existingAudit !== null) {
                if (!hash_equals((string)$existingAudit['request_fingerprint'], $fingerprint)) {
                    throw new \RuntimeException('保存请求标识已用于其他内容，请重新提交。');
                }
                // 重放必须返回该请求首次提交时的快照，不能被后续开关操作改写结果。
                $snapshot = json_decode((string)($existingAudit['new_snapshot_json'] ?? ''), true);
                if (!is_array($snapshot) || !$snapshot) {
                    throw new \RuntimeException('服务开关历史结果无法读取，请刷新后重试。');
                }
                return $this->switchRecord($snapshot);
            }
            $old = Db::name(self::SETTING_TABLE)
                ->where('store_id', $storeId)
                ->lock(true)
                ->find();
            $old = is_array($old) && $old ? $old : [];
            $currentVersion = (int)($old['version'] ?? 0);
            if ($currentVersion !== $expectedVersion) {
                throw new \RuntimeException('服务开关已被其他人修改，请刷新后重试。');
            }
            $nextVersion = $currentVersion + 1;
            $now = time();
            if (!$old) {
                Db::name(self::SETTING_TABLE)->insert([
                    'store_id' => $storeId,
                    'is_enabled' => $enabled ? 1 : 0,
                    'version' => $nextVersion,
                    'created_by' => (int)($operator['id'] ?? 0),
                    'updated_by' => (int)($operator['id'] ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $affected = (int)Db::name(self::SETTING_TABLE)
                    ->where('store_id', $storeId)
                    ->where('version', $currentVersion)
                    ->update([
                        'is_enabled' => $enabled ? 1 : 0,
                        'version' => $nextVersion,
                        'updated_by' => (int)($operator['id'] ?? 0),
                        'updated_at' => $now,
                    ]);
                if ($affected !== 1) {
                    throw new \RuntimeException('服务开关已变化，请刷新后重试。');
                }
            }
            $new = Db::name(self::SETTING_TABLE)
                ->where('store_id', $storeId)
                ->lock(true)
                ->find();
            if (!is_array($new) || !$new) {
                throw new \RuntimeException('服务开关未能保存，请稍后重试。');
            }
            $this->audit($storeId, 0, 'switch', $requestToken, $fingerprint, $currentVersion, $nextVersion, $old, $new, $operator, $now);
            return $this->switchRecord($new);
        });
    }

    /** @param array{id?:int,name?:string} $operator */
    public function update(
        int $storeId,
        array $operator,
        int $id,
        string $name,
        int $expectedVersion,
        string $requestToken
    ): array {
        $this->assertStore($storeId);
        if ($expectedVersion <= 0) {
            throw new \RuntimeException('服务名称版本无效，请刷新后重试。');
        }
        $name = $this->serviceName($name);
        $requestToken = $this->requestToken($requestToken);
        $fingerprint = $this->fingerprint('update', $id, $name, $expectedVersion);

        return Db::transaction(function () use ($storeId, $operator, $id, $name, $expectedVersion, $requestToken, $fingerprint): array {
            $replay = $this->replayed($storeId, $requestToken, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $old = $this->lockedName($storeId, $id);
            if ((int)$old['version'] !== $expectedVersion) {
                throw new \RuntimeException('服务名称已被其他人修改，请刷新后重新编辑。');
            }
            $this->assertNameAvailable($storeId, $name, $id);
            $nextVersion = $expectedVersion + 1;
            $now = time();
            $affected = (int)Db::name(self::NAME_TABLE)
                ->where('id', $id)
                ->where('store_id', $storeId)
                ->where('version', $expectedVersion)
                ->update([
                    'service_name' => $name,
                    'version' => $nextVersion,
                    'updated_by' => (int)($operator['id'] ?? 0),
                    'updated_at' => $now,
                ]);
            if ($affected !== 1) {
                throw new \RuntimeException('服务名称已变化，请刷新后重试。');
            }
            $row = $this->lockedName($storeId, $id);
            $this->audit($storeId, $id, 'update', $requestToken, $fingerprint, $expectedVersion, $nextVersion, $old, $row, $operator, $now);
            return $this->record($row);
        });
    }

    private function assertStore(int $storeId): void
    {
        if ($storeId <= 0) {
            throw new \RuntimeException('缺少会话门店，请重新登录。');
        }
    }

    private function serviceName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('请输入服务名称。');
        }
        if (!mb_check_encoding($name, 'UTF-8')) {
            throw new \RuntimeException('服务名称编码无效，请重新输入。');
        }
        if (mb_strlen($name) > 64) {
            throw new \RuntimeException('服务名称不能超过64个字符。');
        }
        return $name;
    }

    private function requestToken(string $token): string
    {
        $token = trim($token);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,95}$/D', $token)) {
            throw new \RuntimeException('保存请求标识无效，请重新提交。');
        }
        return $token;
    }

    private function fingerprint(string $action, int $id, string $name, int $expectedVersion): string
    {
        return hash('sha256', implode('|', [$action, $id, $expectedVersion, $name]));
    }

    private function replayed(int $storeId, string $token, string $fingerprint): ?array
    {
        $audit = $this->existingAudit($storeId, $token);
        if ($audit === null) {
            return null;
        }
        if (!hash_equals((string)$audit['request_fingerprint'], $fingerprint)) {
            throw new \RuntimeException('保存请求标识已用于其他内容，请重新提交。');
        }
        return $this->record($this->lockedName($storeId, (int)$audit['service_name_id']));
    }

    private function existingAudit(int $storeId, string $token): ?array
    {
        $audit = Db::name(self::AUDIT_TABLE)
            ->where('store_id', $storeId)
            ->where('request_token', $token)
            ->lock(true)
            ->find();
        return is_array($audit) && $audit ? $audit : null;
    }

    private function assertNameAvailable(int $storeId, string $name, int $exceptId): void
    {
        $query = Db::name(self::NAME_TABLE)
            ->where('store_id', $storeId)
            ->where('service_name', $name);
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->lock(true)->find()) {
            throw new \RuntimeException('当前门店已存在相同的服务名称。');
        }
    }

    private function lockedName(int $storeId, int $id): array
    {
        if ($id <= 0) {
            throw new \RuntimeException('服务名称标识无效。');
        }
        $row = Db::name(self::NAME_TABLE)
            ->where('id', $id)
            ->where('store_id', $storeId)
            ->lock(true)
            ->find();
        if (!is_array($row) || !$row) {
            throw new \RuntimeException('服务名称不存在或不属于当前门店。');
        }
        return $row;
    }

    /** @param array{id?:int,name?:string} $operator */
    private function audit(
        int $storeId,
        int $id,
        string $action,
        string $token,
        string $fingerprint,
        int $expectedVersion,
        int $resultVersion,
        array $old,
        array $new,
        array $operator,
        int $now
    ): void {
        Db::name(self::AUDIT_TABLE)->insert([
            'store_id' => $storeId,
            'service_name_id' => $id,
            'action' => $action,
            'request_token' => $token,
            'request_fingerprint' => $fingerprint,
            'expected_version' => $expectedVersion,
            'result_version' => $resultVersion,
            'old_snapshot_json' => json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'new_snapshot_json' => json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'operator_id' => (int)($operator['id'] ?? 0),
            'operator_name_snapshot' => trim((string)($operator['name'] ?? '')),
            'created_at' => $now,
        ]);
    }

    private function record(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['service_name'] ?? ''),
            'sort' => (int)($row['sort_order'] ?? 0),
            'enabled' => (int)($row['is_enabled'] ?? 0) === 1,
            'version' => (int)($row['version'] ?? 0),
            'createdAt' => (int)($row['created_at'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$row['created_at']) : '',
            'updatedAt' => (int)($row['updated_at'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$row['updated_at']) : '',
        ];
    }

    private function switchState(int $storeId, bool $lock = false): array
    {
        $query = Db::name(self::SETTING_TABLE)->where('store_id', $storeId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row) || !$row) {
            return ['enabled' => true, 'version' => 0];
        }
        return $this->switchRecord($row);
    }

    private function switchRecord(array $row): array
    {
        return [
            'enabled' => (int)($row['is_enabled'] ?? 0) === 1,
            'version' => (int)($row['version'] ?? 0),
        ];
    }

    private function rows($rows): array
    {
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        return is_array($rows) ? array_values($rows) : [];
    }
}
