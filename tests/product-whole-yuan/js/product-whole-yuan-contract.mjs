#!/usr/bin/env node

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const form = fs.readFileSync(
  path.join(root, '前端代码/admin/src/pages/product/productAdd/index.vue'),
  'utf8',
)
const memberPrice = fs.readFileSync(
  path.join(root, '前端代码/admin/src/pages/product/productAdd/components/vipPriceBrokerageSet.vue'),
  'utf8',
)
const service = fs.readFileSync(
  path.join(root, '后端代码/app/services/product/product/StoreProductServices.php'),
  'utf8',
)

const checks = [
  [
    'product form validates whole-yuan money before the API request',
    form.includes('validateWholeYuanMoney(formData)') &&
      form.indexOf('validateWholeYuanMoney(formData)') < form.indexOf('productAddApi(formData)'),
  ],
  [
    'product form reports the concrete SKU and Chinese money label',
    form.includes('规格【${sku}】的${label}必须填写整数金额'),
  ],
  [
    'price controls declare a whole-yuan step without precision rounding',
    form.includes('v-model="formData.attr.price"') &&
      form.includes(':step="1"') &&
      !memberPrice.includes(':precision="0"'),
  ],
  [
    'paid and level member prices reject fractional values before submit',
    memberPrice.includes('的会员价必须填写整数金额') &&
      memberPrice.includes('的等级会员价必须填写整数金额'),
  ],
  [
    'integer-equivalent decimals are accepted and normalized without rounding',
    form.includes('/^(?:0|[1-9]\\d*)(?:\\.0+)?$/') &&
      memberPrice.includes('/^(?:0|[1-9]\\d*)(?:\\.0+)?$/') &&
      form.includes('normalizeWholeYuanMoney(value)'),
  ],
  [
    'disabled paid-member and brokerage fields are normalized to zero',
    form.includes('attr.vip_price = 0') &&
      form.includes('attr.brokerage = 0') &&
      memberPrice.includes('item.vip_price = Number(formData.is_vip) === 1 ? Number(item.vip_price) : 0') &&
      memberPrice.includes('item.brokerage_two = 0'),
  ],
  [
    'enabled custom level prices are checked and normalized item by item',
    form.includes('Number(formData.level_type) === 2 && Array.isArray(attr.level_price)') &&
      form.includes('levelPrice.price = this.normalizeWholeYuanMoney(price)') &&
      service.includes("(int)($data['level_type'] ?? 1) === 2"),
  ],
  [
    'batch calculations reject fractional results rather than round them',
    [
      '批量计算后的会员价不是整数金额，请调整设置',
      '批量计算后的一级返佣不是整数金额，请调整设置',
      '批量计算后的二级返佣不是整数金额，请调整设置',
      '批量计算后的等级会员价不是整数金额，请调整设置',
    ].every((message) => memberPrice.includes(message)),
  ],
  [
    'backend maps storage fields to user-facing Chinese labels',
    [
      "'price' => '售价'",
      "'ot_price' => '划线价'",
      "'settle_price' => '结算价'",
      "'cost' => '成本价'",
    ].every((mapping) => service.includes(mapping)) &&
      service.includes("$moneyLabels['vip_price'] = '会员价'") &&
      service.includes("$moneyLabels['brokerage'] = '一级返佣'") &&
      service.includes("$moneyLabels['brokerage_two'] = '二级返佣'"),
  ],
  [
    'backend error does not expose vip_price',
    service.includes("'规格【' . $skuLabel . '】的' . $moneyLabel") &&
      service.includes("$label . '必须填写整数金额'") &&
      !service.includes("'规格' . $field"),
  ],
]

let failed = 0
for (const [name, passed] of checks) {
  if (passed) console.log(`PASS ${name}`)
  else {
    failed += 1
    console.error(`FAIL ${name}`)
  }
}

console.log(`PRODUCT_WHOLE_YUAN passed=${checks.length - failed} failed=${failed}`)
if (failed) process.exit(1)
