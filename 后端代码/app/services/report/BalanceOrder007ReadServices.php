<?php
declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use think\facade\Db;

/**
 * 007 余额订单两表的统一只读投影。新业务读V3事实，未迁旧单经身份和状态核验后读取原凭据，
 * 先按授权门店、会员、业务日归集，再做对照；旧 SQL 的同日充值重复连接和跨店串算不能复现。
 */
final class BalanceOrder007ReadServices
{
    public const DETAIL = 'basic_balance_order_detail';
    public const SUMMARY = 'basic_balance_order_summary';
    private const MAX_FACTS = 10000;
    private $balanceFacts;
    private $rechargeFacts;
    private $legacyCoverage;
    private $memberProfiles;

    /** 可注入只读事实加载器做独立合同测试；生产读取V3事实与明确标识、同源排重的历史兼容来源。 */
    public function __construct(?callable $balanceFacts = null, ?callable $rechargeFacts = null,
        ?callable $legacyCoverage = null, ?callable $memberProfiles = null)
    {
        $this->balanceFacts = $balanceFacts ?? [$this, 'loadBalanceFacts'];
        $this->rechargeFacts = $rechargeFacts ?? [$this, 'loadRechargeFacts'];
        $this->legacyCoverage = $legacyCoverage ?? [$this, 'auditLegacyCoverage'];
        $this->memberProfiles = $memberProfiles ?? [$this, 'loadMemberProfiles'];
    }

    /** 只接管 007 的余额订单明细、汇总，不改变其他财务报表口径。 */
    public static function codes(): array { return [self::DETAIL, self::SUMMARY]; }

    /** 007 可见列保持原顺序，身份键只供授权、精确筛选与稳定行键使用。 */
    public function definitions(string $code): array
    {
        $this->assertCode($code);
        $common = [
            ['store_name', $code === self::DETAIL ? '门店名称' : '门店', 'text', true],
        ];
        if ($code === self::DETAIL) {
            // 产品经理要求所有报表日期统一为业务日；旧“下单日期”会误导跨日支付单。
            $common[] = ['business_date', '业务日期', 'date', true];
            $common[] = ['user_info', '用户信息', 'text', true];
        }
        foreach ([['balance_amount', '余额支付金额'], ['recharge_amount', '充值金额'],
            ['actual_deduct', '实际扣卡金额']] as [$key, $label]) {
            $common[] = [$key, $label, 'decimal', true];
        }
        if ($code === self::DETAIL) $common[] = ['has_recharge', '当天是否有充值', 'text', true];
        $common[] = ['store_id', '门店ID', 'integer', false];
        if ($code === self::DETAIL) $common[] = ['member_id', '会员ID', 'integer', false];
        $common[] = ['row_id', '稳定行键', 'text', false];
        return array_map(static function (array $column): array {
            [$key, $label, $type, $visible] = $column;
            return ['key' => $key, 'label' => $label, 'type' => $type,
                'defaultVisible' => $visible, 'defaultQuick' => in_array($key, ['store_name', 'business_date', 'user_info'], true),
                'width' => $type === 'decimal' ? 150 : 180,
                'fixed' => $key === 'store_name' ? 'left' : '',
                'money' => $type === 'decimal', 'summable' => false];
        }, $common);
    }

