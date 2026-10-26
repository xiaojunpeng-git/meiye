import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8')

const storeStaffView = read('前端代码/cashier-v3/src/views/StaffListView.vue')
const adminStaffView = read('前端代码/admin/src/pages/setting/staff/add.vue')
const completeWrite = read('后端代码/app/services/employee/EmployeePersonCompleteWriteServices.php')

assert.doesNotMatch(storeStaffView, /!editorStaffId\.value && !editorValues\.account\.trim\(\)/)
assert.match(storeStaffView, /if \(!account && password\) return '请先填写登录账号，或清空登录密码。'/)
assert.match(storeStaffView, /登录账号（可选）/)
assert.match(storeStaffView, /不填写则不创建登录权限/)
assert.match(storeStaffView, /:disabled="!editorValues\.account\.trim\(\)"/)

assert.match(adminStaffView, /登录账号（可选）/)
assert.match(adminStaffView, /不填写则不创建登录权限/)
assert.match(adminStaffView, /:disabled="!String\(formInline\.account \|\| ''\)\.trim\(\)"/)

assert.match(completeWrite, /if \(\$account !== ''\) \{/)
assert.doesNotMatch(completeWrite, /请填写登录账号/)

console.log('STAFF_LOGIN_OPTIONAL_CONTRACT_OK')
