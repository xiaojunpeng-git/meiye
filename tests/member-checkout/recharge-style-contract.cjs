const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const assert = require('assert/strict');
const root = path.resolve(__dirname, '../..');
const deps = '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const compiler = require(deps + '/vue-template-compiler');
const parser = require(deps + '/@babel/parser');
const file = '前端代码/uniapp/pages/users/user_payment/index.vue';
const baseline = 'c474daea5a56eaf5d87cdc276317313491bbf1a4';
const raw = fs.readFileSync(path.join(root, file), 'utf8');
const sfc = compiler.parseComponent(raw);
const old = compiler.parseComponent(cp.execFileSync('git', ['show', baseline + ':' + file], {cwd: root, encoding: 'utf8'}));

// Presentation restoration must not resurrect old recharge/commission writes,
// alter validation/defaults or replace the current shared checkout handoff.
assert.equal(sfc.script.content, old.script.content, 'Business script must remain byte-identical');
assert.deepEqual(compiler.compile(sfc.template.content).errors, []);
assert.match(sfc.template.content, /<radio-group[^>]+@change="choosePackage"/);
assert.match(sfc.template.content, /:value="String\(item.id\)" :checked="packageId === Number\(item.id\)"/);
assert.match(sfc.template.content, /value="0" :checked="packageId === 0"/);
assert.match(sfc.template.content, /v-model="amount"[^>]+type="number"[^>]+@change\.stop/);
assert.match(sfc.template.content, /:disabled="loading" @click="continuePayment">下一步/);
assert.match(sfc.template.content, /\.\.\/static\/chongzhi\.png/);
assert.doesNotMatch(raw, /rechargeAPi|memberCardCreate|navRecharges|submitSub|commissionCount|openCoupons|use_integral/);
assert.match(sfc.styles[0].content, /repeat\(3, minmax\(0, 1fr\)\)/);
for (const token of ['--view-theme', '--view-gradient', '--view-minorColorT']) assert(sfc.styles[0].content.includes(token));

let script = sfc.script.content;
const edits = parser.parse(script, {sourceType: 'module'}).program.body.flatMap(n =>
  n.type === 'ImportDeclaration' ? [[n.start, n.end, '']] :
  n.type === 'ExportDefaultDeclaration' ? [[n.start, n.declaration.start, 'return ']] : []);
for (const [a, b, value] of edits.sort((a, b) => b[0] - a[0])) script = script.slice(0, a) + value + script.slice(b);

// Exercise the unchanged real methods with isolated API/router dependencies;
// these tests can never create production recharge orders or move money.
async function verify() {
  const navigations = [];
  let logins = 0;
  const options = new Function('colors', 'getRechargeApi', 'toLogin', 'uni', script)(
    {}, () => Promise.resolve({data: {user_now_money: 13692, recharge_quota: [
      {id: 1, price: 1000, give_money: 100}, {id: 2, price: 0}, {id: 3, price: 5000}
    ], recharge_attention: ['', '门店说明']}}), () => {logins++;}, {navigateTo: value => navigations.push(value.url)});
  const make = loggedIn => ({...options.data(), ...options.methods, $store: {getters: {isLogin: loggedIn}}});
  const guest = make(false);
  options.onLoad.call(guest);
  assert.equal(logins, 1);
  const ctx = make(true);
  assert.equal(ctx.packageId, 0);
  options.onLoad.call(ctx);
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(ctx.packages.map(p => p.id), [1, 3]);
  assert.equal(ctx.balance, 13692);
  assert.deepEqual(ctx.attention, ['门店说明']);
  assert.equal(ctx.loading, false);
  for (const value of ['', '0', '-1', '1.5', '1e2', 'abc']) {
    ctx.amount = value;
    ctx.continuePayment();
    assert.equal(ctx.error, '请输入正整数充值金额');
    assert.equal(navigations.length, 0);
  }
  ctx.amount = '33';
  ctx.continuePayment();
  assert.equal(navigations.pop(), '/pages/goods/member_checkout/index?kind=recharge&price=33&package_id=0');
  ctx.choosePackage({detail: {value: '3'}});
  ctx.continuePayment();
  assert.equal(navigations.pop(), '/pages/goods/member_checkout/index?kind=recharge&price=33&package_id=3');
  ctx.choosePackage({detail: {value: '0'}});
  assert.equal(ctx.amount, '33', 'Keep the established custom-amount retention');
  assert.equal(ctx.whole(33.6), 34);
  const failure = new Function('colors', 'getRechargeApi', 'toLogin', 'uni', script)(
    {}, () => Promise.reject({msg: '读取充值信息失败'}), () => {}, {});
  const failed = {...failure.data(), $store: {getters: {isLogin: true}}};
  failure.onLoad.call(failed);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(failed.error, '读取充值信息失败');
  assert.equal(failed.loading, false);
  console.log('PASS: script byte parity; login/load/defaults; package selection; custom amount/validation; unchanged checkout route and theme tokens');
}
if (require.main === module) verify().catch(e => {console.error(e); process.exitCode = 1;});
module.exports = {root, file, baseline, sfc, script};
