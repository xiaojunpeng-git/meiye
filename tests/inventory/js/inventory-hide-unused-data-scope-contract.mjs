import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
const toolbar = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryOperationalUnifiedQueryToolbar.vue', import.meta.url), 'utf8')

// 库存范围由服务端仓库权限确定；所有库存查询入口都不能展示无效的 normal/all 切换。
assert.doesNotMatch(app, /storeOperationalDataScope|<label>数据范围/)
assert.match(app, /pageCode: operationalQueryPage\.value\.pageCode,\s*\/\/[^\n]*\n\s*dataScope: 'normal'/)
assert.match(app, /:show-data-scope="false"/)
assert.match(toolbar, /:show-data-scope="false"/)
assert.match(app, /<label>状态<select v-model="storeOperationalStatus"/)

console.log('INVENTORY_HIDE_UNUSED_DATA_SCOPE_CONTRACT_OK')
