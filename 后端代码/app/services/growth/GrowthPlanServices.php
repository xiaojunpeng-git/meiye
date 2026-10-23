<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 培养地图只编排已实现活动；所有员工活动最终仍使用同一任务底层。 */
final class GrowthPlanServices
{
    /** 地图与计划各自分页，进度只计算授权内有效必做任务。 */
    public function listing(array $c,array $in=[]): array
    {
        [$maps,$mapsPage]=GrowthPage::take($this->query($c,'growth_map'),$in['maps_page']??1);foreach($maps as &$m){$m['draft']=GrowthCommandServices::decode($m['draft_json']);unset($m['draft_json']);}
        [$plans,$plansPage]=GrowthPage::take($this->query($c,'growth_plan'),$in['plans_page']??1);foreach($plans as &$p){$people=Db::name('growth_plan_employee')->where('plan_id',$p['id'])->where('state','ACTIVE')->count();$p['employee_count']=$people;$q=Db::name('growth_task')->where('plan_id',$p['id'])->where('required',1)->where('state','<>','CANCELLED');app()->make(GrowthAccessServices::class)->taskScope($q,$c);$p['task_count']=(int)(clone $q)->count();$p['completed_tasks']=(int)(clone $q)->where('state','COMPLETED')->count();}
        return ['maps'=>$maps,'plans'=>$plans,'map_options'=>$this->query($c,'growth_map')->field('id,title,release_id')->order('id','desc')->limit(500)->select()->toArray(),'pagination'=>['maps'=>$mapsPage,'plans'=>$plansPage]];
    }

