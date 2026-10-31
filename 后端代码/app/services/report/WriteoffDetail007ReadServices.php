<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\settlement\CashierV3CheckoutCraftsmenSnapshot;
use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

/** 007 核销明细的 V3 与受控历史服务投影；来源明确区分，旧核销 ID 不伪装成 V3 服务 ID。 */
final class WriteoffDetail007ReadServices
{
    private $serviceReader;
    private $amountReader;
    private $sourceReader;
    private $coverageReader;
    private $saleProjectAmountReader;
    private $historicalEnabled;

    /** 测试可注入只读事实；生产入口仍只读已认证门店内的完成服务。 */
    public function __construct(?callable $serviceReader = null, ?callable $amountReader = null,
        ?callable $sourceReader = null, ?callable $coverageReader = null,
        ?callable $saleProjectAmountReader = null)
    {
        $this->historicalEnabled = $serviceReader === null;
        $this->serviceReader = $serviceReader ?: [$this, 'services'];
        $this->amountReader = $amountReader ?: static function (array $stores, array $range, int $employee): array {
            $reader = RegisteredMetricReadServices::withCurrentReportProductCategory();
            return $employee > 0
                ? $reader->categoryRowsForParticipant('consume_amount', '0', $stores, $range, [], [], $employee)
                : $reader->categoryRows('consume_amount', '0', $stores, $range);
        };
        $this->sourceReader = $sourceReader ?: [$this, 'sources'];
        $this->coverageReader = $coverageReader ?: [new ItemConsumption007SourceCoverageServices(),'read'];
        $this->saleProjectAmountReader=$saleProjectAmountReader
            ?: [new SaleProjectConsumption007AmountReadServices(),'read'];
    }

    /** 列表和导出应从同一完整结果分页；无可靠来源的字段保持 null 并标记 gap。 */
    public function read(array $stores, array $range, array $employeeIds = []): array
    {
        $stores = $this->stores($stores); $range = $this->range($range);
        $employee = $this->employee($employeeIds);
        if (!$stores) return $this->project([], [], [], $stores, $range);
        return Db::transaction(function () use ($stores,$range,$employee): array {
            $coverage=($this->coverageReader)($stores,$range);
            $historical=['services'=>[],'amounts'=>[],'sources'=>['phones'=>[],'store_names'=>[],'ordering_store_ids'=>[]],'gaps'=>[]];
            if($this->historicalEnabled){
                $adapter=new HistoricalCardService007ReadServices();$old=$adapter->readServices($stores,$range);
                if($old['complete']){
                    $coverage['gaps']=array_values(array_filter($coverage['gaps']??[],static function($g){return($g['code']??'')!=='LEGACY_WRITEOFF_V3_UNMAPPED';}));
                    $historical=$adapter->reportProjection($old,$employee);
                }
                $coverage['gaps']=array_merge($coverage['gaps']??[],$old['gaps'],$historical['gaps']);$coverage['complete']=!$coverage['gaps'];
            }
            if(!($coverage['complete']??false))return $this->failClosed($stores,$range,$coverage);
            $services = ($this->serviceReader)($stores,$range,$employee);
            $this->cap($services);
            $amounts = ($this->amountReader)($stores,$range,$employee);
            $this->cap($amounts);
            $immediate=($this->saleProjectAmountReader)($stores,$services,$amounts);
            $amounts=array_merge($amounts,$immediate['rows']);$this->cap($amounts);
            $sources = ($this->sourceReader)($stores,$services);
            // Same formatter and amount pairing serve V3 and explicitly labelled historical sources.
            $services=array_merge($services,$historical['services']);$amounts=array_merge($amounts,$historical['amounts']);
            foreach($historical['sources']as$key=>$entries)foreach($entries as$id=>$value)$sources[$key][$id]=$value;
            $result=$this->project($services,$amounts,$sources,$stores,$range);
            $result['gaps']=array_merge($result['gaps'],$immediate['gaps']);
            $result['complete']=$result['gaps']===[];
            $result['source_coverage']=$coverage;
            return $result;
        });
    }

    /** 旧有效核销无法逐 ID 映射时，明细和导出不展示不完整子集。 */
    private function failClosed(array $stores,array $range,array $coverage):array
    {
        $result=$this->project([],[],[],$stores,$range);
        $result['rows']=[];$result['sum_fields']=[];
        $result['gaps']=$coverage['gaps']??[['code'=>'LEGACY_WRITEOFF_SOURCE_UNVERIFIED',
            'message'=>'旧核销覆盖未认证。']];
        $result['complete']=false;$result['source_coverage']=$coverage;
        return $result;
    }

