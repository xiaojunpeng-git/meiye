<?php
namespace app\services\report;

use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

/**
 * R51 sales/service/personnel projections of registered V3 facts, never legacy report SQL.
 * Host owns authentication and injects stores/_report_scope. Provider owns result filtering,
 * sorting, totals and pagination. Money stays in cents until the final numeric-yuan boundary.
 */
final class BasicActivityReportServices
{
    private const REPORTS = [
        'basic_store_item_sales' => 'store_item',
        'basic_item_consumption' => 'item_consumption',
        'basic_writeoff_detail' => 'writeoff',
        'basic_employee_summary' => 'employee',
        'basic_employee_item_sales' => 'employee_item',
        'basic_employee_service_store' => 'employee_service',
        'basic_employee_service_home_store' => 'employee_home',
        'basic_employee_performance_detail' => 'performance',
        'basic_product_sales' => 'product',
        'basic_followup_employee_detail' => 'followup',
    ];

    /** Only executable reports are advertised; unresolved legacy semantics fail explicitly. */
    public static function codes(): array { return array_keys(self::REPORTS); }

    /** No connection or catalog lookup: Providers may register these fields before a read. */
    public function definitions(string $code): array
    {
        $kind = $this->kind($code);
        $keys = ['store_id', 'store_name'];
        if (in_array($kind, ['employee', 'employee_item', 'employee_service', 'employee_home', 'performance', 'followup'], true)) {
            $keys = array_merge($keys, ['employee_id', 'employee_name']);
        }
        if (in_array($kind, ['writeoff', 'performance', 'product', 'followup'], true)) $keys = array_merge($keys, ['business_date', 'document_no', 'member_name']);
        if ($kind !== 'employee') $keys = array_merge($keys, ['item_id', 'item_name', 'category_path']);
        $metrics = [
            'store_item' => ['sale_amount', 'cash_performance', 'customer_count'],
            'item_consumption' => ['quantity', 'consumption_amount'],
            'writeoff' => ['quantity', 'consumption_amount', 'service_object'],
            'employee' => ['position_name', 'position_level_name', 'sales_performance', 'labor_performance', 'labor_fee', 'project_count', 'service_visits', 'point_visits', 'labor_unit_price'],
            'employee_item' => ['sales_performance', 'sales_quantity'],
            'employee_service' => ['project_count', 'labor_performance', 'labor_fee'],
            'employee_home' => ['project_count', 'labor_performance', 'labor_fee'],
            'product' => ['quantity', 'sale_amount', 'cash_amount', 'balance_amount', 'salespeople', 'salesperson_ids', 'employee_balance_performance'],
            'followup' => ['followup_staff_id', 'quantity', 'sale_amount'],
            'performance' => ['performance_type', 'sales_performance', 'labor_performance', 'labor_fee', 'project_count'],
        ];
        $labels = [
            'store_id'=>'门店ID', 'store_name'=>'门店', 'employee_id'=>'员工ID', 'employee_name'=>'员工',
            'business_date'=>'业务日期', 'document_no'=>'业务单号', 'member_name'=>'客户名称',
            'item_id'=>'品项ID', 'item_name'=>'品项', 'category_path'=>'当前分类',
            'sale_amount'=>'销售额', 'cash_performance'=>'现金业绩', 'customer_count'=>'销售人数',
            'quantity'=>'服务数量', 'consumption_amount'=>'消耗业绩', 'service_object'=>'服务对象',
            'sales_performance'=>'销售业绩', 'labor_performance'=>'劳动业绩', 'labor_fee'=>'手工费',
            'project_count'=>'分配项目数', 'sales_quantity'=>'分配销售数量', 'performance_type'=>'业绩类型',
            'cash_amount'=>'记账收款分摊', 'balance_amount'=>'余额支付分摊', 'salespeople'=>'销售人员',
            'salesperson_ids'=>'销售人员ID', 'employee_balance_performance'=>'逐人余额业绩（待核实）',
            'followup_staff_id'=>'跟单任职ID',
            'position_name'=>'当前职务', 'position_level_name'=>'当前职级', 'service_visits'=>'客次',
            'point_visits'=>'指定客', 'labor_unit_price'=>'劳动客单价',
        ];
        $money = ['sale_amount', 'cash_performance', 'consumption_amount', 'sales_performance', 'labor_performance', 'labor_fee', 'cash_amount', 'balance_amount', 'employee_balance_performance', 'labor_unit_price'];
        if (in_array($kind, ['product','followup'], true)) $labels['quantity'] = '销售数量';
        if ($kind === 'followup') $labels['employee_name'] = '跟单人员';
        $numbers = ['store_id', 'employee_id', 'item_id', 'followup_staff_id', 'quantity', 'customer_count', 'project_count', 'sales_quantity'];
        $fields = [];
        foreach (array_merge($keys, $metrics[$kind]) as $key) {
            $isMoney = in_array($key, $money, true);
            $isId = substr($key, -3) === '_id';
            $fields[] = [
                'key'=>$key, 'label'=>$labels[$key],
                'type'=>$isMoney || in_array($key, ['quantity','project_count','sales_quantity','service_visits','point_visits'], true) ? 'decimal'
                    : (in_array($key, $numbers, true) ? 'integer' : ($key === 'business_date' ? 'date' : 'text')),
                'defaultVisible'=>!$isId, 'defaultQuick'=>in_array($key, ['store_name','employee_name','item_name','business_date'], true),
                'width'=>$isMoney ? 130 : ($key === 'document_no' ? 190 : 150),
                'fixed'=>in_array($key, ['store_name','employee_name'], true) ? 'left' : '',
                'money'=>$isMoney,
                // People are deduplicated within an item, so adding across items would double-count.
                'summable'=>($isMoney && !in_array($key,['employee_balance_performance','balance_amount','labor_unit_price'],true)) || in_array($key, ['quantity','project_count','sales_quantity','service_visits','point_visits'], true),
            ];
        }
        return $fields;
    }

