<?php
declare(strict_types=1);

/** Independent ordinal-clarification acceptance. Real Gateway/SQLite and
 * encrypted Run envelopes are driven with synthetic model/fact fixtures.
 * No provider, application bootstrap or business database is contacted. */
require __DIR__ . '/r6-gateway-harness.php';

use app\services\ai\AiGatewayServices;
use app\services\query\metric\MetricReadViewStore;

$checks = 0; $failures = [];
function ocCheck(bool $ok, string $label): void {
    global $checks, $failures; ++$checks; if (!$ok) $failures[] = $label;
}
function ocMethod($object, string $name): ReflectionMethod {
    $method = new ReflectionMethod($object, $name);
    if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
    return $method;
}
function ocSource(R6GatewayHarness $h): array {
    $h->semanticIntent = ['object_kind' => 'store', 'object_term' => '', 'operation' => 'ranking',
        'metric_codes' => ['cash_performance'], 'action_codes' => [], 'needs_metric_choice' => false,
        'ranking' => ['direction' => 'top_and_bottom', 'limit' => 1],
        'periods' => [['kind' => 'date_range', 'start' => '2026-09-01', 'end' => '2026-09-08']],
        'scope' => 'authorized', 'unresolved_fragments' => []];
    $run = $h->start('门店现金业绩最高和最低各一家');
    ocCheck(($run['status'] ?? '') === 'COMPLETED', 'synthetic source ranking completes');
    if (($run['status'] ?? '') !== 'COMPLETED') throw new RuntimeException('source ranking unavailable');
    $evidence = $h->private->read($h->row($run)['evidence_ref']);
    $views = new MetricReadViewStore($h->temp . '/views', $h->private->signingKey());
    $view = $views->get($evidence['view_ref']);
    ocCheck(count($view['results'][0]['rows']['top'] ?? []) === 1
        && count($view['results'][0]['rows']['bottom'] ?? []) === 1, 'fixture really contains two displayed rank groups');
    return [$run, $evidence['query']];
}
function ocCard(R6GatewayHarness $h, array $source, string $question = '第一名详情'): array {
    $models = $h->models; $queries = $h->queries;
    $run = $h->start($question, $source['answer']['context_ref']);
    ocCheck(($run['status'] ?? '') === 'WAITING_CLARIFICATION', $question . ' asks a live clarification');
    ocCheck($h->models === $models && $h->queries === $queries, $question . ' neither guesses through a model nor reads facts');
    if (($run['status'] ?? '') !== 'WAITING_CLARIFICATION') return $run;
    ocCheck(($run['clarification']['fields'][0]['key'] ?? '') === 'semantic_reply', 'uses existing semantic reply protocol');
    ocCheck(strpos($run['clarification']['question'] ?? '', '榜') !== false, 'question names the missing board');
    return $run;
}

