<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 007 门店消耗/服务客数的服务订单桥。旧子单按 link_id 精确连接 V3 原
 * 核销服务，同一核销关联两笔有效子单时保留两笔；原生金额与业务日取 V3；认证历史来源采用已批准的原核销金额和业务日。
 * 新 V3 服务按其正式服务单身份计一次，授权店只看自身归属的结果。
 */
final class Sidebar007ServiceOrderReadServices
{
    private $serviceReader;
    private $legacyOrderReader;
    private $crossStoreReader;

    /** 注入点仅供合同测试；生产金额由统一服务事实 Reader 给出。 */
    public function __construct(?callable $serviceReader=null,?callable $legacyOrderReader=null,
        ?callable $crossStoreReader=null)
    {
        $this->serviceReader=$serviceReader ?: static fn(array $stores,array $range):array=>
            (new CompletedService007AmountIdentityReadServices())->read($stores,$range);
        $this->legacyOrderReader=$legacyOrderReader ?: [$this,'legacyOrders'];
        $this->crossStoreReader=$crossStoreReader ?: [$this,'crossStoreCount'];
    }

    /** 两个本表基础指标须同一批已认证服务订单同时完整。 */
    public function read(array $stores,array $range):array
    {
        $allowed=[];
        foreach($stores as $id){
            if(!is_int($id)||$id<1||isset($allowed[$id]))
                throw new \InvalidArgumentException('服务订单授权门店无效');
            $allowed[$id]=true;
        }
        if(!$allowed)throw new \InvalidArgumentException('服务订单授权门店为空');
        foreach(['start','end'] as $key){
            $value=$range[$key]??null;
            $day=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
            if(!$day||$day->format('Y-m-d')!==$value)
                throw new \InvalidArgumentException('服务订单业务日期无效');
        }
        if($range['start']>$range['end'])throw new \InvalidArgumentException('服务订单日期逆序');
        $source=($this->serviceReader)($stores,$range);
        if(!is_array($source)||!is_bool($source['complete']??null)
            ||!is_array($source['rows']??null)||!is_array($source['gaps']??null))
            throw new \LogicException('逐服务来源结构无效');
        if(!$source['complete']||$source['gaps']!==[])
            return $this->gap($stores,$source['gaps']);
        $legacyIds=[];$seenFact=[];
        foreach($source['rows'] as $fact){
            $id=(string)($fact['service_fact_id']??'');
            $store=(int)($fact['store_id']??0);
            if($id===''||isset($seenFact[$id])||!isset($allowed[$store]))
                throw new \LogicException('逐服务事实越权或重复');
            $seenFact[$id]=true;
            $type=(string)($fact['source_document_type']??'');
            if(in_array($type,['legacy_writeoff','legacy_paid_project'],true)){
                $old=(string)($fact['source_document_id']??'');
                if(!ctype_digit($old)||(int)$old<1||isset($legacyIds[(int)$old]))
                    throw new \LogicException('旧核销来源身份无效或重复');
                $legacyIds[(int)$old]=$fact;
            }elseif(!in_array($type,['direct','sales_order','reservation'],true))
                throw new \LogicException('未知服务订单来源');
        }
        $old=($this->legacyOrderReader)($stores,$range,array_keys($legacyIds));
        $cross=($this->crossStoreReader)($stores,$range);
        if(!is_array($old)||count($old)>100000||!is_int($cross)||$cross<0)
            throw new \LogicException('旧服务子单来源无效或过大');
        if($cross>0)return $this->gap($stores,[['code'=>'LEGACY_SERVICE_ORDER_OUTSIDE_SCOPE',
            'count'=>$cross,'message'=>'部分服务订单归属本店、核销却发生于未授权门店，无法完整对账。']]);
        $suborders=[];
        foreach($old as $order){
            $id=(int)($order['store_id']??0);$link=(int)($order['link_id']??0);
            $orderId=(int)($order['id']??0);
            if(!isset($allowed[$id])||$orderId<1||$link<1||isset($suborders[$orderId]))
                throw new \LogicException('旧服务子单越权或身份重复');
            $suborders[$orderId]=true;
            if(!isset($legacyIds[$link]))return $this->gap($stores,[['code'=>'LEGACY_SERVICE_ORDER_FACT_MISSING',
                'source_order_id'=>$orderId]]);
            $fact=$legacyIds[$link];
            $oldFriend=trim((string)($order['service_object']??''))==='朋友';
            $v3Friend=strtolower(trim((string)$fact['service_object']))==='friend';
            $origin=(int)($fact['legacy_origin_order_id']??0);
            if((int)($order['uid']??-1)!==(int)$fact['member_id']
                ||$oldFriend!==$v3Friend
                ||$origin<1||(int)($order['link_order']??0)!==$origin
                // Historical consumption uses the explicitly approved original writeoff amount;
                // old suborder pay_price may retain a different fractional settlement amount.
                ||(($fact['source_kind']??'')!=='historical_007_card_service'
                    &&$this->oldMoney($order['pay_price']??null)!==(int)$fact['amount_cents']))
                return $this->gap($stores,[['code'=>'LEGACY_SERVICE_ORDER_IDENTITY_OR_AMOUNT_DIFF',
                    'source_order_id'=>$orderId]]);
        }
        $linked=[];
        foreach($old as $order)$linked[(int)$order['link_id']]=true;
        foreach($legacyIds as $writeoffId=>$_)if(!isset($linked[$writeoffId]))
            return $this->gap($stores,[['code'=>'LEGACY_SERVICE_ORDER_QUALIFICATION_UNVERIFIED',
                'source_writeoff_id'=>$writeoffId,
                'message'=>'旧核销已迁入服务事实，但缺少可核的有效服务子单，不能按零笔处理。']]);
        $amounts=array_fill_keys($stores,0);$peopleKeys=[];$counts=array_fill_keys($stores,0);
        $qualifiedEntries=[];
        // “消耗项目数”原页按有效核销 writeoff_num 求和，最后转整数；
        // 同一核销即使关联两张服务子单，在此也只能算一次原核销数量。
        $projectQuantities=array_fill_keys($stores,'0');
        foreach($source['rows'] as $fact){
            $store=(int)$fact['store_id'];$quantity=(string)($fact['quantity']??'');
            if(!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D',$quantity))
                throw new \LogicException('服务项目数量无效');
            $projectQuantities[$store]=bcadd($projectQuantities[$store],$quantity,6);
        }
        foreach($source['rows'] as $fact){
            $type=(string)$fact['source_document_type'];
            if(in_array($type,['legacy_writeoff','legacy_paid_project'],true))continue;
            $this->add($fact,(int)$fact['store_id'],(string)$fact['document_id'],
                true,$amounts,$peopleKeys,$counts,$qualifiedEntries);
        }
        foreach($old as $order){
            $fact=$legacyIds[(int)$order['link_id']];
            // 007 消耗按服务子单门店和笔数统计，不按核销执行门店/去重
            // 后的核销 ID。两笔子单链接同一核销时相同 V3 金额各计一次。
            $this->add($fact,(int)$order['store_id'],'legacy-order:'.$order['id'],
                ($order['aftercare_cash_eligible']??false)===true,
                $amounts,$peopleKeys,$counts,$qualifiedEntries);
        }
        $byStore=[];
        foreach($stores as $id)$byStore[$id]=['consumption_amount_cents'=>$amounts[$id],
            'service_customer_count'=>$counts[$id],
            'consumed_project_count'=>(int)$projectQuantities[$id]];
        return ['by_store'=>$byStore,
            'store_ids'=>$stores,'complete'=>true,'gaps'=>[],
            'qualified_entries'=>$qualifiedEntries,
            // Exact certified period population for downstream personnel coverage; never scan all history.
            'service_rows'=>$source['rows'],
            // Preserve the adapter provenance for the shared original-personnel projection.
            'historical_services'=>array_values(array_filter($source['rows'],static fn(array $r):bool=>
                ($r['source_kind']??'')==='historical_007_card_service')),
            'source'=>'certified_v3_service_amount_by_007_service_order'];
    }

    /** 会员同店同日去重，游客或朋友按服务订单逐笔；金额按订单逐笔。 */
    private function add(array $fact,int $store,string $order,bool $aftercareEligible,array &$amounts,
        array &$seen,array &$counts,array &$entries):void
    {
        $amount=$fact['amount_cents']??null;
        $day=(string)($fact['business_date']??'');
        $member=(int)($fact['member_id']??-1);
        $object=strtolower(trim((string)($fact['service_object']??'')));
        if(!is_int($amount)||$amount<0||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)
            ||$member<0||$order===''||!in_array($object,['self','friend',''],true))
            throw new \LogicException('服务订单金额、日期或客户身份无效');
        $amounts[$store]+=$amount;
        $entries[]=['store_id'=>$store,'business_date'=>$day,'member_id'=>$member,
            'friend'=>$object==='friend','order_id'=>'service:'.$order,
            'service_fact_id'=>(string)$fact['service_fact_id'],
            'source_kind'=>(string)($fact['source_kind']??'v3_service_fact'),
            'aftercare_cash_eligible'=>$aftercareEligible];
        $key=($member===0||$object==='friend')?'order:'.$order:'member:'.$day.':'.$member;
        if(!isset($seen[$store][$key])){$seen[$store][$key]=true;++$counts[$store];}
    }

