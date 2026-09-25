import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const view = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue', import.meta.url), 'utf8')
const query = readFileSync(new URL('../../../后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php', import.meta.url), 'utf8')
const lifecycle = readFileSync(new URL('../../../后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php', import.meta.url), 'utf8')

// 购买和权益是两类业务事实；测试锁定标签、财务隔离以及服务记录命令复用。
assert.match(query, /'businessTag' => '购买'/)
assert.match(query, /'businessTag' => '权益'/)
assert.match(query, /'economicsDataStatus' => 'not_applicable'/)
assert.match(query, /'payableAmount' => null/)
assert.match(query, /'itemCount' => array_sum\(array_map\(static function \(array \$line\)/)
assert.match(view, /sales-order-query-item-cell__business-tag/)
assert.match(view, /salesItemCraftsmanAdjustable\(item\)/)
assert.match(view, /salesPurchaseCraftsmanAdjustable\(record, item\)/)
assert.match(view, /@click="openSalesItemCraftsmanEditor\(record, item\)"/)
assert.match(view, /function openServiceCraftsmanAdjustment\(record\)[\s\S]*?open-service-record-craftsman-adjustment/)
assert.match(view, /function submitServiceCraftsmanAdjustment\(\)[\s\S]*?adjust-service-record-craftsmen/)

// 销售订单和服务记录两张表必须使用相同的左对齐口径，
// 避免金额、状态等特殊单元格与表头产生视觉错位。
assert.match(view, /'service-record-query-table': activeTabKey === 'service'/)
assert.match(view, /\.sales-order-query-table th,[\s\S]*?\.service-record-query-table td \{ text-align: left; \}/)

// 没有服务事实的直接购买项目使用销售订单自身的追加式调整事实；
// 保存劳动指标时不得改写销售金额和支付事实。
assert.match(view, /role === 'craftsmen' \? 'craftsman'/)
assert.match(view, /target\.role === 'craftsman'[\s\S]*?allocationAmountCents:[\s\S]*?laborFeeCents:[\s\S]*?projectCount:/)
assert.match(lifecycle, /!in_array\(\$role, \['salesperson', 'craftsman', 'guide', 'sales_manager'\]/)
assert.match(lifecycle, /private function adjustCraftsmanFacts\([\s\S]*?labor_performance_allocated[\s\S]*?insertAdjustedPerformance/)
assert.doesNotMatch(lifecycle.match(/private function adjustCraftsmanFacts\([\s\S]*?\n    }\n\n    \/\*\*/)?.[0] || '', /cashier_v3_sales_order[^\n]*->update/)

console.log('ORDER_CENTER_SALES_SERVICE_LINES_CONTRACT=PASS')
