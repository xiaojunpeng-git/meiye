<?php
declare(strict_types=1);
namespace app\services\report;

use think\facade\Db;

/**
 * 员工统计表专用旧口径适配：只读统一销售/收款/人员事实，旧表仅提供客源及合作项目配置。
 * 不读取旧订单、不修改首次全款生命周期、工资或手机端新客；结果按百分之一客稳定分配。
 */
final class EmployeeSummaryNewCustomerReadServices
{
    private const LIMIT=10000;

    /** $sales由认证范围内既有净销售Reader提供，必须包含共同销售人，人员筛选在分配后生效。 */
    public function read(array $stores,array $range,array $sales):array
    {
        $sales=array_values(array_filter($sales,static function($p){return $p['performance_type']==='sales_performance_allocated';}));
        if(!$sales)return [];
        $orders=array_values(array_unique(array_column($sales,'order_id')));
        $saleRows=$this->bounded('cashier_v3_sale_fact',$stores,$orders,'fact_id,order_id,source_line_id,checkout_request_id,store_id,member_id,business_date,business_source_primary_id,business_source_primary_name_snapshot,source_type,item_id,sale_amount_cents,debt_amount_cents');
        // 部分迁入行未展开客源，但同一V3已结算原单头已保存冻结客源。
        // 仅补读同租户/店/单/会员/业务日的头快照，并以迁入审计核身份；
        // 不读当前旧订单source，不用名称或当前会员来源猜补历史事实。
        $sourceMapping=(new Sidebar007SourceMappingReadServices())->read();
        if(($sourceMapping['complete']??false)===true){
            $headers=$this->bounded('cashier_v3_sales_order',$stores,$orders,
                'order_id,store_id,member_id,business_date,business_source_primary_id,business_source_primary_name_snapshot,source_document_type',false);
            $headerMap=[];foreach($headers as$header)$headerMap[$header['store_id'].':'.$header['order_id']]=$header;
            foreach($saleRows as&$sale)$sale=self::withCertifiedOrderSource($sale,
                $headerMap[$sale['store_id'].':'.$sale['order_id']]??null,$sourceMapping);
            unset($sale);
        }
        $byOrder=[];foreach($saleRows as$s)if($s['business_date']>=$range['start']&&$s['business_date']<=$range['end'])$byOrder[$s['store_id'].':'.$s['order_id']][]=$s;
        $facts=$this->bounded('cashier_v3_performance_fact',$stores,$orders,'fact_id,order_id,store_id,source_document_type');
        $origins=array_column($facts,'source_document_type','fact_id');
        // 后续补交保留在现金业绩中，但不作为原销售客再次进入本表旧新客队列。
        $sales=array_values(array_filter($sales,static function($p)use($origins){return !in_array($origins[$p['fact_id']]??'', ['recharge','debt_repayment','recharge_debt_repayment'],true);}));
        $result=[];
        foreach($sales as$p){
            $key=$p['store_id'].':'.$p['employee_id'];
            $result[$key]=$result[$key]??['total_hundredths'=>0,'converted_hundredths'=>0,'gaps'=>[]];
            if(!isset($byOrder[$p['store_id'].':'.$p['order_id']])&&!in_array($origins[$p['fact_id']]??'', ['recharge','debt_repayment','recharge_debt_repayment'],true))
                $result[$key]['gaps'][]='销售人员分配缺少可核实的原销售事实，不能计算旧口径新客';
        }
        if(!$saleRows)return $result;
        $context=(new ReportServices())->buildReportSaleSourceContext();
        // 沿用旧报告维护的客源分类；新旧目录按迁移规则的唯一同名根节点核实，不固定010客源ID。
        $legacy=Db::name('cash_source')->field('id,name')->select()->toArray();$legacyNames=[];
        foreach($legacy as$source){$name=(string)$source['name'];if(isset($legacyNames[$name]))throw new \RuntimeException('原客源名称重复，不能确定员工新客口径');$legacyNames[$name]=(int)$source['id'];}
        $directory=Db::name('cashier_v3_business_source')->where('parent_id',0)->field('id,name')->select()->toArray();$sourceNames=array_column($directory,'name','id');
        $eligibleSourceIds=array_fill_keys(array_map('intval',$context['sourceAttr']),true);
        $excludedProducts=array_fill_keys(array_map('intval',$context['productIds']),true);
        $payments=$this->bounded('cashier_v3_payment_fact',$stores,$orders,'order_id,store_id,amount_cents,source_document_type');$cash=[];
        foreach($payments as$p)if(in_array($p['source_document_type'],['cashier_snapshot','cashier_workspace','sales_order','checkout','legacy_store_order'],true)){$key=$p['store_id'].':'.$p['order_id'];$cash[$key]=($cash[$key]??0)+(int)$p['amount_cents'];}
        $components=$this->bounded('cashier_v3_card_sale_item_allocation_fact',$stores,$orders,'order_id,store_id,sale_fact_id,component_product_id',false);
        $bySale=[];foreach($components as$c)$bySale[$c['sale_fact_id']][]=(int)$c['component_product_id'];
        $lines=$this->bounded('cashier_v3_sales_order_line',$stores,$orders,'order_id,store_id,checkout_line_id,order_line_id,service_object',false);$objects=[];
        foreach($lines as$l){$objects[$l['store_id'].':'.$l['order_id'].':'.$l['checkout_line_id']]=$l['service_object'];$objects[$l['store_id'].':'.$l['order_id'].':'.$l['order_line_id']]=$l['service_object'];}
        // 007原报表排除任何退款订单，和净现金业绩的退款日冲减是两种独立口径。
        $refunds=[];$refundRows=0;foreach(array_chunk($orders,300)as$chunk){$q=Db::name('cashier_v3_order_lifecycle_operation')->where('tenant_id','0')->whereIn('store_id',$stores)->whereIn('source_order_id',$chunk)->where('source_type','sales')->where('operation_type','refund')->where('status','succeeded');$part=$q->field('store_id,source_order_id')->limit(self::LIMIT-$refundRows+1)->select()->toArray();$refundRows+=count($part);if($refundRows>self::LIMIT)throw new \InvalidArgumentException('员工新客退款来源超过10000条，请缩小查询范围');foreach($part as$v)$refunds[$v['store_id'].':'.$v['source_order_id']]=true;}
        // 迁入卡退款以持卡ID作为操作对象；必须通过正式发行回执关联原销售，不能把持卡ID猜成订单ID。
        foreach(array_chunk($orders,300)as$chunk){
            $q=Db::name('cashier_v3_card_purchase_receipt')->alias('issued')
                ->join('cashier_v3_order_lifecycle_operation imported_refund',
                    'imported_refund.tenant_id=issued.tenant_id AND imported_refund.store_id=issued.store_id AND imported_refund.source_order_id=CAST(issued.card_holder_id AS CHAR)')
                ->where('issued.tenant_id','0')->whereIn('issued.store_id',$stores)->whereIn('issued.sales_order_id',$chunk)
                ->where('issued.status','completed')->where('imported_refund.source_type','imported_card')
                ->where('imported_refund.operation_type','refund')->where('imported_refund.status','succeeded');
            $part=$q->field('issued.store_id,issued.sales_order_id')->limit(self::LIMIT-$refundRows+1)->select()->toArray();
            $refundRows+=count($part);if($refundRows>self::LIMIT)throw new \InvalidArgumentException('员工新客退款来源超过10000条，请缩小查询范围');
            foreach($part as$v)$refunds[$v['store_id'].':'.$v['sales_order_id']]=true;
        }
        $eligible=[];
        foreach($byOrder as$key=>$items){
            if(isset($refunds[$key]))continue;
            $first=$items[0];$name=(string)$first['business_source_primary_name_snapshot'];$id=(int)$first['business_source_primary_id'];
            $gaps=[];$legacyId=$legacyNames[$name]??null;
            if($legacyId===null||($sourceNames[$id]??null)!==$name)$gaps[]='原销售客源不能与原统计客源唯一核实';
            elseif(!isset($eligibleSourceIds[$legacyId]))continue;
            $excluded=false;$amount=0;$debt=0;$itemSet=[];
            foreach($items as$item){
                foreach(['member_id','business_date','business_source_primary_id','business_source_primary_name_snapshot']as$field)
                    if($item[$field]!==$first[$field])$gaps[]='同一原销售的客户、业务日期或客源事实不一致';
                $amount+=(int)$item['sale_amount_cents'];$debt+=(int)$item['debt_amount_cents'];
                if($item['source_type']==='card'){
                    if(!isset($bySale[$item['fact_id']])&&$context['productIds'])$gaps[]='卡项缺少原内含项目事实，不能确定是否排除合作项目';
                    foreach($bySale[$item['fact_id']]??[]as$product)if(isset($excludedProducts[$product]))$excluded=true;
                }elseif(isset($excludedProducts[(int)$item['item_id']]))$excluded=true;
                $itemSet[$item['source_line_id']]=true;
            }
            if($excluded||($debt>0&&$debt===$amount))continue;
            $eligible[$key]=['store_id'=>(int)$first['store_id'],'member_id'=>(int)$first['member_id'],'day'=>$first['business_date'],
                'converted'=>(int)$first['member_id']>0&&($cash[$key]??0)>=29800,'gaps'=>array_values(array_unique($gaps)),'items'=>$itemSet];
        }
        $allocation=[];
        foreach($sales as$p){
            $key=$p['store_id'].':'.$p['order_id'];if(!isset($eligible[$key]))continue;$order=$eligible[$key];$person=$p['store_id'].':'.$p['employee_id'];
            if($order['gaps']){$result[$person]['gaps']=array_merge($result[$person]['gaps'],$order['gaps']);continue;}
            if(!isset($order['items'][$p['source_line_id']]))continue; // 补交分配不伪装成另一笔原成交。
            $object=$objects[$key.':'.$p['source_line_id']]??null;
            if($object===null){$result[$person]['gaps'][]='原销售行缺少服务对象，不能判断会员或朋友的去重规则';continue;}
            $allocation[]=['store_id'=>$order['store_id'],'employee_id'=>(int)$p['employee_id'],'member_id'=>$order['member_id'],'business_date'=>$order['day'],
                'guest_key'=>$p['fact_id'],'friend'=>in_array($object,['friend','朋友'],true),'converted'=>$order['converted']];
        }
        foreach(self::allocate($allocation)as$key=>$counts){$result[$key]['total_hundredths']=$counts['total_hundredths'];$result[$key]['converted_hundredths']=$counts['converted_hundredths'];}
        foreach($result as&$row)$row['gaps']=array_values(array_unique($row['gaps']));unset($row);
        return $result;
    }

