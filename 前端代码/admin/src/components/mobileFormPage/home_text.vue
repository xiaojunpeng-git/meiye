<template>
  <div class="text-box acea-row row-between-wrapper">
    <span class="title">{{titleTxt}}</span>
    <div class="textVal">
      <span v-if="defaultVal">{{defaultVal}}</span>
      <span v-else class="place">{{tipVal}}</span>
    </div>
  </div>
</template>

<script>
import { mapState } from 'vuex';
export default {
  name: 'home_text',
  cname: '文本框',
  icon: 'iconbiaodanzujian-danhangwenben',
  configName: 'c_home_text',
  type: 0, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'texts', // 外面匹配名称
  props: {
    index: {
      type: null
    },
    num: {
      type: null
    }
  },
  computed: {
    ...mapState('admin/mobildConfig', ['defaultArray'])
  },
  watch: {
    pageData: {
      handler(nVal, oVal) {
        this.setConfig(nVal);
      },
      deep: true
    },
    num: {
      handler(nVal, oVal) {
        const data = this.$store.state.admin.mobildConfig.defaultArray[nVal];
        this.setConfig(data);
      },
      deep: true
    },
    'defaultArray': {
      handler(nVal, oVal) {
        const data = this.$store.state.admin.mobildConfig.defaultArray[this.num];
        this.setConfig(data);
      },
      deep: true
    }
  },
  data() {
    return {
      defaultConfig: {
        name: 'texts',
        timestamp: this.num,
        titleConfig: {
          title: '标题',
          value: '文本框',
          place: '请输入标题',
          max: 10,
          type: 'form'
        },
        valConfig: {
          title: '内容',
          tabVal: 0,
          type: 'form',
          tabList: [
            {
              name: '文本'
            },
            {
              name: '手机号'
            },
            {
              name: '身份证号'
            },
            {
              name: '邮箱'
            },
            {
              name: '数字'
            }
          ]
        },
        defaultValConfig: {
          title: '默认值',
          value: '',
          place: '请输入默认值',
          max: 100,
          type: 'form',
          inputType: 'text'
        },
        tipConfig: {
          title: '提示语',
          value: '请填写',
          place: '请输入提示语',
          max: 13,
          type: 'form'
        },
        titleShow: {
          title: '是否必填',
          val: true,
          type: 'form'
        }
      },
      titleTxt: '',
      defaultVal: '',
      tipVal: '',
      pageData: {}
    };
  },
  mounted() {
    this.$nextTick(() => {
      this.pageData = this.$store.state.admin.mobildConfig.defaultArray[this.num];
      this.setConfig(this.pageData);
    });
  },
  methods: {
    setConfig(data) {
      if (!data) return;
      if (data.titleConfig) {
        this.titleTxt = data.titleConfig.value;
        this.defaultVal = data.defaultValConfig.value;
        this.tipVal = data.tipConfig.value;
      }
    }
  }
};
</script>

<style scoped lang="stylus">
.text-box{
  width 100%;
  background #fff;
  padding 11px 10px 11px 12px;
  font-size 15px;
  color #333;
  border-bottom 1px solid #eee;
  .title{
    width 95px;
  }
  .textVal{
    width 250px;
    text-align right;
    .place{
      font-weight: 400;
      color: #CCCCCC;
    }
  }
}
</style>
