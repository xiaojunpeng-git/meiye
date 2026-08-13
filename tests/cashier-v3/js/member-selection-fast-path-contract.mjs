#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(dirname, '../../..')
const shell = fs.readFileSync(path.join(repo, '前端代码/cashier-v3/src/layouts/CashierShell.vue'), 'utf8')
const selector = fs.readFileSync(path.join(repo, '前端代码/cashier-v3/src/components/common/MemberSelectorOverlay.vue'), 'utf8')
const memberSelection = shell.slice(shell.indexOf('async function selectMemberFromSelector'), shell.indexOf('async function completeMemberSelection'))

const checks = [
  ['会员选择直接消费 cashierDraft', /applyCashierMemberDraft\(result, record\)/.test(shell)],
  ['会员选择路径不再二次读取完整工作台', !/open-cashier-workbench/.test(memberSelection)],
  ['直接回填购物车行与汇总', /lines: draft\.lines[\s\S]*?summary: draft\.summary/.test(shell)],
  ['游客切换也消费命令返回草稿', /applyCashierMemberDraft\(result, null\)/.test(shell)],
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
