<template>
  <div class="staff-module" :style="moduleStyle">
    <div class="staff-module__header">
      <span class="staff-module__title" :style="titleStyle">{{ titleText }}</span>
      <span v-if="showMore" class="staff-module__more" :style="textStyle">查看全部 ›</span>
    </div>
    <div class="staff-module__list" :class="`staff-module__list--${layoutType}`">
      <div
        v-for="item in previewItems"
        :key="item.id"
        class="staff-module__card"
        :style="cardStyle"
      >
        <img class="staff-module__avatar" :src="item.avatar" alt="" />
        <div class="staff-module__info">
          <div class="staff-module__name" :style="titleStyle">{{ item.name }}</div>
          <div v-if="showField(0)" class="staff-module__meta" :style="textStyle">{{ item.position }}</div>
          <div v-if="showField(1)" class="staff-module__level" :style="textStyle">{{ item.level }}</div>
          <div v-if="showField(2)" class="staff-module__intro" :style="textStyle">{{ item.intro }}</div>
        </div>
        <span v-if="showField(3)" class="staff-module__button" :style="buttonStyle">去预约</span>
      </div>
    </div>
  </div>
</template>

<script>
import { mapState } from 'vuex';

export default {
  name: 'home_staff_list',
  cname: '员工展示',
  icon: '#iconzujian-yonghuxinxi',
  configName: 'c_home_staff_list',
  type: 0,
  defaultName: 'staffList',
  props: {
    index: { type: null },
    num: { type: null }
  },
  computed: {
    ...mapState('admin/mobildConfig', ['defaultArray']),
    previewItems() {
      const total = [4, 6, 8][this.numberType] || 4;
      const names = ['林老师', '张老师', '王老师', '李老师', '陈老师', '周老师', '吴老师', '赵老师'];
      const selected = this.selectedPositionIds;
      const configured = this.positionOptions.filter((item) => selected.includes(Number(item.id)));
      const positions = configured.length ? configured : [
        { id: 1, name: '美容师' },
        { id: 2, name: '芳疗师' }
      ];
      return names.slice(0, total).map((name, index) => ({
        id: index,
        name,
        avatar: require('@/assets/images/mobilehead.png'),
        position: positions[index % positions.length].name,
        level: index % 2 ? '高级' : '中级',
        intro: '用心服务每一位顾客'
      }));
    },
    moduleStyle() {
      return {
        background: this.moduleBg,
        marginTop: `${this.marginTop}px`,
        padding: `${this.paddingTop}px ${this.paddingSide}px ${this.paddingBottom}px`
      };
    },
    titleStyle() {
      return { color: this.titleColor };
    },
    textStyle() {
      return { color: this.textColor };
    },
    cardStyle() {
      return { background: this.cardBg, borderRadius: `${this.radius}px` };
    },
    buttonStyle() {
      return { background: this.buttonColor };
    }
  },
  watch: {
    num(nVal) {
      this.setConfig(this.$store.state.admin.mobildConfig.defaultArray[nVal]);
    },
    defaultArray: {
      handler() {
        this.setConfig(this.$store.state.admin.mobildConfig.defaultArray[this.num]);
      },
      deep: true
    }
  },
  data() {
    return {
      defaultConfig: {
        cname: '员工展示',
        name: 'staffList',
        timestamp: this.num,
        isHide: false,
        setUp: { tabVal: 0 },
        titleContent: '展示设置',
        titleStyleGroup: '颜色与间距',
        titleConfig: {
          title: '标题文字',
          value: '明星员工',
          place: '请输入标题',
          max: 10
        },
        moreConfig: {
          title: '查看全部',
          tabVal: 0,
          tabList: [{ name: '显示' }, { name: '隐藏' }]
        },
        styleConfig: {
          title: '展示样式',
          tabVal: 0,
          tabList: [{ name: '横向滑动' }, { name: '双列卡片' }, { name: '列表' }]
        },
        numberConfig: {
          title: '展示人数',
          tabVal: 0,
          tabList: [{ name: '4人' }, { name: '6人' }, { name: '8人' }]
        },
        positionConfig: {
          title: '岗位筛选',
          type: [],
          list: []
        },
        checkboxInfo: {
          title: '展示信息',
          type: [0, 1, 2, 3],
          list: [
            { id: 0, name: '职位' },
            { id: 1, name: '职级' },
            { id: 2, name: '简介' },
            { id: 3, name: '预约按钮' }
          ]
        },
        moduleBgColor: {
          title: '模块背景',
          default: [{ item: '#f7f7f7' }],
          color: [{ item: '#f7f7f7' }]
        },
        cardBgColor: {
          title: '卡片背景',
          default: [{ item: '#ffffff' }],
          color: [{ item: '#ffffff' }]
        },
        titleColor: {
          title: '标题颜色',
          default: [{ item: '#282828' }],
          color: [{ item: '#282828' }]
        },
        textColor: {
          title: '文字颜色',
          default: [{ item: '#666666' }],
          color: [{ item: '#666666' }]
        },
        buttonColor: {
          title: '按钮颜色',
          default: [{ item: '#ff3b8d' }],
          color: [{ item: '#ff3b8d' }]
        },
        fillet: {
          title: '卡片圆角',
          type: 0,
          list: [{ val: '整体' }, { val: '单个' }],
          valName: '圆角值',
          val: 12,
          min: 0,
          valList: [{ val: 12 }, { val: 12 }, { val: 12 }, { val: 12 }]
        },
        topConfig: { title: '上边距', val: 12, min: 0 },
        bottomConfig: { title: '下边距', val: 12, min: 0 },
        prConfig: { title: '左右边距', val: 12, min: 0 },
        mbConfig: { title: '上外边距', val: 0, min: 0 }
      },
      titleText: '明星员工',
      showMore: true,
      layoutType: 0,
      numberType: 0,
      displayFields: [0, 1, 2, 3],
      selectedPositionIds: [],
      positionOptions: [],
      moduleBg: '#f7f7f7',
      cardBg: '#ffffff',
      titleColor: '#282828',
      textColor: '#666666',
      buttonColor: '#ff3b8d',
      radius: 12,
      paddingTop: 12,
      paddingBottom: 12,
      paddingSide: 12,
      marginTop: 0
    };
  },
  mounted() {
    this.$nextTick(() => {
      const data = this.$store.state.admin.mobildConfig.defaultArray[this.num];
      if (!data) {
        this.$store.commit('admin/mobildConfig/UPDATEARR', { num: this.num, val: this.defaultConfig });
      }
      this.setConfig(data || this.defaultConfig);
    });
  },
  methods: {
    color(data, key, fallback) {
      return data[key] && data[key].color && data[key].color[0] ? data[key].color[0].item : fallback;
    },
    showField(id) {
      return this.displayFields.map(Number).includes(Number(id));
    },
    setConfig(data) {
      if (!data) return;
      this.titleText = data.titleConfig ? data.titleConfig.value : '明星员工';
      this.showMore = !data.moreConfig || data.moreConfig.tabVal === 0;
      this.layoutType = data.styleConfig ? Number(data.styleConfig.tabVal) : 0;
      this.numberType = data.numberConfig ? Number(data.numberConfig.tabVal) : 0;
      const positionConfig = data.positionConfig || {};
      this.selectedPositionIds = Array.isArray(positionConfig.type)
        ? positionConfig.type.map(Number).filter((id) => id > 0)
        : [];
      this.positionOptions = Array.isArray(positionConfig.list) ? positionConfig.list : [];
      this.displayFields = data.checkboxInfo ? data.checkboxInfo.type : [0, 1, 2, 3];
      this.moduleBg = this.color(data, 'moduleBgColor', '#f7f7f7');
      this.cardBg = this.color(data, 'cardBgColor', '#ffffff');
      this.titleColor = this.color(data, 'titleColor', '#282828');
      this.textColor = this.color(data, 'textColor', '#666666');
      this.buttonColor = this.color(data, 'buttonColor', '#ff3b8d');
      this.radius = data.fillet ? Number(data.fillet.val) : 12;
      this.paddingTop = data.topConfig ? Number(data.topConfig.val) : 12;
      this.paddingBottom = data.bottomConfig ? Number(data.bottomConfig.val) : 12;
      this.paddingSide = data.prConfig ? Number(data.prConfig.val) : 12;
      this.marginTop = data.mbConfig ? Number(data.mbConfig.val) : 0;
    }
  }
};
</script>

