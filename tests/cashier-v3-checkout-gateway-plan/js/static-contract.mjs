import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const testDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const root = path.resolve(testDir, '../..')
const gatewayPath = path.join(
  root,
  '后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php',
)
const policyPath = path.join(
  root,
  '后端代码/app/services/cashier/v3/registry/CashierV3ContextPolicyRegistry.php',
)
const catalogPath = path.join(
  root,
  '后端代码/app/services/cashier/v3/CashierV3ResourceKindCatalog.php',
)
const entitlementPath = path.join(
  root,
  '后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php',
)
const cashierModulePath = path.join(
  root,
  '后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php',
)
const bootstrapPath = path.join(
  root,
  '后端代码/app/services/cashier/v3/bootstrap/CashierV3Bootstrap.php',
)
const cashierConfigPath = path.join(root, '后端代码/config/cashier_v3.php')

const gateway = fs.readFileSync(gatewayPath, 'utf8')
const policy = fs.readFileSync(policyPath, 'utf8')
const catalog = fs.readFileSync(catalogPath, 'utf8')
const entitlement = fs.readFileSync(entitlementPath, 'utf8')
const cashierModule = fs.readFileSync(cashierModulePath, 'utf8')
const bootstrap = fs.readFileSync(bootstrapPath, 'utf8')
const cashierConfig = fs.readFileSync(cashierConfigPath, 'utf8')

let passed = 0
let failed = 0

function check(name, condition) {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
    return
  }
  failed += 1
  console.error(`FAIL ${name}`)
}

function methodSlice(source, signature, nextSignature) {
  const start = source.indexOf(signature)
  const end = source.indexOf(nextSignature, start + signature.length)
  return start >= 0 && end > start ? source.slice(start, end) : ''
}

function callArguments(source, needle) {
  const calls = []
  let cursor = 0
  while (cursor < source.length) {
    const start = source.indexOf(needle, cursor)
    if (start < 0) break
    let depth = 1
    let quote = ''
    let escaped = false
    const args = []
    let argumentStart = start + needle.length
    let index = argumentStart
    for (; index < source.length; index += 1) {
      const character = source[index]
      if (quote !== '') {
        if (escaped) {
          escaped = false
        } else if (character === '\\') {
          escaped = true
        } else if (character === quote) {
          quote = ''
        }
        continue
      }
      if (character === "'" || character === '"') {
        quote = character
      } else if (character === '(' || character === '[' || character === '{') {
        depth += 1
      } else if (character === ')' || character === ']' || character === '}') {
        depth -= 1
        if (depth === 0) {
          args.push(source.slice(argumentStart, index).trim())
          break
        }
      } else if (character === ',' && depth === 1) {
        args.push(source.slice(argumentStart, index).trim())
        argumentStart = index + 1
      }
    }
    if (depth !== 0) return []
    calls.push(args)
    cursor = index + 1
  }
  return calls
}

const submitStart = policy.indexOf('public function resolveCheckoutSubmitBranch')
const submitEnd = policy.indexOf('public function resolveRoomAssignmentBranch', submitStart)
const submitPolicy = policy.slice(submitStart, submitEnd)
check(
  'submit policy narrows client contexts to workspace and checkout request',
  submitPolicy.includes("$base['required'] = ['cashier_workspace', 'checkout_request'];")
    && submitPolicy.includes("$base['allowed'] = ['cashier_workspace', 'checkout_request'];")
    && submitPolicy.includes("$resolved['expand_from_checkout_resource_plan'] = true;"),
)

const replayBranch = gateway.indexOf('if ($existing) {')
const planExpansion = gateway.indexOf('$this->expandFollowUpFromCheckoutResourcePlan(', replayBranch)
check(
  'successful receipt replay happens before active resource plan loading',
  replayBranch >= 0 && planExpansion > replayBranch,
)

const replayHashStart = gateway.indexOf('protected function resourcePlanReplayContextsHash')
const replayHashEnd = gateway.indexOf(
  'protected function revalidateFollowUpCheckoutResourcePlan',
  replayHashStart,
)
const replayHashBody = gateway.slice(replayHashStart, replayHashEnd)
check(
  'receipt replay is based on stored effective context snapshot',
  replayHashBody.includes("$existing['contexts_json']")
    && replayHashBody.includes("$existing['contexts_hash']")
    && !replayHashBody.includes('loadCheckoutResourcePlan('),
)

