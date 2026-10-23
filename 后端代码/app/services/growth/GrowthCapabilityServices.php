<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 岗位能力关联现有岗位；师傅只配置现有员工的擅长内容，不建立第二套人员主档。 */
final class GrowthCapabilityServices
{
    /** 能力配置与师傅候选使用现有岗位员工，列表分页独立于下拉选项。 */
    public function listing(array $c,array $in): array
    {
        $q=Db::name('growth_capability');if(!$c['super'])$q->whereIn('owner_employee_id',app()->make(GrowthAccessServices::class)->employees($c));[$rows,$page]=GrowthPage::take($q,$in['page']??1);$templates=GrowthCommandServices::decode((string)Db::name('growth_setting')->where('id',1)->value('templates_json'));$ids=app()->make(GrowthAccessServices::class)->employees($c);$mentors=[];foreach($templates['mentors']??[] as $m)if(in_array((int)$m['employee_id'],$ids,true))$mentors[]=$m;
        return ['rows'=>$rows,'pagination'=>$page,'positions'=>Db::name('position')->where('status',1)->field('id,name')->select()->toArray(),'standards'=>array_values(array_filter(app()->make(GrowthStandardServices::class)->listing($c),static fn($r)=>$r['purpose']==='evaluation')),'mentors'=>$mentors,'employees'=>Db::name('employee')->whereIn('id',$ids)->where('status',1)->where('is_del',0)->field('id,name')->select()->toArray()];
    }
    /** 仅保存擅长内容，不因配置师傅而扩大人员档案范围。 */
    public function saveMentor(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'mentor.save',$in,function()use($c,$in){$id=(int)($in['employee_id']??0);app()->make(GrowthAccessServices::class)->assertEmployee($c,$id);if(!Db::name('employee')->where('id',$id)->where('status',1)->where('is_del',0)->find())throw new AdminException('请选择有效员工');$text=trim((string)($in['expertise']??''));if($text==='')throw new AdminException('请填写擅长内容');$row=Db::name('growth_setting')->where('id',1)->lock(true)->find();$templates=GrowthCommandServices::decode($row['templates_json']);$mentors=array_values(array_filter((array)($templates['mentors']??[]),static fn($m)=>(int)$m['employee_id']!==$id));if(!empty($in['enabled']))$mentors[]=['employee_id'=>$id,'expertise'=>mb_substr($text,0,3000),'recorded_by'=>$c['actor_key'],'recorded_at'=>time()];$templates['mentors']=$mentors;Db::name('growth_setting')->where('id',1)->update(['templates_json'=>GrowthCommandServices::json($templates),'updated_at'=>time()]);return ['message'=>'师傅擅长内容已保存，处理范围仍由实际指派决定'];});
    }
}
