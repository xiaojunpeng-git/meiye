<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 007 PK/门店统计共用的原单现金读取边界。金额只能来自已保存 V3 正向收款；
 * 旧订单与组合支付仅用于资格和覆盖证明，不能以旧金额填补缺失的 V3 事实。
 */
final class Sidebar007OriginalCashReadServices
{
    private $paymentReader;
    private $legacyReader;

    /** 独立注入点只用于合同测试和本地对账，正式读取限定 tenant=0 与授权门店。 */
    public function __construct(?callable $paymentReader = null, ?callable $legacyReader = null)
    {
        $this->paymentReader = $paymentReader ?: [$this, 'payments'];
        $this->legacyReader = $legacyReader ?: [$this, 'legacyOrders'];
    }

    /**
     * 每店有任何来源身份、旧单覆盖或旧金额差异即不认证该店总额；其他店可独立认证。
     * 旧金额差异仅作审计标记，返回金额永远是 V3 收款事实之和。
     */
    public function read(array $stores, array $range): array
    {
        if ($stores === [] || count($stores) !== count(array_unique($stores)))
            throw new \InvalidArgumentException('原单现金门店范围无效');
        foreach ($stores as $id) if (!is_int($id) || $id < 1)
            throw new \InvalidArgumentException('原单现金门店 ID 无效');
        foreach (['start','end'] as $key) {
            $value = $range[$key] ?? null;
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d',$value) : false;
            if (!$date || $date->format('Y-m-d') !== $value)
                throw new \InvalidArgumentException('原单现金日期无效');
        }
        if ($range['start'] > $range['end'] || $range['start'] < StoreUnifiedReportServices::COVERAGE_START)
            throw new \InvalidArgumentException('原单现金日期超出覆盖范围');
        $facts = ($this->paymentReader)($stores,$range);
        $expected = ($this->legacyReader)($stores,$range);
        if (!is_array($facts) || !is_array($expected) || count($facts)>50000 || count($expected)>50000)
            throw new \RuntimeException('原单现金来源超出安全范围');
        $amounts=array_fill_keys($stores,0);$gaps=[];$seenFacts=[];$seenPayments=[];$amountsByOrder=[];
        $legacyPaid=[];$expectedByOrder=[];
        foreach ($expected as $order) {
            $store=$this->id($order['store_id']??null,$stores);
            if(($order['business_date_unresolved']??false)===true){
                $gaps[$store][]=['code'=>'ORIGINAL_CASH_BUSINESS_DATE_UNRESOLVED',
                    'source_order_id'=>(string)($order['source_order_id']??'')];
                continue;
            }
            $key=$store.'|'.(string)($order['source_order_id']??'');
            $cents=$this->cents($order['expected_cents']??null);
            if ($key===$store.'|' || isset($expectedByOrder[$key])) throw new \LogicException('旧原单身份缺失或重复');
            $expectedByOrder[$key]=$cents;
        }
        foreach ($facts as $fact) {
            $store=$this->id($fact['store_id']??null,$stores);
            $id=(string)($fact['fact_id']??'');
            if ($id===''||isset($seenFacts[$id])) throw new \LogicException('V3 收款事实身份缺失或重复');
            $seenFacts[$id]=true;
            $source=(string)($fact['source_document_type']??'');
            if (!in_array($source,['sales_order','cashier_snapshot','cashier_workspace',
                'checkout','legacy_store_order','recharge'],true))
                throw new \LogicException('原单现金来源混入非原单收款');
            $amount=$this->cents($fact['amount_cents']??null);
            if ($amount<0) throw new \LogicException('正向原单收款为负');
            $order=(string)($fact['order_id']??'');
            if ($order==='') throw new \LogicException('V3 原单收款缺少订单身份');
            // 迁入可同时出现 payment_received / payment_collected 两种
            // fact_type；同一原支付行须按冻结收款引用去重，不能双计。
            $reference=trim((string)($fact['collection_reference']??''));
            if($reference==='')$reference=trim((string)($fact['source_line_id']??''));
            if($reference===''){
                $gaps[$store][]=['code'=>'ORIGINAL_CASH_PAYMENT_KEY_MISSING','fact_id'=>$id];
                continue;
            }
            $paymentKey=$store.'|'.$order.'|'.$reference;
            if(isset($seenPayments[$paymentKey])){
                $gaps[$store][]=['code'=>'ORIGINAL_CASH_PAYMENT_DUPLICATE',
                    'fact_id'=>$id,'other_fact_id'=>$seenPayments[$paymentKey]];
                continue;
            }
            $seenPayments[$paymentKey]=$id;
            if (!($fact['eligible']??false)) {
                $gaps[$store][]=['code'=>'ORIGINAL_CASH_IDENTITY_UNVERIFIED','fact_id'=>$id,
                    'source_document_type'=>$source];
                continue;
            }
            if ($source==='legacy_store_order') {
                $old=(string)($fact['source_order_id']??'');
                $key=$store.'|'.$old;
                if ($old===''||!array_key_exists($key,$expectedByOrder)) {
                    $gaps[$store][]=['code'=>'ORIGINAL_CASH_LEGACY_ORDER_UNMATCHED','fact_id'=>$id];
                    continue;
                }
                $legacyPaid[$key]=($legacyPaid[$key]??0)+$amount;
            }
            $amounts[$store]+=$amount;
            // 仅返回已认证门店的逐单原收款，用于按成交时冻结客源投影；
            // 后补款和身份不完整的收款永不进入类别指标。
            $amountsByOrder[$store][$order]=($amountsByOrder[$store][$order]??0)+$amount;
        }
        foreach ($expectedByOrder as $key=>$oldCents) {
            [$store]=explode('|',$key,2);$actual=$legacyPaid[$key]??null;
            if ($actual===null && $oldCents>0) $gaps[(int)$store][]=[
                'code'=>'ORIGINAL_CASH_LEGACY_PAYMENT_MISSING','source_order_id'=>explode('|',$key,2)[1]];
            elseif ($actual!==null && $actual!==$oldCents) $gaps[(int)$store][]=[
                'code'=>'ORIGINAL_CASH_LEGACY_AMOUNT_DIFF','source_order_id'=>explode('|',$key,2)[1],
                'old_expected_cents'=>$oldCents,'v3_saved_cents'=>$actual];
        }
        $byStore=[];
        foreach ($stores as $store) $byStore[$store]=[
            'store_id'=>$store,'amount_cents'=>empty($gaps[$store])?$amounts[$store]:null,
            'v3_candidate_cents'=>$amounts[$store], 'complete'=>empty($gaps[$store]),
            'gaps'=>$gaps[$store]??[]];
        $certifiedOrders=[];
        foreach($stores as $store)$certifiedOrders[$store]=empty($gaps[$store])
            ?($amountsByOrder[$store]??[]):[];
        return ['by_store'=>$byStore,'by_order'=>$certifiedOrders,'source'=>'cashier_v3_payment_fact',
            'definition'=>'007-original-sales-and-recharge-cash-v3',
            'complete'=>count($gaps)===0,'gaps'=>$gaps];
    }

