<?php
namespace app\services\ai\execution;

use app\services\ai\contract\AiContractException;

/**
 * Converts a model-bound, human-unit condition set into the registered
 * canonical units consumed by the metric Reader.  It does not interpret
 * customer text or choose a metric.
 */
final class AiConditionSetCompiler
{
    private const MAX_VALUE=100000000000;

    public function compile(array $binding,array $capabilities,string $operation): array
    {
        if (!in_array($operation,['condition_count','condition_list'],true)) $this->fail();
        $keys=array_keys($binding);sort($keys,SORT_STRING);
        if ($keys!==['conditions','relation','result_form','subject']
            ||!in_array($binding['relation']??null,['all','any'],true)
            ||($binding['result_form']??null)!==($operation==='condition_count'?'count':'list')
            ||!is_string($binding['subject']??null)||!is_array($binding['conditions']??null)
            ||count($binding['conditions'])<1||count($binding['conditions'])>4
            ||array_keys($binding['conditions'])!==range(0,count($binding['conditions'])-1)) $this->fail();
        $conditions=[];$codes=[];
        foreach ($binding['conditions'] as $condition) {
            $conditionKeys=is_array($condition)?array_keys($condition):[];sort($conditionKeys,SORT_STRING);
            if ($conditionKeys!==['metric_code','operator','quantity','unit']
                ||!is_string($condition['metric_code']??null)||$condition['metric_code']===''
                ||!in_array($condition['operator']??null,['gte','gt','lte','lt','eq'],true)
                ||!is_string($condition['quantity']??null)||!in_array($condition['unit']??null,['yuan','count','day'],true)) $this->fail();
            $code=$condition['metric_code'];
            if (isset($codes[$code])) $this->fail();
            $contract=$capabilities['metric_readiness'][$code]??null;
            if (!is_array($contract)||empty($contract['ai_query_ready'])
                ||!in_array($operation,(array)($contract['query_shapes']??[]),true)
                ||!in_array($binding['subject'],(array)($contract['condition_subjects']??[]),true)) $this->fail('AI_QUERY_SHAPE_NOT_READY');
            $storage=$contract['storage_unit']??null;
            $expectedUnit=$contract['condition_unit']??($storage==='fen'?'yuan':'count');
            if (!is_string($expectedUnit)||$condition['unit']!==$expectedUnit) $this->fail();
            if ($storage==='fen') {
                $value=$this->scaled($condition['quantity'],2);
            } elseif ($storage==='count') {
                $value=$this->scaled($condition['quantity'],0);
            } elseif ($storage==='project_count_micro') {
                $value=$this->scaled($condition['quantity'],6);
            } elseif ($storage==='customer_tenth') {
                $value=$this->scaled($condition['quantity'],1);
            } else $this->fail('AI_METRIC_NOT_READY');
            if ($value<0||$value>self::MAX_VALUE) $this->fail();
            $codes[$code]=true;
            $conditions[]=['metric_code'=>$code,'operator'=>$condition['operator'],'value'=>$value];
        }
        return ['subject'=>$binding['subject'],'relation'=>$binding['relation'],'conditions'=>$conditions];
    }

    /**
     * Current-state predicates are evaluated at one signed snapshot date. If
     * every condition declares that time model, the server may use today
     * without asking for a meaningless historical range. Period totals keep
     * the normal date clarification path.
     */
    public function usesCurrentSnapshot(array $conditionSet,array $capabilities): bool
    {
        if (!is_array($conditionSet['conditions']??null)||$conditionSet['conditions']===[]) return false;
        foreach ($conditionSet['conditions'] as $condition) {
            $code=is_array($condition)?($condition['metric_code']??null):null;
            $contract=is_string($code)?($capabilities['metric_readiness'][$code]??null):null;
            $aggregation=is_array($contract)?($contract['threshold_count']['aggregation']??null):null;
            if (!in_array($aggregation,['current_state','as_of_age_days'],true)) return false;
        }
        return true;
    }

    private function scaled(string $quantity,int $precision): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,6}))?$/D',$quantity,$match)) $this->fail();
        $whole=$match[1];$fraction=$match[2]??'';
        if (strlen($fraction)>$precision && trim(substr($fraction,$precision),'0')!=='') $this->fail('AI_CONDITION_PRECISION_INVALID');
        $fraction=substr(str_pad($fraction,$precision,'0'),0,$precision);
        $scale=10**$precision;
        if ((int)$whole>intdiv(self::MAX_VALUE,$scale)) $this->fail();
        return ((int)$whole*$scale)+($fraction===''?0:(int)$fraction);
    }

    private function fail(string $code='AI_UNSUPPORTED_CONDITION'): void
    {
        throw new AiContractException($code);
    }
}
