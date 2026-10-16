<?php
declare(strict_types=1);

/** Batch commands reject malformed selections before resolving scope or touching inventory. */
$root = dirname(__DIR__, 3);
require $root . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\InventoryCrossSubjectTransferServices;

$service = new InventoryCrossSubjectTransferServices();
$operate = new ReflectionMethod($service, 'operateBatch');
$operate->setAccessible(true);
$token = str_repeat('a', 64);
$drafts = [
    ['id' => 1, 'expected_edit_token' => $token],
    ['id' => 2, 'expected_edit_token' => $token],
];
$resolver = static function (): array { throw new RuntimeException('validation_reached_scope'); };
$invalid = [
    ['action' => 'DELETE_DRAFT', 'documents' => [$drafts[0]]],
    ['action' => 'DISPATCH', 'documents' => [$drafts[0], $drafts[0]]],
    ['action' => 'DISPATCH', 'documents' => [['id' => 1, 'expected_edit_token' => 'bad'], $drafts[1]]],
    ['action' => 'DELETE_DRAFT', 'documents' => [['id' => '1x', 'expected_edit_token' => $token], $drafts[1]]],
    ['action' => 'WITHDRAW', 'documents' => [['id' => 1, 'expected_withdrawal_count' => '1.5'], ['id' => 2, 'expected_withdrawal_count' => 0]], 'idempotency_key' => 'batch-test-1'],
    ['action' => 'WITHDRAW', 'documents' => [['id' => 1, 'expected_withdrawal_count' => 0], ['id' => 2, 'expected_withdrawal_count' => 0]], 'idempotency_key' => 'short'],
    ['action' => 'DISPATCH', 'documents' => array_fill(0, 201, $drafts[0])],
];
foreach ($invalid as $index => $input) {
    try {
        $operate->invoke($service, $input, $resolver);
        throw new RuntimeException('invalid_batch_accepted_' . $index);
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'inventory_cross_transfer_batch_action_invalid') throw $exception;
    }
}
$withdrawKey = new ReflectionMethod($service, 'batchWithdrawKey');
$firstKey = $withdrawKey->invoke($service, 'batch-test-1', 1);
if ($firstKey !== $withdrawKey->invoke($service, 'batch-test-1', 1)
    || $firstKey === $withdrawKey->invoke($service, 'batch-test-1', 2)
    || preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $firstKey) !== 1) {
    throw new RuntimeException('batch_withdrawal_key_not_stable');
}

$serviceSource = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php');
$platformRoute = (string)file_get_contents($root . '/后端代码/route/admin.php');
$storeRoute = (string)file_get_contents($root . '/后端代码/route/store.php');
$frontend = (string)file_get_contents($root . '/前端代码/inventory-vue3/src/App.vue');
if (strpos($serviceSource, 'return Db::transaction(function () use ($action, $byId, $key, $sourceResolver)') === false
    || strpos($serviceSource, 'foreach ($byId as $id => $entry)') === false
    || strpos($serviceSource, '$this->draftEditToken($document, $lines)') === false
    || strpos($serviceSource, '$this->deleteDraft($source, (int)$id, $entry[\'token\'])') === false
    || strpos($serviceSource, '$this->dispatch($source, (int)$id)') === false
    || strpos($serviceSource, '$this->withdraw($source, (int)$id, [') === false
    || strpos($platformRoute, "v3/hq/cross-transfer/batch-operation") === false
    || strpos($storeRoute, "v3/cross-transfer/batch-operation") === false
    || strpos($frontend, 'openBulkTransferAction') === false
    || strpos($frontend, 'selectedTransferRows') === false) {
    throw new RuntimeException('batch_operation_contract_incomplete');
}
echo "CROSS_TRANSFER_BATCH_OPERATION_CONTRACT_OK\n";
