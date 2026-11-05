// Run the real confirmation/payment and toast methods. Only HTTP and native
// navigation are replaced: rejection must show its cause without placing orders.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const cli = '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const compiler = require(cli + '/vue-template-compiler');
const parser = require(cli + '/@babel/parser');
const preprocess = require(cli + '/@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess').preprocess;
const read = name => fs.readFileSync(path.join(root, '前端代码/uniapp', name), 'utf8');

function method(script, name, context, nested = false) {
  const object = parser.parse(script, { sourceType: 'module' }).program.body
    .find(node => node.type === 'ExportDefaultDeclaration').declaration;
  const methods = nested ? object.properties.find(node => node.key.name === 'methods').value : object;
  const node = methods.properties.find(node => node.key.name === name);
  return vm.runInNewContext('({' + script.slice(node.start, node.end) + '}).' + name, context);
}

async function scenario(platform) {
  const flags = platform === 'WeChat' ? { MP: true, MP_WEIXIN: true } : { H5: true };
  const sfc = compiler.parseComponent(read('pages/goods/order_confirm/index.vue'));
  assert.deepEqual(compiler.compile(preprocess(sfc.template.content, flags, { type: 'html' })).errors, []);
  const script = preprocess(sfc.script.content, flags, { type: 'js' });
  const utilScript = preprocess(read('utils/util.js'), flags, { type: 'js' });
  for (const failure of [
    { status: 400, msg: '该拼团仅新用户才可购买！' },
    '请求失败',
    { status: 400 },
    { status: 400, msg: { invalid: true } },
    null
  ]) {
    const toasts = [], routes = [], creates = [];
    let hidden = 0;
    const uni = { hideLoading: () => hidden++, showToast: options => toasts.push(options), redirectTo: options => routes.push(options) };
    const payment = method(script, 'payment', { uni, orderCreate: (key, data) => {
      creates.push({ key, data }); return Promise.reject(failure);
    } }, true);
    const Tips = method(utilScript, 'Tips', { uni });
    payment.call({ orderKey: 'unchanged-quote-key', spid: 'promoter', $util: { Tips } }, { combinationId: 12, store_id: 134 });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(creates.length, 1, 'a refusal must not automatically retry or bypass eligibility');
    assert.equal(creates[0].key, 'unchanged-quote-key');
    assert.equal(creates[0].data.combinationId, 12);
    assert.equal(creates[0].data.store_id, 134);
    assert.equal(creates[0].data.spid, 'promoter');
    assert.equal(hidden, 1);
    assert.equal(routes.length, 0, 'refused orders cannot open payment');
    assert.equal(toasts.length, 1);
    assert.equal(toasts[0].title, typeof failure === 'string' ? failure :
      failure && typeof failure.msg === 'string' ? failure.msg : '订单提交失败，请稍后重试');
  }
  const routes = [];
  const payment = method(script, 'payment', {
    uni: { hideLoading() {}, redirectTo: options => routes.push(options) },
    orderCreate: () => Promise.resolve({ data: { result: { order_id: 'created-order' } } })
  }, true);
  payment.call({ orderKey: 'same-key', spid: 0, $util: { Tips: () => assert.fail('successful order must not show an error') } }, {});
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(routes[0].url, '/pages/goods/cashier/index?order_id=created-order&from_type=order');
  console.log('PASS ' + platform + ': server eligibility reason, transport error, invalid response fallback, no retry, unchanged payment navigation');
}

(async () => { await scenario('WeChat'); await scenario('H5'); })().catch(error => { console.error(error); process.exitCode = 1; });
