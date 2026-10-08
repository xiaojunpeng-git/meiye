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
     *   people_by_labor_key:array<string,int>,
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
                    'allocation_groups' => $this->snapshotAllocationGroups((string)($row['craftsmen_snapshot_json'] ?? ''), $storeId),
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
            if ($subject === null) continue; // 本人或朋友标记不计数时，只排除客数，不排除真实服务及业绩。

            $lineKey = $storeId . '|' . $employeeId . '|' . $requestId . '|' . $sourceLineId;
            $serviceLineKey = $storeId . '|' . $requestId . '|' . $sourceLineId;
            if (!isset($lines[$lineKey])) {
                $lines[$lineKey] = [
                    'store_id' => $storeId,
                    'business_date' => $businessDate,
                    'subject' => $subject,
                    'employee_id' => $employeeId,
                    'employee_name' => trim((string)($row['employee_name'] ?? '')),
                    // The point/round choice is frozen on the employee role,
                    // not on the calculation rule (which may be shared).
                    'point' => substr((string)($row['role_snapshot'] ?? ''), -6) === ':point',
                    // Replacement labor facts own their adjustment-time role group;
                    // the checkout snapshot is only authoritative before an adjustment.
                    'allocation_group' => $this->factAllocationGroup($row)
                        ?? ($services[$serviceLineKey]['allocation_groups'][$employeeId] ?? 'normal'),
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
            $pointEmployees = $this->snapshotPointEmployeeIds((string)$service['craftsmen_snapshot_json'], (int)$service['store_id']);
            foreach ($this->snapshotEmployees((string)$service['craftsmen_snapshot_json'], (int)$service['store_id']) as $employeeId => $employeeName) {
                $lineKey = (int)$service['store_id'] . '|' . $employeeId . '|' . $service['request_id'] . '|' . $service['source_line_id'];
                $lines[$lineKey] = [
                    'store_id' => $service['store_id'], 'business_date' => $service['business_date'],
                    'subject' => $service['subject'], 'employee_id' => $employeeId,
                    'employee_name' => $employeeName, 'point' => isset($pointEmployees[$employeeId]),
                    'allocation_group' => $service['allocation_groups'][$employeeId] ?? 'normal',
                    'service_line_key' => $serviceLineKey,
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
            if (!isset($daily[$dailyKey])) $daily[$dailyKey] = ['employees' => [], 'lines' => [], 'point_employees' => [], 'allocation_groups' => []];
            if (!isset($period[$periodKey])) $period[$periodKey] = ['employees' => [], 'allocation_groups' => [], 'lines' => []];
            $daily[$dailyKey]['employees'][$employeeId] = $this->name($daily[$dailyKey]['employees'][$employeeId] ?? '', $employeeName);
            $daily[$dailyKey]['lines'][$employeeId][] = $lineKey;
            $period[$periodKey]['lines'][$employeeId][$lineKey] = (string)$line['business_date'];
            if (!empty($line['point'])) $daily[$dailyKey]['point_employees'][$employeeId] = true;
            $period[$periodKey]['employees'][$employeeId] = $this->name($period[$periodKey]['employees'][$employeeId] ?? '', $employeeName);
            // A member may have several service lines in one day or period.
            // Keep one employee visit, with an independently settled role taking precedence over a normal role.
            $allocationGroup = (string)$line['allocation_group'];
            $daily[$dailyKey]['allocation_groups'][$employeeId] = $this->preferredAllocationGroup($daily[$dailyKey]['allocation_groups'][$employeeId] ?? null, $allocationGroup);
            $period[$periodKey]['allocation_groups'][$employeeId] = $this->preferredAllocationGroup($period[$periodKey]['allocation_groups'][$employeeId] ?? null, $allocationGroup);
        }

        $detail = [];
        $peopleDetail = [];
        $detailContext = [];
        $summary = [];
        $dailyTotals = [];
        foreach ($daily as $dailyKey => $group) {
            $parts = $this->splitAllocationGroups($group['allocation_groups']);
            [$storeId, $businessDate] = explode('|', $dailyKey, 3);
            foreach ($parts as $employeeId => $tenths) {
                $employeeName = (string)($group['employees'][$employeeId] ?? '');
                $summaryKey = (int)$storeId . '|' . $employeeId;
                $dailyTotalKey = (int)$storeId . '|' . $businessDate . '|' . $employeeId;
                $this->initSummary($summary, $summaryKey, $employeeName);
                if (!isset($dailyTotals[$dailyTotalKey])) $dailyTotals[$dailyTotalKey] = ['visit_tenths' => 0, 'employee_name' => $employeeName];
                $summary[$summaryKey]['visit_tenths'] += $tenths;
                // 点客属于被明确指定的技师，不按共同服务人数分摊。
                // 同一客人在同门店同一天的多项目只计一次点客次。
                if (isset($group['point_employees'][$employeeId])) $summary[$summaryKey]['point_visit_tenths'] += 10;
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
            foreach ($this->splitAllocationGroups($group['allocation_groups']) as $employeeId => $tenths) {
                $employeeName = (string)($group['employees'][$employeeId] ?? '');
                $summaryKey = (int)$storeId . '|' . $employeeId;
                $this->initSummary($summary, $summaryKey, $employeeName);
                $summary[$summaryKey]['people_tenths'] += $tenths;
                // Period-deduplicated people belong to one stable service line;
                // summing these projected rows must reproduce the period total.
                $candidates = $group['lines'][$employeeId] ?? [];
                if ($candidates === []) $this->fail('METRIC_SERVICE_SOURCE_INVALID');
                asort($candidates, SORT_STRING);
                $firstDate = reset($candidates);
                $firstKeys = array_keys(array_filter($candidates, static fn(string $date): bool => $date === $firstDate));
                sort($firstKeys, SORT_STRING);
                $firstKey = $firstKeys[0];
                $peopleDetail[$firstKey] = ($peopleDetail[$firstKey] ?? 0) + $tenths;
                $detailContext[$firstKey] = $lines[$firstKey];
            }
        }
        ksort($detail, SORT_STRING);
        ksort($peopleDetail, SORT_STRING);
        ksort($detailContext, SORT_STRING);
        ksort($summary, SORT_STRING);
        ksort($dailyTotals, SORT_STRING);
        return [
            'detail_by_labor_key' => $detail,
            'people_by_labor_key' => $peopleDetail,
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
            ->leftJoin('cashier_v3_service_customer_group_fact cg', 'cg.tenant_id=p.tenant_id AND cg.performance_fact_id=p.fact_id AND cg.store_id=sv.store_id AND cg.service_fact_id=sv.id AND cg.employee_id=p.employee_id')
            ->where('sv.tenant_id', $tenantId)->whereIn('sv.store_id', $stores)
            ->whereBetween('sv.business_date', [$range['start'], $range['end']])
            ->where('sv.service_status', 'completed')->order('p.id', 'asc');
        call_user_func($this->normalServices, $query, 'sv');
        return $query->field('p.id,p.fact_id,p.fact_direction,p.reversal_of,p.store_id,p.employee_id,p.employee_name_snapshot employee_name,p.role_snapshot,cg.allocation_group_key fact_allocation_group,cg.position_id fact_position_id,cg.performance_independent fact_performance_independent,sv.checkout_request_id,sv.source_line_id,sv.service_fact_id,sv.store_id service_store_id,sv.business_date,sv.member_id,sv.member_name_snapshot member_name,sv.service_object,sv.friend_counts_as_customer,sv.craftsmen_snapshot_json,sv.labor_amount_cents,sv.labor_fee_amount_cents,sv.store_name_snapshot store_name,sv.organization_name_snapshot company_name,sv.project_name_snapshot item_name,sv.project_category_path_snapshot category_path,sv.source_document_type source_type')->select()->toArray();
    }

    /** An adjustment group must be internally consistent; never infer it from today's position. */
    private function factAllocationGroup(array $row): ?string
    {
        $key = trim((string)($row['fact_allocation_group'] ?? ''));
        if ($key === '') return null;
        $positionId = $this->integer($row['fact_position_id'] ?? 0);
        $independent = $this->integer($row['fact_performance_independent'] ?? 0) === 1;
        if ($key === 'normal' && !$independent) return 'normal';
        if ($independent && $positionId > 0 && $key === 'independent:' . $positionId) return $key;
        $this->fail('METRIC_SERVICE_SOURCE_INVALID');
    }

    /** @return array<int,string> */
    private function snapshotEmployees(string $json, int $storeId): array
    {
        $rows = json_decode($json, true);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $employeeId = $this->integer($row['employee_id'] ?? $row['employeeId'] ?? 0);
            if ($employeeId <= 0) continue;
            $snapshotStoreId = $this->integer($row['store_id'] ?? $row['storeId'] ?? $storeId);
            if ($snapshotStoreId !== $storeId) continue;
            $out[$employeeId] = $this->name($out[$employeeId] ?? '', trim((string)($row['staff_name_snapshot'] ?? $row['name'] ?? '')));
        }
        return $out;
    }

    /** Zero-price completed services use the immutable allocation snapshot. */
    private function snapshotPointEmployeeIds(string $json,int $storeId): array
    {
        $rows=json_decode($json,true);
        if (!is_array($rows)) return [];
        $out=[];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['is_point_customer']??$row['isPointCustomer']??false)) continue;
            $employeeId=$this->integer($row['employee_id']??$row['employeeId']??0);
            if ($employeeId>0 && $this->integer($row['store_id']??$row['storeId']??$storeId)===$storeId) $out[$employeeId]=true;
        }
        return $out;
    }

    /** Freeze service-customer allocation to the checkout's role grouping, never today's position. */
    private function snapshotAllocationGroups(string $json, int $storeId): array
    {
        $rows = json_decode($json, true);
        if (!is_array($rows)) return [];
        $groups = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $employeeId = $this->integer($row['employee_id'] ?? $row['employeeId'] ?? 0);
            if ($employeeId <= 0 || $this->integer($row['store_id'] ?? $row['storeId'] ?? $storeId) !== $storeId) continue;
            $groupKey = trim((string)($row['allocationGroupKey'] ?? $row['allocation_group_key'] ?? ''));
            $hasPositionId = array_key_exists('positionId', $row) || array_key_exists('position_id', $row);
            $positionId = $this->integer($row['positionId'] ?? $row['position_id'] ?? 0);
            $independent = !empty($row['performanceIndependent']) || !empty($row['performance_independent']);
            // Settled service snapshots persist the independent flag and group key,
            // but not always position_id. Accept that frozen key when well formed;
            // if an explicit position is present, it must still agree with the key.
            $validGroup = preg_match('/^independent:[1-9][0-9]*$/D', $groupKey) === 1
                && (!$hasPositionId || ($positionId > 0 && $groupKey === 'independent:' . $positionId));
            $group = $independent && $validGroup
                ? $groupKey : 'normal';
            $groups[$employeeId] = $this->preferredAllocationGroup($groups[$employeeId] ?? null, $group);
        }
        return $groups;
    }

    /** Each independent position owns one full customer unit; ordinary staff share one other unit. */
    private function splitAllocationGroups(array $employeeGroups): array
    {
        $groups = [];
        foreach ($employeeGroups as $employeeId => $group) $groups[$group][] = $employeeId;
        ksort($groups, SORT_STRING);
        $shares = [];
        foreach ($groups as $employeeIds) $shares += $this->split($employeeIds);
        return $shares;
    }

    /** Resolve mixed historical lines deterministically without counting one employee twice. */
    private function preferredAllocationGroup(?string $existing, string $current): string
    {
        if ($existing === null || $existing === 'normal') return $current;
        if ($current === 'normal') return $existing;
        return strcmp($existing, $current) <= 0 ? $existing : $current;
    }

    /** @return string|null null means this completed service is excluded from customer counts. */
    private function subjectKey(array $row, string $serviceFactId): ?string
    {
        $object = strtolower(trim((string)($row['service_object'] ?? '')));
        if (!ServiceCustomerEligibility::allows($row)) return null;
        if ($object === 'friend') {
            return 'record:' . $serviceFactId;
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
        if (!isset($summary[$key])) $summary[$key] = ['visit_tenths' => 0, 'people_tenths' => 0,
            'point_visit_tenths' => 0, 'employee_name' => $employeeName];
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
