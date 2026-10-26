<script setup>
import { requestCashierV3Action } from '@/services/cashierV3Bridge'
const mode = 'a'
function pick() {
  // 三元漏分支：只有 consequent 字面量，alternate 是动态 MemberExpression
  requestCashierV3Action(mode === 'a' ? 'create-reservation' : payload.action, {})
}
</script>
