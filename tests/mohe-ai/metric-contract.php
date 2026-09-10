<?php

// Pure local contracts: no application bootstrap, database, network, credentials or real query.
$root = dirname(__DIR__, 2);
$directory = $root . '/后端代码/app/services/query/metric/';
require_once $root . '/后端代码/app/services/query/UnifiedQueryException.php';
require_once $root . '/后端代码/app/services/query/UnifiedQueryJson.php';
foreach (['MetricQueryContractException', 'MetricQueryCatalog', 'MetricReportCapabilityRegistry', 'MetricQueryReadinessGate'] as $class) require_once $directory . $class . '.php';
require_once $root . '/后端代码/app/services/BaseServices.php';
require_once $root . '/后端代码/app/services/metric/MetricDictionaryServices.php';

use app\services\query\metric\MetricQueryContractException;
use app\services\query\metric\MetricQueryCatalog;
use app\services\query\metric\MetricReportCapabilityRegistry;
use app\services\query\metric\MetricQueryReadinessGate;

$checks = 0;
function checkMetric($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; }
function rejectMetric(callable $call, $code) { try { $call(); } catch (MetricQueryContractException $e) { checkMetric($e->getErrorCode() === $code, 'wrong error: ' . $e->getErrorCode()); return; } throw new RuntimeException('expected ' . $code); }

$dictionary = new \app\services\metric\MetricDictionaryServices();
$lookup = function ($code) use ($dictionary) { return $dictionary->getByCode($code); };
$catalog = MetricQueryCatalog::fromDictionary($lookup);
checkMetric(count($catalog->all()) === 11, 'all round-three canonical codes share one catalog');
foreach ($catalog->all() as $metric) {
    checkMetric($metric['ai_query_ready'] === false && $metric['metric_version'] === null, 'not falsely ready');
    checkMetric(!isset($metric['dev_source']) && !isset($metric['version']) && !isset($metric['updated_at']) && !isset($metric['aliases']), 'no internal fields');
}
checkMetric($catalog->get('actual_performance')['readiness_reasons'] === ['REGISTERED_READER_NOT_BOUND'], 'metadata-only catalog does not claim an execution binding');
checkMetric($catalog->get('consume_amount')['readiness_reasons'] === ['REGISTERED_READER_NOT_BOUND'], 'consumption metadata stays separate from execution readiness');
checkMetric($catalog->get('cash_performance') === MetricQueryCatalog::fromDictionary($lookup)->get('cash_performance'), 'stable metadata');
rejectMetric(function () use ($catalog) { $catalog->get('consumption_performance'); }, 'METRIC_NOT_REGISTERED');
rejectMetric(function () { MetricQueryCatalog::fromDictionary(function () { return []; }); }, 'METRIC_DICTIONARY_INVALID');
$unready = MetricQueryCatalog::fromDictionary(function ($code) use ($lookup) { $row = $lookup($code); $row['user_ready'] = false; return $row; });
checkMetric($unready->get('cash_performance')['summary'] === '口径说明待产品确认', 'unconfirmed content hidden');
$changed = MetricQueryCatalog::fromDictionary(function ($code) use ($lookup) { $row = $lookup($code); $row['note'] = '测试说明变更'; return $row; });
checkMetric($changed->get('cash_performance')['tooltip_content_hash'] !== $catalog->get('cash_performance')['tooltip_content_hash'], 'content hash changes');
$reordered = MetricQueryCatalog::fromDictionary(function ($code) use ($lookup) { $row = array_reverse($lookup($code), true); $row['updated_at'] = 'different request'; $row['version'] = 'not a metric version'; $row['dev_source'] = 'private implementation'; return $row; });
checkMetric($reordered->get('cash_performance')['tooltip_content_hash'] === $catalog->get('cash_performance')['tooltip_content_hash'], 'key order and excluded metadata do not change hash');
rejectMetric(function () use ($lookup) { MetricQueryCatalog::fromDictionary(function ($code) use ($lookup) { $row = $lookup($code); $row['summary'] = "\xC3\x28"; return $row; }); }, 'METRIC_DICTIONARY_ENCODING_INVALID');
$cash = $catalog->get('cash_performance');
$hashContent = ['code' => 'cash_performance', 'name' => $cash['name'], 'user_ready' => true, 'summary' => $cash['summary'], 'include' => $cash['include'], 'exclude' => $cash['exclude'], 'timing' => $cash['timing'], 'note' => $cash['note']];
checkMetric($cash['tooltip_content_hash'] === hash('sha256', \app\services\query\UnifiedQueryJson::encode(['content' => $hashContent, 'hash_schema_version' => MetricQueryCatalog::TOOLTIP_HASH_SCHEMA_VERSION])), 'explicit versioned whitelist projection');
checkMetric($cash['version_ready'] === false && $cash['metric_version'] === null, 'hash never fills missing metric version');

