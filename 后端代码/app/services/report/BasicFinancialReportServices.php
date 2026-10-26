<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

/** R51 financial projections. Facts are read only; the caller owns a consistent
 * read transaction and supplies authenticated stores/scope. Provider alone owns
 * user filters, sorting, complete-result totals and pagination. */
final class BasicFinancialReportServices
{
    private RegisteredMetricReadServices $metrics;
    private $queries;
    private const MAX_ROWS = 10000;
    private const METRICS = [
        'sales_amount' => '销售额', 'cash_performance' => '现金业绩',
        'refund_performance' => '退款业绩', 'actual_performance' => '实际业绩',
        'consume_amount' => '消耗业绩', 'recharge_amount' => '充值本金',
    ];
    /** Store-only additions must never leak into the daily reconciliation
     * contract. Values remain in the registered unit: cents for money, whole
     * completed project quantities for count, never personnel project shares. */
    private const STORE_SUMMARY_METRICS = [
        'staff_labor_yeji' => '劳动业绩', 'staff_labor_fee' => '手工费',
        'completed_service_item_count' => '服务项目数',
    ];

    /** Injection is for isolated local tests; production defaults to the shared Reader. */
    public function __construct(?RegisteredMetricReadServices $metrics = null, ?callable $queries = null)
    {
        $this->metrics = $metrics ?? new RegisteredMetricReadServices();
        $this->queries = $queries ?? static fn(string $table) => Db::name($table);
    }

    /** Only these five implementations belong to this service. */
    public static function codes(): array
    {
        return ['basic_store_summary', 'basic_cross_store_settlement', 'basic_balance_order_detail',
            'basic_balance_order_summary', 'basic_daily_reconciliation'];
    }

    /** Pure metadata: it must work before the database or framework is booted. */
    public function definitions($code): array
    {
        $this->assertCode($code);
        $fields = ['store_id' => ['门店ID', 'number'], 'store_name' => ['门店', 'text']];
        if ($code === 'basic_cross_store_settlement') {
            $fields += ['stored_value_settlement' => ['储值对账金额', 'money'],
                'card_settlement' => ['次卡对账金额', 'money'], 'settlement_total' => ['合计', 'money']];
        } elseif (in_array($code, ['basic_balance_order_detail', 'basic_balance_order_summary'], true)) {
            if ($code === 'basic_balance_order_detail') {
                $fields += ['business_date' => ['业务日期', 'date'], 'member_id' => ['会员ID', 'number'],
                    'member_name' => ['会员', 'text']];
            }
            $fields += ['balance_amount' => ['余额支付金额', 'money'], 'recharge_amount' => ['同日充值金额', 'money'],
                'balance_minus_recharge' => ['余额支付减同日充值', 'money'],
                'principal_amount' => ['本金净扣款', 'money'], 'bonus_amount' => ['赠金净扣款', 'money'],
                'balance_paid' => ['余额支付', 'money'], 'balance_refunded' => ['余额退回', 'money']];
            if ($code === 'basic_balance_order_detail') $fields['has_recharge'] = ['当天是否有充值', 'text'];
        } else {
            if ($code === 'basic_daily_reconciliation') $fields['business_date'] = ['业务日期', 'date'];
            foreach (self::METRICS as $key => $label) $fields[$key] = [$label, 'money'];
            if ($code === 'basic_store_summary') {
                foreach (self::STORE_SUMMARY_METRICS as $key => $label) {
                    $fields[$key] = [$label, $key === 'completed_service_item_count' ? 'count' : 'money'];
                }
                $fields['consumption_cash_ratio'] = ['消耗/现金比（%）', 'percent'];
            }
            $fields += ['balance_paid' => ['余额支付', 'money'], 'balance_refunded' => ['余额退回', 'money'],
                'principal_amount' => ['本金净扣款', 'money'], 'bonus_amount' => ['赠金净扣款', 'money'],
                'balance_amount' => ['余额净扣款', 'money']];
        }
        $out = [];
        foreach ($fields as $key => [$label, $type]) {
            $out[] = ['key' => $key, 'label' => $label,
                'type' => in_array($type, ['money', 'percent'], true) ? 'decimal'
                    : (in_array($type, ['number', 'count'], true) ? 'integer' : $type),
                'defaultVisible' => !in_array($key, ['store_id', 'source_line_id', 'reversal_of'], true),
                'defaultQuick' => in_array($key, ['store_name', 'order_no', 'member_name', 'business_date'], true),
                'width' => $type === 'money' ? 140 : 180,
                'fixed' => in_array($key, ['store_name', 'order_no'], true) ? 'left' : '',
                'money' => $type === 'money', 'summable' => in_array($type, ['money', 'count'], true)];
        }
        return $out;
    }

