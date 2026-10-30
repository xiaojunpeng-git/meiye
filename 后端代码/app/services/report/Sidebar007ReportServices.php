<?php
declare(strict_types=1);
namespace app\services\report;

use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\StructuredExpressionValidator;
use app\services\query\StructuredExpressionEvaluator;
use app\services\report\query\BasicBusinessQueryProvider;
use app\services\report\query\BasicRegistrationQueryProvider;
use think\facade\Db;

/**
 * 四张侧栏表接入统一查询。门店列只能由后端授权集合生成，金额转换只发生在
 * 展示边界；指标纵行的金额、人数、比例不能跨行求和，导出沿用相同事实投影。
 */
final class Sidebar007ReportServices
{
    private const REPORTS = [
        'basic_sales_data' => 'sales_data', 'basic_sales_pk' => 'sales_pk',
        'basic_sales_analysis' => 'sales_analysis', 'basic_store_efficiency' => 'store_efficiency',
    ];
    private const PK_KEYS = ['store_name','goal_cents','bottom_cents','month_cash_cents',
        'sharing_cents','actual_cents','growth_cents','completion_percent'];
    private $source;
    private $storeReader;

    /** 注入仅用于离线合同；正式读取始终在授权范围内的一致事务进行。 */
    public function __construct(?callable $source = null, ?callable $storeReader = null)
    { $this->source = $source; $this->storeReader = $storeReader; }

    public static function codes(): array { return array_keys(self::REPORTS); }

    /** 目录不查询业务库，动态门店列在每次认证查询后再形成白名单。 */
    public function definitions(string $code): array
    {
        if (!isset(self::REPORTS[$code])) throw new \InvalidArgumentException('未配置该侧栏报表');
        if ($code !== 'basic_sales_pk') return [self::field('metric','项目','text',true)];
        $labels = Sidebar007ParityPlanServices::layout('sales_pk')['columns'];
        $fields = [];
        foreach (self::PK_KEYS as $i => $key) {
            $fields[] = self::field($key, $labels[$i], $i === 0 ? 'text' : 'decimal', $i === 0,
                $i > 0 && $i < 7 ? 'money_yuan' : ($i === 7 ? 'percent_integer' : 'text'));
        }
        return $fields;
    }

    /** 结构化条件在本次已授权动态列上校验；客户端不能注入基础指标、列或门店名。 */
    public function query(string $code, array $context, array $payload): array
    {
        if (!isset(self::REPORTS[$code])) throw new \InvalidArgumentException('未配置该侧栏报表');
        $work = function () use ($code, $context, $payload): array {
            $allowed = BasicRegistrationQueryProvider::stores($context);
            $range = BasicBusinessQueryProvider::dates($payload, (string)$context['query_cutoff_date']);
            if ($code === 'basic_sales_pk') {
                // 月表不允许用跨月日期冒充单月，始终从业务月首读到月末或今天。
                if (substr($range['start_date'],0,7) !== substr($range['end_date'],0,7))
                    throw new \InvalidArgumentException('PK 数据请选择同一个月份');
                $range['start_date'] = substr($range['start_date'],0,7) . '-01';
                $range['end_date'] = min((new \DateTimeImmutable($range['start_date']))->format('Y-m-t'), (string)$context['query_cutoff_date']);
            }
            if ($this->storeReader) $stores = ($this->storeReader)($allowed);
            else {
                // 007 三张矩阵排除名为总部的门店，PK仅排除删除门店；全部先与授权相交。
                $storeQuery = Db::name('system_store')->whereIn('id',$allowed)->where('is_del',0);
                if ($code !== 'basic_sales_pk') $storeQuery->where('name','<>','总部');
                $stores = $storeQuery->order('id','asc')->field('id,name')->select()->toArray();
            }
            $seen = [];
            foreach ($stores as &$store) {
                $id = (int)($store['id'] ?? 0);
                if (!in_array($id,$allowed,true) || isset($seen[$id]) || trim((string)($store['name']??'')) === '')
                    throw new \LogicException('报表门店列超出授权或身份重复');
                $store['id'] = $id; $seen[$id] = true;
            }
            unset($store);
            if (count($stores)>250) throw new \InvalidArgumentException('请选择不超过250家门店');
            $report = self::REPORTS[$code];
            if (!$stores) $source = ['rows'=>[], 'columns'=>[], 'gaps'=>[], 'complete'=>true];
            elseif ($this->source) $source = ($this->source)($report,$allowed,$stores,$range,$context);
            elseif ($report === 'sales_pk') $source = (new Sidebar007PkEfficiencyV3ReadServices())->readPk(
                $allowed,$stores,substr($range['start_date'],0,7),[],null,null,null,null,null,null,(string)$context['query_cutoff_date']);
            elseif ($report === 'store_efficiency') $source = (new Sidebar007PkEfficiencyV3ReadServices())->readEfficiency(
                $allowed,$stores,$range['start_date'],$range['end_date']);
            else $source = (new Sidebar007SalesV3ReadServices())->read($report,$allowed,$stores,$range['start_date'],$range['end_date']);
            return $this->project($code,$context,$payload,$range,$stores,$source);
        };
        return $this->source ? $work() : Db::transaction($work);
    }