    /**
     * 007 明细在会员/门店/日对照后排除负的“实际扣卡”；汇总则先在
     * 会员/门店/整个查询期对照后排除负数。两表因此不保证简单逐行相加相等。
     * 无法验证完整事实时整表返回 data_gap，不展示不完整金额。
     */
    public function read(string $code, array $stores, array $input): array
    {
        $fields = $this->definitions($code);
        $stores = $this->stores($stores, $input);
        $range = $this->range($input);
        $result = ['rows' => [], 'fields' => $fields, 'field_sources' => $this->sources(),
            'sum_fields' => [], 'complete' => true, 'data_gaps' => [],
            'table_layout' => ['summary_position' => 'none'],
            'metric_version' => 'balance-order-007-v3-facts-v1',
            'coverage_start' => StoreUnifiedReportServices::COVERAGE_START,
            'coverage' => 'V3余额支付和充值事实，加上已核验且尚未迁入的007原单；同原单只计一次',
            'limitations' => ['余额支付和充值按同一授权门店及会员配对；充值每笔事实只计一次。',
                '不包含欠款补交、余额退款、已作废或已退款原单。'],
            'data_as_of' => time()];
        if ($range['start'] < StoreUnifiedReportServices::COVERAGE_START) {
            return $this->gap($result, 'BALANCE_ORDER_HISTORY_NOT_IMPORTED', '查询范围早于 V3 事实覆盖期，不能以零代替旧单。');
        }
        if ($stores === [] || $range['end'] < $range['start']) return $result;
        $coverage = ($this->legacyCoverage)($stores, $range);
        if (!is_array($coverage) || !isset($coverage['balance_unmapped'], $coverage['recharge_unmapped'])) {
            return $this->gap($result, 'BALANCE_ORDER_COVERAGE_UNVERIFIED', '旧订单迁入覆盖率无法核实。');
        }
        if (!empty($coverage['time_missing'])) {
            return $this->gap($result, 'BUSINESS_DATE_UNRESOLVED',
                '旧已付订单缺少支付时间，无法按 V3 业务日核对迁入覆盖率。');
        }
        if ((int)$coverage['balance_unmapped'] > 0) {
            return $this->gap($result, 'BALANCE_ORDER_LEGACY_PAYMENT_NOT_MIGRATED',
                '查询范围内仍有有效旧余额支付订单未迁入 V3 余额事实，不能显示部分合计。');
        }
        if ((int)$coverage['recharge_unmapped'] > 0) {
            return $this->gap($result, 'BALANCE_ORDER_LEGACY_RECHARGE_NOT_MIGRATED',
                '同店同会员同日的旧充值尚无 V3 收款事实，实际扣卡金额不能核实。');
        }
        $balances = ($this->balanceFacts)($stores, $range);
        $recharges = ($this->rechargeFacts)($stores, $range);
        if (!is_array($balances) || !is_array($recharges) || count($balances) > self::MAX_FACTS
            || count($recharges) > self::MAX_FACTS) {
            return $this->gap($result, 'BALANCE_ORDER_SOURCE_LIMIT', '余额或充值事实超出完整读取范围，请缩小日期或门店。');
        }
        $days = [];
        $seen = [];
        foreach ($balances as $fact) {
            $checked = $this->fact($fact, $stores, $range, $seen, 'balance');
            if ($checked === null) return $this->gap($result, 'BALANCE_ORDER_FACT_INCOMPLETE', '余额支付事实身份、门店、日期或金额不完整。');
            [$key, $store, $member, $day, $amount] = $checked;
            if ($amount <= 0) return $this->gap($result, 'BALANCE_ORDER_FACT_INCOMPLETE', '余额支付事实金额不是正向扣款。');
            if (!$this->initDay($days, $key, $store, $member, $day, $fact)) {
                return $this->gap($result, 'BALANCE_ORDER_FACT_INCOMPLETE', '余额支付事实门店名称快照缺失或不一致。');
            }
            $days[$key]['balance_cents'] = $this->add($days[$key]['balance_cents'], $amount);
        }
        foreach ($recharges as $fact) {
            $checked = $this->fact($fact, $stores, $range, $seen, 'recharge');
            if ($checked === null) return $this->gap($result, 'BALANCE_ORDER_RECHARGE_INCOMPLETE', '充值收款事实身份、门店、日期或金额不完整。');
            [$key, $store, $member, $day, $amount] = $checked;
            if ($amount <= 0) return $this->gap($result, 'BALANCE_ORDER_RECHARGE_INCOMPLETE', '充值收款事实金额不是正向收款。');
            // 余额支付定义人口；只有同门店、同会员、同业务日的充值才进入对照。
            if (isset($days[$key])) $days[$key]['recharge_cents'] = $this->add($days[$key]['recharge_cents'], $amount);
        }
        $profiles = $code === self::DETAIL && $days ? ($this->memberProfiles)(array_values(array_unique(array_column($days, 'member_id')))) : [];
        if (!is_array($profiles)) return $this->gap($result, 'BALANCE_ORDER_MEMBER_PROFILE_INCOMPLETE', '会员当前名称无法核实。');
        $rows = $code === self::DETAIL ? $this->detailRows($days, $profiles) : $this->summaryRows($days);
        if (count($rows) > self::MAX_FACTS) return $this->gap($result, 'BALANCE_ORDER_RESULT_LIMIT', '报表结果超出完整读取范围，请缩小日期或门店。');
        $result['rows'] = $rows;
        return $result;
    }

