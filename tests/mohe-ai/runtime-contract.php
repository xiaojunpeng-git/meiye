<?php
// Deliberately no strict_types: numeric strings must also fail from weak callers.
// No framework bootstrap, vendor autoload, environment file, database or network.
spl_autoload_register(function ($class) {
    $prefix = 'app\\services\\ai\\execution\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name)) {
        throw new RuntimeException('Unexpected test class');
    }
    require_once dirname(__DIR__, 2) . '/后端代码/app/services/ai/execution/' . $name . '.php';
});

use app\services\ai\execution\AiRunBudgetPolicy as Budget;
use app\services\ai\execution\AiCapacityPolicy as Capacity;
use app\services\ai\execution\AiFailureClassificationPolicy as Failure;
use app\services\ai\execution\AiRuntimePolicyException;

$passed = 0;
$failed = 0;
function checkRuntime($label, $condition) {
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
}
function rejectsRuntime($label, $callback, $reason) {
    try {
        $callback();
        checkRuntime($label, false);
    } catch (AiRuntimePolicyException $exception) {
        checkRuntime($label, $exception->reason() === $reason);
    } catch (Throwable $exception) {
        checkRuntime($label . ' unexpected ' . get_class($exception), false);
    }
}
function changedRuntime($input, $key, $value) {
    $input[$key] = $value;
    return $input;
}

$profile = Budget::defaults();
$initial = Budget::create($profile, 1000);
checkRuntime('default 180 seconds starts at create', $initial['execution_deadline_ms'] === 181000);
checkRuntime('300 second registered profile accepted', Budget::create(changedRuntime($profile, 'run_execution_budget_ms', 300000), 1000)['execution_deadline_ms'] === 301000);
Budget::assertPathBudget($profile, 44000, 55000);
checkRuntime('fast 55 second workflow still tighter than Run', true);
rejectsRuntime('workflow bound may not spend finalization twice', function () use ($profile) {
    Budget::assertPathBudget($profile, 180000, 180000);
}, 'WORKFLOW_PATH_BUDGET_EXCEEDED');
rejectsRuntime('workflow full path must fit declared bound', function () use ($profile) {
    Budget::assertPathBudget($profile, 55001, 55000);
}, 'WORKFLOW_PATH_BUDGET_EXCEEDED');
Budget::assertParallelism(3);
checkRuntime('three parallel tools maximum accepted', true);
rejectsRuntime('four parallel tools rejected', function () {
    Budget::assertParallelism(4);
}, 'PARALLEL_TOOL_BUDGET_EXCEEDED');
rejectsRuntime('over 300 seconds rejected', function () use ($profile) {
    Budget::validateProfile(changedRuntime($profile, 'run_execution_budget_ms', 300001));
}, 'BUDGET_PROFILE_INVALID');
foreach (['run_execution_budget_ms' => '180000', 'tool_timeout_ms' => 10000.0, 'version' => true] as $key => $value) {
    rejectsRuntime('profile scalar strict ' . $key, function () use ($profile, $key, $value) {
        Budget::validateProfile(changedRuntime($profile, $key, $value));
    }, 'POLICY_INTEGER_REQUIRED');
}
rejectsRuntime('profile unknown field rejected', function () use ($profile) {
    Budget::validateProfile($profile + ['sql' => 'not executable']);
}, 'POLICY_FIELDS_INVALID');
rejectsRuntime('weak caller clock string rejected', function () use ($profile) {
    Budget::create($profile, '1000');
}, 'CLOCK_INVALID');
rejectsRuntime('tool per-call hard limit', function () use ($profile) {
    Budget::validateProfile(changedRuntime($profile, 'tool_timeout_ms', 10001));
}, 'BUDGET_PROFILE_INVALID');
rejectsRuntime('model per-call hard limit', function () use ($profile) {
    Budget::validateProfile(changedRuntime($profile, 'model_timeout_ms', 20001));
}, 'BUDGET_PROFILE_INVALID');
rejectsRuntime('reserve cannot equal total', function () use ($profile) {
    Budget::validateProfile(changedRuntime($profile, 'finalization_reserve_ms', 180000));
}, 'BUDGET_PROFILE_INVALID');
$spent = Budget::consume($initial, 21000);
checkRuntime('queue preparation and elapsed time consume original budget', $spent['remaining_execution_ms'] === 160000 && $spent['execution_deadline_ms'] === 181000);
checkRuntime('input state is not mutated', $initial['remaining_execution_ms'] === 180000);
checkRuntime('stage tighter than global timeout', Budget::callTimeout($spent, 'tool', 6000, 22000) === 6000);
checkRuntime('remaining minus finalization bounds call', Budget::callTimeout($spent, 'model', 20000, 174000) === 2000);
$liveDeadline = Budget::create($profile, 90000);
$liveDeadline['remaining_execution_ms'] = 6500;
$liveDeadline['execution_deadline_ms'] = 96500;
checkRuntime('model transport timeout preserves finalization reserve from live deadline', Budget::callTimeout($liveDeadline, 'model', 20000, 90000) === 1500);
checkRuntime('model transport timeout never exceeds stage or configured cap', Budget::callTimeout($liveDeadline, 'model', 5000, 90000) === 1500);
checkRuntime('a tighter read-only binding stage remains within the same model policy', Budget::callTimeout($spent, 'model', 10000, 22000) === 10000);
$gatewaySource = file_get_contents(dirname(__DIR__, 2) . '/后端代码/app/services/ai/AiGatewayServices.php');
checkRuntime('gateway derives model transport timeout at each provider send boundary', is_string($gatewaySource)
    && substr_count($gatewaySource, 'modelCallTimeout($owner,$id,$generation,$worker,self::MODEL_STAGE_LIMIT_MS)') === 6
    && substr_count($gatewaySource, 'modelCallTimeout($owner,$id,$generation,$worker,self::BIND_INITIAL_STAGE_LIMIT_MS)') === 1
    && substr_count($gatewaySource, 'modelCallTimeout($owner,$id,$generation,$worker,self::BIND_RECOVERY_STAGE_LIMIT_MS)') === 1
    && strpos($gatewaySource, ',45000,$checkpoint') === false
    && strpos($gatewaySource, ',30000,$checkpoint') === false);
