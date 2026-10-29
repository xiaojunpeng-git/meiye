<?php
declare(strict_types=1);
namespace app\services\report;

use app\services\order\ValidCashOrderServices;
use app\services\report\query\BasicRegistrationQueryProvider;
use think\facade\Db;

/**
 * 门店对账的统一收款适配边界。迁入旧单复用原权威收款资格及金额表达式，
 * 新业务读成功收款事实；已迁入的旧单收款事实不重复叠加。此Reader不写任何业务值。
 * 历史兼容来源会显式随报表返回，不能将尚未完整迁入的新事实冒充完整历史。
 */
final class StoreReconciliationReceiptReader
{
    public const LIMIT = 50000;
    private array $verifiedArchivedIds = [];

    /** 已授权且启用工资统计的门店沿用007显示顺序，同时间戳以ID保证稳定。 */
    public function stores(array $ids): array
    {
        if (!$ids) return [];
        return Db::name('system_store')->whereIn('id', $ids)->where('salary_status', 1)
            ->order('add_time', 'desc')->order('id', 'desc')->field('id,name')->select()->toArray();
    }

    /** 原支付目录保留停用但有历史意义的类型；新系统独有方式追加，不按显示文字匹配身份。 */
    public function methods(): array
    {
        $rows = [];
        foreach (Db::name('cash_type')->where('id', '<>', 9)->order('id', 'asc')->field('id,name')->select()->toArray() as $type) {
            $rows[] = ['code' => 'legacy_' . (int)$type['id'], 'name' => (string)$type['name'], 'legacy_id' => (int)$type['id']];
        }
        $known = array_column($rows, null, 'code');
        foreach (Db::name('cashier_v3_payment_method_config')->where('code', '<>', 'old_card_entry')
            ->order('sort', 'asc')->order('id', 'asc')->field('code,display_name,default_name')->select()->toArray() as $type) {
            $code = self::methodCode((string)$type['code']);
            if (!isset($known[$code])) {
                $rows[] = ['code' => $code, 'name' => trim((string)$type['display_name']) ?: (string)$type['default_name'], 'legacy_id' => 0];
                $known[$code] = true;
            }
        }
        $rows[] = ['code' => 'brush_douyin', 'name' => '抖音刷单', 'legacy_id' => 0];
        $rows[] = ['code' => 'brush_dianping', 'name' => '大众刷单', 'legacy_id' => 0];
        return $rows;
    }

    /**
     * 旧支付枚举在历史迁移中的固定语义；银行转账及充值赠送不猜作其他收款。
     * 新支付宝没有对应旧枚举，保留独立行；目录名称变化不改变收款归属。
     */
    public static function methodCode(string $code): string
    {
        $ids = ['unionpay' => 1, 'dianping_voucher' => 2, 'douyin_voucher' => 3,
            'wechat' => 4, 'partner_collection' => 6, 'cash' => 7, 'other_collection' => 8];
        return isset($ids[$code]) ? 'legacy_' . $ids[$code] : 'v3_' . $code;
    }

