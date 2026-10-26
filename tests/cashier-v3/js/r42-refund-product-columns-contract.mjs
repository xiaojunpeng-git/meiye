import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const page = read('前端代码/cashier-v3/src/views/OrderCenterView.vue')
const contract = read('后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php')
const provider = read('后端代码/app/services/cashier/v3/order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php')
const reader = read('后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php')
const refundWriter = read('后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php')

// The historical refund row stays one operation; both screen and export must
// expose the same two product columns before the unchanged refund amount.
const columns = /'refund_summary', '退款内容'\),\s*field\('product_category', '商品分类'\), field\('product_name', '商品名称'\), field\('refund_amount', '退款金额'/
assert.match(page, columns)
assert.match(contract, /\['refund_summary', '退款内容'\],[\s\S]*?\['product_category', '商品分类'\], \['product_name', '商品名称'\], \['refund_amount', '退款金额'/)
assert.ok(/activeTabKey\.value === 'refund'[\s\S]*?\['product_category', 'product_name'\][\s\S]*?item\.key === 'refund_amount'/.test(page))
assert.match(provider, /'product_category' => \['productCategory'\]/)
assert.match(provider, /'product_name' => \['productName'\]/)
assert.match(reader, /'productCategory' => \$product\['category'\]/)
assert.match(reader, /'productName' => \$product\['name'\]/)
assert.ok(/whereIn\('rl\.operation_id', \$salesRefundIds\)/.test(reader))
assert.match(reader, /sale_line\.category_name_snapshot/)
assert.match(refundWriter, /\$requestSnapshot\['_productCategoryNameSnapshot'\] = \(string\)\(\$target\['categoryName'\]/)
assert.match(refundWriter, /'categoryName' => \(string\)\(\$item\['category'\]/)

console.log('PASS R42 refund product columns contract')
