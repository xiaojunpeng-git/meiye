import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')

const moduleSource = read('后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php')
const submission = read('后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php')
const executionPort = read('后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php')
const gateway = read('后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php')
const manifest = read('后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php')
const projection = read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php')
const kernel = read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')

let passed = 0
let failed = 0
function ok(name, condition, detail = '') {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
    return
  }
  failed += 1
  console.log(`FAIL ${name}${detail ? `: ${detail}` : ''}`)
}

function ordered(source, needles) {
  let cursor = -1
  return needles.every((needle) => {
    cursor = source.indexOf(needle, cursor + 1)
    return cursor >= 0
  })
}

ok('SO-SUB-STATIC-01 production module installs the real submit and result handlers',
  moduleSource.includes('new ThinkPhpCashierV3CheckoutSubmissionExecutionPort(')
    && moduleSource.includes('new CashierV3CheckoutSubmissionOrchestrator(')
    && executionPort.includes('new CashierV3SaleOnlyCheckoutSubmissionServices(')
    && executionPort.includes('return $this->saleOnly->submitInTx($scope)')
    && moduleSource.includes("registerCommand('submit-checkout'")
    && moduleSource.includes("registerProjection('query-checkout-result'"))

ok('SO-SUB-STATIC-02 final submit expands only from the persisted server resource plan',
  moduleSource.includes("'submit-checkout',\n            ['cashier_workspace', 'checkout_request']")
    && moduleSource.includes('resolveCheckoutSubmitBranch'))

const submitManifestStart = manifest.indexOf("'submit-checkout' => [")
const submitManifestEnd = manifest.indexOf("'submit-debt-repayment'", submitManifestStart)
const submitManifest = submitManifestStart >= 0 && submitManifestEnd > submitManifestStart
  ? manifest.slice(submitManifestStart, submitManifestEnd)
  : ''
const synchronousCheckoutEvents = [
  'checkout.completed',
  'entitlement.writeoff.completed',
  'service.completed',
  'performance.consumption.recorded',
  'performance.labor.allocated',
  'gift.consumed',
  'inventory.batch.consumed',
  'inventory.shortage.recorded',
  'inventory.service_consumption.resolved',
]
ok('SO-SUB-STATIC-03 checkout completed has one required event and no asynchronous consumer',
  submitManifest.includes("'required_event_types' => ['checkout.completed']")
    && synchronousCheckoutEvents.every((eventType) => (
      submitManifest.includes(`'${eventType}' => []`)
    )))

ok('SO-SUB-STATIC-04 business writes are ordered inside one Gateway-owned transaction',
  gateway.includes('return Db::transaction(function () use (')
    && ordered(submission, [
      '$this->salesOrders->persistInTx(',
      '$this->collections->persistInTx(',
      '$eventRecorder->recordInTx(',
      '$this->facts->persistInTx(',
      '$this->requests->markSucceededInTx(',
      '$this->workspace->completeSnapshotCheckoutInTx(',
    ]))

ok('SO-SUB-STATIC-05 Gateway consumes the plan before business writes in the same transaction',
  ordered(gateway, [
    '$this->consumeCheckoutResourcePlanInTx(',
    '$result = $business([',
    '$this->versionServices->bumpTouched(',
    "->where('status', 0)",
  ]))

const paymentMethods = [
  'unionpay',
  'wechat',
  'alipay',
  'dianping_voucher',
  'douyin_voucher',
  'partner_collection',
  'other_collection',
]
ok('SO-SUB-STATIC-06 projection exposes the fixed seven bookkeeping methods',
  paymentMethods.every((method) => projection.includes(`'${method}' =>`)))

ok('SO-SUB-STATIC-07 old card entry remains separate from cash performance',
  kernel.includes("PAYMENT_OLD_CARD_ENTRY = 'old_card_entry'")
    && projection.includes('CashierV3CheckoutSettlementKernel::legacyEntryContract()')
    && projection.includes("'canAdd' => false")
    && projection.includes("'cashPerformanceEligible' => false"))

ok('SO-SUB-STATIC-08 succeeded checkout cannot resume or retry and has no command contexts',
  projection.includes("'resumeOnLoad' => false")
    && projection.includes("'canRetry' => false")
    && projection.includes("'commandContexts' => []"))

const frontendUsesPrepareNamespace = workbench.includes("createCashierV3CommandId('CHECKOUT_PREPARE')")
const finalRequiresPrepareNamespace = submission.includes(
  "'/^CHECKOUT_PREPARE-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D'",
)
const finalDoesNotAcceptBroadNamespace = !submission.includes("'/^CHECKOUT-[0-9a-f-]{36}$/D'")
ok('SO-SUB-STATIC-09 initial and final preparation identity use exact CHECKOUT_PREPARE namespace',
  frontendUsesPrepareNamespace && finalRequiresPrepareNamespace && finalDoesNotAcceptBroadNamespace,
  'final preparationRequestId must equal the frozen CHECKOUT_PREPARE-* creation identity')

const migrationDirectories = [
  '2026-07-27-收银V3命令与幂等底座',
  '2026-07-28-收银V3权益购物车草稿',
  '2026-07-28-收银V3统一事件与Outbox',
  '2026-07-29-收银V3销售购物车权威行',
  '2026-07-29-收银V3结账请求与收款明细',
  '2026-07-29-收银V3结账请求来源权威',
  '2026-07-29-收银V3结账资源预锁计划',
  '2026-07-29-收银V3正式销售订单权威',
  '2026-07-29-收银V3正式收款权威',
  '2026-07-29-收银V3统一结账事实底座',
]
ok('SO-SUB-STATIC-10 every canonical migration input exists',
  migrationDirectories.every((directory) => fs.existsSync(path.join(
    root,
    '后端代码/database/upgrades',
    directory,
    '02-正式升级.sql',
  ))))

ok('SO-SUB-STATIC-11 checkout source expansion preserves prior server discovery authority',
  gateway.includes("$contract['server_resource_discovery'] = $discovery;")
    && gateway.includes("$contract['server_resource_discovery_recheck_required'] = count($resources) > 0;")
    && gateway.includes("string $canonicalAction,\n        array $baseContract = []")
    && gateway.includes('$contract = $baseContract;')
    && gateway.includes('$this->expandFromServerResourceDiscovery(')
    && gateway.includes('$this->revalidateServerResourceDiscovery(')
    && (
      gateway.includes('$this->expandFollowUpFromCheckoutResourcePlan(')
      || gateway.includes('$this->expandFollowUpFromCheckoutRequest(')
    ))

console.log(`SALE_ONLY_SUBMISSION_STATIC assertions=${passed + failed} passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
