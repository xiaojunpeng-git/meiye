<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 练考共用评分与答卷事务；题目、答案和结果权威值均在服务端冻结与计算。 */
final class GrowthExamServices
{
    /** 试卷与答卷分页读取已授权事实，历史归属不随调店迁移。 */
    public function listing(array $c,array $in=[]): array
    {
        [$rows,$examPage]=GrowthPage::take(app()->make(GrowthQuestionServices::class)->queryOwned($c,'growth_exam'),$in['exams_page']??1);
        foreach($rows as &$r){$r['rules']=GrowthCommandServices::decode($r['rules_json']);unset($r['rules_json']);}
        $ids=app()->make(GrowthAccessServices::class)->employees($c);
        $attemptQuery=Db::name('growth_attempt')->alias('a')->join('growth_task t','t.id=a.task_id');app()->make(GrowthAccessServices::class)->taskScope($attemptQuery,$c,'t');
        [$attempts,$attemptPage]=GrowthPage::take($attemptQuery->field('a.id,a.task_id,a.exam_id,a.employee_id,a.attempt_no,a.state,a.score,a.max_score,a.passed,a.started_at,a.submitted_at,t.employee_name,t.title'),$in['results_page']??1,'a.id');
        foreach($attempts as &$attempt){$adjust=Db::name('growth_grade_adjustment')->where('attempt_id',$attempt['id'])->order('id','desc')->find();if($adjust){$attempt['score']=$adjust['score'];$attempt['passed']=$adjust['passed'];}}
        return ['exams'=>$rows,'attempts'=>$attempts,'pagination'=>['exams'=>$examPage,'results'=>$attemptPage]];
    }

