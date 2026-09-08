<?php
namespace app\services\ai\execution;

use app\services\query\UnifiedQueryContextFactory;
use app\services\query\UnifiedQueryWorkerContextResolver;

/** Dedicated AI runtime resolver. The binding validator is mandatory, never a frozen permission snapshot. */
final class AiExportWorkerContextResolver implements UnifiedQueryWorkerContextResolver
{
    private $factory; private $validatedBinding; private $principalResolver;
    public function __construct(UnifiedQueryContextFactory $factory,callable $validatedBinding,?callable $principalResolver=null)
    { $this->factory=$factory; $this->validatedBinding=$validatedBinding; $this->principalResolver=$principalResolver; }
    public function pageCode(): string { return 'metric_read_view_export'; }
    public function resolve(array $authoritativeTask): array
    {
        $binding=call_user_func($this->validatedBinding,$authoritativeTask);
        if (!is_array($binding) || !is_string($binding['read_consistency_ref']??null)) { throw new \RuntimeException('AI_EXPORT_BINDING_INVALID'); }
        $principal=$this->principalResolver ? call_user_func($this->principalResolver,$binding) : (new AiTrustedPrincipalResolver())->worker($binding);
        if ((int)($authoritativeTask['account_id']??0)!==$principal['account_id']
            || (int)($authoritativeTask['operator_id']??0)!==$principal['account_id']
            || ($authoritativeTask['tenant_id']??null)!==$principal['tenant_id']) { throw new \RuntimeException('AI_EXPORT_OWNER_MISMATCH'); }
        $org=$principal['origin_organization_id'];
        return $this->factory->make([
            'tenant_id'=>$principal['tenant_id'],'account_id'=>$principal['account_id'],'operator_id'=>$principal['account_id'],
            'store_id'=>$principal['origin_store_id'],'organization_id'=>$org,
            'visible_store_ids'=>$principal['store_ids'],'ancestor_organization_ids'=>$org!==''?[$org]:[],
            'permission_version'=>$principal['permission_version'],
            // This feature is derived only after current AI AND report permission checks above.
            'granted_features'=>['mohe.ai.export'],'manage_shared_fields'=>false,'share_tenant_fields'=>false,
            'scope_dimensions'=>['metric_read_ref'=>[$binding['read_consistency_ref']]],
            'query_cutoff_date'=>(string)($authoritativeTask['query_cutoff_date']??''),
            'data_as_of'=>(int)($authoritativeTask['data_as_of']??0),
        ],['page_code'=>$this->pageCode()]);
    }
}
