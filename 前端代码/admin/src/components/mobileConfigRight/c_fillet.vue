<template>
	<div class="fillets" v-if="configData">
	    <div class="c_row-item">
	        <Col class="c_label">
	            {{configData.title}}
	            <span>{{configData.list[configData.type].val}}</span>
	        </Col>
	        <Col class="color-box">
	            <RadioGroup v-model="configData.type" type="button" @on-change="radioChange($event)">
	                <Radio :label="key" v-for="(radio,key) in configData.list" :key="key">
	                    <span class="iconfont-diy" :class="radio.icon" v-if="radio.icon"></span>
	                    <span v-else>{{radio.val}}</span>
	                </Radio>
	            </RadioGroup>
	        </Col>
	    </div>
		<div class="c_row-item on" v-if="configData.type">
			<div class="c_label">{{configData.valName}}</div>
			<div class="right">
				<Input class="input" :class="index>1?'':'on'" v-model="configData.valList[index].val" v-for="(item,index) in configData.valList" :key="index">
					<template #prefix>
						<span class="iconfont iconzuoshangjiao" v-if="index == 0"></span>
						<span class="iconfont iconyoushangjiao" v-if="index == 1"></span>
						<span class="iconfont iconzuoxiajiao" v-if="index == 2"></span>
						<span class="iconfont iconyouxiajiao" v-if="index == 3"></span>
					</template>
				</Input>
			</div>
		</div>
		<div class="c_row-item" v-else>
		    <Col class="c_label" span="4" v-if="configData.valName">
		        {{configData.valName}}
		    </Col>
		    <Col span="18">
		        <Slider v-model="configData.val" show-input @on-change="sliderChange($event)" :min="configData.min"></Slider>
		    </Col>
		</div>
	</div>
</template>

<script>
export default {
  name: 'c_fillet',
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
      defaults: {},
      configData: {}
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
    radioChange(e) {
      this.$emit('getConfig', { name: 'radio', values: e });
    },
    sliderChange(e) {
    }
  }
};
</script>

<style scoped lang="stylus">
.fillets{
	padding: 0 15px;
}
.txt_tab{
	margin-top 20px
}
.c_row-item{
	margin-bottom 20px
	&.on{
		align-items: flex-start
		.c_label{
			margin-top: 8px;
		}
	}
	.c_label{
		font-size: 12px
		span{
			margin-left: 34px;
		}
	}
	.right{
		width: 204px;
		display: flex;
		justify-content: space-between
		flex-wrap: wrap
		align-items center

		.input{
			width: 95px;
			&.on{
				margin-bottom: 14px
			}
		}
	}
	/deep/.ivu-input-prefix{
		top:7px;
	}
}
.row-item{
	display flex
	justify-content space-between
	align-items center
}
.iconfont{
	font-size 10px
	color: #666666;
}

</style>