    /** 验证每一行/列的稳定身份再展开，未认证的值保留null，不将缺口变成零。 */
    private function project(string $code,array $context,array $payload,array $range,array $stores,array $source): array
    {
        $pk = $code === 'basic_sales_pk';
        $fields = $this->definitions($code); $storeIds = array_column($stores,'id');
        if (!$pk) {
            $expected = array_map(static function($s){return 'store_'.$s['id'];},$stores);
            if (array_column($source['columns'],'key') !== $expected)
                throw new \LogicException('报表动态门店列与授权目录不一致');
            foreach ($stores as $store) $fields[] = self::field('store_'.$store['id'],(string)$store['name'],'decimal',false,'row');
        }
        $keys = array_column($fields,'key'); $rows=[]; $rowIds=[];
        foreach ($source['rows'] as $index=>$item) {
            $id=(string)($item['row_id']??'');
            if ($id===''||isset($rowIds[$id])) throw new \LogicException('报表行身份缺失或重复');
            $rowIds[$id]=true;
            if ($pk && !in_array((int)($item['store_id']??0),$storeIds,true)) throw new \LogicException('报表行超出授权范围');
            $cells=$item['cells']??[];
            $expected=$pk?$keys:array_slice($keys,1);
            if (array_diff(array_keys($cells),$expected)||array_diff($expected,array_keys($cells)))
                throw new \LogicException('报表单元格不属于已登记列');
            $row=['row_id'=>$id,'sort_order'=>$index];
            if (!$pk) {
                $row['metric']=(string)$item['metric'];
                $row['value_unit']=$item['unit']??'count';
                if (!in_array($row['value_unit'],['money_cents','count','percent_integer','ratio'],true))
                    throw new \LogicException('指标单位未登记');
            }
            foreach ($cells as $key=>$value) {
                $money=$pk?in_array($key,array_slice(self::PK_KEYS,1,6),true):$row['value_unit']==='money_cents';
                if ($key !== 'store_name' && $value !== null && !is_int($value) && !is_float($value))
                    throw new \LogicException('报表指标值格式异常');
                if ($money && $value !== null && !is_int($value)) throw new \LogicException('金额必须为分整数');
                $row[$key]=$money && $value!==null?self::yuan($value):$value;
            }
            // 前端根据后端明确的行单位展示；导出数值已在此统一换成元，不再计算指标。
            if (!$pk && $row['value_unit']==='money_cents') $row['value_unit']='money_yuan';
            $rows[]=$row;
        }
        $registry=new UnifiedQueryPageRegistry(); $definitions=[];
        foreach ($fields as $field) $definitions[]=UnifiedQueryPageRegistry::field($field['key'],$field['label'],$field['type'],true,$field['defaultQuick']);
        $definitions[]=UnifiedQueryPageRegistry::field('row_id','行编号','text');
        $definitions[]=UnifiedQueryPageRegistry::field('sort_order','显示顺序','integer');
        $registry->registerPage($code,BasicReportCatalogServices::definition($code)['name'],$definitions,'row_id',
            ['requiredFeature'=>'cashier.v3.data','keywordFields'=>[$pk?'store_name':'metric']]);
        $registry->freeze();
        $execution=new UnifiedQueryExecutionServices($registry,new StructuredExpressionValidator($registry),new StructuredExpressionEvaluator());
        $query=$payload;
        foreach (['pageCode','start_date','end_date','as_of_date'] as $key) unset($query[$key]);
        // 这些表的原页面无跨指标合计。拒绝自选汇总，避免钱、人数和百分比相加。
        if (!empty($query['summaries'])) throw new \InvalidArgumentException('本表不支持跨指标合计');
        $query['limit']=20;
        if (empty($query['sorts'])) $query['sorts']=[['field'=>'sort_order','direction'=>'asc']];
        $result=$this->execute($execution,$code,$rows,$query,$context);
        $guides=$this->guides($code,$fields);
        return array_merge($result,['queryFields'=>$fields,'field_sources'=>$guides,'can_edit'=>false,
            'table_layout'=>['fixed'=>true,'header_height'=>42,'summary_position'=>'none'],
            'complete'=>(bool)($source['complete']??false),'data_gaps'=>$source['gaps']??[],
            'row_sources'=>$source['row_sources']??[],
            'range'=>$range,'data_as_of'=>time(),'aggregation_caught_up'=>true,
            'metric_version'=>'007-parity-business-date-v1','source_kind'=>'unified_business_facts']);
    }