    /** 有界同连接事实读取；不按员工预过滤、不读取期间外收款来重新落档。 */
    private function bounded(string $table,array $stores,array $orders,string $fields,bool $direction=true):array
    {
        $rows=[];
        foreach(array_chunk($orders,300)as$chunk){
            $q=Db::name($table)->where('tenant_id','0')->whereIn('store_id',$stores)->whereIn('order_id',$chunk);
            if($table==='cashier_v3_sales_order')$q->where('order_status','settled')->where('order_direction','forward');
            elseif($table==='cashier_v3_sales_order_line')$q->where('line_status','settled')->where('line_direction','forward');
            else{$q->where('status','effective');if($direction)$q->where('fact_direction','forward');}
            $part=$q->field($fields)->limit(self::LIMIT-count($rows)+1)->select()->toArray();$rows=array_merge($rows,$part);
            if(count($rows)>self::LIMIT)throw new \InvalidArgumentException('员工新客来源超过10000条，请缩小查询范围');
        }
        return $rows;
    }

    /** 只在原销售行完全缺客源时补读原单冻结快照；任何身份冲突保留原缺口。 */
    public static function withCertifiedOrderSource(array $sale,?array $header,array $mapping):array
    {
        if((int)($sale['business_source_primary_id']??0)!==0
            ||(string)($sale['business_source_primary_name_snapshot']??'')!==''
            ||$header===null||($mapping['complete']??false)!==true
            ||($header['source_document_type']??'')!=='legacy_store_order')return $sale;
        foreach(['order_id','store_id','member_id','business_date']as$key)
            if(!isset($sale[$key],$header[$key])||(string)$sale[$key]!== (string)$header[$key])return $sale;
        $id=(int)($header['business_source_primary_id']??0);
        $old=$mapping['by_v3_id'][$id]??null;
        $proof=$old===null?null:($mapping['by_007_id'][$old]??null);
        if($proof===null||(int)($proof['v3_id']??0)!==$id
            ||(string)($proof['name']??'')!==(string)($header['business_source_primary_name_snapshot']??''))return $sale;
        $sale['business_source_primary_id']=$id;
        $sale['business_source_primary_name_snapshot']=(string)$header['business_source_primary_name_snapshot'];
        return $sale;
    }

