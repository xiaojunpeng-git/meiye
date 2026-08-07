#!/usr/bin/env node

import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const testDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const root = path.resolve(testDir, '../..')
const frontendRoot = path.join(root, '前端代码/cashier-v3')
const backendRoot = path.join(root, '后端代码/app/services/cashier/v3')
const requireFromFrontend = createRequire(path.join(frontendRoot, 'package.json'))
const { parse: parseSfc } = requireFromFrontend('@vue/compiler-sfc')
const { parse: parseJavaScript } = requireFromFrontend('@babel/parser')

const overlayPath = path.join(frontendRoot, 'src/components/cashier/CashierCheckoutOverlay.vue')
const workbenchPath = path.join(frontendRoot, 'src/views/CashierWorkbenchView.vue')
const bridgePath = path.join(frontendRoot, 'src/services/cashierV3Bridge.js')
const routerPath = path.join(frontendRoot, 'src/router/index.js')
const lifecyclePath = path.join(frontendRoot, 'src/services/cashierV3SessionLifecycle.js')
const sessionTokenPath = path.join(frontendRoot, 'src/services/storeV3SessionToken.js')
const shellPath = path.join(frontendRoot, 'src/layouts/CashierShell.vue')
const fixturePath = path.join(frontendRoot, 'src/dev/pdV3FixtureMain.js')
const normalizerPath = path.join(backendRoot, 'CashierV3RequestNormalizer.php')
const settlementPath = path.join(backendRoot, 'settlement/CashierV3CheckoutSettlementKernel.php')
const paymentDraftPath = path.join(backendRoot, 'settlement/CashierV3CheckoutPaymentDraftServices.php')
const factPlanPath = path.join(backendRoot, 'fact/CashierV3CheckoutFactPlanV1.php')

let passed = 0
let failed = 0

function check(name, condition, detail = '') {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
    return
  }
  failed += 1
  console.error(`FAIL ${name}${detail ? `: ${detail}` : ''}`)
}

function read(file) {
  return fs.readFileSync(file, 'utf8')
}

function vueScript(file) {
  const source = read(file)
  const parsed = parseSfc(source, { filename: file })
  if (parsed.errors.length > 0) {
    throw new Error(`SFC_PARSE_FAILED ${file}: ${parsed.errors.join('; ')}`)
  }
  return parsed.descriptor.scriptSetup?.content || parsed.descriptor.script?.content || ''
}

function javascriptAst(source, file) {
  return parseJavaScript(source, {
    sourceType: 'module',
    sourceFilename: file,
    plugins: ['jsx', 'topLevelAwait'],
  })
}

function functionSource(source, ast, name) {
  const node = ast.program.body.find((item) => (
    item.type === 'FunctionDeclaration' && item.id?.name === name
  ))
  return node ? source.slice(node.start, node.end) : ''
}

function requestActions(source, file = 'inline.js') {
  if (!source) return []
  const ast = javascriptAst(source, file)
  const actions = []
  const visit = (node) => {
    if (!node || typeof node !== 'object') return
    if (node.type === 'CallExpression'
      && node.callee?.type === 'Identifier'
      && node.callee.name === 'request'
      && node.arguments?.[0]?.type === 'StringLiteral') {
      actions.push(node.arguments[0].value)
    }
    for (const value of Object.values(node)) {
      if (Array.isArray(value)) value.forEach(visit)
      else if (value && typeof value === 'object' && typeof value.type === 'string') visit(value)
    }
  }
  visit(ast.program)
  return actions
}

function phpArrayBody(source, startMarker) {
  const start = source.indexOf(startMarker)
  const open = start >= 0 ? source.indexOf('[', start + startMarker.length - 1) : -1
  if (open < 0) return ''
  let depth = 1
  let quote = ''
  let escaped = false
  for (let index = open + 1; index < source.length; index += 1) {
    const character = source[index]
    if (quote) {
      if (escaped) escaped = false
      else if (character === '\\') escaped = true
      else if (character === quote) quote = ''
      continue
    }
    if (character === "'" || character === '"') quote = character
    else if (character === '[') depth += 1
    else if (character === ']') {
      depth -= 1
      if (depth === 0) return source.slice(open + 1, index)
    }
  }
  return ''
}

