import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const workbench = fs.readFileSync(
  new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root),
  'utf8'
)
const checkoutOverlay = fs.readFileSync(
  new URL('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue', root),
  'utf8'
)

const finalization = workbench.match(
  /async function finalizeLocalCheckoutPreview\(event = \{\}\) \{[\s\S]*?\n}\n\nfunction localCheckoutPaymentAmount/
)?.[0] || ''
const paymentReplay = workbench.match(
  /async function persistLocalCheckoutPaymentPreview\(preview = \{\}\) \{[\s\S]*?\n}\n\nasync function discardStaleCheckoutBeforeLocalFinalization/
)?.[0] || ''
const localSummary = workbench.match(
  /function recalculateLocalCashierDraft\(draft\) \{[\s\S]*?\n}\n\nfunction commitLocalCashierDraft/
)?.[0] || ''
const quantityChanges = workbench.match(
  /async function changeLineQuantity\(line, delta\) \{[\s\S]*?\n}\n\nfunction entitlementLineMaximum/
)?.[0] || ''
const quantityInput = workbench.match(
  /async function setLineQuantity\(line, event\) \{[\s\S]*?\n}\n\nfunction localCheckoutPaymentMethods/
)?.[0] || ''

assert.match(
  finalization,
  /const checkoutSnapshot = buildCheckoutSnapshot\(preview \|\| \{\}\)[\s\S]*?openCheckout\(\{ forceFreshCheckout: true, checkoutSnapshot \}\)[\s\S]*?requestCheckoutAction\(\{ action: 'submit-checkout'/,
  '最终确认先从当前本地预览生成完整快照，再提交结账'
)
assert.doesNotMatch(
  finalization,
  /persistLocalCheckoutPaymentPreview\(preview\)/,
  '最终确认不得先逐条回放收款行'
)
assert.doesNotMatch(
  finalization,
  /for \(const operation of paymentOperations\)/,
  '不能把 local-payment 临时 ID 的历史操作原样发送到服务端'
)
assert.match(
  paymentReplay,
  /const selectedLines = Array\.isArray\(preview\?\.payment\?\.selectedLines\)/,
  '收款预览仍保留在浏览器本地状态'
)
assert.match(
  paymentReplay,
  /const selectedLines = Array\.isArray\(preview\?\.payment\?\.selectedLines\)[\s\S]*?const replayedMethods = new Set\(\)/,
  '以当前第三步可见收款行作为同步来源，并拒绝重复收款方式'
)
assert.match(
  localSummary,
  /const saleLines = lines\.filter\(\(line\) => cartLineRole\(line\) === 'sale'\)[\s\S]*?const saleAmountCents = saleLines\.reduce[\s\S]*?const debtAmountCents = saleLines\.reduce[\s\S]*?const receivableAmountCents = Math\.max\(0, saleAmountCents - debtAmountCents\)/,
  '本地应收、原价与优惠仅计算本次购买，权益服务不计应付'
)
assert.match(
  localSummary,
  /discountAmount: centsToMoney\(Math\.max\(0, originalAmountCents - saleAmountCents\)\)/,
  '欠款只减少应收，不能被误算为优惠'
)
assert.match(
  checkoutOverlay,
  /<span v-if="checkoutLineDebtAmount\(line\) > 0">欠款 \{\{ formatMoney\(checkoutLineDebtAmount\(line\)\) \}\}<\/span>/,
  '结账第一步的购买行必须显示行级欠款金额'
)
assert.match(
  quantityChanges,
  /await mutateCashierDraft\('change-cart-line-quantity', line, \{ delta \}\)/,
  '已有服务端行的加减数量只写本地草稿'
)
assert.doesNotMatch(
  quantityChanges,
  /mutateRootCashierDraft\('change-cart-line-quantity'/,
  '数量编辑阶段不得直接写回服务端工作台'
)
assert.match(
  quantityInput,
  /const result = await mutateCashierDraft\([\s\S]*?'change-cart-line-quantity'[\s\S]*?delta: nextQuantity - currentQuantity/,
  '已有服务端行的手输数量只写本地草稿'
)
assert.match(
  checkoutOverlay,
  /function queryOriginalCheckoutResult\(\) \{[\s\S]*?request\('query-checkout-result',[\s\S]*?\}\)\.then\(handleSubmissionResponse\)/,
  '所有结账类型的原回执查询必须把结果回写结账弹窗'
)
assert.match(
  checkoutOverlay,
  /const canQueryCheckoutResult = computed\(\(\) => \([\s\S]*?showSubmissionLongRunning\.value/,
  '提交超时状态必须允许查询原结账回执，不得只限充值'
)
assert.match(
  workbench,
  /const queryCanUseOverlayIdentity = action === 'query-checkout-result'[\s\S]*?actionSession[\s\S]*?actionCurrent/,
  '成功清空根投影后仍用弹窗原请求标识执行只读结果查询'
)

console.log('LOCAL_CHECKOUT_PAYMENT_REPLAY_CONTRACT passed=12 failed=0')
