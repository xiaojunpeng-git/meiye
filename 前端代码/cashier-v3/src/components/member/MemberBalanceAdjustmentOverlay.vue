<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import RequiredFieldGuidance from '@/components/common/RequiredFieldGuidance.vue'
import { guideRequiredField, REQUIRED_FIELD_GUIDANCE_EVENT, REQUIRED_FIELD_GUIDANCE_DURATION_MS } from '@/services/requiredFieldGuidance'

const props = defineProps({ snapshot: { type: Object, required: true }, onSubmit: { type: Function, required: true } })
const emit = defineEmits(['close', 'saved'])
// 与旧版相同：金额表示本次增减量，打开时清零，不把当前本金/赠金当成新目标值。
const type = ref(1), principal = ref('0'), bonus = ref('0'), remark = ref('')
const saving = ref(false), error = ref(''), root = ref(null), principalInput = ref(null), bonusInput = ref(null)
const guidance = ref({ field: '', message: '' })
let timer, previousFocus, attempt = null
function clearGuide() { clearTimeout(timer); guidance.value = { field: '', message: '' } }
async function handleGuide(event) {
  const field = { 'member-balance-principal': 'principal', 'member-balance-bonus': 'bonus' }[event.detail?.target]
  if (!field) return
  clearGuide(); guidance.value = { field, message: event.detail.message }
  await nextTick()
  const input = field === 'principal' ? principalInput.value : bonusInput.value
  input?.scrollIntoView({ behavior: 'smooth', block: 'center' }); input?.focus()
  timer = setTimeout(clearGuide, REQUIRED_FIELD_GUIDANCE_DURATION_MS)
}
function guide(field, message) { guideRequiredField({ target: 'member-balance-' + field, message }) }
async function save() {
  if (saving.value) return
  error.value = ''
  for (const [field, value] of [['principal', principal.value], ['bonus', bonus.value]]) {
    if (!/^(0|[1-9]\d{0,6})$/.test(String(value))) {
      guide(field, `请在这里填写0至9999999的整数${field === 'principal' ? '本金' : '赠金'}`); return
    }
  }
  if (Number(principal.value) + Number(bonus.value) === 0) { guide('principal', '请在这里填写要修改的本金或赠金，不能都为0'); return }
  const body = { memberId: props.snapshot.memberId, type: type.value, ben_money: String(principal.value),
    give_money: String(bonus.value), mark: remark.value, expectedVersion: props.snapshot.expectedVersion }
  // 响应丢失时同一内容沿用同一幂等键；服务端真实余额版本同时防止并发覆盖。
  const fingerprint = JSON.stringify(body)
  if (!attempt || attempt.fingerprint !== fingerprint) attempt = { fingerprint, key: crypto.randomUUID() }
  saving.value = true
  try { await props.onSubmit({ ...body, idempotencyKey: attempt.key }); emit('saved') }
  catch (e) { error.value = e.message || '修改失败，请重试' }
  finally { saving.value = false }
}
function close() { if (!saving.value) emit('close') }
onMounted(() => { previousFocus = document.activeElement; root.value?.focus(); window.addEventListener(REQUIRED_FIELD_GUIDANCE_EVENT, handleGuide) })
onBeforeUnmount(() => { clearGuide(); window.removeEventListener(REQUIRED_FIELD_GUIDANCE_EVENT, handleGuide); previousFocus?.focus?.() })
</script>

