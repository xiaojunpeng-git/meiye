import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const controller = fs.readFileSync(
  path.join(root, '后端代码/app/controller/admin/v1/organization/Organization.php'),
  'utf8',
)
const policy = fs.readFileSync(
  path.join(root, '后端代码/app/services/organization/JobPositionPolicyServices.php'),
  'utf8',
)
const workspace = fs.readFileSync(
  path.join(root, '前端代码/admin/src/pages/store/region/workspace/index.vue'),
  'utf8',
)

const assertions = [
  ['job-position endpoint accepts the Vue 3 store permission field', controller.includes("[['store_v3_rules', 'a'], []]")],
  ['workspace submits store_v3_rules for an enabled store entry', workspace.includes('store_v3_rules: Number(formPayload.use_store) === 1 ? (formPayload.store_v3_rules || []) : []')],
  ['policy resolves store_v3_rules as the store-v3 authority', policy.includes("self::CHANNEL_STORE_V3 => 'store_v3_rules'")],
  ['person preview labels the new store app as Vue 3', workspace.includes('<strong>Vue 3 门店端</strong>') && workspace.includes('暂无 Vue 3 门店端入口预览')],
]

let failed = 0
for (const [name, passed] of assertions) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.stdout.write(`ASSERT_FAILED=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
