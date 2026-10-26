import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const modal = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/setting/staff/add.vue'), 'utf8')
const list = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/setting/staff/index.vue'), 'utf8')
const scopeService = fs.readFileSync(path.join(root, '后端代码/app/services/organization/EmployeeDataScopeServices.php'), 'utf8')

// 员工管理默认场景为空字符串；“指定门店”与“组织”必须在同一组选项中同显。
assert.match(list, /formScene:\s*''/)
assert.match(list, /:scene="formScene"/)
assert.match(modal, /<Radio label="store_selected">指定门店<\/Radio>\s*<Radio label="org">组织<\/Radio>/)
assert.doesNotMatch(modal, /<Radio\s+v-if="scene === 'organization'"\s+label="store_selected"/)

// 放开平台入口只改变可见性；精确门店 ID 仍由后端按操作者上限校验。
assert.match(scopeService, /resolveOperatorAllowedSelectedStoreIds\(\$adminInfo\)/)
assert.match(scopeService, /array_diff\(\$found, \$allowedStoreIds\)/)
assert.match(scopeService, /门店范围超出可授权范围/)
console.log('PASS platform staff editor exposes selected stores without bypassing server-side scope checks')