    /**
     * 一次有界、一致读取产生可追溯的收款明细。下单日期沿用原单add_time；
     * 新业务以收款来源的业务日入表。刷单使用登记日期，且只作为独立人工行。
     */
    public function receipts(array $stores, array $methods, array $range, string $tenant): array
    {
        if (!$stores) return [];
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $times = [(new \DateTimeImmutable($range['start_date'] . ' 00:00:00', $timezone))->getTimestamp(),
            (new \DateTimeImmutable($range['end_date'] . ' 23:59:59', $timezone))->getTimestamp()];
        $out = [];
        // 一次核实归档证明，后续通道查询复用ID白名单，避免每格/每单重复运行关联子查询。
        $this->verifiedArchivedIds = $this->archivedLegacyOrderIds($stores, $times, $tenant);
        $archived = function ($query, string $alias): void { $this->legacyVisibility($query, $alias); };
        foreach ($methods as $method) {
            if (empty($method['legacy_id'])) continue;
            $where = ['time' => $times, 'cash_choose' => (int)$method['legacy_id']];
            $direct = ValidCashOrderServices::storeCashIncomeByCashChooseQuery($where, $archived)->whereIn('store_id', $stores)
                ->field('id,order_id,store_id,add_time,uid')->fieldRaw(ValidCashOrderServices::buildAmountExpr('', 'cash_pay_price') . ' AS reconciliation_amount')
                ->limit(self::LIMIT + 1)->select()->toArray();
            foreach ($direct as $row) {
                $out[] = $this->legacy($row, $method['code'], false, $timezone);
            }
            $combined = ValidCashOrderServices::combinationCashByCashChooseQuery($where, $archived)->whereIn('b.store_id', $stores)
                ->field('a.id,b.order_id,b.store_id,b.add_time,b.uid')->fieldRaw(ValidCashOrderServices::buildCombinationLineAmountExpr('b', 'a.price') . ' AS reconciliation_amount')
                ->limit(self::LIMIT + 1)->select()->toArray();
            foreach ($combined as $row) $out[] = $this->legacy($row, $method['code'], true, $timezone);
            $this->bounded($out);
        }

        // V3技术卡载体的pay_type不是旧实际支付方式，旧资格查询不会选入；
        // legacy_store_order事实仅是上方同一旧单的迁移投影，必须排除以防双算。
        $query = Db::name('cashier_v3_payment_fact')->alias('p')->where('p.tenant_id', $tenant)
            ->whereIn('p.store_id', $stores)
            ->where('p.status', 'effective')->where('p.fact_direction', 'forward')
            ->whereIn('p.fact_type', ['payment_collected', 'payment_received'])
            ->where('p.source_document_type', '<>', 'legacy_store_order')
            ->where('p.payment_method', '<>', 'old_card_entry');
        $normal = new StoreReportNormalDataScopeServices();
        $normal->excludeVoidedSalesOrderFacts($query, 'p.tenant_id', 'p.order_id');
        $normal->excludeVoidedRechargeOrderFacts($query, 'p.tenant_id', 'p.order_id');
        $normal->excludeVoidedSupplementPaymentFacts($query, 'p.tenant_id', 'p.store_id', 'p.order_id');
        // 007对账排除任何退款原单，不能套用其他净业绩报表的退款日冲减口径。
        $query->whereNotExists(static function ($refund): void {
            $refund->name('cashier_v3_order_lifecycle_operation')->alias('reconciliation_refund')
                ->whereRaw('reconciliation_refund.tenant_id=p.tenant_id')
                ->whereRaw('reconciliation_refund.source_order_id=p.order_id')
                ->where('reconciliation_refund.source_type', 'sales')
                ->where('reconciliation_refund.operation_type', 'refund')->where('reconciliation_refund.status', 'succeeded');
        });
        $this->excludeImportedCardReversal($query, 'p.tenant_id', 'p.store_id', 'p.order_id');
        // 原销售收款沿用原单业务日；补交/充值等独立收款使用各自来源业务日。
        // 两个互斥集合各自按日期索引过滤，不用跨期付款日代替原下单日，也不重复计算。
        $originals = (clone $query)->join('cashier_v3_sales_order original_sale',
                'original_sale.tenant_id=p.tenant_id AND original_sale.order_id=p.order_id AND original_sale.store_id=p.store_id')
            ->where('original_sale.order_status', 'settled')->where('original_sale.order_direction', 'forward')
            ->whereBetween('original_sale.business_date', [$range['start_date'], $range['end_date']])
            ->field('p.fact_id,p.store_id,original_sale.business_date,p.payment_method,p.amount_cents,p.order_no_snapshot,p.member_id')
            ->limit(self::LIMIT + 1)->select()->toArray();
        $independent = (clone $query)->whereBetween('p.business_date', [$range['start_date'], $range['end_date']])
            ->whereNotExists(static function ($sale): void {
                $sale->name('cashier_v3_sales_order')->alias('reconciliation_original_sale')
                    ->whereRaw('reconciliation_original_sale.tenant_id=p.tenant_id')->whereRaw('reconciliation_original_sale.order_id=p.order_id');
            })->field('p.fact_id,p.store_id,p.business_date,p.payment_method,p.amount_cents,p.order_no_snapshot,p.member_id')
            ->limit(self::LIMIT + 1)->select()->toArray();
        $this->bounded($originals); $this->bounded($independent);
        foreach (array_merge($originals, $independent) as $row) {
            $amount = self::integerCents($row['amount_cents']);
            if ($amount < 0) throw new \InvalidArgumentException('正向收款事实金额异常，请核对原单');
            $out[] = ['receipt_id' => 'v3:' . $row['fact_id'], 'store_id' => (int)$row['store_id'],
                'business_date' => $row['business_date'], 'method_code' => self::methodCode((string)$row['payment_method']),
                'amount_cents' => $amount, 'document_no' => (string)$row['order_no_snapshot'],
                'member_id' => (int)$row['member_id'], 'source_kind' => '统一收款', 'combined' => false];
        }
        $this->bounded($out);
        $registrations = Db::name('salary_store')->whereIn('store_id', $stores)->whereBetween('date', $times)
            ->field('id,store_id,date,salary_info')->limit(self::LIMIT + 1)->select()->toArray();
        $this->bounded($registrations);
        foreach ($registrations as $row) {
            $info = json_decode((string)$row['salary_info'], true);
            // 原人工表还保存其他工资表单；无刷单内容的旧空表单不能阻断对账。
            if (!is_array($info)) {
                if (strpos((string)$row['salary_info'], 'shuadan') !== false) throw new \InvalidArgumentException('刷单登记内容格式异常，请核对原记录');
                continue;
            }
            $platform = $info['shuadanpingtai'] ?? '';
            if (!in_array($platform, ['抖音', '大众'], true)) continue;
            $out[] = ['receipt_id' => 'registration:' . $row['id'], 'store_id' => (int)$row['store_id'],
                'business_date' => (new \DateTimeImmutable('@' . (int)$row['date']))->setTimezone($timezone)->format('Y-m-d'),
                'method_code' => $platform === '抖音' ? 'brush_douyin' : 'brush_dianping',
                'amount_cents' => BasicRegistrationQueryProvider::cents($info['shuadanjine'] ?? '0'),
                'document_no' => '登记-' . $row['id'], 'member_id' => 0, 'source_kind' => '刷单登记', 'combined' => false];
        }
        $this->bounded($out);
        return $out;
    }