rejectsRuntime('no new work at reserve boundary', function () use ($spent) {
    Budget::callTimeout($spent, 'tool', 6000, 176000);
}, 'WORK_BUDGET_EXHAUSTED');
Budget::assertCanPublish($spent, 180999);
checkRuntime('finalization may use final millisecond', true);
rejectsRuntime('publish at deadline rejected', function () use ($spent) {
    Budget::assertCanPublish($spent, 181000);
}, 'PUBLICATION_TIME_EXHAUSTED');
$exhausted = Budget::consume($spent, 200000);
checkRuntime('late consume clamps to zero never extends deadline', $exhausted['remaining_execution_ms'] === 0 && $exhausted['execution_deadline_ms'] === 181000);
rejectsRuntime('clock backwards rejected', function () use ($spent) {
    Budget::consume($spent, 20000);
}, 'CLOCK_REGRESSION');
rejectsRuntime('state unknown fields rejected', function () use ($spent) {
    Budget::consume($spent + ['new_budget' => 300000], 22000);
}, 'POLICY_FIELDS_INVALID');
rejectsRuntime('state deadline tamper rejected', function () use ($spent) {
    Budget::consume(changedRuntime($spent, 'execution_deadline_ms', 999999), 22000);
}, 'BUDGET_STATE_INVALID');
rejectsRuntime('weak caller stage string rejected', function () use ($spent) {
    Budget::callTimeout($spent, 'tool', '6000', 22000);
}, 'CALL_BUDGET_INVALID');
$paused = Budget::pauseForClarification($spent, 31000);
checkRuntime('only clarification pause freezes remainder', $paused['remaining_execution_ms'] === 150000 && $paused['execution_deadline_ms'] === null && $paused['counters']['clarification_count'] === 1);
$resumed = Budget::resumeAfterClarification($paused, 331000);
checkRuntime('resume does not grant new duration', $resumed['remaining_execution_ms'] === 150000 && $resumed['execution_deadline_ms'] === 481000);
checkRuntime('pause preserves all counters', $resumed['counters'] === $paused['counters']);
$second=Budget::pauseForClarification($resumed,332000);
checkRuntime('second step shares original frozen profile', $second['counters']['clarification_count']===2 && $second['profile']['max_clarification_rounds']===3);
$third=Budget::pauseForClarification(Budget::resumeAfterClarification($second,333000),334000);
$thirdAnswered=Budget::resumeAfterClarification($third,335000);
checkRuntime('last permitted answer can execute without another step', Budget::callTimeout($thirdAnswered,'tool',10000,335001)===10000);
rejectsRuntime('fourth step exceeds default three',function()use($thirdAnswered){Budget::pauseForClarification($thirdAnswered,336000);},'COUNTER_BUDGET_EXHAUSTED');
rejectsRuntime('all clarification waits share ten minutes',function()use($second){Budget::resumeAfterClarification($second,632000);},'CLARIFICATION_EXPIRED');
rejectsRuntime('no tool during clarification', function () use ($paused) {
    Budget::callTimeout($paused, 'tool', 6000, 32000);
}, 'WAITING_CLARIFICATION');
rejectsRuntime('clarification exactly 10 minutes expires', function () use ($paused) {
    Budget::resumeAfterClarification($paused, 631000);
}, 'CLARIFICATION_EXPIRED');
rejectsRuntime('non-clarification cannot resume', function () use ($spent) {
    Budget::resumeAfterClarification($spent, 22000);
}, 'NOT_WAITING_CLARIFICATION');
rejectsRuntime('24h expiry hard boundary', function () use ($initial) {
    Budget::consume($initial, 86401000);
}, 'RUN_EXPIRED');
foreach (['tool_call_count' => 8, 'skill_execution_count' => 8, 'node_visit_count' => 12,
    'workflow_transition_count' => 16, 'supplement_count' => 1, 'stage_count' => 5,
    'model_recovery_count' => 1] as $counter => $cap) {
    $state = $initial;
    for ($i = 0; $i < $cap; $i++) {
        $state = Budget::reserve($state, $counter);
    }
    checkRuntime('counter cap reached ' . $counter, $state['counters'][$counter] === $cap);
    rejectsRuntime('counter overrun ' . $counter, function () use ($state, $counter) {
        Budget::reserve($state, $counter);
    }, 'COUNTER_BUDGET_EXHAUSTED');
}
rejectsRuntime('unknown counter rejected', function () use ($initial) {
    Budget::reserve($initial, 'extra_loop');
}, 'COUNTER_REQUEST_INVALID');
rejectsRuntime('counter cannot be decremented', function () use ($initial) {
    Budget::reserve($initial, 'tool_call_count', -1);
}, 'COUNTER_REQUEST_INVALID');
rejectsRuntime('weak caller counter string rejected', function () use ($initial) {
    Budget::reserve($initial, 'tool_call_count', '1');
}, 'COUNTER_REQUEST_INVALID');
rejectsRuntime('attempt needs registered stage', function () use ($initial) {
    Budget::reserve($initial, 'model_attempt_count');
}, 'MODEL_RECOVERY_NOT_RESERVED');
$model = Budget::reserve($initial, 'stage_count');
$model = Budget::reserve($model, 'model_attempt_count');
rejectsRuntime('attempt needs shared recovery', function () use ($model) {
    Budget::reserve($model, 'model_attempt_count');
}, 'MODEL_RECOVERY_NOT_RESERVED');
$model = Budget::reserve($model, 'model_recovery_count');
$model = Budget::reserve($model, 'failover_count');
$model = Budget::reserve($model, 'model_attempt_count');
$model = Budget::reserve($model, 'stage_count');
$model = Budget::reserve($model, 'model_attempt_count');
$model = Budget::reserve($model, 'stage_count');
$model = Budget::reserve($model, 'model_attempt_count');
$model = Budget::reserve($model, 'stage_count');
$model = Budget::reserve($model, 'model_attempt_count');
checkRuntime('one bounded recovery may receive a final independent admission review', $model['counters']['model_attempt_count'] === 5);
rejectsRuntime('sixth model attempt blocked', function () use ($model) {
    Budget::reserve($model, 'model_attempt_count');
}, 'COUNTER_BUDGET_EXHAUSTED');
foreach (['model_input_tokens' => 96000, 'model_output_tokens' => 6000] as $counter => $cap) {
    $tokenState = Budget::reserve($initial, $counter, $cap);
    rejectsRuntime('token hard cap ' . $counter, function () use ($tokenState, $counter) {
        Budget::reserve($tokenState, $counter);
    }, 'COUNTER_BUDGET_EXHAUSTED');
}