    /** Dates bound business_date, not recording time. as_of_date caps the end
     * business day; it does not claim to reconstruct a historical database state.
     * SELF_PARTICIPANT cannot see store finance without a verified financial
     * participant contract, and is explicitly denied instead of widened. */
    public function read($code, array $stores, array $input): array
    {
        $definitions = $this->definitions($code);
        $scope = $input['_report_scope'] ?? [];
        $mode = is_array($scope) ? ($scope['mode'] ?? 'none') : 'none';
        if (!in_array($mode, ['all', 'stores'], true)) {
            throw new \InvalidArgumentException('当前数据权限不支持门店财务报表');
        }
        foreach ($stores as $store) {
            if (!is_int($store) || $store < 1) throw new \InvalidArgumentException('门店范围不合法');
        }
        $stores = array_values(array_unique($stores));
        if (count($stores) > self::MAX_ROWS) $this->tooManyRows();
        sort($stores, SORT_NUMERIC);
        $start = $this->date($input['start_date'] ?? null);
        $end = $this->date($input['end_date'] ?? null);
        $asOf = $this->date($input['as_of_date'] ?? $end);
        if ($start > $end) throw new \InvalidArgumentException('开始日期不能晚于结束日期');
        $range = ['start' => $start, 'end' => min($end, $asOf)];
        $sources = $this->sources($definitions, $code);
        $result = ['rows' => [], 'field_sources' => $sources,
            'sum_fields' => array_column(array_filter($definitions, static fn(array $f): bool => $f['summable']), 'key'),
            'range' => $range, 'as_of_date' => $asOf,
            'complete' => true, 'data_gaps' => [],
            'coverage' => '仅统一事实层已成功记录的业务；不混入旧报表或未迁入的历史业务',
            'data_as_of' => time(), 'metric_version' => in_array($code, ['basic_balance_order_detail', 'basic_balance_order_summary'], true)
                ? 'r51-balance-member-store-day-v2' : 'r51-financial-facts-v1'];
        if ($code === 'basic_store_summary') {
            $result['metric_version'] = 'r51-store-summary-service-metrics-v2';
            // A registered alias backed by staff-share addition is not an
            // independently defined store visit metric. Keep the report usable
            // and explicitly disclose this missing optional measure.
            $result['complete'] = false;
            $result['data_gaps'][] = ['code' => 'R51_STORE_SERVICE_VISIT_UNAVAILABLE',
                'metric_code' => 'service_visit',
                'label' => '服务客次尚缺独立店级口径；现有别名读取员工分配份额，本表不据此拼算门店客次'];
        }
        if ($stores === [] || $range['end'] < $range['start']) return $result;

        if ($code === 'basic_cross_store_settlement') {
            $cross = $this->crossStore($stores, $range);
            $rows = $cross['rows'];
            $result['data_gaps'] = $cross['data_gaps'];
            $result['complete'] = $cross['data_gaps'] === [];
            if (isset($cross['data_as_of'])) $result['data_as_of'] = $cross['data_as_of'];
            if (isset($cross['coverage'])) $result['coverage'] = $cross['coverage'];
            if (isset($cross['coverage_start'])) $result['coverage_start'] = $cross['coverage_start'];
            if (isset($cross['source_kind'])) $result['source_kind'] = $cross['source_kind'];
            $result['metric_version'] = 'r51-cross-store-legacy-net-v1';
        } else {
            $balance = $this->balanceRows($stores, $range);
            if (in_array($code, ['basic_balance_order_detail', 'basic_balance_order_summary'], true)) {
                $days = $this->balanceComparisonRows($stores, $range, $balance);
                $rows = $code === 'basic_balance_order_detail' ? $days : $this->balanceComparisonSummary($days);
            } else {
                $rows = $this->summaryRows($code, $stores, $range, $balance);
            }
        }
        // Only the presentation boundary converts cents to yuan; every sum and
        // conservation check above this boundary uses exact integer cents.
        $this->assertRowCount($rows);
        $moneyFields = array_column(array_filter($definitions, static fn(array $f): bool => $f['money']), 'key');
        foreach ($rows as &$row) {
            // Summable project counts are not money; converting all sum_fields
            // to yuan would silently divide service quantities by 100.
            foreach ($moneyFields as $field) $row[$field] = $this->integer($row[$field] ?? 0) / 100;
        }
        unset($row);
        $result['rows'] = $rows;
        return $result;
    }

