<template>
  <Modal
    :value="value"
    title="列设置"
    width="480"
    :mask-closable="false"
    @on-cancel="handleClose"
  >
    <div class="column-setting-tip">拖动可调整列顺序；操作列始终显示</div>
    <div class="column-list">
      <div
        v-for="(item, index) in localColumns"
        :key="item.key"
        class="column-item"
        :class="{ disabled: item.fixed }"
        :draggable="!item.fixed"
        @dragstart="onDragStart(index)"
        @dragover.prevent
        @drop="onDrop(index)"
      >
        <span class="drag-handle" v-if="!item.fixed">
          <Icon type="md-menu" />
        </span>
        <span class="drag-placeholder" v-else></span>
        <Checkbox
          v-model="item.show"
          :disabled="item.fixed"
          @on-change="onShowChange(item)"
        >
          {{ getTitle(item.key) }}
        </Checkbox>
      </div>
    </div>
    <div slot="footer">
      <Button @click="restoreDefault">恢复默认</Button>
      <Button @click="handleClose">取消</Button>
      <Button type="primary" class="ml14" @click="handleSave">保存</Button>
    </div>
  </Modal>
</template>

<script>
export default {
  name: 'ProductColumnSetting',
  props: {
    value: {
      type: Boolean,
      default: false,
    },
    columnsMeta: {
      type: Array,
      default: () => [],
    },
    columns: {
      type: Array,
      default: () => [],
    },
    defaultColumns: {
      type: Array,
      default: () => [],
    },
  },
  data() {
    return {
      localColumns: [],
      dragIndex: -1,
    };
  },
  watch: {
    value(val) {
      if (val) {
        this.initLocalColumns();
      }
    },
  },
  methods: {
    getTitle(key) {
      const meta = this.columnsMeta.find((m) => m.key === key);
      return meta ? meta.title : key;
    },
    initLocalColumns() {
      const saved = Array.isArray(this.columns) ? this.columns : [];
      const defaultKeys = this.defaultColumns.length
        ? this.defaultColumns
        : this.columnsMeta.map((m) => ({ key: m.key, show: m.show !== false }));
      const source = saved.length ? saved : defaultKeys;
      const keySet = new Set(source.map((c) => c.key));
      const merged = source.map((item) => {
        const meta = this.columnsMeta.find((m) => m.key === item.key) || {};
        return {
          key: item.key,
          show: meta.fixed ? true : item.show !== false,
          fixed: !!meta.fixed,
        };
      });
      this.columnsMeta.forEach((meta) => {
        if (!keySet.has(meta.key)) {
          merged.push({
            key: meta.key,
            show: meta.fixed ? true : meta.show !== false,
            fixed: !!meta.fixed,
          });
        }
      });
      this.localColumns = merged;
    },
    onShowChange(item) {
      if (item.fixed) {
        item.show = true;
      }
    },
    onDragStart(index) {
      if (this.localColumns[index].fixed) return;
      this.dragIndex = index;
    },
    onDrop(index) {
      if (this.dragIndex < 0 || this.dragIndex === index) return;
      if (this.localColumns[index].fixed) return;
      const list = [...this.localColumns];
      const [moved] = list.splice(this.dragIndex, 1);
      list.splice(index, 0, moved);
      this.localColumns = list;
      this.dragIndex = -1;
    },
    restoreDefault() {
      this.localColumns = this.defaultColumns.map((item) => {
        const meta = this.columnsMeta.find((m) => m.key === item.key) || {};
        return {
          key: item.key,
          show: meta.fixed ? true : item.show !== false,
          fixed: !!meta.fixed,
        };
      });
    },
    handleSave() {
      const payload = this.localColumns.map((item) => ({
        key: item.key,
        show: item.fixed ? true : !!item.show,
      }));
      this.$emit('save', payload);
      this.handleClose();
    },
    handleClose() {
      this.$emit('input', false);
    },
  },
};
</script>

<style scoped lang="stylus">
.column-setting-tip
  font-size 12px
  color #999
  margin-bottom 12px

.column-list
  max-height 420px
  overflow-y auto

.column-item
  display flex
  align-items center
  gap 8px
  padding 8px 4px
  border-bottom 1px solid #f0f0f0
  cursor grab

  &.disabled
    cursor default
    opacity 0.85

.drag-handle
  width 20px
  color #999
  cursor grab

.drag-placeholder
  width 20px

.ml14
  margin-left 14px
</style>
