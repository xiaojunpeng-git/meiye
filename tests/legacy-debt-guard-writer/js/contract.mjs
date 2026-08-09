import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const backend = path.join(root, '后端代码/app')
const debtServicePath = path.join(backend, 'services/order/StoreDebtServices.php')
const providerDir = path.join(backend, 'services/cashier/v3/checkout/provider')
const adapterPath = path.join(providerDir, 'CashierV3LegacyDebtGuardWriterAdapter.php')
const tokenPath = path.join(providerDir, 'CashierV3LegacyDebtMutationToken.php')
const guardPath = path.join(providerDir, 'CashierV3EntitlementDebtGuardProvider.php')
const contractsPath = path.join(providerDir, 'CashierV3EntitlementProviderContracts.php')

const debt = fs.readFileSync(debtServicePath, 'utf8')
const adapter = fs.readFileSync(adapterPath, 'utf8')
const token = fs.readFileSync(tokenPath, 'utf8')
const guard = fs.readFileSync(guardPath, 'utf8')
const contracts = fs.readFileSync(contractsPath, 'utf8')

let passed = 0
let failed = 0

function ok(name, condition, detail = '') {
  if (condition) {
    passed += 1
    process.stdout.write(`PASS ${name}\n`)
    return
  }
  failed += 1
  process.stdout.write(`FAIL ${name}${detail ? `: ${detail}` : ''}\n`)
}

function methodBody(source, name) {
  const startPattern = new RegExp(`^    (?:public|protected|private) function ${name}\\s*\\(`, 'm')
  const match = startPattern.exec(source)
  if (!match) return ''
  const afterStart = source.slice(match.index + match[0].length)
  const next = /^    (?:public|protected|private) function [A-Za-z0-9_]+\s*\(/m.exec(afterStart)
  return source.slice(match.index, next ? match.index + match[0].length + next.index : source.length)
}

function count(source, pattern) {
  return [...source.matchAll(pattern)].length
}

function phpFiles(dir) {
  const result = []
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const target = path.join(dir, entry.name)
    if (entry.isDirectory()) result.push(...phpFiles(target))
    if (entry.isFile() && entry.name.endsWith('.php')) result.push(target)
  }
  return result
}

