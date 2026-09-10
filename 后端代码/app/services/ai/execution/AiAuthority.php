<?php
namespace app\services\ai\execution;

/** Exact shared identity/report binding used by HTTP and background continuation. */
final class AiAuthority
{
    public static function capabilities(bool $exportReady,array $context=[]): array
    {
        $registered=\app\services\query\metric\MetricReadViewServices::metricCapabilities(); $metrics=[];
        foreach ($registered as $code=>$contract) if (($contract['filter_grain']??null)==='person'
            && (!(($context['analysis_personnel_ready']??false)===true) || ($context['analysis_personnel_grants'][$code]??false)!==true)) unset($registered[$code]);
        foreach ($registered as $code=>$capability) if (!empty($capability['ai_query_ready'])) $metrics[]=$code;
        $metadata=[]; $dictionary=new \app\services\metric\MetricDictionaryServices();
        foreach ($registered as $code=>$contract) {
            $tooltip=$dictionary->getTooltip($code); unset($tooltip['updated_at']);
            if (($tooltip['user_ready']??false)===true && isset($registered[$code]['metric_version'])) {
                $metadata[$code]=['user_ready'=>true,'metric_version'=>$registered[$code]['metric_version'],
                    'description_ref'=>'metric-description-'.hash('sha256',json_encode($tooltip,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
            }
        }
        return ['metric_codes'=>$metrics,'query_shapes'=>['summary','trend','ranking','comparison'],
            'output_formats'=>$exportReady?['screen','screen_and_xlsx']:['screen'],'metric_readiness'=>$registered,
            'definition_metric_codes'=>array_keys($metadata),'metadata_readiness'=>$metadata];
    }
    public static function capabilityHash(bool $exportReady,bool $legacy=false,array $context=[]): string
    {
        $capabilities=self::capabilities($exportReady,$context);
        if ($legacy) unset($capabilities['definition_metric_codes'],$capabilities['metadata_readiness']);
        return hash('sha256',json_encode($capabilities));
    }
    public static function identity(array $context,string $instance,string $key): string
    { return hash_hmac('sha256',$instance.':'.$context['terminal'].':'.$context['account_id'],$key); }
    public static function permissionHash(array $context): string
    { return hash('sha256',json_encode([$context['permission_version'],$context['scope_mode'],$context['store_ids'],$context['can_use'],$context['report_capability_code']])); }
    public static function reportBinding(array $context,string $instance,string $key): array
    {
        if (empty($context['can_use'])) throw new \RuntimeException('AI_PERMISSION_DENIED');
        return ['instance_id'=>$instance,'subject_ref'=>self::identity($context,$instance,$key),'terminal'=>$context['terminal'],
            'tenant_id'=>(string)($context['tenant_id']??0),'permission_version'=>self::permissionHash($context),
            'report_capability_code'=>$context['report_capability_code'],'scope_provider_code'=>'current_report_scope_v1',
            'scope_mode'=>$context['scope_mode'],'store_ids'=>$context['store_ids']];
    }
}
