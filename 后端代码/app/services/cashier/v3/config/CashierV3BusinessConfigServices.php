<?php
declare(strict_types=1);

namespace app\services\cashier\v3\config;

use app\services\BaseServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 收银 V3 的业务来源与记账方式权威配置。
 *
 * canonical code/实体 ID 用于关联，显示名称只在业务发生时解析为快照。
 */
final class CashierV3BusinessConfigServices extends BaseServices
{
    public const SOURCE_ATTRIBUTION_TYPES = [
        'guide' => '导购',
        'beautician' => '美容师',
        'coach' => '拓客教练',
        'external' => '外接地推',
        'online' => '线上',
        'referral' => '老客转介绍',
        'other' => '其他',
    ];
    public const PAYMENT_METHODS = [
        'unionpay' => '银联',
        'wechat' => '微信',
        'alipay' => '支付宝',
        'dianping_voucher' => '大众验券',
        'douyin_voucher' => '抖音验券',
        'partner_collection' => '合作方收款',
        'other_collection' => '其他收款',
        'old_card_entry' => '旧卡录入',
    ];

    public function sourceTree(bool $enabledOnly = false): array
    {
        $query = Db::name('cashier_v3_business_source')->order('sort asc,id asc');
        if ($enabledOnly) {
            $query->where('status', 1);
        }
        $rows = $query->select()->toArray();
        $roots = [];
        $children = [];
        foreach ($rows as $row) {
            $item = $this->sourceView($row);
            if ((int)$row['parent_id'] === 0) {
                $item['children'] = [];
                $roots[(int)$row['id']] = $item;
            } else {
                $children[(int)$row['parent_id']][] = $item;
            }
        }
        foreach ($roots as $id => &$root) {
            $root['children'] = $children[$id] ?? [];
        }
        unset($root);
        return array_values($roots);
    }

    public function accountingMethods(bool $enabledOnly = false): array
    {
        $query = Db::name('cashier_v3_payment_method_config')->order('sort asc,id asc');
        if ($enabledOnly) {
            $query->where('status', 1);
        }
        $rows = $query->select()->toArray();
        $methods = [];
        foreach ($rows as $row) {
            $methods[] = $this->paymentView($row);
        }
        return $methods;
    }

    public function checkoutCatalog(): array
    {
        return [
            'sources' => $this->sourceTree(true),
            'accountingMethods' => $this->accountingMethods(true),
            'sourceMaxLevel' => 2,
            'configVersion' => $this->configVersion(),
        ];
    }

