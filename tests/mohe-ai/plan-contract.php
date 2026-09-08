<?php

// Offline tests of real production contract classes. No framework/bootstrap/DB.
$backend = dirname(__DIR__, 2) . '/后端代码';
require_once $backend . '/app/services/ai/contract/AiContractException.php';
require_once $backend . '/app/services/ai/contract/AiStrictJson.php';
require_once $backend . '/app/services/ai/contract/AiQueryPlanInputValidator.php';

use app\services\ai\contract\AiContractException;
use app\services\ai\contract\AiStrictJson;
use app\services\ai\contract\AiQueryPlanInputValidator;

$passed = 0;
$failed = 0;
function planCheck(string $name, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . "\n";
}
function planReject(string $name, callable $call, string $expected = ''): void
{
    try {
        $call();
        planCheck($name, false);
    } catch (AiContractException $e) {
        planCheck($name, $expected === '' || $expected === $e->reason());
    } catch (\Throwable $e) {
        planCheck($name . ' (unexpected exception class)', false);
    }
}
function planFixture(): array
{
    $owner = ['instance_id' => 'fixture-instance', 'account_id' => 'fixture-account', 'terminal' => 'store', 'conversation_id' => 'fixture-conversation', 'run_id' => 'fixture-run', 'capability_snapshot_ref' => 'fixture-snapshot'];
    $context = $owner + ['now' => 1000];
    $set = [
        'candidate_set_ref' => 'fixture-candidate-set', 'owner' => $owner, 'expires_at' => 2000,
        'workflows' => ['fixture-workflow' => [
            'metric_codes' => ['cash_performance', 'consume_amount'],
            'query_shape' => 'summary', 'output_formats' => ['screen'],
            'date_presets' => ['TODAY' => ['start_date' => '2026-09-08', 'end_date' => '2026-09-08']],
            'max_date_span_days' => 31, 'coverage_start_date' => '2026-01-01', 'coverage_end_date' => '2026-12-31',
        ]],
    ];
    $plan = [
        'schema_version' => 'mohe-plan-v2', 'decision' => 'run_workflow',
        'candidate_set_ref' => 'fixture-candidate-set', 'conversation_relation_hint' => 'new_topic',
        'workflow_ref' => 'fixture-workflow',
        'inputs' => [
            'metric_codes' => ['cash_performance'],
            'time_range' => ['mode' => 'preset', 'preset_code' => 'TODAY', 'explicit_start_date' => null, 'explicit_end_date' => null],
            'compare_time_range' => null,
            'requested_scope' => ['mode' => 'current_report_permission', 'organization_refs' => [], 'store_refs' => [], 'employee_refs' => [], 'scope_selection_ref' => null],
            'dimension_code' => null, 'direction_code' => null, 'limit' => null, 'drill_context_ref' => null,
            'output_format_code' => 'screen',
        ],
        'clarification' => null,
    ];
    return [$plan, $context, $set];
}
function validateFixture(array $plan, array $context, array $set): array
{
    return (new AiQueryPlanInputValidator())->validate(json_encode($plan, JSON_UNESCAPED_UNICODE), $context, $set);
}

