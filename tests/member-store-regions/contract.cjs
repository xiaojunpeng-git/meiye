// Load real Vue2 SFC scripts/templates. Replace only request/native boundaries,
// then exercise selection, request races and original store navigation callbacks.
const fs = require('fs'), path = require('path'), assert = require('assert/strict');
const root = path.resolve(__dirname, '../..');
const deps = path.join(root, '前端代码/admin/node_modules');
const compiler = require(path.join(deps, 'vue-template-compiler'));
const babel = require('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules/@babel/core');
const transform = '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules/@babel/plugin-transform-modules-commonjs';
const preprocess = require('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess').preprocess;
const Vue = require(path.join(deps, 'vue/dist/vue.common.js'));
const sources = {
  region: '前端代码/uniapp/pages/store/list/components/storeRegionWindow.vue',
  page: '前端代码/uniapp/pages/store/list/index.vue',
  drawer: '前端代码/uniapp/components/tui-drawer/tui-drawer.vue'
};
function component(name, api = {}) {
  const source = fs.readFileSync(path.join(root, sources[name]), 'utf8');
  const sfc = compiler.parseComponent(source);
  const template = preprocess(sfc.template.content, {H5: true, APP: false, MP: false}, {type: 'html'});
  const compiled = compiler.compile(template);
  assert.deepEqual(compiled.errors, []);
  const script = babel.transformSync(preprocess(sfc.script.content, {H5: true, APP: false, MP: false}, {type: 'js'}), {babelrc: false, configFile: false, plugins: [transform]}).code;
  const load = dependency => {
    if (dependency === '@/api/new_store.js') return api;
    if (dependency === '@/config/app') return {HTTP_REQUEST_URL: 'http://127.0.0.1:8080'};
    if (dependency === '@/mixins/color') return {computed: {colorStyle() {return '--view-theme:#7d2842;--view-gradient:#a7526c';}}};
    if (dependency.includes('tui-drawer')) return component('drawer').options;
    if (dependency.includes('storeRegionWindow')) return component('region', api).options;
    return {render(h) {return h('view');}};
  };
  const module = {exports: {}};
  new Function('require', 'module', 'exports', script)(load, module, module.exports);
  return {options: module.exports.default, template, sfc, script};
}
const regions = [{id: 1, label: '广东省', children: [
  {id: 2, label: '广州市', children: [{id: 3, label: '海珠区', children: []}]},
  {id: 4, label: '深圳市', children: []}
]}, {id: 6, label: '浙江省', children: [{id: 7, label: '杭州市', children: []}]}];
const deferred = () => {let resolve, reject; const promise = new Promise((yes, no) => {resolve = yes; reject = no;});return {promise, resolve, reject};};
async function run() {
  const calls = [], pending = [];
  const api = {getStoreRegions(query) {calls.push(query);const p = deferred();pending.push(p);return p.promise;}, getList() {return Promise.resolve({data: []});}};
  const vm = new Vue({...component('region', api).options, propsData: {display: false, query: {is_select: 1}, value: []}});
  let submitted;
  vm.$on('submit', value => {submitted = value;});
  const first = vm.loadRegions();
  assert(vm.loading);
  pending.shift().resolve({data: {regions, incomplete_count: 2}});await first;
  assert.equal(vm.regions.length, 2);assert.equal(vm.incompleteCount, 2);
  vm.select(vm.regions[0]);assert.equal(vm.allLabel, '全省门店');
  vm.submit(vm.path);assert.deepEqual(JSON.parse(JSON.stringify(submitted)), [{id: 1, label: '广东省'}]);
  vm.select(vm.options[0]);assert.equal(vm.allLabel, '全市门店');
  vm.select(vm.options[0]);assert.deepEqual(submitted.map(v => v.id), [1, 2, 3]);
  vm.backTo(0);vm.submit(vm.path);assert.deepEqual(submitted, []);
  vm.path = [{id: 99, label: '北京市', children: []}];assert.equal(vm.allLabel, '全市门店');vm.backTo(0);
  const older = vm.loadRegions(), newer = vm.loadRegions();
  const a = pending.shift(), b = pending.shift();b.resolve({data: {regions: [regions[1]]}});await newer;
  a.resolve({data: {regions}});await older;assert.equal(vm.regions.length, 1);
  const failed = vm.loadRegions();pending.shift().reject(Error('network'));await failed;assert(vm.failed && !vm.loading);
  const retry = vm.loadRegions();pending.shift().resolve({data: {regions: []}});await retry;assert(!vm.failed && !vm.loading);
  const closed = vm.loadRegions();vm.$options.watch.display.call(vm, false);pending.shift().resolve({data: {regions}});await closed;assert.equal(vm.regions.length, 0);
  const malformed = vm.loadRegions();pending.shift().resolve({data: []});await malformed;assert(vm.failed);
  assert.equal(calls[0].is_select, 1);
  const events = [], listPending = [], listCalls = [];
  global.uni = {getStorageSync: () => '', $emit: (...a) => events.push(a), navigateBack: () => events.push(['back'])};
  Vue.prototype.$util = {getWXStatusHeight: () => ({barTop: 0, barHeight: 44})};
  const page = new Vue(component('page', {getList(query) {listCalls.push(query);const p = deferred();listPending.push(p);return p.promise;}}).options);
  page.OnChangeAddress([{id: 1, label: '广东省'}]);assert.equal(page.content.province, 1);assert.equal(page.content.city, 0);assert.equal(page.regionLabel, '广东省');
  page.OnChangeAddress([]);assert.equal(page.content.province, 0);assert.equal(page.regionLabel, '全部');
  listPending[1].resolve({data: [{id: 20}]});await Vue.nextTick();await Promise.resolve();
  listPending[0].resolve({data: [{id: 10}]});await Vue.nextTick();await Promise.resolve();assert.equal(page.storeList[0].id, 20);
  const failList = page.getlist();listPending[2].reject(Error('offline'));await failList;assert(page.listError && !page.listLoading);
  for (const [mode, event] of [[1, 'activeFn'], [2, 'activeHome'], [3, 'activeReservation']]) {
    page.comeType = 1;page.isCollage = mode;page.activeFn({id: 130});assert.equal(events[events.length - 2][0], event);assert.equal(events[events.length - 1][0], 'back');
  }
  // The common national-address picker is intentionally not touched or imported.
  assert(!component('page').options.components.areaWindow);
  const module = {exports: {}}, requestCalls = [];
  const script = babel.transformSync(fs.readFileSync(path.join(root, '前端代码/uniapp/api/new_store.js'), 'utf8'), {babelrc: false, configFile: false, plugins: [transform]}).code;
  new Function('require', 'module', 'exports', script)(() => ({get: (...args) => requestCalls.push(args)}), module, module.exports);
  const query = {province: 99, city: 98, product_id: 3648, is_select: 1, store_type: 2};module.exports.getStoreRegions(query);
  assert.equal(query.province, 99);assert.equal(requestCalls[0][0], 'store/list');
  assert.deepEqual(requestCalls[0][1], {...query, province: 0, city: 0, area: 0, street: 0, region_options: 1});
  assert.equal(requestCalls[0][2].noAuth, true);
  vm.$destroy();page.$destroy();
  console.log('PASS: SFC templates, province/city/district/all, async races, close/retry/empty, query identity, legacy selection callbacks.');
}
if (require.main === module) run().catch(error => {console.error(error);process.exitCode = 1;});
module.exports = {root, deps, sources, component, regions};
