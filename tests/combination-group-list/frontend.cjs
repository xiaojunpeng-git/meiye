/* Run in the existing admin container. Exercise the real SFC methods with controlled API promises. */
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const compiler = require('/app/node_modules/vue-template-compiler');
const source = fs.readFileSync('/app/src/pages/marketing/storeCombination/combinaList.vue', 'utf8');
const sfc = compiler.parseComponent(source);
assert.deepStrictEqual(compiler.compile(sfc.template.content).errors, []);
const calls = [], refunds = [], exportCalls = [], warnings = [];
let response = { data: { list: [{ id: 1, status: 2 }], count: 25, page: 1, table_layout: { fixed: true },
  columns: [{ title: '门店名称', key: 'store_name', minWidth: 120, fixed: 'left' }] } };
let deferred;
const context = { module: { exports: {} }, OrganizationStoreScopePicker: {},
  mapState: () => ({}), formatBusinessMoney: String, formatDate: String,
  combineListApi: (payload) => { calls.push(JSON.parse(JSON.stringify(payload))); return deferred || Promise.resolve(response); },
  orderPinkListApi: () => Promise.resolve({ data: { list: [] } }),
  combinationParticipantRefundApi: () => { throw Error('unexpected single-member refund'); },
  combinationCompleteApi: () => { throw Error('unexpected force-completion'); },
  combinationWholeRefundApi: (id, payload) => { refunds.push({ id, payload }); return Promise.resolve({ data: { state: 'partial', results: [] } }); },
  exportExcel: (...args) => exportCalls.push(args),
};
vm.runInNewContext(sfc.script.content.replace(/^\s*import .*?;\s*$/gm, '').replace('export default', 'module.exports ='), context);
const component = context.module.exports;
const state = component.data();
for (const [key, method] of Object.entries(component.methods)) state[key] = method.bind(state);
state.$Message = { warning: msg => warnings.push(msg), error: msg => warnings.push(msg) };
state.$Modal = { confirm: ({ onOk }) => onOk() };
state.appliedFilters = state.snapshotFilters();
(async () => {
  await state.getList();
  assert.equal(state.total, 25);
  assert.equal(state.columns1[0].fixed, 'left');
  state.formValidate.keyword = 'UNSUBMITTED';
  response = { data: { header: ['门店名称'], filekey: ['store_name'], filename: '拼团列表', export: [{ store_name: 'LOCAL' }] } };
  await state.exportList();
  assert.equal(calls[calls.length - 1].keyword, '');
  assert.equal(exportCalls[0][3].length, 1);
  let resolveOld;
  deferred = new Promise(resolve => { resolveOld = resolve; });
  const old = state.getList();
  deferred = null;
  response = { data: { list: [], count: 0, page: 1, columns: [] } };
  state.userSearchs();
  await Promise.resolve(); await Promise.resolve();
  resolveOld({ data: { list: [{ id: 99 }], count: 99, page: 1, columns: [] } });
  await old;
  assert.equal(state.total, 0, 'late previous request cannot replace new search');
  assert.equal(calls[calls.length - 1].keyword, 'UNSUBMITTED');
  state.openGroupRefund({ id: 42, status: 2 });
  state.confirmGroupRefund();
  assert.equal(refunds.length, 0, 'reason is required before a financial command');
  state.groupRefundReason = ' 本地测试原因 ';
  state.confirmGroupRefund();
  await Promise.resolve(); await Promise.resolve();
  assert.equal(refunds[0].id, 42);
  assert.deepStrictEqual(Object.keys(refunds[0].payload).sort(), ['action', 'reason']);
  assert.equal(refunds[0].payload.reason, '本地测试原因');
  assert(source.includes('rows.status !== 2 && row.refund_action'), 'completed groups do not expose single-member refund');
  assert(source.includes('combination-pagination') && source.includes('min-height: 0'), 'bounded scrolling and footer retained');
  console.log('COMBINATION_GROUP_LIST_FRONTEND_PASS');
})().catch(error => { console.error(error); process.exitCode = 1; });
