<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { loginStoreV3, persistStoreV3Token } from '@/services/storeV3LoginApi'
import { applyCashierV3LoginFeatures } from '@/services/cashierV3Bridge'
import { onCashierStoreOrAccountChanged } from '@/services/cashierV3SessionLifecycle'

const router = useRouter()
const account = ref('')
const password = ref('')
const ticket = ref('')
const stores = ref([])
const selectedStoreId = ref(0)
const submitting = ref(false)
const error = ref('')
const needsStoreSelection = computed(() => stores.value.length > 0 && !!ticket.value)

async function submit() {
  if (submitting.value) return
  error.value = ''
  if (!needsStoreSelection.value && (!account.value.trim() || !password.value)) {
    error.value = '请输入登录账号和密码。'
    return
  }
  if (needsStoreSelection.value && !selectedStoreId.value) {
    error.value = '请选择要进入的门店。'
    return
  }
  submitting.value = true
  try {
    const result = await loginStoreV3(needsStoreSelection.value
      ? { store_id: selectedStoreId.value, login_ticket: ticket.value }
      : { account: account.value.trim(), pwd: password.value })
    if (result.need_select_store) {
      ticket.value = String(result.login_ticket || '')
      stores.value = Array.isArray(result.stores) ? result.stores : []
      selectedStoreId.value = stores.value.length === 1 ? Number(stores.value[0].store_id) : 0
      if (!ticket.value || !stores.value.length) throw new Error('当前账号没有可进入的门店端门店。')
      return
    }
    persistStoreV3Token(result.token)
    applyCashierV3LoginFeatures(result.features)
    // 登录成功后必须先接收该账号和门店的首份根投影。功能权限快照仅用于
    // 路由放行，不能替代工作台及其既有资源的并发版本。
    const bootstrap = await onCashierStoreOrAccountChanged({
      reason: 'account',
      storeId: Number(result.store_id || selectedStoreId.value || 0),
      silent: true
    })
    const bootstrapResult = bootstrap?.data?.result && typeof bootstrap.data.result === 'object'
      ? bootstrap.data.result
      : bootstrap?.result
    if (!['success', 'succeeded'].includes(String(bootstrapResult?.status || '').toLowerCase())) {
      throw new Error(String(bootstrapResult?.message || '门店工作台初始化失败，请稍后重试。'))
    }
    await router.replace('/cashier')
  } catch (reason) {
    error.value = reason instanceof Error ? reason.message : '登录未完成，请稍后重试。'
  } finally {
    submitting.value = false
  }
}

function resetSelection() {
  ticket.value = ''
  stores.value = []
  selectedStoreId.value = 0
  error.value = ''
}
</script>

<template>
  <main class="store-login">
    <form class="store-login__panel" @submit.prevent="submit">
      <h1>门店端登录</h1>
      <template v-if="!needsStoreSelection">
        <label>登录账号<input v-model.trim="account" autocomplete="username" /></label>
        <label>登录密码<input v-model="password" type="password" autocomplete="current-password" /></label>
      </template>
      <template v-else>
        <label>进入门店
          <select v-model.number="selectedStoreId">
            <option :value="0" disabled>请选择门店</option>
            <option v-for="store in stores" :key="store.store_id" :value="Number(store.store_id)">{{ store.store_name }}</option>
          </select>
        </label>
        <button class="store-login__text" type="button" :disabled="submitting" @click="resetSelection">返回账号登录</button>
      </template>
      <p v-if="error" class="store-login__error" role="alert">{{ error }}</p>
      <button class="store-login__submit" type="submit" :disabled="submitting">{{ submitting ? '处理中…' : (needsStoreSelection ? '进入门店' : '登录') }}</button>
    </form>
  </main>
</template>

<style scoped>
.store-login { min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #eef2f5; color: #18222d; }
.store-login__panel { width: min(400px, 100%); display: grid; gap: 18px; padding: 32px; border: 1px solid #d6dce2; border-radius: 8px; background: #fff; }
h1 { margin: 0 0 8px; font-size: 24px; font-weight: 600; }
label { display: grid; gap: 8px; font-size: 14px; color: #435160; }
input, select { min-height: 40px; padding: 8px 10px; border: 1px solid #b9c3cd; border-radius: 4px; color: #18222d; background: #fff; }
.store-login__submit { min-height: 42px; border: 0; border-radius: 4px; background: #1677c8; color: #fff; font-size: 15px; cursor: pointer; }
.store-login__submit:disabled { opacity: .6; cursor: wait; }
.store-login__text { justify-self: start; padding: 0; border: 0; color: #1677c8; background: transparent; cursor: pointer; }
.store-login__error { margin: -6px 0 0; color: #b42318; font-size: 14px; }
</style>
