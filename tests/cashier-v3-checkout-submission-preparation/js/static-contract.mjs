import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = process.env.SUBMISSION_PREPARATION_BACKEND_ROOT
  || path.resolve(here, '../../../后端代码')
const servicePath = path.join(
  root,
  'app/services/cashier/v3/settlement/CashierV3CheckoutSubmissionPreparationServices.php'
)
const source = fs.readFileSync(servicePath, 'utf8')

const checks = [
  ['isolated service class exists', source.includes('final class CashierV3CheckoutSubmissionPreparationServices')],
  ['caller-owned transaction is required', source.includes("assertInTransaction('checkoutSubmissionPreparation')")],
  ['aggregate is locked by exact request version', source.includes('lockAggregateForEditInTx(')],
  ['authority is rebuilt from persistence rows', source.includes('$this->rebuilder->rebuild(')],
  ['kernel prepares ready submission', source.includes('CashierV3CheckoutSettlementKernel::prepareSubmission(')],
  ['resource plan is server verified', source.includes('CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(')],
  ['resource plan is persisted in the same call', source.includes('$this->resourcePlans->persistInTx(')],
  ['workspace and request are excluded from hidden plan', source.includes("['cashier_workspace', 'checkout_request']")],
  ['locked navigation contexts join the final plan',
    source.includes("'providerContractVersion' => 'gateway-locked-context-v1'")
      && source.includes('foreach ($contexts as $context)')],
  ['ordinary-context fingerprint is canonical',
    source.includes("'contractVersion' => 'gateway-locked-context-v1'")
      && source.includes('CashierV3CheckoutSettlementCanonicalizer::fingerprint([')],
  ['roles merge and mutate wins only when declared',
    source.includes('private static function mergeRoles(')
      && source.includes("$left === 'mutate' || $right === 'mutate'")],
  ['scope and lock order come from catalog',
    source.includes('CashierV3ResourceKindCatalog::scopeTypeOf($kind)')
      && source.includes('CashierV3ResourceKindCatalog::lockOrderOf($kind)')],
  ['balance debt and entitlement stay closed',
    source.includes("$entitlementLines !== []")
      && source.includes("$balance['amountCents'] !== 0")
      && source.includes("$debt['amountCents'] !== 0")],
  ['namespace secret is fail closed',
    source.includes("config('cashier_v3.checkout_namespace_secret')")
      && source.includes("'checkout_namespace_secret_missing'")],
  ['service does not own Gateway touched output', !source.includes("'touched' =>")],
  ['service has no direct Db access', !source.includes('think\\facade\\Db') && !source.includes('Db::')],
]

let failed = 0
for (const [name, ok] of checks) {
  if (ok) {
    console.log(`PASS: ${name}`)
  } else {
    failed += 1
    console.error(`FAIL: ${name}`)
  }
}

console.log(`CHECKOUT_SUBMISSION_PREPARATION_STATIC passed=${checks.length - failed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
