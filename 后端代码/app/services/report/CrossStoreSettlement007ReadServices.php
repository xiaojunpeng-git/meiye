<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/** 007 跨店结算的一店一行净额投影；V3 来源快照未覆盖前不得展示近似净额。 */
final class CrossStoreSettlement007ReadServices
{
    private $pairReader;
    private $storeReader;
    private $coverageReader;

    /** 注入项供合同测试；生产配对事实仍取已授权统一财务服务。 */
    public function __construct(?callable $pairReader = null, ?callable $storeReader = null,
        ?callable $coverageReader = null)
    {
        $this->pairReader = $pairReader ?: [$this,'canonicalPairs'];
        $this->storeReader = $storeReader ?: static function (array $stores): array {
            return Db::name('system_store')->whereIn('id',$stores)->where('salary_status',1)
                ->field('id,name')->limit(10001)->select()->toArray();
        };
        // 每种金额来源自行认证双向人口；这里只允许测试注入进一步收紧覆盖。
        $this->coverageReader = $coverageReader ?: static fn(array $stores,array $range): bool => true;
    }

    /** 储值两类原单与次卡核销各自认证，再按同一来源店/执行店配对守恒。 */
    private function canonicalPairs(array $stores,array $range):array
    {
        $balance=$this->ordinaryBalancePairs($stores,$range);
        $legacy=$this->orderPayPairs($stores,$range);
        $cards=(new CrossStoreV3CardPairReadServices())->read($stores,$range);
        $rows=[];
        foreach(array_merge($balance['rows'],$legacy['rows'],$cards['rows']) as $pair){
            $key=(int)($pair['source_store_id']??0).':'.(int)($pair['target_store_id']??0);
            if(!isset($rows[$key])){$rows[$key]=$pair;continue;}
            foreach(['stored_value_payable','stored_value_receivable','card_payable','card_receivable'] as $field){
                // 三个来源都在最终投影边界转元；配对相加前复原整数分。
                $rows[$key][$field]=($this->cents($rows[$key][$field]??0)
                    +$this->cents($pair[$field]??0))/100;
            }
        }
        $gaps=array_merge($balance['data_gaps'],$legacy['data_gaps'],$cards['data_gaps']);
        return ['rows'=>array_values($rows),'data_gaps'=>$gaps,'complete'=>$gaps===[]];
    }

