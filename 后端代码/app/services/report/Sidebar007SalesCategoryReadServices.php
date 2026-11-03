<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 按已核的原单收款和冻结的 007→V3 来源 ID 投影销售侧栏客源指标。
 * 任一原单缺身份、来源或已认证收款时，该店整组客源指标保留空白。
 */
final class Sidebar007SalesCategoryReadServices
{
    private $cashReader;
    private $orderReader;
    private $partnerOrderReader;
    private $historicalPartnerReader;

    /** 注入点仅供离线合同；正式路径始终从 V3 原单事实及人员分配读取。 */
    public function __construct(?callable $cashReader=null,?callable $orderReader=null,
        ?callable $partnerOrderReader=null,?callable $historicalPartnerReader=null)
    {
        $this->cashReader=$cashReader ?: static fn(array $ids,array $period):array=>(new Sidebar007OriginalCashReadServices())->read($ids,$period);
        $this->orderReader=$orderReader ?: [$this,'orders'];
        $this->partnerOrderReader=$partnerOrderReader ?: [$this,'partnerOrders'];
        $this->historicalPartnerReader=$historicalPartnerReader ?: ($partnerOrderReader ? static fn():array=>['complete'=>true,'rows'=>[],'gaps'=>[]]
            : static fn(array $stores,array $range):array=>(new HistoricalPartnerSales007ReadServices())->read($stores,$range));
    }

