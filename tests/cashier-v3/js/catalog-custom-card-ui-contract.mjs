import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const workbench = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root), 'utf8')
const customCard = fs.readFileSync(new URL('前端代码/cashier-v3/src/components/cashier/CashierGuidedBusinessPanel.vue', root), 'utf8')
const entitlement = fs.readFileSync(new URL('前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue', root), 'utf8')
const css = fs.readFileSync(new URL('前端代码/cashier-v3/src/styles/base.css', root), 'utf8')

assert.match(workbench, /const categories = computed\(\(\) => \[\s*'全部'/, '商品分类首项必须是全部')
assert.match(workbench, /catalog-category-options/, '商品分类必须使用稳定的一行折叠容器')
assert.match(workbench, /areCategoriesExpanded \? '收起' : '展开'/, '商品分类必须提供展开和收起')
assert.match(css, /\.catalog-category-options \{[\s\S]*?max-height: 38px;[\s\S]*?overflow: hidden;/, '分类默认只展示一行')

assert.match(customCard, /v-else-if="step === 2"[\s\S]*?>显示已选<\/button>[\s\S]*?>取消已选<\/button>/, '两个按钮只能出现在定制卡第二步')
assert.match(customCard, /showSelectedCustomItems[\s\S]*?selectedCustomIds\.value\.includes\(item\.id\)/, '显示已选必须只过滤本次定制项目')
assert.match(customCard, /function clearSelectedCustomItems\(\) \{\s*selectedCustomIds\.value = \[\]/, '取消已选必须只清空本次定制项目')
assert.doesNotMatch(entitlement, />只看已选<\/button>/, '普通权益选择器不得出现定制卡专属筛选')

assert.match(css, /\.cart-line__meta-slot--coupon \{[\s\S]*?left: 20px;/, '优惠券须在原位置基础上左移30px')
assert.match(css, /\.checkout-payment-line__amount \{[\s\S]*?justify-content: flex-start;/, '收款金额区域须靠左')
assert.match(css, /\.checkout-payment-line__amount-input \{[\s\S]*?text-align: left;/, '收款金额输入内容须靠左')

assert.match(workbench, /function isInventoryManagedProductLine\(line = \{\}\) \{\s*return isProductLine\(line\) && !isCustomCardPurchase\(line\)/, '定制卡不得被识别为库存商品')
assert.match(workbench, /function customCardPurchaseSnapshot\(configuration = \{\}\)/, '定制卡必须从浏览器缓存配置形成完整结账快照')
assert.match(workbench, /cardPurchaseSnapshot: customCardPurchaseSnapshot\(payload\)/, '创建定制卡时必须立即缓存完整卡项快照')
assert.match(workbench, /if \(isCustomCardPurchase\(line\)\) \{[\s\S]*?return customCardPurchaseSnapshot\(/, '最终结账必须从定制卡行缓存重建唯一快照')
assert.match(workbench, /v-if="isInventoryManagedProductLine\(line\)"[\s\S]*?>出库<\/button>[\s\S]*?v-if="isInventoryManagedProductLine\(line\)"[\s\S]*?>不出库<\/button>[\s\S]*?v-if="isInventoryManagedProductLine\(line\)"[\s\S]*?>预售<\/button>/, '定制卡不得渲染出库、不出库或预售控制')

console.log('CATALOG_CUSTOM_CARD_UI_CONTRACT=PASS')
