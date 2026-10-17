import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(path.join(repo, '前端代码/cashier-v3/src/views/StaffListView.vue'), 'utf8')
const shell = fs.readFileSync(path.join(repo, '前端代码/cashier-v3/src/layouts/CashierShell.vue'), 'utf8')

// 收银壳会裁剪路由外层溢出；员工页只能让结果表格滚动，分页必须始终留在可视工作区。
const pageRule = source.match(/\.staff-list-page\s*\{([^}]*)\}/)?.[1] ?? ''
const wrapRule = source.match(/\.staff-list-wrap\s*\{([^}]*)\}/)?.[1] ?? ''
const paginationIndex = source.indexOf('<TablePagination')
const tableIndex = source.indexOf('<main class="staff-list-wrap">')

const checks = [
  [/display\s*:\s*flex/.test(pageRule), '员工列表页必须使用纵向弹性布局'],
  [/flex-direction\s*:\s*column/.test(pageRule), '员工列表页必须按查询区、结果区、分页纵向排列'],
  [/height\s*:\s*100%/.test(pageRule), '员工列表页必须占满收银工作区高度'],
  [/overflow\s*:\s*hidden/.test(pageRule), '员工列表页不得把分页滚出收银工作区'],
  [/flex\s*:\s*1\s+1\s+auto/.test(wrapRule), '员工结果区必须占用剩余高度'],
  [/min-height\s*:\s*0/.test(wrapRule), '员工结果区必须允许在短视口内收缩'],
  [/overflow\s*:\s*auto/.test(wrapRule), '员工结果区必须独立滚动'],
  [tableIndex >= 0 && paginationIndex > tableIndex, '员工分页必须位于结果表格之后'],
  [
    /headerlessPageNames\s*=\s*new Set\(\[[\s\S]*?'cashier-v3-staff-list'[\s\S]*?\]\)/.test(shell),
    '员工列表路由必须隐藏收银壳重复页头并释放固定行高',
  ],
  [!shell.includes('openStaffCreator') && !shell.includes('isStaffPage'), '收银壳不得保留已移除员工页头的失效入口'],
]

const failures = checks.filter(([ok]) => !ok).map(([, message]) => message)
if (failures.length) {
  failures.forEach((message) => console.error(`FAIL: ${message}`))
  process.exit(1)
}

console.log('STAFF_PAGINATION_LAYOUT=PASS')
