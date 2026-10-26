const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const dependencies = path.join(root, '前端代码/admin/node_modules');
const compiler = require(path.join(dependencies, 'vue-template-compiler'));
const parser = require(path.join(dependencies, '@babel/parser'));

// Compile and execute the canonical Vue 2 source so the checkout contract is
// checked at the same template and method boundaries used by the member app.
const checkoutPath = path.join(root, '前端代码/uniapp/pages/goods/member_checkout/index.vue');
const source = fs.readFileSync(checkoutPath, 'utf8');
const sfc = compiler.parseComponent(source);
const compiled = compiler.compile(sfc.template.content);
assert.deepEqual(compiled.errors, [], 'member checkout template must compile');

const ast = parser.parse(sfc.script.content, { sourceType: 'module' });
const edits = ast.program.body
  .filter(node => node.type === 'ImportDeclaration' || node.type === 'ExportDefaultDeclaration')
  .map(node => node.type === 'ImportDeclaration'
    ? { start: node.start, end: node.end, value: '' }
    : { start: node.start, end: node.declaration.start, value: 'return ' });
let script = sfc.script.content;
for (const edit of edits.sort((a, b) => b.start - a.start)) {
  script = script.slice(0, edit.start) + edit.value + script.slice(edit.end);
}
const uni = { getSystemInfoSync: () => ({ statusBarHeight: 20 }), navigateBack() {}, navigateTo() {} };
const options = new Function(
  'colors', 'toLogin', 'quoteMemberCommerce', 'createMemberCommerce', 'getMemberCommerce',
  'payMemberCommerce', 'cancelMemberCommerce', 'uni', 'wx', 'window', 'plus', script
)({}, () => {}, async () => ({}), async () => ({}), async () => ({}), async () => ({}), async () => ({}), uni, {}, {}, {});

assert.match(sfc.template.content, /class="checkout-header gradient-box"/, 'uses the established order header');
assert.match(sfc.template.content, />提交订单</, 'uses the established order action wording');
assert.match(sfc.template.content, /@click="openSalespeople"/, 'salespeople are opened from one row');
assert.match(sfc.template.content, /<baseDrawer[^>]+salespeopleVisible/, 'salespeople use a bottom drawer');
assert.doesNotMatch(sfc.template.content, /class="person-row"/, 'salespeople are not flattened into the page');
assert.match(sfc.template.content, /class="quantity-stepper"/, 'direct purchase exposes a quantity stepper');
assert.match(sfc.styles[0].content, /\.submit-button[^}]+background:\s*var\(--view-theme\)/, 'submit button follows the active theme');
assert.match(sfc.styles[0].content, /\.primary-button[^}]+background:\s*var\(--view-theme\)/, 'result action follows the active theme');
assert.match(sfc.styles[0].content, /\.confirm-action[^}]+color:\s*var\(--view-theme\)/, 'drawer confirmation follows the active theme');

// pages.json intentionally contains uni-app comments, so inspect the narrow
// route block instead of accepting a looser comment-stripping parser here.
const pagesSource = fs.readFileSync(path.join(root, '前端代码/uniapp/pages.json'), 'utf8');
const checkoutRoute = pagesSource.match(/\{\s*"path":\s*"member_checkout\/index"[\s\S]*?\n\s*\}/);
assert.ok(checkoutRoute, 'member checkout route must exist');
assert.match(checkoutRoute[0], /"navigationStyle":\s*"custom"/);
assert.match(checkoutRoute[0], /"navigationBarTitleText":\s*"提交订单"/);

let repriced = 0;
let renewed = 0;
const quantityContext = {
  canChangeQuantity: true,
  loading: false,
  purchaseQuantity: 1,
  items: [{ product_id: 911, quantity: 1 }],
  $set(target, key, value) { target[key] = value; },
  newIntent() { renewed += 1; },
  loadQuote() { repriced += 1; }
};
options.methods.changeQuantity.call(quantityContext, 1);
assert.equal(quantityContext.items[0].quantity, 2);
assert.equal(renewed, 1, 'quantity changes create a new transaction intent');
assert.equal(repriced, 1, 'quantity changes obtain a fresh server quote');

const summary = options.computed.salespeopleSummary.call({
  salespeople: [1, 2, 3],
  quote: { salespeople: [{ id: 1, name: '甲' }, { id: 2, name: '乙' }, { id: 3, name: '丙' }] }
});
assert.equal(summary, '甲、乙等3人');
assert.equal(options.computed.themeColor.call({ colorStyle: '--view-theme: #7B2941;--view-minorColor: rgba(123, 41, 65, 0.5)' }), '#7B2941');
assert.equal(options.computed.themeColor.call({ colorStyle: '--view-theme: #12AB34;--view-minorColor: #AABBCC' }), '#12AB34',
  'future theme colors are consumed through the shared token without button-specific changes');

let salespersonRepriced = 0;
let salespersonRenewed = 0;
const salespersonContext = {
  salespeople: [],
  newIntent() { salespersonRenewed += 1; },
  loadQuote() { salespersonRepriced += 1; }
};
options.methods.changeSalespeople.call(salespersonContext, { detail: { value: ['7', '9'] } });
assert.deepEqual(salespersonContext.salespeople, [7, 9]);
assert.equal(salespersonRenewed, 1, 'salesperson changes create a new transaction intent');
assert.equal(salespersonRepriced, 1, 'salesperson changes obtain a fresh server quote');

const payload = options.methods.payload.call({
  kind: 'purchase', storeId: 118, items: [{ product_id: 911, quantity: 2 }], cartIds: '',
  price: '0', packageId: 0, salespeople: [1, 2], paymentMode: 'balance', balanceYuan: '',
  externalMethod: 'wechat', commandKey: 'MEMBER-contract-0001', quote: { fingerprint: 'server-quote' }
});
assert.deepEqual(payload.items, [{ product_id: 911, quantity: 2 }]);
assert.deepEqual(payload.salespeople, [1, 2]);
assert.equal(payload.fingerprint, 'server-quote');

process.stdout.write('PASS member checkout UI, quantity and salesperson contract\n');
