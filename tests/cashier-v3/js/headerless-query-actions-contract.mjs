import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { resolve } from 'node:path'

const root = resolve(fileURLToPath(new URL('../../..', import.meta.url)))
const read = (file) => readFileSync(resolve(root, file), 'utf8')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const css = read('前端代码/cashier-v3/src/styles/base.css')
const reservation = read('前端代码/cashier-v3/src/views/ReservationListView.vue')
const member = read('前端代码/cashier-v3/src/views/MemberListView.vue')
const toolbar = read('前端代码/shared/unified-query-vue3/src/components/UnifiedQueryToolbar.vue')

const routeBlock = shell.match(/const headerlessPageNames = new Set\(\[([\s\S]*?)\]\)/)?.[1]
assert.ok(routeBlock, '隐藏标题栏必须使用明确的路由白名单')
const routes = [...routeBlock.matchAll(/'([^']+)'/g)].map((match) => match[1]).sort()
assert.deepEqual(routes, [
  'cashier-v3-care',
  'cashier-v3-fund',
  'cashier-v3-hang',
  'cashier-v3-member',
  'cashier-v3-order-center',
  'cashier-v3-reservation',
  'cashier-v3-room',
  'cashier-v3-store-business-reports',
  'cashier-v3-store-target-dashboard'
].sort(), '只能隐藏本轮逐项指定的九个页面')
assert.match(shell, /!isCashierWorkflowPage && !isInventoryWorkspaceOpen && !isHeaderlessPage/, '其他页面继续展示外壳标题栏')
assert.match(css, /\.cashier-main--workflow,\s*\.cashier-main--headerless\s*\{\s*grid-template-rows: minmax\(0, 1fr\)/, '移除标题栏后必须释放固定行高')
assert.match(toolbar, />重置<\/button>[\s\S]*?<slot name="primary-actions" \/>/, '新增操作插槽必须位于重置后')
assert.match(reservation, /<template #primary-actions>[\s\S]*?@click="openReservationEditor\(\{ mode: 'create' \}\)"[^>]*>新增预约<\/button>/, '预约新增入口必须走页面自己的编辑器准备流程')
assert.match(member, /<template #primary-actions>[\s\S]*?v-if="canCreateMember"[^>]*@click="openMemberCreator\(\)"[^>]*>新增会员<\/button>/, '会员新增入口必须保持权限限制')
assert.doesNotMatch(member, /member-list-page__head/, '会员列表不能留独立按钮行')

console.log('PASS headerless-query-actions-contract')
