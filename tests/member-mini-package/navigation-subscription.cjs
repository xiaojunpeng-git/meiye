const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const cli = '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const compiler = require(path.join(cli, 'vue-template-compiler'));
const parser = require(path.join(cli, '@babel/parser'));
const preprocess = require(path.join(cli, '@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess')).preprocess;
const read = name => fs.readFileSync(path.join(root, '前端代码/uniapp', name), 'utf8');
const flags = platform => platform === 'weixin' ? { MP: true, MP_WEIXIN: true } : platform === 'app' ? { APP: true, APP_PLUS: true } : { H5: true };

// Execute the canonical methods after uni-app conditional preprocessing. Only
// native APIs and business transport are replaced; no server/money is touched.
function load(name, platform, dependencies, component = true) {
  const source = read(name);
  const parsed = component ? compiler.parseComponent(source) : null;
  if (component) {
    const template = preprocess(parsed.template.content, flags(platform), { type: 'html' });
    assert.deepEqual(compiler.compile(template).errors, [], name);
  }
  let script = preprocess(component ? parsed.script.content : source, flags(platform), { type: 'js' });
  const ast = parser.parse(script, { sourceType: 'module' });
  const exported = [];
  const edits = ast.program.body.flatMap(node => {
    if (node.type === 'ImportDeclaration') return [{ start: node.start, end: node.end, value: '' }];
    if (node.type === 'ExportDefaultDeclaration') return [{ start: node.start, end: node.declaration.start, value: 'return ' }];
    if (node.type === 'ExportNamedDeclaration') {
      exported.push(node.declaration.id.name);
      return [{ start: node.start, end: node.declaration.start, value: '' }];
    }
    return [];
  });
  for (const edit of edits.sort((a, b) => b.start - a.start)) script = script.slice(0, edit.start) + edit.value + script.slice(edit.end);
  if (!component) script += '\nreturn {' + exported.join(',') + '};';
  return new Function(...Object.keys(dependencies), script)(...Object.values(dependencies));
}

const rechargeTemplate = 'nsLLYSlNv5fydo0rgNMtmHPv6oFLrvoJPW3bEt-O_zw';
const consumptionTemplate = 'wbK4H_hUdLeXlpdEMpAcjUtzlamWrXc3B9JNSY3gIcU';
const refundTemplate = '79U-cWBDS9wueyn-Ccj1FyomQpJnwYs7W7dFWHDuXsc';
const calls = [];
let behavior = 'accept';
let cached = '';
let held;
const wx = {
  requestSubscribeMessage(options) {
    calls.push(options.tmplIds);
    if (behavior === 'throw') throw new Error('SDK unavailable');
    if (behavior === 'hold') { held = options; return; }
    if (behavior === 'fail') { options.fail({ errMsg: 'requestSubscribeMessage:fail' }); return; }
    options.success(Object.fromEntries(options.tmplIds.map(id => [id, behavior])));
  },
  openEmbeddedMiniProgram: options => options.success({})
};
const uni = {
  getSystemInfoSync: () => ({ statusBarHeight: 20 }),
  getStorageSync: () => cached,
  requestPayment: options => options.success({})
};
const subscribe = load('utils/SubscribeMessage.js', 'weixin', { uni, wx, SUBSCRIBE_MESSAGE: 'SUBSCRIBE_MESSAGE' }, false);
const flush = async () => { await Promise.resolve(); await Promise.resolve(); };

async function navigation() {
  for (const platform of ['weixin', 'h5', 'app']) {
    let current = '/pages/index/index';
    const routes = [];
    const navigationUni = {
      ...uni,
      switchTab: ({ url }) => { routes.push(url); current = url; },
      navigateTo: ({ url }) => { routes.push(url); current = url; },
      reLaunch: ({ url }) => { routes.push(url); current = url; }
    };
    const count = calls.length;
    const footer = load('components/pageFooter/index.vue', platform, {
      uni: navigationUni, mapGetters: () => ({}),
      getCurrentPages: () => [{ $page: { fullPath: current } }]
    });
    const context = { ...footer.data(), ...footer.methods };
    for (const target of ['/pages/user/index', '/pages/index/index', '/pages/user/index', '/pages/user/index',
      '/pages/activity/therapist_list/index', '/pages/index/index', '/pages/activity/therapist_list/index']) {
      const before = routes.length;
      const same = current === target;
      context.goRouter({ link: target });
      assert.equal(current, target, 'navigation completes without any subscription callback');
      assert.equal(routes.length, before + (same ? 0 : 1), 'same-page click remains a no-op');
    }
    context.goRouter({ link: '' });
    assert.equal(calls.length, count, 'ordinary navigation never asks for permission');
    const money = load('pages/users/user_money/index.vue', platform, {
      uni: navigationUni, colors: {}, recommend: {}, home: {}, mapGetters: () => ({})
    });
    money.methods.goRecharge();
    assert.equal(current, '/pages/users/user_payment/index');
    assert.equal(calls.length, count, 'entering the recharge amount page is not a business submission');
  }
}