function phpArrayValues(source, startMarker) {
  const body = phpArrayBody(source, startMarker)
  if (!body) return []
  const constants = new Map(
    [...source.matchAll(/(?:public|private) const ([A-Z][A-Z0-9_]*) = '([^']+)';/g)]
      .map((match) => [match[1], match[2]]),
  )
  const values = []
  const itemPattern = /'([a-z][a-z0-9_]*)'|self::([A-Z][A-Z0-9_]*)/g
  for (const match of body.matchAll(itemPattern)) {
    const value = match[1] || constants.get(match[2]) || ''
    if (value) values.push(value)
  }
  return values
}

function methodSlice(source, signature, nextSignature) {
  const start = source.indexOf(signature)
  const end = start >= 0 ? source.indexOf(nextSignature, start + signature.length) : -1
  return start >= 0 && end > start ? source.slice(start, end) : ''
}

const fixedMethods = [
  'unionpay',
  'wechat',
  'alipay',
  'dianping_voucher',
  'douyin_voucher',
  'partner_collection',
  'other_collection',
]

const overlayScript = vueScript(overlayPath)
const overlayAst = javascriptAst(overlayScript, overlayPath)
const workbenchScript = vueScript(workbenchPath)
const workbenchAst = javascriptAst(workbenchScript, workbenchPath)
const workbenchSource = read(workbenchPath)
const bridge = read(bridgePath)
const bridgeAst = javascriptAst(bridge, bridgePath)
const router = read(routerPath)
const lifecycle = read(lifecyclePath)
const sessionToken = read(sessionTokenPath)
const shell = read(shellPath)
const fixture = read(fixturePath)
const normalizer = read(normalizerPath)
const settlement = read(settlementPath)
const paymentDraft = fs.existsSync(paymentDraftPath) ? read(paymentDraftPath) : ''
const factPlan = read(factPlanPath)

console.log('\n== stale root HTTP envelope preservation ==')
const unwrapResponseSource = functionSource(bridge, bridgeAst, 'unwrapCashierV3Response')
const buildStateIgnoredSource = functionSource(bridge, bridgeAst, 'buildStateIgnoredResult')
const buildStateIgnoredResult = Function(
  `'use strict'; ${unwrapResponseSource}; ${buildStateIgnoredSource}; return buildStateIgnoredResult;`,
)()
const checkoutResultDto = {
  contractVersion: 'cashier-v3-checkout-result-v1',
  originalIdempotencyKey: 'CHECKOUT-12345678-1234-4abc-8abc-1234567890ab',
  status: 'success',
  phase: 'succeeded',
}
const staleWrappedResult = {
  status: 200,
  message: 'ok',
  data: {
    result: { status: 'success', code: '', message: 'ok' },
    data: { checkoutResult: checkoutResultDto },
    state: { stateContextId: 'ctx-stale', stateRevision: '1' },
    stateContextId: 'ctx-stale',
    stateRevision: '1',
    boundAction: 'query-checkout-result',
  },
}
const preservedStaleResult = buildStateIgnoredResult(staleWrappedResult, {
  requiresRefresh: true,
  code: 'STALE_ROOT_STATE',
  message: '较新根状态已生效。',
})
check(
  'stale root strips only root fields and preserves the unwrapped checkout result DTO',
  preservedStaleResult.stateIgnored === true
    && preservedStaleResult.requiresRefresh === true
    && preservedStaleResult.data?.checkoutResult === checkoutResultDto
    && preservedStaleResult.data?.result === undefined
    && preservedStaleResult.state === undefined
    && preservedStaleResult.stateContextId === undefined,
  JSON.stringify(preservedStaleResult),
)

