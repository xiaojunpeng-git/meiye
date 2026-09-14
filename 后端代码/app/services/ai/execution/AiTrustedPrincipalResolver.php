<?php
namespace app\services\ai\execution;

use app\model\store\SystemStoreStaff;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\CashierV3DataScopeContext;
use think\facade\Db;

/** Same current permission source for authenticated HTTP and trusted export jobs; no stored tokens. */
final class AiTrustedPrincipalResolver
{
    public function storeAuthenticated(int $storeId,int $accountId,array $authenticatedProfile): array
    {
        if ($storeId<=0 || $accountId<=0) { throw new \RuntimeException('AI_AUTH_REQUIRED'); }
        $delegated=!empty($authenticatedProfile['_cashier_v3_delegated']);
        if ($delegated) {
            // Delegated sessions are only available through live authentication, not background re-creation.
            $profile=$authenticatedProfile;
        } else {
            $staff=SystemStoreStaff::where('id',$accountId)->where('status',1)->where('is_del',0)->find();
            if (!$staff) { throw new \RuntimeException('AI_AUTH_REQUIRED'); }
            $profile=$staff->toArray();
        }
        $employeeId=(int)($profile['employee_id']??0);
        if ($employeeId>0 && !Db::name('employee')->where('id',$employeeId)->where('status',1)->where('is_del',0)->find()) {
            throw new \RuntimeException('AI_AUTH_REQUIRED');
        }
        $dispatcher=CashierV3Bootstrap::dispatcher();
        $operator=$dispatcher->scopeResolver()->operatorScope($storeId,$accountId);
        $scope=$dispatcher->dataScopeFactory()->build($storeId,$accountId,$profile,$operator->tenantId(),$operator->organizationId());
        $personal=$scope->isSelfParticipantMode();
        $allowed=$scope->visibleStoreIds();
        // The cashier page binds a current store. AI uses the employee's data
        // authority instead; the trusted origin is only an optional filter.
        if ($employeeId>0 && $scope->authorizationMode()!==CashierV3DataScopeContext::MODE_NONE) {
            $dataScopes=app()->make(\app\services\organization\EmployeeDataScopeServices::class);
            $scopeProfile=$profile;
            if (!array_key_exists('admin_type',$scopeProfile)) $scopeProfile['admin_type']=3;
            $allowed=$dataScopes->resolveEffectiveStoreIds($employeeId,0,$scopeProfile);
            $personal=$allowed===[];
            if ($personal) $allowed=$dataScopes->resolveCurrentActiveStoreIds($employeeId);
        }
        $stores=$scope->authorizationMode()===CashierV3DataScopeContext::MODE_NONE?[]:($allowed===null
            ?Db::name('system_store')->where('is_del',0)->where('name','<>','总部')->column('id'):(array)$allowed);
        $stores=array_values(array_unique(array_filter(array_map('intval',$stores),static fn($id)=>$id>0)));sort($stores);
        $context=['terminal'=>'store','account_id'=>$accountId,
            'scope_mode'=>$personal?'self_participant':($stores?'stores':'none'),'store_ids'=>$stores,
            'permission_version'=>hash('sha256',json_encode([$scope->permissionVersion(),$stores,$employeeId])),
            'can_configure'=>false,
            // The cashier management-center menu controls that page, not the
            // person's AI data scope or AI query admission.
            'employee_id'=>$employeeId,'personnel_data_authorized'=>!empty($stores),'store_report_authorized'=>true,
            'report_capability_code'=>'group_management_dashboard',
            'export_principal_ready'=>!$delegated,'principal_kind'=>$delegated?'delegated_session':'store_staff','origin_store_id'=>$storeId,
            'origin_organization_id'=>$operator->organizationId(),'tenant_id'=>$operator->tenantId()];
        $context['can_use']=AiAuthority::canUseDataScope($context);
        return $context;
    }

    /** Caller must first validate server-owned task/binding instance + Run fence; never expose as an HTTP identity selector. */
    public function worker(array $binding): array
    {
        if (($binding['terminal']??null)==='platform') { return (new AiPlatformPrincipalResolver())->worker($binding); }
        if (($binding['terminal']??null)==='merchant') { return (new AiMerchantPrincipalResolver())->worker($binding); }
        if (($binding['terminal']??null)!=='store' || ($binding['principal_kind']??null)!=='store_staff'
            || !is_int($binding['account_id']??null) || !is_int($binding['origin_store_id']??null)) {
            throw new \RuntimeException('AI_EXPORT_PRINCIPAL_UNAVAILABLE');
        }
        $context=$this->storeAuthenticated($binding['origin_store_id'],$binding['account_id'],[]);
        if (!$context['can_use']) { throw new \RuntimeException('AI_PERMISSION_REVOKED'); }
        return $context;
    }
}