// Deliberate fixture, not a production capability registration.
$binding = ['metric_code' => 'cash_performance', 'query_shape' => 'summary', 'terminal' => 'store', 'filter_grain' => 'store', 'report_capability_code' => 'fixture.store.total', 'scope_provider_code' => 'fixture.store.scope', 'filter_contract_ref' => 'fixture.no_extra_filters'];
$registry = new MetricReportCapabilityRegistry();
rejectMetric(function () use ($registry) { $registry->resolve('cash_performance', 'summary', 'store', 'store'); }, 'METRIC_REGISTRY_NOT_FROZEN');
$registry->register($binding);
rejectMetric(function () use ($registry, $binding) { $personBinding = $binding; $personBinding['filter_grain'] = 'person'; $registry->register($personBinding); }, 'METRIC_PERMISSION_GRAIN_CONFLICT');
rejectMetric(function () use ($registry, $binding) { $registry->register($binding); }, 'METRIC_BINDING_AMBIGUOUS');
$registry->freeze();
rejectMetric(function () use ($registry, $binding) { $registry->register($binding); }, 'METRIC_REGISTRY_FROZEN');
rejectMetric(function () use ($binding) { $r = new MetricReportCapabilityRegistry(); $r->register($binding + ['ai_query_ready' => true]); }, 'METRIC_BINDING_SCHEMA_INVALID');
rejectMetric(function () use ($registry) { $registry->resolve('cash_performance', 'summary', 'store', 'person'); }, 'METRIC_CAPABILITY_UNAVAILABLE');
$request = ['metric_code' => 'cash_performance', 'query_shape' => 'summary', 'terminal' => 'store'];
$auth = ['instance_id' => 'fixture-instance', 'subject_ref' => 'fixture-subject', 'terminal' => 'store', 'permission_version' => 'fixture-v1', 'effective_scope_ref' => 'fixture-store-one', 'filter_grain' => 'store', 'report_capability_code' => 'fixture.store.total', 'scope_provider_code' => 'fixture.store.scope', 'filter_contract_ref' => 'fixture.no_extra_filters'];
$gate = new MetricQueryReadinessGate($catalog, $registry);
checkMetric($gate->inspect($request, $auth)['ai_query_ready'] === false, 'valid fixture still not production-ready');
rejectMetric(function () use ($gate, $request, $auth) { $gate->assertQueryable($request, $auth); }, 'METRIC_QUERY_NOT_READY');
foreach (['sql', 'dao', 'filter_grain', 'ai_query_ready', 'permission_version'] as $field) rejectMetric(function () use ($gate, $request, $auth, $field) { $gate->inspect($request + [$field => 'untrusted'], $auth); }, 'METRIC_QUERY_SCHEMA_INVALID');
$person = $auth; $person['filter_grain'] = 'person';
rejectMetric(function () use ($gate, $request, $person) { $gate->inspect($request, $person); }, 'METRIC_CAPABILITY_UNAVAILABLE');
$wrong = $auth; $wrong['scope_provider_code'] = 'fixture.wider';
rejectMetric(function () use ($gate, $request, $wrong) { $gate->inspect($request, $wrong); }, 'METRIC_PERMISSION_DENIED');
$wrong = $auth; $wrong['terminal'] = 'platform';
rejectMetric(function () use ($gate, $request, $wrong) { $gate->inspect($request, $wrong); }, 'METRIC_PERMISSION_DENIED');
$missing = $auth; unset($missing['permission_version']);
rejectMetric(function () use ($gate, $request, $missing) { $gate->inspect($request, $missing); }, 'METRIC_QUERY_SCHEMA_INVALID');
$emptyRegistry = (new MetricReportCapabilityRegistry())->freeze();
rejectMetric(function () use ($catalog, $emptyRegistry, $request, $auth) { (new MetricQueryReadinessGate($catalog, $emptyRegistry))->inspect($request, $auth); }, 'METRIC_CAPABILITY_UNAVAILABLE');
echo 'metric-contract: PASS (' . $checks . " checks; no production provider enabled)\n";
