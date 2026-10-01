import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// This contract protects the three visible gift pickers without creating
// member entitlements; the local business-path check is recorded separately.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/member/DirectGiftOverlay.vue'), 'utf8')

assert.match(source, /const kindItems = computed\(\(\) => items\.value\.filter\(\(item\) => item\.kind === activeKind\.value\)\)/)
assert.match(source, /const visibleItems = computed\(\(\) => \{[\s\S]*?catalogKeyword\.value\.trim\(\)\.toLocaleLowerCase\(\)[\s\S]*?item\.name, item\.specification/)
assert.match(source, /function openCatalog\(kind\) \{[\s\S]*?catalogKeyword\.value = ''[\s\S]*?catalogOpen\.value = true/)
assert.match(source, /<input v-model="catalogKeyword" type="search"[^>]*:aria-label="`搜索\$\{kindLabel\(activeKind\)\}`"/)
assert.match(source, /\['project', '项目'\], \['product', '产品'\], \['coupon', '优惠券'\]/)
assert.match(source, /kindItems\.length \? '没有匹配的赠送内容。'/)

console.log('cashier direct-gift three-type search contract: PASS')
