import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import vm from 'node:vm'
import { fileURLToPath } from 'node:url'

// Execute the production append handler with isolated draft dependencies, not a copied implementation.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'), 'utf8')
const start = source.indexOf('async function appendCatalogItemToDraft(item) {')
const end = source.indexOf('\nfunction ', start)
assert.ok(start >= 0 && end > start, 'production append handler exists')
const lines = []
const operations = []
const personnelInputs = []
const context = vm.createContext({
  catalogItemAppendQueue: Promise.resolve(),
  clonePlain: (value) => JSON.parse(JSON.stringify(value)),
  defaultExclusivePersonnelForLine: async (line) => { personnelInputs.push({ ...line }); return {} },
  appendLocalCashierDraftOperation: (operation, mutate) => {
    operations.push(operation)
    mutate({ lines })
  }
})
vm.runInContext(source.slice(start, end), context)

// Real regression IDs: SKU 582480 is not store project 600534 or platform project 600451.
await context.appendCatalogItemToDraft({ id: 582480, productId: 600534, kind: '项目', productType: 6, name: '指尖胶原', price: '198' })
assert.equal(lines[0].catalogItemId, 582480)
assert.equal(lines[0].itemId, 582480)
assert.equal(lines[0].productId, 600534)
assert.equal(lines[0].projectId, 600534)
assert.equal(personnelInputs[0].projectId, 600534)
assert.equal(operations[0].itemId, 582480, 'checkout intent keeps SKU key')
await context.appendCatalogItemToDraft({ id: 9001, productId: 8001, kind: '产品', productType: 0, name: '产品', price: '10' })
assert.equal(lines[1].productId, 8001)
assert.equal(lines[1].projectId, 0, 'physical products do not invent a project identity')
await context.appendCatalogItemToDraft({ id: 9002, productId: 8002, kind: '卡项', productType: 1, name: '卡项', price: '100' })
assert.equal(lines[2].productId, 8002)
assert.equal(lines[2].projectId, 0)
const before = lines.length
const invalid = await context.appendCatalogItemToDraft({ productId: 600534, kind: '项目' })
assert.equal(invalid.result.status, 'failed')
assert.equal(lines.length, before, 'invalid SKU never adds a line')
console.log('PASS project/SKU identity, physical product, card and invalid SKU; checkout SKU unchanged')
