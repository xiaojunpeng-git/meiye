<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php');
$controller = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php');
$policy = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStoreAccessPolicy.php');
$middleware = (string)file_get_contents($root . '/后端代码/app/http/middleware/store/StoreCkeckRoleMiddleware.php');
$query = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php');
$routes = (string)file_get_contents($root . '/后端代码/route/store.php');
$failed = 0;

function draftEditAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) $failed++;
}

$updateStart = strpos($service, 'public function updateDraftForStore(');
$updateEnd = strpos($service, 'public function createDraftForHeadquarters(', $updateStart ?: 0);
$update = $updateStart !== false && $updateEnd !== false ? substr($service, $updateStart, $updateEnd - $updateStart) : '';

draftEditAssert('only source-store draft receives list and detail edit permission',
    strpos($service, "\$row['can_edit'] = \$row['can_dispatch']") !== false
    && strpos($service, "\$document['can_edit'] = (string)\$document['document_status'] === 'DRAFT'") !== false
    && strpos($query, "\$row['can_edit'] = \$row['can_dispatch']") !== false);
draftEditAssert('both transfer lists expose deletion only for editable source-store drafts',
    strpos($service, "\$row['can_delete'] = \$row['can_edit']") !== false
    && strpos($query, "\$row['can_delete'] = \$row['can_edit']") !== false);
draftEditAssert('detail exposes a token derived from persisted document and ordered lines',
    strpos($service, "\$document['edit_token'] = \$this->draftEditToken(\$document, \$lines)") !== false
    && strpos($service, 'return hash(\'sha256\'') !== false);
draftEditAssert('edit locks draft and rejects stale versions or existing dispatch allocations',
    strpos($update, 'lockedStoreDocument($source, $documentId, true)') !== false
    && strpos($update, "\$document['document_status'] !== 'DRAFT'") !== false
    && strpos($update, 'hash_equals($this->draftEditToken($document, $previousLines), $expectedToken)') !== false
    && strpos($update, "inventory_cross_transfer_batch_allocation')->where('document_id', \$documentId)->lock(true)") !== false);
draftEditAssert('request association and receiving subject remain fixed for linked drafts',
    strpos($update, "\$document['request_document_id'] !== (int)\$command['requestDocumentId']") !== false
    && strpos($update, 'inventory_cross_transfer_request_target_locked') !== false
    && strpos($update, '$this->requestDocument($target, $command[\'requestDocumentId\'], $source)') !== false);
draftEditAssert('edit replaces only draft lines and header, not inventory or settlement facts',
    strpos($update, "Db::name('inventory_cross_transfer_line')->where('document_id', \$documentId)->delete()") !== false
    && strpos($update, '$this->persistDraftLines($source, $target, $request, $command, $documentId)') !== false
    && strpos($update, "Db::name('inventory_cross_transfer_document')->where('id', \$documentId)->update") !== false
    && strpos($update, 'appendFact(') === false && strpos($update, 'decreaseSource(') === false);
draftEditAssert('store command route is authenticated through the existing transfer controller',
    strpos($routes, "Route::post('v3/cross-transfer/:id/update'") !== false
    && strpos($controller, 'public function update(int $id)') !== false
    && strpos($controller, 'updateDraftForStore(') !== false);
draftEditAssert('transfer writes resolve organization employees through the shared store actor policy',
    strpos($service, 'transferActor($storeId, $operatorId)') !== false
    && strpos($policy, "return \$this->writableActor(\$storeId, \$sessionId, 'cashier.v3.inventory.transfer')") !== false
    && strpos($service, "'operatorId' => (int)(\$actor['id'] ?? \$operatorId)") !== false
    && strpos($controller, "'inventory_cross_transfer_scope_denied' => '当前账号没有本门店的调拨操作权限") !== false);
draftEditAssert('view-mode exception is restricted to transfer commands and the authenticated store',
    strpos($policy, "!empty(\$profile['_cashier_v3_delegated'])") !== false
    && strpos($policy, "(int)(\$profile['store_id'] ?? 0) !== \$storeId") !== false
    && strpos($middleware, 'isDelegatedTransferCommand(') !== false
    && strpos($middleware, '(?:update|delete-draft|dispatch|receive)') !== false);
draftEditAssert('store draft creation rechecks target organization on the server',
    strpos($service, 'if (!$this->sameOrganizationRoot($sourceScope, $target)) throw new \\RuntimeException(\'inventory_cross_transfer_target_scope_denied\')') !== false);

echo "CROSS_TRANSFER_DRAFT_EDIT_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
