#!/usr/bin/env node

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const testRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const root = path.resolve(testRoot, '../..')
const domain = path.join(root, '后端代码/app/services/cashier/v3/order/settlement')
const cashier = path.join(root, '后端代码/app/services/cashier/v3')
const read = (file) => fs.readFileSync(file, 'utf8')

const plan = read(path.join(domain, 'CashierV3SalesOrderPlanV1.php'))
const ids = read(path.join(domain, 'CashierV3SalesOrderIdFactory.php'))
const contract = read(path.join(domain, 'CashierV3SalesOrderAuthorityWriter.php'))
const writer = read(path.join(domain, 'ThinkPhpCashierV3SalesOrderAuthorityWriter.php'))
const kernel = read(path.join(cashier, 'settlement/CashierV3CheckoutSettlementKernel.php'))
const manifest = read(path.join(cashier, 'manifest/CashierV3ActionManifest.php'))
const module = read(path.join(cashier, 'cashier/CashierV3CashierModule.php'))
const submission = read(path.join(cashier, 'settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php'))
const executionPort = read(path.join(
  cashier,
  'settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php',
))
const c5 = read(path.join(cashier, 'order/CashierV3SalesOrderQueryServices.php'))

function balancedPhpDelimiters(source) {
  const stack = []
  const pairs = { ')': '(', ']': '[', '}': '{' }
  let state = 'normal'
  let escaped = false
  for (let index = 0; index < source.length; index += 1) {
    const current = source[index]
    const next = source[index + 1] || ''
    if (state === 'line-comment') {
      if (current === '\n') state = 'normal'
      continue
    }
    if (state === 'block-comment') {
      if (current === '*' && next === '/') {
        state = 'normal'
        index += 1
      }
      continue
    }
    if (state === 'single' || state === 'double') {
      if (escaped) {
        escaped = false
      } else if (current === '\\') {
        escaped = true
      } else if ((state === 'single' && current === "'")
        || (state === 'double' && current === '"')) {
        state = 'normal'
      }
      continue
    }
    if (current === '/' && next === '/') {
      state = 'line-comment'
      index += 1
    } else if (current === '#') {
      state = 'line-comment'
    } else if (current === '/' && next === '*') {
      state = 'block-comment'
      index += 1
    } else if (current === "'") {
      state = 'single'
    } else if (current === '"') {
      state = 'double'
    } else if (['(', '[', '{'].includes(current)) {
      stack.push(current)
    } else if (Object.hasOwn(pairs, current)) {
      if (stack.pop() !== pairs[current]) return false
    }
  }
  return stack.length === 0 && ['normal', 'line-comment'].includes(state)
}

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

