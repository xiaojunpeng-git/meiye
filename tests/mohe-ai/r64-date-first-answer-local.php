<?php
/** Local-only real-model regression: an unqualified date ranking must answer before asking for a metric. */
if (getenv('MOHE_R64_DATE_TEST') !== 'LOCAL_ONLY') exit("LOCAL_ONLY opt-in required\n");
require getcwd() . '/vendor/autoload.php';
(new think\App())->initialize();

// This probe reads one authorized local instance and never targets the
// deployed customer service. It leaves the customer's other conversations
// untouched by using a fresh client session and conversation ID.
$database = (array)config('database.connections.' . config('database.default'));
if (($database['hostname'] ?? null) !== 'mysql' || ($database['database'] ?? null) !== 'ruihao'
    || (think\facade\Db::query('SELECT @@server_uuid uuid')[0]['uuid'] ?? null)
        !== '3506c91f-98cb-11f1-af5f-9e1a52451c2a') {
    throw new RuntimeException('LOCAL_INSTANCE_MISMATCH');
}
$runtime = app\services\ai\execution\AiRuntimeFactory::make();
if (!app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($runtime['config']->read())) {
    throw new RuntimeException('LOCAL_AI_AUTH_REQUIRED');
}
$adminId = (int)think\facade\Db::name('system_admin')->where('account', 'admin')->value('id');
$resolver = new app\services\ai\execution\AiPlatformPrincipalResolver();
$context = $resolver->authenticated($adminId);
if (empty($context['can_use'])) throw new RuntimeException('LOCAL_ADMIN_AUTH_REQUIRED');
$context['_refresh'] = static function () use ($resolver, $adminId): array {
    return $resolver->authenticated($adminId);
};
$gateway = new app\services\ai\AiGatewayServices();
$client = 'r64-date-' . bin2hex(random_bytes(8));
$boot = $gateway->handle('bootstrap', $context, ['client_session_id' => $client]);
$input = [
    'client_request_id' => 'r64-date-' . bin2hex(random_bytes(8)),
    'conversation_id' => 'r64-date-' . bin2hex(random_bytes(8)),
    'client_session_id' => $client,
    'window_token' => $boot['window_token'],
    'question' => '这个月业绩最高是哪天',
    'history' => [],
    'output_format' => 'screen',
    'guidance_schema_version' => 'mohe-clarification-v2',
];
$started = microtime(true);
$run = $gateway->handle('create', $context, $input);
if (!isset($run['run_id'], $run['generation'], $run['run_delivery_token'])) {
    throw new RuntimeException('RUN_NOT_ADMITTED_' . (string)($run['reason'] ?? 'unknown'));
}
$delivery = ['client_session_id' => $client, 'generation' => $run['generation'],
    'run_delivery_token' => $run['run_delivery_token']];
$run = $gateway->handle('execute', $context, $input + $delivery, $run['run_id']);
$until = microtime(true) + 75;
while (in_array($run['status'] ?? null, ['RECEIVED', 'WORKFLOW_EXECUTING', 'WAITING_EXPORT'], true)
    && microtime(true) < $until) {
    usleep(250000);
    $run = $gateway->handle('status', $context, $delivery, $run['run_id']);
}
$row = think\facade\Db::name('mohe_ai_run')->where('run_id', $run['run_id'])->find();
$query = ($run['status'] ?? null) === 'COMPLETED'
    ? ($runtime['private']->read($row['evidence_ref'])['query'] ?? []) : [];
$summary = (string)($run['answer']['summary'] ?? '');
$passed = ($run['status'] ?? null) === 'COMPLETED'
    && ($query['business_filters']['object_kind'] ?? null) === 'business_date'
    && ($query['metric_codes'] ?? null) === ['actual_performance']
    && ($query['ranking']['direction'] ?? null) === 'top'
    && preg_match('/2026-10-\d{2}/', $summary) === 1;
echo json_encode(['run_id' => $run['run_id'], 'status' => $run['status'] ?? null,
    'reason' => $run['reason'] ?? null, 'elapsed_ms' => (int)((microtime(true) - $started) * 1000),
    'metric_codes' => $query['metric_codes'] ?? null, 'object_kind' => $query['business_filters']['object_kind'] ?? null,
    'has_ranked_date' => preg_match('/2026-10-\d{2}/', $summary) === 1,
    'passed' => $passed], JSON_UNESCAPED_UNICODE), PHP_EOL;
if (!$passed) throw new RuntimeException('R64_DATE_FIRST_ANSWER_FAILED');
