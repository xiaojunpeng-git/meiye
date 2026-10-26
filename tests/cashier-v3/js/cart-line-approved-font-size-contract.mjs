import assert from 'node:assert/strict'
import fs from 'node:fs'

const css = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/styles/base.css', import.meta.url), 'utf8')

// 只锁定产品经理逐项指定的五处文案；金额和其他按钮不随标题一同放大。
assert.match(css, /\.cart-line__title-row strong\s*\{[^}]*font-size: 16px;/, '商品名称应放大一级')
assert.match(css, /\.cart-line__title-row \.cart-line__role\s*\{[^}]*font-size: 12px;/, '标题行的权益标记应放大一级')
assert.match(css, /\.cart-line__source-detail\s*\{[^}]*font-size: 12px;/, '权益来源说明应放大一级')
assert.match(css, /\.cart-line__experience\s*\{[^}]*font-size: 13px;/, '体验入口应放大一级')
assert.match(css, /\.cart-line__meta-action\s*\{[^}]*font-size: 13px;/, '人员及第一排操作入口应放大一级')
assert.match(css, /\.cart-line__header strong\s*\{[^}]*font-size: 15px;/, '金额和其他标题强调整体样式不应一起放大')

console.log('CART_LINE_APPROVED_FONT_SIZE_CONTRACT=PASS')
