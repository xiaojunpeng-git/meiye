<template><view :id="anchor" :class="['required-field-guide', { active }]" @touchstart="clear" @click="clear" @keydown="clear" @input="clear" @change="clear"><slot :focused="active" /><view v-if="active" class="hint" role="status" aria-live="polite">↑ {{ message }}</view></view></template>
<script>
// 只定位第一个缺项，不补值、不提交；H5/小程序共用定位与3.2秒清除规则。
export default { props: { anchor: String }, data: () => ({ active: false, message: '', timer: null }), beforeDestroy() { this.clear(); }, methods: {
  guide(message) { this.clear(); this.message = message; this.active = true; this.$nextTick(() => { uni.createSelectorQuery().in(this).select('#' + this.anchor).boundingClientRect(rect => { if (rect) uni.pageScrollTo({ scrollTop: Math.max(0, rect.top + (this.$parent.pageScrollTop || 0) - 100), duration: 300 }); }).exec();
    // #ifdef H5
    const control = this.$el.querySelector('textarea,input,button,[tabindex]'); if (control) control.focus({ preventScroll: true });
    // #endif
  }); this.timer = setTimeout(this.clear, 3200); },
  clear() { clearTimeout(this.timer); this.active = false; }
} };
</script>
<style scoped>.required-field-guide{position:relative;border-radius:10rpx}.active{outline:2px solid #2674dc;outline-offset:6px}.hint{font-size:26rpx;color:#1752a0;background:#edf5ff;padding:12rpx;margin-top:12rpx}</style>
