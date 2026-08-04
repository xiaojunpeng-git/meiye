import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/member/RechargeOverlay.vue'), 'utf8')

let passed = 0
function expect(name, condition) {
  if (!condition) throw new Error(`FAIL ${name}`)
  passed += 1
  console.log(`PASS ${name}`)
}

expect('桌面套餐固定一行四项', source.includes('grid-template-columns: repeat(4, minmax(0, 1fr))'))
expect('套餐金额明确为本金且赠送位于右下', source.includes('class="recharge-overlay__package-main"')
  && source.includes('本金 {{ formatMoney(item.price) }}')
  && source.includes('grid-column: 2; grid-row: 2'))
expect('套餐摘要独立位于主金额区下一行', source.includes('<small v-if="item.giftProductCount || item.giftCouponCount">'))
expect('套餐模式不渲染重复本金与赠送输入', source.includes('<template v-if="rechargeMode === \'custom\'">')
  && source.includes(':class="{ \'recharge-overlay__fields--custom\': rechargeMode === \'custom\' }"'))
expect('自定义充值的本金赠送欠款固定三列', source.includes('.recharge-overlay__fields--custom { grid-template-columns: repeat(3, minmax(0, 1fr)); }'))
expect('小高度窗口的充值弹窗可以内部滚动至确认操作', source.includes('max-height: calc(100vh - 48px)')
  && source.includes('overflow-y: auto'))
expect('金额仅允许整数元', source.includes("if (!/^(0|[1-9]\\d*)$/.test(raw)) return false")
  && source.includes("const match = /^(0|[1-9]\\d*)$/.exec(raw)"))
expect('充值各金额输入即时截断小数', source.includes('function normalizePrincipalAmount(event)')
  && source.includes('function normalizeBonusAmount(event)')
  && source.includes('function normalizeDebtAmount(event)')
  && source.includes('@input="normalizePrincipalAmount"')
  && source.includes('@input="normalizeBonusAmount"')
  && source.includes('@input="normalizeDebtAmount"')
  && source.includes('@input="normalizePaymentAmount(line, $event)"')
  && source.includes('@input="normalizeSalespersonAmount(item, $event)"'))
expect('同一收款方式可新增多条且下拉不禁用', !source.includes('paymentLines.value.some((item) => item.paymentMethod === method)')
  && !source.includes(':disabled="code !== line.paymentMethod && paymentLines.some((item) => item.paymentMethod === code)"')
  && !source.includes(':disabled="paymentLines.some((item) => item.paymentMethod === code)"'))
expect('每笔充值收款行使用独立稳定标识', source.includes('id: `recharge-payment-${paymentLineSequence++}`'))

console.log(`RECHARGE_OVERLAY_FRONTEND_CONTRACT_OK passed=${passed}`)
