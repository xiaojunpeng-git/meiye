import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const admin = path.join(root, '前端代码/admin');
const require = createRequire(path.join(admin, 'package.json'));
const compiler = require('vue-template-compiler');
const babel = require('@babel/core');
const plain = value => JSON.parse(JSON.stringify(value));

// Execute actual component methods; only network/UI hosts are replaced. No real member mutation.
function component(relative, mocks) {
  const file = path.join(admin, 'src/pages/setting/staff', relative);
  const source = fs.readFileSync(file, 'utf8');
  const parsed = compiler.parseComponent(source);
  assert.deepEqual(compiler.compile(parsed.template.content).errors, []);
  const module = { exports: {} };
  const { code } = babel.transformSync(parsed.script.content, {
    filename: file, babelrc: false, configFile: false,
    plugins: [require.resolve('@babel/plugin-transform-modules-commonjs')],
  });
  vm.runInNewContext(code, { module, exports: module.exports, require: mocks });
  return { definition: module.exports.default, template: parsed.template.content };
}
function state(definition, extra = {}) {
  const value = { value: true, staffRow: { id: 10 }, ...extra };
  Object.assign(value, definition.data.call(value));
  for (const [key, method] of Object.entries(definition.methods)) {
    if (typeof method === 'function') value[key] = method.bind(value);
  }
  return value;
}
function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
const reads = [];
const page = component('index.vue', id => {
  if (id === 'vuex') return { mapState: () => ({}) };
  if (id === '@/api/setting') return { merchantStaffCustomer(...args) {
    const request = deferred(); reads.push({ args, ...request }); return request.promise;
  } };
  return {};
});
assert.doesNotMatch(page.template, /权限编辑|staff-feature-permission-modal|专属客户/);
assert.match(page.template, /转移专属会员/);
const errors = [];
const pageState = state(page.definition, { $Message: { error: message => errors.push(message) } });
assert.deepEqual(plain(pageState.columns1), [
  { title: '客户昵称', key: 'nickname' }, { title: '客户姓名', key: 'real_name' },
  { title: '手机号', key: 'phone' }, { title: '绑定时间', key: 'bound_at' },
]);
pageState.handleClick1(10);
pageState.handleClick1(20);
reads[1].resolve({ data: { list: [{ nickname: 'new' }], count: 1 } });
await new Promise(resolve => setImmediate(resolve));
reads[0].resolve({ data: { list: [{ nickname: 'stale' }], count: 10 } });
await new Promise(resolve => setImmediate(resolve));
assert.equal(pageState.tableData1[0].nickname, 'new');
assert.equal(pageState.total1, 1);
pageState.onDateChange1(['2026-10-01', '2026-10-31']);
assert.equal(pageState.formData1.date_from, '2026-10-01');
assert.equal(pageState.formData1.date_to, '2026-10-31');
pageState.getCustomerList();
pageState.onModal1Cancel();
pageState.modal1 = false;
reads[2].reject({ msg: 'stale failure' });
await new Promise(resolve => setImmediate(resolve));
assert.equal(errors.length, 0);
pageState.openExclusiveTransfer({ id: -1, is_organization_direct: 1 });
assert.equal(pageState.exclusiveTransferModal, false);
pageState.openExclusiveTransfer({ id: 20 });
assert.equal(pageState.exclusiveTransferStaff.id, 20);
console.log('PASS four columns, renamed actions, date boundaries, stale requests and direct-employee guard');

const prepares = [];
const transfers = [];
const modal = component('components/ExclusiveMemberTransferModal.vue', id => {
  if (id !== '@/api/setting') return {};
  return {
    prepareExclusiveMemberTransfer(id) { const request = deferred(); prepares.push({ id, ...request }); return request.promise; },
    transferExclusiveMembers(id, payload) { const request = deferred(); transfers.push({ id, payload, ...request }); return request.promise; },
  };
});
assert.match(modal.template, /multiple filterable/);
assert.doesNotMatch(modal.template, /金额|业绩/);
const events = [];
const modalState = state(modal.definition, { $emit: (...args) => events.push(args), $Message: { success() {} } });
const first = modalState.load();
modalState.staffRow = { id: 20 };
const second = modalState.load();
prepares[1].resolve({ data: { count: 2, snapshot: 'b'.repeat(64), recipients: [{ staff_id: 21, staff_name: 'B' }] } });
await second;
prepares[0].resolve({ data: { count: 99, snapshot: 'a'.repeat(64), recipients: [] } });
await first;
assert.equal(modalState.preview.count, 2);
modalState.recipientIds = [21, 22];
modalState.selectionChanged();
const token = modalState.token;
const submission = modalState.submit();
await modalState.submit();
modalState.close();
assert.equal(transfers.length, 1);
assert.equal(events.length, 0);
assert.deepEqual(plain(transfers[0].payload), { recipient_ids: [21, 22], snapshot: 'b'.repeat(64), request_token: token });
transfers[0].reject({ msg: '专属会员绑定已变化' });
await submission;
assert.deepEqual(plain(modalState.recipientIds), [21, 22]);
assert.equal(modalState.error, '专属会员绑定已变化');
assert.equal(modalState.token, token);
await modalState.submit();
assert.equal(transfers.length, 1);
const refresh = modalState.load();
prepares[2].resolve({ data: { count: 1, snapshot: 'c'.repeat(64), recipients: [{ staff_id: 21 }] } });
await refresh;
assert.equal(modalState.error, '');
assert.deepEqual(plain(modalState.recipientIds), []);
modalState.recipientIds = [21];
modalState.selectionChanged();
const success = modalState.submit();
transfers[1].resolve({ msg: '转移成功', data: { count: 1 } });
await success;
assert.deepEqual(events, [['success'], ['input', false]]);
console.log('PASS preview races, multi-select payload, duplicate/close guard, error preservation, refresh and success');
console.log('PASS both Vue templates compile');
