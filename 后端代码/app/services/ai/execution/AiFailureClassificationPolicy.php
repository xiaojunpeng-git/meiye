<?php
declare(strict_types=1);

namespace app\services\ai\execution;

/** Classification only: unique outcome counting and 24h persistence are adapter duties. */
class AiFailureClassificationPolicy
{
    public static function failureCounterAction($outcome, $cause, $independentExport): string
    {
        $allowed = [
            'SUCCEEDED' => ['NONE', 'NORMAL_EMPTY'],
            'PARTIAL_SUCCEEDED' => ['EXPORT_TECHNICAL_FAILURE'],
            'CAPACITY_REJECTED' => ['CAPACITY'],
            'CANCELLED' => ['USER_CANCELLED', 'AUTHORIZATION_REVOKED'],
            'FAILED' => ['CAPACITY_STOPPED', 'MODEL_TECHNICAL_FAILURE', 'QUERY_TECHNICAL_FAILURE',
                'EXPRESSION_TECHNICAL_FAILURE', 'PUBLICATION_TECHNICAL_FAILURE', 'MODEL_UNKNOWN',
                'TOOL_UNKNOWN', 'EXPORT_TECHNICAL_FAILURE', 'PARAMETER_REJECTED', 'CAPABILITY_REJECTED',
                'PERMISSION_REJECTED', 'EVIDENCE_INSUFFICIENT'],
        ];
        if (!is_string($outcome) || !is_string($cause) || !is_bool($independentExport)
            || !isset($allowed[$outcome]) || !in_array($cause, $allowed[$outcome], true)
            || ($independentExport && $outcome === 'PARTIAL_SUCCEEDED')) {
            throw new AiRuntimePolicyException('FAILURE_CLASSIFICATION_INVALID');
        }
        if ($independentExport) {
            return 'unchanged';
        }
        if ($outcome === 'PARTIAL_SUCCEEDED' || ($outcome === 'SUCCEEDED' && $cause === 'NONE')) {
            return 'clear';
        }
        if ($outcome === 'FAILED' && in_array($cause, ['MODEL_TECHNICAL_FAILURE', 'QUERY_TECHNICAL_FAILURE',
            'EXPRESSION_TECHNICAL_FAILURE', 'PUBLICATION_TECHNICAL_FAILURE', 'MODEL_UNKNOWN', 'TOOL_UNKNOWN'], true)) {
            return 'increment';
        }
        return 'unchanged';
    }

    /** Caller establishes cancellation-vs-stop order using its atomic storage boundary. */
    public static function capacityStopDecision($currentState): array
    {
        if (in_array($currentState, ['CANCELLED', 'FAILED', 'COMPLETED'], true)) {
            return ['action' => 'KEEP_TERMINAL', 'state' => $currentState];
        }
        if (!in_array($currentState, ['RECEIVED', 'CONTEXT_READY', 'WORKFLOW_SELECTING',
            'WAITING_CLARIFICATION', 'COMPILING', 'WORKFLOW_EXECUTING', 'EVALUATING_NODE',
            'SUPPLEMENTING', 'VERIFYING', 'RENDERING', 'PUBLISHING'], true)) {
            throw new AiRuntimePolicyException('RUN_STATE_INVALID');
        }
        return ['action' => 'STOP_AND_CANCEL_CHILDREN', 'state' => 'FAILED', 'reason' => 'CAPACITY_STOPPED'];
    }
}
