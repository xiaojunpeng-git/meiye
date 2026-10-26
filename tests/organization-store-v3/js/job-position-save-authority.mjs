import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// 执行两个真实页面的保存方法：通用组织超管只读状态不能挡住岗位保存。
for (const [file, method, busy] of [
  ['job-positions/index.vue', 'save', 'saving'],
  ['workspace/index.vue', 'onJobPositionFormSave', 'writeSubmitting'],
]) {
  const source = fs.readFileSync(new URL(`../../../前端代码/admin/src/pages/store/region/${file}`, import.meta.url), 'utf8');
  const match = source.match(new RegExp(`    ${method}\\(([^)]*)\\) \\{([\\s\\S]*?)\\n    \\},`));
  assert.ok(match);
  let calls = 0;
  const fn = vm.runInNewContext(`(function(${match[1]}) {${match[2]}\n})`, {
    READONLY_TIP: 'readonly', UNKNOWN_WRITE_TIP: 'failed',
    saveJobPosition() { calls++; return Promise.resolve({ msg: '保存成功' }); },
  });
  const state = {
    canWrite: false, writeStatus: { write_enabled: true }, [busy]: false,
    showToast() {}, blockWrite() {}, resetToken() {}, resetWriteToken() {},
    writeHeaders() { return { 'X-Request-Token': 'test-token' }; },
    writeHeadersFor() { return { 'X-Request-Token': 'test-token' }; },
    closeJobPositionFormAfterSave() {}, finishWriteSuccess() {}, loadList() {},
    loadJobPositionList() {}, loadJobPositionOptions() {}, handleWriteCatch() {},
  };
  fn.call(state, { id: 1, name: 'test', use_store: 1 });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls, 1, 'non-super save reaches backend');
  state.writeStatus.write_enabled = false;
  fn.call(state, { id: 1 });
  assert.equal(calls, 1, 'global write switch stays enforced');
  state.writeStatus.write_enabled = true;
  state[busy] = true;
  fn.call(state, { id: 1 });
  assert.equal(calls, 1, 'busy guard stays enforced');
  console.log(`PASS ${file}: 普通账号发起保存、总写开关和重复点击保护`);
}
