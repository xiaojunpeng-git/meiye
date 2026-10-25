import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../前端代码/uniapp/pages/goods/order_confirm/index.vue', import.meta.url), 'utf8');
const start = source.indexOf('onLoad: async function(options) {');
const end = source.indexOf('this.spid = options.spid || 0;', start);
assert.ok(start >= 0 && end > start, 'checkout entry route is present');
const entry = source.slice(start + 'onLoad: async function(options) {'.length, end);

// Run the real entry branch with a temporary Redis cart key and a persisted
// database cart ID. Direct-buy keys must never enter the numeric DB lookup.
async function route(options, ordinary = false) {
  const calls = [];
  const context = {
    $store: { getters: { isLogin: true } },
    $util: { Tips: value => calls.push(['tip', value.title]) },
  };
  const memberCommerceCartEntry = async id => {
    calls.push(['entry', id]);
    return { data: { ordinary } };
  };
  const uni = { redirectTo: value => calls.push(['redirect', value.url]) };
  const toLogin = () => calls.push(['login']);
  await new Function('options', 'memberCommerceCartEntry', 'uni', 'toLogin',
    `return (async function () { ${entry} }).call(this);`)
    .call(context, options, memberCommerceCartEntry, uni, toLogin);
  return calls;
}

assert.deepEqual(await route({ cartId: 'r63-redis-key', new: '1' }), []);
assert.deepEqual(await route({ cartId: '123', new: '0' }, false), [['entry', '123']]);
assert.deepEqual(await route({ cartId: '123', new: '0' }, true), [
  ['entry', '123'], ['redirect', '/pages/goods/member_checkout/index?cart_ids=123'],
]);
assert.deepEqual(await route({ cartId: 'r63-table-key', new: '1', tableId: '2' }), []);
process.stdout.write('direct purchase and persisted cart routing: PASS\n');
