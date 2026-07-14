<template>
    <div class="box" :class="configData.type == 'form'?'':'on3'" v-if="configData">
        <div class="c_row-item" :class="{on:configData.type=='form',on2:configData.type=='ranges'}">
            <Col class="label" :span="configData.type=='ranges'?'':4">
                {{configData.title}}
            </Col>
            <Col :span="configData.type=='ranges'?24:configData.type=='form'?19:18" class="slider-box">
                <div v-if="configData.type=='link'">
                  <Input icon="ios-link" v-model="configData.value" :placeholder="configData.place" :maxlength="configData.max" @on-click="getLink(configData)" />
                </div>
				<div @click="getLink(configData)" v-else>
				  <Input v-if="configData.inputType=='text'" :type='(defaults.valConfig.tabVal==1 || defaults.valConfig.tabVal==4)?"number":"text"' :icon="configData.title=='链接'?'ios-arrow-forward':''" :readonly="configData.title=='链接'?true:false" v-model="configData.value" :placeholder="configData.place" :maxlength="configData.max" />
				  <Input v-else :icon="configData.title=='链接'?'ios-arrow-forward':''" :readonly="configData.title=='链接'?true:false" v-model="configData.value" :placeholder="configData.place" :maxlength="configData.max" />
				</div>
            </Col>
        </div>
        <linkaddress ref="linkaddres" @linkUrl="linkUrl" v-if="configData.type!='form' && (configData.title=='链接' || configData.type=='link')"></linkaddress>
    </div>
</template>

<script>
import linkaddress from '@/components/linkaddress';
export default {
  name: 'c_input_item',
  props: {
    configObj: {
      type: Object
    },
    configNme: {
      type: String
    }
  },
  components: {
    linkaddress
  },
  data() {
    return {
      value: '',
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
    linkUrl(e) {
      this.configData.value = e;
    },
    getLink(configData) {
      if (configData.title != '链接' && configData.type != 'link') {
        return;
      }
      this.$refs.linkaddres.modals = true;
    }
  }
};
</script>

<style scoped lang="stylus">
/deep/.ivu-input{
  font-size 13px!important
}
.box{
	&.on3{
		padding: 0 15px;
		/deep/.ivu-input{
			font-size: 12px !important;
		}
		.label{
			font-size: 12px;
			color: #999;
		}
		/deep/.ivu-input-icon{
			color: #BBBBBB;
		}
	}
}
.c_row-item{
  margin-bottom 13px;
  &.on{
    margin-bottom 20px;
    .label{
      text-align right;
      color #666;
    }
  }
  &.on2{
    display block;
    .label{
      margin-bottom 5px;
      color #666;
    }
  }
}
</style>
