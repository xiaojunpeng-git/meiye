import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(here, '../../..')
const providerDir = path.join(repo, '后端代码/app/services/cashier/v3/checkout/provider')
const migrationDir = path.join(repo, '后端代码/database/upgrades/2026-07-29-收银V3会员余额权威')

const provider = fs.readFileSync(path.join(providerDir, 'CashierV3MemberBalanceProvider.php'), 'utf8')
const adapter = fs.readFileSync(path.join(providerDir, 'CashierV3MemberBalanceWriterAdapter.php'), 'utf8')
const migration = fs.readFileSync(path.join(migrationDir, '02-正式升级.sql'), 'utf8')
const precheck = fs.readFileSync(path.join(migrationDir, '01-升级前检查.sql'), 'utf8')
const postcheck = fs.readFileSync(path.join(migrationDir, '03-升级后验证.sql'), 'utf8')
const checklist = fs.readFileSync(path.join(migrationDir, '00-升级清单.md'), 'utf8')

const checks = [
  ['provider is DataScoped and owns member_balance',
    provider.includes('implements CashierV3DataScopedVersionProvider')
      && provider.includes("public const KIND = 'member_balance'")],
  ['provider locks real user row and reads real positive version',
    provider.includes("Db::name(self::TABLE)")
      && provider.includes('->lock(true)')
      && provider.includes("'accountVersion' => $version")],
  ['provider validates total equals principal plus gift',
    provider.includes('if ($principal + $gift !== $total)')
      && provider.includes("'member_balance_invariant_invalid'")],
  ['provider version bump is mutation-owned observation',
    provider.includes('memberBalanceObserveMutationVersion')
      && provider.includes('Returning the current real-row version prevents a second fake bump.')
      && !provider.includes("Db::name('cashier_v3_member_balance_version')")],
  ['adapter requires the outer transaction and integer cents',
    adapter.includes("assertInTransaction('memberBalanceCheckoutDeduct')")
      && adapter.includes('MAX_AMOUNT_CENTS')
      && adapter.includes('centsToMoney')],
  ['adapter uses principal-first atomic writer with stable idempotency',
    adapter.includes('->deductPreferBen(')
      && adapter.includes("return 'cv3-balance-' . hash('sha256'")
      && adapter.includes("'deductedPrincipalCents'")
      && adapter.includes("'deductedGiftCents'")],
  ['adapter enforces exact one-version mutation',
    adapter.includes("'member_balance_version_advance_invalid'")
      && adapter.includes("$before['accountVersion'] + 1")],
  ['migration creates main-row version and one trigger',
    migration.includes('ADD COLUMN `balance_version` bigint(20) unsigned NOT NULL DEFAULT')
      && migration.includes('CREATE TRIGGER `eb_user_balance_version_bu`')
      && migration.includes('SET NEW.`balance_version` = OLD.`balance_version` + 1')],
  ['trigger blocks version-only tampering',
    migration.includes('SET NEW.`balance_version` = OLD.`balance_version`;')],
  ['migration has no shadow balance version table',
    !migration.includes('CREATE TABLE')
      && !migration.includes('cashier_v3_member_balance_version')],
  ['precheck detects trigger collision and malformed partial DDL',
    precheck.includes('@mba_before_update_triggers')
      && precheck.includes('@mba_trigger_partial_valid')
      && precheck.includes('STOP_CASHIER_V3_MEMBER_BALANCE_PRECHECK_FAILED')],
  ['postcheck lists reconciliation instead of silently repairing',
    postcheck.includes('balance_reconciliation_required_count')
      && postcheck.includes('RECONCILIATION_REQUIRED')
      && checklist.includes('不静默纠正旧数据')],
  ['no gateway, bootstrap, manifest or action activation is present',
    !provider.includes('CashierV3Bootstrap')
      && !adapter.includes('CashierV3Bootstrap')
      && !adapter.includes('registerAction')],
]

let failed = 0
for (const [name, ok] of checks) {
  if (ok) {
    console.log(`PASS ${name}`)
  } else {
    failed += 1
    console.error(`FAIL ${name}`)
  }
}
console.log(`CHECKOUT_BALANCE_STATIC passed=${checks.length - failed} failed=${failed}`)
if (failed > 0) process.exit(1)
