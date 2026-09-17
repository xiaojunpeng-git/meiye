<?php

namespace app\services\query\metric;

use app\services\report\StoreReportNormalDataScopeServices;
use think\facade\Db;

/**
 * V3 employee service-customer metric reader.
 *
 * The completed-service fact owns the customer-object snapshot while the
 * effective labour-performance fact owns the actual serving employee.  A
 * zero-price service has no performance amount and therefore no labour fact;
 * its immutable craftsmen snapshot is the narrowly-scoped fallback.  The
 * reader never reopens carts, sales orders or historical operational tables.
 *
 * All returned customer values use tenths.  Ten units represent one customer,
 * allowing a stable 0.3 / 0.3 / 0.4 allocation without floating point math.
 */
final class ServiceCustomerMetricReadServices
{
    private $queryFactory;
    private $normalServices;

    public function __construct(?callable $queryFactory = null, ?callable $normalServices = null)
    {
        $this->queryFactory = $queryFactory ?: static function (string $table) { return Db::name($table); };
        $this->normalServices = $normalServices ?: static function ($query, string $serviceAlias): void {
            (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, $serviceAlias);
        };
    }

    /**
     * @return array{
     *   detail_by_labor_key:array<string,int>,
     *   detail_context_by_labor_key:array<string,array<string,mixed>>,
     *   summary_by_store_employee:array<string,array{visit_tenths:int,people_tenths:int,employee_name:string}>,
     *   daily_by_store_employee:array<string,array{visit_tenths:int,employee_name:string}>
     * }
     */
    public function employeeMetrics(string $tenantId, array $stores, array $range): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $rows = $this->rows($tenantId, $stores, $range);

        // Adjustment reversals remain immutable facts.  Removing an original
        // allocation therefore means ignoring the forward fact named by its
        // effective reversal, then taking the replacement forward allocation.
        $reversed = [];
        foreach ($rows as $row) {
            if ((string)($row['fact_direction'] ?? '') === 'reversal') {
                $target = trim((string)($row['reversal_of'] ?? ''));
                if ($target !== '') $reversed[$target] = true;
            }
        }

        /** @var array<string,array<string,mixed>> $services */
        $services = [];
        foreach ($rows as $row) {
            $storeId = $this->positive($row['service_store_id'] ?? null);
            $businessDate = $this->date($row['business_date'] ?? null);
            $requestId = $this->token($row['checkout_request_id'] ?? null, 'METRIC_SERVICE_SOURCE_INVALID');
            $sourceLineId = $this->token($row['source_line_id'] ?? null, 'METRIC_SERVICE_SOURCE_INVALID');
            $serviceFactId = $this->token($row['service_fact_id'] ?? null, 'METRIC_SERVICE_SOURCE_INVALID');
            if (!in_array($storeId, $stores, true)) $this->fail('METRIC_SERVICE_SOURCE_INVALID');
            $subject = $this->subjectKey($row, $serviceFactId);
            $serviceLineKey = $storeId . '|' . $requestId . '|' . $sourceLineId;
            if (!isset($services[$serviceLineKey])) {
                $services[$serviceLineKey] = [
                    'store_id' => $storeId, 'business_date' => $businessDate,
                    'request_id' => $requestId, 'source_line_id' => $sourceLineId,
                    'service_fact_id' => $serviceFactId, 'subject' => $subject,
                    'member_id' => $this->integer($row['member_id'] ?? 0),
                    'member_name' => trim((string)($row['member_name'] ?? '')),
                    'company_name' => trim((string)($row['company_name'] ?? '')),
                    'store_name' => trim((string)($row['store_name'] ?? '')),
                    'item_name' => trim((string)($row['item_name'] ?? '')),
                    'category_path' => trim((string)($row['category_path'] ?? '')),
                    'source_type' => trim((string)($row['source_type'] ?? '')),
                    'craftsmen_snapshot_json' => (string)($row['craftsmen_snapshot_json'] ?? ''),
                    'labor_amount_cents' => $this->integer($row['labor_amount_cents'] ?? 0),
                    'labor_fee_amount_cents' => $this->integer($row['labor_fee_amount_cents'] ?? 0),
                    'had_labor_fact' => false,
                ];
            }
            if (trim((string)($row['fact_id'] ?? '')) !== '') $services[$serviceLineKey]['had_labor_fact'] = true;
        }

