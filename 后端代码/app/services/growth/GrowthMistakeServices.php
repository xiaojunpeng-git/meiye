<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 客观错题建立独立练习；原错误保留，自主练习不产生积分或证书。 */
final class GrowthMistakeServices
{
    /** 本人已公布的客观错题引用原统一题目新建练习，不补发积分证书。 */
    public function create(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'exam.mistakes',$in,function()use($c,$in){
            $a=Db::name('growth_attempt')->where('id',(int)($in['attempt_id']??0))->where('employee_id',$c['employee_id'])->where('state','PUBLISHED')->find();
            if(!$a)throw new AdminException('仅可重练本人已公布答卷的客观错题');
            $source=app()->make(GrowthTaskServices::class)->task($c,(int)$a['task_id']);
            $adjust=Db::name('growth_grade_adjustment')->where('attempt_id',$a['id'])->order('id','desc')->find();
            $grades=GrowthCommandServices::decode((string)($adjust['grades_json']??$a['grades_json']));$questions=[];
            foreach(GrowthCommandServices::decode($a['questions_json']) as $q)
                if($q['type']!=='text'&&(int)($grades[(string)$q['id']]['score']??0)<(int)$q['score'])$questions[]=$q;
            if(!$questions)throw new AdminException('本次没有可重练的客观错题；简答题请按负责人安排练习');
            $title=mb_substr('错题再练 · '.$source['title'],0,180);
            $draft=['mode'=>'fixed','question_ids'=>array_column($questions,'id'),'draw_count'=>count($questions)];
            $paper=(int)Db::name('growth_paper')->insertGetId(['title'=>$title,'draft_json'=>GrowthCommandServices::json($draft),'created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'created_at'=>time(),'updated_at'=>time()]);
            // 新练习仍引用原统一题库ID，使用原答卷内冻结题目，不重新抄一份可编辑题库。
            $release=(int)Db::name('growth_paper_release')->insertGetId(['paper_id'=>$paper,'title'=>$title,'snapshot_json'=>GrowthCommandServices::json(['mode'=>'fixed','questions'=>$questions,'draw_count'=>count($questions)]),'published_by'=>$c['actor_key'],'published_at'=>time()]);
            Db::name('growth_paper')->where('id',$paper)->update(['release_id'=>$release]);
            $rules=['wrong_source_attempt_id'=>(int)$a['id'],'self_practice'=>true,'multiple_rule'=>'exact','retake_adoption'=>'highest','key_question_ids'=>[]];
            $exam=(int)Db::name('growth_exam')->insertGetId(['title'=>$title,'kind'=>'practice','paper_release_id'=>$release,'max_attempts'=>20,'pass_score'=>0,'required_pass'=>0,'auto_publish'=>1,'show_analysis'=>1,'rules_json'=>GrowthCommandServices::json($rules),'created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'created_at'=>time()]);
            $task=app()->make(GrowthTaskServices::class)->insertTask($c,$c['employee_id'],['assignment_key'=>'mistakes:'.$exam,'title'=>$title,'kind'=>'practice','required'=>0,'source_type'=>'exam','source_id'=>$exam,'rules_json'=>GrowthCommandServices::json($rules)]);
            return ['task_id'=>$task,'question_count'=>count($questions),'message'=>'已创建客观错题练习，原错误记录保留'];
        });
    }
}
