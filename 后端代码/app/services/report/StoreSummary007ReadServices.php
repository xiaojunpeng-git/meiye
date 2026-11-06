<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/** 007 门店统计表的独立候选；未认证的旧指标不得借用同名 V3 指标填数。 */
final class StoreSummary007ReadServices
{
    private $storeReader;
    private $certifiedCashReader;
    private $partnerFactQuery;
    private $originalPaymentReader;
    private $originalLegacyReader;
    private $newCustomerReader;
    private $serviceOrderReader;
    private $soldProjectReader;
    private $designatedReader;
    private $partnerServiceReader;

    /** 门店范围由 Host 重认证；注入门店维度只用于隔离合同测试。 */
    public function __construct(?callable $storeReader = null, ?callable $certifiedCashReader = null,
        ?callable $partnerFactQuery = null,
        ?callable $originalPaymentReader = null, ?callable $originalLegacyReader = null,
        ?callable $newCustomerReader = null,
        ?callable $serviceOrderReader = null,?callable $soldProjectReader = null,
        ?callable $designatedReader = null,?callable $partnerServiceReader = null)
    {
        $this->storeReader = $storeReader ?: static function (array $stores): array {
            return Db::name('system_store')->whereIn('id', $stores)->where('salary_status', 1)
                ->field('id,name')->limit(10001)->select()->toArray();
        };
        $this->certifiedCashReader = $certifiedCashReader;
        $this->partnerFactQuery = $partnerFactQuery;
        $this->originalPaymentReader = $originalPaymentReader;
        $this->originalLegacyReader = $originalLegacyReader;
        $this->newCustomerReader = $newCustomerReader;
        $this->serviceOrderReader = $serviceOrderReader;
        $this->soldProjectReader = $soldProjectReader;
        $this->designatedReader = $designatedReader;
        $this->partnerServiceReader = $partnerServiceReader;
    }

