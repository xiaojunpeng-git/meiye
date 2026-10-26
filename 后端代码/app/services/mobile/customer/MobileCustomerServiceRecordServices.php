<?php

namespace app\services\mobile\customer;

use think\facade\Db;

/**
 * Mobile read projection for completed V3 facts and imported completed legacy
 * work snapshots. Legacy rows are display-only and cannot become V3 service,
 * writeoff, labor or reporting facts. A visible customer permits their legacy
 * history across old stores; current V3 facts retain the merchant store scope.
 */
final class MobileCustomerServiceRecordServices
{
    private const TENANT_ID = '0';
    private const MAX_PAGE_SIZE = 50;

    /** @var MobileCustomerQueryServices */
    private $customers;

    public function __construct(MobileCustomerQueryServices $customers)
    {
        $this->customers = $customers;
    }

    public function page(array $merchantContext, array $payload): array
    {
        $memberId = (int)($payload['memberId'] ?? 0);
        $this->customers->assertMemberVisible($merchantContext, $memberId);
        $page = max(1, (int)($payload['page'] ?? 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, (int)($payload['pageSize'] ?? $payload['limit'] ?? 20)));
        $current = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
            ->where('sf.tenant_id', self::TENANT_ID)
            ->where('sf.member_id', $memberId)
            ->where('sf.service_status', 'completed');
        $this->applyServiceScope($current, $merchantContext);
        $currentTotal = (int)(clone $current)->count('sf.id');
        $legacyTotal = (int)Db::name('mobile_customer_legacy_service_record')
            ->where('source_system', 'morestore')->where('member_id', $memberId)->count();
        $total = $currentTotal + $legacyTotal;
        $rows = $total > 0 ? $this->mergedPage($merchantContext, $memberId, $page, $pageSize) : [];

        return [
            'memberId' => $memberId,
            'records' => array_map(function (array $row): array {
                return [
                    'id' => ((int)$row['origin'] === 1 ? 'legacy:' : 'service:') . (int)$row['row_id'],
                    'serviceRecordNo' => (string)$row['service_record_no'],
                    'businessDate' => (string)$row['business_date'],
                    'projectName' => (string)$row['project_name'],
                    'usedTimes' => (int)$row['quantity'],
                    'storeName' => (string)$row['store_name'],
                    'craftsmenSummary' => (int)$row['origin'] === 1
                        ? (string)$row['craftsmen'] : $this->craftsmenSummary((string)$row['craftsmen']),
                    'operatorName' => (string)$row['operator_name'],
                    'entitlementSource' => (int)$row['origin'] === 1
                        ? ((string)$row['source_card_name'] !== '' ? '卡项权益' : '')
                        : $this->entitlementSource($row),
                    'sourceCardName' => (string)$row['source_card_name'],
                    'sourceCardNo' => (string)$row['source_card_no'],
                    'serviceStatus' => '已完成',
                    'serviceCompletedAt' => $this->dateTime((int)$row['completed_at']),
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'hasMore' => $page * $pageSize < $total,
            'dataAuthority' => 'COMPLETED_SERVICE_FACT_AND_IMPORTED_LEGACY_WORK',
        ];
    }

    /**
     * SQL sorts both read models before paging, so a legacy visit cannot be
     * omitted or repeated when old and new completion timestamps interleave.
     * All interpolated values are server-derived positive integers; table names
     * use the validated connection prefix, never a client supplied value.
     */
    private function mergedPage(array $merchantContext, int $memberId, int $page, int $pageSize): array
    {
        $prefix = (string)Db::connect()->getConfig('prefix');
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
            throw new \RuntimeException('Invalid database table prefix');
        }
        $stores = $this->serviceStoreIds($merchantContext);
        $storeCondition = $stores === [] ? '1=0' : 'sf.store_id IN (' . implode(',', $stores) . ')';
        $offset = ($page - 1) * $pageSize;
        $sql = 'SELECT * FROM ('
            . 'SELECT 0 origin,sf.id row_id,sf.service_fact_id service_record_no,'
            . 'sf.business_date,sf.project_name_snapshot project_name,sf.quantity,'
            . 'sf.store_name_snapshot store_name,sf.craftsmen_snapshot_json craftsmen,'
            . 'sf.operator_name_snapshot operator_name,'
            . 'COALESCE(NULLIF(sf.settled_at,0),sf.occurred_at) completed_at,'
            . 'wf.is_gift,wf.source_kind,wf.source_name_snapshot source_card_name,'
            . 'wf.source_code_snapshot source_card_no '
            . 'FROM ' . $prefix . 'cashier_v3_entitlement_service_fact sf '
            . 'LEFT JOIN ' . $prefix . 'cashier_v3_entitlement_writeoff_fact wf '
            . 'ON wf.tenant_id=sf.tenant_id AND wf.checkout_request_id=sf.checkout_request_id '
            . 'AND wf.source_line_id=sf.source_line_id '
            . "WHERE sf.tenant_id='0' AND sf.member_id={$memberId} "
            . "AND sf.service_status='completed' AND {$storeCondition} "
            . 'UNION ALL '
            . "SELECT 1 origin,l.id row_id,CONCAT('legacy:',l.source_work_id) service_record_no,"
            . 'l.business_date,l.project_name_snapshot project_name,l.quantity,'
            . 'l.store_name_snapshot store_name,l.craftsman_name_snapshot craftsmen,'
            . "'' operator_name,l.completed_at,0 is_gift,'' source_kind,"
            . "l.source_card_name_snapshot source_card_name,'' source_card_no "
            . 'FROM ' . $prefix . 'mobile_customer_legacy_service_record l '
            . "WHERE l.source_system='morestore' AND l.member_id={$memberId}"
            . ") merged ORDER BY completed_at DESC,origin ASC,row_id DESC LIMIT {$offset},{$pageSize}";
        return Db::query($sql);
    }

    private function applyServiceScope($query, array $merchantContext): void
    {
        $stores = $this->serviceStoreIds($merchantContext);
        if ($stores === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn('sf.store_id', $stores);
    }

    /** Existing completed V3 services keep their original merchant store scope. */
    private function serviceStoreIds(array $merchantContext): array
    {
        if ((string)($merchantContext['dataScopeMode'] ?? '') === 'PERSONAL_SELF') {
            $storeId = (int)($merchantContext['storeId'] ?? 0);
            return $storeId > 0 ? [$storeId] : [];
        }
        return array_values(array_unique(array_filter(array_map('intval',
            (array)($merchantContext['visibleStoreIds'] ?? [])), static function (int $id): bool {
            return $id > 0;
        })));
    }

    private function entitlementSource(array $row): string
    {
        if ((int)($row['is_gift'] ?? 0) === 1) return '赠送权益';
        // source_kind is an internal completion enum, not a customer-facing
        // card classification. Historical facts may carry "unknown".
        return '卡项权益';
    }

    private function craftsmenSummary(string $json): string
    {
        $items = json_decode($json, true);
        if (!is_array($items)) return '';
        $names = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $name = trim((string)($item['staff_name_snapshot'] ?? $item['staffName'] ?? $item['staff_name'] ?? ''));
            if ($name !== '' && !in_array($name, $names, true)) $names[] = $name;
        }
        return implode('、', $names);
    }

    private function dateTime(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
