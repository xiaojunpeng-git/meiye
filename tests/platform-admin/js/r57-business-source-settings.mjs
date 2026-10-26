import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

// Isolate transport and the unrelated base form; compile and render the real Vue 2 page and iView controls.
// This test never calls a business API or writes a database. The optional output path holds screenshots only.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const admin = path.join(root, '前端代码/admin');
const require = createRequire(path.join(admin, 'package.json'));
const compiler = require('vue-template-compiler');
const loader = require('iview-loader');
const source = fs.readFileSync(path.join(admin, 'src/pages/setting/shop/base.vue'), 'utf8');
const transformed = loader.call({ cacheable() {}, query: {} }, source);
const sfc = compiler.parseComponent(transformed);
const compiled = compiler.compile(sfc.template.content);
assert.deepEqual(compiled.errors, [], 'real platform SFC template compiles');
const script = sfc.script.content.replace(/^\s*import\s+[^;]+;\s*$/gm, '').replace('export default', 'globalThis.pageOptions =');
const fixture = () => ({
    source_enabled: 1, inherit_previous: 0, version: 7,
    purchase_primary_id: 11, purchase_secondary_id: 12,
    recharge_primary_id: 21, recharge_secondary_id: 0,
    repayment_primary_id: 11, repayment_secondary_id: 12,
    sources: [{ id: 11, name: '线上引流', children: [{ id: 12, name: '抖音' }] },
        { id: 21, name: '老客首护', children: [] }]
});
const requests = [];
const errors = [];
const successes = [];
let transport = () => Promise.resolve({ data: fixture() });
const context = vm.createContext({
    fromSubmit: {}, buildData: {},
    request: options => { requests.push(options); return transport(options); },
    getDealPeopleThreshold: () => Promise.resolve({ data: { a_yuan: 1000, other_yuan: 500, version: 3 } }),
    updateDealPeopleThreshold: value => Promise.resolve({ data: value })
});
vm.runInContext(script, context);
const options = context.pageOptions;
const state = Object.assign(options.data(), { $Message: { error: value => errors.push(value), success: value => successes.push(value) } });
Object.entries(options.methods).forEach(([key, method]) => { state[key] = method.bind(state); });
let checks = 1;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
const equal = (value, expected, message) => { assert.equal(value, expected, message); checks++; };