// Run-owned options are executable selections of prior rows. The accepted
// reply rechecks permission and retains the source query's time/measurement.
foreach (['suggestion_1', 'suggestion_2', 'supplement', 'permission', 'out_of_range'] as $case) {
    $h = new R6GatewayHarness(5, [1], 'platform');
    try {
        [$source, $sourceQuery] = ocSource($h);
        $run = ocCard($h, $source, $case === 'out_of_range' ? '第二名详情' : '第一名详情');
        if (($run['status'] ?? '') !== 'WAITING_CLARIFICATION') continue;
        $stored = $h->private->read($h->row($run)['clarification_ref']);
        $envelope = $stored['envelope'];
        $options = array_column($run['clarification']['fields'][0]['options'] ?? [], 'value');
        if ($case === 'out_of_range') {
            ocCheck(($envelope['_semantic_recommendations'] ?? []) === [], 'nonexistent second rows never become executable suggestions');
            ocCheck($options === ['__supplement__'], 'out-of-range position offers only relevant supplementation');
            ocCheck(strpos($run['clarification']['question'] ?? '', '没有第2名') !== false,
                'out-of-range explanation identifies the absent position instead of asking which nonexistent board row');
            continue;
        }
        ocCheck(isset($envelope['_semantic_recommendations']['suggestion_1'], $envelope['_semantic_recommendations']['suggestion_2']),
            'both actual first-place groups have a validated candidate');
        ocCheck(($envelope['_semantic_reply_state']['ordinal_group_required'] ?? false) === true,
            'existing free-text channel retains the unresolved board and original ordinal');
        $beforeModels = $h->models; $beforeQueries = $h->queries;
        if ($case === 'permission') $h->auth['permission_version'] = 'changed-after-card';
        if ($case === 'supplement') {
            $h->understandingOverride = ['goal' => 'select the lower board', 'status' => 'understood', 'topic_relation' => 'continuation',
                'requirements' => [['id' => 'r1', 'meaning' => 'the current reply identifies the lower board',
                    'fields' => ['ranking'], 'values' => ['ranking' => ['direction' => 'bottom', 'limit' => null]],
                    'evidence' => [['message_id' => 'current', 'quote' => '最低榜单']]]]];
            $done = $h->choose($run, ['semantic_reply' => '__supplement__', 'supplement_text' => '最低榜单']);
        } else $done = $h->choose($run, ['semantic_reply' => $case === 'permission' ? 'suggestion_1' : $case]);
        if ($case === 'permission') {
            ocCheck(($done['status'] ?? '') === 'FAILED' && $h->queries === $beforeQueries,
                'changed authority blocks candidate execution before facts');
            continue;
        }
        ocCheck(($done['status'] ?? '') === 'COMPLETED', $case . ' resumes the original query');
        if (($done['status'] ?? '') !== 'COMPLETED') continue;
        $query = $h->private->read($h->row($done)['evidence_ref'])['query'];
        ocCheck(($query['query_shape'] ?? '') === 'breakdown' && ($query['ranking'] ?? null) === null,
            $case . ' does not recompute a one-row ranking');
        ocCheck(($query['store_ids'] ?? []) === [1], $case . ' identity is the original displayed store');
        ocCheck($query['start_date'] === $sourceQuery['start_date'] && $query['end_date'] === $sourceQuery['end_date']
            && in_array($sourceQuery['metric_codes'][0], $query['metric_codes'], true), $case . ' keeps original period and measurement');
        if ($case !== 'supplement') ocCheck($h->models === $beforeModels, $case . ' executable choice is not reinterpreted by model');
    } catch (Throwable $error) { ocCheck(false, $case . ' unexpected ' . $error->getMessage()); }
    finally { $h->close(); }
}

