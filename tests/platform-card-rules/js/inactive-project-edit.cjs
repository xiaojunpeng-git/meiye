// 执行编辑页的真实关联映射，验证保存数据携带关系状态而非 SKU 的状态。
const fs = require('fs');
const assert = require('assert');
const path = require('path');
const source = fs.readFileSync(path.resolve(__dirname, '../../../前端代码/admin/src/pages/product/productAdd/index.vue'), 'utf8');
const start = source.indexOf('let related = data.related.map((item) => {');
const end = source.indexOf('this.cardData = related;', start);
assert(start >= 0 && end > start, '编辑映射未找到');
const map = new Function('data', source.slice(start, end) + '\nreturn related;');
const rows = map({ related: [{ status: 0, write_times: 10, productInfo: { store_name: '项目', attrInfo: { product_id: 1304, unique: 'sku', status: 1 } } }] });
assert.equal(rows[0].status, 0);
assert.equal(rows[0].write_times, 10);
assert.equal(rows[0].product_id, 1304);
console.log('PASS 编辑映射保留停用关联状态及次数');
