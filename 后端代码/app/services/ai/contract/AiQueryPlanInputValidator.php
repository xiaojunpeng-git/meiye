<?php

namespace app\services\ai\contract;

/**
 * Query branch input validation ONLY, not a compiler or an authorization source.
 * The future trusted candidate issuer supplies the current Run-bound envelope.
 * Production must still compile registered dependencies, rebuild report scopes,
 * verify readiness and obtain real consistent-read evidence before execution.
 * Unsupported clarify/refuse/new filter schemas fail closed in this foundation.
 */
final class AiQueryPlanInputValidator
{
    public function validate(string $json, array $trustedContext, array $candidateSet): array
    {
        $plan = AiStrictJson::decodeObject($json);
        $this->fields($plan, ['schema_version', 'decision', 'candidate_set_ref', 'conversation_relation_hint', 'workflow_ref', 'inputs', 'clarification']);
        if ($plan->schema_version !== 'mohe-plan-v2' || $plan->decision !== 'run_workflow' || $plan->clarification !== null) {
            $this->fail('AI_PLAN_BRANCH_UNSUPPORTED');
        }
        if (!in_array($plan->conversation_relation_hint, ['new_topic', 'follow_up', 'refine', 'clarification_reply'], true)) {
            $this->fail('AI_PLAN_RELATION_INVALID');
        }
        $this->owner($trustedContext, $candidateSet);
        if (!is_string($plan->candidate_set_ref) || $plan->candidate_set_ref !== $candidateSet['candidate_set_ref']) {
            $this->fail('AI_PLAN_CANDIDATE_MISMATCH');
        }
        if (!is_string($plan->workflow_ref) || $plan->workflow_ref === '' || !isset($candidateSet['workflows'][$plan->workflow_ref])) {
            $this->fail('AI_PLAN_WORKFLOW_UNKNOWN');
        }
        $candidate = $candidateSet['workflows'][$plan->workflow_ref];
        $this->candidate($candidate);
        $input = $plan->inputs;
        $this->fields($input, ['metric_codes', 'time_range', 'compare_time_range', 'requested_scope', 'dimension_code', 'direction_code', 'limit', 'drill_context_ref', 'output_format_code']);
        if (!is_array($input->metric_codes) || count($input->metric_codes) < 1 || count($input->metric_codes) > 8) {
            $this->fail('AI_PLAN_METRICS_INVALID');
        }
        $metrics = [];
        foreach ($input->metric_codes as $metric) {
            if (!is_string($metric) || !in_array($metric, $candidate['metric_codes'], true) || in_array($metric, $metrics, true)) {
                $this->fail('AI_PLAN_METRICS_INVALID');
            }
            $metrics[] = $metric;
        }
        if (!is_string($input->output_format_code) || !in_array($input->output_format_code, $candidate['output_formats'], true)) {
            $this->fail('AI_PLAN_FORMAT_UNAVAILABLE');
        }
        if ($input->drill_context_ref !== null) {
            $this->fail('AI_PLAN_DRILL_DISABLED');
        }
        $scope = $input->requested_scope;
        $this->fields($scope, ['mode', 'organization_refs', 'store_refs', 'employee_refs', 'scope_selection_ref']);
        if ($scope->mode !== 'current_report_permission' || $scope->organization_refs !== [] || $scope->store_refs !== [] || $scope->employee_refs !== [] || $scope->scope_selection_ref !== null) {
            $this->fail('AI_PLAN_SCOPE_BINDING_UNAVAILABLE');
        }
        $range = $this->range($input->time_range, $candidate);
        $comparison = null;
        if ($candidate['query_shape'] === 'comparison') {
            $comparison = $this->range($input->compare_time_range, $candidate);
        } elseif ($input->compare_time_range !== null) {
            $this->fail('AI_PLAN_COMPARISON_NOT_ALLOWED');
        }
        if ($candidate['query_shape'] === 'ranking') {
            if ($input->dimension_code !== 'store' || !in_array($input->direction_code, ['top', 'bottom', 'top_and_bottom'], true) || !is_int($input->limit) || $input->limit < 1 || $input->limit > 20) {
                $this->fail('AI_PLAN_RANKING_INVALID');
            }
        } elseif ($input->dimension_code !== null || $input->direction_code !== null || $input->limit !== null) {
            $this->fail('AI_PLAN_SHAPE_FIELDS_INVALID');
        }
        return [
            'schema_version' => 'mohe-validated-query-input-v1',
            'requires_authoritative_compile' => true,
            'candidate_set_ref' => $plan->candidate_set_ref,
            'workflow_ref' => $plan->workflow_ref,
            'conversation_relation_hint' => $plan->conversation_relation_hint,
            'metric_codes' => $metrics,
            'query_shape' => $candidate['query_shape'],
            'time_range' => $range,
            'compare_time_range' => $comparison,
            'scope_mode' => 'current_report_permission',
            'dimension_code' => $input->dimension_code,
            'direction_code' => $input->direction_code,
            'limit' => $input->limit,
            'output_format_code' => $input->output_format_code,
        ];
    }

    private function owner(array $context, array $set): void
    {
        $keys = ['instance_id', 'account_id', 'terminal', 'conversation_id', 'run_id', 'capability_snapshot_ref'];
        if (!isset($context['now']) || !is_int($context['now']) || $context['now'] < 0 || !isset($set['expires_at']) || !is_int($set['expires_at']) || $set['expires_at'] <= $context['now']) {
            $this->fail('AI_PLAN_CANDIDATE_EXPIRED');
        }
        if (!isset($set['owner']) || !is_array($set['owner']) || !isset($set['candidate_set_ref']) || !is_string($set['candidate_set_ref']) || $set['candidate_set_ref'] === '' || !isset($set['workflows']) || !is_array($set['workflows']) || count($set['workflows']) > 16) {
            $this->fail('AI_PLAN_CANDIDATE_INVALID');
        }
        foreach ($keys as $key) {
            if (!isset($context[$key]) || !is_string($context[$key]) || $context[$key] === '' || !isset($set['owner'][$key]) || $set['owner'][$key] !== $context[$key]) {
                $this->fail('AI_PLAN_OWNER_MISMATCH');
            }
        }
    }

