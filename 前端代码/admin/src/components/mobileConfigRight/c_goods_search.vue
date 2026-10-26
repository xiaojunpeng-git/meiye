<template>
	<!-- 此组件目前没用，留着方便以后开发再用 -->
	<div class="acea-row row-top" style="margin-bottom: 20px" v-if="configData">
		<CheckboxGroup v-model="configData.type" @on-change="checkboxChange($event)">
			<div>
				<Checkbox :label="1">
				    <span>商品分类</span>
				</Checkbox>
				<Cascader
					 :data="configData.list"
					 placeholder="请选择商品分类"
					 change-on-select
					 v-model="configData.activeValue"
					 filterable
					 @on-change="sliderChange"
				></Cascader>
			</div>
			<div>
				<Checkbox :label="2">
				    <span>Twitter2</span>
				</Checkbox>
			</div>
		</CheckboxGroup>
	</div>
</template>

<script>
export default {
  name: 'c_goods_search',
  props: {
    configObj: {
      type: Object
    },
    configNme: {
      type: String
    }
  },
  data() {
    return {
      formData: {
        type: 0
      },
      defaults: {},
      configData: {}
    };
  },
  watch: {
    configObj: {
      handler(nVal, oVal) {
        this.defaults = nVal;
        this.configData = nVal[this.configNme];
      },
      deep: true
    }
  },
  mounted() {
    this.$nextTick(() => {
      this.defaults = this.configObj;
      this.configData = this.configObj[this.configNme];
    });
  },
  methods: {
    checkboxChange(e) {
      console.log('dfdf', e);
      this.$emit('getConfig', e);
    },
    sliderChange(e) {
			  const storage = window.localStorage;
			  this.configData.activeValue = e || storage.getItem(this.timeStamp);
			  this.$emit('getConfig', { name: 'cascader', values: e });
    }
  }
};
</script>

<style>
</style>