    /**
     * 已成功迁移的旧单因技术归档被标为删除，不能据此把原收款漏掉。
     * 只有正式迁移销售头同时证明原ID、单号、会员、门店及成功状态才纳入；
     * 普通删除单不恢复，成功作废/退款仍排除。这里只形成只读投影，不改旧单标记。
     */
    private function legacyVisibility($query, string $alias): void
    {
        $prefix = ($alias !== '' ? $alias : Db::name('store_order')->getTable()) . '.';
        $ids = $this->verifiedArchivedIds;
        $query->where(static function ($visible) use ($prefix, $ids): void {
            $visible->where($prefix . 'is_system_del', 0);
            if ($ids) $visible->whereOr($prefix . 'id', 'in', $ids);
        });
    }

    /** 归档证明只读一次，范围、原实体身份和有效生命周期均在数据库内核实。 */
    private function archivedLegacyOrderIds(array $stores, array $times, string $tenant): array
    {
        $proof = Db::name('cashier_v3_sales_order')->alias('h')
            ->join('store_order o', 'h.source_document_id=CAST(o.id AS CHAR) AND h.order_no=o.order_id AND h.store_id=o.store_id AND h.member_id=o.uid')
            ->where('h.tenant_id', $tenant)->where('h.contract_version', 'legacy-import-data-v1')
            ->where('h.source_document_type', 'legacy_store_order')->where('h.order_status', 'settled')->where('h.order_direction', 'forward')
            ->whereIn('o.store_id', $stores)->whereBetween('o.add_time', $times)->where('o.is_system_del', 1)
            ->whereNotExists(static function ($reversal): void {
                $reversal->name('cashier_v3_order_lifecycle_operation')->alias('reconciliation_import_reversal')
                    ->whereRaw('reconciliation_import_reversal.tenant_id=h.tenant_id')->whereRaw('reconciliation_import_reversal.store_id=h.store_id')
                    ->whereRaw('reconciliation_import_reversal.source_order_id=h.order_id')->where('reconciliation_import_reversal.source_type', 'sales')
                    ->whereIn('reconciliation_import_reversal.operation_type', ['refund','void'])->where('reconciliation_import_reversal.status', 'succeeded');
            });
        $this->excludeImportedCardReversal($proof, 'h.tenant_id', 'h.store_id', 'h.order_id');
        $rows = $proof->field('o.id')->limit(self::LIMIT + 1)->select()->toArray();
        $this->bounded($rows);
        return array_values(array_unique(array_map('intval', array_column($rows, 'id'))));
    }

