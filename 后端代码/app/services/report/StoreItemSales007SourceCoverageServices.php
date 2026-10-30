<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/** 007 门店品项有效旧购物车覆盖审计；资格取本地快照 type-5 SQL，日期取 V3 原单业务日。 */
final class StoreItemSales007SourceCoverageServices
{
    private $sourceReader;

    /** 可注入来源供合同测试；生产只读旧人口与 V3 原单身份。 */
    public function __construct(?callable $sourceReader = null)
    {
        $this->sourceReader = $sourceReader ?: [$this, 'legacySource'];
    }

    /** 用原订单主键和购物车详情主键与已迁 V3 销售行一对一核对。 */
    public function read(array $stores, array $range, array $sales): array
    {
        $allowed = [];
        foreach ($stores as $store) {
            if (!is_int($store) || $store < 1) throw new \InvalidArgumentException('授权门店无效');
            $allowed[$store] = true;
        }
        if ($allowed === []) return ['complete'=>true, 'legacy_eligible_lines'=>0,
            'matched_lines'=>0, 'eligible_legacy_fact_ids'=>[], 'gaps'=>[]];
        $source = ($this->sourceReader)(array_keys($allowed), $range);
        if (!($source['verified'] ?? false)) return ['complete'=>false,
            'gaps'=>[$source['gap'] ?? ['code'=>'STORE_ITEM_007_SOURCE_UNVERIFIED',
                'message'=>'007 门店品项旧销售人口不可核实。']]];
        $v3 = [];
        foreach ($sales as $sale) {
            if ((string)($sale['source_document_type'] ?? '') !== 'legacy_store_order') continue;
            $store = (int)($sale['store_id'] ?? 0);
            if (!isset($allowed[$store])) throw new \LogicException('V3 销售事实越过授权门店');
            $key = $this->key($store, (string)($sale['order_id'] ?? ''),
                (string)($sale['source_line_id'] ?? ''));
            $v3[$key][] = (string)($sale['fact_id'] ?? '');
        }
        $seen = []; $eligible = []; $missing = 0; $ambiguous = 0; $matched = 0;
        foreach ($source['lines'] ?? [] as $line) {
            $store = (int)($line['store_id'] ?? 0);
            if (!isset($allowed[$store])) throw new \LogicException('007 来源越过授权门店');
            $order = (int)($line['old_order_id'] ?? 0);
            $cart = (int)($line['old_cart_detail_id'] ?? 0);
            if ($order < 1 || $cart < 1) throw new \LogicException('007 来源无原主键');
            $key = $this->key($store, 'IMP007-'.$order.'-sale',
                'IMP007-'.$order.'-line-'.$cart);
            if (isset($seen[$key])) throw new \LogicException('007 原购物车重复');
            $seen[$key] = true;
            $facts = $v3[$key] ?? [];
            if (count($facts) === 1 && $facts[0] !== '') {
                $eligible[$facts[0]] = true; ++$matched;
            } elseif ($facts === []) ++$missing;
            else ++$ambiguous;
        }
        $gaps = [];
        if ($missing > 0) $gaps[] = ['code'=>'STORE_ITEM_007_SALE_FACT_MISSING',
            'message'=>'007 有效旧购物车行缺 V3 原销售事实。', 'source_count'=>$missing];
        if ($ambiguous > 0) $gaps[] = ['code'=>'STORE_ITEM_007_SALE_FACT_AMBIGUOUS',
            'message'=>'007 原购物车行对应多条 V3 原销售事实。', 'source_count'=>$ambiguous];
        if ($source['unknown_date_candidate'] ?? false) $gaps[] = [
            'code'=>'STORE_ITEM_007_BUSINESS_DATE_UNRESOLVED',
            'message'=>'有 007 有效旧购物车行缺 V3 原单业务日；不能用旧下单日归期。'];
        return ['complete'=>$gaps === [], 'legacy_eligible_lines'=>count($seen),
            'matched_lines'=>$matched, 'eligible_legacy_fact_ids'=>array_keys($eligible), 'gaps'=>$gaps];
    }

    /** 稳定键来自 010 历史导入计划，不以外部订单号或旧 cart_id 代替数据库主键。 */
    private function key(int $store, string $order, string $line): string
    {
        if ($store < 1 || $order === '' || $line === '') throw new \LogicException('原成交行身份缺失');
        return json_encode([$store, $order, $line]);
    }