        /** @var array<string,array<string,mixed>> $lines */
        $lines = [];
        foreach ($rows as $row) {
            if ((string)($row['fact_direction'] ?? '') !== 'forward'
                || isset($reversed[(string)($row['fact_id'] ?? '')])) {
                continue;
            }
            if (trim((string)($row['fact_id'] ?? '')) === '') continue;
            $storeId = $this->positive($row['store_id'] ?? null);
            $serviceStoreId = $this->positive($row['service_store_id'] ?? null);
            $employeeId = $this->positive($row['employee_id'] ?? null);
            $businessDate = $this->date($row['business_date'] ?? null);
            $requestId = $this->token($row['checkout_request_id'] ?? null, 'METRIC_SERVICE_SOURCE_INVALID');
            $sourceLineId = $this->token($row['source_line_id'] ?? null, 'METRIC_SERVICE_SOURCE_INVALID');
            $serviceFactId = $this->token($row['service_fact_id'] ?? null, 'METRIC_SERVICE_SOURCE_INVALID');
            if ($storeId !== $serviceStoreId || !in_array($storeId, $stores, true)) {
                $this->fail('METRIC_SERVICE_SOURCE_INVALID');
            }
            $subject = $this->subjectKey($row, $serviceFactId);
            if ($subject === null) continue; // 朋友不算不进入任何服务客数指标。

            $lineKey = $storeId . '|' . $employeeId . '|' . $requestId . '|' . $sourceLineId;
            $serviceLineKey = $storeId . '|' . $requestId . '|' . $sourceLineId;
            if (!isset($lines[$lineKey])) {
                $lines[$lineKey] = [
                    'store_id' => $storeId,
                    'business_date' => $businessDate,
                    'subject' => $subject,
                    'employee_id' => $employeeId,
                    'employee_name' => trim((string)($row['employee_name'] ?? '')),
                    'service_line_key' => $serviceLineKey,
                    'service' => $services[$serviceLineKey],
                ];
            } elseif ($lines[$lineKey]['business_date'] !== $businessDate || $lines[$lineKey]['subject'] !== $subject) {
                $this->fail('METRIC_SERVICE_SOURCE_INVALID');
            }
        }
        // A zero-value service has no labour-performance allocation at all.
        // Its frozen craftsmen snapshot remains the service-person source.  Do
        // not fall back after a performance allocation (including an adjusted
        // one) existed, otherwise an adjustment could be silently overridden.
        foreach ($services as $serviceLineKey => $service) {
            if ($service['subject'] === null || $service['had_labor_fact']
                || (int)$service['labor_amount_cents'] !== 0 || (int)$service['labor_fee_amount_cents'] !== 0) continue;
            foreach ($this->snapshotEmployees((string)$service['craftsmen_snapshot_json'], (int)$service['store_id']) as $employeeId => $employeeName) {
                $lineKey = (int)$service['store_id'] . '|' . $employeeId . '|' . $service['request_id'] . '|' . $service['source_line_id'];
                $lines[$lineKey] = [
                    'store_id' => $service['store_id'], 'business_date' => $service['business_date'],
                    'subject' => $service['subject'], 'employee_id' => $employeeId,
                    'employee_name' => $employeeName, 'service_line_key' => $serviceLineKey,
                    'service' => $service,
                ];
            }
        }

        /** @var array<string,array{employees:array<int,string>,lines:array<int,array<int,string>>}> $daily */
        $daily = [];
        /** @var array<string,array{employees:array<int,string>}> $period */
        $period = [];
        foreach ($lines as $lineKey => $line) {
            $storeId = (int)$line['store_id'];
            $employeeId = (int)$line['employee_id'];
            $employeeName = (string)$line['employee_name'];
            $dailyKey = $storeId . '|' . (string)$line['business_date'] . '|' . (string)$line['subject'];
            $periodKey = $storeId . '|' . (string)$line['subject'];
            if (!isset($daily[$dailyKey])) $daily[$dailyKey] = ['employees' => [], 'lines' => []];
            if (!isset($period[$periodKey])) $period[$periodKey] = ['employees' => []];
            $daily[$dailyKey]['employees'][$employeeId] = $this->name($daily[$dailyKey]['employees'][$employeeId] ?? '', $employeeName);
            $daily[$dailyKey]['lines'][$employeeId][] = $lineKey;
            $period[$periodKey]['employees'][$employeeId] = $this->name($period[$periodKey]['employees'][$employeeId] ?? '', $employeeName);
        }

