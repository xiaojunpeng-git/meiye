import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// 执行真实表单提交方法，保护网络错误提示、原筛选值、重试令牌及成功跳转边界。
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const source = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/store/addStore/index.vue'), 'utf8');
const method = source.slice(source.indexOf('handleSubmit (name)'), source.indexOf('onCouponSelfBuiltStatusChange(value)'));
let submit;
const handle = new Function('storeUpdateApi', `return ({${method}}).handleSubmit`)((id, payload) => submit(id, payload));
const flush = () => new Promise(resolve => setImmediate(resolve));
function state() {
  const errors = [], successes = [], routes = [], sent = [];
  const vm = { saving: false, formItem: { id: 84, organization_id: 90, expected_organization_id: 80,
    address: '原地区', detailed_address: '原详细地址', addressSelect: [1, 2, 3], phone: '19900004900',
    day_time: ['10:00:00', '22:00:00'], delivery_type: ['2'], region_id: 123, manage_region_id: 456 },
    $refs: { formItem: { validate: callback => callback(true) } }, goodsList: [], cityDeliveryStatus: false,
    currentTab: '1', listRoutePath: '/admin/store/store/index', listRouteQuery: { keywords: '原查询' },
    $Message: { error: message => errors.push(message), success: message => successes.push(message) },
    $router: { push: route => routes.push(route) } };
  return { vm, errors, successes, routes, sent };
}
for (const [result, expected] of [[{ msg: '所属组织超出当前账号的数据范围' }, '所属组织超出当前账号的数据范围'],
  [new Error('测试网络错误'), '测试网络错误'], [undefined, '门店保存失败，请稍后重试']]) {
  const s = state(); submit = async () => { throw result; };
  handle.call(s.vm, 'formItem'); await flush();
  assert.deepEqual(s.errors, [expected]); assert.equal(s.vm.saving, false);
  assert.deepEqual(s.routes, []); assert.deepEqual(s.successes, []);
  console.log('PASS 保存失败准确提示，停留当前表单并恢复按钮：' + expected);
}
const empty = state(); submit = async () => undefined;
handle.call(empty.vm, 'formItem'); await flush();
assert.deepEqual(empty.errors, ['门店保存失败，请稍后重试']); assert.deepEqual(empty.routes, []);
console.log('PASS 旧请求拦截器返回空值不会误报成功或再次触发提示组件异常');
const s = state(); submit = async (id, payload) => { s.sent.push(payload); throw { msg: '暂时失败' }; };
handle.call(s.vm, 'formItem'); await flush(); handle.call(s.vm, 'formItem'); await flush();
assert.equal(s.sent[0].request_token, s.sent[1].request_token);
assert.equal(s.sent[0].organization_id, 90); assert.equal(s.sent[0].expected_organization_id, 80);
assert.equal(s.sent[0].address, '原地区'); assert.deepEqual(s.sent[0].addressSelect, [1, 2, 3]);
assert.ok(!('region_id' in s.sent[0]) && !('manage_region_id' in s.sent[0]));
console.log('PASS 重试保留组织、原地址和同一幂等令牌，不提交旧区域字段');
submit = async () => ({ status: 200, msg: '操作成功' }); handle.call(s.vm, 'formItem'); await flush();
assert.deepEqual(s.successes, ['操作成功']); assert.deepEqual(s.routes, [{ path: s.vm.listRoutePath, query: s.vm.listRouteQuery }]);
assert.equal(s.vm.saving, false);
console.log('PASS 成功后返回原列表筛选，保存按钮正常恢复');