    /** 007独立计算两类客群：同店同日会员按员工ID排序均分，最后一人接尾差；游客/朋友每条人员记录一客。 */
    public static function allocate(array $facts):array
    {
        $groups=['total'=>[],'converted'=>[]];$out=[];
        foreach($facts as$f){
            $subject=$f['member_id']>0&&!$f['friend']?'member:'.$f['business_date'].':'.$f['member_id']:'guest:'.$f['guest_key'];
            $key=$f['store_id'].':'.$subject;$person=(int)$f['employee_id'];
            $groups['total'][$key]['store']=(int)$f['store_id'];$groups['total'][$key]['people'][$person]=true;
            if($f['converted']){$groups['converted'][$key]['store']=(int)$f['store_id'];$groups['converted'][$key]['people'][$person]=true;}
        }
        foreach($groups as$kind=>$cohorts)foreach($cohorts as$group){
            $people=array_keys($group['people']);sort($people,SORT_NUMERIC);$n=count($people);$base=intdiv(100,$n);
            foreach($people as$i=>$person){$key=$group['store'].':'.$person;$out[$key]=$out[$key]??['total_hundredths'=>0,'converted_hundredths'=>0];$out[$key][$kind.'_hundredths']+=$base+($i===$n-1?100-$base*$n:0);}
        }
        return $out;
    }

    /** 沿用007原公式的整数百分数；已知零分母为0，源缺失由调用方保留null，不冒充0。 */
    public static function rate(int $converted,int $total):int
    {
        return $total>0?(int)bcdiv(bcmul((string)$converted,'100',0),(string)$total,0):0;
    }
}
