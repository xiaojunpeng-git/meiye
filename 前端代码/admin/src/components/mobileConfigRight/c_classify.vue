<template>
    <div class="slider-box">
        <div class="c_row-item" v-if="configData.title">
            <Col class="label" span="4">
                {{configData.title}}
            </Col>
            <Col span="18">
				<el-cascader
				        @change="sliderChange"
				        placeholder="请选择分类"
				        size="mini"
				        v-model="configData.classVal"
				        :options="treeSelect"
				        :props="props"
				        filterable
				        clearable>
				</el-cascader>
            </Col>
        </div>
    </div>
</template>

<script>
import { cascaderListApi } from '@/api/product';
export default {
  name: 'c_classify',
  props: {
    configObj: {
      type: Object
    },
    configNme: {
      type: String
    },
    number: {
      type: null
    }
  },
  data() {
    return {
      defaults: {},
      configData: {},
      props: { emitPath: false, multiple: true },
      treeSelect: []
    };
  },
  mounted() {
    this.$nextTick(() => {
      this.defaults = this.configObj;
      this.configData = this.configObj[this.configNme];
      this.goodsCategory();
    });
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
  methods: {
    sliderChange() {
      this.$emit('getConfig', { name: 'classlfy' });
    },
    goodsCategory() {
			  cascaderListApi({
        type: 0,
        relation_id: 0
      })
			    .then((res) => {
			      this.treeSelect = res.data;
			    })
			    .catch((res) => {
			      this.$Message.error(res.msg);
			    });
    }
  }
};
</script>

<style scoped lang="stylus">
	.slider-box{
		padding: 0 15px;
		.label{
			color: #999999;
			font-size: 12px;
			width: 75px;
			margin-right: 16px;
		}
	}
	.c_row-item{
	    margin-bottom 20px
	}
	/deep/.el-cascader__search-input{
		margin-left: 8px;
	}
	/deep/.el-cascader{
		width: 100%
	}
</style>
