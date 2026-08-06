<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  sources: { type: Array, default: () => [] },
  primarySourceId: { type: Number, default: 0 },
  secondarySourceId: { type: Number, default: 0 },
  saving: { type: Boolean, default: false },
  loadError: { type: String, default: '' }
})
const emit = defineEmits(['close', 'confirm', 'retry'])
const primaryId = ref(0)
const secondaryId = ref(0)
const validationMessage = ref('')

const roots = computed(() => Array.isArray(props.sources) ? props.sources.filter((item) => Number(item?.id) > 0) : [])
const selectedPrimary = computed(() => roots.value.find((item) => Number(item.id) === Number(primaryId.value)) || null)
const children = computed(() => Array.isArray(selectedPrimary.value?.children) ? selectedPrimary.value.children.filter((item) => Number(item?.id) > 0) : [])
const requiresSecondary = computed(() => selectedPrimary.value?.requireSecondary === true || Number(selectedPrimary.value?.requireSecondary) === 1)

watch(() => [props.primarySourceId, props.secondarySourceId, props.sources], () => {
  primaryId.value = Number(props.primarySourceId) || 0
  secondaryId.value = Number(props.secondarySourceId) || 0
  validationMessage.value = ''
}, { immediate: true, deep: true })

function choosePrimary(id) {
  primaryId.value = Number(id) || 0
  if (!children.value.some((item) => Number(item.id) === Number(secondaryId.value))) secondaryId.value = 0
  validationMessage.value = ''
}

function confirm() {
  if (!primaryId.value) {
    validationMessage.value = '请选择一级来源。'
    return
  }
  if (requiresSecondary.value && !secondaryId.value) {
    validationMessage.value = '该来源要求同时选择二级来源。'
    return
  }
  emit('confirm', { primarySourceId: primaryId.value, secondarySourceId: secondaryId.value })
}
</script>

<template>
  <Teleport to="body">
    <section class="checkout-source-overlay" role="presentation" @click.self="!saving && emit('close')">
      <section class="checkout-source-panel" role="dialog" aria-modal="true" aria-label="选择业务来源">
        <header>
          <div><span>结账信息</span><h2>选择业务来源</h2></div>
          <button type="button" class="button button--secondary" :disabled="saving" @click="emit('close')">关闭</button>
        </header>
        <div v-if="loadError" class="checkout-source-panel__error" role="alert">
          <span>{{ loadError }}</span><button type="button" class="button button--secondary" @click="emit('retry')">重新加载</button>
        </div>
        <template v-else>
          <section class="checkout-source-panel__section">
            <strong>一级来源</strong>
            <div class="checkout-source-panel__choices">
              <button v-for="item in roots" :key="item.id" type="button" :class="{ 'is-selected': Number(item.id) === Number(primaryId) }" @click="choosePrimary(item.id)">{{ item.name }}</button>
            </div>
            <p v-if="!roots.length" class="checkout-source-panel__empty">当前没有可用的业务来源，请联系平台管理员配置后重试。</p>
          </section>
          <section v-if="selectedPrimary && children.length" class="checkout-source-panel__section">
            <strong>二级来源 <small v-if="requiresSecondary">必选</small></strong>
            <div class="checkout-source-panel__choices">
              <button type="button" :class="{ 'is-selected': !secondaryId }" @click="secondaryId = 0">不选择二级</button>
              <button v-for="item in children" :key="item.id" type="button" :class="{ 'is-selected': Number(item.id) === Number(secondaryId) }" @click="secondaryId = Number(item.id)">{{ item.name }}</button>
            </div>
          </section>
          <p v-if="validationMessage" class="checkout-source-panel__error" role="alert">{{ validationMessage }}</p>
        </template>
        <footer><button type="button" class="button button--secondary" :disabled="saving" @click="emit('close')">取消</button><button type="button" class="button button--primary" :disabled="saving || Boolean(loadError) || !roots.length" @click="confirm">{{ saving ? '正在保存' : '确认' }}</button></footer>
      </section>
    </section>
  </Teleport>
</template>

<style scoped>
.checkout-source-overlay { position: fixed; inset: 0; z-index: 1300; display: grid; place-items: center; padding: 24px; background: rgba(20, 32, 46, .45); }
.checkout-source-panel { width: min(640px, 100%); border: 1px solid #dfe5ec; border-radius: 8px; background: #fff; box-shadow: 0 20px 56px rgba(18, 34, 54, .24); }
.checkout-source-panel > header, .checkout-source-panel > footer { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 22px; }
.checkout-source-panel > header { border-bottom: 1px solid #edf0f4; }.checkout-source-panel > header span, .checkout-source-panel small { color: #7b8798; font-size: 12px; }.checkout-source-panel h2 { margin: 3px 0 0; font-size: 18px; }.checkout-source-panel > footer { justify-content: flex-end; border-top: 1px solid #edf0f4; }
.checkout-source-panel__section { display: grid; gap: 10px; padding: 18px 22px 0; }.checkout-source-panel__section > strong { color: #263445; font-size: 14px; }.checkout-source-panel__choices { display: flex; flex-wrap: wrap; gap: 8px; }.checkout-source-panel__choices button { min-height: 34px; padding: 0 12px; border: 1px solid #cfd7e3; border-radius: 5px; background: #fff; color: #3f4c5c; cursor: pointer; }.checkout-source-panel__choices button.is-selected { border-color: #2981e5; background: #f1f7ff; color: #175fb3; }.checkout-source-panel__error, .checkout-source-panel__empty { margin: 18px 22px 0; color: #c63434; font-size: 13px; }.checkout-source-panel__error { display: flex; align-items: center; gap: 12px; }.checkout-source-panel__empty { color: #7b8798; }
</style>
