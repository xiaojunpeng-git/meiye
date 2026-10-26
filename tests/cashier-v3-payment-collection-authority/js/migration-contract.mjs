#!/usr/bin/env node

import crypto from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const testRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const root = path.resolve(testRoot, '../..')
const migration = path.join(root, '后端代码/database/upgrades/2026-07-29-收银V3正式收款权威')
const read = (name) => fs.readFileSync(path.join(migration, name), 'utf8')
const checklist = read('00-升级清单.md')
const precheck = read('01-升级前检查.sql')
const apply = read('02-正式升级.sql')
const postcheck = read('03-升级后验证.sql')
const rollback = read('04-回滚或应急说明.md')
const recovery = read('05-部分创表恢复.sql')

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

function createBlock(table) {
  const start = apply.indexOf(`CREATE TABLE IF NOT EXISTS \`${table}\``)
  const end = start >= 0 ? apply.indexOf(') ENGINE=InnoDB', start) : -1
  return start >= 0 && end > start ? apply.slice(start, end) : ''
}

function counts(block) {
  return {
    columns: (block.match(/^\s*`[^`]+`\s+/gm) || []).length,
    indexes: (block.match(/^\s*(?:PRIMARY KEY|UNIQUE KEY|KEY\s+)/gm) || []).length,
  }
}

const batch = createBlock('eb_cashier_v3_payment_collection_batch')
const collection = createBlock('eb_cashier_v3_payment_collection')
const batchCounts = counts(batch)
const collectionCounts = counts(collection)
const upgradeKey = '20260729-012-cashier-v3-payment-collection-authority-v1'

check('all migration stages use one stable upgrade key',
  [precheck, apply, postcheck, recovery].every((source) => source.includes(upgradeKey))
    && checklist.includes(upgradeKey))
check('migration creates only isolated batch and collection tables',
  batch !== '' && collection !== ''
    && (apply.match(/CREATE TABLE IF NOT EXISTS/g) || []).length === 2
    && !/ALTER TABLE|DROP TABLE|TRUNCATE TABLE|RENAME TABLE/i.test(apply))
check('batch schema has the frozen 43-column and 11-index shape',
  batchCounts.columns === 43 && batchCounts.indexes === 11,
  JSON.stringify(batchCounts))
check('collection schema has the frozen 51-column and 15-index shape',
  collectionCounts.columns === 51 && collectionCounts.indexes === 15,
  JSON.stringify(collectionCounts))
check('one checkout and sales order can create only one batch',
  batch.includes('UNIQUE KEY `uk_tenant_checkout` (`tenant_id`,`checkout_request_id`)')
    && batch.includes('UNIQUE KEY `uk_tenant_order` (`tenant_id`,`sales_order_id`)')
    && batch.includes('UNIQUE KEY `uk_tenant_natural` (`tenant_id`,`natural_key`)'))
check('one payment draft creates only one collection',
  collection.includes('UNIQUE KEY `uk_checkout_payment_draft` (`tenant_id`,`checkout_request_id`,`checkout_payment_draft_id`)')
    && collection.includes('UNIQUE KEY `uk_tenant_natural` (`tenant_id`,`natural_key`)')
    && collection.includes('UNIQUE KEY `uk_batch_line_no` (`tenant_id`,`batch_id`,`payment_line_no`)'))
check('paging and reconciliation indexes are explicit',
  batch.includes('KEY `idx_scope_settled` (`tenant_id`,`store_id`,`settled_at`,`id`)')
    && collection.includes('KEY `idx_scope_date_method` (`tenant_id`,`store_id`,`business_date`,`payment_method`,`collection_status`,`id`)')
    && collection.includes('KEY `idx_sales_order` (`tenant_id`,`sales_order_id`,`id`)'))
check('money, method, times and historical snapshots are materialized',
  ['collected_amount_cents', 'cash_performance_amount_cents', 'receivable_amount_cents']
    .every((field) => batch.includes(`\`${field}\` bigint(20) unsigned`))
    && ['payment_method', 'payment_method_name_snapshot', 'amount_cents',
      'payment_business_time_snapshot', 'payment_recorded_at_snapshot',
      'operator_name_snapshot', 'source_document_no_snapshot',
      'external_transaction_no_snapshot', 'remark_snapshot']
      .every((field) => collection.includes(`\`${field}\``)))
