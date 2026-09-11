<?php
namespace app\services\ai\execution;

use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\organization\EmployeeDataScopeServices;

/** Business authority only: never stores, generates or impersonates a merchant session. */
final class AiMerchantPrincipalResolver
{
    public function authenticated(array $merchant): array
    {
        $current=app()->make(MobileMerchantRequestContextResolver::class)->trustedBusinessPrincipal(
            (int)$merchant['employeeId'],(int)$merchant['staffId'],(int)$merchant['storeId'],(string)$merchant['organizationId']);
        if ((int)$current['accountId']!==(int)$merchant['accountId']) throw new \RuntimeException('AI_AUTH_REQUIRED');
        $scopes=app()->make(EmployeeDataScopeServices::class);
        $stores=$scopes->resolveEffectiveStoreIds((int)$current['employeeId'],0);
        $personal=$stores===[];
        if ($personal) $stores=$scopes->resolveCurrentActiveStoreIds((int)$current['employeeId']);
        if ($stores===null) $stores=\think\facade\Db::name('system_store')->where('is_del',0)->where('name','<>','总部')->column('id');
        $stores=array_values(array_unique(array_filter(array_map('intval',$stores),static fn($id)=>$id>0)));sort($stores);
        $report=in_array('MERCHANT_WAREHOUSE_VIEW',(array)$current['availableActions'],true);
        $context=['terminal'=>'merchant','account_id'=>(int)$current['accountId'],'scope_mode'=>$personal?'self_participant':($stores?'stores':'none'),
            'store_ids'=>$stores,'can_use'=>in_array('MOHE_AI_USE',(array)$current['availableActions'],true),
            'store_report_authorized'=>$report,'analysis_personnel_grants'=>['staff_labor_yeji'=>$report,'staff_sales_yeji'=>$report],
            'permission_version'=>hash('sha256',json_encode([$current['permissionVersion']??null,$stores,$personal,$current['availableActions'],(int)$current['employeeId']])),
            'report_capability_code'=>'group_management_dashboard'];
        $context['can_configure']=false; // Configuration is platform admin-only, never inherited from merchant scope.
        return $context+['tenant_id'=>'0','origin_store_id'=>(int)$current['storeId'],'origin_organization_id'=>(string)$current['organizationId'],
            'employee_id'=>(int)$current['employeeId'],'staff_id'=>(int)$current['staffId'],'export_principal_ready'=>true,'principal_kind'=>'merchant_employee'];
    }
    /** Binding must be verified by the export caller before entering this method. */
    public function worker(array $binding): array
    {
        if (($binding['terminal']??'')!=='merchant' || ($binding['principal_kind']??'')!=='merchant_employee') throw new \RuntimeException('AI_EXPORT_PRINCIPAL_UNAVAILABLE');
        foreach (['account_id','employee_id','staff_id','origin_store_id'] as $key) if (!is_int($binding[$key]??null)) throw new \RuntimeException('AI_EXPORT_PRINCIPAL_UNAVAILABLE');
        if (!is_string($binding['origin_organization_id']??null)) throw new \RuntimeException('AI_EXPORT_PRINCIPAL_UNAVAILABLE');
        $context=$this->authenticated(['accountId'=>$binding['account_id'],'employeeId'=>$binding['employee_id'],'staffId'=>$binding['staff_id'],
            'storeId'=>$binding['origin_store_id'],'organizationId'=>$binding['origin_organization_id']]);
        if (!$context['can_use']) throw new \RuntimeException('AI_PERMISSION_REVOKED');
        return $context;
    }
}