    /**
     * 返回销售数据 0..13 行与销售分析同源人数；售后、服务客及门店总业绩
     * 另有事实资格，不能由这些来源类别简单推成完整结果。
     */
    public function read(array $stores,array $range,array $mapping):array
    {
        if(($mapping['complete']??false)!==true || !is_array($mapping['by_v3_id']??null))
            return ['by_store'=>[], 'gaps'=>[['code'=>'SOURCE_007_MAPPING_UNAVAILABLE']], 'complete'=>false];
        $cash=($this->cashReader)($stores,$range);
        if(!is_array($cash)||!is_array($cash['by_store']??null)||!is_array($cash['by_order']??null))
            throw new \LogicException('分类指标原单收款结果无效');
        $orders=($this->orderReader)($stores,$range,$cash['by_order']);
        // Certified original sales/recharges are not V3 sales heads. Their old source identity
        // is translated only through the same executed 007 dictionary migration audit.
        $reverse=[];
        foreach($mapping['by_v3_id'] as $v3=>$old)$reverse[(int)$old][]=(int)$v3;
        foreach($cash['historical_orders']??[] as $historic){
            if(($historic['source_kind']??'')!=='historical_007_original_receipt')
                throw new \LogicException('分类历史收款来源无效');
            $mapped=$reverse[(int)($historic['legacy_source_id']??0)]??[];
            $historic['source_id']=count($mapped)===1?$mapped[0]:0;
            $orders[]=$historic;
        }
        $partnerRows=($this->partnerOrderReader)($stores,$range);
        $history=($this->historicalPartnerReader)($stores,$range);
        if(!($history['complete']??false))return ['by_store'=>[],'after_sales_entries'=>[],
            'complete'=>false,'gaps'=>$history['gaps']??[]];
        foreach($history['rows'] as $row)$partnerRows[]=$row+['unclassified_rows'=>0,'unsigned_reversal_rows'=>0];
        if(!is_array($orders)||!is_array($partnerRows)||count($orders)>50000||count($partnerRows)>50000)
            throw new \RuntimeException('分类指标订单结果超过安全范围');
        $allowed=array_fill_keys($stores,true);$orderMap=[];$partner=[];$unknownPartner=[];
        foreach($orders as $row){
            $id=(int)($row['store_id']??0);$order=(string)($row['order_id']??'');
            if(!isset($allowed[$id])||$order===''||isset($orderMap[$id][$order]))
                throw new \LogicException('分类指标订单越权或重复');
            $orderMap[$id][$order]=$row;
        }
        foreach($partnerRows as $row){
            $id=(int)($row['store_id']??0);$order=(string)($row['order_id']??'');
            if(!isset($allowed[$id]))throw new \LogicException('合作方订单越权');
            if($order===''||(int)($row['unclassified_rows']??0)>0
                ||(int)($row['unsigned_reversal_rows']??0)>0){
                $unknownPartner[$id]=true;
                if($order==='')continue;
            }
            if(!array_key_exists('partner_amount_cents',$row)|| (int)$row['partner_amount_cents']!==0)
                $partner[$id][$order]=true;
        }
        $byStore=[];$gaps=[];$afterEntries=[];
        foreach($stores as $id){
            if(($cash['by_store'][$id]['complete']??false)!==true){
                $gaps[]=['store_id'=>$id,'code'=>'SOURCE_007_ORIGINAL_CASH_UNVERIFIED'];continue;
            }
            $group=[];$unknown=isset($unknownPartner[$id]);$identityGaps=[];
            if($unknown)$identityGaps[]=['reason'=>'partner_allocation_identity'];
            $amountByOrder=$cash['by_order'][$id]??[];
            // 007 ordinary source populations are restricted to real cash_source IDs.
            // A certified old source=0 is outside that population, not a missing zero-cash
            // sale. Cash totals remain independent; partner orders retain their own rule.
            foreach($orderMap[$id]??[] as $orderId=>$row){
                if((($row['legacy_source_id']??null)===0||($row['old_card_entry_excluded']??false)===true)
                    &&!isset($partner[$id][(string)$orderId])){
                    unset($orderMap[$id][$orderId],$amountByOrder[$orderId]);
                }
            }

            // 无外部收款仍可能是纯余额或全额优惠的已结算销售。只有
            // 成功结账快照证明现金及其他收款均为零时才按零现金计客。
            foreach($orderMap[$id]??[] as $orderId=>$row)if(!array_key_exists((string)$orderId,$amountByOrder)){
                if(($row['zero_cash_certified']??false)===true)$amountByOrder[(string)$orderId]=0;
                else {$unknown=true;$identityGaps[]=['order_id'=>(string)$orderId,'reason'=>'missing_cash_or_certified_zero'];}
            }
            foreach($amountByOrder as $order=>$amount){
                $row=$orderMap[$id][(string)$order]??null;
                $old=$row===null?null:($mapping['by_v3_id'][(int)($row['source_id']??0)]??null);
                $date=(string)($row['business_date']??'');
                $member=(int)($row['member_id']??-1);
                $friend=$row['friend']??null;
                $mixed=($row['mixed_service_object']??false)===true;
                $split=$row['mixed_cash_split']??null;
                $splitKnown=is_array($split)&&count($split)===2
                    &&is_int($split['self']??null)&&is_int($split['friend']??null)
                    &&$split['self']>=0&&$split['friend']>=0
                    &&$split['self']+$split['friend']===$amount;
                if($row===null||!is_int($amount)||$amount<0||$old===null||$member<0
                    ||($row['cash_identity_verified']??false)!==true
                    ||($row['legacy_source_id']??$old)!==$old
                    // 混合新单只有零现金或“全现金且逐行销售事实同额”时可拆分；
                    // 部分现金无法证明两种服务对象各收多少，继续留缺口。
                    ||(!is_bool($friend)&&!($mixed&&($amount===0||$splitKnown)))
                    ||$date<$range['start']||$date>$range['end']){
                    $unknown=true;$identityGaps[]=['order_id'=>(string)$order,'reason'=>'order_source_or_cash_identity',
                        'order_present'=>$row!==null,'source_id'=>$row['source_id']??null,
                        'legacy_source_id'=>$row['legacy_source_id']??null,'mapped_source_id'=>$old,
                        'cash_identity_verified'=>$row['cash_identity_verified']??null,
                        'friend_identity_known'=>is_bool($friend),'mixed_service_object'=>$mixed,
                        'business_date'=>$date];continue;
                }
                foreach($mixed?[false,true]:[$friend] as $serviceFriend){
                    $group[]=['order_id'=>(string)$order.($mixed?($serviceFriend?':friend':':self'):''),
                        'source007_id'=>(int)$old,
                        'amount_cents'=>$mixed?($amount===0?0:$split[$serviceFriend?'friend':'self']):$amount,
                        'member_id'=>$member,'friend'=>$serviceFriend,'business_date'=>$date,
                        'partner'=>isset($partner[$id][(string)$order])];
                }
            }
            if($unknown){$gaps[]=['store_id'=>$id,'code'=>'SOURCE_007_ORDER_IDENTITY_UNVERIFIED',
                'source_count'=>count($identityGaps),'orders'=>array_slice($identityGaps,0,100)];continue;}
            $values=[];
            foreach([[0,1,2,3],[4,5,6,7],[8,9,10,11]] as $indexes){
                $sourceGroup=(int)($indexes[0]/4);
                foreach([0,1] as $isNew){
                    $filtered=[];
                    foreach($group as $order){
                        if($order['partner']||!$this->inSourceGroup($order['source007_id'],$sourceGroup))continue;
                        if($isNew===1 && ($order['member_id']===0||$order['amount_cents']<29800))continue;
                        if($isNew===0 && $order['member_id']>0&&$order['amount_cents']>=29800)continue;
                        $filtered[]=$order;
                    }
                    $base=$indexes[$isNew*2];
                    $values[$base]=$this->people($filtered);
                    $values[$base+1]=array_sum(array_column($filtered,'amount_cents'));
                }
            }
            $renewal=array_values(array_filter($group,static fn(array $order):bool=>
                !$order['partner']&&$order['source007_id']===6&&$order['amount_cents']>0));
            $values[12]=$this->people($renewal);
            $values[13]=array_sum(array_column($renewal,'amount_cents'));
            // 合作项目客数按本轮确认的合作方人员实配订单计算；同店同日
            // 会员去重，游客与朋友逐笔。只有订单/人员身份均完整才填数。
            $partnerSales=array_values(array_filter($group,static fn(array $order):bool=>
                $order['partner']));
            $values[16]=$this->people($partnerSales);
            // 007 售后业绩只计来源11、会员原销售的有效现金；服务
            // 子单只参与售后客数，不得把核销金额混进现金售后业绩。
            $afterSales=array_values(array_filter($group,static fn(array $order):bool=>
                !$order['partner']&&$order['member_id']>0
                &&$order['source007_id']===11&&$order['amount_cents']>0));
            $values[15]=array_sum(array_column($afterSales,'amount_cents'));
            $afterEntries[$id]=array_map(static fn(array $order):array=>[
                'store_id'=>$id,'business_date'=>$order['business_date'],
                'member_id'=>$order['member_id'],'friend'=>$order['friend'],
                'order_id'=>'sale:'.$order['order_id']],$afterSales);
            // 销售分析使用一组来源先去重，不能把不同来源类别的独立
            // 去重结果相加，否则同一会员同日跨渠道会重复计入。
            $analysisBase=array_values(array_filter($group,static fn(array $order):bool=>
                !$order['partner']&&!in_array($order['source007_id'],[6,7,11,12],true)));
            $new=array_values(array_filter($analysisBase,static fn(array $order):bool=>
                $order['member_id']>0&&$order['amount_cents']>=29800));
            $casual=array_values(array_filter($analysisBase,static fn(array $order):bool=>
                $order['member_id']===0||$order['amount_cents']<29800));
            $byStore[$id]=['sales_data'=>$values,'sales_analysis'=>[
                'new_customer_count'=>$this->people($new),
                'casual_customer_count'=>$this->people($casual)]];
        }
        return ['by_store'=>$byStore,'after_sales_entries'=>$afterEntries,
            'gaps'=>$gaps,'complete'=>$gaps===[],
            'source'=>'frozen_source_mapping_and_certified_v3_original_cash'];
    }

