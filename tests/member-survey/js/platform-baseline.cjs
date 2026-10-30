// Execute preserved staff-page configuration from the accepted R22 source, with no network or saves.
const fs = require('fs'), path = require('path'), vm = require('vm'), cp = require('child_process');
const root = path.resolve(__dirname, '../../..'), sourceCommit = '82894c184b9d9120d43e8e91a97f49d6f474953c', checks = [];
function check(ok, label) { if (!ok) throw Error(label); checks.push(label); }
function load(relative) {
  const file = '前端代码/admin/src/pages/setting/staff/' + relative;
  const bytes = fs.readFileSync(path.join(root, file));
  const original = cp.execFileSync('git', ['show', sourceCommit + ':' + file], { cwd: root });
  check(bytes.equals(original), relative + '与已验收R22源码逐字节一致');
  let code = bytes.toString().match(/<script>([\s\S]*?)<\/script>/)[1];
  const context = { module: { exports: {} } };
  code = code.replace(/import\s+([\s\S]*?)\s+from\s+['"][^'"]+['"];?/g, (_, names) => {
    for (const name of names.replace(/[{}]/g, '').split(',').map(s => s.trim()).filter(Boolean)) context[name] = name === 'DEFAULT_STAFF_AVATAR' ? '/synthetic-avatar.png' : () => ({});
    return '';
  }).replace('export default', 'module.exports =');
  vm.createContext(context); vm.runInContext(code, context); return context;
}
const list = load('index.vue'), data = list.module.exports.data();
const columns = list.module.exports.computed.tableColumns.call({ ...data, buildTableColumn: m => m });
check(!columns.some(c => c.key === 'craftsman_performance_type'), '员工列表不会恢复已取消的业绩类型列');
check(columns.some(c => c.key === 'cashier_craftsman_enabled') && columns.some(c => c.key === 'cashier_salesperson_enabled'), '销售人及手艺人开关列继续保留');
const form = load('add.vue');
check(form.getDefaultStaffForm().craftsman_performance_type === 'commission_labor', '新员工仍按已上线双项业绩保存契约初始化');
fs.writeFileSync(path.join(root, 'tests/customer-010/evidence/round12-survey-miniprogram/platform-baseline.json'), JSON.stringify({ passed: true, preserved_source_commit: sourceCommit, checks }, null, 2));
console.log('PLATFORM BASELINE PASS', checks.length);
