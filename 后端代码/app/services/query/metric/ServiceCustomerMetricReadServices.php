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
 * Customer allocation never reopens carts or historical operational tables;
 * only the original settled sales order and committed tender are read to
 * determine whether the service contributes a period person.
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
        $lines = $this->serviceLines($this->rows($tenantId, $stores, $range), $stores, true);
        $daily = []; $period = [];
        $this->collectGroups($lines, $daily, $period, true);
        return $this->projectGroups($daily, $period, $lines, true);
    }

    /**
     * Count distinct craftsmen actually attached to the caller's eligible
     * completed services. Effective replacement labour facts supersede old
     * allocations; zero-value services retain their frozen craftsmen snapshot.
     * This is a denominator only: store visits remain owned by the separate
     * non-experience member-day reader and are never summed across employees.
     */
    public function participatingCraftsmanCount(string $tenantId, array $stores, array $range, array $serviceFactIds): int
    {
        $wanted = array_fill_keys(array_map('strval', array_filter($serviceFactIds, static fn($id): bool => trim((string)$id) !== '')), true);
        if ($wanted === []) return 0;
        $employees = []; $serviceRows = []; $serviceKey = null;
        $flush = function () use (&$serviceRows, &$employees, $stores): void {
            if ($serviceRows === []) return;
            foreach ($this->serviceLines($serviceRows, $stores, false, true) as $line) {
                $employees[(int)$line['employee_id']] = true;
            }
            $serviceRows = [];
        };
        // The dashboard already selected eligible fact IDs. Resolve their
        // craftsmen in bounded indexed batches instead of sorting every
        // service in a multi-store/year scope a second time.
        foreach (array_chunk(array_keys($wanted), 300) as $chunk) {
            foreach ($this->aggregateRows($tenantId, $stores, $range, true, $chunk) as $row) {
                $factId = (string)($row['service_fact_id'] ?? '');
                if (!isset($wanted[$factId])) continue;
                $nextKey = (int)$row['service_store_id'] . '|' . $factId;
                if ($serviceKey !== null && $serviceKey !== $nextKey) $flush();
                $serviceRows[] = $row;
                if (count($serviceRows) > 10000) $this->fail('METRIC_GROUP_OUTPUT_TOO_LARGE');
                $serviceKey = $nextKey;
            }
            $flush();
            $serviceKey = null;
        }
        return count($employees);
    }

    /**
     * Summary/ranking projection: stream complete service facts, then retain
     * only one store/customer's employee sets until its last service. Never
     * prefilter employees: all co-workers must remain in the allocation divisor.
     * The same group splitter owns detail and aggregate values, including
     * cross-day people, point visits and independent-position allocations.
     *
     * @return array{summary_by_store_employee:array,daily_by_store_employee:array}
     */
    public function aggregateEmployeeMetrics(string $tenantId, array $stores, array $range): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $summary = []; $dailyTotals = []; $daily = []; $period = [];
        $summaryNameOrder = []; $dailyNameOrder = [];
        $serviceRows = []; $serviceKey = null; $subjectKey = null;
        $flushService = function () use (&$serviceRows, &$daily, &$period, $stores): void {
            if ($serviceRows === []) return;
            $this->collectGroups($this->serviceLines($serviceRows, $stores, false), $daily, $period, false);
            $serviceRows = [];
        };
        $flushSubject = function () use (&$daily, &$period, &$summary, &$dailyTotals, &$summaryNameOrder, &$dailyNameOrder): void {
            $totals = $this->projectGroups($daily, $period, [], false);
            foreach ($totals['summary_by_store_employee'] as $key => $row) {
                $this->initSummary($summary, $key, '');
                $this->retainSourceName($summary[$key], $summaryNameOrder, $key, $row['employee_name'], $totals['_summary_name_order'][$key]);
                foreach (['visit_tenths', 'people_tenths', 'point_visit_tenths'] as $field) $summary[$key][$field] += $row[$field];
            }
            foreach ($totals['daily_by_store_employee'] as $key => $row) {
                if (!isset($dailyTotals[$key])) $dailyTotals[$key] = ['visit_tenths' => 0, 'employee_name' => ''];
                $dailyTotals[$key]['visit_tenths'] += $row['visit_tenths'];
                $this->retainSourceName($dailyTotals[$key], $dailyNameOrder, $key, $row['employee_name'], $totals['_daily_name_order'][$key]);
            }
            $daily = []; $period = [];
        };
        foreach ($this->aggregateRows($tenantId, $stores, $range) as $row) {
            $storeId = $this->positive($row['service_store_id'] ?? null);
            $factId = $this->token($row['service_fact_id'] ?? null, 'METRIC_SERVICE_SOURCE_INVALID');
            $nextSubject = $storeId . '|' . ($this->subjectKey($row, $factId) ?? 'excluded:' . $factId);
            $nextService = $storeId . '|' . $factId;
            if ($serviceKey !== null && $serviceKey !== $nextService) $flushService();
            if ($subjectKey !== null && $subjectKey !== $nextSubject) $flushSubject();
            $serviceRows[] = $row;
            // A malformed service with unbounded adjustment history must fail
            // before memory exhaustion; never truncate a divisor or reversal set.
            if (count($serviceRows) > 10000) $this->fail('METRIC_GROUP_OUTPUT_TOO_LARGE');
            $serviceKey = $nextService; $subjectKey = $nextSubject;
        }
        $flushService(); $flushSubject();
        ksort($summary, SORT_STRING); ksort($dailyTotals, SORT_STRING);
        return ['summary_by_store_employee' => $summary, 'daily_by_store_employee' => $dailyTotals];
    }

    /** Resolve immutable service participants before any display projection. */
    private function serviceLines(array $rows, array $stores, bool $details, bool $includeAllServices = false): array
    {
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
            if ($subject === null && $includeAllServices) $subject = 'service:' . $serviceFactId;
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
                    'origin_other_collection' => (int)($row['origin_other_collection'] ?? 0) === 1,
                    'craftsmen_snapshot_json' => (string)($row['craftsmen_snapshot_json'] ?? ''),
                    'allocation_groups' => $this->snapshotAllocationGroups((string)($row['craftsmen_snapshot_json'] ?? ''), $storeId),
                    'labor_amount_cents' => $this->integer($row['labor_amount_cents'] ?? 0),
                    'labor_fee_amount_cents' => $this->integer($row['labor_fee_amount_cents'] ?? 0),
                    'had_labor_fact' => false,
                    'first_row_order' => (int)($row['id'] ?? 0),
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
            if ($subject === null && $includeAllServices) $subject = 'service:' . $serviceFactId;
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
                    'people_eligible' => !$services[$serviceLineKey]['origin_other_collection'],
                    'service_line_key' => $serviceLineKey,
                ];
                if ($details) { $lines[$lineKey]['service'] = $services[$serviceLineKey]; unset($lines[$lineKey]['service']['first_row_order']); }
                else $lines[$lineKey]['source_order'] = [0, (int)($row['id'] ?? 0), $serviceFactId];
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
                    'people_eligible' => !$service['origin_other_collection'],
                    'service_line_key' => $serviceLineKey,
                ];
                if ($details) { $lines[$lineKey]['service'] = $service; unset($lines[$lineKey]['service']['first_row_order']); }
                else $lines[$lineKey]['source_order'] = [1, $service['first_row_order'], $service['service_fact_id']];
            }
        }

        return $lines;
    }

    /** Merge sets before splitting: separate services can share one daily/period customer. */
    private function collectGroups(array $lines, array &$daily, array &$period, bool $details): void
    {
        /** @var array<string,array{employees:array<int,string>,lines:array<int,array<int,string>>}> $daily */
        /** @var array<string,array{employees:array<int,string>}> $period */
        foreach ($lines as $lineKey => $line) {
            $storeId = (int)$line['store_id'];
            $employeeId = (int)$line['employee_id'];
            $employeeName = (string)$line['employee_name'];
            $dailyKey = $storeId . '|' . (string)$line['business_date'] . '|' . (string)$line['subject'];
            $periodKey = $storeId . '|' . (string)$line['subject'];
            if (!isset($daily[$dailyKey])) $daily[$dailyKey] = ['employees' => [], 'lines' => [], 'point_employees' => [], 'allocation_groups' => []];
            // Other-collection funding removes only period people. The same
            // completed service still contributes visits, labor and consumption.
            if (!empty($line['people_eligible']) && !isset($period[$periodKey])) $period[$periodKey] = ['employees' => [], 'allocation_groups' => [], 'lines' => []];
            if ($details) $daily[$dailyKey]['employees'][$employeeId] = $this->name($daily[$dailyKey]['employees'][$employeeId] ?? '', $employeeName);
            else {
                // Global p.id order selected legacy snapshot names. Preserve
                // each daily group's first participant and first nonempty name
                // even though the bounded stream visits customers in a new order.
                $order = $line['source_order'];
                if (!isset($daily[$dailyKey]['first_order']) || $order < $daily[$dailyKey]['first_order']) $daily[$dailyKey]['first_order'] = $order;
                if (!isset($daily[$dailyKey]['employees'][$employeeId])) $daily[$dailyKey]['employees'][$employeeId] = '';
                if ($employeeName !== '' && (!isset($daily[$dailyKey]['name_orders'][$employeeId]) || $order < $daily[$dailyKey]['name_orders'][$employeeId])) {
                    $daily[$dailyKey]['employees'][$employeeId] = $employeeName;
                    $daily[$dailyKey]['name_orders'][$employeeId] = $order;
                }
            }
            if ($details) {
                $daily[$dailyKey]['lines'][$employeeId][] = $lineKey;
                if (!empty($line['people_eligible'])) $period[$periodKey]['lines'][$employeeId][$lineKey] = (string)$line['business_date'];
            }
            if (!empty($line['point'])) $daily[$dailyKey]['point_employees'][$employeeId] = true;
            if (!empty($line['people_eligible'])) $period[$periodKey]['employees'][$employeeId] = $this->name($period[$periodKey]['employees'][$employeeId] ?? '', $employeeName);
            // A member may have several service lines in one day or period.
            // Keep one employee visit, with an independently settled role taking precedence over a normal role.
            $allocationGroup = (string)$line['allocation_group'];
            $daily[$dailyKey]['allocation_groups'][$employeeId] = $this->preferredAllocationGroup($daily[$dailyKey]['allocation_groups'][$employeeId] ?? null, $allocationGroup);
            if (!empty($line['people_eligible'])) $period[$periodKey]['allocation_groups'][$employeeId] = $this->preferredAllocationGroup($period[$periodKey]['allocation_groups'][$employeeId] ?? null, $allocationGroup);
        }

    }

    /** One allocation algorithm serves both detailed rows and compact totals. */
    private function projectGroups(array $daily, array $period, array $lines, bool $details): array
    {
        $detail = [];
        $peopleDetail = [];
        $detailContext = [];
        $summary = [];
        $dailyTotals = [];
        $summaryNameOrder = []; $dailyNameOrder = [];
        if (!$details) uasort($daily, static function (array $left, array $right): int { return $left['first_order'] <=> $right['first_order']; });
        foreach ($daily as $dailyKey => $group) {
            $parts = $this->splitAllocationGroups($group['allocation_groups']);
            [$storeId, $businessDate] = explode('|', $dailyKey, 3);
            foreach ($parts as $employeeId => $tenths) {
                $employeeName = (string)($group['employees'][$employeeId] ?? '');
                $summaryKey = (int)$storeId . '|' . $employeeId;
                $dailyTotalKey = (int)$storeId . '|' . $businessDate . '|' . $employeeId;
                $this->initSummary($summary, $summaryKey, $employeeName);
                if (!$details && !isset($summaryNameOrder[$summaryKey])) $summaryNameOrder[$summaryKey] = $employeeName !== '' ? $group['first_order'] : null;
                if (!$details) $dailyNameOrder[$dailyTotalKey] = $group['first_order'];
                if (!isset($dailyTotals[$dailyTotalKey])) $dailyTotals[$dailyTotalKey] = ['visit_tenths' => 0, 'employee_name' => $employeeName];
                $summary[$summaryKey]['visit_tenths'] += $tenths;
                // 点客属于被明确指定的技师，不按共同服务人数分摊。
                // 同一客人在同门店同一天的多项目只计一次点客次。
                if (isset($group['point_employees'][$employeeId])) $summary[$summaryKey]['point_visit_tenths'] += 10;
                $dailyTotals[$dailyTotalKey]['visit_tenths'] += $tenths;
                $dailyTotals[$dailyTotalKey]['employee_name'] = $this->name($dailyTotals[$dailyTotalKey]['employee_name'], $employeeName);
                if (!$details) continue;
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
                if (!$details) continue;
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
        ] + ($details ? [] : ['_summary_name_order' => $summaryNameOrder, '_daily_name_order' => $dailyNameOrder]);
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(string $tenantId, array $stores, array $range): array
    {
        return $this->serviceQuery($tenantId, $stores, $range)->order('p.id', 'asc')->select()->toArray();
    }

    /**
     * Keep a complete customer and complete service contiguous. MySQL's default
     * buffered cursor also materializes the whole result, so disable buffering
     * only for this statement and close it before restoring the shared connection.
     * No other query is issued while this iterator is active.
     */
    private function aggregateRows(string $tenantId, array $stores, array $range, bool $denominatorOnly = false, array $serviceFactIds = []): \Generator
    {
        $query = $this->serviceQuery($tenantId, $stores, $range, $denominatorOnly, $serviceFactIds)->order('sv.store_id', 'asc');
        // Staff visit allocation needs customer-contiguous rows; the dashboard
        // denominator only needs service-contiguous rows and skips costly
        // origin-payment checks, without changing its effective participants.
        if (!$denominatorOnly) $query->orderRaw("CASE WHEN COALESCE(sv.friend_counts_as_customer,1)=1 AND LOWER(TRIM(sv.service_object))='self' AND sv.member_id>0 THEN CONCAT('member:',sv.member_id) ELSE CONCAT('record:',sv.service_fact_id) END ASC");
        $query->order('sv.service_fact_id', 'asc')->order('p.id', 'asc');
        if (!$query instanceof \think\db\Query) {
            // Injectable sources obey the same ordered streaming contract.
            yield from $query->cursor();
            return;
        }
        // Clone only the ORM statement holder, as Query::cursor() itself
        // does. Both holders retain the same PDO/read transaction.
        $connection = clone $query->getConnection();
        $pdo = $connection->getPdo() ?: $connection->connect();
        $buffered = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $cursor = null;
        try {
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $cursor = $connection->cursor($query);
            yield from $cursor;
        } finally {
            unset($cursor);
            $connection->free(); // Release the statement even after a contract rejection.
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }

    /** Same fact joins, normal-service scope and source fields for both projections. */
    private function serviceQuery(string $tenantId, array $stores, array $range, bool $denominatorOnly = false, array $serviceFactIds = [])
    {
        $query = call_user_func($this->queryFactory, 'cashier_v3_entitlement_service_fact')->alias('sv')
            ->leftJoin('cashier_v3_performance_fact p', "p.tenant_id=sv.tenant_id AND p.checkout_request_id=sv.checkout_request_id AND p.source_line_id=sv.source_line_id AND p.status='effective' AND p.performance_type='labor_performance_allocated'")
            ->leftJoin('cashier_v3_service_customer_group_fact cg', 'cg.tenant_id=p.tenant_id AND cg.performance_fact_id=p.fact_id AND cg.store_id=sv.store_id AND cg.service_fact_id=sv.id AND cg.employee_id=p.employee_id')
            ->where('sv.tenant_id', $tenantId)->whereIn('sv.store_id', $stores)
            ->whereBetween('sv.business_date', [$range['start'], $range['end']])
            ->where('sv.service_status', 'completed');
        if ($serviceFactIds !== []) $query->whereIn('sv.service_fact_id', $serviceFactIds);
        call_user_func($this->normalServices, $query, 'sv');
        return $query->field('p.id,p.fact_id,p.fact_direction,p.reversal_of,p.store_id,p.employee_id,p.employee_name_snapshot employee_name,p.role_snapshot,cg.allocation_group_key fact_allocation_group,cg.position_id fact_position_id,cg.performance_independent fact_performance_independent,sv.checkout_request_id,sv.source_line_id,sv.service_fact_id,sv.store_id service_store_id,sv.business_date,sv.member_id,sv.member_name_snapshot member_name,sv.service_object,sv.friend_counts_as_customer,sv.craftsmen_snapshot_json,sv.labor_amount_cents,sv.labor_fee_amount_cents,sv.store_name_snapshot store_name,sv.organization_name_snapshot company_name,sv.project_name_snapshot item_name,sv.project_category_path_snapshot category_path,sv.source_document_type source_type,' . ($denominatorOnly ? '0' : $this->originOtherCollectionSql()) . ' origin_other_collection');
    }

    /**
     * Resolve the settled purchase that created the consumed project/card.
     * A mixed tender still excludes salary people when its committed other
     * collection amount is positive; never infer funding from product names
     * or from the later service checkout's payment selection.
     */
    private function originOtherCollectionSql(): string
    {
        return <<<'SQL'
CASE WHEN sv.source_document_type='sales_order' THEN EXISTS (
    SELECT 1 FROM eb_cashier_v3_sales_order origin_order
    JOIN eb_cashier_v3_checkout_payment_draft origin_payment
      ON origin_payment.tenant_id=origin_order.tenant_id
     AND origin_payment.store_id=origin_order.store_id
     AND origin_payment.request_id=origin_order.checkout_request_id
     AND origin_payment.draft_version=origin_order.checkout_request_version
     AND origin_payment.payment_method='other_collection'
     AND origin_payment.draft_status='committed'
     AND origin_payment.amount_cents>0
    WHERE origin_order.tenant_id=sv.tenant_id
      AND origin_order.id=sv.source_document_id
      AND origin_order.order_status='settled'
      AND origin_order.order_direction='forward'
) ELSE EXISTS (
    SELECT 1 FROM eb_cashier_v3_entitlement_writeoff_fact source_writeoff
    JOIN eb_cashier_v3_card_purchase_receipt issued
      ON issued.tenant_id=source_writeoff.tenant_id
     AND issued.card_holder_id=source_writeoff.holder_id
     AND issued.legacy_order_id=source_writeoff.origin_order_id
     AND issued.status='completed'
    JOIN eb_cashier_v3_sales_order origin_order
      ON origin_order.tenant_id=issued.tenant_id
     AND origin_order.order_id=issued.sales_order_id
     AND origin_order.order_status='settled'
     AND origin_order.order_direction='forward'
    JOIN eb_cashier_v3_checkout_payment_draft origin_payment
      ON origin_payment.tenant_id=origin_order.tenant_id
     AND origin_payment.store_id=origin_order.store_id
     AND origin_payment.request_id=origin_order.checkout_request_id
     AND origin_payment.draft_version=origin_order.checkout_request_version
     AND origin_payment.payment_method='other_collection'
     AND origin_payment.draft_status='committed'
     AND origin_payment.amount_cents>0
    WHERE source_writeoff.tenant_id=sv.tenant_id
      AND source_writeoff.checkout_request_id=sv.checkout_request_id
      AND source_writeoff.source_line_id=sv.source_line_id
      AND source_writeoff.status='effective'
) OR EXISTS (
    SELECT 1 FROM eb_cashier_v3_entitlement_writeoff_fact source_writeoff
    JOIN eb_cashier_v3_card_operation_settlement upgraded
      ON upgraded.tenant_id=source_writeoff.tenant_id
     AND upgraded.target_card_holder_id=source_writeoff.holder_id
     AND upgraded.target_legacy_order_id=source_writeoff.origin_order_id
     AND upgraded.settlement_status='settled'
    JOIN eb_cashier_v3_sales_order origin_order
      ON origin_order.tenant_id=upgraded.tenant_id
     AND origin_order.order_id=upgraded.sales_order_id
     AND origin_order.order_status='settled'
     AND origin_order.order_direction='forward'
    JOIN eb_cashier_v3_checkout_payment_draft origin_payment
      ON origin_payment.tenant_id=origin_order.tenant_id
     AND origin_payment.store_id=origin_order.store_id
     AND origin_payment.request_id=origin_order.checkout_request_id
     AND origin_payment.draft_version=origin_order.checkout_request_version
     AND origin_payment.payment_method='other_collection'
     AND origin_payment.draft_status='committed'
     AND origin_payment.amount_cents>0
    WHERE source_writeoff.tenant_id=sv.tenant_id
      AND source_writeoff.checkout_request_id=sv.checkout_request_id
      AND source_writeoff.source_line_id=sv.source_line_id
      AND source_writeoff.status='effective'
) END
SQL;
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

    /** Keep the original first nonempty daily-group snapshot, not stream order. */
    private function retainSourceName(array &$row, array &$orders, string $key, string $name, ?array $order): void
    {
        if ($name !== '' && $order !== null && (!isset($orders[$key]) || $order < $orders[$key])) {
            $row['employee_name'] = $name; $orders[$key] = $order;
        }
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