    /** 007 来源排除集合固定在原表逻辑，不能拿 V3 新字典同号类比。 */
    private function inSourceGroup(int $source,int $group):bool
    {
        if($group===0)return !in_array($source,[1,2,6,7,11,12],true);
        return $source===$group;
    }

    /** 会员同日去重；游客及朋友按每张原单计数。 */
    private function people(array $orders):int
    {
        $seen=[];$count=0;
        foreach($orders as $order){
            if($order['member_id']===0||$order['friend']){++$count;continue;}
            $key=$order['business_date'].'|'.$order['member_id'];
            if(!isset($seen[$key])){$seen[$key]=true;++$count;}
        }
        return $count;
    }

    /** 原销售头与充值头均使用业务发生时冻结的一级来源。 */
    private function orders(array $stores,array $range,array $cashByOrder):array
    {
        $saleIds=[];$rechargeIds=[];
        foreach($cashByOrder as $byOrder)foreach(array_keys($byOrder) as $order){
            if(strpos((string)$order,'RCH:')===0)$rechargeIds[]=(int)substr((string)$order,4);
            else $saleIds[]=(string)$order;
        }
        $allSale=Db::name('cashier_v3_sales_order')->where('tenant_id','0')
            ->whereIn('store_id',$stores)->whereBetween('business_date',[$range['start'],$range['end']])
            ->where('order_status','settled')->where('order_direction','forward')
            ->whereNotExists($this->reversalPredicate('sales',Db::name('cashier_v3_sales_order')->getTable().'.order_id',Db::name('cashier_v3_sales_order')->getTable().'.store_id'))
            ->field('order_id')->limit(50001)->select()->toArray();
        if(count($allSale)>50000)throw new \RuntimeException('客源原销售超过安全范围');
        foreach($allSale as $head)$saleIds[]=(string)$head['order_id'];
        $out=[];
        foreach(array_chunk(array_values(array_unique($saleIds)),500) as $ids){
            $headers=Db::name('cashier_v3_sales_order')->alias('h')
                ->leftJoin('cashier_v3_checkout_request q',
                    'q.tenant_id=h.tenant_id AND q.store_id=h.store_id AND q.request_id=h.checkout_request_id')
                ->where('h.tenant_id','0')
                ->whereIn('h.store_id',$stores)->whereIn('h.order_id',$ids)
                ->whereBetween('h.business_date',[$range['start'],$range['end']])
                ->where('h.order_status','settled')->where('h.order_direction','forward')
                ->whereNotExists($this->reversalPredicate('sales','h.order_id','h.store_id'))
                ->field('h.store_id,h.order_id,h.business_date,h.member_id,h.business_source_primary_id,h.source_document_type,h.source_document_id,h.sale_amount_cents,'
                    .'q.request_status,q.cash_performance_amount_cents,q.selected_payment_amount_cents,'
                    .'q.balance_deduction_amount_cents,q.debt_amount_cents,q.receivable_amount_cents,q.sales_amount_cents')
                ->select()->toArray();
            $friends=Db::name('cashier_v3_sales_order_line')->where('tenant_id','0')
                ->whereIn('store_id',$stores)->whereIn('order_id',$ids)
                ->where('line_status','settled')->where('line_direction','forward')
                ->fieldRaw("store_id,order_id,MAX(CASE WHEN LOWER(TRIM(service_object))='friend' THEN 1 ELSE 0 END) friend,MAX(CASE WHEN LOWER(TRIM(service_object))='self' THEN 1 ELSE 0 END) self_line")
                ->group('store_id,order_id')->select()->toArray();
            $friendMap=[];$mixedIds=[];
            foreach($friends as $friend){
                $isFriend=(int)$friend['friend']===1;
                $isSelf=(int)$friend['self_line']===1;
                // 旧销售单只有一个服务对象；新单允许自用和朋友项目并存。
                // 此处仅冻结两种对象均存在的证据，是否可拆分要等现金认证。
                $friendMap[(int)$friend['store_id'].'|'.$friend['order_id']]=[
                    'friend'=>$isFriend&&$isSelf?null:$isFriend,
                    'mixed'=>$isFriend&&$isSelf];
                if($isFriend&&$isSelf)$mixedIds[]=(string)$friend['order_id'];
            }
            // 只为混合对象的新单读取逐行销售事实。原收款覆盖整单销售额时，
            // 核对每行事实与结账行同额，才可将现金按本人/朋友精确拆分。
            $mixedSplit=$this->mixedFullCashSplits($stores,$mixedIds);
            $oldIds=[];
            foreach($headers as $head)if(($head['source_document_type']??'')==='legacy_store_order')
                $oldIds[]=(int)($head['source_document_id']??0);
            $oldOrders=[];
            foreach(array_chunk(array_values(array_unique($oldIds)),500) as $oldChunk){
                foreach(Db::name('store_order')->whereIn('store_id',$stores)->whereIn('id',$oldChunk)
                    ->field('id,store_id,cash_pay_price,paid,refund_status,order_type,source,cash_choose,pay_type')->select()->toArray() as $old)
                    $oldOrders[(int)$old['store_id'].'|'.$old['id']]=$old;
            }
            foreach($headers as $head){
                $key=(int)$head['store_id'].'|'.$head['order_id'];
                $isOld=($head['source_document_type']??'')==='legacy_store_order';
                $oldKey=(int)$head['store_id'].'|'.($head['source_document_id']??'');
                $old=$isOld?($oldOrders[$oldKey]??null):null;
                $oldCash=$old===null?null:$this->oldMoney($old['cash_pay_price']??null);
                $savedCash=$cashByOrder[(int)$head['store_id']][(string)$head['order_id']]??null;
                $zeroCash=$savedCash===null&&($head['request_status']??'')==='succeeded'
                    &&(int)($head['cash_performance_amount_cents']??-1)===0
                    &&(int)($head['selected_payment_amount_cents']??-1)===0
                    &&(int)($head['debt_amount_cents']??-1)===0
                    &&(int)($head['balance_deduction_amount_cents']??-1)===(int)($head['receivable_amount_cents']??-2)
                    &&(int)($head['sales_amount_cents']??-1)===(int)($head['sale_amount_cents']??-2);
                if($isOld)$zeroCash=$zeroCash&&$old!==null&&$oldCash===0
                    &&(string)($old['pay_type']??'')!=='combination'
                    &&!in_array((int)($old['cash_choose']??-1),[9,10],true);
                // 旧来源分客类用 cash_pay_price 门槛，故旧单还需确认
                // 该金额等于 V3 已保存原实收；不相等时不能用 pay_price 代替。
                $cashVerified=!$isOld||($old!==null&&(int)$old['paid']===1
                    &&(int)$old['refund_status']===0&&in_array((int)$old['order_type'],[0,1],true)
                    &&$oldCash===($savedCash??($zeroCash?0:null)));
                $split=$mixedSplit[$key]??null;
                if($split!==null&&($savedCash??0)!==(int)$head['sale_amount_cents'])$split=null;
                $out[]=['store_id'=>(int)$head['store_id'],'order_id'=>(string)$head['order_id'],
                    'business_date'=>(string)$head['business_date'],'member_id'=>(int)$head['member_id'],
                    'source_id'=>(int)$head['business_source_primary_id'],
                    'friend'=>$friendMap[$key]['friend']??null,
                    'mixed_service_object'=>$friendMap[$key]['mixed']??false,
                    'mixed_cash_split'=>$split,
                    'cash_identity_verified'=>$cashVerified,
                    'zero_cash_certified'=>$zeroCash,
                    'old_card_entry_excluded'=>$isOld&&$old!==null&&(string)$old['pay_type']!=='combination'&&(int)$old['cash_choose']===9,
                    'legacy_source_id'=>$isOld&&$old!==null?(int)$old['source']:null];
            }
        }
        foreach(array_chunk(array_values(array_unique($rechargeIds)),500) as $ids){
            $rows=Db::name('user_recharge')->whereIn('store_id',$stores)->whereIn('id',$ids)
                ->where('paid',1)->whereBetween('business_date',[$range['start'],$range['end']])
                ->whereNotExists($this->reversalPredicate('recharge',Db::name('user_recharge')->getTable().'.id',Db::name('user_recharge')->getTable().'.store_id'))
                ->field('id,store_id,uid,business_date,business_source_primary_id')->select()->toArray();
            foreach($rows as $row)$out[]=['store_id'=>(int)$row['store_id'],
                'order_id'=>'RCH:'.$row['id'],'business_date'=>(string)$row['business_date'],
                'member_id'=>(int)$row['uid'],'source_id'=>(int)$row['business_source_primary_id'],
                'friend'=>false,'cash_identity_verified'=>true];
        }
        $paidRecharge=Db::name('user_recharge')->whereIn('store_id',$stores)
            ->whereBetween('business_date',[$range['start'],$range['end']])->where('paid',1)
            ->whereNotExists($this->reversalPredicate('recharge',Db::name('user_recharge')->getTable().'.id',Db::name('user_recharge')->getTable().'.store_id'))
            ->field('store_id,id')->limit(50001)->select()->toArray();
        if(count($paidRecharge)>50000)throw new \RuntimeException('客源充值原单超过安全范围');
        foreach($paidRecharge as $recharge){
            $id=(int)$recharge['store_id'];$order='RCH:'.$recharge['id'];
            if(!array_key_exists($order,$cashByOrder[$id]??[]))
                $out[]=['store_id'=>$id,'order_id'=>$order,'cash_identity_verified'=>false];
        }
        return $out;
    }