try {
    foreach (['{"x":1,"x":2}', '{"x":1,"\\u0078":2}', '{"outer":{"z":0,"z":1}}'] as $i => $json) {
        planReject('duplicate or escaped duplicate key ' . $i, function () use ($json): void { AiStrictJson::decodeObject($json); }, 'AI_JSON_DUPLICATE_KEY');
    }
    foreach (['[]', 'null', '"hello"', '1', '{} {}', '{"x":01}', '{"x":1,}', '{"x":[1,]}', '{"x":tru}', '{"x":"\\q"}', '{"x":"\\ud800"}', '{"\\u0000x":1}', '{"x":' . "\xFF" . '}', '{"x":"unterminated}'] as $i => $json) {
        planReject('malformed/root type JSON ' . $i, function () use ($json): void { AiStrictJson::decodeObject($json); });
    }
    foreach (['1.0', '1e2', '92233720368547758080', '-92233720368547758080'] as $number) {
        planReject('float or overflowing integer ' . $number, function () use ($number): void { AiStrictJson::decodeObject('{"x":' . $number . '}'); }, 'AI_JSON_INTEGER_REQUIRED');
    }
    planReject('JSON byte limit', function (): void { AiStrictJson::decodeObject('{"x":"' . str_repeat('a', 65536) . '"}'); });
    planReject('JSON depth limit', function (): void { AiStrictJson::decodeObject('{"x":' . str_repeat('[', 34) . '0' . str_repeat(']', 34) . '}'); });
    planReject('JSON node limit', function (): void { AiStrictJson::decodeObject('{"x":[' . implode(',', array_fill(0, 4096, '0')) . ']}'); });
    planCheck('canonical key order', AiStrictJson::canonicalEncode(AiStrictJson::decodeObject('{"b":2,"a":{"z":false,"x":null}}')) === '{"a":{"x":null,"z":false},"b":2}');
    planCheck('empty object differs from empty list', AiStrictJson::canonicalEncode(AiStrictJson::decodeObject('{"a":{},"b":[]}')) === '{"a":{},"b":[]}');
    planCheck('Unicode text and escape decoded safely', AiStrictJson::decodeObject('{"中文":"a\\"b\\\\c"}')->{'中文'} === "a\"b\\c");
    planReject('canonical float refused', function (): void { AiStrictJson::canonicalEncode((object)['value' => 1.2]); });
    planReject('ambiguous associative PHP array refused', function (): void { AiStrictJson::canonicalEncode(['value' => 1]); });
    planReject('external object with NUL key rejected safely', function (): void { AiStrictJson::canonicalEncode((object)["\0x" => 1]); });
    foreach ([['metric_codes', [true]], ['metric_codes', ['cash_performance', 'cash_performance']], ['output_formats', ['csv']], ['output_formats', ['screen', 'screen']], ['unknown', true], ['date_presets', ['UNUSED' => 'broken']], ['date_presets', ['TODAY' => ['start_date' => '2026-09-08', 'end_date' => '2026-09-08', 'sql' => 'forged']]]] as $change) {
        list($p, $c, $s) = planFixture();
        $s['workflows']['fixture-workflow'][$change[0]] = $change[1];
        planReject('malformed candidate metadata ' . json_encode($change), function () use ($p, $c, $s): void { validateFixture($p, $c, $s); }, 'AI_PLAN_CANDIDATE_INVALID');
    }

    list($plan, $context, $set) = planFixture();
    $result = validateFixture($plan, $context, $set);
    planCheck('Q001/Q002 current-report cash input accepted but not authorized', $result['metric_codes'] === ['cash_performance'] && $result['requires_authoritative_compile'] === true);
    planCheck('TODAY frozen from trusted report date, not system date', $result['time_range'] === ['start_date' => '2026-09-08', 'end_date' => '2026-09-08']);
    $plan['inputs']['metric_codes'] = ['consume_amount'];
    planCheck('Q003 consumption input preserved', validateFixture($plan, $context, $set)['metric_codes'] === ['consume_amount']);
    $plan['inputs']['metric_codes'] = ['cash_performance', 'consume_amount'];
    planCheck('all requested metrics preserved', count(validateFixture($plan, $context, $set)['metric_codes']) === 2);
    foreach (['sql', 'dao', 'tool_calls', 'budget', 'source_type', 'instance_id', 'card_ref'] as $key) {
        list($p, $c, $s) = planFixture();
        $p[$key] = 'forged';
        planReject('root injection ' . $key, function () use ($p, $c, $s): void { validateFixture($p, $c, $s); }, 'AI_PLAN_FIELDS_INVALID');
    }
    foreach (['business_filter_refs', 'formula', 'cash_amount', 'scope_provider_code'] as $key) {
        list($p, $c, $s) = planFixture();
        $p['inputs'][$key] = [];
        planReject('unregistered input ' . $key, function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    }
    foreach (['instance_id', 'account_id', 'terminal', 'conversation_id', 'run_id', 'capability_snapshot_ref'] as $key) {
        list($p, $c, $s) = planFixture();
        $s['owner'][$key] = 'another-owner';
        planReject('candidate ownership ' . $key, function () use ($p, $c, $s): void { validateFixture($p, $c, $s); }, 'AI_PLAN_OWNER_MISMATCH');
    }
    foreach (['candidate_set_ref', 'workflow_ref'] as $key) {
        list($p, $c, $s) = planFixture(); $p[$key] = 'old-ref';
        planReject('unknown reference ' . $key, function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    }
    list($p, $c, $s) = planFixture(); $s['expires_at'] = $c['now'];
    planReject('exact expiry rejected', function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    list($p, $c, $s) = planFixture(); $c['now'] = '1000';
    planReject('clock type not coerced', function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    foreach ([[], ['cash_performance', 'cash_performance'], ['actual_performance'], ['耗卡业绩'], [true]] as $metrics) {
        list($p, $c, $s) = planFixture(); $p['inputs']['metric_codes'] = $metrics;
        planReject('invalid/unknown/duplicate metrics ' . json_encode($metrics), function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    }
    foreach (['store_refs', 'employee_refs', 'organization_refs'] as $key) {
        list($p, $c, $s) = planFixture(); $p['inputs']['requested_scope'][$key] = ['forged-object'];
        planReject('unbound scope ' . $key, function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    }
    list($p, $c, $s) = planFixture(); $p['inputs']['output_format_code'] = 'screen_and_xlsx';
    planReject('Excel missing registered branch not silently screen', function () use ($p, $c, $s): void { validateFixture($p, $c, $s); }, 'AI_PLAN_FORMAT_UNAVAILABLE');
    list($p, $c, $s) = planFixture(); $p['inputs']['drill_context_ref'] = 'old-drill';
    planReject('drill disabled', function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    foreach ([['2026-02-29', '2026-03-01'], ['2026-09-09', '2026-09-08'], ['2025-12-31', '2026-01-01'], ['2026-01-01', '2026-03-01'], ['2026-9-01', '2026-09-08']] as $dates) {
        list($p, $c, $s) = planFixture(); $p['inputs']['time_range'] = ['mode' => 'explicit', 'preset_code' => null, 'explicit_start_date' => $dates[0], 'explicit_end_date' => $dates[1]];
        planReject('date validity/coverage/span ' . implode('/', $dates), function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    }
    list($p, $c, $s) = planFixture(); $p['inputs']['time_range'] = ['mode' => 'explicit', 'preset_code' => null, 'explicit_start_date' => '2026-09-01', 'explicit_end_date' => '2026-09-08'];
    planCheck('explicit valid date remains unchanged', validateFixture($p, $c, $s)['time_range']['start_date'] === '2026-09-01');
    list($p, $c, $s) = planFixture(); $s['workflows']['fixture-workflow']['date_presets']['TODAY'] = 'broken';
    planReject('malformed trusted date binding fails safely', function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    list($p, $c, $s) = planFixture(); $p['decision'] = 'clarify';
    planReject('unsupported clarification branch cannot execute as query', function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    list($p, $c, $s) = planFixture(); $s['workflows']['fixture-workflow']['query_shape'] = 'ranking';
    $p['inputs']['dimension_code'] = 'store'; $p['inputs']['direction_code'] = 'top_and_bottom'; $p['inputs']['limit'] = 5;
    planCheck('registered store ranking shape', validateFixture($p, $c, $s)['limit'] === 5);
    foreach (['5', 0, 21, true] as $limit) {
        $p['inputs']['limit'] = $limit;
        planReject('ranking limit strict ' . json_encode($limit), function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    }
    list($p, $c, $s) = planFixture(); $s['workflows']['fixture-workflow']['query_shape'] = 'comparison';
    planReject('comparison requires second range', function () use ($p, $c, $s): void { validateFixture($p, $c, $s); });
    $p['inputs']['compare_time_range'] = $p['inputs']['time_range'];
    planCheck('comparison keeps both explicit obligations', validateFixture($p, $c, $s)['compare_time_range'] !== null);
} catch (\Throwable $e) {
    planCheck('unexpected suite exception ' . get_class($e) . ':' . $e->getMessage(), false);
}
echo "PLAN_CONTRACT_PASS={$passed}\nPLAN_CONTRACT_FAIL={$failed}\n";
exit($failed === 0 ? 0 : 1);
