import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const sourcePath = path.join(root, '前端代码/admin/src/pages/product/productAdd/index.vue');
const source = fs.readFileSync(sourcePath, 'utf8');

const checks = [
  [
    'retail save normalizes delivery before submitting',
    /this\.normalizeRetailDeliveryBeforeSave\(formData\);\s*productAddApi\(formData\)/s.test(source),
  ],
  [
    'empty retail delivery defaults to store pickup',
    /if \(!deliveryTypes\.length \|\| hasIncompleteStoreDelivery\) \{\s*deliveryTypes = \['2'\];\s*formData\.delivery_type = deliveryTypes;\s*formData\.store_delivery_type = \[\];/s.test(source),
  ],
  [
    'incomplete store delivery is replaced rather than written as a store delivery subtype',
    /const hasIncompleteStoreDelivery = deliveryTypes\.includes\('3'\) && !storeDeliveryTypes\.length;/s.test(source),
  ],
  [
    'store-pickup retail products submit with zero freight instead of stale fixed postage',
    /if \(!deliveryTypes\.includes\('1'\) && !deliveryTypes\.includes\('3'\)\) \{\s*formData\.freight = 1;\s*formData\.postage = 0;\s*formData\.temp_id = 0;/s.test(source),
  ],
  [
    'products with online delivery keep their configured freight settings',
    /if \(!deliveryTypes\.includes\('1'\) && !deliveryTypes\.includes\('3'\)\)/.test(source),
  ],
  [
    'non-retail product delivery is unchanged',
    /if \(Number\(formData\.product_type\) !== 0\) return;/.test(source),
  ],
  [
    'legacy delivery warning no longer blocks retail save',
    !source.includes("return this.$Message.warning('请选择配送类型');"),
  ],
];

let failed = 0;
for (const [name, passed] of checks) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`);
  if (!passed) failed += 1;
}

process.exit(failed === 0 ? 0 : 1);