    /** 007 逐门店列出当前开启工资统计的门店；业务指标没有同义事实时保持 null。 */
    public function read(array $stores, array $range): array
    {
        $stores = $this->stores($stores);
        $range = $this->range($range);
        $dimensions = $stores === [] ? [] : ($this->storeReader)($stores);
        // 维度 Reader 的结果先核授权，再访问任何事实表；测试或错误
        // 配置返回越权门店时，不能先读取合作方/收款事实再发现问题。
        $allowed=array_fill_keys($stores,true);$seen=[];
        foreach($dimensions as $dimension){
            $id=(int)($dimension['id']??0);
            if(!isset($allowed[$id])||isset($seen[$id]))
                throw new \LogicException('门店统计维度超出授权或重复');
            $seen[$id]=true;
        }
        $cash = [];
        $partner = null;
        $original = null;
        $newCustomers = null;
        $serviceOrders = null;
        $soldProjects = null;
        $designated = null;
        $insideConsumption = null;
        if ($stores !== [] && $dimensions !== []) {
            // 仅查询可见列所依赖的来源。员工诊断指标及核销执行店金额不属于
            // 本表口径，不能让其额外扫描或异常阻断门店统计；诊断仍由独立 Reader 提供。
            // 生产默认读取独立 V3 原单收款并逐店核旧订单覆盖；先前
            // 后端认证输入仅供已有合同调用，不能由客户端提交经营数字。
            $original=(new Sidebar007OriginalCashReadServices(
                $this->originalPaymentReader,$this->originalLegacyReader))->read($stores,$range);
            foreach($original['by_store'] as $id=>$item) if($item['complete'])
                $cash[(int)$id]=$item['amount_cents'];
            if ($this->certifiedCashReader !== null) {
                $cash=$this->certifiedCash(($this->certifiedCashReader)($stores,$range),$stores);
            }
            $partner = (new Sidebar007PartnerAllocationReadServices())->read($stores,$range,$this->partnerFactQuery);
            // 本表新客数与销售分析共用 007 同一个 sourceOrder 来源集合；
            // 映射与原单资格由同一 Reader 完成，不能另算一个近似人数。
            $newCustomers=$this->newCustomerReader
                ?($this->newCustomerReader)($stores,$range,$original)
                :(new Sidebar007SalesCategoryReadServices(static fn():array=>$original))
                    ->read($stores,$range,(new Sidebar007SourceMappingReadServices())->read());
            if(!is_array($newCustomers)||!is_array($newCustomers['by_store']??null))
                throw new \LogicException('门店统计新客来源结果无效');
            // 007 消耗业绩和总客数按有效服务子单归属店汇总；逐核销执行店
            // 聚合仅供对账，跨店和同核销多子单时不得直接填本表。
            $serviceOrders=$this->serviceOrderReader
                ?($this->serviceOrderReader)($stores,$range)
                :(new Sidebar007ServiceOrderReadServices())->read($stores,$range);
            if(!is_array($serviceOrders)||!is_array($serviceOrders['by_store']??null)
                ||!is_bool($serviceOrders['complete']??null))
                throw new \LogicException('门店统计服务子单来源无效');
            $soldProjects=$this->soldProjectReader
                ?($this->soldProjectReader)($stores,$range)
                :(new Sidebar007SoldProjectCountReadServices())->read($stores,$range);
            if(!is_array($soldProjects)||!is_array($soldProjects['by_store']??null)
                ||!is_bool($soldProjects['complete']??null))
                throw new \LogicException('门店统计销售项目来源无效');
            // 指定客与消耗共用本次已认证的服务集合，避免再次扫描全部服务。
            $designated=$this->designatedReader
                ?($this->designatedReader)($stores,$range)
                :(new Sidebar007DesignatedCustomerReadServices(
                    static fn():array=>$serviceOrders))->read($stores,$range);
            if(!is_array($designated)||!is_array($designated['by_store']??null)
                ||!is_bool($designated['complete']??null))
                throw new \LogicException('门店统计指定客来源无效');
            $insideConsumption=$this->partnerServiceReader
                ?($this->partnerServiceReader)($stores,$range,$serviceOrders)
                :(new Sidebar007PartnerServiceAllocationReadServices())->read(
                    $stores,$range,$serviceOrders);
            if(!is_array($insideConsumption)
                ||!is_array($insideConsumption['by_store']??null)
                ||!is_bool($insideConsumption['complete']??null))
                throw new \LogicException('门店统计店内消耗来源无效');
        }
        return $this->project($dimensions, $stores, $range, $cash, $partner,
            $original,$newCustomers,$serviceOrders,$soldProjects,
            $designated,$insideConsumption);
    }