    private function excludeImportedCardReversal($query, string $tenant, string $store, string $order): void
    {
        $query->whereNotExists(static function ($proof) use ($tenant, $store, $order): void {
            $proof->name('cashier_v3_card_purchase_receipt')->alias('reconciliation_issued')
                ->join('cashier_v3_order_lifecycle_operation reconciliation_card_reversal',
                    'reconciliation_card_reversal.tenant_id=reconciliation_issued.tenant_id AND reconciliation_card_reversal.store_id=reconciliation_issued.store_id'
                    . ' AND reconciliation_card_reversal.source_order_id=CAST(reconciliation_issued.card_holder_id AS CHAR)')
                ->whereRaw('reconciliation_issued.tenant_id=' . $tenant)->whereRaw('reconciliation_issued.store_id=' . $store)
                ->whereRaw('reconciliation_issued.sales_order_id=' . $order)->where('reconciliation_issued.status', 'completed')
                ->where('reconciliation_card_reversal.source_type', 'imported_card')->where('reconciliation_card_reversal.status', 'succeeded')
                ->whereIn('reconciliation_card_reversal.operation_type', ['refund','void']);
        });
    }

    /** 金额解析采用既有十进制边界，旧历史分值保持可追溯，不触发新收银写入。 */
    private function legacy(array $row, string $method, bool $combined, \DateTimeZone $timezone): array
    {
        return ['receipt_id' => ($combined ? 'legacy-combination:' : 'legacy-order:') . $row['id'] . ':' . $method,
            'store_id' => (int)$row['store_id'], 'method_code' => $method,
            'business_date' => (new \DateTimeImmutable('@' . (int)$row['add_time']))->setTimezone($timezone)->format('Y-m-d'),
            'amount_cents' => BasicRegistrationQueryProvider::cents($row['reconciliation_amount']),
            'document_no' => (string)$row['order_id'], 'member_id' => (int)$row['uid'],
            'source_kind' => $combined ? '历史组合收款' : '历史订单收款', 'combined' => $combined];
    }

    /** 数据库BIGINT按十进制校验后转整数，禁止浮点或溢出金额混入合计。 */
    private static function integerCents($value): int
    {
        if (!preg_match('/^-?\d{1,15}$/D', (string)$value)) throw new \InvalidArgumentException('收款事实金额异常');
        return (int)$value;
    }

    private function bounded(array $rows): void
    {
        if (count($rows) > self::LIMIT) throw new \InvalidArgumentException('对账明细超过安全查询范围，请缩小日期或门店范围');
    }
}
