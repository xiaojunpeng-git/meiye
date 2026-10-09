import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')

// 实际行映射只读取后端行归属，不能把仓名或当前登录门店当成盘点单的门店名称。
const mapping = app.slice(app.indexOf('function mapApiRow('), app.indexOf('/** Status can no longer'))
assert.ok(mapping.startsWith('function mapApiRow('))
const mapApiRow = new Function('text', 'centsMoney', 'countStatusName', 'operationTime', `${mapping}; return mapApiRow`)(
  (value, fallback = '-') => value === undefined || value === null || value === '' ? fallback : String(value),
  String, String, String
)
assert.equal(mapApiRow('count', { store_name: '肥西水晶城', location_name: '默认门店仓' })[1], '肥西水晶城')
assert.equal(mapApiRow('count', { store_name: '', location_name: '默认门店仓', store_name_label: '错误门店' })[1], '-')
assert.equal(mapApiRow('count', { store_name: '总部仓', location_name: '总部仓' })[1], '总部仓')
assert.match(app, /count: \{ pageCode: 'inventory_count', fields: \[\['order_sn', '盘点单号'\], \['store_name', '门店名称'\]/)
assert.match(app, /columns: \['盘点单号', '门店名称', '盘点范围'/)
assert.ok(!app.includes('盘点主体'))
console.log('COUNT_STORE_NAME_CONTRACT_OK')
