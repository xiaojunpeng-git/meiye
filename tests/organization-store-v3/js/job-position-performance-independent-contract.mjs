import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const modal = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/store/region/workspace/components/JobPositionFormModal.vue'), 'utf8')
const workspace = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/store/region/workspace/index.vue'), 'utf8')
const controller = fs.readFileSync(path.join(root, '后端代码/app/controller/admin/v1/organization/Organization.php'), 'utf8')
const service = fs.readFileSync(path.join(root, '后端代码/app/services/organization/JobPositionPolicyServices.php'), 'utf8')
const migration = fs.readFileSync(path.join(root, '后端代码/database/upgrades/2026-08-28-岗位业绩独立核算标记/02-正式升级.sql'), 'utf8')

const assertions = [
  ['岗位弹窗显示业绩独立核算开关', modal.includes('业绩独立核算') && modal.includes('performance_independent')],
  ['岗位弹窗保存并回填开关值', modal.includes('performance_independent: Number(this.form.performance_independent) === 1 ? 1 : 0') && modal.includes('Number(p.performance_independent) === 1 ? 1 : 0')],
  ['工作台表单和列表保存链路透传开关', workspace.includes('performance_independent: Number(formPayload.performance_independent) === 1 ? 1 : 0') && workspace.includes('performance_independent: Number(src.performance_independent) === 1 ? 1 : 0')],
  ['后端白名单并规范化开关值', controller.includes("[['performance_independent', 'd'], 0]") && service.includes('$performanceIndependent = (int)($data[\'performance_independent\'] ?? 0) === 1 ? 1 : 0;')],
  ['迁移字段默认关闭且可重复执行', migration.includes('ADD COLUMN `performance_independent` tinyint unsigned NOT NULL DEFAULT 0') && migration.includes('information_schema.COLUMNS')],
  ['开关用于收银独立分组核算', service.includes('performance_independent') && service.includes('用于收银人员分配')],
  ['员工岗位选择限制为单选', fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/views/StaffListView.vue'), 'utf8').includes('type="radio"') && fs.readFileSync(path.join(root, '后端代码/app/services/organization/StaffJobPositionServices.php'), 'utf8').includes('每位员工只能设置一个有效岗位')],
]

let failed = 0
for (const [name, passed] of assertions) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.stdout.write(`ASSERT_FAILED=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