    /** 投影只负责字段与来源缺口，不从余额、员工份额或当前分类推算 007 口径。 */
    private function project(array $dimensions, array $stores, array $range, array $cash,
        ?array $partner, ?array $original,
        ?array $newCustomers,?array $serviceOrders,?array $soldProjects,
        ?array $designated,?array $insideConsumption): array
    {
        if (count($dimensions) > 10000) throw new \RuntimeException('门店统计来源超过10000行');
        $allowed = array_fill_keys($stores, true);
        $rows = []; $seen = [];
        foreach ($dimensions as $dimension) {
            $id = (int)($dimension['id'] ?? 0);
            if (!isset($allowed[$id]) || isset($seen[$id])) throw new \LogicException('门店统计维度超出授权或重复');
            $seen[$id] = true;
            $name = trim((string)($dimension['name'] ?? ''));
            if ($name === '') throw new \LogicException('门店统计维度缺少门店名');
            $row = ['row_id'=>'store_summary_007:'.$id, 'store_id'=>$id, 'store_name'=>$name];
            foreach (self::metricKeys() as $key) $row[$key] = null;
            if (isset($cash[$id])) {
                $row['cash_performance']=$cash[$id];
                $partnerAmount=$partner['by_store'][$id] ?? null;
                // 本张 007 对齐表经产品确认：实际业绩＝原单现金－业务时
                // partner 人员实配，不能借用全局“现金－退款”注册指标。
                if ($partnerAmount !== null) {
                    if (($partnerAmount < 0 && $cash[$id] > PHP_INT_MAX + $partnerAmount)
                        || ($partnerAmount > 0 && $cash[$id] < PHP_INT_MIN + $partnerAmount)) {
                        throw new \OverflowException('门店统计实际业绩整数溢出');
                    }
                    $row['actual_performance']=$cash[$id]-$partnerAmount;
                    // 产品对“合作方”已统一为成交时 partner 人员实配；
                    // 店内现金依同一原单现金减实配，不能退回旧商品分类扣减。
                    $row['inside_cash_performance']=$row['actual_performance'];
                }
            }
            // 项目消耗的执行店/核销粒度不能冒充本表服务订单的归属店/
            // 订单粒度；旧快照有跨店和重复链接，候选值只放诊断区。
            if($serviceOrders!==null && $serviceOrders['complete']){
                $pair=$serviceOrders['by_store'][$id]??null;
                if(!is_array($pair)||!is_int($pair['consumption_amount_cents']??null)
                    ||!is_int($pair['service_customer_count']??null)
                    ||$pair['consumption_amount_cents']<0||$pair['service_customer_count']<0)
                    throw new \LogicException('门店统计服务子单金额或客数缺失');
                $row['consumption_performance']=$pair['consumption_amount_cents'];
                $row['customer_count']=$pair['service_customer_count'];
                $projects=$pair['consumed_project_count']??null;
                if($projects!==null){
                    if(!is_int($projects)||$projects<0)
                        throw new \LogicException('门店统计消耗项目数无效');
                    $row['consumed_project_count']=$projects;
                }
                if(isset($cash[$id])){
                    // 007 配置公式最终转 int，金额先按整元入公式再求整百分点。
                    $cashYuan=intdiv($cash[$id],100);
                    $consumeYuan=intdiv($pair['consumption_amount_cents'],100);
                    $row['consumption_cash_ratio']=$cashYuan>0
                        ?intdiv($consumeYuan*100,$cashYuan):0;
                }
            }
            if($soldProjects!==null && $soldProjects['complete']){
                $sold=$soldProjects['by_store'][$id]??null;
                if(!is_int($sold)||$sold<0)
                    throw new \LogicException('门店统计销售项目数缺失');
                $row['sold_project_count']=$sold;
            }
            if($designated!==null && $designated['complete']){
                $point=$designated['by_store'][$id]??null;
                if(!is_int($point)||$point<0)
                    throw new \LogicException('门店统计指定客数缺失');
                $row['designated_customer_count']=$point;
            }
            if($insideConsumption!==null && $insideConsumption['complete']){
                $inside=$insideConsumption['by_store'][$id]??null;
                if(!is_int($inside))
                    throw new \LogicException('门店统计店内消耗业绩缺失');
                $row['inside_consumption_performance']=$inside;
            }
            $newBase=$newCustomers['by_store'][$id]['sales_analysis']??null;
            if(is_array($newBase)&&is_int($newBase['new_customer_count']??null)
                &&is_int($newBase['casual_customer_count']??null)){
                $new=$newBase['new_customer_count'];$casual=$newBase['casual_customer_count'];
                if($new<0||$casual<0)throw new \LogicException('门店统计新客人数无效');
                $total=$new+$casual;
                $row['new_customer_count']=$new;
                $row['new_customer_total_count']=$total;
                // 007 本表公式最终强制转 int：非负比例向下截取整百分点。
                $row['new_customer_conversion_rate']=$total>0?intdiv($new*100,$total):0;
            }
            // 共用报表 money 字段以元传递，来源及诊断保留整数分；在后端一次转换，
            // 让筛选、合计、页面和导出使用同一精确数值，禁止前端再各自除以100。
            foreach (self::definitions() as $field) {
                if ($field['money'] && $row[$field['key']] !== null)
                    $row[$field['key']] = bcdiv((string)$row[$field['key']], '100', 2);
            }
            $rows[] = $row;
        }
        usort($rows, static fn(array $a,array $b):int=>$a['store_id']<=>$b['store_id']);
        $unverified=[];
        foreach (self::metricKeys() as $key) foreach ($rows as $row) if ($row[$key]===null) {
            $unverified[]=$key; break;
        }
        $gaps = $rows === [] || $unverified === [] ? [] : [[
            'code'=>'STORE_SUMMARY_007_METRICS_UNVERIFIED',
            'message'=>'部分门店的原始事实或迁入覆盖尚未认证，对应单元格保持空白；实际业绩采用本表已确认的原单现金减合作方实配口径。',
            'affected_fields'=>$unverified,
        ]];
        if ($rows !== []) foreach ($unverified as $key) $gaps[] = [
            'code'=>'STORE_SUMMARY_007_MAPPING_PENDING', 'field'=>$key,
            'message'=>self::metricSourceNotes()[$key],
        ];
        $fields=self::definitions();
        foreach ($fields as &$field) if (in_array($field['key'],
            ['cash_performance','actual_performance','inside_cash_performance','consumption_performance',
                'inside_consumption_performance',
                'customer_count','designated_customer_count','new_customer_count','sold_project_count',
                'consumed_project_count'],true)) {
            $field['summable']=!in_array($field['key'],$unverified,true);
        }
        unset($field);
        return ['rows'=>$rows,'fields'=>$fields,'field_sources'=>$this->sources(),
            'complete'=>$unverified===[],'gaps'=>$gaps,
            'sum_fields'=>array_values(array_filter(['cash_performance','actual_performance',
                'inside_cash_performance',
                'consumption_performance','inside_consumption_performance',
                'customer_count','designated_customer_count',
                'new_customer_count',
                'sold_project_count','consumed_project_count'],
                static fn(string $key):bool=>!in_array($key,$unverified,true))),
            'metric_version'=>'store-summary-007-v3-3',
            'coverage_start'=>StoreUnifiedReportServices::COVERAGE_START,'data_as_of'=>date('c'),
            'scope'=>['store_ids'=>$stores,'date_range'=>$range],
            'fact_completeness'=>$unverified===[]
                ?'本次授权门店、日期和指标的来源已通过逐项覆盖校验。'
                :'未通过来源或迁入覆盖校验的指标保持空白，详情见缺口代码。',
            'partner_allocation'=>$partner,
            'original_cash'=>$original,
            'service_order_source'=>$serviceOrders,
            'sold_project_source'=>$soldProjects,
            'designated_source'=>$designated,
            'inside_consumption_source'=>$insideConsumption,
            'new_customer_source'=>$newCustomers,
            'limitations'=>['店内现金和店内消耗均按本轮合作方人员实配定义读取；同次服务或人员身份缺证据时对应列留空。',
                '合作方以业务发生时人员类型快照为 partner 的实际分配业绩为准，不沿用旧商品分类过滤。']];
    }

