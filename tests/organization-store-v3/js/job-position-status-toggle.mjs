import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// 执行独立岗位页的实际方法，防止只修工作台入口而漏掉线上岗位策略路由。
const pageUrl = new URL('../../../前端代码/admin/src/pages/store/region/job-positions/index.vue', import.meta.url);
const source = fs.readFileSync(pageUrl, 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
  .replace(/^import[\s\S]*?;\s*$/gm, '')
  .replace('export default', 'component =');
let saves = [];
let details = 0;
let failure = false;
const context = vm.createContext({
  JobPositionFormModal: {},
  getOrganizationWriteStatus() {},
  getJobPositions() {},
  getJobPositionDetail() { details += 1; throw new Error('Status toggle must not load permissions'); },
  async saveJobPosition(payload, headers) {
    saves.push({ payload: JSON.parse(JSON.stringify(payload)), headers });
    if (failure) throw { msg: '无权限执行该操作' };
    return { msg: payload.status ? '岗位已启用' : '岗位已停用' };
  },
});
vm.runInContext(script, context);
const methods = context.component.methods;

function fixture(status = 1, filter = '1') {
  const row = {
    id: 99, name: '保洁', status, allow_store_select: 0, performance_independent: 1,
    channel_rules: { mobile: { rules: 'obsolete-menu' } },
  };
  const state = {
    ...methods, canWrite: true, saving: false, status: filter, list: [row], messages: [], refreshes: 0,
    writeStatus: { reason_text: '当前只读' },
    resetToken() {},
    writeHeaders(action, payload) {
      assert.equal(action, 'job_position_status');
      assert.equal(payload.status_only, 1);
      return { 'X-Request-Token': '12345678-1234-4234-8234-123456789abc' };
    },
    showToast(message) { this.messages.push(message); },
    async ask() { return true; },
    async loadList() {
      this.refreshes += 1;
      this.list = this.status === '1' && row.status !== 1 ? [] : [row];
    },
  };
  return { state, row };
}

// 历史菜单无效也能启停；发给后端的命令不能夹带名称、权限或独立核算开关。
for (const [initial, filter] of [[1, '1'], [0, ''], [1, '']]) {
  const { state, row } = fixture(initial, filter);
  const permissions = JSON.stringify(row.channel_rules);
  await state.toggleStatus(row);
  const { payload, headers } = saves.at(-1);
  assert.deepEqual(payload, { id: 99, status: initial ? 0 : 1, status_only: 1,
    request_token: headers['X-Request-Token'] });
  assert.equal(details, 0);
  assert.equal(row.performance_independent, 1);
  assert.equal(JSON.stringify(row.channel_rules), permissions);
  assert.equal(state.refreshes, 1);
  assert.equal(state.list.length, filter === '1' ? 0 : 1);
  assert.equal(state.saving, false);
}
console.log('PASS 启停使用状态专用命令；成功刷新启用/全部列表；不重存权限');

failure = true;
const failed = fixture();
await failed.state.toggleStatus(failed.row);
assert.equal(failed.row.status, 1);
assert.equal(failed.state.saving, false);
assert.equal(failed.state.refreshes, 0);
assert.deepEqual(failed.state.messages, ['无权限执行该操作']);
console.log('PASS 后端拒绝时恢复原状态并显示真实错误');

// 取消、只读和提交中不能继续写入；避免重复点击扩大生产操作范围。
for (const mode of ['cancel', 'readonly', 'busy']) {
  const { state, row } = fixture();
  if (mode === 'cancel') state.ask = async () => false;
  if (mode === 'readonly') state.canWrite = false;
  if (mode === 'busy') state.saving = true;
  const count = saves.length;
  await state.toggleStatus(row);
  assert.equal(saves.length, count);
  assert.equal(row.status, 1);
}
console.log('PASS 取消、只读和提交中不发送写请求');