function owningMethod(source, offset) {
  const methods = [...source.matchAll(/^    (?:public|protected|private) function ([A-Za-z0-9_]+)\s*\(/gm)]
  let owner = ''
  for (const match of methods) {
    if (match.index > offset) break
    owner = match[1]
  }
  return owner
}

function balancedDelimiters(source) {
  const pairs = { ')': '(', ']': '[', '}': '{' }
  const stack = []
  let state = 'code'
  for (let i = 0; i < source.length; i += 1) {
    const ch = source[i]
    const next = source[i + 1] || ''
    if (state === 'line') {
      if (ch === '\n') state = 'code'
      continue
    }
    if (state === 'block') {
      if (ch === '*' && next === '/') {
        state = 'code'
        i += 1
      }
      continue
    }
    if (state === 'single' || state === 'double') {
      if (ch === '\\') {
        i += 1
        continue
      }
      if ((state === 'single' && ch === "'") || (state === 'double' && ch === '"')) state = 'code'
      continue
    }
    if (ch === '/' && next === '/') {
      state = 'line'
      i += 1
      continue
    }
    if (ch === '#') {
      state = 'line'
      continue
    }
    if (ch === '/' && next === '*') {
      state = 'block'
      i += 1
      continue
    }
    if (ch === "'") {
      state = 'single'
      continue
    }
    if (ch === '"') {
      state = 'double'
      continue
    }
    if (['(', '[', '{'].includes(ch)) stack.push(ch)
    if (pairs[ch] && stack.pop() !== pairs[ch]) return false
  }
  return state === 'code' && stack.length === 0
}

ok(
  'changed PHP sources have balanced lexical delimiters',
  [debt, adapter, token, guard].every(balancedDelimiters)
)

ok(
  'writer contract version is frozen',
  /DEBT_GUARD_WRITER\s*=\s*'c2-entitlement-debt-guard-writer-v1'/.test(contracts)
)
ok(
  'adapter publishes exact create repay adjustment paths',
  /private const PATHS = \['create', 'repay', 'adjustment'\]/.test(adapter)
    && /'create' => \$this->contractVersion\(\)/.test(adapter)
    && /'repay' => \$this->contractVersion\(\)/.test(adapter)
    && /'adjustment' => \$this->contractVersion\(\)/.test(adapter)
)

const originLock = methodBody(adapter, 'lockOriginOrderInTx')
ok(
  'adapter locks guard before authoritative origin order',
  originLock.indexOf('lockOrCreateSnapshotInTx(') >= 0
    && originLock.indexOf('lockOrCreateSnapshotInTx(') < originLock.indexOf('originOrder($originOrderId, true)')
)
const debtLock = methodBody(adapter, 'lockDebtInTx')
ok(
  'adapter resolves origin guard before locking debt row',
  debtLock.indexOf('lockOriginOrderInTx(') >= 0
    && debtLock.indexOf('lockOriginOrderInTx(') < debtLock.indexOf('lockDebtForTokenInTx(')
)
ok(
  'adapter scope is fixed server-side and store comes from origin order',
  /CashierV3ScopeResolver::TENANT_SCOPE_ID/.test(adapter)
    && /\$storeId = \(int\)\(\$originOrder\['store_id'\]/.test(adapter)
    && /'source' => 'locked_origin_order'/.test(adapter)
)
ok(
  'guard validates authoritative order store for resolve lock and snapshot',
  count(guard, /assertOriginOrderScope\(/g) >= 5
    && /'originStoreId' => \(int\)\$locked\['store_id'\]/.test(guard)
    && /field\('id,store_id'\)/.test(guard)
)
ok(
  'same mutation key with different fingerprint or action fails closed',
    /debt_guard_mutation_key_conflict/.test(guard)
    && /request_fingerprint/.test(methodBody(guard, 'assertMutationReceiptMatches'))
    && /\(string\)\$existing\['action'\] !== \$action/.test(methodBody(guard, 'assertMutationReceiptMatches'))
)

const createOrder = methodBody(debt, 'createRepayOrder')
ok(
  'repay order creation is protected and requires a server mutation token',
  /^    protected function createRepayOrder\(\n        CashierV3LegacyDebtMutationToken \$token,/m.test(createOrder)
    && /lockDebtForTokenInTx\(\$token, \$debtId\)/.test(createOrder)
)
ok(
  'all repay-order call sites pass the token acquired with the debt lock',
  count(debt, /\$this->createRepayOrder\(\$guarded\['token'\],/g) === 4
)
ok(
  'repay store staff and source never come from request or pending payload',
  !/\$params\['(?:pay_store_id|staff_id|source)'\]/.test(debt)
    && !/\$pending\['(?:pay_store_id|staff_id)'\]/.test(debt)
    && /\$payStoreId = \$token->originStoreId\(\)/.test(createOrder)
    && /\$staffId = \$this->authoritativeDebtStaffId\(\$token\)/.test(createOrder)
)
ok(
  'pending callback facts are tied to locked debt and repay order',
  /rememberDebtRepayPending\(\n        CashierV3LegacyDebtMutationToken \$token,/.test(debt)
    && count(debt, /\$this->rememberDebtRepayPending\(\$guarded\['token'\],/g) === 3
    && /lockAndAssertRepayOrderRelation\(\$token, \$repayOrderId\)/.test(methodBody(debt, 'rememberDebtRepayPending'))
)
ok(
  'read formatting performs no lazy debt write',
  !/->update\(/.test(methodBody(debt, 'formatDebtList'))
)

const mutationOwners = new Map([
  ['$this->dao->save(', new Set(['createFromPaidOrder'])],
  ['$this->dao->update(', new Set(['voidDebtByOrderId', 'closeDebt', 'applyRepay', 'reverseRepayOnRefund'])],
  ['$this->itemDao->saveAll(', new Set(['createFromPaidOrder'])],
  ['$this->itemDao->update(', new Set(['allocateRepayToItems', 'reverseAllocateRepayToItems'])],
  ['$this->repayDao->save(', new Set(['applyRepay'])],
])
const unexpectedWrites = []
for (const [needle, allowed] of mutationOwners) {
  let cursor = 0
  while ((cursor = debt.indexOf(needle, cursor)) >= 0) {
    const owner = owningMethod(debt, cursor)
    if (!allowed.has(owner)) unexpectedWrites.push(`${needle}@${owner || 'unknown'}`)
    cursor += needle.length
  }
}
ok(
  'every legacy debt DAO write stays inside a reviewed guarded path',
  unexpectedWrites.length === 0,
  unexpectedWrites.join(', ')
)
const externalDebtMutators = []
for (const file of phpFiles(backend)) {
  if (file === debtServicePath) continue
  const source = fs.readFileSync(file, 'utf8').replace(/\s+/g, ' ')
  if (/Db::name\(['"]store_debt(?:_item|_repay)?['"]\).{0,600}?->(?:insert|update|delete)\s*\(/.test(source)
    || /StoreDebt(?:Item|Repay)?::.{0,300}?(?:create|save|update|delete|destroy)\s*\(/.test(source)) {
    externalDebtMutators.push(path.relative(backend, file))
  }
}
ok(
  'no second legacy debt writer exists outside StoreDebtServices',
  externalDebtMutators.length === 0,
  externalDebtMutators.join(', ')
)
ok(
  'all public debt mutations reuse an outer transaction or open one',
  /return \$this->isInDbTransaction\(\) \? \$runner\(\) : \$this->transaction\(\$runner\)/.test(
    methodBody(debt, 'runDebtMutationTransaction')
  )
    && count(debt, /runDebtMutationTransaction\(/g) >= 9
    && !/\$this->transaction\(function/.test(debt)
)
for (const method of ['voidDebtByOrderId', 'closeDebt']) {
  const body = methodBody(debt, method)
  ok(
    `${method} validates mutation receipt before state idempotency`,
    body.indexOf('beginMutationInTx(') >= 0
      && body.indexOf('beginMutationInTx(') < body.indexOf('Historical business idempotency'),
    method
  )
}

const applyRepay = methodBody(debt, 'applyRepay')
ok(
  'repay mutation receipt is checked before legacy business idempotency',
  applyRepay.indexOf('beginMutationInTx(') >= 0
    && applyRepay.indexOf('beginMutationInTx(') < applyRepay.indexOf("where('repay_no', $repayNo)")
    && /'payType' => \$payType/.test(applyRepay)
    && /'combinationInfoFingerprint'/.test(applyRepay)
)
ok(
  'origin order repayment total uses the locked token snapshot',
  /\$order = \$token->originOrder\(\)/.test(applyRepay)
    && /where\('id', \$token->originOrderId\(\)\)->update/.test(applyRepay)
)

const reverse = methodBody(debt, 'reverseRepayOnRefund')
ok(
  'refund reversal is keyed by cumulative refund and rejects over-reversal',
  /cumulativeRefundAmount/.test(reverse)
    && /legacy-debt:reverse:'/.test(reverse)
    && /补交退款累计金额不正确/.test(reverse)
    && /补交退款超过当前已还欠款/.test(reverse)
)
ok(
  'readiness requires the complete legacy writer schema',
  /'source', 'gendan_staff_id', 'is_gendan'/.test(adapter)
    && /'combination_info'/.test(adapter)
    && /'cart_info_id', 'product_id'/.test(adapter)
)

const tokenConstructors = []
for (const file of phpFiles(backend)) {
  const source = fs.readFileSync(file, 'utf8')
  if (/new CashierV3LegacyDebtMutationToken\s*\(/.test(source)) {
    tokenConstructors.push(path.relative(backend, file))
  }
}
ok(
  'only the guard adapter issues mutation tokens',
  tokenConstructors.length === 1
    && tokenConstructors[0] === 'services/cashier/v3/checkout/provider/CashierV3LegacyDebtGuardWriterAdapter.php',
  tokenConstructors.join(', ')
)
ok(
  'mutation token is immutable by API',
  /final class CashierV3LegacyDebtMutationToken/.test(token)
    && !/public function set[A-Z]/.test(token)
)

process.stdout.write(`ASSERT_PASSED=${passed}\n`)
process.stdout.write(`ASSERT_FAILED=${failed}\n`)
if (failed > 0) process.exit(1)
process.stdout.write('LEGACY_DEBT_GUARD_WRITER_STATIC=PASS\n')
