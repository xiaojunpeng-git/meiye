import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relative) => fs.readFileSync(path.join(repo, relative), 'utf8')
const staffView = read('前端代码/cashier-v3/src/views/StaffListView.vue')
const staffApi = read('前端代码/cashier-v3/src/services/staffManagementApi.js')
const router = read('前端代码/cashier-v3/src/router/index.js')
const management = read('前端代码/cashier-v3/src/views/ManagementCenterView.vue')
const registrar = read('后端代码/app/services/query/provider/StaffUnifiedQueryPageRegistrar.php')
const storeStaffController = read('后端代码/app/controller/store/staff/StoreStaff.php')
const personCompleteWriter = read('后端代码/app/services/employee/EmployeePersonCompleteWriteServices.php')
const employeeTypeAuthority = read('后端代码/app/services/employee/EmployeeTypeAuthorityServices.php')

const failures = []
const expect = (condition, message) => {
  if (!condition) failures.push(message)
}

expect(staffView.includes("const PAGE_CODE = 'staff_list'"), '员工页未绑定 staff_list')
expect(staffView.includes("requestAction('query-staff'"), '员工页未走统一查询动作')
expect(staffView.includes('<UnifiedQueryToolbar'), '员工页未使用统一查询工具栏')
expect(staffView.includes(':on-create-export="unifiedQuery.createExport"'), '员工页未接统一导出')
expect(
  staffView.includes('const capability = await unifiedQuery.load({ silent: true })')
    && staffView.includes('await queryStaff(initialQuery(capability))')
    && staffView.includes('isQueryLoading.value = false'),
  '员工页未在门店上下文稳定后执行核心首次查询'
)
expect(staffView.includes("key: 'uid', label: '商城用户ID'"), '员工页缺少旧商城用户ID字段')
expect(staffView.includes('salespersonEnabled') && staffView.includes('craftsmanEnabled'), '员工编辑缺少独立资格开关')
expect(router.includes("path: 'management-center/staff'"), '员工列表路由未登记')
expect(
  management.includes("id: 'staff-list-v3'")
    && management.includes("router.push({ name: 'cashier-v3-staff-list' })"),
  '管理菜单未登记员工列表'
)
expect(
  staffApi.includes("credentials: 'omit'")
    && staffApi.includes("'Authori-zation': `Bearer ${token}`"),
  '员工编辑接口未使用门店 V3 Bearer 会话'
)
expect(staffApi.includes('cashier_salesperson_enabled') && staffApi.includes('cashier_craftsman_enabled'), '员工编辑未提交两个资格字段')
expect(
  staffApi.includes('crypto.randomUUID')
    && staffApi.includes('crypto.getRandomValues')
    && !staffApi.includes('STAFF_ROLE_${Date.now()}'),
  '员工编辑必须生成后端可校验的 UUID 请求令牌'
)
expect(
  staffView.includes("{ id: 'basic', label: '基本信息' }")
    && staffView.includes("{ id: 'scope', label: '数据权限' }")
    && staffView.includes("{ id: 'login', label: '登录设置' }")
    && staffView.includes("{ id: 'other', label: '其他信息' }"),
  '员工新增/编辑未提供完整四页签'
)
expect(
  staffApi.includes('readStoreStaffComplete')
    && staffApi.includes('readStoreStaffPositions')
    && staffApi.includes('/storeapi/staff/staff/positions')
    && staffApi.includes('readStoreStaffWorkMembers')
    && staffApi.includes('uploadStoreStaffAvatar')
    && staffApi.includes('saveStoreStaff'),
  '员工完整资料接口未接齐'
)
expect(
  staffApi.includes('position_ids')
    && staffApi.includes('work_member_id')
    && staffApi.includes('is_reservable')
    && staffApi.includes('employee_number')
    && staffApi.includes('contract_begin')
    && staffApi.includes('department')
    && staffApi.includes('employment_type_code')
    && staffApi.includes('employment_type_version'),
  '员工完整资料字段未提交'
)
expect(
  staffView.includes("employmentTypeCode: 'internal'")
    && staffView.includes('employmentTypeVersion: 1')
    && staffView.includes("['internal', 'partner', 'outsourced'].includes(editorValues.employmentTypeCode)")
    && staffView.includes('<option value="partner">合作方</option>'),
  '门店员工人员类型选择或版本校验未接入'
)
expect(
  storeStaffController.includes("'work_member_id', 'notify', 'is_customer', 'customer_url', 'is_reservable'")
    && personCompleteWriter.includes("'work_member_id', 'notify', 'is_customer', 'customer_url', 'is_reservable'")
    && personCompleteWriter.includes("'source_type' => $source === 'store'"),
  '员工完整资料未进入权威事务写链或数据范围来源错误'
)
expect(
  employeeTypeAuthority.includes('assertStoreManagePermission')
    && employeeTypeAuthority.includes('assertStoreTargetAssignment')
    && personCompleteWriter.includes("readSnapshot($employeeId, 'store', $staffId, $storeId)"),
  '门店人员类型未受任职边界约束'
)
expect(registrar.includes("['uid', '商城用户ID', 'integer'"), '后端统一查询缺少旧商城用户ID字段')
expect(!staffView.includes('store_id:'), '员工页不得自行提交门店范围')

if (failures.length) {
  for (const failure of failures) console.error(`FAIL: ${failure}`)
  process.exit(1)
}
console.log('STAFF_UNIFIED_QUERY_FRONTEND=PASS')
