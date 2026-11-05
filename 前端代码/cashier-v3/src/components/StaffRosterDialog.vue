<script setup>
import { ref } from 'vue'
import { useModalFocusTrap } from '@/composables/useModalFocusTrap'
const props = defineProps({ label: String, canClose: Boolean })
const emit = defineEmits(['close'])
const container = ref(null)
// 复用系统弹窗键盘边界；保存结果未确认前不能关闭后另发一笔写入。
useModalFocusTrap({ containerRef: container, canClose: () => props.canClose, onClose: () => emit('close') })
</script>
<template><div class="roster-backdrop"><section ref="container" class="roster-dialog" role="dialog" aria-modal="true" :aria-label="label" tabindex="-1"><slot /></section></div></template>
<style scoped>.roster-backdrop{position:fixed;inset:0;background:#17284166;display:flex;align-items:center;justify-content:center;z-index:1000}.roster-dialog{width:min(520px,calc(100vw - 32px));max-height:90vh;display:flex;flex-direction:column;background:#fff;border-radius:10px;box-shadow:0 12px 60px #142d4944;outline:0}</style>