<style scoped lang="less">
.staff-module { box-sizing: border-box; }
.staff-module__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.staff-module__title { font-size: 16px; font-weight: 600; }
.staff-module__more { font-size: 11px; }
.staff-module__list { display: flex; gap: 8px; }
.staff-module__list--0 { overflow: hidden; }
.staff-module__list--1 { flex-wrap: wrap; }
.staff-module__list--2 { flex-direction: column; }
.staff-module__card { position: relative; box-sizing: border-box; padding: 10px; min-width: 142px; display: flex; align-items: center; gap: 8px; box-shadow: 0 3px 12px rgba(0, 0, 0, .04); }
.staff-module__list--1 .staff-module__card { width: calc(50% - 4px); min-width: 0; }
.staff-module__list--2 .staff-module__card { width: 100%; }
.staff-module__avatar { width: 46px; height: 46px; border-radius: 50%; object-fit: cover; flex: none; }
.staff-module__info { flex: 1; min-width: 0; }
.staff-module__name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.staff-module__meta, .staff-module__level, .staff-module__intro { margin-top: 2px; font-size: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.staff-module__button { flex: none; padding: 4px 7px; border-radius: 12px; color: #fff; font-size: 10px; }
.staff-module__list--0 .staff-module__card:nth-child(n+3) { display: none; }
</style>
