// Exercise real Vue methods without network access or creating appointments.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { createRequire } = require('node:module');
const requireCompiler = createRequire(path.join(process.env.UNIAPP_CLI_ROOT ||
  '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli', 'package.json'));
const compiler = requireCompiler('vue-template-compiler');
const parser = requireCompiler('@babel/parser');
const generate = requireCompiler('@babel/generator').default;
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, '前端代码/uniapp/pages/activity/reservation/index.vue'), 'utf8');
const sfc = compiler.parseComponent(source);
const ast = parser.parse(sfc.script.content, { sourceType: 'module', plugins: ['optionalChaining', 'nullishCoalescingOperator'] });
const component = ast.program.body.find(node => node.type === 'ExportDefaultDeclaration').declaration;
const methods = component.properties.find(node => node.key.name === 'methods').value.properties;
const names = ['openAddonPicker', 'loadAddonPickerOptions', 'chooseHistoricalStore', 'confirmAddonPicker'];
const selected = names.map(name => {
  const method = methods.find(node => node.key.name === name);
  assert.ok(method, `missing ${name}`);
  return generate(method).code;
});
const calls = [];
const actualMethods = vm.runInNewContext(`({${selected.join(',')}})`, {
  getUserPurchasedRemainItems: args => {
    calls.push(JSON.parse(JSON.stringify(args)));
    return Promise.resolve({ data: { list: [{ fixture: true }] } });
  },
});
const template = compiler.compile(sfc.template.content).ast;
const elements = [];
const seen = new Set();
function walk(node) {
  if (!node || seen.has(node)) return;
  seen.add(node);
  elements.push(node);
  for (const child of node.children || []) walk(child);
  for (const branch of node.ifConditions || []) walk(branch.block);
}
walk(template);
const button = elements.find(node => node.staticClass === '"addon-add-btn"');
assert.ok(button && !button.if, 'historical add-project button must not be hidden');
const allTab = elements.find(node => node.attrsMap && node.attrsMap['@click'] === 'switchAddonTab(2)');
assert.equal(allTab, undefined, 'catalog tab removed; picker only exposes owned rights');
assert(!elements.some(n=>n.staticClass==='"addon-picker-tabs acea-row"'), 'both tabs removed');

(async () => {
  const tips = [];
  const ctx = {
    historicalStorePicker: true, storeId: 0, orderId: 5338, cartInfoId: 13967,
    addonItems: [], addonPickerOptions: [], addonPickerRequestId: 0, collapsedAddonGroups: [], historicalStoreOptions: [{ id: 11, name: '隔离测试门店' }],
    $util: { Tips: value => tips.push(value.title) },
    buildBookUidParams: value => value,
    parsePurchasedAddonOptions: value => value,
    loadStaffList: () => Promise.resolve(),
    ...actualMethods,
  };
  ctx.openAddonPicker();
  assert.equal(tips[0], '请先选择预约门店');
  assert.equal(calls.length, 0);
  ctx.storeId = 11;
  ctx.openAddonPicker();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(ctx.addonPickerVisible, true);
  assert.deepEqual(calls[0], { store_id: 11, main_order_id: 5338, main_detail_id: 13967, main_quantity: 1 });
  assert.equal(ctx.addonPickerOptions.length, 1);
  ctx.addonPickerOptions = [{ _key: 'chosen' }];
  ctx.addonPickerSelected = ['chosen'];
  ctx.selectedTimeRange = { begin: 'old time' };
  ctx.confirmAddonPicker();
  assert.equal(ctx.addonItems.length, 1);
  assert.equal(ctx.selectedTimeRange, null, 'adding a project invalidates old timing');
  ctx.chooseHistoricalStore({ id: 11, name: '隔离测试门店' });
  assert.equal(ctx.addonItems.length, 0, 'changing store must reset selected add-ons');
  ctx.historicalStorePicker = false;
  ctx.openAddonPicker();
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(calls[1], { store_id: 11, main_order_id: 5338, main_detail_id: 13967, main_quantity: 1 }, 'ordinary main also uses owned rights authority');
  console.log('historical add-project Vue methods and visibility: PASS');
})().catch(error => { console.error(error); process.exitCode = 1; });
