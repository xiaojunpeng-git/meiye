<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
const props = defineProps({ memberId: { type: [Number, String], required: true }, adapter: { type: Object, required: true } })
const info = ref(null), password = ref(''), loading = ref(false), busy = ref(false), message = ref(''), failed = ref(false), confirmClear = ref(false)
let sequence = 0
// 密码仅留在当前输入控件内存，切换会员/离开页签即清空，不加入会员资料草稿。
onBeforeUnmount(() => { password.value = ''; sequence++ })
async function load() {
  const current = ++sequence
  info.value = null; password.value = ''; message.value = ''; failed.value = false; confirmClear.value = false; busy.value = false; loading.value = true
  try { const data = await props.adapter.read(props.memberId); if (current === sequence) info.value = data }
  catch (error) { if (current === sequence) { message.value = error.message; failed.value = true } }
  finally { if (current === sequence) loading.value = false }
}
watch(() => props.memberId, load, { immediate: true })
async function submit(clear = false) {
  if (busy.value || !info.value) return
  busy.value = true; message.value = ''; failed.value = false
  // 请求只更新发起时的会员；切换会员后迟到的响应不能回填另一人的登录信息。
  const current = sequence, memberId = props.memberId
  const data = { revision: info.value.revision, requestId: crypto.randomUUID().replaceAll('-', '') }
  if (!clear) data.newPassword = password.value
  try {
    const saved = await (clear ? props.adapter.clear(memberId, data) : props.adapter.password(memberId, data))
    if (current !== sequence) return
    info.value = saved
    password.value = ''; confirmClear.value = false
    message.value = clear ? '微信绑定已清除，旧登录已失效，会员需重新授权手机号登录。' : '密码已更新，旧登录已失效。'
  } catch (error) { if (current === sequence) { message.value = error.message; failed.value = true } }
  finally { if (current === sequence) busy.value = false }
}
</script>

<template>
  <section class="member-login-panel" aria-label="登录信息" :aria-busy="loading || busy">
    <p v-if="loading" role="status">正在读取登录信息…</p>
    <template v-if="info">
      <label class="member-login-panel__field"><span>会员端登录账号</span><input :value="info.account" readonly aria-readonly="true"><small>登录账号为手机号，修改手机号请到基本信息。</small></label>
      <label class="member-login-panel__field"><span>新密码</span><input v-model="password" type="text" autocomplete="off" maxlength="20" placeholder="输入6至20位新密码" :disabled="busy"><small>输入内容直接显示。原密码不回显，不填写则不修改。</small></label>
      <button type="button" class="member-creator__button member-creator__button--primary" :disabled="busy || !password" @click="submit(false)">{{ busy ? '处理中…' : '修改密码' }}</button>
      <section class="member-login-panel__bindings"><h3>微信绑定信息</h3>
        <p v-if="!info.bindings?.length">未绑定微信</p>
        <div v-for="binding in info.bindings" :key="`${binding.channel}:${binding.boundAt}`" class="member-login-panel__binding"><strong>{{ binding.label }}</strong><span>{{ binding.nickname || '未设置昵称' }}</span><small>{{ binding.boundAt || '绑定时间未记录' }}</small></div>
        <button type="button" class="member-creator__button" :disabled="busy || !info.bindings?.length" @click="confirmClear = true">清除绑定信息</button>
        <div v-if="confirmClear" class="member-login-panel__confirm" role="alert"><p>清除后，原微信关联及旧登录失效；会员需重新授权当前手机号才能进入原账号。余额、卡项和订单保留。</p><button type="button" class="member-creator__button" :disabled="busy" @click="confirmClear = false">取消</button><button type="button" class="member-creator__button member-creator__button--primary" :disabled="busy" @click="submit(true)">确认清除</button></div>
      </section>
    </template>
    <p v-if="message" :role="failed ? 'alert' : 'status'" :class="{ 'member-login-panel__error': failed }">{{ message }}</p>
    <button v-if="!info && !loading" type="button" class="member-creator__button" @click="load">重新读取</button>
  </section>
</template>

<style scoped>
.member-login-panel{display:grid;gap:18px;padding:6px 0 16px;color:#334155}.member-login-panel__field{display:grid;gap:8px;font-size:13px}.member-login-panel__field input{box-sizing:border-box;width:100%;height:40px;padding:0 12px;border:1px solid #dce3ed;border-radius:6px;background:#fff;color:#25374e}.member-login-panel__field input[readonly]{background:#f5f7fa}.member-login-panel small{color:#738298}.member-login-panel button{justify-self:start;min-height:36px;padding:8px 16px;border:1px solid #dce3ed;border-radius:5px;background:#fff;color:#334155;cursor:pointer}.member-login-panel .member-creator__button--primary{background:#477caf;color:#fff;border-color:#477caf}.member-login-panel button:disabled{opacity:.5;cursor:default}.member-login-panel__bindings{border-top:1px solid #edf0f5;padding-top:16px}.member-login-panel__bindings h3{margin:0 0 14px;font-size:14px}.member-login-panel__binding{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:0 0 14px;padding:12px;background:#f7f9fc;border-radius:6px}.member-login-panel__binding small{margin-left:auto}.member-login-panel__confirm{margin-top:14px;padding:14px;border:1px solid #e3d5be;border-radius:6px;background:#fffcf5}.member-login-panel__confirm button+button{margin-left:10px}.member-login-panel__error{color:#b42318}
</style>
