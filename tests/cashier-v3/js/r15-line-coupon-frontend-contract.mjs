import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import vm from 'node:vm'

const root = process.cwd()
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')

const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const selector = read('前端代码/cashier-v3/src/components/cashier/CashierCouponSelectorOverlay.vue')
const manifest = read('前端代码/cashier-v3/src/services/cashierV3ActionManifest.js')
const bridge = read('前端代码/cashier-v3/src/services/cashierV3Bridge.js')

function sourceFunction(name) {
  const functionStart = workbench.indexOf(`function ${name}(`)
  const start = workbench.slice(functionStart - 6, functionStart) === 'async '
    ? functionStart - 6 : functionStart
  if (start < 0) throw new Error(`missing ${name}`)
  const open = workbench.indexOf(') {', start) + 2
  let depth = 0
  for (let index = open; index < workbench.length; index += 1) {
    if (workbench[index] === '{') depth += 1
    if (workbench[index] === '}') depth -= 1
    if (depth === 0) return workbench.slice(start, index + 1)
  }
  throw new Error(`incomplete ${name}`)
}

const amounts = vm.runInNewContext(`
  ${sourceFunction('getLineAmount')}
  ${sourceFunction('localDraftLineTotalAmountCents')}
  ${sourceFunction('localDraftLinePayableAmountCents')}
  ({ gross: localDraftLineTotalAmountCents(line), payable: localDraftLinePayableAmountCents(line) })
`, {
  line: { id: 'local-sale-1', finalAmount: 3960, quantity: 4, couponDiscountCents: 100000 },
  moneyToCents: (value) => Math.round(Number(value) * 100),
  isRecord: (value) => value !== null && typeof value === 'object' && !Array.isArray(value),
  isLocalCashierDraftLine: (line) => String(line.id).startsWith('local-')
})

const mutationDraft = { lines: [{ id: 'local-sale-1', finalAmount: 3960, amount: 3960, quantity: 4 }] }
const mutation = vm.runInNewContext(`
  ${sourceFunction('getLineAmount')}
  ${sourceFunction('localDraftLineTotalAmountCents')}
  ${sourceFunction('localDraftLinePayableAmountCents')}
  ${sourceFunction('applyLocalCashierDraftMutation')}
  applyLocalCashierDraftMutation('apply-line-coupon', draft.lines[0], {
    couponId: '16833', couponSummary: '老友答谢券', discountAmountCents: 100000
  })
  const afterCoupon = localDraftLinePayableAmountCents(draft.lines[0])
  const unitAfterCoupon = draft.lines[0].finalAmount
  applyLocalCashierDraftMutation('change-cart-line-quantity', draft.lines[0], { delta: -1 });
  ({ afterCoupon, unitAfterCoupon, afterQuantity: localDraftLinePayableAmountCents(draft.lines[0]),
    couponId: draft.lines[0].couponId || '' })
`, {
  draft: mutationDraft,
  appendLocalCashierDraftOperation: (_operation, update) => update(mutationDraft),
  clonePlain: (value) => JSON.parse(JSON.stringify(value)),
  localDraftResult: () => ({}),
  moneyToCents: (value) => Math.round(Number(value) * 100),
  centsToMoney: (value) => value / 100,
  isRecord: (value) => value !== null && typeof value === 'object' && !Array.isArray(value),
  isLocalCashierDraftLine: (line) => String(line.id).startsWith('local-'),
  refreshMultiCardUpgradePayable: () => {},
  isEntitlementLine: () => false
})

