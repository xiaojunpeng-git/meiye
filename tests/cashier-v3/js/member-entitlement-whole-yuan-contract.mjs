import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8')

const summary = read('前端代码/cashier-v3/src/components/common/MemberSummaryCard.vue')
const detail = read('前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const selector = read('前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue')

assert.match(summary, /<span>权益金额：<\/span>/)
assert.doesNotMatch(summary, /<span>次卡：<\/span>/)
assert.match(summary, /return raw\.split\('\.'\)\[0\]/)
assert.match(detail, /剩余项目次数不计算时间卡的次数/)
assert.match(shell, /void loadMemberDetailTab\(\{ memberId: openedMemberId, tab: memberDetailInitialTab\.value, keyword: '' \}\)/)
assert.match(shell, /loadSequence === memberDetailLoadSequence/)
assert.match(selector, /whole-yuan-floor-final-remainder-v1/)
assert.match(selector, /\\\.0\{1,2\}/)

console.log('MEMBER_ENTITLEMENT_WHOLE_YUAN_CONTRACT_OK')
