<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;

/** 成长Outbox消费者；事件落库后可重试恢复，关闭模块时不继续写奖励或消息。 */
final class GrowthProcessServices
{
    /** 站点内只运行一个批次，避免多个调度进程同时写游标和通知。 */
    public function tick(int $limit=100): array
    {
        $name='growth-worker:'.substr(hash('sha256',(string)config('database.connections.mysql.database')),0,40);$r=Db::query('SELECT GET_LOCK(?,0) acquired',[$name]);if((int)($r[0]['acquired']??0)!==1)return ['state'=>'BUSY','processed'=>0];
        try{return $this->process($limit);}finally{Db::query('SELECT RELEASE_LOCK(?)',[$name]);}
    }
    private function process(int $limit): array
    {
        if(!app()->make(GrowthAccessServices::class)->isEnabled())return ['state'=>'DISABLED','processed'=>0];
        $onboarded=app()->make(GrowthOnboardingServices::class)->tick();
        $paused=app()->make(GrowthReminderServices::class)->pauseInactive();
        $timedOut=app()->make(GrowthExamServices::class)->expire();
        $rows=Db::name('growth_event')->whereIn('delivery_state',['PENDING','FAILED'])->order('id')->limit(max(1,min(500,$limit)))->select()->toArray();$done=0;$failed=[];
        foreach($rows as $row){
            if($row['delivery_state']==='FAILED'){$last=Db::name('growth_delivery_attempt')->where('event_id',$row['id'])->order('id','desc')->find();if($last&&(int)$last['created_at']>time()-30)continue;}
            try{Db::transaction(function()use($row){
                $event=Db::name('growth_event')->where('id',$row['id'])->lock(true)->find();if(!$event||$event['delivery_state']==='DONE')return;
                app()->make(GrowthAccessServices::class)->assertEnabled();app()->make(GrowthRewardServices::class)->consume($event);$this->message($event);
                Db::name('growth_event')->where('id',$event['id'])->update(['delivery_state'=>'DONE']);Db::name('growth_delivery_attempt')->insert(['event_id'=>$event['id'],'state'=>'DONE','created_at'=>time()]);
            });$done++;}catch(\Throwable $e){$failed[]=(int)$row['id'];Db::name('growth_event')->where('id',$row['id'])->update(['delivery_state'=>'FAILED']);Db::name('growth_delivery_attempt')->insert(['event_id'=>$row['id'],'state'=>'FAILED','error_message'=>mb_substr($e->getMessage(),0,500),'created_at'=>time()]);}
        }
        $expired=$this->expireCertificates();$reminded=app()->make(GrowthReminderServices::class)->tick();return ['state'=>'ACTIVE','processed'=>$done,'failed_event_ids'=>$failed,'expired_certificates'=>$expired,'expired_attempts'=>$timedOut,'sent_reminders'=>$reminded,'paused_inactive_tasks'=>$paused,'onboarding_dispatches'=>$onboarded];
    }
    private function message(array $event): void
    {
        $titles=['TASK_RESULT_CORRECTED'=>'关联结果变化，课程结论已更新','TASK_COMPLETED'=>'成长任务已完成','EXAM_PUBLISHED'=>'练考结果已公布','EVALUATION_CONFIRMED'=>'实操鉴定已有结果','WORK_SUBMITTED'=>'有作业等待点评','ISSUE_CREATED'=>'新的整改事项','RECTIFICATION_SUBMITTED'=>'整改等待复查','ISSUE_REVIEWED'=>'整改复查已有结果','GRADE_CORRECTED'=>'练考成绩已更正'];$type=$event['event_type'];if(!isset($titles[$type]))return;
        $payload=GrowthCommandServices::decode($event['payload_json']);$employee=(int)$event['employee_id'];$taskId=(int)($payload['task_id']??0);
        if($type==='TASK_COMPLETED')$taskId=(int)$event['source_id'];
        if(in_array($type,['EXAM_PUBLISHED','GRADE_CORRECTED'],true)){$attempt=Db::name('growth_attempt')->where('id',$type==='GRADE_CORRECTED'?(int)($payload['attempt_id']??0):$event['source_id'])->find();$taskId=(int)($attempt['task_id']??0);}
        if($type==='WORK_SUBMITTED'){$submission=Db::name('growth_submission')->where('id',$event['source_id'])->find();$taskId=(int)($submission['task_id']??0);$employee=(int)Db::name('growth_task')->where('id',$taskId)->value('handler_employee_id');}
        if($type==='RECTIFICATION_SUBMITTED'){$issue=Db::name('growth_issue')->where('id',(int)($payload['issue_id']??0))->find();$employee=(int)($issue['reviewer_employee_id']??0);$taskId=(int)($issue['task_id']??0);}
        if($type==='ISSUE_CREATED')$taskId=(int)Db::name('growth_issue')->where('id',$event['source_id'])->value('task_id');
        $task=$taskId?Db::name('growth_task')->where('id',$taskId)->find():[];if(empty(app()->make(GrowthReminderServices::class)->taskPolicy($task?:[])['result']))return;
        if($employee<=0||!Db::name('employee')->where('id',$employee)->where('status',1)->where('is_del',0)->find())return;
        $key='event:'.$event['id'];if(Db::name('growth_message')->where('employee_id',$employee)->where('source_key',$key)->find())return;
        Db::name('growth_message')->insert(['employee_id'=>$employee,'source_key'=>$key,'title'=>$titles[$type],'summary'=>'进入原业务查看当前真实状态与下一步安排。','task_id'=>$taskId,'created_at'=>time()]);
    }
    private function expireCertificates(): int
    {
        $rows=Db::name('growth_certificate')->where('state','VALID')->where('expires_at','>',0)->where('expires_at','<=',time())->order('id')->limit(100)->select()->toArray();$count=0;
        foreach($rows as $row)Db::transaction(function()use($row,&$count){$r=Db::name('growth_certificate')->where('id',$row['id'])->lock(true)->find();if(!$r||$r['state']!=='VALID'||$r['expires_at']>time())return;Db::name('growth_certificate')->where('id',$r['id'])->update(['state'=>'EXPIRED']);Db::name('growth_certificate_action')->insert(['certificate_id'=>$r['id'],'action'=>'expired','reason'=>'达到证书到期时间，等待复核','evidence_json'=>'{}','old_expires_at'=>$r['expires_at'],'new_expires_at'=>$r['expires_at'],'actor_key'=>'growth:expiry','created_at'=>time()]);$count++;});return $count;
    }
}
