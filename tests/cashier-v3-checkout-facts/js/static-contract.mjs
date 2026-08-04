import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const factDir = path.join(root, '后端代码/app/services/cashier/v3/fact')
const migrationDir = path.join(root, '后端代码/database/upgrades/2026-07-29-收银V3统一结账事实底座')
const read = (file) => fs.readFileSync(file, 'utf8')
let passed = 0
let failed = 0
function ok(name, condition) {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
  } else {
    failed += 1
    console.error(`FAIL ${name}`)
  }
}

const plan = read(path.join(factDir, 'CashierV3CheckoutFactPlanV1.php'))
const repository = read(path.join(factDir, 'ThinkPhpCashierV3CheckoutFactRepository.php'))
const eventRecorder = read(path.join(
  root,
  '后端代码/app/services/cashier/v3/event/CashierV3BusinessEventRecorder.php',
))
const apply = read(path.join(migrationDir, '02-正式升级.sql'))

ok('fact plan is an internal v1 contract independent of DTO',
  plan.includes('cashier-v3-checkout-fact-plan-v1')
    && plan.includes('fromInternalAuthority')
    && !plan.includes('RequestNormalizer'))
ok('old card entry is explicitly rejected',
  plan.includes('old_card_entry_payment_fact_forbidden'))
ok('actual performance is materialized and equation checked before persistence',
  plan.includes('actual_performance_recorded')
    && plan.includes('actual_performance_fact_required')
    && plan.includes('$actual !== $cash - $external'))
ok('repository requires caller-owned transaction and opens none',
  repository.includes("assertInTransaction('checkoutFacts.persistInTx')")
    && !repository.includes('Db::transaction('))
ok('facts require the transaction-local authoritative business event',
  repository.includes('assertBusinessEventAuthority')
    && repository.includes("->where('event_no', $context['business_event_no'])")
    && repository.includes('checkout_fact_business_event_missing')
    && repository.includes('checkout_fact_business_event_mismatch'))
ok('fact event authority is bound to checkout order and request identities',
  repository.includes("'event_type' => 'checkout.completed'")
    && repository.includes("'aggregate_type' => 'sales_order'")
    && repository.includes("'aggregate_id' => $context['order_id']")
    && repository.includes("'source_type' => 'submit-checkout'")
    && repository.includes("'source_id' => $context['checkout_request_id']"))
ok('event recorder accepts and returns one server-frozen business date and time set',
  eventRecorder.includes("$event['recorded_at'] ?? time()")
    && eventRecorder.includes("array_key_exists('business_date', $event)")
    && eventRecorder.includes('explicitBusinessDate(')
    && eventRecorder.includes("'recorded_at' => (int)($row['recorded_at'] ?? 0)"))
ok('repository forces server data scope',
  repository.includes('CashierV3DataScopeContext')
    && repository.includes('$dataScope->allowsStore')
    && repository.includes('checkout_fact_data_scope_denied'))
ok('natural key replay performs immutable comparison',
  repository.includes('lockByNaturalKey')
    && repository.includes('foreach ($expected as $column => $value)')
    && repository.includes('array_key_exists($column, $existing)')
    && repository.includes('fact_natural_key_payload_conflict'))
ok('missing natural keys avoid gap-lock creation deadlocks',
  repository.includes('Do not FOR UPDATE a missing unique key')
    && repository.indexOf("->field('fact_id')") < repository.indexOf('lockByNaturalKey'))
ok('reversal locks original and caps cumulative reversal',
  repository.includes('assertReversalTarget')
    && repository.includes("->where('fact_id', $row['reversal_of'])")
    && repository.includes('reversal_amount_exceeds_original'))
ok('repository writes only four dedicated fact tables',
  ['cashier_v3_sale_fact', 'cashier_v3_payment_fact', 'cashier_v3_balance_fact', 'cashier_v3_performance_fact']
    .every((table) => repository.includes(`'${table}'`))
    && !/(business_event|outbox|checkout_request).*insert/i.test(repository))
ok('migration uses signed bigint cents and no universal JSON payload',
  apply.includes('`amount_cents` bigint(20) NOT NULL')
    && !/`[^`]+`\s+json\b/i.test(apply)
    && !/`[^`]*(amount|delta)[^`]*`\s+(float|double|decimal)\b/i.test(apply))
ok('scope source and time indexes are present',
  apply.includes('idx_scope_date_status')
    && apply.includes('idx_scope_method_date')
    && apply.includes('idx_scope_type_date')
    && apply.includes('idx_checkout_line')
    && apply.includes('idx_reversal'))

console.log(`CHECKOUT_FACT_STATIC passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
