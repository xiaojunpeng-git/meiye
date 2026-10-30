<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 007 其他报表的专用可见投影。金额与数量只取统一事实或当前权益权威，
 * 原单只作为跟单身份凭据；缺少可证关系时报告缺口，不推算一笔经营事实。
 */
final class OtherReports007ReadServices
{
    public const DAILY = 'basic_daily_reconciliation';
    public const FOLLOWUP = 'basic_followup_employee_detail';
    public const RIGHTS = 'basic_customer_entitlements';
    private const LIMIT = 10000;
    private $dailySource;
    private $followupSource;
    private $rightsSource;
    private $nativeFollowupSource;
    private $rightsPopulation;

    /** 注入仅供独立合同测试；生产加载器均只读。 */
    public function __construct(?callable $dailySource = null, ?callable $followupSource = null,
        ?callable $rightsSource = null, ?callable $nativeFollowupSource = null,
        ?callable $rightsPopulation = null)
    {
        $this->dailySource = $dailySource ?? [$this, 'loadDaily'];
        $this->followupSource = $followupSource ?? [$this, 'loadFollowup'];
        $this->nativeFollowupSource = $nativeFollowupSource ?? [$this, 'loadNativeFollowup'];
        $this->rightsSource = $rightsSource ?? static function (array $stores, array $input): array {
            return (new BasicEntitlementReportServices())->read(self::RIGHTS, $stores, $input);
        };
        $this->rightsPopulation = $rightsPopulation ?? [$this, 'loadRightsPopulation'];
    }

    public static function codes(): array { return [self::DAILY, self::FOLLOWUP, self::RIGHTS]; }

    /** 前七列、前十列和每日宽表均按 007 页面实际顺序声明。 */
    public function definitions(string $code): array
    {
        $this->assertCode($code);
        if ($code === self::DAILY) {
            $columns = [['payment_name', '支付方式', 'text'], ['total_amount', '合计', 'decimal']];
            for ($day = 1; $day <= 31; ++$day) $columns[] = ['day_' . $day, $day . '号', 'decimal'];
            $columns[] = ['method_code', '支付方式代码', 'text', false];
        } elseif ($code === self::FOLLOWUP) {
            $columns = [['store_name', '门店', 'text'], ['business_date', '日期', 'date'],
                ['customer_name', '客户名称', 'text'], ['project_name', '项目', 'text'],
                ['quantity', '次数', 'decimal'], ['amount', '金额', 'decimal'],
                ['followup_name', '跟单人员', 'text'], ['store_id', '门店ID', 'integer', false],
                ['followup_staff_id', '跟单任职ID', 'integer', false]];
        } else {
            $columns = [['store_name', '门店', 'text'], ['customer_name', '姓名', 'text'],
                ['phone', '电话', 'text'], ['principal_amount', '本金', 'decimal'],
                ['gift_amount', '赠金', 'decimal'], ['category_name', '分类', 'text'],
                ['card_name', '卡名称', 'text'], ['project_name', '项目', 'text'],
                ['remaining_times', '剩余次数', 'decimal'], ['order_no', '订单号', 'text'],
                ['store_id', '门店ID', 'integer', false], ['member_id', '会员ID', 'integer', false],
                ['card_holder_id', '持卡实例ID', 'integer', false],
                ['project_detail_id', '权益项目明细ID', 'integer', false]];
        }
        $columns[] = ['row_id', '稳定行键', 'text', false];
        return array_map(static function (array $column): array {
            [$key, $label, $type] = $column;
            $visible = $column[3] ?? true;
            return ['key' => $key, 'label' => $label, 'type' => $type,
                'defaultVisible' => $visible, 'defaultQuick' => in_array($key,
                    ['payment_name', 'store_name', 'business_date', 'customer_name', 'phone', 'project_name'], true),
                'width' => $type === 'decimal' ? 145 : 180,
                'fixed' => in_array($key, ['payment_name', 'store_name'], true) ? 'left' : '',
                'money' => in_array($key, ['total_amount', 'amount', 'principal_amount', 'gift_amount'], true)
                    || strncmp($key, 'day_', 4) === 0,
                'summable' => false];
        }, $columns);
    }

    /** Host 负责同一结果的查询、分页、下钻和导出；本服务只形成完整有界人口。 */
    public function read(string $code, array $stores, array $input): array
    {
        $this->assertCode($code);
        $scope = $input['_report_scope'] ?? null;
        if (!is_array($scope) || !in_array($scope['mode'] ?? '', ['all', 'stores'], true)) {
            throw new \InvalidArgumentException('当前权限不能读取这张报表');
        }
        foreach ($stores as $store) if (!is_int($store) || $store < 1) {
            throw new \InvalidArgumentException('授权门店无效');
        }
        $stores = array_values(array_unique($stores)); sort($stores, SORT_NUMERIC);
        if (isset($input['store_id']) && $input['store_id'] !== '') {
            $store = filter_var($input['store_id'], FILTER_VALIDATE_INT);
            if ($store === false || $store < 1) throw new \InvalidArgumentException('门店筛选无效');
            $stores = array_values(array_intersect($stores, [$store]));
        }
        $range = $code === self::RIGHTS ? null : $this->range($input);
        $result = ['rows' => [], 'fields' => $this->definitions($code),
            'field_sources' => $this->fieldSources($code), 'sum_fields' => [],
            'table_layout' => ['summary_position' => 'none'],
            'complete' => true, 'data_gaps' => [], 'data_as_of' => time(),
            'metric_version' => 'other-reports-007-v3-projection-v1'];
        if (!$stores) return $result;
        if ($code === self::DAILY) return $this->daily($stores, $range, $result);
        if ($code === self::FOLLOWUP) return $this->followup($stores, $range, $result);
        return $this->rights($stores, $input, $result);
    }

