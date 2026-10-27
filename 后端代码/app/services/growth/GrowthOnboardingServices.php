<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 入职规则只读取已有首次正式任职事实；登录、姓名编辑、调岗和复职不视为新人入职。 */
final class GrowthOnboardingServices
{
    /** 规则与实际派发结果分别分页，不把保存规则当作已派发成功。 */
    public function listing(array $c,array $in=[]): array
    {
        $ids=app()->make(GrowthAccessServices::class)->employees($c);$q=Db::name('growth_onboarding_rule');if(!$c['super'])$q->whereIn('owner_employee_id',$ids);$ruleIds=(clone $q)->column('id');[$rules,$rulePage]=GrowthPage::take($q,$in['rules_page']??1);[$dispatches,$dispatchPage]=GrowthPage::take(Db::name('growth_onboarding_dispatch')->whereIn('rule_id',$ruleIds),$in['dispatches_page']??1);
        $stores=Db::name('system_store')->where('is_del',0);$orgs=Db::name('organization')->where('is_del',0);if(!$c['super']){$stores->whereIn('id',(array)$c['store_ids']);$orgs->whereIn('id',$c['organization_ids']);}
        return ['rules'=>$rules,'dispatches'=>$dispatches,'pagination'=>['rules'=>$rulePage,'dispatches'=>$dispatchPage],'stores'=>$stores->field('id,name')->select()->toArray(),'organizations'=>$orgs->field('id,name')->select()->toArray(),'positions'=>Db::name('position')->where('status',1)->field('id,name')->select()->toArray()];
    }
    /** 规则修改记录当前正式任职水位，老员工须明确补发。 */
    public function save(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'onboarding.save',$in,function()use($c,$in){
            if($c['employee_id']<=0)throw new AdminException('自动派发规则需关联真实员工负责人');$title=trim((string)($in['title']??''));$days=$in['relative_days']??30;$handler=(int)($in['responsible_employee_id']??0);app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$handler);if($title===''||filter_var($days,FILTER_VALIDATE_INT)===false||(int)$days<1||(int)$days>3650)throw new AdminException('请填写规则名称及1至3650天期限');
            $release=Db::name('growth_map_release')->where('id',(int)($in['map_release_id']??0))->find();if(!$release)throw new AdminException('请选择已发布培养地图');$map=Db::name('growth_map')->where('id',$release['map_id'])->find();if(!$c['super'])app()->make(GrowthAccessServices::class)->assertEmployee($c,(int)$map['owner_employee_id']);
            $store=(int)($in['store_id']??0);$org=(int)($in['organization_id']??0);$position=(int)($in['position_id']??0);if(!$c['super']&&(($store&&!in_array($store,(array)$c['store_ids'],true))||($org&&!in_array($org,$c['organization_ids'],true))))throw new AdminException('入职匹配范围超出当前权限');if(($store&&!Db::name('system_store')->where('id',$store)->where('is_del',0)->find())||($org&&!Db::name('organization')->where('id',$org)->where('is_del',0)->find()))throw new AdminException('匹配门店或组织不存在');if($position&&!Db::name('position')->where('id',$position)->where('status',1)->find())throw new AdminException('岗位不存在');
            $id=(int)($in['id']??0);$old=$id?Db::name('growth_onboarding_rule')->where('id',$id)->lock(true)->find():null;if($id&&!$old)throw new AdminException('规则不存在');if($old&&!$c['super'])app()->make(GrowthAccessServices::class)->assertEmployee($c,(int)$old['owner_employee_id']);
            $row=['title'=>mb_substr($title,0,180),'enabled'=>!empty($in['enabled'])?1:0,'organization_id'=>$org,'store_id'=>$store,'position_id'=>$position,'map_release_id'=>$release['id'],'relative_days'=>(int)$days,'responsible_employee_id'=>$handler,'updated_at'=>time()];
            if($old)Db::name('growth_onboarding_rule')->where('id',$id)->update($row);else $id=(int)Db::name('growth_onboarding_rule')->insertGetId($row+['owner_employee_id'=>$c['employee_id'],'created_by'=>$c['actor_key'],'created_at'=>time()]);
            // 开启或修改规则从当前正式任职水位开始；老员工只能通过计划明确补发。
            $cursor=['last_job_id'=>(int)Db::name('staff_job_position')->max('id'),'enabled_since'=>time(),'updated_at'=>time()];if(Db::name('growth_onboarding_cursor')->where('rule_id',$id)->find())Db::name('growth_onboarding_cursor')->where('rule_id',$id)->update($cursor);else Db::name('growth_onboarding_cursor')->insert(['rule_id'=>$id]+$cursor);
            $orgCursor=['last_org_employee_id'=>(int)Db::name('organization_employee')->max('id'),'enabled_since'=>time(),'updated_at'=>time()];if(Db::name('growth_onboarding_org_cursor')->where('rule_id',$id)->find())Db::name('growth_onboarding_org_cursor')->where('rule_id',$id)->update($orgCursor);else Db::name('growth_onboarding_org_cursor')->insert(['rule_id'=>$id]+$orgCursor);
            return ['id'=>$id,'message'=>'入职规则已保存，仅匹配此后首次生效任职；老员工不自动补发'];
        });
    }
    /** 只消费首次正式任职来源；未来生效和失败重试沿用原来源键。 */
    public function tick(): int
    {
        $rules=Db::name('growth_onboarding_rule')->where('enabled',1)->order('id')->select()->toArray();$n=0;
        foreach($rules as $rule){$cursor=Db::name('growth_onboarding_cursor')->where('rule_id',$rule['id'])->find();if(!$cursor)continue;
            $rows=Db::name('staff_job_position')->where('id','>',$cursor['last_job_id'])->order('id')->limit(500)->select()->toArray();
            foreach($rows as $job){if($job['start_time']>time()){
                    $exists=Db::name('growth_onboarding_dispatch')->where('rule_id',$rule['id'])->where('source_event_key','job:'.$job['id'])->find();if(!$exists)Db::name('growth_onboarding_dispatch')->insert(['rule_id'=>$rule['id'],'employee_id'=>$job['employee_id'],'source_event_key'=>'job:'.$job['id'],'state'=>'SCHEDULED','created_at'=>time()]);
                    Db::name('growth_onboarding_cursor')->where('rule_id',$rule['id'])->update(['last_job_id'=>$job['id'],'updated_at'=>time()]);continue;}
                if($job['status']&& !$job['is_del']&& !$job['end_time']&&$job['start_time']>=$cursor['enabled_since']&&(int)Db::name('staff_job_position')->where('employee_id',$job['employee_id'])->min('id')===(int)$job['id']&&$this->firstEmployment($job)&&$this->matches($rule,$job))$n+=$this->dispatch($rule,$job);
                Db::name('growth_onboarding_cursor')->where('rule_id',$rule['id'])->update(['last_job_id'=>$job['id'],'updated_at'=>time()]);
            }
            $n+=$this->organizationEntries($rule,$cursor);
            // 失败保留原来源键，重试不为该员工另造入职事件。
            $failed=Db::name('growth_onboarding_dispatch')->where('rule_id',$rule['id'])->whereIn('state',['FAILED','SCHEDULED'])->order('id')->limit(100)->select()->toArray();foreach($failed as $f){$isOrg=strpos($f['source_event_key'],'org:')===0;$source=$isOrg?Db::name('organization_employee')->where('id',(int)substr($f['source_event_key'],4))->find():null;$job=$isOrg?($source?$this->organizationJob($source):null):Db::name('staff_job_position')->where('id',(int)substr($f['source_event_key'],4))->find();if($isOrg&&$job){if($job['status']&&!$job['is_del']&&$this->matches($rule,$job))$n+=$this->dispatch($rule,$job);continue;}if($job&&$job['status']&&!$job['is_del']&&!$job['end_time']&&$job['start_time']<=time()&&$job['start_time']>=$cursor['enabled_since']&&(int)Db::name('staff_job_position')->where('employee_id',$job['employee_id'])->min('id')===(int)$job['id']&&$this->firstEmployment($job)&&$this->matches($rule,$job))$n+=$this->dispatch($rule,$job);}
        }return $n;
    }
    private function firstEmployment(array $job): bool
    {
        $org=Db::name('organization_employee')->where('employee_id',$job['employee_id'])->order('id')->find();return !$org||(int)$org['add_time']>=(int)$job['start_time'];
    }
    private function organizationJob(array $row): array{return ['id'=>$row['id'],'source_key'=>'org:'.$row['id'],'employee_id'=>$row['employee_id'],'store_id'=>0,'position_id'=>0,'status'=>$row['status'],'is_del'=>$row['is_del'],'start_time'=>$row['add_time'],'end_time'=>0];}
    /** 组织直属员工以首条正式归属为来源；已有任职或历史组织归属均不能冒充新入职。 */
    private function organizationEntries(array $rule,array $cursor): int
    {
        $q=Db::name('growth_onboarding_org_cursor')->where('rule_id',$rule['id']);$orgCursor=$q->find();if(!$orgCursor){Db::name('growth_onboarding_org_cursor')->insert(['rule_id'=>$rule['id'],'last_org_employee_id'=>(int)Db::name('organization_employee')->max('id'),'enabled_since'=>time(),'updated_at'=>time()]);return 0;}
        $n=0;$rows=Db::name('organization_employee')->where('id','>',$orgCursor['last_org_employee_id'])->order('id')->limit(500)->select()->toArray();foreach($rows as $row){if($row['status']&&!$row['is_del']&&$row['add_time']>=$orgCursor['enabled_since']&&(int)Db::name('organization_employee')->where('employee_id',$row['employee_id'])->min('id')===(int)$row['id']&&!Db::name('staff_job_position')->where('employee_id',$row['employee_id'])->find()){$job=$this->organizationJob($row);if($this->matches($rule,$job))$n+=$this->dispatch($rule,$job);}$q->update(['last_org_employee_id'=>$row['id'],'updated_at'=>time()]);}return $n;
    }
    private function matches(array $r,array $j): bool
    {
        if($r['store_id']&&(int)$r['store_id']!==(int)$j['store_id'])return false;if($r['position_id']&&(int)$r['position_id']!==(int)$j['position_id'])return false;if($r['organization_id']){$ids=[(int)$r['organization_id']];$orgs=Db::name('organization')->where('is_del',0)->field('id,pid')->select()->toArray();do{$before=count($ids);foreach($orgs as $org)if(in_array((int)$org['pid'],$ids,true))$ids[]=(int)$org['id'];$ids=array_values(array_unique($ids));}while(count($ids)>$before);$match=(int)$j['store_id']>0?Db::name('organization_store')->where('store_id',$j['store_id'])->whereIn('org_id',$ids)->find():Db::name('organization_employee')->where('employee_id',$j['employee_id'])->whereIn('org_id',$ids)->where('status',1)->where('is_del',0)->find();if(!$match)return false;}return true;
    }
    private function dispatch(array $rule,array $job): int
    {
        $key=$job['source_key']??('job:'.$job['id']);$q=Db::name('growth_onboarding_dispatch')->where('rule_id',$rule['id'])->where('employee_id',$job['employee_id'])->where('source_event_key',$key);$old=$q->find();if($old&&$old['state']==='DONE')return 0;if($old&&$old['state']==='FAILED'){$last=Db::name('growth_onboarding_attempt')->where('dispatch_id',$old['id'])->order('id','desc')->find();if($last&&$last['created_at']>time()-30)return 0;}
        try{Db::transaction(function()use($rule,$job,$key,$old){$c=app()->make(GrowthAccessServices::class)->principal((int)$rule['owner_employee_id'],'growth:onboarding:'.$rule['id'],false,true);$access=app()->make(GrowthAccessServices::class);$access->assertActiveEmployee($c,(int)$rule['owner_employee_id']);$access->assertActiveEmployee($c,(int)$job['employee_id']);$access->assertActiveEmployee($c,(int)$rule['responsible_employee_id']);
                $result=app()->make(GrowthPlanServices::class)->publishPlan($c,['title'=>$rule['title'],'map_release_id'=>(int)$rule['map_release_id'],'responsible_employee_id'=>(int)$rule['responsible_employee_id'],'employee_ids'=>[(int)$job['employee_id']],'starts_at'=>time(),'due_at'=>time()+(int)$rule['relative_days']*86400,'idempotency_key'=>'onboard-'.$rule['id'].'-'.str_replace(':','-',$key)]);$row=['state'=>'DONE','plan_id'=>$result['id'],'failure_reason'=>''];if($old)Db::name('growth_onboarding_dispatch')->where('id',$old['id'])->update($row);else Db::name('growth_onboarding_dispatch')->insert($row+['rule_id'=>$rule['id'],'employee_id'=>$job['employee_id'],'source_event_key'=>$key,'created_at'=>time()]);});$this->attempt($rule,$job,$key,'DONE','');return 1;
        }catch(\Throwable $e){$row=['state'=>'FAILED','failure_reason'=>mb_substr($e->getMessage(),0,255)];if($old)Db::name('growth_onboarding_dispatch')->where('id',$old['id'])->update($row);else Db::name('growth_onboarding_dispatch')->insert($row+['rule_id'=>$rule['id'],'employee_id'=>$job['employee_id'],'source_event_key'=>$key,'created_at'=>time()]);$this->attempt($rule,$job,$key,'FAILED',$row['failure_reason']);return 0;}
    }
    private function attempt(array $rule,array $job,string $key,string $state,string $reason): void
    {
        $id=(int)Db::name('growth_onboarding_dispatch')->where('rule_id',$rule['id'])->where('employee_id',$job['employee_id'])->where('source_event_key',$key)->value('id');Db::name('growth_onboarding_attempt')->insert(['dispatch_id'=>$id,'rule_id'=>$rule['id'],'source_event_key'=>$key,'state'=>$state,'failure_reason'=>$reason,'created_at'=>time()]);
    }
}
