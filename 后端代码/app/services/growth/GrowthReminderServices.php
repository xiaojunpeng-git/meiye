<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;

/** 站内提醒只引用当前有效事项；来源、接收人和时间窗口唯一，已处理旧状态不再催办。 */
final class GrowthReminderServices
{
    /** 企业提醒策略复用单份设置，未设置项使用明确默认值。 */
    public function policy(): array
    {
        $saved=GrowthCommandServices::decode((string)Db::name('growth_setting')->where('id',1)->value('templates_json'));
        return array_replace(['assignment'=>true,'result'=>true,'due'=>true,'overdue'=>true,'pending'=>true,'certificate'=>true,'lead_hours'=>24,'frequency_hours'=>24,'recipient'=>'executor','summary'=>true],(array)($saved['reminders']??[]));
    }
    /** 已发布模板的覆盖项随任务固定；未覆盖项继续使用企业当前提醒策略。 */
    public function taskPolicy(array $task): array
    {
        $rules=GrowthCommandServices::decode((string)($task['rules_json']??'{}'));return array_replace($this->policy(),(array)($rules['reminder_overrides']??[]));
    }
    /** 仅对仍有效待办发送，来源和时间窗口去重，已结束事项不再催办。 */
    public function tick(): int
    {
        $policy=$this->policy();$count=0;$now=time();$period=max(3600,min(604800,(int)$policy['frequency_hours']*3600));$slot=(int)floor($now/$period);$lead=max(0,min(720,(int)$policy['lead_hours']))*3600;
        $global=$policy;$tasks=Db::name('growth_task')->alias('t')->join('employee e','e.id=t.employee_id')->where('e.status',1)->where('e.is_del',0)->whereNotIn('t.state',['COMPLETED','CANCELLED','PAUSED'])->where(function($q)use($now,$lead){$q->where('t.state','PENDING')->whereOr(function($w)use($now,$lead){$w->where('t.due_at','>',0)->where('t.due_at','<=',$now+720*3600);});})->field('t.*')->order('t.id')->select()->toArray();
        $groups=[];
        foreach($tasks as $t){$policy=$this->taskPolicy($t);$period=max(3600,min(604800,(int)$policy['frequency_hours']*3600));$slot=(int)floor($now/$period);$lead=max(0,min(720,(int)$policy['lead_hours']))*3600;if($t['state']!=='PENDING'&&(int)$t['due_at']>$now+$lead)continue;$kind=$t['state']==='PENDING'?'pending':((int)$t['due_at']<$now?'overdue':'due');if(empty($policy[$kind]))continue;$employee=$kind==='pending'?(int)$t['handler_employee_id']:(int)$t['employee_id'];
            if($kind!=='pending'&&$policy['recipient']==='handler'&&(int)$t['handler_employee_id']>0)$employee=(int)$t['handler_employee_id'];if(!$employee)continue;
            $key=$kind.':'.$t['id'].':'.$t['due_at'].':'.$slot;$groups[$employee.':'.$kind.':'.(!empty($policy['summary'])?'summary':'single')][]=['employee'=>$employee,'type'=>$kind,'key'=>$key,'task'=>$t,'summary'=>!empty($policy['summary'])];}
        foreach($groups as $items){$fresh=[];foreach($items as $r)if(!Db::name('growth_reminder')->where('source_key',$r['key'])->where('employee_id',$r['employee'])->find())$fresh[]=$r;if(!$fresh)continue;
            if(!empty($fresh[0]['summary'])){$count+=$this->send($fresh,true);}else foreach($fresh as $r)$count+=$this->send([$r],false);}
        $policy=$global;$period=max(3600,min(604800,(int)$policy['frequency_hours']*3600));$slot=(int)floor($now/$period);$lead=max(0,min(720,(int)$policy['lead_hours']))*3600;
        if(!empty($policy['certificate'])){$certs=Db::name('growth_certificate')->whereIn('state',['VALID','EXPIRED'])->where('expires_at','>',0)->where('expires_at','<=',$now+$lead)->select()->toArray();foreach($certs as $r){$key='certificate:'.$r['id'].':'.$r['expires_at'].':'.$slot;$proof=GrowthCommandServices::decode($r['evidence_json']);$recipients=array_unique([(int)$r['employee_id'],(int)($proof['rule_snapshot']['responsible_employee_id']??0)]);foreach($recipients as $employee)if($employee>0)$count+=$this->send([['employee'=>$employee,'type'=>'certificate','key'=>$key,'task'=>['id'=>0,'due_at'=>$r['expires_at'],'title'=>$r['title'],'certificate_owner_id'=>(int)$r['employee_id']]]],false);}}
        return $count;
    }
    private function send(array $rows,bool $summary): int
    {
        return Db::transaction(function()use($rows,$summary){$first=$rows[0];$employee=$first['employee'];Db::name('employee')->where('id',$employee)->lock(true)->find();if(!Db::name('employee')->where('id',$employee)->where('status',1)->where('is_del',0)->find())return 0;$fresh=[];
            foreach($rows as $r){if(Db::name('growth_reminder')->where('source_key',$r['key'])->where('employee_id',$employee)->find())continue;Db::name('growth_reminder')->insert(['source_key'=>$r['key'],'employee_id'=>$employee,'task_id'=>$r['task']['id'],'reminder_type'=>$r['type'],'due_at'=>$r['task']['due_at'],'state'=>'SENT','sent_at'=>time()]);$fresh[]=$r;}if(!$fresh)return 0;
            $title=['due'=>'成长任务临近截止','overdue'=>'成长任务已逾期','pending'=>'有成长事项待确认','certificate'=>'证书临近到期或待复核'][$first['type']];$text=$summary?'共'.count($fresh).'项：'.implode('、',array_slice(array_column(array_column($fresh,'task'),'title'),0,5)):$first['task']['title'];
            $messageId=(int)Db::name('growth_message')->insertGetId(['employee_id'=>$employee,'source_key'=>'reminder:'.substr(hash('sha256',implode('|',array_column($fresh,'key'))),0,48),'title'=>$title,'summary'=>mb_substr($text,0,500),'task_id'=>count($fresh)===1?$first['task']['id']:0,'created_at'=>time()]);if($first['type']==='certificate')Db::name('growth_message_target')->insert(['message_id'=>$messageId,'target_type'=>(int)($first['task']['certificate_owner_id']??0)===$employee?'certificate':'certificate_review','target_id'=>0]);elseif(count($fresh)>1)Db::name('growth_message_target')->insert(['message_id'=>$messageId,'target_type'=>$first['type']==='pending'?'pending':'tasks','target_id'=>0]);return count($fresh);
        });
    }
    /** 全局离职后停止执行与催办，保留归属和结果；复职不静默恢复曾暂停任务。 */
    public function pauseInactive(): int
    {
        $rows=Db::name('growth_task')->alias('t')->join('employee e','e.id=t.employee_id')->where(function($w){$w->where('e.status','<>',1)->whereOr('e.is_del',1);})->whereNotIn('t.state',['COMPLETED','CANCELLED','PAUSED'])->field('t.*')->select()->toArray();$n=0;
        foreach($rows as $row)Db::transaction(function()use($row,&$n){$t=Db::name('growth_task')->where('id',$row['id'])->lock(true)->find();if(!$t||in_array($t['state'],['COMPLETED','CANCELLED','PAUSED'],true))return;Db::name('growth_task')->where('id',$t['id'])->update(['state'=>'PAUSED']);Db::name('growth_task_action')->insert(['task_id'=>$t['id'],'action'=>'employee_inactive','reason'=>'权威员工主档已离职或停用，暂停执行和催办','before_json'=>GrowthCommandServices::json($t),'after_json'=>'{"state":"PAUSED"}','actor_key'=>'growth:personnel','created_at'=>time()]);$n++;});return $n;
    }
}
