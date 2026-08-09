import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8')

const staffWriter = read('后端代码/app/services/employee/EmployeeStaffWriteServices.php')
const transferService = read('后端代码/app/services/store/StoreStaffTransferServices.php')
const transferApplyService = read('后端代码/app/services/store/StoreStaffTransferApplyServices.php')
const transferApplyDao = read('后端代码/app/dao/store/StoreStaffTransferApplyDao.php')
const transferApplyModel = read('后端代码/app/model/store/StoreStaffTransferApply.php')
const tenureService = read('后端代码/app/services/organization/StaffTenureServices.php')
const internalLogin = read('后端代码/app/services/employee/EmployeeInternalLoginServices.php')
const storeLogin = read('后端代码/app/services/cashier/v3/CashierV3StoreLoginServices.php')
const storeController = read('后端代码/app/controller/admin/v1/store/SystemStore.php')
const storeService = read('后端代码/app/services/store/SystemStoreServices.php')
const loginView = read('前端代码/cashier-v3/src/views/StoreLoginView.vue')
const workspace = read('前端代码/admin/src/pages/store/region/workspace/index.vue')
const employeeAuthCenter = read('后端代码/app/services/organization/EmployeeAuthCenterServices.php')

const singleAssignmentMethod = staffWriter.slice(
  staffWriter.indexOf('protected function assertSingleActiveStoreAssignment'),
  staffWriter.indexOf('public function assertStoreRolesUsable'),
)
const storeStatusMethod = storeController.slice(
  storeController.indexOf('public function set_show'),
  storeController.indexOf('public function resetAdminForm'),
)
const resetAdminMethod = storeService.slice(
  storeService.indexOf('public function resetAdmin'),
  storeService.indexOf('public function erpShopList'),
)
const saveStoreMethod = storeService.slice(
  storeService.indexOf('public function saveStore'),
  storeService.indexOf('public function storeChart'),
)

const assertions = [
  [
    'transfer application service dependency has a concrete DAO and model',
    transferApplyService.includes('StoreStaffTransferApplyDao $dao')
      && transferApplyDao.includes('class StoreStaffTransferApplyDao extends BaseDao')
      && transferApplyDao.includes('return StoreStaffTransferApply::class;')
      && transferApplyModel.includes('class StoreStaffTransferApply extends BaseModel')
      && transferApplyModel.includes("protected $name = 'store_staff_transfer_apply';")
      && transferApplyModel.includes('protected $autoWriteTimestamp = false;'),
  ],
  [
    'staff writes enforce one active assignment across every store',
    staffWriter.includes('assertSingleActiveStoreAssignment($employeeId, $staffId, $staffStatus)')
      && singleAssignmentMethod.includes("->where('employee_id', $employeeId)")
      && !singleAssignmentMethod.includes("->where('store_id', $storeId)"),
  ],
  [
    'inactive assignment edits do not create a false single-store conflict',
    singleAssignmentMethod.includes('if ($targetStatus !== 1)') && singleAssignmentMethod.includes('return;'),
  ],
  [
    'cross-store assignment creation directs operators to the transfer workflow',
    singleAssignmentMethod.includes('如需变更门店请使用调店功能'),
  ],
  [
    'resuming an old tenure cannot create a second active store assignment',
    tenureService.includes("(int)($staff['status'] ?? 0) === 1")
      && tenureService.includes('当前任职已生效，无需重复复职')
      && tenureService.includes("->where('id', '<>', $staffId)")
      && tenureService.includes('$otherActiveStaff')
      && tenureService.includes('如需变更门店请使用调店功能')
      && tenureService.includes("->where('id', $employeeId)->where('is_del', 0)->lock(true)->find()"),
  ],
  [
    'the person drawer exposes resume only for an ended, inactive tenure and keeps the existing confirmation flow',
    employeeAuthCenter.includes("$t['staff_id'] = (int)($s['id'] ?? 0);")
      && workspace.includes("v-if=\"row.can_resume\"")
      && workspace.includes("@click=\"openTenureConfirm(row.staff, 'resume')\"")
      && workspace.includes("can_resume: !statusOn && !!assignment && Number(assignment.status) !== 1"),
  ],
  [
    'transfer validates the source is the only active assignment before changing tenure',
    transferService.includes('$activeAssignmentIds')
      && transferService.includes('$activeAssignmentIds[0] !== $sourceStaffId')
      && transferService.indexOf('$activeAssignmentIds') < transferService.indexOf('// 停用原任职'),
  ],
  [
    'transfer validates the target is the only active assignment before commit',
    transferService.includes('$activeAfterTransfer[0] !== $executeStaffId')
      && transferService.includes('员工未形成唯一目标门店任职'),
  ],
  [
    'store-v3 login resolves exactly one active store assignment',
    internalLogin.includes('resolveUniqueStoreV3Staff')
      && internalLogin.includes('当前员工存在多条有效门店任职，请先完成调店处理')
      && storeLogin.includes('resolveUniqueStoreV3Staff($employeeId)'),
  ],
  [
    'store-v3 login no longer creates a store-selection ticket',
    !storeLogin.includes('SELECT_TICKET_PREFIX')
      && !storeLogin.includes("'need_select_store' => true")
      && !storeLogin.includes("'login_ticket'"),
  ],
  [
    'the legacy context-switch endpoint cannot enter another store',
    storeLogin.includes('员工只能进入当前任职门店')
      && storeLogin.match(/resolveUniqueStoreV3Staff\(\$employeeId\)/g)?.length >= 2
      && !storeLogin.includes('$employees->listEligibleStoreV3Staff($employeeId)'),
  ],
  [
    'store-v3 login page submits credentials once and has no store selector',
    loginView.includes("loginStoreV3({ account: account.value.trim(), pwd: password.value })")
      && !loginView.includes('needsStoreSelection')
      && !loginView.includes('<select')
      && !loginView.includes('请选择门店'),
  ],
  [
    'changing store business status never changes employee tenure status',
    !storeStatusMethod.includes('SystemStoreStaffServices')
      && !storeStatusMethod.includes("['status' => 1]")
      && !storeStatusMethod.includes("['status' => 0]"),
  ],
  [
    'resetting a store administrator resolves the current manager on the server',
    resetAdminMethod.includes("->where('store_id', $id)")
      && resetAdminMethod.includes("->where('is_manager', 1)")
      && resetAdminMethod.includes('->lock(true)')
      && resetAdminMethod.includes('管理员任职信息已变化，请刷新后重试'),
  ],
  [
    'administrator reset cannot create or move tenure and rejects duplicate active assignment',
    !resetAdminMethod.includes("'store_id' =>")
      && !resetAdminMethod.includes('$staffServices->save(')
      && resetAdminMethod.includes('请先在人员管理中设置该门店管理员')
      && resetAdminMethod.includes("->where('employee_id', $employeeId)")
      && resetAdminMethod.includes('当前员工存在其他有效门店任职，请先完成调店处理')
      && resetAdminMethod.includes('该手机号已在其他门店任职，如需变更门店请使用调店功能'),
  ],
  [
    'new store administrator creation uses the unified single-store assignment writer',
    saveStoreMethod.includes('EmployeeStaffWriteServices::class')
      && saveStoreMethod.includes('saveStaffAssignment(0, $staff_data')
      && saveStoreMethod.includes("['use_outer_transaction' => true]")
      && !saveStoreMethod.includes('$staffServices->save($staff_data)'),
  ],
]

let failed = 0
for (const [name, passed] of assertions) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.stdout.write(`ASSERT_FAILED=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