    /**
     * Complete scoped population, with stable identity and explicit non-additive dimensions.
     * A rejected/oversized Reader result propagates; no partial rows or invented footer is returned.
     */
    public function read(string $code, array $stores, array $input): array
    {
        $fields = $this->definitions($code);
        $kind = $this->kind($code);
        [$stores, $range, $employeeIds] = $this->scope($stores, $input);
        $rows = [];
        if ($stores) {
            // All constituent Reader calls and snapshot enrichment share one consistent DB view.
            $rows = Db::transaction(function () use ($kind, $stores, $range, $employeeIds): array {
                $this->guardSourceSize($kind, $stores, $range, $employeeIds);
                if ($kind === 'store_item') return $this->storeItems($stores, $range, $employeeIds);
                if ($kind === 'product') return $this->products($stores, $range, $employeeIds);
                if ($kind === 'followup') return $this->followups($stores, $range, $employeeIds);
                if (in_array($kind, ['item_consumption', 'writeoff'], true)) return $this->services($kind, $stores, $range, $employeeIds);
                return $this->personnel($kind, $stores, $range, $employeeIds);
            });
        }
        if (count($rows) > 10000) throw new \InvalidArgumentException('基础报表结果超过10000行，请缩小查询范围');
        usort($rows, static function ($a, $b) { return strcmp($a['row_id'], $b['row_id']); });
        $seen = [];
        foreach ($rows as &$row) {
            if (!in_array((int)$row['store_id'], $stores, true) || isset($seen[$row['row_id']])) throw new \LogicException('基础报表事实范围或行身份异常');
            $seen[$row['row_id']] = true;
            foreach ($fields as $field) {
                $key = $field['key'];
                if ($field['money']) $row[$key] = array_key_exists($key, $row) && $row[$key] === null ? null : ((int)($row[$key] ?? 0)) / 100;
                elseif (!array_key_exists($key, $row)) $row[$key] = in_array($field['type'], ['integer','decimal'], true) ? null : '';
            }
        }
        unset($row);
        $sources = [];
        foreach ($fields as $field) $sources[$field['key']] = $this->source($field['key'], $kind);
        return ['rows'=>$rows, 'fields'=>$fields, 'field_sources'=>$sources,
            'sum_fields'=>array_column(array_filter($fields, static function ($f) { return $f['summable']; }), 'key'),
            'metric_version'=>'r51-activity-v1', 'coverage_start'=>StoreUnifiedReportServices::COVERAGE_START,
            'data_as_of'=>date('c'), 'scope'=>['store_ids'=>$stores, 'date_range'=>$range],
            'fact_completeness'=>'仅V3正式事实；不混入旧订单，未回填历史',
            'limitations'=>$kind === 'followup' ? ['仅覆盖统一销售事实经正式签发凭据关联原订单、且原单明确保存跟单身份的明细；未记录跟单不计入。不混入导购、销售经理或旧订单财务金额。']
                : ($kind === 'store_item' ? ['卡内项目按已冻结金额分摊；没有可靠组件销售数量，不用整卡次数冒充项目销售数量。']
                : ($kind === 'product' ? ['销售明细粒度，付款按明细分摊；逐人余额业绩无可靠事实，返回null，不能按比例伪造。']
                : ($kind === 'employee_home' ? ['查询时当前唯一门店归属；只统计授权服务门店内的事实，多店任职或归属缺失时报错。']
                : ($kind === 'employee_item' ? ['仅直接销售项目，数量使用冻结的销售分配权重；未将整卡销售次数当作卡内项目销售数。'] : [])))),
        ];
    }

    /** Unknown reports never silently fall back to another grain. */
    private function kind(string $code): string
    {
        if (!isset(self::REPORTS[$code])) throw new \InvalidArgumentException('未实现的基础活动报表');
        return self::REPORTS[$code];
    }

