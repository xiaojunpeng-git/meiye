<script setup>
import { requestCashierV3Action } from '@/services/cashierV3Bridge'

// 前后端 permission 不一致：使用 manifest 中不存在的 permission 字段发网
function badPerm() {
  requestCashierV3Action('choose-catalog-item', { permission: 'cashier-fake-index' })
}
</script>