    /**
     * 仅返回两种服务对象的逐行销售事实与结账行一一对应时的分额。
     * 调用方还要核对整单原收款等于销售额；部分现金不能按项目售价猜分摊。
     *
     * @param int[] $stores
     * @param string[] $orderIds
     * @return array<string,array{self:int,friend:int}>
     */
    private function mixedFullCashSplits(array $stores,array $orderIds):array
    {
        if(!$orderIds)return [];
        $lines=Db::name('cashier_v3_sales_order_line')->where('tenant_id','0')
            ->whereIn('store_id',$stores)->whereIn('order_id',$orderIds)
            ->where('line_status','settled')->where('line_direction','forward')
            ->field('store_id,order_id,order_line_id,service_object,sale_amount_cents')
            ->limit(50001)->select()->toArray();
        $facts=Db::name('cashier_v3_sale_fact')->where('tenant_id','0')
            ->whereIn('store_id',$stores)->whereIn('order_id',$orderIds)
            ->where('status','effective')->where('fact_direction','forward')
            ->where('fact_type','sale_completed')
            ->field('store_id,order_id,source_line_id,sale_amount_cents')
            ->limit(50001)->select()->toArray();
        if(count($lines)>50000||count($facts)>50000)
            throw new \RuntimeException('混合服务对象销售事实超过安全范围');
        $expected=[];$actual=[];$invalid=[];
        foreach($lines as $line){
            $key=(int)$line['store_id'].'|'.$line['order_id'];
            $lineId=(string)$line['order_line_id'];$object=(string)$line['service_object'];
            $amount=(string)$line['sale_amount_cents'];
            if($lineId===''||!in_array($object,['self','friend'],true)
                ||!ctype_digit($amount)||isset($expected[$key][$lineId])){
                $invalid[$key]=true;continue;
            }
            $expected[$key][$lineId]=['object'=>$object,'amount'=>(int)$amount];
        }
        foreach($facts as $fact){
            $key=(int)$fact['store_id'].'|'.$fact['order_id'];
            $lineId=(string)$fact['source_line_id'];$amount=(string)$fact['sale_amount_cents'];
            if($lineId===''||!ctype_digit($amount)||isset($actual[$key][$lineId])){
                $invalid[$key]=true;continue;
            }
            $actual[$key][$lineId]=(int)$amount;
        }
        $out=[];
        foreach($expected as $key=>$items){
            if(isset($invalid[$key])||count($items)!==count($actual[$key]??[]))continue;
            $split=['self'=>0,'friend'=>0];$valid=true;
            foreach($items as $lineId=>$item){
                if(!array_key_exists($lineId,$actual[$key])||$actual[$key][$lineId]!==$item['amount']){
                    $valid=false;break;
                }
                $split[$item['object']]+=$item['amount'];
            }
            if($valid)$out[$key]=$split;
        }
        return $out;
    }

