<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 实操、巡检与考评共用检查算法；无适用项、必评缺失与关键项失败不能伪装达标。 */
final class GrowthStandardServices
{
    /** 只列授权内维护标准，正式活动引用发布内容而不是当前草稿。 */
    public function listing(array $c): array
    {
        $q=Db::name('growth_standard');if(!$c['super'])$q->whereIn('owner_employee_id',app()->make(GrowthAccessServices::class)->employees($c));
        $rows=$q->order('id','desc')->limit(500)->select()->toArray();foreach($rows as &$r){$r['draft']=GrowthCommandServices::decode($r['draft_json']);unset($r['draft_json']);}return $rows;
    }
    /** 统一检查规则在发布时冻结，关键项和分值边界不能由客户端绕过。 */
    public function publish(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'standard.publish',$in,function()use($c,$in){
            $title=trim((string)($in['title']??''));$purpose=(string)($in['purpose']??'evaluation');$mode=(string)($in['mode']??'pass');$calculation=(string)($in['calculation']??'sum');
            if($title===''||!in_array($purpose,['evaluation','inspection','appraisal'],true)||!in_array($mode,['pass','score'],true)||!in_array($calculation,['sum','proportion'],true))throw new AdminException('检查表基本设置无效');
            $items=[];foreach(array_values((array)($in['items']??[])) as $i=>$v){$name=trim((string)($v['title']??''));$max=GrowthCommandServices::integer($v['max_score']??10,1,1000,'检查项满分');$key=(string)($v['key']??('item-'.($i+1)));if($name===''||$max<=0||$max>1000||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$key))throw new AdminException('检查项须有名称、稳定标识和有效分值');$critical=(bool)($v['critical']??false);$items[]=['key'=>$key,'title'=>mb_substr($name,0,180),'description'=>mb_substr((string)($v['description']??''),0,5000),'max_score'=>$max,'required'=>(bool)($v['required']??true),'critical'=>$critical,'allow_na'=>$critical?false:(bool)($v['allow_na']??false),'evidence_required'=>(bool)($v['evidence_required']??false)];}
            if(!$items||count($items)>100||count(array_unique(array_column($items,'key')))!==count($items))throw new AdminException('检查表需要1至100个不重复检查项');
            $max=GrowthCommandServices::integer($in['max_score']??100,1,100000,'检查表满分');$pass=GrowthCommandServices::integer($in['pass_score']??60,0,100000,'检查表及格线');if($max<=0||$pass<0||($mode==='score'&&$pass>($calculation==='proportion'?$max:array_sum(array_column($items,'max_score')))))throw new AdminException('检查表满分或及格线无效');
            $snapshot=['title'=>mb_substr($title,0,180),'purpose'=>$purpose,'mode'=>$mode,'calculation'=>$calculation,'max_score'=>$max,'pass_score'=>$pass,'items'=>$items];
            $id=(int)($in['id']??0);if($id){$this->owned($c,$id);}else $id=(int)Db::name('growth_standard')->insertGetId(['title'=>$snapshot['title'],'purpose'=>$purpose,'draft_json'=>GrowthCommandServices::json($snapshot),'created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'updated_at'=>time()]);
            $release=(int)Db::name('growth_standard_release')->insertGetId(['standard_id'=>$id,'snapshot_json'=>GrowthCommandServices::json($snapshot),'published_by'=>$c['actor_key'],'published_at'=>time()]);Db::name('growth_standard')->where('id',$id)->update(['title'=>$snapshot['title'],'purpose'=>$purpose,'draft_json'=>GrowthCommandServices::json($snapshot),'release_id'=>$release,'updated_at'=>time()]);return ['id'=>$id,'release_id'=>$release,'message'=>'检查标准已发布'];
        });
    }
    /** 读取可用原标准发布并重新验证维护范围。 */
    public function release(array $c,int $id): array
    {
        $r=Db::name('growth_standard_release')->where('id',$id)->find();if(!$r)throw new AdminException('请选择已发布检查标准');$this->owned($c,(int)$r['standard_id']);return GrowthCommandServices::decode($r['snapshot_json']);
    }
    /** 传入冻结检查表及当前逐项结果，返回唯一算法结果，前端不重算总分。 */
    public function calculate(array $snapshot,array $values): array
    {
        $sum=0;$effective=0;$count=0;$critical=true;$allPassed=true;$results=[];
        foreach($snapshot['items'] as $item){$key=$item['key'];$v=$values[$key]??null;if(!is_array($v)){if($item['required'])throw new AdminException('请完成必评项：'.$item['title']);continue;}
            if(!empty($v['not_applicable'])){if(!$item['allow_na']||$item['critical']||trim((string)($v['reason']??''))==='')throw new AdminException('该检查项不能跳过，或缺少不适用说明');$results[$key]=['not_applicable'=>true,'reason'=>$v['reason']];continue;}
            $assets=array_values(array_unique(array_map('intval',(array)($v['asset_ids']??[]))));if($item['evidence_required']&&!$assets)throw new AdminException('请提供证据：'.$item['title']);
            $note=trim((string)($v['note']??''));if($note==='')throw new AdminException('请填写评价依据：'.$item['title']);
            if($snapshot['mode']==='score'){if(!isset($v['score'])||filter_var($v['score'],FILTER_VALIDATE_INT)===false)throw new AdminException('分项须填写整数得分');$score=(int)$v['score'];if($score<0||$score>$item['max_score'])throw new AdminException('分项得分超过允许范围');$passed=$score===$item['max_score'];}
            else {if(!array_key_exists('passed',$v)||!is_bool($v['passed']))throw new AdminException('请逐项选择通过或未通过');$passed=(bool)$v['passed'];$score=$passed?$item['max_score']:0;}
            if($item['critical']&&!$passed)$critical=false;if(!$passed)$allPassed=false;$count++;$sum+=$score;$effective+=$item['max_score'];$results[$key]=['score'=>$score,'passed'=>$passed,'note'=>$note,'asset_ids'=>$assets];
        }
        if(!$count||!$effective)throw new AdminException('没有适用检查项，不能形成总分或通过结论');
        $score=$snapshot['mode']==='score'?($snapshot['calculation']==='proportion'?intdiv($sum*$snapshot['max_score']*2+$effective,2*$effective):$sum):null;
        $passed=$critical&&($snapshot['mode']==='score'?$score>=$snapshot['pass_score']:$allPassed);
        return ['score'=>$score,'effective_max'=>$effective,'applicable_count'=>$count,'critical_passed'=>$critical,'passed'=>$passed,'items'=>$results];
    }
    private function owned(array $c,int $id): void{$r=Db::name('growth_standard')->where('id',$id)->find();if(!$r)throw new AdminException('检查标准不存在');if(!$c['super'])app()->make(GrowthAccessServices::class)->assertEmployee($c,(int)$r['owner_employee_id']);}
}
