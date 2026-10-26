<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 题目反馈引用真实答卷；核查记录不修改原题、原答案或已公布成绩。 */
final class GrowthFeedbackServices
{
    /** 题目必须属于本人真实答卷，反馈不修改原答案或正式成绩。 */
    public function submit(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'question.feedback',$in,function()use($c,$in){
            $attempt=Db::name('growth_attempt')->where('id',(int)($in['attempt_id']??0))->where('employee_id',$c['employee_id'])->find();
            if(!$attempt)throw new AdminException('只能反馈本人答卷中的题');
            app()->make(GrowthTaskServices::class)->task($c,(int)$attempt['task_id']);
            $qid=(int)($in['question_id']??0);$questions=GrowthCommandServices::decode($attempt['questions_json']);
            if(!in_array($qid,array_map('intval',array_column($questions,'id')),true))throw new AdminException('题目不属于这份答卷');
            $description=trim((string)($in['description']??''));if($description==='')throw new AdminException('请说明题目疑问或错误');
            $id=(int)Db::name('growth_question_feedback')->insertGetId(['question_id'=>$qid,'employee_id'=>$c['employee_id'],'description'=>mb_substr($description,0,10000),'resolution'=>'','created_at'=>time()]);
            return ['id'=>$id,'message'=>'题目反馈已提交，原答卷与成绩保留'];
        });
    }
    /** 题库维护者分页核查本范围反馈，不向其他员工公开答卷。 */
    public function listing(array $c,array $in): array
    {
        $q=Db::name('growth_question_feedback')->alias('f')->join('growth_question q','q.id=f.question_id');
        if(!$c['super'])$q->whereIn('q.owner_employee_id',app()->make(GrowthAccessServices::class)->employees($c));
        [$rows,$page]=GrowthPage::take($q->field('f.*,q.stem'),$in['page']??1,'f.id');return ['rows'=>$rows,'pagination'=>$page];
    }
    /** 记录核查依据；改题及改分必须分别回到原业务执行。 */
    public function resolve(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'question.feedback.resolve',$in,function()use($c,$in){
            $r=Db::name('growth_question_feedback')->where('id',(int)($in['id']??0))->lock(true)->find();
            if(!$r||$r['state']!=='OPEN')throw new AdminException('题目反馈已处理或不存在');
            app()->make(GrowthQuestionServices::class)->owned($c,'growth_question',(int)$r['question_id']);
            $note=trim((string)($in['resolution']??''));if($note==='')throw new AdminException('请填写核查结论与处理依据');
            Db::name('growth_question_feedback')->where('id',$r['id'])->update(['state'=>'RESOLVED','resolution'=>mb_substr($note,0,10000),'handler_employee_id'=>$c['employee_id'],'resolved_at'=>time()]);
            return ['message'=>'反馈处理已记录；改题或改分须从原业务另行操作'];
        });
    }
}
