import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const loginService = fs.readFileSync(
  path.join(root, '后端代码/app/services/employee/EmployeeInternalLoginServices.php'),
  'utf8',
)
const positionService = fs.readFileSync(
  path.join(root, '后端代码/app/services/organization/StaffJobPositionServices.php'),
  'utf8',
)
const featureResolver = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/permission/CashierV3FeatureResolver.php'),
  'utf8',
)

const assertions = [
  ['store-v3 eligibility is resolved through the employee store-login filter', loginService.includes('listEligibleStoreV3Staff')],
  ['a missing employee channel override inherits the assigned position rules', !loginService.includes("->where('status', 1)\n            ->where('is_del', 0)\n            ->find();\n        if (!$entry) {")],
  ['an explicit disabled employee channel override still denies entry', loginService.includes("if ($entry && (int)($entry['status'] ?? 0) !== 1) {")],
  ['feature resolution also inherits a missing employee channel override', featureResolver.includes("->where('is_del', 0)->find();\n            if ($entry && (int)($entry['status'] ?? 0) !== 1) {")],
  ['role projection documents the same no-override inheritance rule', positionService.includes('无入口表时：以岗位规则为准')],
]

let failed = 0
for (const [name, passed] of assertions) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.stdout.write(`ASSERT_FAILED=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
