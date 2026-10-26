<template>
  <div class="text-box acea-row row-between-wrapper">
    <span class="title">{{titleTxt}}</span>
    <div class="textVal">
      <span class="place" v-if="tipVal">{{tipVal}}</span>
      <span class="place on" v-else>{{time}}</span>
      <span class="iconfont iconjinru"></span>
    </div>
  </div>
</template>
<script>
import { formatDate } from '@/utils/validate';
import { mapState } from 'vuex';
export default {
  name: 'home_daterange',
  cname: '日期范围',
  icon: 'iconbiaodanzujian-riqifanwei',
  configName: 'c_home_daterange',
  type: 0, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'dateranges', // 外面匹配名称
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
        name: 'dateranges',
        timestamp: this.num,
        titleConfig: {
          title: '标题',
          value: '日期范围',
          place: '请输入标题',
          max: 10,
          type: 'form'
        },
        valConfig: {
          title: '默认值',
          type: 'daterange',
          specifyDate: '',
          tabVal: 0,
          tabData: 0,
          tabList: [
            {
              name: '显示'
            },
            {
              name: '隐藏'
            }
          ],
          dataList: [
            {
              name: '当前日期'
            },
            {
              name: '指定日期'
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
      pageData: {},
      time: ''
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
        if (data.valConfig.tabVal == 0) {
          if (data.valConfig.tabData == 0) {
            this.tipVal = '';
            const current = formatDate(new Date(Number(new Date().getTime())), 'yyyy/MM/dd');
            this.time = current + ' - ' + current;
          } else {
            if (data.valConfig.specifyDate[0]) {
              const start = data.valConfig.specifyDate[0];
              const end = data.valConfig.specifyDate[1];
              this.tipVal = '';
              this.time = formatDate(new Date(start), 'yyyy/MM/dd') + ' - ' + formatDate(new Date(end), 'yyyy/MM/dd');
            } else {
              this.tipVal = data.tipConfig.value;
            }
          }
        } else {
          this.tipVal = data.tipConfig.value;
        }
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
      &.on{
        color #333;
      }
    }
  }
}
</style>
