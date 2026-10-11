<?php
declare(strict_types=1);

/** A complete model-owned condition is independent of its advisory status.
 * Real Gateway/SQLite contracts run against synthetic fact facades only;
 * this fixture never loads application configuration or a business database.
 * Negative cases protect meaning coverage, private scope and topic ownership.
 */
namespace think\facade {
    final class Db {
        public static function table($table) { return new \Opt3TypedConditionFacts(); }
        public static function query($sql) { return [['qualified_count' => '1']]; }
    }
}
namespace {
require __DIR__ . '/r6-gateway-harness.php';

final class Opt3TypedConditionFacts extends R6Facts {
    public static $scopes = [];
    public function __call($name, $args) {
        if ($name === 'whereIn' && preg_match('/(?:^|\.)store_id$/D', (string)($args[0] ?? '')))
            self::$scopes[] = $args[1];
        return parent::__call($name, $args);
    }
    public function buildSql($wrap = true) { return 'SELECT 101 member_id,12345 metric_value'; }
    public function count($column = '*') { return 1; }
    public function toArray() {
        return [['store_id' => '1', 'uid' => '101', 'member_id' => '101', 'member_name' => 'fixture member',
            'member_name_snapshot' => 'fixture member', 'entity_id' => '101',
            'entity_name' => 'fixture member', 'metric_value' => '12345', 'metric_values' => 'cash_performance:12345']];
    }
}
$checks = 0; $failures = []; $observations = [];
function tcCheck(bool $ok, string $label): void {
    global $checks, $failures; ++$checks; if (!$ok) $failures[] = $label;
}
function tcProperty($object, string $name): ReflectionProperty {
    $p = new ReflectionProperty($object, $name);
    if (PHP_VERSION_ID < 80100) $p->setAccessible(true);
    return $p;
}
function tcHarness(): R6GatewayHarness {
    Opt3TypedConditionFacts::$scopes = [];
    $h = new R6GatewayHarness(5, [1], 'platform');
    tcProperty($h->gateway, 'queryTransaction')->setValue($h->gateway, static function ($call) use ($h) {
        ++$h->queries;
        return $call(new \app\services\query\metric\GroupPerformanceMetricReadServices(
            static function () { return new Opt3TypedConditionFacts(); }, static function () {}
        ));
    });
    return $h;
}
function tcMeaning(string $question, string $form = 'count', string $status = 'needs_clarification'): array {
    return ['goal' => 'count or list the explicitly constrained member population', 'status' => $status,
        'requirements' => [['id' => 'r1', 'meaning' => 'all stated condition slots',
            'fields' => ['metric_codes', 'object_kind', 'object_relation', 'operation', 'periods', 'aggregate_condition'],
            'values' => ['metric_terms' => ['消费'], 'object_kind' => 'member', 'object_relation' => 'analysis',
                'operation' => 'condition_' . $form,
                'periods' => [tcPeriod()],
                'aggregate_condition' => ['subject' => 'member', 'relation' => 'all', 'result_form' => $form,
                    'conditions' => [['metric_term' => '消费', 'operator' => 'lt', 'quantity' => '500', 'unit' => 'yuan']]]],
            'evidence' => [['message_id' => 'current', 'quote' => $question]]]]];
}
function tcPeriod(): array {
    $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
    return ['kind' => 'date_range', 'start' => $today->format('Y-m-01'), 'end' => $today->format('Y-m-d')];
}
function tcQuery(R6GatewayHarness $h, array $run): ?array {
    if (($run['status'] ?? '') !== 'COMPLETED') return null;
    $evidence = $h->private->read($h->row($run)['evidence_ref']);
    $views = new \app\services\query\metric\MetricReadViewStore($h->temp . '/views', $h->private->signingKey());
    return $views->get($evidence['view_ref'])['query'];
}
function tcModelStages(R6GatewayHarness $h, array $run): array {
    if (!isset($run['run_id'])) return [];
    return $h->db->query('SELECT attempt_code FROM mohe_ai_attempt WHERE run_id='
        . $h->db->quote($run['run_id']) . " AND kind='model' ORDER BY attempt_code")->fetchAll(\PDO::FETCH_COLUMN);
}
/** A same-conversation date edit reuses the signed verified population. */
function tcCheckPeriodContinuation(R6GatewayHarness $h, array $run, array $source, string $label): void {
    $models = $h->models;
    $next = $h->start('上个月呢', $run['answer']['context_ref']); $query = tcQuery($h, $next);
    tcCheck($query !== null, $label . ' accepts last-month continuation');
    if ($query === null) return;
    $lastMonth = (new \DateTimeImmutable(tcPeriod()['end']))->modify('first day of last month');
    tcCheck($query['query_shape'] === $source['query_shape'] && $query['metric_codes'] === $source['metric_codes']
        && $query['condition_set'] === $source['condition_set'] && $query['aggregate_condition'] === $source['aggregate_condition'],
        $label . ' continuation retains all predicates, comparators, amounts and response form');
    tcCheck($query['start_date'] === $lastMonth->format('Y-m-01') && $query['end_date'] === $lastMonth->format('Y-m-t'),
        $label . ' continuation changes only the requested month');
    tcCheck($h->models === $models, $label . ' closed calendar continuation makes no extra model request');
}

// Positive/control pairs differ only in the advisory status. The requested
// period and every monetary comparator must survive without a second model
// selecting a different metric or turning the predicate into a summary.
foreach (['count', 'list'] as $form) foreach (['understood', 'needs_clarification'] as $status) {
    $h = tcHarness(); $label = $form . '/' . $status;
    try {
        $question = $form === 'count' ? '本月消费低于500的客户有几个' : '请列出本月消费低于500的客户';
        $h->understandingOverride = tcMeaning($question, $form, $status);
        $run = $h->start($question); $query = tcQuery($h, $run);
        $observations[$label] = ['status' => $run['status'] ?? '', 'reason' => $run['reason'] ?? '',
            'queries' => $h->queries, 'model_stages' => tcModelStages($h, $run), 'fixture_query' => $query];
        tcCheck($query !== null, $label . ' directly answers complete typed meaning');
        if ($query === null) continue;
        tcCheck($query['query_shape'] === 'condition_' . $form && $query['metric_codes'] === ['cash_performance'],
            $label . ' keeps registered metric and requested result form');
        tcCheck($query['start_date'] === tcPeriod()['start'] && $query['end_date'] === tcPeriod()['end'],
            $label . ' preserves explicit time');
        tcCheck(Opt3TypedConditionFacts::$scopes !== []
            && count(array_filter(Opt3TypedConditionFacts::$scopes, static function ($ids): bool { return $ids !== [1]; })) === 0,
            $label . ' Reader constrains every fact branch to authenticated stores');
        tcCheck(($query['condition_set']['conditions'][0] ?? []) === [
            'metric_code' => 'cash_performance', 'operator' => 'lt', 'value' => 50000],
            $label . ' preserves open comparator and exact cents');
        tcCheck(tcModelStages($h, $run) === ['understand_meaning'],
            $label . ' does not ask binding or recommendation model to restate proven slots');
        if ($status === 'needs_clarification') tcCheckPeriodContinuation($h, $run, $query, $label);
    } catch (\Throwable $error) { tcCheck(false, $label . ' unexpected ' . $error->getMessage()); }
    finally { $h->close(); }
}

// Multiple registered conditions keep their Boolean relation and positional
// metric ownership. Decimal cents are checked before a Reader can run them.
foreach (['all', 'any'] as $relation) foreach (['understood', 'needs_clarification'] as $status) {
    $h = tcHarness(); $label = $relation . '/two-conditions/' . $status;
    try {
        $question = '请统计本月消费低于499.50元' . ($relation === 'all' ? '且' : '或') . '实际收款销售额大于100元的客户有几个';
        $meaning = tcMeaning($question, 'count', $status);
        $values = &$meaning['requirements'][0]['values'];
        $values['metric_terms'][] = '实际收款销售额';
        $values['aggregate_condition']['relation'] = $relation;
        $values['aggregate_condition']['conditions'][0]['quantity'] = '499.50';
        $values['aggregate_condition']['conditions'][] = ['metric_term' => '实际收款销售额',
            'operator' => 'gt', 'quantity' => '100', 'unit' => 'yuan'];
        unset($values);
        $h->understandingOverride = $meaning; $run = $h->start($question); $query = tcQuery($h, $run);
        $observations[$label] = ['status' => $run['status'] ?? '', 'reason' => $run['reason'] ?? '',
            'queries' => $h->queries, 'model_stages' => tcModelStages($h, $run)];
        tcCheck($query !== null, $label . ' executes the whole complete condition set');
        if ($query === null) continue;
        tcCheck($query['condition_set']['subject'] === 'member' && $query['condition_set']['relation'] === $relation
            && $query['condition_set']['conditions'] === [
            ['metric_code' => 'cash_performance', 'operator' => 'lt', 'value' => 49950],
            ['metric_code' => 'sales_collected_amount', 'operator' => 'gt', 'value' => 10000]],
            $label . ' keeps relation, condition order, comparators and cents');
        tcCheck(tcModelStages($h, $run) === ['understand_meaning'], $label . ' uses no extra model decision');
        if ($status === 'needs_clarification') tcCheckPeriodContinuation($h, $run, $query, $label);
    } catch (\Throwable $error) { tcCheck(false, $label . ' unexpected ' . $error->getMessage()); }
    finally { $h->close(); }
}

// Preserve the original redundant-slot cases as positive controls. Existing
// UnderstandingContract projects subject from the complete condition and
// grounds its response form in the explicit current request before preflight.
foreach (['redundant unknown object', 'redundant result form'] as $label) {
    $h = tcHarness();
    try {
        $question = '本月消费低于500的客户有几个'; $meaning = tcMeaning($question);
        if ($label === 'redundant unknown object') $meaning['requirements'][0]['values']['object_kind'] = 'unknown';
        else $meaning['requirements'][0]['values']['aggregate_condition']['result_form'] = 'list';
        $h->understandingOverride = $meaning; $run = $h->start($question); $query = tcQuery($h, $run);
        tcCheck($query !== null && $query['query_shape'] === 'condition_count'
            && $query['condition_set']['subject'] === 'member' && $query['aggregate_condition']['result_form'] === 'count',
            $label . ' follows existing grounded semantic normalization');
        if ($query !== null) tcCheck($query['condition_set']['conditions'] === [
            ['metric_code' => 'cash_performance', 'operator' => 'lt', 'value' => 50000]],
            $label . ' does not change the independently valid monetary predicate');
    } catch (\Throwable $error) { tcCheck(false, $label . ' unexpected ' . $error->getMessage()); }
    finally { $h->close(); }
}

// A complete new topic may close the prior query, but it may not borrow its
// day, metric or selection. The original accepted relation remains visible.
$h = tcHarness();
try {
    $source = $h->start('昨天现金业绩多少');
    $question = '本月消费低于500的客户有几个';
    $h->understandingOverride = tcMeaning($question);
    $h->understandingOverride['topic_relation'] = 'new_topic';
    $run = $h->start($question, $source['answer']['context_ref']);
    $query = tcQuery($h, $run);
    tcCheck($query !== null && ($run['topic_boundary'] ?? '') === 'reset', 'complete new topic answers after accepted reset');
    if ($query !== null) tcCheck($query['start_date'] === tcPeriod()['start'] && $query['end_date'] === tcPeriod()['end'],
        'new topic never inherits yesterday from its predecessor');
} catch (\Throwable $error) { tcCheck(false, 'new topic unexpected ' . $error->getMessage()); }
finally { $h->close(); }

// Existing context is not permission to treat an unclear relation as a
// self-contained query. This optimization is limited to fresh/confirmed-new
// meaning until the normal signed continuation machinery has resolved it.
foreach (['continuation', 'missing relation'] as $relation) {
    $h = tcHarness();
    try {
        $source = $h->start('昨天现金业绩多少'); $before = $h->queries;
        $question = '本月消费低于500的客户有几个';
        $h->understandingOverride = tcMeaning($question);
        if ($relation === 'continuation') $h->understandingOverride['topic_relation'] = $relation;
        $run = $h->start($question, $source['answer']['context_ref']);
        tcCheck(($run['status'] ?? '') !== 'COMPLETED' && $h->queries === $before,
            $relation . ' is not silently converted into an independent complete query');
        tcCheck(!isset($run['topic_boundary']), $relation . ' does not discard the verified topic');
    } catch (\Throwable $error) { tcCheck(false, $relation . ' unexpected ' . $error->getMessage()); }
    finally { $h->close(); }
}

// Authority can change after language understanding. A local completeness
// proof must still pass the established final fresh-authority fence.
$h = tcHarness();
try {
    $modelProperty = tcProperty($h->gateway, 'model'); $originalModel = $modelProperty->getValue($h->gateway);
    $modelProperty->setValue($h->gateway, static function (...$args) use ($h, $originalModel) {
        $reply = $originalModel(...$args);
        if (($args[5] ?? 'binding') === 'understanding') $h->auth['permission_version'] = 'changed-after-understanding';
        return $reply;
    });
    $question = '本月消费低于500的客户有几个'; $h->understandingOverride = tcMeaning($question);
    $run = $h->start($question);
    tcCheck(($run['status'] ?? '') !== 'COMPLETED' && $h->queries === 0,
        'permission change after understanding blocks all facts');
} catch (\Throwable $error) { tcCheck(false, 'permission change unexpected ' . $error->getMessage()); }
finally { $h->close(); }

// These are semantic/authority exclusions, not alternate phrases to parse.
// A narrow complete-condition proof must decline the entire request rather
// than dropping an unsupported carrier and reading a reduced population.
$mutations = [
    'missing period' => static function (array &$m): void {
        $m['requirements'][0]['fields'] = array_values(array_diff($m['requirements'][0]['fields'], ['periods']));
        unset($m['requirements'][0]['values']['periods']);
    },
    'unknown additional typed field' => static function (array &$m): void {
        $m['requirements'][0]['fields'][] = 'future_condition';
        $m['requirements'][0]['values']['future_condition'] = 'must not vanish';
    },
    'unknown object' => static function (array &$m): void {
        // The existing understanding contract projects a condition's own
        // subject onto its redundant object field. An independent unresolved
        // object requirement must nevertheless remain a blocker.
        $m['requirements'][] = ['id' => 'r2', 'meaning' => 'another unresolved object',
            'fields' => ['object_kind'], 'values' => ['object_kind' => 'unknown'],
            'evidence' => $m['requirements'][0]['evidence']];
    },
    'unanchored model evidence' => static function (array &$m): void {
        $m['requirements'][0]['evidence'] = [['message_id' => 'current', 'quote' => 'a model-invented excerpt']];
    },
    'conflicting period requirement' => static function (array &$m): void {
        $m['requirements'][] = ['id' => 'r2', 'meaning' => 'conflicting independent period',
            'fields' => ['periods'], 'values' => ['periods' => [['kind' => 'date_range', 'start' => '2026-08-01', 'end' => '2026-08-31']]],
            'evidence' => $m['requirements'][0]['evidence']];
    },
    'private object selection' => static function (array &$m): void {
        $m['requirements'][0]['values']['object_relation'] = 'selection';
    },
    'unsupported condition unit' => static function (array &$m): void {
        $m['requirements'][0]['values']['aggregate_condition']['conditions'][0]['unit'] = 'count';
    },
    'inconsistent result form' => static function (array &$m): void {
        $m['requirements'][] = ['id' => 'r2', 'meaning' => 'independent conflicting response form',
            'fields' => ['operation'], 'values' => ['operation' => 'condition_list'],
            'evidence' => $m['requirements'][0]['evidence']];
    },
    'independent result groups' => static function (array &$m): void {
        $m['requirements'][] = ['id' => 'r2', 'meaning' => 'an independent store request',
            'fields' => ['object_kind', 'object_relation', 'operation'],
            'values' => ['object_kind' => 'store', 'object_relation' => 'analysis', 'operation' => 'summary'],
            'evidence' => $m['requirements'][0]['evidence']];
        $m['groups'] = [['id' => 'q1', 'requirement_ids' => ['r1']], ['id' => 'q2', 'requirement_ids' => ['r2']]];
    },
    'private previous result reference' => static function (array &$m): void {
        $m['requirements'][0]['fields'][] = 'result_reference';
        $m['requirements'][0]['values']['result_reference'] = ['group' => 'top', 'ordinal' => 1];
    },
    'extra metric requirement' => static function (array &$m): void {
        $m['requirements'][] = ['id' => 'r2', 'meaning' => 'another measurement must not vanish',
            'fields' => ['metric_codes'], 'values' => ['metric_terms' => ['客户消耗金额']],
            'evidence' => $m['requirements'][0]['evidence']];
    },
];
foreach ($mutations as $label => $mutate) {
    $h = tcHarness();
    try {
        $question = $label === 'missing period' ? '消费低于500的客户有几个' : '本月消费低于500的客户有几个';
        if ($label === 'extra metric requirement') $question .= '，也要客户消耗金额';
        if ($label === 'private previous result reference') $question .= '，取第一名';
        $meaning = tcMeaning($question); $mutate($meaning); $h->understandingOverride = $meaning;
        $run = $h->start($question);
        $observations['guard/' . $label] = ['status' => $run['status'] ?? '', 'reason' => $run['reason'] ?? '',
            'queries' => $h->queries, 'model_stages' => tcModelStages($h, $run)];
        tcCheck(($run['status'] ?? '') !== 'COMPLETED' && $h->queries === 0,
            $label . ' never executes a reduced query');
    } catch (\Throwable $error) { tcCheck(false, $label . ' unexpected ' . $error->getMessage()); }
    finally { $h->close(); }
}

foreach ($failures as $failure) fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
echo json_encode(['suite' => 'OPT3-complete-typed-condition', 'checks' => $checks, 'failures' => $failures,
    'observations' => $observations, 'pass' => $failures === []], JSON_UNESCAPED_UNICODE) . PHP_EOL;
if ($failures !== []) exit(1);
}
