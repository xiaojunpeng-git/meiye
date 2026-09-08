<?php
// Offline pure-contract tests: no framework bootstrap, database, queue or model.
// Intentionally a weak-typing caller: numeric strings must still be rejected.
// Load only the existing worker class declaration for its shared constant.
// Never instantiate the worker or call its methods.
require_once dirname(__DIR__, 2) . '/后端代码/app/services/query/UnifiedQueryExportWorkerServices.php';
require_once dirname(__DIR__, 2) . '/后端代码/app/services/query/UnifiedQueryExportSourcePolicy.php';

use app\services\query\UnifiedQueryExportSourcePolicy as Policy;

$passed = 0;
function checkExport(string $name, callable $test, string $expected = ''): void
{
    global $passed;
    try {
        $test();
        if ($expected !== '') {
            throw new \RuntimeException('Expected rejection: ' . $expected);
        }
    } catch (\InvalidArgumentException $exception) {
        if ($expected === '' || $exception->getMessage() !== $expected) {
            throw $exception;
        }
    }
    $passed++;
    echo '[PASS] ' . $name . PHP_EOL;
}

$owner = ['instance_fingerprint' => 'fixture-instance', 'account_id' => 7,
    'terminal' => 'store', 'conversation_id' => 'fixture-conversation',
    'run_id' => 'fixture-run', 'generation' => 2];
$binding = array_merge($owner, ['created_at' => 1000, 'expires_at' => 3000,
    'execution_deadline_at' => 1180, 'source_expires_at' => [3000, 4000],
    'evidence_ref' => 'fixture-evidence', 'canonical_result_ref' => 'fixture-result',
    'read_consistency_ref' => 'fixture-read']);
$task = ['source_type' => 'AI', 'execution_partition' => 'AI_EXPORT',
    'export_scope' => 'query', 'ai_binding' => $binding, 'status' => 'pending'];
$plan = ['query' => ['export' => ['scope' => 'query']]];

checkExport('valid AI query contract', function () use ($task, $plan, $owner) {
    Policy::assertClaimable($task, $plan, 'AI_EXPORT', $owner, 1010);
});
foreach ([null, '', 'ai', ' REPORT', 1, [], 'OTHER'] as $source) {
    checkExport('invalid source rejected without default', function () use ($source) {
        Policy::partitionForSource($source);
    }, 'EXPORT_SOURCE_INVALID');
}
checkExport('source immutable', function () {
    Policy::assertSourceUnchanged('REPORT', 'AI');
}, 'EXPORT_SOURCE_IMMUTABLE');
checkExport('same source retained', function () {
    Policy::assertSourceUnchanged('REPORT', 'REPORT');
});
checkExport('wrong worker rejects without state mutation', function () use ($task, $plan, $owner) {
    $before = $task;
    try {
        Policy::assertClaimable($task, $plan, 'REPORT', $owner, 1010);
    } finally {
        if ($task !== $before) { throw new \RuntimeException('Task mutated'); }
    }
}, 'EXPORT_PARTITION_MISMATCH');
checkExport('partition copy cannot override source', function () use ($task, $plan, $owner) {
    $task['execution_partition'] = 'REPORT';
    Policy::assertClaimable($task, $plan, 'AI_EXPORT', $owner, 1010);
}, 'EXPORT_PARTITION_MISMATCH');
foreach (['query', 'page'] as $scope) {
    checkExport('ordinary ' . $scope . ' remains unchanged', function () use ($scope) {
        Policy::assertClaimable(['source_type' => 'REPORT', 'export_scope' => $scope], [], 'REPORT', [], 1010);
    });
}
checkExport('REPORT may not hide AI binding', function () use ($binding) {
    Policy::assertClaimable(['source_type' => 'REPORT', 'ai_binding' => $binding], [], 'REPORT', [], 1010);
}, 'EXPORT_REPORT_AI_BINDING_FORBIDDEN');
foreach (['page', null, ''] as $scope) {
    checkExport('AI task scope must explicitly be query', function () use ($task, $plan, $owner, $scope) {
        $task['export_scope'] = $scope;
        Policy::assertClaimable($task, $plan, 'AI_EXPORT', $owner, 1010);
    }, 'EXPORT_AI_QUERY_SCOPE_REQUIRED');
    checkExport('AI frozen scope must explicitly be query', function () use ($task, $plan, $owner, $scope) {
        $plan['query']['export']['scope'] = $scope;
        Policy::assertClaimable($task, $plan, 'AI_EXPORT', $owner, 1010);
    }, 'EXPORT_AI_QUERY_SCOPE_REQUIRED');
}
foreach (array_keys($owner) as $key) {
    checkExport('owner/generation mismatch: ' . $key, function () use ($task, $plan, $owner, $key) {
        unset($owner[$key]);
        Policy::assertClaimable($task, $plan, 'AI_EXPORT', $owner, 1010);
    }, 'EXPORT_AI_OWNER_MISMATCH');
}
checkExport('binding required', function () use ($task, $plan, $owner) {
    unset($task['ai_binding']);
    Policy::assertClaimable($task, $plan, 'AI_EXPORT', $owner, 1010);
}, 'EXPORT_AI_BINDING_REQUIRED');
checkExport('source result required', function () use ($task, $plan, $owner) {
    unset($task['ai_binding']['canonical_result_ref']);
    Policy::assertClaimable($task, $plan, 'AI_EXPORT', $owner, 1010);
}, 'EXPORT_AI_SOURCE_REQUIRED');
foreach ([1180, 3000] as $now) {
    checkExport('deadline equality or later rejected', function () use ($task, $plan, $owner, $now) {
        Policy::assertClaimable($task, $plan, 'AI_EXPORT', $owner, $now);
    }, 'EXPORT_AI_EXPIRED');
}
checkExport('earliest source expiry and 24-hour cap', function () {
    if (Policy::expiresAt(1000, [3000, 4000]) !== 3000
        || Policy::expiresAt(1000, [100000]) !== 1000 + \app\services\query\UnifiedQueryExportWorkerServices::RETENTION_SECONDS) {
        throw new \RuntimeException('Expiry cap mismatch');
    }
});
checkExport('completion cannot renew source lifetime', function () use ($task, $plan, $owner) {
    $task['ai_binding']['expires_at'] = 3100;
    Policy::assertClaimable($task, $plan, 'AI_EXPORT', $owner, 1010);
}, 'EXPORT_AI_EXPIRED');
foreach ([[], [1000], ['3000'], [null]] as $sources) {
    checkExport('invalid source expiry rejected', function () use ($sources) {
        Policy::expiresAt(1000, $sources);
    }, 'EXPORT_EXPIRY_INVALID');
}
foreach (['1000', 1000.0, true, null] as $createdAt) {
    checkExport('creation timestamp cannot be scalar-coerced', function () use ($createdAt) {
        Policy::expiresAt($createdAt, [3000]);
    }, 'EXPORT_EXPIRY_INVALID');
}
foreach (['1010', 1010.0, true, null] as $now) {
    checkExport('current timestamp cannot be scalar-coerced', function () use ($task, $plan, $owner, $now) {
        Policy::assertClaimable($task, $plan, 'AI_EXPORT', $owner, $now);
    }, 'EXPORT_EXPIRY_INVALID');
}
echo 'Passed ' . $passed . ' offline export source checks; runtime integration remains disabled.' . PHP_EOL;