    /** 事实金额必须是已保存整数分；旧原单金额也只作相等性证明。 */
    private function cents($value): int
    {
        if (!is_int($value) && !(is_string($value)&&preg_match('/^\d{1,15}$/D',$value)))
            throw new \LogicException('原单现金金额不是整数分');
        return (int)$value;
    }

    private function id($value,array $stores): int
    {
        if (!is_int($value)&&!(is_string($value)&&ctype_digit($value)))
            throw new \LogicException('原单现金门店身份无效');
        $id=(int)$value;
        if (!in_array($id,$stores,true)) throw new \LogicException('原单现金越过授权门店');
        return $id;
    }

    /**
     * 正式 V3 只选原销售/充值收款，后补款和旧卡没有进入查询。旧单是否
     * 可计还须与原主键和旧有效支付行匹配；新单须有正式完成头或已付充值。
     */
    private function payments(array $stores,array $range): array
    {
        $query=Db::name('cashier_v3_payment_fact')->alias('p')
            ->leftJoin('cashier_v3_sales_order h',
                'h.tenant_id=p.tenant_id AND h.store_id=p.store_id AND h.order_id=p.order_id')
            ->leftJoin('user_recharge r', "CONCAT('RCH:',r.id)=p.order_id AND r.store_id=p.store_id")
            ->where('p.tenant_id','0')->whereIn('p.store_id',$stores)
            // 销售原单与迁入旧单按正式头的业务日归期，充值按自身
            // 收款业务日。缺头但收款日在期的孤儿事实保留为 gap。
            ->where(static function($date)use($range):void{
                $date->whereBetween('h.business_date',[$range['start'],$range['end']])
                    ->whereOr(static function($unresolved)use($range):void{
                        $unresolved->whereRaw('h.id IS NULL')
                            ->whereBetween('p.business_date',[$range['start'],$range['end']]);
                    })
                    ->whereOr(static function($recharge)use($range):void{
                        $recharge->where('p.source_document_type','recharge')
                            ->whereBetween('p.business_date',[$range['start'],$range['end']]);
                    });
            })
            ->where('p.status','effective')->where('p.fact_direction','forward')
            // 本地 V3 新收款写 payment_collected；现有迁入/对账 Reader
            // 同时承认 payment_received，不能漏掉该类历史正式收款。
            ->whereIn('p.fact_type',['payment_collected','payment_received'])
            ->whereIn('p.source_document_type',['sales_order','cashier_snapshot',
                'cashier_workspace','checkout','legacy_store_order','recharge'])
            ->where('p.payment_method','<>','old_card_entry')
            ->whereNotExists(static function($operation):void{
                $operation->name('cashier_v3_order_lifecycle_operation')->alias('original_cash_reversal')
                    ->whereRaw('original_cash_reversal.tenant_id=p.tenant_id')
                    ->whereRaw('original_cash_reversal.store_id=p.store_id')
                    ->whereRaw("(original_cash_reversal.source_order_id=p.order_id OR CONCAT('RCH:',original_cash_reversal.source_order_id)=p.order_id)")
                    ->whereIn('original_cash_reversal.operation_type',['refund','void'])
                    ->where('original_cash_reversal.status','succeeded');
            });
        $rows=$query->field('p.fact_id,p.store_id,p.order_id,p.source_document_type,p.payment_method,'
            .'p.collection_reference,p.source_line_id,p.amount_cents,'
            .'h.source_document_id source_order_id,h.source_document_type header_source,'
            .'h.order_status,h.order_direction,r.paid recharge_paid')
            ->limit(50001)->select()->toArray();
        if(count($rows)>50000)throw new \RuntimeException('原单现金收款超过安全范围');
        foreach($rows as &$row){
            $recharge=$row['source_document_type']==='recharge';
            $row['eligible']=$recharge ? (int)($row['recharge_paid']??0)===1
                : ($row['order_status']==='settled' && $row['order_direction']==='forward'
                    && ($row['header_source']??'')===$row['source_document_type']);
            // 旧 offline 可迁成 other_collection，但同一代码也表示非现金
            // 其他收款。没有冻结的旧方式映射前保留缺口，不能悄悄漏算。
            if(($row['payment_method']??'')==='other_collection')$row['eligible']=false;
        }
        unset($row);
        return $rows;
    }

