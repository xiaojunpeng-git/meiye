import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const settlement = path.join(root, '后端代码/app/services/cashier/v3/settlement')
const migration = path.join(root, '后端代码/database/upgrades/2026-07-29-收银V3结账请求来源权威')
const settlementMigration = path.join(
  root,
  '后端代码/database/upgrades/2026-07-29-收银V3结账请求与收款明细'
)
let passed = 0
let failed = 0
function ok(name, condition) {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
    return
  }
  failed += 1
  console.error(`FAIL ${name}`)
}
function read(file) {
  return fs.readFileSync(file, 'utf8')
}

const repository = read(path.join(settlement, 'ThinkPhpCashierV3CheckoutRequestRepository.php'))
const kernel = read(path.join(settlement, 'CashierV3CheckoutSettlementKernel.php'))
const projection = read(path.join(settlement, 'CashierV3CheckoutProjectionServices.php'))
const preparation = read(path.join(settlement, 'CashierV3CheckoutPreparationServices.php'))
const cashierPartition = read(path.join(
  root,
  '后端代码/app/services/cashier/v3/cashier/CashierV3CashierPartitionProvider.php'
))
const provider = read(path.join(settlement, 'CashierV3CheckoutRequestVersionProvider.php'))
const loader = read(path.join(settlement, 'CashierV3CheckoutSourceAuthorityLoader.php'))
const sources = read(path.join(settlement, 'CashierV3CheckoutVerifiedSourceSet.php'))
const bootstrap = read(path.join(root, '后端代码/app/services/cashier/v3/bootstrap/CashierV3Bootstrap.php'))
const apply = read(path.join(migration, '02-正式升级.sql'))
const settlementApply = read(path.join(settlementMigration, '02-正式升级.sql'))
const settlementPostcheck = read(path.join(settlementMigration, '03-升级后验证.sql'))

ok('ThinkPHP repository implements the production contract',
  repository.includes('implements CashierV3CheckoutRequestRepository'))
ok('repository requires the caller-owned active transaction',
  repository.includes("assertInTransaction('checkoutRequestPersistence')")
    && !repository.includes('Db::transaction('))
ok('repository consumes only fixed kernel table names',
  repository.includes("'eb_cashier_v3_checkout_request'")
    && repository.includes("'eb_cashier_v3_checkout_line_draft'")
    && repository.includes("'eb_cashier_v3_checkout_payment_draft'"))
ok('repository implements insert CAS no-write replay and exact affected checks',
  repository.includes("['insert', 'cas_replace_children', 'none']")
    && repository.includes("where('request_version', $expectedVersion)")
    && repository.includes('assertAffected('))
ok('request CAS precedes source replacement in code order',
  repository.indexOf("'checkout_request_cas'") < repository.indexOf("'checkout_source_delete'"))
