import fs from 'node:fs';
import assert from 'node:assert/strict';

// The shared participant sheet must consume the backend-normalized activity
// stock/quota pair. Raw catalog stock is informational and cannot independently
// disable card/project or inventory-disabled group purchases.
const windowSource = fs.readFileSync('前端代码/uniapp/components/productWindow/index.vue', 'utf8');
assert.match(windowSource, /iSbnt\s*&&\s*attr\.productSelect\.stock>0\s*&&\s*attr\.productSelect\.quota>0/);
assert.match(windowSource, /iSbnt\s*&&\s*\(attr\.productSelect\.stock<=0\s*\|\|\s*attr\.productSelect\.quota<=0\)/);
assert.doesNotMatch(windowSource, /productSelect\.product_stock\s*<=\s*0/);

const serviceSource = fs.readFileSync('后端代码/app/services/product/sku/StoreProductAttrServices.php', 'utf8');
assert.match(serviceSource, /product_type.*is_inventory.*allow_negative_stock/s);
assert.match(serviceSource, /if \(\$protectCatalogStock && \$value\['product_stock'\] <= 0\) \$value\['stock'\] = 0;/);

console.log('PASS participant sheet uses normalized activity stock and quota');
console.log('PASS activity SKU normalization follows the saved catalog inventory policy');
