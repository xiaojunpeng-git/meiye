import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
const modal = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
const provider = readFileSync(new URL('../../../后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php', import.meta.url), 'utf8')

// 草稿无完成日期：列表由服务端保留草稿，保存后不能替用户切换筛选或加载商品。
assert.match(modal, /await persistCountDraft\(\)[\s\S]*?emit\('saved'\)/)
assert.match(app, /count: \['草稿', '已确认', '已取消'\]/)
assert.match(app, /草稿: 'DRAFT', 已确认: 'CONFIRMED', 已取消: 'CANCELLED'/)
assert.doesNotMatch(app, /result\?\.countDraftSaved/)
assert.match(app, /storeOperationalStatus\.value === '草稿' \? \{ min: '', max: '' \} : todayRange\(\)/)
assert.match(app, /field: 'status_name', operator: 'eq', value: operationalStatusFilterValue\(\)/)
assert.match(app, /if \(Number\(row\?\.draft_id \|\| 0\) > 0\) openCountDraftDetail\(row\)/)
assert.match(app, /@edit-count-draft="editor = 'count-edit'"/)
assert.match(modal, /isCountDraftDetail && detail\?\.draft_id/)
assert.match(modal, /@click="emit\('edit-count-draft'\)"/)
assert.match(app, /active === 'count' && Number\(sourceRows\[index\]\?\.draft_id \|\| 0\) > 0/)
assert.match(app, /requestDeleteCountDraft\(sourceRows\[index\]\)/)
assert.match(app, /await inventoryApi\.countDraftDetail\(row\.draft_id\)[\s\S]*?countDraftDeleteConfirmation\.value = \{ id: Number\(detail\.draft_id\), version: Number\(detail\.version\) \}/)
assert.match(app, /await inventoryApi\.deleteCountDraft\(target\.id, target\.version\)/)
assert.match(app, /服务器中保存的草稿和全部盘点商品数据将被永久删除/)
assert.match(provider, /'status_name' => 'DRAFT'/)
assert.match(provider, /'count_date' => ''/)
assert.match(provider, /includeDraftsOutsideCompletionPeriod/)

console.log('COUNT_DRAFT_LIST_VISIBILITY_CONTRACT_OK')
