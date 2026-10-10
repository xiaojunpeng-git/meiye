import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'

// 执行真实组件 data/筛选方法，验证组织列、组织查询及重置同一口径。
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/store/storeList/index.vue'), 'utf8')
const require = createRequire(path.join(root, '前端代码/admin/package.json'))
const parsed = require('vue-template-compiler').parseComponent(source)
const script = parsed.script.content.slice(parsed.script.content.indexOf('export default {')).replace('export default ', 'return ')
const component = new Function('Setting', 'mapState', 'OrganizationResourceSelector', script)({ roterPre: '/admin' }, () => ({}), {})
const state = component.data()
const column = state.columns.find(item => item.title === '所属组织')
assert.equal(column.key, 'organization_name')
assert.equal(column.minWidth, 100)
assert.equal(state.columns.some(item => item.key === 'recommend_region_name'), false)
console.log('PASS 所属组织列读取正式组织名称，保留既有列宽')
assert.equal(source.includes('<FormItem label="所属组织："'), true)
assert.equal(source.includes('resource="organization"'), true)
assert.equal(source.includes('v-model="formValidate.organization_id"'), true)
assert.equal(source.includes('@change="searchs"'), true)
assert.equal(state.formValidate.organization_id, 0)
assert.equal('region_id' in state.formValidate, false)
assert.equal(/getAllRegion|allRegion\(|regionList/.test(source), false)
console.log('PASS 所属组织筛选复用组织选择器，移除旧区域读取路径')
assert.equal(state.columns.find(item => item.key === 'address').render instanceof Function, true)
assert.equal(state.columns.find(item => item.slot === 'action').fixed, 'right')
console.log('PASS 完整地址展示与操作列固定行为保持不变')
let reads = 0
const context = { ...state, getList() { reads++ }, $refs: {
  formValidate: { resetFields() {} },
} }
context.searchs = component.methods.searchs.bind(context)
context.formValidate.page = 5
context.formValidate.organization_id = 23
context.searchs()
assert.equal(context.formValidate.organization_id, 23)
assert.equal(context.formValidate.page, 1)
assert.equal(reads, 1)
context.formValidate.organization_id = 0
context.searchs()
assert.equal(context.formValidate.organization_id, 0)
console.log('PASS 选择和清空组织均回到第一页，查询参数只传组织 ID')
context.formValidate.organization_id = 23
context.formValidate.page = 4
component.methods.resetForm.call(context)
assert.equal(context.formValidate.organization_id, 0)
assert.equal(context.formValidate.page, 1)
assert.equal(reads, 3)
console.log('PASS 重置同步清空组织 ID、选择器回显及页码')
