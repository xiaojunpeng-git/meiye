#!/usr/bin/env node
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const queryDir = path.join(root, '后端代码/app/services/customer/care/query')
const integrationDir = path.join(root, '后端代码/app/services/customer/care/integration')

const expected = [
  'CustomerCareCursorCodec.php',
  'CustomerCareProjectionContract.php',
  'CustomerCareProjectionErrorCode.php',
  'CustomerCareProjectionException.php',
  'CustomerCareQueryRepository.php',
  'CustomerCareQueryScope.php',
  'CustomerCareWorkbenchQueryService.php',
  'ThinkPhpCustomerCareQueryRepository.php',
  'CustomerCareActionInputMapper.php',
  'CustomerCareWorkbenchActionAdapter.php'
]

let passed = 0
let failed = 0
function ok(name, condition, detail = '') {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
  } else {
    failed += 1
    console.log(`FAIL ${name}${detail ? `: ${detail}` : ''}`)
  }
}

for (const file of expected) {
  const target = fs.existsSync(path.join(queryDir, file))
    ? path.join(queryDir, file)
    : path.join(integrationDir, file)
  ok(`source exists ${file}`, fs.existsSync(target), target)
}

const sources = [...fs.readdirSync(queryDir).map((name) => path.join(queryDir, name)),
  ...fs.readdirSync(integrationDir).map((name) => path.join(integrationDir, name))]
  .filter((target) => target.endsWith('.php'))
  .map((target) => fs.readFileSync(target, 'utf8'))
  .join('\n')
const repository = fs.readFileSync(
  path.join(queryDir, 'ThinkPhpCustomerCareQueryRepository.php'),
  'utf8'
)
const merchantResolver = fs.readFileSync(
  path.join(root, '后端代码/app/services/mobile/merchant/MobileMerchantRequestContextResolver.php'),
  'utf8'
)

ok('contract version frozen', sources.includes("CONTRACT_VERSION = 'customer-care.v1'"))
for (const block of ['taskView', 'customerView', 'recordView', 'statistics', 'settings']) {
  ok(`full projection block ${block}`, sources.includes(`'${block}' =>`))
}
ok('tenant and store injected before filters', sources.includes("->where('tenant_id', $scope->tenantId())")
  && sources.includes("->whereIn('business_store_id', $scope->allowedBusinessStoreIds())"))
ok('self task scope is server enforced', sources.includes("->where('owner_staff_id', $scope->staffId())"))
ok('default task scope and month range are server-owned',
  sources.includes("$scope->canViewAllTasks() ? 'all' : 'my'")
    && sources.includes("$query['bucket'] ?? self::BUCKET_ALL")
    && sources.includes('withCurrentMonthRange')
    && sources.includes("modify('first day of this month')"))
ok('member DataScope is independent from task ownership',
  repository.includes("Db::name('store_user')->alias('su')")
    && repository.includes("applyAuthorizedMemberScope($base, $scope, 'r.member_id')")
    && repository.includes("applyAuthorizedMemberScope($query, $scope, 'r.member_id')")
    && repository.includes('care history is not truncated again by record creator'))
ok('customer exact member filter is normalized and cannot widen store scope',
  sources.includes("'memberId' => self::optionalPositiveInt")
    && repository.includes("->where('u.uid', (int)$query['memberId'])")
    && repository.includes("->whereIn('su.store_id', $scope->allowedBusinessStoreIds())"))
ok('customer view echoes only a positive exact member filter',
  sources.includes("'memberId' => (int)($query['memberId'] ?? 0) > 0")
    && sources.includes("? (string)(int)$query['memberId']")
    && sources.includes(": ''"))
ok('customer-scoped task and record views normalize and apply exact member filters',
  sources.includes("$statusGroup = self::enum")
    && sources.includes("'memberId' => self::optionalPositiveInt($query['memberId'] ?? null, 'memberId')")
    && repository.includes("$base->where('member_id', (int)$query['memberId'])")
    && repository.includes("$base->where('r.member_id', (int)$query['memberId'])"))
ok('open customer task view can only contain unfinished states',
  repository.includes("($query['statusGroup'] ?? '') === 'open'")
    && repository.includes('CustomerCareTaskState::UNSTARTED, CustomerCareTaskState::IN_PROGRESS'))
ok('mutually exclusive bucket SQL', sources.includes('planned_at < %d')
  && sources.includes('planned_at >= %d')
  && sources.includes('planned_at > %d'))
ok('stable task and record ordering', sources.includes("->order('planned_at', 'asc')")
  && sources.includes("->order('r.followed_at', 'desc')"))
