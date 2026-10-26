import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const backend = readFileSync(new URL('../../../后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php', import.meta.url), 'utf8')
const detail = readFileSync(new URL('../../../前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue', import.meta.url), 'utf8')

// 订单详情只显示核销时冻结的卡来源，不从当前持卡记录或赠送名称推断历史卡项。
assert.match(backend, /wf\.entitlement_instance_type,wf\.source_name_snapshot,wf\.source_code_snapshot/)
assert.match(backend, /'cardName' =>[\s\S]*?entitlement_instance_type'[\s\S]*?card_holder'[\s\S]*?source_name_snapshot/)
assert.match(backend, /'cardNo' =>[\s\S]*?entitlement_instance_type'[\s\S]*?card_holder'[\s\S]*?source_code_snapshot/)
assert.match(detail, /item\.businessTag === '权益' && \(item\.cardName \|\| item\.cardNo\)/)
assert.match(detail, /卡名称：\{\{ item\.cardName \}\}/)
assert.match(detail, /卡号：\{\{ item\.cardNo \}\}/)

console.log('ORDER_CENTER_ENTITLEMENT_CARD_SOURCE_CONTRACT=PASS')