    /** Cash and customer populations must exclude the same successful refund/void events.
     * Formal heads remain immutable settled records; their status alone cannot prove eligibility.
     */
    private function reversalPredicate(string $type,string $order,string $store):callable
    {
        return static function($query)use($type,$order,$store):void{
            $query->name('cashier_v3_order_lifecycle_operation')->alias('category_reversal')
                ->where('category_reversal.tenant_id','0')->where('category_reversal.source_type',$type)
                ->whereRaw('category_reversal.store_id='.$store)
                ->whereRaw('category_reversal.source_order_id='.$order)
                ->whereIn('category_reversal.operation_type',['refund','void'])->where('category_reversal.status','succeeded');
        };
    }

    /** 合作方订单只由成交时 partner 人员净实际分配证明，未知人员类型留缺口。 */
    private function partnerOrders(array $stores,array $range):array
    {
        // 旧导入可能有冻结人员类型但无当前档案版本；只接受独立认证表
        // 与完整事实指纹相符的来源证明，不能按当前人员或ID前缀放行。
        $unknown=ImportedPerformanceTypeProvenanceServices::unverifiedSql('pf');
        return Db::name('cashier_v3_performance_fact')->alias('pf')->where('tenant_id','0')
            ->whereIn('store_id',$stores)->whereBetween('business_date',[$range['start'],$range['end']])
            ->where('status','effective')->where('performance_type','sales_performance_allocated')
            ->fieldRaw("store_id,order_id,SUM(CASE WHEN employee_type_snapshot='partner' THEN amount_cents ELSE 0 END) partner_amount_cents,SUM(CASE WHEN employee_id>0 AND ".$unknown." THEN 1 ELSE 0 END) unclassified_rows,SUM(CASE WHEN employee_type_snapshot='partner' AND fact_direction='reversal' AND amount_cents>0 THEN 1 ELSE 0 END) unsigned_reversal_rows")
            ->group('store_id,order_id')->limit(50001)->select()->toArray();
    }

    /** 旧金额只核身份和门槛，不以旧金额填报表。 */
    private function oldMoney($value):?int
    {
        if(!preg_match('/^(\d{1,13})(?:\.(\d{1,2})(?:0{1,4})?)?$/D',(string)$value,$parts))
            return null;
        return (int)$parts[1]*100+(int)str_pad($parts[2]??'0',2,'0');
    }
}