    /** 007 把查询期内同月日号汇在一格，始终保留 1–31 号列。 */
    private function daily(array $stores, array $range, array $result): array
    {
        try {
            $source = ($this->dailySource)($stores, $range);
        } catch (\RuntimeException $error) {
            if (preg_match('/^(DAILY_[A-Z_]+): (.+)$/D', $error->getMessage(), $match)) {
                return $this->gap($result, $match[1], $match[2]);
            }
            throw $error;
        }
        if (!is_array($source) || !isset($source['methods'], $source['receipts'])
            || count($source['receipts']) > self::LIMIT) return $this->gap($result, 'DAILY_SOURCE_INCOMPLETE', '对账来源超过完整读取范围。');
        if (!empty($source['unresolved_legacy_count'])) {
            return $this->gap($result, 'BUSINESS_DATE_UNRESOLVED',
                '旧已付订单缺少支付时间，无法证明统一业务日；不能按创建日归入查询期。');
        }
        if (!empty($source['data_gaps'])) {
            $result['complete'] = false;
            $result['data_gaps'] = $source['data_gaps'];
            return $result;
        }
        $methods = []; $rows = [];
        foreach ($source['methods'] as $method) {
            $code = (string)($method['code'] ?? ''); $name = trim((string)($method['name'] ?? ''));
            if ($code === '' || $name === '' || isset($methods[$code])) return $this->gap($result, 'DAILY_METHOD_INVALID', '支付方式目录不完整或重复。');
            $methods[$code] = true;
            $row = ['row_id' => 'daily-007:' . $code, 'method_code' => $code,
                'payment_name' => $name, 'total_amount' => 0];
            for ($day = 1; $day <= 31; ++$day) $row['day_' . $day] = 0;
            $rows[$code] = $row;
        }
        $seen = []; $cents = [];
        foreach ($source['receipts'] as $receipt) {
            $id = trim((string)($receipt['receipt_id'] ?? ''));
            $code = (string)($receipt['method_code'] ?? '');
            $store = (int)($receipt['store_id'] ?? 0); $date = (string)($receipt['business_date'] ?? '');
            $amount = $this->cents($receipt['amount_cents'] ?? null);
            if ($id === '' || isset($seen[$id]) || !isset($methods[$code]) || !in_array($store, $stores, true)
                || $date < $range['start'] || $date > $range['end'] || !$this->validDate($date) || $amount === null) {
                return $this->gap($result, 'DAILY_RECEIPT_INVALID', '对账收款身份、门店、日期或金额无法核实。');
            }
            $seen[$id] = true;
            $day = (int)substr($date, 8, 2);
            $cents[$code]['total_amount'] = $this->add($cents[$code]['total_amount'] ?? 0, $amount);
            $cents[$code]['day_' . $day] = $this->add($cents[$code]['day_' . $day] ?? 0, $amount);
        }
        foreach ($rows as $code => &$row) foreach ($cents[$code] ?? [] as $key => $amount) $row[$key] = $amount / 100;
        unset($row);
        $result['rows'] = array_values($rows);
        $result['coverage'] = '有效历史收款、统一收款事实与独立人工刷单登记；原单退款、作废不计。';
        $result['limitations'] = ['新收款方式若无 007 旧支付配置，以独立支付方式行保留，不并入名称相近的旧方式。'];
        return $result;
    }

