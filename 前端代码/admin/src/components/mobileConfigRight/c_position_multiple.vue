<template>
  <div class="position-multiple">
    <div class="c_row-item">
      <div class="label">{{ configData.title || '岗位筛选' }}</div>
      <div class="select-box">
        <Select
          v-model="configData.type"
          multiple
          filterable
          clearable
          :loading="loading"
          placeholder="不选择表示全部岗位"
        >
          <Option
            v-for="item in configData.list"
            :key="item.id"
            :value="item.id"
          >
            {{ item.name }}
          </Option>
        </Select>
        <div class="tips">可多选；不选择时展示全部可预约员工</div>
      </div>
    </div>
  </div>
</template>

<script>
import { position } from '@/api/staff';

export default {
  name: 'c_position_multiple',
  props: {
    configObj: { type: Object, default: () => ({}) },
    configNme: { type: String, default: 'positionConfig' }
  },
  data() {
    return {
      configData: { title: '岗位筛选', type: [], list: [] },
      loading: false
    };
  },
  watch: {
    configObj: {
      handler(value) {
        this.bindConfig(value);
      },
      immediate: true,
      deep: true
    }
  },
  mounted() {
    this.loadPositions();
  },
  methods: {
    bindConfig(value) {
      if (!value) return;
      if (!value[this.configNme]) {
        this.$set(value, this.configNme, {
          title: '岗位筛选',
          type: [],
          list: []
        });
      }
      const config = value[this.configNme];
      if (!Array.isArray(config.type)) this.$set(config, 'type', []);
      if (!Array.isArray(config.list)) this.$set(config, 'list', []);
      this.configData = config;
    },
    loadPositions() {
      this.loading = true;
      position()
        .then((res) => {
          const list = Array.isArray(res.data) ? res.data : [];
          this.$set(this.configData, 'list', list
            .filter((item) => Number(item.value) > 0 && String(item.label || '').trim())
            .map((item) => ({
              id: Number(item.value),
              name: String(item.label || '').trim()
            })));
          this.configData.type = this.configData.type
            .map(Number)
            .filter((id, index, values) => id > 0 && values.indexOf(id) === index);
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '岗位列表加载失败');
        })
        .finally(() => {
          this.loading = false;
        });
    }
  }
};
</script>

<style scoped lang="less">
.position-multiple {
  padding: 0 15px;
  margin-bottom: 20px;
}
.c_row-item { align-items: flex-start !important; }
.label { flex: 0 0 16.6667%; color: #999; font-size: 12px; line-height: 32px; }
.select-box { flex: 0 0 75%; min-width: 0; }
.tips { margin-top: 6px; color: #999; font-size: 12px; line-height: 18px; }
</style>