    /** 宽表导出复用执行器的字段校验与过滤，分块只解决100列限制，不扩大门店范围。 */
    private function execute(UnifiedQueryExecutionServices $execution,string $code,array $rows,array $query,array $context): array
    {
        $wanted=$query['export']['fields']??null;
        if (!is_array($wanted)||count($wanted)<=100) return $execution->execute($code,$rows,[],$query,$context,static function(){return true;});
        if (count($wanted)>251||array_filter($wanted,static function($key){return !is_string($key);}))
            throw new \InvalidArgumentException('导出字段无效');
        $identity=null;$merged=[];$result=[];
        foreach (array_chunk(array_values(array_unique($wanted)),99) as $chunk) {
            $part=$query; $part['export']['fields']=array_values(array_unique(array_merge(['row_id'],$chunk)));
            $result=$execution->execute($code,$rows,[],$part,$context,static function(){return true;});
            $ids=array_column($result['exportRows'],'row_id');
            if ($identity!==null&&$identity!==$ids) throw new \LogicException('导出行身份不一致');
            $identity=$ids;
            foreach ($result['exportRows'] as $i=>$record) $merged[$i]=array_replace($merged[$i]??[],$record);
        }
        $selected=array_fill_keys($wanted,true);
        $result['exportRows']=array_map(static function($row)use($selected){return array_intersect_key($row,$selected);},$merged);
        return $result;
    }

    private static function field(string $key,string $label,string $type,bool $fixed=false,string $unit='text'): array
    {
        return ['key'=>$key,'label'=>$label,'type'=>$type,'defaultVisible'=>true,
            'defaultQuick'=>$type==='text','width'=>$fixed?180:145,'fixed'=>$fixed?'left':'',
            'money'=>$unit==='money_yuan','summable'=>false,'value_unit'=>$unit];
    }

    /** 页面说明仅使用业务语言；源表名、迁移诊断与内部字段不作为用户帮助内容。 */
    private function guides(string $code,array $fields): array
    {
        $pk=['store_name'=>'当前查询权限内启用统计的门店。','goal_cents'=>'该门店所选月份保存的业绩目标。',
            'bottom_cents'=>'前六个月的现金业绩减合作方分配业绩，加已确认历史调整后计算月平均。',
            'month_cash_cents'=>'所选业务月内有效原销售和原充值的现金收款，不重复计入欠款后补收款。',
            'sharing_cents'=>'业务发生时人员类型为合作方的销售人实际分配业绩。',
            'actual_cents'=>'本月业绩减业绩分成款。','growth_cents'=>'本月实际分成减本月底标。',
            'completion_percent'=>'本月实际分成占本月目标的比例；目标为零时显示0%。'];
        $out=[];
        foreach ($fields as $field) $out[$field['key']]=$code==='basic_sales_pk'?$pk[$field['key']]
            :($field['key']==='metric'?'各行分别表示金额、客数或使用率，单位随指标显示。':'该门店在所选业务日期内、符合本行统计条件的有效业务；缺少计算依据时显示“-”。');
        return $out;
    }
    private static function yuan(int $cents): string
    { return ($cents<0?'-':'').intdiv(abs($cents),100).'.'.str_pad((string)(abs($cents)%100),2,'0',STR_PAD_LEFT); }
}
