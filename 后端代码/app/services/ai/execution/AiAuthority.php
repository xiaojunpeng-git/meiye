<?php
namespace app\services\ai\execution;

/** Exact shared identity/report binding used by HTTP and background continuation. */
final class AiAuthority
{
    /**
     * AI query admission is derived solely from the freshly authenticated
     * person's effective data range. A terminal, page menu or feature switch
     * may decide whether an interface is rendered, but never what the person
     * may ask AI to read.
     */
    public static function canUseDataScope(array $context): bool
    {
        return in_array($context['scope_mode']??null,['all','stores','self_participant'],true)
            && is_array($context['store_ids']??null) && $context['store_ids']!==[];
    }

    public static function capabilities(bool $exportReady,array $context=[]): array
    {
        $registered=\app\services\query\metric\MetricReadViewServices::metricCapabilities(); $metrics=[];
        // AI entry authorization and personnel data authorization are separate
        // concerns.  A report menu may decide whether its page is visible, but
        // it must never silently narrow the data a person can ask AI to read.
        // The Reader receives the freshly resolved staff/store scope below and
        // remains the sole enforcement point for every query.
        foreach ($registered as $code=>$contract) if (($contract['filter_grain']??null)==='person'
            && !(($context['analysis_personnel_ready']??false)===true)) unset($registered[$code]);
        // A self-participant grant is only executable through the person
        // grain.  Keeping store totals in the capability projection would
        // offer a choice that the Reader must later reject because it would
        // expose the whole store rather than the authenticated employee.
        if (($context['scope_mode']??null)==='self_participant') {
            foreach ($registered as $code=>$contract) {
                if (($contract['filter_grain']??null)!=='person') unset($registered[$code]);
            }
        }
        foreach ($registered as $code=>$capability) if (!empty($capability['ai_query_ready'])) $metrics[]=$code;
        $metadata=[]; $dictionary=new \app\services\metric\MetricDictionaryServices();
        foreach ($registered as $code=>$contract) {
            $tooltip=$dictionary->getTooltip($code); unset($tooltip['updated_at']);
            if (($tooltip['user_ready']??false)===true && isset($registered[$code]['metric_version'])) {
                $metadata[$code]=['user_ready'=>true,'metric_version'=>$registered[$code]['metric_version'],
                    'description_ref'=>'metric-description-'.hash('sha256',json_encode($tooltip,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
            }
        }
        // The compiler receives the same current report scope that the Reader
        // will revalidate. It may use it only to reject expansion or accept a
        // narrower, catalog-bound store selection.
        $stores=array_values($context['store_ids']??[]);
        return ['metric_codes'=>$metrics,'query_shapes'=>['summary','breakdown','trend','ranking','comparison','threshold_count','condition_count','condition_list'],
            'output_formats'=>$exportReady?['screen','screen_and_xlsx']:['screen'],'metric_readiness'=>$registered,
            'definition_metric_codes'=>array_keys($metadata),'metadata_readiness'=>$metadata,'store_ids'=>$stores];
    }
    public static function capabilityHash(bool $exportReady,bool $legacy=false,array $context=[]): string
    {
        $capabilities=self::capabilities($exportReady,$context);
        if ($legacy) unset($capabilities['definition_metric_codes'],$capabilities['metadata_readiness']);
        return hash('sha256',json_encode($capabilities));
    }
    public static function identity(array $context,string $instance,string $key): string
    { return hash_hmac('sha256',$instance.':'.$context['terminal'].':'.$context['account_id'],$key); }
    public static function currentStoreId(array $context): ?int
    {
        $id=$context['origin_store_id']??null;
        return is_int($id) && $id>0 && in_array($id,$context['store_ids']??[],true)?$id:null;
    }
    public static function permissionHash(array $context): string
    { return hash('sha256',json_encode([$context['permission_version'],$context['scope_mode'],$context['store_ids'],$context['employee_id']??0])); }
    public static function reportBinding(array $context,string $instance,string $key): array
    {
        if (empty($context['can_use'])) throw new \RuntimeException('AI_PERMISSION_DENIED');
        return ['instance_id'=>$instance,'subject_ref'=>self::identity($context,$instance,$key),'terminal'=>$context['terminal'],
            'tenant_id'=>(string)($context['tenant_id']??0),'permission_version'=>self::permissionHash($context),
            'report_capability_code'=>$context['report_capability_code'],'scope_provider_code'=>'current_report_scope_v1',
            'scope_mode'=>$context['scope_mode'],'store_ids'=>$context['store_ids'],
            'store_report_authorized'=>($context['store_report_authorized']??true)===true,'employee_id'=>(int)($context['employee_id']??0)];
    }
}
