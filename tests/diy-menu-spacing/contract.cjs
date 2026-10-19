// Exercise real component methods and saved JSON, not parallel spacing logic.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { root, readComponent, config } = require('./source-harness.cjs');
const preview = readComponent('preview');
const settings = readComponent('settings');
const member = readComponent('member');
const original = config();
delete original.outerMarginConfig;
original.prConfig.val = 50;
const initial = JSON.stringify(original);
const ctx = { $store: { state: { admin: { mobildConfig: { defaultArray: { 0: original } } } } } };
settings.options.methods.loadConfig.call(ctx, 0);
assert.equal(JSON.stringify(original), initial, 'Loading editor settings mutated saved decoration');
assert.equal(ctx.configObj.outerMarginConfig.val, 0);
assert.equal(ctx.configObj.prConfig.val, 50, 'Existing inner spacing changed');
assert.equal(ctx.configObj.prConfig.title, '左右内边距');
assert.equal(ctx.configObj.outerMarginConfig.title, '左右外边距');
assert(settings.options.data().fourStyle.some(x => x.configNme === 'outerMarginConfig'));

for (const [value, expected] of [[undefined, 0], [0, 0], [10, 10], [50, 50], ['12', 12], [-5, 0], [999, 100], ['invalid', 0]]) {
  for (const inner of [0, 8, 50]) {
    const data = config();data.prConfig.val = inner;
    if (value === undefined) delete data.outerMarginConfig;else data.outerMarginConfig.val = value;
    const view = preview.options.data.call({ num: 0 });
    view.objToArr = preview.options.methods.objToArr;
    preview.options.methods.setConfig.call(view, data);
    const style = member.options.computed.boxStyle.call({ dataConfig: data });
    assert.equal(view.outerMarginConfig, expected);
    assert.equal(view.prConfig, inner);
    assert.equal(style.marginLeft, `${expected * 2}rpx`);
    assert.equal(style.marginRight, `${expected * 2}rpx`);
    assert.equal(style.padding, `0rpx ${inner * 2}rpx 0rpx`);
    assert.deepEqual(JSON.parse(JSON.stringify(data)).menuConfig, data.menuConfig);
  }
}

// Existing UPDATEARR/save JSON paths keep the independent field after reload.
ctx.configObj.outerMarginConfig.val = 10;
const payload = JSON.parse(JSON.stringify({ menus: ctx.configObj }));
const restored = payload.menus;
settings.options.methods.loadConfig.call({ ...ctx, $store: { state: { admin: { mobildConfig: { defaultArray: { 0: restored } } } } } }, 0);
assert.equal(restored.outerMarginConfig.val, 10);
assert.equal(restored.prConfig.val, 50);
assert.deepEqual(restored.menuConfig, original.menuConfig);
assert.equal(member.options.computed.boxStyle.call({ dataConfig: restored }).marginLeft, '20rpx');
assert.match(preview.template, /marginLeft:outerMarginConfig/);
assert.match(preview.template, /marginRight:outerMarginConfig/);
// Preserve the existing navigation contracts: filtering, columns, pagination
// and jump targets must not change when an independent gutter is added.
for (const [tab, columns] of [[0, 3], [1, 4], [2, 5]]) {
  assert.equal(member.options.computed.gridColumns.call({ dataConfig: { number: { tabVal: tab } } }).gridTemplateColumns, `repeat(${columns}, 1fr)`);
}
const visible = member.options.computed.menus.call({ dataConfig: { menuConfig: { list: [...original.menuConfig.list, { show: false }] } } });
assert.equal(visible.length, 4);
const paged = { menus: visible, $set(object, key, value) { object[key] = value; } };
member.options.methods.pageNum.call(paged, 3);
assert.deepEqual(paged.menuList.map(p => p.list.length), [3, 1]);
let target;
member.options.methods.menusTap.call({ $util: { JumpPath(url) { target = url; } } }, visible[2].info[1].value);
assert.equal(target, '/test-link-2');
const backend = fs.readFileSync(root + '/后端代码/app/controller/admin/v1/diy/Diy.php', 'utf8');
assert.match(backend, /\$data\['value'\] = json_encode\(\$value\)/);
assert.doesNotMatch(backend, /unset\([^\n]*outerMarginConfig/);
console.log('PASS: Vue2 SFC compilation; independent inner/outer margins; legacy zero fallback; invalid-value guards; draft isolation; saved-JSON round trip; labels and unchanged navigation links.');