ok('repository writes no business fact event or outbox table',
  !/Db::name\([^\n]*(business_event|outbox|payment_collected|sale_fact|performance)/i.test(repository))
ok('entitlement detail identity is explicit from kernel fingerprint through repository readback',
  kernel.includes("'entitlementSourceDetailId' => self::positiveInt(")
    && kernel.includes("'entitlementSourceDetailId' => $line['entitlementSourceDetailId']")
    && repository.includes("'entitlementSourceDetailId' => 'entitlement_source_detail_id'")
    && repository.includes('line_role,entitlement_source_detail_id,line_fingerprint')
    && !/authorityKey[^\n]*(split|explode|preg_match)/.test(kernel))
ok('settlement migration persists and validates role-specific entitlement detail identity',
  settlementApply.includes('`entitlement_source_detail_id` bigint(20) unsigned NOT NULL')
    && settlementPostcheck.includes('entitlement_source_detail_id<>0')
    && settlementPostcheck.includes('entitlement_source_detail_id=0'))
ok('checkout repository projection read is exact-scope latest-editing and lock free',
  repository.includes('function readLatestEditingProjection(')
    && repository.includes("->where('request_status', 'editing')")
    && repository.includes("->where('authority_snapshot_version', $preparedWorkspaceVersion)")
    && repository.includes("->where('resource_kind', 'cashier_workspace')")
    && repository.includes("->order('update_time desc,id desc')")
    && repository.includes("->where('state_context_id', $stateContextId)")
    && !repository.slice(
      repository.indexOf('function readLatestEditingProjection('),
      repository.indexOf('public function lockCurrentForKernelInTx(')
    ).includes('->lock(true)'))
ok('checkout projection token is correlation only and command contexts remain write authority',
  projection.includes("'preparationTokenRole' => 'projection_correlation_only'")
    && projection.includes("'kind' => 'cashier_workspace'")
    && projection.includes("'kind' => 'checkout_request'")
    && projection.includes('not an authentication or'))
ok('cashier root loads current checkout projection instead of a permanent null placeholder',
  cashierPartition.includes('CashierV3CheckoutProjectionServices')
    && cashierPartition.includes("'checkout' => $checkout")
    && !cashierPartition.includes("'checkout' => null"))

ok('checkout request provider is DataScoped',
  provider.includes('implements CashierV3DataScopedVersionProvider'))
ok('provider locks the real request row and scopes tenant store',
  provider.includes('->lock(true)')
    && provider.includes("->where('tenant_id', $dataScope->tenantId())")
    && provider.includes("->where('store_id', $dataScope->forcedStoreId())"))
ok('provider observes the aggregate-owned request CAS without double bumping',
  provider.includes('Gateway\'s bump phase observes that domain')
    && provider.includes('return $current;')
    && !provider.includes("Db::raw('request_version + 1')"))
ok('production bootstrap registers the checkout request authority provider',
  bootstrap.includes('CashierV3CheckoutRequestVersionProvider::KIND')
    && bootstrap.includes('new CashierV3CheckoutRequestVersionProvider()'))
ok('production bootstrap binds the server-side checkout source loader',
  bootstrap.includes('setCheckoutSourceLoader(new CashierV3CheckoutSourceAuthorityLoader())'))

ok('authority loader remains one-argument and read-only',
  loader.includes('function __invoke(string $checkoutRequestId)')
    && !loader.includes('->lock(true)')
    && !loader.includes('->insert(')
    && !loader.includes('->update(')
    && !loader.includes('->delete('))
ok('authority loader reads every request reference so cross-store corruption cannot be filtered out',
  !loader.includes("->where('tenant_id'")
    && !loader.includes("->where('store_id'")
    && loader.includes('fromServerVerifiedAuthorityRows'))
ok('authority loader returns request version and Gateway sources',
  loader.includes("'requestVersion' => $requestVersion")
    && loader.includes("'sources' => $verified->gatewaySources()"))
ok('source authority persists and projects the locked business source version',
  apply.includes('`source_version` bigint(20) unsigned NOT NULL')
    && repository.includes("'source_version' => $reference['sourceVersion']")
    && loader.includes("'sourceVersion' => $sourceVersion")
    && projection.includes("'expectedVersion' => $reference['sourceVersion']"))
ok('checkout preparation reads the Gateway normalized source version key',
  preparation.includes("$context['expected_version'] ?? $context['expectedVersion'] ?? 0")
    && preparation.includes('$this->contextVersion($contexts, $kind, $sourceId)'))
ok('lock-free projection rechecks workspace and request after reading children',
  repository.includes('$workspaceVersionAfter')
    && repository.includes('$requestAfter')
    && repository.includes('return null;'))
ok('source set supports only canonical source kinds',
  ['service_order', 'hang_order', 'reservation', 'room']
    .every((kind) => sources.includes(`'${kind}' =>`)))
ok('source set boundary is explicitly server-verified',
  sources.includes('fromServerVerifiedAuthorityRows')
    && sources.includes('Never construct this value from an HTTP payload'))
ok('source set rejects duplicate identity and cross scope',
  sources.includes('checkout_source_duplicate')
    && sources.includes('checkout_source_scope_mismatch'))
ok('migration is one technical source table with MySQL 5.6-compatible columns',
  (apply.match(/CREATE TABLE IF NOT EXISTS/g) || []).length === 1
    && apply.includes('eb_cashier_v3_checkout_source_reference')
    && !/\bjson\b/i.test(apply))

console.log(`CHECKOUT_REQUEST_STATIC passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
