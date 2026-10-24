<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 阶段考评使用同一检查规则，正式结果与异议独立留痕，不联动薪资或岗位权限。 */
final class GrowthAppraisalServices
{
    /** 按授权事实分页列考评，本人及指定处理人可进入对应业务。 */
    public function listing(array $c,array $in=[]): array
    {
        $ids=app()->make(GrowthAccessServices::class)->employees($c);$q=Db::name('growth_appraisal')->alias('a')->join('growth_task t',"t.source_type='appraisal' AND t.source_id=a.id");$q->where(function($w)use($c){$w->where(function($scope)use($c){app()->make(GrowthAccessServices::class)->taskScope($scope,$c,'t');})->whereOr('a.handler_employee_id',$c['employee_id']);});if(!$c['manager'])$q->where(function($w)use($c){$w->where('a.employee_id',$c['employee_id'])->whereOr('a.handler_employee_id',$c['employee_id']);});
        [$rows,$page]=GrowthPage::take($q->field('a.*'),$in['page']??1,'a.id');foreach($rows as &$r)$r['result']=Db::name('growth_appraisal_result')->where('appraisal_id',$r['id'])->order('id','desc')->find();return ['rows'=>$rows,'pagination'=>$page];
    }
    /** 沿现有员工与检查标准发起考评，同时原子创建唯一任务。 */
    public function create(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'appraisal.create',$in,function()use($c,$in){
            $employee=(int)($in['employee_id']??0);$handler=(int)($in['handler_employee_id']??0);$access=app()->make(GrowthAccessServices::class);$access->assertEmployee($c,$employee);$access->assertActiveEmployee($c,$handler);
            $title=trim((string)($in['title']??''));$start=(string)($in['period_start']??'');$end=(string)($in['period_end']??'');if($title===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$end)||date('Y-m-d',strtotime($start)?:0)!==$start||date('Y-m-d',strtotime($end)?:0)!==$end||$start>$end)throw new AdminException('请填写考评名称与有效起止日期');
            $standard=(int)($in['standard_release_id']??0);$s=app()->make(GrowthStandardServices::class)->release($c,$standard);if($s['purpose']!=='appraisal')throw new AdminException('请选择考评用途的标准');$person=Db::name('employee')->where('id',$employee)->where('status',1)->where('is_del',0)->find();if(!$person)throw new AdminException('员工当前不可发起考评');
            $id=(int)Db::name('growth_appraisal')->insertGetId(['employee_id'=>$employee,'employee_name'=>$person['name'],'title'=>mb_substr($title,0,180),'period_start'=>$start,'period_end'=>$end,'standard_release_id'=>$standard,'handler_employee_id'=>$handler,'created_by'=>$c['actor_key'],'created_at'=>time()]);
            $task=app()->make(GrowthTaskServices::class)->insertTask($c,$employee,['assignment_key'=>'appraisal:'.$id,'title'=>$title,'kind'=>'appraisal','source_type'=>'appraisal','source_id'=>$id,'handler_employee_id'=>$handler,'rules_json'=>'{}']);return ['id'=>$id,'task_id'=>$task,'message'=>'阶段考评已发起'];
        });
    }
    /** 返回原标准、结果链及异议，不把人工汇总分当作新的来源。 */
    public function detail(array $c,int $id): array
    {
        $r=$this->record($c,$id);$standard=Db::name('growth_standard_release')->where('id',$r['standard_release_id'])->find();return ['appraisal'=>$r,'standard'=>GrowthCommandServices::decode($standard['snapshot_json']),'results'=>Db::name('growth_appraisal_result')->where('appraisal_id',$id)->order('id','desc')->select()->toArray(),'objections'=>Db::name('growth_objection')->where('appraisal_id',$id)->order('id','desc')->select()->toArray(),'can_handle'=>(int)$r['handler_employee_id']===$c['employee_id']];
    }
    /** 只有指派人可确认；更正追加结果，保留原结果和评分依据。 */
    public function confirm(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'appraisal.confirm',$in,function()use($c,$in){
            $r=$this->record($c,(int)($in['id']??0),true);if((int)$r['handler_employee_id']!==$c['employee_id'])throw new AdminException('仅指派考评负责人可确认结果');$reason=trim((string)($in['reason']??''));if($reason==='')throw new AdminException('请填写考评依据或更正原因');$s=Db::name('growth_standard_release')->where('id',$r['standard_release_id'])->find();$standard=GrowthCommandServices::decode($s['snapshot_json']);$result=app()->make(GrowthStandardServices::class)->calculate($standard,(array)($in['items']??[]));
            // 正式考评不能复活已暂停、已作废任务；已公布更正沿独立结果链追加。
            $task=Db::name('growth_task')->where('source_type','appraisal')->where('source_id',$r['id'])->lock(true)->find();if(!$task||in_array($task['state'],['PAUSED','CANCELLED'],true))throw new AdminException('考评任务已暂停或作废');
            foreach($result['items'] as $item)app()->make(GrowthAssetServices::class)->validateEvidence($c,(array)($item['asset_ids']??[]));$previous=(int)Db::name('growth_appraisal_result')->where('appraisal_id',$r['id'])->order('id','desc')->value('id');$result+=['standard_snapshot'=>$standard,'next_steps'=>mb_substr((string)($in['next_steps']??''),0,10000),'reason'=>$reason];
            $id=(int)Db::name('growth_appraisal_result')->insertGetId(['appraisal_id'=>$r['id'],'previous_result_id'=>$previous,'snapshot_json'=>GrowthCommandServices::json($result),'score'=>$result['score'],'passed'=>$result['passed']?1:0,'reason'=>$reason,'handler_employee_id'=>$c['employee_id'],'confirmed_at'=>time()]);Db::name('growth_appraisal')->where('id',$r['id'])->update(['state'=>'PUBLISHED']);$proof=[];foreach($result['items'] as $item)$proof=array_merge($proof,(array)($item['asset_ids']??[]));app()->make(GrowthAssetServices::class)->reference(array_values(array_unique($proof)),'appraisal_result',$id,(int)$task['id']);
            $task=Db::name('growth_task')->where('source_type','appraisal')->where('source_id',$r['id'])->lock(true)->find();if($task){if($task['state']!=='COMPLETED')app()->make(GrowthTaskServices::class)->complete($c,$task,'appraisal_result',$id,$result['passed']?'PASSED':'FAILED');else Db::name('growth_task')->where('id',$task['id'])->update(['conclusion'=>$result['passed']?'PASSED':'FAILED','result_id'=>$id]);}
            if($previous){$action=(int)Db::name('growth_task_action')->insertGetId(['task_id'=>$task['id'],'action'=>'appraisal_corrected','reason'=>$reason,'before_json'=>GrowthCommandServices::json(['result_id'=>$task['result_id'],'conclusion'=>$task['conclusion']]),'after_json'=>GrowthCommandServices::json(['result_id'=>$id,'conclusion'=>$result['passed']?'PASSED':'FAILED']),'actor_key'=>$c['actor_key'],'created_at'=>time()]);app()->make(GrowthCommandServices::class)->event($c,'TASK_RESULT_CORRECTED','task_action',$action,['task_id'=>(int)$task['id'],'employee_id'=>(int)$r['employee_id']]);}
            app()->make(GrowthCommandServices::class)->event($c,'APPRAISAL_PUBLISHED','appraisal_result',$id,['employee_id'=>(int)$r['employee_id'],'appraisal_id'=>(int)$r['id'],'previous_result_id'=>$previous]);return ['id'=>$id,'message'=>$previous?'考评更正已保存，原结果保留':'考评结果已确认公布'];
        });
    }
    /** 员工只能对本人已公布结果提出一份待处理异议。 */
    public function object(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'appraisal.object',$in,function()use($c,$in){$r=$this->record($c,(int)($in['id']??0),true);if((int)$r['employee_id']!==$c['employee_id']||$r['state']!=='PUBLISHED')throw new AdminException('仅本人可对已公布考评提出异议');$body=trim((string)($in['body']??''));if($body==='')throw new AdminException('请说明异议内容');if(Db::name('growth_objection')->where('appraisal_id',$r['id'])->where('state','OPEN')->find())throw new AdminException('已有异议等待处理');$result=Db::name('growth_appraisal_result')->where('appraisal_id',$r['id'])->order('id','desc')->find();$assets=app()->make(GrowthAssetServices::class)->validateEvidence($c,(array)($in['asset_ids']??[]));$id=(int)Db::name('growth_objection')->insertGetId(['appraisal_id'=>$r['id'],'result_id'=>$result['id'],'employee_id'=>$c['employee_id'],'handler_employee_id'=>$r['handler_employee_id'],'body'=>mb_substr($body,0,10000),'evidence_json'=>GrowthCommandServices::json($assets),'created_at'=>time()]);$task=Db::name('growth_task')->where('source_type','appraisal')->where('source_id',$r['id'])->find();if($task)app()->make(GrowthAssetServices::class)->reference($assets,'objection',$id,(int)$task['id']);return ['id'=>$id,'message'=>'异议已交原考评负责人处理'];});
    }
    /** 由当前指定处理人记录核查依据，改分必须在原考评另存正式结果。 */
    public function resolve(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'appraisal.resolve',$in,function()use($c,$in){$o=Db::name('growth_objection')->where('id',(int)($in['objection_id']??0))->lock(true)->find();if(!$o||(int)$o['handler_employee_id']!==$c['employee_id']||$o['state']!=='OPEN')throw new AdminException('当前异议不可由本人处理');$this->record($c,(int)$o['appraisal_id']);$note=trim((string)($in['resolution']??''));if($note==='')throw new AdminException('请填写处理依据；改分时先确认新的考评结果');Db::name('growth_objection')->where('id',$o['id'])->update(['state'=>'RESOLVED','resolution'=>$note,'resolved_at'=>time()]);return ['id'=>(int)$o['id'],'message'=>'异议处理已记录'];});
    }
    /** 能力关联现有人事岗位及统一实操标准，不自动授予岗位资格。 */
    public function saveCapability(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'capability.save',$in,function()use($c,$in){$position=(int)($in['position_id']??0);$title=trim((string)($in['title']??''));if(!$position||$title===''||!Db::name('position')->where('id',$position)->where('status',1)->find())throw new AdminException('请选择现有岗位并填写能力项');$standard=(int)($in['standard_release_id']??0);if($standard){$s=app()->make(GrowthStandardServices::class)->release($c,$standard);if($s['purpose']!=='evaluation')throw new AdminException('能力项请选择实操用途检查标准');}$id=(int)($in['id']??0);if($id){$r=Db::name('growth_capability')->where('id',$id)->find();if(!$r)throw new AdminException('能力项不存在');if(!$c['super'])app()->make(GrowthAccessServices::class)->assertEmployee($c,(int)$r['owner_employee_id']);}$row=['position_id'=>$position,'title'=>mb_substr($title,0,180),'description'=>mb_substr((string)($in['description']??''),0,10000),'standard_release_id'=>$standard,'evidence_required'=>(int)!empty($in['evidence_required']),'updated_at'=>time()];if($id)Db::name('growth_capability')->where('id',$id)->update($row);else $id=(int)Db::name('growth_capability')->insertGetId($row+['created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id']]);return ['id'=>$id,'message'=>'岗位能力项已保存'];});
    }
    private function record(array $c,int $id,bool $lock=false): array{$q=Db::name('growth_appraisal')->where('id',$id);if($lock)$q->lock(true);$r=$q->find();if(!$r)throw new AdminException('考评不存在');$task=Db::name('growth_task')->where('source_type','appraisal')->where('source_id',$id)->find();if($task&&(int)$r['handler_employee_id']!==$c['employee_id'])app()->make(GrowthAccessServices::class)->assertTaskFact($c,$task);if(!$c['manager']&&!in_array($c['employee_id'],[(int)$r['employee_id'],(int)$r['handler_employee_id']],true))throw new AdminException('未关联此考评');return $r;}
}
