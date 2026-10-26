<template>
    <div>
        <div class="c_row-item" v-if="configData">
            <Col class="c_label" >{{configData.title}}</Col>
            <Col class="color-box">
                <div class="color-item" v-for="(color,key) in configData.color" :key="key">
                    <ColorPicker v-model="color.item" @on-change="changeColor($event,color)" alpha ></ColorPicker>
					<Input class="input" v-model="color.item"/>
					<span @click="resetBgA(color,index,key)">重置</span>
                </div>
				<div class="iconfont iconlianjie" v-if="configData.color.length>1"></div>
            </Col>
        </div>
    </div>

</template>

<script>
const restColor = '';
export default {
  name: 'c_bg_color',
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
      defaults: {
      },
      configData: {},
      bgColor: {
        bgStar: '',
        bgEnd: ''
      },
      oldColor: {
        bgStar: '',
        bgEnd: ''
      },
      index: 0
    };
  },
  created() {
    this.defaults = this.configObj;
    this.configData = this.configObj[this.configNme];
  },
  watch: {
    configObj: {
      handler(nVal, oVal) {
        this.defaults = nVal;
        this.configData = nVal[this.configNme];
      },
      immediate: true,
      deep: true
    }
  },
  methods: {
    changeColor(e, color) {
      if (!e) {
        color.item = 'transparent';
      }
      // this.$emit('getConfig', this.defaults)
    },
    // 重置
    resetBgA(color, index, key) {
      color.item = this.configData.default[key].item;
    }
  }
};
</script>

<style scoped lang="stylus">
 .color-box{
	    position: relative;
		.iconfont{
			position: absolute;
			top:24px;
			left: 24px;
			color: #BBBBBB;
			font-size: 21px;
		}
		.color-item{
			display flex
			align-items center
			margin-left 15px
			&~.color-item{
				margin-top:15px;
			}
			span{
				margin-left 15px
				color #1890FF
				font-size 13px
				cursor pointer
			}
			.input{
				margin-left: 11px;
				width: 192px;
			}
		}
	}
 .c_row-item{
	margin: 0 15px 20px 15px
	align-items: flex-start;
	.c_label{
		font-size: 12px
		margin-top:8px;
	}
 }
 /deep/.ivu-color-picker-color{
	 width: 22px;
	 height: 22px;
 }
 /deep/.ivu-input-icon{
	 width: 35px;
	 height: 35px;
	 line-height: 35px;
	 font-size: 14px;
	 right: -1px;
	 color: #fff;
 }
 /deep/.ivu-input-icon-normal + .ivu-input{
	 padding-right: 4px;
 }

 /deep/.ivu-color-picker-color{
	 top:0;
 }
 /deep/.ivu-input{
	 padding: 4px 5px;
	 border: 1px solid #eee;
 }
</style>
