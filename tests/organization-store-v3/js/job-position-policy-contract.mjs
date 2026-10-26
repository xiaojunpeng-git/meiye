import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const workspace = fs.readFileSync(
  path.join(root, '前端代码/admin/src/pages/store/region/workspace/index.vue'),
  'utf8',
)
const styles = fs.readFileSync(
  path.join(root, '前端代码/admin/src/pages/store/region/workspace/workspace.css'),
  'utf8',
)
const controller = fs.readFileSync(
  path.join(root, '后端代码/app/controller/admin/v1/organization/Organization.php'),
  'utf8',
)
const routes = fs.readFileSync(
  path.join(root, '后端代码/route/admin.php'),
  'utf8',
)
const service = fs.readFileSync(
  path.join(root, '后端代码/app/services/organization/JobPositionPolicyServices.php'),
  'utf8',
)

const assertions = [
  ['岗位列表默认只显示启用项', workspace.includes("status: 1,")
    && workspace.includes('v-model="jobPositionModal.status"')
    && workspace.includes('<option :value="1">启用</option>')],
  ['岗位列表提供全部状态筛选并按状态重新加载', workspace.includes('<option value="">全部</option>')
    && workspace.includes('@change="loadJobPositionList"')
    && workspace.includes('status: this.jobPositionModal.status')],
  ['岗位筛选控件具有独立可见样式', styles.includes('.org-prototype .jp-toolbar .jp-status-filter')
    && styles.includes('min-width: 88px')],
  ['岗位保存接口接收店长标记，列表切换不会静默清除', controller.includes("[['is_store_manager', 'd'], 0]")
    && service.includes("'is_store_manager' => $isStoreManager")],
  ['岗位查询服务执行状态过滤', service.includes("$q->where('status', (int)$where['status']);")],
  ['启停使用状态专用保存分支，避免岗位重名校验阻断', workspace.includes('status_only: 1')
    && controller.includes("[['status_only', 'd'], 0]")
    && controller.includes("$data['status_only']")
    && service.includes("'job_position_status'")],
]

let failed = 0
for (const [name, passed] of assertions) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.stdout.write(`ASSERT_FAILED=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
