import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import vm from 'node:vm'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const frontend = path.join(root, '前端代码/cashier-v3')
const requireFrontend = createRequire(path.join(frontend, 'package.json'))
const { parse: parseSfc } = requireFrontend('@vue/compiler-sfc')
const { parse: parseJs } = requireFrontend('@babel/parser')
const file = path.join(frontend, 'src/views/CashierWorkbenchView.vue')
const source = fs.readFileSync(file, 'utf8')
const script = parseSfc(source, { filename: file }).descriptor.scriptSetup.content
const ast = parseJs(script, { sourceType: 'module' })
const method = ast.program.body.find((node) => node.type === 'FunctionDeclaration' && node.id.name === 'localCheckoutPaymentMethods')
const product = ast.program.body.find((node) => node.type === 'FunctionDeclaration' && node.id.name === 'isProductLine')
const checkoutAction = ast.program.body.find((node) => node.type === 'FunctionDeclaration' && node.id.name === 'enqueueCheckoutAction')
const finalSnapshot = ast.program.body.find((node) => node.type === 'FunctionDeclaration' && node.id.name === 'buildCheckoutSnapshot')
const paymentLines = ast.program.body.find((node) => node.type === 'FunctionDeclaration' && node.id.name === 'checkoutSnapshotPaymentLines')
const businessLines = ast.program.body.find((node) => node.type === 'FunctionDeclaration' && node.id.name === 'checkoutSnapshotBusinessLines')
const lineRole = ast.program.body.find((node) => node.type === 'FunctionDeclaration' && node.id.name === 'checkoutLineRole')
assert.ok(method, 'checkout payment method selector must exist')
assert.ok(product, 'product identity selector must exist')
assert.ok(checkoutAction && finalSnapshot && paymentLines && businessLines && lineRole,
  'checkout selection and final snapshot functions must exist')

// Exercise the actual Vue selector while keeping the permission and product
// identity inputs explicit; accounting eligibility must not depend on a mock list.
const select = vm.runInNewContext(`${script.slice(product.start, product.end)}\n(${script.slice(method.start, method.end)})`, {
  cartLineRole: (line) => line.role,
  isCustomCardPurchase: (line) => line.customCard === true,
  canUseCashierV3Operation: () => true
})
const oldData = (lines, enabled = true) => select(lines, enabled).find((row) => row.id === 'old_card_entry')

assert.equal(oldData([{ role: 'sale', kind: '卡项' }])?.name, '旧数据录入')
assert.equal(oldData([{ role: 'sale', kind: '产品', productType: 0 }])?.name, '旧数据录入')
assert.equal(oldData([{ role: 'sale', kind: '卡项' }, { role: 'sale', kind: '产品', productType: 0 }])?.name, '旧数据录入')
assert.equal(oldData([{ role: 'sale', kind: '项目' }]), undefined)
assert.equal(oldData([{ role: 'sale', kind: '产品' }, { role: 'entitlement_service', kind: '项目' }]), undefined)
assert.equal(oldData([{ role: 'sale', kind: '产品' }], false), undefined)
assert.equal(oldData([]), undefined)

// Exercise the browser-owned payment mutation and final command snapshot. A
// historical product entry must keep its noncash method across both boundaries.
const preview = { value: {
  localDraftPreview: true, businessType: 'sale', memberId: 0,
  customerMode: 'guest', occurredAt: 1, businessDate: '2026-10-19',
  lines: [{ id: 'local-product', lineRole: 'sale', sourceType: 'product',
    itemId: 1, kind: '产品', name: '产品', amount: 3300, originalAmount: 3300,
    finalAmount: 3300, quantity: 1 }],
  payment: { methods: select([{ role: 'sale', kind: '产品' }], true) }
} }
const operations = { value: [] }
const clone = (value) => JSON.parse(JSON.stringify(value))
const context = {
  localCheckoutPreview: preview,
  localCheckoutPaymentOperations: operations,
  isRecord: (value) => value !== null && typeof value === 'object' && !Array.isArray(value),
  clonePlain: clone,
  cartLineRole: (line) => line.lineRole,
  checkoutSnapshotReceivableAmount: () => 3300,
  localDraftResult: () => ({ result: { status: 'succeeded' } }),
  window: { dispatchEvent() {} },
  CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail } },
  moneyToCents: (value) => Math.round(Number(value) * 100),
  localDraftLinePayableAmountCents: (line) => Math.round(line.amount * 100),
  localDraftLineTotalAmountCents: (line) => Math.round(line.originalAmount * 100),
  getLineAmount: (line) => line.amount,
  isLocalUpgradeOperationLine: () => false,
  isInventoryManagedProductLine: () => true,
  isProjectLine: () => false,
  isCustomCardPurchase: () => false,
  cartLinePresaleSelected: () => false,
  cartLineInventoryOutboundRequired: () => true,
  checkoutCardPurchaseSnapshot: () => ({}),
  canonicalCheckoutCraftsmen: () => [],
  canonicalCheckoutEntitlementCraftsmen: () => [],
  canonicalCheckoutSalespeople: () => [],
  canonicalCheckoutAttributions: () => [],
  checkoutSourceSnapshot: () => ({})
}
const extract = (node) => script.slice(node.start, node.end)
const flow = vm.runInNewContext(`${[lineRole, paymentLines, businessLines, checkoutAction, finalSnapshot]
  .map(extract).join('\n')}\n({ enqueueCheckoutAction, buildCheckoutSnapshot })`, context)
await flow.enqueueCheckoutAction({ action: 'add-payment-method', payload: { paymentMethodId: 'old_card_entry' } })
assert.equal(preview.value.lines.at(-1).method, 'old_card_entry')
assert.equal(preview.value.cashPerformanceAmount, 0)
assert.equal(flow.buildCheckoutSnapshot(preview.value).lines.at(-1).method, 'old_card_entry')
assert.equal(flow.buildCheckoutSnapshot(preview.value).lines.at(0).inventoryOutboundRequired, true)
console.log('PASS old data entry checkout eligibility')