    /**
     * 旧订单只用于检查每笔迁入收款覆盖。007 直接支付取 pay_price，组合
     * 按有效组合行求和；这些旧金额绝不进入上方返回的现金业绩。
     */
    private function legacyOrders(array $stores,array $range): array
    {
        $zone=new \DateTimeZone('Asia/Shanghai');
        $from=(new \DateTimeImmutable($range['start'].' 00:00:00',$zone))->getTimestamp();
        $until=(new \DateTimeImmutable($range['end'].' 23:59:59',$zone))->getTimestamp();
        // 原单头先按 V3 业务日、授权门店驱动，再用旧订单主键回查；
        // 同一旧单若有两个在期 V3 头，保留两行交给下方身份校验拒绝，绝不静默去重。
        $headers=Db::name('cashier_v3_sales_order')->where('tenant_id','0')
            ->whereIn('store_id',$stores)->where('source_document_type','legacy_store_order')
            ->whereBetween('business_date',[$range['start'],$range['end']])
            ->field('id,store_id,source_document_id,business_date,order_status,order_direction')
            ->limit(50001)->select()->toArray();
        if(count($headers)>50000)throw new \RuntimeException('旧原单头覆盖超过安全范围');
        $headerIds=[];
        foreach($headers as $header){
            $raw=(string)$header['source_document_id'];
            if(!ctype_digit($raw)||$raw===''||(string)(int)$raw!==$raw||(int)$raw<1)continue;
            $headerIds[]=(int)$raw;
        }
        $migratedOrders=[];
        foreach(array_chunk(array_values(array_unique($headerIds)),500) as $ids){
            foreach($this->legacyBaseQuery($stores)->whereIn('id',$ids)
                ->field('id,store_id,pay_type,pid,cash_choose,pay_price,pay_time,is_system_del')
                ->limit(50001)->select()->toArray() as $old)
                $migratedOrders[(int)$old['id']]=$old;
        }
        $orders=self::migratedCandidateRows($headers,$migratedOrders);
        // 无头人口只按旧支付日找候选；其中缺支付日者仍是全店业务日缺口。
        // 对每批候选通过 V3 来源身份索引反查所有头，不将“头在别月”误作未迁。
        $candidates=$this->legacyBaseQuery($stores)->where('is_system_del',0)
            ->where(static function($pay)use($from,$until):void{
                $pay->whereBetween('pay_time',[$from,$until])
                    ->whereOrRaw('pay_time IS NULL OR pay_time=0');
            })
            ->field('id,store_id,pay_type,pid,cash_choose,pay_price,pay_time,is_system_del')
            ->limit(50001)->select()->toArray();
        if(count($candidates)>50000)throw new \RuntimeException('旧原单覆盖超过安全范围');
        foreach(array_chunk($candidates,500) as $batch){
            $ids=array_values(array_unique(array_map(static fn(array $order):string=>(string)$order['id'],$batch)));
            $matched=[];
            foreach(Db::name('cashier_v3_sales_order')->where('tenant_id','0')
                ->whereIn('store_id',$stores)->where('source_document_type','legacy_store_order')
                ->whereIn('source_document_id',$ids)->field('store_id,source_document_id')
                ->limit(50001)->select()->toArray() as $header)
                $matched[(int)$header['store_id'].'|'.(string)$header['source_document_id']]=true;
            $orders=array_merge($orders,self::unmigratedCandidateRows($batch,$matched));
        }
        if(count($orders)>50000)throw new \RuntimeException('旧原单覆盖超过安全范围');
        $comboIds=[];
        foreach($orders as $order) if($order['pay_type']==='combination')$comboIds[]=(int)$order['id'];
        $combo=[];
        foreach(array_chunk($comboIds,500) as $ids){
            $lines=Db::name('combination_order')->whereIn('order_id',$ids)
                ->where('cash_choose','<>',9)->where('active_pay','<>',3)
                ->field('order_id,price')->limit(50001)->select()->toArray();
            if(count($lines)>50000)throw new \RuntimeException('组合支付覆盖超过安全范围');
            foreach($lines as $line)$combo[(int)$line['order_id']]=
                ($combo[(int)$line['order_id']]??0)+$this->legacyMoney($line['price']);
        }
        $out=[];
        foreach($orders as $order){
            $pid=(int)$order['pid'];
            if($pid!==0&&$pid!==-2&&$pid<1)continue;
            $id=(int)$order['id'];
            $dateState=self::legacyDateState($order['migrated_header_id']??null,
                $order['migrated_business_date']??null,$order['pay_time']??null,$range);
            if($dateState==='outside')continue;
            if($order['pay_type']==='combination')$amount=$combo[$id]??0;
            else{
                // 007 monthYeji 的 not_old 仅排 cash_choose=9；不能
                // 套用另一张对账表排除 10 的“欠款录入”规则。
                if((int)$order['cash_choose']===9)continue;
                $amount=$this->legacyMoney($order['pay_price']);
            }
            if($amount>0)$out[]=['store_id'=>(int)$order['store_id'],
                'source_order_id'=>(string)$id,'expected_cents'=>$amount,
                'business_date_unresolved'=>$dateState==='unresolved'];
        }
        return $out;
    }