console.log('== local-only checkout navigation ==')
const goPrevious = functionSource(overlayScript, overlayAst, 'goPrevious')
const goNext = functionSource(overlayScript, overlayAst, 'goNext')
const finishCheckoutAndReturn = functionSource(overlayScript, overlayAst, 'finishCheckoutAndReturn')
check(
  'previous checkout step changes only local state',
  goPrevious.includes('localStep.value =') && requestActions(goPrevious).length === 0,
)
const goNextActions = requestActions(goNext)
check(
  'next checkout step is local and only terminal submission emits a command',
  goNext.includes('localStep.value = nextStep.number')
    && goNext.indexOf('return', goNext.indexOf('localStep.value = nextStep.number'))
      < goNext.indexOf("request('submit-checkout'")
    && JSON.stringify(goNextActions) === JSON.stringify(['submit-checkout']),
  JSON.stringify(goNextActions),
)
check(
  '组合收款由多条草稿明细自动表达，不再要求额外开关',
  !overlayScript.includes('toggleCombinationMode')
    && !overlayScript.includes('request(\'toggle-combination-payment\''),
)
check(
  'overlay never emits checkout-step or combination-toggle business actions',
  !overlayScript.includes("request('checkout-step-next'")
    && !overlayScript.includes("request('checkout-step-back'")
    && !overlayScript.includes("request('toggle-combination-payment'"),
)
check(
  'verified success emits completed locally, then the parent refreshes the workbench',
  finishCheckoutAndReturn.includes('if (!isSucceeded.value) return')
    && finishCheckoutAndReturn.includes("emit('completed')")
    && requestActions(finishCheckoutAndReturn).length === 0
    && !overlayScript.includes("request('finish-checkout-and-return')"),
)
check(
  'bootstrap failure is retryable and the cashier session is isolated by browser tab',
  router.includes('if (workbenchBootstrapPromise) return workbenchBootstrapPromise')
    && router.includes('.finally(() => {')
    && router.includes('workbenchBootstrapPromise = null')
    && lifecycle.includes('export function hasCashierV3Session')
    && lifecycle.includes('readStoreV3SessionToken')
    && sessionToken.includes('sessionStorage')
    && !sessionToken.includes('localStorage')
    && !sessionToken.includes('document.cookie')
    && !shell.includes('observeCashierV3BrowserSession()')
    && !shell.includes('stopBrowserSessionObserver?.()'),
)
check(
  'cashier toolbar and the routed workbench render together outside inventory mode',
  shell.includes('<template v-else>')
    && shell.includes('<div v-if="isCashierPage" class="cashier-workflow-toolbar cashier-workflow-toolbar--cashier"')
    && /<div class="cashier-main__view">\s*<RouterView\s*\/>\s*<\/div>\s*<\/template>/.test(shell),
)

console.log('\n== sale catalog entry contract ==')
check(
  'catalog defaults to projects and merges every non-custom card subtype into the card tab',
  workbenchScript.includes("const selectedType = ref('项目')")
    && workbenchScript.includes("const defaultTypes = ['项目', '产品', '卡项', '定制卡']")
    && !workbenchScript.includes("'次卡'"),
)
check(
  'catalog provides an all-category entry and a one-row expandable category list',
  workbenchScript.includes("const selectedCategory = ref('')")
    && workbenchScript.includes("'全部',")
    && workbenchScript.includes("category === '全部' ? '' : category")
    && workbenchScript.includes("const categoryMatched = !selectedCategory.value || item.category === selectedCategory.value")
    && workbenchSource.includes('class="catalog-category-options"')
    && workbenchSource.includes("{{ areCategoriesExpanded ? '收起' : '展开' }}"),
)
check(
  'custom cards remain a dedicated configuration entry instead of a normal card catalog item',
  workbenchScript.includes("items.push({ id: 'custom-card-entry', name: '新建定制卡', kind: '定制卡'")
    && workbenchScript.includes("guidedBusinessMode.value = 'custom-card'")
    && workbenchScript.includes("requestAction('create-custom-card-configuration', result.payload || {})"),
)

