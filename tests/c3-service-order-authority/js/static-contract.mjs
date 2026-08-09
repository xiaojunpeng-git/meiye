import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const backend = path.join(root, '后端代码/app/services/cashier/v3')
const service = path.join(backend, 'service')
const migration = path.join(root, '后端代码/database/upgrades/2026-07-29-C3服务单权益占用权威源')

const read = (file) => fs.readFileSync(file, 'utf8')
const authority = read(path.join(service, 'CashierV3ServiceOrderOccupationAuthorityProvider.php'))
const repository = read(path.join(service, 'ThinkPhpCashierV3ServiceOrderRepository.php'))
const state = read(path.join(service, 'CashierV3ServiceOrderState.php'))
const applySql = read(path.join(migration, '02-正式升级.sql'))
const recoverySql = read(path.join(migration, '05-部分创表恢复.sql'))

const checks = [
  ['implements frozen authority interface', authority.includes('implements CashierV3ServiceOrderOccupationAuthority')],
  ['returns frozen contract version', authority.includes('CashierV3EntitlementProviderContracts::SERVICE_ORDER_OCCUPATION')],
  ['transaction guard is mandatory', authority.includes("assertInTransaction('c3ServiceOrderOccupationAuthority')")],
  ['guard locks before complete set', authority.indexOf('lockOrCreateEntitlementGuard(') < authority.indexOf('lockOccupationSet(')],
  ['direct reservation and service order are accepted', ['direct', 'reservation', 'service_order'].every((v) => authority.includes(`'${v}'`))],
  ['only current service order converts', authority.includes("$request['source']['serviceOrderId'] === $orderId")],
  ['competitors are not DataScope filtered', authority.includes('Competing orders are counted across stores') && authority.includes('assertCurrentOrderScope')],
  ['missing current active contributor fails closed', authority.includes('current_service_order_not_active_contributor')],
  ['empty range uses insert ignore guard', repository.includes('INSERT IGNORE INTO `eb_cashier_v3_service_order_entitlement_guard`')],
  ['lock order is guard order complete lines', repository.includes('guard -> service order id -> complete line set')],
  ['no physical delete API exists', !repository.match(/->delete\s*\(/) && !repository.match(/TRUNCATE/i)],
  ['ordinary state machine excludes completion', state.includes('service_order_completion_requires_business_transaction') || !state.match(/PENDING_CHECKOUT[^\n]+COMPLETED/)],
  ['four canonical tables are created', [
    'eb_cashier_v3_service_order',
    'eb_cashier_v3_service_order_line',
    'eb_cashier_v3_service_order_entitlement_guard',
    'eb_cashier_v3_service_order_operation',
  ].every((table) => applySql.includes(`CREATE TABLE IF NOT EXISTS \`${table}\``))],
  ['guard unique key is canonical', applySql.includes('UNIQUE KEY `uk_tenant_entitlement_detail` (`tenant_id`,`entitlement_source_detail_id`)')],
  ['operation idempotency is tenant scoped', applySql.includes('UNIQUE KEY `uk_tenant_command_idem` (`tenant_id`,`command_idempotency_key`)')],
  ['partial recovery is non destructive', recoverySql.includes('Read-only, non-destructive') && !recoverySql.match(/\b(DROP TABLE|TRUNCATE|DELETE FROM)\b/i)],
]

let failed = 0
for (const [name, ok] of checks) {
  if (ok) console.log(`PASS ${name}`)
  else {
    failed += 1
    console.error(`FAIL ${name}`)
  }
}
console.log(`C3_STATIC_CONTRACT passed=${checks.length - failed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
