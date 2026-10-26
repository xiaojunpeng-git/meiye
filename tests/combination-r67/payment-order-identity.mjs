// Execute the production payment method to protect the temporary-to-canonical
// order identity handoff. This test never contacts a gateway or business DB.
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/package.json');
const parser = require('@babel/parser');
const source = fs.readFileSync('前端代码/uniapp/pages/goods/cashier/index.vue', 'utf8')
  .match(/<script>([\s\S]*?)<\/script>/)[1]
  .replace(/\/\/ #ifdef APP-PLUS[\s\S]*?\/\/ #endif/g, '');
const ast = parser.parse(source, { sourceType: 'module' });
const component = ast.program.body.find(node => node.type === 'ExportDefaultDeclaration').declaration;
const methods = component.properties.find(node => node.key?.name === 'methods').value;
const goPayNode = methods.properties.find(node => node.key?.name === 'goPay');
const compile = node => vm.runInNewContext(
  `(function(${node.params.map(param => source.slice(param.start, param.end)).join(',')})${source.slice(node.body.start, node.body.end)})`,
  context
);

const temporaryOrderId = 'wx-temp-checkout';
const canonicalOrderId = 'wx-canonical-paid-order';
const payRequests = [];
const statusReads = [];
let providerOpenCount = 0;
const context = {
  orderPay: payload => {
    payRequests.push(payload);
    return Promise.resolve({
      data: {
        status: 'WECHAT_PAY',
        result: {
          order_id: canonicalOrderId,
          jsConfig: { qr_code: 'provider-token', app_id: 'provider-app' }
        }
      }
    });
  },
  rechargePayAPi: () => { throw new Error('unexpected recharge'); },
  debtRepayPay: () => { throw new Error('unexpected debt'); },
  memberCardPayApi: () => { throw new Error('unexpected vip'); },
  uni: {
    showLoading() {},
    hideLoading() {},
    setStorageSync() {},
    navigateTo() {},
    requestPayment() {}
  },
  wx: {
    openEmbeddedMiniProgram(options) {
      providerOpenCount++;
      options.success();
    }
  },
  parseFloat,
  encodeURIComponent,
  Promise,
  String,
  setTimeout,
  location: { protocol: 'https:', hostname: 'example.test', port: '' },
  window: { location: { protocol: 'https:', host: 'example.test' } }
};

const state = {
  orderId: temporaryOrderId,
  fromType: 'order',
  active: 0,
  paytype: 'weixin',
  now_money: '0',
  payPriceShow: 1,
  paying: false,
  awaitingPaymentConfirmation: false,
  checkingPayment: false,
  isGroupOrder: true,
  is_bak: 0,
  paymentPageVisible: true,
  $wechat: { isWeixin: () => true },
  $util: { Tips: message => { throw new Error(`unexpected tip: ${message?.title}`); } },
  checkPaymentAfterReturn() {
    statusReads.push(this.orderId);
  }
};
state.goPay = compile(goPayNode);

state.goPay();
for (let index = 0; index < 12; index++) await Promise.resolve();

assert.equal(payRequests.length, 1, 'the gateway request must be issued exactly once');
assert.equal(payRequests[0].uni, temporaryOrderId, 'the initial request uses the checkout identity');
assert.equal(state.orderId, canonicalOrderId, 'the page must retain the canonical order identity returned by the API');
assert.deepEqual(statusReads, [canonicalOrderId], 'the first authoritative status read must use the canonical identity');
assert.equal(providerOpenCount, 1, 'the provider mini program opens once');

state.goPay();
await Promise.resolve();
assert.equal(payRequests.length, 1, 'manual confirmation must never issue a second payment');
assert.deepEqual(statusReads, [canonicalOrderId, canonicalOrderId]);

console.log('PASS canonical order id drives post-payment reads and duplicate taps remain query-only');