    /** 归期和展示日期统一取 V3 业务日；操作人及手艺人取不可变服务快照。 */
    private function services(array $stores,array $range,int $employee): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('s')
            ->where('s.tenant_id','0')->whereIn('s.store_id',$stores)
            ->whereBetween('s.business_date',[$range['start'],$range['end']])
            ->where('s.service_status','completed');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query,'s');
        if($employee>0)(new StoreReportParticipantScopeServices())->applyCheckout($query,'s.checkout_request_id',$employee);
        return $query->field('s.id,s.service_fact_id,s.store_id,s.store_name_snapshot,s.member_id,'
            .'s.member_name_snapshot,s.document_no_snapshot,s.project_id,s.project_name_snapshot,'
            .'s.project_category_name_snapshot,s.quantity,s.operator_name_snapshot,s.business_date,'
            .'s.checkout_request_id,s.source_line_id,s.document_id,s.source_document_type,s.source_document_id,'
            .'s.craftsmen_snapshot_json')->limit(10001)->select()->toArray();
    }

    /**
     * 手机号只读当前档案。下单门店按来源类型核原单身份：旧服务的
     * source_document_id 是 writeoff.id，绝不能当作 V3 销售单数字 ID。
     */
    private function sources(array $stores,array $services): array
    {
        $memberIds=array_values(array_unique(array_filter(array_map('intval',array_column($services,'member_id')))));
        $phones=[];$names=[];$ordering=[];$oldIds=[];$formalIds=[];$directServices=[];
        foreach($services as $service){
            $type=(string)($service['source_document_type']??'');
            $id=(int)($service['source_document_id']??0);
            if(in_array($type,['direct','reservation'],true))$directServices[json_encode([(int)$service['store_id'],
                (string)$service['checkout_request_id'],(string)$service['source_line_id']])]=$service;
            if($id<=0)continue;
            if(in_array($type,['legacy_writeoff','legacy_paid_project'],true))$oldIds[$id]=true;
            elseif($type==='sales_order')$formalIds[$id]=true;
        }
        foreach(array_chunk($memberIds,500) as $ids){
            $rows=Db::name('user')->whereIn('uid',$ids)->field('uid,phone')->select()->toArray();
            foreach($rows as $row)$phones[(int)$row['uid']]=(string)$row['phone'];
        }
        $formal=[];
        foreach(array_chunk(array_keys($formalIds),500) as $ids){
            $rows=Db::name('cashier_v3_sales_order')->where('tenant_id','0')->whereIn('id',$ids)
                ->field('id,store_id,member_id')->select()->toArray();
            foreach($rows as $row)$formal[(int)$row['id']]=$row;
        }
        $old=[];$orders=[];$imported=[];
        foreach(array_chunk(array_keys($oldIds),500) as $ids){
            $rows=Db::name('store_order_writeoff')->whereIn('id',$ids)
                ->field('id,oid,uid,relation_id,product_id,status')->select()->toArray();
            foreach($rows as $row)$old[(int)$row['id']]=$row;
        }
        // Direct and reservation redemption carry the purchased entitlement's original
        // order primary key. Read only those scoped writeoff facts; do not
        // infer the ordering store from today's member ownership.
        $directWriteoffs=[];$directOriginIds=[];$holderIds=[];
        $directRequests=array_values(array_unique(array_filter(array_map('strval',
            array_column($directServices,'checkout_request_id')))));
        foreach(array_chunk($directRequests,500) as $ids){
            $rows=Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id','0')
                ->whereIn('store_id',$stores)->whereIn('checkout_request_id',$ids)
                ->where('status','effective')
                ->field('writeoff_id,store_id,member_id,checkout_request_id,source_line_id,origin_order_id,'
                    .'project_id,quantity,entitlement_instance_type,holder_id,is_gift')
                ->limit(10001)->select()->toArray();
            $this->cap($rows);
            foreach($rows as $row){
                $key=json_encode([(int)$row['store_id'],(string)$row['checkout_request_id'],
                    (string)$row['source_line_id']]);
                if(!isset($directServices[$key]))continue;
                if(array_key_exists($key,$directWriteoffs))$directWriteoffs[$key]=null;
                else $directWriteoffs[$key]=$row;
                $origin=(string)$row['origin_order_id'];
                if(ctype_digit($origin)&&(int)$origin>0)$directOriginIds[(int)$origin]=true;
                if((int)$row['holder_id']>0)$holderIds[(int)$row['holder_id']]=true;
            }
        }
        $holders=[];
        foreach(array_chunk(array_keys($holderIds),500) as $ids){
            foreach(Db::name('user_card_holder')->whereIn('id',$ids)
                ->field('id,oid,uid')->select()->toArray() as $row)$holders[(int)$row['id']]=$row;
        }
        $originIds=array_values(array_unique(array_merge(
            array_filter(array_map('intval',array_column($old,'oid'))),array_keys($directOriginIds))));
        foreach(array_chunk($originIds,500) as $ids){
            $rows=Db::name('store_order')->whereIn('id',$ids)
                ->field('id,uid,store_id,paid,order_type')->select()->toArray();
            foreach($rows as $row)$orders[(int)$row['id']]=$row;
            $headers=Db::name('cashier_v3_sales_order')->where('tenant_id','0')
                ->where('source_document_type','legacy_store_order')
                ->whereIn('source_document_id',array_map('strval',$ids))
                ->field('id,order_id,source_document_id,store_id,member_id')->select()->toArray();
            foreach($headers as $row){
                $key=(int)$row['source_document_id'];
                if(isset($imported[$key]))$imported[$key]=null;
                else $imported[$key]=$row;
            }
        }
        foreach($services as $service){
            $factId=(string)($service['service_fact_id']??'');
            $sourceId=(int)($service['source_document_id']??0);
            $type=(string)($service['source_document_type']??'');
            $member=(int)($service['member_id']??0);
            if($type==='sales_order'){
                $header=$formal[$sourceId]??null;
                if($header&&(int)$header['member_id']===$member)$ordering[$factId]=(int)$header['store_id'];
                continue;
            }
            if(in_array($type,['direct','reservation'],true)){
                $key=json_encode([(int)$service['store_id'],(string)$service['checkout_request_id'],
                    (string)$service['source_line_id']]);
                $writeoff=$directWriteoffs[$key]??null;
                $origin=$writeoff?(string)$writeoff['origin_order_id']:'';
                $order=ctype_digit($origin)?($orders[(int)$origin]??null):null;
                if(!$writeoff||!$order||(int)$writeoff['store_id']!==(int)$service['store_id']
                    ||(int)$writeoff['member_id']!==$member
                    ||(int)$writeoff['project_id']!==(int)$service['project_id']
                    ||!is_numeric($writeoff['quantity'])||!is_numeric($service['quantity'])
                    ||bccomp((string)$writeoff['quantity'],(string)$service['quantity'],6)!==0
                    ||(int)$order['uid']!==$member||(int)$order['paid']!==1
                    ||(int)$order['order_type']!==0)continue;
                if((string)$writeoff['entitlement_instance_type']==='card_holder'){
                    $holder=$holders[(int)$writeoff['holder_id']]??null;
                    if(!$holder||(int)$holder['uid']!==$member
                        ||(string)$holder['oid']!==$origin)continue;
                }
                $ordering[$factId]=(int)$order['store_id'];
                continue;
            }
            if(!in_array($type,['legacy_writeoff','legacy_paid_project'],true))continue;
            $writeoff=$old[$sourceId]??null;
            if(!$writeoff||(int)$writeoff['status']!==0
                ||(int)$writeoff['relation_id']!==(int)($service['store_id']??0)
                ||(int)$writeoff['product_id']!==(int)($service['project_id']??0)
                ||(int)$writeoff['uid']!==$member)continue;
            $orderId=(int)$writeoff['oid'];$order=$orders[$orderId]??null;$header=$imported[$orderId]??null;
            if(!$order||!$header||(int)$order['uid']!==$member
                ||(int)$header['member_id']!==$member
                ||(int)$header['store_id']!==(int)$order['store_id'])continue;
            if($type==='legacy_paid_project'
                &&(string)($service['document_id']??'')!==(string)$header['order_id'])continue;
            $ordering[$factId]=(int)$order['store_id'];
        }
        foreach(array_chunk(array_values(array_unique(array_values($ordering))),500) as $ids){
            $rows=Db::name('system_store')->whereIn('id',$ids)->field('id,name')->select()->toArray();
            foreach($rows as $row)$names[(int)$row['id']]=(string)$row['name'];
        }
        return ['phones'=>$phones,'ordering_store_ids'=>$ordering,'store_names'=>$names];
    }

    /** 按服务事实逐行显示；消费事实用结账键归属，人员快照绝不拿当前任职反推。 */
    private function project(array $services,array $amounts,array $sources,array $stores,array $range): array
    {
        $authorized=array_fill_keys($stores,true);$money=[];$amountIds=[];$gaps=[];$rows=[];$seen=[];
        foreach($amounts as $fact){
            $storeId=(int)($fact['store_id']??0);
            if(!isset($authorized[$storeId]))throw new \LogicException('核销金额越权');
            $factId=(string)($fact['fact_id']??'');
            if($factId===''||isset($amountIds[$factId]))throw new \LogicException('核销金额事实身份异常');
            $amountIds[$factId]=true;
            $key=json_encode([$storeId,(string)($fact['checkout_request_id']??''),(string)($fact['source_line_id']??'')]);
            $money[$key]=($money[$key]??0)+(int)($fact['amount_cents']??0);
        }
        foreach($services as $service){
            $id=(string)($service['service_fact_id']??'');$storeId=(int)($service['store_id']??0);
            if($id===''||isset($seen[$id])||!isset($authorized[$storeId]))throw new \LogicException('核销服务事实身份或权限异常');
            $seen[$id]=true;
            $line=json_encode([$storeId,(string)($service['checkout_request_id']??''),(string)($service['source_line_id']??'')]);
            $amount=array_key_exists($line,$money)?$money[$line]:null;
            if($amount===null)$gaps[]=['service_fact_id'=>$id,'code'=>'WRITEOFF_AMOUNT_MISSING',
                'message'=>'完成服务缺少对应消耗事实；核销金额不能显示为0。'];
            unset($money[$line]);
            $names=$this->craftsmanNames((string)($service['craftsmen_snapshot_json']??''),
                (string)($service['source_document_type']??''));
            if($names===null){$names=[];$gaps[]=['service_fact_id'=>$id,'code'=>'CRAFTSMEN_SNAPSHOT_INVALID',
                'message'=>'手艺人快照无法可靠解析。'];}
            $memberId=(int)($service['member_id']??0);
            // Guest service has no member archive by design; 007's LEFT JOIN
            // displays an empty phone, which is complete rather than missing.
            // A positive member ID without an archive remains a source gap.
            $phone=$memberId===0?'':($sources['phones'][$memberId]??null);
            if($phone===null)$gaps[]=['service_fact_id'=>$id,'code'=>'CURRENT_PHONE_UNAVAILABLE',
                'message'=>'会员当前手机号不可得；不以其他人的手机号填充。'];
            $orderStoreId=$sources['ordering_store_ids'][$id]??0;
            $orderingStore=$sources['store_names'][$orderStoreId]??null;
            if($orderingStore===null)$gaps[]=['service_fact_id'=>$id,'code'=>'ORDERING_STORE_UNAVAILABLE',
                'message'=>'无可靠原下单门店关联；不能用核销门店替代。'];
            $time=(string)($service['business_date']??'');
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$time)
                ||$time<$range['start']||$time>$range['end'])
                throw new \LogicException('核销服务业务日期缺失或超出查询期');
            if(!is_numeric($service['quantity']??null)|| (float)$service['quantity']<0)
                throw new \LogicException('核销数量无效');
            $rows[]=[
                'row_id'=>'writeoff_007:'.$id,
                'id'=>in_array((string)($service['source_document_type']??''),
                    ['legacy_writeoff','legacy_paid_project'],true)
                    ?(int)($service['source_document_id']??0):(int)($service['id']??0),
                'order_no'=>(string)($service['document_no_snapshot']??''),
                'member_name'=>(string)($service['member_name_snapshot']??''), 'phone'=>$phone,
                'category_name'=>(string)($service['project_category_name_snapshot']??''),
                'product_name'=>(string)($service['project_name_snapshot']??''),
                'craftsmen'=>implode('、',$names), 'writeoff_quantity'=>(float)$service['quantity'],
                'writeoff_amount'=>$amount===null?null:$amount/100,
                'ordering_store'=>$orderingStore,
                'writeoff_store'=>(string)($service['store_name_snapshot']??''),
                'writeoff_operator'=>(string)($service['operator_name_snapshot']??''),
                'writeoff_time'=>$time,'store_id'=>$storeId,'service_fact_id'=>$id,
            ];
        }
        // An amount fact without a completed service must not vanish while
        // the detail still claims full coverage. Preserve the unmatched key.
        foreach($money as $line=>$amountCents)$gaps[]=[
            'source_line_key'=>$line,'amount_cents'=>$amountCents,
            'code'=>'WRITEOFF_ORPHAN_AMOUNT',
            'message'=>'消费金额事实没有匹配的已完成核销服务，不能静默漏计。'];
        $this->cap($rows);
        usort($rows,static fn(array $a,array $b):int=>strcmp($b['writeoff_time']??'',$a['writeoff_time']??'')
            ?:strcmp($b['row_id'],$a['row_id']));
        return ['rows'=>$rows,'fields'=>$this->fields(),'field_sources'=>$this->fieldSources(),
            'complete'=>$gaps===[],'gaps'=>$gaps,'sum_fields'=>[],
            'metric_version'=>'writeoff-detail-007-v3-candidate-1',
            'coverage_start'=>StoreUnifiedReportServices::COVERAGE_START,'data_as_of'=>date('c'),
            'scope'=>['store_ids'=>$stores,'date_range'=>$range],
            'fact_completeness'=>'V3已完成且未作废服务；旧有效核销若缺原ID映射则整表拒绝展示。',
            'limitations'=>['旧核销 ID 沿用来源 writeoff.id，新服务显示 V3 服务事实 ID；订单号为服务单号快照。',
                '核销时间列按已确认的 V3 业务日期显示，不回拨旧事件时刻。',
                '手机号读取会员当前号码，非核销时历史快照；direct服务无可证实下单门店时显示空并标来源缺口。']];
    }

    /** 007 十三列纯静态契约，供统一入口提前展示。 */
    public static function definitions():array { return self::fields(); }

    public static function fields():array
    {
        $spec=[['id','ID','integer',false],['order_no','订单号','text',false],
            ['member_name','客户名称','text',false],['phone','手机号','text',false],
            ['category_name','商品分类','text',false],['product_name','商品名称','text',false],
            ['craftsmen','手艺人','text',false],['writeoff_quantity','核销数量','decimal',false],
            ['writeoff_amount','核销金额','decimal',true],['ordering_store','下单门店','text',false],
            ['writeoff_store','核销门店','text',false],['writeoff_operator','核销人员','text',false],
            ['writeoff_time','核销时间','date',false]];
        $out=[];foreach($spec as [$key,$label,$type,$money])$out[]=['key'=>$key,'label'=>$label,'type'=>$type,
            'defaultVisible'=>true,'defaultQuick'=>in_array($key,['order_no','member_name','product_name'],true),
            'width'=>150,'fixed'=>$key==='id'?'left':'','money'=>$money,'summable'=>false];
        foreach(['store_id','service_fact_id'] as $key)$out[]=['key'=>$key,'label'=>$key,
            'type'=>$key==='store_id'?'integer':'text','defaultVisible'=>false,'defaultQuick'=>false,
            'width'=>100,'fixed'=>'','money'=>false,'summable'=>false];
        return $out;
    }

    private function fieldSources():array
    {
        return ['id'=>'旧核销沿用来源 writeoff.id，新服务使用 V3 服务事实数字 ID。','order_no'=>'服务单号快照。',
            'member_name'=>'服务发生时客户名称快照。','phone'=>'会员当前手机号，非历史快照。',
            'category_name'=>'服务发生时项目分类名称快照。','product_name'=>'服务发生时项目名称快照。',
            'craftsmen'=>'服务行冻结的手艺人姓名及点客标记，显示为“姓名(点/轮)”。',
            'writeoff_quantity'=>'完成服务事实的原数量。',
            'writeoff_amount'=>'权益核销取有效消费事实；整行即买即用项目核同一已结算销售行后取冻结行成交额，保留分精度。',
            'ordering_store'=>'旧核销按显式 writeoff.id 复核原订单与 V3 迁入销售单，再取原下单门店；新服务按正式原销售单。',
            'writeoff_store'=>'服务事实的执行门店快照。','writeoff_operator'=>'服务事实的操作人快照。',
            'writeoff_time'=>'服务事实的 V3 业务日期；实际事件时刻不改变报表归期或展示日。',
            'store_id'=>'执行门店ID，供后端授权。','service_fact_id'=>'不可变服务事实ID，供稳定行键。'];
    }

    /** 007 手艺人带点/轮标记；只用完成时冻结的人名与 is_point_customer，不查当前任职。 */
    private function craftsmanNames(string $json,string $sourceType=''):?array
    {
        // Reservation completion freezes a smaller personnel contract than
        // checkout. Read its own immutable name/point flag, never today's staff.
        $people=json_decode($json,true);
        $imported=in_array($sourceType,['legacy_writeoff','legacy_paid_project'],true)
            &&is_array($people)&&($people===[]||isset($people[0]['employeeId'],$people[0]['name']));
        if($sourceType==='reservation'||$imported){
            // The verified historical importer freezes employee IDs rather
            // than store-staff IDs. Preserve that distinct identity contract.
            $identity=$imported?'employeeId':'staffId';
            if(!is_array($people)||($people!==[]&&array_keys($people)!==range(0,count($people)-1))
                ||count($people)>20)return null;
            $names=[];$seen=[];
            foreach($people as $person){
                if(!is_array($person)||!isset($person[$identity],$person['name'])
                    ||!is_int($person[$identity])||$person[$identity]<=0
                    ||isset($seen[$person[$identity]])||!is_string($person['name'])
                    ||trim($person['name'])===''||!array_key_exists('isPointCustomer',$person)
                    ||!is_bool($person['isPointCustomer']))return null;
                $seen[$person[$identity]]=true;
                $names[]=trim($person['name']).($person['isPointCustomer']?'(点)':'(轮)');
            }
            return array_values(array_unique($names));
        }
        try {
            $people=CashierV3CheckoutCraftsmenSnapshot::decode($json);
            return array_values(array_unique(array_filter(array_map(
                static fn(array $person):string=>trim((string)($person['name']??''))
                    .(($person['isPointCustomer']??false)?'(点)':'(轮)'),$people))));
        } catch(\InvalidArgumentException $e) {
            $people=json_decode($json,true);
            if(json_last_error()!==JSON_ERROR_NONE||!is_array($people)||array_keys($people)!==range(0,count($people)-1)
                ||count($people)>20)return null;
            $names=[];
            foreach($people as $person){
                if(!is_array($person)||!isset($person['staff_id'],$person['staff_name_snapshot'])
                    ||!array_key_exists('is_point_customer',$person)
                    ||(int)$person['staff_id']<=0||trim((string)$person['staff_name_snapshot'])==='')return null;
                $point=$person['is_point_customer'];
                if(!in_array($point,[true,false,0,1,'0','1'],true))return null;
                $names[]=trim((string)$person['staff_name_snapshot']).($point?'(点)':'(轮)');
            }
            return array_values(array_unique($names));
        }
    }

    private function stores(array $stores):array
    {
        $out=[];foreach($stores as $id){if((!is_int($id)&&!(is_string($id)&&ctype_digit($id)))||(int)$id<=0)
            throw new \InvalidArgumentException('授权门店无效');$out[(int)$id]=(int)$id;}return array_values($out);
    }
    private function range(array $range):array
    {
        foreach(['start','end'] as $key){$value=$range[$key]??null;$date=is_string($value)
            ?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
            if(!$date||$date->format('Y-m-d')!==$value)throw new \InvalidArgumentException('日期须为有效YYYY-MM-DD');}
        if($range['start']>$range['end']||$range['start']<StoreUnifiedReportServices::COVERAGE_START)
            throw new \InvalidArgumentException('日期范围无效或早于V3覆盖期');
        return ['start'=>$range['start'],'end'=>$range['end']];
    }
    private function employee(array $ids):int
    {
        if($ids===[])return 0;
        if(count($ids)!==1||(!is_int($ids[0])&&!(is_string($ids[0])&&ctype_digit($ids[0])))||(int)$ids[0]<=0)
            throw new \InvalidArgumentException('本人参与权限身份无效');return (int)$ids[0];
    }
    private function cap(array $rows):void
    {
        if(count($rows)>10000)throw new \InvalidArgumentException('基础报表源数据超过10000行，请缩小范围');
    }
}
