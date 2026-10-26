<template>
    <div class="slider-box">
        <div class="c_row-item">
            <Col class="label" span="4" v-if="configData.title">
                {{configData.title}}
            </Col>
            <Col span="18">
				<div class="labelInput acea-row row-between-wrapper" @click="openStoreLabel">
				  <div style="width: 90%;">
				    <div v-if="configData.list.length">
				      <Tag closable v-for="(item,index) in configData.list" :key="index" @on-close="closeStoreLabel(item)">{{item.label_name}}</Tag>
				    </div>
				    <span class="span" v-else>选择商品标签</span>
				  </div>
				  <div class="iconfont iconxiayi"></div>
				</div>
            </Col>
        </div>
		<!-- 商品标签 -->
		<Modal
		    v-model="storeLabelShow"
		    scrollable
		    title="选择商品标签"
		    :closable="true"
		    width="540"
		    :footer-hide="true"
		    :mask-closable="false"
		>
		  <storeLabelList ref="storeLabel" @activeData="activeStoreData" @close="storeLabelClose"></storeLabelList>
		</Modal>
    </div>
</template>

<script>
import storeLabelList from '@/components/storeLabelList';
export default {
  name: 'c_goods_label',
  components: {
		   storeLabelList
  },
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
      timeStamp: '',
      storeLabelShow: false
    };
  },
  mounted() {
    this.$nextTick(() => {
      this.defaults = this.configObj;
      this.configData = this.configObj[this.configNme];
    });
  },
  watch: {
    configObj: {
      handler(nVal, oVal) {
        this.defaults = nVal;
        this.configData = nVal[this.configNme];
      },
      deep: true
    },
    number(nVal) {
      this.timeStamp = nVal;
    }
  },
  methods: {
    openStoreLabel(row) {
			  this.storeLabelShow = true;
			  this.$refs.storeLabel.storeLabel(JSON.parse(JSON.stringify(this.configData.list)));
    },
    closeStoreLabel(label) {
			  const index = this.configData.list.indexOf(this.configData.list.filter(d => d.id == label.id)[0]);
			  this.configData.list.splice(index, 1);
			  this.getLabelId(this.configData.list);
    },
    activeStoreData(storeDataLabel) {
			  this.storeLabelShow = false;
			  this.configData.list = storeDataLabel;
			  this.getLabelId(storeDataLabel);
    },
    getLabelId(storeDataLabel) {
			  const storeActiveIds = [];
			  storeDataLabel.forEach((item) => {
			    storeActiveIds.push(item.id);
			  });
			  this.configData.activeValue = storeActiveIds;
			  this.$emit('getConfig', { name: 'goodsLabel' });
    },
    // 标签弹窗关闭
    storeLabelClose() {
			  this.storeLabelShow = false;
    }
  }
};
</script>

<style scoped lang="stylus">
	.slider-box{
		padding: 0 15px;
	}
	.c_row-item{
	    margin-bottom 20px
	}
	.label{
		color: #999999;
		font-size: 12px;
	}
	.labelInput{
	  border: 1px solid #dcdee2;
	  width 100%;
	  padding 0 5px;
	  border-radius 5px;
	  min-height 30px;
	  cursor pointer;
	  .span{
	    color: #c5c8ce;
	  }
	  .iconxiayi{
	    font-size 12px
	  }
	}
</style>
