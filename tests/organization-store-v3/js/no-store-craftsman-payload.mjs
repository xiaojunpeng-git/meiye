import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Run the actual form serializer: no-store writes carry the loaded version,
// while assignment edits remain independent of the employee default version.
const source = readFileSync(new URL('../../../前端代码/admin/src/pages/setting/staff/add.vue', import.meta.url), 'utf8');
const start = source.indexOf('    buildSubmitPayload() {');
const end = source.indexOf('    handleSubmit() {', start);
const build = new Function('DATE_FIELDS', 'newRequestToken',
  `return ({${source.slice(start, end)}}).buildSubmitPayload`)([], () => 'test-token');
const context = {
  formInline: { employee_id: 7, store_id: '', org_id: 8, staff_name: 'Test', phone: '19900005083',
    craftsman_performance_type: 'commission_labor', craftsman_setting_version: 4 },
  editId: 7, originalInternalAccount: '', positionIdsTouched: false,
  mobileAuthLoaded: false, canEditEmploymentType: false, educationLoaded: false,
};
assert.equal(build.call(context).craftsman_performance_type, 'commission_labor');
assert.equal(build.call(context).craftsman_setting_version, 4);
context.formInline.craftsman_setting_version = null;
assert.equal(build.call(context).craftsman_setting_version, null);
context.formInline.store_id = 3;
assert.equal(Object.hasOwn(build.call(context), 'craftsman_setting_version'), false);
assert.equal(build.call(context).craftsman_performance_type, 'commission_labor');
console.log('PASS no-store version/value serialization, missing-version fail-closed, assignment isolation');
