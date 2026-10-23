<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 人工答疑明确当前负责人；转交、回复和整理知识分别留痕，不自动编造答案。 */
final class GrowthHelpServices
{
    /** 提问和处理关系决定可见范围，同组织姓名候选不等于数据查看权。 */
    public function listing(array $c,array $in): array
    {
        $ids=$c['manager']?app()->make(GrowthAccessServices::class)->employees($c):app()->make(GrowthAccessServices::class)->contacts($c);$q=$this->visible($c);if(!empty($in['question_id']))$q->where('h.id',(int)$in['question_id']);
        if(!empty($in['state']))$q->where('h.state',(string)$in['state']);$total=$q->count();$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)($in['page']??1)));$rows=$q->field('h.*')->order('h.id','desc')->page($page,20)->select()->toArray();foreach($rows as &$r)$r['replies']=Db::name('growth_help_reply')->where('question_id',$r['id'])->order('id')->select()->toArray();
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'total_pages'=>$pages,'page_size'=>20,'operator_employee_id'=>$c['employee_id'],'employees'=>Db::name('employee')->whereIn('id',$ids)->where('status',1)->where('is_del',0)->field('id,name')->select()->toArray()];
    }
    /** 将实际问题交给所选联系人，关联任务必须本人有权读取。 */
    public function ask(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'help.ask',$in,function()use($c,$in){$task=(int)($in['task_id']??0);$handler=(int)($in['handler_employee_id']??0);if($task){$t=app()->make(GrowthTaskServices::class)->task($c,$task);if((int)$t['employee_id']!==$c['employee_id'])throw new AdminException('只能为本人的任务提问');$handler=$handler?:((int)$t['handler_employee_id']);}if(!in_array($handler,app()->make(GrowthAccessServices::class)->contacts($c),true))throw new AdminException('答疑负责人不在当前可联络范围');if(!Db::name('employee')->where('id',$handler)->where('status',1)->where('is_del',0)->find())throw new AdminException('答疑负责人已停用');$body=trim((string)($in['body']??''));if($body==='')throw new AdminException('请填写问题内容');$id=(int)Db::name('growth_help_question')->insertGetId(['employee_id'=>$c['employee_id'],'handler_employee_id'=>$handler,'task_id'=>$task,'body'=>mb_substr($body,0,10000),'created_at'=>time()]);$staff=$task?$t:Db::name('system_store_staff')->where('employee_id',$c['employee_id'])->where('status',1)->where('is_del',0)->order('id','desc')->find();$store=(int)($staff['store_id']??0);$org=$task?(int)$t['organization_id']:($store?(int)Db::name('organization_store')->where('store_id',$store)->value('org_id'):(int)Db::name('organization_employee')->where('employee_id',$c['employee_id'])->where('status',1)->where('is_del',0)->value('org_id'));Db::name('growth_help_scope')->insert(['question_id'=>$id,'employee_id'=>$c['employee_id'],'store_id'=>$store,'organization_id'=>$org]);$this->message($handler,$id,$task,'有新的成长问题待解答',$id);return ['id'=>$id,'message'=>'已交给指定负责人'];});
    }
    /** 当事人回复或当前处理人转交均保留历史；整理知识只生成待维护内容。 */
    public function reply(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'help.reply',$in,function()use($c,$in){$r=Db::name('growth_help_question')->where('id',(int)($in['id']??0))->lock(true)->find();if(!$r||!in_array($c['employee_id'],[(int)$r['handler_employee_id'],(int)$r['employee_id']],true)||$r['state']==='CLOSED')throw new AdminException('仅提问人或当前答疑负责人可补充、回复');$isHandler=(int)$r['handler_employee_id']===$c['employee_id'];if(!$isHandler&&(!empty($in['transfer_to'])||!empty($in['close'])))throw new AdminException('只有当前答疑负责人可转交或结束问题');$body=trim((string)($in['body']??''));if($body==='')throw new AdminException('请填写回复或转交说明');$transfer=(int)($in['transfer_to']??0);if($transfer){$contacts=$c['manager']?app()->make(GrowthAccessServices::class)->employees($c):app()->make(GrowthAccessServices::class)->contacts($c);if(!in_array($transfer,$contacts,true)||!Db::name('employee')->where('id',$transfer)->where('status',1)->where('is_del',0)->find())throw new AdminException('请选择有效且可联络的接收人');}$id=(int)Db::name('growth_help_reply')->insertGetId(['question_id'=>$r['id'],'employee_id'=>$c['employee_id'],'body'=>mb_substr($body,0,10000),'transfer_to'=>$transfer,'created_at'=>time()]);Db::name('growth_help_question')->where('id',$r['id'])->update($transfer?['handler_employee_id'=>$transfer,'state'=>'OPEN']:['state'=>!$isHandler?'OPEN':(!empty($in['close'])?'CLOSED':'ANSWERED')]);$this->message($transfer?:($isHandler?(int)$r['employee_id']:(int)$r['handler_employee_id']),$id,(int)$r['task_id'],$transfer?'收到转交的成长答疑':($isHandler?'你的成长问题已有回复':'提问人补充了成长问题'),(int)$r['id']);return ['id'=>$id,'message'=>$transfer?'问题已转交':($isHandler?'回复已保存':'补充说明已交给负责人')];});
    }
    /** 整理生成知识草稿，仍需按课程发布流程检查内容与适用范围，不直接公开问答。 */
    public function promote(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'help.promote',$in,function()use($c,$in){$q=$this->visible($c)->where('h.id',(int)($in['question_id']??0))->field('h.*')->lock(true)->find();if(!$q)throw new AdminException('问题不存在');if($q['knowledge_content_id'])return ['id'=>(int)$q['knowledge_content_id'],'message'=>'已整理过知识草稿'];$result=app()->make(GrowthContentServices::class)->save($c,['kind'=>'knowledge','title'=>(string)($in['title']??''),'category'=>(string)($in['category']??''),'description'=>'整理自人工答疑','chapters'=>[['type'=>'text','body'=>(string)($in['body']??''),'title'=>'专业解答']],'idempotency_key'=>'promote-'.$q['id']]);Db::name('growth_help_question')->where('id',$q['id'])->update(['knowledge_content_id'=>$result['id']]);return ['id'=>$result['id'],'message'=>'已生成知识草稿，请到课程与知识审核发布'];});
    }
    /** 指派只开放该问题；管理者按问题发生时归属读取，调店不迁入旧问答。 */
    private function visible(array $c)
    {
        $q=Db::name('growth_help_question')->alias('h')->leftJoin('growth_help_scope hs','hs.question_id=h.id');if(!$c['super'])$q->where(function($w)use($c){$w->where('h.employee_id',$c['employee_id'])->whereOr('h.handler_employee_id',$c['employee_id']);if($c['manager'])$w->whereOr(function($scope)use($c){app()->make(GrowthAccessServices::class)->taskScope($scope,$c,'hs');});});return $q;
    }
    /** 消息固定原问题目标；阅读已读状态不等于问题已经处理。 */
    private function message(int $employee,int $source,int $task,string $title,int $question): void
    {
        $id=(int)Db::name('growth_message')->insertGetId(['employee_id'=>$employee,'source_key'=>'help:'.$source.':'.substr(hash('sha256',$title),0,12),'title'=>$title,'summary'=>'进入成长答疑查看具体内容。','task_id'=>$task,'created_at'=>time()]);Db::name('growth_message_target')->insert(['message_id'=>$id,'target_type'=>'help','target_id'=>$question]);
    }
}
