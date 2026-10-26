import fs from 'node:fs'
import assert from 'node:assert/strict'

const root = decodeURIComponent(new URL('../../../', import.meta.url).pathname)
const backend = fs.readFileSync(`${root}/后端代码/app/services/employee/EmployeeCraftsmanPerformanceTypeServices.php`, 'utf8')
const write = fs.readFileSync(`${root}/后端代码/app/services/employee/EmployeePersonCompleteWriteServices.php`, 'utf8')
const adminForm = fs.readFileSync(`${root}/前端代码/admin/src/pages/setting/staff/add.vue`, 'utf8')
const storeForm = fs.readFileSync(`${root}/前端代码/cashier-v3/src/views/StaffListView.vue`, 'utf8')
const migration = fs.readFileSync(`${root}/后端代码/database/upgrades/2026-08-14-员工手艺人服务业绩类型/02-正式升级.sql`, 'utf8')

for (const code of ['commission', 'labor', 'commission_labor']) assert.match(backend, new RegExp(`'${code}'`))
assert.match(backend, /craftsman_performance_enabled.*\$type === self::LABOR \? 0 : 1/s)
assert.match(backend, /craftsman_labor_enabled.*\$type === self::COMMISSION \? 0 : 1/s)
assert.match(write, /craftsman_performance_type/)
assert.match(adminForm, /手艺人服务业绩类型/)
assert.match(storeForm, /手艺人服务业绩类型/)
assert.match(migration, /ADD COLUMN `craftsman_performance_type`/)
console.log('craftsman-performance-type-contract: PASS')
