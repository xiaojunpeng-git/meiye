<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 带教、作业、实操与线下活动复用任务，各类正式业务结果保留自身记录。 */
final class GrowthPracticeServices
{
    /** 平台分范围查看实操，手机处理人只获得明确指派的待处理事项。 */
    public function listing(array $c,array $in=[]): array
    {
        $q=Db::name('growth_activity');if(!$c['super'])$q->whereIn('owner_employee_id',app()->make(GrowthAccessServices::class)->employees($c));
        [$activities,$activityPage]=GrowthPage::take($q,$in['activities_page']??1);
        $kinds=$c['manager']?['assignment','evaluation','coaching','offline']:['assignment','evaluation','coaching','offline','exam','practice','appraisal'];$pending=Db::name('growth_task')->whereIn('kind',$kinds)->whereNotIn('state',['COMPLETED','CANCELLED']);
        // 被指派人只获得所负责任务；不能再被个人数据范围过滤掉，也不扩张人员主档。
        if($c['manager'])$pending->where(function($w)use($c){$w->where(function($scope)use($c){app()->make(GrowthAccessServices::class)->taskScope($scope,$c);})->whereOr('handler_employee_id',$c['employee_id']);});else $pending->where('handler_employee_id',$c['employee_id']);
        [$pendingRows,$pendingPage]=GrowthPage::take($pending,$in['pending_page']??1);
        return ['activities'=>$activities,'pending'=>$pendingRows,'pagination'=>['activities'=>$activityPage,'pending'=>$pendingPage],'standards'=>app()->make(GrowthStandardServices::class)->listing($c)];
    }
    /** 活动引用同一员工、能力和检查标准，每人分配唯一任务。 */
    public function create(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'activity.create',$in,function()use($c,$in){
            $kind=(string)($in['kind']??'assignment');$title=trim((string)($in['title']??''));if(!in_array($kind,['assignment','evaluation','coaching','offline'],true)||$title==='')throw new AdminException('请选择活动类型并填写名称');
            $handler=(int)($in['handler_employee_id']??0);$confirmation=(bool)($in['requires_confirmation']??true);if($kind!=='assignment')$confirmation=true;if($confirmation)app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$handler);
            $standard=(int)($in['standard_release_id']??0);if($kind==='evaluation'&&!$standard)throw new AdminException('实操鉴定须选择检查标准');if($standard){$s=app()->make(GrowthStandardServices::class)->release($c,$standard);if($s['purpose']!=='evaluation')throw new AdminException('请选择实操用途的检查标准');}
            $count=(int)($in['required_submissions']??1);$retry=(int)($in['max_resubmissions']??2);$valid=(int)($in['valid_days']??0);if($count<1||$count>100||$retry<0||$retry>20||$valid<0)throw new AdminException('提交次数、补交次数或有效期无效');
            $due=(int)($in['due_at']??0);if($due&&$due<=time())throw new AdminException('截止时间须晚于当前时间');
            $capability=(int)($in['capability_id']??0);if($capability&&!Db::name('growth_capability')->where('id',$capability)->find())throw new AdminException('能力项不存在');
            $employees=array_values(array_unique(array_map('intval',(array)($in['employee_ids']??[]))));if(!$employees||count($employees)>500)throw new AdminException('请选择1至500名员工');
            $id=(int)Db::name('growth_activity')->insertGetId(['kind'=>$kind,'title'=>mb_substr($title,0,180),'description'=>mb_substr((string)($in['description']??''),0,10000),'standard_release_id'=>$standard,'capability_id'=>$capability,'handler_employee_id'=>$handler,'evidence_required'=>(int)!empty($in['evidence_required']),'requires_confirmation'=>$confirmation?1:0,'required_submissions'=>$count,'max_resubmissions'=>$retry,'due_at'=>$due,'valid_days'=>$valid,'location'=>mb_substr((string)($in['location']??''),0,255),'event_at'=>(int)($in['event_at']??0),'created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'created_at'=>time()]);
            $ids=[];foreach($employees as $employee){app()->make(GrowthAccessServices::class)->assertEmployee($c,$employee);$ids[]=app()->make(GrowthTaskServices::class)->insertTask($c,$employee,['assignment_key'=>'activity:'.$id,'title'=>$title,'kind'=>$kind,'source_type'=>'activity','source_id'=>$id,'handler_employee_id'=>$handler,'due_at'=>$due,'rules_json'=>'{}']);}
            return ['id'=>$id,'task_ids'=>$ids,'message'=>'活动任务已分配'];
        });
    }