        $detail = [];
        $detailContext = [];
        $summary = [];
        $dailyTotals = [];
        foreach ($daily as $dailyKey => $group) {
            $parts = $this->split(array_keys($group['employees']));
            [$storeId, $businessDate] = explode('|', $dailyKey, 3);
            foreach ($parts as $employeeId => $tenths) {
                $employeeName = (string)($group['employees'][$employeeId] ?? '');
                $summaryKey = (int)$storeId . '|' . $employeeId;
                $dailyTotalKey = (int)$storeId . '|' . $businessDate . '|' . $employeeId;
                $this->initSummary($summary, $summaryKey, $employeeName);
                if (!isset($dailyTotals[$dailyTotalKey])) $dailyTotals[$dailyTotalKey] = ['visit_tenths' => 0, 'employee_name' => $employeeName];
                $summary[$summaryKey]['visit_tenths'] += $tenths;
                $dailyTotals[$dailyTotalKey]['visit_tenths'] += $tenths;
                $dailyTotals[$dailyTotalKey]['employee_name'] = $this->name($dailyTotals[$dailyTotalKey]['employee_name'], $employeeName);
                $lineKeys = $group['lines'][$employeeId] ?? [];
                sort($lineKeys, SORT_STRING);
                if ($lineKeys === []) $this->fail('METRIC_SERVICE_SOURCE_INVALID');
                // A detail table retains its project grain.  Give the daily
                // customer share to one deterministic project line only.
                $detail[$lineKeys[0]] = ($detail[$lineKeys[0]] ?? 0) + $tenths;
                $detailContext[$lineKeys[0]] = $lines[$lineKeys[0]];
            }
        }
        // Keep the store identity tied to the period key rather than employee
        // names; the latter may legitimately be duplicated across stores.
        foreach ($period as $periodKey => $group) {
            [$storeId] = explode('|', $periodKey, 2);
            foreach ($this->split(array_keys($group['employees'])) as $employeeId => $tenths) {
                $employeeName = (string)($group['employees'][$employeeId] ?? '');
                $summaryKey = (int)$storeId . '|' . $employeeId;
                $this->initSummary($summary, $summaryKey, $employeeName);
                $summary[$summaryKey]['people_tenths'] += $tenths;
            }
        }
        ksort($detail, SORT_STRING);
        ksort($detailContext, SORT_STRING);
        ksort($summary, SORT_STRING);
        ksort($dailyTotals, SORT_STRING);
        return [
            'detail_by_labor_key' => $detail,
            'detail_context_by_labor_key' => $detailContext,
            'summary_by_store_employee' => $summary,
            'daily_by_store_employee' => $dailyTotals,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(string $tenantId, array $stores, array $range): array
    {
        $query = call_user_func($this->queryFactory, 'cashier_v3_entitlement_service_fact')->alias('sv')
            ->leftJoin('cashier_v3_performance_fact p', "p.tenant_id=sv.tenant_id AND p.checkout_request_id=sv.checkout_request_id AND p.source_line_id=sv.source_line_id AND p.status='effective' AND p.performance_type='labor_performance_allocated'")
            ->where('sv.tenant_id', $tenantId)->whereIn('sv.store_id', $stores)
            ->whereBetween('sv.business_date', [$range['start'], $range['end']])
            ->where('sv.service_status', 'completed')->order('p.id', 'asc');
        call_user_func($this->normalServices, $query, 'sv');
        return $query->field('p.id,p.fact_id,p.fact_direction,p.reversal_of,p.store_id,p.employee_id,p.employee_name_snapshot employee_name,sv.checkout_request_id,sv.source_line_id,sv.service_fact_id,sv.store_id service_store_id,sv.business_date,sv.member_id,sv.member_name_snapshot member_name,sv.service_object,sv.friend_counts_as_customer,sv.craftsmen_snapshot_json,sv.labor_amount_cents,sv.labor_fee_amount_cents,sv.store_name_snapshot store_name,sv.organization_name_snapshot company_name,sv.project_name_snapshot item_name,sv.project_category_path_snapshot category_path,sv.source_document_type source_type')->select()->toArray();
    }

    /** @return array<int,string> */
    private function snapshotEmployees(string $json, int $storeId): array
    {
        $rows = json_decode($json, true);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $employeeId = $this->integer($row['employeeId'] ?? 0);
            if ($employeeId <= 0) continue;
            $snapshotStoreId = $this->integer($row['storeId'] ?? $storeId);
            if ($snapshotStoreId !== $storeId) continue;
            $out[$employeeId] = $this->name($out[$employeeId] ?? '', trim((string)($row['name'] ?? '')));
        }
        return $out;
    }