    /** 旧子单金额只校验已迁 V3 身份；未迁历史金额由统一原核销桥认证。 */
    private function oldMoney($value):?int
    {
        if(!preg_match('/^(\d{1,13})(?:\.(\d{1,2})(?:0{1,4})?)?$/D',(string)$value,$parts))
            return null;
        return (int)$parts[1]*100+(int)str_pad($parts[2]??'0',2,'0');
    }

    /** 同期旧子单只按已核的原核销 ID 找，统计日期由 V3 服务事实决定。 */
    private function legacyOrders(array $stores,array $range,array $legacyIds):array
    {
        $out=[];
        foreach(array_chunk($legacyIds,500) as $ids){
            $batch=Db::name('store_order')->whereIn('store_id',$stores)
                ->whereIn('link_id',$ids)->where('order_type',2)->where('paid',1)
                ->where('refund_status',0)->whereIn('is_system_del',[0,1])
                ->field('*')
                ->limit(100001)->select()->toArray();
            if(count($batch)>100000)throw new \RuntimeException('服务子单超过安全范围');
            // Complete rows are required for the independent archive fingerprint. Only
            // exact sealed-result proofs admit archived children; other deletion stays excluded.
            $certified=(new ServiceOrderArchiveProvenanceServices())->certified($batch);
            $batch=array_values(array_filter($batch,static fn(array $row):bool=>
                (int)$row['is_system_del']===0||isset($certified[(int)$row['id']])));

            $combinationIds=[];
            foreach($batch as $row)if((string)($row['pay_type']??'')==='combination')
                $combinationIds[]=(int)$row['id'];
            $validComb=[];
            foreach(array_chunk($combinationIds,500) as $comboChunk){
                $rows=Db::name('combination_order')->whereIn('order_id',$comboChunk)
                    ->whereNotIn('cash_choose',[9,10])->where('active_pay','<>',3)
                    ->where(function($query):void{
                        $query->whereNull('pay_sub_type')->whereOr('pay_sub_type','<>','debt');
                    })->field('order_id')->select()->toArray();
                foreach($rows as $row)$validComb[(int)$row['order_id']]=true;
            }
            foreach($batch as &$row){
                $choice=$row['cash_choose']??null;
                $row['aftercare_cash_eligible']=(string)($row['pay_type']??'')==='combination'
                    ?isset($validComb[(int)$row['id']])
                    :($choice!==null&&!in_array((int)$choice,[9,10],true));
            }
            unset($row);
            $out=array_merge($out,$batch);
            if(count($out)>100000)throw new \RuntimeException('服务子单超过安全范围');
        }
        return $out;
    }

