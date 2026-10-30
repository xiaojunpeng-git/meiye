<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/** 跨店次卡只读取有效 V3 核销，原购买店取该权益唯一持卡人与原单。 */
final class CrossStoreV3CardPairReadServices
{
    /** 已授权且开启工资统计的门店由调用方传入；业务日、作废及金额均由 V3 事实决定。 */
    public function read(array $stores, array $range): array
    {
        $stores=array_values(array_unique(array_map('intval',$stores)));
        if($stores===[] || min($stores)<1)return ['rows'=>[],'data_gaps'=>[],'complete'=>true,'source_count'=>0];
        $all=array_map('intval',Db::name('system_store')->where('is_del',0)
            ->where('name','<>','总部')->where('salary_status',1)->column('id'));
        $visible=array_fill_keys($stores,true);$enabled=array_fill_keys($all,true);
        $pairs=[];$gaps=[];$seen=[];$count=0;
        $voided=$this->voidedServiceIdentities();
        foreach($this->batches($all,$range) as $batch){
            $query=Db::name('cashier_v3_entitlement_writeoff_fact')->alias('w')
                ->leftJoin('user_card_holder h','h.id=w.holder_id')
                ->leftJoin('store_order o','o.id=w.origin_order_id')
                ->where('w.tenant_id','0')->whereIn('w.store_id',$batch)
                // 仅取本店执行或本店原购买的交易，不取与授权店无关的核销行。
                ->whereRaw('(w.store_id IN ('.implode(',',array_fill(0,count($stores),'?')).') OR '
                    .'h.store_id IN ('.implode(',',array_fill(0,count($stores),'?')).'))',array_merge($stores,$stores))
                ->whereBetween('w.business_date',[$range['start'],$range['end']])
                ->where('w.status','effective')->where('w.is_gift',0);
            $facts=$query->field('w.writeoff_id,w.store_id,w.member_id,w.holder_id,w.origin_order_id,'
                .'w.entitlement_instance_type,w.actual_entitlement_amount_cents,w.checkout_request_id,w.source_line_id,'
                .'h.id verified_holder_id,h.uid holder_member_id,h.oid holder_order_id,h.store_id holder_store_id,'
                .'o.id verified_order_id,o.uid order_member_id,o.store_id origin_store_id,'
                .'o.paid,o.order_type,o.is_del,o.is_system_del,o.refund_status')
                ->limit(10001)->select()->toArray();
            if(count($facts)>10000)throw new \RuntimeException('单批跨店次卡核销超过10000条');
            foreach($facts as $fact){
                $line=(string)($fact['source_line_id']??'');
                $checkout=(string)($fact['checkout_request_id']??'');
                $serviceKey=json_encode([(int)($fact['store_id']??0),$checkout,$line]);
                $saleKey=json_encode([$checkout,$line]);
                if(isset($voided['sales'][$saleKey])||isset($voided['services'][$serviceKey]))continue;
                ++$count;$id=(string)($fact['writeoff_id']??'');$target=(int)($fact['store_id']??0);
                if($id===''||isset($seen[$id])||!isset($enabled[$target]))
                    throw new \LogicException('跨店次卡核销身份或权限异常');
                $seen[$id]=true;
                $reason=$this->identityGap($fact);
                if($reason!==null){$gaps[$reason]=($gaps[$reason]??0)+1;continue;}
                $origin=(int)$fact['origin_store_id'];
                if($origin===$target||!isset($enabled[$origin]))continue;
                if(!isset($visible[$origin])&&!isset($visible[$target]))
                    throw new \LogicException('次卡双向查询读取了无关门店交易');
                $cents=$this->integerCents($fact['actual_entitlement_amount_cents']);
                if($cents<0)throw new \LogicException('次卡核销金额方向异常');
                $key=$origin.':'.$target;
                if(!isset($pairs[$key]))$pairs[$key]=['source_store_id'=>$origin,'target_store_id'=>$target,
                    'card_payable_cents'=>0,'card_receivable_cents'=>0];
                // 同一笔有效核销只入账一次；两端来自同一个整数分，不再分别四舍五入。
                $pairs[$key]['card_payable_cents']+=$cents;
                $pairs[$key]['card_receivable_cents']+=$cents;
            }
            unset($facts);
        }
        $rows=[];
        foreach($pairs as $pair)$rows[]=['source_store_id'=>$pair['source_store_id'],
            'target_store_id'=>$pair['target_store_id'],
            'card_payable'=>$pair['card_payable_cents']/100,
            'card_receivable'=>$pair['card_receivable_cents']/100,
            'stored_value_payable'=>0.0,'stored_value_receivable'=>0.0];
        $dataGaps=[];
        foreach($gaps as $code=>$n)$dataGaps[]=['code'=>$code,'source_count'=>$n,
            'message'=>'有付费次卡核销无法认证原权益购买店或金额，整期结算不得展示局部金额。'];
        return ['rows'=>$rows,'data_gaps'=>$dataGaps,'complete'=>$dataGaps===[],
            'source_count'=>$count];
    }