const effectiveStart = gateway.indexOf('protected function effectiveCheckoutResourcePlanContextsHash')
const effectiveEnd = gateway.indexOf('protected function resourcePlanReplayContextsHash', effectiveStart)
const effectiveBody = gateway.slice(effectiveStart, effectiveEnd)
check(
  'effective contexts hash binds plan contract fingerprint and bound request version',
  effectiveBody.includes("$plan['resourcePlanFingerprint']")
    && effectiveBody.includes("$plan['planContractVersion']")
    && effectiveBody.includes("$plan['boundRequestVersion']")
    && effectiveBody.includes('$this->contextServices->fingerprint($contexts)'),
)

const consume = gateway.indexOf('$this->consumeCheckoutResourcePlanInTx(')
const business = gateway.indexOf('$result = $business([', consume)
const normalized = gateway.indexOf('$normalized = $this->normalizeBusinessResult(', business)
const bump = gateway.indexOf('$this->versionServices->bumpTouched(', normalized)
check(
  'plan is consumed before request CAS business then version bump and receipt completion',
  consume >= 0 && consume < business && business < normalized && normalized < bump,
)

const serverDiscoveryGuard = gateway.indexOf(
  "if (!empty($contract['expand_from_server_resource_discovery'])) {",
)
const serverDiscovery = gateway.indexOf(
  '$this->expandFromServerResourceDiscovery(',
  serverDiscoveryGuard,
)
const businessResourceLock = gateway.indexOf(
  '$this->versionServices->lockAndAssert($contexts)',
  serverDiscovery,
)
const serverRediscovery = gateway.indexOf(
  '$this->revalidateServerResourceDiscovery(',
  businessResourceLock,
)
check(
  'server-only discovery precedes business locks and rediscovery follows all locks',
  serverDiscoveryGuard >= 0
    && serverDiscovery > serverDiscoveryGuard
    && businessResourceLock > serverDiscovery
    && serverRediscovery > businessResourceLock
    && business > serverRediscovery,
)

check(
  'catalog comparison distinguishes canonical decimals from opaque byte ordered ids',
  catalog.includes("preg_match('/^[1-9][0-9]*$/D', $left)")
    && catalog.includes('strlen($left) <=> strlen($right)')
    && catalog.includes('return strcmp($left, $right);'),
)

const openSelector = methodSlice(
  entitlement,
  'public function openSelector(',
  'public function validateSelectedLinesInTx(',
)
const validateSelected = methodSlice(
  entitlement,
  'public function validateSelectedLinesInTx(',
  'public function assertDraftLineQuantityInTx(',
)
const assertQuantity = methodSlice(
  entitlement,
  'public function assertDraftLineQuantityInTx(',
  'private function loadRows(',
)
const openSelectorReads = callArguments(openSelector, '$this->loadRows(')
const validateSelectedReads = callArguments(validateSelected, '$this->loadRows(')
const assertQuantityReads = callArguments(assertQuantity, '$this->loadRows(')
check(
  'entitlement post-lock authority rechecks never reacquire lower-order row locks',
  openSelectorReads.length === 2
    && validateSelectedReads.length === 1
    && assertQuantityReads.length === 1
    && [...openSelectorReads, ...validateSelectedReads, ...assertQuantityReads]
      .every((args) => args.length >= 3 && args[2] === 'false'),
)

check(
  'prepare checkout is wired to server discovery and the production root partition',
  cashierModule.includes("registerCommand('prepare-checkout'")
    && cashierModule.includes('new CashierV3CheckoutPreparationServices(')
    && cashierModule.includes('configureServerResourceDiscovery(')
    && cashierModule.includes("[$preparation, 'discover']")
    && bootstrap.includes('CashierV3CashierModule::install($dispatcher, $assembler)'),
)

check(
  'checkout namespace secret is environment-only and has no source default',
  cashierConfig.includes("Env::get(\n        'cashier_v3.checkout_namespace_secret',\n        ''")
    && !cashierConfig.match(/[A-Fa-f0-9]{32,}/),
)

console.log(`CHECKOUT_GATEWAY_PLAN_STATIC_CONTRACT passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
