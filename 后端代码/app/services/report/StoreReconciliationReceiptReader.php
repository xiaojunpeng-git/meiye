<?php
declare(strict_types=1);
namespace app\services\report;

use app\services\order\ValidCashOrderServices;
use app\services\member\MemberOrderAccounting;
use app\services\cashier\v3\report\CashierV3EffectivePaymentMethodSql;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementKernel;
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

    /** 历史现金/每日汇总仍需原完整目录，停用不改历史收款；门店对账的展示目录使用 checkoutMethods。 */
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
     * 门店对账与收银共用启用配置、显示名和配置顺序；刷单是独立登记行，
     * 不加入实际收款目录。旧渠道只在读取边界映射，不能按名称合并历史事实。
     */
    public function checkoutMethods(): array
    {
        $catalog = (new CashierV3BusinessConfigServices())->checkoutCatalog();
        $supported = array_fill_keys(CashierV3CheckoutSettlementKernel::paymentMethods(), true);
        $enabledCodes = array_fill_keys(array_column($catalog['accountingMethods'], 'code'), true);
        $rows = [];
        foreach ($catalog['accountingMethods'] as $method) {
            $paymentCode = (string)$method['code'];
            if (!isset($supported[$paymentCode])) continue;
            $code = self::methodCode($paymentCode);
            // 已上线站点未启用新企微/其他收入时，旧渠道4/8仍归原有微信/其他收款；
            // 010切换为企微/其他收入后才由新代码承接，不能因共用源码漏掉旧站金额。
            if ($paymentCode === 'wechat' && !isset($enabledCodes['enterprise_wechat'])) $code = 'legacy_4';
            if ($paymentCode === 'other_collection' && !isset($enabledCodes['other_income'])) $code = 'legacy_8';
            $rows[] = ['code' => $code,
                'name' => trim((string)$method['displayName']) ?: (string)$method['defaultName'],
                'legacy_id' => strpos($code, 'legacy_') === 0 ? (int)substr($code, 7) : 0,
                'payment_code' => $paymentCode];
        }
        // 会员端第三方支付是自动收款分类，使用同一记账配置，但不能成为人工收银按钮。
        foreach ((new CashierV3BusinessConfigServices())->accountingMethods(true) as $method) {
            if ((string)$method['code'] !== MemberOrderAccounting::CODE) continue;
            $rows[] = ['code' => self::methodCode(MemberOrderAccounting::CODE),
                'name' => (string)$method['displayName'], 'legacy_id' => 0];
            break;
        }
        // 007刷单登记不是收银支付方式；只在对账表末尾展示，供逐笔核对。
        $rows[] = ['code' => 'brush_douyin', 'name' => '抖音刷单', 'legacy_id' => 0];
        $rows[] = ['code' => 'brush_dianping', 'name' => '大众刷单', 'legacy_id' => 0];
        return $rows;
    }

    /**
     * 007旧枚举4是企微，不是普通微信；8是010当前的实际“其他收入”，
     * 不是非现金的other_collection。普通微信/支付宝保留独立历史代码，
     * 停用时仅从当前对账展示目录消失，已记事实不改写。
     */
    public static function methodCode(string $code): string
    {
        $ids = ['unionpay' => 1, 'dianping_voucher' => 2, 'douyin_voucher' => 3,
            'enterprise_wechat' => 4, 'bank_transfer' => 5, 'partner_collection' => 6,
            'cash' => 7, 'other_income' => 8];
        return isset($ids[$code]) ? 'legacy_' . $ids[$code] : 'v3_' . $code;
    }

    /**
     * 一次有界、一致读取产生可追溯的收款明细。默认保留原门店对账add_time契约；
     * 每日汇总显式传businessDateMode，沿批准迁入规则按业务日。新业务始终
     * 以收款来源业务日入表；刷单使用登记日期，只作为独立人工行。
     */
    public function receipts(array $stores, array $methods, array $range, string $tenant, bool $businessDateMode = false, ?array $originalOrderIds = null): array
    {
        if (!$stores) return [];
        if($originalOrderIds!==null){
            if(!$businessDateMode)throw new \InvalidArgumentException('原单限定读取必须采用业务日');
            foreach($originalOrderIds as $id)if(!is_int($id)||$id<1)throw new \InvalidArgumentException('原收款订单ID无效');
            $originalOrderIds=array_values(array_unique($originalOrderIds));
            if(!$originalOrderIds)return [];
        }
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $times = [(new \DateTimeImmutable($range['start_date'] . ' 00:00:00', $timezone))->getTimestamp(),
            (new \DateTimeImmutable($range['end_date'] . ' 23:59:59', $timezone))->getTimestamp()];
        $out = [];
        // 每日汇总显式选择业务日模式；原门店对账默认add_time契约不变。
        $businessDates = $businessDateMode ? $this->legacyBusinessDates($stores, $range, $tenant, $timezone) : [];
        // Optional exact original IDs narrow an already authorized business-date scope.
        // Historical gap repair must not re-audit every archived order in a store/month.
        // Omitting this argument preserves the full reconciliation contract unchanged.
        if($originalOrderIds!==null)$businessDates=array_intersect_key($businessDates,array_fill_keys($originalOrderIds,true));

        // 一次核实归档证明，后续通道查询复用ID白名单，避免每格/每单重复运行关联子查询。
        $this->verifiedArchivedIds = $this->archivedLegacyOrderIds($stores, $times, $tenant,
            $businessDateMode ? array_keys($businessDates) : null);
        $archived = function ($query, string $alias) use ($tenant, $originalOrderIds): void {
            $this->legacyVisibility($query, $alias);
            // 完整收款人口只保留迁入充值事实；原单限定读取仍用于历史身份审计。
            if ($originalOrderIds === null) LegacyRechargeCarrierScope::excludeCovered($query, $tenant, $alias);
        };
        $hasThirdParty = in_array(self::methodCode(MemberOrderAccounting::CODE), array_column($methods, 'code'), true);
        foreach ($methods as $method) {
            if (empty($method['legacy_id'])) continue;
            $where = ['time' => $businessDateMode ? [] : $times, 'cash_choose' => (int)$method['legacy_id']];
            $directQuery = ValidCashOrderServices::storeCashIncomeByCashChooseQuery($where, $archived)->whereIn('store_id', $stores);
            // 新会员订单以创建时冻结的分类归列，不再按旧cash_choose重复归入人工渠道。
            if ($hasThirdParty) $directQuery->where(static function ($normal): void {
                $normal->whereNull('accounting_method')->whereOr('accounting_method', '<>', MemberOrderAccounting::CODE);
            });
            if ($businessDateMode) $directQuery->whereIn('id', array_keys($businessDates) ?: [0]);
            $direct = $directQuery->field('id,id source_order_id,order_id,store_id,add_time,uid,accounting_method')->fieldRaw(ValidCashOrderServices::buildAmountExpr('', 'cash_pay_price') . ' AS reconciliation_amount')
                ->limit(self::LIMIT + 1)->select()->toArray();
            foreach ($direct as $row) {
                $out[] = $this->legacy($row, $method['code'], false, $timezone, $businessDateMode ? $businessDates[(int)$row['source_order_id']] : null);
            }
            $combinedQuery = ValidCashOrderServices::combinationCashByCashChooseQuery($where, $archived)->whereIn('b.store_id', $stores);
            if ($hasThirdParty) $combinedQuery->where(static function ($normal): void {
                $normal->whereNull('b.accounting_method')->whereOr('b.accounting_method', '<>', MemberOrderAccounting::CODE);
            });
            if ($businessDateMode) $combinedQuery->whereIn('b.id', array_keys($businessDates) ?: [0]);
            $combined = $combinedQuery->field('a.id,b.id source_order_id,b.order_id,b.store_id,b.add_time,b.uid,b.accounting_method')->fieldRaw(ValidCashOrderServices::buildCombinationLineAmountExpr('b', 'a.price') . ' AS reconciliation_amount')
                ->limit(self::LIMIT + 1)->select()->toArray();
            foreach ($combined as $row) $out[] = $this->legacy($row, $method['code'], true, $timezone, $businessDateMode ? $businessDates[(int)$row['source_order_id']] : null);
            $this->bounded($out);
        }

        if ($hasThirdParty) {
            // 手机订单通常没有人工cash_choose编号；沿用同一旧单收款资格及金额表达式，
            // 以冻结的third_party分类单列。余额、旧卡、退款仍由原资格查询排除。
            $where = ['time' => $businessDateMode ? [] : $times];
            $directQuery = ValidCashOrderServices::storeCashIncomeByCashChooseQuery($where, $archived)
                ->whereIn('store_id', $stores)->where('accounting_method', MemberOrderAccounting::CODE);
            if ($businessDateMode) $directQuery->whereIn('id', array_keys($businessDates) ?: [0]);
            $direct = $directQuery->field('id,id source_order_id,order_id,store_id,add_time,uid,accounting_method')
                ->fieldRaw(ValidCashOrderServices::buildAmountExpr('', 'cash_pay_price') . ' AS reconciliation_amount')
                ->limit(self::LIMIT + 1)->select()->toArray();
            foreach ($direct as $row) $out[] = $this->legacy($row, self::methodCode(MemberOrderAccounting::CODE),
                false, $timezone, $businessDateMode ? $businessDates[(int)$row['source_order_id']] : null);
            $combinedQuery = ValidCashOrderServices::combinationCashByCashChooseQuery($where, $archived)
                ->whereIn('b.store_id', $stores)->where('b.accounting_method', MemberOrderAccounting::CODE);
            if ($businessDateMode) $combinedQuery->whereIn('b.id', array_keys($businessDates) ?: [0]);
            $combined = $combinedQuery->field('a.id,b.id source_order_id,b.order_id,b.store_id,b.add_time,b.uid,b.accounting_method')
                ->fieldRaw(ValidCashOrderServices::buildCombinationLineAmountExpr('b', 'a.price') . ' AS reconciliation_amount')
                ->limit(self::LIMIT + 1)->select()->toArray();
            foreach ($combined as $row) $out[] = $this->legacy($row, self::methodCode(MemberOrderAccounting::CODE),
                true, $timezone, $businessDateMode ? $businessDates[(int)$row['source_order_id']] : null);
            $this->bounded($out);
        }

        // An explicit original-ID request returns only those original receipts, never
        // unrelated native payments or manual registrations from the same store/month.
        if($originalOrderIds!==null)return $out;

        // V3技术卡载体的pay_type不是旧实际支付方式，旧资格查询不会选入；
        // legacy_store_order事实仅是上方同一旧单的迁移投影，必须排除以防双算。
        $query = Db::name('cashier_v3_payment_fact')->alias('p')->where('p.tenant_id', $tenant)
            ->whereIn('p.store_id', $stores)
            ->where('p.status', 'effective')->where('p.fact_direction', 'forward')
            ->whereIn('p.fact_type', ['payment_collected', 'payment_received'])
            ->where('p.source_document_type', '<>', 'legacy_store_order')
            ->where('p.payment_method', '<>', 'old_card_entry');
        CashierV3EffectivePaymentMethodSql::join($query, 'p', 'receipt_method_current', 'receipt_method_reversal');
        $effectiveMethod = CashierV3EffectivePaymentMethodSql::expression('p', 'receipt_method_current', 'receipt_method_reversal');
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
            ->fieldRaw('p.fact_id,p.order_id,p.source_document_type,p.store_id,original_sale.business_date,' . $effectiveMethod . ' payment_method,p.amount_cents,p.order_no_snapshot,p.member_id')
            ->limit(self::LIMIT + 1)->select()->toArray();
        $independent = (clone $query)->whereBetween('p.business_date', [$range['start_date'], $range['end_date']])
            ->whereNotExists(static function ($sale): void {
                $sale->name('cashier_v3_sales_order')->alias('reconciliation_original_sale')
                    ->whereRaw('reconciliation_original_sale.tenant_id=p.tenant_id')->whereRaw('reconciliation_original_sale.order_id=p.order_id');
            })->fieldRaw('p.fact_id,p.order_id,p.source_document_type,p.store_id,p.business_date,' . $effectiveMethod . ' payment_method,p.amount_cents,p.order_no_snapshot,p.member_id')
            ->limit(self::LIMIT + 1)->select()->toArray();
        $this->bounded($originals); $this->bounded($independent);
        $legacyOwners = array_column($methods, 'payment_code', 'code');
        foreach (array_merge($originals, $independent) as $row) {
            $amount = self::integerCents($row['amount_cents']);
            if ($amount < 0) throw new \InvalidArgumentException('正向收款事实金额异常，请核对原单');
            $methodCode = self::methodCode((string)$row['payment_method']);
            // 统一收款事实也跟随本站旧渠道归属；只看静态编号会使4/8在未迁移站点掉行。
            if ($row['payment_method'] === 'wechat' && ($legacyOwners['legacy_4'] ?? '') === 'wechat') $methodCode = 'legacy_4';
            if ($row['payment_method'] === 'other_collection' && ($legacyOwners['legacy_8'] ?? '') === 'other_collection') $methodCode = 'legacy_8';
            $out[] = ['receipt_id' => 'v3:' . $row['fact_id'], 'store_id' => (int)$row['store_id'],
                'business_date' => $row['business_date'], 'method_code' => $methodCode,
                'amount_cents' => $amount, 'document_no' => (string)$row['order_no_snapshot'],
                // 原始来源主键随收款证据传递；跳转不能再按可能重复的显示单号扩展人口。
                'order_reference' => ['type' => (string)$row['source_document_type'], 'id' => (string)$row['order_id']],
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
    private function archivedLegacyOrderIds(array $stores, array $times, string $tenant, ?array $businessOrderIds = null): array
    {
        $proof = Db::name('cashier_v3_sales_order')->alias('h')
            ->join('store_order o', 'h.source_document_id=CAST(o.id AS CHAR) AND h.order_no=o.order_id AND h.store_id=o.store_id AND h.member_id=o.uid')
            ->where('h.tenant_id', $tenant)->where('h.contract_version', 'legacy-import-data-v1')
            ->where('h.source_document_type', 'legacy_store_order')->where('h.order_status', 'settled')->where('h.order_direction', 'forward')
            ->whereIn('o.store_id', $stores)->where('o.is_system_del', 1)
            ->whereNotExists(static function ($reversal): void {
                $reversal->name('cashier_v3_order_lifecycle_operation')->alias('reconciliation_import_reversal')
                    ->whereRaw('reconciliation_import_reversal.tenant_id=h.tenant_id')->whereRaw('reconciliation_import_reversal.store_id=h.store_id')
                    ->whereRaw('reconciliation_import_reversal.source_order_id=h.order_id')->where('reconciliation_import_reversal.source_type', 'sales')
                    ->whereIn('reconciliation_import_reversal.operation_type', ['refund','void'])->where('reconciliation_import_reversal.status', 'succeeded');
            });
        if ($businessOrderIds === null) $proof->whereBetween('o.add_time', $times);
        else $proof->whereIn('o.id', $businessOrderIds ?: [0]);
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

    /**
     * 复用已批准010迁入时间规则：未迁旧单支付秒数转上海业务日，已迁单
     * 以身份完整匹配的正式销售头business_date为准。只生成只读日期索引，
     * 收款资格/金额仍由既有ValidCashOrderServices决定，不把目录价作实收。
     */
    private function legacyBusinessDates(array $stores, array $range, string $tenant, \DateTimeZone $zone): array
    {
        $headers = Db::name('cashier_v3_sales_order')->alias('h')
            ->join('store_order o','h.source_document_id=CAST(o.id AS CHAR) AND h.order_no=o.order_id AND h.store_id=o.store_id AND h.member_id=o.uid')
            ->where('h.tenant_id',$tenant)->where('h.source_document_type','legacy_store_order')
            ->where('h.contract_version','legacy-import-data-v1')->whereIn('h.store_id',$stores)
            ->field('o.id,h.order_id,h.business_date,h.order_status,h.order_direction')->limit(self::LIMIT+1)->select()->toArray();
        $this->bounded($headers);$mapped=[];$inside=[];$blocked=[];
        // 正式迁入后发生成功退款/作废时，旧壳未同步状态也不能复活该收款。
        $reversed=[];
        foreach(array_chunk(array_column($headers,'order_id'),200) as $orderIds){
            $operations=Db::name('cashier_v3_order_lifecycle_operation')->where('tenant_id',$tenant)
                ->whereIn('store_id',$stores)->where('source_type','sales')->whereIn('source_order_id',$orderIds)
                ->whereIn('operation_type',['refund','void'])->where('status','succeeded')
                ->field('source_order_id')->select()->toArray();
            foreach($operations as $operation)$reversed[(string)$operation['source_order_id']]=true;
        }
        foreach($headers as $header){
            $id=(int)$header['id'];$day=(string)$header['business_date'];
            if($header['order_status']!=='settled'||$header['order_direction']!=='forward'
                ||isset($reversed[(string)$header['order_id']])){$blocked[$id]=true;continue;}
            if(isset($mapped[$id]))throw new \RuntimeException('DAILY_BUSINESS_DATE_AMBIGUOUS: 同一旧单存在多个正式业务日期来源。');
            $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$day,$zone);
            if(!$parsed||$parsed->format('Y-m-d')!==$day)throw new \RuntimeException('DAILY_BUSINESS_DATE_INVALID: 正式业务日期无效。');
            $mapped[$id]=$day;if($day>=$range['start_date']&&$day<=$range['end_date'])$inside[]=$id;
        }
        $from=(new \DateTimeImmutable($range['start_date'].' 00:00:00',$zone))->getTimestamp();
        $until=(new \DateTimeImmutable($range['end_date'].' 23:59:59',$zone))->getTimestamp();
        $query=Db::name('store_order')->whereIn('store_id',$stores)->where('paid',1)
            ->whereIn('pay_type',['weixin','alipay','cash','offline','combination'])
            ->whereIn('order_type',[0,1])->where('refund_status',0);
        $rows=$query->where(static function($q)use($from,$until,$inside):void{
            $q->whereBetween('pay_time',[$from,$until]);if($inside)$q->whereOr('id','in',$inside);
        })->field('id,pay_time')->limit(self::LIMIT+1)->select()->toArray();
        $this->bounded($rows);$dates=[];
        foreach($rows as $row){
            $id=(int)$row['id'];if(isset($blocked[$id]))continue;$day=$mapped[$id]??null;
            if($day===null){
                if((int)$row['pay_time']<=0)throw new \RuntimeException('DAILY_BUSINESS_DATE_UNRESOLVED: 旧单缺已批准业务日期来源。');
                $day=(new \DateTimeImmutable('@'.(int)$row['pay_time']))->setTimezone($zone)->format('Y-m-d');
            }
            if($day>=$range['start_date']&&$day<=$range['end_date'])$dates[$id]=$day;
        }
        return $dates;
    }

    /** 金额解析采用既有十进制边界，旧历史分值保持可追溯，不触发新收银写入。 */
    private function legacy(array $row, string $method, bool $combined, \DateTimeZone $timezone, ?string $businessDate = null): array
    {
        // 资格及外部实收金额完全复用原收款查询；仅新会员订单按创建时冻结分类归列。
        // 不能改写 cash_choose/pay_type，也不能把余额合并进收款；旧单空标记仍归原列。
        $memberOrder = MemberOrderAccounting::isThirdParty($row);
        if ($memberOrder) $method = self::methodCode(MemberOrderAccounting::CODE);
        return ['receipt_id' => ($combined ? 'legacy-combination:' : 'legacy-order:') . $row['id'] . ':' . $method,
            'store_id' => (int)$row['store_id'], 'method_code' => $method,
            'business_date' => $businessDate ?? (new \DateTimeImmutable('@' . (int)$row['add_time']))->setTimezone($timezone)->format('Y-m-d'),
            'amount_cents' => BasicRegistrationQueryProvider::cents($row['reconciliation_amount']),
            'document_no' => (string)$row['order_id'], 'member_id' => (int)$row['uid'],
            // 组合行自身ID不同于原订单ID，必须保存已查询到的原单主键。
            'order_reference' => ['type' => 'legacy_store_order', 'id' => (string)$row['source_order_id']],
            'source_kind' => $memberOrder ? '会员订单收款' : ($combined ? '历史组合收款' : '历史订单收款'), 'combined' => $combined];
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