    /** 007 现场表头次序是唯一可见列契约。 */
    public static function definitions(): array
    {
        $names = ['store_name'=>'门店','cash_performance'=>'现金业绩','actual_performance'=>'实际业绩',
            'inside_cash_performance'=>'店内现金业绩','consumption_performance'=>'消耗业绩',
            'inside_consumption_performance'=>'店内消耗业绩','consumption_cash_ratio'=>'耗卡/现金',
            'customer_count'=>'总客数','designated_customer_count'=>'指定客','new_customer_count'=>'新客数',
            'sold_project_count'=>'销售项目数','consumed_project_count'=>'消耗项目数',
            'new_customer_conversion_rate'=>'新客成交率%','new_customer_total_count'=>'新客总客数'];
        $out = [];
        foreach ($names as $key=>$label) $out[] = ['key'=>$key,'label'=>$label,
            'type'=>$key==='store_name'?'text':'decimal','defaultVisible'=>true,
            'defaultQuick'=>$key==='store_name','width'=>150,'fixed'=>$key==='store_name'?'left':'',
            'money'=>in_array($key,['cash_performance','actual_performance','inside_cash_performance',
                'consumption_performance','inside_consumption_performance'],true),
            'summable'=>false];
        $out[] = ['key'=>'store_id','label'=>'门店ID','type'=>'integer','defaultVisible'=>false,
            'defaultQuick'=>false,'width'=>100,'fixed'=>'','money'=>false,'summable'=>false];
        return $out;
    }

