import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
const modal = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
const provider = readFileSync(new URL('../../../后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php', import.meta.url), 'utf8')

// 列表的展示值、周期条件和详情必须共用完成日期；草稿不借用保存日期。
assert.match(app, /UnifiedQueryDateRange :model-value="countDateRange" label="盘点日期周期"/)
assert.match(app, /columns: \['盘点单号'[^\n]*'盘点日期', '操作时间', '状态'\]/)
assert.match(app, /text\(row\.count_date\), operationTime\(row\.operation_at\)/)
assert.match(app, /field: 'count_date', operator: 'between'/)
// 首次进入按当天完成日期筛选；清空周期仍可检索无完成日期的草稿。
assert.match(app, /const countDateRange = ref\(todayRange\(\)\)/)
assert.match(app, /if \(key === 'count'\) countDateRange\.value = todayRange\(\)/)
assert.match(modal, /盘点日期：\{\{ detail\.document\.count_date \|\| '-' \}\}/)
assert.match(provider, /InventoryStockCountDate::fromConfirmedAt\(\(int\)\$row\['confirmed_at'\]\)/)
assert.match(provider, /'count_date' => ''/)
console.log('COUNT_DATE_UI_CONTRACT_OK')
