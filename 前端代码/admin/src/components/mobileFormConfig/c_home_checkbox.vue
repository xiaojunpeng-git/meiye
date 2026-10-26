<template>
  <div class="mobile-config">
    <div  v-for="(item,key) in rCom" :key="key">
      <component :is="item.components.name" :configObj="configObj" ref="childData" :configNme="item.configNme" :key="key" @getConfig="getConfig" :index="activeIndex" :num="item.num"></component>
    </div>
    <rightBtn :activeIndex="activeIndex" :configObj="configObj"></rightBtn>
  </div>
</template>

<script>
import toolCom from '@/components/mobileConfigRight/index.js';
import rightBtn from '@/components/rightBtn/index.vue';
import { mapState, mapMutations, mapActions } from 'vuex';
export default {
  name: 'c_home_checkbox',
  componentsName: 'home_checkbox',
  components: {
    ...toolCom,
    rightBtn
  },
  props: {
    activeIndex: {
      type: null
    },
    num: {
      type: null
    },
    index: {
      type: null
    }
  },
  data() {
    return {
      configObj: {},
      rCom: [
        {
          components: toolCom.c_input_item,
          configNme: 'titleConfig'
        },
        {
          components: toolCom.c_select_item,
          configNme: 'wordsConfig'
        },
        {
          components: toolCom.c_is_show,
          configNme: 'titleShow'
        }
      ]
    };
  },
  watch: {
    num(nVal) {
      const value = JSON.parse(JSON.stringify(this.$store.state.admin.mobildConfig.defaultArray[nVal]));
      this.configObj = value;
    },
    configObj: {
      handler(nVal, oVal) {
        this.$store.commit('admin/mobildConfig/UPDATEARR', { num: this.num, val: nVal });
      },
      deep: true
    }
  },
  mounted() {
    this.$nextTick(() => {
      const value = JSON.parse(JSON.stringify(this.$store.state.admin.mobildConfig.defaultArray[this.num]));
      this.configObj = value;
    });
  },
  methods: {
    // 获取组件参数
    getConfig(data) {}
  }
};
</script>

<style scoped lang="stylus">
.title-tips
  padding-bottom 10px
  font-size 14px
  color #333
  span
    margin-right 14px
    color #999
</style>