check('forward status version and reversal linkage are reserved',
  ['batch_status', 'batch_version', 'batch_direction', 'reversal_of_batch_id']
    .every((field) => batch.includes(`\`${field}\``))
    && ['collection_status', 'collection_version', 'collection_direction',
      'reversal_of_collection_id']
      .every((field) => collection.includes(`\`${field}\``)))
check('SQL remains within MySQL 5.6 syntax baseline',
  !/\bWITH\s+[A-Za-z_]|\bJSON\b|\bGENERATED\b|\bCHECK\s*\(|\bWINDOW\b|ROW_NUMBER\s*\(/i
    .test(precheck + apply + postcheck + recovery))
check('precheck requires exact checkout payment and sales-order dependencies',
  precheck.includes('@pc_dependency_tables=3')
    && precheck.includes('@pc_dependency_columns=80')
    && precheck.includes('@pc_target_tables=0')
    && precheck.includes("'PRECHECK_OK'"))
check('postcheck audits values order links totals methods and orphans',
  postcheck.includes('@pc_batch_columns=43')
    && postcheck.includes('@pc_collection_columns=51')
    && postcheck.includes('@pc_invalid_batches=0')
    && postcheck.includes('@pc_invalid_collections=0')
    && postcheck.includes('@pc_orphan_batches=0')
    && postcheck.includes('@pc_orphan_collections=0')
    && postcheck.includes('@pc_batch_total_mismatches=0')
    && postcheck.includes('@pc_duplicate_methods=0')
    && postcheck.includes("'POSTCHECK_OK'"))
check('partial-create recovery is read-only exact and empty-row gated',
  recovery.includes('@pc_target_tables=1')
    && recovery.includes('@pc_exact_tables=@pc_target_tables')
    && recovery.includes("(@pc_batch_rows+@pc_collection_rows)=0")
    && recovery.includes('PARTIAL_CREATE_RECOVERY_READY')
    && !/\bDROP\b|\bDELETE\b|\bTRUNCATE\b|\bALTER\b/i.test(recovery))
check('documentation preserves activation and independent-instance boundaries',
  checklist.includes('不激活 `submit-checkout`')
    && checklist.includes('不读取、不写入、不迁移 legacy')
    && checklist.includes('逐实例登记')
    && checklist.includes('`old_card_entry` 不是收款事实')
    && rollback.includes('未被产品经理点名'))

const checksumLines = read('SHA256SUMS.txt').trim().split(/\r?\n/).filter(Boolean)
const expectedFiles = [
  '00-升级清单.md', '01-升级前检查.sql', '02-正式升级.sql',
  '03-升级后验证.sql', '04-回滚或应急说明.md', '05-部分创表恢复.sql',
]
const checksums = new Map(checksumLines.map((lineText) => {
  const match = lineText.match(/^([0-9a-f]{64})  (.+)$/)
  return match ? [match[2], match[1]] : ['', '']
}))
const checksumOk = expectedFiles.every((name) => {
  const digest = crypto.createHash('sha256')
    .update(fs.readFileSync(path.join(migration, name))).digest('hex')
  return checksums.get(name) === digest
}) && checksums.size === expectedFiles.length && !checksums.has('')
check('SHA256SUMS freezes every executable and instruction file', checksumOk)

console.log(`PAYMENT_COLLECTION_AUTHORITY_MIGRATION passed=${passed} failed=${failed}`)
if (failed > 0) process.exit(1)
