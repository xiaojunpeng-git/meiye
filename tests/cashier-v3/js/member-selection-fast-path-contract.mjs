#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(dirname, '../../..')
const shell = fs.readFileSync(path.join(repo, '前端代码/cashier-v3/src/layouts/CashierShell.vue'), 'utf8')
const memberSelection = shell.slice(shell.indexOf('async function selectMemberFromSelector'), shell.indexOf('async function completeMemberSelection'))

const checks = [
  ['会员选择直接消费 cashierDraft', /applyCashierMemberDraft\(result, record\)/.test(shell)],
  ['会员选择路径不再二次读取完整工作台', !/open-cashier-workbench/.test(memberSelection)],
  ['直接回填购物车行与汇总', /lines: draft\.lines[\s\S]*?summary: draft\.summary/.test(shell)],
  ['游客切换也消费命令返回草稿', /applyCashierMemberDraft\(result, null\)/.test(shell)]
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
