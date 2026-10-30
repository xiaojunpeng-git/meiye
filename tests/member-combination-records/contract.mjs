import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const read = (path) => readFileSync(resolve(root, path), 'utf8');

const route = read('后端代码/route/api.php');
const controller = read('后端代码/app/controller/api/v1/activity/StoreCombination.php');
const service = read('后端代码/app/services/activity/combination/MemberCombinationRecordServices.php');
const memberApi = read('前端代码/uniapp/api/activity.js');
const pages = read('前端代码/uniapp/pages.json');
const memberPage = read('前端代码/uniapp/pages/activity/user_combination_records/index.vue');
const linkPicker = read('前端代码/admin/src/components/linkaddress/index.vue');
const goodSelect = read('前端代码/admin/src/store/modules/admin/modules/goodSelect.js');
const fresh = read('前端代码/admin/src/store/modules/admin/modules/fresh.js');

assert.match(route, /combination\/member\/records[^\n]+memberRecords/);
assert.match(controller, /public function memberRecords\([\s\S]*?\(int\)\$request->uid\(\)/);
assert.doesNotMatch(controller.slice(controller.indexOf('public function memberRecords'), controller.indexOf('public function remove')), /\['uid'/);

assert.match(service, /where\('p\.uid', \$uid\)/);
assert.match(service, /join\('store_pink g', 'g\.id=IF\(p\.k_id=0,p\.id,p\.k_id\)'\)/);
assert.match(service, /leftJoin\('store_order o', 'o\.id=p\.order_id_key'\)/);
assert.match(service, /'pending'.*'success'.*'failed'.*'refunded'/s);
assert.match(service, /page\(\$page, \$limit\)/);
assert.match(service, /order\('p\.add_time desc,p\.id desc'\)/);

assert.match(memberApi, /combination\/member\/records/);
assert.match(pages, /"path": "user_combination_records\/index"/);
assert.match(memberPage, /\{ key: 'all'/);
assert.match(memberPage, /\{ key: 'pending'/);
assert.match(memberPage, /\{ key: 'success'/);
assert.match(memberPage, /\{ key: 'failed'/);
assert.match(memberPage, /\{ key: 'refunded'/);
assert.match(memberPage, /goods_combination_status\/index\?id=' \+ item\.group_id/);
assert.match(memberPage, /getMemberCombinationRecords\(\{ status: this\.status, page: this\.page, limit: this\.limit \}\)/);
assert.match(memberPage, /onShow\(\)[\s\S]*this\.isLogin[\s\S]*this\.loadRecords\(\)/);

for (const source of [linkPicker, goodSelect, fresh]) {
  assert.match(source, /\/pages\/activity\/user_combination_records\/index/);
}
assert.match(linkPicker, /name: '我的拼团'/);

console.log('member combination records contract: PASS');