function checkout(platform, kind, options = {}) {
  let creates = 0;
  let pays = 0;
  const component = load('pages/goods/member_checkout/index.vue', platform, {
    colors: {}, uni, wx, openMemberCommerceSubscribe: subscribe.openMemberCommerceSubscribe,
    createMemberCommerce: async () => { creates += 1; return { data: { id: 'test-order', kind, status: options.pending ? 'pending' : 'paid' } }; },
    payMemberCommerce: async () => { pays += 1; return { data: { qr_code: 'test-qr', app_id: 'test-app' } }; }
  });
  const context = {
    ...component.data(), ...component.methods, kind, storeId: 107, quote: { canSubmit: true },
    payload: () => ({ idempotency_key: 'stable-business-command' }),
    refreshOrder: async () => {}
  };
  return { context, counts: () => ({ creates, pays }) };
}

async function businessActions() {
  for (const kind of ['purchase', 'recharge']) {
    for (const result of ['accept', 'reject', 'fail', 'throw']) {
      behavior = result;
      cached = JSON.stringify({ recharge_success: 'configured-recharge-template' });
      const count = calls.length;
      const flow = checkout('weixin', kind, { pending: true });
      const key = flow.context.commandKey;
      await flow.context.submit();
      assert.deepEqual(flow.counts(), { creates: 1, pays: 1 });
      assert.equal(flow.context.error, '', 'permission outcome never becomes a payment failure');
      assert.equal(flow.context.commandKey, key, 'permission never changes the idempotency key');
      assert.equal(flow.context.submitting, false);
      assert.equal(flow.context.paying, false);
      assert.equal(calls.length, count + 1, 'automatic payment handoff does not ask twice');
      const ids = calls.at(-1);
      assert(ids.includes(kind === 'purchase' ? consumptionTemplate : rechargeTemplate));
      assert(ids.includes(refundTemplate), 'later store refunds retain the original template');
      assert(!ids.includes(kind === 'purchase' ? rechargeTemplate : consumptionTemplate));
      assert(ids.length <= 3);
      if (kind === 'recharge') assert(ids.includes('configured-recharge-template'));
    }
  }

  behavior = 'hold';
  const flow = checkout('weixin', 'purchase', { pending: true });
  const count = calls.length;
  const first = flow.context.submit();
  await flush();
  await flow.context.submit();
  assert.deepEqual(flow.counts(), { creates: 0, pays: 0 });
  assert.equal(calls.length, count + 1, 'double submit cannot create a second permission request or order');
  held.success({});
  await first;
  assert.deepEqual(flow.counts(), { creates: 1, pays: 1 });

  behavior = 'accept';
  const retry = checkout('weixin', 'purchase');
  retry.context.order = { id: 'existing-recharge', kind: 'recharge', status: 'pending' };
  const retryCount = calls.length;
  await retry.context.payOnline({ type: 'tap' });
  assert.deepEqual(retry.counts(), { creates: 0, pays: 1 });
  assert.equal(calls.length, retryCount + 1);
  assert(calls.at(-1).includes(rechargeTemplate), 'retry uses the existing order kind rather than route defaults');

  for (const invalid of [{ storeId: 0 }, { submitting: true }, { quote: { canSubmit: false } }]) {
    const count = calls.length;
    const invalidFlow = checkout('weixin', 'purchase');
    Object.assign(invalidFlow.context, invalid);
    await invalidFlow.context.submit();
    assert.equal(calls.length, count);
    assert.deepEqual(invalidFlow.counts(), { creates: 0, pays: 0 });
  }
  for (const cache of ['', '{invalid json', 'null', JSON.stringify({ recharge_success: rechargeTemplate })]) {
    cached = cache;
    await subscribe.openMemberCommerceSubscribe('recharge');
    assert(calls.at(-1).includes(rechargeTemplate));
    assert(calls.at(-1).includes(refundTemplate));
    assert.equal(new Set(calls.at(-1)).size, calls.at(-1).length);
  }
  const finalCount = calls.length;
  await subscribe.openMemberCommerceSubscribe('unknown');
  assert.equal(calls.length, finalCount);
  for (const platform of ['h5', 'app']) {
    const flow = checkout(platform, 'purchase');
    await flow.context.submit();
    assert.equal(calls.length, finalCount, platform + ' never calls Weixin subscription APIs');
    assert.deepEqual(flow.counts(), { creates: 1, pays: 0 });
  }
}

(async () => {
  await navigation();
  await businessActions();
  console.log('PASS repeated navigation, business subscription templates, consent outcomes, retries, duplicate submit and platform isolation');
})().catch(error => { console.error(error); process.exitCode = 1; });