    private function sources(): array
    {
        $out = ['store_name'=>'当前开启工资统计门店的名称；门店身份以 ID 固定。'];
        foreach (self::metricSourceNotes() as $key=>$note) $out[$key] = $note;
        $out['store_id'] = '授权门店 ID，供精确筛选和稳定行键使用。';
        return $out;
    }

    /** 区分“已有 V3 来源待映射”与“业务公式冲突”，避免把未扫描误写成缺事实。 */
    private static function metricSourceNotes(): array
    {
        return [
            'cash_performance'=>'统计期间内本店原销售与原充值的有效实收，扣除已退款或作废原单；不含后补款与旧卡录入。',
            'actual_performance'=>'本表按已确认口径，以现金业绩减去成交时合作方人员实际分配的业绩。',
            'inside_cash_performance'=>'本店原单现金业绩减去成交时合作方人员实际分配的业绩；与本表实际业绩同一已确认口径。',
            'consumption_performance'=>'统计期间内本店已完成且有效的项目服务消耗业绩。',
            'inside_consumption_performance'=>'本店有效服务子单消耗减去同次服务合作方人员实际分配的劳动业绩；跨店服务按原子单归属店。',
            'consumption_cash_ratio'=>'消耗业绩除以现金业绩后换算为百分比；基础金额未核准时不显示比率。',
            'customer_count'=>'统计期间内本店有效服务客数；同一会员同日按原表规则去重，朋友服务逐笔计算。',
            'designated_customer_count'=>'统计期间内本店选择指定服务人员的有效客户数，按客户和日期去重。',
            'new_customer_count'=>'统计期间内本店符合原单实收门槛的新客成交人数，按客户和日期去重。',
            'sold_project_count'=>'统计期间内本店有效原销售中的项目数量，仅计入原表规定的项目类型。',
            'consumed_project_count'=>'统计期间内本店已完成且有效的项目核销数量。',
            'new_customer_conversion_rate'=>'新客成交人数除以新客总人数后换算为百分比；基础人数未核准时不显示比率。',
            'new_customer_total_count'=>'统计期间内本店符合原表规则的散客人数与新客成交人数之和。',
        ];
    }

    private static function metricKeys(): array
    {
        return ['cash_performance','actual_performance','inside_cash_performance','consumption_performance',
            'inside_consumption_performance','consumption_cash_ratio','customer_count',
            'designated_customer_count','new_customer_count','sold_project_count','consumed_project_count',
            'new_customer_conversion_rate','new_customer_total_count'];
    }

    /**
     * 只有独立后端事实 Reader 明确声明 007 原单现金资格且完整时才可
     * 参与本表派生；现有注册 cash_performance 含充值/补交，不能冒名注入。
     */
    private function certifiedCash(array $facts, array $stores): array
    {
        $allowed=array_fill_keys($stores,true);$out=[];
        foreach ($facts as $fact) {
            $id=$fact['store_id']??null;$amount=$fact['amount_cents']??null;
            if (!is_int($id)||!isset($allowed[$id])||isset($out[$id])
                ||($fact['definition']??'')!=='007-original-order-cash-v3'
                ||($fact['complete']??false)!==true||!is_int($amount)||$amount<0) {
                throw new \LogicException('007 原单现金事实范围或认证信息无效');
            }
            $out[$id]=$amount;
        }
        return $out;
    }

    private function stores(array $stores): array
    {
        $out = [];
        foreach ($stores as $id) {
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int)$id < 1)
                throw new \InvalidArgumentException('授权门店无效');
            $out[(int)$id] = (int)$id;
        }
        return array_values($out);
    }

    private function range(array $range): array
    {
        foreach (['start','end'] as $key) {
            $value = $range[$key] ?? null;
            $day = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d',$value) : false;
            if (!$day || $day->format('Y-m-d') !== $value) throw new \InvalidArgumentException('报表日期无效');
        }
        if ($range['start'] > $range['end'] || $range['start'] < StoreUnifiedReportServices::COVERAGE_START)
            throw new \InvalidArgumentException('日期超出 V3 覆盖范围');
        return ['start'=>$range['start'],'end'=>$range['end']];
    }
}
