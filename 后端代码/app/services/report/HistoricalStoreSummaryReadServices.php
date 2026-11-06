<?php
declare(strict_types=1);
namespace app\services\report;

use think\facade\Db;

/** 按已批准的历史期间选择本实例封存事实，未覆盖日期继续调用同一现行Reader。 */
final class HistoricalStoreSummaryReadServices
{
    private $batchReader;
    private $factReader;

    public function __construct(?callable $batchReader=null,?callable $factReader=null)
    {
        $this->batchReader=$batchReader?:static function(array $range):array{
            $table=(string)config('database.connections.mysql.prefix').'report_history_batch';
            if(Db::query('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table])===[])return [];
            // 不可把数据表错误当作无历史范围而静默退回另一个口径。
            return Db::name('report_history_batch')->where('report_code','basic_store_summary')
                ->where('status','active')->where('period_start','<=',$range['end'])
                ->where('period_end','>=',$range['start'])->order('period_start')->select()->toArray();
        };
        $this->factReader=$factReader?:static function(string $domain,array $batch,array $stores,array $range):array{
            if(!in_array($domain,HistoricalStoreSummaryFacts::DOMAINS,true))throw new \LogicException('历史事实域无效');
            if($stores===[])return [];
            return Db::name('report_history_'.$domain)->where('batch_id',$batch['batch_id'])
                ->whereIn('store_id',$stores)->whereBetween('business_date',[$range['start'],$range['end']])
                ->order('source_id')->select()->toArray();
        };
    }

    /** 期间按天不重叠分段；成员去重键本身含自然日，跨段合并不会重复计客。 */
    public function read(array $stores,array $range,array $dimensions,callable $current): array
    {
        $batches=($this->batchReader)($range);
        if($batches===[]||$dimensions===[])return $current($range);
        $parts=[];$cursor=$range['start'];$previousEnd=null;
        foreach($batches as $batch){
            // 即便表结构是DATE也校验认证边界，避免损坏或错误注入的批次隐式选中日期。
            foreach(['period_start','period_end'] as $key){$value=(string)($batch[$key]??'');
                $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
                if(!$day||$day->format('Y-m-d')!==$value)throw new \LogicException('历史批次日期无效');}
            if(($batch['policy']??'')!==HistoricalStoreSummaryFacts::POLICY
                ||!preg_match('/^[a-f0-9]{64}$/D',(string)($batch['payload_sha256']??''))
                ||!preg_match('/^[a-f0-9]{64}$/D',(string)($batch['source_archive_sha256']??''))
                ||($batch['period_start']??'')>($batch['period_end']??'')
                ||($previousEnd!==null&&$batch['period_start']<=$previousEnd))
                throw new \LogicException('历史报表期间认证无效或重叠');
            $previousEnd=$batch['period_end'];
            $start=max($range['start'],$batch['period_start']);$end=min($range['end'],$batch['period_end']);
            if($start>$end)throw new \LogicException('历史批次不在查询范围');
            if($cursor<$start)$parts[]=$current(['start'=>$cursor,'end'=>self::day($start,-1)]);
            $segment=['start'=>$start,'end'=>$end];$facts=[];
            $visibleStores=array_map('intval',array_column($dimensions,'id'));
            foreach(HistoricalStoreSummaryFacts::DOMAINS as $domain)$facts[$domain]=($this->factReader)($domain,$batch,$visibleStores,$segment);
            $parts[]=$this->project(HistoricalStoreSummaryFacts::aggregate($facts,$dimensions,$segment),$stores,$segment,$batch);
            $cursor=self::day($end,1);
        }
        if($cursor<=$range['end'])$parts[]=$current(['start'=>$cursor,'end'=>$range['end']]);
        if(count($parts)===1)return $parts[0];
        return $this->merge($parts,$stores,$range);
    }

    /** 历史口径在后端元数据中明示；不会把历史商品分类解释成现行人员分配。 */
    private function project(array $rows,array $stores,array $range,array $batch): array
    {
        $fields=StoreSummary007ReadServices::definitions();$sum=[];$sources=[];
        foreach($fields as &$field){$key=$field['key'];
            $field['summable']=!in_array($key,['store_id','store_name','consumption_cash_ratio','new_customer_conversion_rate'],true);
            if($field['summable'])$sum[]=$key;
            $sources[$key]='已核准历史期间的原始记录，按原单身份和原始日期汇总。';
        }unset($field);
        $sources=array_merge($sources,[
            'store_name'=>'当前授权范围内开启统计的门店，历史记录按门店ID对应。',
            'store_id'=>'授权门店ID。',
            'cash_performance'=>'历史销售、充值原单的有效现金收款，按原下单日期汇总后截取整元。',
            'actual_performance'=>'历史现金业绩减去原分成人员的销售分成金额。',
            'inside_cash_performance'=>'历史现金业绩扣除原合作类商品按应付金额占比分摊的收款；不是扣人员分配。',
            'consumption_performance'=>'有效历史服务子单金额，按原下单日期及归属门店汇总。',
            'inside_consumption_performance'=>'有效历史服务子单金额，排除原合作类商品关联的有效核销项目。',
            'customer_count'=>'历史服务子单：会员同店同日去重，游客及朋友服务逐单计数。',
            'designated_customer_count'=>'历史有效指定劳动业绩：会员同店同日去重，游客及朋友逐条分配记录计数。',
            'new_customer_count'=>'原来源范围内实收达到298元的会员，同店同日去重；朋友逐单计数，排除原合作人员参与的订单。',
            'new_customer_total_count'=>'历史新客成交人数加同口径未达门槛或游客人数，两类分别按原规则去重。',
            'sold_project_count'=>'有效原销售中规定项目类型的购物车项目数量之和，汇总后截取整数。',
            'consumed_project_count'=>'有效历史核销记录的项目数量之和，按核销时间和执行门店汇总后截取整数。',
            'consumption_cash_ratio'=>'历史消耗业绩除以现金业绩，换算百分比后截取整数。',
            'new_customer_conversion_rate'=>'历史新客成交人数除以新客总人数，换算百分比后截取整数。',
        ]);
        return ['rows'=>$rows,'fields'=>$fields,'field_sources'=>$sources,'sum_fields'=>$sum,'complete'=>true,
            'gaps'=>[],'metric_version'=>'store-summary-007-sealed-v1','coverage_start'=>$batch['period_start'],
            'data_as_of'=>date('c'),'scope'=>['store_ids'=>$stores,'date_range'=>$range],
            'fact_completeness'=>'已按封存原单、人员分配及项目明细核准历史统计。',
            'historical_source'=>['batch_id'=>$batch['batch_id'],'period_start'=>$batch['period_start'],
                'period_end'=>$batch['period_end'],'source_archive_sha256'=>$batch['source_archive_sha256'],
                'payload_sha256'=>$batch['payload_sha256']],
            'limitations'=>['核准历史期间沿用原统计口径；原始历史金额与当前收银归整金额分别可追溯。']];
    }

    /** 跨历史/现行期间只合并可加指标，再统一重算比率；任一分段缺证据保留空值。 */
    private function merge(array $parts,array $stores,array $range): array
    {
        $result=$parts[0];$byId=[];$gaps=[];$sources=[];$complete=true;
        foreach($parts as $part){
            $complete=$complete&&$part['complete'];$gaps=array_merge($gaps,$part['gaps']??[]);
            foreach($part['field_sources'] as $key=>$note)$sources[$key][]=$note;
            foreach($part['rows'] as $row){$id=(int)$row['store_id'];
                if(!isset($byId[$id])){$byId[$id]=$row;continue;}
                foreach($part['fields'] as $f){$k=$f['key'];
                    if(in_array($k,['store_id','store_name','consumption_cash_ratio','new_customer_conversion_rate'],true))continue;
                    if($byId[$id][$k]===null||$row[$k]===null){$byId[$id][$k]=null;continue;}
                    $byId[$id][$k]=$f['money']?bcadd((string)$byId[$id][$k],(string)$row[$k],2)
                        :$byId[$id][$k]+$row[$k];
                }
            }
        }
        foreach($byId as &$row){
            $cash=$row['cash_performance'];$consume=$row['consumption_performance'];
            $row['consumption_cash_ratio']=$cash===null||$consume===null?null:((int)$cash>0?intdiv((int)$consume*100,(int)$cash):0);
            $new=$row['new_customer_count'];$total=$row['new_customer_total_count'];
            $row['new_customer_conversion_rate']=$new===null||$total===null?null:($total>0?intdiv($new*100,$total):0);
        }unset($row);
        $result['rows']=array_values($byId);$result['complete']=$complete;$result['gaps']=$gaps;
        $result['scope']=['store_ids'=>$stores,'date_range'=>$range];$result['metric_version']='store-summary-period-policy-v1';
        $result['field_sources']=array_map(static fn(array $a):string=>'按查询期间分别采用已核准口径：'.implode('；',array_unique($a)),$sources);
        $result['period_sources']=array_map(static fn(array $p):array=>['range'=>$p['scope']['date_range'],'metric_version'=>$p['metric_version']],$parts);
        $result['fact_completeness']=$complete?'本次各期间来源已分别核准。':'部分期间来源缺失，对应合计保持空白。';
        // 分段认证结果不能因首段完整就把后段空值列标为可合计。
        $result['sum_fields']=[];
        foreach($result['fields'] as &$f){$k=$f['key'];$ok=$f['summable'];
            foreach($result['rows'] as $r)if(($r[$k]??null)===null)$ok=false;
            $f['summable']=$ok;if($ok)$result['sum_fields'][]=$k;
        }unset($f);
        return $result;
    }

    private static function day(string $date,int $offset): string
    { return (new \DateTimeImmutable($date))->modify(($offset>0?'+':'').$offset.' day')->format('Y-m-d'); }
}