    /** 通过原任务授权读取完整提交和评价历史，处理权仍由当前指派决定。 */
    public function detail(array $c,int $taskId): array
    {
        $t=app()->make(GrowthTaskServices::class)->task($c,$taskId);$a=$this->activity($t);
        $standard=$a['standard_release_id']?Db::name('growth_standard_release')->where('id',$a['standard_release_id'])->find():null;
        return ['task'=>$t,'activity'=>$a,'standard'=>$standard?GrowthCommandServices::decode($standard['snapshot_json']):null,'submissions'=>Db::name('growth_submission')->where('task_id',$taskId)->order('id','desc')->select()->toArray(),'evaluations'=>Db::name('growth_evaluation')->where('task_id',$taskId)->order('id','desc')->select()->toArray(),'attendance'=>Db::name('growth_attendance')->where('task_id',$taskId)->order('id','desc')->select()->toArray(),'coaching'=>Db::name('growth_coaching')->where('task_id',$taskId)->order('id','desc')->select()->toArray(),'can_handle'=>(int)$t['handler_employee_id']===$c['employee_id']];
    }

    /** 每次提交完整证据冻结；补交形成新记录，不能更新原作业内容。 */
    public function submit(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'work.submit',$in,function()use($c,$in){
            $t=app()->make(GrowthTaskServices::class)->task($c,(int)($in['task_id']??0),true);app()->make(GrowthTaskServices::class)->assertExecutable($c,$t);$a=$this->activity($t);
            if(!in_array($a['kind'],['assignment','evaluation'],true))throw new AdminException('当前活动不由学员提交作业');
            $previous=Db::name('growth_submission')->where('task_id',$t['id'])->order('id','desc')->find();if($previous&&$previous['state']==='PENDING')throw new AdminException('上一份提交正在等待处理');
            $no=(int)($previous['attempt_no']??0)+1;if($no>(int)$a['required_submissions']+(int)$a['max_resubmissions'])throw new AdminException('补交次数已用完');
            $body=trim((string)($in['body']??''));$assets=app()->make(GrowthAssetServices::class)->validateEvidence($c,(array)($in['asset_ids']??[]),(int)$t['id']);if(($a['evidence_required']&&!$assets)||($body===''&&!$assets))throw new AdminException('请提交要求的说明或证据');
            $snapshot=['body'=>mb_substr($body,0,10000),'asset_ids'=>$assets];$state=$a['requires_confirmation']?'PENDING':'ACCEPTED';
            $id=(int)Db::name('growth_submission')->insertGetId(['task_id'=>$t['id'],'employee_id'=>$c['employee_id'],'attempt_no'=>$no,'previous_submission_id'=>(int)($previous['id']??0),'snapshot_json'=>GrowthCommandServices::json($snapshot),'state'=>$state,'submitted_at'=>time(),'reviewer_employee_id'=>$t['handler_employee_id']]);
            app()->make(GrowthAssetServices::class)->reference($assets,'submission',$id,(int)$t['id']);
            app()->make(GrowthCommandServices::class)->event($c,'WORK_SUBMITTED','submission',$id,['employee_id'=>(int)$t['employee_id'],'task_id'=>(int)$t['id']]);
            if($a['requires_confirmation'])Db::name('growth_task')->where('id',$t['id'])->update(['state'=>'PENDING']);else $this->syncAssignment($c,$t,$a,$id);
            return ['id'=>$id,'state'=>$state,'message'=>$a['requires_confirmation']?'作业已提交，等待点评':'提交已记录'];
        });
    }

    /** 点评与鉴定只能由指派人执行；关键项、必评项和证据在服务端完整计算。 */
    public function review(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'work.review',$in,function()use($c,$in){
            $submission=Db::name('growth_submission')->where('id',(int)($in['submission_id']??0))->find();if(!$submission)throw new AdminException('作业不存在');$t=app()->make(GrowthTaskServices::class)->task($c,(int)$submission['task_id'],true);$a=$this->activity($t);$this->handler($c,$t);
            $submission=Db::name('growth_submission')->where('id',$submission['id'])->lock(true)->find();if($submission['state']!=='PENDING'||$t['state']!=='PENDING')throw new AdminException('本次提交已处理或任务已停止');app()->make(GrowthTaskServices::class)->assertPrerequisites($t);
            $note=trim((string)($in['note']??''));if($note==='')throw new AdminException('请填写点评或评价依据');$accepted=(bool)($in['accepted']??false);$result=['passed'=>$accepted,'note'=>$note];
            if($a['kind']==='evaluation'){
                $r=Db::name('growth_standard_release')->where('id',$a['standard_release_id'])->find();$snapshot=GrowthCommandServices::decode($r['snapshot_json']);$result=app()->make(GrowthStandardServices::class)->calculate($snapshot,(array)($in['items']??[]));$result['note']=$note;$result['capability_snapshot']=GrowthCommandServices::decode($t['rules_json'])['capability_snapshot']??null;
                foreach($result['items'] as $v)app()->make(GrowthAssetServices::class)->validateEvidence($c,(array)($v['asset_ids']??[]),(int)$t['id']);
                $previous=(int)Db::name('growth_evaluation')->where('task_id',$t['id'])->order('id','desc')->value('id');
                $evaluation=(int)Db::name('growth_evaluation')->insertGetId(['task_id'=>$t['id'],'employee_id'=>$t['employee_id'],'capability_id'=>$a['capability_id'],'standard_release_id'=>$a['standard_release_id'],'previous_evaluation_id'=>$previous,'preparation_json'=>$submission['snapshot_json'],'result_json'=>GrowthCommandServices::json($result),'conclusion'=>$result['passed']?'QUALIFIED':'NEED_PRACTICE','score'=>$result['score'],'evaluator_employee_id'=>$c['employee_id'],'occurred_at'=>time(),'valid_until'=>$a['valid_days']?time()+$a['valid_days']*86400:0]);
                $proof=[];foreach($result['items'] as $item)$proof=array_merge($proof,(array)($item['asset_ids']??[]));app()->make(GrowthAssetServices::class)->reference(array_values(array_unique($proof)),'evaluation',$evaluation,(int)$t['id']);$accepted=$result['passed'];app()->make(GrowthCommandServices::class)->event($c,'EVALUATION_CONFIRMED','evaluation',$evaluation,['employee_id'=>(int)$t['employee_id'],'passed'=>$accepted,'task_id'=>(int)$t['id']]);
                if($accepted)app()->make(GrowthTaskServices::class)->complete($c,$t,'evaluation',$evaluation,'QUALIFIED');
            }
            Db::name('growth_submission')->where('id',$submission['id'])->update(['state'=>$accepted?'ACCEPTED':'RETURNED','review_json'=>GrowthCommandServices::json($result),'reviewed_at'=>time()]);
            if(!$accepted)Db::name('growth_task')->where('id',$t['id'])->update(['state'=>'RETURNED','conclusion'=>$a['kind']==='evaluation'?'NEED_PRACTICE':'UNTESTED']);
            elseif($a['kind']==='assignment')$this->syncAssignment($c,$t,$a,(int)$submission['id']);
            return ['id'=>(int)$submission['id'],'accepted'=>$accepted,'message'=>$accepted?'已确认':'已退回，原提交与点评保留'];
        });
    }

    /** 带教和到场由指派负责人逐人确认，不复制视频观看率或替代个人鉴定。 */
    public function confirmActivity(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'activity.confirm',$in,function()use($c,$in){
            $t=app()->make(GrowthTaskServices::class)->task($c,(int)($in['task_id']??0),true);$a=$this->activity($t);$this->handler($c,$t);if(!in_array($t['state'],['NOT_STARTED','IN_PROGRESS'],true))throw new AdminException('任务当前不可确认');app()->make(GrowthTaskServices::class)->assertPrerequisites($t);
            $body=trim((string)($in['body']??''));if($body==='')throw new AdminException('请填写实际带教或活动说明');$assets=app()->make(GrowthAssetServices::class)->validateEvidence($c,(array)($in['asset_ids']??[]),(int)$t['id']);if($a['evidence_required']&&!$assets)throw new AdminException('请提供活动证据');
            $occurred=(int)($in['occurred_at']??time());if($occurred>time()+300||$occurred<=0)throw new AdminException('实际活动时间无效');
            if($a['kind']==='coaching'){$id=(int)Db::name('growth_coaching')->insertGetId(['task_id'=>$t['id'],'employee_id'=>$t['employee_id'],'mentor_employee_id'=>$c['employee_id'],'snapshot_json'=>GrowthCommandServices::json(['body'=>$body,'asset_ids'=>$assets,'problems'=>mb_substr((string)($in['problems']??''),0,10000),'comment'=>mb_substr((string)($in['comment']??''),0,10000),'next_steps'=>mb_substr((string)($in['next_steps']??''),0,10000)]),'occurred_at'=>$occurred,'recorded_at'=>time()]);$type='coaching';$complete=true;}
            elseif($a['kind']==='offline'){$previous=(int)Db::name('growth_attendance')->where('task_id',$t['id'])->order('id','desc')->value('id');$complete=!empty($in['attended']);$id=(int)Db::name('growth_attendance')->insertGetId(['task_id'=>$t['id'],'activity_id'=>$a['id'],'employee_id'=>$t['employee_id'],'previous_attendance_id'=>$previous,'attended'=>$complete?1:0,'evidence_json'=>GrowthCommandServices::json(['body'=>$body,'asset_ids'=>$assets]),'confirmer_employee_id'=>$c['employee_id'],'occurred_at'=>$occurred]);$type='attendance';}
            else throw new AdminException('当前活动不适用到场或带教确认');
            app()->make(GrowthAssetServices::class)->reference($assets,$type,$id,(int)$t['id']);app()->make(GrowthCommandServices::class)->event($c,'ACTIVITY_CONFIRMED',$type,$id,['employee_id'=>(int)$t['employee_id'],'task_id'=>(int)$t['id']]);if($complete)app()->make(GrowthTaskServices::class)->complete($c,$t,$type,$id,'UNTESTED');else Db::name('growth_task')->where('id',$t['id'])->update(['state'=>'IN_PROGRESS']);return ['id'=>$id,'completed'=>$complete,'message'=>'活动记录已保存'];
        });
    }
    private function activity(array $t): array{if($t['source_type']!=='activity')throw new AdminException('任务不是带教或实操活动');$a=Db::name('growth_activity')->where('id',$t['source_id'])->find();if(!$a)throw new AdminException('活动不存在');return $a;}
    private function handler(array $c,array $t): void{if((int)$t['handler_employee_id']!==$c['employee_id']||$c['employee_id']<=0)throw new AdminException('仅指派处理人可以确认');}
    private function syncAssignment(array $c,array $t,array $a,int $id): void{$done=Db::name('growth_submission')->where('task_id',$t['id'])->where('state','ACCEPTED')->count();if($done>=$a['required_submissions'])app()->make(GrowthTaskServices::class)->complete($c,$t,'submission',$id,'UNTESTED');else Db::name('growth_task')->where('id',$t['id'])->update(['state'=>'IN_PROGRESS']);}
}
