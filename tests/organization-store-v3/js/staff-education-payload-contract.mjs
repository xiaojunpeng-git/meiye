import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/setting/staff/add.vue'), 'utf8')
const start = source.indexOf('    buildSubmitPayload() {')
const end = source.indexOf('    handleSubmit() {', start)
assert.ok(start >= 0 && end > start, 'staff payload method exists')

// Execute the actual component method with a minimal form state, so the test covers
// whether an unrelated permission edit sends education and its optimistic-lock version.
const method = new Function('DATE_FIELDS', 'newRequestToken',
  `return ({ ${source.slice(start, end)} }).buildSubmitPayload`)([], () => 'test-token')
const context = (education, originalEducation, educationLoaded = true) => ({
  formInline: {
    org_id: 1, store_id: 2, employee_id: 437, staff_name: '测试员工',
    phone: '13800000000', account: '', scope_mode: 'store_selected', store_ids: [3, 4],
    education, education_version: 7, status: 1, status_version: 1,
  },
  editId: 437,
  originalInternalAccount: '',
  positionIdsTouched: false,
  mobileAuthLoaded: false,
  canEditEmploymentType: false,
  educationLoaded,
  originalEducation,
})

const unchanged = method.call(context('本科', '本科'))
assert.deepEqual(unchanged.store_ids, [3, 4])
assert.equal(Object.hasOwn(unchanged, 'education'), false)
assert.equal(Object.hasOwn(unchanged, 'education_version'), false)
console.log('PASS permission-only edit omits unchanged education and its version')

const changed = method.call(context('硕士', '本科'))
assert.equal(changed.education, '硕士')
assert.equal(changed.education_version, 7)
console.log('PASS changed education includes the value and loaded version')

const cleared = method.call(context('', '本科'))
assert.equal(cleared.education, '')
assert.equal(cleared.education_version, 7)
console.log('PASS explicit education clearing remains writable')

const unavailable = method.call(context('本科', '', false))
assert.equal(Object.hasOwn(unavailable, 'education'), false)
assert.equal(Object.hasOwn(unavailable, 'education_version'), false)
console.log('PASS unavailable education detail cannot overwrite existing data')
