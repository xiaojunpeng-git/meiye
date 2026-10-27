#!/usr/bin/env node
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(__dirname, '../../..')
const frontend = path.join(repo, '前端代码/cashier-v3/src')
const sharedFrontend = path.join(repo, '前端代码/shared/unified-query-vue3/src')
const sharedToolbar = fs.readFileSync(path.join(sharedFrontend, 'components/UnifiedQueryToolbar.vue'), 'utf8')

assert.match(sharedToolbar, /dataScope: dataScope\.value/)
assert.match(sharedToolbar, /businessStatus: businessStatus\.value/)
assert.match(sharedToolbar, /emit\('query', buildQueryPayload\(\)\)/)
assert.doesNotMatch(sharedToolbar, /query\.status\s*=/, '范围切换只提交统一查询合同字段，不能附带旧 status 参数')

const facadeFiles = {
  QueryEntitySelectorOverlay: 'components/query/QueryEntitySelectorOverlay.vue',
  UnifiedQueryCustomFieldDrawer: 'components/query/UnifiedQueryCustomFieldDrawer.vue',
  UnifiedQueryExportDrawer: 'components/query/UnifiedQueryExportDrawer.vue',
  UnifiedQueryFieldRenameDrawer: 'components/query/UnifiedQueryFieldRenameDrawer.vue',
  UnifiedQuerySettingsDrawer: 'components/query/UnifiedQuerySettingsDrawer.vue',
  UnifiedQueryToolbar: 'components/query/UnifiedQueryToolbar.vue',
  useUnifiedQueryPage: 'composables/useUnifiedQueryPage.js',
  unifiedQueryContract: 'services/unifiedQueryContract.js'
}
const facadeSources = Object.fromEntries(Object.entries(facadeFiles).map(([name, relativePath]) => {
  const filePath = path.join(frontend, relativePath)
  assert.equal(fs.existsSync(filePath), true, `cashier 兼容路径仍存在：${relativePath}`)
  return [name, fs.readFileSync(filePath, 'utf8')]
}))

