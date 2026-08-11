<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { loginStoreV3, persistStoreV3Token } from '@/services/storeV3LoginApi'
import { applyCashierV3LoginFeatures } from '@/services/cashierV3Bridge'
import { onCashierStoreOrAccountChanged } from '@/services/cashierV3SessionLifecycle'

const router = useRouter()
const account = ref('')
const password = ref('')
const submitting = ref(false)
const error = ref('')

async function submit() {
  if (submitting.value) return
  error.value = ''
  if (!account.value.trim() || !password.value) {
    error.value = '请输入登录账号和密码。'
    return
  }
  submitting.value = true
  try {
    const result = await loginStoreV3({ account: account.value.trim(), pwd: password.value })
    persistStoreV3Token(result.token)
    applyCashierV3LoginFeatures(result.features)
    // 登录成功后必须先接收该账号和门店的首份根投影。功能权限快照仅用于
    // 路由放行，不能替代工作台及其既有资源的并发版本。
    const bootstrap = await onCashierStoreOrAccountChanged({
      reason: 'account',
      storeId: Number(result.store_id || 0),
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
</script>

<template>
  <main class="store-login">
    <section class="store-login__layout">
      <div class="store-login__visual" aria-hidden="true" />
      <div class="store-login__access">
        <div class="store-login__message">
          <h2 aria-label="你亲手雕琢细腻美好，我留存全部暖心操作">
            <span aria-hidden="true">你亲手雕琢细腻美好，</span>
            <span aria-hidden="true">我留存全部暖心操作</span>
          </h2>
        </div>

        <form class="store-login__panel" @submit.prevent="submit">
          <header>
            <p>欢迎回来</p>
            <h1>门店端登录</h1>
          </header>
          <label>
            <span>登录账号</span>
            <input v-model.trim="account" autocomplete="username" />
          </label>
          <label>
            <span>登录密码</span>
            <input v-model="password" type="password" autocomplete="current-password" />
          </label>
          <p v-if="error" class="store-login__error" role="alert">{{ error }}</p>
          <button class="store-login__submit" type="submit" :disabled="submitting">{{ submitting ? '处理中…' : '登录' }}</button>
        </form>
      </div>
    </section>

    <footer class="store-login__footer">厦门魔核方舟科技有限公司提供支持</footer>
  </main>
</template>

<style scoped>
:global(html:has(.store-login)),
:global(body:has(.store-login)),
:global(#app:has(> .store-login)) {
  min-width: 0;
}

:global(body:has(.store-login)) { overflow-x: hidden; }

.store-login,
.store-login * { box-sizing: border-box; }

.store-login {
  position: relative;
  width: 100%;
  min-height: 100vh;
  min-height: 100dvh;
  overflow-x: hidden;
  display: grid;
  align-items: center;
  padding: 56px clamp(24px, 5vw, 80px) 72px;
  color: #202b33;
  background-color: #f7fafc;
  background-image: url('@/assets/login-beauty-quiet.png');
  background-position: center;
  background-repeat: no-repeat;
  background-size: cover;
}

.store-login__layout {
  width: min(1240px, 100%);
  margin: 0 auto;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(360px, 420px);
  align-items: center;
  gap: clamp(48px, 8vw, 120px);
}

.store-login__access {
  display: grid;
  width: 100%;
  gap: 22px;
}

.store-login__message {
  padding: 0;
}

.store-login__eyebrow {
  margin: 0 0 18px;
  color: #6e7f88;
  font-size: 14px;
  font-weight: 600;
}

.store-login__message h2 {
  margin: 0;
  color: #a9709d;
  font-family: "STKaiti", "KaiTi", "Kaiti SC", "Songti SC", "Noto Serif SC", serif;
  font-size: 25px;
  font-weight: 700;
  line-height: 1.42;
  letter-spacing: .075em;
  text-shadow: 0 3px 14px rgba(169, 112, 157, .16);
}

.store-login__message h2 span {
  display: block;
  opacity: 0;
  transform: translateY(10px);
  animation: store-login-title .7s ease-out forwards;
}

.store-login__message h2 span:last-child {
  padding-left: 1.2em;
  animation-delay: .12s;
}

.store-login__panel {
  width: 100%;
  display: grid;
  gap: 20px;
  padding: 38px 40px 40px;
  border: 1px solid rgba(178, 191, 198, .72);
  border-radius: 6px;
  background: rgba(255, 255, 255, .94);
  box-shadow: 0 18px 50px rgba(40, 58, 67, .12);
  backdrop-filter: blur(8px);
}

.store-login__panel header p {
  margin: 0 0 8px;
  color: #b05d6d;
  font-size: 14px;
  font-weight: 600;
}

.store-login__panel h1 {
  margin: 0 0 6px;
  color: #202b33;
  font-size: 26px;
  line-height: 1.3;
  font-weight: 650;
}

.store-login__panel label {
  display: grid;
  gap: 9px;
  color: #4d5d65;
  font-size: 14px;
  font-weight: 500;
}

.store-login__panel input {
  width: 100%;
  min-width: 0;
  min-height: 46px;
  padding: 10px 13px;
  border: 1px solid #bdc8ce;
  border-radius: 4px;
  outline: none;
  color: #202b33;
  background: #fff;
  font: inherit;
  transition: border-color .18s ease, box-shadow .18s ease;
}

.store-login__panel input:focus {
  border-color: #6f9286;
  box-shadow: 0 0 0 3px rgba(111, 146, 134, .16);
}

.store-login__submit {
  min-height: 46px;
  margin-top: 2px;
  border: 0;
  border-radius: 4px;
  background: #315d52;
  color: #fff;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: background-color .18s ease, transform .18s ease;
}

.store-login__submit:hover:not(:disabled) { background: #284d44; }
.store-login__submit:active:not(:disabled) { transform: translateY(1px); }
.store-login__submit:disabled { opacity: .6; cursor: wait; }

.store-login__error {
  margin: -4px 0 0;
  color: #b42318;
  font-size: 14px;
  line-height: 1.5;
}

.store-login__footer {
  position: absolute;
  right: 24px;
  bottom: 22px;
  left: 24px;
  color: #7d8a91;
  font-size: 13px;
  text-align: center;
}

@keyframes store-login-title {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 860px) {
  .store-login {
    align-items: start;
    min-height: 100dvh;
    padding: 48px 18px 76px;
    background-position: 31% center;
  }

  .store-login__layout {
    grid-template-columns: minmax(0, 1fr);
    gap: 28px;
  }

  .store-login__visual { display: none; }

  .store-login__access {
    width: min(440px, 100%);
    margin: 0 auto;
  }

  .store-login__message {
    width: 100%;
  }

  .store-login__message h2 { font-size: 24px; }

  .store-login__panel {
    width: 100%;
    margin: 0;
    padding: 30px 26px 32px;
    background: rgba(255, 255, 255, .97);
  }
}

@media (max-width: 460px) {
  .store-login { padding: 34px 14px 70px; }
  .store-login__eyebrow { margin-bottom: 10px; }
  .store-login__message h2 { font-size: 20px; line-height: 1.5; }
  .store-login__panel { gap: 17px; padding: 26px 20px 28px; }
  .store-login__panel h1 { font-size: 23px; }
  .store-login__footer { right: 12px; bottom: 16px; left: 12px; font-size: 12px; }
}

@media (prefers-reduced-motion: reduce) {
  .store-login__message h2 span {
    opacity: 1;
    transform: none;
    animation: none;
  }
}
</style>
