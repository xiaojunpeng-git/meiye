#!/usr/bin/env node

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const testRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const root = path.resolve(testRoot, '../..')
const domain = path.join(root, '后端代码/app/services/cashier/v3/settlement')
const service = fs.readFileSync(path.join(domain, 'CashierV3CheckoutResultQueryServices.php'), 'utf8')
const repository = fs.readFileSync(path.join(domain, 'ThinkPhpCashierV3CheckoutResultReadRepository.php'), 'utf8')
const contract = fs.readFileSync(path.join(domain, 'CashierV3CheckoutResultReadRepository.php'), 'utf8')
const projection = fs.readFileSync(path.join(domain, 'CashierV3CheckoutProjectionServices.php'), 'utf8')
const requestRepository = fs.readFileSync(path.join(domain, 'ThinkPhpCashierV3CheckoutRequestRepository.php'), 'utf8')
const moduleSource = fs.readFileSync(path.join(root, '后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php'), 'utf8')

let passed = 0
let failed = 0
function check(name, condition) {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
  } else {
    failed += 1
    console.error(`FAIL ${name}`)
  }
}

check('receipt read is bound to original key and current store',
  repository.includes("->where('idempotency_key', $idempotencyKey)")
    && repository.includes("->where('store_id', $storeId)")
    && !repository.includes("->where('operator_id', $operatorId)"))
check('the service accepts only an original submit-checkout receipt',
  service.includes("private const ORIGINAL_ACTION = 'submit-checkout'")
    && service.includes('IDEMPOTENCY_KEY_CONFLICT'))
check('no-scope and cross-identity reads fail closed',
  service.includes('MODE_NONE')
    && service.includes('$operatorScope->storeId() !== $dataScope->forcedStoreId()')
    && service.includes('PERMISSION_DENIED'))
check('pending and missing results stay result_unknown',
  service.includes("'pending'")
    && service.includes("'not_found'")
    && service.includes('STATUS_RESULT_UNKNOWN'))
check('success is re-read from checkout request plus the composition authority',
  repository.includes("private const ORDER_TABLE = 'cashier_v3_sales_order'")
    && repository.includes("private const ENTITLEMENT_RECEIPT_TABLE = 'cashier_v3_entitlement_completion_receipt'")
    && repository.includes("private const REQUEST_TABLE = 'cashier_v3_checkout_request'")
    && repository.includes("->where('command_idempotency_key', $idempotencyKey)")
    && repository.includes("->where('last_idempotency_key', $idempotencyKey)")
    && repository.includes("->where('request_status', CashierV3CheckoutSettlementStateMachine::SUCCEEDED)"))
check('authority re-read is tenant organization store and state-context scoped',
  ['tenant_id', 'organization_id', 'store_id', 'state_context_id']
    .every((field) => repository.includes(`->where('${field}'`)))
check('sale-only and mixed require their exact settled forward CSO authority',
  repository.includes("->where('composition', $composition)")
    && repository.includes("->where('order_status', 'settled')")
    && repository.includes("->where('order_direction', 'forward')"))
check('entitlement-only and mixed require a completed real ECR authority',
  repository.includes("->where('status', 'completed')")
    && repository.includes("if ($composition === 'mixed')")
    && repository.includes('findEntitlementReceipt(')
    && repository.includes("'/^ECR-[0-9a-f]{40}$/D'")
    && service.includes("'salesOrder' => is_array($committed['salesOrder'] ?? null)")
    && service.includes("'entitlementCompletion' => is_array($committed['entitlementCompletion'] ?? null)"))
check('success DTO is deliberately minimal',
  service.includes("'requestId' => (string)$committed['checkoutRequest']['requestId']")
    && service.includes("'orderId' => (string)$committed['salesOrder']['orderId']")
    && !service.includes("'saleAmountCents' =>")
    && !service.includes("'memberId' =>"))
check('read path cannot create commands facts or transaction side effects',
  !/->(?:insert|insertAll|insertGetId|update|delete|lock)\s*\(/.test(repository)
    && !/Db::transaction\s*\(|startTrans\s*\(|commit\s*\(|rollback\s*\(/.test(repository + service)
    && !/cashier_v3_(?:sale|payment|balance|performance)_fact/.test(repository + service))
check('repository contract exposes reads only',
  contract.includes('findLatestCommittedCheckoutForWorkspace(')
    && contract.includes('findLatestCommittedSaleOnlyForWorkspace(')
    && contract.includes('findReceiptForActor(')
    && contract.includes('findCommittedCheckoutResult(')
    && contract.includes('findCommittedSaleOnlyResult(')
    && !/(?:persist|save|create|write)InTx\s*\(/.test(contract))
check('root projection prefers an editable request then falls back to a verified committed result',
  projection.indexOf('readLatestEditingProjection(') < projection.indexOf('findLatestCommittedCheckoutForWorkspace(')
    && projection.includes('projectSucceededResult($committed)')
    && moduleSource.includes('$checkoutRootProjection')
    && moduleSource.includes('$checkoutResultReads'))
check('production module installs final submit policy and successful recovery requests a root rebuild',
  moduleSource.includes('function registerSubmitCheckoutPolicy(')
    && moduleSource.includes("[$dispatcher->policies(), 'resolveCheckoutSubmitBranch']")
    && moduleSource.includes('self::registerSubmitCheckoutPolicy($dispatcher);')
    && moduleSource.includes("'return_root_state' => (string)($result['status'] ?? '')")
    && moduleSource.includes('=== CashierV3ResultCode::STATUS_SUCCESS'))
check('a completed root result cannot auto-resume or expose mutable command contexts',
  projection.includes("'resumeOnLoad' => false")
    && projection.includes("'canRetry' => false")
    && projection.includes("'commandContexts' => []"))
check('request success rechecks the exact real authority under the caller transaction',
  requestRepository.includes("CashierV3TransactionGuard::assertInTransaction('checkoutRequestMarkSucceeded')")
    && requestRepository.includes('lockCompletionAuthorityInTx(')
    && requestRepository.includes("private const SALES_ORDER_TABLE = 'cashier_v3_sales_order'")
    && requestRepository.includes("private const ENTITLEMENT_COMPLETION_RECEIPT_TABLE = 'cashier_v3_entitlement_completion_receipt'")
    && requestRepository.includes("->where('status', 'completed')")
    && !/Db::transaction\s*\(|startTrans\s*\(|commit\s*\(/.test(requestRepository))
check('entitlement-only commits zero payment drafts while other checkouts require a settlement source',
  requestRepository.includes('($entitlementOnly && $paymentCount !== 0)')
    && requestRepository.includes('!$this->hasSettlementSource($request, $paymentCount)')
    && requestRepository.includes("$request['balance_deduction_amount_cents']")
    && requestRepository.includes("$request['debt_amount_cents']")
    && requestRepository.includes('$paymentCount === 0 ? 0'))

for (const source of [service, repository, contract, projection, requestRepository]) {
  check('new PHP stays within PHP 7.4 syntax', !/\?->|#\[|\bmatch\s*\(|\breadonly\s+class\b/.test(source))
}

console.log(`CHECKOUT_RESULT_QUERY_STATIC passed=${passed} failed=${failed}`)
if (failed > 0) process.exit(1)
