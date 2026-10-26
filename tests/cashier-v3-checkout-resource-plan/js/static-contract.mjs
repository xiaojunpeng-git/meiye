import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const settlement = path.join(root, '后端代码/app/services/cashier/v3/settlement')
const migration = path.join(root, '后端代码/database/upgrades/2026-07-29-收银V3结账资源预锁计划')
const read = (file) => fs.readFileSync(file, 'utf8')
let passed = 0
let failed = 0
const ok = (name, condition) => {
  if (condition) {
    passed += 1
    process.stdout.write(`PASS ${name}\n`)
  } else {
    failed += 1
    process.stdout.write(`FAIL ${name}\n`)
  }
}

const plan = read(path.join(settlement, 'CashierV3CheckoutVerifiedResourcePlan.php'))
const repository = read(path.join(settlement, 'ThinkPhpCashierV3CheckoutResourcePlanRepository.php'))
const repositoryContract = read(path.join(settlement, 'CashierV3CheckoutResourcePlanRepository.php'))
const loader = read(path.join(settlement, 'CashierV3CheckoutResourcePlanLoader.php'))
const apply = read(path.join(migration, '02-正式升级.sql'))
const method = (source, name, nextName, nextVisibility = 'public') => {
  const start = source.indexOf(`public function ${name}`)
  const end = nextName
    ? source.indexOf(`${nextVisibility} function ${nextName}`, start + 1)
    : source.length
  return start >= 0 && end > start ? source.slice(start, end) : ''
}

ok('plan is explicitly server-only',
  plan.includes('must never be assembled from HTTP contexts')
    && plan.includes('fromServerVerifiedAuthorityRows'))
ok('plan has the fixed ten-thousand limits',
  plan.includes('MAX_RESOURCES = 10000') && plan.includes('MAX_ROLES = 10000'))
ok('one physical resource merges canonical multi-role bindings',
  plan.includes('$byPhysical')
    && plan.includes("$physical = $kind . \"\\0\" . $id")
    && plan.includes("sort($existing['roles'], SORT_STRING)"))
ok('plan requires positive bound and resource versions',
  plan.includes('checkout_resource_plan_bound_version_invalid')
    && plan.includes("positiveInt($row['expectedVersion']"))
ok('scope order access provider and authority are fingerprinted',
  ['scopeType', 'scopeId', 'lockOrder', 'accessMode', 'providerContractVersion', 'authorityFingerprint']
    .every((token) => plan.includes(`'${token}'`)))
ok('plan reuses the global kind order and resource comparator',
  plan.includes('CashierV3ResourceKindCatalog::lockOrderOf($kind)')
    && plan.includes('checkout_resource_plan_lock_order_mismatch')
    && plan.includes('CashierV3ResourceKindCatalog::compareResources(')
    && repository.includes('CashierV3ResourceKindCatalog::compareResources(')
    && loader.includes('CashierV3ResourceKindCatalog::compareResources('))
ok('repository requires caller transaction and exact ready request version',
  repository.includes("assertInTransaction('checkoutResourcePlanPersistence')")
    && repository.includes("request_status'] ?? '') !== 'ready_for_submit'")
    && repository.includes('boundRequestVersion()'))
ok('repository keeps immutable rows during invalidation',
  repository.includes('STATUS_INVALIDATED')
    && repository.includes("'plan_status' => self::STATUS_INVALIDATED")
    && !repository.includes("Db::name(self::ROW_TABLE)->delete"))
const invalidate = method(repository, 'invalidateInTx', 'markConsumedInTx')
const consume = method(repository, 'markConsumedInTx', 'supersedeActiveBeforeVersionInTx')
ok('plan transitions lock checkout request before plan header',
  invalidate.indexOf('$this->lockRequest') >= 0
    && invalidate.indexOf('$this->lockRequest') < invalidate.indexOf('$this->lockHeader')
    && consume.indexOf('$this->lockRequest') >= 0
    && consume.indexOf('$this->lockRequest') < consume.indexOf('$this->lockHeader'))
const supersede = method(
  repository,
  'supersedeActiveBeforeVersionInTx',
  'assertReplay',
  'private',
)
ok('checkout request CAS has an explicit immutable-row supersession operation',
  repositoryContract.includes('supersedeActiveBeforeVersionInTx')
    && supersede.includes("assertInTransaction('checkoutResourcePlanSupersession')")
    && supersede.indexOf('$this->lockRequest') >= 0
    && supersede.indexOf('$this->lockRequest') < supersede.indexOf('$this->lockActiveHeadersBeforeVersion')
    && supersede.includes("'plan_status' => self::STATUS_SUPERSEDED")
    && !supersede.includes('ROW_TABLE'))
ok('loader is read-only and binds exact current request version',
  !loader.includes('->lock(true)')
    && !loader.includes('->insert')
    && !loader.includes('->update')
    && loader.includes("->where('bound_request_version', $requestVersion)"))
ok('loader rebuilds contract and verifies header and row fingerprints',
  loader.includes('fromServerVerifiedAuthorityRows')
    && loader.includes('checkout_resource_plan_header_fingerprint_drift')
    && loader.includes('checkout_resource_plan_row_fingerprint_drift')
    && loader.includes("'tenantId' => $tenantId")
    && loader.includes("'storeId' => $storeId"))
ok('migration uses separate header and row tables',
  apply.includes('eb_cashier_v3_checkout_resource_plan`')
    && apply.includes('eb_cashier_v3_checkout_resource_plan_row`'))
ok('migration preserves physical and request-version uniqueness',
  apply.includes('UNIQUE KEY `uk_plan_physical_resource` (`plan_id`,`resource_kind`,`resource_id`)')
    && apply.includes('UNIQUE KEY `uk_request_version_resource` (`request_id`,`bound_request_version`,`resource_kind`,`resource_id`)'))
ok('scope and large text are MySQL 5.6 compatible',
  apply.includes('`roles_json` mediumtext NOT NULL')
    && !/`[^`]+`\s+json\b/i.test(apply)
    && !apply.includes('SKIP LOCKED')
    && !apply.includes('ROW_NUMBER('))

process.stdout.write(`CHECKOUT_RESOURCE_PLAN_STATIC_CONTRACT passed=${passed} failed=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
