<template><span aria-hidden="true"></span></template>
<script>
import { mountMoheAi } from '../../../shared/mohe-ai/browser-entry.mjs';
import { browserTransport } from '../../../shared/mohe-ai/browser-transport.mjs';
import Setting from '@/setting';
import util from '@/libs/util';
export default {
  mounted() {
    const apiPrefix = String(Setting.apiBaseURL).replace(/\/+$/, '') + '/ai';
    this.disposeAi = mountMoheAi({ request: browserTransport(apiPrefix, () =>
      (typeof this.__getToken === 'function' ? this.__getToken() : '') || util.cookies.get('token') || ''
    ) });
  },
  beforeDestroy() { if (this.disposeAi) this.disposeAi(); }
};
</script>
