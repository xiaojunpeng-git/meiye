import assert from 'node:assert/strict'
import fs from 'node:fs'

// 守住购物车操作位的页面口径；库存与体验仅换外观，不改原业务命令。
const workbench = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const css = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/styles/base.css', import.meta.url), 'utf8')
const metadata = workbench.split('class="cart-line__secondary-meta"')[1].split('class="cart-line__upgrade-entitlement-quantity"')[0]

const firstRowSlots = ['salesperson', 'debt', 'attribution', 'coupon']
const positions = firstRowSlots.map((name) => metadata.indexOf(`cart-line__meta-slot--${name}`))
assert.ok(positions.every((position) => position >= 0), '第一排四个操作入口必须保留')
assert.ok(positions.every((position, index) => index === 0 || positions[index - 1] < position), 'DOM 操作顺序必须为销售人、欠款、导购/销售经理、优惠券')
for (const [name, column] of firstRowSlots.map((name, index) => [name, index + 1])) {
  assert.match(css, new RegExp(`\\.cart-line__meta-slot--${name} \\{ grid-area: 1 / ${column}; \\}`), `桌面第 ${column} 列应为 ${name}`)
}
assert.match(css, /\.cart-line__meta-slot--debt \{ grid-area: 1 \/ 2; \}[\s\S]*?\.cart-line__meta-slot--service-object \{ grid-area: 2 \/ 2; \}/, '体验与欠款必须共用第二列左边界')
const debtStyle = css.match(/\.cart-line__meta-slot--debt \.cart-line__meta-action \{([^}]+)\}/)?.[1] || ''
const experienceSlotStyle = css.match(/\.cart-line__meta-slot--service-object:not\(\.cart-line__meta-slot--inventory\) \{([^}]+)\}/)?.[1] || ''
const experienceStyle = css.match(/\.cart-line__experience \{([^}]+)\}/)?.[1] || ''
const leftPx = (style) => Number(style.match(/padding-left:\s*(\d+)px/)?.[1])
const experiencePadding = Number(experienceStyle.match(/padding:\s*0\s+(\d+)px/)?.[1])
assert.match(experienceStyle, /display: inline-flex;[\s\S]*?justify-content: flex-start;/, '体验文字不能因按钮宽度而居中漂移')
assert.ok(Number.isFinite(leftPx(debtStyle)) && Number.isFinite(leftPx(experienceSlotStyle)) && Number.isFinite(experiencePadding), '第二列文字缩进必须明确')
assert.equal(leftPx(debtStyle), leftPx(experienceSlotStyle) + experiencePadding, '欠款与体验文字必须共用同一左边界')
assert.match(metadata, /cart-line__meta-action--has-amount': lineDebtAmountCents\(line\) > 0/, '仅有正数欠款的草稿行高亮')
assert.match(css, /\.cart-line__meta-slot--debt \.cart-line__meta-action--has-amount \{[^}]*margin-left: 16px;[^}]*padding-right: 8px;[^}]*padding-left: 8px;[^}]*background: #dc2626;[^}]*color: #fff;/, '已填写欠款应红底白字且保持第二列文字对齐')
assert.match(css, /\.cart-line__meta-slot--debt \.cart-line__meta-action--has-amount:hover:not\(:disabled\) \{[^}]*color: #fff;/, '鼠标悬停不能使欠款白字失效')
assert.match(css, /\.cart-line__delete \{[^}]*border: 1px solid #f5222d;/, '删除按钮默认边框应为红色')
assert.match(metadata, /cart-line__meta-slot--inventory': isInventoryManagedProductLine\(line\)/, '只有库存产品行移动出库控件')
assert.match(css, /\.cart-line__meta-slot--inventory \{ grid-area: 2 \/ 3 \/ 3 \/ 5; justify-self: end; \}/, '出库控件应与标题行右侧控件组共用右边界')
assert.match(css, /@container \(max-width: 630px\) \{[\s\S]*?\.cart-line__meta-slot--inventory \{ grid-area: 3 \/ 1 \/ 4 \/ 3; justify-self: end; \}/, '窄购物车中出库控件仍贴右，且不占用项目体验位')
assert.match(metadata, /cart-line__service-controls--inventory[\s\S]*?setCartLineInventoryMode\(line, 'outbound'\)[\s\S]*?setCartLineInventoryMode\(line, 'no-outbound'\)[\s\S]*?setCartLineInventoryMode\(line, 'presale'\)/, '库存三种模式须保留原命令与顺序')
assert.match(css, /\.cart-line__service-controls--inventory \{[^}]*width: 270px;[^}]*height: 26px;[^}]*gap: 0;/, '库存分段按钮须与朋友控件等宽、等高并共用分隔线')
assert.match(css, /\.cart-line__experience \{[^}]*border: 0;[^}]*background: transparent;/, '体验按钮不应有边框')
assert.match(css, /\.cart-line__experience\.is-active,[\s\S]*?\.cart-line__experience\[aria-pressed="true"\] \{[^}]*background: #15803d;[^}]*color: #fff;/, '体验选中时应为绿色填充和白字')
assert.match(metadata, /class="cart-line__experience"[\s\S]*?toggleCartLineExperience\(line\)/, '体验切换仍须调用原命令')

console.log('CART_LINE_METADATA_LAYOUT_CONTRACT=PASS')