    /** Validate before any query. Host stores are the hard boundary; input may only narrow it. */
    private function scope(array $stores, array $input): array
    {
        $ids = [];
        foreach ($stores as $id) {
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int)$id < 1) throw new \InvalidArgumentException('授权门店无效');
            $ids[(int)$id] = (int)$id;
        }
        $stores = array_values($ids);
        $scope = $input['_report_scope'] ?? [];
        if (!is_array($scope)) throw new \InvalidArgumentException('报表权限无效');
        $mode = $scope['mode'] ?? 'stores';
        if (!in_array($mode, ['stores','all','self_participant'], true)) throw new \InvalidArgumentException('当前权限不能读取活动报表');
        $employeeIds = [];
        if ($mode === 'self_participant') {
            $employee = (int)($scope['employee_id'] ?? 0);
            if ($employee < 1) throw new \InvalidArgumentException('本人参与范围缺少员工身份');
            $employeeIds = [$employee];
        }
        if (isset($input['store_id']) && $input['store_id'] !== '') $stores = array_values(array_intersect($stores, [(int)$input['store_id']]));
        if (isset($input['store_ids'])) {
            if (!is_array($input['store_ids'])) throw new \InvalidArgumentException('门店筛选无效');
            $stores = array_values(array_intersect($stores, array_map('intval', $input['store_ids'])));
        }
        $range = [];
        foreach (['start'=>'start_date','end'=>'end_date'] as $key=>$inputKey) $range[$key] = $this->date($input[$inputKey] ?? null);
        if ($range['start'] > $range['end']) throw new \InvalidArgumentException('日期范围无效');
        if (isset($input['as_of_date'])) $this->date($input['as_of_date']);
        // No silent date clamp: metadata must describe exactly the requested covered population.
        if ($range['start'] < StoreUnifiedReportServices::COVERAGE_START) throw new \InvalidArgumentException('日期早于V3事实覆盖期');
        return [$stores, $range, $employeeIds];
    }

    private function date($value): string
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('日期须为有效YYYY-MM-DD');
        return $value;
    }

    /** Registered category readers own card expansion, current category identity and cash allocation. */
    private function category(string $metric, array $stores, array $range, array $employees): array
    {
        $reader = RegisteredMetricReadServices::withCurrentReportProductCategory();
        $rows = $employees
            ? $reader->categoryRowsForParticipant($metric, '0', $stores, $range, [], [], $employees[0])
            : $reader->categoryRows($metric, '0', $stores, $range);
        $this->checkSize($rows);
        return $rows;
    }

    private function storeItems(array $stores, array $range, array $employees): array
    {
        $grouped = []; $customers = [];
        foreach (['sales_amount'=>'sale_amount','cash_performance'=>'cash_performance'] as $metric=>$target) {
            $facts = $this->category($metric, $stores, $range, $employees);
            $names = $this->saleSnapshots($stores, array_column($facts, 'fact_id'));
            foreach ($facts as $fact) {
                // Recharge has its own report. Unresolved item identity remains in an
                // explicit unclassified bucket so missing dimensions never erase money.
                if (($fact['product_type_snapshot'] ?? '') === 'recharge') continue;
                $key = $this->identity([$fact['store_id'], $fact['item_id']]);
                $snapshot = $names[$fact['fact_id'] ?? ''] ?? [];
                if (!isset($grouped[$key])) $grouped[$key] = $this->item($fact) + ['row_id'=>'store_item:'.$key,'sale_amount'=>0,'cash_performance'=>0,'customer_count'=>0];
                if ((int)($fact['item_id'] ?? 0) < 1) $grouped[$key]['item_name'] = '未关联品项';
                $grouped[$key][$target] += (int)$fact['amount_cents'];
                if ($grouped[$key]['item_name'] === '' && (int)($snapshot['item_id'] ?? 0) === (int)$fact['item_id']) $grouped[$key]['item_name'] = (string)$snapshot['item_name_snapshot'];
                if ($metric === 'sales_amount') {
                    $member = (int)($fact['member_id'] ?? 0);
                    $customer = $member > 0 ? $fact['business_date'].':'.$member : 'line:'.$fact['order_id'].':'.$fact['source_line_id'];
                    $customers[$key][$customer] = true;
                }
            }
        }
        foreach ($grouped as $key=>&$row) $row['customer_count'] = count($customers[$key] ?? []);
        unset($row);
        return array_values($grouped);
    }

    /** Service quantity is project-level, consumption is independent of the number of craftsmen. */
    private function services(string $kind, array $stores, array $range, array $employees): array
    {
        $consumption = [];
        foreach ($this->category('consume_amount', $stores, $range, $employees) as $fact) {
            $key = $this->identity([$fact['store_id'], $fact['checkout_request_id'], $fact['source_line_id']]);
            $consumption[$key] = ($consumption[$key] ?? 0) + (int)$fact['amount_cents'];
        }
        $facts = $this->category('completed_service_item_count', $stores, $range, $employees);
        $snapshots = $this->serviceSnapshots($stores, array_column($facts, 'service_fact_id'));
        $rows = [];
        foreach ($facts as $fact) {
            $detail = $snapshots[$fact['service_fact_id']] ?? null;
            if (!$detail) throw new \LogicException('服务事实快照缺失');
            $line = $this->identity([$fact['store_id'], $detail['checkout_request_id'], $fact['source_line_id']]);
            $row = $this->item($fact) + [
                'row_id'=>'writeoff:'.$fact['service_fact_id'], 'business_date'=>$fact['business_date'],
                'document_no'=>$detail['document_no_snapshot'], 'member_name'=>$detail['member_name_snapshot'],
                'quantity'=>(float)$fact['quantity'], 'consumption_amount'=>$consumption[$line] ?? 0,
                'service_object'=>$detail['service_object'],
            ];
            if ($kind === 'writeoff') $rows[] = $row;
            else {
                $key = $this->identity([$row['store_id'], $row['item_id']]);
                if (!isset($rows[$key])) $rows[$key] = $this->item($fact) + ['row_id'=>'item_consumption:'.$key, 'quantity'=>0, 'consumption_amount'=>0];
                $rows[$key]['quantity'] += $row['quantity'];
                $rows[$key]['consumption_amount'] += $row['consumption_amount'];
            }
        }
        return array_values($rows);
    }

    /** Personnel money comes from final sales allocations and registered labor/project-count readers. */
    private function personnel(string $kind, array $stores, array $range, array $employees): array
    {
        $rows = [];
        if (in_array($kind, ['employee','employee_item','performance'], true)) {
            $sales = (new StoreSalespersonNetReadServices())->detail($stores, $range, $employees, 0, 1, 20, true)['records'];
            $this->checkSize($sales);
            $enriched = $this->salesPersonnelSnapshots($stores, array_column($sales, 'fact_id'));
            foreach ($sales as $fact) {
                $extra = $enriched[$fact['fact_id']] ?? [];
                if ($kind === 'employee_item' && (($extra['source_type'] ?? '') !== 'project' || $fact['performance_type'] !== 'sales_performance_allocated')) continue;
                $row = $this->person($fact) + $this->item($extra + $fact) + [
                    'row_id'=>'performance:'.$fact['fact_id'], 'business_date'=>$fact['business_date'],
                    'document_no'=>$fact['performance_type'] === 'refund_sales_deduction' ? $fact['refund_no'] : $fact['order_no_snapshot'],
                    'member_name'=>$fact['member_name'], 'performance_type'=>$fact['performance_type'] === 'refund_sales_deduction' ? '退款冲减' : '销售分配',
                    'sales_performance'=>(int)$fact['amount_cents'], 'labor_performance'=>0, 'labor_fee'=>0, 'project_count'=>0,
                    // Frozen allocation weight, never a count of employees currently on the order.
                    'sales_quantity'=>$fact['performance_type'] === 'sales_performance_allocated' && isset($extra['quantity']) && (int)$extra['allocation_weight_denominator'] > 0
                        ? (float)$extra['quantity'] * (int)$extra['allocation_weight_numerator'] / (int)$extra['allocation_weight_denominator'] : 0,
                ];
                $this->appendPersonnel($rows, $row, $kind);
            }
        }
        if ($kind !== 'employee_item') {
            $reader = new RegisteredMetricReadServices();
            $labor = $reader->personnelDetailResult('staff_labor_yeji', '0', $stores, $range, $employees, 0, true)['rows'];
            $counts = [];
            foreach ($reader->personnelDetailResult('staff_project_num', '0', $stores, $range, $employees, 0, true)['rows'] as $fact) $counts[$fact['fact_id']] = $fact['metric_value'];
            $details = $this->laborSnapshots($stores, array_column($labor, 'fact_id'));
            foreach ($labor as $fact) {
                $extra = $details[$fact['fact_id']] ?? [];
                $row = $this->person($fact) + $this->item($extra + $fact) + [
                    'row_id'=>'performance:'.$fact['fact_id'], 'business_date'=>$fact['business_date'],
                    'document_no'=>$fact['order_no_snapshot'], 'member_name'=>$fact['member_name_snapshot'],
                    'performance_type'=>'劳动分配', 'sales_performance'=>0, 'sales_quantity'=>0,
                    'labor_performance'=>(int)$fact['metric_value'], 'labor_fee'=>(int)$fact['labor_fee_amount_cents'],
                    'project_count'=>(int)($counts[$fact['fact_id']] ?? 0) / 1000000,
                ];
                $this->appendPersonnel($rows, $row, $kind);
            }
        }
        if ($kind === 'employee') return $this->employeeSummary($rows, $stores, $range, $employees);
        if ($kind === 'employee_home') return $this->homeStores(array_values($rows), $stores);
        return array_values($rows);
    }

    /**
     * Period visits come from the registered customer allocator, not service-line counts.
     * Keep integer tenths until the final count boundary and never prefilter co-workers
     * before the Reader has determined their shares. Zero-value services can introduce
     * a legitimate employee row even without a monetary performance fact.
     */
    private function employeeSummary(array $rows, array $stores, array $range, array $employees): array
    {
        $reader = new RegisteredMetricReadServices(null, null, null, null, true);
        $visits = [];
        $storeNames = [];
        foreach ($reader->personnelDailyTotals('staff_service_num', '0', $stores, $range, $employees, true) as $daily) {
            $key = $this->identity([$daily['store_id'], $daily['employee_id']]);
            if (!isset($rows[$key])) {
                if (!$storeNames) $storeNames = $reader->storeNames($stores);
                $rows[$key] = ['row_id'=>'employee:'.$key, 'store_id'=>(int)$daily['store_id'],
                    'store_name'=>$storeNames[(int)$daily['store_id']] ?? '',
                    'employee_id'=>(int)$daily['employee_id'], 'employee_name'=>(string)$daily['employee_name'],
                    'sales_performance'=>0, 'labor_performance'=>0, 'labor_fee'=>0, 'project_count'=>0];
            }
            $visits[$key] = ($visits[$key] ?? 0) + (int)$daily['metric_value'];
        }
        $this->checkSize($rows);
        if (!$rows) return [];
        $pairs = []; $byStore = [];
        foreach ($rows as $row) {
            $pair = ['store_id'=>(int)$row['store_id'], 'employee_id'=>(int)$row['employee_id']];
            $pairs[] = $pair; $byStore[$pair['store_id']][] = $pair;
        }
        $positions = (new EmployeeCurrentPositionDimensionServices())->resolve($pairs);
        $levels = $this->employeeCurrentLevels($pairs);
        $points = [];
        // The point metric has a registered period-total shape, not a daily shape.
        // Its result merges employee IDs, so query each store separately to retain row grain.
        foreach ($byStore as $storeId=>$storePairs) {
            foreach ($reader->personnelTotals('staff_point_visit_num', '0', [(int)$storeId], $range, $storePairs) as $point) {
                $key = $this->identity([$storeId, $point['employee_id']]);
                $points[$key] = ($points[$key] ?? 0) + (int)$point['metric_value'];
            }
        }
        foreach ($rows as $key=>&$row) {
            $pairKey = $row['store_id'].'|'.$row['employee_id'];
            $row['position_name'] = $positions[$pairKey] ?? null;
            $row['position_level_name'] = $levels[$pairKey] ?? null;
            $tenths = $visits[$key] ?? 0;
            $row['service_visits'] = $tenths / 10;
            $row['point_visits'] = ($points[$key] ?? 0) / 10;
            $row['labor_unit_price'] = $tenths > 0 ? $this->laborUnitPriceCents((int)$row['labor_performance'], $tenths) : null;
        }
        unset($row);
        return array_values($rows);
    }

    /**
     * Current grade belongs to the active staff appointment, not a historical salary result.
     * Prefer the exact business-store appointment; otherwise accept only one unambiguous
     * grade across current appointments. Missing/disabled/ambiguous grades remain null.
     */
    private function employeeCurrentLevels(array $pairs): array
    {
        $appointments = Db::name('system_store_staff')->alias('ss')
            ->leftJoin('position_level l', 'l.id=ss.position_level AND l.status=1')
            ->whereIn('ss.employee_id', array_unique(array_column($pairs, 'employee_id')))
            ->where('ss.status', 1)->where('ss.is_del', 0)
            ->field('ss.employee_id,ss.store_id,ss.position_level,l.name level_name')->select()->toArray();
        $exact = []; $fallback = [];
        foreach ($appointments as $appointment) {
            $employee = (int)$appointment['employee_id']; $level = (int)$appointment['position_level'];
            $label = trim((string)($appointment['level_name'] ?? ''));
            $exact[(int)$appointment['store_id'].'|'.$employee][$level] = $label;
            $fallback[$employee][$level] = $label;
        }
        $out = [];
        foreach ($pairs as $pair) {
            $key = $pair['store_id'].'|'.$pair['employee_id'];
            $candidates = $exact[$key] ?? $fallback[$pair['employee_id']] ?? [];
            if (count($candidates) === 1 && reset($candidates) !== '') $out[$key] = reset($candidates);
        }
        return $out;
    }

    /** Round the derived ratio to a cent using integer decimal arithmetic; zero division is handled above. */
    private function laborUnitPriceCents(int $laborCents, int $visitTenths): int
    {
        $numerator = bcmul(ltrim((string)$laborCents, '-'), '10', 0);
        $rounded = bcdiv(bcadd($numerator, (string)intdiv($visitTenths, 2), 0), (string)$visitTenths, 0);
        return ($laborCents < 0 ? -1 : 1) * (int)$rounded;
    }

    /** Group by stable IDs, not mutable labels. Detail retains the fact identity instead. */
    private function appendPersonnel(array &$rows, array $row, string $kind): void
    {
        if ($kind === 'performance') { $rows[] = $row; return; }
        $parts = [$row['store_id'], $row['employee_id']];
        if ($kind !== 'employee') $parts[] = $row['item_id'];
        $key = $this->identity($parts);
        if (!isset($rows[$key])) {
            $rows[$key] = $row;
            $rows[$key]['row_id'] = $kind.':'.$key;
            foreach (['sales_performance','labor_performance','labor_fee','project_count','sales_quantity'] as $metric) $rows[$key][$metric] = 0;
        }
        foreach (['sales_performance','labor_performance','labor_fee','project_count','sales_quantity'] as $metric) $rows[$key][$metric] += $row[$metric];
    }

    private function item(array $fact): array
    {
        return ['store_id'=>(int)$fact['store_id'], 'store_name'=>(string)($fact['store_name_snapshot'] ?? $fact['store_name'] ?? ''),
            'item_id'=>(int)($fact['item_id'] ?? $fact['project_id'] ?? 0),
            'item_name'=>(string)($fact['item_name'] ?? $fact['project_name_snapshot'] ?? $fact['item_name_snapshot'] ?? ''),
            'category_path'=>(string)($fact['category_path'] ?? '')];
    }

    private function person(array $fact): array
    {
        return ['employee_id'=>(int)$fact['employee_id'], 'employee_name'=>(string)($fact['employee_name'] ?? $fact['employee_name_snapshot'] ?? '')];
    }

    /** PDO may return an ID as int or decimal text; both must identify the same business row. */
    private function identity(array $parts): string { return hash('sha256', json_encode(array_map('strval', $parts))); }

    /** Enrichment is limited to already-authorized IDs; it must never introduce or sum facts. */
    private function saleSnapshots(array $stores, array $ids): array
    {
        if (!$ids) return [];
        return Db::name('cashier_v3_sale_fact')->where('tenant_id','0')->whereIn('store_id',$stores)->whereIn('fact_id',array_unique($ids))
            ->column('fact_id,store_id,store_name_snapshot,business_date,order_id,source_line_id,order_no_snapshot,member_name_snapshot,source_type,item_id,item_name_snapshot,quantity,sale_amount_cents', 'fact_id');
    }

    /** Fail rather than publish a truncated source population, even if PHP grouping would hide it. */
    private function checkSize(array $rows): void
    {
        if (count($rows) > 10000) throw new \InvalidArgumentException('基础报表源数据超过10000行，请缩小范围');
    }

    /**
     * A bounded existence check, not a report dataset: exceeding the source budget rejects
     * the entire read before expensive correlated reversal queries. Count original effective
     * candidates conservatively, including adjustment history, rather than pretending a limit
     * has returned a complete business population. All predicates remain within Host scope.
     */
    private function guardSourceSize(string $kind, array $stores, array $range, array $employees): void
    {
        $tables = in_array($kind,['store_item','product','followup'],true)
            ? ['cashier_v3_sale_fact','cashier_v3_payment_sale_allocation_fact']
            : (in_array($kind,['writeoff','item_consumption'],true)
                ? ['cashier_v3_entitlement_service_fact','cashier_v3_performance_fact'] : ['cashier_v3_performance_fact']);
        // Customer counts also read zero-value service snapshots with no personnel money fact.
        if ($kind === 'employee') $tables[] = 'cashier_v3_entitlement_service_fact';
        foreach ($tables as $table) {
            $query = Db::name($table)->where('tenant_id','0')->whereIn('store_id',$stores)
                ->whereBetween('business_date',[$range['start'],$range['end']]);
            if ($table === 'cashier_v3_entitlement_service_fact') $query->where('service_status','completed');
            else $query->where('status','effective');
            if ($table === 'cashier_v3_performance_fact') {
                if (in_array($kind,['writeoff','item_consumption'],true)) $query->where('performance_type','consumption_performance_recorded');
                else {
                    $types = in_array($kind,['employee_service','employee_home'],true) ? ['labor_performance_allocated']
                        : ($kind === 'employee_item' ? ['sales_performance_allocated'] : ['sales_performance_allocated','refund_sales_deduction','labor_performance_allocated']);
                    $query->where('employee_id','>',0)->whereIn('performance_type',$types);
                    if ($employees) $query->whereIn('employee_id',$employees);
                }
            }
            $this->checkSize($query->field('id')->limit(10001)->select()->toArray());
        }
    }

    /** Query-time staff ownership mirrors the old home-store report, with ambiguity rejected. */
    private function homeStores(array $rows, array $stores): array
    {
        if (!$rows) return [];
        $memberships = Db::name('system_store_staff')->alias('ss')->leftJoin('system_store st','st.id=ss.store_id')
            ->whereIn('ss.employee_id',array_unique(array_column($rows,'employee_id')))
            ->where('ss.status',1)->where('ss.is_del',0)
            ->field('ss.employee_id,ss.store_id,st.name store_name')->select()->toArray();
        $homes = [];
        foreach ($memberships as $membership) $homes[(int)$membership['employee_id']][(int)$membership['store_id']] = $membership['store_name'];
        $grouped = [];
        foreach ($rows as $row) {
            $home = $homes[$row['employee_id']] ?? [];
            if (count($home) !== 1) throw new \InvalidArgumentException('员工'.$row['employee_id'].'当前所属门店缺失或存在多店歧义');
            $homeId = (int)array_key_first($home);
            if (!in_array($homeId,$stores,true)) continue;
            $serviceStore = $row['store_id'];
            $row['store_id'] = $homeId; $row['store_name'] = (string)$home[$homeId];
            $key = $this->identity([$homeId,$row['employee_id'],$row['item_id']]);
            $this->appendPersonnel($grouped,$row,'employee_home');
            // Retain execution-store evidence when several authorized service stores collapse into one home row.
            $grouped[$key]['service_store_ids'][] = $serviceStore;
            $grouped[$key]['service_store_ids'] = array_values(array_unique($grouped[$key]['service_store_ids']));
        }
        return array_values($grouped);
    }

    /**
     * Product rows retain sale-line grain: money/quantity appear once, and salespeople are a dimension.
     * Cash uses the registered allocation Reader; balance uses the frozen order-line allocation,
     * never a recalculation by payment ratio or a guessed employee balance performance.
     */
    private function products(array $stores, array $range, array $employees): array
    {
        $facts = $this->category('sales_amount',$stores,$range,$employees);
        $sales = $this->saleSnapshots($stores,array_column($facts,'fact_id'));
        $cash = [];
        foreach ($this->category('cash_performance',$stores,$range,$employees) as $fact) {
            if (!isset($fact['sale_fact_id'])) continue;
            $cash[$fact['sale_fact_id']] = ($cash[$fact['sale_fact_id']] ?? 0) + (int)$fact['amount_cents'];
        }
        $balances = []; $people = [];
        if ($sales) {
            $lines = Db::name('cashier_v3_sales_order_line')->alias('l')
                ->leftJoin('cashier_v3_order_balance_allocation b','b.tenant_id=l.tenant_id AND b.store_id=l.store_id AND b.order_line_id=l.order_line_id')
                ->where('l.tenant_id','0')->whereIn('l.store_id',$stores)->whereIn('l.checkout_line_id',array_column($sales,'source_line_id'))
                ->where('l.line_direction','forward')->field('l.store_id,l.order_id,l.checkout_line_id,b.amount_cents')->select()->toArray();
            foreach ($lines as $line) $balances[$this->identity([$line['store_id'],$line['order_id'],$line['checkout_line_id']])] = $line['amount_cents'] === null ? null : (int)$line['amount_cents'];
            $allocations = (new StoreSalespersonNetReadServices())->detail($stores,$range,$employees,0,1,20,true)['records'];
            $this->checkSize($allocations);
            foreach ($allocations as $allocation) {
                if ($allocation['performance_type'] !== 'sales_performance_allocated') continue;
                $key = $this->identity([$allocation['store_id'],$allocation['order_id'],$allocation['source_line_id']]);
                $people[$key][(int)$allocation['employee_id']] = $allocation['employee_name'];
            }
        }
        $rows = [];
        foreach ($facts as $fact) {
            $sale = $sales[$fact['fact_id']] ?? [];
            if (($sale['source_type'] ?? '') !== 'product') continue;
            $key = $this->identity([$sale['store_id'],$sale['order_id'],$sale['source_line_id']]);
            $staff = $people[$key] ?? []; ksort($staff);
            $rows[] = $this->item($fact + ['item_name'=>$sale['item_name_snapshot']]) + [
                'row_id'=>'product:'.$fact['fact_id'], 'business_date'=>$sale['business_date'],
                'document_no'=>$sale['order_no_snapshot'],'member_name'=>$sale['member_name_snapshot'],
                'quantity'=>(float)$sale['quantity'], 'sale_amount'=>(int)$fact['amount_cents'],
                'cash_amount'=>$cash[$fact['fact_id']] ?? 0,
                // No allocation can mean historical coverage is missing; never claim a made-up zero.
                'balance_amount'=>$balances[$key] ?? null,
                'salespeople'=>implode('、',array_values($staff)), 'salesperson_ids'=>implode(',',array_keys($staff)),
                'employee_balance_performance'=>null,
            ];
        }
        return $rows;
    }

    /**
     * Follow-up is a supplemental identity, never a financial source. The issuance receipt
     * explicitly links a V3 sale line to a legacy order/base cart: numeric ID coincidence
     * or matching order names cannot establish this relationship. Multiple issued cards
     * on a sale line must agree on one staff identity, or no full-line amount can be assigned.
     */
    private function followups(array $stores, array $range, array $employees): array
    {
        // Scope by stores first. Personal scope here means the explicitly recorded follow-up
        // employee, not the unrelated salesperson/guide participant of the same order.
        $facts = $this->category('sales_amount',$stores,$range,[]);
        $sales = $this->personnelCategories($this->saleSnapshots($stores,array_column($facts,'fact_id')),false);
        if (!$sales) return [];
        $dimensions = Db::name('cashier_v3_card_purchase_receipt')->alias('r')
            ->join('cashier_v3_sales_order_line l','l.tenant_id=r.tenant_id AND l.store_id=r.store_id AND l.order_id=r.sales_order_id AND l.order_line_id=r.sales_order_line_id')
            ->join('store_order o','o.id=r.legacy_order_id AND o.store_id=r.store_id AND o.uid=r.member_id')
            ->join('store_order_cart_info c','c.id=r.base_cart_id AND c.oid=o.id AND c.cart_type=0')
            ->join('system_store_staff ss','ss.id=o.gendan_staff_id')
            ->where('r.tenant_id','0')->whereIn('r.store_id',$stores)->where('r.status','completed')
            ->where('o.is_gendan',1)->where('o.gendan_staff_id','>',0)
            ->whereIn('l.checkout_line_id',array_column($sales,'source_line_id'))
            ->field('r.store_id,r.sales_order_id,l.checkout_line_id,ss.id followup_staff_id,ss.employee_id,ss.staff_name employee_name')
            ->select()->toArray();
        $this->checkSize($dimensions);
        $byLine = [];
        foreach ($dimensions as $dimension) {
            $key = $this->identity([$dimension['store_id'],$dimension['sales_order_id'],$dimension['checkout_line_id']]);
            $byLine[$key][(int)$dimension['followup_staff_id']] = $dimension;
        }
        $rows = [];
        foreach ($sales as $sale) {
            $key = $this->identity([$sale['store_id'],$sale['order_id'],$sale['source_line_id']]);
            $participants = $byLine[$key] ?? [];
            if (!$participants) continue;
            if (count($participants) !== 1) throw new \InvalidArgumentException('同一销售明细的原单跟单身份存在歧义，不能重复分配整行金额');
            $person = reset($participants);
            if ($employees && !in_array((int)$person['employee_id'],$employees,true)) continue;
            $rows[] = $this->item($sale) + $this->person($person) + [
                'row_id'=>'followup:'.$sale['fact_id'].':'.$person['followup_staff_id'],
                'business_date'=>$sale['business_date'],'document_no'=>$sale['order_no_snapshot'],
                'member_name'=>$sale['member_name_snapshot'], 'followup_staff_id'=>(int)$person['followup_staff_id'],
                'quantity'=>(float)$sale['quantity'], 'sale_amount'=>(int)$sale['sale_amount_cents'],
            ];
        }
        return $rows;
    }

    private function serviceSnapshots(array $stores, array $ids): array
    {
        if (!$ids) return [];
        return Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id','0')->whereIn('store_id',$stores)->whereIn('service_fact_id',$ids)
            ->column('service_fact_id,checkout_request_id,document_no_snapshot,member_name_snapshot,service_object', 'service_fact_id');
    }

    private function salesPersonnelSnapshots(array $stores, array $ids): array
    {
        if (!$ids) return [];
        $rows = Db::name('cashier_v3_performance_fact')->alias('p')
            ->leftJoin('cashier_v3_sale_fact s', "s.tenant_id=p.tenant_id AND s.store_id=p.store_id AND s.order_id=p.order_id AND s.source_line_id=p.source_line_id AND s.fact_direction='forward' AND s.status='effective'")
            ->where('p.tenant_id','0')->whereIn('p.store_id',$stores)->whereIn('p.fact_id',$ids)
            ->field('p.fact_id,p.store_id,s.source_type,s.item_id,s.item_name_snapshot,s.quantity,p.allocation_weight_numerator,p.allocation_weight_denominator')->select()->toArray();
        return $this->personnelCategories(array_column($rows,null,'fact_id'), false);
    }

    private function laborSnapshots(array $stores, array $ids): array
    {
        if (!$ids) return [];
        $rows = Db::name('cashier_v3_performance_fact')->alias('p')
            ->join('cashier_v3_entitlement_service_fact s','s.tenant_id=p.tenant_id AND s.store_id=p.store_id AND s.checkout_request_id=p.checkout_request_id AND s.source_line_id=p.source_line_id')
            ->where('p.tenant_id','0')->whereIn('p.store_id',$stores)->whereIn('p.fact_id',$ids)
            ->field('p.fact_id,p.store_id,s.project_id item_id,s.project_name_snapshot item_name')->select()->toArray();
        return $this->personnelCategories(array_column($rows,null,'fact_id'), true);
    }

    /** Current classification uses verified identity; names remain immutable source snapshots. */
    private function personnelCategories(array $rows, bool $service): array
    {
        $links = [];
        foreach ($rows as $id=>$row) $links[$id] = ['store_id'=>(int)$row['store_id'], 'product_id'=>(int)($row['item_id'] ?? 0)];
        $resolver = new StoreReportCurrentProductCategoryServices();
        $categories = $service ? $resolver->resolveVerifiedFactItems($links) : $resolver->resolve($links);
        foreach ($rows as $id=>&$row) $row['category_path'] = $categories[$id]['path'] ?? '';
        unset($row);
        return $rows;
    }

    /** Business wording shared by screen/export metadata; no physical schema leaks into field help. */
    private function source(string $key, string $kind): string
    {
        if ($key === 'store_name' && $kind === 'employee_home') return '按查询时员工唯一有效任职的当前门店归属显示；多店归属不猜测，已授权服务门店仍限制实际服务范围。';
        if ($key === 'employee_name' && $kind === 'followup') return '已关联原订单明确记录的跟单任职对应姓名；该兼容身份没有独立历史姓名快照，按当前任职名称展示，不冒充导购或销售经理。';
        if ($key === 'quantity' && in_array($kind,['product','followup'],true)) return '成功销售事实记录的原销售明细数量，不按支付方式或参与人数重复计算；非金额数字。';
        $sources = [
            'sale_amount'=>'结账成功后按品项记录的销售额；卡内项目使用冻结分摊金额，整单作废不计入。',
            'cash_performance'=>'成功记账收款按原销售品项分摊；欠款补交按补交业务日计入，不含余额支付及未关联品项的收款。',
            'customer_count'=>'每个门店品项内同一会员同一天计一人，游客按销售明细计；不同品项之间不合计人数。',
            'quantity'=>'已完成且未作废服务的项目数量；不是销售数量或分给手艺人的项目数。',
            'consumption_amount'=>'项目实际完成后形成的项目级消耗业绩，不按手艺人人数重复计算。',
            'sales_performance'=>'最终有效的逐人销售分配，退款按退款业务日冲减；被调整替代或已作废分配不计入。',
            'labor_performance'=>'有效服务分给该员工的劳动业绩，按业务日期读取带符号的调整事实，不冒充项目级消耗。',
            'labor_fee'=>'与劳动分配一起保存的独立手工费，不以劳动业绩代替。',
            'project_count'=>'服务完成时最终分给该员工的项目数，保留小数；非金额数字。',
            'sales_quantity'=>'直接销售项目数量乘原销售分配权重；只计有效销售分配，不把补交或退款当成再次销售；非金额数字。',
            'category_path'=>'由已核实的品项身份关联平台商品当前分类；无法核实则留空，不按名称猜测。',
            'cash_amount'=>'查询业务日期内成功记账收款分给该销售明细的金额，不按人数重复显示。',
            'balance_amount'=>'结账时为该订单行冻结的余额支付分摊；历史缺少可靠分摊时留空，不重新按比例计算。为防止缺失值造成伪合计，本列不声明合计。',
            'employee_balance_performance'=>'当前没有可核实的逐人余额业绩事实，留空且不参与合计。',
            'salespeople'=>'本期有效销售分配记录中的销售人员，作为销售明细的人员维度，不增加金额行数。',
            'salesperson_ids'=>'对应销售人员稳定ID，以逗号分隔；不是金额，不可合计。',
            'position_name'=>'读取查询时有效岗位任职：优先本业务门店岗位，其次员工直属岗位，再次其他有效任职；不是历史工资岗位快照。',
            'position_level_name'=>'读取查询时有效门店任职的当前职级，优先本业务门店；否则仅取当前任职中唯一明确的职级，缺失、停用或多职级歧义留空。',
            'service_visits'=>'统一客次规则分给员工的有效服务客次，按顾客、门店、业务日去重分配；原始十分之一单位除以10，非金额数字，不按服务项目条数计数。',
            'point_visits'=>'被明确指定的技师在同门店同一天服务同一顾客计一次指定客，不按共同服务人数平分；统一十分之一单位除以10，非金额数字。',
            'labor_unit_price'=>'本员工本门店查询区间的劳动业绩除以客次，按分四舍五入；客次为0时留空，不把多个员工的客单价相加。',
        ];
        if (isset($sources[$key])) return $sources[$key];
        if (substr($key, -3) === '_id') return '对应业务对象的稳定身份，仅作定位与精确筛选；非金额数字，不可合计。';
        return '对应已授权有效业务事实保存的'.(['store_name'=>'门店名称','employee_name'=>'员工姓名','item_name'=>'品项名称','business_date'=>'业务归属日期','document_no'=>'业务单号','member_name'=>'客户名称','service_object'=>'服务对象','performance_type'=>'业绩业务类型'][$key] ?? $key).'，不按当前名称重写历史。';
    }
}
