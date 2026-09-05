#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(dirname, '../../..')
const shell = fs.readFileSync(path.join(repo, '前端代码/cashier-v3/src/layouts/CashierShell.vue'), 'utf8')
const selector = fs.readFileSync(path.join(repo, '前端代码/cashier-v3/src/components/common/MemberSelectorOverlay.vue'), 'utf8')
const memberSelection = shell.slice(shell.indexOf('async function selectMemberFromSelector'), shell.indexOf('function applyCashierMemberDraft'))
const memberSelectionFlow = shell.slice(shell.indexOf('async function selectMemberFromSelector'), shell.indexOf('async function selectGuestOrderFromSelector'))
const guestSelection = shell.slice(shell.indexOf('async function selectGuestOrderFromSelector'), shell.indexOf('async function createMemberFromSelector'))
const cashierSelectorOpen = shell.slice(shell.indexOf('async function openWorkflowMemberSelector'), shell.indexOf('async function openWorkflowMemberDetail'))

const checks = [
  ['收银会员选择只写入浏览器草稿', /applyLocalCashierCustomerSelection\(\{ customerMode: 'member', member: record \}\)/.test(memberSelection)],
  ['收银会员选择不再调用服务端选择命令', !/select-cashier-member/.test(memberSelection)],
  ['收银会员选择不再二次读取完整工作台', !/open-cashier-workbench/.test(memberSelection)],
  ['收银会员选择读取权威欠款摘要', /requestCashierV3Action\(\s*['"]query-cashier-member-summary['"]/.test(memberSelection)
    && /memberSummary/.test(memberSelection)
    && /pendingDebtReminderAfterSource\.value = \{ member: selectedMember \}/.test(memberSelection)
    && /open-toolbar-business-source/.test(memberSelectionFlow)
    && !/handleCheckoutBusinessSourceSettled\(\)\s*;\s*return true/.test(memberSelectionFlow)],
  ['收银会员选择器打开不再写服务端工作台', !/requestCashierV3Action\(\s*['"]open-member-selector['"]/.test(cashierSelectorOpen)],
  ['游客切换只写入浏览器草稿', /applyLocalCashierCustomerSelection\(\{ customerMode: 'guest' \}\)/.test(guestSelection)],
  ['游客切换不再调用服务端命令', !/requestCashierV3Action\(\s*['"]set-guest-order['"]/.test(guestSelection)],
  ['本地客户切换清空购物车与来源', /function emptyLocalCashierCart\([\s\S]*?lines: \[\]/.test(shell)
    && /function applyLocalCashierCustomerSelection[\s\S]*?cashier-v3:clear-toolbar-business-source/.test(shell)],
  ['新增会员可触发推荐人选择', selector.includes('onSelectReferrer')
    && selector.includes(':on-select-referrer="onSelectReferrer"')
    && shell.includes('function selectMemberReferrer(')
    && shell.includes(':on-select-referrer="selectMemberReferrer"')],
  ['推荐人选择使用独立弹层以保留新建会员表单', shell.includes('const isReferrerSelectorOpen = ref(false)')
    && shell.includes('v-if="isReferrerSelectorOpen"')
    && shell.includes('function closeReferrerSelector()')],
  ['推荐人查询使用当前门店推荐人范围和收银权限入口', shell.includes("selectorContext: 'member-referrer'")
    && shell.includes("selectorEntry: 'cashier'")
    && shell.includes("const selectorEntry = selectorContext === 'member-referrer'" )
    && !shell.includes("selectorEntry: 'member-referrer'")],
  ['推荐人不能选择自己', shell.includes('memberId === referrerSelectorExcludedMemberId')]
]

let failed = 0
for (const [name, passed] of checks) {
  if (passed) console.log(`PASS ${name}`)
  else {
    failed += 1
    console.log(`FAIL ${name}`)
  }
}
if (failed) process.exitCode = 1