console.log('\n== repeatable payment draft editing ==')
const addPayment = functionSource(overlayScript, overlayAst, 'addPaymentMethod')
const updatePayment = functionSource(overlayScript, overlayAst, 'savePaymentLine')
const removePayment = functionSource(overlayScript, overlayAst, 'removePaymentLine')
check(
  'overlay emits the selected-line mutation while keeping balance as its own draft action',
  JSON.stringify(requestActions(addPayment)) === JSON.stringify(['add-payment-method'])
    && JSON.stringify(requestActions(updatePayment)) === JSON.stringify(['update-payment-line'])
    && JSON.stringify(requestActions(removePayment))
      === JSON.stringify(['remove-balance-payment', 'remove-payment-line']),
)
check(
  '选择收款方式只追加草稿明细，不自动删除已有方式',
  !addPayment.includes('remove-payment-line')
    && !addPayment.includes('remove-balance-payment'),
)
const currentContexts = functionSource(
  workbenchScript,
  workbenchAst,
  'currentCheckoutCommandContexts',
)
check(
  'every edit reads live checkout contexts and request version instead of frozen preparation data',
  currentContexts.includes('const snapshot = checkout.value')
    && currentContexts.includes('clonePlain(snapshot.commandContexts)')
    && currentContexts.includes('checkoutRequestVersion(snapshot)')
    && currentContexts.includes('commandContexts: contexts')
    && currentContexts.includes('checkoutRequestVersion: Number(requestVersion)')
    && !currentContexts.includes('session.snapshot')
    && !currentContexts.includes('session.checkoutRequestVersion'),
)
const requestCheckout = functionSource(workbenchScript, workbenchAst, 'requestCheckoutAction')
const submissionContexts = functionSource(
  workbenchScript,
  workbenchAst,
  'checkoutSubmissionCommandContexts',
)
check(
  'each payment mutation rebuilds payload with latest version token and contexts',
  requestCheckout.includes('const current = currentCheckoutCommandContexts(session)')
    && requestCheckout.includes('checkoutRequestVersion: current.checkoutRequestVersion')
    && requestCheckout.includes('preparationToken: String(checkoutPreparationToken(checkout.value)')
    && requestCheckout.includes('commandContexts: current.commandContexts')
    && requestCheckout.includes('requestAction(effectiveAction, approvedPayload)'),
)
check(
  'one visible submit first freezes a server resource plan then submits with refreshed versions',
  requestCheckout.includes("submissionKey.replace(/^CHECKOUT-/, 'CHECKOUT_PREPARE-')")
    && requestCheckout.includes("await requestAction('prepare-checkout-submission'")
    && requestCheckout.includes('let refreshed = currentCheckoutCommandContexts(checkoutSession.value)')
    && requestCheckout.includes("if (!refreshed || String(checkout.value?.requestStatus || '') !== 'ready_for_submit')")
    && requestCheckout.includes("await requestAction('open-cashier-workbench', { silent: true })")
    && requestCheckout.includes('refreshed = currentCheckoutCommandContexts(checkoutSession.value)')
    && requestCheckout.includes('const submitContexts = checkoutSubmissionCommandContexts(refreshed.commandContexts)')
    && requestCheckout.includes('approvedPayload.checkoutRequestVersion = refreshed.checkoutRequestVersion')
    && requestCheckout.includes('approvedPayload.commandContexts = submitContexts')
    && submissionContexts.includes("const allowedKinds = new Set(['cashier_workspace', 'checkout_request'])")
    && submissionContexts.includes(".filter((context) => allowedKinds.has(String(context?.kind || '')))")
    && requestCheckout.indexOf("await requestAction('prepare-checkout-submission'")
      < requestCheckout.lastIndexOf('result = await requestAction(effectiveAction, approvedPayload)'),
)
const resolver = methodSlice(
  bridge,
  'function resolveCommandContexts(action, payload)',
  'function preparationKindForAction(action)',
)
check(
  'gateway bridge refreshes supplied contexts from the current public version store',
  resolver.includes('getCashierV3PublicVersion(context.kind, context.id)')
    && resolver.includes('expectedVersion: storeVersion')
    && !resolver.includes('expectedVersion: context.expectedVersion'),
)
const adapterResponseStart = bridge.indexOf("if (adapter && typeof adapter.request === 'function')")
const applyResponse = bridge.indexOf('const emission = emitCashierV3UiResult(result || {}, requestMeta)', adapterResponseStart)
const returnResponse = bridge.indexOf('return result || {}', applyResponse)
check(
  'successful response state is applied before the first edit resolves, enabling a second edit',
  adapterResponseStart >= 0
    && applyResponse > adapterResponseStart
    && returnResponse > applyResponse
    && bridge.includes('replaceCashierV3State(response.state)')
    && bridge.includes('mergeCashierV3PublicVersions(response.versions, respCtx'),
)