<template>
  <div class="balance-adjust-overlay" @keydown.esc.stop="close">
    <section ref="root" class="balance-adjust-dialog" role="dialog" aria-modal="true" aria-labelledby="balance-adjust-title" tabindex="-1">
      <header><h2 id="balance-adjust-title">修改余额</h2><button type="button" aria-label="关闭修改余额" :disabled="saving" @click="close">×</button></header>
      <div class="balance-adjust-info">
        <img v-if="snapshot.avatar" :src="snapshot.avatar" alt="会员头像">
        <div><strong>{{ snapshot.name }}</strong><span>{{ snapshot.phone }}</span><div class="balance-adjust-values"><span>余额 <b>{{ snapshot.balance }}</b></span><span>本金 <b>{{ snapshot.principal }}</b></span><span>赠金 <b>{{ snapshot.bonus }}</b></span></div></div>
      </div>
      <!-- 统一由行内定位校验处理整数边界，避免原生气泡先截断提交。 -->
      <form novalidate @submit.prevent="save">
        <fieldset :disabled="saving">
          <div class="balance-adjust-field"><span>修改类型：</span><div class="balance-adjust-radios"><label><input v-model="type" type="radio" :value="1" name="balance-adjust-type">增加</label><label><input v-model="type" type="radio" :value="2" name="balance-adjust-type">减少</label></div></div>
          <div class="balance-adjust-field"><label for="balance-adjust-principal">修改本金：</label><div class="balance-adjust-input"><input id="balance-adjust-principal" ref="principalInput" v-model="principal" type="number" min="0" max="9999999" step="1" :class="{ guided: guidance.field === 'principal' }" @pointerdown="clearGuide" @input="clearGuide"><RequiredFieldGuidance v-if="guidance.field === 'principal'" :message="guidance.message" /></div></div>
          <div class="balance-adjust-field"><label for="balance-adjust-bonus">修改赠金：</label><div class="balance-adjust-input"><input id="balance-adjust-bonus" ref="bonusInput" v-model="bonus" type="number" min="0" max="9999999" step="1" :class="{ guided: guidance.field === 'bonus' }" @pointerdown="clearGuide" @input="clearGuide"><RequiredFieldGuidance v-if="guidance.field === 'bonus'" :message="guidance.message" /></div></div>
          <div class="balance-adjust-field"><label for="balance-adjust-remark">备注：</label><input id="balance-adjust-remark" v-model="remark" maxlength="500" placeholder="请输入备注内容"></div>
        </fieldset>
        <p v-if="error" class="balance-adjust-error" role="alert">{{ error }}</p>
        <footer><button type="button" :disabled="saving" @click="close">取消</button><button type="submit" class="primary" :disabled="saving">{{ saving ? '提交中…' : '提交' }}</button></footer>
      </form>
    </section>
  </div>
</template>

<style scoped>
.balance-adjust-overlay{position:fixed;inset:0;z-index:1200;background:rgba(0,0,0,.45);display:flex;align-items:flex-start;justify-content:center;padding:10vh 20px;overflow:auto}.balance-adjust-dialog{width:680px;max-width:100%;background:#fff;border-radius:6px;box-shadow:0 10px 35px #0003;outline:none;color:#303133;font-size:14px}.balance-adjust-dialog header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e8eaec}.balance-adjust-dialog h2{margin:0;font-size:16px;font-weight:600}.balance-adjust-dialog header button{border:0;background:none;font-size:24px;color:#808695}.balance-adjust-info{display:flex;gap:14px;align-items:center;margin:24px}.balance-adjust-info img{width:60px;height:60px;border-radius:50%;object-fit:cover}.balance-adjust-info strong,.balance-adjust-info>div>span{display:inline-block;margin-right:14px}.balance-adjust-values{display:flex;flex-wrap:wrap;gap:20px;margin-top:10px}.balance-adjust-values b{color:#f5222d;font-size:16px;margin-left:4px}.balance-adjust-dialog fieldset{border:0;margin:0;padding:0 24px 8px;min-width:0}.balance-adjust-field{display:flex;align-items:center;gap:12px;margin-bottom:24px}.balance-adjust-field>label,.balance-adjust-field>span{flex:0 0 90px;text-align:right}.balance-adjust-field>input,.balance-adjust-input{flex:1;min-width:0}.balance-adjust-input{position:relative}.balance-adjust-field input:not([type=radio]){width:100%;box-sizing:border-box;height:34px;padding:4px 10px;border:1px solid #dcdee2;border-radius:4px;font:inherit}.balance-adjust-field input:focus{outline:2px solid #b6d8ff;border-color:#2d8cf0}.balance-adjust-input input.guided{outline:3px solid #f59e0b}.balance-adjust-radios{display:flex;gap:24px}.balance-adjust-radios label{display:flex;align-items:center;gap:6px}.balance-adjust-dialog footer{display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #e8eaec;padding:12px 20px}.balance-adjust-dialog button{cursor:pointer}.balance-adjust-dialog footer button{padding:7px 18px;border:1px solid #dcdee2;border-radius:4px;background:white;color:#515a6e;font:inherit}.balance-adjust-dialog footer .primary{background:#2d8cf0;border-color:#2d8cf0;color:white}.balance-adjust-dialog button:disabled{opacity:.6;cursor:wait}.balance-adjust-error{margin:0 24px 16px;color:#c62828}@media(max-width:520px){.balance-adjust-overlay{padding:20px 10px}.balance-adjust-field{gap:6px}.balance-adjust-field>label,.balance-adjust-field>span{flex-basis:80px}.balance-adjust-values{gap:10px}}
</style>
