<?php
declare(strict_types=1);
namespace app\services\growth;

use think\facade\Db;
use mohe\exceptions\AdminException;

/** 单项任务是权威待办；消息已读、打开课程和浏览位置均不直接等于完成。 */
final class GrowthTaskServices
{
    /** 任务列表分页按发生时授权，消息已读及打开页面均不改变完成状态。 */
    public function listing(array $c,array $in): array
    {
        $q=Db::name('growth_task');
        if($c['manager'])app()->make(GrowthAccessServices::class)->taskScope($q,$c);
        else $q->where('employee_id',$c['employee_id']);
        if(!empty($in['employee_id']))$q->where('employee_id',(int)$in['employee_id']);
        if(!empty($in['state']))$q->where('state',(string)$in['state']);
        if(($in['view']??'')==='todo')$q->whereNotIn('state',['COMPLETED','CANCELLED']);
        if(!empty($in['keyword']))$q->whereLike('title','%'.mb_substr((string)$in['keyword'],0,100).'%');
        $total=(clone $q)->count();$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)($in['page']??1)));
        $rows=$q->order('id','desc')->page($page,20)->select()->toArray();
        foreach($rows as &$r){$r['overdue']=$r['due_at']>0&&$r['due_at']<time()&&!in_array($r['state'],['COMPLETED','CANCELLED'],true);unset($r['rules_json']);}
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'page_size'=>20,'total_pages'=>$pages];
    }

    /** 固定发布内容与接收名单；相同来源补发不重置原任务。 */
    public function assign(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'task.assign',$in,function()use($c,$in){
            $p=Db::name('growth_publication')->where('id',(int)($in['publication_id']??0))->find();
            if(!$p||$p['state']!=='PUBLISHED')throw new AdminException('请选择已正式发布的课程');
            $content=app()->make(GrowthContentServices::class)->owned($c,(int)$p['content_id']);
            if($content['state']==='STOPPED')throw new AdminException('课程已停用，不能新分配');
            $employees=array_values(array_unique(array_map('intval',(array)($in['employee_ids']??[]))));
            if(!$employees||count($employees)>500)throw new AdminException('每次请选择1至500名员工');
            $starts=(int)($in['starts_at']??0);$due=(int)($in['due_at']??0);$relative=$in['relative_days']??0;if(is_bool($relative)||filter_var($relative,FILTER_VALIDATE_INT)===false||(int)$relative<0||(int)$relative>3650)throw new AdminException('相对期限为0至3650天');if((int)$relative>0)$due=max(time(),$starts)+(int)$relative*86400;$handler=(int)($in['handler_employee_id']??0);if($handler)app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$handler);
            if($due>0&&$due<=max(time(),$starts))throw new AdminException('截止时间须晚于开始时间及当前时间');
            $ids=[];$group='single:'.$in['idempotency_key'];
            foreach($employees as $employee){app()->make(GrowthAccessServices::class)->assertEmployee($c,$employee);$ids[]=$this->insertTask($c,$employee,['assignment_key'=>$group,'publication_id'=>(int)$p['id'],'title'=>trim((string)($in['title']??''))?:$p['title'],'kind'=>'course','required'=>($in['required']??true)?1:0,'handler_employee_id'=>$handler,'starts_at'=>$starts,'due_at'=>$due,'allow_late'=>(int)(bool)($in['allow_late']??true),'rules_json'=>GrowthCommandServices::json(['description'=>mb_substr((string)($in['description']??''),0,10000),'relative_days'=>(int)$relative])]);}
            return ['task_ids'=>$ids,'assigned_count'=>count($ids),'message'=>'学习任务已分配'];
        });
    }

    /** 由当前人员主档取发生时姓名与归属；后续调店不会重写这些历史维度。 */
    public function insertTask(array $c,int $employee,array $data): int
    {
        $person=Db::name('employee')->where('id',$employee)->where('status',1)->where('is_del',0)->find();if(!$person)throw new AdminException('接收员工已失效');
        $old=Db::name('growth_task')->where('assignment_key',$data['assignment_key'])->where('employee_id',$employee)->find();if($old)return (int)$old['id'];if(!empty($data['publication_id'])){$publication=Db::name('growth_publication')->where('id',$data['publication_id'])->where('state','PUBLISHED')->find();$content=$publication?Db::name('growth_content')->where('id',$publication['content_id'])->lock(true)->find():null;if(!$content||$content['state']==='STOPPED')throw new AdminException('课程已停用或发布内容不可用，不能新派发');}
        $staff=Db::name('system_store_staff')->where('employee_id',$employee)->where('status',1)->where('is_del',0)->order('id','desc')->find();
        $store=(int)($staff['store_id']??0);$org=$store?(int)Db::name('organization_store')->where('store_id',$store)->value('org_id'):(int)Db::name('organization_employee')->where('employee_id',$employee)->where('status',1)->where('is_del',0)->value('org_id');
        if(($data['source_type']??'')==='activity'){$activity=Db::name('growth_activity')->where('id',$data['source_id'])->find();$rules=GrowthCommandServices::decode((string)($data['rules_json']??'{}'));if($activity){$rules['activity_snapshot']=$activity;if($activity['capability_id'])$rules['capability_snapshot']=Db::name('growth_capability')->where('id',$activity['capability_id'])->find();}$data['rules_json']=GrowthCommandServices::json($rules);}
        if(app()->make(GrowthAccessServices::class)->hasTable('growth_point_rule')){
            // 派发时固定奖励规则；后台后续修改只影响新任务，不把旧待消费事件按新规则补奖。
            $rules=GrowthCommandServices::decode((string)($data['rules_json']??'{}'));
            $rules['point_rules']=Db::name('growth_point_rule')->where('enabled',1)->order('id')->select()->toArray();
            $rules['certificate_rules']=Db::name('growth_certificate_rule')->where('enabled',1)->order('id')->select()->toArray();
            // 非课程任务可明确选择本次奖励；课程继续以发布内容中的选择为准。
            $selection=$rules['reward_selection']??['mode'=>'default'];if(($selection['mode']??'default')==='selected'){$rules['point_rules']=array_values(array_filter($rules['point_rules'],static fn($r)=>in_array((int)$r['id'],$selection['point_rule_ids']??[],true)));$rules['certificate_rules']=array_values(array_filter($rules['certificate_rules'],static fn($r)=>in_array((int)$r['id'],$selection['certificate_rule_ids']??[],true)));}
            if(!empty($data['publication_id'])){$snapshot=GrowthCommandServices::decode($publication['snapshot_json']);$selected=$snapshot['reward_selection']??['mode'=>'default'];if($selected['mode']==='selected'){$rules['point_rules']=array_values(array_filter($rules['point_rules'],static fn($r)=>in_array((int)$r['id'],$selected['point_rule_ids'],true)));$rules['certificate_rules']=array_values(array_filter($rules['certificate_rules'],static fn($r)=>in_array((int)$r['id'],$selected['certificate_rule_ids'],true)));}$rules['reminder_overrides']=$snapshot['reminder_overrides']??[];$rules['rule_source']=$snapshot['rule_source']??['type'=>'enterprise'];}
            if(!empty($rules['self_practice'])){$rules['point_rules']=[];$rules['certificate_rules']=[];}
            $data['rules_json']=GrowthCommandServices::json($rules);
        }
        $id=(int)Db::name('growth_task')->insertGetId($data+['employee_id'=>$employee,'employee_name'=>$person['name'],'store_id'=>$store,'organization_id'=>$org,'assigned_by'=>$c['actor_key'],'assigned_at'=>time(),'rules_json'=>'{}']);
        // 同一次分配事务冻结发生时岗位，报表筛选不再回读员工调岗后的任职。
        $jobs=Db::name('staff_job_position')->alias('j')->join('position p','p.id=j.position_id')->where('j.employee_id',$employee)->where('j.store_id',$store)->where('j.status',1)->where('j.is_del',0)->where('j.end_time',0)->where('j.start_time','<=',time())->field('j.id,j.position_id,p.name')->select()->toArray();foreach($jobs as $job)Db::name('growth_task_job')->insert(['task_id'=>$id,'job_id'=>$job['id'],'position_id'=>$job['position_id'],'position_name'=>$job['name']]);
        if(!empty(app()->make(GrowthReminderServices::class)->taskPolicy($data)['assignment']))Db::name('growth_message')->insert(['employee_id'=>$employee,'source_key'=>'task:'.$id,'title'=>'新的成长任务','summary'=>$data['title'],'task_id'=>$id,'created_at'=>time()]);
        if(!empty($data['publication_id'])){$publication=Db::name('growth_publication')->where('id',$data['publication_id'])->find();if($publication)app()->make(GrowthCourseRulesServices::class)->attach($c,Db::name('growth_task')->where('id',$id)->find(),GrowthCommandServices::decode($publication['snapshot_json']));}
        app()->make(GrowthCommandServices::class)->event($c,'TASK_ASSIGNED','task',$id,['employee_id'=>$employee,'store_id'=>$store,'organization_id'=>$org]);
        return $id;
    }

    /** 原任务汇集冻结课程、学习、依赖与引用来源，结果失效不能显示已满足。 */
    public function detail(array $c,int $id): array
    {
        $task=$this->task($c,$id);$task['can_handle']=(int)$task['handler_employee_id']===$c['employee_id'];$task['is_learner']=(int)$task['employee_id']===$c['employee_id'];$task['rules']=GrowthCommandServices::decode($task['rules_json']);unset($task['rules_json']);
        $publication=Db::name('growth_publication')->where('id',$task['publication_id'])->find();
        $task['content']=$publication?GrowthCommandServices::decode($publication['snapshot_json']):null;
        if($task['content']){$task['content']['materials']=app()->make(GrowthContentServices::class)->materialSummaries($task['content']);$task['learning_complete']=app()->make(GrowthCourseRulesServices::class)->readingComplete($task,$task['content']);$task['content']=app()->make(GrowthCourseRulesServices::class)->publicContent($task,$task['content']);}$task['dependencies']=app()->make(GrowthCourseRulesServices::class)->dependencies($task);$task['reference_candidates']=$task['content']?app()->make(GrowthCourseRulesServices::class)->referenceCandidates($task,$task['content']):[];if($task['result_type']==='reference'){$task['reference']=Db::name('growth_result_reference')->where('id',$task['result_id'])->find();$origin=Db::name('growth_task')->where('id',(int)$task['reference']['source_task_id'])->find();$task['reference']['valid']=$origin&&$origin['state']==='COMPLETED';$task['learning_complete']=(bool)$task['reference']['valid'];$task['dependencies']=[];if($origin&&$task['content'])$task['content']=app()->make(GrowthCourseRulesServices::class)->publicContent($origin,$task['content']);}
        if($task['content']) foreach($task['content']['chapters'] as &$ch)if($ch['asset_id']>0){$a=Db::name('growth_asset')->where('id',$ch['asset_id'])->find();$ch['asset']=$a?app()->make(GrowthAssetServices::class)->summary($a):null;}
        if(in_array($task['kind'],['exam','practice'],true))$task['attempts']=Db::name('growth_attempt')->where('task_id',$id)->field('id,attempt_no,state,submitted_at')->order('id','desc')->select()->toArray();
        $task['history']=Db::name('growth_task_action')->where('task_id',$id)->field('id,action,reason,created_at,actor_key')->order('id','desc')->select()->toArray();
        $task['learning']=Db::name('growth_learning')->where('task_id',isset($task['reference'])?(int)$task['reference']['source_task_id']:$id)->select()->toArray();
        foreach($task['learning'] as &$r){$r['intervals']=GrowthCommandServices::decode($r['intervals_json']);unset($r['intervals_json']);}
        return $task;
    }

    /** 原任务可由发生范围或实际指派处理人访问，指派不授予员工其他资料。 */
    public function task(array $c,int $id,bool $lock=false): array
    {
        $q=Db::name('growth_task')->where('id',$id);if($lock)$q->lock(true);$t=$q->find();if(!$t)throw new AdminException('任务不存在');
        // 指派处理权只开放这一原任务，不授予该员工的其他历史或组织范围。
        if((int)$t['handler_employee_id']!==$c['employee_id'])app()->make(GrowthAccessServices::class)->assertTaskFact($c,$t);
        if(!$c['manager']&&(int)$t['employee_id']!==$c['employee_id']&&(int)$t['handler_employee_id']!==$c['employee_id'])throw new AdminException('任务不属于本人或未指派处理');
        return $t;
    }

    /** 暂停/取消等操作必须有业务原因；结束和通过结论分开，不提供手填完成按钮。 */
    public function lifecycle(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'task.lifecycle',$in,function()use($c,$in){
            $t=$this->task($c,(int)($in['task_id']??0),true);$action=(string)($in['action']??'');$reason=trim((string)($in['reason']??''));
            if($reason==='')throw new AdminException('请填写处理原因');
            if($t['state']==='CANCELLED'||($t['state']==='COMPLETED'&&$action!=='transfer'))throw new AdminException('任务已结束，新增要求请派发补充任务；仅处理职责可以交接');
            // 已开始练考保持原定时限，暂停会造成不同员工获得额外作答时间，故须先终止或等待结束。
            if($action==='pause'&&Db::name('growth_attempt')->whereIn('task_id',Db::name('growth_task_dependency')->where('parent_task_id',$t['id'])->column('child_task_id'))->whereIn('state',['ANSWERING','GRADING','HELD'])->find())throw new AdminException('关联练考已开始或等待公布，不能暂停主课程');
            if($action==='pause'&&in_array($t['kind'],['exam','practice'],true)&&Db::name('growth_attempt')->where('task_id',$t['id'])->whereIn('state',['ANSWERING','GRADING','HELD'])->find())throw new AdminException('练考已经开始或等待公布，不能暂停；可明确作废任务');
            if($action==='cancel'&&in_array($t['kind'],['exam','practice'],true))Db::name('growth_attempt')->where('task_id',$t['id'])->whereIn('state',['ANSWERING','GRADING','HELD'])->update(['state'=>'CANCELLED']);
            $change=[];
            if($action==='pause'&&in_array($t['state'],['NOT_STARTED','IN_PROGRESS'],true))$change=['state'=>'PAUSED'];
            elseif($action==='resume'&&$t['state']==='PAUSED')$change=['state'=>'IN_PROGRESS'];
            elseif($action==='cancel')$change=['state'=>'CANCELLED'];
            elseif($action==='extend'){if((int)($in['due_at']??0)<=max(time(),(int)$t['due_at']))throw new AdminException('新期限须晚于原期限和当前时间');$change=['due_at'=>(int)$in['due_at']];}
            elseif($action==='transfer'){app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,(int)($in['handler_employee_id']??0));$change=['handler_employee_id'=>(int)$in['handler_employee_id']];}
            else throw new AdminException('当前状态不支持此操作');
            if($action==='transfer'&&$t['kind']==='appraisal'){Db::name('growth_appraisal')->where('id',$t['source_id'])->update(['handler_employee_id'=>$change['handler_employee_id']]);Db::name('growth_objection')->where('appraisal_id',$t['source_id'])->where('state','OPEN')->update(['handler_employee_id'=>$change['handler_employee_id']]);}if($action==='transfer'&&$t['kind']==='rectification')Db::name('growth_issue')->where('id',$t['source_id'])->update(['reviewer_employee_id'=>$change['handler_employee_id']]);
            if($action==='extend'&&in_array($t['kind'],['exam','practice'],true))app()->make(GrowthExamServices::class)->extendWindow($t,(int)$change['due_at']);
            Db::name('growth_task')->where('id',$t['id'])->update($change);if($action==='cancel')$this->cancelChildren($c,(int)$t['id'],$reason);
            Db::name('growth_task_action')->insert(['task_id'=>$t['id'],'action'=>$action,'reason'=>$reason,'before_json'=>GrowthCommandServices::json($t),'after_json'=>GrowthCommandServices::json($change),'actor_key'=>$c['actor_key'],'created_at'=>time()]);
            return ['task_id'=>(int)$t['id']]+$change;
        });
    }

    /** 主课程作废或退出计划时停止其未结束后续事项，原正式结果不删除。 */
    public function cancelChildren(array $c,int $parent,string $reason): void
    {
        $ids=Db::name('growth_task_dependency')->where('parent_task_id',$parent)->order('child_task_id')->column('child_task_id');foreach($ids as $id){$t=Db::name('growth_task')->where('id',$id)->lock(true)->find();if(!$t||in_array($t['state'],['COMPLETED','CANCELLED'],true))continue;Db::name('growth_task')->where('id',$id)->update(['state'=>'CANCELLED']);Db::name('growth_attempt')->where('task_id',$id)->whereIn('state',['ANSWERING','GRADING','HELD'])->update(['state'=>'CANCELLED']);Db::name('growth_task_action')->insert(['task_id'=>$id,'action'=>'parent_cancelled','reason'=>$reason,'before_json'=>GrowthCommandServices::json($t),'after_json'=>'{"state":"CANCELLED"}','actor_key'=>$c['actor_key'],'created_at'=>time()]);}
    }

    /** 阅读确认保存在章节记录；全部必学章节满足后才能产生一次完成事件。 */
    public function learn(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'learning.save',$in,function()use($c,$in){
            $t=$this->task($c,(int)($in['task_id']??0),true);$this->assertExecutable($c,$t);
            $pub=Db::name('growth_publication')->where('id',$t['publication_id'])->find();$snapshot=GrowthCommandServices::decode((string)($pub['snapshot_json']??''));
            $chapter=null;foreach($snapshot['chapters']??[] as $ch)if($ch['key']===($in['chapter_key']??''))$chapter=$ch;if(!$chapter)throw new AdminException('章节不属于本次任务');
            $row=Db::name('growth_learning')->where('task_id',$t['id'])->where('chapter_key',$chapter['key'])->find();
            $now=time();$position=max(0,min(1000000,(int)($in['position']??0)));$confirmed=(int)($row['confirmed']??0);$intervals=GrowthCommandServices::decode((string)($row['intervals_json']??'[]'));
            $session=(string)($in['session_key']??'');if(!preg_match('/^[a-zA-Z0-9._:-]{8,100}$/D',$session))throw new AdminException('学习会话无效');
            if($row&&$row['session_key']!==$session&&empty($in['session_open'])&&($chapter['single_session']??true))throw new AdminException('学习已在另一页面继续，请重新打开本章后接管学习');
            if($chapter['completion']==='coverage'){
                $asset=Db::name('growth_asset')->where('id',$chapter['asset_id'])->find();$duration=(int)($asset['duration_seconds']??0);$position=min($position,$duration);
                $rate=(float)($in['playback_rate']??1);if(!in_array($rate,array_map('floatval',$chapter['allowed_rates']??[1]),true))$rate=1;
                if(($chapter['seek_policy']??'allow')==='watched_only'){$max=0;foreach($intervals as $segment)$max=max($max,(int)$segment[1]);$allowed=$max+min(15,max(0,$now-(int)($row['last_received_at']??$now)))*$rate+2;$position=min($position,(int)$allowed);}
                // 首次和换会话仅定位；上报间隔上限15秒，客户端位置不能凭快进获得覆盖。
                if($row&&$row['session_key']===$session&&(($in['foreground']??false)||!($chapter['foreground_required']??true))&&($in['playing']??false)&&empty($in['buffering'])&&(!($chapter['idle_prompt']??false)||($in['responsive']??false))&&((float)($in['playback_rate']??1)<=1.05||!empty($chapter['allow_speed']))){
                    $elapsed=min(15,max(0,$now-(int)$row['last_received_at']));$delta=$position-(int)$row['position'];
                    if($elapsed>0&&$delta>0&&$delta<=$elapsed*$rate+2)$intervals[]=[(int)$row['position'],$position];
                }
                usort($intervals,static fn($a,$b)=>$a[0]<=>$b[0]);$merged=[];foreach($intervals as $seg){$n=count($merged);if($n&&$seg[0]<=$merged[$n-1][1])$merged[$n-1][1]=max($merged[$n-1][1],$seg[1]);else $merged[]=$seg;}$intervals=$merged;
                $covered=0;foreach($intervals as $seg)$covered+=$seg[1]-$seg[0];if($duration>0&&100*$covered>=$duration*$chapter['coverage_percent'])$confirmed=1;foreach($chapter['critical_segments']??[] as $segment){$full=false;foreach($intervals as $interval)if($interval[0]<=$segment['start']&&$interval[1]>=$segment['end'])$full=true;if(!$full)$confirmed=0;}
            }elseif(!empty($in['confirm']))$confirmed=1;
            foreach($chapter['quizzes']??[] as $quiz)if($quiz['required']&&!app()->make(GrowthCourseRulesServices::class)->quizSatisfied((int)$t['id'],$chapter['key'],$quiz))$confirmed=0;
            // 每次有效请求保存序列来源，审计上报与合并后的覆盖结果保持可核对。
            $eventId=(int)Db::name('growth_learning_event')->insertGetId(['task_id'=>$t['id'],'chapter_key'=>$chapter['key'],'session_key'=>$session,'event_key'=>$in['idempotency_key'],'position'=>$position,'received_at'=>$now]);
            $lastSequence=(int)Db::name('growth_learning_sample')->where('task_id',$t['id'])->where('chapter_key',$chapter['key'])->where('session_key',$session)->max('sequence_no');$sequence=(int)($in['sequence_no']??($lastSequence+1));if($sequence<=$lastSequence)throw new AdminException('学习事件序号已使用，请重试原请求或重新打开章节');
            Db::name('growth_learning_sample')->insert(['event_id'=>$eventId,'task_id'=>$t['id'],'chapter_key'=>$chapter['key'],'session_key'=>$session,'sequence_no'=>$sequence,'snapshot_json'=>GrowthCommandServices::json(['playback_rate'=>(float)($in['playback_rate']??1),'foreground'=>(bool)($in['foreground']??false),'playing'=>(bool)($in['playing']??false),'buffering'=>(bool)($in['buffering']??false),'responsive'=>(bool)($in['responsive']??true),'client_time'=>(int)($in['client_time']??0),'received_at'=>$now,'position'=>$position])]);
            $values=['position'=>$position,'intervals_json'=>GrowthCommandServices::json($intervals),'confirmed'=>$confirmed,'session_key'=>$session,'last_received_at'=>$now,'updated_at'=>$now];
            if($row)Db::name('growth_learning')->where('id',$row['id'])->update($values);else Db::name('growth_learning')->insert($values+['task_id'=>$t['id'],'employee_id'=>$c['employee_id'],'chapter_key'=>$chapter['key']]);
            $done=Db::name('growth_learning')->where('task_id',$t['id'])->where('confirmed',1)->column('chapter_key');$all=true;foreach($snapshot['chapters'] as $ch)if($ch['required']&&!in_array($ch['key'],$done,true))$all=false;
            $reading=$all;$all=$all&&app()->make(GrowthCourseRulesServices::class)->satisfied($t);
            if($all)$this->complete($c,$t,'learning',(int)$t['id'],empty($snapshot['course_rules']['post_exam_id'])&&empty($snapshot['course_rules']['post_activity_id'])?'UNTESTED':'QUALIFIED');
            else Db::name('growth_task')->where('id',$t['id'])->update(['state'=>'IN_PROGRESS','started_at'=>$t['started_at']?:$now]);
            return ['task_id'=>(int)$t['id'],'chapter_key'=>$chapter['key'],'confirmed_position'=>$position,'chapter_complete'=>(bool)$confirmed,'learning_complete'=>$reading,'state'=>$all?'COMPLETED':'IN_PROGRESS'];
        });
    }

    /** 本人、起止时间、暂停作废及前序阶段都在正式执行前校验。 */
    public function assertExecutable(array $c,array $t): void
    {
        if((int)$t['employee_id']!==$c['employee_id'])throw new AdminException('只能由任务执行员工提交');
        if(!in_array($t['state'],['NOT_STARTED','IN_PROGRESS','RETURNED'],true))throw new AdminException('任务当前不可执行');
        if($t['due_at']>0&&$t['due_at']<time()&&!$t['allow_late'])throw new AdminException('已超过提交期限，请联系负责人');
        $this->assertPrerequisites($t);
    }

    /** 处理人已另行授权；确认活动仍须遵守开始时间、课程前置和培养阶段。 */
    public function assertPrerequisites(array $t): void
    {
        if($t['starts_at']>time())throw new AdminException('任务尚未开始');
        $parents=Db::name('growth_task_dependency')->where('child_task_id',$t['id'])->column('parent_task_id');foreach($parents as $parentId){$parent=Db::name('growth_task')->where('id',$parentId)->find();$publication=$parent?Db::name('growth_publication')->where('id',$parent['publication_id'])->find():null;if(!$parent||in_array($parent['state'],['PAUSED','CANCELLED'],true)||!$publication||!app()->make(GrowthCourseRulesServices::class)->readingComplete($parent,GrowthCommandServices::decode($publication['snapshot_json'])))throw new AdminException('请先完成关联课程的必学章节');}
        if($t['plan_id']>0){
            $plan=Db::name('growth_plan')->where('id',$t['plan_id'])->find();$participant=Db::name('growth_plan_employee')->where('plan_id',$t['plan_id'])->where('employee_id',$t['employee_id'])->find();
            if(!$plan||$plan['state']!=='ACTIVE'||!$participant||$participant['state']!=='ACTIVE')throw new AdminException('本培养计划已暂停或员工已退出');
            if($plan['sequential']&&$t['stage_no']>0){$prior=Db::name('growth_task')->where('plan_id',$t['plan_id'])->where('employee_id',$t['employee_id'])->where('stage_no','<',$t['stage_no'])->where('required',1)->where('state','<>','COMPLETED')->count();if($prior)throw new AdminException('请先完成前一阶段必做任务');}
        }
    }

    /** 调用方已锁任务且具备正式来源；唯一完成事件阻止奖励和档案重复生效。 */
    public function complete(array $c,array $t,string $type,int $result,string $conclusion): void
    {
        if($t['state']==='COMPLETED')return;
        Db::name('growth_task')->where('id',$t['id'])->update(['state'=>'COMPLETED','conclusion'=>$conclusion,'result_type'=>$type,'result_id'=>$result,'started_at'=>$t['started_at']?:time(),'completed_at'=>time()]);
        if(!Db::name('growth_event')->where('event_type','TASK_COMPLETED')->where('source_type','task')->where('source_id',$t['id'])->find())
        app()->make(GrowthCommandServices::class)->event($c,'TASK_COMPLETED','task',(int)$t['id'],['employee_id'=>(int)$t['employee_id'],'store_id'=>(int)$t['store_id'],'organization_id'=>(int)$t['organization_id'],'result_type'=>$type,'result_id'=>$result,'conclusion'=>$conclusion]);
        app()->make(GrowthCourseRulesServices::class)->syncParents($c,(int)$t['id']);app()->make(GrowthCourseRulesServices::class)->syncReferences($c,(int)$t['id']);
    }
}