    /**
     * 本地 007 快照 SQL SHA-256 4299cacd9d27acd99cba04138106efece73cc2ddc331622dbaddec29ff128e06。
     * 保留其非日期资格；旧 add_time 仅属旧报表，当前日期由 h.business_date 指定。
     */
    private function legacySource(array $stores, array $range): array
    {
        $required = [
            'store_order'=>['id','store_id','paid','is_system_del','is_del','refund_status',
                'is_auto','pid','order_type','pay_type','cash_choose'],
            'store_order_cart_info'=>['id','oid','cart_type','product_id'],
            'store_product'=>['id','pid','product_type','store_name'],
            'system_store'=>['id','is_del'],
            'combination_order'=>['order_id','cash_choose','active_pay'],
            'cashier_v3_sales_order'=>['id','tenant_id','store_id','source_document_type',
                'source_document_id','business_date'],
        ];
        $names = [];
        try {
            foreach ($required as $table=>$fields) {
                $query = Db::name($table);
                if (array_diff($fields, $query->getTableFields()) !== []) return $this->unverified(
                    'STORE_ITEM_007_SOURCE_SCHEMA_MISSING');
                $name = $query->getTable();
                if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]+$/D', $name))
                    throw new \LogicException('来源表名无效');
                $names[$table] = '`'.$name.'`';
            }
        } catch (\Throwable $error) { return $this->unverified('STORE_ITEM_007_SOURCE_UNAVAILABLE'); }
        $storeList = implode(',', array_map('intval', $stores));
        $old = $names['store_order']; $cart = $names['store_order_cart_info'];
        $product = $names['store_product']; $shop = $names['system_store'];
        $combo = $names['combination_order']; $header = $names['cashier_v3_sales_order'];
        $base = "FROM {$old} o INNER JOIN {$cart} c ON c.oid=o.id AND IFNULL(c.cart_type,0)=0 "
            ."INNER JOIN {$product} cp ON cp.id=c.product_id "
            ."LEFT JOIN {$product} parent ON parent.id=cp.pid AND cp.pid>0 "
            ."INNER JOIN {$shop} ss ON ss.id=o.store_id AND ss.is_del=0 "
            ."LEFT JOIN {$header} h ON h.tenant_id='0' AND h.store_id=o.store_id "
            ."AND h.source_document_type='legacy_store_order' AND h.source_document_id=CAST(o.id AS CHAR) "
            ."WHERE o.store_id IN ({$storeList}) AND o.paid=1 AND o.is_system_del=0 "
            ."AND o.is_del=0 AND o.refund_status IN (0,3) AND IFNULL(o.is_auto,0)<>1 "
            ."AND (o.pid IN (0,-2) OR o.pid>0) AND o.order_type=0 "
            ."AND cp.product_type IN (0,5,6) "
            ."AND IFNULL(NULLIF(parent.store_name,''),cp.store_name)<>'' "
            ."AND ((o.pay_type<>'combination' AND IFNULL(o.cash_choose,0)<>9) "
            ."OR (o.pay_type='combination' AND EXISTS (SELECT 1 FROM {$combo} co "
            ."WHERE co.order_id=o.id AND co.cash_choose<>9 AND co.active_pay<>3)))";
        $start = $range['start']; $end = $range['end'];
        $sql = "SELECT o.store_id,o.id old_order_id,c.id old_cart_detail_id {$base} "
            ." AND h.business_date BETWEEN '{$start}' AND '{$end}' LIMIT 10001";
        $unknownSql = "SELECT 1 {$base} AND h.id IS NULL LIMIT 1";
        try { $lines = Db::query($sql); $unknown = Db::query($unknownSql); }
        catch (\Throwable $error) { return $this->unverified('STORE_ITEM_007_SOURCE_QUERY_FAILED'); }
        if (count($lines) > 10000) return $this->unverified('STORE_ITEM_007_SOURCE_TOO_LARGE');
        return ['verified'=>true, 'lines'=>$lines, 'unknown_date_candidate'=>$unknown !== []];
    }

    private function unverified(string $code): array
    {
        return ['verified'=>false, 'gap'=>['code'=>$code,
            'message'=>'007 门店品项有效旧购物车人口不可核实，不能展示 V3 子集。']];
    }
}
