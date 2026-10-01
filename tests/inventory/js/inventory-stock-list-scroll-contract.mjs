import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'

const root = path.resolve(process.cwd())
const app = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
const styles = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/styles.css'), 'utf8')

const checks = [
  ['滚动约束只挂载到库存查询结果卡片', app.includes(":class=\"{ 'table-card--stock-scroll': active === 'stock' }\"")],
  ['库存结果区具有视口高度上限', styles.includes('.table-card--stock-scroll .table-scroll { max-height: clamp(320px, calc(100vh - 360px), 680px);')],
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
