#!/usr/bin/env node

import crypto from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const testRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const root = path.resolve(testRoot, '../..')
const migration = path.join(root, '后端代码/database/upgrades/2026-07-29-收银V3正式销售订单权威')
const read = (name) => fs.readFileSync(path.join(migration, name), 'utf8')
const checklist = read('00-升级清单.md')
const precheck = read('01-升级前检查.sql')
const apply = read('02-正式升级.sql')
const postcheck = read('03-升级后验证.sql')
const recovery = read('05-部分创表恢复.sql')
const rollback = read('04-回滚或应急说明.md')

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

const header = createBlock('eb_cashier_v3_sales_order')
const line = createBlock('eb_cashier_v3_sales_order_line')
const headerCounts = counts(header)
const lineCounts = counts(line)
const upgradeKey = '20260729-011-cashier-v3-sales-order-authority-v1'

check('all migration stages use one stable upgrade key',
  [precheck, apply, postcheck, recovery].every((source) => source.includes(upgradeKey))
    && checklist.includes(upgradeKey))
check('migration creates only the isolated header and line tables',
  header !== '' && line !== ''
    && (apply.match(/CREATE TABLE IF NOT EXISTS/g) || []).length === 2
    && !/ALTER TABLE|DROP TABLE|TRUNCATE TABLE|RENAME TABLE/i.test(apply))
check('header schema has the frozen 44-column and 11-index shape',
  headerCounts.columns === 44 && headerCounts.indexes === 11,
  JSON.stringify(headerCounts))
check('line schema has the frozen 32-column and 9-index shape',
  lineCounts.columns === 32 && lineCounts.indexes === 9,
  JSON.stringify(lineCounts))
check('one checkout request and natural key can create only one header',
  header.includes('UNIQUE KEY `uk_tenant_checkout` (`tenant_id`,`checkout_request_id`)')
    && header.includes('UNIQUE KEY `uk_tenant_natural` (`tenant_id`,`natural_key`)')
    && header.includes('UNIQUE KEY `uk_tenant_order_no` (`tenant_id`,`order_no`)'))
check('future read model has a stable settled-time paging index',
  header.includes('KEY `idx_scope_settled` (`tenant_id`,`store_id`,`settled_at`,`id`)'))
check('one checkout line can create only one immutable order line',
  line.includes('UNIQUE KEY `uk_checkout_line` (`tenant_id`,`checkout_request_id`,`checkout_line_id`)')
    && line.includes('UNIQUE KEY `uk_tenant_natural` (`tenant_id`,`natural_key`)'))
check('guest, fixed-point money and historical snapshots have explicit columns',
  header.includes("`member_id` bigint(20) unsigned NOT NULL DEFAULT '0'")
    && line.includes("`member_id` bigint(20) unsigned NOT NULL DEFAULT '0'")
    && ['original_amount_cents', 'discount_amount_cents', 'sale_amount_cents']
      .every((column) => header.includes(`\`${column}\` bigint(20) unsigned`)
        && line.includes(`\`${column}\` bigint(20) unsigned`))
    && line.includes('`item_name_snapshot`')
    && line.includes('`item_code_snapshot`')
    && line.includes('`category_name_snapshot`'))
check('status version and reversal linkage are stored on header and line',
  ['order_status', 'order_version', 'order_direction', 'reversal_of_order_id']
    .every((column) => header.includes(`\`${column}\``))
    && ['line_status', 'line_version', 'line_direction', 'reversal_of_line_id']
      .every((column) => line.includes(`\`${column}\``)))
check('SQL remains within MySQL 5.6 syntax baseline',
  !/\bWITH\s+[A-Za-z_]|\bJSON\b|\bGENERATED\b|\bCHECK\s*\(|\bWINDOW\b|ROW_NUMBER\s*\(/i
    .test(precheck + apply + postcheck + recovery))
check('precheck requires exact checkout authority dependencies and fresh targets',
  precheck.includes('@so_dependency_tables=4')
    && precheck.includes('@so_dependency_columns=91')
    && precheck.includes('@so_target_tables=0')
    && precheck.includes("'PRECHECK_OK'"))
check('postcheck audits schema, values, orphans and header-line totals',
  postcheck.includes('@so_header_columns=44')
    && postcheck.includes('@so_line_columns=32')
    && postcheck.includes('@so_invalid_headers=0')
    && postcheck.includes('@so_invalid_lines=0')
    && postcheck.includes('@so_orphan_lines=0')
    && postcheck.includes('@so_header_line_mismatches=0')
    && postcheck.includes("'POSTCHECK_OK'"))
check('partial-create recovery is read-only, exact and empty-row gated',
  recovery.includes('@so_target_tables=1')
    && recovery.includes('@so_exact_tables=@so_target_tables')
    && recovery.includes("(@so_header_rows+@so_line_rows)=0")
    && recovery.includes('PARTIAL_CREATE_RECOVERY_READY')
    && !/\bDROP\b|\bDELETE\b|\bTRUNCATE\b|\bALTER\b/i.test(recovery))
check('documentation preserves activation and instance boundaries',
  checklist.includes('不激活 `submit-checkout`')
    && checklist.includes('不读取、不写入、不迁移 legacy `store_order`')
    && checklist.includes('`entitlement_only` 只形成核销/服务结果')
    && checklist.includes('逐实例登记结果')
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
  const digest = crypto.createHash('sha256').update(fs.readFileSync(path.join(migration, name))).digest('hex')
  return checksums.get(name) === digest
}) && checksums.size === expectedFiles.length && !checksums.has('')
check('SHA256SUMS freezes every executable and instruction file', checksumOk)

console.log(`SALES_ORDER_AUTHORITY_MIGRATION passed=${passed} failed=${failed}`)
if (failed > 0) process.exit(1)
