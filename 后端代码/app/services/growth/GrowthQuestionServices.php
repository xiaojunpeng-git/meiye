<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 题库与试卷发布共用唯一题源；员工答卷读取开始时冻结的题目，不读取可变题库。 */
final class GrowthQuestionServices
{
    /** 管理列表分页而下拉提供受限候选，题库与试卷均复用统一来源。 */
    public function catalog(array $c,array $in): array
    {
        $q=Db::name('growth_question');if(!$c['super'])$q->whereIn('owner_employee_id',app()->make(GrowthAccessServices::class)->employees($c));
        if(!empty($in['keyword']))$q->whereLike('stem','%'.mb_substr((string)$in['keyword'],0,100).'%');
        $total=(clone $q)->count();$page=max(1,min(max(1,(int)ceil($total/20)),(int)($in['page']??1)));
        $rows=$q->order('id','desc')->page($page,20)->select()->toArray();foreach($rows as &$row){$row['options']=GrowthCommandServices::decode($row['options_json']);$row['answer']=GrowthCommandServices::decode($row['answer_json']);unset($row['options_json'],$row['answer_json']);}
        [$papers,$paperPage]=GrowthPage::take($this->queryOwned($c,'growth_paper'),$in['papers_page']??1);foreach($papers as &$p){$p['draft']=GrowthCommandServices::decode($p['draft_json']);unset($p['draft_json']);}
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'total_pages'=>max(1,(int)ceil($total/20)),'papers'=>$papers,'papers_pagination'=>$paperPage,'paper_options'=>$this->queryOwned($c,'growth_paper')->field('id,title,release_id')->order('id','desc')->limit(500)->select()->toArray()];
    }

    /** 完整保存可编辑题目，正式试卷已有冻结题目不受后续改题替换。 */
    public function save(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'question.save',$in,function()use($c,$in){
            $id=(int)($in['id']??0);if($id)$this->owned($c,'growth_question',$id);
            $data=$this->normalize($in);$data['updated_at']=time();
            if($id)Db::name('growth_question')->where('id',$id)->update($data);else $id=(int)Db::name('growth_question')->insertGetId($data+['created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'created_at'=>time()]);
            return ['id'=>$id,'message'=>'题目已保存，已有答卷不受影响'];
        });
    }

    /** 逐行返回原行号与错误；合法行同批保存，重试复用整批回执。 */
    public function import(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'question.import',$in,function()use($c,$in){
            $rows=(array)($in['rows']??[]);if(!$rows||count($rows)>500)throw new AdminException('每批导入1至500题');$errors=[];$ids=[];
            foreach($rows as $i=>$row){try{$data=$this->normalize((array)$row);$ids[]=(int)Db::name('growth_question')->insertGetId($data+['created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'created_at'=>time(),'updated_at'=>time()]);}catch(AdminException $e){$errors[]=['row'=>$i+2,'reason'=>$e->getMessage()];}}
            return ['success_count'=>count($ids),'failure_count'=>count($errors),'errors'=>$errors,'question_ids'=>$ids];
        });
    }

    /** 固定或随机试卷都冻结题池内容；开始考试才随机选择，刷新不会重新抽题。 */
    public function paper(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'paper.publish',$in,function()use($c,$in){
            $title=trim((string)($in['title']??''));if($title===''||mb_strlen($title)>180)throw new AdminException('请填写试卷名称');
            $mode=(string)($in['mode']??'fixed');if(!in_array($mode,['fixed','random'],true))throw new AdminException('组卷方式无效');
            $ids=array_values(array_unique(array_map('intval',(array)($in['question_ids']??[]))));if(!$ids||count($ids)>200)throw new AdminException('请选择1至200道题');
            $questions=[];foreach($ids as $id){$q=$this->owned($c,'growth_question',$id);if($q['state']!=='ACTIVE')throw new AdminException('试卷不能包含停用题');$q['options']=GrowthCommandServices::decode($q['options_json']);$q['answer']=GrowthCommandServices::decode($q['answer_json']);unset($q['options_json'],$q['answer_json']);$questions[]=$q;}
            // 分值属于本次试卷；题库后续改分不影响已发布试卷或答卷。
            $overrides=(array)($in['question_scores']??[]);foreach($questions as &$question){$value=$overrides[$question['id']]??null;if($value!==null&&$value!=='')$question['score']=GrowthCommandServices::integer($value,1,10000,'试卷单题分值');}unset($question);
            $drawRules=[];$inputRules=$mode==='random'?array_values((array)($in['draw_rules']??[])):[];
            if(count($inputRules)>30)throw new AdminException('最多30组抽题规则');$used=[];$matched=[];
            foreach($inputRules as $rule){$type=(string)($rule['type']??'');$category=trim((string)($rule['category']??''));if($type!==''&&!in_array($type,['single','multiple','boolean','order','text'],true))throw new AdminException('抽题规则题型无效');$number=GrowthCommandServices::integer($rule['count']??0,1,200,'抽题数量');$score=GrowthCommandServices::integer($rule['score']??0,1,10000,'抽题单题分值');$pool=[];
                foreach($questions as $q){if(($type!==''&&$q['type']!==$type)||($category!==''&&$q['category']!==$category))continue;if(isset($used[$q['id']]))throw new AdminException('抽题规则重叠，同一道题不能属于多组');$used[$q['id']]=true;$q['score']=$score;$matched[]=$q;$pool[]=(int)$q['id'];}if(count($pool)<$number)throw new AdminException('分类或题型内可用题目不足');$drawRules[]=['type'=>$type,'category'=>$category,'count'=>$number,'score'=>$score,'question_ids'=>$pool];
            }
            if($drawRules){$questions=$matched;$count=array_sum(array_column($drawRules,'count'));}else $count=$mode==='random'?GrowthCommandServices::integer($in['draw_count']??0,1,200,'抽题数量'):count($ids);if($count<=0||$count>count($questions))throw new AdminException('抽题数量须在题池范围内');
            $in['draw_count']=$count;$snapshot=['mode'=>$mode,'questions'=>$questions,'draw_count'=>$count,'draw_rules'=>$drawRules,'multiple_rule'=>'exact','multiple_rule_label'=>'全对得分，错选、漏选不得分','order_rule'=>'exact_sequence','order_rule_label'=>'按正确顺序选齐全部选项才得分'];
            $id=(int)($in['id']??0);if($id)$this->owned($c,'growth_paper',$id);else $id=(int)Db::name('growth_paper')->insertGetId(['title'=>$title,'draft_json'=>GrowthCommandServices::json($in),'created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'created_at'=>time(),'updated_at'=>time()]);
            $release=(int)Db::name('growth_paper_release')->insertGetId(['paper_id'=>$id,'title'=>$title,'snapshot_json'=>GrowthCommandServices::json($snapshot),'published_at'=>time(),'published_by'=>$c['actor_key']]);
            Db::name('growth_paper')->where('id',$id)->update(['title'=>$title,'draft_json'=>GrowthCommandServices::json($in),'release_id'=>$release,'updated_at'=>time()]);
            return ['id'=>$id,'release_id'=>$release,'question_count'=>$count,'message'=>'试卷已发布'];
        });
    }

    /** 题目、试卷与考试只能按固定允许实体及维护范围读取。 */
    public function owned(array $c,string $table,int $id): array
    {
        $row=$this->queryOwned($c,$table)->where('id',$id)->find();if(!$row)throw new AdminException('记录不存在或不在当前数据范围');return $row;
    }
    /** 公共候选查询只接受固定实体白名单并裁剪数据范围。 */
    public function queryOwned(array $c,string $table)
    {
        if(!in_array($table,['growth_question','growth_paper','growth_exam'],true))throw new \InvalidArgumentException('不支持的成长题库实体');
        $q=Db::name($table);if(!$c['super'])$q->whereIn('owner_employee_id',app()->make(GrowthAccessServices::class)->employees($c));return $q;
    }
    /** 关键题计入各自配额；冻结题池内抽样，重进时直接读取已存答卷。 */
    public function drawQuestions(array $paper,array $keys=[]): array
    {
        if($paper['mode']!=='random')return $paper['questions'];$groups=$paper['draw_rules']??[];if(!$groups)$groups=[['question_ids'=>array_map('intval',array_column($paper['questions'],'id')),'count'=>(int)$paper['draw_count']]];$result=[];
        foreach($groups as $group){$required=[];$pool=[];foreach($paper['questions'] as $q){if(!in_array((int)$q['id'],$group['question_ids'],true))continue;if(in_array((int)$q['id'],$keys,true))$required[]=$q;else $pool[]=$q;}if(count($required)>(int)$group['count'])throw new AdminException('关键必过题超过所属分类或题型的抽题数量');shuffle($pool);$result=array_merge($result,$required,array_slice($pool,0,(int)$group['count']-count($required)));}shuffle($result);return $result;
    }
    /** 及格线按所有可能抽卷的最低满分校验，不能等员工开考才发现不可能通过。 */
    public function minimumScore(array $paper,array $keys=[]): int
    {
        if($paper['mode']!=='random')return (int)array_sum(array_column($paper['questions'],'score'));$this->drawQuestions($paper,$keys);$groups=$paper['draw_rules']??[];if($groups)return (int)array_sum(array_map(static function($g){return $g['count']*$g['score'];},$groups));$required=0;$pool=[];$number=0;foreach($paper['questions'] as $q){if(in_array((int)$q['id'],$keys,true)){$required+=(int)$q['score'];$number++;}else $pool[]=(int)$q['score'];}sort($pool);return $required+(int)array_sum(array_slice($pool,0,(int)$paper['draw_count']-$number));
    }

    private function normalize(array $in): array
    {
        $type=(string)($in['type']??'single');if(!in_array($type,['single','multiple','boolean','order','text'],true))throw new AdminException('题型须为单选、多选、判断、顺序或简答');
        $stem=trim((string)($in['stem']??''));if(filter_var($in['score']??1,FILTER_VALIDATE_INT)===false)throw new AdminException('题目分值必须为整数');$score=(int)($in['score']??1);if($stem===''||mb_strlen($stem)>10000||$score<=0||$score>1000)throw new AdminException('请填写题干，分值为1至1000的整数');
        $options=$type==='boolean'?['正确','错误']:array_values(array_map('strval',(array)($in['options']??[])));
        foreach((array)($in['answer']??[]) as $index)if(filter_var($index,FILTER_VALIDATE_INT)===false)throw new AdminException('正确答案选项必须为整数索引');
        $rawAnswer=array_values(array_map('intval',(array)($in['answer']??[])));
        $answer=$type==='order'?$rawAnswer:array_values(array_unique($rawAnswer));if($type!=='order')sort($answer);
        $basis=trim((string)($in['basis']??''));
        if($type==='text'){if($basis==='')throw new AdminException('简答题须填写评分依据');$answer=[];$options=[];}
        else{if(count($options)<2||count($options)>10||!$answer)throw new AdminException('客观题须有2至10个选项及正确答案');foreach($options as $v)if(trim($v)==='')throw new AdminException('选项不能为空');foreach($answer as $v)if(!array_key_exists($v,$options))throw new AdminException('正确答案不在选项内');if(in_array($type,['single','boolean'],true)&&count($answer)!==1)throw new AdminException('单选和判断只能有一个正确答案');
            if($type==='order'&&(count($answer)!==count($options)||count(array_unique($answer))!==count($options)))throw new AdminException('顺序题须按正确先后列出全部选项，不能重复或漏选');}
        $state=(string)($in['state']??'ACTIVE');if(!in_array($state,['ACTIVE','STOPPED'],true))throw new AdminException('题目状态无效');
        return ['type'=>$type,'stem'=>$stem,'options_json'=>GrowthCommandServices::json($options),'answer_json'=>GrowthCommandServices::json($answer),'basis'=>$basis,'score'=>$score,'explanation'=>mb_substr((string)($in['explanation']??''),0,10000),'category'=>mb_substr((string)($in['category']??''),0,100),'state'=>$state];
    }
}
