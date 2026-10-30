<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/** 007 产品销售旧有效购物车人口的只读覆盖审计；不把全部商品或卡项误作分母。 */
final class ProductSales007SourceCoverageServices
{
    private $sourceReader;

    /** 可注入原人口用于合同测试；生产 SQL 与 007 配置报表的 WHERE 保持一致。 */
    public function __construct(?callable $sourceReader = null)
    {
        $this->sourceReader = $sourceReader ?: [$this,'legacySource'];
    }

    /** 逐原购物车行核对 V3 已生效商品销售事实；任何不明或缺失均 fail closed。 */
    public function read(array $stores,array $range,array $sales): array
    {
        $allowed=[];
        foreach($stores as $store){
            if(!is_int($store)||$store<1)throw new \InvalidArgumentException('授权门店无效');
            $allowed[$store]=true;
        }
        if($allowed===[])return ['complete'=>true,'legacy_eligible_lines'=>0,'matched_lines'=>0,
            'eligible_legacy_fact_ids'=>[],'gaps'=>[]];
        $source=($this->sourceReader)(array_keys($allowed),$range);
        if(!($source['verified']??false))return ['complete'=>false,'legacy_eligible_lines'=>null,
            'matched_lines'=>null,'gaps'=>[$source['gap']??[
                'code'=>'LEGACY_PRODUCT_SOURCE_UNVERIFIED',
                'message'=>'旧产品销售有效人口未核实，不能把空 V3 结果当作零销售。']]];
        $lines=$source['lines']??[];
        if(count($lines)>10000)throw new \RuntimeException('007 产品销售有效来源超过10000行，请缩小范围');
        $v3=[];$v3FactIds=[];
        foreach($sales as $sale){
            $store=(int)($sale['store_id']??0);
            if(!isset($allowed[$store]))throw new \LogicException('V3 商品销售事实超出授权');
            if((string)($sale['source_document_type']??'')!=='legacy_store_order')continue;
            $key=$this->key($store,(string)($sale['order_id']??''),(string)($sale['source_line_id']??''));
            $v3[$key]=($v3[$key]??0)+1;
            $v3FactIds[$key][]=(string)($sale['fact_id']??'');
        }
        $seen=[];$missing=0;$ambiguous=0;$matched=0;$dateUnresolved=0;$eligibleFacts=[];
        foreach($lines as $line){
            $store=(int)($line['store_id']??0);
            if(!isset($allowed[$store]))throw new \LogicException('007 商品来源越过授权');
            $oldOrder=(int)($line['old_order_id']??0);
            $oldCartDetail=(int)($line['old_cart_detail_id']??0);
            if($oldOrder<1||$oldCartDetail<1)throw new \LogicException('007 产品来源缺原数据库主键');
            $key=$this->key($store,'IMP007-'.$oldOrder.'-sale',
                'IMP007-'.$oldOrder.'-line-'.$oldCartDetail);
            if(isset($seen[$key]))continue; // 007 一条商品行可能有多名原销售人。
            $seen[$key]=true;
            // Old add_time is not a report date. A missing V3 business day
            // prevents the old cart from being assigned to any query period.
            if(!isset($line['business_date'])||$line['business_date']===null){
                ++$dateUnresolved;continue;
            }
            if($line['business_date']<$range['start']||$line['business_date']>$range['end'])
                throw new \LogicException('旧商品来源超出 V3 业务日范围');
            if(($v3[$key]??0)===1){
                ++$matched;
                // The same old eligibility audit also bounds which migrated
                // product facts may enter the 007 result, not merely completeness.
                $eligibleFacts[]=$v3FactIds[$key][0];
            }
            elseif(($v3[$key]??0)>1)++$ambiguous;
            else ++$missing;
        }
        $knownUnassigned=$dateUnresolved;
        if($source['unknown_date_candidate']??false)$dateUnresolved=max(1,$dateUnresolved);
        $gaps=[];
        if($missing>0)$gaps[]=['code'=>'LEGACY_PRODUCT_SALE_FACT_MISSING',
            'message'=>'007 有效产品购物车行尚未迁入 V3 商品销售事实，结果不能解释为完整。',
            'source_count'=>$missing];
        if($ambiguous>0)$gaps[]=['code'=>'LEGACY_PRODUCT_SALE_FACT_AMBIGUOUS',
            'message'=>'一条 007 有效产品购物车行对应多条 V3 商品销售事实。',
            'source_count'=>$ambiguous];
        if($dateUnresolved>0)$gaps[]=['code'=>'LEGACY_PRODUCT_BUSINESS_DATE_UNRESOLVED',
            'message'=>'007 有效产品购物车行没有 V3 原单业务日，不能用旧下单日回拨到任何查询期。',
            'source_count'=>$dateUnresolved];
        return ['complete'=>$gaps===[],'legacy_eligible_lines'=>count($seen)-$knownUnassigned,
            'date_unresolved_lines'=>$dateUnresolved,
            'matched_lines'=>$matched,'eligible_legacy_fact_ids'=>$eligibleFacts,'gaps'=>$gaps];
    }

    /** 身份使用旧订单/购物车详情主键导入行键；cart_id 仅用于旧销售人关联。 */
    private function key(int $store,string $order,string $cart): string
    {
        if($store<1||$order===''||$cart==='')throw new \LogicException('原产品购物车缺稳定订单或行身份');
        return json_encode([$store,$order,$cart]);
    }