    /**
     * 跨店子单只阻断本期：已迁服务用事实业务日，未迁原核销采用已批准
     * 的 add_time 导入业务日。不能让数年前跨店记录永久阻断每月报表；
     * 本期真正的授权外来源仍保留缺口，不擅自扩大调用者门店权限。
     */
    private function crossStoreCount(array $stores,array $range):int
    {
        $zone=new \DateTimeZone('Asia/Shanghai');
        $start=(new \DateTimeImmutable($range['start'],$zone))->getTimestamp();
        $end=(new \DateTimeImmutable($range['end'],$zone))->modify('+1 day')->getTimestamp();
        $rows=Db::name('store_order')->alias('o')
            ->join('store_order_writeoff w','w.id=o.link_id')
            ->leftJoin('cashier_v3_entitlement_service_fact s',
                "s.tenant_id='0' AND s.source_document_type IN ('legacy_writeoff','legacy_paid_project') AND s.source_document_id=CAST(w.id AS CHAR)")
            ->whereIn('o.store_id',$stores)->whereNotIn('w.relation_id',$stores)
            ->where('o.order_type',2)->where('o.paid',1)->where('o.refund_status',0)
            ->whereIn('o.is_system_del',[0,1])
            ->where(function($q)use($range,$start,$end):void{
                $q->whereBetween('s.business_date',[$range['start'],$range['end']])
                    ->whereOr(function($missing)use($start,$end):void{
                        $missing->whereNull('s.service_fact_id')->where(function($day)use($start,$end):void{
                            $day->whereBetween('w.add_time',[$start,$end-1])->whereOr('w.add_time','<=',0);
                        });
                    });
            })->field('o.*')->distinct(true)->limit(100001)->select()->toArray();
        if(count($rows)>100000)throw new \RuntimeException('跨店原服务子单超过安全范围');
        $certified=(new ServiceOrderArchiveProvenanceServices())->certified($rows);
        return count(array_filter($rows,static fn(array $row):bool=>
            (int)$row['is_system_del']===0||isset($certified[(int)$row['id']])));
    }

    private function gap(array $stores,array $gaps):array
    {
        $byStore=[];
        foreach($stores as $id)$byStore[$id]=['consumption_amount_cents'=>null,
            'service_customer_count'=>null];
        return ['by_store'=>$byStore,
            'store_ids'=>$stores,'complete'=>false,'gaps'=>$gaps,
            'source'=>'certified_v3_service_amount_by_007_service_order'];
    }
}