    /** 有效原销售的正向余额支付，已作废或退款原单不参加 007 余额订单表。 */
    private function loadBalanceFacts(array $stores, array $range): array
    {
        $query = Db::name('cashier_v3_balance_fact')->alias('p')
            ->where('p.tenant_id', '0')->whereIn('p.store_id', $stores)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective')->where('p.fact_direction', 'forward')
            // 已迁入的 007 原单冻结为 order_pay，新 V3 收银写 order_payment；
            // 两者都是原订单正向余额扣款，不能把旧类型误判为空数据。
            ->whereIn('p.balance_change_type', ['order_pay', 'order_payment'])->where('p.member_id', '>', 0);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query, 'p.tenant_id', 'p.order_id');
        $this->excludeRefunded($query, 'sales');
        $rows = $query->field('p.fact_id,p.store_id,p.store_name_snapshot,p.member_id,p.member_name_snapshot,p.business_date,p.principal_delta_cents,p.bonus_delta_cents')
            ->limit(self::MAX_FACTS + 1)->select()->toArray();
        return array_merge($rows, $this->legacyRows('balance', $stores, $range));
    }

    /** 充值仅取原充值单成功收款，不把欠款补交或跨店收款充作同日充值。 */
    private function loadRechargeFacts(array $stores, array $range): array
    {
        $query = Db::name('cashier_v3_payment_fact')->alias('p')
            ->where('p.tenant_id', '0')->whereIn('p.store_id', $stores)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective')->where('p.fact_direction', 'forward')
            ->where('p.fact_type', 'payment_collected')->where('p.source_document_type', 'recharge')
            ->whereIn('p.payment_method', CashierV3CheckoutFactPlanV1::paymentMethods())
            ->where('p.payment_method', '<>', 'other_collection')
            ->where('p.member_id', '>', 0);
        (new StoreReportNormalDataScopeServices())->excludeVoidedRechargeOrderFacts($query, 'p.tenant_id', 'p.order_id');
        $this->excludeRefunded($query, 'recharge');
        $rows = $query->field('p.fact_id,p.store_id,p.store_name_snapshot,p.member_id,p.member_name_snapshot,p.business_date,p.amount_cents')
            ->limit(self::MAX_FACTS + 1)->select()->toArray();
        return array_merge($rows, $this->legacyRows('recharge', $stores, $range));
    }

    /**
     * 007 原有效单若仍未归档，就不能仅用部分 V3 事实给出零或小额。
     * 日期缺失仍阻断；日期完整的旧原凭据交兼容读取并明确保留来源。
     */
    private function auditLegacyCoverage(array $stores, array $range): array
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $from = (new \DateTimeImmutable($range['start'] . ' 00:00:00', $timezone))->getTimestamp();
        $until = (new \DateTimeImmutable($range['end'] . ' 23:59:59', $timezone))->getTimestamp();
        $balanceSource = $this->legacyBalanceCandidate($stores);
        $rechargeSource = $this->legacyRechargeCandidate($stores);
        // 无支付秒数的已付旧单无法可靠归入任何查询期；在授权门店全域探测，
        // 避免以创建日代替业务日而漏掉跨期来源。旧单只用于迁入完整性审计。
        $missingBalanceTime = (clone $balanceSource)
            ->whereRaw('(legacy_balance.pay_time IS NULL OR legacy_balance.pay_time=0)')
            ->limit(1)->value('legacy_balance.id');
        $missingRechargeTime = (clone $rechargeSource)
            ->whereRaw('(legacy_recharge.pay_time IS NULL OR legacy_recharge.pay_time=0)')
            ->limit(1)->value('legacy_recharge.id');
        if ((int)$missingBalanceTime > 0 || (int)$missingRechargeTime > 0) {
            return ['balance_unmapped' => 0, 'recharge_unmapped' => 0, 'time_missing' => 1];
        }
        // 日期完整的未迁旧单由同一后端兼容读取，不再误报为必须先造新事实。
        return ['balance_unmapped'=>0, 'recharge_unmapped'=>0];
    }

    /**
     * 未迁原凭据只投影实际总额，不伪造本金/赠金分摊或新的余额事件。
     * 采用已批准迁入业务日规则（原pay_time上海日），原单同源V3存在即不重复。
     */
    private function legacyRows(string $kind,array $stores,array $range): array
    {
        $alias=$kind==='balance'?'legacy_balance':'legacy_recharge';
        $query=$kind==='balance'?$this->legacyBalanceCandidate($stores):$this->legacyRechargeCandidate($stores);
        $zone=new \DateTimeZone('Asia/Shanghai');
        $from=(new \DateTimeImmutable($range['start'].' 00:00:00',$zone))->getTimestamp();
        $until=(new \DateTimeImmutable($range['end'].' 23:59:59',$zone))->getTimestamp();
        $query->whereBetween($alias.'.pay_time',[$from,$until]);
        $table=$kind==='balance'?'cashier_v3_balance_fact':'cashier_v3_payment_fact';
        $query->whereNotExists(static function($proof)use($table,$alias,$kind):void{
            $proof->name($table)->alias('old_report_mapped')->where('old_report_mapped.tenant_id','0')
                ->whereRaw('old_report_mapped.store_id='.$alias.'.store_id')
                ->whereRaw('old_report_mapped.member_id='.$alias.'.uid')
                ->whereRaw('old_report_mapped.order_no_snapshot='.$alias.'.order_id')
                ->where('old_report_mapped.source_document_type',$kind==='balance'?'legacy_store_order':'recharge');
            // 连已冲销原事实也算已迁，不能因status变化从旧壳重新复活一次。
            if($kind==='balance')$proof->whereIn('old_report_mapped.balance_change_type',['order_pay','order_payment']);
        });
        $query->whereNotExists(static function($terminal)use($alias):void{
            $terminal->name('cashier_v3_sales_order')->alias('old_report_header')
                ->join('cashier_v3_order_lifecycle_operation old_report_terminal',
                    'old_report_terminal.tenant_id=old_report_header.tenant_id AND old_report_terminal.store_id=old_report_header.store_id AND old_report_terminal.source_order_id=old_report_header.order_id')
                ->where('old_report_header.tenant_id','0')->where('old_report_header.source_document_type','legacy_store_order')
                ->whereRaw('old_report_header.source_document_id=CAST('.$alias.'.id AS CHAR)')
                ->whereRaw('old_report_header.store_id='.$alias.'.store_id')
                ->whereRaw('old_report_header.member_id='.$alias.'.uid')
                ->whereRaw('old_report_header.order_no='.$alias.'.order_id')
                ->where('old_report_terminal.source_type','sales')->whereIn('old_report_terminal.operation_type',['refund','void'])
                ->where('old_report_terminal.status','succeeded');
        });
        $raw=$query->join('system_store old_report_store','old_report_store.id='.$alias.'.store_id')
            ->field($alias.'.id,'.$alias.'.uid,'.$alias.'.store_id,'.$alias.'.pay_time,'.$alias.'.'.($kind==='balance'?'yue_pay_price':'pay_price').' original_amount,old_report_store.name')
            ->order($alias.'.id','asc')->limit(self::MAX_FACTS+1)->select()->toArray();
        $rows=[];
        foreach($raw as $row){
            $amount=\app\services\report\query\BasicRegistrationQueryProvider::cents($row['original_amount']);
            $rows[]=['fact_id'=>'historical-007-'.$kind.':'.$row['id'],'source_kind'=>'historical_007_original',
                'store_id'=>(int)$row['store_id'],'store_name_snapshot'=>(string)$row['name'],'member_id'=>(int)$row['uid'],
                'business_date'=>(new \DateTimeImmutable('@'.(int)$row['pay_time']))->setTimezone($zone)->format('Y-m-d'),
                'amount_cents'=>$amount];
        }
        return $rows;
    }

    /** 007 旧有效余额单资格；这里只用身份和支付秒数审计迁入完整性。 */
    private function legacyBalanceCandidate(array $stores)
    {
        return Db::name('store_order')->alias('legacy_balance')
            ->whereIn('legacy_balance.store_id', $stores)
            ->where('legacy_balance.paid', 1)->where('legacy_balance.is_system_del', 0)
            ->where('legacy_balance.pay_type','<>','cashier_v3')
            ->where('legacy_balance.is_del', 0)->where('legacy_balance.refund_status', 0)
            ->whereRaw('IFNULL(legacy_balance.is_auto,0)<>1')
            ->whereRaw('(legacy_balance.pid>=0 OR legacy_balance.pid=-2)')
            ->where('legacy_balance.order_type', 0)->whereRaw('IFNULL(legacy_balance.cash_choose,0)<>9')
            ->where('legacy_balance.yue_pay_price', '>', 0)->where('legacy_balance.uid', '>', 0);
    }

    /** 旧充值资格与 007 来源一致；只有已付且未退款的充值参与覆盖审计。 */
    private function legacyRechargeCandidate(array $stores)
    {
        return Db::name('store_order')->alias('legacy_recharge')
            ->whereIn('legacy_recharge.store_id', $stores)
            ->where('legacy_recharge.order_type', 1)->where('legacy_recharge.paid', 1)
            ->where('legacy_recharge.is_system_del', 0)->where('legacy_recharge.refund_status', 0)
            ->where('legacy_recharge.pay_type','<>','cashier_v3')
            ->where('legacy_recharge.uid', '>', 0)->where('legacy_recharge.pay_price', '>', 0);
    }

    /** 原单一旦成功退款，007 的 refund_status=0 人口将其整体排除。 */
    private function excludeRefunded($query, string $source): void
    {
        $query->whereNotExists(static function ($op) use ($source): void {
            $op->name('cashier_v3_order_lifecycle_operation')->alias('balance_007_refund')
                ->whereRaw('balance_007_refund.tenant_id=p.tenant_id')
                ->whereRaw($source === 'recharge'
                    ? "CONCAT('RCH:', balance_007_refund.source_order_id)=p.order_id"
                    : 'balance_007_refund.source_order_id=p.order_id')
                ->where('balance_007_refund.source_type', $source)
                ->where('balance_007_refund.operation_type', 'refund')
                ->where('balance_007_refund.status', 'succeeded');
        });
    }

    /** 同一事实 ID 只出现一次；金额全程按整数分计算。 */
    private function fact(array $fact, array $stores, array $range, array &$seen, string $kind): ?array
    {
        $id = trim((string)($fact['fact_id'] ?? ''));
        $store = filter_var($fact['store_id'] ?? null, FILTER_VALIDATE_INT);
        $member = filter_var($fact['member_id'] ?? null, FILTER_VALIDATE_INT);
        $day = (string)($fact['business_date'] ?? '');
        if ($id === '' || isset($seen[$kind . ':' . $id]) || $store === false || $member === false
            || $member < 1 || !in_array($store, $stores, true) || $day < $range['start'] || $day > $range['end']
            || !self::validDate($day)) return null;
        $seen[$kind . ':' . $id] = true;
        if ($kind === 'balance' && ($fact['source_kind'] ?? '') === 'historical_007_original') {
            // 旧凭据只有实际余额总额，绝不伪称为本金或赠金任一项。
            $amount = $this->integer($fact['amount_cents'] ?? null);
            if ($amount === null || $amount <= 0) return null;
        } elseif ($kind === 'balance') {
            $principal = $this->integer($fact['principal_delta_cents'] ?? null);
            $bonus = $this->integer($fact['bonus_delta_cents'] ?? null);
            if ($principal === null || $bonus === null || $principal > 0 || $bonus > 0) return null;
            $debit = $this->add($principal, $bonus);
            if ($debit === PHP_INT_MIN) return null;
            $amount = -$debit;
        } else {
            $amount = $this->integer($fact['amount_cents'] ?? null);
            if ($amount === null) return null;
        }
        return [$store . ':' . $member . ':' . $day, $store, $member, $day, $amount];
    }

    private function initDay(array &$days, string $key, int $store, int $member, string $day, array $fact): bool
    {
        $name = trim((string)($fact['store_name_snapshot'] ?? ''));
        if ($name === '') return false;
        if (isset($days[$key])) return $days[$key]['store_name'] === $name;
        $days[$key] = ['store_id' => $store, 'store_name' => $name, 'member_id' => $member,
            'member_name' => trim((string)($fact['member_name_snapshot'] ?? '')),
            'business_date' => $day, 'balance_cents' => 0, 'recharge_cents' => 0];
        return true;
    }

    private function detailRows(array $days, array $profiles): array
    {
        $out = [];
        foreach ($days as $day) {
            $actual = $day['balance_cents'] - $day['recharge_cents'];
            if ($actual < 0) continue;
            $profile = $profiles[$day['member_id']] ?? [];
            $nickname = trim((string)($profile['nickname'] ?? ''));
            $label = ($nickname !== '' ? $nickname : $day['member_name']) . '/' . $day['member_id'];
            if (array_key_exists('delete_time', $profile) && $profile['delete_time'] !== null) $label .= '(已注销)';
            $out[] = ['row_id' => 'balance-007:detail:' . $day['store_id'] . ':' . $day['member_id'] . ':' . $day['business_date'],
                'store_id' => $day['store_id'], 'store_name' => $day['store_name'],
                'member_id' => $day['member_id'], 'business_date' => $day['business_date'],
                'user_info' => $label,
                'balance_amount' => $day['balance_cents'] / 100,
                'recharge_amount' => $day['recharge_cents'] / 100, 'actual_deduct' => $actual / 100,
                'has_recharge' => $day['recharge_cents'] > 0 ? '是' : '否'];
        }
        usort($out, static fn(array $a, array $b): int => [$a['store_id'], $a['business_date'], $a['member_id']]
            <=> [$b['store_id'], $b['business_date'], $b['member_id']]);
        return $out;
    }

    private function summaryRows(array $days): array
    {
        $members = [];
        foreach ($days as $day) {
            $key = $day['store_id'] . ':' . $day['member_id'];
            if (!isset($members[$key])) $members[$key] = ['store_id' => $day['store_id'], 'store_name' => $day['store_name'],
                'balance_cents' => 0, 'recharge_cents' => 0];
            $members[$key]['balance_cents'] = $this->add($members[$key]['balance_cents'], $day['balance_cents']);
            $members[$key]['recharge_cents'] = $this->add($members[$key]['recharge_cents'], $day['recharge_cents']);
        }
        $stores = [];
        foreach ($members as $member) {
            $actual = $member['balance_cents'] - $member['recharge_cents'];
            if ($actual < 0) continue;
            $store = $member['store_id'];
            if (!isset($stores[$store])) $stores[$store] = ['store_id' => $store, 'store_name' => $member['store_name'],
                'balance_cents' => 0, 'recharge_cents' => 0, 'actual_cents' => 0];
            $stores[$store]['balance_cents'] = $this->add($stores[$store]['balance_cents'], $member['balance_cents']);
            $stores[$store]['recharge_cents'] = $this->add($stores[$store]['recharge_cents'], $member['recharge_cents']);
            $stores[$store]['actual_cents'] = $this->add($stores[$store]['actual_cents'], $actual);
        }
        ksort($stores, SORT_NUMERIC);
        $out = [];
        foreach ($stores as $store) $out[] = ['row_id' => 'balance-007:summary:' . $store['store_id'],
            'store_id' => $store['store_id'], 'store_name' => $store['store_name'],
            'balance_amount' => $store['balance_cents'] / 100,
            'recharge_amount' => $store['recharge_cents'] / 100,
            'actual_deduct' => $store['actual_cents'] / 100];
        return $out;
    }

    private function stores(array $stores, array $input): array
    {
        $scope = $input['_report_scope'] ?? [];
        if (!is_array($scope) || !in_array($scope['mode'] ?? '', ['all', 'stores'], true)) {
            throw new \InvalidArgumentException('当前权限不能读取余额订单报表');
        }
        $out = [];
        foreach ($stores as $store) {
            if ((!is_int($store) && !(is_string($store) && ctype_digit($store))) || (int)$store < 1) {
                throw new \InvalidArgumentException('授权门店无效');
            }
            $out[(int)$store] = (int)$store;
        }
        if (isset($input['store_id']) && $input['store_id'] !== '') $out = array_intersect_key($out, [(int)$input['store_id'] => true]);
        return array_values($out);
    }

    private function range(array $input): array
    {
        $start = (string)($input['start_date'] ?? '');
        $end = (string)($input['end_date'] ?? '');
        $asOf = (string)($input['as_of_date'] ?? $end);
        if (!self::validDate($start) || !self::validDate($end) || !self::validDate($asOf) || $start > $end) {
            throw new \InvalidArgumentException('余额订单报表日期范围无效');
        }
        return ['start' => $start, 'end' => min($end, $asOf)];
    }

    private static function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function integer($value): ?int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^-?(?:0|[1-9][0-9]*)$/D', (string)$value)) return null;
        if (bccomp((string)$value, (string)PHP_INT_MAX, 0) > 0 || bccomp((string)$value, (string)PHP_INT_MIN, 0) < 0) return null;
        return (int)$value;
    }

    private function add(int $left, int $right): int
    {
        $sum = bcadd((string)$left, (string)$right, 0);
        $value = $this->integer($sum);
        if ($value === null) throw new \OverflowException('余额订单金额超出整数分范围');
        return $value;
    }

    private function gap(array $result, string $code, string $message): array
    {
        $result['complete'] = false;
        $result['data_gaps'][] = ['code' => $code, 'message' => $message];
        return $result;
    }

    private function sources(): array
    {
        return [
            'row_id' => '按授权门店、会员和业务日组成的稳定行键；汇总表按授权门店组成。',
            'store_id' => '交易门店的稳定编号，用于权限和精确筛选。',
            'member_id' => '会员的稳定编号，用于同店同人充值配对。',
            'store_name' => '统一事实的门店名称快照；历史原凭据按其原交易门店编号关联门店名称。',
            'business_date' => '交易结算所属的业务日期。',
            'user_info' => '优先显示会员当前昵称，昵称为空时显示交易记录中的姓名；后接会员编号，已注销会员附加标识。',
            'balance_amount' => '有效原销售订单正向余额扣款，按授权门店、会员和业务日归集；单位元。',
            'recharge_amount' => '同一授权门店、同一会员、同一业务日的有效原充值收款，每笔只计一次；单位元。',
            'actual_deduct' => '余额支付金额减同日充值金额；仅在满足 007 非负显示条件时展示。',
            'has_recharge' => '同门店、同会员、同业务日有正向原充值收款则为是。',
        ];
    }

    /** 当前昵称及注销标记是 007 用户信息展示口径，不参与金额计算。 */
    private function loadMemberProfiles(array $memberIds): array
    {
        $profiles = [];
        foreach (array_chunk($memberIds, 300) as $chunk) {
            foreach (Db::name('user')->whereIn('uid', $chunk)
                ->field('uid,nickname,delete_time')->select()->toArray() as $member) {
                $profiles[(int)$member['uid']] = ['nickname'=>(string)$member['nickname'],
                    'delete_time'=>$member['delete_time']];
            }
        }
        return $profiles;
    }

    private function assertCode(string $code): void
    {
        if (!in_array($code, self::codes(), true)) throw new \InvalidArgumentException('未配置该余额订单报表');
    }
}
