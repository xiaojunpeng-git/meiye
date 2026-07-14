<template>
  <div>
    <FormItem :label="title" class="input-build" :class="getClassName()">
      <div class="input-error-wrapper">
        <CheckboxGroup
          :ref="'checkbox' + field"
          v-model="valueModel"
          @on-change="changeEvent('change', $event)"
        >
          <Checkbox
            v-for="item in options"
            :label="item.value"
            :key="item.value"
            >{{ item.label }}</Checkbox
          >
        </CheckboxGroup>
        <!-- 错误提醒 -->
        <div v-if="errorMessage" class="error-wrapper">{{ errorMessage }}</div>
      </div>
      <!-- 说明 -->
      <div v-if="info" class="info-wrapper">{{ info }}</div>
    </FormItem>
  </div>
</template>

<script>
import build from './build';

export default {
  name: 'checkboxBuild',
  mixins: [build]
};
</script>

<style scoped>
@import url('./css/build.css');
</style>