    /**
     * 普通 V3 余额支付与退款只用成功交易冻结的旧原单 kua_store 定方向。
     * 新单若没有对手店快照，逐来源报缺口；当前会员所属店不能代替原单。
     */
    private function ordinaryBalancePairs(array $stores,array $range):array
    {
        $enabled=array_map('intval',Db::name('system_store')->where('is_del',0)
            ->where('name','<>','总部')->where('salary_status',1)->column('id'));
        if($enabled===[])return ['rows'=>[],'data_gaps'=>[]];
        $query=Db::name('cashier_v3_balance_fact')->alias('p')->where('p.tenant_id','0')
            ->whereIn('p.store_id',$enabled)->where('p.status','effective')
            ->whereBetween('p.business_date',[$range['start'],$range['end']])
            ->whereIn('p.balance_change_type',['order_payment','order_refund','debt_repayment']);
        $normal=new StoreReportNormalDataScopeServices();
        $normal->excludeVoidedSalesOrderFacts($query,'p.tenant_id','p.order_id');
        $normal->excludeVoidedRechargeOrderFacts($query,'p.tenant_id','p.order_id');
        $normal->excludeVoidedSupplementPaymentFacts($query,'p.tenant_id','p.store_id','p.order_id');
        $outsideAudit=clone $query;
        $visibleSql=implode(',',array_map('intval',$stores));
        // 只读取本店执行或旧原单明确标记本店为对手方的金额事实。
        $query->whereRaw('(p.store_id IN ('.$visibleSql.') OR EXISTS (SELECT 1 FROM eb_store_order scope_o '
            .'WHERE scope_o.order_id=p.order_id AND scope_o.store_id=p.store_id '
            .'AND scope_o.uid=p.member_id AND scope_o.order_type=0 AND scope_o.paid=1 '
            .'AND scope_o.kua_store IN ('.$visibleSql.')))');
        $facts=$query->field('p.fact_id,p.store_id,p.member_id,p.order_id,p.balance_change_type,'
            .'p.principal_delta_cents,p.bonus_delta_cents')->limit(10001)->select()->toArray();
        if(count($facts)>10000)throw new \RuntimeException('跨店储值事实超过10000行');
        $orderIds=array_values(array_unique(array_filter(array_map('strval',array_column($facts,'order_id')))));
        $orders=[];
        foreach(array_chunk($orderIds,500) as $ids){
            foreach(Db::name('store_order')->whereIn('order_id',$ids)->whereIn('store_id',$enabled)
                ->where('order_type',0)->where('paid',1)
                ->field('id,order_id,uid,store_id,kua_store')->select()->toArray() as $row){
                $key=(string)$row['order_id'].':'.(int)$row['store_id'].':'.(int)$row['uid'];
                if(array_key_exists($key,$orders))$orders[$key]=null;
                else $orders[$key]=$row;
            }
        }
        $allowed=array_fill_keys($enabled,true);$visible=array_fill_keys($stores,true);
        $pairs=[];$gaps=[];$seen=[];$missingCounterparty=0;
        foreach($facts as $fact){
            $id=(string)($fact['fact_id']??'');$target=(int)($fact['store_id']??0);
            if($id===''||isset($seen[$id])||!isset($allowed[$target]))
                throw new \LogicException('储值事实身份或权限异常');
            $seen[$id]=true;
            $identity=(string)$fact['order_id'].':'.$target.':'.(int)$fact['member_id'];
            $order=$orders[$identity]??null;
            if(!$order||!array_key_exists('kua_store',$order)){
                ++$missingCounterparty;
                continue;
            }
            // 007 only admits kua_store>0. An existing original column with
            // NULL/0 is excluded by that eligibility rule, not an unknown
            // cross-store transaction; never infer today's member store.
            $origin=(int)$order['kua_store'];
            if($origin===0||$origin===$target||!isset($allowed[$origin]))continue;
            if(!isset($visible[$origin])&&!isset($visible[$target]))continue;
            $amount=-($this->integerCents($fact['principal_delta_cents']??null)
                +$this->integerCents($fact['bonus_delta_cents']??null));
            $key=$origin.':'.$target;
            if(!isset($pairs[$key]))$pairs[$key]=['source_store_id'=>$origin,'target_store_id'=>$target,
                '_stored_value_cents'=>0];
            $pairs[$key]['_stored_value_cents']=$this->integerCents($pairs[$key]['_stored_value_cents']+$amount);
        }
        $rows=[];
        foreach($pairs as $pair)$rows[]=['source_store_id'=>$pair['source_store_id'],
            'target_store_id'=>$pair['target_store_id'],
            'stored_value_payable'=>$pair['_stored_value_cents']/100,
            'stored_value_receivable'=>$pair['_stored_value_cents']/100,
            'card_payable'=>0.0,'card_receivable'=>0.0];
        if($missingCounterparty>0)$gaps[]=['code'=>'CROSS_STORED_VALUE_COUNTERPARTY_MISSING',
            'source_count'=>$missingCounterparty,
            'message'=>'V3 储值支付缺少成交时冻结的对手门店；原单无可认证 kua_store，不能用当前会员门店推算。'];
        if(array_diff($enabled,$stores)!==[]){
            // 外店未知方向仅做 exists 审计，不读取其会员、金额或交易行。
            $unknown=$outsideAudit->whereNotIn('p.store_id',$stores)
                ->whereRaw('NOT EXISTS (SELECT 1 FROM eb_store_order scope_o '
                    .'WHERE scope_o.order_id=p.order_id AND scope_o.store_id=p.store_id '
                    .'AND scope_o.uid=p.member_id AND scope_o.order_type=0 AND scope_o.paid=1 '
                    .')')
                ->fieldRaw('1 marker')->limit(1)->select()->toArray();
            if($unknown!==[])$gaps[]=['code'=>'CROSS_STORED_VALUE_OUTSIDE_DIRECTION_UNVERIFIED',
                'message'=>'外店存在未冻结对手店的储值交易，无法认证是否由本店承担结算；不读取其金额或会员。'];
        }
        return ['rows'=>$rows,'data_gaps'=>$gaps];
    }