    /** 只投影有原单跟单身份且 V3 销售事实能逐行证明的项目销售。 */
    private function followup(array $stores, array $range, array $result): array
    {
        $source = ($this->followupSource)($stores, $range);
        if (!is_array($source) || !isset($source['rows']) || count($source['rows']) > self::LIMIT) {
            return $this->gap($result, 'FOLLOWUP_SOURCE_LIMIT', '跟单销售来源超过完整读取范围。');
        }
        if (!empty($source['unresolved_legacy_count'])) {
            return $this->gap($result, 'BUSINESS_DATE_UNRESOLVED',
                '原跟单项目订单缺少支付时间，无法证明统一业务日；不能按创建日归入查询期。');
        }
        if (!empty($source['unmapped_legacy_count'])) {
            return $this->gap($result, 'FOLLOWUP_LEGACY_SALE_NOT_MIGRATED',
                '查询范围内有原跟单项目销售尚无对应 V3 销售事实，不能返回不完整明细。');
        }
        // 新 V3 销售只有正式签发凭据能证明原单跟单任职才进入本表。
        // 普通项目销售没有跟单身份不属于数据缺口，也不得猜成跟单。
        try {
            $native = ($this->nativeFollowupSource)($stores, $range);
        } catch (\Throwable $error) {
            return $this->gap($result, 'FOLLOWUP_NATIVE_SOURCE_UNVERIFIED',
                '新销售跟单身份或对应 V3 销售事实无法逐行核实。');
        }
        if (!is_array($native) || !isset($native['rows']) || count($native['rows']) > self::LIMIT) {
            return $this->gap($result, 'FOLLOWUP_NATIVE_SOURCE_UNVERIFIED', '新销售跟单身份来源无法核实。');
        }
        $source['rows'] = array_merge($source['rows'], $native['rows']);
        if (count($source['rows']) > self::LIMIT) {
            return $this->gap($result, 'FOLLOWUP_SOURCE_LIMIT', '跟单销售结果超过完整读取范围。');
        }
        $seen = []; $rows = [];
        foreach ($source['rows'] as $sale) {
            $id = (string)($sale['fact_id'] ?? ''); $store = (int)($sale['store_id'] ?? 0);
            $date = (string)($sale['business_date'] ?? ''); $staff = (int)($sale['followup_staff_id'] ?? 0);
            $amount = $this->cents($sale['amount_cents'] ?? null);
            $quantity = $sale['quantity'] ?? null;
            if ($id === '' || isset($seen[$id]) || $staff < 1 || !in_array($store, $stores, true)
                || !$this->validDate($date) || $date < $range['start'] || $date > $range['end']
                || $amount === null || $amount < 0 || !is_numeric($quantity) || (float)$quantity < 0) {
                return $this->gap($result, 'FOLLOWUP_FACT_INVALID', '跟单销售事实或任职身份无法核实。');
            }
            $seen[$id] = true;
            $rows[] = ['row_id' => 'followup-007:' . $id, 'store_id' => $store,
                'store_name' => (string)$sale['store_name'], 'business_date' => $date,
                'customer_name' => (string)$sale['customer_name'], 'project_name' => (string)$sale['project_name'],
                'quantity' => (float)$quantity, 'amount' => $amount / 100,
                'followup_staff_id' => $staff, 'followup_name' => (string)$sale['followup_name']];
        }
        usort($rows, static function (array $a, array $b): int {
            $date = strcmp($b['business_date'], $a['business_date']);
            return $date !== 0 ? $date : [$a['store_name'], $a['followup_name'], $a['row_id']]
                <=> [$b['store_name'], $b['followup_name'], $b['row_id']];
        });
        $result['rows'] = $rows;
        $result['coverage'] = '原订单明确记录的跟单项目销售，加正式签发凭据可证明跟单任职的新销售；金额、次数均读取 V3 销售事实。';
        return $result;
    }

    /** 007 的每条有效卡内项目重复展示所属会员本金/赠金，但不给重复值做合计。 */
    private function rights(array $stores, array $input, array $result): array
    {
        try {
            $source = ($this->rightsSource)($stores, $input);
        } catch (\RuntimeException $error) {
            // 迁入持卡实例缺少可证来源门店时整表留空并声明缺口；不能按会员当前门店猜测卡归属。
            if ($error->getMessage() === 'ENTITLEMENT_HOLDER_STORE_UNRESOLVED') {
                $label = '部分持卡项目缺少可核实的归属门店，当前权益清单暂不完整。';
                $result = $this->gap($result, 'ENTITLEMENT_HOLDER_STORE_UNRESOLVED', $label);
                // 共享页面读取label展示具体缺口，不能只在内部message中保留原因。
                $result['data_gaps'][count($result['data_gaps']) - 1]['label'] = $label;
                return $result;
            }
            throw $error;
        }
        if (!is_array($source) || !isset($source['rows']) || count($source['rows']) > self::LIMIT) {
            return $this->gap($result, 'RIGHTS_SOURCE_INCOMPLETE', '当前会员权益来源超出完整读取范围。');
        }
        // 当前权益 Reader 是唯一可用余额与卡内项目权威；若其自身报告缺口，
        // 不能再把其中的部分项目投影成看似完整的 007 权益清单。
        if (($source['complete'] ?? false) !== true) {
            $result['complete'] = false;
            $result['data_gaps'] = $source['data_gaps'] ?? [['code' => 'RIGHTS_SOURCE_INCOMPLETE',
                'message' => '当前会员权益权威来源未完成，不能展示部分项目。']];
            return $result;
        }
        // The current-asset authority is intentionally stricter than the old
        // report on member and card visibility. Audit the full 007 eligible
        // population first: silently omitting an old valid project would make
        // a partial result look complete. This query never supplies amounts.
        try { $population = ($this->rightsPopulation)($stores); }
        catch (\Throwable $error) {
            return $this->gap($result, 'RIGHTS_POPULATION_UNVERIFIED', '有效权益项目范围无法完整核实。');
        }
        if (!is_array($population) || count($population) > self::LIMIT)
            return $this->gap($result, 'RIGHTS_POPULATION_UNVERIFIED', '有效权益项目范围无法完整核实。');
        $balances = []; $rows = [];
        foreach ($source['rows'] as $row) if (($row['asset_type'] ?? '') === '储值') {
            $id = (int)($row['member_id'] ?? 0);
            if ($id < 1 || isset($balances[$id])) return $this->gap($result, 'RIGHTS_BALANCE_IDENTITY', '会员储值身份缺失或重复。');
            $balances[$id] = $row;
        }
        foreach ($source['rows'] as $row) {
            if (($row['asset_type'] ?? '') !== '卡内项目' || (float)($row['remaining_times'] ?? 0) <= 0) continue;
            $id = (int)($row['member_id'] ?? 0); $holder = (int)($row['card_holder_id'] ?? 0);
            $project = (int)($row['project_detail_id'] ?? 0); $store = (int)($row['store_id'] ?? 0);
            if ($id < 1 || $holder < 1 || $project < 1 || !isset($balances[$id])
                || !in_array($store, $stores, true) || (int)$balances[$id]['store_id'] !== $store) {
                return $this->gap($result, 'RIGHTS_PROJECT_IDENTITY', '卡内项目与会员储值身份不能精确关联。');
            }
            if (!isset($population[$project])) continue;
            if ((int)$population[$project]['member_id'] !== $id || (int)$population[$project]['store_id'] !== $store)
                return $this->gap($result, 'RIGHTS_PROJECT_IDENTITY', '有效项目所属会员或门店无法核实。');
            unset($population[$project]);
            $rows[] = ['row_id' => 'rights-007:' . $holder . ':' . $project,
                'store_id' => $store, 'member_id' => $id, 'card_holder_id' => $holder,
                'project_detail_id' => $project, 'store_name' => (string)$row['store_name'],
                'customer_name' => (string)$row['customer_name'], 'phone' => (string)$row['phone'],
                'principal_amount' => $balances[$id]['principal_amount'],
                'gift_amount' => $balances[$id]['gift_amount'],
                'category_name' => (string)$row['category_name'], 'card_name' => (string)$row['card_name'],
                'project_name' => (string)$row['project_name'],
                'remaining_times' => $row['remaining_times'], 'order_no' => (string)$row['order_no']];
        }
        if ($population) return $this->gap($result, 'RIGHTS_PROJECT_NOT_IN_CURRENT_AUTHORITY',
            '有效原卡项目尚未进入当前权益权威，不能显示不完整清单。');
        $result['rows'] = $rows;
        $result['complete'] = true;
        $result['time_mode'] = 'current';
        $result['as_of_date'] = $source['as_of_date'] ?? null;
        $result['coverage'] = '当前有效卡内项目且剩余次数大于零；储值取同一会员当前权威余额。';
        $result['limitations'] = ['本金、赠金随每条权益项目重复展示，不能逐行求和；不提供历史时点重建。',
            '卡项来源门店也须在已授权范围内，不能通过会员所属门店越权读取。'];
        return $result;
    }

