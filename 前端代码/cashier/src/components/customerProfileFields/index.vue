<template>
  <div class="customer-profile-fields" v-if="visibleFields.length">
    <div
      v-for="group in groupList"
      :key="group.key"
      class="section"
      v-if="fieldsByGroup(group.key).length"
    >
      <div class="section-hd" v-if="showGroupTitle">{{ group.title }}</div>
      <div class="section-bd">
        <FormItem
          v-for="item in fieldsByGroup(group.key)"
          :key="item.field_key"
          :label="item.info + '：'"
          :required="!!item.required"
        >
          <Input
            v-if="['text', 'id', 'mail', 'phone', 'address'].includes(item.format)"
            v-model="localValues[item.field_key]"
            :placeholder="item.tip || ('请输入' + item.info)"
            :disabled="disabled"
          />
          <Input
            v-else-if="item.format === 'num'"
            v-model="localValues[item.field_key]"
            type="number"
            :placeholder="item.tip || ('请输入' + item.info)"
            :disabled="disabled"
          />
          <DatePicker
            v-else-if="item.format === 'date'"
            :value="localValues[item.field_key]"
            type="date"
            transfer
            :placeholder="item.tip || ('请选择' + item.info)"
            :disabled="disabled"
            style="width: 100%"
            @on-change="(v) => onDate(item.field_key, v)"
          />
          <RadioGroup
            v-else-if="item.format === 'radio'"
            v-model="localValues[item.field_key]"
          >
            <Radio
              v-for="(opt, idx) in item.singlearr || []"
              :key="idx"
              :label="idx"
              :disabled="disabled"
            >{{ opt }}</Radio>
          </RadioGroup>
          <Input
            v-else
            type="textarea"
            :rows="2"
            v-model="localValues[item.field_key]"
            :placeholder="item.tip || ('请输入' + item.info)"
            :disabled="disabled"
          />
        </FormItem>
      </div>
    </div>
  </div>
</template>

<script>
/**
 * 复用「用户设置-基础信息」自定义字段渲染
 * skipParams: 已在外层基础表单维护的内置字段，避免重复
 * RH-P38：已移植 RH-P37 等值短路，避免 v-model 无限回写
 */
export default {
  name: 'CustomerProfileFields',
  props: {
    fields: { type: Array, default: () => [] },
    groups: {
      type: Array,
      default: () => [
        { key: 'basic', title: '基础资料' },
        { key: 'prefer', title: '偏好与需求' },
        { key: 'health', title: '身体及健康情况' },
        { key: 'remark', title: '备注信息' },
      ],
    },
    value: { type: Object, default: () => ({}) },
    skipParams: {
      type: Array,
      default: () => ['real_name', 'sex', 'birthday', 'card_id', 'address', 'addres', 'mark', 'phone'],
    },
    disabled: { type: Boolean, default: false },
    showGroupTitle: { type: Boolean, default: true },
  },
  data() {
    return {
      localValues: {},
    };
  },
  computed: {
    visibleFields() {
      return (this.fields || []).filter((f) => {
        if (!f || !f.use) return false;
        const param = f.param || '';
        if (param && this.skipParams.includes(param)) return false;
        return true;
      });
    },
    groupList() {
      return this.groups && this.groups.length
        ? this.groups
        : [
            { key: 'basic', title: '基础资料' },
            { key: 'prefer', title: '偏好与需求' },
            { key: 'health', title: '身体及健康情况' },
            { key: 'remark', title: '备注信息' },
          ];
    },
  },
  watch: {
    fields: {
      immediate: true,
      handler() {
        this.syncLocal();
      },
    },
    value: {
      deep: true,
      handler() {
        this.syncLocal();
      },
    },
    localValues: {
      deep: true,
      handler(val) {
        // v-model 的父值与本地值相同时不能再次回写，否则会形成：
        // localValues -> input -> value -> syncLocal -> localValues 的更新死循环。
        if (this.isSameValueMap(val, this.value)) return;
        this.$emit('input', { ...val });
        this.$emit('change', { ...val });
      },
    },
  },
  methods: {
    isSameValueMap(left, right) {
      const a = left && typeof left === 'object' ? left : {};
      const b = right && typeof right === 'object' ? right : {};
      const aKeys = Object.keys(a).sort();
      const bKeys = Object.keys(b).sort();
      if (aKeys.length !== bKeys.length) return false;
      for (let i = 0; i < aKeys.length; i++) {
        if (aKeys[i] !== bKeys[i]) return false;
        if (JSON.stringify(a[aKeys[i]]) !== JSON.stringify(b[bKeys[i]])) return false;
      }
      return true;
    },
    fieldsByGroup(key) {
      return this.visibleFields.filter((f) => (f.group || 'prefer') === key);
    },
    syncLocal() {
      const next = { ...(this.value || {}) };
      this.visibleFields.forEach((f) => {
        const k = f.field_key;
        if (next[k] === undefined || next[k] === null) {
          next[k] = f.value !== undefined && f.value !== null ? f.value : '';
        }
      });
      if (!this.isSameValueMap(next, this.localValues)) {
        this.localValues = next;
      }
    },
    onDate(key, v) {
      this.$set(this.localValues, key, v || '');
    },
    /** 供父组件校验必填 */
    validate() {
      for (let i = 0; i < this.visibleFields.length; i++) {
        const f = this.visibleFields[i];
        if (!f.required) continue;
        const v = this.localValues[f.field_key];
        if (v === '' || v === null || v === undefined) {
          this.$Message.warning(f.tip || `请填写${f.info}`);
          return false;
        }
      }
      return true;
    },
    getExtendInfoPayload() {
      const extend_info = {};
      this.visibleFields.forEach((f) => {
        extend_info[f.field_key] = this.localValues[f.field_key];
        if (f.param) extend_info[f.param] = this.localValues[f.field_key];
        if (f.info) extend_info[f.info] = this.localValues[f.field_key];
      });
      return extend_info;
    },
  },
};
</script>

<style scoped lang="stylus">
.section
  margin-bottom 12px
.section-hd
  font-weight 600
  margin-bottom 8px
  color #17233d
</style>
