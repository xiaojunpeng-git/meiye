#!/usr/bin/env node

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const testRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const root = path.resolve(testRoot, '../..')
const domain = path.join(root, '后端代码/app/services/cashier/v3/settlement/payment')
const cashier = path.join(root, '后端代码/app/services/cashier/v3')
const read = (file) => fs.readFileSync(file, 'utf8')

const plan = read(path.join(domain, 'CashierV3PaymentCollectionPlanV1.php'))
const ids = read(path.join(domain, 'CashierV3PaymentCollectionIdFactory.php'))
const contract = read(path.join(domain, 'CashierV3PaymentCollectionAuthorityWriter.php'))
const writer = read(path.join(domain, 'ThinkPhpCashierV3PaymentCollectionAuthorityWriter.php'))
const exception = read(path.join(domain, 'CashierV3PaymentCollectionAuthorityException.php'))
const kernel = read(path.join(cashier, 'settlement/CashierV3CheckoutSettlementKernel.php'))
const manifest = read(path.join(cashier, 'manifest/CashierV3ActionManifest.php'))
const submission = read(path.join(cashier, 'settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php'))

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
    } else if (Object.prototype.hasOwnProperty.call(pairs, current)) {
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

check('plan accepts only a locked aggregate and generated sales-order plan',
  plan.includes('public static function fromLockedCheckoutAggregate(')
    && plan.includes('CashierV3SalesOrderPlanV1 $salesOrder')
    && plan.includes('CashierV3CheckoutVerifiedSourceSet')
    && plan.includes('self::AGGREGATE_KEYS'))
check('only final sale-bearing checkout can produce formal collections',
  plan.includes("$normalized['request_status'] !== 'ready_for_submit'")
    && plan.includes("$normalized['last_operation'] !== 'prepare_submission'")
    && plan.includes("private const SALE_BEARING_COMPOSITIONS = ['sale_only', 'mixed']")
    && plan.includes("!in_array($normalized['composition'], self::SALE_BEARING_COMPOSITIONS, true)")
    && plan.includes("throw self::failure('payment_collection_sale_composition_required')")
    && plan.includes('public function composition(): string'))
check('seven bookkeeping methods are an exact server allowlist',
  ['unionpay', 'wechat', 'alipay', 'dianping_voucher', 'douyin_voucher',
    'partner_collection', 'other_collection']
    .every((method) => plan.includes(`'${method}' =>`))
    && plan.includes("$method === 'old_card_entry'")
    && plan.includes("'old_card_entry_payment_collection_forbidden'"))
check('one payment draft becomes one collection natural grain',
  plan.includes("'checkout_payment_draft_id' => $payment['payment_draft_id']")
    && plan.includes("'checkout_payment_collection:'")
    && plan.includes("$payment['payment_draft_id']")
    && plan.includes("'collection_count' => count($collectionRows)"))
check('stable HMAC identities exclude command idempotency',
  ids.includes("hash_hmac('sha256'")
    && ids.includes("'checkoutRequestId' => $checkoutRequestId")
    && ids.includes("'paymentDraftId' => $paymentDraftId")
    && !ids.includes('commandIdempotencyKey'))
check('collection totals equal checkout payment cash performance and receivable',
  plan.includes("$paymentTotal !== $request['selected_payment_amount_cents']")
    && plan.includes("$paymentTotal !== $request['cash_performance_amount_cents']")
    && plan.includes("$paymentTotal + $balanceTotal + $debtTotal")
    && plan.includes("!== $request['receivable_amount_cents']")
    && plan.includes("$balanceTotal = $request['balance_deduction_amount_cents']")
    && plan.includes("$debtTotal = $request['debt_amount_cents']"))
check('method time operator and source snapshots are explicit detail columns',
  ['payment_method_name_snapshot', 'payment_business_time_snapshot',
    'payment_recorded_at_snapshot', 'operator_name_snapshot',
    'source_document_no_snapshot', 'external_transaction_no_snapshot', 'remark_snapshot']
    .every((field) => plan.includes(`'${field}' =>`)))
check('final success times come only from server command arguments',
  plan.includes("'occurred_at' => $occurredAt")
    && plan.includes("'settled_at' => $settledAt")
    && plan.includes("'recorded_at' => $recordedAt")
    && plan.includes("$occurredAt < $request['operation_occurred_at']"))
check('generated and persisted sales-order identity is mandatory',
  plan.includes('payment_collection_sales_order_identity_mismatch')
    && plan.includes("$salesOrder->commandIdempotencyKey()")
    && writer.includes('assertPersistedSalesOrderIdentity($batch, $plan->composition())')
    && writer.includes("->where('order_id', $batch['sales_order_id'])")
    && writer.includes("$order['sale_amount_cents'] ?? -1")
    && writer.includes("->lock(true)"))
check('plan and writer bind the same sale-only or mixed composition',
  plan.includes("!hash_equals((string)$order['composition'], $request['composition'])")
    && writer.includes("in_array($expectedComposition, ['sale_only', 'mixed'], true)")
    && writer.includes("$order['composition'] ?? '') !== $expectedComposition")
    && !writer.includes("$order['composition'] ?? '') !== 'sale_only'"))
check('command idempotency immutable fingerprints and reversal slots are stored',
  plan.includes("'command_idempotency_key' => $commandIdempotencyKey")
    && plan.includes("$row['immutable_fingerprint'] = self::canonicalFingerprint($row)")
    && plan.includes("$batch['immutable_fingerprint'] = self::canonicalFingerprint")
    && plan.includes("'reversal_of_collection_id' => ''")
    && plan.includes("'collection_direction' => self::DIRECTION_FORWARD"))
check('writer contract is caller-owned transaction only',
  contract.includes('public function persistInTx(')
    && contract.includes('caller-owned final checkout transaction')
    && writer.includes("CashierV3TransactionGuard::assertInTransaction('paymentCollectionAuthority.persistInTx')")
    && !/Db::transaction\s*\(/.test(writer)
    && !/startTrans\s*\(|->commit\s*\(|->rollback\s*\(/.test(writer)
    && !/function\s+(save|persist)\s*\(/.test(writer)
    && writer.includes('public function persistInTx('))
check('request-level batch serializes concurrent multi-detail creators',
  writer.includes("->where('checkout_request_id', $batch['checkout_request_id'])")
    && writer.includes('isDuplicateKey($exception)')
    && writer.includes('lockBatchByCheckoutRequest(')
    && writer.includes('assertImmutableReplay($plan, $existing)'))
check('immutable replay verifies batch and every collection column',
  writer.includes('payment_collection_batch_payload_conflict')
    && writer.includes('payment_collection_replay_detail_count_conflict')
    && writer.includes('payment_collection_replay_detail_payload_conflict')
    && writer.includes("->order('payment_line_no asc,id asc')"))
check('server DataScope is enforced before the first collection write',
  writer.indexOf('$this->assertDataScope(') < writer.indexOf('Db::name(self::BATCH_TABLE)')
    && writer.includes('$dataScope->allowsStore($operatorScope->storeId())')
    && writer.includes('$operatorScope->operatorId() !== $dataScope->operatorId()'))
check('legacy tables and generic payment payloads are outside this domain',
  !/store_order|pay_type|paymentPayload|clientPayment/i.test(plan + ids + contract + writer + exception))
check('sale-only submit-checkout persists the seven-method collection authority in its final transaction',
  submission.includes('CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(')
    && submission.includes('$this->collections->persistInTx(')
    && manifest.includes("'required_event_types' => ['checkout.completed']"))

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

console.log(`PAYMENT_COLLECTION_AUTHORITY_STATIC passed=${passed} failed=${failed}`)
if (failed > 0) process.exit(1)