    /** 一次发布固定考试规则和应考名单；后续补考建立新尝试，不清空旧答卷。 */
    public function create(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'exam.create',$in,function()use($c,$in){
            $title=trim((string)($in['title']??''));if($title===''||mb_strlen($title)>180)throw new AdminException('请填写考试名称');
            $release=Db::name('growth_paper_release')->where('id',(int)($in['paper_release_id']??0))->find();if(!$release)throw new AdminException('请选择已发布试卷');
            app()->make(GrowthQuestionServices::class)->owned($c,'growth_paper',(int)$release['paper_id']);
            $paper=GrowthCommandServices::decode($release['snapshot_json']);$manual=false;foreach($paper['questions'] as $q)if($q['type']==='text')$manual=true;
            $grader=(int)($in['grader_employee_id']??0);if($manual&&$grader<=0)throw new AdminException('含简答题须指定阅卷人');if($grader)app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$grader);
            $max=GrowthCommandServices::integer($in['max_attempts']??1,1,20,'考试次数');$pass=GrowthCommandServices::integer($in['pass_score']??60,0,100000,'及格线');$minutes=GrowthCommandServices::integer($in['duration_minutes']??0,0,1440,'考试分钟');
            
            $starts=(int)($in['starts_at']??0);$ends=(int)($in['ends_at']??0);if($ends>0&&$ends<=max(time(),$starts))throw new AdminException('结束时间须晚于开始和当前时间');
            $kind=(string)($in['kind']??'exam');if(!in_array($kind,['exam','practice'],true))throw new AdminException('练考类型无效');
            $keys=array_values(array_unique(array_map('intval',(array)($in['key_question_ids']??[]))));if(array_diff($keys,array_column($paper['questions'],'id')))throw new AdminException('关键必过题须属于试卷');
            $minimum=app()->make(GrowthQuestionServices::class)->minimumScore($paper,$keys);
            if($pass>$minimum)throw new AdminException('及格线不能超过试卷可达到的满分');
            if(count($keys)>(int)$paper['draw_count'])throw new AdminException('关键必过题数量不能超过抽题数量');
            $timing=$kind==='practice'?(string)($in['practice_analysis_timing']??'after_submit'):'after_submit';if(!in_array($timing,['immediate','after_submit'],true))throw new AdminException('练习解析时机无效');
            $rules=['reward_selection'=>app()->make(GrowthSettingsServices::class)->rewards((array)($in['reward_selection']??['mode'=>'default'])),'practice_analysis_timing'=>$timing,'multiple_rule'=>'exact','retake_adoption'=>'highest','key_question_ids'=>$keys,'instructions'=>mb_substr((string)($in['instructions']??''),0,10000)];
            $row=['title'=>$title,'kind'=>$kind,'paper_release_id'=>$release['id'],'starts_at'=>$starts,'ends_at'=>$ends,'duration_seconds'=>$minutes*60,'max_attempts'=>$max,'pass_score'=>$pass,'grader_employee_id'=>$grader,'auto_publish'=>!empty($in['auto_publish'])?1:0,'show_analysis'=>!empty($in['show_analysis'])?1:0,'required_pass'=>isset($in['required_pass'])?(int)(bool)$in['required_pass']:1,'rules_json'=>GrowthCommandServices::json($rules),'created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'created_at'=>time()];
            if(!$row['auto_publish']&&$grader<=0)throw new AdminException('人工公布须指定成绩负责人');
            $employees=array_values(array_unique(array_map('intval',(array)($in['employee_ids']??[]))));if(!$employees||count($employees)>500)throw new AdminException('请选择1至500名应考员工');
            $exam=(int)Db::name('growth_exam')->insertGetId($row);$tasks=[];
            foreach($employees as $employee){app()->make(GrowthAccessServices::class)->assertEmployee($c,$employee);$tasks[]=app()->make(GrowthTaskServices::class)->insertTask($c,$employee,['assignment_key'=>'exam:'.$exam,'kind'=>$kind,'title'=>$title,'source_type'=>'exam','source_id'=>$exam,'starts_at'=>$starts,'due_at'=>$ends,'allow_late'=>0,'handler_employee_id'=>$grader,'rules_json'=>GrowthCommandServices::json($rules)]);}
            return ['id'=>$exam,'task_ids'=>$tasks,'message'=>'练考任务已发布'];
        });
    }

    /** 本人开启独立尝试并冻结题目，重开恢复同次答卷并更新作答会话。 */
    public function start(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'exam.start',$in,function()use($c,$in){
            $t=app()->make(GrowthTaskServices::class)->task($c,(int)($in['task_id']??0),true);$exam=$this->examForTask($t);$this->assertCanAnswer($c,$t,$exam);
            $ends=(int)($t['due_at']?:$exam['ends_at']);$old=Db::name('growth_attempt')->where('task_id',$t['id'])->order('attempt_no','desc')->lock(true)->find();
            if($old&&$old['state']==='ANSWERING'){
                if($old['deadline_at']>0&&$old['deadline_at']<=time()){$this->finalize($c,$t,$exam,$old,GrowthCommandServices::decode($old['draft_answers_json']));return $this->view($c,(int)$old['id']);}
                $nonce=bin2hex(random_bytes(24));Db::name('growth_attempt')->where('id',$old['id'])->update(['answer_session_key'=>$nonce]);return $this->view($c,(int)$old['id']);
            }
            if($old&&in_array($old['state'],['GRADING','HELD'],true))throw new AdminException('上一份答卷正在处理，请等待成绩');
            $no=(int)($old['attempt_no']??0)+1;if($no>(int)$exam['max_attempts'])throw new AdminException('本次允许次数已用完');
            if($exam['state']!=='OPEN'||($exam['starts_at']>time())||($ends>0&&$ends<=time()))throw new AdminException('不在允许考试时间内');
            $release=Db::name('growth_paper_release')->where('id',$exam['paper_release_id'])->find();$paper=GrowthCommandServices::decode($release['snapshot_json']);$questions=$paper['questions'];
            $rules=GrowthCommandServices::decode($exam['rules_json']);$questions=app()->make(GrowthQuestionServices::class)->drawQuestions($paper,$rules['key_question_ids']??[]);
            $total=array_sum(array_map('intval',array_column($questions,'score')));if($exam['pass_score']>$total)throw new AdminException('及格线超过本次试卷满分，请联系负责人');
            $deadline=$exam['duration_seconds']>0?time()+(int)$exam['duration_seconds']:0;if($ends>0)$deadline=$deadline?min($deadline,$ends):$ends;
            $id=(int)Db::name('growth_attempt')->insertGetId(['task_id'=>$t['id'],'exam_id'=>$exam['id'],'employee_id'=>$c['employee_id'],'attempt_no'=>$no,'previous_attempt_id'=>(int)($old['id']??0),'questions_json'=>GrowthCommandServices::json($questions),'draft_answers_json'=>'{}','answer_session_key'=>bin2hex(random_bytes(24)),'started_at'=>time(),'deadline_at'=>$deadline,'max_score'=>$total]);
            Db::name('growth_task')->where('id',$t['id'])->update(['state'=>$t['state']==='COMPLETED'?'COMPLETED':'IN_PROGRESS','started_at'=>$t['started_at']?:time()]);return $this->view($c,$id);
        });
    }

    /** 自动保存与交卷均先锁任务再锁尝试，冻结后所有迟到保存明确拒绝。 */
    public function answer(array $c,array $in,bool $submit): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,$submit?'exam.submit':'exam.save',$in,function()use($c,$in,$submit){
            $a=Db::name('growth_attempt')->where('id',(int)($in['attempt_id']??0))->find();if(!$a)throw new AdminException('答卷不存在');
            $t=app()->make(GrowthTaskServices::class)->task($c,(int)$a['task_id'],true);$a=Db::name('growth_attempt')->where('id',$a['id'])->lock(true)->find();
            if((int)$a['employee_id']!==$c['employee_id'])throw new AdminException('仅本人可以作答');
            if($a['state']!=='ANSWERING')throw new AdminException('答卷已经冻结，请查看正式结果');
            if(!hash_equals($a['answer_session_key'],(string)($in['answer_session_key']??'')))throw new AdminException('作答会话已切换，请重新打开本次答卷');
            $exam=$this->examForTask($t);
            if(in_array($t['state'],['PAUSED','CANCELLED'],true))throw new AdminException('任务已暂停或作废，不能提交答卷');
            if($a['deadline_at']>0&&$a['deadline_at']<=time()){$this->finalize($c,$t,$exam,$a,GrowthCommandServices::decode($a['draft_answers_json']));return $this->view($c,(int)$a['id']);}
            $this->assertCanAnswer($c,$t,$exam);
            $answers=$this->answers(GrowthCommandServices::decode($a['questions_json']),(array)($in['answers']??[]));
            if($submit)$this->finalize($c,$t,$exam,$a,$answers);else Db::name('growth_attempt')->where('id',$a['id'])->update(['draft_answers_json'=>GrowthCommandServices::json($answers),'saved_at'=>time()]);
            return $this->view($c,(int)$a['id']);
        });
    }

    /** 即时解析仅供明确启用的练习本人；不保存正式得分、不解锁任务，也不放宽正式考试。 */
    public function practiceFeedback(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'practice.feedback',$in,function()use($c,$in){
            $a=Db::name('growth_attempt')->where('id',(int)($in['attempt_id']??0))->find();if(!$a)throw new AdminException('练习答卷不存在');$task=app()->make(GrowthTaskServices::class)->task($c,(int)$a['task_id'],true);$a=Db::name('growth_attempt')->where('id',$a['id'])->lock(true)->find();$exam=$this->examForTask($task);$rules=GrowthCommandServices::decode($exam['rules_json']);
            if((int)$a['employee_id']!==$c['employee_id']||$exam['kind']!=='practice'||($rules['practice_analysis_timing']??'')!=='immediate')throw new AdminException('当前练考未开放即时解析');if($a['state']!=='ANSWERING'||!hash_equals($a['answer_session_key'],(string)($in['answer_session_key']??'')))throw new AdminException('本次作答已结束或会话已切换');$this->assertCanAnswer($c,$task,$exam);if($a['deadline_at']&&$a['deadline_at']<=time())throw new AdminException('答题时间已结束，请查看正式结果');
            $question=null;foreach(GrowthCommandServices::decode($a['questions_json']) as $q)if((int)$q['id']===(int)($in['question_id']??0))$question=$q;if(!$question||$question['type']==='text')throw new AdminException('请选择本次练习的客观题；简答仍由指派人评价');$score=GrowthObjectiveScorer::score($question,$in['answer']??[]);return ['question_id'=>(int)$question['id'],'passed'=>$score===(int)$question['score'],'score'=>$score,'answer'=>$question['answer'],'explanation'=>$question['explanation'],'message'=>$score===(int)$question['score']?'这题答对了，继续保持':'看看解析，把这一步练扎实'];
        });
    }

    /** 只有本人或指派阅卷人能看答卷；公布前不向员工返回分数、答案或解析。 */
    public function view(array $c,int $id): array
    {
        $a=Db::name('growth_attempt')->where('id',$id)->find();if(!$a)throw new AdminException('答卷不存在');$t=app()->make(GrowthTaskServices::class)->task($c,(int)$a['task_id']);$exam=$this->examForTask($t);$ends=(int)($t['due_at']?:$exam['ends_at']);
        $grader=(int)$t['handler_employee_id']===(int)$c['employee_id']&&$c['employee_id']>0;
        if(!$c['manager']&&!$grader&&(int)$a['employee_id']!==$c['employee_id'])throw new AdminException('未指派此答卷');
        $questions=GrowthCommandServices::decode($a['questions_json']);$revealed=$a['state']==='PUBLISHED';$show=$c['manager']||$grader||($revealed&&$exam['show_analysis']);
        foreach($questions as &$q){unset($q['created_by'],$q['owner_employee_id']);if(!$show)unset($q['answer'],$q['basis'],$q['explanation']);}
        $r=['practice_analysis_timing'=>$exam['kind']==='practice'?(GrowthCommandServices::decode($exam['rules_json'])['practice_analysis_timing']??'after_submit'):'after_submit','self_practice'=>(bool)(GrowthCommandServices::decode($exam['rules_json'])['self_practice']??false),'can_handle'=>$grader,'id'=>(int)$a['id'],'task_id'=>(int)$a['task_id'],'title'=>$exam['title'],'kind'=>$exam['kind'],'state'=>$a['state'],'attempt_no'=>(int)$a['attempt_no'],'max_attempts'=>(int)$exam['max_attempts'],'max_score'=>(int)$a['max_score'],'deadline_at'=>(int)$a['deadline_at'],'server_time'=>time(),'questions'=>$questions,'answers'=>GrowthCommandServices::decode($a['state']==='ANSWERING'?$a['draft_answers_json']:(string)$a['final_answers_json']),'saved_at'=>(int)$a['saved_at'],'instructions'=>GrowthCommandServices::decode($exam['rules_json'])['instructions']??'','can_retake'=>$revealed&&!$a['passed']&&$a['attempt_no']<$exam['max_attempts']&&(!$ends||$ends>time())];
        if($a['state']==='ANSWERING'&&(int)$a['employee_id']===$c['employee_id'])$r['answer_session_key']=$a['answer_session_key'];
        if($revealed||$c['manager']||$grader){$adjust=Db::name('growth_grade_adjustment')->where('attempt_id',$id)->order('id','desc')->find();$r['score']=(int)($adjust['score']??$a['score']);$r['passed']=(bool)($adjust['passed']??$a['passed']);$r['grades']=GrowthCommandServices::decode((string)($adjust['grades_json']??$a['grades_json']));}
        if($revealed&&isset($r['passed']))$r['can_retake']=!$r['passed']&&$a['attempt_no']<$exam['max_attempts']&&(!$ends||$ends>time());
        if(in_array($t['state'],['PAUSED','CANCELLED'],true))$r['can_retake']=false;
        return $r;
    }

    /** 当前指派人按冻结规则评分；正式更正追加记录并重新采用有效成绩。 */
    public function grade(array $c,array $in,bool $correction=false): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,$correction?'exam.correct':'exam.grade',$in,function()use($c,$in,$correction){
            $a=Db::name('growth_attempt')->where('id',(int)($in['attempt_id']??0))->find();if(!$a)throw new AdminException('答卷不存在');$t=app()->make(GrowthTaskServices::class)->task($c,(int)$a['task_id'],true);$a=Db::name('growth_attempt')->where('id',$a['id'])->lock(true)->find();$exam=$this->examForTask($t);
            if((int)$t['handler_employee_id']!==(int)$c['employee_id']||$c['employee_id']<=0)throw new AdminException('仅指派阅卷人可以确认或更正成绩');
            if(in_array($t['state'],['PAUSED','CANCELLED'],true))throw new AdminException('任务已暂停或作废，不能确认成绩');
            if(!$correction&&!in_array($a['state'],['GRADING','HELD'],true))throw new AdminException('当前答卷不等待阅卷或公布');
            if($correction&&($a['state']!=='PUBLISHED'||trim((string)($in['reason']??''))===''))throw new AdminException('已公布成绩更正须填写原因');
            [$score,$passed,$grades]=$this->score($exam,GrowthCommandServices::decode($a['questions_json']),GrowthCommandServices::decode((string)$a['final_answers_json']),(array)($in['grades']??[]),true);
            if($correction){$prior=(int)Db::name('growth_grade_adjustment')->where('attempt_id',$a['id'])->order('id','desc')->value('id');$adjust=(int)Db::name('growth_grade_adjustment')->insertGetId(['attempt_id'=>$a['id'],'previous_adjustment_id'=>$prior,'score'=>$score,'passed'=>$passed?1:0,'grades_json'=>GrowthCommandServices::json($grades),'reason'=>$in['reason'],'actor_key'=>$c['actor_key'],'created_at'=>time()]);app()->make(GrowthCommandServices::class)->event($c,'GRADE_CORRECTED','grade_adjustment',$adjust,['employee_id'=>(int)$a['employee_id'],'attempt_id'=>(int)$a['id'],'score'=>$score,'passed'=>$passed]);}
            else {Db::name('growth_attempt')->where('id',$a['id'])->update(['score'=>$score,'passed'=>$passed?1:0,'grades_json'=>GrowthCommandServices::json($grades),'state'=>'PUBLISHED','published_at'=>time()]);app()->make(GrowthCommandServices::class)->event($c,'EXAM_PUBLISHED','attempt',(int)$a['id'],['employee_id'=>(int)$a['employee_id'],'score'=>$score,'passed'=>$passed]);}
            $this->syncTask($c,$t,$exam);app()->make(GrowthCourseRulesServices::class)->syncParents($c,(int)$t['id']);return $this->view($c,(int)$a['id']);
        });
    }

    /** 任务延期只调整该员工的考试窗口，原定限时不增加；历史截止由任务操作记录保留。 */
    public function extendWindow(array $task,int $due): void
    {
        $exam=$this->examForTask($task);$a=Db::name('growth_attempt')->where('task_id',$task['id'])->where('state','ANSWERING')->lock(true)->find();if(!$a)return;$deadline=$exam['duration_seconds']>0?min($due,(int)$a['started_at']+(int)$exam['duration_seconds']):$due;if($deadline>(int)$a['deadline_at'])Db::name('growth_attempt')->where('id',$a['id'])->update(['deadline_at'=>$deadline]);
    }

    /** 定时器只冻结服务端已保存答案；离线、关页或失去前台均不会无限延长考试。 */
    public function expire(int $limit=100): int
    {
        $rows=Db::name('growth_attempt')->where('state','ANSWERING')->where('deadline_at','>',0)->where('deadline_at','<=',time())->order('id')->limit($limit)->select()->toArray();$count=0;
        foreach($rows as $row)Db::transaction(function()use($row,&$count){
            $task=Db::name('growth_task')->where('id',$row['task_id'])->lock(true)->find();$a=Db::name('growth_attempt')->where('id',$row['id'])->lock(true)->find();
            if(!$task||!$a||$a['state']!=='ANSWERING'||$a['deadline_at']>time()||in_array($task['state'],['PAUSED','CANCELLED'],true))return;
            $c=['actor_key'=>'growth:deadline','employee_id'=>(int)$a['employee_id'],'organization_id'=>(int)$task['organization_id'],'store_id'=>(int)$task['store_id']];
            $this->finalize($c,$task,$this->examForTask($task),$a,GrowthCommandServices::decode($a['draft_answers_json']));$count++;
        });return $count;
    }

    private function finalize(array $c,array $task,array $exam,array $a,array $answers): void
    {
        $questions=GrowthCommandServices::decode($a['questions_json']);$manual=false;foreach($questions as $q)if($q['type']==='text')$manual=true;
        [$score,$passed,$grades]=$this->score($exam,$questions,$answers,[],false);
        $state=$manual?'GRADING':($exam['auto_publish']?'PUBLISHED':'HELD');
        Db::name('growth_attempt')->where('id',$a['id'])->where('state','ANSWERING')->update(['final_answers_json'=>GrowthCommandServices::json($answers),'draft_answers_json'=>'{}','grades_json'=>GrowthCommandServices::json($grades),'score'=>$score,'passed'=>$passed?1:0,'state'=>$state,'submitted_at'=>time(),'published_at'=>$state==='PUBLISHED'?time():0]);
        app()->make(GrowthCommandServices::class)->event($c,'EXAM_SUBMITTED','attempt',(int)$a['id'],['employee_id'=>(int)$a['employee_id'],'state'=>$state]);
        if($state==='PUBLISHED')app()->make(GrowthCommandServices::class)->event($c,'EXAM_PUBLISHED','attempt',(int)$a['id'],['employee_id'=>(int)$a['employee_id'],'task_id'=>(int)$task['id'],'score'=>$score,'passed'=>$passed]);
        if($state==='PUBLISHED'){$this->syncTask($c,$task,$exam);app()->make(GrowthCourseRulesServices::class)->syncParents($c,(int)$task['id']);}elseif($task['state']!=='COMPLETED')Db::name('growth_task')->where('id',$task['id'])->update(['state'=>'PENDING']);
    }

    /** 最高有效成绩采用规则在后端统一执行；调整留历史，任务结果据有效来源重新关联。 */
    private function syncTask(array $c,array $task,array $exam): void
    {
        $attempts=Db::name('growth_attempt')->where('task_id',$task['id'])->where('state','PUBLISHED')->order('id')->select()->toArray();$best=null;
        foreach($attempts as $a){$adjust=Db::name('growth_grade_adjustment')->where('attempt_id',$a['id'])->order('id','desc')->find();if($adjust){$a['score']=$adjust['score'];$a['passed']=$adjust['passed'];}if(!$best||$a['score']>$best['score']||($a['score']==$best['score']&&$a['passed']&&!$best['passed']))$best=$a;}
        if(!$best)return;
        $complete=$best['passed']||!$exam['required_pass'];$conclusion=$best['passed']?'PASSED':'FAILED';
        if($complete&&$task['state']!=='COMPLETED')app()->make(GrowthTaskServices::class)->complete($c,$task,'attempt',(int)$best['id'],$conclusion);
        else Db::name('growth_task')->where('id',$task['id'])->update(['state'=>$complete?'COMPLETED':'IN_PROGRESS','conclusion'=>$conclusion,'result_type'=>'attempt','result_id'=>$best['id'],'completed_at'=>$complete?($task['completed_at']?:time()):0]);
    }

    private function score(array $exam,array $questions,array $answers,array $manual,bool $requireManual): array
    {
        $total=0;$grades=[];$keyPass=true;$rules=GrowthCommandServices::decode($exam['rules_json']);
        foreach($questions as $q){$id=(string)$q['id'];$value=$answers[$id]??($q['type']==='text'?'':[]);$score=0;$note='';
            if($q['type']==='text'){
                if($requireManual){$g=$manual[$id]??null;if(!is_array($g)||!isset($g['score'])||filter_var($g['score'],FILTER_VALIDATE_INT)===false)throw new AdminException('请为每道简答题填写整数分数');$score=(int)$g['score'];$note=trim((string)($g['note']??''));if($score<0||$score>(int)$q['score']||$note==='')throw new AdminException('简答得分须在题目分值内，并填写评分依据');}
            }else{
                $score=GrowthObjectiveScorer::score($q,$value);
            }
            $total+=$score;$grades[$id]=['score'=>$score,'max_score'=>(int)$q['score'],'note'=>$note];if(in_array((int)$q['id'],$rules['key_question_ids']??[],true)&&$score<(int)$q['score'])$keyPass=false;
        }
        return [$total,$total>=(int)$exam['pass_score']&&$keyPass,$grades];
    }
    private function answers(array $questions,array $input): array
    {
        $r=[];foreach($questions as $q){$key=(string)$q['id'];$v=$input[$key]??($q['type']==='text'?'':[]);if($q['type']==='text')$r[$key]=mb_substr((string)$v,0,10000);else{
                $v=GrowthObjectiveScorer::normalize($q,$v);
                $r[$key]=$v;
            }}return $r;
    }
    /** 完成即可的检验仍可按次数补练；新尝试不撤回已经形成的有效完成事实。 */
    private function assertCanAnswer(array $c,array $task,array $exam): void
    {
        $execution=$task;if($task['state']==='COMPLETED'&&!$exam['required_pass']&&$task['conclusion']==='FAILED')$execution['state']='IN_PROGRESS';app()->make(GrowthTaskServices::class)->assertExecutable($c,$execution);
    }
    private function examForTask(array $task): array
    {
        if(!in_array($task['kind'],['exam','practice'],true)||$task['source_type']!=='exam')throw new AdminException('任务不是练习或考试');$row=Db::name('growth_exam')->where('id',$task['source_id'])->find();if(!$row)throw new AdminException('考试不存在');return $row;
    }
}
