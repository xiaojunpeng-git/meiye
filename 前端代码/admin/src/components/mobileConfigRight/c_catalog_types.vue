<template>
  <div class="catalog-types acea-row">
    <div class="catalog-types__label">商品类型</div>
    <CheckboxGroup :value="selectedTypes" size="small" @on-change="changeTypes">
      <Checkbox v-for="item in options" :key="item.id" :label="item.id">{{ item.name }}</Checkbox>
    </CheckboxGroup>
  </div>
</template>

<script>
import { diyProductTypeOptions, diyProductTypes } from '@/utils/diyProductTypes';

export default {
  name: 'c_catalog_types',
  props: { configObj: { type: Object, required: true } },
  computed: {
    options() { return diyProductTypeOptions; },
    // Merely opening an old page must not rewrite its saved selection contract.
    selectedTypes() { return diyProductTypes(this.configObj) || this.options.map(item => item.id); }
  },
  methods: {
    changeTypes(types) {
      this.$set(this.configObj, 'catalogTypes', { type: types });
      this.$emit('getConfig', { name: 'catalogTypes' });
    }
  }
};
</script>

<style scoped>
.catalog-types { padding: 0 15px; margin-bottom: 20px; align-items: flex-start; }
.catalog-types__label { width: 96px; flex-shrink: 0; color: #999; font-size: 12px; }
.catalog-types .ivu-checkbox-group { flex: 1; min-width: 0; }
.catalog-types .ivu-checkbox-wrapper { margin-right: 14px; font-size: 12px; }
</style>
