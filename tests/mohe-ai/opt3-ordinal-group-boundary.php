<?php
declare(strict_types=1);

/** Ranking positions belong to one displayed group. Pure local/model detail
 * cannot concatenate two boards; explicit group references and whole sets
 * retain their existing behavior. Synthetic only: no application or DB. */
require __DIR__ . '/fixture-autoload.php';
use app\services\ai\AiGatewayServices;
use app\services\ai\context\ObjectDetailContinuationResolver;
use app\services\ai\context\MemberDetailContinuationResolver;
use app\services\ai\context\ResultReferenceResolver;

$gateway = (new ReflectionClass(AiGatewayServices::class))->newInstanceWithoutConstructor();
$local = new ReflectionMethod($gateway, 'localVerifiedOrdinalDetailUnderstanding');
$compile = new ReflectionMethod($gateway, 'compileObjectDetailContinuation');
if (PHP_VERSION_ID < 80100) { $local->setAccessible(true); $compile->setAccessible(true); }
$checks = 0; $failures = [];
$check = static function (bool $ok, string $label) use (&$checks, &$failures): void { ++$checks; if (!$ok) $failures[] = $label; };
$rejects = static function (callable $call): bool {
    try { $call(); } catch (RuntimeException $e) {
        return in_array($e->getMessage(), ['AI_RESULT_REFERENCE_UNAVAILABLE', 'AI_OBJECT_DETAIL_SELECTION_REQUIRED', 'AI_MEMBER_DETAIL_SELECTION_REQUIRED'], true);
    }
    return false;
};
$source = static function (string $kind): array {
    $query = ['query_shape' => 'ranking', 'metric_codes' => [$kind === 'person' ? 'staff_sales_yeji' : 'cash_performance'],
        'start_date' => '2026-10-01', 'end_date' => '2026-10-11', 'compare_range' => null,
        'ranking' => ['direction' => 'top_and_bottom', 'limit' => 2], 'ranking_presentation_metrics' => [],
        'store_ids' => [11, 12, 21, 22], 'business_filters' => ['object_kind' => $kind], 'aggregate_condition' => null];
    $rows = [];
    foreach (['top' => [11, 12], 'bottom' => [21, 22]] as $group => $ids) foreach ($ids as $id) {
        $point = ['amount_cents' => $id * 100];
        if ($kind === 'store') $point += ['store_id' => $id, 'store_name' => 'fixture-' . $id];
        elseif ($kind === 'person') $point += ['employee_id' => $id, 'employee_name' => 'fixture-' . $id];
        else $point += ['member_id' => $id, 'member_name' => 'fixture-' . $id];
        $rows[$group][] = $point;
    }
    return ['query' => $query, 'view' => ['query' => $query, 'results' => [['object_kind' => $kind, 'rows' => $rows]]]];
};
$detail = static fn(int $ordinal): array => ['view' => 'summary', 'target' => 'single', 'ordinal' => $ordinal];
$resolver = new ObjectDetailContinuationResolver();
foreach (['store', 'person', 'member'] as $kind) {
    $context = $source($kind);
    foreach ([2 => '第二名详情', 3 => '第三名详情'] as $ordinal => $question) {
        $safe = ['question' => $question, 'prior_query' => ['operation' => 'ranking'],
            'evidence_messages' => [['id' => 'current', 'text' => $question]]];
        $pending = $local->invoke($gateway, $safe, ['local_conditions' => []], $context);
        $check(($pending['requirements'][0]['values']['object_detail'] ?? null) === $detail($ordinal)
            && !isset($pending['requirements'][0]['values']['result_reference']),
            $kind . ' local ungrouped ' . $ordinal . ' retains only ordinal meaning for board clarification');
        $meaning = ['status' => 'understood', 'requirements' => [['id' => 'r1', 'fields' => ['object_detail'],
            'values' => ['object_detail' => $detail($ordinal)]]]];
        $check($rejects(static function () use ($compile, $gateway, $meaning, $context): void { $compile->invoke($gateway, $meaning, $context, 'screen'); }),
            $kind . ' model detail ' . $ordinal . ' must retain the same group ambiguity guard');
    }
    // One answer can also contain two independent ranked results. Array order
    // is not an authority to reinterpret their two second places as one list.
    $separate = $context; $second = $separate['view']['results'][0];
    $separate['view']['results'][0]['rows'] = ['top' => $context['view']['results'][0]['rows']['top']];
    $second['rows'] = ['top' => $context['view']['results'][0]['rows']['bottom']];
    $separate['view']['results'][] = $second;
    $check($rejects(static function () use ($resolver, $separate, $detail): void { $resolver->resolve($separate['query'], $separate['view'], $detail(2)); }),
        $kind . ' multiple ranked results do not supply an implicit board owner');
    foreach (['top' => 12, 'bottom' => 22] as $group => $expected) {
        $single = $context;
        $single['query']['ranking']['direction'] = $group;
        $single['view']['query'] = $single['query'];
        $single['view']['results'][0]['rows'] = [$group => $context['view']['results'][0]['rows'][$group]];
        $selected = $resolver->resolve($single['query'], $single['view'], $detail(2));
        $actual = $kind === 'store' ? ($selected['store_id'] ?? null) : ($selected['selection_ref'] ?? null);
        $check($actual === ($kind === 'store' ? $expected : $kind . ':' . $expected), $kind . ' one ' . $group . ' board keeps its second row');
        if ($kind !== 'member') {
            $explicit = ResultReferenceResolver::resolve($context['query'], $context['view'], ['group' => $group, 'ordinal' => 2]);
            $check($kind === 'store' ? ($explicit['store_ids'] ?? null) === [$expected]
                : ($explicit['business_filters']['selection_ref'] ?? null) === 'person:' . $expected,
                $kind . ' explicit ' . $group . ' result reference remains exact');
        }
    }
    $set = $resolver->resolve($context['query'], $context['view'], ['view' => 'summary', 'target' => 'set', 'ordinal' => null]);
    $check(count($set[$kind === 'member' ? 'members' : 'objects'] ?? []) === 4, $kind . ' whole displayed set is still allowed without ordinal');
}
// Compatibility member_detail calls the member resolver directly; it must
// enforce the same rule before flattened rows are used as a numbered list.
$members = $source('member');
$check($rejects(static function () use ($members, $detail): void {
    (new MemberDetailContinuationResolver())->resolve($members['query'], $members['view'], $detail(3));
}), 'direct legacy member resolver never maps third place to bottom first');
foreach ($failures as $failure) fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
echo json_encode(['suite' => 'OPT3-ordinal-group-boundary', 'checks' => $checks, 'failed' => count($failures), 'pass' => $failures === []]) . PHP_EOL;
if ($failures !== []) exit(1);