console.log('\n== payment method boundaries ==')
const kernelMethods = phpArrayValues(
  settlement,
  'private const PAYMENT_METHODS = [',
)
check(
  'settlement authority exposes exactly seven bookkeeping methods',
  JSON.stringify(kernelMethods) === JSON.stringify(fixedMethods),
  JSON.stringify(kernelMethods),
)
const normalizerMethods = phpArrayValues(
  normalizer,
  'if (!in_array($method, [',
)
check(
  'gateway normalizer preserves seven methods plus legacy old-card recognition',
  JSON.stringify(normalizerMethods) === JSON.stringify([...fixedMethods, 'old_card_entry']),
  JSON.stringify(normalizerMethods),
)
const domainAdd = methodSlice(
  paymentDraft,
  'private static function addPayment(',
  'private static function updatePayment(',
)
check(
  'payment draft domain rejects old-card entry before accepting the seven methods',
  paymentDraft !== ''
    && domainAdd.includes('PAYMENT_OLD_CARD_ENTRY')
    && domainAdd.includes("'old_card_entry_separate_flow_required'")
    && domainAdd.includes('CashierV3CheckoutSettlementKernel::paymentMethods()'),
  paymentDraft === '' ? 'CashierV3CheckoutPaymentDraftServices.php missing' : '',
)
check(
  'checkout fact plan forbids an old-card payment-collected fact',
  factPlan.includes("if ($method === 'old_card_entry')")
    && factPlan.includes("throw self::failure('old_card_entry_payment_fact_forbidden')"),
)
check(
  'frontend displays old-card entry as a disabled separate operation',
  bridge.includes("{ id: 'old_card_entry', name: '旧卡录入', canAdd: false")
    && addPayment.includes('method.canAdd === false'),
)
check(
  'paper cash is absent from frontend and server method catalogs',
  !/\{\s*id:\s*['"]cash['"]/.test(bridge)
    && !kernelMethods.includes('cash')
    && !normalizerMethods.includes('cash'),
)
check(
  'all seven bookkeeping method codes are present in the frontend catalog',
  fixedMethods.every((method) => bridge.includes(`{ id: '${method}',`)),
)

console.log('\n== local preview payment behavior ==')
check(
  'preview executes add update and remove instead of returning a no-op demo response',
  fixture.includes("if (action === 'add-payment-method')")
    && fixture.includes("if (action === 'update-payment-line')")
    && fixture.includes("if (action === 'remove-payment-line')")
    && !fixture.slice(
      fixture.indexOf("if ([\n      'open-balance-payment'"),
      fixture.indexOf("if (action === 'print-sales-order-receipt')"),
    ).includes("'add-payment-method'"),
)
check(
  'preview defaults a new payment method to the remaining receivable amount',
  fixture.includes('const remainingAmount = Number(checkout.payment?.summary?.remainingAmount || 0)')
    && fixture.includes('remainingAmount,')
    && fixture.includes("{ method: payload.paymentMethodId }"),
)
check(
  'preview keeps each repeated method as an independent editable line',
  !fixture.includes('PD_FIXTURE_PAYMENT_METHOD_DUPLICATE')
    && !fixture.includes('PD_FIXTURE_PAYMENT_ALREADY_BALANCED')
    && fixture.includes("if (!/^(?:0|[1-9]\\d*)$/.test(amountText)")
    && fixture.includes('amount >= 0'),
)
check(
  'preview recomputes selected and remaining amounts after every mutation',
  fixture.includes('function recalculateCheckoutPayment(checkout)')
    && fixture.includes('checkout.payment.summary.selectedAmount = selectedAmount')
    && fixture.includes('checkout.payment.summary.remainingAmount = Math.max(0, receivableAmount - selectedAmount)'),
)
check(
  'preview advances checkout and workspace versions before exposing the next edit',
  fixture.includes('function advanceCheckoutPaymentDraft(checkout, state)')
    && fixture.includes('state.workspace.revision = workspaceVersion')
    && fixture.includes('checkout.checkoutRequestVersion = requestVersion')
    && fixture.includes('checkout.preparationToken = `PD-CHECKOUT-SNAPSHOT-TOKEN-${requestVersion}`')
    && fixture.includes('checkout.commandContexts = checkoutContexts('),
)

console.log(`\ncheckout-payment-ui-gateway-static: ${passed} passed, ${failed} failed`)
if (failed > 0) process.exit(1)
