<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use think\facade\Db;

/**
 * A storeless imported entitlement belongs to an organization, not to an
 * invented operating store. Only a server-side migration scope can opt a
 * holder into this path; ordinary cards keep their existing store rules.
 */
final class CashierV3HistoricalEntitlementScopePolicy
{
    /** Return the allowlisted holder IDs for one authenticated operating store. */
    public static function allowedHolderIds(array $holderIds, string $tenantId, int $operatorStoreId): array
    {
        $holderIds = array_values(array_unique(array_filter(array_map('intval', $holderIds),
            static function (int $id): bool { return $id > 0; })));
        if (!$holderIds || $tenantId === '' || $operatorStoreId <= 0 || !self::tableReady()) {
            return [];
        }
        $store = Db::name('system_store')->where('id', $operatorStoreId)
            ->field('id,is_show,is_del,business_status')->find();
        if (!$store || (int)$store['is_show'] !== 1 || (int)$store['is_del'] !== 0
            || (int)$store['business_status'] !== 1) {
            return [];
        }
        $rows = Db::name('migration_entitlement_scope')
            ->where('tenant_id', $tenantId)->where('status', 'active')
            ->whereIn('card_holder_id', $holderIds)
            ->field('card_holder_id,owner_org_id')->select()->toArray();
        $out = [];
        $allowedOrganizations = [];
        foreach ($rows as $row) {
            $holderId = (int)$row['card_holder_id'];
            $ownerOrgId = (int)$row['owner_org_id'];
            if ($ownerOrgId > 0 && !array_key_exists($ownerOrgId, $allowedOrganizations)) {
                $allowedOrganizations[$ownerOrgId] = self::storeWithinOrganization($operatorStoreId, $ownerOrgId);
            }
            if ($allowedOrganizations[$ownerOrgId] ?? false) {
                $out[$holderId] = true;
            }
        }
        return array_keys($out);
    }

    /** Member self-service may show only its own holder; no store permission is inferred. */
    public static function isImportedHolder(int $holderId, string $tenantId): bool
    {
        if ($holderId <= 0 || $tenantId === '' || !self::tableReady()) {
            return false;
        }
        return Db::name('migration_entitlement_scope')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->where('status', 'active')->count() === 1;
    }

    /** Batch identity probe for member self-service display. */
    public static function importedHolderIds(array $holderIds, string $tenantId): array
    {
        $holderIds = array_values(array_unique(array_filter(array_map('intval', $holderIds),
            static function (int $id): bool { return $id > 0; })));
        if (!$holderIds || $tenantId === '' || !self::tableReady()) return [];
        return array_map('intval', Db::name('migration_entitlement_scope')
            ->where('tenant_id', $tenantId)->where('status', 'active')
            ->whereIn('card_holder_id', $holderIds)->column('card_holder_id'));
    }

    /**
     * Member-facing original paid amount; it is never a target refund or
     * revenue amount. The technical carrier order deliberately remains zero.
     */
    public static function sourcePaidPrice(int $holderId, string $tenantId): ?string
    {
        if ($holderId <= 0 || $tenantId === '' || !self::tableReady()) return null;
        $json = Db::name('migration_entitlement_scope')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->where('status', 'active')
            ->value('source_snapshot_json');
        if (!is_string($json)) return null;
        $snapshot = json_decode($json, true);
        $price = trim((string)($snapshot['source_paid_amount_yuan'] ?? ''));
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $price) !== 1) return null;
        $parts = explode('.', $price, 2);
        return $parts[0] . '.' . str_pad($parts[1] ?? '', 2, '0');
    }

    /** Missing migration on another client instance means no imported rights. */
    private static function tableReady(): bool
    {
        $prefix = (string)Db::connect()->getConfig('prefix');
        // Workers are long-lived: a cached negative would hide rights after
        // migration, and a cached positive could outlive a rollback.
        return (int)Db::query(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$prefix . 'migration_entitlement_scope']
        )[0]['n'] === 1;
    }

    /** Walk the authoritative org tree; sibling brands never grant one another rights. */
    private static function storeWithinOrganization(int $storeId, int $ownerOrgId): bool
    {
        $orgIds = Db::name('organization_store')->where('store_id', $storeId)->column('org_id');
        foreach ($orgIds as $orgId) {
            $seen = [];
            for ($depth = 0, $current = (int)$orgId; $depth < 64 && $current > 0; $depth++) {
                if (isset($seen[$current])) {
                    break;
                }
                $seen[$current] = true;
                $org = Db::name('organization')->where('id', $current)
                    ->field('id,pid,status,is_del')->find();
                if (!$org || (int)$org['is_del'] !== 0 || (int)$org['status'] !== 1) {
                    break;
                }
                if ($current === $ownerOrgId) {
                    return true;
                }
                $current = (int)$org['pid'];
            }
        }
        return false;
    }
}
