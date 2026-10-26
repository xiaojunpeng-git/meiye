<template>
    <Card :style="styleModel" :bordered="false" dis-hover class="input-build-card">
        <p slot="title">
            <template v-if="showRechargeTabs">
                <recharge-menu-tabs />
            </template>
            <template v-else>
                <Icon v-if="icon" :type="icon"></Icon>
                {{title}}
            </template>
        </p>
        <Button v-if="!index && guideButton" slot="extra" type="text" custom-icon="iconfont iconpeizhiyindao1" @click="showGuide">配置引导</Button>
        <use-component :validate="validate" :control="control" :errorsValidate="errorsValidate" @changeValue="changeValue" :rules="componentsModel"></use-component>
    </Card>
</template>

<script>
import rechargeMenuTabs from '@/components/rechargeMenuTabs';

export default {
  name: 'cardBuild',
  components: {
    useComponent: () => import('./useComponent'),
    rechargeMenuTabs
  },
  inject: ['type', 'guideButton'],
  computed: {
    showRechargeTabs() {
      return this.type === 'recharge' && !this.index;
    }
  },
  props: {
    styleModel: {
      type: String,
      default: ''
    },
    icon: {
      type: String,
      default: ''
    },
    title: {
      type: String,
      default: ''
    },
    componentsModel: {
      type: Array,
      default() {
        return [];
      }
    },
    validate: {
      type: Object,
      default() {
        return {};
      }
    },
    errorsValidate: {
      type: Array,
      default() {
        return [];
      }
    },
    control: {
      type: Array,
      default() {
        return [];
      }
    },
    index: {
      type: Number,
      default: 0
    }
  },
  date() {
    return {
      submitValue: {}
    };
  },
  mounted() {

  },
  methods: {
    changeValue(e) {
      this.$emit('changeValue', { field: e.field, value: e.value });
    },
    showGuide() {
      this.bus.$emit('settingGuideShow');
    }
  }
};
</script>

<style scoped>
    @import url('./css/build.css');
    .ivu-btn-text {
      color: #2D8CF0;
      font-size: 13px !important;
    }
    .ivu-btn-text:focus {
      box-shadow: none;
    }
</style>