$capacityProfile = ['version' => 1, 'parent_slots' => 4, 'active_run_limit' => 8,
    'queue_wait_ms' => 10000, 'control_slots' => 1, 'design_verified' => true,
    'load_verified' => true, 'alert_thresholds_registered' => true];
$snapshot = ['profile_version' => 1, 'healthy_parent_slots' => 4, 'occupied_parent_slots' => 3,
    'reserved_parent_slots' => 0, 'active_runs' => 4, 'pending_parent_nodes' => 0,
    'available_control_slots' => 1, 'release_upper_bounds_ms' => [null, null, null]];
$request = ['remaining_execution_ms' => 180000, 'required_after_start_ms' => 57000, 'initial_wait_limit_ms' => 3000];
$admit = Capacity::decide($capacityProfile, $snapshot, $request);
checkRuntime('fifth person not mechanically rejected if physical slot available', $admit['decision'] === 'ADMIT' && $admit['latest_start_delay_ms'] === 0);
checkRuntime('policy explicitly requires actual atomic reservation', $admit['requires_atomic_reservation'] === true);
$busy = changedRuntime($snapshot, 'occupied_parent_slots', 4);
$busy['release_upper_bounds_ms'] = [null, null, null, null];
checkRuntime('unknown physical stop never freed by Run timeout', Capacity::decide($capacityProfile, $busy, $request)['reason'] === 'NO_PROVABLE_RELEASE');
$waitable = changedRuntime($busy, 'release_upper_bounds_ms', [3000, null, null, null]);
checkRuntime('provable release at short wait bound admitted', Capacity::decide($capacityProfile, $waitable, $request)['latest_start_delay_ms'] === 3000);
checkRuntime('wait beyond workflow bound rejected', Capacity::decide($capacityProfile,
    changedRuntime($busy, 'release_upper_bounds_ms', [3001, null, null, null]), $request)['decision'] === 'CAPACITY_REJECTED');
