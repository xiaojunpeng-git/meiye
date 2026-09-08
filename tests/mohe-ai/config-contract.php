<?php
declare(strict_types=1);

// No application bootstrap, customer .env, DB, Redis or consumer is loaded.
require_once __DIR__.'/../../后端代码/vendor/topthink/framework/src/think/Env.php';
require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiRuntimeMonitor.php';
$fixtureEnv = new \think\Env();
function env($key, $default = null) { global $fixtureEnv; return $fixtureEnv->get($key, $default); }
function configFixture(array $values = []): array {
    global $fixtureEnv;
    $fixtureEnv = new \think\Env();
    // Explicitly mask ambient environment: tests never inherit release flags.
    $keys = ['monitor_registered','monitor_capacity_count','monitor_export_min_samples',
        'monitor_export_failure_rate','monitor_export_consecutive_failures','monitor_security_count',
        'monitor_unknown_count','monitor_technical_count','monitor_cleanup_stale_seconds','monitor_duration_ms',
        'export_compatible_workers_ready','export_reserved_slots_verified','export_monitoring_ready',
        'export_reserved_slots','export_queue_wait_budget_ms','export_publication_reserve_ms'];
    foreach ($keys as $key) $fixtureEnv->set('mohe_ai.'.$key, $values[$key] ?? false);
    foreach (['export_reserved_slots'=>2,'export_queue_wait_budget_ms'=>10000,'export_publication_reserve_ms'=>5000] as $key=>$default) {
        if (!array_key_exists($key, $values)) $fixtureEnv->set('mohe_ai.'.$key, $default);
    }
    return require __DIR__.'/../../后端代码/config/mohe_ai.php';
}
$checks = 0;
function checkConfig(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
}
$default = configFixture();
checkConfig($default['monitoring']['registered'] === false && !\app\services\ai\execution\AiRuntimeMonitor::profileReady($default['monitoring']), 'defaults cannot attest monitoring');
foreach (['compatible_workers_ready','reserved_slots_verified','monitoring_ready'] as $key) checkConfig($default['export'][$key] === false, 'export default is closed');
checkConfig($default['export']['reserved_slots'] === 2 && $default['export']['queue_wait_budget_ms'] === 10000 && $default['export']['publication_reserve_ms'] === 5000, 'default capacity unchanged');
foreach ([false, 0, 'false', '0', '', 'FALSE', 'yes', 'off', 'TRUE', [], 2] as $invalid) {
    $c = configFixture(['monitor_registered'=>$invalid,'export_compatible_workers_ready'=>$invalid,'export_reserved_slots_verified'=>$invalid,'export_monitoring_ready'=>$invalid]);
    checkConfig($c['monitoring']['registered'] === false && $c['export']['compatible_workers_ready'] === false && $c['export']['reserved_slots_verified'] === false && $c['export']['monitoring_ready'] === false, 'non-explicit values fail closed');
}
foreach ([true, 1, 'true', '1'] as $enabled) {
    checkConfig(configFixture(['export_compatible_workers_ready'=>$enabled])['export']['compatible_workers_ready'] === true, 'explicit boolean representation accepted');
}
$valid = ['monitor_registered'=>'1','monitor_capacity_count'=>'2','monitor_export_min_samples'=>'10',
    'monitor_export_failure_rate'=>'0.5','monitor_export_consecutive_failures'=>'5','monitor_security_count'=>'1',
    'monitor_unknown_count'=>'1','monitor_technical_count'=>'5','monitor_cleanup_stale_seconds'=>'60','monitor_duration_ms'=>'180000'];
checkConfig(\app\services\ai\execution\AiRuntimeMonitor::profileReady(configFixture($valid)['monitoring']), 'complete registered threshold profile accepted');
foreach (['monitor_capacity_count','monitor_export_min_samples','monitor_export_consecutive_failures','monitor_security_count','monitor_unknown_count','monitor_technical_count','monitor_cleanup_stale_seconds','monitor_duration_ms'] as $key) {
    foreach ([0, '0', '-1', '1.5', '1e2', '1junk', '99999999999999999999999999', true, []] as $bad) {
        checkConfig(!\app\services\ai\execution\AiRuntimeMonitor::profileReady(configFixture(array_replace($valid, [$key=>$bad]))['monitoring']), 'bad integer threshold disables profile');
    }
}
foreach ([0, '0', '1.01', '-0.1', 'NaN', INF, true, '0.5junk', []] as $bad) {
    checkConfig(!\app\services\ai\execution\AiRuntimeMonitor::profileReady(configFixture(array_replace($valid, ['monitor_export_failure_rate'=>$bad]))['monitoring']), 'bad rate disables profile');
}
foreach (['1', '1.0', 1, 0.5] as $good) checkConfig(\app\services\ai\execution\AiRuntimeMonitor::profileReady(configFixture(array_replace($valid, ['monitor_export_failure_rate'=>$good]))['monitoring']), 'bounded rate accepted');
foreach (['export_reserved_slots'=>[0,17,'2junk'], 'export_queue_wait_budget_ms'=>[0,10001,'1000.5'], 'export_publication_reserve_ms'=>[999,10001,'5e3']] as $key=>$cases) {
    foreach ($cases as $bad) checkConfig(configFixture([$key=>$bad])['export'][substr($key, 7)] === null, 'invalid capacity does not silently use default');
}
// Exercise the framework's actual INI section flattening, without reading a file.
$ini = parse_ini_string("[MOHE_AI]\nEXPORT_COMPATIBLE_WORKERS_READY = true\nEXPORT_RESERVED_SLOTS = 2\nEXPORT_MONITORING_READY = false\n", true);
$fixtureEnv = new \think\Env();
$fixtureEnv->set($ini);
$c = require __DIR__.'/../../后端代码/config/mohe_ai.php';
checkConfig($c['export']['compatible_workers_ready'] === true && $c['export']['reserved_slots'] === 2 && $c['export']['monitoring_ready'] === false, 'actual INI section format preserves explicit bool semantics');
echo "PASS {$checks} instance-local AI configuration checks.\n";
