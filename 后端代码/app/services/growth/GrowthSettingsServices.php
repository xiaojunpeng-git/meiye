<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 企业默认和课程模板只给新草稿/新任务提供初值；保存使用整份配置，不引入版本令牌。 */
final class GrowthSettingsServices
{
    /** 开关恢复建立新入职水位，关闭期间的任职不自动追发；已有正式结果原样保留。 */
    public function save(array $c,array $in,int $admin): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'settings.save',$in,function()use($c,$in,$admin){
            $enabled=!empty($in['enabled']);if($enabled)app()->make(GrowthSchemaServices::class)->assertReady();
            $old=Db::name('growth_setting')->where('id',1)->lock(true)->find();if(!$old)throw new AdminException('成长管理尚未安装');
            $templates=(array)($in['templates']??[]);$templates['reminders']=$this->reminders((array)($templates['reminders']??[]));
            $rows=[];foreach(array_values((array)($templates['course_templates']??[])) as $r){$id=trim((string)($r['key']??''));$title=trim((string)($r['title']??''));if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$id)||$title==='')throw new AdminException('课程模板须有名称与稳定标识');$purpose=(string)($r['purpose']??'course');if(!in_array($purpose,['course','knowledge'],true))throw new AdminException('模板用途无效');$coverage=(int)($r['coverage_percent']??90);if($coverage<1||$coverage>100)throw new AdminException('模板观看比例为1至100');$handler=(int)($r['confirmer_employee_id']??0);if($handler)app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$handler);
                $rows[]=['key'=>$id,'title'=>mb_substr($title,0,180),'purpose'=>$purpose,'completion'=>($r['completion']??'confirm')==='coverage'?'coverage':'confirm','coverage_percent'=>$coverage,'foreground_required'=>(bool)($r['foreground_required']??true),'allow_speed'=>(bool)($r['allow_speed']??false),'single_session'=>(bool)($r['single_session']??true),'confirmer_employee_id'=>$handler,'reminder_overrides'=>$this->reminders((array)($r['reminder_overrides']??[])),'reward_selection'=>$this->rewards((array)($r['reward_selection']??['mode'=>'default'])),'maintained_by'=>$c['actor_key'],'maintained_at'=>time()];}
            if(count($rows)>50||count(array_unique(array_column($rows,'key')))!==count($rows))throw new AdminException('最多50份且模板标识不能重复');$templates['course_templates']=$rows;
            if($enabled&&!(int)$old['enabled']){
                Db::name('growth_onboarding_cursor')->where('rule_id','>',0)->update(['last_job_id'=>(int)Db::name('staff_job_position')->max('id'),'enabled_since'=>time(),'updated_at'=>time()]);
                Db::name('growth_onboarding_org_cursor')->where('rule_id','>',0)->update(['last_org_employee_id'=>(int)Db::name('organization_employee')->max('id'),'enabled_since'=>time(),'updated_at'=>time()]);
                // 预排的入职在停用期到期，不在恢复时静默补发；需要时管理员另行补发名单。
                $scheduled=Db::name('growth_onboarding_dispatch')->where('state','SCHEDULED')->select()->toArray();foreach($scheduled as $d){$job=Db::name('staff_job_position')->where('id',(int)substr($d['source_event_key'],4))->find();if($job&&$job['start_time']<=time()){Db::name('growth_onboarding_dispatch')->where('id',$d['id'])->update(['state'=>'SKIPPED','failure_reason'=>'模块停用期间生效，恢复不自动补发']);Db::name('growth_onboarding_attempt')->insert(['dispatch_id'=>$d['id'],'rule_id'=>$d['rule_id'],'source_event_key'=>$d['source_event_key'],'state'=>'SKIPPED','failure_reason'=>'恢复时不自动补发停用期间入职','created_at'=>time()]);}}
            }
            $data=['enabled'=>$enabled?1:0,'business_link_enabled'=>!empty($in['business_link_enabled'])?1:0,'templates_json'=>GrowthCommandServices::json($templates),'updated_by'=>$admin,'updated_at'=>time()];Db::name('growth_setting')->where('id',1)->update($data);
            if((int)$old['enabled']!==$data['enabled']){$pending=(int)Db::name('growth_task')->whereNotIn('state',['COMPLETED','CANCELLED'])->count();$reason=mb_substr((string)($in['reason']??''),0,1000);$action=(int)Db::name('growth_setting_action')->insertGetId(['enabled_before'=>(int)$old['enabled'],'enabled_after'=>$data['enabled'],'unfinished_tasks'=>$pending,'reason'=>$reason,'actor_key'=>$c['actor_key'],'created_at'=>time()]);app()->make(GrowthCommandServices::class)->event($c,$enabled?'GROWTH_ENABLED':'GROWTH_DISABLED','setting_action',$action,['unfinished_tasks'=>$pending,'reason'=>$reason]);}return $data;
        });
    }
    /** 设置页只观察真实事件及尝试记录，普通维护者仅看本人发起或本人结果。 */
    public function monitor(array $c): array
    {
        $schema=app()->make(GrowthSchemaServices::class)->status();if(!$schema['ready'])return ['ready'=>false,'pending'=>0,'failed'=>0,'rows'=>[]];$q=Db::name('growth_event');if(!$c['super'])$q->where(function($w)use($c){$w->where('actor_key',$c['actor_key'])->whereOr('employee_id',$c['employee_id']);});$pending=(int)(clone $q)->where('delivery_state','PENDING')->count();$failed=(int)(clone $q)->where('delivery_state','FAILED')->count();$rows=$q->whereIn('delivery_state',['PENDING','FAILED'])->field('id,event_type,source_type,source_id,delivery_state,occurred_at')->order('id','desc')->limit(20)->select()->toArray();foreach($rows as &$row){$row['attempts']=(int)Db::name('growth_delivery_attempt')->where('event_id',$row['id'])->count();$row['last_attempt_at']=(int)Db::name('growth_delivery_attempt')->where('event_id',$row['id'])->max('created_at');}return ['ready'=>true,'enabled'=>app()->make(GrowthAccessServices::class)->isEnabled(),'pending'=>$pending,'failed'=>$failed,'rows'=>$rows,'notice'=>'后台定期按原正式结果恢复；失败至少间隔30秒重试，重复运行不重复发放。'];
    }
    /** 模板提醒覆盖只接受与企业策略相同字段，缺省值由企业策略补齐。 */
    public function reminders(array $in): array
    {
        $out=[];foreach(['assignment','result','due','overdue','pending','certificate','summary'] as $key)if(array_key_exists($key,$in)){if(!is_bool($in[$key]))throw new AdminException('提醒开关须为明确的是或否');$out[$key]=$in[$key];}
        foreach(['lead_hours'=>[0,720],'frequency_hours'=>[1,168]] as $key=>$range)if(array_key_exists($key,$in)){if(is_bool($in[$key])||filter_var($in[$key],FILTER_VALIDATE_INT)===false||$in[$key]<$range[0]||$in[$key]>$range[1])throw new AdminException('提醒提前0至720小时，频率1至168小时');$out[$key]=(int)$in[$key];}
        if(isset($in['recipient'])){if(!in_array($in['recipient'],['executor','handler'],true))throw new AdminException('提醒接收对象无效');$out['recipient']=$in['recipient'];}return $out;
    }
    /** 选用奖励仍引用统一规则ID，发布和分配时分别验证/冻结，不新增另一套积分算法。 */
    public function rewards(array $in): array
    {
        $mode=(string)($in['mode']??'default');if(!in_array($mode,['default','selected'],true))throw new AdminException('奖励选择方式无效');$out=['mode'=>$mode];foreach(['point_rule_ids'=>'growth_point_rule','certificate_rule_ids'=>'growth_certificate_rule'] as $key=>$table){$ids=array_values(array_unique(array_filter(array_map('intval',(array)($in[$key]??[])))));if(count($ids)>100||($ids&&Db::name($table)->whereIn('id',$ids)->where('enabled',1)->count()!==count($ids)))throw new AdminException('所选奖励规则已停用或不存在');$out[$key]=$ids;}return $out;
    }
}