// Failed, malformed and stale-capability reads must not permit writing the default form values.
await state.saveCommerce();
equal(requests.length, 0, 'no save before successful read');
transport = () => Promise.reject({ msg: 'network unavailable' });
await state.loadCommerce();
equal(state.commerceLoaded, false, 'network failure closes writes');
equal(state.commerceLoadFailed, true, 'network failure is visible');
await state.saveCommerce();
equal(requests.filter(item => item.method === 'put').length, 0, 'failed read cannot write');
for (const invalid of [{ ...fixture(), source_enabled: undefined }, { ...fixture(), inherit_previous: 2 },
    { ...fixture(), repayment_primary_id: undefined }, { ...fixture(), version: -1 }, { ...fixture(), sources: null }]) {
    transport = () => Promise.resolve({ data: invalid });
    await state.loadCommerce();
    equal(state.commerceLoaded, false, 'incomplete response closes writes');
}
for (const flag of [0, false, '0', 1, true, '1']) {
    const normalized = state.normalizeCommerce({ ...fixture(), source_enabled: flag, inherit_previous: flag });
    equal(normalized.source_enabled, Number(flag), 'source flag preserves explicit false / zero');
    equal(normalized.inherit_previous, Number(flag), 'inherit flag preserves explicit false / zero');
}
transport = () => Promise.resolve({ data: fixture() });
await state.loadCommerce();
equal(state.commerceLoaded, true, 'successful retry enables controls');
equal(state.commerceLoadFailed, false, 'retry clears failure');
equal(state.commerce.version, 7, 'read retains server version');
state.commerce.source_enabled = 0;
state.commerce.repayment_secondary_id = 0;
const beforeInvalidSave = requests.length;
await state.saveCommerce();
equal(requests.length, beforeInvalidSave, 'required repayment child blocks write');
check(errors.at(-1).includes('补交固定来源'), 'validation identifies the correct business kind');
state.commerce.repayment_primary_id = 999;
await state.saveCommerce();
equal(requests.length, beforeInvalidSave, 'unknown primary blocks write');
state.commerce.repayment_primary_id = 11;
state.commerce.repayment_secondary_id = 12;
state.commerce.purchase_secondary_id = 21;
await state.saveCommerce();
equal(requests.length, beforeInvalidSave, 'child from another primary blocks write');
state.commerce.purchase_secondary_id = 12;
let completeSave;
transport = () => new Promise(resolve => { completeSave = resolve; });
const saving = state.saveCommerce();
equal(state.commerceSaving, true, 'save is guarded while pending');
await state.saveCommerce();
equal(requests.length, beforeInvalidSave + 1, 'repeated click sends one request');
const payload = requests.at(-1);
equal(payload.url, 'setting/member-commerce-defaults', 'existing endpoint preserved');
equal(payload.method, 'put', 'existing write verb preserved');
equal(payload.data.version, 7, 'write retains concurrency version');
equal(payload.data.source_enabled, 0, 'closed mode survives submission');
equal(payload.data.inherit_previous, 0, 'non-inherit survives submission');
check(/^SETTINGS-/.test(payload.data.idempotency_key), 'write carries idempotency key');
check(!('sources' in payload.data), 'directory is not part of writable settings');
equal(Object.keys(payload.data).length, 10, 'write contains only policy, source IDs and request guards');
completeSave({ data: { ...fixture(), source_enabled: 0, version: 8 } });
await saving;
equal(state.commerceSaving, false, 'save clears pending state');
equal(state.commerce.version, 8, 'save replaces original version with server result');
equal(state.commerce.source_enabled, 0, 'save response keeps the switch disabled');
transport = () => Promise.resolve({ data: { ...fixture(), source_enabled: 0, version: 8 } });
await state.loadCommerce();
equal(state.commerce.source_enabled, 0, 'fresh read after save keeps the switch disabled');
check(successes.includes('保存成功'), 'save reports success');
state.commerce.source_enabled = 1;
state.commerce.inherit_previous = 1;
state.commerce.purchase_primary_id = 0;
transport = () => Promise.reject({ msg: '版本冲突' });
await state.saveCommerce();
equal(requests.at(-1).data.inherit_previous, 1, 'enabled inherit mode submits without requiring hidden fixed sources');
equal(errors.at(-1), '版本冲突', 'server conflict is reported without invented success');
equal(state.commerce.version, 8, 'conflict does not advance server version');
equal(state.commerceSaving, false, 'failed save can be retried');
check(requests.at(-1).data.idempotency_key !== payload.data.idempotency_key, 'distinct save attempts have distinct keys');

