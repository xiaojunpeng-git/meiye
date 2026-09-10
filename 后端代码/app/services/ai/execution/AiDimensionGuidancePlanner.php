<?php
namespace app\services\ai\execution;

/**
 * Guides a registered analysis dimension, such as member payment ranking.
 * The dimension and its candidate metrics come from lower-layer contracts;
 * this class contains no report name, table, SQL, formula or object-specific
 * business value.  It can therefore be reused when a later domain registers
 * another aggregate dimension.
 */
final class AiDimensionGuidancePlanner
{
    public function start(string $objectKind, array $intent, array $projection, array $candidates, string $format, string $today): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $objectKind) || ($intent['operation'] ?? null) !== 'ranking') {
            throw new \RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        }
        ksort($candidates);
        if (!$candidates) throw new \RuntimeException('AI_CAPABILITY_NOT_READY');
        $signals = $projection['signals'] ?? [];
        // Preserve every explicit metric: selecting the supported intersection
        // would silently discard part of a multi-metric question.
        $explicit = array_values(array_intersect($signals, array_keys(\app\services\query\metric\MetricSemanticCatalog::entries())));
        // Business actions are produced by the general intent Skill and have
        // already been validated against the business Skill contract.  This
        // planner never scans the question for action words.
        $requestedActions=$intent['action_codes']??[];
        if (!is_array($requestedActions)||count($requestedActions)>8||count(array_unique($requestedActions))!==count($requestedActions)) throw new \RuntimeException('AI_MODEL_RESPONSE_INVALID');
        foreach ($requestedActions as $action) if(!is_string($action)||!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$action)) throw new \RuntimeException('AI_MODEL_RESPONSE_INVALID');
        if ($requestedActions) {
            // Multiple words can describe the same registered metric, such as
            // member “付款能力” and “收款”.  Keep only candidates whose source
            // contract satisfies every stated action.  If none can, preserve
            // the complete request and stop instead of selecting one action.
            $candidates=array_filter($candidates,static function(array $candidate) use($requestedActions): bool {
                return array_diff($requestedActions,(array)($candidate['action_codes']??[]))===[];
            });
            ksort($candidates);
            if (!$candidates) {
                if (count($requestedActions)>1) throw new \RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
                return ['kind'=>'capability_unavailable','reason'=>'AI_DIMENSION_ACTION_CONTRACT_NOT_READY'];
            }
        }
        // A user-visible dictionary match is stronger than model output.  When
        // the Skill itself established an action (for example “卖得”), the
        // action contract is stronger than an arbitrary model metric choice.
        $selected = $explicit ?: ($requestedActions ? [] : ($intent['metric_codes'] ?? []));
        if (count($selected) > 1 || array_diff($selected, array_keys($candidates))) throw new \RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        $metric = $selected[0] ?? null;
        if ($metric===null && $requestedActions && count($candidates)===1) $metric=(string)array_key_first($candidates);
        if (!$explicit && !$requestedActions && ($intent['needs_metric_choice'] ?? false) === true) $metric = null;
        $terms = $projection['date_terms'] ?? [];
        if (count($terms) > 1 || !empty($projection['date_grouping_ambiguous'])) throw new \RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        $range = $terms ? (new AiWorkflowPlanner())->normalizePeriod($terms[0], $today) : null;
        // Result shape belongs to the model's generic language understanding,
        // not to a project/member/person phrase table or a business scene.
        $ranking=$intent['ranking']??null;
        if (!is_array($ranking) || array_keys($ranking)!==['direction','limit']
            || !in_array($ranking['direction']??null,['top','bottom','top_and_bottom','unspecified'],true)
            || (!is_null($ranking['limit']??null) && !is_int($ranking['limit']))) throw new \RuntimeException('AI_MODEL_RESPONSE_INVALID');
        $direction=$ranking['direction']==='unspecified'?null:$ranking['direction'];
        $limit=$ranking['limit'];
        // A ranked list whose count is genuinely omitted uses the published
        // result default.  An explicit count is never normalized or replaced.
        if ($limit === null && $direction !== null) $limit = 5;
        if ($limit !== null && (!is_int($limit) || $limit < 1 || $limit > 20)) throw new \RuntimeException('AI_DIMENSION_RANK_LIMIT_NOT_READY');
        return $this->next(['object_kind'=>$objectKind,'metric'=>$metric,'candidates'=>$candidates,'range'=>$range,
            'direction'=>$direction,'limit'=>$limit,'format'=>$format,'today'=>$today]);
    }

    public function choose(array $envelope, array $choices): array
    {
        $state = $envelope['dimension_state'] ?? null;
        if (!is_array($state) || !is_array($envelope['fields'] ?? null)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $expected = array_column($envelope['fields'], 'key'); $actual = array_keys($choices); sort($expected); sort($actual);
        if ($expected !== $actual) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        foreach ($envelope['fields'] as $field) {
            $key = $field['key'];
            if ($key === 'start_date') {
                $state['range'] = (new AiWorkflowPlanner())->normalizePeriod(['code'=>'EXPLICIT','start'=>$choices['start_date'] ?? null,'end'=>$choices['end_date'] ?? null], $state['today']);
                continue;
            }
            // Both dates are validated together by normalizePeriod above.
            if ($key === 'end_date') continue;
            $value = $choices[$key] ?? null;
            if (!is_string($value) || !in_array($value, array_column($field['options'] ?? [], 'value'), true)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
            if ($key === 'dimension_metric') $state['metric'] = $value;
            elseif ($key === 'dimension_direction') $state['direction'] = $value;
            elseif ($key === 'dimension_limit') $state['limit'] = (int)$value;
            else throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        }
        return $this->next($state);
    }

    private function next(array $state): array
    {
        $fields=[]; $question='';
        if ($state['metric'] === null) {
            $question='您想按哪种表现排行？'; $options=[];
            foreach ($state['candidates'] as $code=>$candidate) $options[]=['value'=>$code,'label'=>$candidate['name'].'：'.$candidate['summary']];
            $fields[]=['key'=>'dimension_metric','type'=>'select','label'=>'评价指标','options'=>$options];
        } elseif ($state['range'] === null) {
            $question='您想查询哪个时间段？';
            $fields[]=['key'=>'start_date','label'=>'开始日期','type'=>'date'];
            $fields[]=['key'=>'end_date','label'=>'结束日期','type'=>'date'];
        } elseif ($state['direction'] === null) {
            $question='您希望从高到低还是从低到高查看？';
            $fields[]=['key'=>'dimension_direction','label'=>'排序方向','type'=>'select','options'=>[['value'=>'top','label'=>'从高到低'],['value'=>'bottom','label'=>'从低到高']]];
        } elseif ($state['limit'] === null) {
            $question='您希望查看多少项结果？';
            $fields[]=['key'=>'dimension_limit','label'=>'展示数量','type'=>'select','options'=>[['value'=>'5','label'=>'5 项'],['value'=>'10','label'=>'10 项'],['value'=>'20','label'=>'20 项']]];
        }
        if ($fields) {
            $summary=[];
            if ($state['metric'] !== null) $summary[]=['label'=>'评价指标','value'=>$state['candidates'][$state['metric']]['name']];
            if ($state['range'] !== null) $summary[]=['label'=>'查询期间','value'=>$state['range']['start'].' 至 '.$state['range']['end']];
            if ($state['direction'] !== null) $summary[]=['label'=>'排序方向','value'=>['top'=>'从高到低','bottom'=>'从低到高','top_and_bottom'=>'最高与最低'][$state['direction']]];
            return ['kind'=>'clarification','schema_version'=>'mohe-dimension-guidance-v1','dimension_state'=>$state,
                'fields'=>$fields,'question'=>$question,'confirmed_summary'=>$summary];
        }
        return ['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_ranking','query'=>[
            'query_shape'=>'ranking','metric_codes'=>[$state['metric']],'start_date'=>$state['range']['start'],'end_date'=>$state['range']['end'],
            'compare_range'=>null,'store_ids'=>[],'business_filters'=>['object_kind'=>$state['object_kind']],
            'ranking'=>['direction'=>$state['direction'],'limit'=>$state['limit']]],'output_format'=>$state['format']]];
    }
}
