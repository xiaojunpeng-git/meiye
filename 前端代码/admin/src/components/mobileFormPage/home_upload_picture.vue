<template>
  <div class="uploadPicture">
    <div class="title">{{titleTxt}}</div>
    <div class="list acea-row row-middle">
      <div class="pictrue" v-for="(item,index) in picList" :key="index">
        <img src="@/assets/images/formImg.png">
      </div>
      <div class="pictrue">
        <img src="@/assets/images/uploadImg.png">
      </div>
    </div>
  </div>
</template>

<script>
import { mapState } from 'vuex';
export default {
  name: 'home_upload_picture',
  cname: '图片',
  icon: 'iconbiaodanzujian-tupian',
  configName: 'c_upload_picture',
  type: 0, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'uploadPicture', // 外面匹配名称
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
        name: 'uploadPicture',
        timestamp: this.num,
        titleConfig: {
          title: '标题',
          value: '上传图片',
          place: '请输入标题',
          max: 20,
          type: 'form'
        },
        numConfig: {
          val: 8,
          title: '最多上传',
          type: 'form'
        },
        titleShow: {
          title: '是否必填',
          val: true,
          type: 'form'
        }
      },
      picList: [],
      titleTxt: '',
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
        const num = data.numConfig.val >= 8 ? 8 : data.numConfig.val;
        this.picList = [];
        for (let i = 0;i < num;i++) {
          this.picList.push(1);
        }
      }
    }
  }
};
</script>

<style scoped lang="less">
.uploadPicture{
  padding: 0 12px 15px 12px;
  border-bottom: 1px solid #eee;
  .title{
    color: #282828;
    font-size: 15px;
    padding-top: 14px;
  }
  .list{
    .pictrue{
      width: 74px;
      height: 74px;
      margin: 10px 10px 0 0;
      img{
        width: 100%;
        height: 100%;
      }
    }
  }
}
</style>