    /** One row per immutable balance fact, retaining signed refund links. No
     * sale-line join: a payment split across goods must not multiply the debit. */
    private function balanceRows(array $stores, array $range): array
    {
        $query = $this->factQuery('cashier_v3_balance_fact', $stores, $range)
            ->whereIn('p.balance_change_type', ['order_payment', 'order_refund', 'debt_repayment']);
        $this->normalMoney($query);
        $facts = $query->field('p.fact_id,p.store_id,p.store_name_snapshot,p.business_date,p.member_id,p.member_name_snapshot,p.order_id,p.order_no_snapshot,p.source_line_id,p.balance_change_type,p.reversal_of,p.principal_delta_cents,p.bonus_delta_cents')->limit(self::MAX_ROWS + 1)->select()->toArray();
        $this->assertRowCount($facts);
        $out = [];
        foreach ($facts as $fact) {
            $this->checkFact($fact, $stores, $range);
            $principal = -$this->integer($fact['principal_delta_cents']);
            $bonus = -$this->integer($fact['bonus_delta_cents']);
            $id = (string)$fact['fact_id'];
            if ($id === '' || isset($out[$id])) throw new \RuntimeException('余额事实身份重复或缺失');
            $out[$id] = ['row_id' => 'balance:' . $id, 'fact_id' => $id,
                'store_id' => (int)$fact['store_id'], 'store_name' => (string)$fact['store_name_snapshot'],
                'business_date' => $fact['business_date'], 'member_id' => (int)$fact['member_id'],
                'member_name' => (string)$fact['member_name_snapshot'], 'order_id' => (string)$fact['order_id'],
                'order_no' => (string)$fact['order_no_snapshot'], 'source_line_id' => (string)$fact['source_line_id'],
                'change_type' => (string)$fact['balance_change_type'], 'reversal_of' => (string)$fact['reversal_of'],
                'principal_amount' => $principal, 'bonus_amount' => $bonus,
                'balance_amount' => $this->add($principal, $bonus)];
        }
        return array_values($out);
    }

    /** The legacy balance report compares member/store/business-day amounts,
     * not individual payments. Balance days define the population: recharge-only
     * days cannot inflate this report. Aggregate independent populations BEFORE
     * matching so one recharge is not multiplied by multiple order debits.
     * Signed refunds stay on their own business day; no funding-source inference
     * is made from the resulting difference, including negative differences. */
    private function balanceComparisonRows(array $stores, array $range, array $balance): array
    {
        $out = [];
        foreach ($balance as $fact) {
            $member = $this->integer($fact['member_id']);
            if ($member < 1) throw new \RuntimeException('余额日对照缺少有效会员身份');
            $key = $fact['store_id'] . ':' . $member . ':' . $fact['business_date'];
            if (!isset($out[$key])) $out[$key] = [
                'row_id' => 'balance-day:' . $key, 'store_id' => $fact['store_id'],
                'store_name' => $fact['store_name'], 'member_id' => $member, 'member_name' => $fact['member_name'],
                'business_date' => $fact['business_date'], 'balance_amount' => 0, 'recharge_amount' => 0,
                'balance_minus_recharge' => 0, 'principal_amount' => 0, 'bonus_amount' => 0,
                'balance_paid' => 0, 'balance_refunded' => 0, 'has_recharge' => '否'];
            foreach (['balance_amount', 'principal_amount', 'bonus_amount'] as $field) {
                $out[$key][$field] = $this->add($out[$key][$field], $fact[$field]);
            }
            $field = $fact['change_type'] === 'order_refund' ? 'balance_refunded' : 'balance_paid';
            $amount = $field === 'balance_refunded' ? -$fact['balance_amount'] : $fact['balance_amount'];
            $out[$key][$field] = $this->add($out[$key][$field], $amount);
        }
        if ($out === []) return [];
        // The registered Reader owns eligible methods, successful repayments,
        // recharge voids and signed cash adjustments. Each receipt fact (not
        // order or member) is unique; split payment receipts must all survive.
        $recharges = $this->metrics->rechargeCashFacts('0', $stores, $range);
        if (count($balance) + count($recharges) > self::MAX_ROWS) $this->tooManyRows();
        $seen = [];
        foreach ($recharges as $recharge) {
            $this->checkFact($recharge, $stores, $range);
            $id = (string)($recharge['fact_id'] ?? '');
            if ($id === '') throw new \RuntimeException('充值收款事实缺少稳定身份');
            $member = $this->integer($recharge['member_id']);
            $amount = $this->integer($recharge['amount_cents']);
            $key = (int)$recharge['store_id'] . ':' . $member . ':' . $recharge['business_date'];
            $identity = [$key, $amount];
            if (isset($seen[$id])) {
                if ($seen[$id] !== $identity) throw new \RuntimeException('同一充值收款事实的金额或身份不一致');
                continue;
            }
            $seen[$id] = $identity;
            if (!isset($out[$key])) continue;
            $out[$key]['recharge_amount'] = $this->add($out[$key]['recharge_amount'], $amount);
            if ($amount > 0) $out[$key]['has_recharge'] = '是';
        }
        foreach ($out as &$row) {
            $row['balance_minus_recharge'] = $this->add($row['balance_amount'], -$row['recharge_amount']);
        }
        unset($row);
        return array_values($out);
    }