// Use the actual iView runtime, not simulated switches/selects, to verify visibility and v-model behavior.
const { chromium } = createRequire('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json')('playwright');
const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1270, height: 905 } });
    await page.setContent('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><main id="app"></main></body></html>');
    await page.addStyleTag({ path: path.join(admin, 'node_modules/view-design/dist/styles/iview.css') });
    await page.addStyleTag({ content: sfc.styles[0].content });
    await page.addScriptTag({ path: path.join(admin, 'node_modules/vue/dist/vue.js') });
    await page.addScriptTag({ path: path.join(admin, 'node_modules/view-design/dist/iview.js') });
    await page.evaluate(({ script, compiled, fixture }) => {
        window.fromSubmit = { render(h) { return h('div', { attrs: { 'data-unrelated-settings': 'retained' } }); } };
        window.buildData = {};
        window.requests = [];
        window.request = config => { window.requests.push(config); return Promise.resolve({ data: fixture }); };
        window.getDealPeopleThreshold = () => Promise.resolve({ data: { a_yuan: 1000, other_yuan: 500, version: 3 } });
        window.updateDealPeopleThreshold = value => Promise.resolve({ data: value });
        (0, eval)(script);
        window.pageOptions.render = new Function(compiled.render);
        window.pageOptions.staticRenderFns = compiled.staticRenderFns.map(body => new Function(body));
        window.component = new window.Vue(window.pageOptions).$mount('#app');
    }, { script, compiled, fixture: fixture() });
    const section = page.locator('section[aria-label="业务来源设置"]');
    await section.locator('.ivu-select').waitFor();
    equal(await section.locator('.ivu-select').count(), 1, 'enabled mode shows only inherit select');
    equal(await section.locator('.ivu-select-selected-value').innerText(), '不默认上次选择', 'zero inherit renders without accidental default');
    await section.locator('.ivu-switch').click();
    equal(await page.evaluate(() => window.component.commerce.source_enabled), 0, 'real switch writes integer zero');
    equal(await section.locator('.ivu-select').count(), 5, 'closed mode shows three primaries and required children');
    equal(await section.getByText('默认方式', { exact: false }).count(), 0, 'inherit control hidden while disabled');
    for (const label of ['消费固定来源', '充值固定来源', '补交固定来源']) {
        check((await section.innerText()).includes(label), `${label} is visible`);
    }
    const purchase = section.locator('label').filter({ hasText: '消费固定来源' });
    await purchase.locator('.ivu-select').first().click();
    await purchase.getByText('老客首护', { exact: true }).click();
    equal(await page.evaluate(() => window.component.commerce.purchase_primary_id), 21, 'real primary select changes ID');
    equal(await page.evaluate(() => window.component.commerce.purchase_secondary_id), 0, 'primary change clears old child');
    equal(await purchase.locator('.ivu-select').count(), 1, 'no-child primary hides child select');
    await section.locator('.ivu-switch').click();
    await section.locator('.ivu-select').click();
    await section.getByText('默认上次选择', { exact: true }).click();
    equal(await page.evaluate(() => window.component.commerce.inherit_previous), 1, 'real mode select writes integer one');
    await page.evaluate(() => { window.component.commerceLoaded = false; window.component.commerceLoadFailed = true; });
    check(await section.locator('button').last().isDisabled(), 'failed read disables save button');
    check((await section.locator('.ivu-switch').getAttribute('class')).includes('ivu-switch-disabled'), 'failed read disables iView switch');
    await section.locator('.ivu-switch').click();
    equal(await page.evaluate(() => window.component.commerce.source_enabled), 1, 'disabled iView switch cannot change policy');
    check(await section.locator('.deal-people-threshold__error').isVisible(), 'failed read has visible error');
    equal(await page.locator('[data-unrelated-settings="retained"]').count(), 1, 'unrelated settings form retained');
    equal(await page.locator('section[aria-label="成交人数门槛"]').count(), 1, 'threshold controls retained');
    await page.evaluate(() => { window.component.commerceLoaded = true; window.component.commerceLoadFailed = false; });
    if (process.env.R57_SCREENSHOT_DIR) {
        fs.mkdirSync(process.env.R57_SCREENSHOT_DIR, { recursive: true });
        await page.waitForTimeout(250);
        await page.screenshot({ path: path.join(process.env.R57_SCREENSHOT_DIR, 'platform-enabled.png') });
        await section.locator('.ivu-switch').click();
        await page.waitForTimeout(250);
        await page.screenshot({ path: path.join(process.env.R57_SCREENSHOT_DIR, 'platform-disabled.png') });
    }
} finally {
    await browser.close();
}
console.log(JSON.stringify({ status: 'PASS', checks, scope: 'R57 platform source policy only', transport: 'isolated fixture', businessWrites: 0 }));
