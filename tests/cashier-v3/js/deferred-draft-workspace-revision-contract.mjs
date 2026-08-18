import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const view = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'),
  'utf8'
)

assert.match(
  view,
  /function adoptCashierWorkspaceRevisionFromResult\(result = \{\}\)[\s\S]*?responseDataBlock\(result\)[\s\S]*?cashier_workspace[\s\S]*?state\.workspace = \{ \.\.\.state\.workspace, revision: Number\(row\.version\) \}/,
  '每条命令响应必须推进 cashier workspace 版本'
)

const sync = view.slice(view.indexOf('async function synchronizeLocalCashierDraft'))
const revisionAdoptions = (sync.match(/adoptCashierWorkspaceRevisionFromResult\(/g) || []).length
assert.ok(revisionAdoptions >= 6, '草稿回放的各类成功命令都应推进版本')

assert.match(
  view,
  /function reportCheckoutEntryFailure\(result, fallback = '[^']+'\)[\s\S]*?cashier-v3:ui-result[\s\S]*?resultMessage\(result, fallback\)/,
  '结账入口同步失败必须向用户报告'
)

console.log('DEFERRED_DRAFT_WORKSPACE_REVISION_CONTRACT passed=3 failed=0')