    /** 精确的旧ID字符串、门店及归档资格仍按原 LEFT JOIN 逐行语义判定。 */
    private static function migratedCandidateRows(array $headers,array $migratedOrders): array
    {
        $rows=[];
        foreach($headers as $header){
            $raw=(string)$header['source_document_id'];
            if(!ctype_digit($raw)||$raw===''||(string)(int)$raw!==$raw)continue;
            $old=$migratedOrders[(int)$raw]??null;
            if(!$old||(int)$old['store_id']!==(int)$header['store_id'])continue;
            if((int)$old['is_system_del']!==0
                &&!((int)$old['is_system_del']===1
                    &&$header['order_status']==='settled'
                    &&$header['order_direction']==='forward'))continue;
            // 保留同一旧单对应的多个在期头，稍后原单身份校验会拒绝重复。
            $rows[]=array_merge($old,['migrated_header_id'=>$header['id'],
                'migrated_business_date'=>$header['business_date']]);
        }
        return $rows;
    }

    /** 头存在但业务日在别月的旧单绝不能回落到支付日人口。 */
    private static function unmigratedCandidateRows(array $candidates,array $matched): array
    {
        $rows=[];
        foreach($candidates as $old){
            $key=(int)$old['store_id'].'|'.(string)$old['id'];
            if(!isset($matched[$key]))$rows[]=array_merge($old,
                ['migrated_header_id'=>null,'migrated_business_date'=>null]);
        }
        return $rows;
    }

