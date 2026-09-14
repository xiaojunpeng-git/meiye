<?php
namespace app\services\ai\execution;

use app\services\ai\registry\AiBusinessRegistry;
use app\services\ai\registry\AiRegistryValue;

/** Sequential closed-graph scheduler. Runtime ownership and Attempts stay in trusted callbacks. */
final class AiRegisteredWorkflowExecutor
{
    private $registry; private $clock;
    public function __construct(?AiBusinessRegistry $registry=null,?callable $clock=null)
    {
        $this->registry=$registry??new AiBusinessRegistry();
        $this->clock=$clock??static function():int { return (int)floor(hrtime(true)/1000000); };
    }

    /**
     * $handlers are installed by application wiring, NEVER request/model data.
     * handler($input, $node, $compiled, $heartbeat) returns a schema-checked array.
     * checkpoint($event, $node, $trace) must enforce generation, cancellation, authority,
     * atomic ownership/Attempt and persistent budgets. Exceptions stop the graph immediately.
     * No automatic retry, supplement, model call, query arithmetic or publication occurs here.
     */
    public function execute(array $compiled,array $handlers,callable $checkpoint): array
    {
        (new AiRegisteredPlanCompiler($this->registry))->assertCompiled($compiled);
        if (array_diff(array_keys($handlers),$this->registry->handlers())) AiRegistryValue::fail('AI_HANDLER_NOT_REGISTERED');
        // Preflight the entire path: a missing renderer/exporter must not cause a data read first.
        foreach ($compiled['nodes'] as $node) if (!isset($handlers[$node['handler']])||!is_callable($handlers[$node['handler']])) AiRegistryValue::fail('AI_HANDLER_UNAVAILABLE');
        $started=$this->now(); $last=$started;
        $deadline=$started+$compiled['budget']['remaining_execution_ms']-$compiled['budget']['finalization_reserve_ms'];
        $pathDeadline=$started+$compiled['budget']['worst_path_ms'];
        $trace=[]; $outputs=[];
        $counters=['node_visit_count'=>0,'skill_execution_count'=>0,'tool_call_count'=>0,'workflow_transition_count'=>0,'supplement_count'=>0];
        $activeNode='preflight';
        try { foreach ($compiled['nodes'] as $node) {
            $activeNode=$node['id'];
            if (array_key_exists($node['id'],$outputs)||array_diff($node['depends_on'],array_keys($outputs))) AiRegistryValue::fail('AI_WORKFLOW_DEPENDENCY_UNSATISFIED');
            $now=$this->now();
            if ($now<$last) AiRegistryValue::fail('AI_EXECUTION_CLOCK_REGRESSED');
            $last=$now;
            if ($now+$node['timeout_ms']>min($deadline,$pathDeadline)) AiRegistryValue::fail('AI_WORKFLOW_BUDGET_EXHAUSTED');
            foreach ($node['charges'] as $counter=>$amount) {
                $counters[$counter]+=$amount;
                if ($counters[$counter]>($compiled['budget']['counters'][$counter]??-1)) AiRegistryValue::fail('AI_WORKFLOW_BUDGET_EXHAUSTED');
            }
            call_user_func($checkpoint,'before_node',$node,$trace);
            // Check again after the atomic admission callback; waiting there consumes the same budget.
            $nodeStart=$this->now(); $nodeDeadline=min($nodeStart+$node['timeout_ms'],$deadline,$pathDeadline);
            if ($nodeStart<$last) AiRegistryValue::fail('AI_EXECUTION_CLOCK_REGRESSED');
            if ($nodeStart+$node['timeout_ms']>min($deadline,$pathDeadline)) AiRegistryValue::fail('AI_WORKFLOW_BUDGET_EXHAUSTED');
            $last=$nodeStart;
            $heartbeat=function()use($checkpoint,$node,&$trace,&$last,$nodeDeadline):void {
                $now=$this->now();
                if ($now<$last) AiRegistryValue::fail('AI_EXECUTION_CLOCK_REGRESSED');
                $last=$now;
                if ($now>=$nodeDeadline) AiRegistryValue::fail('AI_NODE_TIMEOUT');
                call_user_func($checkpoint,'heartbeat',$node,$trace);
            };
            $input=['query'=>$compiled['query'],'definition_metric_codes'=>$compiled['definition_metric_codes'],
                'dependencies'=>array_intersect_key($outputs,array_flip($node['depends_on'])),'output_format'=>$compiled['output_format']];
            $heartbeat();
            $result=call_user_func($handlers[$node['handler']],$input,$node,$compiled,$heartbeat);
            $finished=$this->now();
            if ($finished<$last) AiRegistryValue::fail('AI_EXECUTION_CLOCK_REGRESSED');
            $last=$finished;
            // A non-cooperative call can only be rejected after it returns, never called cancelled early.
            if ($finished>=$nodeDeadline) AiRegistryValue::fail('AI_NODE_TIMEOUT');
            $this->assertOutput($node['output_schema'],$result);
            $outputs[$node['id']]=$result;
            $trace[]=['node_id'=>$node['id'],'handler'=>$node['handler'],'tool'=>$node['tool'],'status'=>'SUCCEEDED',
                'elapsed_ms'=>$finished-$nodeStart,'charges'=>$node['charges']];
            call_user_func($checkpoint,'after_node',$node,$trace);
            if ($node['output_schema']==='export_result'&&$result['deferred']===true) {
                return ['status'=>'WAITING_EXTERNAL','compiled_run_hash'=>$compiled['compiled_run_hash'],'outputs'=>$outputs,'trace'=>$trace,'counters'=>$counters];
            }
        }
        } catch (\Throwable $error) {
            // Preserve registered error codes verbatim.  For an otherwise
            // opaque PHP/runtime failure, disclose only its server-owned graph
            // node to the gateway; customer facts and exception text stay out.
            if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$error->getMessage())) throw $error;
            throw new \RuntimeException('AI_WORKFLOW_NODE_'.strtoupper($activeNode).'_FAILED',0,$error);
        }
        return ['status'=>'COMPLETED','compiled_run_hash'=>$compiled['compiled_run_hash'],'outputs'=>$outputs,'trace'=>$trace,'counters'=>$counters];
    }
    private function assertOutput(string $schema,$value): void
    {
        if (!is_array($value)||AiRegistryValue::isList($value)) AiRegistryValue::fail('AI_NODE_OUTPUT_INVALID');
        if ($schema==='business_evidence') {
            if (($value['ai_query_ready']??null)!==true||($value['result_status']??null)!=='complete'||!is_array($value['results']??null)||!$value['results']||!AiRegistryValue::isList($value['results'])) AiRegistryValue::fail('AI_EVIDENCE_INCOMPLETE');
        } elseif ($schema==='metadata_evidence') {
            if (!is_array($value['definitions']??null)||!$value['definitions']) AiRegistryValue::fail('AI_METADATA_NOT_READY');
        } elseif ($schema==='verified_result') {
            if (($value['verified']??null)!==true) AiRegistryValue::fail('AI_EVIDENCE_INCOMPLETE');
        } elseif ($schema==='answer_result') {
            if (!is_array($value['answer']??null)||!$value['answer']) AiRegistryValue::fail('AI_NODE_OUTPUT_INVALID');
        } elseif ($schema==='export_result') {
            if (!is_bool($value['deferred']??null)) AiRegistryValue::fail('AI_NODE_OUTPUT_INVALID');
        } else AiRegistryValue::fail('AI_REGISTRY_SCHEMA_INVALID');
    }
    private function now(): int
    {
        $now=call_user_func($this->clock);
        if (!is_int($now)||$now<0) AiRegistryValue::fail('AI_EXECUTION_CLOCK_INVALID');
        return $now;
    }
}
