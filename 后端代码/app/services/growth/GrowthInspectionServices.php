<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 门店现场问题独立闭环；培训和通知均不代替整改复查。 */
final class GrowthInspectionServices
{
    /** 巡检按门店与实际指派分页；整改和检查记录分别返回。 */
    public function listing(array $c,array $in=[]): array
    {
        $q=Db::name('growth_inspection');$issues=Db::name('growth_issue');
        if($c['manager']){if(!$c['super']){$q->whereIn('store_id',(array)$c['store_ids']);$issues->whereIn('store_id',(array)$c['store_ids']);}}
        else{$q->where(function($x)use($c){$x->where('executor_employee_id',$c['employee_id'])->whereOr('reviewer_employee_id',$c['employee_id']);});$issues->where(function($x)use($c){$x->where('responsible_employee_id',$c['employee_id'])->whereOr('reviewer_employee_id',$c['employee_id']);});}
        $stores=Db::name('system_store')->where('is_del',0);if(!$c['super'])$stores->whereIn('id',(array)$c['store_ids']);
        $people=app()->make(GrowthAccessServices::class)->employees($c);
        [$inspectionRows,$inspectionPage]=GrowthPage::take($q,$in['inspections_page']??$in['field_page']??1);[$issueRows,$issuePage]=GrowthPage::take($issues,$in['issues_page']??$in['field_page']??1);
        return ['field_total'=>$inspectionPage['total']+$issuePage['total'],'field_total_pages'=>max($inspectionPage['total_pages'],$issuePage['total_pages']),'pagination'=>['inspections'=>$inspectionPage,'issues'=>$issuePage],'operator_employee_id'=>$c['employee_id'],'employees'=>Db::name('employee')->whereIn('id',$people)->where('status',1)->where('is_del',0)->field('id,name')->order('id')->select()->toArray(),'inspections'=>$inspectionRows,'issues'=>$issueRows,'stores'=>$stores->field('id,name')->order('id')->select()->toArray(),'standards'=>array_values(array_filter(app()->make(GrowthStandardServices::class)->listing($c),static fn($s)=>$s['purpose']==='inspection'))];
    }
    /** 真实门店和统一标准固定为检查安排，无门店不能伪造巡检身份。 */
    public function create(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'inspection.create',$in,function()use($c,$in){
            $store=(int)($in['store_id']??0);$this->store($c,$store);$s=Db::name('system_store')->where('id',$store)->where('is_del',0)->find();if(!$s)throw new AdminException('请选择真实有效门店');
            $title=trim((string)($in['title']??''));$executor=(int)($in['executor_employee_id']??0);$reviewer=(int)($in['reviewer_employee_id']??0);$standard=(int)($in['standard_release_id']??0);
            if($title==='')throw new AdminException('请填写巡检任务名称');app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$executor);if($reviewer)app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$reviewer);
            $snapshot=app()->make(GrowthStandardServices::class)->release($c,$standard);if($snapshot['purpose']!=='inspection')throw new AdminException('请选择巡检用途的检查表');
            $due=(int)($in['due_at']??0);if($due&&$due<=time())throw new AdminException('巡检期限须晚于当前时间');
            $id=(int)Db::name('growth_inspection')->insertGetId(['title'=>mb_substr($title,0,180),'store_id'=>$store,'store_name'=>$s['name'],'executor_employee_id'=>$executor,'reviewer_employee_id'=>$reviewer,'standard_release_id'=>$standard,'due_at'=>$due,'created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'created_at'=>time()]);
            app()->make(GrowthCommandServices::class)->event($c,'INSPECTION_ASSIGNED','inspection',$id,['employee_id'=>$executor,'store_id'=>$store]);return ['id'=>$id,'message'=>'巡检已安排'];
        });
    }
    /** 只在授权或指派关系内返回原标准与正式检查结果。 */
    public function detail(array $c,int $id): array
    {
        $r=$this->inspection($c,$id);$standard=Db::name('growth_standard_release')->where('id',$r['standard_release_id'])->find();return ['inspection'=>$r,'standard'=>GrowthCommandServices::decode($standard['snapshot_json']),'issues'=>Db::name('growth_issue')->where('inspection_id',$id)->order('id')->select()->toArray()];
    }
    /** 检查提交与问题交办原子保存，问题按原检查项分别跟进。 */
    public function submit(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'inspection.submit',$in,function()use($c,$in){
            $r=$this->inspection($c,(int)($in['id']??0),true);if((int)$r['executor_employee_id']!==$c['employee_id'])throw new AdminException('仅指派执行人可以提交巡检');if($r['state']!=='ASSIGNED')throw new AdminException('本次巡检已经提交');
            $s=Db::name('growth_standard_release')->where('id',$r['standard_release_id'])->find();$snapshot=GrowthCommandServices::decode($s['snapshot_json']);$result=app()->make(GrowthStandardServices::class)->calculate($snapshot,(array)($in['items']??[]));
            foreach($result['items'] as $v)app()->make(GrowthAssetServices::class)->validateEvidence($c,(array)($v['asset_ids']??[]));
            $problems=(array)($in['problems']??[]);$ids=[];
            foreach($problems as $problem){$key=(string)($problem['item_key']??'');$item=null;foreach($snapshot['items'] as $v)if($v['key']===$key)$item=$v;if(!$item)throw new AdminException('问题必须关联本次真实检查项');
                $body=trim((string)($problem['description']??''));$responsible=(int)($problem['responsible_employee_id']??0);$reviewer=(int)($problem['reviewer_employee_id']??0);$due=(int)($problem['due_at']??0);if($body===''||$due<=time())throw new AdminException('整改问题须有说明、责任人、复查人及未来期限');app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$responsible);app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$reviewer);
                $assets=app()->make(GrowthAssetServices::class)->validateEvidence($c,(array)($problem['asset_ids']??[]));
                $id=(int)Db::name('growth_issue')->insertGetId(['inspection_id'=>$r['id'],'item_key'=>$key,'item_title'=>$item['title'],'store_id'=>$r['store_id'],'store_name'=>$r['store_name'],'description'=>mb_substr($body,0,10000),'original_evidence_json'=>GrowthCommandServices::json($assets),'responsible_employee_id'=>$responsible,'reviewer_employee_id'=>$reviewer,'due_at'=>$due,'created_at'=>time()]);$ids[]=$id;
                $taskId=app()->make(GrowthTaskServices::class)->insertTask($c,$responsible,['assignment_key'=>'issue:'.$id.':'.$responsible,'title'=>'整改：'.$item['title'],'kind'=>'rectification','source_type'=>'issue','source_id'=>$id,'handler_employee_id'=>$reviewer,'due_at'=>$due,'rules_json'=>'{}']);
                Db::name('growth_issue')->where('id',$id)->update(['task_id'=>$taskId]);app()->make(GrowthAssetServices::class)->reference($assets,'issue',$id,$taskId);
                app()->make(GrowthCommandServices::class)->event($c,'ISSUE_CREATED','issue',$id,['employee_id'=>$responsible,'store_id'=>(int)$r['store_id']]);
            }
            $state=$r['reviewer_employee_id']?'PENDING_REVIEW':'SUBMITTED';Db::name('growth_inspection')->where('id',$r['id'])->update(['state'=>$state,'result_json'=>GrowthCommandServices::json($result),'occurred_at'=>time(),'submitted_at'=>time()]);app()->make(GrowthCommandServices::class)->event($c,'INSPECTION_SUBMITTED','inspection',(int)$r['id'],['store_id'=>(int)$r['store_id'],'issue_ids'=>$ids]);return ['id'=>(int)$r['id'],'state'=>$state,'issue_ids'=>$ids,'message'=>'巡检已提交，整改问题分别跟进'];
        });
    }
    /** 问题、整改、复查、补训与操作历史共用原问题授权。 */
    public function issueDetail(array $c,int $id): array
    {
        $r=$this->issue($c,$id);return ['trainings'=>Db::name('growth_issue_training')->alias('l')->join('growth_task t','t.id=l.task_id')->where('l.issue_id',$id)->field('l.id,l.reason,l.task_id,t.title,t.employee_name,t.state,t.conclusion')->order('l.id','desc')->select()->toArray(),'issue'=>$r,'rectifications'=>Db::name('growth_rectification')->where('issue_id',$id)->order('id','desc')->select()->toArray(),'reviews'=>Db::name('growth_issue_review')->where('issue_id',$id)->order('id','desc')->select()->toArray(),'history'=>Db::name('growth_issue_action')->where('issue_id',$id)->order('id','desc')->select()->toArray()];
    }
    /** 责任人提交完整整改证据，提交成功不直接关闭问题。 */
    public function rectify(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'issue.rectify',$in,function()use($c,$in){
            $r=$this->issue($c,(int)($in['issue_id']??0),true);if((int)$r['responsible_employee_id']!==$c['employee_id'])throw new AdminException('仅当前整改责任人可以提交');if($r['state']!=='OPEN')throw new AdminException('问题当前不等待整改');
            $task=app()->make(GrowthTaskServices::class)->task($c,(int)$r['task_id'],true);app()->make(GrowthTaskServices::class)->assertExecutable($c,$task);
            $body=trim((string)($in['body']??''));$assets=app()->make(GrowthAssetServices::class)->validateEvidence($c,(array)($in['asset_ids']??[]));if($body===''||!$assets)throw new AdminException('请填写整改说明并提供新证据');
            $previous=(int)Db::name('growth_rectification')->where('issue_id',$r['id'])->order('id','desc')->value('id');$id=(int)Db::name('growth_rectification')->insertGetId(['issue_id'=>$r['id'],'employee_id'=>$c['employee_id'],'previous_rectification_id'=>$previous,'snapshot_json'=>GrowthCommandServices::json(['body'=>$body,'asset_ids'=>$assets]),'submitted_at'=>time()]);
            app()->make(GrowthAssetServices::class)->reference($assets,'rectification',$id,(int)$r['task_id']);Db::name('growth_task')->where('id',$r['task_id'])->update(['state'=>'PENDING']);
            Db::name('growth_issue')->where('id',$r['id'])->update(['state'=>'PENDING_REVIEW']);app()->make(GrowthCommandServices::class)->event($c,'RECTIFICATION_SUBMITTED','rectification',$id,['issue_id'=>(int)$r['id'],'employee_id'=>$c['employee_id'],'store_id'=>(int)$r['store_id']]);return ['id'=>$id,'state'=>'PENDING_REVIEW','message'=>'整改已提交，等待复查'];
        });
    }
    /** 只有指派复查人可确认；同次整改只能形成一次复查结果。 */
    public function review(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'issue.review',$in,function()use($c,$in){
            $r=$this->issue($c,(int)($in['issue_id']??0),true);if((int)$r['reviewer_employee_id']!==$c['employee_id'])throw new AdminException('仅指派复查人可以确认');if($r['state']!=='PENDING_REVIEW')throw new AdminException('当前问题不等待复查');
            $rect=Db::name('growth_rectification')->where('issue_id',$r['id'])->where('state','PENDING')->order('id','desc')->lock(true)->find();if(!$rect)throw new AdminException('没有待复查的整改提交');$note=trim((string)($in['note']??''));if($note===''||!isset($in['passed'])||!is_bool($in['passed']))throw new AdminException('请选择复查结论并填写依据');$passed=$in['passed'];$assets=app()->make(GrowthAssetServices::class)->validateEvidence($c,(array)($in['asset_ids']??[]));
            $id=(int)Db::name('growth_issue_review')->insertGetId(['issue_id'=>$r['id'],'rectification_id'=>$rect['id'],'reviewer_employee_id'=>$c['employee_id'],'passed'=>$passed?1:0,'note'=>$note,'evidence_json'=>GrowthCommandServices::json($assets),'reviewed_at'=>time()]);
            $task=app()->make(GrowthTaskServices::class)->task($c,(int)$r['task_id'],true);if($passed)app()->make(GrowthTaskServices::class)->complete($c,$task,'issue_review',$id,'QUALIFIED');else Db::name('growth_task')->where('id',$r['task_id'])->update(['state'=>'RETURNED']);app()->make(GrowthAssetServices::class)->reference($assets,'issue_review',$id,(int)$r['task_id']);
            Db::name('growth_rectification')->where('id',$rect['id'])->update(['state'=>$passed?'ACCEPTED':'RETURNED']);Db::name('growth_issue')->where('id',$r['id'])->update(['state'=>$passed?'CLOSED':'OPEN','closed_at'=>$passed?time():0,'closed_by'=>$passed?$c['employee_id']:0]);app()->make(GrowthCommandServices::class)->event($c,'ISSUE_REVIEWED','issue_review',$id,['issue_id'=>(int)$r['id'],'employee_id'=>(int)$r['responsible_employee_id'],'store_id'=>(int)$r['store_id'],'passed'=>$passed]);return ['id'=>$id,'state'=>$passed?'CLOSED':'OPEN','message'=>$passed?'复查通过，问题已关闭':'已退回整改'];
        });
    }
    /** 交接延期作废必须有原因，既有证据随原问题关系保留。 */
    public function action(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'issue.action',$in,function()use($c,$in){
            $r=$this->issue($c,(int)($in['issue_id']??0),true);$reason=trim((string)($in['reason']??''));$action=(string)($in['action']??'');if($reason===''||in_array($r['state'],['CLOSED','CANCELLED'],true))throw new AdminException('问题已结束或缺少处理原因');
            $change=[];if($action==='extend'){$due=(int)($in['due_at']??0);if($due<=max(time(),(int)$r['due_at']))throw new AdminException('新期限须晚于原期限和当前时间');$change=['due_at'=>$due];}
            elseif($action==='transfer'){if($r['state']!=='OPEN')throw new AdminException('待复查时请先完成本轮复查再转交');$person=(int)($in['responsible_employee_id']??0);app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$person);$change=['responsible_employee_id'=>$person];}
            elseif($action==='cancel')$change=['state'=>'CANCELLED'];else throw new AdminException('问题操作无效');
            if($action==='transfer'){
                Db::name('growth_task')->where('id',$r['task_id'])->where('state','<>','COMPLETED')->update(['state'=>'CANCELLED']);
                $change['task_id']=app()->make(GrowthTaskServices::class)->insertTask($c,$change['responsible_employee_id'],['assignment_key'=>'issue:'.$r['id'].':'.$change['responsible_employee_id'].':'.bin2hex(random_bytes(6)),'title'=>'整改：'.$r['item_title'],'kind'=>'rectification','source_type'=>'issue','source_id'=>$r['id'],'handler_employee_id'=>$r['reviewer_employee_id'],'due_at'=>$r['due_at'],'rules_json'=>'{}']);
                $proofs=Db::name('growth_asset_reference')->where('task_id',$r['task_id'])->distinct(true)->column('asset_id');app()->make(GrowthAssetServices::class)->reference(array_map('intval',$proofs),'issue_transfer',(int)$change['task_id'],(int)$change['task_id']);
            }elseif($action==='extend')Db::name('growth_task')->where('id',$r['task_id'])->update(['due_at'=>$change['due_at']]);
            elseif($action==='cancel')Db::name('growth_task')->where('id',$r['task_id'])->where('state','<>','COMPLETED')->update(['state'=>'CANCELLED']);
            Db::name('growth_issue')->where('id',$r['id'])->update($change);Db::name('growth_issue_action')->insert(['issue_id'=>$r['id'],'action'=>$action,'reason'=>$reason,'before_json'=>GrowthCommandServices::json($r),'after_json'=>GrowthCommandServices::json($change),'actor_key'=>$c['actor_key'],'created_at'=>time()]);return ['id'=>(int)$r['id'],'message'=>'问题安排已调整'];
        });
    }
    /** 补训只新增关联任务，任何学习/练习通过都不能替代原问题的复查关闭。 */
    public function train(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'issue.train',$in,function()use($c,$in){$r=$this->issue($c,(int)($in['issue_id']??0),true);if($r['state']==='CANCELLED')throw new AdminException('已撤销问题不可安排补训');$reason=trim((string)($in['reason']??''));if($reason==='')throw new AdminException('请填写补训原因');$kind=(string)($in['kind']??'course');$source=(int)($in['source_id']??0);$employee=(int)($in['employee_id']??$r['responsible_employee_id']);app()->make(GrowthAccessServices::class)->assertEmployee($c,$employee);$due=(int)($in['due_at']??0);if($due&&$due<=time())throw new AdminException('补训期限须晚于当前时间');$data=['assignment_key'=>'issue-training:'.$r['id'].':'.$kind.':'.$source,'title'=>'问题补训','due_at'=>$due,'rules_json'=>'{}'];
            if($kind==='course'){$p=Db::name('growth_publication')->where('id',$source)->where('state','PUBLISHED')->find();if(!$p)throw new AdminException('请选择有效课程');app()->make(GrowthContentServices::class)->owned($c,(int)$p['content_id']);$data+=['kind'=>'course','publication_id'=>$source,'source_type'=>'issue_training','source_id'=>$r['id']];$data['title']=$p['title'];}
            elseif(in_array($kind,['assignment','coaching','evaluation','offline'],true)){$a=Db::name('growth_activity')->where('id',$source)->where('kind',$kind)->find();if(!$a)throw new AdminException('请选择有效活动');if(!$c['super'])app()->make(GrowthAccessServices::class)->assertEmployee($c,(int)$a['owner_employee_id']);$data+=['kind'=>$kind,'source_type'=>'activity','source_id'=>$source,'handler_employee_id'=>$a['handler_employee_id']];$data['title']=$a['title'];}else throw new AdminException('请选择课程、作业、带教或实操活动');
            $task=app()->make(GrowthTaskServices::class)->insertTask($c,$employee,$data);if(!Db::name('growth_issue_training')->where('issue_id',$r['id'])->where('task_id',$task)->find())Db::name('growth_issue_training')->insert(['issue_id'=>$r['id'],'task_id'=>$task,'reason'=>$reason,'assigned_by'=>$c['actor_key'],'assigned_at'=>time()]);return ['task_id'=>$task,'message'=>'补训已派发；原问题仍须独立整改与复查'];});
    }

    /** 交接只作用于未完成职责，不回写替换已正式检查及复核的历史人员。 */
    public function handover(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'inspection.handover',$in,function()use($c,$in){
            $r=$this->inspection($c,(int)($in['inspection_id']??0),true);
            $employee=(int)($in['employee_id']??0);app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$employee);
            $reason=trim((string)($in['reason']??''));if($reason==='')throw new AdminException('请填写交接原因');
            if($r['state']==='ASSIGNED')$change=['executor_employee_id'=>$employee];
            elseif($r['state']==='PENDING_REVIEW')$change=['reviewer_employee_id'=>$employee];
            else throw new AdminException('本次检查已正式完成，不再交接历史职责');
            Db::name('growth_inspection')->where('id',$r['id'])->update($change);
            Db::name('growth_inspection_action')->insert(['inspection_id'=>$r['id'],'action'=>'handover','reason'=>$reason,'before_json'=>GrowthCommandServices::json($r),'after_json'=>GrowthCommandServices::json($change),'actor_key'=>$c['actor_key'],'created_at'=>time()]);
            return ['id'=>(int)$r['id'],'message'=>'未处理巡检职责已交接，历史结果保留'];
        });
    }
    /** 独立复核只确认检查记录，不能代替整改问题的逐条复查。 */
    public function confirmInspection(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'inspection.confirm',$in,function()use($c,$in){$r=$this->inspection($c,(int)($in['id']??0),true);$note=trim((string)($in['note']??''));if((int)$r['reviewer_employee_id']!==$c['employee_id']||$r['state']!=='PENDING_REVIEW'||$note==='')throw new AdminException('仅指派复核人可确认待复核巡检，并须填写依据');Db::name('growth_inspection')->where('id',$r['id'])->update(['state'=>'REVIEWED','review_note'=>$note,'reviewed_at'=>time()]);return ['id'=>(int)$r['id'],'message'=>'巡检复核已确认，整改问题仍独立复查'];});
    }
    private function inspection(array $c,int $id,bool $lock=false): array{$q=Db::name('growth_inspection')->where('id',$id);if($lock)$q->lock(true);$r=$q->find();if(!$r)throw new AdminException('巡检不存在');if($c['manager'])$this->store($c,(int)$r['store_id']);elseif(!in_array($c['employee_id'],[(int)$r['executor_employee_id'],(int)$r['reviewer_employee_id']],true))throw new AdminException('未指派此巡检');return $r;}
    private function issue(array $c,int $id,bool $lock=false): array{$q=Db::name('growth_issue')->where('id',$id);if($lock)$q->lock(true);$r=$q->find();if(!$r)throw new AdminException('问题不存在');if($c['manager'])$this->store($c,(int)$r['store_id']);elseif(!in_array($c['employee_id'],[(int)$r['responsible_employee_id'],(int)$r['reviewer_employee_id']],true))throw new AdminException('未指派此整改问题');return $r;}
    private function store(array $c,int $id): void{if($id<=0||(!$c['super']&&!in_array($id,(array)$c['store_ids'],true)))throw new AdminException('门店不在当前巡检范围');}
}
