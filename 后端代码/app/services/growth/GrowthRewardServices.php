<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 员工奖励是有来源的流水，不修改会员积分，也不把业务分数直接累加为积分。 */
final class GrowthRewardServices
{
    private const EVENTS=['TASK_COMPLETED','EXAM_PUBLISHED','EVALUATION_CONFIRMED'];
    /** 只读返回独立积分证书规则与成长等级，不混用会员积分。 */
    public function settings(): array
    {
        return ['point_rules'=>Db::name('growth_point_rule')->order('id','desc')->select()->toArray(),'levels'=>Db::name('growth_level')->order('threshold')->select()->toArray(),'certificate_rules'=>Db::name('growth_certificate_rule')->order('id','desc')->select()->toArray()];
    }
    /** 规则调整用于新分配，已有任务保存的实际规则不被追溯替换。 */
    public function saveRule(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'reward.rule',$in,function()use($c,$in){
            $kind=(string)($in['kind']??'points');$title=trim((string)($in['title']??''));$event=(string)($in['event_type']??'TASK_COMPLETED');if($title===''||!in_array($event,self::EVENTS,true))throw new AdminException('请填写名称并选择有效奖励来源');
            $base=['title'=>mb_substr($title,0,180),'event_type'=>$event,'enabled'=>!empty($in['enabled'])?1:0,'require_passed'=>!empty($in['require_passed'])?1:0,'created_by'=>$c['actor_key'],'created_at'=>time()];
            if($kind==='points'){$points=$in['points']??null;$max=$in['max_awards']??1;if(is_bool($points)||is_bool($max)||filter_var($points,FILTER_VALIDATE_INT)===false||(int)$points<1||(int)$points>100000||filter_var($max,FILTER_VALIDATE_INT)===false||(int)$max<1||(int)$max>100)throw new AdminException('积分为1至100000整数，次数上限1至100');$table='growth_point_rule';$base+=['points'=>(int)$points,'first_only'=>!empty($in['first_only'])?1:0,'max_awards'=>(int)$max];}
            elseif($kind==='certificate'){$person=(int)($in['responsible_employee_id']??0);app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$person);$days=(int)($in['valid_days']??0);if($days<0||$days>36500)throw new AdminException('证书有效天数无效');$table='growth_certificate_rule';$base+=['responsible_employee_id'=>$person,'valid_days'=>$days];}
            else throw new AdminException('奖励规则类型无效');
            $old=(int)($in['id']??0);if($old)Db::name($table)->where('id',$old)->update(['enabled'=>0]);$id=(int)Db::name($table)->insertGetId($base);return ['id'=>$id,'message'=>'规则已保存，仅用于新派发任务'];
        });
    }
    /** 门槛唯一且有序，按有效独立积分重算等级而不改变岗位或薪资。 */
    public function saveLevels(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'reward.levels',$in,function()use($in){
            $levels=(array)($in['levels']??[]);$thresholds=[];foreach($levels as $r){if(trim((string)($r['title']??''))===''||is_bool($r['threshold']??null)||filter_var($r['threshold']??null,FILTER_VALIDATE_INT)===false||(int)$r['threshold']<0)throw new AdminException('等级需要名称和非负整数积分门槛');$thresholds[]=(int)$r['threshold'];}if(count($thresholds)!==count(array_unique($thresholds)))throw new AdminException('等级门槛不能重复');
            Db::name('growth_level')->where('id','>',0)->update(['enabled'=>0]);foreach($levels as $r){$old=Db::name('growth_level')->where('threshold',(int)$r['threshold'])->find();$row=['title'=>mb_substr((string)$r['title'],0,100),'threshold'=>(int)$r['threshold'],'enabled'=>1];if($old)Db::name('growth_level')->where('id',$old['id'])->update($row);else Db::name('growth_level')->insert($row);}foreach(Db::name('growth_point_ledger')->distinct(true)->column('employee_id') as $person)$this->refreshLevel((int)$person);return ['message'=>'等级门槛已保存，按有效积分重新展示'];
        });
    }
    /** 人工积分通过有原因和依据的新流水增减，不能手填或覆盖余额。 */
    public function adjust(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'points.adjust',$in,function()use($c,$in){
            $employee=(int)($in['employee_id']??0);app()->make(GrowthAccessServices::class)->assertEmployee($c,$employee);$delta=$in['delta']??null;$reason=trim((string)($in['reason']??''));if(filter_var($delta,FILTER_VALIDATE_INT)===false||(int)$delta===0||abs((int)$delta)>100000||$reason==='')throw new AdminException('请填写非零整数积分与调整原因');
            Db::name('employee')->where('id',$employee)->lock(true)->find();$id=(int)Db::name('growth_point_ledger')->insertGetId(['employee_id'=>$employee,'delta'=>(int)$delta,'source_type'=>'manual','source_key'=>'manual:'.$in['idempotency_key'],'reason'=>$reason,'evidence_json'=>GrowthCommandServices::json(['basis'=>(string)($in['basis']??''),'scope'=>$this->personScope($employee)]),'actor_key'=>$c['actor_key'],'occurred_at'=>time()]);$this->refreshLevel($employee);return ['id'=>$id,'balance'=>$this->balance($employee),'message'=>'调整已记入独立流水'];
        });
    }
    /** 手工记录固定发生时人员归属，调店后不把原门店证据迁入新门店。 */
    private function personScope(int $employee): array
    {
        $store=(int)Db::name('system_store_staff')->where('employee_id',$employee)->where('status',1)->where('is_del',0)->order('id','desc')->value('store_id');
        $org=$store?(int)Db::name('organization_store')->where('store_id',$store)->value('org_id'):(int)Db::name('organization_employee')->where('employee_id',$employee)->where('status',1)->where('is_del',0)->value('org_id');return ['store_id'=>$store,'organization_id'=>$org];
    }
    /** 调用方先校验员工可读范围；余额仅为独立积分流水净额。 */
    public function balance(int $employee): int{return (int)Db::name('growth_point_ledger')->where('employee_id',$employee)->sum('delta');}
    /** 等级投影与历史使用有效积分和当时门槛；不授予岗位或薪资权限。调用方须处于事务。 */
    public function refreshLevel(int $employee): void
    {
        Db::name('employee')->where('id',$employee)->lock(true)->find();$balance=$this->balance($employee);$level=$this->level($employee);$current=Db::name('growth_level_state')->where('employee_id',$employee)->lock(true)->find();$ledger=(int)Db::name('growth_point_ledger')->where('employee_id',$employee)->max('id');$id=(int)($level['id']??0);$title=(string)($level['title']??'');
        if((int)($current['level_id']??0)!==$id||($current['level_title']??'')!==$title)Db::name('growth_level_event')->insert(['employee_id'=>$employee,'previous_level_id'=>(int)($current['level_id']??0),'previous_level_title'=>(string)($current['level_title']??''),'level_id'=>$id,'level_title'=>$title,'point_balance'=>$balance,'source_ledger_id'=>$ledger,'reason'=>$current&&(int)$current['source_ledger_id']===$ledger?'等级门槛调整':'有效积分变化','rules_json'=>GrowthCommandServices::json(Db::name('growth_level')->where('enabled',1)->order('threshold')->select()->toArray()),'occurred_at'=>time()]);
        $row=['level_id'=>$id,'level_title'=>$title,'point_balance'=>$balance,'source_ledger_id'=>$ledger,'updated_at'=>time()];if($current)Db::name('growth_level_state')->where('employee_id',$employee)->update($row);else Db::name('growth_level_state')->insert(['employee_id'=>$employee]+$row);
    }
    /** 等级依据有效积分和已启用门槛，不等同岗位资格或公开排名。 */
    public function level(int $employee): ?array{return Db::name('growth_level')->where('enabled',1)->where('threshold','<=',max(0,$this->balance($employee)))->order('threshold','desc')->find();}

    /** 内部证书须有正式来源；外部登记明确证书编号、发放日、依据及适用到期日。 */
    public function registerCertificate(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'certificate.register',$in,function()use($c,$in){
            $employee=(int)($in['employee_id']??0);app()->make(GrowthAccessServices::class)->assertEmployee($c,$employee);$title=trim((string)($in['title']??''));$number=trim((string)($in['certificate_number']??''));$basis=trim((string)($in['basis']??''));$issued=(int)($in['issued_at']??0);$expires=(int)($in['expires_at']??0);if($title===''||$number===''||$basis===''||$issued<=0||($expires&&$expires<=$issued))throw new AdminException('请填写外部证书名称、编号、发放日期、来源依据和有效到期日');
            $assets=app()->make(GrowthAssetServices::class)->validateEvidence($c,(array)($in['asset_ids']??[]));$id=(int)Db::name('growth_certificate')->insertGetId(['employee_id'=>$employee,'title'=>mb_substr($title,0,180),'certificate_number'=>mb_substr($number,0,100),'origin'=>'external','source_type'=>'external','source_key'=>'external:'.$in['idempotency_key'],'state'=>$expires&&$expires<=time()?'EXPIRED':'VALID','evidence_json'=>GrowthCommandServices::json(['basis'=>$basis,'asset_ids'=>$assets,'scope'=>$this->personScope($employee)]),'issued_at'=>$issued,'expires_at'=>$expires,'created_by'=>$c['actor_key']]);return ['id'=>$id,'message'=>'外部证书已登记'];
        });
    }
    /** 续期撤销均保留原因和原有效期，过期不自动续期。 */
    public function certificateAction(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'certificate.action',$in,function()use($c,$in){$id=(int)($in['id']??0);$r=Db::name('growth_certificate')->where('id',$id)->lock(true)->find();if(!$r)throw new AdminException('证书不存在');app()->make(GrowthAccessServices::class)->assertEmployee($c,(int)$r['employee_id']);$action=(string)($in['action']??'');$reason=trim((string)($in['reason']??''));if($reason==='')throw new AdminException('请填写复核或撤销依据');$next=(int)($in['expires_at']??0);
            if($action==='renew'){if($r['state']==='REVOKED'||$next<=max(time(),(int)$r['expires_at']))throw new AdminException('已撤销证书不能续期，新期限须晚于现期限和当前时间');$change=['state'=>'VALID','expires_at'=>$next];}
            elseif($action==='revoke'){if($r['state']==='REVOKED')throw new AdminException('证书已撤销');$change=['state'=>'REVOKED'];}else throw new AdminException('证书操作无效');
            Db::name('growth_certificate_action')->insert(['certificate_id'=>$id,'action'=>$action,'reason'=>$reason,'evidence_json'=>GrowthCommandServices::json(['basis'=>(string)($in['basis']??'')]),'old_expires_at'=>$r['expires_at'],'new_expires_at'=>$change['expires_at']??$r['expires_at'],'actor_key'=>$c['actor_key'],'created_at'=>time()]);Db::name('growth_certificate')->where('id',$id)->update($change);return ['id'=>$id,'message'=>'证书处理已留痕'];});
    }

    /** Outbox消费者在事件事务中调用；员工锁和来源唯一键共同保护并发与重试。 */
    public function consume(array $event): void
    {
        $source=$this->source($event);if(!$source)return;$employee=$source['employee_id'];Db::name('employee')->where('id',$employee)->lock(true)->find();
        if(in_array($event['event_type'],['GRADE_CORRECTED','TASK_RESULT_CORRECTED'],true)){$this->correctRewards($event,$source);$this->refreshLevel($employee);return;}
        $task=Db::name('growth_task')->where('id',$source['task_id'])->find();$rules=GrowthCommandServices::decode((string)($task['rules_json']??''));
        foreach($rules['point_rules']??[] as $r){if($r['event_type']!==$event['event_type']||($r['require_passed']&&!$source['passed']))continue;$group=$source['group'];$key='award:'.$r['id'].':'.$source['type'].':'.$source['id'];if(Db::name('growth_point_ledger')->where('employee_id',$employee)->where('source_key',$key)->find())continue;$count=$this->activeAwards($employee,(int)$r['id'],$group);if($r['event_type']==='TASK_COMPLETED'&&$this->activeAwards($employee,(int)$r['id'],$group,0,(int)$source['task_id']))continue;if($count>=($r['first_only']?1:(int)$r['max_awards']))continue;
            Db::name('growth_point_ledger')->insert(['employee_id'=>$employee,'delta'=>$r['points'],'rule_id'=>$r['id'],'source_type'=>$source['type'],'source_id'=>$source['id'],'source_group'=>$group,'source_key'=>$key,'reason'=>$r['title'],'evidence_json'=>GrowthCommandServices::json(['event_id'=>(int)$event['id'],'rule_snapshot'=>$r,'task_id'=>$source['task_id']]),'actor_key'=>'growth:outbox','occurred_at'=>time()]);}
        foreach($rules['certificate_rules']??[] as $r){if($r['event_type']!==$event['event_type']||($r['require_passed']&&!$source['passed']))continue;if($r['event_type']==='TASK_COMPLETED'&&Db::name('growth_certificate')->where('employee_id',$employee)->where('rule_id',$r['id'])->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(evidence_json,'$.task_id'))=?",[(string)$source['task_id']])->find())continue;$key='certificate:'.$r['id'].':'.$source['type'].':'.$source['id'];if(Db::name('growth_certificate')->where('employee_id',$employee)->where('source_key',$key)->find())continue;$number='GROWTH-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(5)));Db::name('growth_certificate')->insert(['employee_id'=>$employee,'title'=>$r['title'],'certificate_number'=>$number,'origin'=>'internal','rule_id'=>$r['id'],'source_type'=>$source['type'],'source_id'=>$source['id'],'source_key'=>$key,'evidence_json'=>GrowthCommandServices::json(['event_id'=>(int)$event['id'],'rule_snapshot'=>$r,'task_id'=>$source['task_id']]),'issued_at'=>time(),'expires_at'=>$r['valid_days']?time()+$r['valid_days']*86400:0,'created_by'=>'growth:outbox']);}
        $this->refreshLevel($employee);
    }
    private function source(array $event): ?array
    {
        $payload=GrowthCommandServices::decode($event['payload_json']);$task=null;$type='';$id=0;
        if($event['event_type']==='TASK_RESULT_CORRECTED'){$task=Db::name('growth_task')->where('id',(int)($payload['task_id']??0))->find();if(!$task)return null;$type=$task['result_type'];$id=(int)$task['result_id'];}
        elseif($event['event_type']==='TASK_COMPLETED'){$task=Db::name('growth_task')->where('id',$event['source_id'])->find();if(!$task||$task['state']!=='COMPLETED')return null;$type=$task['result_type'];$id=(int)$task['result_id'];}
        elseif(in_array($event['event_type'],['EXAM_PUBLISHED','GRADE_CORRECTED'],true)){$id=$event['event_type']==='GRADE_CORRECTED'?(int)($payload['attempt_id']??0):(int)$event['source_id'];$a=Db::name('growth_attempt')->where('id',$id)->where('state','PUBLISHED')->find();if(!$a)return null;$task=Db::name('growth_task')->where('id',$a['task_id'])->find();$type='attempt';}
        elseif($event['event_type']==='EVALUATION_CONFIRMED'){$id=(int)$event['source_id'];$r=Db::name('growth_evaluation')->where('id',$id)->find();if(!$r)return null;$task=Db::name('growth_task')->where('id',$r['task_id'])->find();$type='evaluation';}
        if(!$task||!$id||$type==='reference')return null;$passed=in_array($task['conclusion'],['PASSED','QUALIFIED'],true);$group=$task['publication_id']?'publication:'.$task['publication_id']:$task['source_type'].':'.$task['source_id'];
        if($type==='attempt'){$a=Db::name('growth_attempt')->where('id',$id)->find();$adjust=Db::name('growth_grade_adjustment')->where('attempt_id',$id)->order('id','desc')->find();$passed=(bool)($adjust['passed']??$a['passed']);}
        elseif($type==='evaluation'){$r=Db::name('growth_evaluation')->where('id',$id)->find();$passed=($r['conclusion']??'')==='QUALIFIED';}
        return ['type'=>$type,'id'=>$id,'employee_id'=>(int)$task['employee_id'],'task_id'=>(int)$task['id'],'task_complete'=>$task['state']==='COMPLETED','passed'=>$passed,'group'=>$group];
    }
    /** 员工锁内统计仍有效奖励；冲正后的无效来源不占首次或次数名额。 */
    private function activeAwards(int $employee,int $rule,string $group,int $except=0,int $task=0): int
    {
        $q=Db::name('growth_point_ledger')->alias('ba')->where('ba.employee_id',$employee)->where('ba.rule_id',$rule)->where('ba.source_group',$group)->where('ba.reversal_of',0);if($except)$q->where('ba.id','<>',$except);if($task)$q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(ba.evidence_json,'$.task_id'))=?",[(string)$task]);$net=Db::name('growth_point_ledger')->whereRaw('reversal_of=ba.id')->fieldRaw('COALESCE(SUM(delta),0)')->buildSql();return (int)$q->whereRaw('ba.delta+'.$net.'>0')->count();
    }
    /** 完成类资格属于任务；考试公布类资格仍属于该份原答卷。 */
    private function rewardStillValid(array $rule,array $source,array $task): bool
    {
        if(($rule['event_type']??'')==='TASK_COMPLETED')return ($task['state']??'')==='COMPLETED'&&(empty($rule['require_passed'])||in_array($task['conclusion'],['PASSED','QUALIFIED'],true));return empty($rule['require_passed'])||$source['passed'];
    }
    /** 原来源及同任务的完成类奖励一起复核，避免补考改分后遗漏旧完成奖励。 */
    private function correctedSources($q,array $source): void
    {
        $q->where(function($w)use($source){$w->where(function($same)use($source){$same->where('source_type',$source['type']);if($source['type']==='appraisal_result')$same->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(evidence_json,'$.task_id'))=?",[(string)$source['task_id']]);else $same->where('source_id',$source['id']);})->whereOr(function($completion)use($source){$completion->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(evidence_json,'$.task_id'))=? AND JSON_UNQUOTE(JSON_EXTRACT(evidence_json,'$.rule_snapshot.event_type'))='TASK_COMPLETED'",[(string)$source['task_id']]);});});
    }
    /** 原奖励保留，按更正后的有效来源追加差额；同一更正事件重复消费不重复冲正。 */
    private function correctRewards(array $event,array $source): void
    {
        $task=Db::name('growth_task')->where('id',$source['task_id'])->find();
        $ledger=Db::name('growth_point_ledger')->where('employee_id',$source['employee_id'])->where('reversal_of',0)->where('rule_id','>',0);$this->correctedSources($ledger,$source);$rows=$ledger->select()->toArray();
        foreach($rows as $r){$proof=GrowthCommandServices::decode($r['evidence_json']);$rule=$proof['rule_snapshot']??[];$valid=$this->rewardStillValid($rule,$source,$task?:[]);$net=(int)$r['delta']+(int)Db::name('growth_point_ledger')->where('reversal_of',$r['id'])->sum('delta');$difference=($valid?(int)$r['delta']:0)-$net;if($difference>0){$limit=!empty($rule['first_only'])?1:(int)($rule['max_awards']??1);$used=$this->activeAwards($source['employee_id'],(int)$r['rule_id'],$source['group'],(int)$r['id']);$sameTask=($rule['event_type']??'')==='TASK_COMPLETED'?$this->activeAwards($source['employee_id'],(int)$r['rule_id'],$source['group'],(int)$r['id'],(int)$source['task_id']):0;if($used>=$limit||$sameTask)$difference=0;}if(!$difference)continue;$key='correct:'.$event['id'].':'.$r['id'];if(Db::name('growth_point_ledger')->where('employee_id',$source['employee_id'])->where('source_key',$key)->find())continue;Db::name('growth_point_ledger')->insert(['employee_id'=>$source['employee_id'],'delta'=>$difference,'rule_id'=>$r['rule_id'],'source_type'=>$source['type'],'source_id'=>$source['id'],'source_group'=>$source['group'],'source_key'=>$key,'reversal_of'=>$r['id'],'reason'=>'正式成绩更正联动','evidence_json'=>GrowthCommandServices::json(['event_id'=>(int)$event['id'],'original_ledger_id'=>(int)$r['id']]),'actor_key'=>'growth:outbox','occurred_at'=>time()]);}
        $certQuery=Db::name('growth_certificate')->where('employee_id',$source['employee_id']);$this->correctedSources($certQuery,$source);$certs=$certQuery->select()->toArray();
        foreach($certs as $r){
            $proof=GrowthCommandServices::decode($r['evidence_json']);$rule=$proof['rule_snapshot']??[];
            $valid=$this->rewardStillValid($rule,$source,$task?:[]);
            $last=Db::name('growth_certificate_action')->where('certificate_id',$r['id'])->order('id','desc')->find();
            // 只自动恢复因来源成绩被撤销的证书；人工撤销及已到期证书不被成绩更正覆盖。
            $action='';$state='';if(!$valid&&$r['state']==='VALID'){$action='source_revoked';$state='REVOKED';}
            elseif($valid&&$r['state']==='REVOKED'&&($last['action']??'')==='source_revoked'&&(!$r['expires_at']||$r['expires_at']>time())){$action='source_restored';$state='VALID';}
            if($action==='')continue;
            Db::name('growth_certificate')->where('id',$r['id'])->update(['state'=>$state]);
            Db::name('growth_certificate_action')->insert(['certificate_id'=>$r['id'],'action'=>$action,'reason'=>$valid?'正式成绩更正后恢复原有效期内资格':'正式成绩更正后不再满足原发放条件','evidence_json'=>'{}','old_expires_at'=>$r['expires_at'],'new_expires_at'=>$r['expires_at'],'source_event_id'=>$event['id'],'actor_key'=>'growth:outbox','created_at'=>time()]);
        }
        // 原获奖答卷失效而另一补考仍通过时，冲正旧来源后以当前采用结果恢复资格。
        // 被冲正的无效奖励不占有效奖励次数；仍有有效奖励时不会再次发放。
        if($source['type']==='attempt'&&$task&&$task['conclusion']==='PASSED'&&$task['result_type']==='attempt'){
            $published=$event;$published['event_type']='EXAM_PUBLISHED';$published['source_id']=$task['result_id'];$this->consume($published);
            if($task['state']==='COMPLETED'){$completed=$event;$completed['event_type']='TASK_COMPLETED';$completed['source_id']=$task['id'];$this->consume($completed);}
        }
        if($source['type']==='appraisal_result'&&$source['task_complete']){$completed=$event;$completed['event_type']='TASK_COMPLETED';$completed['source_id']=$source['task_id'];$this->consume($completed);}
    }
}
