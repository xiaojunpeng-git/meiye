<script setup>
function requestDynamic(action) {
  requestCashierV3Action(action, {})
}
function wrapper(action) {
  return requestDynamic(action)
}
import { requestCashierV3Action } from '@/services/cashierV3Bridge'
</script>
