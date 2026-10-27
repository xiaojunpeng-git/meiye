import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const app = readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')

// Stock pagination is shared by both hosts; totals and warehouse scope must remain server-authoritative.
assert.match(app, /const stockPage = ref\(1\)/)
assert.match(app, /const stockPages = computed\(\(\) => Math\.max\(1, Math\.ceil\(Number\(remoteTotal\.value\) \/ stockPageSize\)\)\)/)
assert.match(app, /query\.page = stockPage\.value[\s\S]*query\.limit = stockPageSize/)
assert.match(app, /remoteTotal\.value = Number\(response\?\.total \?\? response\?\.count \?\? list\.length\)/)
assert.doesNotMatch(app, /remoteTotal\.value = active\.value === 'stock' && mode\.value === 'store' \? sourceRows\.value\.length/)
assert.match(app, /await loadCurrentList\(Object\.keys\(executed\)\.length \? \{ \.\.\.executed, page: nextPage, limit: stockPageSize \} : null\)/)
assert.match(app, /if \(active\.value === 'stock' && stockPage\.value > stockPages\.value\)/)
// 门店与平台共享 100 行固定底部分页，后端完整总数与库存汇总不按当前页重算。
assert.match(app, /const stockPageSize = 100/)
assert.match(app, /<footer v-if="inventoryListPage" class="inventory-warehouse-pagination inventory-list-pagination"/)
assert.match(app, /const inventoryListPage = computed\(\(\) => storeListPage\.value \|\|/)
assert.match(app, /stockPage\.value = 1[\s\S]*stockExecutedQuery\.value = \{\}/)

console.log('STOCK_PAGINATION_CONTRACT_OK checks=11')
