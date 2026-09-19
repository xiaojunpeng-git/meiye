<?php
namespace app\services\ai\execution;

use app\services\organization\EmployeeDataScopeServices;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;

/** Current platform report authority shared by live AI and server-bound export tasks. */
final class AiPlatformPrincipalResolver
{
    public function authenticated(int $accountId): array
    {
        $admin=$accountId>0?Db::name('system_admin')->where('id',$accountId)->where('status',1)->where('is_del',0)->find():null;
        if (!$admin) throw new \RuntimeException('AI_AUTH_REQUIRED');
        $employeeId=(int)($admin['employee_id']??0);
        if ($employeeId>0 && !Db::name('employee')->where('id',$employeeId)->where('status',1)->where('is_del',0)->find()) throw new \RuntimeException('AI_AUTH_REQUIRED');
        $type=(int)($admin['admin_type']??0);
        // Configuration ownership is independent of data access and display
        // names. Query admission below is exclusively scope-derived.
        $configure=$type!==3 && ($admin['account']??null)==='admin';
        $mode='none';$stores=[];
        {
            if ($type===3 && (int)($admin['relation_id']??0)>0) {
                $mode='agent_limited';$stores=app()->make(OrganizationScopeService::class)->getResolvedStoreIdsByLegacyAgentId((int)$admin['relation_id']);
            } elseif ($type===3) {
                // Broken agent identity must not fall through to legacy platform-wide scope.
                $mode='none';
            } elseif ($employeeId>0) {
                $scopes=app()->make(EmployeeDataScopeServices::class);
                $resolved=$scopes->resolveEffectiveStoreIds($employeeId,0,$admin);
                if ($resolved===null) { $mode='all';$stores=$this->allStores(); }
                elseif ($resolved===[]) { $mode='self_participant';$stores=$scopes->resolveCurrentActiveStoreIds($employeeId); }
                else { $mode='stores';$stores=$resolved; }
            } else { $mode='platform_admin';$stores=$this->allStores(); }
        }
        $stores=array_values(array_unique(array_filter(array_map('intval',(array)$stores),static fn($id)=>$id>0)));sort($stores);
        $context=['terminal'=>'platform','account_id'=>$accountId,'scope_mode'=>$mode==='self_participant'?$mode:($stores?'stores':'none'),
            'store_ids'=>$stores,'permission_version'=>hash('sha256',json_encode([$mode,$employeeId,$stores])),
            'employee_id'=>$employeeId,'personnel_data_authorized'=>!empty($stores),'member_data_authorized'=>!empty($stores),'store_report_authorized'=>true,
            'can_configure'=>$configure,'report_capability_code'=>'group_management_dashboard',
            'tenant_id'=>'0','origin_store_id'=>0,'origin_organization_id'=>'0','export_principal_ready'=>true,'principal_kind'=>'platform_admin'];
        $context['can_use']=AiAuthority::canUseDataScope($context);
        return $context;
    }

    /** Only a previously verified server-owned task may select this account. */
    public function worker(array $binding): array
    {
        if (($binding['terminal']??'')!=='platform' || ($binding['principal_kind']??'')!=='platform_admin' || !is_int($binding['account_id']??null)) throw new \RuntimeException('AI_EXPORT_PRINCIPAL_UNAVAILABLE');
        $context=$this->authenticated($binding['account_id']);
        if (!$context['can_use']) throw new \RuntimeException('AI_PERMISSION_REVOKED');
        return $context;
    }
    private function allStores(): array { return Db::name('system_store')->where('is_del',0)->where('name','<>','总部')->column('id')?:[]; }
}
