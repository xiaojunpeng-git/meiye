import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const page = read('前端代码/cashier-v3/src/views/OrderCenterView.vue')
const overlay = read('前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue')
const refund = read('后端代码/app/services/cashier/v3/order/CashierV3RefundSalespersonServices.php')
const attribution = read('后端代码/app/services/cashier/v3/order/CashierV3RefundAttributionServices.php')
const list = read('后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php')

assert.match(page, /field\('salesperson', '退款销售人', 'person'\),\s*field\('refund_attribution', '导购\/销售经理', 'person'\)/)
assert.match(page, /refund_attribution: \['refundAttributionSummary'\]/)
assert.match(page, /<div class="unified-refund-dialog__field-row">\s*<div class="unified-refund-dialog__choices"[\s\S]*?不作废[\s\S]*?<label class="service-void-modal__label">导购\/销售经理<\/label>/)
assert.match(page, /v-if="unifiedRefundAttributionEditorOpen && unifiedRefundPersonnel"[\s\S]*?hide-guide-rounds[\s\S]*?@confirm="setUnifiedRefundAttribution"/)
assert.match(page, /guideSelections: unifiedRefundGuides\.value\.map\([\s\S]*?guideRoundNo: 0/)
assert.match(page, /salesManagerSelections: unifiedRefundSalesManagers\.value\.map/)
assert.match(page, /scope: 'group_attributions', lineRole: 'sale'/)
assert.match(overlay, /v-if="selectedGuides\.length && !hideGuideRounds"/)
assert.match(overlay, /v-if="activeTab === 'guides' && selectedGuides\.length && !hideGuideRounds"/)
assert.match(refund, /\(new CashierV3RefundAttributionServices\(\)\)->createInTx\(\$operation, \$payload, \$source,/)
assert.match(attribution, /CashierV3TransactionGuard::assertInTransaction\('refundAttribution\.createInTx'\)/)
assert.match(attribution, /guideRoundNo'\] \?\? 0\) !== 0/)
assert.match(list, /'refundAttributionSummary' => \$attributionSummary/)
console.log('PASS R42 refund attribution contract')
