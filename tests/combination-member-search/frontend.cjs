/* Run in the existing platform container: compile the real SFC and test submitted search state. */
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const compiler = require('/app/node_modules/vue-template-compiler');
const source = process.env.SFC_BASE64 ? Buffer.from(process.env.SFC_BASE64, 'base64').toString() : fs.readFileSync('/app/src/pages/marketing/storeCombination/combinaList.vue', 'utf8');
const sfc = compiler.parseComponent(source);
assert.deepStrictEqual(compiler.compile(sfc.template.content).errors, []);
assert(source.includes('placeholder="团长/团员姓名或手机号"'));
const calls = [];
const sandbox = { module: { exports: {} }, OrganizationStoreScopePicker: {}, formatBusinessMoney: String, formatDate: String,
  combineListApi: payload => { calls.push(JSON.parse(JSON.stringify(payload))); return Promise.resolve({ data: { list: [], count: 0, page: payload.page, columns: [] } }); },
  exportExcel: () => {}, orderPinkListApi: () => { throw Error('unexpected detail request'); },
  combinationParticipantRefundApi: () => { throw Error('unexpected refund'); },
  combinationCompleteApi: () => { throw Error('unexpected completion'); },
  combinationWholeRefundApi: () => { throw Error('unexpected group refund'); } };
vm.runInNewContext(sfc.script.content.replace(/^\s*import .*?;\s*$/gm, '').replace('export default', 'module.exports ='), sandbox);
const component = sandbox.module.exports, state = component.data();
for (const [key, method] of Object.entries(component.methods)) state[key] = method.bind(state);
state.$Message = { error: msg => { throw Error(msg); } };
(async () => {
  state.formValidate.page = 4; state.formValidate.keyword = '团员测试'; state.formValidate.store_ids = [130]; state.formValidate.status = 2;
  state.userSearchs(); await Promise.resolve(); await Promise.resolve();
  assert.equal(calls[calls.length - 1].keyword, '团员测试'); assert.equal(calls[calls.length - 1].page, 1);
  assert.deepStrictEqual(calls[calls.length - 1].store_ids, [130]); assert.equal(calls[calls.length - 1].status, 2);
  state.formValidate.keyword = '未提交草稿'; state.pageChange(2); await Promise.resolve(); await Promise.resolve();
  assert.equal(calls[calls.length - 1].keyword, '团员测试'); assert.equal(calls[calls.length - 1].page, 2);
  await state.exportList(); assert.equal(calls[calls.length - 1].view, 'export'); assert.equal(calls[calls.length - 1].keyword, '团员测试');
  assert.deepStrictEqual(calls[calls.length - 1].store_ids, [130]);
  state.resetSearch(); await Promise.resolve(); await Promise.resolve();
  assert.equal(calls[calls.length - 1].keyword, ''); assert.equal(calls[calls.length - 1].page, 1);
  console.log('MEMBER_SEARCH_FRONTEND_PASS compilation, submitted member search, page reset, retained filters, pagination, export and reset');
})().catch(error => { console.error(error); process.exitCode = 1; });
