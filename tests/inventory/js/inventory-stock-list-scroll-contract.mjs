import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'

const root = path.resolve(process.cwd())
const app = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
const styles = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/styles.css'), 'utf8')

const checks = [
  ['库存查询沿用后端固定布局，仓库设置与列表共用滚动容器', app.includes("'table-card--stock-scroll': stockTableFixed || active === 'warehouse'") && app.includes("active.value === 'stock' && stockTableLayout.value.fixed === true")],
  ['两端库存结果区占满剩余高度并独立滚动', styles.includes('.table-card--inventory-list-page .table-scroll { flex: 1 1 auto; min-height: 0; max-height: none; }')],
  ['平台结果区使用同一固定底部分页布局', styles.includes('.inventory-shell--inventory-list-page .inventory-list-result { display: flex;') && app.includes("'table-card--inventory-list-page': inventoryListPage")],
  ['库存结果区始终显示纵向滚动轨道', styles.includes('overflow-y: scroll;') && styles.includes('scrollbar-gutter: stable;')],
  ['库存表头在滚动时固定', styles.includes('.table-card--stock-scroll .table-scroll thead th { position: sticky;')],
  ['滚动条包含 Chromium 与 Firefox 样式', styles.includes('::-webkit-scrollbar-thumb') && styles.includes('scrollbar-width: thin;')],
]

let failed = 0
for (const [name, passed] of checks) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`)
  if (!passed) failed += 1
}

if (failed) {
  console.error(`inventory stock list scroll contract failed: ${failed}/${checks.length}`)
  process.exit(1)
}

console.log(`inventory stock list scroll contract passed: ${checks.length}/${checks.length}`)