    /** 编排已接通的真实来源并冻结阶段顺序，不发布无可执行来源的活动。 */
    public function publishMap(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'map.publish',$in,function()use($c,$in){
            $title=trim((string)($in['title']??''));$stages=array_values((array)($in['stages']??[]));if($title===''||!$stages||count($stages)>30)throw new AdminException('请填写地图名称及1至30个阶段');
            $normalized=[];
            foreach($stages as $i=>$stage){$activities=[];foreach(array_values((array)($stage['activities']??[])) as $index=>$a){$kind=(string)($a['kind']??'course');$source=(int)($a['source_id']??0);$titleA=trim((string)($a['title']??''));if($source<=0||$titleA==='')throw new AdminException('每个活动须有名称和有效来源');
                    if($kind==='course'){$p=Db::name('growth_publication')->where('id',$source)->where('state','PUBLISHED')->find();if(!$p)throw new AdminException('请选择有效的课程发布');app()->make(GrowthContentServices::class)->owned($c,(int)$p['content_id']);}
                    elseif(in_array($kind,['exam','practice'],true)){$exam=app()->make(GrowthQuestionServices::class)->owned($c,'growth_exam',$source);if($exam['kind']!==$kind||$exam['state']!=='OPEN'||($exam['ends_at']&&$exam['ends_at']<=time()))throw new AdminException('练习考试类型不符、已结束或已停用');}
                    elseif(in_array($kind,['assignment','evaluation','coaching','offline'],true)){
                        $activity=Db::name('growth_activity')->where('id',$source)->where('kind',$kind)->find();if(!$activity)throw new AdminException('地图引用的实操或带教活动无效');if(!$c['super'])app()->make(GrowthAccessServices::class)->assertEmployee($c,(int)$activity['owner_employee_id']);
                    }
                    else throw new AdminException('当前活动类型尚未接通，不能发布到地图');
                    $activities[]=['key'=>'stage-'.($i+1).'-activity-'.($index+1),'kind'=>$kind,'source_id'=>$source,'title'=>mb_substr($titleA,0,180),'required'=>(bool)($a['required']??true)];
                }if(!$activities)throw new AdminException('每个阶段至少一个可执行活动');$normalized[]=['title'=>mb_substr((string)($stage['title']??('第'.($i+1).'阶段')),0,180),'activities'=>$activities];}
            $snapshot=['title'=>mb_substr($title,0,180),'description'=>mb_substr((string)($in['description']??''),0,10000),'sequential'=>(bool)($in['sequential']??true),'stages'=>$normalized,'service_product_id'=>(int)($in['service_product_id']??0)];
            $id=(int)($in['id']??0);if($id)$this->owned($c,'growth_map',$id);else $id=(int)Db::name('growth_map')->insertGetId(['title'=>$snapshot['title'],'description'=>$snapshot['description'],'draft_json'=>GrowthCommandServices::json($snapshot),'created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'created_at'=>time(),'updated_at'=>time()]);
            $release=(int)Db::name('growth_map_release')->insertGetId(['map_id'=>$id,'snapshot_json'=>GrowthCommandServices::json($snapshot),'published_by'=>$c['actor_key'],'published_at'=>time()]);
            Db::name('growth_map')->where('id',$id)->update(['title'=>$snapshot['title'],'description'=>$snapshot['description'],'sequential'=>$snapshot['sequential']?1:0,'service_product_id'=>$snapshot['service_product_id'],'draft_json'=>GrowthCommandServices::json($snapshot),'release_id'=>$release,'updated_at'=>time()]);return ['id'=>$id,'release_id'=>$release,'message'=>'培养地图已发布'];
        });
    }

    /** 固定地图发布内容与学员名单，负责人和期限在派发事务内校验。 */
    public function publishPlan(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'plan.publish',$in,function()use($c,$in){
            $release=Db::name('growth_map_release')->where('id',(int)($in['map_release_id']??0))->find();if(!$release)throw new AdminException('请选择已发布培养地图');$this->owned($c,'growth_map',(int)$release['map_id']);
            $snapshot=GrowthCommandServices::decode($release['snapshot_json']);$title=trim((string)($in['title']??''));if($title==='')throw new AdminException('请填写计划名称');
            $responsible=(int)($in['responsible_employee_id']??0);app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$responsible);
            $due=(int)($in['due_at']??0);$starts=(int)($in['starts_at']??0);if($due>0&&$due<=max(time(),$starts))throw new AdminException('计划截止时间无效');
            $people=array_values(array_unique(array_map('intval',(array)($in['employee_ids']??[]))));if(!$people||count($people)>500)throw new AdminException('每次选择1至500名学员');
            $id=(int)Db::name('growth_plan')->insertGetId(['title'=>mb_substr($title,0,180),'map_release_id'=>$release['id'],'sequential'=>$snapshot['sequential']?1:0,'responsible_employee_id'=>$responsible,'starts_at'=>$starts,'due_at'=>$due,'created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'created_at'=>time()]);
            $this->enroll($c,$id,$people,(int)($in['mentor_employee_id']??0));return ['id'=>$id,'employee_count'=>count($people),'message'=>'培养计划已分配'];
        });
    }

    /** 补发唯一来源不重建原结果；退出仅停止在途任务并留下名单历史。 */
    public function changePeople(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'plan.people',$in,function()use($c,$in){
            $id=(int)($in['plan_id']??0);$this->owned($c,'growth_plan',$id);$people=array_values(array_unique(array_map('intval',(array)($in['employee_ids']??[]))));$reason=trim((string)($in['reason']??''));if(!$people||$reason==='')throw new AdminException('请选择员工并填写补发或退出原因');
            if(($in['action']??'')==='enroll')$this->enroll($c,$id,$people,(int)($in['mentor_employee_id']??0));
            elseif(($in['action']??'')==='withdraw'){foreach($people as $employee){app()->make(GrowthAccessServices::class)->assertEmployee($c,$employee);Db::name('growth_plan_employee')->where('plan_id',$id)->where('employee_id',$employee)->where('state','ACTIVE')->update(['state'=>'WITHDRAWN','withdrawn_at'=>time(),'withdrawal_reason'=>$reason]);$tasks=Db::name('growth_task')->where('plan_id',$id)->where('employee_id',$employee)->whereNotIn('state',['COMPLETED','CANCELLED'])->order('id')->lock(true)->select()->toArray();foreach($tasks as $t){Db::name('growth_task')->where('id',$t['id'])->whereNotIn('state',['COMPLETED','CANCELLED'])->update(['state'=>'CANCELLED']);app()->make(GrowthTaskServices::class)->cancelChildren($c,(int)$t['id'],$reason);Db::name('growth_attempt')->where('task_id',$t['id'])->whereIn('state',['ANSWERING','GRADING','HELD'])->update(['state'=>'CANCELLED']);Db::name('growth_task_action')->insert(['task_id'=>$t['id'],'action'=>'plan_withdraw','reason'=>$reason,'before_json'=>GrowthCommandServices::json($t),'after_json'=>'{"state":"CANCELLED"}','actor_key'=>$c['actor_key'],'created_at'=>time()]);}}}
            else throw new AdminException('名单操作无效');return ['plan_id'=>$id,'message'=>'名单处理完成'];
        });
    }

