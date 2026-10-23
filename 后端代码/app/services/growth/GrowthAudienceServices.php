<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 查阅范围与业务数据权限分开：知识可面向本店员工，个人数据权限不会因此扩大。 */
final class GrowthAudienceServices
{
    /** 可见范围在发布者授权内固定；全员开放仅允许超级管理员。 */
    public function normalize(array $c,array $in): array
    {
        $mode=(string)($in['mode']??'current');if(!in_array($mode,['current','all','selected','personal'],true))throw new AdminException('知识适用范围无效');if($mode==='all'&&!$c['super'])throw new AdminException('仅平台超级管理员可发布面向全员的知识');
        $out=['mode'=>$mode,'store_ids'=>[],'organization_ids'=>[],'position_ids'=>[],'employee_ids'=>[]];
        if($mode==='current'){$out['store_ids']=array_map('intval',Db::name('system_store_staff')->where('employee_id',$c['employee_id'])->where('status',1)->where('is_del',0)->column('store_id'));if(!$out['store_ids'])$out['organization_ids']=array_map('intval',Db::name('organization_employee')->where('employee_id',$c['employee_id'])->where('status',1)->where('is_del',0)->column('org_id'));if(!$out['store_ids']&&!$out['organization_ids'])$out['mode']='personal';}
        if($mode==='selected')foreach(['store_ids','organization_ids','position_ids','employee_ids'] as $field){$ids=array_values(array_unique(array_filter(array_map('intval',(array)($in[$field]??[])))));if(count($ids)>500)throw new AdminException('单次适用范围不超过500项');$out[$field]=$ids;}
        if(!$c['super']){if(array_diff($out['store_ids'],(array)$c['store_ids'])&&$mode!=='current')throw new AdminException('知识适用门店超出当前范围');if(array_diff($out['organization_ids'],$c['organization_ids'])&&$mode!=='current')throw new AdminException('知识适用组织超出当前范围');foreach($out['employee_ids'] as $id)app()->make(GrowthAccessServices::class)->assertEmployee($c,$id);}
        if($mode==='selected'&&!$out['store_ids']&&!$out['organization_ids']&&!$out['employee_ids'])throw new AdminException('请指定至少一个适用组织、门店或员工');foreach($out['position_ids'] as $id)if(!Db::name('position')->where('id',$id)->where('status',1)->find())throw new AdminException('适用岗位不存在');return $out;
    }
    /** 知识查阅从当前合法任职匹配冻结受众，不扩大员工业务数据权限。 */
    public function apply($query,array $c): void
    {
        if($c['super'])return;$stores=array_map('intval',Db::name('system_store_staff')->where('employee_id',$c['employee_id'])->where('status',1)->where('is_del',0)->column('store_id'));$orgs=array_map('intval',Db::name('organization_employee')->where('employee_id',$c['employee_id'])->where('status',1)->where('is_del',0)->column('org_id'));if($stores)$orgs=array_merge($orgs,array_map('intval',Db::name('organization_store')->whereIn('store_id',$stores)->column('org_id')));
        // 上级组织知识可由其下级员工阅读，归属仍来自权威组织关系，不使用客户端范围。
        $nodes=Db::name('organization')->where('is_del',0)->column('pid','id');do{$n=count($orgs);foreach($orgs as $id)if(isset($nodes[$id])&&(int)$nodes[$id]>0)$orgs[]=(int)$nodes[$id];$orgs=array_values(array_unique($orgs));}while(count($orgs)>$n);
        $positions=array_map('intval',Db::name('staff_job_position')->where('employee_id',$c['employee_id'])->where('status',1)->where('is_del',0)->where('end_time',0)->column('position_id'));
        $query->where(function($q)use($c,$stores,$orgs){$q->where('c.owner_employee_id',$c['employee_id'])->whereOrRaw("JSON_UNQUOTE(JSON_EXTRACT(p.snapshot_json,'$.audience.mode'))='all'");foreach(['store_ids'=>$stores,'organization_ids'=>$orgs,'employee_ids'=>[$c['employee_id']]] as $key=>$ids)foreach($ids as $id)$q->whereOrRaw("JSON_CONTAINS(JSON_EXTRACT(p.snapshot_json,'$.audience.{$key}'),?)",[(string)$id]);});
        $query->where(function($q)use($positions){$q->whereRaw("COALESCE(JSON_LENGTH(JSON_EXTRACT(p.snapshot_json,'$.audience.position_ids')),0)=0");foreach($positions as $id)$q->whereOrRaw("JSON_CONTAINS(JSON_EXTRACT(p.snapshot_json,'$.audience.position_ids'),?)",[(string)$id]);});
    }
}
