<script setup>
import { ref, watch } from 'vue'
import { confirmStoreTransferStaff, createRequestToken, findStoreTransferStaff } from '@/services/staffManagementApi'

const props = defineProps({ open: Boolean, contextKey: String, storeName: String })
const emit = defineEmits(['close', 'transferred'])
const phone = ref('')
const candidate = ref(null)
const busy = ref(false)
const error = ref('')
const uncertain = ref(false)
let token = ''
let sequence = 0

// 门店/登录上下文改变就丢弃旧候选，异步旧响应不能调入或刷新新门店。
watch(() => props.contextKey, () => {
  sequence += 1
  phone.value = ''; candidate.value = null; error.value = ''; uncertain.value = false; busy.value = false; token = ''
  emit('close')
})
watch(() => props.open, (open) => {
  if (open && !uncertain.value) {
    phone.value = ''; candidate.value = null; error.value = ''; token = ''
  }
})
watch(phone, () => { candidate.value = null; token = ''; error.value = '' })

async function search() {
  if (busy.value || uncertain.value) return
  candidate.value = null; token = ''; error.value = ''
  if (!/^1[3-9]\d{9}$/.test(phone.value.trim())) { error.value = '请输入完整的11位手机号码'; return }
  const current = ++sequence
  const context = props.contextKey
  busy.value = true
  try {
    const result = await findStoreTransferStaff(phone.value)
    if (current !== sequence || context !== props.contextKey) return
    candidate.value = result.data
  } catch (failure) {
    if (current === sequence && context === props.contextKey) error.value = failure.message || '查询失败，请重试'
  } finally {
    if (current === sequence && context === props.contextKey) busy.value = false
  }
}

async function confirm() {
  if (busy.value || !candidate.value) return
  const current = ++sequence
  const context = props.contextKey
  busy.value = true; error.value = ''
  try {
    // 首次确认后一直复用同一令牌；不把未知结果当失败后另建一笔调店。
    if (!token) token = createRequestToken()
    const result = await confirmStoreTransferStaff(candidate.value, token)
    if (current !== sequence || context !== props.contextKey) return
    uncertain.value = false; candidate.value = null; token = ''
    emit('transferred', result.data)
    emit('close')
  } catch (failure) {
    if (current !== sequence || context !== props.contextKey) return
    // 令牌生成失败时尚未发送请求，是确定失败；只有已生成令牌后的通信失败才锁定原请求。
    uncertain.value = token !== '' && failure.definitive !== true
    error.value = uncertain.value
      ? '调店结果尚未确认，请点击“查询操作结果”使用原请求重试，勿重复发起。'
      : failure.message || '调入失败，请重新查询'
    if (!uncertain.value) { candidate.value = null; token = '' }
  } finally {
    if (current === sequence && context === props.contextKey) busy.value = false
  }
}

function close() {
  if (busy.value || uncertain.value) return
  sequence += 1
  emit('close')
}
</script>

<template>
  <div v-if="open" class="staff-transfer-backdrop" @click.self="close">
    <form class="staff-transfer-dialog" role="dialog" aria-modal="true" aria-label="调入本门店" @submit.prevent="search">
      <header><h3>调店</h3><button type="button" aria-label="关闭" :disabled="busy || uncertain" @click="close">×</button></header>
      <main>
        <label for="staff-transfer-phone">员工手机号码</label>
        <div class="staff-transfer-search"><input id="staff-transfer-phone" v-model="phone" type="tel" inputmode="numeric" maxlength="11" autocomplete="off" placeholder="请输入完整手机号码" :disabled="busy || uncertain"><button type="submit" class="button button--primary" :disabled="busy || uncertain">查询</button></div>
        <section v-if="candidate" class="staff-transfer-candidate" aria-label="查找到的员工">
          <p>员工姓名：{{ candidate.staff_name }}</p><p>原门店：{{ candidate.from_store_name }}</p><p>调入门店：{{ storeName }}</p>
          <small v-if="candidate.source_staff_id">确认后结束原店任职，历史订单和业绩仍保留原店归属。</small>
          <small v-else>确认后在本店建立任职，并打开员工编辑页填写岗位及其他资料。</small>
        </section>
        <p v-if="error" class="staff-transfer-error" role="alert">{{ error }}</p>
      </main>
      <footer><button type="button" class="button button--secondary" :disabled="busy || uncertain" @click="close">取消</button><button type="button" class="button button--primary" :disabled="busy || !candidate" @click="confirm">{{ busy ? '处理中…' : uncertain ? '查询操作结果' : '确认调入本门店' }}</button></footer>
    </form>
  </div>
</template>

<style scoped>
.staff-transfer-backdrop { position:fixed; inset:0; z-index:1200; display:flex; align-items:center; justify-content:center; padding:20px; background:rgba(20,29,40,.4); }
.staff-transfer-dialog { width:480px; max-width:100%; border-radius:10px; background:#fff; box-shadow:0 18px 48px rgba(20,29,40,.18); }
header,footer { display:flex; align-items:center; padding:18px 20px; gap:10px; } header { justify-content:space-between; border-bottom:1px solid #edf1f5; } header h3 { margin:0; } header > button { border:0; background:transparent; font-size:24px; color:#697586; cursor:pointer; }
main { padding:20px; } main > label { display:block; margin-bottom:8px; color:#4c596a; } .staff-transfer-search { display:flex; gap:10px; } input { flex:1; min-width:0; height:36px; padding:0 10px; border:1px solid #d9e1eb; border-radius:6px; font:inherit; }
.staff-transfer-candidate { margin-top:16px; padding:12px; border:1px solid #dbe4ef; border-radius:6px; background:#f7faff; } .staff-transfer-candidate p { margin:0 0 8px; } small { color:#697586; line-height:1.5; } .staff-transfer-error { color:#d93025; margin:14px 0 0; line-height:1.5; } footer { justify-content:flex-end; border-top:1px solid #edf1f5; }
</style>
