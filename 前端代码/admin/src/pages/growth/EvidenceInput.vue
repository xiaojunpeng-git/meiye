<template>
  <div>
    <input type="file" accept="image/*,.mp4,.webm,.pdf" :disabled="busy" @change="upload" />
    <p v-if="busy">正在处理证据…</p>
    <p v-if="error" class="growth-error">{{ error }}</p>
    <div
      v-for="(id, i) in value"
      :key="id"
      class="growth-actions"
      style="justify-content: flex-start; margin-top: 6px"
    >
      <span>{{ names[id] || '证据 ' + (i + 1) }}</span
      ><button @click="preview(id)">查看</button><button @click="remove(i)">移除</button>
    </div>
  </div>
</template>
<script>
import { growthUpload, growthPreview } from '@/api/growth';
/** 上传先取得可用资源，再把资源引用交给正式业务提交；不接收公开URL。 */
export default {
  name: 'GrowthEvidenceInput',
  props: {
    value: { type: Array, default: () => [] },
    menu: { type: String, default: 'practice' },
    taskId: { type: Number, default: 0 }
  },
  data: () => ({ busy: false, error: '', names: {} }),
  methods: {
    async upload(event) {
      const file = event.target.files[0];
      if (!file) return;
      this.busy = true;
      this.error = '';
      try {
        const r = await growthUpload(file, this.menu);
        if (r.data.state !== 'READY') throw new Error(r.data.error_message || '资源未就绪');
        this.$set(this.names, r.data.id, r.data.original_name);
        this.$emit('input', [...this.value, Number(r.data.id)]);
      } catch (e) {
        this.error = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    remove(i) {
      const list = [...this.value];
      list.splice(i, 1);
      this.$emit('input', list);
    },
    async preview(id) {
      try {
        const url = await growthPreview(id, this.menu, this.taskId);
        window.open(url, '_blank', 'noopener');
        setTimeout(() => URL.revokeObjectURL(url), 60000);
      } catch (e) {
        this.error = e.msg || e.message;
      }
    }
  }
};
</script>