    public function createSource(array $input, int $adminId): array
    {
        $name = $this->normalizeName($input['name'] ?? '');
        $parentId = max(0, (int)($input['parentId'] ?? $input['parent_id'] ?? 0));
        $status = $this->normalizeStatus($input['status'] ?? 1);
        $sort = $this->normalizeSort($input['sort'] ?? 0);
        $requireSecondary = $this->normalizeStatus(
            $input['requireSecondary'] ?? $input['require_secondary'] ?? 0
        );
        $attributionType = $this->normalizeSourceAttributionType(
            $input['attributionType'] ?? $input['attribution_type'] ?? 'other'
        );
        $idempotencyKey = $this->requireIdempotencyKey($input);

        return Db::transaction(function () use (
            $name, $parentId, $status, $sort, $requireSecondary, $attributionType, $idempotencyKey, $adminId
        ): array {
            $replayed = $this->replayedResult('source_create', $idempotencyKey);
            if ($replayed !== null) {
                return $replayed;
            }
            if ($parentId > 0) {
                $parent = Db::name('cashier_v3_business_source')->where('id', $parentId)->lock(true)->find();
                if (!$parent || (int)$parent['parent_id'] !== 0) {
                    throw new ValidateException('上级来源无效，来源最多支持两级');
                }
                $requireSecondary = 0;
            } elseif ($requireSecondary === 1) {
                throw new ValidateException('请先新增二级来源，再开启强制选择二级来源');
            }
            $this->assertUniqueSourceName($parentId, $name, 0);
            $now = time();
            $id = (int)Db::name('cashier_v3_business_source')->insertGetId([
                'parent_id' => $parentId,
                'name' => $name,
                'status' => $status,
                'sort' => $sort,
                'require_secondary' => $requireSecondary,
                'attribution_type' => $attributionType,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->writeAudit('source_create', 'source', (string)$id, $idempotencyKey, $adminId, [], $this->sourceById($id));
            return $this->sourceById($id);
        });
    }

    public function updateSource(int $id, array $input, int $adminId): array
    {
        if ($id <= 0) {
            throw new ValidateException('来源不存在');
        }
        $idempotencyKey = $this->requireIdempotencyKey($input);
        $expectedVersion = (int)($input['expectedVersion'] ?? $input['expected_version'] ?? 0);
        if ($expectedVersion <= 0) {
            throw new ValidateException('缺少有效的来源版本');
        }

        return Db::transaction(function () use ($id, $input, $adminId, $idempotencyKey, $expectedVersion): array {
            $replayed = $this->replayedResult('source_update', $idempotencyKey);
            if ($replayed !== null) {
                return $replayed;
            }
            $row = Db::name('cashier_v3_business_source')->where('id', $id)->lock(true)->find();
            if (!$row) {
                throw new ValidateException('来源不存在');
            }
            if ((int)$row['version'] !== $expectedVersion) {
                throw new ValidateException('来源设置已更新，请刷新后重试');
            }
            $before = $this->sourceView($row);
            $requestedParentId = (int)($input['parentId'] ?? $input['parent_id'] ?? $row['parent_id']);
            if ($requestedParentId !== (int)$row['parent_id']) {
                throw new ValidateException('已建立的来源不能变更层级或上级，请停用后新增');
            }
            $name = $this->normalizeName($input['name'] ?? $row['name']);
            $status = $this->normalizeStatus($input['status'] ?? $row['status']);
            $sort = $this->normalizeSort($input['sort'] ?? $row['sort']);
            $requireSecondary = $this->normalizeStatus(
                $input['requireSecondary'] ?? $input['require_secondary'] ?? $row['require_secondary']
            );
            $attributionType = $this->normalizeSourceAttributionType(
                $input['attributionType'] ?? $input['attribution_type'] ?? $row['attribution_type'] ?? 'other'
            );
            $parentId = (int)$row['parent_id'];
            if ($parentId > 0) {
                $requireSecondary = 0;
            } elseif ($requireSecondary === 1 && !$this->hasEnabledChild($id)) {
                throw new ValidateException('至少启用一个二级来源后，才能要求收银台选择二级来源');
            }
            if ($parentId > 0 && $status === 0) {
                $parent = Db::name('cashier_v3_business_source')->where('id', $parentId)->lock(true)->find();
                if ($parent && (int)$parent['status'] === 1 && (int)$parent['require_secondary'] === 1
                    && !$this->hasOtherEnabledChild($parentId, $id)) {
                    throw new ValidateException('该一级来源要求选择二级来源，不能停用最后一个有效二级来源');
                }
            }
            $this->assertUniqueSourceName($parentId, $name, $id);
            Db::name('cashier_v3_business_source')->where('id', $id)->update([
                'name' => $name,
                'status' => $status,
                'sort' => $sort,
                'require_secondary' => $requireSecondary,
                'attribution_type' => $attributionType,
                'version' => $expectedVersion + 1,
                'updated_at' => time(),
            ]);
            $after = $this->sourceById($id);
            $this->writeAudit('source_update', 'source', (string)$id, $idempotencyKey, $adminId, $before, $after);
            return $after;
        });
    }

    public function updateAccountingMethod(string $code, array $input, int $adminId): array
    {
        $code = trim($code);
        $this->assertCanonicalPaymentCode($code);
        if (isset($input['code']) && trim((string)$input['code']) !== $code) {
            throw new ValidateException('系统记账代码不可修改');
        }
        $idempotencyKey = $this->requireIdempotencyKey($input);
        $expectedVersion = (int)($input['expectedVersion'] ?? $input['expected_version'] ?? 0);
        if ($expectedVersion <= 0) {
            throw new ValidateException('缺少有效的记账设置版本');
        }

        return Db::transaction(function () use ($code, $input, $adminId, $idempotencyKey, $expectedVersion): array {
            $replayed = $this->replayedResult('payment_update', $idempotencyKey);
            if ($replayed !== null) {
                return $replayed;
            }
            $row = Db::name('cashier_v3_payment_method_config')->where('code', $code)->lock(true)->find();
            if (!$row || !isset(self::PAYMENT_METHODS[$code])) {
                throw new ValidateException('记账方式不存在');
            }
            if ((int)$row['version'] !== $expectedVersion) {
                throw new ValidateException('记账设置已更新，请刷新后重试');
            }
            $before = $this->paymentView($row);
            $displayName = $this->normalizeName($input['displayName'] ?? $input['display_name'] ?? $row['display_name']);
            $status = $this->normalizeStatus($input['status'] ?? $row['status']);
            $sort = $this->normalizeSort($input['sort'] ?? $row['sort']);
            if ($status === 0 && (int)$row['status'] === 1) {
                $activeCount = (int)Db::name('cashier_v3_payment_method_config')
                    ->where('status', 1)
                    ->where('code', '<>', 'old_card_entry')
                    ->count();
                if ($activeCount <= 1) {
                    throw new ValidateException('至少保留一种启用的记账收款方式');
                }
            }
            Db::name('cashier_v3_payment_method_config')->where('code', $code)->update([
                'display_name' => $displayName,
                'status' => $status,
                'sort' => $sort,
                'version' => $expectedVersion + 1,
                'updated_at' => time(),
            ]);
            $after = $this->paymentByCode($code);
            $this->writeAudit('payment_update', 'payment_method', $code, $idempotencyKey, $adminId, $before, $after);
            return $after;
        });
    }

    /** 恢复固定目录的默认显示名称，保留管理员设置的启停和排序。 */
    public function restoreAccountingDefaults(array $input, int $adminId): array
    {
        $idempotencyKey = $this->requireIdempotencyKey($input);
        return Db::transaction(function () use ($idempotencyKey, $adminId): array {
            $replayed = $this->replayedResult('payment_restore_defaults', $idempotencyKey);
            if ($replayed !== null) {
                return $replayed;
            }
            $before = $this->accountingMethods(false);
            $now = time();
            foreach (self::PAYMENT_METHODS as $code => $defaultName) {
                Db::name('cashier_v3_payment_method_config')->where('code', $code)->update([
                    'display_name' => $defaultName,
                    'version' => Db::raw('version + 1'),
                    'updated_at' => $now,
                ]);
            }
            $after = $this->accountingMethods(false);
            $this->writeAudit('payment_restore_defaults', 'payment_method_set', 'all', $idempotencyKey, $adminId, $before, $after);
            return $after;
        });
    }

    /**
     * 在结账事务内解析并校验来源，调用方保存返回的名称快照。
     */
    public function resolveSourceSnapshot(int $primaryId, int $secondaryId = 0, bool $forUpdate = false): array
    {
        if ($primaryId <= 0) {
            throw new ValidateException('请选择一级来源');
        }
        $query = Db::name('cashier_v3_business_source')->where('id', $primaryId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $primary = $query->find();
        if (!$primary || (int)$primary['parent_id'] !== 0 || (int)$primary['status'] !== 1) {
            throw new ValidateException('一级来源已停用或不存在，请重新选择');
        }
        $secondary = null;
        if ($secondaryId > 0) {
            $secondaryQuery = Db::name('cashier_v3_business_source')->where('id', $secondaryId);
            if ($forUpdate) {
                $secondaryQuery->lock(true);
            }
            $secondary = $secondaryQuery->find();
            if (!$secondary || (int)$secondary['parent_id'] !== $primaryId || (int)$secondary['status'] !== 1) {
                throw new ValidateException('二级来源已停用、不存在或不属于所选一级来源');
            }
        } elseif ((int)$primary['require_secondary'] === 1) {
            throw new ValidateException('请选择二级来源');
        }
        return [
            'primarySourceId' => (int)$primary['id'],
            'primarySourceNameSnapshot' => (string)$primary['name'],
            'secondarySourceId' => $secondary ? (int)$secondary['id'] : 0,
            'secondarySourceNameSnapshot' => $secondary ? (string)$secondary['name'] : '',
            'displayNameSnapshot' => $secondary
                ? (string)$primary['name'] . ' / ' . (string)$secondary['name']
                : (string)$primary['name'],
            'primarySourceVersion' => (int)$primary['version'],
            'secondarySourceVersion' => $secondary ? (int)$secondary['version'] : 0,
            'attributionTypeSnapshot' => (string)($secondary['attribution_type'] ?? $primary['attribution_type'] ?? 'other'),
        ];
    }

    /** 在结账事务内解析记账名称；canonical code 永远不从显示名称反推。 */
    public function resolveAccountingMethodSnapshot(string $code, bool $forUpdate = false): array
    {
        $this->assertCanonicalPaymentCode($code);
        $query = Db::name('cashier_v3_payment_method_config')->where('code', $code);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!$row || (int)$row['status'] !== 1) {
            throw new ValidateException('记账方式已停用或不存在，请重新选择');
        }
        return [
            'code' => $code,
            'displayNameSnapshot' => (string)$row['display_name'],
            'configVersion' => (int)$row['version'],
        ];
    }

    private function sourceById(int $id): array
    {
        $row = Db::name('cashier_v3_business_source')->where('id', $id)->find();
        if (!$row) {
            throw new ValidateException('来源不存在');
        }
        return $this->sourceView($row);
    }

    private function paymentByCode(string $code): array
    {
        $row = Db::name('cashier_v3_payment_method_config')->where('code', $code)->find();
        if (!$row) {
            throw new ValidateException('记账方式不存在');
        }
        return $this->paymentView($row);
    }

    private function sourceView(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'parentId' => (int)$row['parent_id'],
            'status' => (int)$row['status'],
            'sort' => (int)$row['sort'],
            'requireSecondary' => (int)$row['require_secondary'],
            'attributionType' => (string)($row['attribution_type'] ?? 'other'),
            'version' => (int)$row['version'],
        ];
    }

    private function paymentView(array $row): array
    {
        $code = (string)$row['code'];
        return [
            'code' => $code,
            'defaultName' => self::PAYMENT_METHODS[$code] ?? (string)$row['default_name'],
            'displayName' => (string)$row['display_name'],
            'status' => (int)$row['status'],
            'sort' => (int)$row['sort'],
            'version' => (int)$row['version'],
        ];
    }

    private function normalizeName($value): string
    {
        $name = trim((string)$value);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new ValidateException('名称不能为空且不能超过64个字符');
        }
        return $name;
    }