    /** @return string|null null means 朋友不算 */
    private function subjectKey(array $row, string $serviceFactId): ?string
    {
        $object = strtolower(trim((string)($row['service_object'] ?? '')));
        if ($object === 'friend') {
            return $this->integer($row['friend_counts_as_customer'] ?? null) === 1 ? 'record:' . $serviceFactId : null;
        }
        if ($object !== 'self') $this->fail('METRIC_SERVICE_OBJECT_INVALID');
        $memberId = $this->integer($row['member_id'] ?? null);
        return $memberId > 0 ? 'member:' . $memberId : 'record:' . $serviceFactId;
    }

    /** @param array<int,int|string> $employeeIds @return array<int,int> */
    private function split(array $employeeIds): array
    {
        $ids = [];
        foreach ($employeeIds as $employeeId) $ids[$this->positive($employeeId)] = true;
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        if ($ids === []) $this->fail('METRIC_SERVICE_SOURCE_INVALID');
        $base = intdiv(10, count($ids));
        $remainder = 10 - $base * count($ids);
        $out = [];
        foreach ($ids as $index => $employeeId) $out[$employeeId] = $base + ($index >= count($ids) - $remainder ? 1 : 0);
        return $out;
    }

    /** @param array<string,array{visit_tenths:int,people_tenths:int,employee_name:string}> $summary */
    private function initSummary(array &$summary, string $key, string $employeeName): void
    {
        if (!isset($summary[$key])) $summary[$key] = ['visit_tenths' => 0, 'people_tenths' => 0, 'employee_name' => $employeeName];
        else $summary[$key]['employee_name'] = $this->name($summary[$key]['employee_name'], $employeeName);
    }

    private function name(string $left, string $right): string
    {
        $left = trim($left); $right = trim($right);
        return $left !== '' ? $left : $right;
    }

    private function assertScope(string $tenantId, array $stores, array $range): void
    {
        if ($tenantId === '' || strlen($tenantId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $tenantId)
            || $stores === [] || count($stores) > 10000 || array_keys($stores) !== range(0, count($stores) - 1)
            || count(array_unique($stores)) !== count($stores) || count($range) !== 2 || !isset($range['start'], $range['end'])) {
            $this->fail('METRIC_SERVICE_SCOPE_INVALID');
        }
        foreach ($stores as $store) $this->positive($store);
        $start = $this->date($range['start']); $end = $this->date($range['end']);
        if ($start > $end) $this->fail('METRIC_SOURCE_RANGE_INVALID');
    }

    private function date($value): string
    {
        if (!is_string($value)) $this->fail('METRIC_SOURCE_RANGE_INVALID');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== $value) $this->fail('METRIC_SOURCE_RANGE_INVALID');
        return $value;
    }

    private function token($value, string $code): string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 160 || preg_match('/[\x00-\x1f\x7f|]/', $value)) $this->fail($code);
        return $value;
    }

    private function positive($value): int
    {
        $value = $this->integer($value);
        if ($value <= 0) $this->fail('METRIC_SERVICE_SOURCE_INVALID');
        return $value;
    }

    private function integer($value): int
    {
        if (is_int($value)) return $value;
        if (!is_string($value) || !preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) || (string)(int)$value !== $value) {
            $this->fail('METRIC_SERVICE_SOURCE_INVALID');
        }
        return (int)$value;
    }

    private function fail(string $code): void
    {
        throw new MetricQueryContractException($code, '服务客数数据或查询范围不合法。');
    }
}