    /**
     * 只读 007 原 SQL 的项目资格：有效原单、非赠送卡内项目、未过期且
     * 仍有次数，会员当前所属门店须在授权范围内。本源只审计人口，所有
     * 余额和剩余次数仍从当前权益权威读取，不回算旧表金额。
     */
    private function loadRightsPopulation(array $stores): array
    {
        $population = [];
        $cursor = 0;
        do {
            // Keyset every original component by its primary key. A bounded
            // component range keeps MySQL on PRIMARY instead of its slow
            // paid-order index merge. Every component is visited once, so
            // current-member ownership through ci.uid stays exact even when
            // it differs from the original order buyer.
            $detailIds = array_map('intval', Db::name('store_order_cart_info')
                ->where('id', '>', $cursor)->order('id', 'asc')->limit(10000)->column('id'));
            if (!$detailIds) break;
            $firstId = $detailIds[0];
            $cursor = $detailIds[count($detailIds) - 1];
            $query = Db::name('store_order_cart_info')->alias('ci')
                ->join('store_order o', 'o.id=ci.oid')
                ->join('user u', 'u.uid=ci.uid')
                ->join('system_store ss', 'ss.id=u.belong_store_id')
                ->join('store_product cp', 'cp.id=ci.product_id')
                ->leftJoin('store_product parent', 'parent.id=cp.pid AND cp.pid>0')
                ->whereBetween('ci.id', [$firstId, $cursor])
                ->whereIn('u.belong_store_id', $stores)
                ->where('u.uid', '>', 0)->where('u.is_del', 0)
                ->where('ss.name', '<>', '')->where('ss.name', '<>', '总部')
                ->where('o.paid', 1)->where('o.is_del', 0)->where('o.is_system_del', 0)
                ->whereIn('o.refund_status', [0, 3])
                ->whereRaw('IFNULL(o.is_auto,0)<>1 AND (o.pid IN (0,-2) OR o.pid>0)')
                ->whereRaw('IFNULL(ci.is_card,0)=1 AND IFNULL(ci.is_gift,0)=0')
                ->where('ci.write_surplus_times', '>', 0)
                ->whereRaw('(ci.write_end=0 OR ci.write_end>=UNIX_TIMESTAMP(NOW()))')
                ->whereRaw("IFNULL(NULLIF(parent.store_name,''),cp.store_name)<>''")
                ->field('ci.id,ci.uid,u.belong_store_id')->select()->toArray();
            foreach ($query as $row) {
                $id = (int)$row['id'];
                if ($id < 1 || isset($population[$id])) throw new \LogicException('RIGHTS_SOURCE_IDENTITY_INVALID');
                $population[$id] = ['member_id'=>(int)$row['uid'], 'store_id'=>(int)$row['belong_store_id']];
                if (count($population) > self::LIMIT) throw new \RuntimeException('RIGHTS_SOURCE_LIMIT_EXCEEDED');
            }
        } while (count($detailIds) === 10000);
        return $population;
    }