    private function enroll(array $c,int $id,array $people,int $mentor): void
    {
        $plan=Db::name('growth_plan')->where('id',$id)->lock(true)->find();$release=Db::name('growth_map_release')->where('id',$plan['map_release_id'])->find();$map=GrowthCommandServices::decode($release['snapshot_json']);if($mentor)app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$mentor);
        foreach($people as $employee){app()->make(GrowthAccessServices::class)->assertEmployee($c,$employee);$old=Db::name('growth_plan_employee')->where('plan_id',$id)->where('employee_id',$employee)->find();if($old)continue;
            Db::name('growth_plan_employee')->insert(['plan_id'=>$id,'employee_id'=>$employee,'mentor_employee_id'=>$mentor,'assigned_at'=>time(),'withdrawal_reason'=>'']);
            foreach($map['stages'] as $stageNo=>$stage)foreach($stage['activities'] as $a){$data=['assignment_key'=>'plan:'.$id.':'.$a['key'],'plan_id'=>$id,'stage_no'=>$stageNo+1,'title'=>$a['title'],'kind'=>$a['kind'],'required'=>$a['required']?1:0,'starts_at'=>$plan['starts_at'],'due_at'=>$plan['due_at'],'handler_employee_id'=>$mentor,'rules_json'=>'{}'];
                if($a['kind']==='course')$data+=['publication_id'=>$a['source_id'],'source_type'=>'plan','source_id'=>$id];
                elseif(in_array($a['kind'],['assignment','evaluation','coaching','offline'],true)){
                    $activity=Db::name('growth_activity')->where('id',$a['source_id'])->where('kind',$a['kind'])->find();if(!$activity)throw new AdminException('地图引用活动不存在');$data+=['source_type'=>'activity','source_id'=>$activity['id']];$data['handler_employee_id']=$activity['handler_employee_id'];
                }
                else{$exam=Db::name('growth_exam')->where('id',$a['source_id'])->where('state','OPEN')->find();if(!$exam||($exam['ends_at']&&$exam['ends_at']<=time()))throw new AdminException('地图引用的练考已不可用');$data+=['source_type'=>'exam','source_id'=>$exam['id']];$data['handler_employee_id']=$exam['grader_employee_id'];if($exam['ends_at'])$data['due_at']=$plan['due_at']?min($plan['due_at'],$exam['ends_at']):$exam['ends_at'];}
                app()->make(GrowthTaskServices::class)->insertTask($c,$employee,$data);
            }
        }
    }
    private function query(array $c,string $table){if(!in_array($table,['growth_map','growth_plan'],true))throw new \InvalidArgumentException('不支持的培养实体');$q=Db::name($table);if(!$c['super'])$q->whereIn('owner_employee_id',app()->make(GrowthAccessServices::class)->employees($c));return $q;}
    private function owned(array $c,string $table,int $id): array{$row=$this->query($c,$table)->where('id',$id)->find();if(!$row)throw new AdminException('培养记录不在当前范围');return $row;}
}