const quantityDraft = { lines: [{ id: 'local-sale-2', finalAmount: 3960, amount: 3960, quantity: 4, couponId: '16833', couponDiscountCents: 100000 }] }
const quantityCoupon = await vm.runInNewContext(`
  ${sourceFunction('getLineAmount')}
  ${sourceFunction('localDraftLineTotalAmountCents')}
  ${sourceFunction('localDraftLinePayableAmountCents')}
  ${sourceFunction('applyLocalCashierDraftMutation')}
  ${sourceFunction('changeLineQuantityWithCoupon')}
  const mutateCashierDraft = (action, line, payload) => Promise.resolve(applyLocalCashierDraftMutation(action, line, payload));
  (async () => {
    await changeLineQuantityWithCoupon(draft.lines[0], -1)
    const eligible = { amount: localDraftLinePayableAmountCents(draft.lines[0]), couponId: draft.lines[0].couponId }
    await changeLineQuantityWithCoupon(draft.lines[0], -1)
    return { eligible, below: { amount: localDraftLinePayableAmountCents(draft.lines[0]), couponId: draft.lines[0].couponId || '' } }
  })()
`, {
  draft: quantityDraft,
  cartLines: { value: quantityDraft.lines },
  currentCashierDraftScopeKey: { value: 'test-scope' },
  isRefreshingLineCoupon: { value: false },
  appendLocalCashierDraftOperation: (_operation, update) => update(quantityDraft),
  clonePlain: (value) => JSON.parse(JSON.stringify(value)),
  localDraftResult: () => ({ result: { status: 'succeeded' } }),
  resultStatus: (value) => value?.result?.status || '',
  moneyToCents: (value) => Math.round(Number(value) * 100),
  centsToMoney: (value) => value / 100,
  isRecord: (value) => value !== null && typeof value === 'object' && !Array.isArray(value),
  isLocalCashierDraftLine: (line) => String(line.id).startsWith('local-'),
  isEntitlementLine: () => false,
  refreshMultiCardUpgradePayable: () => {},
  openCartLineCoupon: async (line) => ({ coupons: Number(line.quantity) * 396000 >= 1000000
    ? [{ couponId: '16833', name: '老友答谢券', discountAmountCents: 100000 }] : [] }),
  window: { dispatchEvent: () => {} },
  CustomEvent: class {}
})

const checks = [
  ['本地草稿优惠券使用只读选择投影', workbench.includes("localDraft ? 'open-local-line-coupon' : 'open-line-coupon'") && workbench.includes('reservedCouponIds')],
  ['普通销售行与升级目标销售行共用优惠券入口', workbench.includes('@click="openCartLineCoupon(line)"') && !workbench.includes('cardOperationUpgradeBinding(line) && openCartLineCoupon')],
  ['卡内权益行不能使用优惠券', workbench.includes('if (isEntitlementLine(line)) return') && workbench.includes(':disabled="isEntitlementLine(line)"')],
  ['选择器只接收后端 couponSelector', workbench.includes('responseDataBlock(result).couponSelector')],
  ['未达门槛券只展示并保持禁用', selector.includes('belowMinimumCoupons') && selector.includes('shortfallCents') && selector.includes('name="cashier-line-coupon" :value="couponId(coupon)" disabled')],
  ['前端提交仅从可用券集合取券', workbench.includes('if (couponId && !coupon) return') && selector.includes('canConfirmSelection')],
  ['四件商品按整行金额选券', workbench.includes('const lineAmountCents = localDraftLineTotalAmountCents(') && amounts.gross === 1584000],
  ['整行一张券只扣一次', amounts.payable === 1484000 && mutation.afterCoupon === 1484000 && mutation.unitAfterCoupon === 3960 && workbench.includes('next.lineAmountCents = localDraftLinePayableAmountCents(line)')],
  ['修改数量时旧券失效', mutation.afterQuantity === 1188000 && mutation.couponId === ''],
  ['数量变化后重新核验仍可用的券', quantityCoupon.eligible.amount === 1088000 && quantityCoupon.eligible.couponId === '16833'],
  ['数量低于门槛时清券', quantityCoupon.below.amount === 792000 && quantityCoupon.below.couponId === ''],
  ['应用优惠券写入本地草稿队列', workbench.includes("saveLineCoupon('apply-line-coupon'") && workbench.includes('mutateCashierDraft(action, line')],
  ['移除优惠券写入本地草稿队列', workbench.includes("saveLineCoupon('remove-line-coupon')")],
  ['写命令已登记在前端动作清单', manifest.includes("'apply-line-coupon': FEATURE_CASHIER") && manifest.includes("'remove-line-coupon': FEATURE_CASHIER")],
  ['写命令属于收银工作台上下文', bridge.includes("'apply-line-coupon'") && bridge.includes("'remove-line-coupon'")],
  ['选择器展示券名和后端优惠金额', selector.includes("coupon.name || '优惠券'") && selector.includes('coupon.discountAmountCents')],
  ['选择器支持不使用优惠券', selector.includes("emit('remove')") && selector.includes('不使用优惠券')],
  ['选择器没有客户端计算升级补价', !selector.includes('cardOperationUpgrade') && !selector.includes('targetAmount')],
  ['优惠券仍使用现有立即结账流程', !selector.includes('CashierCheckoutOverlay') && !selector.includes('prepare-checkout')]
]

let failed = 0
for (const [label, passed] of checks) {
  if (passed) {
    console.log(`PASS ${label}`)
  } else {
    failed += 1
    console.error(`FAIL ${label}`)
  }
}

if (failed) process.exit(1)
console.log(`R15 line coupon frontend contract passed (${checks.length} checks).`)
