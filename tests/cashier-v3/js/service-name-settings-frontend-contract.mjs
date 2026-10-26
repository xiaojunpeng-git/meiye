import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')

const view = read('前端代码/cashier-v3/src/views/RoomSettingsView.vue')
const api = read('前端代码/cashier-v3/src/services/roomManagementApi.js')
const routes = read('后端代码/route/cashier-v3.php')

assert.match(view, />房间设置<\/button>/)
assert.match(view, />服务名称<\/button>/)
assert.match(view, /switchTab\('services'\)/)
assert.match(view, /查询<\/button>\s*<button[^>]+openCreate\('room'\)[^>]*>新增房间<\/button>/)
assert.match(view, /openCreate\('service'\)[^>]*>新增服务<\/button>/)
assert.match(view, /toggleServiceSwitch/)
assert.match(view, /服务开关：\{\{ serviceSwitch\.enabled \? '开' : '关' \}\}/)
assert.match(view, /result\.switch\?\.enabled/)
assert.match(view, /openServiceEdit\(service\)[^>]*>编辑<\/button>/)
assert.match(view, /moveService\(serviceRecords\.indexOf\(service\), -1\)/)
assert.match(view, /moveService\(serviceRecords\.indexOf\(service\), 1\)/)
assert.match(view, /toggleService\(service\)/)
assert.match(view, /service\.enabled \? '停用' : '启用'/)
assert.match(view, /createdAt/)
assert.match(view, /updatedAt/)
assert.doesNotMatch(view, /删除服务|deleteServiceName/)

assert.match(api, /\/cashierapi\/v3\/management\/service-names/)
assert.match(api, /expected_version: 0/)
assert.match(api, /expected_version: Number\(expectedVersion\)/)
assert.match(api, /request_token: token/)
assert.match(api, /setServiceNameEnabled/)
assert.match(api, /sortServiceNames/)
assert.match(api, /setServiceNameSwitch/)
assert.match(api, /service-names\/switch/)
assert.match(routes, /store\.system\.ServiceNames\/index/)
assert.match(routes, /store\.system\.ServiceNames\/create/)
assert.match(routes, /store\.system\.ServiceNames\/update/)
assert.match(routes, /store\.system\.ServiceNames\/setStatus/)
assert.match(routes, /store\.system\.ServiceNames\/sort/)
assert.match(routes, /store\.system\.ServiceNames\/setSwitch/)

console.log('SERVICE_NAME_SETTINGS_FRONTEND_CONTRACT_OK')
