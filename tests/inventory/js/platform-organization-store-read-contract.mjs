#!/usr/bin/env node

import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createDocumentDetailReader } from '../../../前端代码/inventory-vue3/src/documentDetailReader.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const page = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
const picker = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/components/InventoryOrganizationStorePicker.vue'), 'utf8')
const calls = []
const platformApi = {
  documentRemarkDetail: async (type, id, scope) => {
    calls.push({ type, id, scope })
    return { document: { can_void: true }, lines: [], remark_editable: true, can_edit: true, can_dispatch: true, can_receive: true }
  },
  hqInboundOutboundDetails: async () => ({ lines: [] })
}
const storeReader = createDocumentDetailReader({ mode: 'platform', hqLocationId: 9, warehouseSelection: 'HQ:9', organizationStoreId: 42, platformApi })
for (const type of ['inbound', 'outbound', 'request', 'transfer', 'usage']) {
  const detail = await storeReader(type, { id: 7, source_id: 'doc7', location_id: 88, store_id: 42 })
  assert.equal(detail.remark_editable, false)
  assert.equal(detail.can_edit, false)
  assert.equal(detail.can_dispatch, false)
  assert.equal(detail.can_receive, false)
  assert.equal(detail.document.can_void, false)
  const scope = calls.at(-1).scope
  assert.equal(type === 'request' || type === 'transfer' || type === 'usage' ? scope.store_id : scope.storeId, 42)
}
const hqReader = createDocumentDetailReader({ mode: 'platform', hqLocationId: 9, warehouseSelection: 'HQ:9', platformApi })
const hqDetail = await hqReader('request', { id: 7 })
assert.deepEqual(calls.at(-1).scope, { hq_location_id: 9 })
assert.equal(hqDetail.remark_editable, true)
assert.ok(page.includes("const platformOrganizationPages = new Set(['inbound', 'outbound', 'request', 'transfer', 'usage'])"))
assert.ok(page.includes('platformOrganizationStoreId.value = 0'))
assert.ok(page.includes("subject: 'STORE', storeId: platformOrganizationStoreId.value"))
assert.ok(page.includes('platformHqListWarehouseOptions'))
assert.ok(page.includes('const platformStorePageSize = 20'))
assert.ok(page.includes('turnPlatformStorePage(delta)'))
assert.ok(picker.includes("if (item.node_type === 'store') stores.push(item)"))
assert.ok(picker.includes("emit('update:modelValue', Number(node.id))"))
assert.ok(picker.includes("emit('update:modelValue', 0)"))
console.log('PLATFORM_ORGANIZATION_STORE_READ_OK: HQ default, single store, five details read-only')