ok('task and record time ranges are server normalized and SQL filtered',
  sources.includes("'plannedFrom' => $plannedRange['from']")
    && sources.includes("'followedFrom' => $followedRange['from']")
    && repository.includes("->where('planned_at', '>=', (int)$query['plannedFrom'])")
    && repository.includes("->where('r.followed_at', '>=', (int)$query['followedFrom'])"))
ok('mixed ASCII document numbers and Chinese keywords normalize before LIKE',
  repository.includes('applyUtf8KeywordLike')
    && repository.includes('CONVERT(%s USING utf8mb4) COLLATE utf8mb4_general_ci LIKE ?')
    && repository.includes("'task_no', 'member_name_snapshot'")
    && repository.includes("'r.record_no', 'r.member_name_snapshot'"))
ok('date-only upper bound includes the whole final day',
  sources.includes("$date->setTime(23, 59, 59)->getTimestamp()"))
ok('cursor is HMAC signed', sources.includes("hash_hmac('sha256'"))
ok('malformed cursor is normalized to projection error',
  /base64UrlDecode\(\$body\);[\s\S]{0,120}catch \(\\Throwable/.test(sources))
ok('appointment and settings fail closed', sources.includes("'canManageRules' => false")
  && sources.includes("'canHandleExceptions' => false")
  && sources.includes("'appointment' => [\n                    'enabled' => false"))
ok('no rule or exception write adapter', !sources.includes('save-care-followup-rule')
  && !sources.includes('retry-care-auto-exception'))
ok('Phase A service is the only command writer', sources.includes('CustomerCareCommandService')
  && !sources.includes("Db::name('customer_care_task')->insert")
  && !sources.includes("Db::name('customer_care_record')->insert"))
ok('command success requires a full projection', sources.includes("'projection' => ['customerCare' => $projection]")
  && sources.includes('PROJECTION_REFRESH_REQUIRED')
  && sources.includes("'commandReceipt' => $commandResult"))
ok('projection refresh failure keeps its applied-operation audit trail',
  sources.includes('logProjectionRefreshFailure')
    && sources.includes('command applied but projection refresh failed')
    && sources.includes("'operationKey' => (string)($commandResult['operationKey'] ?? '')")
    && sources.includes('failureMessage'))
ok('linked record dual versions stay explicit', sources.includes("$payload['expectedTaskVersion']")
  && sources.includes("$payload['expectedRecordVersion']")
  && sources.includes('Never substitute a freshly-read version'))
ok('navigation is target code plus business id', sources.includes("'targetCode' => 'member-detail'")
  && sources.includes("'businessId' =>"))
ok('service summary provenance is explicit', sources.includes('care_task_service_completed_snapshot')
  && sources.includes("'recentServiceSource' =>"))
ok('formal task and record numbers are server-projected while internal keys stay out of DTOs',
  sources.includes("'taskNo' => (string)(\$row['task_no']")
    && sources.includes("'recordNo' => (string)(\$row['record_no']")
    && !sources.includes("'taskNo' => (string)\$row['task_key']")
    && !sources.includes("'recordNo' => (string)\$row['record_key']"))
ok('customer next task carries an authorized navigation reference',
  repository.includes("'taskId' => (string)(int)\$task['id']")
    && repository.includes("'canOpen' => true")
    && sources.includes("'nextTask' => is_array"))
ok('statistics drill-down has a fixed metric contract and server-owned filtering',
  sources.includes('normalizeStatisticsQuery')
    && sources.includes('STATISTICS_METRIC_EMPLOYEE_COMPLETED')
    && repository.includes("where('owner_staff_id', $ownerStaffId)")
    && repository.includes("where('r.follower_staff_id', $followerStaffId)"))
ok('statistics drill-down remains read-only', !sources.includes('create-care-metric-detail')
  && !sources.includes('save-care-metric-detail'))
ok('no arbitrary URL navigation', !/['"](?:url|href)['"]\s*=>/i.test(sources)
  && !/https?:\/\//i.test(sources))
ok('shared cashier v3 is not registered or imported', !sources.includes('CashierV3Bootstrap')
  && !sources.includes('CashierV3ActionManifest')
  && !sources.includes('CashierV3ActionDispatcher'))
ok('organization-direct managers are query-only and cannot receive customer-care write',
  sources.includes('queryStaffId')
    && sources.includes("$permissions['canViewAllTasks']")
    && merchantResolver.includes("$staffId <= 0")
    && merchantResolver.includes("$action !== 'CUSTOMER_CARE_WRITE'")
    && merchantResolver.includes("(int)$context['staffId'] > 0"))

console.log(`CUSTOMER_CARE_QUERY_STATIC passed=${passed} failed=${failed}`)
if (failed) process.exit(1)
