<?php
namespace app\services\ai\execution;

use app\services\organization\EmployeeDataScopeServices;
use app\services\organization\OrganizationScopeService;
use app\services\system\SystemRoleServices;
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
        $roles=$admin['roles']??[];
        $roles=is_string($roles)?array_filter(explode(',',$roles)):(array)$roles;
        $allowed=function(string $code,int $kind=1)use($admin,$type,$roles):bool {
            if (!(int)($admin['level']??0) && $type!==3) return true;
            if (!$roles) return false;
            foreach (app()->make(SystemRoleServices::class)->getRolesByAuth($roles,$kind) as $menu) if (($menu['unique_auth']??'')===$code) return true;
            return false;
        };
        // Configuration ownership is independent of role grants and display names.
        // This row is reloaded from the authenticated account on every request.
        $entry=$allowed('mohe-ai-entry',2);$configure=$type!==3 && ($admin['account']??null)==='admin';
        $report=$allowed('admin-report-group-management-dashboard');$mode='none';$stores=[];
        if ($report) {
            if ($type===3 && (int)($admin['relation_id']??0)>0) {
                $mode='agent_limited';$stores=app()->make(OrganizationScopeService::class)->getResolvedStoreIdsByLegacyAgentId((int)$admin['relation_id']);
            } elseif ($type===3) {
                // Broken agent identity must not fall through to legacy platform-wide scope.
                $mode='none';
            } elseif ($employeeId>0) {
                $scopes=app()->make(EmployeeDataScopeServices::class);
                $resolved=$scopes->resolveEffectiveStoreIds($employeeId,0,$admin);
                if ($resolved===null) { $mode='all';$stores=$this->allStores(); }
                elseif ($scopes->resolvePrimaryHqScopeMode($employeeId)===EmployeeDataScopeServices::MODE_PERSONAL) $mode='self_participant';
                else { $mode='stores';$stores=$resolved; }
            } else { $mode='platform_admin';$stores=$this->allStores(); }
        }
        $stores=array_values(array_unique(array_filter(array_map('intval',(array)$stores),static fn($id)=>$id>0)));sort($stores);
        return ['terminal'=>'platform','account_id'=>$accountId,'scope_mode'=>$mode==='self_participant'?$mode:($stores?'stores':'none'),
            'store_ids'=>$stores,'permission_version'=>hash('sha256',json_encode([$mode,$employeeId,$stores,$roles,$entry,$configure,$report])),
            'can_use'=>$entry && $report && $stores!==[],'can_configure'=>$configure,'report_capability_code'=>'group_management_dashboard',
            'tenant_id'=>'0','origin_store_id'=>0,'origin_organization_id'=>'0','export_principal_ready'=>true,'principal_kind'=>'platform_admin'];
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
