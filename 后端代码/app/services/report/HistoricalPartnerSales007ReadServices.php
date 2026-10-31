<?php
declare(strict_types=1);
namespace app\services\report;
use think\facade\Db;

/** Original 007 sales/recharge personnel allocations not yet represented by V3.
 * Current personnel type follows the approved current-employment report rule;
 * amount and original order identity remain the recorded staff_yeji evidence.
 * This reader does not manufacture V3 allocation rows or rewrite business data.
 */
final class HistoricalPartnerSales007ReadServices
{
    /** Caller-scoped, exact-ID lookups avoid a large cross join of all historical heads. */
    public function read(array $stores,array $range):array
    {
        if(!$stores||count($stores)!==count(array_unique($stores)))throw new \InvalidArgumentException('历史合作方授权范围无效');
        foreach($stores as $id)if(!is_int($id)||$id<1)throw new \InvalidArgumentException('历史合作方门店无效');
        foreach(['start','end'] as $key){$day=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)($range[$key]??''));
            if(!$day||$day->format('Y-m-d')!==$range[$key])throw new \InvalidArgumentException('历史合作方业务日无效');}
        if($range['start']>$range['end'])throw new \InvalidArgumentException('历史合作方日期倒置');
        $people=Db::name('staff_yeji')->alias('y')->leftJoin('system_store_staff s','s.id=y.staff_id')
            ->whereIn('y.store_id',$stores)->whereIn('y.type',[1,2])->where('y.status',0)
            ->where(static function($q):void{$q->where('s.is_hezuofang',1)->whereOrRaw('s.is_hezuofang IS NULL');})
            ->field('y.id,y.type,y.store_id,y.staff_id,y.order_id,y.link_id,y.yeji,s.employee_id,s.is_hezuofang')
            ->limit(100001)->select()->toArray();
        if(count($people)>100000)throw new \RuntimeException('原合作方分配超过安全范围');
        $ids=array_values(array_unique(array_map(static fn(array $r):int=>(int)$r['order_id'],$people)));
        $orders=[];$facts=[];
        $op=Db::name('cashier_v3_order_lifecycle_operation')->getTable();
        $receipts=Db::name('cashier_v3_card_purchase_receipt')->getTable();
        $visibility=ReportLegacyArchiveProofServices::visibilitySql('o','h',$op,$receipts);
        foreach(array_chunk($ids,300) as $chunk){
            $rows=Db::name('store_order')->alias('o')->leftJoin('cashier_v3_sales_order h',
                "h.tenant_id='0' AND h.source_document_type='legacy_store_order' AND h.source_document_id=CAST(o.id AS CHAR) AND h.store_id=o.store_id")
                ->whereIn('o.id',$chunk)->whereIn('o.store_id',$stores)->where('o.paid',1)->where('o.refund_status',0)
                ->whereIn('o.order_type',[0,1])->whereRaw($visibility)
                ->whereNotExists(static function($q):void{
                    $q->name('cashier_v3_order_lifecycle_operation')->alias('partner_old_void')
                        ->where('partner_old_void.tenant_id','0')->whereRaw('partner_old_void.store_id=h.store_id')
                        ->where('partner_old_void.source_type','sales')->whereRaw('partner_old_void.source_order_id=h.order_id')
                        ->whereIn('partner_old_void.operation_type',['refund','void'])->where('partner_old_void.status','succeeded');
                })->field('o.id,o.store_id,o.order_type,o.pay_time,h.order_id migrated_order_id,h.business_date')->select()->toArray();
            foreach($rows as $row){$id=(int)$row['id'];if(isset($orders[$id]))throw new \LogicException('原合作方订单头重复');$orders[$id]=$row;}
        }
        foreach(array_chunk(array_column($people,'id'),500) as $chunk){
            $keys=array_map(static fn($id):string=>'IMP007-Y'.$id.'-sales',$chunk);
            $unknown=ImportedPerformanceTypeProvenanceServices::unverifiedSql('p');
            $rows=Db::name('cashier_v3_performance_fact')->alias('p')->where('p.tenant_id','0')
                ->whereIn('p.store_id',$stores)->whereIn('p.fact_id',$keys)
                ->where('p.performance_type','sales_performance_allocated')
                ->field('p.fact_id,p.store_id,p.order_id,p.employee_id,p.amount_cents,p.employee_type_snapshot,p.status')
                ->fieldRaw('IF(NOT '.$unknown.',1,0) certified')->select()->toArray();
            foreach($rows as $row)$facts[$row['fact_id']]=$row;
        }
        return $this->project($people,$orders,$facts,$stores,$range);
    }

    /** Shared projection: existing certified V3 allocations win; mismatches never double-count. */
    public function project(array $people,array $orders,array $facts,array $stores,array $range):array
    {
        $allowed=array_fill_keys($stores,true);$rows=[];$gaps=[];$seen=[];
        foreach($people as $p){
            $id=(int)$p['id'];$store=(int)$p['store_id'];$old=$orders[(int)$p['order_id']]??null;
            if(!isset($allowed[$store])||$id<1||isset($seen[$id]))throw new \LogicException('原合作方人员范围或身份重复');
            $seen[$id]=true;if($old===null)continue;
            $day=(string)($old['business_date']??'');
            if($day===''&&(int)($old['pay_time']??0)>0)$day=(new \DateTimeImmutable('@'.(int)$old['pay_time']))
                ->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
            if($day===''){$gaps[]=['code'=>'HISTORICAL_PARTNER_BUSINESS_DATE','source_order_id'=>(int)$p['order_id']];continue;}
            if($day<$range['start']||$day>$range['end'])continue;
            $type=(int)$p['type'];$employee=(int)($p['employee_id']??0);
            if((int)$old['store_id']!==$store||$employee<1||($p['is_hezuofang']??null)===null
                ||($type===2&&((int)$p['link_id']!==(int)$p['order_id']||(int)$old['order_type']!==0))
                ||($type===1&&(int)$old['order_type']!==1)){
                $gaps[]=['code'=>'HISTORICAL_PARTNER_IDENTITY','source_yeji_id'=>$id];continue;
            }
            if((int)$p['is_hezuofang']!==1)continue;
            $text=(string)$p['yeji'];
            if(!preg_match('/^(\d{1,13})(?:\.(\d{1,2})0*)?$/D',$text,$m)){
                $gaps[]=['code'=>'HISTORICAL_PARTNER_AMOUNT','source_yeji_id'=>$id];continue;
            }
            $amount=(int)$m[1]*100+(int)str_pad($m[2]??'0',2,'0');
            $existing=$facts['IMP007-Y'.$id.'-sales']??null;
            $order=(string)($old['migrated_order_id']??'');
            if($existing!==null){
                // An existing terminal V3 allocation is not an unmigrated source. Never
                // resurrect its old personnel amount after a later adjustment or reversal.
                if(($existing['status']??'effective')!=='effective'){
                    $gaps[]=['code'=>'HISTORICAL_PARTNER_V3_LIFECYCLE_UNVERIFIED','source_yeji_id'=>$id];continue;
                }
                if((int)($existing['certified']??0)!==1||(int)$existing['store_id']!==$store
                    ||$existing['order_id']!==$order||(int)$existing['employee_id']!==$employee
                    ||(int)$existing['amount_cents']!==$amount||$existing['employee_type_snapshot']!=='partner')
                    $gaps[]=['code'=>'HISTORICAL_PARTNER_V3_CONFLICT','source_yeji_id'=>$id];
                continue;
            }
            $rows[]=['source_kind'=>'historical_007_partner_sales','source_yeji_id'=>$id,'store_id'=>$store,
                'order_id'=>$order!==''?$order:'historical:order:'.$p['order_id'],
                'business_date'=>$day,'partner_amount_cents'=>$amount];
        }
        return ['rows'=>$rows,'gaps'=>$gaps,'complete'=>$gaps===[]];
    }
}
