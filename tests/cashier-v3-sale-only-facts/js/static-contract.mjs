import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const factDir = path.join(root, '后端代码/app/services/cashier/v3/fact')
const read = (name) => fs.readFileSync(path.join(factDir, name), 'utf8')
const ids = read('CashierV3CheckoutFactIdFactory.php')
const assembler = read('CashierV3SaleOnlyFactAssembler.php')
const phpTestDir = path.resolve(here, '../php')
const fixture = fs.readFileSync(path.join(phpTestDir, 'fixture.php'), 'utf8')
const contract = fs.readFileSync(path.join(phpTestDir, 'contract.php'), 'utf8')

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
      if (escaped) escaped = false
      else if (current === '\\') escaped = true
      else if ((state === 'single' && current === "'") || (state === 'double' && current === '"')) state = 'normal'
      continue
    }
    if (current === '/' && next === '/') {
      state = 'line-comment'
      index += 1
    } else if (current === '#') state = 'line-comment'
    else if (current === '/' && next === '*') {
      state = 'block-comment'
      index += 1
    } else if (current === "'") state = 'single'
    else if (current === '"') state = 'double'
    else if (['(', '[', '{'].includes(current)) stack.push(current)
    else if (Object.hasOwn(pairs, current) && stack.pop() !== pairs[current]) return false
  }
  return stack.length === 0 && ['normal', 'line-comment'].includes(state)
}

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

check('factory uses server HMAC for ids and natural keys',
  ids.includes("hash_hmac('sha256'")
    && ids.includes("'factVersion' => 1")
    && ids.includes("'checkout_fact:' . $factType . ':v1:'")
    && !ids.includes('commandIdempotencyKey'))
check('assembler accepts only typed authority plans plus locked aggregate and results',
  assembler.includes('CashierV3SalesOrderPlanV1 $salesOrderPlan')
    && assembler.includes('CashierV3PaymentCollectionPlanV1 $paymentCollectionPlan')
    && assembler.includes('array $lockedAggregate')
    && assembler.includes('array $completedEventAuthority'))
check('sale-only debt authority is explicit and fail closed',
  assembler.includes("'sale_only_fact_composition_required'")
    && assembler.includes("'sale_only_fact_debt_authority_invalid'")
    && assembler.includes('CashierV3CheckoutDebtAuthorityServices::authorityKey')
    && assembler.includes("'sale_only_fact_entitlement_line_forbidden'"))
check('facts use formal order lines and formal collection details as grain',
  assembler.includes("$sourceLineId = (string)$line['order_line_id']")
    && assembler.includes("$sourceLineId = (string)$collection['collection_id']")
    && assembler.includes("'paymentAuthorityKey' => (string)$collection['checkout_payment_authority_key']"))
check('salesperson and actual performance are materialized together',
  assembler.includes("'performanceType' => CashierV3CheckoutFactPlanV1::ACTUAL_PERFORMANCE")
    && assembler.includes("'employeeId' => 0")
    && assembler.includes("'performanceType' => CashierV3CheckoutFactPlanV1::SALES_PERFORMANCE")
    && assembler.includes("'amountCents' => $actualPerformanceAmount"))
check('balance fact is optional and labor outputs remain absent',
  assembler.includes("'balanceFacts' => $balanceFact === null ? [] : [$balanceFact]")
    && !assembler.includes('CONSUMPTION_PERFORMANCE')
    && !assembler.includes('LABOR_PERFORMANCE'))
check('a full balance or debt settlement may have zero accounting collections',
  assembler.includes('$hasNonCollectionSettlement')
    && assembler.includes("$request['balance_deduction_amount_cents']")
    && assembler.includes("$request['debt_amount_cents']")
    && assembler.includes('count($collections) === 0 && !$hasNonCollectionSettlement'))
check('checkout.completed event identity and four times are cross checked',
  assembler.includes("public const EVENT_TYPE = 'checkout.completed'")
    && assembler.includes("'aggregate_type'] !== 'sales_order'")
    && assembler.includes("'source_type'] !== 'submit-checkout'")
    && ['business_date', 'occurred_at', 'settled_at', 'recorded_at']
      .every((field) => assembler.includes(`['${field}']`)))
check('assembler opens no transaction and writes no table',
  !/Db::|persistInTx|insert\s*\(|update\s*\(|delete\s*\(/.test(assembler))
check('new PHP stays within PHP 7.4 and has balanced lexical structure',
  [ids, assembler, fixture, contract].every((source) => source.startsWith('<?php')
    && balancedPhpDelimiters(source)
    && !/\?->|#\[|\bmatch\s*\(|\breadonly\s+class\b/.test(source)))

console.log(`SALE_ONLY_FACT_STATIC passed=${passed} failed=${failed}`)
if (failed > 0) process.exit(1)
