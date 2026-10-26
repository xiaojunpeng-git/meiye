import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../../..');
const source = fs.readFileSync(
  path.join(root, '前端代码/admin/src/pages/setting/staff/add.vue'),
  'utf8',
);

let passed = 0;
let failed = 0;
function check(name, condition) {
  if (condition) {
    passed += 1;
    process.stdout.write(`PASS ${name}\n`);
    return;
  }
  failed += 1;
  process.stdout.write(`FAIL ${name}\n`);
}

check(
  'visible staff type control is exact three-state selector',
  source.includes('<FormItem label="人员类型：">')
    && source.includes('<Radio label="internal" :disabled="!canEditEmploymentType">内部员工</Radio>')
    && source.includes('<Radio label="partner" :disabled="!canEditEmploymentType">合作方</Radio>')
    && source.includes('<Radio label="outsourced" :disabled="!canEditEmploymentType">外包</Radio>'),
);
check(
  'legacy share switch is no longer the visible control',
  !source.includes('<FormItem label="参与分成：">')
    && source.includes('payload.is_fencheng = Number(this.formInline.is_fencheng)'),
);
check(
  'new employee defaults to internal version zero',
  source.includes("employment_type_code: 'internal'")
    && source.includes('employment_type_version: 0'),
);
check(
  'permission is fail-closed and unauthorized payload omits type fields',
  source.includes("access.indexOf('setting-staff-employment-type')")
    && source.includes('delete payload.employment_type_code;')
    && source.includes('delete payload.employment_type_version;'),
);
check(
  'editing requires an authoritative loaded version before type write',
  source.includes('employmentTypeLoaded')
    && source.includes('canEditEmploymentType()')
    && source.includes("Object.prototype.hasOwnProperty.call(data, 'employment_type_version')"),
);
check(
  'only frozen enum values can be submitted',
  source.includes("['internal', 'partner', 'outsourced'].includes(this.formInline.employment_type_code)")
    && source.includes("this.$Message.required('请选择人员类型')"),
);
check(
  'unchanged internal account is omitted from employee edit saves',
  source.includes('originalInternalAccount')
    && source.includes('payload.account === this.originalInternalAccount')
    && source.includes('delete payload.account;'),
);

process.stdout.write(`ASSERT_PASSED=${passed}\n`);
process.stdout.write(`ASSERT_FAILED=${failed}\n`);
if (failed > 0) process.exit(1);
process.stdout.write('EMPLOYEE_TYPE_AUTHORITY_FRONTEND_CONTRACT=PASS\n');
