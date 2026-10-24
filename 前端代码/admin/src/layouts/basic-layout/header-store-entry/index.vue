<template>
  <button class="header-store-entry" type="button" :disabled="opening" @click="enterStore">
    <Icon type="ios-open-outline" />
    <span>进入门店</span>
  </button>
</template>

<script>
import request from '@/plugins/request';

export default {
  name: 'iHeaderStoreEntry',
  data() {
    return { opening: false };
  },
  methods: {
    async enterStore() {
      if (this.opening) return;
      // 必须在用户点击的同步调用栈打开页签，异步签票后再打开会被浏览器拦截。
      const tab = window.open('about:blank', '_blank');
      if (!tab) {
        this.$Message.error('浏览器阻止了新页签，请允许弹出窗口后重试');
        return;
      }
      this.opening = true;
      const devOrigin = String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || '').trim();
      const target = new URL('/view_cashier_v3/#/login?platform_entry=1', devOrigin || window.location.origin);
      let ready = false;
      let ticket = '';
      let sent = false;
      const deliver = () => {
        if (!ready || !ticket || sent || tab.closed) return;
        sent = true;
        // 凭证只经指定新页签的 postMessage 传递，不进入地址栏、历史记录或日志。
        tab.postMessage({ type: 'platform-store-entry-ticket', ticket }, target.origin);
        window.clearTimeout(timeout);
        window.removeEventListener('message', onMessage);
        this.opening = false;
      };
      const onMessage = (event) => {
        if (event.source !== tab || event.origin !== target.origin) return;
        if (event.data && event.data.type === 'platform-store-entry-ready') {
          ready = true;
          deliver();
        }
      };
      window.addEventListener('message', onMessage);
      const timeout = window.setTimeout(() => {
        window.removeEventListener('message', onMessage);
        if (!sent) this.$Message.error('门店页签未能完成连接，请重试');
        this.opening = false;
      }, 20000);
      try {
        tab.location.replace(target.href);
        const result = await request({ url: 'store-entry/ticket', method: 'post' });
        ticket = String(result && result.data && result.data.ticket || '');
        if (!ticket) throw new Error('未取得门店进入凭证');
        deliver();
      } catch (reason) {
        window.clearTimeout(timeout);
        window.removeEventListener('message', onMessage);
        if (!tab.closed) tab.close();
        this.$Message.error(String(reason && (reason.msg || reason.message) || '进入门店失败'));
        this.opening = false;
      }
    }
  }
};
</script>

<style scoped>
.header-store-entry {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 30px;
  margin: 0 10px 0 0;
  padding: 0 10px;
  color: inherit;
  background: rgba(255, 255, 255, .12);
  border: 1px solid currentColor;
  border-radius: 4px;
  cursor: pointer;
  white-space: nowrap;
}
.header-store-entry:hover { background: rgba(255, 255, 255, .22); }
.header-store-entry:disabled { opacity: .6; cursor: wait; }
</style>