    /** Store totals sum exactly the same selected member-days, including their
     * matched recharge once. Never subtract ALL store recharge or recompute a
     * period-level join, which would change the detail population. */
    private function balanceComparisonSummary(array $days): array
    {
        $out = [];
        foreach ($days as $day) {
            $store = $day['store_id'];
            if (!isset($out[$store])) {
                $out[$store] = ['row_id' => 'basic_balance_order_summary:' . $store,
                    'store_id' => $store, 'store_name' => $day['store_name']];
                foreach (['balance_amount', 'recharge_amount', 'balance_minus_recharge', 'principal_amount',
                    'bonus_amount', 'balance_paid', 'balance_refunded'] as $field) $out[$store][$field] = 0;
            }
            foreach (['balance_amount', 'recharge_amount', 'balance_minus_recharge', 'principal_amount',
                'bonus_amount', 'balance_paid', 'balance_refunded'] as $field) {
                $out[$store][$field] = $this->add($out[$store][$field], $day[$field]);
            }
        }
        return array_values($out);
    }

    /** Shared store/day grain uses registered cash/refund/net/sales/service and
     * recharge metrics. Balance refunds stay a separate signed fact population;
     * they are not cash refunds and do not reduce cash performance. */
    private function summaryRows(string $code, array $stores, array $range, array $balance): array
    {
        $daily = $code === 'basic_daily_reconciliation';
        $metrics = self::METRICS;
        if ($code === 'basic_store_summary') $metrics += self::STORE_SUMMARY_METRICS;
        $out = [];
        $make = function (int $store, string $day, string $name = '') use (&$out, $daily, $code, $metrics): string {
            $key = $store . ($daily ? ':' . $day : '');
            if (!isset($out[$key])) {
                $out[$key] = ['row_id' => $code . ':' . $key, 'store_id' => $store, 'store_name' => $name,
                    'balance_paid' => 0, 'balance_refunded' => 0, 'principal_amount' => 0,
                    'bonus_amount' => 0, 'balance_amount' => 0];
                if ($daily) $out[$key]['business_date'] = $day;
                foreach ($metrics as $metric => $unused) $out[$key][$metric] = 0;
            }
            if ($out[$key]['store_name'] === '' && $name !== '') $out[$key]['store_name'] = $name;
            return $key;
        };
        foreach ($balance as $row) {
            $key = $make($row['store_id'], $row['business_date'], $row['store_name']);
            foreach (['principal_amount', 'bonus_amount', 'balance_amount'] as $field) {
                $out[$key][$field] = $this->add($out[$key][$field], $row[$field]);
            }
            $field = $row['change_type'] === 'order_refund' ? 'balance_refunded' : 'balance_paid';
            $amount = $field === 'balance_refunded' ? -$row['balance_amount'] : $row['balance_amount'];
            $out[$key][$field] = $this->add($out[$key][$field], $amount);
        }
        $sourceRows = count($balance);
        foreach ($metrics as $metric => $unused) {
            $points = $this->metrics->dailyStoreTotals($metric, '0', $stores, $range);
            $sourceRows += count($points);
            if ($sourceRows > self::MAX_ROWS) $this->tooManyRows();
            foreach ($points as $point) {
                $this->checkFact($point, $stores, $range);
                $key = $make((int)$point['store_id'], $point['business_date']);
                $out[$key][$metric] = $this->add($out[$key][$metric], $this->integer($point['amount_cents']));
            }
        }
        // Stores without facts do not invent a transaction/day. Names for rows
        // without balance snapshots are dimensions only, never amount sources.
        $missingNames = [];
        foreach ($out as $row) if ($row['store_name'] === '') $missingNames[$row['store_id']] = $row['store_id'];
        $names = $missingNames === [] ? [] : $this->metrics->storeNames(array_values($missingNames));
        foreach ($out as &$row) {
            if ($row['store_name'] === '') $row['store_name'] = (string)($names[$row['store_id']] ?? '');
            if ($code === 'basic_store_summary') {
                // Ratio of the final period totals, not an average of daily
                // ratios. Percent value 100 means 100%; zero cash is undefined.
                // Actual performance remains the registered cash-minus-refund
                // metric; neither labour nor fee is deducted from that value.
                $row['consumption_cash_ratio'] = $row['cash_performance'] === 0 ? null
                    : $row['consume_amount'] / $row['cash_performance'] * 100;
            }
        }
        unset($row);
        return array_values($out);
    }