    private function normalizeStatus($value): int
    {
        if ($value === true || $value === 1 || $value === '1') {
            return 1;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return 0;
        }
        throw new ValidateException('状态参数无效');
    }

    private function normalizeSort($value): int
    {
        $sort = (int)$value;
        if ($sort < 0 || $sort > 65535) {
            throw new ValidateException('排序必须在0到65535之间');
        }
        return $sort;
    }

    private function normalizeSourceAttributionType($value): string
    {
        $type = trim((string)$value);
        if (!isset(self::SOURCE_ATTRIBUTION_TYPES[$type])) {
            throw new ValidateException('来源类型无效');
        }
        return $type;
    }

    private function requireIdempotencyKey(array $input): string
    {
        $key = trim((string)($input['idempotencyKey'] ?? $input['idempotency_key'] ?? ''));
        if ($key === '' || strlen($key) > 128) {
            throw new ValidateException('缺少有效的幂等键');
        }
        return $key;
    }

    private function assertCanonicalPaymentCode(string $code): void
    {
        if (!isset(self::PAYMENT_METHODS[$code])) {
            throw new ValidateException('记账方式代码无效');
        }
    }

    private function assertUniqueSourceName(int $parentId, string $name, int $excludeId): void
    {
        $query = Db::name('cashier_v3_business_source')
            ->where('parent_id', $parentId)
            ->where('name', $name);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }
        if ($query->count() > 0) {
            throw new ValidateException('同级来源名称已存在');
        }
    }

    private function hasEnabledChild(int $parentId): bool
    {
        return Db::name('cashier_v3_business_source')
            ->where('parent_id', $parentId)->where('status', 1)->count() > 0;
    }

    private function hasOtherEnabledChild(int $parentId, int $excludeId): bool
    {
        return Db::name('cashier_v3_business_source')->where('parent_id', $parentId)
            ->where('id', '<>', $excludeId)->where('status', 1)->count() > 0;
    }

    /** 幂等重放返回第一次确定的结果；同一幂等键不得跨动作复用。 */
    private function replayedResult(string $action, string $idempotencyKey): ?array
    {
        $row = Db::name('cashier_v3_business_config_audit')
            ->where('idempotency_key', $idempotencyKey)->find();
        if (!$row) {
            return null;
        }
        if ((string)$row['action'] !== $action) {
            throw new ValidateException('幂等键已被其他配置操作使用');
        }
        $result = json_decode((string)$row['after_snapshot_json'], true);
        if (!is_array($result)) {
            throw new ValidateException('配置操作回执损坏，请联系管理员');
        }
        return $result;
    }

    private function writeAudit(
        string $action,
        string $entityType,
        string $entityKey,
        string $idempotencyKey,
        int $adminId,
        array $before,
        array $after
    ): void {
        Db::name('cashier_v3_business_config_audit')->insert([
            'action' => $action,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'idempotency_key' => $idempotencyKey,
            'operator_id' => max(0, $adminId),
            'before_snapshot_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after_snapshot_json' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => time(),
        ]);
    }

    private function configVersion(): string
    {
        $sourceVersion = (int)Db::name('cashier_v3_business_source')->sum('version');
        $paymentVersion = (int)Db::name('cashier_v3_payment_method_config')->sum('version');
        $sourceUpdatedAt = (int)Db::name('cashier_v3_business_source')->max('updated_at');
        $paymentUpdatedAt = (int)Db::name('cashier_v3_payment_method_config')->max('updated_at');
        return hash('sha256', implode(':', [
            $sourceVersion,
            $paymentVersion,
            $sourceUpdatedAt,
            $paymentUpdatedAt,
            (int)Db::name('cashier_v3_business_source')->count(),
            (int)Db::name('cashier_v3_payment_method_config')->count(),
        ]));
    }
}
