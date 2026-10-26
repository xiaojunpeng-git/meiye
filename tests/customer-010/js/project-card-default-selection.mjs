import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

// Exercise the real card-project picker method with server-shaped default values.
// A missing project amount must retain the variable unit-price behavior.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const source = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/product/productAdd/index.vue'), 'utf8');
const start = source.indexOf('    getAtterId(selectCardData){');
const end = source.indexOf('    /** 每个项目只属于一个组合', start);
assert.ok(start > 0 && end > start, 'card picker method exists');
const methodSource = source.slice(start, end).trim().replace(/,\s*$/, '');
const { getAtterId } = vm.runInNewContext(`({ ${methodSource} })`);
const context = {
  formData: { card_rule_type: 'normal' },
  cardData: [{ unique: 'saved', craftsman_default_mode: 'manual_amount', craftsman_default_unit_amount: 66 }],
  combinationPickerGroupNo: 0,
  goodsModal: true,
  $set: (item, key, value) => { item[key] = value; },
  $Message: { warning: (message) => { throw new Error(message); } },
};
getAtterId.call(context, [
  { unique: 'project-9272', product_id: 9272, labor_fee: 0, craftsman_default_unit_amount: 170 },
  { unique: 'project-8996', product_id: 8996, labor_fee: 0, craftsman_default_unit_amount: null },
  { unique: 'explicit-zero', product_id: 100, labor_fee: 0, craftsman_default_unit_amount: 0 },
]);
assert.equal(context.cardData[0].craftsman_default_unit_amount, 66, 'saved manual card amount remains independent');
assert.deepEqual(
  Array.from(context.cardData.slice(1), (row) => [row.craftsman_default_mode, row.craftsman_default_unit_amount]),
  [['manual_amount', 170], ['default_unit_price', 0], ['manual_amount', 0]],
);
assert.equal(context.goodsModal, false);
console.log('PASS configured amount, missing-source unit price, explicit zero, saved manual value');
