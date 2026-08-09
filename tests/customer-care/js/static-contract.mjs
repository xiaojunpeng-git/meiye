import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import crypto from 'node:crypto'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const sourceDir = path.join(root, '后端代码/app/services/customer/care')
const migrationDir = path.join(root, '后端代码/database/upgrades/2026-07-29-客情任务与记录内核')
const phpTestDir = path.join(root, 'tests/customer-care/php')
let passed = 0
let failed = 0

function ok(name, condition, detail = '') {
  if (condition) {
    passed += 1
    process.stdout.write(`PASS ${name}\n`)
    return
  }
  failed += 1
  process.stdout.write(`FAIL ${name}${detail ? ` -> ${detail}` : ''}\n`)
}

function read(file) {
  return fs.readFileSync(file, 'utf8')
}

function balancedPhp(source) {
  const stack = []
  const pairs = { ')': '(', ']': '[', '}': '{' }
  let mode = 'code'
  for (let index = 0; index < source.length; index += 1) {
    const current = source[index]
    const next = source[index + 1] ?? ''
    if (mode === 'line') {
      if (current === '\n') mode = 'code'
      continue
    }
    if (mode === 'block') {
      if (current === '*' && next === '/') {
        mode = 'code'
        index += 1
      }
      continue
    }
    if (mode === 'single' || mode === 'double') {
      if (current === '\\') {
        index += 1
        continue
      }
      if ((mode === 'single' && current === "'") || (mode === 'double' && current === '"')) {
        mode = 'code'
      }
      continue
    }
    if (current === '/' && next === '/') {
      mode = 'line'
      index += 1
      continue
    }
    if (current === '#') {
      mode = 'line'
      continue
    }
    if (current === '/' && next === '*') {
      mode = 'block'
      index += 1
      continue
    }
    if (current === "'") {
      mode = 'single'
      continue
    }
    if (current === '"') {
      mode = 'double'
      continue
    }
    if ('([{'.includes(current)) stack.push(current)
    if (')]}'.includes(current) && stack.pop() !== pairs[current]) return false
  }
  return mode === 'code' && stack.length === 0
}

const state = read(path.join(sourceDir, 'CustomerCareTaskState.php'))
const record = read(path.join(sourceDir, 'CustomerCareRecordState.php'))
const service = read(path.join(sourceDir, 'CustomerCareCommandService.php'))
const repository = read(path.join(sourceDir, 'CustomerCareRepository.php'))
const thinkRepository = read(path.join(sourceDir, 'ThinkPhpCustomerCareRepository.php'))
const codeRegistry = read(path.join(sourceDir, 'CustomerCareCodeRegistry.php'))
const apply = read(path.join(migrationDir, '02-正式升级.sql'))
const post = read(path.join(migrationDir, '03-升级后验证.sql'))
const recovery = read(path.join(migrationDir, '05-部分创表恢复.sql'))
const concurrencyIntegration = read(path.join(phpTestDir, 'mysql-concurrency-integration.php'))
const repositoryWorker = read(path.join(phpTestDir, 'mysql-repository-worker.php'))

for (const directory of [sourceDir, phpTestDir]) {
  for (const file of fs.readdirSync(directory).filter((name) => name.endsWith('.php'))) {
    ok(`balanced PHP structure ${path.basename(directory)}/${file}`,
      balancedPhp(read(path.join(directory, file))))
  }
}

const taskStates = [...state.matchAll(/public const ([A-Z_]+) = '([A-Z_]+)'/g)].map((match) => match[2])
ok('four task states are exact', JSON.stringify(taskStates) === JSON.stringify([
  'UNSTARTED', 'IN_PROGRESS', 'COMPLETED', 'VOIDED',
]), JSON.stringify(taskStates))
ok('no fake deleted or reassigned task state', !/public const (DELETED|REASSIGNED)/.test(state))
ok('void only accepts in progress', /assertCanVoid[\s\S]*?\[self::IN_PROGRESS\]/.test(state))
ok('delete only accepts unstarted', /assertCanDelete[\s\S]*?status !== self::UNSTARTED/.test(state))
ok('overdue compares planned instant with now', /plannedAt < \$now/.test(state))
ok('record states are normal and voided only',
  /public const NORMAL = 'NORMAL'/.test(record) && /public const VOIDED = 'VOIDED'/.test(record))