    /** Legacy settlement direction is transaction.store_id (receiving) versus
     * transaction.kua_store (paying), never current member ownership or funding
     * lots. Only identity/direction comes from the successful transaction; money
     * comes from unified signed balance/writeoff facts. Both endpoints must be
     * authorized and salary-enabled. Missing direction is exposed as a gap and
     * does not hide other verified pairs; kua_store=0 is explicitly unmarked. */
    private function crossStore(array $stores, array $range): array
    {
        // Pure report contracts run without the framework bootstrap; only an
        // explicitly configured instance may switch to a sealed legacy batch.
        $batchId = function_exists('config') ? (string)config('basic_report.cross_store_legacy_batch_id', '') : '';
        if ($batchId !== '') return $this->legacyCrossStore($stores, $range, $batchId);
        $out = [];
        $gaps = [];
        // Older local schemas may lack the historical direction flag. Metadata
        // discovery is read-only; missing fields become a named coverage gap,
        // never an SQL error or an inferred counterparty.
        foreach (['system_store' => ['id', 'name', 'salary_status'],
            'store_order' => ['id', 'order_id', 'uid', 'store_id', 'kua_store', 'order_type', 'paid']] as $table => $required) {
            $missing = array_diff($required, ($this->queries)($table)->getTableFields());
            if ($missing !== []) return ['rows' => [], 'data_gaps' => [[
                'code' => 'R51_CROSS_DIMENSION_FIELD_MISSING',
                'label' => '跨店交易归属或门店统计开关字段缺失，不能核实结算范围',
                'source_count' => 0, 'amount' => 0,
            ]]];
        }
        $settings = ($this->queries)('system_store')->whereIn('id', $stores)
            ->field('id,name,salary_status')->limit(self::MAX_ROWS + 1)->select()->toArray();
        $this->assertRowCount($settings);
        $enabled = [];
        foreach ($settings as $store) {
            $id = (int)$store['id'];
            if (in_array($id, $stores, true) && (int)$store['salary_status'] === 1) $enabled[$id] = (string)$store['name'];
        }
        if ($enabled === []) return ['rows' => [], 'data_gaps' => []];
        $activeStores = array_keys($enabled);
        $balance = $this->balanceRows($activeStores, $range);
        // Match opaque business order numbers as strings. Never cast a CSO/DRP
        // identity to a legacy numeric primary key. Repayments have no legacy
        // order_type=0 transaction mapping and remain an explicit direction gap.
        $orders = [];
        foreach (array_chunk(array_values(array_unique(array_column($balance, 'order_id'))), 300) as $chunk) {
            $matches = ($this->queries)('store_order')->whereIn('order_id', $chunk)
                ->whereIn('store_id', $activeStores)->where('order_type', 0)->where('paid', 1)
                ->field('id,order_id,uid,store_id,kua_store')->limit(self::MAX_ROWS + 1)->select()->toArray();
            $this->assertRowCount($matches);
            foreach ($matches as $match) {
                $identity = (string)$match['order_id'] . ':' . (int)$match['store_id'] . ':' . (int)$match['uid'];
                if (isset($orders[$identity])) throw new \RuntimeException('跨店原订单身份不唯一');
                $orders[$identity] = $match;
            }
        }
        foreach ($balance as $fact) {
            $identity = $fact['order_id'] . ':' . $fact['store_id'] . ':' . $fact['member_id'];
            $order = $orders[$identity] ?? [];
            $this->addCrossFact($out, $gaps, $enabled, $fact['store_id'], $order['kua_store'] ?? null,
                'stored_value', $fact['balance_amount']);
        }
        $query = $this->factQuery('cashier_v3_entitlement_writeoff_fact', $activeStores, $range);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'p');
        // document_id is the transaction identity; origin_order_id is only the
        // purchased entitlement identity. They must not be interchanged.
        $facts = $query->leftJoin('store_order o', 'o.order_id=p.document_id AND o.store_id=p.store_id AND o.uid=p.member_id AND o.order_type=2 AND o.paid=1')
            ->leftJoin('user_card_holder h', 'h.id=p.holder_id AND h.oid=p.origin_order_id AND h.uid=p.member_id')
            ->field('p.writeoff_id,p.store_id,p.store_name_snapshot,p.business_date,p.entitlement_instance_type,p.is_gift,p.member_id,p.holder_id,p.origin_order_id,p.actual_entitlement_amount_cents,o.kua_store,h.id verified_holder_id')
            ->limit(self::MAX_ROWS + 1)->select()->toArray();
        $this->assertRowCount($facts);
        if (count($balance) + count($facts) > self::MAX_ROWS) $this->tooManyRows();
        $seen = [];
        foreach ($facts as $fact) {
            $this->checkFact($fact, $stores, $range);
            $id = (string)($fact['writeoff_id'] ?? '');
            if ($id === '' || isset($seen[$id])) throw new \RuntimeException('次卡核销事实身份缺失或重复');
            $seen[$id] = true;
            // Independent gifts are not purchased card liabilities.
            if ((int)$fact['is_gift'] === 1) continue;
            if ($fact['entitlement_instance_type'] !== 'card_holder') {
                $this->addGap($gaps, 'R51_CARD_IDENTITY_MISSING', '付费权益持卡身份无法核实', $this->integer($fact['actual_entitlement_amount_cents']));
                continue;
            }
            $target = (int)$fact['store_id'];
            if (!isset($enabled[$target])) continue;
            $amount = $this->integer($fact['actual_entitlement_amount_cents']);
            if ($amount < 0) throw new \RuntimeException('次卡权益金额不合法');
            // A transaction explicitly marked local requires no cross-store
            // identity projection, even if an old holder is no longer present.
            if (isset($fact['kua_store']) && (int)$fact['kua_store'] === 0) continue;
            if (isset($fact['kua_store']) && ((int)$fact['kua_store'] === $target || !isset($enabled[(int)$fact['kua_store']]))) continue;
            if ((int)($fact['verified_holder_id'] ?? 0) !== (int)$fact['holder_id'] || (int)$fact['holder_id'] < 1) {
                $this->addGap($gaps, 'R51_CARD_IDENTITY_MISSING', '次卡原单、会员与持卡身份无法核实', $amount);
                continue;
            }
            $this->addCrossFact($out, $gaps, $enabled, $target, $fact['kua_store'] ?? null, 'card', $amount);
        }
        foreach ($gaps as &$gap) {
            $gap['amount'] = $gap['amount_cents'] / 100;
            unset($gap['amount_cents']);
        }
        unset($gap);
        // The old settlement report is a net amount by store. Preserve the
        // verified fact direction while projecting both sides into one row per
        // authorized store; the two sides of each pair must cancel exactly.
        $rows = $this->emptyCrossStoreRows($enabled);
        foreach ($out as $pair) {
            $this->addCrossStoreAmount($rows, (int)$pair['target_store_id'], 'stored_value_settlement', $pair['stored_value_receivable']);
            $this->addCrossStoreAmount($rows, (int)$pair['source_store_id'], 'stored_value_settlement', -$pair['stored_value_payable']);
            $this->addCrossStoreAmount($rows, (int)$pair['target_store_id'], 'card_settlement', $pair['card_receivable']);
            $this->addCrossStoreAmount($rows, (int)$pair['source_store_id'], 'card_settlement', -$pair['card_payable']);
        }
        return ['rows' => array_values($rows), 'data_gaps' => array_values($gaps)];
    }

    /** The sealed 007 order projection is report-only. Its batch identity and
     * source cutoff are checked before reading; current cashier/order tables are
     * never overwritten to make historical financial reports agree. */
    private function legacyCrossStore(array $stores, array $range, string $batchId): array
    {
        $batch = ($this->queries)('basic_cross_store_legacy_batch')->where('batch_id', $batchId)->find();
        if (!$batch || (string)$batch['status'] !== 'sealed') throw new \RuntimeException('跨店历史快照未完成校验');
        if ($range['start'] < (string)$batch['range_start'] || $range['end'] > (string)$batch['range_end']) {
            throw new \InvalidArgumentException('跨店历史快照仅支持 ' . $batch['range_start'] . ' 至 ' . $batch['range_end'] . '，超出范围需先补齐事实');
        }
        $settings = ($this->queries)('system_store')->whereIn('id', $stores)
            ->field('id,name,salary_status')->limit(self::MAX_ROWS + 1)->select()->toArray();
        $this->assertRowCount($settings);
        $enabled = [];
        foreach ($settings as $store) {
            if ((int)$store['salary_status'] === 1) $enabled[(int)$store['id']] = (string)$store['name'];
        }
        $rows = $this->emptyCrossStoreRows($enabled);
        if (!$rows) return ['rows' => [], 'data_gaps' => [], 'data_as_of' => strtotime((string)$batch['captured_at'])];
        $facts = ($this->queries)('basic_cross_store_legacy_order')->where('batch_id', $batchId)
            ->whereBetween('business_date', [$range['start'], $range['end']])
            ->field('source_order_id,store_id,kua_store,order_type,amount_cents')
            ->limit(self::MAX_ROWS + 1)->select()->toArray();
        $this->assertRowCount($facts);
        $seen = [];
        foreach ($facts as $fact) {
            $sourceId = (int)$fact['source_order_id'];
            if ($sourceId < 1 || isset($seen[$sourceId])) throw new \RuntimeException('跨店历史订单身份重复或缺失');
            $seen[$sourceId] = true;
            $target = (int)$fact['store_id'];
            $origin = (int)$fact['kua_store'];
            // The imported population was already restricted to two
            // salary-enabled stores. A single-store viewer receives only its
            // own signed balance, never the counterparty's row or name.
            if (!isset($rows[$target]) && !isset($rows[$origin])) continue;
            $type = (int)$fact['order_type'];
            if (!in_array($type, [0, 2], true)) throw new \RuntimeException('跨店历史订单类型不合法');
            $field = $type === 0 ? 'stored_value_settlement' : 'card_settlement';
            $amount = $this->integer($fact['amount_cents']);
            if (isset($rows[$target])) $this->addCrossStoreAmount($rows, $target, $field, $amount);
            if (isset($rows[$origin])) $this->addCrossStoreAmount($rows, $origin, $field, -$amount);
        }
        return ['rows' => array_values($rows), 'data_gaps' => [],
            'data_as_of' => strtotime((string)$batch['captured_at']),
            'source_kind' => 'sealed_legacy_settlement_snapshot',
            'coverage_start' => (string)$batch['range_start'],
            'coverage' => '007 只读固定快照；截至 ' . $batch['captured_at'] . '；仅含该时点已成功且未退款/删除的跨店订单'];
    }

    /** One stable row per salary-enabled store, including zero-settlement stores. */
    private function emptyCrossStoreRows(array $enabled): array
    {
        krsort($enabled, SORT_NUMERIC);
        $rows = [];
        foreach ($enabled as $id => $name) $rows[$id] = ['row_id' => 'cross-store:' . $id,
            'store_id' => $id, 'store_name' => $name, 'stored_value_settlement' => 0,
            'card_settlement' => 0, 'settlement_total' => 0];
        return $rows;
    }

    /** Signed cents are conserved in both columns and the total. */
    private function addCrossStoreAmount(array &$rows, int $storeId, string $field, int $amount): void
    {
        $rows[$storeId][$field] = $this->add($rows[$storeId][$field], $amount);
        $rows[$storeId]['settlement_total'] = $this->add($rows[$storeId]['settlement_total'], $amount);
    }

    /** One directional pair carries equal payable/receivable within each money
     * pool. A refund is signed and reverses the same pair, never another store. */
    private function addCrossFact(array &$out, array &$gaps, array $enabled, int $target, $counterparty, string $pool, int $amount): void
    {
        if ($counterparty === null) {
            $this->addGap($gaps, 'R51_' . strtoupper($pool) . '_COUNTERPARTY_MISSING',
                ($pool === 'card' ? '次卡' : '储值') . '成功交易缺少可核实的跨店归属字段，新路径须先记录交易对手店', $amount);
            return;
        }
        $origin = (int)$counterparty;
        if ($origin === 0 || $origin === $target || !isset($enabled[$origin], $enabled[$target])) return;
        $key = $origin . ':' . $target;
        if (!isset($out[$key])) $out[$key] = [
            'row_id' => 'cross:' . $key, 'store_ids' => [$origin, $target],
            'store_id' => $target, 'store_name' => $enabled[$target],
            'source_store_id' => $origin, 'target_store_id' => $target,
            'stored_value_payable' => 0, 'stored_value_receivable' => 0, 'card_payable' => 0, 'card_receivable' => 0];
        foreach (['payable', 'receivable'] as $side) $out[$key][$pool . '_' . $side] = $this->add($out[$key][$pool . '_' . $side], $amount);
    }

    /** Missing dimensions stay visible as aggregate evidence, not invented zero
     * settlement rows. Amounts refer only to the authenticated source population. */
    private function addGap(array &$gaps, string $code, string $label, int $amount): void
    {
        if (!isset($gaps[$code])) $gaps[$code] = ['code' => $code, 'label' => $label, 'source_count' => 0, 'amount_cents' => 0];
        ++$gaps[$code]['source_count'];
        $gaps[$code]['amount_cents'] = $this->add($gaps[$code]['amount_cents'], $amount);
    }

    /** Only effective facts in the authenticated local product tenant are read. */
    private function factQuery(string $table, array $stores, array $range)
    {
        return ($this->queries)($table)->alias('p')->where('p.tenant_id', '0')
            ->whereIn('p.store_id', $stores)->where('p.status', 'effective')
            ->whereBetween('p.business_date', [$range['start'], $range['end']]);
    }

    /** Voids remove the original and reversal together, including cross-day voids. */
    private function normalMoney($query): void
    {
        $normal = new StoreReportNormalDataScopeServices();
        $normal->excludeVoidedSalesOrderFacts($query, 'p.tenant_id', 'p.order_id');
        $normal->excludeVoidedRechargeOrderFacts($query, 'p.tenant_id', 'p.order_id');
        $normal->excludeVoidedSupplementPaymentFacts($query, 'p.tenant_id', 'p.store_id', 'p.order_id');
    }

    private function assertCode($code): void
    {
        if (!in_array($code, self::codes(), true)) throw new \InvalidArgumentException('不支持的财务报表');
    }

    private function date($value): string
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Asia/Shanghai')) : false;
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('报表日期不合法');
        return $value;
    }

    /** Fail on malformed money rather than round/truncate source evidence. */
    private function integer($value): int
    {
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) && (string)(int)$value === $value) $value = (int)$value;
        if (!is_int($value) || abs($value) > 9007199254740991) throw new \RuntimeException('财务事实金额越界或非整数分');
        return $value;
    }

    private function add(int $left, int $right): int
    {
        return $this->integer($left + $right);
    }

    /** A sentinel is only a refusal boundary, never a truncated successful result. */
    private function assertRowCount(array $rows): void
    {
        if (count($rows) > self::MAX_ROWS) $this->tooManyRows();
    }

    private function tooManyRows(): void
    {
        throw new \RuntimeException('R51_SOURCE_ROWS_EXCEEDED: 财务来源超过10000行，请缩小日期或门店范围');
    }

    private function checkFact(array $row, array $stores, array $range): void
    {
        if (!in_array((int)($row['store_id'] ?? 0), $stores, true)
            || !is_string($row['business_date'] ?? null)
            || $row['business_date'] < $range['start'] || $row['business_date'] > $range['end']) {
            throw new \RuntimeException('财务事实超出授权范围或日期');
        }
    }

    /** Human-readable explanations accompany every exposed column. */
    private function sources(array $definitions, string $code): array
    {
        $explanations = [
            'sales_amount' => '成功结账销售明细的成交金额，按销售业务日计入，保留退款调整，排除作废。',
            'cash_performance' => '成功实际收款的正向金额，含充值及欠款补交；余额和非现金其他收款不计入。',
            'refund_performance' => '成功现金退款的金额，按退款成功业务日计入；仅退回余额的不计入。',
            'actual_performance' => '同一日期和门店范围的现金业绩减退款业绩。',
            'consume_amount' => '项目实际完成后形成的项目级消耗业绩，排除作废服务；不是售卡额或余额扣款。',
            'staff_labor_yeji' => '已完成服务分配给手艺人的有效劳动业绩合计，按业务日和门店统计，保留有效调整并排除作废；不从现金业绩中扣除。',
            'staff_labor_fee' => '有效劳动分配记录保存的手工费合计，按业务日和门店统计并排除作废；不按劳动业绩推算，也不从实际业绩中扣除。',
            'completed_service_item_count' => '成功完成且未作废的服务项目数量合计，按服务业务日与门店统计；不是销售数量、员工分配项目数或服务客次。',
            'consumption_cash_ratio' => '同门店查询期间消耗业绩金额除以现金业绩金额，再乘100，以百分数返回；现金为零时为空，不按每日比率平均，不属于金额且不能合计。',
            'recharge_amount' => '充值成功实际增加的本金及有效调整，不含赠金，不等同于现金收款。',
            'balance_paid' => '订单成功支付实际扣减的本金和赠金之和；作废订单不计入。',
            'balance_refunded' => '销售退款成功后实际退回的本金和赠金，按返还业务日计入，不是现金退款。',
            'principal_amount' => '订单支付扣减的本金，退款返还为负数，作废及冲销链不计入。',
            'bonus_amount' => '订单支付扣减的赠金，退款返还为负数，作废及冲销链不计入。',
            'balance_amount' => '订单本金与赠金净扣款之和；保留历史分精度，不包括充值、人工调整或提现退款。',
            'stored_value_settlement' => '成功且未退款、未删除的跨店普通订单余额支付金额：本店交易加，权益所属店减；固定历史批次按007订单原值，保留分精度。',
            'card_settlement' => '成功且未退款、未删除的跨店次卡订单实付金额：本店交易加，权益所属店减；固定历史批次按007订单原值，保留分精度。',
            'settlement_total' => '同门店储值对账金额加次卡对账金额；两端均纳入统计时所有门店合计为零。',
            'business_date' => '本笔成功业务实际归属日期；退款使用返还业务日。',
            'store_name' => '余额明细优先显示业务发生时门店名称；无该快照的汇总按已授权门店名称定位。',
        ];
        if (in_array($code, ['basic_balance_order_detail', 'basic_balance_order_summary'], true)) {
            $explanations['balance_amount'] = '同门店、同会员、同业务日的订单余额本金与赠金净扣款，包含欠款补交，退款返还反向计入；作废不计入。';
            $explanations['recharge_amount'] = '仅对有订单余额扣减或返还的会员日，合计同门店同会员同日成功的实际充值收款及其退款调整；每笔收款只计一次，不含赠金，不因同日多订单重复累计。汇总为这些会员日的合计，不是门店全部充值。';
            $explanations['balance_minus_recharge'] = '余额支付金额减同门店同会员同日充值收款净额，允许为负数；只是日期金额对照，不代表真正旧本金消费，也不代表余额资金来源或资金流向。';
            $explanations['has_recharge'] = '同门店同会员同日存在正向成功充值收款时为是；只有退款或其他日期、其他门店的充值为否。';
            $explanations['business_date'] = '订单余额扣减或退款返还的业务归属日；只对照该门店该会员同一业务日的充值收款。';
        }
        $out = [];
        foreach ($definitions as $field) $out[$field['key']] = $explanations[$field['key']]
            ?? ($field['label'] . '取自成功业务保存的身份或原事实关联，不按显示名称猜测。');
        return $out;
    }
}
