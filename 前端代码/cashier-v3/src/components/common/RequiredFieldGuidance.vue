<script setup>
defineProps({
  message: {
    type: String,
    default: '请完成此必填项'
  }
})
</script>

<template>
  <span class="required-field-guidance" role="status" aria-live="polite">{{ message }}</span>
</template>

<style scoped>
/* 必填提示紧贴目标控件，短箭头负责指向目标；提示本身不拦截用户继续操作。 */
.required-field-guidance {
  position: absolute;
  top: calc(100% + 10px);
  left: 0;
  z-index: 5;
  width: max-content;
  max-width: 220px;
  padding: 7px 10px;
  border-radius: 6px;
  background: #d97706;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  line-height: 18px;
  box-shadow: 0 6px 18px rgba(146, 64, 14, .22);
  pointer-events: none;
}
.required-field-guidance::before {
  position: absolute;
  bottom: 100%;
  left: 22px;
  border: 6px solid transparent;
  border-bottom-color: #d97706;
  content: '';
}
</style>