ok('repository has no record content overwrite method',
  !/function updateRecord(?:Content)?\s*\(/.test(repository))
ok('command receipt is claimed before resource locks and completed in the same transaction',
  /function claimCommandReceipt\s*\(/.test(repository)
    && /function completeCommandReceipt\s*\(/.test(repository)
    && (service.match(/\$this->claimAndReplay\(/g) ?? []).length === 8
    && /insertOperation\(\$row\)[\s\S]{0,240}completeCommandReceipt\(\$operation\)/.test(service))
ok('production ThinkPHP repository reuses the exact C1 receipt table transactionally',
  /implements CustomerCareRepository/.test(thinkRepository)
    && /Db::transaction\(\$callback\)/.test(thinkRepository)
    && /RECEIPT_TABLE = 'cashier_v3_command_receipt'/.test(thinkRepository)
    && /insertGetId\(\$row\)/.test(thinkRepository))
ok('duplicate replay is strict 1062 and does not upgrade the duplicate statement lock',
  /Driver Error Code/.test(thinkRepository)
    && /=== 1062/.test(thinkRepository)
    && !/where\('idempotency_key',[\s\S]{0,120}->lock\(true\)/.test(thinkRepository))
ok('care receipt mapping uses registered UUID-shaped keys and frozen resource contexts',
  /CARE_RECEIPT-/.test(thinkRepository)
    && /CARE_CONTEXT-/.test(thinkRepository)
    && /uuidFromHash/.test(thinkRepository)
    && /contexts_hash/.test(thinkRepository)
    && /receiptResourceContext/.test(service)
    && /expectedRecordVersion/.test(service))
ok('receipt replay validates every operation identity field fail-closed',
  ['tenant_id', 'command_idempotency_key', 'operation_type', 'request_fingerprint',
    'operation_store_id', 'actor_staff_id']
    .every((field) => thinkRepository.includes(`$operation['${field}']`)))
ok('receipt replay cross-checks the complete immutable operation snapshot',
  /function operationSnapshotMatches/.test(thinkRepository)
    && /next_task_id_after/.test(thinkRepository)
    && /命令回执与不可变 operation 不一致/.test(thinkRepository))
ok('real concurrency gate identifies both service connections and proves the receipt lock wait',
  repositoryWorker.includes('SELECT CONNECTION_ID() AS connection_id')
    && concurrencyIntegration.includes('information_schema.PROCESSLIST')
    && concurrencyIntegration.includes('information_schema.INNODB_LOCK_WAITS')
    && concurrencyIntegration.includes('requester.trx_mysql_thread_id=?')
    && concurrencyIntegration.includes('blocker.trx_mysql_thread_id=?')
    && concurrencyIntegration.includes("requested.lock_index='uk_idempotency_key'")
    && concurrencyIntegration.includes('CARE_TEST_FIRST_TRANSACTION_FAILURE')
    && concurrencyIntegration.includes('blocked worker takes over as executor')
    && !concurrencyIntegration.includes('customer_care_test_ready'))
ok('replay rechecks current business store scope',
  /claimAndReplay[\s\S]*?assertBusinessStoreAllowed\(\$actor, \(int\)\(\$operation\['business_store_id'\]/.test(service))
ok('backend registry owns all persisted business codes',
  ['taskType', 'sourceType', 'recordType', 'followupMethod', 'resultCode', 'relatedBusinessType']
    .every((dimension) => codeRegistry.includes(`'${dimension}' => [`)))
ok('creating a task for another owner requires management permission',
  /ownerStaffId'\] !== \$actor\['staffId'\][\s\S]{0,160}REASSIGN_PERMISSION_REQUIRED/.test(service))
ok('manager can execute a task without replacing its responsible employee',
  /owner_staff_id'\] !== \$actor\['staffId'\] && !\$actor\['canReassign'\]/.test(service)
    && /只有当前负责人或门店管理员可以执行/.test(service))
ok('service requires expected task and record versions',
  /expectedVersion/.test(service) && /expectedRecordVersion/.test(service))
ok('delete uses visibility CAS rather than a task state',
  /updateTaskVisibility/.test(service) && /'is_visible' => 0/.test(service))
ok('reassign uses owner CAS and preserves task status in receipt',
  /updateTaskOwner/.test(service)
    && /self::REASSIGN_TASK,[\s\S]*?\(string\)\$task\['status'\],[\s\S]*?\(string\)\$task\['status'\]/.test(service))
ok('formal record is inserted during completion',
  /function completeTask/.test(service) && /insertRecord\(\[/.test(service))
ok('completion atomically persists an optional next task and immutable linkage snapshots',
  /createNextTask/.test(service)
    && /'source_type' => 'PREVIOUS_FOLLOWUP'/.test(service)
    && /'next_task_id' => \$nextTask === null/.test(service)
    && /'next_task_id_after' =>/.test(service)
    && !/APPOINTMENT_UNAVAILABLE/.test(service))
ok('appointment success remains a care result and does not invoke reservation behavior',
  codeRegistry.includes("'APPOINTMENT_SUCCESS'")
    && !/APPOINTMENT_SUCCESS[\s\S]{0,180}(reservation|预约)/i.test(service)
    && !/APPOINTMENT_SUCCESS/.test(thinkRepository))
ok('standalone formal record command is explicit and taskless',
  /public function createRecord/.test(service)
    && /self::CREATE_RECORD/.test(service)
    && /'task_id' => null/.test(service))
ok('record type followed time actual follower and related business are persisted',
  /'record_type' => \$command\['recordType'\]/.test(service)
    && /'followed_at' => \$command\['followedAt'\]/.test(service)
    && /'follower_staff_id' => \$actor\['staffId'\]/.test(service)
    && /'related_business_type' => \$command\['relatedBusinessType'\]/.test(service))
ok('business date uses followed time while success times use server now',
  /businessDate\([\s\S]{0,120}\$command\['followedAt'\]/.test(service)
    && /'occurred_at' => \$now/.test(service)
    && /'settled_at' => \$now/.test(service)
    && /'recorded_at' => \$now/.test(service))
ok('record void only mutates status metadata',
  /voidRecordStatus/.test(service)
    && !/voidRecordStatus\([\s\S]{0,800}'summary'\s*=>/.test(service))
ok('cross-store reassignment has a hard error',
  /CROSS_STORE_REASSIGN_FORBIDDEN/.test(service) && /assignmentStoreId/.test(service))
ok('operation audit is appended for every successful command',
  (service.match(/\$this->insertOperation\(/g) ?? []).length === 8)

ok('migration has exactly three InnoDB tables',
  (apply.match(/CREATE TABLE IF NOT EXISTS/g) ?? []).length === 3
    && (apply.match(/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci/g) ?? []).length === 3)
ok('migration uses ascii_bin identity keys', (apply.match(/COLLATE ascii_bin/g) ?? []).length === 39)
ok('nullable task id permits standalone records without weakening linked uniqueness',
  /`task_id` bigint\(20\) unsigned NULL DEFAULT NULL/.test(apply)
    && /UNIQUE KEY `uk_tenant_task_record` \(`tenant_id`,`task_id`\)/.test(apply))
ok('next-task linkage is nullable unique and replayable from operation',
  /`next_task_id` bigint\(20\) unsigned NULL DEFAULT NULL/.test(apply)
    && /UNIQUE KEY `uk_tenant_next_task` \(`tenant_id`,`next_task_id`\)/.test(apply)
    && /`next_task_id_after` bigint\(20\) unsigned NOT NULL/.test(apply)
    && /`next_task_version_after` bigint\(20\) unsigned NOT NULL/.test(apply))
ok('migration has 767-safe key widths',
  /`tenant_id` varchar\(32\).*ascii_bin/.test(apply)
    && /`command_idempotency_key` varchar\(96\).*ascii_bin/.test(apply)
    && /`organization_path` varchar\(191\).*ascii_bin/.test(apply))
const sqlWithoutComments = apply.replace(/^--.*$/gm, '')
ok('migration excludes MySQL 5.6 forbidden features',
  !/\b(json|generated|check)\b/i.test(sqlWithoutComments)
    && !/\bover\s*\(/i.test(sqlWithoutComments)
    && !/\bwith\s+\w+\s+as\s*\(/i.test(sqlWithoutComments))
ok('postcheck rejects illegal states and zero versions',
  post.includes("status NOT IN ('UNSTARTED','IN_PROGRESS','COMPLETED','VOIDED')")
    && post.includes("status NOT IN ('NORMAL','VOIDED')")
    && post.includes('version=0'))
ok('partial DDL recovery is exact non-destructive one-two table validation',
  recovery.includes('actual_column_hash=expected_column_hash')
    && recovery.includes('actual_index_hash=expected_index_hash')
    && recovery.includes('STOP_PARTIAL_DDL_UPGRADE_LOG_INVALID')
    && recovery.includes('STOP_PARTIAL_DDL_UPGRADE_REGISTERED')
    && recovery.includes('STOP_PARTIAL_DDL_DEPENDENCY_INVALID')
    && recovery.includes('STOP_PARTIAL_DDL_ZERO_TABLES')
    && recovery.includes('STOP_PARTIAL_DDL_FULL_INSTALL')
    && recovery.includes('STOP_PARTIAL_DDL_HETEROGENEOUS')
    && recovery.includes('STOP_PARTIAL_DDL_NONEMPTY')
    && recovery.includes('PARTIAL_DDL_RECOVERY_READY')
    && !/DROP\s+TABLE\s+`?eb_customer_care_/i.test(recovery))

const expectedArtifacts = [
  '00-升级清单.md',
  '01-升级前检查.sql',
  '02-正式升级.sql',
  '03-升级后验证.sql',
  '04-回滚或应急说明.md',
  '05-部分创表恢复.sql',
]
const checksumLines = read(path.join(migrationDir, 'SHA256SUMS.txt')).trim().split(/\r?\n/)
const checksumEntries = checksumLines.map((line) => {
  const matched = line.match(/^([a-f0-9]{64})  (.+)$/)
  return matched ? { hash: matched[1], file: matched[2] } : null
})
ok('checksum manifest lists every migration artifact exactly once',
  checksumEntries.every(Boolean)
    && JSON.stringify(checksumEntries.map((entry) => entry.file)) === JSON.stringify(expectedArtifacts))
ok('checksum manifest matches migration bytes', checksumEntries.every((entry) => {
  if (!entry) return false
  const digest = crypto.createHash('sha256')
    .update(fs.readFileSync(path.join(migrationDir, entry.file)))
    .digest('hex')
  return digest === entry.hash
}))

process.stdout.write(`ASSERT_PASSED=${passed}\nASSERT_FAILED=${failed}\n`)
if (failed > 0) process.exit(1)
process.stdout.write('CUSTOMER_CARE_STATIC_CONTRACT=PASS\n')
