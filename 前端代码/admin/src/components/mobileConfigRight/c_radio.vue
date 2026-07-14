<template>
    <div class="c_radio" :class="configData.type=='form'?'mb15':'on mb5'" v-if="configData">
        <div class="c_row-item">
            <Col class="c_label" :class="configData.type=='form'?'on':''" span="4">
                {{configData.title}}
            </Col>
            <Col class="color-box" :span="configData.type=='form'?'19':'18'">
                <RadioGroup v-model="configData.tabVal" @on-change="radioChange($event)">
                    <Radio :label="key" v-for="(radio,key) in configData.tabList" :key="key">
                        <span>{{radio.name}}</span>
                    </Radio>
                </RadioGroup>
            </Col>
        </div>
    </div>

</template>

<script>
export default {
  name: 'c_radio',
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
      this.$emit('getConfig', e, 'radio');
    }
  }
};
</script>

<style scoped lang="less">
    .c_radio{
		&.on{
			padding: 0 15px;
			.c_label{
				color: #999999;
				font-size: 12px;
			}
			/deep/.ivu-radio-wrapper{
				margin: 5px 25px 15px 0;
			}
		}
        .c_row-item{
           align-items: unset;
        }
        .c_label{
            color: #000;
            margin-right: 15px;
            margin-top: 4px;
            &.on{
              text-align: right;
              color: #666;
            }
        }
        /deep/.ivu-radio-wrapper{
          margin: 5px 25px 5px 0;
		  &:nth-last-child(1){
			  margin-right: 0;
		  }
        }
        /deep/.ivu-radio{
            margin-right: 6px;
        }
    }
</style>
