<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;

/** 工作台引用统计出口和原任务，只提供行动入口，不维护另一份业务结果。 */
final class GrowthOverviewServices
{
    /** 工作台复用统计结果及原任务入口，不维护第二套进度或人工指标。 */
    public function read(array $c,array $in): array
    {
        $reports=app()->make(GrowthReportServices::class);$base=['start'=>(string)($in['start']??date('Y-m-01')),'end'=>(string)($in['end']??date('Y-m-d')),'organization_id'=>(int)($in['organization_id']??0),'store_ids'=>(array)($in['store_ids']??[]),'position_id'=>(int)($in['position_id']??0),'plan_id'=>(int)($in['plan_id']??0),'store_id'=>(int)($in['store_id']??0),'employee_id'=>(int)($in['employee_id']??0)];
        $tasks=$reports->read($c,$base+['report'=>'tasks']);$exams=$reports->read($c,$base+['report'=>'exams']);$issues=$reports->read($c,$base+['report'=>'issues']);$capabilities=$reports->read($c,$base+['report'=>'capabilities']);$pending=$reports->read($c,$base+['report'=>'tasks','state'=>'PENDING']);$open=$reports->read($c,$base+['report'=>'issues','state'=>'OPEN']);
        $ids=app()->make(GrowthAccessServices::class)->employees($c);$plans=Db::name('growth_plan');if(!$c['super'])$plans->whereIn('owner_employee_id',$ids);$planRows=$plans->order('id','desc')->limit(20)->select()->toArray();foreach($planRows as &$p){$q=Db::name('growth_task')->where('plan_id',$p['id'])->where('required',1)->where('state','<>','CANCELLED');app()->make(GrowthAccessServices::class)->taskScope($q,$c);$p['required_tasks']=(int)(clone $q)->count();$p['completed_tasks']=(int)(clone $q)->where('state','COMPLETED')->count();$p['progress']=$p['required_tasks']?number_format(100*$p['completed_tasks']/$p['required_tasks'],1).'%':null;}
        return ['repeat_groups'=>$issues['repeat_groups'],'capabilities'=>$capabilities['totals'],'tasks'=>$tasks['totals'],'exams'=>$exams['totals'],'issues'=>$issues['totals'],'pending'=>$pending['records'],'open_issues'=>$open['records'],'plans'=>$planRows,'data_as_of'=>$tasks['data_as_of'],'metric_version'=>GrowthReportServices::METRIC,'business_link_enabled'=>(bool)Db::name('growth_setting')->where('id',1)->value('business_link_enabled')];
    }
}