    private function loadDaily(array $stores, array $range): array
    {
        $types = Db::name('cash_type')->where('id', '<>', 9)->order('id', 'asc')
            ->field('id,name')->select()->toArray();
        $methods = []; $byName = [];
        foreach ($types as $type) {
            $id = (int)$type['id']; $name = trim((string)$type['name']);
            if ($id < 1 || $name === '' || isset($byName[$name])) {
                throw new \RuntimeException('DAILY_METHOD_DIRECTORY_INVALID: 支付方式目录无法精确识别。');
            }
            $methods[] = ['code' => 'legacy_' . $id, 'name' => $name]; $byName[$name] = $id;
        }
        $methods[] = ['code' => 'brush_douyin', 'name' => '抖音刷单'];
        $methods[] = ['code' => 'brush_dianping', 'name' => '大众刷单'];
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $from = (new \DateTimeImmutable($range['start'] . ' 00:00:00', $timezone))->getTimestamp();
        $until = (new \DateTimeImmutable($range['end'] . ' 23:59:59', $timezone))->getTimestamp();
        $payment = Db::name('cashier_v3_payment_fact')->alias('p')
            ->leftJoin('cashier_v3_payment_collection c', 'c.tenant_id=p.tenant_id AND c.store_id=p.store_id AND c.collection_id=p.collection_reference')
            ->leftJoin('cashier_v3_sales_order h', "h.tenant_id=p.tenant_id AND h.store_id=p.store_id AND h.order_id=p.order_id AND h.source_document_type='legacy_store_order'")
            ->leftJoin('store_order o', 'CAST(o.id AS CHAR)=h.source_document_id AND o.store_id=h.store_id AND o.order_id=h.order_no')
            ->where('p.tenant_id', '0')->whereIn('p.store_id', $stores)
            ->where('p.status', 'effective')->where('p.fact_direction', 'forward')
            ->whereIn('p.fact_type', ['payment_collected', 'payment_received'])
            ->where('p.payment_method', '<>', 'old_card_entry')
            // 全部收款与统一报表共用 V3 业务日；旧单下单时间只保留在迁入覆盖审计。
            ->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->whereNotExists(static function ($operation): void {
                $operation->name('cashier_v3_order_lifecycle_operation')->alias('daily_007_cancel')
                    ->whereRaw('daily_007_cancel.tenant_id=p.tenant_id')
                    ->whereRaw('daily_007_cancel.store_id=p.store_id')
                    ->whereRaw("((daily_007_cancel.source_type='sales' AND daily_007_cancel.source_order_id=p.order_id)"
                        . " OR (daily_007_cancel.source_type='recharge' AND CONCAT('RCH:',daily_007_cancel.source_order_id)=p.order_id))")
                    ->whereIn('daily_007_cancel.operation_type', ['refund', 'void'])
                    ->where('daily_007_cancel.status', 'succeeded');
            })
            ->field('p.fact_id,p.store_id,p.business_date,p.source_document_type,p.payment_method,p.amount_cents,c.payment_method_name_snapshot,o.id source_order_id,o.paid,o.refund_status,o.is_del,o.order_type,o.cash_choose,h.order_status,h.order_direction')
            ->limit(self::LIMIT + 1)->select()->toArray();
        if (count($payment) > self::LIMIT) throw new \RuntimeException('DAILY_SOURCE_LIMIT: 收款事实过多，请缩小范围。');
        $fixed = ['unionpay' => 1, 'dianping_voucher' => 2, 'douyin_voucher' => 3,
            'wechat' => 4, 'partner_collection' => 6, 'cash' => 7];
        $newNames = ['alipay' => '支付宝', 'other_collection' => '其他收款'];
        $receipts = [];
        foreach ($payment as $fact) {
            $legacy = $fact['source_document_type'] === 'legacy_store_order';
            if ($legacy) {
                if ($fact['source_order_id'] === null) {
                    throw new \RuntimeException('DAILY_SOURCE_IDENTITY_UNVERIFIED: 迁入旧单收款缺少原单身份凭据。');
                }
                if ((int)$fact['paid'] !== 1 || (int)$fact['is_del'] !== 0
                    || (int)$fact['refund_status'] !== 0
                    || !in_array((int)$fact['order_type'], [0, 1], true)
                    || (int)$fact['cash_choose'] === 9
                    || $fact['order_status'] !== 'settled' || $fact['order_direction'] !== 'forward') continue;
            } else {
                if (!in_array($fact['source_document_type'],
                    ['sales_order', 'cashier_snapshot', 'cashier_workspace', 'checkout', 'recharge'], true)) continue;
            }
            $date = (string)$fact['business_date'];
            $method = (string)$fact['payment_method']; $sourceName = trim((string)($fact['payment_method_name_snapshot'] ?? ''));
            if ($sourceName !== '' && isset($byName[$sourceName])) {
                $code = 'legacy_' . $byName[$sourceName];
            } elseif (isset($fixed[$method])) {
                $code = 'legacy_' . $fixed[$method];
            } elseif ($method === 'other_collection' && $sourceName !== '') {
                // 旧银行转账、其他收款与充值赠送均可编码为 other_collection；
                // 必须由正式收款行冻结的原支付方式名称精确判别。
                throw new \RuntimeException('DAILY_METHOD_SOURCE_UNMAPPED: 原支付方式名称无法对应目录。');
            } elseif (isset($newNames[$method])) {
                $code = 'v3_' . $method;
                if (!in_array($code, array_column($methods, 'code'), true)) {
                    $methods[] = ['code' => $code, 'name' => $newNames[$method]];
                }
            } else {
                throw new \RuntimeException('DAILY_METHOD_SOURCE_UNMAPPED: 收款方式无权威映射。');
            }
            $receipts[] = ['receipt_id' => 'payment:' . $fact['fact_id'], 'store_id' => (int)$fact['store_id'],
                'business_date' => $date, 'method_code' => $code, 'amount_cents' => $fact['amount_cents']];
        }
        // 刷单登记是独立人工权威，不进入实际收款事实；沿用 007 的登记日期。
        $registrations = Db::name('salary_store')->whereIn('store_id', $stores)
            ->whereBetween('date', [$from, $until])->field('id,store_id,date,salary_info')
            ->limit(self::LIMIT + 1)->select()->toArray();
        if (count($registrations) > self::LIMIT) throw new \RuntimeException('DAILY_REGISTRATION_LIMIT: 刷单登记过多。');
        foreach ($registrations as $registration) {
            $info = json_decode((string)$registration['salary_info'], true);
            if (!is_array($info)) {
                if (strpos((string)$registration['salary_info'], 'shuadan') !== false) {
                    throw new \RuntimeException('DAILY_REGISTRATION_INVALID: 刷单登记内容无效。');
                }
                continue;
            }
            $platform = (string)($info['shuadanpingtai'] ?? '');
            if (!in_array($platform, ['抖音', '大众'], true)) continue;
            $amount = $this->yuanToCents($info['shuadanjine'] ?? null);
            if ($amount === null) throw new \RuntimeException('DAILY_REGISTRATION_INVALID: 刷单金额无效。');
            $receipts[] = ['receipt_id' => 'registration:' . $registration['id'],
                'store_id' => (int)$registration['store_id'],
                'business_date' => (new \DateTimeImmutable('@' . (int)$registration['date']))->setTimezone($timezone)->format('Y-m-d'),
                'method_code' => $platform === '抖音' ? 'brush_douyin' : 'brush_dianping',
                'amount_cents' => $amount];
        }
        // 旧订单仍可能尚未迁入统一收款事实。这里只读身份与资格做缺口审计，
        // 不从旧金额补造 V3 事实或返回看似完整的每日合计。
        $legacySource = Db::name('store_order')->alias('legacy_daily')
            ->whereIn('legacy_daily.store_id', $stores)
            ->where('legacy_daily.paid', 1)->where('legacy_daily.is_del', 0)
            ->where('legacy_daily.refund_status', 0)->whereIn('legacy_daily.order_type', [0, 1])
            ->where('legacy_daily.cash_choose', '<>', 9)
            ->whereRaw('(legacy_daily.pid>=0 OR legacy_daily.pid=-2)')
            ->whereRaw("(legacy_daily.cash_pay_price>0 OR EXISTS (SELECT 1 FROM eb_combination_order legacy_comb"
                . " WHERE legacy_comb.order_id=legacy_daily.id AND legacy_comb.active_pay=2"
                . " AND legacy_comb.cash_choose<>9 AND legacy_comb.price>0))");
        // 支付时间缺失无法放进任何日期区间，必须独立标记，不能回退创建时间。
        $unresolved = (clone $legacySource)->whereRaw('(legacy_daily.pay_time IS NULL OR legacy_daily.pay_time=0)')
            ->limit(1)->value('legacy_daily.id');
        $unmapped = (clone $legacySource)->whereBetween('legacy_daily.pay_time', [$from, $until])
            ->whereNotExists(static function ($proof): void {
                $proof->name('cashier_v3_payment_fact')->alias('daily_migrated')
                    ->whereRaw('daily_migrated.tenant_id=\'0\'')
                    ->whereRaw('daily_migrated.store_id=legacy_daily.store_id')
                    ->whereRaw('daily_migrated.order_no_snapshot=legacy_daily.order_id')
                    ->where('daily_migrated.status', 'effective')
                    ->where('daily_migrated.fact_direction', 'forward')
                    ->whereIn('daily_migrated.fact_type', ['payment_received', 'payment_collected']);
            })->count('legacy_daily.id');
        $gaps = (int)$unmapped > 0 ? [['code' => 'DAILY_LEGACY_PAYMENT_NOT_MIGRATED',
            'message' => '查询范围内仍有未迁入 V3 收款事实的旧有效订单；每日合计不可核实。',
            'source_rows' => (int)$unmapped]] : [];
        return ['methods' => $methods, 'receipts' => $receipts, 'data_gaps' => $gaps,
            'unresolved_legacy_count' => (int)$unresolved > 0 ? 1 : 0];
    }

