// Test saved schema, real editor watches and real member methods, not replicas.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { root, readComponent, upgrade, config, stores } = require('./source-harness.cjs');
const preview = readComponent('preview'), settings = readComponent('settings'), member = readComponent('member');
const checkbox = readComponent('checkbox');readComponent('radio');
const old = config();old.storeStyleConfig.tabList.pop();old.storeStyleConfig.tabVal = 2;
old.checkboxInfo.list = old.checkboxInfo.list.filter(x => x.id !== 4);old.checkboxInfo.type = [1, 3];
const original = JSON.stringify(old);
const ctx = { $store: { state: { admin: { mobildConfig: { defaultArray: { 0: old } } } } } };
settings.options.methods.loadConfig.call(ctx, 0);
assert.equal(JSON.stringify(old), original, 'Loading settings changed the saved decoration');
assert.equal(ctx.configObj.storeStyleConfig.tabVal, 2);
assert.equal(ctx.configObj.storeStyleConfig.tabList[3].name, '样式四');
assert.deepEqual(ctx.configObj.checkboxInfo.type, [1, 3, 4]);
assert.equal(member.options.computed.showStoreName.call({ dataConfig: old, checkboxInfo: old.checkboxInfo.type }), true, 'Legacy member name disappeared');
const noList = JSON.parse(original);delete noList.checkboxInfo.list;
assert.equal(member.options.computed.showStoreName.call({ dataConfig: noList, checkboxInfo: [] }), true);
const fresh = config();fresh.checkboxInfo.type = fresh.checkboxInfo.type.filter(x => x !== 4);
for (let round = 0; round < 4; round++) upgrade(fresh);
assert.equal(fresh.checkboxInfo.list.filter(x => x.id === 4).length, 1);
assert.equal(fresh.storeStyleConfig.tabList.length, 4);
assert(!fresh.checkboxInfo.type.includes(4), 'Repeated upgrade reset hidden-name choice');
const restored = JSON.parse(JSON.stringify(fresh));
assert.equal(restored.storeStyleConfig.tabVal, 3);
assert.equal(member.options.computed.showStoreName.call({ dataConfig: restored, checkboxInfo: restored.checkboxInfo.type }), false);

for (const style of [0, 1, 2, 3, 1, 3, 0]) {
  const data = config();data.checkboxInfo.type = [1, 3];
  const c = { configData: data.checkboxInfo };
  checkbox.options.watch['configObj.storeStyleConfig.tabVal'].handler.call(c, style);
  assert(c.configData.list.some(x => x.id === 4), 'Style switch removed name toggle');
  assert.deepEqual(c.configData.type, [1, 3], 'Style switch changed selected information');
  assert.equal(c.configData.list.some(x => x.id === 1), style !== 1, 'Existing style-two hours contract changed');
}
const other = { configData: { list: [{ id: 4, name: 'Other field' }], type: [4] } };
checkbox.options.watch['configObj.storeStyleConfig.tabVal'].handler.call(other, 1);
assert.deepEqual(other.configData.list, [{ id: 4, name: 'Other field' }], 'Shared control changed unrelated modules');
for (const visible of [false, true]) for (const style of [0, 1, 2, 3]) {
  const data = config();data.storeStyleConfig.tabVal = style;data.checkboxInfo.type = visible ? [4] : [];
  const v = preview.options.data.call({ num: 0 });preview.options.methods.setConfig.call(v, data);
  assert.equal(preview.options.computed.showStoreName.call(v), visible);
  assert.equal(member.options.computed.showStoreName.call({ dataConfig: data, checkboxInfo: data.checkboxInfo.type }), visible);
  assert.equal(v.storeStyleConfig, style);
}
assert.equal(preview.options.computed.horizontalSpacing.call({ storeNumber: 12 }).marginRight, '12px');
assert.equal(member.options.computed.horizontalSpacing.call({ dataConfig: config() }).marginRight, '24rpx');
for (const value of [undefined, null, '', -1, 'NaN', Infinity]) assert.equal(member.options.methods.storeDistance({ distance: value }), null);
assert.equal(member.options.methods.storeDistance({ distance: 1240 }), 1.2);
assert.equal(member.options.methods.storeDistance({ distance: 0 }), 0);
let target;
global.uni = { navigateTo(data) { target = data.url; }, reLaunch(data) { target = data.url; }, getStorageSync() { return ''; } };
member.options.methods.goStore(stores()[0]);assert.equal(target, '/pages/store/home/index?&id=101');
member.options.methods.goDetails({ id: 9 });assert.equal(target, '/pages/goods_details/index?id=9');
member.options.methods.goPage('/pages/store_list/index');assert.equal(target, '/pages/store_list/index');
const memberSource = fs.readFileSync(root + '/前端代码/uniapp/pages/index/components/storeList.vue', 'utf8');
assert.equal((member.template.match(/v-if="showStoreName"/g) || []).length, 4);
assert.equal((preview.template.match(/v-if="showStoreName"/g) || []).length, 4);
assert.match(memberSource, /limit:this.numberConfig/);
assert.doesNotMatch(memberSource, /is_all:|shop_operation_type|user_belong_store/);
const backend = fs.readFileSync(root + '/后端代码/app/controller/admin/v1/diy/Diy.php', 'utf8');
assert.match(backend, /\$data\['value'\] = json_encode\(\$value\)/);
console.log('PASS: five canonical SFC compiles; legacy/new name visibility; draft isolation; idempotent upgrade; style-switch preservation; four-style display contract; saved-JSON roundtrip; gap/distance guards; unchanged navigation/API scope.');
