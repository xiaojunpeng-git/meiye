import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')
const staffApi = read('前端代码/admin/src/api/staff.js')
const staffForm = read('前端代码/admin/src/pages/setting/staff/add.vue')
const controller = read('后端代码/app/controller/admin/v1/merchant/SystemStoreStaff.php')
const routes = read('后端代码/route/admin.php')

const assertions = [
  ['explicit employee API uses a dedicated endpoint', staffApi.includes('merchant/staff/person_complete/save/${employeeId}')],
  ['organization form saves through the explicit employee API', staffForm.includes("this.scene === 'organization'\n        ? postPersonComplete(payload, Number(this.editId) || 0, headers)")],
  ['dedicated route is registered', routes.includes("Route::post('staff/person_complete/save/:id', 'v1.merchant.SystemStoreStaff/savePersonComplete')")],
  ['controller keeps the legacy and explicit identity paths separate', controller.includes('return $this->saveStaffByIdentity((int)$id, false);') && controller.includes('return $this->saveStaffByIdentity((int)$employeeId, true);')],
  ['explicit path rejects employee mismatch and unrelated staff', controller.includes("return $this->fail('路径员工与提交员工不一致');") && controller.includes("return $this->fail('任职与员工不匹配');")],
]

let failed = 0
for (const [name, passed] of assertions) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.exit(failed === 0 ? 0 : 1)
