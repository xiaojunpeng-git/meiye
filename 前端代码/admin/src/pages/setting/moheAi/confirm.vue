<template>
  <div v-if="visible" class="confirmation-mask" @keydown.esc.stop.prevent="finish(false)" @keydown.tab="trapFocus">
    <section class="confirmation" role="alertdialog" aria-modal="true" :aria-labelledby="titleId" :aria-describedby="descriptionId">
      <h2 :id="titleId">{{ title }}</h2><p :id="descriptionId">{{ message }}</p>
      <div class="actions"><button ref="cancel" type="button" @click="finish(false)">暂不操作</button><button ref="confirm" type="button" class="primary" @click="finish(true)">确认继续</button></div>
    </section>
  </div>
</template>
<script>
let sequence = 0;
export default {
  name: 'MoheAiConfirmation',
  data() { const id = ++sequence; return { visible: false, title: '', message: '', titleId: 'mohe-confirm-title-' + id, descriptionId: 'mohe-confirm-description-' + id }; },
  beforeDestroy() { this.finish(false); }, deactivated() { this.finish(false); },
  methods: {
    open(message, title = '请确认本次操作') { if (this.resolvePending) return Promise.resolve(false); this.previousFocus = document.activeElement; this.message = message; this.title = title; this.visible = true; this.$nextTick(() => { if (this.$refs.cancel) this.$refs.cancel.focus(); }); return new Promise(resolve => { this.resolvePending = resolve; }); },
    finish(accepted) { const resolve = this.resolvePending; this.resolvePending = null; this.visible = false; if (resolve) resolve(accepted); if (this.previousFocus && typeof this.previousFocus.focus === 'function' && this.previousFocus.isConnected) this.previousFocus.focus(); this.previousFocus = null; },
    trapFocus(event) { const first = this.$refs.cancel; const last = this.$refs.confirm; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } }
  }
};
</script>
<style scoped>
.confirmation-mask{position:fixed;inset:0;z-index:2147483010;background:rgba(25,42,63,.4);display:flex;align-items:center;justify-content:center;padding:20px}.confirmation{width:min(480px,100%);padding:24px;border:1px solid #dce3ed;background:white;border-radius:12px;box-shadow:0 18px 60px #1b2b4b33;color:#283f56}.confirmation h2{font-size:19px;margin:0 0 15px}.confirmation p{font-size:14px;line-height:1.8;white-space:pre-wrap}.actions{display:flex;gap:12px;justify-content:flex-end;margin-top:24px}button{font:inherit;padding:9px 16px;border:1px solid #cbd5e1;border-radius:6px;background:white;cursor:pointer}.primary{background:#4169a1;border-color:#4169a1;color:white}button:focus-visible{outline:3px solid #96bbed;outline-offset:2px}
</style>