    /** 旧单资格在已迁和无头两分支共用，避免拆查询后漏掉作废、付款或类型过滤。 */
    private function legacyBaseQuery(array $stores)
    {
        return Db::name('store_order')->whereIn('store_id',$stores)->where('paid',1)
            ->where('refund_status',0)->whereIn('order_type',[0,1])
            ->whereIn('pay_type',['weixin','alipay','cash','offline','combination']);
    }

    /**
     * 已迁单只信冻结的 V3 原单业务日。未迁单支付时间只用于找漏项；
     * 无头又无支付时间时保留全域缺口，不以旧 add_time 猜测归期。
     */
    public static function legacyDateState($headerId,$businessDate,$payTime,array $range): string
    {
        if($headerId!==null){
            $date=(string)$businessDate;
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))return 'unresolved';
            return $date>=$range['start']&&$date<=$range['end']?'inside':'outside';
        }
        if($payTime===null||!ctype_digit((string)$payTime)||(int)$payTime<=0)
            return 'unresolved';
        $date=(new \DateTimeImmutable('@'.(int)$payTime))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        return $date>=$range['start']&&$date<=$range['end']?'inside':'outside';
    }

    /** 老金额仅供迁移对账，不以浮点换算或写入新事实。 */
    private function legacyMoney($value): int
    {
        $text=(string)$value;
        if(!preg_match('/^(\d{1,13})(?:\.(\d{1,2})(?:0{1,4})?)?$/D',$text,$parts))
            throw new \LogicException('旧原单金额精度无法认证');
        return (int)$parts[1]*100+(int)str_pad($parts[2]??'0',2,'0');
    }
}