checkRuntime('full downstream path must fit', Capacity::decide($capacityProfile, $waitable,
    changedRuntime($request, 'remaining_execution_ms', 59999))['decision'] === 'CAPACITY_REJECTED');
checkRuntime('full downstream exact fit permitted', Capacity::decide($capacityProfile, $waitable,
    changedRuntime($request, 'remaining_execution_ms', 60000))['decision'] === 'ADMIT');
checkRuntime('existing recovery work protected', Capacity::decide($capacityProfile,
    changedRuntime($snapshot, 'pending_parent_nodes', 1), $request)['decision'] === 'CAPACITY_REJECTED');
checkRuntime('existing reservation not oversold', Capacity::decide($capacityProfile,
    changedRuntime($snapshot, 'reserved_parent_slots', 1), $request)['decision'] === 'CAPACITY_REJECTED');
checkRuntime('activity includes wait-for-file or clarification', Capacity::decide($capacityProfile,
    changedRuntime($snapshot, 'active_runs', 8), $request)['reason'] === 'ACTIVE_RUN_LIMIT');
checkRuntime('unverified profile remains disabled', Capacity::decide(changedRuntime($capacityProfile, 'load_verified', false),
    $snapshot, $request)['reason'] === 'CAPACITY_PROFILE_NOT_READY');
checkRuntime('capacity profile mismatch rejected', Capacity::decide($capacityProfile,
    changedRuntime($snapshot, 'profile_version', 2), $request)['reason'] === 'CAPACITY_PROFILE_CHANGED');
checkRuntime('reserved control capacity required', Capacity::decide($capacityProfile,
    changedRuntime($snapshot, 'available_control_slots', 0), $request)['reason'] === 'CONTROL_CAPACITY_UNAVAILABLE');
