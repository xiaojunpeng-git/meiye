import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')

const view = read('前端代码/cashier-v3/src/views/RoomSettingsView.vue')
const api = read('前端代码/cashier-v3/src/services/roomManagementApi.js')
const router = read('前端代码/cashier-v3/src/router/index.js')
const management = read('前端代码/cashier-v3/src/views/ManagementCenterView.vue')
const service = read('后端代码/app/services/store/RoomSettingsServices.php')

assert.match(router, /cashier-v3-room-settings/)
assert.match(management, /room-settings-v3/)
assert.match(view, /新增房间/)
assert.match(view, /room\.enabled && !room\.canDisable/)
assert.match(api, /\/storeapi\/room-settings/)
assert.match(service, /cashier_v3_reservation/)
assert.match(service, /cashier_v3_service_order/)
assert.match(service, /cashier_v3_hang_order/)
assert.match(service, /cashier_v3_room_open_service_guard/)
assert.match(service, /Db::transaction/)
assert.doesNotMatch(view, /房型|容量/)

console.log('ROOM_SETTINGS_FRONTEND_CONTRACT_OK')
