import fs from 'node:fs';
import vm from 'node:vm';
import { strict as assert } from 'assert';
import { createRequire } from 'node:module';

// 从现有 Vue2 组件直接执行方法，模拟网络应答而不复制业务实现。
const require = createRequire('/app/package.json');
const compiler = require('vue-template-compiler');
let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks++; console.log('PASS ' + message); }
function component(file, mocks) {
  const source = fs.readFileSync('/app/src/pages/target/scheme/' + file, 'utf8');
  const parsed = compiler.parseComponent(source);
  assert.deepEqual(compiler.compile(parsed.template.content).errors, []);
  const sandbox = { module: { exports: {} }, ...mocks, OrganizationStoreScopePicker: {}, XLSX: {} };
  vm.runInNewContext(parsed.script.content.replace(/^import .*;$/gm, '').replace('export default', 'module.exports ='), sandbox);
  const definition = sandbox.module.exports;
  const instance = { ...definition.data(), ...definition.methods };
  if (definition.beforeCreate) definition.beforeCreate.call(instance);
  return { instance, source };
}
let deleteCalls = []; let failDelete = true;
const list = component('index.vue', {
  schemeList: async () => ({ data: [] }), schemeVoid: async () => ({}),
  schemeDelete: async payload => { deleteCalls.push(payload); if (failDelete) throw new Error('retry'); return { data: { status: 'deleted' } }; },
});
list.instance.openDelete({ id: 1, status: 'active', version: 3 });
check(list.instance.deleteTarget === null, 'active scheme has no delete action');
list.instance.openDelete({ id: 2, status: 'void', version: 4 });
await list.instance.confirmDelete();
check(list.instance.deleteTarget.id === 2 && list.instance.deleteError === 'retry', 'delete failure keeps confirmation visible');
failDelete = false;
await list.instance.confirmDelete();
check(deleteCalls.length === 2 && deleteCalls[0].idempotency_key === deleteCalls[1].idempotency_key, 'delete retry preserves idempotency key');
check(deleteCalls[1].expected_version === 4 && list.instance.deleteTarget === null, 'delete sends current version and closes only after success');
check(list.source.includes('v-else type="button" class="target-scheme-list__danger" @click="openDelete(scheme)"'), 'only void rows expose delete button');
const activationCalls = []; let round = 0;
const edit = component('Edit.vue', {
  schemeActivate: async payload => {
    activationCalls.push(payload);
    round++;
    if (round === 1) return { data: { status: 'confirmation_required', conflict_token: 'first', conflicts: [{ binding_id: 1 }] } };
    if (round === 2) return { data: { status: 'confirmation_required', conflict_token: 'changed', conflicts: [{ binding_id: 2 }] } };
    if (round === 3) throw new Error('network timeout');
    return { data: { status: 'active' } };
  },
});
const e = edit.instance;
e.detail = { scheme: { version: 2 } }; e.selectedId = 20; e.selectedReports = ['group_dashboard'];
let reloads = 0; e.openScheme = async () => { reloads++; };
const navigations = []; e.$router = { push: route => navigations.push(route) };
await e.activate();
check(e.activationConflict.conflict_token === 'first' && reloads === 0, 'conflict preview opens modal without claiming activation');
check(!e.activationSucceeded && navigations.length === 0, 'preview does not show success or navigate');
e.selectedReports = ['target_monthly'];
await e.submitActivation('first');
check(e.activationConflict.conflict_token === 'changed' && e.error.includes('重新核对'), 'stale confirmation displays updated conflicts');
check(activationCalls[1].report_codes[0] === 'group_dashboard', 'confirmation preserves original report selection snapshot');
await e.submitActivation('changed');
check(e.activationConflict !== null && e.error === 'network timeout', 'network failure does not discard pending confirmation');
check(!e.activationSucceeded && navigations.length === 0, 'failed activation does not show success or navigate');
await e.submitActivation('changed');
check(activationCalls[2].idempotency_key === activationCalls[3].idempotency_key, 'activation confirmation retry reuses command key');
check(reloads === 0 && e.activationConflict === null && e.activationSucceeded, 'confirmed activation shows success independently of detail reload');
check(navigations.length === 0 && e.detail.scheme.status === 'active', 'success waits for confirmation and disables draft actions');
await e.activate();
check(activationCalls.length === 4, 'success cannot resubmit activation');
e.backToList();
check(navigations.length === 1 && navigations[0].name === 'target_scheme', 'confirmation returns to scheme list');
check(edit.source.includes(':closable="false" :mask-closable="false"') && edit.source.includes('<Button type="primary" @click="backToList">确认</Button>'), 'success dialog exposes only explicit confirmation');
const direct = component('Edit.vue', { schemeActivate: async () => ({ data: { status: 'active', version: 3 } }) }).instance;
direct.detail = { scheme: { version: 2 } }; direct.selectedId = 21; direct.selectedReports = ['group_dashboard'];
await direct.activate();
check(direct.activationSucceeded && direct.detail.scheme.version === 3 && !direct.error, 'nonconflicting activation uses same success dialog');
const unexpected = component('Edit.vue', { schemeActivate: async () => ({ data: { status: 'draft' } }) }).instance;
unexpected.detail = { scheme: { version: 2 } }; unexpected.selectedId = 22; unexpected.selectedReports = ['group_dashboard'];
await unexpected.activate();
check(!unexpected.activationSucceeded && unexpected.error.includes('未收到启用成功'), 'unexpected response cannot claim activation success');
check(edit.source.includes('row.active_report_codes'), 'read-only detail displays per-line effective reports');
console.log('R95_UI_CHECKS=' + checks);
