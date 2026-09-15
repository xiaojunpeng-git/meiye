import assert from 'node:assert/strict'
import fs from 'node:fs'

const workbench = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const orderCenter = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue', import.meta.url), 'utf8')
const salesDetail = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue', import.meta.url), 'utf8')
const baseCss = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/styles/base.css', import.meta.url), 'utf8')

assert.match(workbench, /function openCartLineDetailRemark\(line\)/, '每条购物车明细必须有独立备注入口')
assert.match(workbench, /action === 'update-cart-line-detail-remark'[\s\S]{0,280}target\.detailRemark/, '备注必须仅更新对应本地购物车行')
assert.match(workbench, /type: 'line-detail-remark',[\s\S]{0,100}title: '明细备注'/, '编辑器必须标识明细备注模式')
assert.match(workbench, /editor\.type === 'line-detail-remark'[\s\S]{0,180}update-cart-line-detail-remark/, '保存必须发送行级备注草稿动作')
assert.match(workbench, /cart-line__detail-remark[\s\S]{0,180}明细备注[\s\S]{0,500}v-if="cardOperationUpgradeBinding\(line\)/, '备注按钮必须位于数量控件左侧且不打断 v-if/v-else 数量分支')
assert.match(workbench, /v-else-if="moreActionEditor\.type === 'line-detail-remark'"[\s\S]{0,240}placeholder="填写该明细备注"/, '明细备注必须使用独立编辑框')
assert.doesNotMatch(workbench, /v-else-if="moreActionEditor\.type === 'line-detail-remark'"[\s\S]{0,240}maxlength=/, '明细备注不得增加业务长度校验')
assert.match(baseCss, /\.cart-line__detail-remark\s*\{/, '备注按钮必须有收银样式')
assert.match(salesDetail, /明细备注：/, '销售订单详情必须展示明细备注快照')
assert.match(orderCenter, /detail_remark: \['detailRemark'\]/, '服务记录字段必须映射明细备注快照')
assert.match(orderCenter, /field\('detail_remark', '明细备注', 'text', \{ defaultVisible: false \}\)/, '服务记录列表不应默认扩列，详情必须有明细备注字段')
assert.match(orderCenter, /detailOnlyKeys[\s\S]{0,180}'detail_remark'/, '服务记录详情必须无条件可展示该快照字段')

console.log('CART_LINE_DETAIL_REMARK_FRONTEND_CONTRACT=PASS')