rejectsRuntime('capacity rejects unknown fields', function () use ($capacityProfile, $snapshot, $request) {
    Capacity::decide($capacityProfile, $snapshot + ['cancelled_frees_slot' => true], $request);
}, 'POLICY_FIELDS_INVALID');
rejectsRuntime('capacity rejects string counts', function () use ($capacityProfile, $snapshot, $request) {
    Capacity::decide($capacityProfile, changedRuntime($snapshot, 'active_runs', '4'), $request);
}, 'POLICY_INTEGER_REQUIRED');
rejectsRuntime('capacity rejects unsafe wait profile', function () use ($capacityProfile, $snapshot, $request) {
    Capacity::decide(changedRuntime($capacityProfile, 'queue_wait_ms', 10001), $snapshot, $request);
}, 'CAPACITY_PROFILE_INVALID');
rejectsRuntime('release vector must cover physical occupied slots', function () use ($capacityProfile, $snapshot, $request) {
    Capacity::decide($capacityProfile, changedRuntime($snapshot, 'release_upper_bounds_ms', []), $request);
}, 'CAPACITY_SNAPSHOT_INVALID');
rejectsRuntime('zero release not accepted as physical stop evidence', function () use ($capacityProfile, $busy, $request) {
    Capacity::decide($capacityProfile, changedRuntime($busy, 'release_upper_bounds_ms', [0, null, null, null]), $request);
}, 'POLICY_INTEGER_REQUIRED');

checkRuntime('capacity rejection neither increments nor clears', Failure::failureCounterAction('CAPACITY_REJECTED', 'CAPACITY', false) === 'unchanged');
checkRuntime('capacity stop neither increments nor clears', Failure::failureCounterAction('FAILED', 'CAPACITY_STOPPED', false) === 'unchanged');
checkRuntime('normal empty neither increments nor clears', Failure::failureCounterAction('SUCCEEDED', 'NORMAL_EMPTY', false) === 'unchanged');
checkRuntime('partial query success clears user counter', Failure::failureCounterAction('PARTIAL_SUCCEEDED', 'EXPORT_TECHNICAL_FAILURE', false) === 'clear');
checkRuntime('complete query success clears user counter', Failure::failureCounterAction('SUCCEEDED', 'NONE', false) === 'clear');
foreach (['MODEL_TECHNICAL_FAILURE', 'QUERY_TECHNICAL_FAILURE', 'EXPRESSION_TECHNICAL_FAILURE',
    'PUBLICATION_TECHNICAL_FAILURE', 'MODEL_UNKNOWN', 'TOOL_UNKNOWN'] as $cause) {
    checkRuntime('real technical fault counted ' . $cause, Failure::failureCounterAction('FAILED', $cause, false) === 'increment');
}
checkRuntime('export root cause not punished as query', Failure::failureCounterAction('FAILED', 'EXPORT_TECHNICAL_FAILURE', false) === 'unchanged');
checkRuntime('independent export never clears query counter', Failure::failureCounterAction('SUCCEEDED', 'NONE', true) === 'unchanged');
rejectsRuntime('wrong outcome cannot hide technical failure as capacity', function () {
    Failure::failureCounterAction('CAPACITY_REJECTED', 'MODEL_UNKNOWN', false);
}, 'FAILURE_CLASSIFICATION_INVALID');
rejectsRuntime('no partial independent export', function () {
    Failure::failureCounterAction('PARTIAL_SUCCEEDED', 'EXPORT_TECHNICAL_FAILURE', true);
}, 'FAILURE_CLASSIFICATION_INVALID');
rejectsRuntime('weak caller boolean rejected', function () {
    Failure::failureCounterAction('SUCCEEDED', 'NONE', 'false');
}, 'FAILURE_CLASSIFICATION_INVALID');
foreach (['CANCELLED', 'COMPLETED', 'FAILED'] as $terminal) {
    checkRuntime('capacity stop preserves terminal ' . $terminal,
        Failure::capacityStopDecision($terminal) === ['action' => 'KEEP_TERMINAL', 'state' => $terminal]);
}
checkRuntime('admitted capacity stop is FAILED and cancels children', Failure::capacityStopDecision('WORKFLOW_EXECUTING') ===
    ['action' => 'STOP_AND_CANCEL_CHILDREN', 'state' => 'FAILED', 'reason' => 'CAPACITY_STOPPED']);
rejectsRuntime('capacity rejected is not a Run state', function () {
    Failure::capacityStopDecision('CAPACITY_REJECTED');
}, 'RUN_STATE_INVALID');

echo 'MOHE_AI_RUNTIME_CONTRACT=' . ($failed ? 'FAIL' : 'PASS') . ' passed=' . $passed . ' failed=' . $failed . PHP_EOL;
echo 'BOUNDARY=pure-policy-only; no-storage-atomicity/no-physical-cancellation/no-capacity-load-test/no-network/no-database' . PHP_EOL;
exit($failed ? 1 : 0);
