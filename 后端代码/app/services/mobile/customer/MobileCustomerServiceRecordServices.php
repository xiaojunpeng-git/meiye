<?php

namespace app\services\mobile\customer;

use think\facade\Db;

/**
 * Mobile read projection for the member's actual V3 completed-service facts.
 * It owns neither checkout nor service completion and never reads sales orders
 * as a substitute for a service record.
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
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
            ->leftJoin(
                'cashier_v3_entitlement_writeoff_fact wf',
                'wf.tenant_id = sf.tenant_id AND wf.checkout_request_id = sf.checkout_request_id AND wf.source_line_id = sf.source_line_id'
            )
            ->where('sf.tenant_id', self::TENANT_ID)
            ->where('sf.member_id', $memberId)
            ->where('sf.service_status', 'completed');
        $this->applyServiceScope($query, $merchantContext);

        $total = (int)(clone $query)->count('sf.id');
        $rows = $query->field(implode(',', [
            'sf.id', 'sf.service_fact_id', 'sf.business_date', 'sf.project_name_snapshot', 'sf.quantity',
            'sf.store_name_snapshot', 'sf.craftsmen_snapshot_json', 'sf.operator_name_snapshot',
            'sf.settled_at', 'sf.occurred_at', 'wf.is_gift', 'wf.source_kind',
            'wf.source_name_snapshot AS source_name_snapshot', 'wf.source_code_snapshot AS source_code_snapshot',
        ]))->order('sf.settled_at', 'desc')->order('sf.id', 'desc')->page($page, $pageSize)->select()->toArray();

        return [
            'memberId' => $memberId,
            'records' => array_map(function (array $row): array {
                return [
                    'id' => 'service:' . (int)$row['id'],
                    'serviceRecordNo' => (string)$row['service_fact_id'],
                    'businessDate' => (string)$row['business_date'],
                    'projectName' => (string)$row['project_name_snapshot'],
                    'usedTimes' => (int)$row['quantity'],
                    'storeName' => (string)$row['store_name_snapshot'],
                    'craftsmenSummary' => $this->craftsmenSummary((string)$row['craftsmen_snapshot_json']),
                    'operatorName' => (string)$row['operator_name_snapshot'],
                    'entitlementSource' => $this->entitlementSource($row),
                    'sourceCardName' => (string)$row['source_name_snapshot'],
                    'sourceCardNo' => (string)$row['source_code_snapshot'],
                    'serviceStatus' => '已完成',
                    'serviceCompletedAt' => $this->dateTime((int)($row['settled_at'] ?: $row['occurred_at'])),
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'hasMore' => $page * $pageSize < $total,
            'dataAuthority' => 'CASHIER_V3_COMPLETED_SERVICE_FACT',
        ];
    }

    private function applyServiceScope($query, array $merchantContext): void
    {
        if ((string)$merchantContext['dataScopeMode'] === 'PERSONAL_SELF') {
            $query->where('sf.store_id', (int)$merchantContext['storeId']);
            return;
        }
        $stores = array_values(array_filter(array_map('intval', (array)($merchantContext['visibleStoreIds'] ?? []))));
        if ($stores === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn('sf.store_id', $stores);
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