    private function candidate($candidate): void
    {
        if (!is_array($candidate) || !isset($candidate['query_shape']) || !in_array($candidate['query_shape'], ['summary', 'trend', 'ranking', 'comparison'], true)) {
            $this->fail('AI_PLAN_CANDIDATE_INVALID');
        }
        $keys = array_keys($candidate);
        $expected = ['query_shape', 'metric_codes', 'output_formats', 'date_presets', 'max_date_span_days', 'coverage_start_date', 'coverage_end_date'];
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            $this->fail('AI_PLAN_CANDIDATE_INVALID');
        }
        foreach (['metric_codes', 'output_formats', 'date_presets'] as $key) {
            if (!isset($candidate[$key]) || !is_array($candidate[$key])) {
                $this->fail('AI_PLAN_CANDIDATE_INVALID');
            }
        }
        foreach (['metric_codes' => 8, 'output_formats' => 2] as $key => $maximum) {
            $values = $candidate[$key];
            if (!$values || count($values) > $maximum || array_keys($values) !== range(0, count($values) - 1)) {
                $this->fail('AI_PLAN_CANDIDATE_INVALID');
            }
            $seen = [];
            foreach ($values as $value) {
                if (!is_string($value) || $value === '' || strlen($value) > 128 || in_array($value, $seen, true)) {
                    $this->fail('AI_PLAN_CANDIDATE_INVALID');
                }
                if ($key === 'output_formats' && !in_array($value, ['screen', 'screen_and_xlsx'], true)) {
                    $this->fail('AI_PLAN_CANDIDATE_INVALID');
                }
                $seen[] = $value;
            }
        }
        if (count($candidate['date_presets']) > 32) {
            $this->fail('AI_PLAN_CANDIDATE_INVALID');
        }
        foreach ($candidate['date_presets'] as $code => $preset) {
            if (!is_string($code) || $code === '' || strlen($code) > 128 || !is_array($preset)) {
                $this->fail('AI_PLAN_CANDIDATE_INVALID');
            }
            $keys = array_keys($preset);
            sort($keys, SORT_STRING);
            if ($keys !== ['end_date', 'start_date']) {
                $this->fail('AI_PLAN_CANDIDATE_INVALID');
            }
            $this->date($preset['start_date']);
            $this->date($preset['end_date']);
            if ($preset['start_date'] > $preset['end_date']) {
                $this->fail('AI_PLAN_CANDIDATE_INVALID');
            }
        }
        if (!isset($candidate['max_date_span_days']) || !is_int($candidate['max_date_span_days']) || $candidate['max_date_span_days'] < 1 || $candidate['max_date_span_days'] > 3660) {
            $this->fail('AI_PLAN_CANDIDATE_INVALID');
        }
        $start = $candidate['coverage_start_date'] ?? null;
        $end = $candidate['coverage_end_date'] ?? null;
        $this->date($start);
        $this->date($end);
        if ($start > $end) {
            $this->fail('AI_PLAN_CANDIDATE_INVALID');
        }
    }

    private function range($value, array $candidate): array
    {
        $this->fields($value, ['mode', 'preset_code', 'explicit_start_date', 'explicit_end_date']);
        if ($value->mode === 'preset') {
            if (!is_string($value->preset_code) || !isset($candidate['date_presets'][$value->preset_code]) || $value->explicit_start_date !== null || $value->explicit_end_date !== null) {
                $this->fail('AI_PLAN_DATE_INVALID');
            }
            // Resolved by the report's trusted business-date rules, not local now.
            $resolved = $candidate['date_presets'][$value->preset_code];
            if (!is_array($resolved)) {
                $this->fail('AI_PLAN_CANDIDATE_INVALID');
            }
            $start = $resolved['start_date'] ?? null;
            $end = $resolved['end_date'] ?? null;
        } elseif ($value->mode === 'explicit' && $value->preset_code === null) {
            $start = $value->explicit_start_date;
            $end = $value->explicit_end_date;
        } else {
            $this->fail('AI_PLAN_DATE_INVALID');
        }
        $first = $this->date($start);
        $last = $this->date($end);
        if ($start > $end || $start < $candidate['coverage_start_date'] || $end > $candidate['coverage_end_date'] || (int)$first->diff($last)->format('%a') + 1 > $candidate['max_date_span_days']) {
            $this->fail('AI_PLAN_DATE_OUTSIDE_CAPABILITY');
        }
        return ['start_date' => $start, 'end_date' => $end];
    }

    private function date($value): \DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            $this->fail('AI_PLAN_DATE_INVALID');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value || substr($value, 0, 4) === '0000') {
            $this->fail('AI_PLAN_DATE_INVALID');
        }
        return $date;
    }

    private function fields($object, array $allowed): void
    {
        if (!($object instanceof \stdClass)) {
            $this->fail('AI_PLAN_OBJECT_REQUIRED');
        }
        $actual = array_keys(get_object_vars($object));
        sort($actual, SORT_STRING);
        sort($allowed, SORT_STRING);
        if ($actual !== $allowed) {
            $this->fail('AI_PLAN_FIELDS_INVALID');
        }
    }

    private function fail(string $reason): void
    {
        throw new AiContractException($reason);
    }
}
