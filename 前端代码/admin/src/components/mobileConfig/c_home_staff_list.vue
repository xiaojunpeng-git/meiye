<template>
  <div class="mobile-config">
    <div v-for="(item, key) in rCom" :key="key">
      <component
        :is="item.components.name"
        :configObj="configObj"
        :configNme="item.configNme"
        :index="activeIndex"
        @getConfig="getConfig"
      />
    </div>
    <rightBtn :activeIndex="activeIndex" :configObj="configObj" />
  </div>
</template>

<script>
import toolCom from '@/components/mobileConfigRight/index.js';
import rightBtn from '@/components/rightBtn/index.vue';

export default {
  name: 'c_home_staff_list',
  componentsName: 'home_staff_list',
  components: { ...toolCom, rightBtn },
  props: {
    activeIndex: { type: null },
    num: { type: null },
    index: { type: null }
  },
  data() {
    return {
      configObj: {},
      rCom: [{ components: toolCom.c_set_up, configNme: 'setUp' }],
      contentItems: [
        { components: toolCom.c_title, configNme: 'titleContent' },
        { components: toolCom.c_input_item, configNme: 'titleConfig' },
        { components: toolCom.c_radio, configNme: 'moreConfig' },
        { components: toolCom.c_radio, configNme: 'styleConfig' },
        { components: toolCom.c_radio, configNme: 'numberConfig' },
        { components: toolCom.c_position_multiple, configNme: 'positionConfig' },
        { components: toolCom.c_checkbox, configNme: 'checkboxInfo' }
      ],
      styleItems: [
        { components: toolCom.c_title, configNme: 'titleStyleGroup' },
        { components: toolCom.c_bg_color, configNme: 'moduleBgColor' },
        { components: toolCom.c_bg_color, configNme: 'cardBgColor' },
        { components: toolCom.c_bg_color, configNme: 'titleColor' },
        { components: toolCom.c_bg_color, configNme: 'textColor' },
        { components: toolCom.c_bg_color, configNme: 'buttonColor' },
        { components: toolCom.c_fillet, configNme: 'fillet' },
        { components: toolCom.c_slider, configNme: 'topConfig' },
        { components: toolCom.c_slider, configNme: 'bottomConfig' },
        { components: toolCom.c_slider, configNme: 'prConfig' },
        { components: toolCom.c_slider, configNme: 'mbConfig' }
      ]
    };
  },
  watch: {
    num(nVal) {
      this.configObj = this.normalizedConfig(this.$store.state.admin.mobildConfig.defaultArray[nVal]);
    },
    configObj: {
      handler(nVal) {
        this.$store.commit('admin/mobildConfig/UPDATEARR', { num: this.num, val: nVal });
      },
      deep: true
    },
    'configObj.setUp.tabVal': {
      handler(nVal) {
        const first = this.rCom[0];
        this.rCom = [first].concat(Number(nVal) === 0 ? this.contentItems : this.styleItems);
      },
      deep: true
    }
  },
  mounted() {
    this.$nextTick(() => {
      this.configObj = this.normalizedConfig(this.$store.state.admin.mobildConfig.defaultArray[this.num]);
    });
  },
  methods: {
    normalizedConfig(value) {
      const config = JSON.parse(JSON.stringify(value || {}));
      if (!config.positionConfig) {
        config.positionConfig = { title: '岗位筛选', type: [], list: [] };
      }
      if (!Array.isArray(config.positionConfig.type)) config.positionConfig.type = [];
      if (!Array.isArray(config.positionConfig.list)) config.positionConfig.list = [];
      return config;
    },
    getConfig() {}
  }
};
</script>
