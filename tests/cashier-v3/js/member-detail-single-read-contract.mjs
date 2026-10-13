import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const root = new URL('../../../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const memberList = read('前端代码/cashier-v3/src/views/MemberListView.vue')
const detail = read('后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php')

const workflowOpen = shell.split('function openWorkflowMemberDetail(memberId) {')[1].split('\nfunction selectGuestOrderFromWorkflow')[0]
const listOpen = memberList.split('function openMemberDetail(record) {')[1].split('\nfunction resultData')[0]
assert.match(workflowOpen, /cashier-v3:open-member-detail/)
assert.doesNotMatch(workflowOpen, /requestCashierV3Action\('open-member-detail'/)
assert.match(listOpen, /cashier-v3:open-member-detail/)
assert.doesNotMatch(listOpen, /requestAction\('open-member-detail'/)
assert.match(detail, /\$loadAll = \$tab === '';/)
assert.match(detail, /\$gifts = \(\$loadAll \|\| \$tab === 'gift'\)/)
assert.match(detail, /\$query = Db::name\('store_order'\)->alias\('o'\)->force\('uid'\)/)
console.log('MEMBER_DETAIL_SINGLE_READ_CONTRACT_OK')
