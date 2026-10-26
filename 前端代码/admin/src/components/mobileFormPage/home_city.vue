<template>
  <div class="text-box acea-row row-between-wrapper">
    <span class="title">{{titleTxt}}</span>
    <div class="textVal">
      <span class="place">{{tipVal}}</span>
      <span class="iconfont iconjinru"></span>
    </div>
  </div>
</template>
<script>
import { mapState } from 'vuex';
export default {
  name: 'home_city',
  cname: '城市',
  icon: 'iconbiaodanzujian-chengshi',
  configName: 'c_home_city',
  type: 0, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'citys', // 外面匹配名称
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
        name: 'citys',
        timestamp: this.num,
        titleConfig: {
          title: '标题',
          value: '城市',
          place: '请输入标题',
          max: 10,
          type: 'form'
        },
        valConfig: {
          title: '默认值',
          tabVal: 1,
          type: 'form',
          tabList: [
            {
              name: '省市'
            },
            {
              name: '省市区'
            },
            {
              name: '省市区街道'
            }
          ]
        },
        tipConfig: {
          title: '提示语',
          value: '请选择',
          place: '请输入提示语',
          max: 10,
          type: 'form'
        },
        titleShow: {
          title: '是否必填',
          val: true,
          type: 'form'
        }
      },
      titleTxt: '',
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
    .iconfont{
      color #999;
      margin-left 10px;
    }
    .place{
      font-weight: 400;
      color: #CCCCCC;
    }
  }
}
</style>