// Direct helper cases isolate prior row identity and model-evidence guards.
// Multi-result/empty/out-of-range boards must never be flattened into choices.
$h = new R6GatewayHarness(5, [1, 2, 3, 4], 'platform');
try {
    if (!method_exists($h->gateway, 'ordinalGroupClarification')) throw new RuntimeException('ordinalGroupClarification not implemented');
    $helper = ocMethod($h->gateway, 'ordinalGroupClarification');
    $caps = ocMethod($h->gateway, 'capabilities')->invoke($h->gateway, $h->context);
    $query = ['query_shape' => 'ranking', 'metric_codes' => ['cash_performance'], 'start_date' => '2026-09-01',
        'end_date' => '2026-09-08', 'compare_range' => null, 'store_ids' => [1, 2, 3, 4],
        'business_filters' => [], 'ranking' => ['direction' => 'top_and_bottom', 'limit' => 2],
        'aggregate_condition' => null, 'ranking_presentation_metrics' => []];
    $rows = [];
    foreach (['top' => [1, 2], 'bottom' => [3, 4]] as $group => $ids) foreach ($ids as $id)
        $rows[$group][] = ['store_id' => $id, 'store_name' => 'fixture-' . $id, 'amount_cents' => $id * 100];
    $source = ['query' => $query, 'view' => ['query' => $query, 'results' => [['object_kind' => 'store', 'rows' => $rows]]],
        'meaning' => ['presentation_origin' => 'customer_or_verified_context']];
    $invoke = static function (array &$meaning, string $question, array $context) use ($helper, $h, $caps): ?array {
        $safe = ['outbound' => ['question' => $question, 'prior_query' => ['operation' => 'ranking'],
            'evidence_messages' => [['id' => 'current', 'text' => $question]]], 'local_conditions' => [], 'reference_values' => []];
        $body = ['question' => $question, 'output_format' => 'screen', 'context_ref' => ['fixture' => true], '_platform_guidance' => true];
        $args = [&$meaning, $safe, $body, $context, $caps, '2026-10-11'];
        return $helper->invokeArgs($h->gateway, $args);
    };
    $meaning = static function (): array {
        return ['status' => 'understood', 'topic_relation' => 'continuation', 'goal' => 'inspect one displayed position',
            'requirements' => [['id' => 'r1', 'meaning' => 'select second place', 'fields' => ['result_reference'],
                'values' => ['result_reference' => ['group' => 'top', 'ordinal' => 2]],
                'evidence' => [['message_id' => 'current', 'quote' => '第二名详情']]]]];
    };
    $m = $meaning(); $card = $invoke($m, '第二名详情', $source);
    ocCheck(($card['kind'] ?? '') === 'clarification', 'model group with no current group evidence cannot guess top');
    foreach (['suggestion_1' => 2, 'suggestion_2' => 4] as $option => $expected) {
        $candidate = $card['_semantic_recommendations'][$option]['plan']['query'] ?? [];
        ocCheck(($candidate['store_ids'] ?? []) === [$expected], $option . ' resolves second place inside its own actual group');
        ocCheck(($candidate['start_date'] ?? '') === $query['start_date'] && ($candidate['end_date'] ?? '') === $query['end_date']
            && in_array($query['metric_codes'][0], $candidate['metric_codes'] ?? [], true), $option . ' retains original source conditions');
    }
    $choice = ocMethod($h->gateway, 'semanticRecommendationChoice');
    $bottom = $choice->invoke($h->gateway, $card,
        ['choices' => ['semantic_reply' => '__supplement__', 'supplement_text' => '最低榜单']]);
    ocCheck(($bottom['plan']['query']['store_ids'] ?? []) === [4], 'free-text group confirms the original second ordinal, not first');
    ocCheck($choice->invoke($h->gateway, $card,
        ['choices' => ['semantic_reply' => '__supplement__', 'supplement_text' => '最低榜单今天的消耗业绩']]) === null,
        'mixed free-text reply cannot execute an old candidate and discard new fields');
    // Choosing through free text must keep the same payload limits as every
    // other semantic supplement, even if punctuation/space normalizes away.
    foreach (['overlong' => '最低榜单' . str_repeat(' ', 1001), 'extra key' => '最低榜单'] as $label => $reply) {
        $input = ['choices' => ['semantic_reply' => '__supplement__', 'supplement_text' => $reply]];
        if ($label === 'extra key') $input['choices']['injected_plan'] = [];
        try { $choice->invoke($h->gateway, $card, $input); ocCheck(false, $label . ' supplement must be rejected'); }
        catch (RuntimeException $error) { ocCheck($error->getMessage() === 'AI_CLARIFICATION_INVALID', $label . ' retains protocol validation'); }
    }
    $m = $meaning(); $explicit = $invoke($m, '最低榜单第二名详情', $source);
    ocCheck(($explicit['plan']['query']['store_ids'] ?? []) === [4],
        'current explicit group controls the board instead of an inconsistent model top');
    foreach ([
        'new metric' => ['fields' => ['metric_codes'], 'values' => ['metric_terms' => ['消耗业绩']]],
        'new period' => ['fields' => ['periods'], 'values' => ['periods' => [['kind' => 'relative_days', 'days' => 1, 'end_offset_days' => 0]]]],
    ] as $label => $extra) {
        $extraQuestion = $label === 'new metric' ? '第二名的消耗业绩详情' : '第二名今天的详情';
        $m = $meaning(); $m['requirements'][] = ['id' => 'r2', 'meaning' => $label,
            'evidence' => [['message_id' => 'current', 'quote' => $extraQuestion]]] + $extra;
        $m['requirements'][0]['evidence'][0]['quote'] = $extraQuestion;
        $card = $invoke($m, $extraQuestion, $source);
        ocCheck(($card['kind'] ?? '') === 'clarification' && ($card['_semantic_recommendations'] ?? []) === [],
            $label . ' is not swallowed by an executable old-detail option');
        ocCheck(in_array($extra['fields'][0], array_merge(...array_column($card['_semantic_reply_state']['understanding']['requirements'] ?? [], 'fields')), true),
            $label . ' remains in encrypted supplemental meaning');
        $outbound = ['question' => '最低榜单', 'prior_query' => ['operation' => 'ranking'],
            'clarification_context' => ['question' => $extraQuestion, 'understanding' => $card['_semantic_reply_state']['understanding']],
            'evidence_messages' => [['id' => 'current', 'text' => '最低榜单'], ['id' => 'original', 'text' => $extraQuestion]]];
        $merged = ocMethod($h->gateway, 'confirmedOrdinalGroupUnderstanding')->invoke($h->gateway,
            ['_clarification_context' => $card['_semantic_reply_state']], $outbound);
        ocCheck(($merged['requirements'][0]['values']['result_reference'] ?? []) === ['group' => 'bottom', 'ordinal' => 2]
            && in_array($extra['fields'][0], array_merge(...array_column($merged['requirements'] ?? [], 'fields')), true),
            $label . ' survives a later group-only answer together with its original ordinal');
    }
    foreach (['今天天气怎么样', '今天现金业绩多少'] as $question) {
        $m = ['status' => 'needs_clarification', 'goal' => $question, 'requirements' => []];
        ocCheck($invoke($m, $question, $source) === null, 'unrelated question does not receive a ranking card: ' . $question);
    }
    $m = $meaning(); $m['topic_relation'] = 'new_topic';
    ocCheck($invoke($m, '第二名详情', $source) === null, 'confirmed new topic cannot reopen prior rows');
    $single = $source; unset($single['view']['results'][0]['rows']['bottom']);
    $m = $meaning(); ocCheck($invoke($m, '第二名详情', $single) === null, 'one real board needs no group clarification');
    // Member detail has an existing rights view. A board choice must preserve
    // that request, including legacy private envelopes, rather than replacing
    // it with a store/person overview or a model-selected member identity.
    $members = $source; $members['query']['business_filters'] = ['object_kind' => 'member'];
    $members['view']['query'] = $members['query'];
    $members['view']['read_consistency_ref'] = 'synthetic-signed-member-view';
    $members['view']['expires_at'] = 2000000000;
    $members['view']['results'][0]['object_kind'] = 'member';
    foreach (['top' => [1, 2], 'bottom' => [3, 4]] as $group => $ids) {
        $members['view']['results'][0]['rows'][$group] = [];
        foreach ($ids as $id) $members['view']['results'][0]['rows'][$group][] = ['member_id' => $id, 'member_name' => 'member-' . $id, 'amount_cents' => $id * 100];
    }
    foreach (['object_detail', 'member_detail'] as $field) foreach (['summary', 'rights'] as $view) {
        $m = ['status' => 'understood', 'topic_relation' => 'continuation', 'goal' => 'inspect a member in the displayed ranking',
            'requirements' => [['id' => 'r1', 'meaning' => 'inspect the second member', 'fields' => [$field],
                'values' => [$field => ['view' => $view, 'target' => 'single', 'ordinal' => 2]],
                'evidence' => [['message_id' => 'current', 'quote' => '第二名详情']]]]];
        $card = $invoke($m, '第二名详情', $members);
        foreach (['suggestion_1' => 2, 'suggestion_2' => 4] as $option => $expected) {
            $candidate = $card['_semantic_recommendations'][$option] ?? [];
            ocCheck(($candidate['kind'] ?? '') === 'member_detail'
                && ($candidate['member_detail']['selection_ref'] ?? '') === 'member:' . $expected
                && ($candidate['member_detail']['view'] ?? '') === $view,
                $field . '/' . $view . '/' . $option . ' retains member identity and requested detail mode');
            ocCheck(($candidate['member_detail']['source_query'] ?? []) === $members['query']
                && ($candidate['member_detail']['source_view_ref'] ?? '') === $members['view']['read_consistency_ref'],
                $field . '/' . $view . '/' . $option . ' remains tied to its original signed query/view');
        }
    }
} catch (Throwable $error) { ocCheck(false, 'helper contract unexpected ' . $error->getMessage()); }
finally { $h->close(); }

foreach ($failures as $failure) fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
echo json_encode(['suite' => 'OPT3-ordinal-clarification-boundary', 'checks' => $checks,
    'failures' => $failures, 'pass' => $failures === []], JSON_UNESCAPED_UNICODE) . PHP_EOL;
if ($failures !== []) exit(1);