    /**
     * 成功作废的销售来源线及独立服务线跨业务日退出经营报表。
     * 销售来源线同时绑定结账键，避免别单同名线被误排；先取稀疏
     * 作废身份，避免在 5 万条核销上反复执行相关子查询。
     */
    private function voidedServiceIdentities():array
    {
        $sales=Db::name('cashier_v3_order_lifecycle_operation')->alias('v')
            ->join('cashier_v3_sale_fact sf',
                'sf.tenant_id=v.tenant_id AND sf.order_id=v.source_order_id')
            ->where('v.tenant_id','0')->where('v.source_type','sales')
            ->where('v.operation_type','void')->where('v.status','succeeded')
            ->field('sf.checkout_request_id,sf.source_line_id')->limit(100001)->select()->toArray();
        $services=Db::name('cashier_v3_service_record_void_operation')->alias('v')
            ->join('cashier_v3_entitlement_service_fact s',
                's.tenant_id=v.tenant_id AND s.id=v.service_fact_id')
            ->where('v.tenant_id','0')->where('v.status','succeeded')
            ->field('s.store_id,s.checkout_request_id,s.source_line_id')
            ->limit(100001)->select()->toArray();
        if(count($sales)>100000||count($services)>100000)
            throw new \RuntimeException('跨店作废身份超过只读检查上限');
        $saleKeys=[];$serviceKeys=[];
        foreach($sales as $row)$saleKeys[json_encode([
            (string)$row['checkout_request_id'],(string)$row['source_line_id']])]=true;
        foreach($services as $row)$serviceKeys[json_encode([
            (int)$row['store_id'],(string)$row['checkout_request_id'],(string)$row['source_line_id']])]=true;
        return ['sales'=>$saleKeys,'services'=>$serviceKeys];
    }

    /** 所有正常付费权益都必须有同会员、同原单的持卡身份；不以当前会员门店猜来源。 */
    private function identityGap(array $fact): ?string
    {
        if(($fact['entitlement_instance_type']??'')!=='card_holder')return 'CROSS_CARD_ENTITLEMENT_TYPE_UNVERIFIED';
        $member=(int)($fact['member_id']??0);$holder=(int)($fact['holder_id']??0);
        $order=(int)($fact['origin_order_id']??0);
        if($member<1||$holder<1||$order<1||$holder!==(int)($fact['verified_holder_id']??0)
            ||$member!==(int)($fact['holder_member_id']??0)
            ||$order!==(int)($fact['holder_order_id']??0))return 'CROSS_CARD_HOLDER_IDENTITY_UNVERIFIED';
        if($order!==(int)($fact['verified_order_id']??0)
            ||$member!==(int)($fact['order_member_id']??0)
            ||(int)($fact['paid']??0)!==1||(int)($fact['order_type']??-1)!==0
            ||(int)($fact['is_del']??1)!==0||(int)($fact['is_system_del']??1)!==0
            ||!in_array((int)($fact['refund_status']??-1),[0,3],true))
            return 'CROSS_CARD_ORIGIN_ORDER_UNVERIFIED';
        if((int)($fact['origin_store_id']??0)<1
            ||(int)$fact['origin_store_id']!==(int)($fact['holder_store_id']??0))
            return 'CROSS_CARD_ORIGIN_STORE_UNVERIFIED';
        $this->integerCents($fact['actual_entitlement_amount_cents']??null);
        return null;
    }

    /** 原始行数只用于分批，所有金额仍来自统一核销事实。 */
    private function batches(array $stores,array $range):array
    {
        $weights=array_fill_keys($stores,0);
        foreach(Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id','0')
            ->whereIn('store_id',$stores)->whereBetween('business_date',[$range['start'],$range['end']])
            ->where('status','effective')->where('is_gift',0)
            ->fieldRaw('store_id,COUNT(*) n')->group('store_id')->select()->toArray() as $row){
            $store=(int)$row['store_id'];if(!isset($weights[$store]))throw new \LogicException('次卡分批越权');
            $weights[$store]=(int)$row['n'];
        }
        $out=[];$batch=[];$used=0;
        foreach($stores as $store){$weight=max(1,$weights[$store]);
            if($weight>10000)throw new \RuntimeException('单店次卡核销超过10000条，请缩小日期范围');
            if($batch!==[]&&$used+$weight>8000){$out[]=$batch;$batch=[];$used=0;}
            $batch[]=$store;$used+=$weight;
        }
        if($batch!==[])$out[]=$batch;
        return $out;
    }

    /** 事实金额必须原样是整数分；小数元展示仅在最终投影边界。 */
    private function integerCents($value):int
    {
        if(is_string($value)&&preg_match('/^-?(0|[1-9][0-9]*)$/D',$value))$value=(int)$value;
        if(!is_int($value)||abs($value)>9007199254740991)
            throw new \LogicException('次卡核销金额不是整数分');
        return $value;
    }
}
