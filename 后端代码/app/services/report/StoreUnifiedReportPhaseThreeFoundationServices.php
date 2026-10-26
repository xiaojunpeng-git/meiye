<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Shared persisted dimensions used by the six platform-only Phase 3 reports. */
final class StoreUnifiedReportPhaseThreeFoundationServices
{
    public const ORGANIZATION_DIMENSION_TABLE = 'cashier_v3_report_organization_dimension';
    public const CONSUMPTION_TIER_TABLE = 'cashier_v3_report_consumption_tier';
    public const CONSUMPTION_TIER_AUDIT_TABLE = 'cashier_v3_report_consumption_tier_audit';
    public const MEMBER_ORIGIN_TABLE = 'cashier_v3_report_member_origin_evidence';
    public const MEMBER_ORIGIN_AUDIT_TABLE = 'cashier_v3_report_member_origin_evidence_audit';
    public const MEMBER_STORE_PERIOD_TABLE = 'cashier_v3_report_member_store_assignment_period';
    public const V3_CUTOVER_AT = 1786291200; // 2026-08-10 00:00:00 Asia/Shanghai

    private const DIMENSION_CODES = ['company', 'city_manager'];
    private const ORIGIN_TYPES = ['SYSTEM_CREATED', 'IMPORTED', 'PRE_CUTOVER', 'UNKNOWN'];

    /** @var array<string,array<int,array<string,mixed>>> */
    private $organizationDimensionCache = [];

    /** @return array<int,array<string,mixed>> */
    public function organizationDimensions(string $tenantId, string $dimensionCode, string $asOfDate): array
    {
        $tenantId = $this->token($tenantId, 'tenant_id', 32);
        $dimensionCode = $this->dimensionCode($dimensionCode);
        $asOfDate = $this->date($asOfDate, 'as_of_date');
        $cacheKey = $tenantId . '|' . $dimensionCode . '|' . $asOfDate;
        if (isset($this->organizationDimensionCache[$cacheKey])) {
            return $this->organizationDimensionCache[$cacheKey];
        }
        return $this->organizationDimensionCache[$cacheKey] = Db::name(self::ORGANIZATION_DIMENSION_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('dimension_code', $dimensionCode)
            ->where('enabled', 1)
            ->where('valid_from', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('valid_to')->whereOr('valid_to', '>=', $asOfDate);
            })
            ->order('display_order', 'asc')->order('id', 'asc')->select()->toArray();
    }