    /** 人工登记按十进制元解析，保持历史分精度且拒绝非数字内容。 */
    private function yuanToCents($value): ?int
    {
        $text = trim((string)$value);
        if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $text)) return null;
        $parts = explode('.', $text, 2); $fraction = str_pad($parts[1] ?? '', 2, '0');
        $number = bcadd(bcmul($parts[0], '100', 0), ($parts[0][0] ?? '') === '-' ? '-' . $fraction : $fraction, 0);
        return $this->cents($number);
    }

    /** 原单身份证明：正式迁移头与原订单同店同单，销售行与旧明细 ID 精确对应。 */
    private function loadFollowup(array $stores, array $range): array
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $from = (new \DateTimeImmutable($range['start'] . ' 00:00:00', $timezone))->getTimestamp();
        $until = (new \DateTimeImmutable($range['end'] . ' 23:59:59', $timezone))->getTimestamp();
        $query = Db::name('cashier_v3_sale_fact')->alias('s')
            ->join('cashier_v3_sales_order h', 'h.tenant_id=s.tenant_id AND h.store_id=s.store_id AND h.order_id=s.order_id')
            ->join('store_order o', 'CAST(o.id AS CHAR)=h.source_document_id AND o.store_id=h.store_id AND o.order_id=h.order_no')
            ->join('store_order_cart_info ci', "ci.oid=o.id AND IFNULL(ci.cart_type,0)=0 AND s.source_line_id=CONCAT('IMP007-',o.id,'-line-',ci.id)")
            ->join('system_store_staff gs', 'gs.id=o.gendan_staff_id AND gs.is_del=0')
            ->join('system_store ss', 'ss.id=o.store_id AND ss.is_del=0')
            ->leftJoin('user u', 'u.uid=o.uid AND u.is_del=0')
            ->where('s.tenant_id', '0')->whereIn('s.store_id', $stores)
            ->where('s.status', 'effective')->where('s.fact_direction', 'forward')->where('s.source_type', 'project')
            ->where('h.source_document_type', 'legacy_store_order')->where('h.contract_version', 'legacy-import-data-v1')
            ->where('h.order_status', 'settled')->where('h.order_direction', 'forward')
            ->whereBetween('s.business_date', [$range['start'], $range['end']])
            ->where('o.paid', 1)->where('o.is_del', 0)
            ->whereIn('o.refund_status', [0, 3])->where('o.order_type', 0)
            ->where('o.is_gendan', 1)->where('o.gendan_staff_id', '>', 0)->where('o.cash_choose', '<>', 9)
            ->whereRaw('IFNULL(o.is_auto,0)<>1')
            ->whereRaw('(o.pid IN (0,-2) OR o.pid>0)')
            ->whereNotExists(static function ($reversal): void {
                $reversal->name('cashier_v3_order_lifecycle_operation')->alias('fu_reversal')
                    ->whereRaw('fu_reversal.tenant_id=s.tenant_id')
                    ->whereRaw('fu_reversal.store_id=s.store_id')
                    ->whereRaw('fu_reversal.source_order_id=s.order_id')
                    ->where('fu_reversal.source_type', 'sales')->whereIn('fu_reversal.operation_type', ['refund', 'void'])
                    ->where('fu_reversal.status', 'succeeded');
            });
        $rows = $query->field("s.fact_id,s.store_id,ss.name store_name,s.business_date,gs.id followup_staff_id,gs.staff_name followup_name,s.item_name_snapshot project_name,s.quantity,s.sale_amount_cents amount_cents,COALESCE(NULLIF(TRIM(u.real_name),''),NULLIF(TRIM(u.nickname),''),TRIM(o.real_name),'') customer_name")
            ->limit(self::LIMIT + 1)->select()->toArray();
        // 旧有效跟单项目必须逐行有迁入销售事实。旧表只做完整性身份审计，
        // 金额和次数绝不从旧购物车补进 V3 投影。
        $legacySource = Db::name('store_order')->alias('legacy_followup')
            ->join('store_order_cart_info legacy_ci', 'legacy_ci.oid=legacy_followup.id AND IFNULL(legacy_ci.cart_type,0)=0')
            ->join('system_store_staff legacy_staff', 'legacy_staff.id=legacy_followup.gendan_staff_id AND legacy_staff.is_del=0')
            ->whereIn('legacy_followup.store_id', $stores)
            ->where('legacy_followup.paid', 1)->where('legacy_followup.is_del', 0)
            ->whereIn('legacy_followup.refund_status', [0, 3])->where('legacy_followup.order_type', 0)
            ->where('legacy_followup.is_gendan', 1)->where('legacy_followup.gendan_staff_id', '>', 0)
            ->where('legacy_followup.cash_choose', '<>', 9)
            ->whereRaw('IFNULL(legacy_followup.is_auto,0)<>1')
            ->whereRaw('(legacy_followup.pid IN (0,-2) OR legacy_followup.pid>0)');
        $unresolved = (clone $legacySource)
            ->whereRaw('(legacy_followup.pay_time IS NULL OR legacy_followup.pay_time=0)')
            ->limit(1)->value('legacy_ci.id');
        $unmapped = (clone $legacySource)->whereBetween('legacy_followup.pay_time', [$from, $until])
            ->whereNotExists(static function ($proof): void {
                $proof->name('cashier_v3_sale_fact')->alias('followup_migrated')
                    ->whereRaw("followup_migrated.tenant_id='0'")
                    ->whereRaw('followup_migrated.store_id=legacy_followup.store_id')
                    ->whereRaw('followup_migrated.order_no_snapshot=legacy_followup.order_id')
                    ->whereRaw("followup_migrated.source_line_id=CONCAT('IMP007-',legacy_followup.id,'-line-',legacy_ci.id)")
                    ->where('followup_migrated.status', 'effective')
                    ->where('followup_migrated.fact_direction', 'forward')
                    ->where('followup_migrated.source_type', 'project');
            })->count('legacy_ci.id');
        return ['rows' => $rows, 'unmapped_legacy_count' => (int)$unmapped,
            'unresolved_legacy_count' => (int)$unresolved > 0 ? 1 : 0];
    }

    /**
     * 复用既有活动 Reader 已验证的“正式卡签发→销售行→原订单跟单任职”链。
     * 这条链仅证明人员身份；金额、数量、业务日仍从同一 V3 销售事实读取。
     * 无签发凭据的普通新销售不冒充跟单，也不自动视为事实缺失。
     */
    private function loadNativeFollowup(array $stores, array $range): array
    {
        $input = ['_report_scope' => ['mode' => 'stores'], 'start_date' => $range['start'],
            'end_date' => $range['end']];
        $recognized = (new BasicActivityReportServices())->read(self::FOLLOWUP, $stores, $input);
        if (($recognized['complete'] ?? false) !== true || !isset($recognized['rows'])) {
            throw new \RuntimeException('FOLLOWUP_NATIVE_SOURCE_UNVERIFIED');
        }
        $people = []; $ids = [];
        foreach ($recognized['rows'] as $row) {
            $rowId = (string)($row['row_id'] ?? '');
            $last = strrpos($rowId, ':');
            if (strncmp($rowId, 'followup:', 9) !== 0 || $last === false || $last <= 9
                || !ctype_digit(substr($rowId, $last + 1))
                || (int)substr($rowId, $last + 1) !== (int)($row['followup_staff_id'] ?? 0)) {
                throw new \RuntimeException('FOLLOWUP_NATIVE_IDENTITY_INVALID');
            }
            $id = substr($rowId, 9, $last - 9);
            if (isset($people[$id])) throw new \RuntimeException('FOLLOWUP_NATIVE_IDENTITY_AMBIGUOUS');
            $people[$id] = $row; $ids[] = $id;
        }
        if ($ids === []) return ['rows' => []];
        if (count($ids) > self::LIMIT) throw new \RuntimeException('FOLLOWUP_NATIVE_SOURCE_LIMIT');
        $facts = Db::name('cashier_v3_sale_fact')->where('tenant_id', '0')->whereIn('store_id', $stores)
            ->whereIn('fact_id', $ids)->whereBetween('business_date', [$range['start'], $range['end']])
            ->where('status', 'effective')->where('fact_direction', 'forward')
            ->field('fact_id,store_id,store_name_snapshot,business_date,member_name_snapshot,'
                . 'item_name_snapshot,quantity,sale_amount_cents,source_type,source_document_type')
            ->limit(self::LIMIT + 1)->select()->toArray();
        $out = []; $seen = [];
        foreach ($facts as $fact) {
            $id = (string)$fact['fact_id'];
            if (isset($seen[$id])) throw new \RuntimeException('FOLLOWUP_NATIVE_FACT_AMBIGUOUS');
            $seen[$id] = true;
            // 迁入旧单由上方更严格的逐购物车身份审计处理，避免两条链重复显示。
            if ($fact['source_document_type'] === 'legacy_store_order') continue;
            if ($fact['source_type'] !== 'project') continue;
            $person = $people[$id] ?? null;
            if ($person === null || (int)$person['store_id'] !== (int)$fact['store_id']
                || (string)$person['business_date'] !== (string)$fact['business_date']) {
                throw new \RuntimeException('FOLLOWUP_NATIVE_FACT_IDENTITY_MISMATCH');
            }
            $out[] = ['fact_id' => $id, 'store_id' => $fact['store_id'],
                'store_name' => $fact['store_name_snapshot'], 'business_date' => $fact['business_date'],
                'customer_name' => $fact['member_name_snapshot'],
                'project_name' => $fact['item_name_snapshot'], 'quantity' => $fact['quantity'],
                'amount_cents' => $fact['sale_amount_cents'],
                'followup_staff_id' => $person['followup_staff_id'],
                'followup_name' => $person['employee_name']];
        }
        if (count($seen) !== count($people)) throw new \RuntimeException('FOLLOWUP_NATIVE_FACT_MISSING');
        return ['rows' => $out];
    }

    private function range(array $input): array
    {
        $start = (string)($input['start_date'] ?? ''); $end = (string)($input['end_date'] ?? '');
        $asOf = (string)($input['as_of_date'] ?? $end);
        if (!$this->validDate($start) || !$this->validDate($end) || !$this->validDate($asOf) || $start > $end) {
            throw new \InvalidArgumentException('报表日期范围无效');
        }
        return ['start' => $start, 'end' => min($end, $asOf)];
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function cents($value): ?int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^-?(?:0|[1-9][0-9]*)$/D', (string)$value)
            || bccomp((string)$value, (string)PHP_INT_MAX, 0) > 0
            || bccomp((string)$value, (string)PHP_INT_MIN, 0) < 0) return null;
        return (int)$value;
    }

    private function add(int $left, int $right): int
    {
        $sum = $this->cents(bcadd((string)$left, (string)$right, 0));
        if ($sum === null) throw new \OverflowException('报表金额超出整数分范围');
        return $sum;
    }

    private function gap(array $result, string $code, string $message): array
    {
        $result['complete'] = false;
        $result['data_gaps'][] = ['code' => $code, 'message' => $message];
        return $result;
    }

    /** 字段来源随可见列一起交付，隐藏身份列同样有明确来源。 */
    private function fieldSources(string $code): array
    {
        $sources = [];
        foreach ($this->definitions($code) as $field) {
            $key = $field['key'];
            if ($code === self::DAILY) {
                $sources[$key] = $key === 'payment_name' ? '原支付配置或新收款方式的名称；刷单为独立人工登记。'
                    : ($key === 'method_code' || $key === 'row_id' ? '稳定支付方式身份，不按名称合并。'
                    : ($key === 'total_amount' ? '本支付方式在查询范围内每日金额之和。'
                    : '本支付方式在查询范围内对应日号的有效收款与刷单登记金额；单位元。'));
            } elseif ($code === self::FOLLOWUP) {
                $sources[$key] = in_array($key, ['quantity', 'amount', 'project_name'], true)
                    ? '经原交易核实的项目销售；金额取已结算销售额，不重复分摊销售人员份额。'
                    : '已迁入原单的交易门店、业务日、客户或明确记录的跟单任职身份。';
            } else {
                $sources[$key] = in_array($key, ['principal_amount', 'gift_amount'], true)
                    ? '同会员当前储值权威余额；每条权益项目重复展示，不可按行累加。'
                    : '当前会员权益权威的有效卡内项目及其订单、门店、分类身份。';
            }
        }
        return $sources;
    }

    private function assertCode(string $code): void
    {
        if (!in_array($code, self::codes(), true)) throw new \InvalidArgumentException('未配置该 007 报表');
    }
}
