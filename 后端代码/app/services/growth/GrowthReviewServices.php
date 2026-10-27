<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 复盘文字独立保存，观测指标由统一出口冻结读数，不能手工填写金额或推断培训收益。 */
final class GrowthReviewServices
{
    /** 按发生时观测范围列复盘，调店不自动开放旧周期经营读数。 */
    public function listing(array $c,array $in): array
    {
        $q=$this->query($c);$total=$q->count();$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)($in['page']??1)));$rows=$q->order('id','desc')->page($page,20)->select()->toArray();foreach($rows as &$r){$r['snapshot']=GrowthCommandServices::decode($r['snapshot_json']);$r['metrics']=GrowthCommandServices::decode($r['metrics_json']);unset($r['snapshot_json'],$r['metrics_json']);}return ['rows'=>$rows,'total'=>$total,'page'=>$page,'total_pages'=>$pages];
    }
    /** 复盘关联只给出当前可读来源，不让客户端任填编号扩大事实范围。 */
    public function catalog(array $c): array
    {
        $access=app()->make(GrowthAccessServices::class);$plans=Db::name('growth_plan');if(!$c['super'])$plans->whereIn('owner_employee_id',$access->employees($c));$tasks=Db::name('growth_task');$access->taskScope($tasks,$c);$issues=Db::name('growth_issue');if(!$c['super'])$issues->whereIn('store_id',(array)$c['store_ids']);return ['plans'=>$plans->field('id,title')->order('id','desc')->limit(500)->select()->toArray(),'tasks'=>$tasks->field('id,title,employee_name')->order('id','desc')->limit(500)->select()->toArray(),'issues'=>$issues->field('id,description,store_name')->order('id','desc')->limit(500)->select()->toArray()];
    }
    /** 复盘观测保留发生时门店范围；调店后不把旧周期经营读数自动开放给新门店。 */
    private function query(array $c)
    {
        $q=Db::name('growth_review');if($c['super'])return $q;
        $allowed=GrowthCommandServices::json(array_values(array_map('intval',(array)$c['store_ids'])));
        $q->where(function($w)use($c,$allowed){$w->where(function($own)use($c){$own->where('created_by',$c['actor_key'])->whereRaw("JSON_LENGTH(JSON_EXTRACT(snapshot_json,'$.scope_store_ids'))=0");})->whereOr(function($scope)use($c,$allowed){$scope->whereRaw("JSON_LENGTH(JSON_EXTRACT(snapshot_json,'$.scope_store_ids'))>0 AND JSON_CONTAINS(?,JSON_EXTRACT(snapshot_json,'$.scope_store_ids'))=1",[$allowed]);})->whereOrRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.scope_employee_id')) AS UNSIGNED)=?",[$c['employee_id']]);});return $q;
    }
    /** 业务指标由统一读取器冻结，复盘文字按最后完整保存覆盖。 */
    public function save(array $c,array $in): array
    {
        $title=trim((string)($in['title']??''));$judgment=trim((string)($in['judgment']??''));$next=trim((string)($in['next_steps']??''));if($title===''||$judgment===''||$next==='')throw new AdminException('请填写复盘标题、管理判断和下一步行动');
        $type=(string)($in['object_type']??'employee');$object=(int)($in['object_id']??0);$a=app()->make(GrowthAccessServices::class);if($type==='employee')$a->assertEmployee($c,$object);elseif($type!=='store'||(!$c['super']&&!in_array($object,(array)$c['store_ids'],true)))throw new AdminException('复盘对象不在当前范围');
        $dates=[];foreach(['baseline_start','baseline_end','followup_start','followup_end'] as $f){$s=(string)($in[$f]??'');$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$s);if(!$d||$d->format('Y-m-d')!==$s)throw new AdminException('请填写有效的基准与后续周期');$dates[$f]=$s;}if($dates['baseline_start']>$dates['baseline_end']||$dates['followup_start']>$dates['followup_end']||$dates['baseline_end']>=$dates['followup_start'])throw new AdminException('后续周期须在基准周期之后，周期不能重叠');
        $business=app()->make(GrowthBusinessServices::class);$source=['object_type'=>$type,'object_id'=>$object];$before=$business->read($c,$source+['start'=>$dates['baseline_start'],'end'=>$dates['baseline_end']]);$after=$business->read($c,$source+['start'=>$dates['followup_start'],'end'=>$dates['followup_end']]);$selected=array_values(array_unique(array_map('strval',(array)($in['metrics']??[]))));$allowed=array_column($before['metrics'],'code');if(array_diff($selected,$allowed))throw new AdminException('只能选择已接通且适用于此对象的指标');
        // 读取器使用独立一致读事务，先完成观测后保存复盘；不在业务写事务内嵌套经营读取。
        $snapshot=['scope_store_ids'=>array_values(array_unique(array_merge($before['scope_store_ids']??[],$after['scope_store_ids']??[]))),'scope_employee_id'=>$type==='employee'?$object:0,'baseline'=>$before,'followup'=>$after,'observed_at'=>time(),'notice'=>'周期变化是观察结果，不自动构成培养效果或收益因果证明'];
        return app()->make(GrowthCommandServices::class)->run($c,'review.save',$in,function()use($c,$in,$dates,$title,$judgment,$next,$type,$object,$selected,$snapshot){$id=(int)($in['id']??0);if($id){$r=$this->query($c)->where('id',$id)->lock(true)->find();if(!$r)throw new AdminException('复盘不存在');}
            $plan=(int)($in['plan_id']??0);if($plan){$p=Db::name('growth_plan')->where('id',$plan)->find();if(!$p)throw new AdminException('培养计划不存在');if(!$c['super'])app()->make(GrowthAccessServices::class)->assertEmployee($c,(int)$p['owner_employee_id']);}$issue=(int)($in['issue_id']??0);if($issue)app()->make(GrowthInspectionServices::class)->issueDetail($c,$issue);$task=(int)($in['followup_task_id']??0);if($task)app()->make(GrowthTaskServices::class)->task($c,$task);
            $row=$dates+['title'=>mb_substr($title,0,180),'object_type'=>$type,'object_id'=>$object,'plan_id'=>$plan,'issue_id'=>$issue,'metrics_json'=>GrowthCommandServices::json($selected),'snapshot_json'=>GrowthCommandServices::json($snapshot),'background'=>mb_substr((string)($in['background']??''),0,10000),'judgment'=>mb_substr($judgment,0,10000),'next_steps'=>mb_substr($next,0,10000),'followup_task_id'=>$task,'updated_at'=>time()];if($id)Db::name('growth_review')->where('id',$id)->update($row);else $id=(int)Db::name('growth_review')->insertGetId($row+['owner_employee_id'=>$c['employee_id'],'created_by'=>$c['actor_key'],'created_at'=>time()]);return ['id'=>$id,'message'=>'复盘已保存，观测指标与文字分别留存'];});
    }
}