check('plan has one strict locked-aggregate factory',
  (plan.match(/public static function fromLockedCheckoutAggregate\s*\(/g) || []).length === 1
    && plan.includes("['request', 'lines', 'payments', 'sources', 'currentRequest', 'verifiedSources']")
    && plan.includes('instanceof CashierV3CheckoutVerifiedSourceSet'))
check('only ready-for-submit preparation can produce a sales-order plan',
  plan.includes("$normalized['request_status'] !== 'ready_for_submit'")
    && plan.includes("$normalized['last_operation'] !== 'prepare_submission'"))
check('formal order grain is one checkout request with stable natural keys',
  plan.includes("'natural_key' => 'checkout_sales_order:' . $request['request_id']")
    && plan.includes("'checkout_sales_order_line:'")
    && ids.includes("'checkoutRequestId' => $checkoutRequestId")
    && !ids.includes("'commandIdempotencyKey'"))
check('server HMAC derives opaque order and line identities while document numbers stay authoritative',
  ids.includes("hash_hmac('sha256'")
    && ids.includes("return $prefix . '-' . substr")
    && !ids.includes("'SO-' . str_replace('-', '', $businessDate)")
    && plan.includes('CashierV3BusinessDocumentNumberServices::isSalesOrderNo'))
check('guest is non-negative rather than positive member authority',
  plan.includes("'member_id' => self::nonNegativeInt($request['member_id']")
    && plan.includes("$normalized['member_id'] === 0")
    && !plan.includes("positiveInt($request['member_id']"))
check('product card and project are the only formal line types',
  plan.includes("private const SALE_TYPES = ['product', 'card', 'project']")
    && plan.includes("$role === 'sale'")
    && plan.includes("$role !== 'entitlement_service'"))
check('entitlement-only checkout cannot create a zero-sale order',
  plan.includes("$normalized['composition'] === 'entitlement_only'")
    && plan.includes("throw self::failure('sales_order_formal_sale_line_required')")
    && plan.includes("private const COMPOSITIONS = ['sale_only', 'mixed']"))
check('checkout line and historical catalog snapshots are persisted',
  ['checkout_line_id', 'item_type', 'item_id', 'item_version', 'item_code_snapshot',
    'item_name_snapshot', 'category_id_snapshot', 'category_name_snapshot', 'quantity',
    'original_amount_cents', 'discount_amount_cents', 'sale_amount_cents']
    .every((field) => plan.includes(`'${field}' =>`)))
check('amount equation and locked aggregate totals are enforced',
  plan.includes("$sale !== $original - $discount")
    && plan.includes('sales_order_checkout_total_mismatch')
    && plan.includes('sales_order_checkout_not_balanced'))
check('final order time is server command time, not checkout preparation time',
  plan.includes("'occurred_at' => $occurredAt")
    && plan.includes("$settledAt < $occurredAt")
    && !plan.includes("'occurred_at' => $request['operation_occurred_at']"))
check('balance and debt authority keys and versions are fail-closed',
  plan.includes('checkout_balance_authority_incomplete')
    && plan.includes('checkout_debt_authority_incomplete')
    && plan.includes('zero_balance_authority_must_be_empty')
    && plan.includes('zero_debt_authority_must_be_empty'))
check('command idempotency and immutable fingerprints are first-class columns',
  plan.includes("'command_idempotency_key' => $commandIdempotencyKey")
    && plan.includes("$row['immutable_fingerprint'] = self::canonicalFingerprint($row)")
    && plan.includes("$header['immutable_fingerprint'] = self::canonicalFingerprint"))
check('status version and reversal linkage are reserved without rewriting history',
  plan.includes("'order_status' => self::ORDER_STATUS_SETTLED")
    && plan.includes("'order_version' => 1")
    && plan.includes("'order_direction' => self::ORDER_DIRECTION_FORWARD")
    && plan.includes("'reversal_of_order_id' => ''")
    && plan.includes("'reversal_of_line_id' => ''"))
check('writer contract requires caller-owned transaction API',
  contract.includes('public function persistInTx(')
    && contract.includes('caller-owned final'))
check('writer asserts a real transaction and never owns transaction boundaries',
  writer.includes("CashierV3TransactionGuard::assertInTransaction('salesOrderAuthority.persistInTx')")
    && !/Db::transaction\s*\(/.test(writer)
    && !/startTrans\s*\(|->commit\s*\(|->rollback\s*\(/.test(writer))
check('writer protects concurrent creation with unique-key replay',
  writer.includes('isDuplicateKey($exception)')
    && writer.includes('lockHeaderByCheckoutRequest(')
    && writer.includes('assertImmutableReplay($plan, $existing)'))
check('writer verifies every immutable header and line on replay',
  writer.includes('sales_order_natural_key_payload_conflict')
    && writer.includes('sales_order_replay_line_count_conflict')
    && writer.includes('sales_order_replay_line_payload_conflict')
    && writer.includes("->lock(true)"))
check('server DataScope is enforced before the first write',
  writer.indexOf('$this->assertDataScope(') < writer.indexOf('Db::name(self::HEADER_TABLE)')
    && writer.includes('$dataScope->allowsStore($operatorScope->storeId())'))
check('legacy store_order is not read or written',
  !/Db::name\s*\([^\n)]*store_order/i.test(plan + ids + contract + writer))
check('sale-only submit-checkout persists the formal sales order in its final transaction',
  submission.includes('CashierV3SalesOrderPlanV1::fromLockedCheckoutAggregate(')
    && submission.includes('$this->salesOrders->persistInTx(')
    && module.includes('CashierV3CheckoutSubmissionOrchestrator')
    && module.includes('ThinkPhpCashierV3CheckoutSubmissionExecutionPort')
    && executionPort.includes('CashierV3SaleOnlyCheckoutSubmissionServices')
    && executionPort.includes('return $this->saleOnly->submitInTx($scope)')
    && manifest.includes("'required_event_types' => ['checkout.completed']"))
check('sales order query prefers V3 authority and keeps explicit legacy compatibility',
  c5.includes('queryAuthoritySalesOrders')
    && c5.includes('cashier_v3_sales_order')
    && c5.includes("Db::name('store_order')")
    && c5.includes('partial_legacy_snapshot'))

const phpFiles = [
  ...fs.readdirSync(domain).filter((name) => name.endsWith('.php')).map((name) => path.join(domain, name)),
  path.join(testRoot, 'php/fixture.php'),
  path.join(testRoot, 'php/contract.php'),
]
const phpSources = phpFiles.map((file) => read(file))
check('all new PHP files have balanced lexical structure',
  phpSources.every((source) => source.startsWith('<?php') && balancedPhpDelimiters(source)))
check('new PHP code stays within the PHP 7.4 syntax baseline',
  phpSources.every((source) => !/\?->|#\[|\bmatch\s*\(|\breadonly\s+class\b/.test(source)))

console.log(`SALES_ORDER_AUTHORITY_STATIC passed=${passed} failed=${failed}`)
if (failed > 0) process.exit(1)