    /**
     * Resolve the nearest configured ancestor. organizationPathIds must be
     * root-to-leaf and come from the persisted business fact, not the client.
     */
    public function resolveOrganizationDimension(
        string $tenantId,
        array $organizationPathIds,
        string $dimensionCode,
        string $asOfDate
    ): ?array {
        $path = [];
        foreach ($organizationPathIds as $organizationId) {
            $value = trim((string)$organizationId);
            if ($value !== '' && !in_array($value, $path, true)) {
                $path[] = $value;
            }
        }
        if ($path === []) {
            return null;
        }
        $byOrganization = [];
        foreach ($this->organizationDimensions($tenantId, $dimensionCode, $asOfDate) as $row) {
            $byOrganization[(string)$row['organization_id']] = $row;
        }
        foreach (array_reverse($path) as $organizationId) {
            if (isset($byOrganization[$organizationId])) {
                return $byOrganization[$organizationId];
            }
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public function consumptionTiers(string $tenantId, bool $enabledOnly = true): array
    {
        $query = Db::name(self::CONSUMPTION_TIER_TABLE)
            ->where('tenant_id', $this->token($tenantId, 'tenant_id', 32))
            ->whereNull('deleted_at');
        if ($enabledOnly) {
            $query->where('enabled', 1);
        }
        return $query->order('sort_order', 'asc')->order('id', 'asc')->select()->toArray();
    }

    /** Net negative consumption matches the lowest tier as zero without changing the reported amount. */
    public function matchConsumptionTier(string $tenantId, int $netCashCents): ?array
    {
        $matchAmount = max(0, $netCashCents);
        foreach ($this->consumptionTiers($tenantId, true) as $tier) {
            $lower = (int)$tier['lower_bound_cents'];
            $upper = $tier['upper_bound_cents'] === null ? null : (int)$tier['upper_bound_cents'];
            if ($matchAmount >= $lower && ($upper === null || $matchAmount < $upper)) {
                return $tier;
            }
        }
        return null;
    }

    /** Create/update/disable a tier with optimistic concurrency and immutable audit. */
    public function saveConsumptionTier(array $context, array $payload): array
    {
        $tenantId = $this->token($context['tenant_id'] ?? '', 'tenant_id', 32);
        $tierCode = $this->token($payload['tier_code'] ?? '', 'tier_code', 64);
        $idempotencyKey = $this->token($payload['idempotency_key'] ?? '', 'idempotency_key', 128);
        $tierName = trim((string)($payload['tier_name'] ?? ''));
        if ($tierName === '' || mb_strlen($tierName, 'UTF-8') > 128) {
            throw new \InvalidArgumentException('消费分级名称不能为空且不能超过 128 个字符');
        }
        $lower = (int)($payload['lower_bound_cents'] ?? -1);
        $upper = array_key_exists('upper_bound_cents', $payload) && $payload['upper_bound_cents'] !== null
            ? (int)$payload['upper_bound_cents'] : null;
        $sortOrder = (int)($payload['sort_order'] ?? 0);
        $enabled = (int)($payload['enabled'] ?? 1);
        $deleted = !empty($payload['delete']);
        $expectedVersion = (int)($payload['expected_version'] ?? 0);
        if ($lower < 0 || ($upper !== null && $upper <= $lower) || $sortOrder < 0 || !in_array($enabled, [0, 1], true)) {
            throw new \InvalidArgumentException('消费分级区间、排序或状态无效');
        }
        if ($deleted && $enabled !== 0) throw new \InvalidArgumentException('删除消费分级必须同时停用');
        $operatorId = (int)($context['operator_id'] ?? $context['admin_id'] ?? 0);
        $operatorName = mb_substr(trim((string)($context['operator_name'] ?? $context['admin_name'] ?? '')), 0, 128);

        return Db::transaction(function () use ($tenantId, $tierCode, $idempotencyKey, $tierName, $lower, $upper, $sortOrder, $enabled, $deleted, $expectedVersion, $operatorId, $operatorName): array {
            $audit = Db::name(self::CONSUMPTION_TIER_AUDIT_TABLE)
                ->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->lock(true)->find();
            if ($audit) {
                if ((string)$audit['tier_code'] !== $tierCode) {
                    throw new \InvalidArgumentException('幂等标识已用于其他消费分级');
                }
                $replayed = Db::name(self::CONSUMPTION_TIER_TABLE)->where('id', (int)$audit['tier_id'])->find();
                if (!$replayed) {
                    throw new \RuntimeException('消费分级审计存在但配置缺失');
                }
                $replayed['replayed'] = true;
                return $replayed;
            }

            $existing = Db::name(self::CONSUMPTION_TIER_TABLE)
                ->where('tenant_id', $tenantId)->where('tier_code', $tierCode)->lock(true)->find();
            if ($existing && $existing['deleted_at'] !== null) {
                throw new \InvalidArgumentException('已删除的消费分级不能重新使用');
            }
            $before = $existing ?: [];
            $now = time();
            if ($existing) {
                if ($expectedVersion <= 0 || $expectedVersion !== (int)$existing['version']) {
                    throw new \InvalidArgumentException('消费分级已更新，请刷新后再保存');
                }
                $version = (int)$existing['version'] + 1;
                Db::name(self::CONSUMPTION_TIER_TABLE)->where('id', (int)$existing['id'])->update([
                    'tier_name' => $tierName, 'lower_bound_cents' => $lower, 'upper_bound_cents' => $upper,
                    'sort_order' => $sortOrder, 'enabled' => $enabled, 'version' => $version,
                    'deleted_at' => $deleted ? $now : null,
                    'updated_by' => $operatorId, 'updated_by_name_snapshot' => $operatorName, 'updated_at' => $now,
                ]);
                $tierId = (int)$existing['id'];
                $action = $deleted ? 'DELETED' : ($enabled ? 'UPDATED' : 'DISABLED');
            } else {
                if ($expectedVersion !== 0) {
                    throw new \InvalidArgumentException('新增消费分级的版本必须为 0');
                }
                $version = 1;
                $tierId = (int)Db::name(self::CONSUMPTION_TIER_TABLE)->insertGetId([
                    'tenant_id' => $tenantId, 'tier_code' => $tierCode, 'tier_name' => $tierName,
                    'lower_bound_cents' => $lower, 'upper_bound_cents' => $upper, 'sort_order' => $sortOrder,
                    'enabled' => $enabled, 'version' => 1, 'created_by' => $operatorId,
                    'created_by_name_snapshot' => $operatorName, 'updated_by' => $operatorId,
                    'updated_by_name_snapshot' => $operatorName, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $action = 'CREATED';
            }
            $this->assertNoEnabledTierOverlap($tenantId);
            $after = Db::name(self::CONSUMPTION_TIER_TABLE)->where('id', $tierId)->find();
            Db::name(self::CONSUMPTION_TIER_AUDIT_TABLE)->insert([
                'tenant_id' => $tenantId, 'tier_id' => $tierId, 'tier_code' => $tierCode,
                'action' => $action, 'idempotency_key' => $idempotencyKey,
                'before_snapshot_json' => $this->json($before), 'after_snapshot_json' => $this->json($after),
                'before_version' => (int)($before['version'] ?? 0), 'after_version' => $version,
                'operator_id' => $operatorId, 'operator_name_snapshot' => $operatorName, 'occurred_at' => $now,
            ]);
            $after['replayed'] = false;
            return $after;
        });
    }

    /** Atomically reorder existing tiers; every row keeps independent audit and concurrency protection. */
    public function sortConsumptionTiers(array $context, array $payload): array
    {
        $tenantId = $this->token($context['tenant_id'] ?? '', 'tenant_id', 32);
        $idempotencyKey = $this->token($payload['idempotency_key'] ?? '', 'idempotency_key', 80);
        $requested = array_values((array)($payload['tiers'] ?? []));
        if ($requested === []) throw new \InvalidArgumentException('消费分级排序不能为空');
        $operatorId = (int)($context['operator_id'] ?? $context['admin_id'] ?? 0);
        $operatorName = mb_substr(trim((string)($context['operator_name'] ?? $context['admin_name'] ?? '')), 0, 128);

        return Db::transaction(function () use ($tenantId, $idempotencyKey, $requested, $operatorId, $operatorName): array {
            $normalized = [];
            foreach ($requested as $index => $item) {
                if (!is_array($item)) throw new \InvalidArgumentException('消费分级排序内容无效');
                $code = $this->token($item['tier_code'] ?? '', 'tier_code', 64);
                if (isset($normalized[$code])) throw new \InvalidArgumentException('消费分级排序存在重复项');
                $version = (int)($item['expected_version'] ?? 0);
                if ($version <= 0) throw new \InvalidArgumentException('消费分级排序数据已过期，请刷新后重试');
                $normalized[$code] = ['expected_version' => $version, 'sort_order' => ($index + 1) * 10];
            }

            $rows = Db::name(self::CONSUMPTION_TIER_TABLE)->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')->lock(true)->select()->toArray();
            $persistedCodes = array_values(array_map(static function (array $row): string {
                return (string)$row['tier_code'];
            }, $rows));
            sort($persistedCodes, SORT_STRING);
            $requestedCodes = array_keys($normalized);
            sort($requestedCodes, SORT_STRING);
            if ($persistedCodes !== $requestedCodes) {
                throw new \InvalidArgumentException('消费分级排序必须包含当前全部配置');
            }
            $byCode = [];
            foreach ($rows as $row) $byCode[(string)$row['tier_code']] = $row;
            $now = time();
            foreach ($normalized as $code => $target) {
                $row = (array)$byCode[$code];
                $auditKey = $idempotencyKey . ':' . substr(hash('sha256', $code), 0, 16);
                $replay = Db::name(self::CONSUMPTION_TIER_AUDIT_TABLE)
                    ->where('tenant_id', $tenantId)->where('idempotency_key', $auditKey)->lock(true)->find();
                if ($replay) continue;
                if ((int)$row['version'] !== (int)$target['expected_version']) {
                    throw new \InvalidArgumentException('消费分级排序数据已更新，请刷新后重试');
                }
                $newVersion = (int)$row['version'] + 1;
                Db::name(self::CONSUMPTION_TIER_TABLE)->where('id', (int)$row['id'])->update([
                    'sort_order' => (int)$target['sort_order'], 'version' => $newVersion,
                    'updated_by' => $operatorId, 'updated_by_name_snapshot' => $operatorName, 'updated_at' => $now,
                ]);
                $after = $row;
                $after['sort_order'] = (int)$target['sort_order'];
                $after['version'] = $newVersion;
                $after['updated_by'] = $operatorId;
                $after['updated_by_name_snapshot'] = $operatorName;
                $after['updated_at'] = $now;
                Db::name(self::CONSUMPTION_TIER_AUDIT_TABLE)->insert([
                    'tenant_id' => $tenantId, 'tier_id' => (int)$row['id'], 'tier_code' => $code,
                    'action' => 'SORTED', 'idempotency_key' => $auditKey,
                    'before_snapshot_json' => $this->json($row), 'after_snapshot_json' => $this->json($after),
                    'before_version' => (int)$row['version'], 'after_version' => $newVersion,
                    'operator_id' => $operatorId, 'operator_name_snapshot' => $operatorName, 'occurred_at' => $now,
                ]);
            }
            return $this->consumptionTiers($tenantId, false);
        });
    }

    /** @return array{origin_type:string,evidence_status:string,is_new_customer_eligible:bool,member_created_at:int} */
    public function memberOrigin(string $tenantId, int $memberId): array
    {
        return $this->memberOrigins($tenantId, [$memberId])[$memberId];
    }

    /** @return array<int,array{origin_type:string,evidence_status:string,is_new_customer_eligible:bool,member_created_at:int}> */
    public function memberOrigins(string $tenantId, array $memberIds): array
    {
        $tenantId = $this->token($tenantId, 'tenant_id', 32);
        $memberIds = $this->memberIds($memberIds);
        $unknown = ['origin_type' => 'UNKNOWN', 'evidence_status' => 'UNKNOWN',
            'is_new_customer_eligible' => false, 'member_created_at' => 0];
        $result = array_fill_keys($memberIds, $unknown);
        if ($memberIds === []) return $result;
        foreach (Db::name(self::MEMBER_ORIGIN_TABLE)->where('tenant_id', $tenantId)
            ->whereIn('member_id', $memberIds)->select()->toArray() as $row) {
            $origin = (string)$row['origin_type'];
            $result[(int)$row['member_id']] = [
                'origin_type' => $origin,
                'evidence_status' => (string)$row['evidence_status'],
                'is_new_customer_eligible' => $origin === 'SYSTEM_CREATED'
                    && (int)$row['member_created_at'] >= (int)$row['cutover_at'],
                'member_created_at' => (int)$row['member_created_at'],
            ];
        }
        return $result;
    }

    /** Valid non-conflicting phones share identity; invalid/empty/conflicting phones fall back to member id. */
    public function memberIdentityKey(int $memberId): string
    {
        return $this->memberIdentityKeys([$memberId])[$memberId];
    }

    /** @return array<int,string> */
    public function memberIdentityKeys(array $memberIds): array
    {
        $memberIds = $this->memberIds($memberIds);
        $result = [];
        foreach ($memberIds as $memberId) $result[$memberId] = 'member:' . $memberId;
        if ($memberIds === []) return $result;
        $digests = [];
        foreach (Db::name('user_phone_identity')->whereIn('bound_uid', $memberIds)
            ->where('state', 'BOUND')->field('bound_uid,phone_digest')->select()->toArray() as $row) {
            $memberId = (int)$row['bound_uid'];
            $digest = trim((string)$row['phone_digest']);
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) continue;
            $digests[$memberId][$digest] = true;
        }
        foreach ($digests as $memberId => $memberDigests) {
            if (count($memberDigests) === 1) $result[$memberId] = 'phone:' . (string)array_key_first($memberDigests);
        }
        return $result;
    }

    /** Cutoff is inclusive; the effective lookup uses 23:59:59 in Asia/Shanghai. */
    public function memberStoreAssignmentAt(string $tenantId, int $memberId, string $cutoffDate): array
    {
        return $this->memberStoreAssignmentsAt($tenantId, [$memberId], $cutoffDate)[$memberId];
    }

    /** @return array<int,array{store_id:int,store_name:string,configured:bool}> */
    public function memberStoreAssignmentsAt(string $tenantId, array $memberIds, string $cutoffDate): array
    {
        $tenantId = $this->token($tenantId, 'tenant_id', 32);
        $memberIds = $this->memberIds($memberIds);
        $cutoff = strtotime($this->date($cutoffDate, 'cutoff_date') . ' 23:59:59 Asia/Shanghai');
        $unconfigured = ['store_id' => 0, 'store_name' => '未配置归属门店', 'configured' => false];
        $result = array_fill_keys($memberIds, $unconfigured);
        if ($memberIds === []) return $result;
        $rows = Db::name(self::MEMBER_STORE_PERIOD_TABLE)
            ->where('tenant_id', $tenantId)
            ->whereIn('member_id', $memberIds)
            ->where('valid_from_at', '<=', $cutoff)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('valid_to_at')->whereOr('valid_to_at', '>', $cutoff);
            })->order('valid_from_at', 'desc')->order('id', 'desc')->select()->toArray();
        $seen = [];
        foreach ($rows as $row) {
            $memberId = (int)$row['member_id'];
            if (!isset($result[$memberId]) || isset($seen[$memberId])) continue;
            $seen[$memberId] = true;
            if ((int)$row['assigned'] !== 1 || (int)$row['store_id'] <= 0) continue;
            $result[$memberId] = [
                'store_id' => (int)$row['store_id'],
                'store_name' => trim((string)$row['store_name_snapshot']) ?: '未配置归属门店',
                'configured' => true,
            ];
        }
        return $result;
    }

    /** Persist explicit creation/import evidence for future member writers. */
    public function recordMemberOrigin(array $context, array $evidence): array
    {
        $tenantId = $this->token($context['tenant_id'] ?? '', 'tenant_id', 32);
        $memberId = $this->positiveInt((int)($evidence['member_id'] ?? 0), 'member_id');
        $originType = strtoupper(trim((string)($evidence['origin_type'] ?? '')));
        if (!in_array($originType, self::ORIGIN_TYPES, true)) {
            throw new \InvalidArgumentException('会员来源证据类型无效');
        }
        $sourceType = $this->token($evidence['source_type'] ?? '', 'source_type', 64);
        $sourceId = mb_substr(trim((string)($evidence['source_id'] ?? '')), 0, 128);
        $idempotencyKey = $this->token($evidence['idempotency_key'] ?? '', 'idempotency_key', 128);
        $createdAt = max(0, (int)($evidence['member_created_at'] ?? 0));
        $operatorId = (int)($context['operator_id'] ?? 0);
        return Db::transaction(function () use ($tenantId, $memberId, $originType, $sourceType, $sourceId, $idempotencyKey, $createdAt, $operatorId): array {
            $replay = Db::name(self::MEMBER_ORIGIN_TABLE)->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)->lock(true)->find();
            if ($replay) {
                if ((int)$replay['member_id'] !== $memberId || (string)$replay['origin_type'] !== $originType) {
                    throw new \InvalidArgumentException('幂等标识已用于其他会员来源证据');
                }
                return $replay;
            }
            $existing = Db::name(self::MEMBER_ORIGIN_TABLE)->where('tenant_id', $tenantId)
                ->where('member_id', $memberId)->lock(true)->find();
            if ($existing) {
                if ((string)$existing['origin_type'] !== $originType) {
                    throw new \InvalidArgumentException('会员来源证据已存在且不一致');
                }
                return $existing;
            }
            $now = time();
            $id = (int)Db::name(self::MEMBER_ORIGIN_TABLE)->insertGetId([
                'tenant_id' => $tenantId, 'member_id' => $memberId, 'origin_type' => $originType,
                'evidence_status' => 'PROVEN', 'evidence_source_type' => $sourceType,
                'evidence_source_id' => $sourceId, 'member_created_at' => $createdAt,
                'cutover_at' => self::V3_CUTOVER_AT, 'version' => 1, 'idempotency_key' => $idempotencyKey,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $row = Db::name(self::MEMBER_ORIGIN_TABLE)->where('id', $id)->find();
            Db::name(self::MEMBER_ORIGIN_AUDIT_TABLE)->insert([
                'tenant_id' => $tenantId, 'member_id' => $memberId, 'action' => 'CREATED',
                'idempotency_key' => 'audit:' . $idempotencyKey, 'before_snapshot_json' => '{}',
                'after_snapshot_json' => $this->json($row), 'before_version' => 0, 'after_version' => 1,
                'operator_id' => $operatorId, 'occurred_at' => $now,
            ]);
            return $row;
        });
    }

    /**
     * Persist a bind/switch/unbind event as an effective period. Callers must
     * invoke this inside the same transaction as the authoritative assignment.
     */
    public function recordMemberStoreAssignmentInTx(array $context, array $event): array
    {
        CashierV3TransactionGuard::assertInTransaction('phaseThreeMemberStoreAssignment.persistInTx');
        $tenantId = $this->token($context['tenant_id'] ?? '', 'tenant_id', 32);
        $memberId = $this->positiveInt((int)($event['member_id'] ?? 0), 'member_id');
        $assigned = (bool)($event['assigned'] ?? false);
        $storeId = $assigned ? $this->positiveInt((int)($event['store_id'] ?? 0), 'store_id') : 0;
        $sourceType = $this->token($event['source_type'] ?? '', 'source_type', 64);
        $sourceEventId = $this->token($event['source_event_id'] ?? '', 'source_event_id', 128);
        $idempotencyKey = $this->token($event['idempotency_key'] ?? '', 'idempotency_key', 128);
        $effectiveAt = (int)($event['effective_at'] ?? time());
        if ($effectiveAt <= 0) {
            throw new \InvalidArgumentException('effective_at 无效');
        }
        $replay = Db::name(self::MEMBER_STORE_PERIOD_TABLE)->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)->lock(true)->find();
        if ($replay) {
            if ((int)$replay['member_id'] !== $memberId || (int)$replay['assigned'] !== (int)$assigned
                || (int)$replay['store_id'] !== $storeId) {
                throw new \InvalidArgumentException('幂等标识已用于其他会员归属事件');
            }
            return $replay;
        }
        $current = Db::name(self::MEMBER_STORE_PERIOD_TABLE)->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)->whereNull('valid_to_at')
            ->order('valid_from_at', 'desc')->order('id', 'desc')->lock(true)->find();
        if ($current && (int)$current['valid_from_at'] >= $effectiveAt) {
            throw new \InvalidArgumentException('会员归属事件时间早于当前有效记录');
        }
        if ($current) {
            Db::name(self::MEMBER_STORE_PERIOD_TABLE)->where('id', (int)$current['id'])
                ->update(['valid_to_at' => $effectiveAt]);
        }
        $storeName = '';
        if ($assigned) {
            $store = Db::name('system_store')->where('id', $storeId)->field('id,name')->lock(true)->find();
            if (!$store) {
                throw new \InvalidArgumentException('归属门店不存在');
            }
            $storeName = mb_substr(trim((string)$store['name']), 0, 128);
        }
        $row = [
            'tenant_id' => $tenantId, 'member_id' => $memberId, 'assigned' => $assigned ? 1 : 0,
            'store_id' => $storeId, 'store_name_snapshot' => $storeName, 'valid_from_at' => $effectiveAt,
            'valid_to_at' => null, 'source_type' => $sourceType, 'source_event_id' => $sourceEventId,
            'idempotency_key' => $idempotencyKey, 'version' => 1, 'created_at' => time(),
        ];
        $row['immutable_fingerprint'] = hash('sha256', $this->json($row));
        $id = (int)Db::name(self::MEMBER_STORE_PERIOD_TABLE)->insertGetId($row);
        return Db::name(self::MEMBER_STORE_PERIOD_TABLE)->where('id', $id)->find();
    }

