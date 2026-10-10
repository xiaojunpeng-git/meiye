import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/setting/staff/add.vue'), 'utf8')
// 执行组件真实方法，不以文本存在代替命令身份、撤销及岗位边界验证。
const extract = (startMarker, endMarker, name, args = [], values = []) => {
  const start = source.indexOf(startMarker)
  const end = source.indexOf(endMarker, start)
  assert.ok(start >= 0 && end > start)
  return new Function(...args, `return ({ ${source.slice(start, end)} }).${name}`)(...values)
}
const toggle = extract('    toggleClearCurrentStore() {', '    onScopeOrganizationsPick(', 'toggleClearCurrentStore')
const payload = extract('    buildSubmitPayload() {', '    handleSubmit() {', 'buildSubmitPayload',
  ['DATE_FIELDS', 'newRequestToken'], [[], () => 'test-token'])
const warnings = []
const state = {
  clearCurrentStore: false, currentStoreVersion: 7, originalStoreId: 22,
  originalPositionIds: [2, 1], positionIdsTouched: true,
  $Message: { warning: (message) => warnings.push(message) },
  editId: 437, originalInternalAccount: '', mobileAuthLoaded: false,
  canEditEmploymentType: false, educationLoaded: false,
  formInline: { employee_id: 437, staff_id: 98, org_id: 110, store_id: 22,
    staff_name: '测试人员', phone: '19900007104', position_ids: [1, 2],
    scope_mode: 'store', account: '', status: 1, status_version: 4 },
}
toggle.call(state)
assert.equal(state.clearCurrentStore, true)
assert.equal(state.formInline.store_id, '')
assert.equal(state.formInline.scope_mode, 'store')
assert.equal(warnings.length, 0)
console.log('PASS 回显触发的岗位 change 不阻止清空，且不自动改变数据范围')

const command = payload.call(state)
assert.equal(command.clear_current_store, 1)
assert.equal(command.clear_store_staff_id, 98)
assert.equal(command.clear_store_id, 22)
assert.equal(command.current_store_version, 7)
assert.equal(command.store_id, 0)
assert.equal(command.status, 1)
assert.equal(Object.hasOwn(command, 'position_ids'), false)
assert.equal(Object.hasOwn(command, 'employee_id'), false)
assert.equal(Object.hasOwn(command, 'staff_id'), false)
console.log('PASS 清空使用稳定任职及版本命令，不复制岗位或改变全局在职状态')

toggle.call(state)
assert.equal(state.clearCurrentStore, false)
assert.equal(state.formInline.store_id, 22)
assert.equal(Object.hasOwn(payload.call(state), 'clear_current_store'), false)
console.log('PASS 撤销恢复原门店，普通保存不携带任职结束命令')

state.formInline.position_ids = [3]
toggle.call(state)
assert.equal(state.clearCurrentStore, false)
assert.equal(warnings.length, 1)
console.log('PASS 真正改岗位时禁止混入清空命令')

state.formInline.position_ids = [1, 2]
state.formInline.status = 0
toggle.call(state)
assert.equal(state.clearCurrentStore, false)
assert.equal(warnings.length, 2)
assert.match(warnings[1], /不能同时办理离职/)
assert.match(source, /v-model="formInline.status"[^>]*:disabled="clearCurrentStore"/)
console.log('PASS 全局离职与任职清空分开提交')

const pickOrganization = extract('    onOrganizationScopePick(scope = {}) {',
  '    onAppointmentStoreScopePick(', 'onOrganizationScopePick')
state.storeLocked = true
state.organizationPickerLabel = ''
state.$refs = {}
state.onStorePick = () => { throw new Error('不能借组织选择调店或重开已清空任职') }
state.clearCurrentStore = true
state.formInline.store_id = ''
pickOrganization.call(state, { nodeType: 'store', orgId: 120, storeId: 99, label: '测试组织/门店' })
assert.equal(state.formInline.store_id, '')
assert.equal(state.formInline.org_id, 120)
console.log('PASS 已有任职的组织选择不能绕过调店边界或撤销清空')
