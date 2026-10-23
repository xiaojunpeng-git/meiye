<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 课程互动题冻结统一题库，后续考试/实操引用原领域结果，不生成第二套成绩。 */
final class GrowthCourseRulesServices
{
    /** 学习规则与统一题库题目在本次发布内完整冻结，拒绝无效定位和规则。 */
    public function normalize(array $c,array $draft,array $in): array
    {
        $rules=(array)($in['course_rules']??[]);$exam=(int)($rules['post_exam_id']??0);$activity=(int)($rules['post_activity_id']??0);
        if($exam)app()->make(GrowthQuestionServices::class)->owned($c,'growth_exam',$exam);
        if($activity){$a=Db::name('growth_activity')->where('id',$activity)->find();if(!$a)throw new AdminException('关联的实操或带教活动不存在');if(!$c['super'])app()->make(GrowthAccessServices::class)->assertEmployee($c,(int)$a['owner_employee_id']);}
        $draft['course_rules']=['post_exam_id'=>$exam,'post_activity_id'=>$activity,'require_exam_passed'=>(bool)($rules['require_exam_passed']??true),'allow_result_reference'=>(bool)($rules['allow_result_reference']??false),'reference_days'=>max(0,min(3650,(int)($rules['reference_days']??0)))];
        foreach($draft['chapters'] as $i=>&$ch){$source=(array)($in['chapters'][$i]??[]);$quizzes=[];$asset=$ch['asset_id']?Db::name('growth_asset')->where('id',$ch['asset_id'])->find():null;
            foreach(array_values((array)($source['quizzes']??[])) as $n=>$q){$id=(int)($q['question_id']??0);$question=app()->make(GrowthQuestionServices::class)->owned($c,'growth_question',$id);if($question['state']!=='ACTIVE'||$question['type']==='text')throw new AdminException('随堂题请选择启用的客观题');$question['options']=GrowthCommandServices::decode($question['options_json']);$question['answer']=GrowthCommandServices::decode($question['answer_json']);unset($question['options_json'],$question['answer_json']);
                $key=(string)($q['key']??'quiz-'.($n+1));if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$key))throw new AdminException('随堂题标识无效');$position=(int)($q['position']??0);$kind=$ch['type']==='video'?'video_time':(in_array($ch['type'],['word','ppt','pdf'],true)?'document_page':'text_paragraph');$max=$kind==='video_time'?(int)($asset['duration_seconds']??0):($kind==='document_page'?(int)($asset['page_count']??0):max(1,count(preg_split('/\R/u',$ch['body']))));if($position<0||($max&&$position>$max))throw new AdminException('随堂题出现位置超出本章内容');$wrong=(string)($q['wrong_policy']??'retry');if(!in_array($wrong,['retry','review','continue'],true))throw new AdminException('随堂题答错处理无效');$analysis=(string)($q['analysis_timing']??'after_answer');if(!in_array($analysis,['after_answer','after_pass'],true))throw new AdminException('随堂题解析时机无效');$quizzes[]=['key'=>$key,'question_id'=>$id,'question'=>$question,'position_type'=>$kind,'position'=>$position,'required'=>(bool)($q['required']??true),'wrong_policy'=>$wrong,'analysis_timing'=>$analysis,'review_position'=>max(0,min($max,(int)($q['review_position']??0)))];}
            if(count($quizzes)>30||count(array_unique(array_column($quizzes,'key')))!==count($quizzes))throw new AdminException('每章最多30道不重复随堂题');$ch['quizzes']=$quizzes;
            $segments=[];foreach((array)($source['critical_segments']??[]) as $segment){$from=(int)($segment['start']??0);$to=(int)($segment['end']??0);if($ch['type']!=='video'||$from<0||$to<=$from||($asset&&$to>(int)$asset['duration_seconds']))throw new AdminException('关键视频片段起止无效');$segments[]=['start'=>$from,'end'=>$to];}$ch['critical_segments']=$segments;$ch['foreground_required']=(bool)($source['foreground_required']??true);$ch['allow_speed']=(bool)($source['allow_speed']??false);$ch['single_session']=(bool)($source['single_session']??true);$ch['seek_policy']=(string)($source['seek_policy']??'allow');if(!in_array($ch['seek_policy'],['allow','watched_only'],true))throw new AdminException('视频定位策略无效');$ch['idle_prompt']=(bool)($source['idle_prompt']??false);$ch['idle_seconds']=(int)($source['idle_seconds']??600);if($ch['idle_seconds']<60||$ch['idle_seconds']>3600)throw new AdminException('未响应等待时间为60至3600秒');$rates=array_values(array_unique(array_map('floatval',(array)($source['allowed_rates']??[1,1.25,1.5]))));foreach($rates as $rate)if(!in_array($rate,[0.5,0.8,1.0,1.25,1.5],true))throw new AdminException('倍速档位不受支持');$ch['allowed_rates']=$ch['allow_speed']?$rates:[1];
        }return $draft;
    }
    /** 关联活动创建同底层子任务，不复制考试、实操或评分实现。 */
    public function attach(array $c,array $task,array $snapshot): void
    {
        $rules=$snapshot['course_rules']??[];$service=app()->make(GrowthTaskServices::class);
        if(!empty($rules['post_exam_id'])){$e=Db::name('growth_exam')->where('id',(int)$rules['post_exam_id'])->where('state','OPEN')->find();if(!$e||($e['ends_at']&&$e['ends_at']<=time()))throw new AdminException('课后练考已不可用，请先调整课程关联');$id=$service->insertTask($c,(int)$task['employee_id'],['assignment_key'=>'course:'.$task['id'].':exam','title'=>$e['title'],'kind'=>$e['kind'],'source_type'=>'exam','source_id'=>$e['id'],'handler_employee_id'=>$e['grader_employee_id'],'starts_at'=>$task['starts_at'],'due_at'=>$e['ends_at']?($task['due_at']?min($task['due_at'],$e['ends_at']):$e['ends_at']):$task['due_at'],'rules_json'=>'{}']);$this->link((int)$task['id'],$id,'exam',(bool)$rules['require_exam_passed']);}
        if(!empty($rules['post_activity_id'])){$a=Db::name('growth_activity')->where('id',(int)$rules['post_activity_id'])->find();if(!$a)throw new AdminException('课程关联活动不存在');$id=$service->insertTask($c,(int)$task['employee_id'],['assignment_key'=>'course:'.$task['id'].':activity','title'=>$a['title'],'kind'=>$a['kind'],'source_type'=>'activity','source_id'=>$a['id'],'handler_employee_id'=>$a['handler_employee_id'],'starts_at'=>$task['starts_at'],'due_at'=>$task['due_at'],'rules_json'=>'{}']);$this->link((int)$task['id'],$id,'activity',$a['kind']==='evaluation');}
    }
    private function link(int $parent,int $child,string $type,bool $passed): void{Db::name('growth_task_dependency')->insert(['parent_task_id'=>$parent,'child_task_id'=>$child,'dependency_type'=>$type,'require_passed'=>$passed?1:0]);}
    /** 满足条件从关联正式结果读取；交卷与待阅卷不等于通过。 */
    public function dependencies(array $task): array
    {
        $rows=Db::name('growth_task_dependency')->alias('d')->join('growth_task t','t.id=d.child_task_id')->where('d.parent_task_id',$task['id'])->field('d.dependency_type,d.require_passed,t.id,t.title,t.kind,t.state,t.conclusion,t.source_id')->order('d.id')->select()->toArray();foreach($rows as &$r){$r['satisfied']=$r['state']==='COMPLETED'&&(!$r['require_passed']||in_array($r['conclusion'],['PASSED','QUALIFIED'],true));if($r['dependency_type']==='exam'&&!$r['require_passed'])$r['satisfied']=(bool)Db::name('growth_attempt')->where('task_id',$r['id'])->where('state','PUBLISHED')->find();}return $rows;
    }
    /** 仅必学章节确认参与阅读完成判定，播放器位置不作完成证明。 */
    public function readingComplete(array $task,array $snapshot): bool
    {
        $done=Db::name('growth_learning')->where('task_id',$task['id'])->where('confirmed',1)->column('chapter_key');foreach($snapshot['chapters']??[] as $ch)if($ch['required']&&!in_array($ch['key'],$done,true))return false;return true;
    }
    /** 整课完成必须同时满足已启用的后续条件。 */
    public function satisfied(array $task): bool{foreach($this->dependencies($task) as $d)if(!$d['satisfied'])return false;return true;}
    /** 随堂要求以正式尝试和错答策略判断，旧错误尝试保留。 */
    public function quizSatisfied(int $task,string $chapter,array $quiz): bool
    {
        $q=Db::name('growth_inline_attempt')->where('task_id',$task)->where('chapter_key',$chapter)->where('quiz_key',$quiz['key']);if($quiz['wrong_policy']!=='continue')$q->where('passed',1);return (bool)$q->find();
    }
    /** 对学习者剔除尚不可展示的答案与评分依据，只返回实际互动要求。 */
    public function publicContent(array $task,array $snapshot): array
    {
        foreach($snapshot['chapters'] as &$ch)foreach($ch['quizzes']??[] as $i=>$quiz){$satisfied=$this->quizSatisfied((int)$task['id'],$ch['key'],$quiz);$last=Db::name('growth_inline_attempt')->where('task_id',$task['id'])->where('chapter_key',$ch['key'])->where('quiz_key',$quiz['key'])->order('id','desc')->find();$ch['quizzes'][$i]['satisfied']=$satisfied;$ch['quizzes'][$i]['last_result']=$last?['id'=>(int)$last['id'],'passed'=>(bool)$last['passed'],'submitted_at'=>(int)$last['submitted_at']]:null;
                // 题干可用于触发，公布解析前不向美容师返回正确答案。
                unset($ch['quizzes'][$i]['question']['answer'],$ch['quizzes'][$i]['question']['basis'],$ch['quizzes'][$i]['question']['explanation']);}
        return $snapshot;
    }
    /** 每次随堂作答形成独立尝试，共用客观题精确判分口径。 */
    public function answer(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'inline.answer',$in,function()use($c,$in){$task=app()->make(GrowthTaskServices::class)->task($c,(int)($in['task_id']??0),true);app()->make(GrowthTaskServices::class)->assertExecutable($c,$task);$publication=Db::name('growth_publication')->where('id',$task['publication_id'])->find();$snapshot=GrowthCommandServices::decode($publication['snapshot_json']);$found=null;
            foreach($snapshot['chapters'] as $ch)if($ch['key']===($in['chapter_key']??''))foreach($ch['quizzes']??[] as $quiz)if($quiz['key']===($in['quiz_key']??''))$found=$quiz;if(!$found)throw new AdminException('随堂题不属于本次课程');$q=$found['question'];$answers=GrowthObjectiveScorer::normalize($q,(array)($in['answers']??[]));if(!$answers)throw new AdminException('请选择本题答案');$score=GrowthObjectiveScorer::score($q,$answers);$passed=$score===(int)$q['score'];$id=(int)Db::name('growth_inline_attempt')->insertGetId(['task_id'=>$task['id'],'chapter_key'=>(string)$in['chapter_key'],'quiz_key'=>$found['key'],'employee_id'=>$c['employee_id'],'question_id'=>$found['question_id'],'snapshot_json'=>GrowthCommandServices::json(['question'=>$q,'rule'=>$found,'answers'=>$answers]),'passed'=>$passed?1:0,'score'=>$score,'submitted_at'=>time()]);$r=['id'=>$id,'passed'=>$passed,'satisfied'=>$passed||$found['wrong_policy']==='continue','wrong_policy'=>$found['wrong_policy'],'review_position'=>$found['review_position'],'message'=>$passed?'答对了，继续学习吧':'再想一想，回看相关内容后继续尝试'];if($found['analysis_timing']==='after_answer'||$passed)$r+=['reference_answer'=>$q['answer'],'explanation'=>$q['explanation']];return $r;});
    }
    /** 只列本人相同发布内容且仍有效的原结果，不允许链式重复引用。 */
    public function referenceCandidates(array $task,array $snapshot): array
    {
        if(empty($snapshot['course_rules']['allow_result_reference'])||$task['state']==='COMPLETED')return [];$q=Db::name('growth_task')->where('employee_id',$task['employee_id'])->where('publication_id',$task['publication_id'])->where('id','<>',$task['id'])->where('state','COMPLETED')->where('result_type','<>','reference');$days=(int)($snapshot['course_rules']['reference_days']??0);if($days)$q->where('completed_at','>=',time()-$days*86400);$rows=$q->order('completed_at','desc')->limit(20)->select()->toArray();$out=[];foreach($rows as $row){$valid=true;foreach($this->dependencies($row) as $d){if(!$d['satisfied'])$valid=false;$child=Db::name('growth_task')->where('id',$d['id'])->find();if($child&&$child['result_type']==='evaluation'){$e=Db::name('growth_evaluation')->where('id',$child['result_id'])->find();if(!$e||($e['valid_until']&&$e['valid_until']<=time()))$valid=false;}}if($valid)$out[]=['id'=>(int)$row['id'],'title'=>$row['title'],'completed_at'=>(int)$row['completed_at'],'conclusion'=>$row['conclusion']];}return $out;
    }
    /** 只追加来源关系，不复制旧答卷、学习区间、鉴定或奖励；新任务另有明确完成事实。 */
    public function reference(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'course.reference',$in,function()use($c,$in){$service=app()->make(GrowthTaskServices::class);$task=$service->task($c,(int)($in['task_id']??0),true);$service->assertExecutable($c,$task);$pub=Db::name('growth_publication')->where('id',$task['publication_id'])->find();if(!$pub)throw new AdminException('本任务没有可引用内容');$snapshot=GrowthCommandServices::decode($pub['snapshot_json']);$source=(int)($in['source_task_id']??0);$candidates=$this->referenceCandidates($task,$snapshot);if(!in_array($source,array_column($candidates,'id'),true))throw new AdminException('原结果已失效或不满足本次相同发布内容的要求');$old=$service->task($c,$source,true);$id=(int)Db::name('growth_result_reference')->insertGetId(['task_id'=>$task['id'],'source_task_id'=>$source,'employee_id'=>$c['employee_id'],'reason'=>'本人确认引用相同发布内容的有效正式结果','actor_key'=>$c['actor_key'],'created_at'=>time()]);foreach($this->dependencies($task) as $d){$child=Db::name('growth_task')->where('id',$d['id'])->lock(true)->find();if(!$child||in_array($child['state'],['COMPLETED','CANCELLED'],true))continue;Db::name('growth_task')->where('id',$child['id'])->update(['state'=>'CANCELLED']);Db::name('growth_attempt')->where('task_id',$child['id'])->whereIn('state',['ANSWERING','GRADING','HELD'])->update(['state'=>'CANCELLED']);Db::name('growth_task_action')->insert(['task_id'=>$child['id'],'action'=>'result_referenced','reason'=>'主课程已引用原有效结果，不再重复执行关联任务','before_json'=>GrowthCommandServices::json($child),'after_json'=>'{"state":"CANCELLED"}','actor_key'=>$c['actor_key'],'created_at'=>time()]);}$service->complete($c,$task,'reference',$id,(string)$old['conclusion']);return ['id'=>$id,'message'=>'已引用原有效结果，不重复生成答卷、鉴定或奖励'];});
    }

    /** 被引用原结果更正时，引用任务暂停或恢复；不伪造新答卷，也不自动恢复人工作废事项。 */
    public function syncReferences(array $c,int $source): void
    {
        $origin=Db::name('growth_task')->where('id',$source)->find();if(!$origin)return;$references=Db::name('growth_result_reference')->where('source_task_id',$source)->order('task_id')->select()->toArray();
        foreach($references as $ref){$t=Db::name('growth_task')->where('id',$ref['task_id'])->lock(true)->find();if(!$t||$t['result_type']!=='reference'||!in_array($t['state'],['COMPLETED','PAUSED'],true))continue;$valid=$origin['state']==='COMPLETED';if(($t['state']==='COMPLETED')===$valid)continue;
            if($t['state']==='PAUSED'){$last=Db::name('growth_task_action')->where('task_id',$t['id'])->order('id','desc')->find();if(($last['action']??'')!=='reference_source_invalid')continue;}
            $change=['state'=>$valid?'COMPLETED':'PAUSED','conclusion'=>$valid?$origin['conclusion']:'FAILED','completed_at'=>$valid?$ref['created_at']:0];Db::name('growth_task')->where('id',$t['id'])->update($change);$action=(int)Db::name('growth_task_action')->insertGetId(['task_id'=>$t['id'],'action'=>$valid?'reference_source_restored':'reference_source_invalid','reason'=>$valid?'原正式结果恢复有效，引用关系恢复':'原正式结果已更正为未满足要求，暂停引用任务，等待负责人安排','before_json'=>GrowthCommandServices::json($t),'after_json'=>GrowthCommandServices::json($change),'actor_key'=>$c['actor_key'],'created_at'=>time()]);app()->make(GrowthCommandServices::class)->event($c,'TASK_RESULT_CORRECTED','task_action',$action,['task_id'=>(int)$t['id'],'employee_id'=>(int)$t['employee_id']]);
        }
    }

    /** 后续成绩更正会重算课程结论，原完成事件保留；奖励按独立更正事件冲正或恢复。 */
    public function syncParents(array $c,int $child): void
    {
        $parents=Db::name('growth_task_dependency')->where('child_task_id',$child)->column('parent_task_id');foreach($parents as $id){$task=Db::name('growth_task')->where('id',$id)->lock(true)->find();if(!$task||!in_array($task['state'],['NOT_STARTED','IN_PROGRESS','COMPLETED'],true))continue;$p=Db::name('growth_publication')->where('id',$task['publication_id'])->find();$valid=$p&&$this->readingComplete($task,GrowthCommandServices::decode($p['snapshot_json']))&&$this->satisfied($task);$was=$task['state']==='COMPLETED';if($valid===$was)continue;
            $hadCompletion=(bool)Db::name('growth_event')->where('event_type','TASK_COMPLETED')->where('source_type','task')->where('source_id',$task['id'])->find();
            if($valid)app()->make(GrowthTaskServices::class)->complete($c,$task,'learning',(int)$task['id'],'QUALIFIED');else Db::name('growth_task')->where('id',$task['id'])->update(['state'=>'IN_PROGRESS','conclusion'=>'FAILED','completed_at'=>0]);
            $this->syncReferences($c,(int)$task['id']);
            if($hadCompletion){$action=(int)Db::name('growth_task_action')->insertGetId(['task_id'=>$task['id'],'action'=>'linked_result_corrected','reason'=>'关联练考有效结果变化，重新核对课程完成条件','before_json'=>GrowthCommandServices::json($task),'after_json'=>GrowthCommandServices::json(['state'=>$valid?'COMPLETED':'IN_PROGRESS']),'actor_key'=>$c['actor_key'],'created_at'=>time()]);app()->make(GrowthCommandServices::class)->event($c,'TASK_RESULT_CORRECTED','task_action',$action,['task_id'=>(int)$task['id'],'employee_id'=>(int)$task['employee_id']]);}
        }
    }
}
