<template><span aria-hidden="true"></span></template>
<script>
import { mountMoheAi } from '../../../shared/mohe-ai/browser-entry.mjs';
import { browserTransport } from '../../../shared/mohe-ai/browser-transport.mjs';
import moheAiEntryIcon from '@/assets/images/mohe-ai-entry-orbits.gif';
import Setting from '@/setting';
import util from '@/libs/util';
export default {
  mounted() {
    this.syncAiWorkArea = () => {
      const sider = document.querySelector('.i-layout-sider');
      const rect = sider ? sider.getBoundingClientRect() : null;
      // The shell owns the sidebar width.  Read its actual rendered edge so a
      // collapsed or configured sidebar does not leave the AI entry covering
      // navigation, and avoid a second hard-coded layout width here.
      const left = rect && rect.left <= 1 && rect.right > 0 ? Math.round(rect.right) : 0;
      document.documentElement.style.setProperty('--mohe-ai-admin-workarea-left', `${left}px`);
    };
    this.observeAiWorkArea = () => {
      const sider = document.querySelector('.i-layout-sider');
      if (sider === this.aiWorkAreaSider) return;
      if (this.aiWorkAreaObserver) this.aiWorkAreaObserver.disconnect();
      this.aiWorkAreaSider = sider || null;
      if (sider && typeof window.ResizeObserver === 'function') {
        this.aiWorkAreaObserver = new window.ResizeObserver(this.syncAiWorkArea);
        this.aiWorkAreaObserver.observe(sider);
      }
    };
    this.syncAiWorkArea();
    const apiPrefix = String(Setting.apiBaseURL).replace(/\/+$/, '') + '/ai';
    this.disposeAi = mountMoheAi({ request: browserTransport(apiPrefix, () =>
      (typeof this.__getToken === 'function' ? this.__getToken() : '') || util.cookies.get('token') || ''
    ), entryLeft: 'var(--mohe-ai-admin-workarea-left, 0px)', panelLeft: 'calc(var(--mohe-ai-admin-workarea-left, 0px) + 12px)', presentation: 'workspace', entryIconUrl: moheAiEntryIcon });
    window.addEventListener('resize', this.syncAiWorkArea);
    this.observeAiWorkArea();
    // App.vue mounts this entry before an async route has necessarily rendered
    // its layout. Watch only for that layout handoff, then observe the actual
    // sider width; the panel can never rely on its first (possibly zero) read.
    if (typeof window.MutationObserver === 'function') {
      this.aiWorkAreaMutationObserver = new window.MutationObserver(() => {
        this.observeAiWorkArea();
        this.syncAiWorkArea();
      });
      this.aiWorkAreaMutationObserver.observe(document.body, { childList: true, subtree: true });
    }
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.syncAiWorkArea);
    if (this.aiWorkAreaObserver) this.aiWorkAreaObserver.disconnect();
    if (this.aiWorkAreaMutationObserver) this.aiWorkAreaMutationObserver.disconnect();
    if (this.disposeAi) this.disposeAi();
  }
};
</script>
