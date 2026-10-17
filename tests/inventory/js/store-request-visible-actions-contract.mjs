import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const app = read('../../../前端代码/inventory-vue3/src/App.vue')
const modal = read('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue')
const query = read('../../../后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php')
const requestService = read('../../../后端代码/app/services/product/inventory/InventoryStockRequestServices.php')
const transferService = read('../../../后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php')

// 门店列表必须完全由服务端能力字段驱动；终端类型不能再把已授权操作隐藏。
assert.ok(app.includes("active === 'request' && sourceRows[index].can_transfer"))
assert.ok(!app.includes("active === 'request' && mode === 'platform' && sourceRows[index].can_transfer"))
assert.ok(app.includes("sourceRows[index].can_void && active !== 'transfer'"))

// 可见请货主体均获得入口，但状态和“曾生成调拨”的关闭边界仍由服务端计算。
assert.ok(query.includes("$row['can_transfer'] = ($isRequester || $isSupplier)"))
assert.ok(query.includes("$row['can_void'] = ($isRequester || $isSupplier)"))
assert.ok(query.includes("&& !$linkedTransfer"))
assert.ok(requestService.includes('if (!$visibleAsRequester && !$visibleAsSupplier)'))
assert.ok(requestService.includes('inventory_stock_request_void_transfer_exists'))

// 门店发起转调拨时必须重新锁定原请货方向，不能采用客户端传来的任意调出/调入主体。
assert.ok(transferService.includes('[$sourceScope, $target] = $this->visibleRequestTransferScopes($viewerScope, (array)$request)'))
assert.ok(transferService.includes("$supplierType = strtoupper((string)($document['supply_party_type'] ?? ''))"))
assert.ok(transferService.includes("$requesterType = $legacyRequester ? 'STORE'"))
assert.ok(transferService.includes("$source['initiatorStoreId'] = (int)$viewer['storeId']"))
assert.ok(transferService.includes("request_document_id' => $request ? (int)$request['id'] : 0"))

// 关联原请货后，页面锁定方向和商品来源，数量再由服务端按剩余量复核。
assert.ok(modal.includes(':disabled="requestLinkedTransfer"'))
assert.ok(modal.includes("pageKey === 'transfer' && requestLinkedTransfer"))
assert.ok(modal.includes('关联请货单已无可调拨商品或不在当前供货权限内'))

console.log('STORE_REQUEST_VISIBLE_ACTIONS_CONTRACT_OK')