    private function assertNoEnabledTierOverlap(string $tenantId): void
    {
        $previousUpper = null;
        foreach (Db::name(self::CONSUMPTION_TIER_TABLE)->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')->where('enabled', 1)
            ->order('lower_bound_cents', 'asc')->order('id', 'asc')->lock(true)->select()->toArray() as $tier) {
            $lower = (int)$tier['lower_bound_cents'];
            if ($previousUpper === null && isset($seen)) {
                throw new \InvalidArgumentException('启用的消费分级区间存在重叠');
            }
            if (isset($seen) && $lower < $previousUpper) {
                throw new \InvalidArgumentException('启用的消费分级区间存在重叠');
            }
            $previousUpper = $tier['upper_bound_cents'] === null ? null : (int)$tier['upper_bound_cents'];
            $seen = true;
        }
    }

    private function dimensionCode(string $value): string
    {
        $value = trim($value);
        if (!in_array($value, self::DIMENSION_CODES, true)) {
            throw new \InvalidArgumentException('组织报表统计维度无效');
        }
        return $value;
    }

    private function token($value, string $field, int $max): string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > $max || preg_match('/^[A-Za-z0-9:._-]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' 无效');
        }
        return $value;
    }

    private function positiveInt(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException($field . ' 无效');
        }
        return $value;
    }

    /** @return array<int,int> */
    private function memberIds(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = (int)$value;
            if ($value > 0) $result[$value] = $value;
        }
        return array_values($result);
    }

    private function date(string $value, string $field): string
    {
        $value = trim($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Asia/Shanghai'));
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($field . ' 无效');
        }
        return $value;
    }

    private function json($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('报表配置审计序列化失败');
        }
        return $json;
    }
}
