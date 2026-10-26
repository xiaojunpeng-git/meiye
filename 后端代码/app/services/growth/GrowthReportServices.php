<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 成长统计唯一只读出口：列表、全量合计、下钻与导出共用同一范围及查询。 */
final class GrowthReportServices
{
    public const METRIC='growth-formal-facts-v1';
    private const STATES=['NOT_STARTED'=>'未开始','IN_PROGRESS'=>'进行中','PENDING'=>'待确认','COMPLETED'=>'已完成','CANCELLED'=>'已作废','PAUSED'=>'已暂停','RETURNED'=>'待补充','OPEN'=>'待整改','PENDING_REVIEW'=>'待复查','CLOSED'=>'已关闭','PASSED'=>'通过','FAILED'=>'未通过','QUALIFIED'=>'合格','NEED_PRACTICE'=>'待补练','UNTESTED'=>'未检验','NOT_EVALUATED'=>'未评价'];
    /** 筛选候选来自同一授权范围，日期含义及服务器默认日期显式返回。 */
    public function filters(array $c): array
    {
        $access=app()->make(GrowthAccessServices::class);$stores=Db::name('system_store')->where('is_del',0);$orgs=Db::name('organization')->where('is_del',0);if(!$c['super']){$stores->whereIn('id',(array)$c['store_ids']);$orgs->whereIn('id',$c['organization_ids']);}
        $nodes=$orgs->field('id,pid,name')->order('id')->select()->toArray();$shopRows=$stores->field('id,name')->order('id')->select()->toArray();$links=Db::name('organization_store')->whereIn('store_id',array_column($shopRows,'id'))->select()->toArray();$byStore=[];foreach($links as $link)$byStore[(int)$link['store_id']]=(int)$link['org_id'];$byId=[];foreach($nodes as $node)$byId[(int)$node['id']]=['id'=>(int)$node['id'],'org_id'=>(int)$node['id'],'name'=>$node['name'],'node_type'=>'organization','pid'=>(int)$node['pid'],'children'=>[]];$tree=[];foreach($shopRows as $shop){$org=$byStore[(int)$shop['id']]??0;$leaf=['id'=>(int)$shop['id'],'store_id'=>(int)$shop['id'],'org_id'=>$org,'name'=>$shop['name'],'node_type'=>'store','children'=>[]];if(isset($byId[$org]))$byId[$org]['children'][]=$leaf;else $tree[]=$leaf;}foreach(array_keys($byId) as $id){$parent=$byId[$id]['pid'];if($parent&&isset($byId[$parent]))$byId[$parent]['children'][]=&$byId[$id];else $tree[]=&$byId[$id];}
        $taskOptions=Db::name('growth_task');$access->taskScope($taskOptions,$c);$sourceIds=(clone $taskOptions)->where('source_type','exam')->distinct(true)->column('source_id');$planIds=(clone $taskOptions)->where('plan_id','>',0)->distinct(true)->column('plan_id');
        return ['positions'=>Db::name('position')->field('id,name')->order('id')->select()->toArray(),'plans'=>Db::name('growth_plan')->whereIn('id',$planIds)->field('id,title')->order('id','desc')->select()->toArray(),'exams'=>Db::name('growth_exam')->whereIn('id',$sourceIds)->field('id,title')->order('id','desc')->select()->toArray(),'date_defaults'=>['start'=>date('Y-m-01'),'end'=>date('Y-m-d')],'tree'=>$tree,'organizations'=>$nodes,'stores'=>$shopRows,'employees'=>app()->make(GrowthProfileServices::class)->people($c),'states'=>self::STATES,'reports'=>['tasks'=>'任务与培养进度','exams'=>'考试结果','issues'=>'整改问题','points'=>'积分流水','capabilities'=>'岗位能力缺口'],'date_meanings'=>['tasks'=>'任务分配日期','exams'=>'考试任务分配日期','issues'=>'问题发现日期','points'=>'积分记账日期','capabilities'=>'当前在岗能力要求及最新有效鉴定，不按分配日期截断']];
    }
    /** 列表分页、完整合计及列来源在同一一致读事务内生成。 */
    public function read(array $c,array $in,bool $all=false): array
    {
        return Db::transaction(function()use($c,$in,$all){$filter=$this->normalize($in);[$q,$fields,$columns]=$this->query($c,$filter);$capTotals=$filter['report']==='capabilities'?$this->capabilityTotals(clone $q):null;$total=$capTotals?$capTotals['expected_capabilities']:(clone $q)->count();if($all&&$total>10000)throw new AdminException('导出超过10000条，请缩小日期或对象范围');$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)($in['page']??1)));
            $rows=$q->field($fields)->order($filter['report']==='points'?'r.id':'t.id','desc');if(!$all)$rows->page($page,20);$rows=$rows->select()->toArray();$names=[];
            foreach($rows as &$r){$r['row_key']=$filter['report'].':'.$r['id'];$r['state_text']=self::STATES[$r['state']??'']??($r['state']??'—');$r['conclusion_text']=self::STATES[$r['conclusion']??'']??($r['conclusion']??'—');foreach(['assigned_at','completed_at','due_at','created_at','closed_at','occurred_at'] as $field)if(isset($r[$field]))$r[$field.'_text']=$r[$field]?date('Y-m-d H:i:s',(int)$r[$field]):'—';if(isset($r['employee_id'])&&!isset($r['employee_name'])){$id=(int)$r['employee_id'];if(!isset($names[$id]))$names[$id]=(string)Db::name('employee')->where('id',$id)->value('name');$r['employee_name']=$names[$id];}$r['drill']=['report'=>$filter['report'],'id'=>$filter['report']==='capabilities'?(string)$r['id']:(int)$r['id']];}
            return ['report'=>$filter['report'],'metric_version'=>self::METRIC,'filters'=>$filter,'columns'=>$columns,'groups'=>[['key'=>'identity','title'=>'业务对象','color'=>'#eef4f0'],['key'=>'result','title'=>'正式结果','color'=>'#f5f1e7']],'table_layout'=>['fixed'=>true,'fixed_header'=>true,'fixed_total'=>true,'fixed_columns'=>['id','title'],'row_key'=>'row_key'],'records'=>$rows,'repeat_groups'=>$filter['report']==='issues'?$this->repeatGroups($c,$filter):[],'total'=>$total,'page'=>$page,'page_size'=>20,'total_pages'=>$pages,'totals'=>$capTotals??$this->totals($c,$filter),'data_as_of'=>date('Y-m-d H:i:s'),'result_as_of'=>time(),'aggregation_caught_up'=>true,'aggregation_status'=>'直接读取已提交成长事实，无异步汇总延迟'];
        });
    }
    private function normalize(array $in): array
    {
        $report=(string)($in['report']??'tasks');if(!in_array($report,['tasks','exams','issues','points','capabilities'],true))throw new AdminException('成长报表类型无效');$start=(string)($in['start']??date('Y-m-01'));$end=(string)($in['end']??date('Y-m-d'));
        foreach([$start,$end] as $d){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$d);if(!$date||$date->format('Y-m-d')!==$d)throw new AdminException('请选择有效统计日期');}if($start>$end||strtotime($end)-strtotime($start)>366*86400)throw new AdminException('统计范围需在一年内且起始不晚于截止');
        return ['report'=>$report,'start'=>$start,'end'=>$end,'organization_id'=>(int)($in['organization_id']??0),'store_ids'=>array_slice(array_values(array_unique(array_filter(array_map('intval',(array)($in['store_ids']??[]))))),0,500),'store_id'=>(int)($in['store_id']??0),'item_key'=>mb_substr((string)($in['item_key']??''),0,64),'standard_id'=>(int)($in['standard_id']??0),'rule_id'=>(int)($in['rule_id']??0),'position_id'=>(int)($in['position_id']??0),'employee_id'=>(int)($in['employee_id']??0),'plan_id'=>(int)($in['plan_id']??0),'source_id'=>(int)($in['source_id']??0),'state'=>(string)($in['state']??''),'keyword'=>mb_substr(trim((string)($in['keyword']??'')),0,100)];
    }
    /** 事实粒度由固定报表决定；客户端不能提交表名、列名或任意SQL条件。 */
    private function query(array $c,array $f): array
    {
        $a=app()->make(GrowthAccessServices::class);$start=strtotime($f['start'].' 00:00:00');$end=strtotime($f['end'].' 23:59:59');$common=[['id','记录编号',110,'业务事实主键'],['title','任务 / 来源',280,'任务标题或正式问题内容'],['employee_name','员工',130,'任务分配时员工名称；积分读取当前员工名称']];
        if(in_array($f['report'],['tasks','exams'],true)){$q=Db::name('growth_task')->alias('t');$a->taskScope($q,$c,'t');if($f['report']==='exams')$q->whereIn('t.kind',['exam','practice']);$q->whereBetween('t.assigned_at',[$start,$end]);if($f['plan_id'])$q->where('t.plan_id',$f['plan_id']);if($f['source_id'])$q->where('t.source_id',$f['source_id']);if($f['employee_id'])$q->where('t.employee_id',$f['employee_id']);if($f['store_id'])$q->where('t.store_id',$f['store_id']);if($f['organization_id'])$q->whereIn('t.organization_id',$this->organizations($f['organization_id']));elseif($f['store_ids'])$q->whereIn('t.store_id',$f['store_ids']);if($f['state'])$q->where('t.state',$f['state']);if($f['keyword'])$q->whereLike('t.title','%'.$f['keyword'].'%');
            $jobs=Db::name('growth_task_job')->whereRaw('task_id=t.id')->fieldRaw("GROUP_CONCAT(DISTINCT position_name ORDER BY position_id SEPARATOR '、')")->buildSql();if($f['position_id'])$q->whereExists(function($job)use($f){$job->name('growth_task_job')->whereRaw('task_id=t.id')->where('position_id',$f['position_id']);});
            $fields=$jobs.' AS position_name,t.id,t.title,t.kind,t.employee_id,t.employee_name,t.organization_id,t.store_id,t.state,t.conclusion,t.required,t.plan_id,t.source_type,t.source_id,t.assigned_at,t.due_at,t.completed_at,t.result_type,t.result_id';$defs=array_merge($common,[['position_name','分配时岗位',180,'任务分配时冻结的任职岗位；未知历史不猜测补填'],['state_text','任务状态',130,'任务当前有效状态'],['conclusion_text','正式结论',130,'正式公布的最高有效成绩或原业务结果'],['assigned_at_text','分配时间',180,'任务正式派发时间'],['completed_at_text','完成时间',180,'满足任务全部条件的确认时间']]);
        }elseif($f['report']==='issues'){$q=Db::name('growth_issue')->alias('t')->join('growth_inspection gi','gi.id=t.inspection_id')->join('growth_standard_release gs','gs.id=gi.standard_release_id');if($f['item_key'])$q->where('t.item_key',$f['item_key']);if($f['standard_id'])$q->where('gs.standard_id',$f['standard_id']);if(!$c['super'])$q->whereIn('t.store_id',(array)$c['store_ids']);$q->whereBetween('t.created_at',[$start,$end]);if($f['store_id'])$q->where('t.store_id',$f['store_id']);if($f['employee_id'])$q->where('t.responsible_employee_id',$f['employee_id']);if($f['source_id'])$q->where('t.inspection_id',$f['source_id']);if($f['state'])$q->where('t.state',$f['state']);if($f['keyword'])$q->whereLike('t.description','%'.$f['keyword'].'%');if($f['organization_id'])$q->whereIn('t.store_id',Db::name('organization_store')->whereIn('org_id',$this->organizations($f['organization_id']))->column('store_id'));elseif($f['store_ids'])$q->whereIn('t.store_id',$f['store_ids']);
            $fields='gs.standard_id,t.item_key,t.id,t.description AS title,t.responsible_employee_id AS employee_id,t.store_id,t.store_name,t.state,t.created_at,t.closed_at,t.due_at,t.task_id,t.inspection_id';$defs=array_merge($common,[['store_name','门店',180,'巡检发现时门店名称'],['state_text','整改状态',130,'待整改、待复查、已复查关闭分别统计'],['created_at_text','发现时间',180,'原问题正式创建时间'],['closed_at_text','复查关闭时间',180,'复查人确认通过的时间']]);
        }elseif($f['report']==='capabilities'){[$q,$fields]=$this->capabilityQuery($c,$f);$defs=array_merge($common,[['position_name','现任岗位',180,'人事在岗岗位关联的能力要求'],['state_text','能力状态',130,'最新授权内实操鉴定；无记录或过期为未评价'],['occurred_at_text','最近鉴定时间',180,'原正式实操鉴定发生时间']]);
        }else{$q=$a->rewardQuery($c,'growth_point_ledger');if($f['rule_id'])$q->where('r.rule_id',$f['rule_id']);$q->whereBetween('r.occurred_at',[$start,$end]);if($f['employee_id'])$q->where('r.employee_id',$f['employee_id']);if($f['store_id'])$q->where(function($w)use($f){$w->where('t.store_id',$f['store_id'])->whereOrRaw("JSON_UNQUOTE(JSON_EXTRACT(r.evidence_json,'$.scope.store_id'))=?",[(string)$f['store_id']]);});if($f['organization_id']){$orgIds=$this->organizations($f['organization_id']);$q->where(function($w)use($orgIds){$w->whereIn('t.organization_id',$orgIds)->whereOrRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(r.evidence_json,'$.scope.organization_id')) AS UNSIGNED) IN (".implode(',',array_map('intval',$orgIds)).')');});}elseif(!$f['store_id']&&$f['store_ids'])$q->where(function($w)use($f){$w->whereIn('t.store_id',$f['store_ids'])->whereOrRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(r.evidence_json,'$.scope.store_id')) AS UNSIGNED) IN (".implode(',',array_map('intval',$f['store_ids'])).')');});if($f['keyword'])$q->whereLike('r.reason','%'.$f['keyword'].'%');$fields='r.id,r.reason AS title,r.employee_id,r.delta,r.source_type,r.source_id,r.reversal_of,r.occurred_at';$defs=array_merge($common,[['delta','积分变动',130,'独立积分流水的有符号整数，冲正为新流水'],['occurred_at_text','记账时间',180,'本条积分事实记账时间']]);}
        $columns=[];foreach($defs as [$key,$title,$width,$source])$columns[]=['key'=>$key,'title'=>$title,'width'=>$width,'fixed'=>in_array($key,['id','title'],true)?'left':null,'group'=>in_array($key,['id','title','employee_name','store_name'],true)?'identity':'result','summable'=>$key==='delta','value_source'=>$source,'drillable'=>$key==='title','param_map'=>$key==='title'?['report'=>'report','id'=>'id']:[]];return [$q,$fields,$columns];
    }
    private function totals(array $c,array $f): array
    {
        [$q]=$this->query($c,$f);if($f['report']==='points')return ['delta'=>(int)$q->sum('r.delta'),'explanation'=>'完整筛选范围积分增减流水净额'];
        if($f['report']==='capabilities')return $this->capabilityTotals($q);
        if($f['report']==='issues'){$valid=(int)(clone $q)->where('t.state','<>','CANCELLED')->count();$closed=(int)(clone $q)->where('t.state','CLOSED')->count();$groupSql=$this->issueGroupQuery(clone $q)->buildSql();$groupTotals=Db::query('SELECT COUNT(*) groups_count,COALESCE(SUM(occurrences),0) repeated_count FROM '.$groupSql.' g')[0];return ['repeated_issue_groups'=>(int)$groupTotals['groups_count'],'repeated_issues'=>(int)$groupTotals['repeated_count'],'valid_issues'=>$valid,'closed_issues'=>$closed,'close_rate'=>$this->ratio($closed,$valid),'explanation'=>'按原问题计数；提交次数不作为关闭数。反复问题指筛选内同店、同标准及相同稳定检查项至少出现两次'];}
        $valid=(clone $q)->where('t.state','<>','CANCELLED');$required=(int)(clone $valid)->where('t.required',1)->count();$completed=(int)(clone $valid)->where('t.required',1)->where('t.state','COMPLETED')->count();$people=(clone $valid)->where('t.required',1)->fieldRaw("t.employee_id,COUNT(*) required_count,SUM(CASE WHEN t.state='COMPLETED' THEN 1 ELSE 0 END) completed_count")->group('t.employee_id')->select()->toArray();$all=0;foreach($people as $p)if((int)$p['required_count']===(int)$p['completed_count'])$all++;
        $totals=['assigned_people'=>(int)(clone $valid)->distinct(true)->count('t.employee_id'),'cancelled_tasks'=>(int)(clone $q)->where('t.state','CANCELLED')->count(),'required_tasks'=>$required,'completed_tasks'=>$completed,'completion_rate'=>$this->ratio($completed,$required),'required_people'=>count($people),'all_completed_people'=>$all,'people_completion_rate'=>$this->ratio($all,count($people)),'overdue_tasks'=>(int)(clone $valid)->whereNotIn('t.state',['COMPLETED','PAUSED'])->where('t.due_at','>',0)->where('t.due_at','<',time())->count(),'pending_tasks'=>(int)(clone $valid)->where('t.state','PENDING')->count(),'explanation'=>'按分配日期形成任务队列，查看这些任务当前正式结果；取消任务单列，待阅卷不算完成'];
        if($f['report']==='exams'){$expected=(int)(clone $valid)->distinct(true)->count('t.employee_id');$passed=(int)(clone $valid)->where('t.conclusion','PASSED')->distinct(true)->count('t.employee_id');$totals+=['expected_people'=>$expected,'passed_people'=>$passed,'pass_rate'=>$this->ratio($passed,$expected),'not_started_tasks'=>(int)(clone $valid)->where('t.state','NOT_STARTED')->count()];}return $totals;
    }
    /** 一次条件聚合覆盖全量能力；不对每种状态反复展开员工与能力笛卡尔集合。 */
    private function capabilityTotals($q): array
    {
        $row=$q->fieldRaw("COUNT(*) expected,COALESCE(SUM(CASE WHEN t.state='QUALIFIED' THEN 1 ELSE 0 END),0) qualified,COALESCE(SUM(CASE WHEN t.state='NEED_PRACTICE' THEN 1 ELSE 0 END),0) need_practice,COALESCE(SUM(CASE WHEN t.state='NOT_EVALUATED' THEN 1 ELSE 0 END),0) not_evaluated")->select()->toArray()[0];return ['expected_capabilities'=>(int)$row['expected'],'qualified_capabilities'=>(int)$row['qualified'],'need_practice_capabilities'=>(int)$row['need_practice'],'not_evaluated_capabilities'=>(int)$row['not_evaluated'],'explanation'=>'每人每项当前岗位能力计一条；无正式鉴定或已过期显示未评价，不作为不合格，也不自动改变岗位资格'];
    }
    /** 当前岗位决定应评能力，历史鉴定必须经过原任务发生归属授权；未评价不伪装成零分。 */
    private function capabilityQuery(array $c,array $f): array
    {
        $a=app()->make(GrowthAccessServices::class);
        $latest=Db::name('growth_evaluation')->alias('ev0')->join('growth_task et','et.id=ev0.task_id');
        $a->taskScope($latest,$c,'et');$latestSql=$latest->fieldRaw('ev0.employee_id,ev0.capability_id,MAX(ev0.id) evaluation_id')->group('ev0.employee_id,ev0.capability_id')->buildSql();
        $source=Db::name('staff_job_position')->alias('j')->join('employee e','e.id=j.employee_id')->join('position p','p.id=j.position_id')->join('growth_capability cap','cap.position_id=j.position_id')->leftJoin([$latestSql=>'latest'],'latest.employee_id=j.employee_id AND latest.capability_id=cap.id')->leftJoin('growth_evaluation ev','ev.id=latest.evaluation_id')
            ->where('j.is_del',0)->where('j.status',1)->where('j.end_time',0)->where('j.start_time','<=',time())->where('e.status',1)->where('e.is_del',0)->whereIn('j.employee_id',$a->employees($c));
        // 在岗门店范围与人员范围取交集；跨店兼职不能让一店管理员看到另一店岗位。
        if(!$c['super'])$source->where(function($w)use($c){$w->where('j.employee_id',$c['employee_id']);if($c['store_ids'])$w->whereOr('j.store_id','in',$c['store_ids']);if($c['organization_ids'])$w->whereOr('j.store_id',0);});
        if($f['position_id'])$source->where('cap.position_id',$f['position_id']);if($f['employee_id'])$source->where('j.employee_id',$f['employee_id']);if($f['store_id'])$source->where('j.store_id',$f['store_id']);elseif($f['store_ids'])$source->whereIn('j.store_id',$f['store_ids']);
        if($f['organization_id'])$source->whereIn('j.store_id',Db::name('organization_store')->whereIn('org_id',$this->organizations($f['organization_id']))->column('store_id'));
        $state="CASE WHEN ev.id IS NULL OR (ev.valid_until>0 AND ev.valid_until<=".time().") THEN 'NOT_EVALUATED' ELSE ev.conclusion END";
        $inner=$source->distinct(true)->fieldRaw("CONCAT(j.employee_id,':',cap.id) id,cap.title,j.employee_id,e.name employee_name,p.name position_name,cap.id capability_id,ev.id evaluation_id,ev.task_id,ev.occurred_at,".$state." state")->buildSql();
        $q=Db::table($inner)->alias('t');if($f['state'])$q->where('t.state',$f['state']);if($f['keyword'])$q->whereLike('t.title','%'.$f['keyword'].'%');return [$q,'t.*'];
    }
    /** 反复问题按同店同标准同检查项聚合，不能把不同检查表的同名项混成一组。 */
    private function issueGroupQuery($q)
    {
        return $q->where('t.state','<>','CANCELLED')->fieldRaw('t.store_id,gs.standard_id,t.item_key,COUNT(*) occurrences,MAX(t.id) latest_issue_id')->group('t.store_id,gs.standard_id,t.item_key')->having('COUNT(*)>1');
    }
    /** 工作台仅预览前20组，完整数量仍由同一筛选下的分组合计计算。 */
    private function repeatGroups(array $c,array $f): array
    {
        [$q]=$this->query($c,$f);$rows=$this->issueGroupQuery($q)->orderRaw('COUNT(*) DESC,MAX(t.id) DESC')->limit(20)->select()->toArray();foreach($rows as &$r){$latest=Db::name('growth_issue')->where('id',$r['latest_issue_id'])->find();$r['item_title']=$latest['item_title'];$r['store_name']=$latest['store_name'];}return $rows;
    }
    private function organizations(int $root): array
    {
        $all=Db::name('organization')->where('is_del',0)->field('id,pid')->select()->toArray();$ids=[$root];do{$n=count($ids);foreach($all as $r)if(in_array((int)$r['pid'],$ids,true))$ids[]=(int)$r['id'];$ids=array_values(array_unique($ids));}while(count($ids)>$n);return $ids;
    }
    private function ratio(int $numerator,int $denominator): ?string{return $denominator?number_format(100*$numerator/$denominator,1,'.','').'%':null;}
    /** 下钻重新套用原筛选和授权，稳定来源键不接受模糊文本定位。 */
    public function drill(array $c,array $in): array
    {
        $f=$this->normalize($in);[$q,$fields]=$this->query($c,$f);$id=$f['report']==='capabilities'?(string)($in['id']??''):(int)($in['id']??0);if($f['report']==='capabilities'&&!preg_match('/^\d+:\d+$/D',$id))throw new AdminException('能力来源参数无效');$row=$q->where($f['report']==='points'?'r.id':'t.id',$id)->field($fields)->find();if(!$row)throw new AdminException('明细已不在当前筛选或授权范围');
        if($f['report']==='capabilities')return ['type'=>'capability','record'=>$row];
        if(in_array($f['report'],['tasks','exams'],true))return ['type'=>'task','record'=>app()->make(GrowthTaskServices::class)->detail($c,$id)];if($f['report']==='issues')return ['type'=>'issue','record'=>app()->make(GrowthInspectionServices::class)->issueDetail($c,$id)];return ['type'=>'points','record'=>$row];
    }
    /** 导出执行时重新按当前菜单和数据范围查询；文件仅通过私有下载接口读取。 */
    public function export(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'report.export',$in,function()use($c,$in){$r=$this->read($c,$in,true);$dir=app()->getRuntimePath().'private/growth-exports';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new AdminException('无法创建导出目录');$name=bin2hex(random_bytes(20)).'.csv';$file=fopen($dir.'/'.$name,'wb');if(!$file)throw new AdminException('无法写入导出文件');fwrite($file,"\xEF\xBB\xBF");fputcsv($file,array_column($r['columns'],'title'));foreach($r['records'] as $record){$values=[];foreach($r['columns'] as $col){$v=$record[$col['key']]??'—';if(is_string($v)&&preg_match('/^[=+@-]/u',$v))$v="'".$v;$values[]=$v;}fputcsv($file,$values);}fputcsv($file,[]);fputcsv($file,['完整筛选合计',GrowthCommandServices::json($r['totals'])]);foreach($r['columns'] as $col)fputcsv($file,[$col['title'],$col['value_source']]);fclose($file);chmod($dir.'/'.$name,0600);
            $ids=[];foreach($r['records'] as $row)if(!empty($row['employee_id']))$ids[]=(int)$row['employee_id'];$id=(int)Db::name('growth_export')->insertGetId(['actor_key'=>$c['actor_key'],'query_json'=>GrowthCommandServices::json($r['filters']),'scope_json'=>GrowthCommandServices::json(['employee_ids'=>array_values(array_unique($ids)),'store_ids'=>$c['store_ids'],'organization_ids'=>$c['organization_ids'],'super'=>$c['super']]),'state'=>'DONE','row_count'=>$r['total'],'file_key'=>$name,'requested_at'=>time(),'completed_at'=>time()]);return ['id'=>$id,'row_count'=>$r['total'],'state'=>'DONE','message'=>'导出已生成'];});
    }
    /** 下载时复核当前范围；旧导出不能延续已经撤回的数据权限。 */
    public function exportFile(array $c,int $id): string
    {
        $r=Db::name('growth_export')->where('id',$id)->where('actor_key',$c['actor_key'])->where('state','DONE')->find();if(!$r)throw new AdminException('导出记录不存在');$scope=GrowthCommandServices::decode($r['scope_json']);if(!$c['super']&&(!empty($scope['super'])||array_diff($scope['employee_ids'],app()->make(GrowthAccessServices::class)->employees($c))||array_diff((array)$scope['store_ids'],(array)$c['store_ids'])||array_diff($scope['organization_ids'],$c['organization_ids'])))throw new AdminException('权限范围已变化，请重新查询导出');$path=app()->getRuntimePath().'private/growth-exports/'.basename($r['file_key']);if(!is_file($path))throw new AdminException('导出文件已不存在，请重新申请');return $path;
    }
}