    /**
     * Imported original wallet debits use balance_change_type=order_pay.
     * Resolve both direction and legacy eligibility through an exact V3
     * sales-order source identity; old order time never determines the period.
     */
    private function orderPayPairs(array $stores,array $range):array
    {
        $enabled=array_map('intval',Db::name('system_store')->where('is_del',0)
            ->where('name','<>','总部')->where('salary_status',1)->column('id'));
        if($enabled===[])return ['rows'=>[],'data_gaps'=>[]];
        $query=Db::name('cashier_v3_balance_fact')->alias('p')
            ->where('p.tenant_id','0')->whereIn('p.store_id',$enabled)
            ->whereBetween('p.business_date',[$range['start'],$range['end']])
            ->where('p.balance_change_type','order_pay')
            ->where('p.status','effective')->where('p.fact_direction','forward');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query,'p.tenant_id','p.order_id');
        $outsideAudit=clone $query;
        $visibleSql=implode(',',array_map('intval',$stores));
        // 导入原单仅提取本店执行或明确由本店承担来源的事实。
        $sourceSql='SELECT 1 FROM eb_cashier_v3_sales_order scope_s '
            .'JOIN eb_store_order scope_o ON scope_o.id=scope_s.source_document_id '
            .'WHERE scope_s.tenant_id=p.tenant_id AND scope_s.order_id=p.order_id '
            ."AND scope_s.source_document_type='legacy_store_order' "
            ."AND p.source_document_type='legacy_store_order' "
            ."AND scope_s.store_id=p.store_id AND scope_s.member_id=p.member_id "
            ."AND scope_o.store_id=p.store_id AND scope_o.uid=p.member_id "
            ."AND scope_o.order_id=scope_s.source_document_no_snapshot "
            ."AND scope_o.order_type=0 AND scope_o.paid=1 ";
        $query->whereRaw('(p.store_id IN ('.$visibleSql.') OR EXISTS ('.$sourceSql
            .'AND scope_o.kua_store IN ('.$visibleSql.')))');
        $facts=$query->field('p.fact_id,p.store_id,p.member_id,p.order_id,p.source_document_type,'
            .'p.principal_delta_cents,p.bonus_delta_cents')
            ->limit(10001)->select()->toArray();
        if(count($facts)>10000)throw new \RuntimeException('跨店原单余额事实超过10000行');
        $v3OrderIds=array_values(array_unique(array_filter(array_map('strval',array_column($facts,'order_id')))));
        $orders=[];$headers=[];$oldIds=[];
        foreach(array_chunk($v3OrderIds,500) as $ids){
            foreach(Db::name('cashier_v3_sales_order')->where('tenant_id','0')
                ->where('source_document_type','legacy_store_order')->whereIn('order_id',$ids)
                ->field('id,source_document_type,source_document_id,source_document_no_snapshot,order_id,store_id,member_id')
                ->select()->toArray() as $row){
                $key=(string)$row['order_id'];
                if(array_key_exists($key,$headers))$headers[$key]=null;
                else $headers[$key]=$row;
                if(ctype_digit((string)$row['source_document_id']))$oldIds[(int)$row['source_document_id']]=true;
            }
        }
        foreach(array_chunk(array_keys($oldIds),500) as $ids){
            foreach(Db::name('store_order')->whereIn('id',$ids)
                ->field('id,order_id,uid,store_id,kua_store,order_type,paid')->select()->toArray() as $row)
                $orders[(int)$row['id']]=$row;
        }
        $result=$this->projectOrderPay($facts,$orders,$headers,$enabled,$stores);
        if(array_diff($enabled,$stores)!==[]){
            $unknown=$outsideAudit->whereNotIn('p.store_id',$stores)
                ->whereRaw('NOT EXISTS ('.$sourceSql.')')
                ->fieldRaw('1 marker')->limit(1)->select()->toArray();
            if($unknown!==[])$result['data_gaps'][]=[
                'code'=>'CROSS_ORDER_PAY_OUTSIDE_DIRECTION_UNVERIFIED',
                'message'=>'外店原单余额事实缺对手店身份，无法认证是否由本店承担结算；不读取其金额或会员。'];
        }
        return $result;
    }

    /** A signed debit is assigned to the using store and reversed at its verified source store. */
    private function projectOrderPay(array $facts,array $orders,array $headers,array $stores,array $visibleStores=[]):array
    {
        $allowed=array_fill_keys($stores,true);
        $visible=array_fill_keys($visibleStores?:$stores,true);$rows=[];$gaps=[];$seen=[];
        foreach($facts as $fact){
            $factId=(string)($fact['fact_id']??'');$target=(int)($fact['store_id']??0);
            if($factId===''||isset($seen[$factId])||!isset($allowed[$target]))
                throw new \LogicException('跨店原单余额身份或权限异常');
            $seen[$factId]=true;
            $header=$headers[(string)($fact['order_id']??'')]??null;
            $oldId=(string)($header['source_document_id']??'');
            $order=ctype_digit($oldId)?($orders[(int)$oldId]??null):null;
            if((string)($fact['source_document_type']??'')!=='legacy_store_order'
                ||!$order||!$header||(int)$order['paid']!==1||(int)$order['order_type']!==0
                ||(int)$order['store_id']!==$target||(int)$order['uid']!==(int)$fact['member_id']
                ||(string)$order['order_id']!==(string)$header['source_document_no_snapshot']
                ||(int)$header['store_id']!==$target
                ||(int)$header['member_id']!==(int)$fact['member_id']
                ||(string)$header['order_id']!==(string)$fact['order_id']){
                $gaps[]=['code'=>'CROSS_STORE_ORDER_PAY_SOURCE_UNVERIFIED','fact_id'=>$factId,
                    'message'=>'原单余额扣款缺少一对一的旧订单与 V3 来源身份，不能推算跨店方向。'];
                continue;
            }
            if(!array_key_exists('kua_store',$order)){
                $gaps[]=['code'=>'CROSS_STORE_ORDER_PAY_DIRECTION_MISSING','fact_id'=>$factId,
                    'message'=>'原订单没有冻结的跨店来源门店，不能推算结算方向。'];
                continue;
            }
            // 007 only admits kua_store>0. An existing original column with
            // NULL/0 is excluded by that eligibility rule, not an unknown
            // cross-store transaction; never infer today's member store.
            $origin=(int)$order['kua_store'];
            if($origin===0||$origin===$target)continue;
            if(!isset($allowed[$origin]))continue;
            if(!isset($visible[$origin])&&!isset($visible[$target]))continue;
            $principal=$this->integerCents($fact['principal_delta_cents']??null);
            $bonus=$this->integerCents($fact['bonus_delta_cents']??null);
            $amount=-($principal+$bonus);
            if($amount<0)throw new \LogicException('原单余额扣款正反方向异常');
            $key=$origin.':'.$target;
            if(!isset($rows[$key]))$rows[$key]=['row_id'=>'cross:'.$key,
                'source_store_id'=>$origin,'target_store_id'=>$target,
                '_stored_value_cents'=>0];
            $rows[$key]['_stored_value_cents']=$this->integerCents(
                $rows[$key]['_stored_value_cents']+$amount);
        }
        foreach($rows as &$row){
            $row['stored_value_payable']=$row['_stored_value_cents']/100;
            $row['stored_value_receivable']=$row['_stored_value_cents']/100;
            $row['card_payable']=0.0;$row['card_receivable']=0.0;
            unset($row['_stored_value_cents']);
        }
        unset($row);
        return ['rows'=>array_values($rows),'data_gaps'=>$gaps];
    }

    /** Money facts are integer cents; a decimal source must never be silently rounded. */
    private function integerCents($value):int
    {
        if(is_string($value)&&preg_match('/^-?(0|[1-9][0-9]*)$/D',$value))$value=(int)$value;
        if(!is_int($value)||abs($value)>9007199254740991)
            throw new \LogicException('跨店原单余额金额不是整数分');
        return $value;
    }

    /** 两端门店都须在已授权范围；列表与合计按同一完整来源投影。 */
    public function read(array $stores,array $range): array
    {
        $stores=$this->stores($stores);$range=$this->range($range);
        $dimensions=$stores===[]?[]:($this->storeReader)($stores);
        $covered=$stores===[]?true:(bool)($this->coverageReader)($stores,$range);
        // 来源覆盖被额外收紧时不读取局部配对。
        $pairs=$stores===[]?['rows'=>[],'data_gaps'=>[],'complete'=>true]
            :($covered?($this->pairReader)($stores,$range):['rows'=>[],'data_gaps'=>[],'complete'=>true]);
        return $this->project($dimensions,$pairs,$covered,$stores,$range);
    }

    /** 007 是使用店加、权益来源店减；任何来源缺口会使该期全部净额不可认证。 */
    private function project(array $dimensions,array $pairs,bool $covered,array $stores,array $range): array
    {
        if(count($dimensions)>10000||count($pairs['rows']??[])>10000)
            throw new \RuntimeException('跨店结算来源超过10000行');
        $allowed=array_fill_keys($stores,true);$names=[];
        foreach($dimensions as $dimension){
            $id=(int)($dimension['id']??0);$name=trim((string)($dimension['name']??''));
            if(!isset($allowed[$id])||isset($names[$id])||$name==='')
                throw new \LogicException('跨店结算门店维度无效或超出授权');
            $names[$id]=$name;
        }
        $gaps=array_values($pairs['data_gaps']??[]);
        if(!($pairs['complete']??false)&&$gaps===[])$gaps[]=[
            'code'=>'CROSS_STORE_PAIR_INCOMPLETE','message'=>'跨店原始配对事实不完整，不能计算净额。'];
        if(!$covered&&$names!==[])$gaps[]=[
            'code'=>'CROSS_STORE_SOURCE_COVERAGE_UNVERIFIED',
            'message'=>'跨店交易双向来源尚未通过本次覆盖认证。'];
        $totals=[];
        foreach($names as $id=>$unused)$totals[$id]=['stored_value'=>0,'card'=>0];
        foreach($pairs['rows']??[] as $pair){
            $origin=(int)($pair['source_store_id']??0);$target=(int)($pair['target_store_id']??0);
            if($origin===$target||(!isset($names[$origin])&&!isset($names[$target])))
                throw new \LogicException('跨店配对与授权门店无关');
            foreach(['stored_value'=>['stored_value_payable','stored_value_receivable'],
                'card'=>['card_payable','card_receivable']] as $pool=>[$payable,$receivable]){
                $debit=$this->cents($pair[$payable]??null);
                $credit=$this->cents($pair[$receivable]??null);
                if($debit!==$credit)throw new \LogicException('跨店配对两端金额不守恒');
                // 对手店只做身份校验；只向当前授权店投影其净应收或净应付。
                if(isset($totals[$origin]))$totals[$origin][$pool]-=$debit;
                if(isset($totals[$target]))$totals[$target][$pool]+=$credit;
            }
        }
        $complete=$gaps===[];$rows=[];
        ksort($names,SORT_NUMERIC);
        // The row set is incomplete when even one source snapshot is missing;
        // returning named stores with blank money would look like a valid
        // zero-activity report and would also leak into export.
        foreach($complete?$names:[] as $id=>$name){
            $stored=$complete?$totals[$id]['stored_value']/100:null;
            $card=$complete?$totals[$id]['card']/100:null;
            $rows[]=['row_id'=>'cross_store_settlement_007:'.$id,'store_id'=>$id,'store_name'=>$name,
                'stored_value_amount'=>$stored,'card_amount'=>$card,
                'total_amount'=>$complete?($totals[$id]['stored_value']+$totals[$id]['card'])/100:null];
        }
        return ['rows'=>$rows,'fields'=>$this->fields(),'field_sources'=>$this->sources(),
            'complete'=>$complete,'gaps'=>$gaps,
            'sum_fields'=>$complete?['stored_value_amount','card_amount','total_amount']:[],
            'metric_version'=>'cross-store-settlement-007-v3-candidate-1',
            'coverage_start'=>StoreUnifiedReportServices::COVERAGE_START,'data_as_of'=>date('c'),
            'scope'=>['store_ids'=>$stores,'date_range'=>$range],
            'fact_completeness'=>'仅在跨店来源店、使用店和原单金额快照全覆盖获认证后展示净额。',
            'limitations'=>['007 旧次卡结算取原订单 pay_price；现 V3 配对读取权益实际金额，未对账前不能宣称等价。']];
    }

    /** 输入已是统一财务服务返回的元金额；转回分检查两端守恒。 */
    private function cents($amount): int
    {
        if(!is_int($amount)&&!is_float($amount)&&!(is_string($amount)&&is_numeric($amount)))
            throw new \LogicException('跨店金额不是数值');
        $cents=round((float)$amount*100);
        if(!is_finite((float)$amount)||abs($cents)>9007199254740991
            ||abs((float)$amount*100-$cents)>0.000001)
            throw new \LogicException('跨店金额精度无效');
        return (int)$cents;
    }

    /** 不查库的列契约，供统一 Host 在查询前展示 007 列。 */
    public static function definitions(): array { return self::fields(); }

    public static function fields(): array
    {
        $names=['store_name'=>'门店','stored_value_amount'=>'储值对账金额',
            'card_amount'=>'次卡对账金额','total_amount'=>'合计'];$out=[];
        foreach($names as $key=>$label)$out[]=['key'=>$key,'label'=>$label,
            'type'=>$key==='store_name'?'text':'decimal','defaultVisible'=>true,
            'defaultQuick'=>$key==='store_name','width'=>160,'fixed'=>$key==='store_name'?'left':'',
            'money'=>$key!=='store_name','summable'=>$key!=='store_name'];
        $out[]=['key'=>'store_id','label'=>'门店ID','type'=>'integer','defaultVisible'=>false,
            'defaultQuick'=>false,'width'=>100,'fixed'=>'','money'=>false,'summable'=>false];
        return $out;
    }

    private function sources(): array
    {
        return ['store_name'=>'当前工资统计开启门店的名称。',
            'stored_value_amount'=>'通过认证的原单余额跨店扣款，使用店记正、来源店记负。',
            'card_amount'=>'通过认证的次卡跨店原单金额，使用店记正、来源店记负。',
            'total_amount'=>'同一门店储值与次卡对账金额逐分相加。',
            'store_id'=>'授权门店ID，供精确筛选和稳定行键使用。'];
    }

    private function stores(array $stores): array
    {
        $out=[];foreach($stores as $id){
            if((!is_int($id)&&!(is_string($id)&&ctype_digit($id)))||(int)$id<1)
                throw new \InvalidArgumentException('授权门店无效');
            $out[(int)$id]=(int)$id;
        }return array_values($out);
    }

    private function range(array $range): array
    {
        foreach(['start','end'] as $key){
            $value=$range[$key]??null;
            $day=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
            if(!$day||$day->format('Y-m-d')!==$value)throw new \InvalidArgumentException('日期无效');
        }
        if($range['start']>$range['end']||$range['start']<StoreUnifiedReportServices::COVERAGE_START)
            throw new \InvalidArgumentException('日期超出 V3 覆盖范围');
        return ['start'=>$range['start'],'end'=>$range['end']];
    }
}
