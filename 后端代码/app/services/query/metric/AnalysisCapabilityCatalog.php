<?php
namespace app\services\query\metric;

/** Source-owned semantic catalog. Neither metadata presence nor discovery grants access.
 * No SQL, DAO, formulas or customer objects are accepted or returned here.
 * Bindings are supplied by verified query providers, never by the model/request.
 */
final class AnalysisCapabilityCatalog
{
    private $metrics = [];
    private $bindings = [];

    public function __construct(array $definitions, array $bindings)
    {
        foreach ($definitions as $definition) {
            $code = $definition['code'] ?? null;
            $this->identifier($code);
            if (isset($this->metrics[$code])) $this->fail();
            $ready = ($definition['user_ready'] ?? false) === true;
            $item = ['metric_code'=>$code, 'user_ready'=>$ready];
            foreach (['name','summary','include','exclude','timing','note'] as $key) {
                $value = $definition[$key] ?? '';
                if (!is_string($value)) $this->fail();
                $item[$key] = $ready ? $value : '';
            }
            if (!$ready) $item['summary'] = '口径说明待确认';
            // Technical aliases (legacy field names) are deliberately not externalized.
            $item['definition_ref'] = hash('sha256', json_encode($item, JSON_UNESCAPED_UNICODE));
            $this->metrics[$code] = $item;
        }
        foreach ($bindings as $binding) {
            $keys = ['capability_code','metric_code','object_kind','operations','filter_keys','relation_role','contract_version'];
            if (!is_array($binding) || array_diff(array_keys($binding),$keys) || array_diff($keys,array_keys($binding))) $this->fail();
            foreach (['capability_code','metric_code','object_kind','relation_role','contract_version'] as $key) $this->identifier($binding[$key]);
            if (!isset($this->metrics[$binding['metric_code']]) || isset($this->bindings[$binding['capability_code']])) $this->fail();
            foreach (['operations','filter_keys'] as $key) $this->identifiers($binding[$key]);
            if (!$binding['operations']) $this->fail();
            $this->bindings[$binding['capability_code']] = $binding;
        }
        ksort($this->metrics); ksort($this->bindings);
    }

    /** All definitions are visible only to the existing platform maintainer catalog.
     * This is NOT the model view or an ordinary user's permission-filtered directory.
     */
    public function inventory(): array
    {
        $items = [];
        foreach ($this->metrics as $code=>$metric) {
            $bindings = array_values(array_filter($this->bindings, static function(array $b) use($code): bool { return $b['metric_code']===$code; }));
            $items[] = $metric + ['status'=>!$metric['user_ready']?'definition_pending':($bindings?'provider_registered':'definition_only'), 'bindings'=>$bindings];
        }
        return ['schema_version'=>'analysis-capability-catalog-v1','catalog_ref'=>$this->fingerprint(),'items'=>$items,
            'authorization_required'=>true,'instance_readiness_verified'=>false];
    }

    /** Bound discovery, independent of scenes/report page names. Authorization callback
     * must be injected from a current server-side authority and reject unknown bindings.
     */
    public function discover(array $request, callable $authorize): array
    {
        $keys=['metric_codes','object_kind','operation','filter_keys','relation_role'];
        if (array_diff(array_keys($request),$keys) || array_diff($keys,array_keys($request))) $this->fail();
        $this->identifiers($request['metric_codes']); $this->identifiers($request['filter_keys']);
        foreach (['object_kind','operation','relation_role'] as $key) $this->identifier($request[$key]);
        $items=[];
        foreach ($this->bindings as $binding) {
            $metric=$this->metrics[$binding['metric_code']];
            if (!$metric['user_ready'] || ($request['metric_codes'] && !in_array($binding['metric_code'],$request['metric_codes'],true))) continue;
            if ($binding['object_kind']!==$request['object_kind'] || $binding['relation_role']!==$request['relation_role']
                || !in_array($request['operation'],$binding['operations'],true) || array_diff($request['filter_keys'],$binding['filter_keys'])) continue;
            if ($authorize($binding)!==true) continue;
            $items[]=['metric'=>$metric,'binding'=>$binding];
        }
        // A multi-metric request is indivisible: never silently remove an unsupported metric.
        if ($request['metric_codes'] && array_diff($request['metric_codes'],array_column(array_column($items,'binding'),'metric_code'))) $items=[];
        return ['catalog_ref'=>$this->fingerprint(),'items'=>$items,'complete_request_supported'=>(bool)$items];
    }

    public function fingerprint(): string
    { return hash('sha256',json_encode([$this->metrics,$this->bindings],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }

    private function identifiers($values): void
    {
        if (!is_array($values) || count($values)>64 || ($values && array_keys($values)!==range(0,count($values)-1))) $this->fail();
        foreach ($values as $value) $this->identifier($value);
        if (count(array_unique($values))!==count($values)) $this->fail();
    }
    private function identifier($value): void
    { if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D',$value)) $this->fail(); }
    private function fail(): void
    { throw new \RuntimeException('ANALYSIS_CAPABILITY_CONTRACT_INVALID'); }
}