for (const name of [
  'QueryEntitySelectorOverlay',
  'UnifiedQueryCustomFieldDrawer',
  'UnifiedQueryExportDrawer',
  'UnifiedQueryFieldRenameDrawer',
  'UnifiedQuerySettingsDrawer'
]) {
  const source = facadeSources[name]
  assert.ok(source.split('\n').length <= 12, `${name} 仅保留薄组件 facade`)
  assert.match(source, /from '@mohe\/unified-query-vue3'/, `${name} 从唯一共享包导入实现`)
  assert.match(source, /v-bind="\$attrs"/, `${name} 原样转发宿主属性与事件`)
  assert.doesNotMatch(source, /defineProps|computed\(|reactive\(|watch\(/, `${name} 不保留平行业务实现`)
}

assert.ok(facadeSources.UnifiedQueryToolbar.split('\n').length <= 50, '工具栏 facade 仅保留宿主适配')
assert.match(facadeSources.UnifiedQueryToolbar, /UnifiedQueryToolbar as SharedUnifiedQueryToolbar/, '工具栏实现来自唯一共享包')
assert.match(facadeSources.UnifiedQueryToolbar, /openCashierV3QueryEntitySelector/, 'cashier 只在 facade 注入既有实体选择能力')
assert.match(facadeSources.UnifiedQueryToolbar, /v-bind="\$attrs"/, '工具栏 facade 转发原页面合同')
assert.doesNotMatch(facadeSources.UnifiedQueryToolbar, /dataScope|buildQueryPayload|isSettingsOpen/, '工具栏 facade 不复制查询状态机')

assert.ok(facadeSources.useUnifiedQueryPage.split('\n').length <= 3, 'composable 原路径仅保留 re-export')
assert.match(facadeSources.useUnifiedQueryPage, /export \{ useUnifiedQueryPage \} from '@mohe\/unified-query-vue3\/composable'/, 'composable 指向共享唯一实现')
assert.ok(facadeSources.unifiedQueryContract.split('\n').length <= 10, 'contract 原路径仅保留 re-export 与会员适配')
assert.match(facadeSources.unifiedQueryContract, /export \* from '@mohe\/unified-query-vue3\/contract'/, '通用 contract 从共享包导出')
assert.match(
  facadeSources.unifiedQueryContract,
  /extractUnifiedQueryProjection\(raw, \['memberCenter', 'member_center'\]\)/,
  '会员 facade 只声明领域 projection 别名，不复制通用解析逻辑'
)

const contract = await import(pathToFileURL(path.join(sharedFrontend, 'contracts/unifiedQueryContract.js')).href)

const memberKeys = [
  'member_name', 'phone', 'member_no', 'member_status', 'member_level', 'member_tag', 'store',
  'exclusive_service_staff', 'account_balance', 'active_card_count', 'remaining_project_times',
  'remaining_project_amount', 'debt_amount', 'total_consumption_amount', 'visit_count',
  'latest_purchase_date', 'last_service_staff', 'latest_visit_date', 'created_at'
]

const systemFields = memberKeys.map((key) => ({
  key,
  label: key === 'phone' ? '完整手机号' : key,
  type: key.includes('amount') || key === 'account_balance' ? 'amount' : 'text',
  allowedOperations: ['list', 'quick_filter', 'filter', 'sort', 'group', 'summary', 'export', 'custom_input']
}))
const presentationFields = systemFields.map((field) => (
  field.key === 'store' ? { ...field, type: 'store', recordKey: 'storeName' } : field
))

function capabilityEnvelope(pageCode = 'member_list') {
  return {
    result: { status: 'success', code: '', message: 'ok' },
    versions: [{ kind: 'query_preference', id: 'member_list', version: 4 }],
    data: {
      capability: {
        enabled: true,
        pageCode,
        pageName: '会员列表',
        schemaVersion: 'unified-query-2026-07-28-v1',
        dataAsOf: '2026-07-28 18:40:00',
        fields: systemFields,
        customFields: [{
          id: 'cf-1',
          key: 'cf_total_value',
          name: '会员价值',
          returnType: 'amount',
          status: 'active',
          version: 2,
          visibility: 'personal',
          allowedOperations: ['list', 'filter', 'sort', 'group', 'summary', 'export']
        }],
        fieldAliases: { phone: '联系电话', cf_total_value: '客户价值' },
        fieldAliasVersion: 3,
        permissions: {
          createCustomField: true,
          editCustomField: true,
          shareCustomField: false,
          changeCustomFieldStatus: true,
          archiveCustomField: true,
          renameFields: true,
          export: true
        },
        exportCapability: {
          enabled: true,
          formats: ['xlsx'],
          allowCurrentQuery: true,
          allowCurrentPage: true,
          allowSummary: true
        },
        querySettings: {
          settings: {
            visibleFields: ['member_name', 'phone', 'cf_total_value'],
            quickFields: ['member_name'],
            filters: [{ fieldKey: 'visit_count', operator: 'greater_or_equal', value: 3 }],
            filterRelation: 'all',
            sorts: [{ field: 'created_at', direction: 'desc' }],
            groupBy: ['member_level'],
            summaries: [{ field: 'account_balance', aggregation: 'sum' }],
            queryCutoffDate: '2026-07-28',
            schemaVersion: 'unified-query-2026-07-28-v1'
          },
          customFieldVersions: { cf_total_value: 2 },
          invalidReferences: [{ fieldKey: 'cf_old', version: 1, status: 'invalid', reason: '字段已停用' }]
        }
      }
    }
  }
}

const valid = contract.normalizeUnifiedQueryCapability(capabilityEnvelope(), 'member_list')
assert.equal(valid.enabled, true, '服务端显式 enabled 且 pageCode 匹配时才开放')
assert.equal(valid.schemaVersion, 'unified-query-2026-07-28-v1', '字符串 schemaVersion 原样回传，不强转为数字')
assert.equal(valid.queryCutoffDate, '2026-07-28', '查询截止日期只取服务端签发时间')
assert.deepEqual(valid.commandContext, { kind: 'query_preference', id: 'member_list' }, '命令绑定账号页面偏好版本')
assert.equal(valid.fields.length, 20, '19 个系统字段与 1 个自定义字段进入同一白名单')
assert.equal(valid.fields.find((field) => field.key === 'phone').capabilities.quickFilter, true, 'allowedOperations 映射顶部查询')
assert.equal(valid.fields.find((field) => field.key === 'phone').capabilities.export, true, 'allowedOperations 映射导出')
assert.equal(valid.permissions.shareCustomField, false, '权限必须读取严格 true，不能自动放大')
assert.equal(valid.fieldAliasVersion, 3, '字段改名携带服务端 alias 版本')
assert.equal(valid.exportCapability.format, 'xlsx', '导出格式固定为 xlsx')
assert.deepEqual(valid.querySettings.filters[0], { field: 'visit_count', operator: 'gte', value: 3 }, '后端字段键和长操作符可回显为前端筛选')
assert.equal(valid.customFieldVersions.cf_total_value, 2, '已保存查询保留服务端固定的字段版本')
assert.equal(valid.invalidReferences[0].reason, '字段已停用', '失效引用原因进入前端能力合同')

const upgradeAvailableEnvelope = capabilityEnvelope()
upgradeAvailableEnvelope.data.capability.querySettings.invalidReferences = [{
  fieldKey: 'cf_total_value',
  version: 2,
  status: 'upgrade_available',
  reason: '该字段已有新版本，可选择升级。'
}]
const upgradeAvailableCapability = contract.normalizeUnifiedQueryCapability(upgradeAvailableEnvelope, 'member_list')
assert.deepEqual(
  upgradeAvailableCapability.invalidReferences[0],
  {
    fieldKey: 'cf_total_value',
    version: 2,
    status: 'upgrade_available',
    reason: '该字段已有新版本，可选择升级。'
  },
  '字段新版本提示必须以稳定键、冻结版本和状态原样进入升级界面'
)

const presented = contract.presentUnifiedQueryFields(presentationFields, valid)
assert.deepEqual(presented.slice(0, 19).map((field) => field.key), memberKeys, '稳定字段 key 与原列表顺序不变')
assert.equal(presented.find((field) => field.key === 'phone').label, '联系电话', '字段别名只改变显示名称')
assert.equal(presented.find((field) => field.key === 'phone').originalLabel, '完整手机号', '保留系统原名称用于改名界面')
assert.equal(presented.find((field) => field.key === 'cf_total_value').label, '客户价值', '自定义字段别名使用同一显示管线')
assert.equal(presented.find((field) => field.key === 'store').type, 'store', '系统字段保留实体选择器 UI 类型')
assert.equal(presented.find((field) => field.key === 'store').queryType, 'text', '后端查询类型单独保存在 queryType')
assert.equal(presented.find((field) => field.key === 'store').recordKey, 'storeName', '系统字段保留真实列表取值键')

const mismatch = contract.normalizeUnifiedQueryCapability(capabilityEnvelope('order_list'), 'member_list')
assert.equal(mismatch.enabled, false, 'pageCode 不匹配时 fail-closed')
assert.equal(mismatch.commandContext, null, 'pageCode 不匹配时不保留写命令上下文')

const noVersion = capabilityEnvelope()
noVersion.versions = []
const noContext = contract.normalizeUnifiedQueryCapability(noVersion, 'member_list')
assert.equal(noContext.enabled, true, '只读字段投影仍可识别')
assert.equal(noContext.commandContext, null, '无公开资源版本时写入口必须关闭')

const readyTask = contract.extractUnifiedQueryExportTask({
  result: { status: 'success', code: '', message: 'ok' },
  data: {
    exportTask: {
      taskId: 'export-1',
      status: 'ready',
      resultCount: 18,
      downloadUrl: '/api/unified-query/export/export-1',
      frozenFields: [
        { key: 'member_name', label: '会员姓名', type: 'text', version: 0 },
        { key: 'cf_total_value', label: '客户价值', type: 'amount', version: 2 }
      ]
    }
  }
})
assert.deepEqual(
  { id: readyTask.id, status: readyTask.status, rowCount: readyTask.rowCount, downloadUrl: readyTask.downloadUrl },
  { id: 'export-1', status: 'ready', rowCount: 18, downloadUrl: '/api/unified-query/export/export-1' },
  '成功导出任务兼容 resultCount 且只保留站内相对下载地址'
)
assert.deepEqual(
  readyTask.frozenFields,
  [
    { key: 'member_name', label: '会员姓名', type: 'text', version: 0 },
    { key: 'cf_total_value', label: '客户价值', type: 'amount', version: 2 }
  ],
  '导出任务只读取服务端冻结表头，后续字段改名不能影响已创建任务'
)
const failedTask = contract.extractUnifiedQueryExportTask({
  result: { status: 'success', code: '', message: 'ok' },
  data: { exportTask: { id: 'export-2', status: 'failed', errorReason: '字段版本失效', downloadUrl: 'https://outside.example/file.xlsx' } }
})
assert.equal(failedTask.failureReason, '字段版本失效', '失败导出任务兼容 errorReason')
assert.equal(failedTask.downloadUrl, '', '外站下载地址 fail-closed')
assert.deepEqual(failedTask.frozenFields, [], '没有服务端冻结表头的旧任务不得伪造当前字段')
assert.equal(contract.unifiedQueryActionStatus({ result: { status: 'failed', code: 'EXPORT_DENIED', message: '无权限' } }), 'failed', '失败 envelope 保留确定失败状态')
assert.equal(contract.unifiedQueryActionMessage({ result: { status: 'failed', code: 'EXPORT_DENIED', message: '无权限' } }), '无权限', '失败 envelope 保留服务端原因')

const querySnapshot = {
  pageCode: 'member_list',
  page: 3,
  limit: 20,
  keyword: '林',
  dataScope: 'normal',
  businessStatus: '',
  topFilters: [{ field: 'member_level', operator: 'eq', value: '金卡' }],
  sorts: [{ field: 'created_at', direction: 'desc' }],
  filters: [{ field: 'visit_count', operator: 'gte', value: 3 }],
  filterRelation: 'all',
  groupBy: ['member_level'],
  summaries: [{ field: 'account_balance', aggregation: 'sum' }],
  queryCutoffDate: '2026-07-28',
  fieldVersions: { cf_total_value: 2 }
}
const exportConfiguration = {
  fields: ['member_name', 'phone', 'cf_total_value'],
  includeSummary: true,
  fileName: '会员查询.xlsx'
}
const currentQueryExport = contract.buildUnifiedQueryExportPayload(querySnapshot, { ...exportConfiguration, scope: 'query' }, valid)
const currentPageExport = contract.buildUnifiedQueryExportPayload(querySnapshot, { ...exportConfiguration, scope: 'page' }, valid)
assert.deepEqual(currentQueryExport.query, currentPageExport.query, '当前查询/当前页只改变导出范围，不改变查询快照')
assert.equal(currentQueryExport.scope, 'query', '当前查询范围保持 query')
assert.equal(currentPageExport.scope, 'page', '当前页范围保持 page')
assert.equal(currentQueryExport.query.businessStatus, '', '正常数据导出快照不得携带异常业务状态')
assert.equal(currentQueryExport.query.queryCutoffDate, undefined, '导出查询内不重复携带截止日期')
assert.equal(currentQueryExport.queryCutoffDate, '2026-07-28', '导出任务顶层冻结服务端截止日期')
assert.deepEqual(currentQueryExport.fields, exportConfiguration.fields, '导出字段顺序按用户配置冻结')
assert.equal(currentQueryExport.fileName, '会员查询.xlsx', '文件名作为顶层导出合同提交')
querySnapshot.filters[0].value = 999
querySnapshot.fieldVersions.cf_total_value = 9
assert.equal(currentQueryExport.query.filters[0].value, 3, '导出任务查询快照必须深拷贝，后续控件修改不能改写已执行条件')
assert.equal(currentQueryExport.query.fieldVersions.cf_total_value, 2, '导出任务字段版本必须深拷贝，不能漂移到后续版本')
const changedCapability = { ...valid, queryCutoffDate: '2026-07-29' }
assert.equal(
  contract.buildUnifiedQueryExportPayload({ ...querySnapshot, queryCutoffDate: '2026-07-28' }, exportConfiguration, changedCapability).queryCutoffDate,
  '2026-07-28',
  '导出优先使用成功执行查询的截止日期快照，而不是之后重新加载的能力日期'
)

const toolbarSource = fs.readFileSync(path.join(sharedFrontend, 'components/UnifiedQueryToolbar.vue'), 'utf8')
assert.match(toolbarSource, /dataScope === 'all'/, '其他状态仅在全部数据范围出现')
assert.match(toolbarSource, /businessStatus\.value = ''/, '切回正常数据会清空不兼容业务状态')
assert.match(toolbarSource, /unified-query-scope__thumb[\s\S]*?unified-query-scope__thumb--all/, '正常数据／全部数据使用左右滑动分段控件')
assert.match(toolbarSource, />导出<\/button>/, '统一查询工具栏保留可见导出入口')
assert.match(
  toolbarSource,
  /function selectScope\(scope\)[\s\S]*?dataScope\.value = scope[\s\S]*?if \(scope === 'normal'\)[\s\S]*?businessStatus\.value = ''[\s\S]*?submitQuery\(\)/,
  '范围切换在提交查询前同步清空旧异常状态'
)
for (const surface of ['UnifiedQueryCustomFieldDrawer', 'UnifiedQueryFieldRenameDrawer', 'UnifiedQueryExportDrawer']) {
  assert.match(toolbarSource, new RegExp(surface), `${surface} 已接入真实统一查询工具栏`)
}
assert.match(toolbarSource, /hasExecutedQuerySnapshot/, '导出入口必须先验证存在成功执行的查询快照')
assert.match(toolbarSource, /props\.isQueryLoading !== true/, '查询请求进行中不得开启导出')
assert.match(toolbarSource, /query: cloneUnifiedQuerySnapshot\(props\.executedQuery\)/, '导出只传入已执行快照的深拷贝，不能读取未查询的当前控件')
assert.match(toolbarSource, /stateContextKey[\s\S]*?exportTaskReference\.value = null/, '切换账号或门店时必须清空旧导出任务状态')
assert.match(toolbarSource, /limit: Math\.max/, '查询和分页统一使用后端 limit 合同')
assert.doesNotMatch(toolbarSource, /querySettings:\s*\{/, '查询条件不再维护嵌套的第二套执行合同')
assert.match(toolbarSource, /preservedDormantAliases/, '停用字段的账号页面别名不会因修改其他字段而静默丢失')
assert.match(toolbarSource, /settingsButtonLabel:\s*\{[\s\S]*?default:\s*'设置'/, '共享查询工具栏默认文案保持为设置，不能扩散修改其他页面')
assert.equal((toolbarSource.match(/@click="openSettings"/g) || []).length, 1, '共享查询工具栏只保留一个设置抽屉入口')
assert.match(
  toolbarSource,
  /unified-query-export-button[\s\S]*?v-if="showSettingsButton"[\s\S]*?@click="openSettings">\{\{ settingsButtonLabel \}\}<\/button>/,
  '参数化设置入口保留在原工具栏位置并继续打开同一抽屉'
)

const previewSource = fs.readFileSync(path.join(frontend, 'dev/UnifiedQueryCustomFieldPreview.vue'), 'utf8')
assert.match(previewSource, /v-if="previewDataScope === 'all'"[\s\S]*?aria-label="其他状态"/, '确认界面与生产口径一致：其他状态只在全部数据出现')
assert.match(previewSource, /previewDataScope = 'normal'; previewBusinessStatus = ''/, '确认界面切回正常数据会清空其他状态')
assert.doesNotMatch(previewSource, /batch_stock_quantity|batch_unit_cost|expiry_date|inbound_date/, '会员列表确认界面不冒充尚未接入的库存字段')
const previewHeaderSource = previewSource.match(/<header>[\s\S]*?<\/header>/)?.[0] || ''
assert.doesNotMatch(previewHeaderSource, /<button/, '确认稿页头删除重复的查询设置入口')
assert.equal((previewSource.match(/@click="isSettingsOpen = true">查询设置<\/button>/g) || []).length, 1, '确认稿只保留工具栏当前位置的查询设置入口')

const composableSource = fs.readFileSync(path.join(sharedFrontend, 'composables/useUnifiedQueryPage.js'), 'utf8')
for (const action of [
  'query-unified-query-capabilities',
  'save-unified-query-field-aliases',
  'save-unified-query-custom-field',
  'change-unified-query-custom-field-status',
  'archive-unified-query-custom-field',
  'upgrade-unified-query-field-reference',
  'create-unified-query-export',
  'query-unified-query-export-task'
]) {
  assert.match(composableSource, new RegExp(`['"]${action}['"]`), `${action} 使用固定 action 字面量`)
}
assert.match(composableSource, /missingKeys\.length/, '服务端少任一页面稳定字段时关闭增强能力')
assert.match(composableSource, /\.\.\.fieldPayload/, '自定义字段命令把字段合同放在 payload 顶层')
assert.doesNotMatch(composableSource, /field:\s*fieldPayload/, '自定义字段命令不得套入后端不识别的 field 对象')
assert.match(composableSource, /fieldKey\s*\}/, '状态和归档命令统一提交稳定 fieldKey')

const customFieldSource = fs.readFileSync(path.join(sharedFrontend, 'components/UnifiedQueryCustomFieldDrawer.vue'), 'utf8')
assert.doesNotMatch(customFieldSource, /\beval\s*\(|new Function|expressionSql|expressionJs|expressionPhp/, '前端不执行任意脚本或 SQL')
assert.match(customFieldSource, /v-if="previewMode"[^>]*>[\s\S]*?复制/, '复制字段只保留在确认稿，不在生产开放')
assert.match(customFieldSource, /option value="tenant">全商户/, '全商户共享使用后端 tenant 合同')
assert.doesNotMatch(customFieldSource, /2026-07-28 16:20/, '生产版本记录不得使用确认稿假数据')

const dateExpression = contract.buildCustomFieldExpression({
  leftField: 'latest_visit_date',
  operator: 'date_diff',
  rightMode: 'query_cutoff',
  nullMode: 'empty',
  returnType: 'integer'
})
assert.deepEqual(dateExpression.right, { type: 'context', key: 'query_cutoff_date' }, '历史日期上下文使用验证器接受的 key')
assert.equal(dateExpression.nullMode, 'empty', 'binary 表达式内携带空值策略')
assert.equal(dateExpression.returnType, 'integer', 'binary 表达式内携带声明结果类型')

const fallbackExpression = contract.buildCustomFieldExpression({
  leftField: 'remaining_project_times',
  operator: 'multiply',
  rightMode: 'constant',
  constantValue: '10',
  nullMode: 'fallback',
  fallbackValue: '0',
  returnType: 'amount'
})
assert.equal(fallbackExpression.operator, 'coalesce', '备用值通过受控 coalesce AST 表达')
assert.equal(fallbackExpression.args[0]._return_type, undefined, '备用值包装的内部节点不得携带可篡改结果类型')
const editableFallback = contract.normalizeCustomFieldExpressionForEditor({ returnType: 'amount', expression: fallbackExpression })
assert.equal(editableFallback.loaded, true, '服务端 canonical AST 可重新载入编辑器')
assert.equal(editableFallback.nullMode, 'fallback', 'canonical AST 往返保留备用值策略')
assert.equal(editableFallback.constantValue, '10', 'canonical AST 往返保留固定值')

const comparisonExpression = contract.buildCustomFieldExpression({
  ruleKind: 'comparison',
  leftField: 'visit_count',
  operator: 'greater_or_equal',
  rightMode: 'constant',
  constantValue: '3',
  constantType: 'integer'
})
assert.equal(comparisonExpression.operator, 'greater_or_equal', '比较模板使用白名单操作符')
assert.equal(comparisonExpression.args[1].value_type, 'integer', '比较模板固定值携带明确类型')
assert.equal(
  contract.normalizeCustomFieldExpressionForEditor({ returnType: 'boolean', expression: comparisonExpression }).ruleKind,
  'comparison',
  '比较模板 canonical AST 可回显'
)

const betweenExpression = contract.buildCustomFieldExpression({
  ruleKind: 'between',
  leftField: 'account_balance',
  lowerValue: '100.00',
  upperValue: '500.00',
  constantType: 'amount'
})
assert.equal(betweenExpression.operator, 'between', '区间模板使用受控 between')
assert.deepEqual(betweenExpression.args.slice(1).map((node) => node.value), ['100.00', '500.00'], '区间模板冻结上下限')

const conditionalExpression = contract.buildCustomFieldExpression({
  ruleKind: 'conditional',
  conditionField: 'account_balance',
  conditionOperator: 'greater_or_equal',
  conditionRightMode: 'constant',
  conditionValue: '1000.00',
  conditionValueType: 'amount',
  trueValue: '高价值',
  falseValue: '普通',
  returnType: 'text'
})
assert.equal(conditionalExpression.operator, 'if', '如果／否则模板使用受控 if')
assert.equal(conditionalExpression.args[0].operator, 'greater_or_equal', '条件本身仍为受控比较节点')
assert.equal(conditionalExpression.args[0]._return_type, undefined, '条件子表达式不携带结果类型元数据')
assert.equal(
  contract.normalizeCustomFieldExpressionForEditor({ returnType: 'text', expression: conditionalExpression }).trueValue,
  '高价值',
  '如果／否则 canonical AST 往返保留分支结果'
)

const nullExpression = contract.buildCustomFieldExpression({
  ruleKind: 'null_check',
  leftField: 'latest_visit_date'
})
assert.equal(nullExpression.operator, 'is_null', '空值判断只生成受控 is_null')
assert.equal(nullExpression._return_type, 'boolean', '空值判断固定返回是／否')

const aggregateExpression = contract.buildCustomFieldExpression({
  ruleKind: 'aggregate',
  leftField: 'total_consumption_amount',
  operator: 'sum',
  returnType: 'amount'
})
assert.equal(aggregateExpression.operator, 'sum', '聚合模板只生成白名单聚合')
assert.equal(aggregateExpression.args.length, 1, '聚合模板只接受单字段')

const bucketExpression = contract.buildCustomFieldExpression({
  ruleKind: 'bucket',
  bucketSource: 'date_diff',
  leftMode: 'query_cutoff',
  rightMode: 'field',
  rightField: 'latest_visit_date',
  buckets: [
    { upperBound: '30', result: '30 天内' },
    { upperBound: '90', result: '31 至 90 天' }
  ],
  bucketFallback: '90 天以上',
  returnType: 'text'
})
assert.equal(bucketExpression.operator, 'bucket', '多档分组使用受控 bucket')
assert.equal(bucketExpression.args[0].operator, 'date_diff_days', '分档可复用查询截止日期的受控日期差')
assert.equal(bucketExpression.args[0]._return_type, undefined, '分档内部日期差不携带可篡改结果类型')
assert.equal(bucketExpression.args[0].args[0].key, 'query_cutoff_date', '历史日期按查询截止日期计算')
assert.equal(bucketExpression.args.length, 6, '两档分组生成值、两组上界/结果及兜底')
const editableBucket = contract.normalizeCustomFieldExpressionForEditor({ returnType: 'text', expression: bucketExpression })
assert.equal(editableBucket.ruleKind, 'bucket', '分档 canonical AST 可重新载入编辑器')
assert.deepEqual(editableBucket.buckets, [
  { upperBound: '30', result: '30 天内' },
  { upperBound: '90', result: '31 至 90 天' }
], '分档 canonical AST 往返不变形')

const normalizedSettings = contract.normalizeUnifiedQuerySettings({
  settings: {
    filters: [{ fieldKey: 'created_at', operator: 'between', value: ['2026-07-01', '2026-07-28'] }]
  }
})
assert.deepEqual(
  normalizedSettings.filters[0],
  { field: 'created_at', operator: 'between', value: '2026-07-01', valueTo: '2026-07-28' },
  '服务端 between 数组可完整回显为起止输入'
)

const memberProjection = contract.extractUnifiedQueryProjection({
  result: { status: 'success', code: '', message: 'ok' },
  data: {
    rows: [{ member_id: 9, member_name: '林女士' }],
    pagination: { page: 2, limit: 30, total: 61 },
    summaries: { 'account_balance:sum': '120.00' },
    groups: [{ values: { member_level: '金卡' }, count: 1 }],
    dataAsOf: '2026-07-28 19:20:00'
  }
}, ['memberCenter', 'member_center'])
assert.deepEqual(
  { total: memberProjection.total, page: memberProjection.page, pageSize: memberProjection.pageSize },
  { total: 61, page: 2, pageSize: 30 },
  '会员列表消费 projection 的分页合同'
)
assert.equal(memberProjection.records[0].member_id, 9, '会员列表消费 projection rows')
assert.equal(memberProjection.summaries['account_balance:sum'], '120.00', '会员列表消费 projection 合计')

const memberSource = fs.readFileSync(path.join(frontend, 'views/MemberListView.vue'), 'utf8')
assert.match(memberSource, /MEMBER_QUERY_PAGE_CODE = 'member_list'/, '会员列表使用服务端白名单 pageCode')
for (const key of memberKeys) assert.match(memberSource, new RegExp(`key: '${key}'`), `会员字段 ${key} 保持稳定标识`)
assert.match(memberSource, /queryDisplayValues/, '自定义字段结果只展示后端返回值')
assert.doesNotMatch(memberSource, /customField[^\n]*(?:\+|-|\*|\/)/, '会员列表不在客户端重算自定义字段')
assert.match(memberSource, /extractUnifiedQueryMemberProjection/, '查询和分页显式消费 projection 响应')
assert.match(memberSource, /memberQuerySequence/, '较慢的旧查询响应不会覆盖新条件')
assert.match(memberSource, /stateContextId !== state\.stateContextId/, '旧门店／账号上下文的查询响应不会进入当前列表')
assert.match(memberSource, /lastSuccessfulQuery/, '会员列表维护最近一次成功执行的查询快照')
assert.match(memberSource, /isQueryLoading/, '会员列表显式维护查询进行状态')
assert.match(memberSource, /queryError/, '会员列表在查询失败时展示页面级错误')
assert.match(memberSource, /clearMemberQueryResult/, '失败或缺少 projection 时必须清空旧列表而非回退旧 state')
assert.match(memberSource, /lastSuccessfulQuery\.value = cloneUnifiedQuerySnapshot\(nextQuery\)/, '只有完整 projection 成功返回后才提交新的执行快照')
assert.match(memberSource, /:executed-query="lastSuccessfulQuery"/, '工具栏明确接收已成功执行快照')
assert.match(memberSource, /role="alert"/, '查询失败以页面级可访问错误提示呈现')
assert.doesNotMatch(memberSource, /queryModel/, '失败请求不得通过 queryModel 伪装为已执行查询')
assert.match(memberSource, /await unifiedQuery\.load\(\{ silent: true \}\)[\s\S]*?await queryMembers\(initialQueryPayload/, 'stateContext 就绪后先加载服务端能力，再执行首屏真实查询')
assert.match(memberSource, /unifiedQueryActionSucceeded\(result\)[\s\S]*?await unifiedQuery\.load/, '保存查询设置成功后刷新 capability 与命令版本')
assert.equal((memberSource.match(/<UnifiedQueryToolbar/g) || []).length, 1, '会员列表只接入一个统一查询工具栏')
assert.match(memberSource, /settings-button-label="查询设置"/, '会员列表把唯一工具栏入口命名为查询设置')
assert.doesNotMatch(memberSource, /@click="isSettingsOpen = true"/, '会员列表不另建重复的页面级查询设置按钮')

const settingsSource = fs.readFileSync(path.join(sharedFrontend, 'components/UnifiedQuerySettingsDrawer.vue'), 'utf8')
assert.match(settingsSource, /unresolvedInvalidReferences/, '失效引用必须显式提示并阻断静默保存')
assert.match(settingsSource, /移除此引用/, '用户可明确移除失效引用')
assert.match(settingsSource, /isUpgradeAvailable\(reference\)/, '仅可升级的版本引用展示升级操作')
assert.match(settingsSource, /升级到最新版本/, '版本升级使用明确且可理解的用户提示')
assert.match(settingsSource, /fieldKey,[\s\S]*?commandIdempotencyKey/, '升级请求只发送稳定字段键和幂等键')
assert.match(
  settingsSource,
  /query-settings-field-tools[\s\S]*?自定义字段[\s\S]*?字段改名/,
  '字段改名与自定义字段放在同一并列操作组'
)
assert.match(toolbarSource, /onUpgradeSavedQueryFieldReference/, '升级成功后工具栏会重新加载同一份服务端查询设置')

for (const visibleTemplate of ['比较判断', '区间判断', '如果／否则', '空值判断', '受控聚合', '多档分组', '查询截止日期']) {
  assert.match(customFieldSource, new RegExp(visibleTemplate.replace('／', '[／/]')), `自定义字段界面提供“${visibleTemplate}”受控模板`)
}
assert.doesNotMatch(customFieldSource, /<textarea[^>]*(?:expression|formula)|contenteditable/, '生产界面不提供任意表达式或公式输入框')

const exportSource = fs.readFileSync(path.join(sharedFrontend, 'components/UnifiedQueryExportDrawer.vue'), 'utf8')
assert.doesNotMatch(exportSource, /2026-07-28 17:30/, '导出不得展示固定假更新时间')
assert.match(exportSource, /safeDownloadUrl/, '下载入口必须经过同源 URL 校验')
assert.match(exportSource, /task\.value\?\.frozenFields/, '任务结果读取服务端冻结字段而不是当前页面字段')
assert.match(exportSource, /frozenTaskFieldCount/, '任务结果的字段数由冻结字段数组计算')
assert.match(exportSource, /v-for="field in frozenTaskFields"/, '任务结果的 Excel 表头只使用冻结字段')
assert.match(exportSource, /冻结表头/, '旧任务没有冻结表头时明确提示，不能伪造当前字段')

console.log('PASS unified-query-frontend-contract')
console.log('GATE_PASS=UQ-FEC-01')
console.log('RUNNER_OK=js/unified-query-frontend-contract.mjs')