    /** 复刻 007 配置 SQL 的非日期人口过滤；日期改用 V3 原单业务日。 */
    private function legacySource(array $stores,array $range): array
    {
        $required=[
            'store_order'=>['id','order_id','store_id','paid','is_system_del','is_del','refund_status',
                'is_auto','pid','order_type','pay_type','cash_choose','cash_pay_price','yue_pay_price',
                'pay_price'],
            'store_order_cart_info'=>['id','oid','cart_id','cart_type','product_type','cash_pay_amount',
                'yue_pay_amount','card_upgrade_amount','debt_amount','pay_price'],
            'staff_yeji'=>['order_id','cart_id','type','status'],
            'combination_order'=>['order_id','cash_choose'],
            'cashier_v3_sales_order'=>['id','tenant_id','store_id','source_document_id','source_document_type','business_date'],
        ];
        $names=[];
        try{
            foreach($required as $table=>$fields){
                $query=Db::name($table);$missing=array_diff($fields,$query->getTableFields());
                if($missing!==[])return $this->unverified('LEGACY_PRODUCT_SOURCE_SCHEMA_MISSING',
                    '007 产品销售原订单/购物车/人员字段不完整，不能核实迁移覆盖。');
                $name=$query->getTable();
                if(!is_string($name)||!preg_match('/^[A-Za-z0-9_]+$/D',$name))
                    throw new \LogicException('旧表名无效');
                $names[$table]='`'.$name.'`';
            }
        }catch(\Throwable $error){
            return $this->unverified('LEGACY_PRODUCT_SOURCE_UNAVAILABLE',
                '007 产品销售原来源不可读取，不能把空 V3 结果当作零销售。');
        }
        // All interpolated values were validated by ProductSales007ReadServices:
        // store IDs are positive integers and dates are exact YYYY-MM-DD.
        $storeList=implode(',',array_map('intval',$stores));
        $start=$range['start'];$end=$range['end'];
        $cash="CASE WHEN IFNULL(c.cash_pay_amount,0)>0 THEN c.cash_pay_amount "
            ."WHEN IFNULL(c.yue_pay_amount,0)=0 AND IFNULL(c.card_upgrade_amount,0)=0 "
            ."AND IFNULL(c.debt_amount,0)=0 AND IFNULL(o.cash_pay_price,0)>0 "
            ."AND IFNULL(o.pay_price,0)>0 THEN ROUND(o.cash_pay_price*c.pay_price/o.pay_price,2) ELSE 0 END";
        $balance="CASE WHEN IFNULL(c.yue_pay_amount,0)>0 THEN c.yue_pay_amount "
            ."WHEN IFNULL(c.cash_pay_amount,0)=0 AND IFNULL(c.card_upgrade_amount,0)=0 "
            ."AND IFNULL(c.debt_amount,0)=0 AND IFNULL(o.yue_pay_price,0)>0 "
            ."AND IFNULL(o.pay_price,0)>0 THEN ROUND(o.yue_pay_price*c.pay_price/o.pay_price,2) ELSE 0 END";
        $base="SELECT DISTINCT "
            ."o.store_id,o.id old_order_id,c.id old_cart_detail_id,h.business_date,{$cash} cash_amount,{$balance} balance_amount "
            ."FROM {$names['store_order']} o "
            ."INNER JOIN {$names['store_order_cart_info']} c ON c.oid=o.id AND IFNULL(c.cart_type,0)=0 "
            ."LEFT JOIN {$names['cashier_v3_sales_order']} h ON h.tenant_id='0' "
            ."AND h.store_id=o.store_id AND h.source_document_id=CAST(o.id AS CHAR) "
            ."AND h.source_document_type='legacy_store_order' "
            ."WHERE o.store_id IN ({$storeList}) AND o.paid=1 AND o.is_system_del=0 AND o.is_del=0 "
            ."AND o.refund_status IN (0,3) AND IFNULL(o.is_auto,0)<>1 "
            ."AND (o.pid IN (0,-2) OR o.pid>0) AND o.order_type=0 "
            ."AND IFNULL(c.product_type,0)=0 "
            ."AND EXISTS (SELECT 1 FROM {$names['staff_yeji']} sy WHERE sy.order_id=o.id "
            ."AND sy.cart_id=c.cart_id AND sy.type=2 AND sy.status=0) "
            ."AND ((o.pay_type<>'combination' AND IFNULL(o.cash_choose,0)<>9) "
            ."OR (o.pay_type='combination' AND EXISTS (SELECT 1 FROM {$names['combination_order']} co "
            ."WHERE co.order_id=o.id AND co.cash_choose<>9))) ";
        $sql="SELECT d.store_id,d.old_order_id,d.old_cart_detail_id,d.business_date FROM ({$base} "
            ."AND h.business_date BETWEEN '{$start}' AND '{$end}') d "
            ."WHERE d.cash_amount>0 OR d.balance_amount>0 LIMIT 10001";
        // A missing V3 order header has no business date. Probe every eligible
        // old row in the authorized stores so old creation time cannot hide a
        // source whose business day may fall inside the requested period.
        $unknownSql="SELECT 1 FROM ({$base} AND h.id IS NULL) d "
            ."WHERE d.cash_amount>0 OR d.balance_amount>0 LIMIT 1";
        try{$rows=Db::query($sql);$unknown=Db::query($unknownSql);}catch(\Throwable $error){
            return $this->unverified('LEGACY_PRODUCT_SOURCE_QUERY_FAILED',
                '007 产品销售有效人口查询失败，不能把空 V3 结果当作零销售。');
        }
        if(count($rows)>10000)return $this->unverified('LEGACY_PRODUCT_SOURCE_TOO_LARGE',
            '007 产品销售有效来源超过10000行，请缩小日期或门店范围。');
        return ['verified'=>true,'lines'=>$rows,'unknown_date_candidate'=>count($unknown)>0];
    }

    private function unverified(string $code,string $message): array
    {
        return ['verified'=>false,'gap'=>['code'=>$code,'message'=>$message]];
    }
}
