<template>
    <div class="c_row-item">
        <Col class="c_label" :class="{on:configData.type=='form',on2:configData.type=='ranges'}" :span="configData.type=='form' || configData.type=='ranges'?'4':''">{{configData.title}}</Col>
        <Col :span="configData.type=='form' || configData.type=='ranges'?'19':''">
            <i-switch v-model="configData.val">
              <span slot="open">是</span>
              <span slot="close">否</span>
            </i-switch>
        </Col>
    </div>
</template>

<script>
export default {
  name: 'c_is_show',
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

  }
};
</script>

<style scoped lang="stylus">
    .c_row-item{
        display flex
        justify-content space-between
        align-items center
        margin-bottom 20px
      .c_label{
        &.on{
          color #666;
          text-align right;
        }
        &.on2{
          text-align left;
          color #666;
        }
      }
    }
</style>
