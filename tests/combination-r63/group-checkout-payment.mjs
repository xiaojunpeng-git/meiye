import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const cashier = readFileSync(new URL('../../前端代码/uniapp/pages/goods/cashier/index.vue', import.meta.url), 'utf8');
const start = cashier.indexOf('payList(){');
const end = cashier.indexOf('\n\t\t\tpayType(', start);
assert.ok(start >= 0 && end > start, 'member payment selector is present');
const method = new Function(`return ({ ${cashier.slice(start, end).trim().replace(/,\s*$/, '')} }).payList`)();

// Exercise the actual member pay-list method with the same channel statuses
// returned by the cashier API. Group orders must not inherit cash or balance.
function available(isGroupOrder, statuses) {
  const context = {
    isGroupOrder,
    cartArr: Object.entries(statuses).map(([value, payStatus]) => ({ value, payStatus })),
    payTypeList: [],
    payType(value) { this.selected = value; },
  };
  method.call(context);
  return { channels: context.payTypeList.map(item => item.value), selected: context.selected };
}

assert.deepEqual(available(true, { weixin: 1, alipay: 1, yue: 1, offline: 1 }), {
  channels: ['weixin', 'alipay'], selected: 'weixin',
});
assert.deepEqual(available(true, { weixin: 0, alipay: 1, yue: 1, offline: 1 }), {
  channels: ['alipay'], selected: 'alipay',
});
assert.deepEqual(available(true, { weixin: 0, alipay: 0, yue: 1, offline: 1 }), {
  channels: [], selected: undefined,
});
assert.deepEqual(available(false, { weixin: 1, alipay: 1, yue: 1, offline: 1 }).channels,
  ['weixin', 'alipay', 'yue', 'offline']);
process.stdout.write('group payment channel selection: PASS\n');
