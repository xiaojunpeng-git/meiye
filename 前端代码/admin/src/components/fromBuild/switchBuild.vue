<template>
    <div>
        <Alert closable v-if="field === 'city_delivery_status'">温馨提示：1、此页面用于管控平台及各门店是否支持启用同城配送相关功能，请谨慎操作。2、开启同城配送后，请务必核实商品重量是否已准确填写，该信息将直接影响配送费用的计算。</Alert>
        <FormItem :label="title" class="input-build" :class="getClassName()">
            <Switch size="large" v-model="valueModel" :true-value="getSwitch(true)" :false-value="getSwitch(false)" @on-change="changeEvent('change',$event)" >
                <span slot="open">{{getSwitch(true,true)}}</span>
                <span slot="close">{{getSwitch(false,true)}}</span>
            </Switch>

            <!-- 说明 -->
            <div v-if="info" class="info-wrapper">{{ info }}
              <Poptip placement="bottom" trigger="hover" :width="exampleSize[field]" :transfer="true" v-if="exampleImage[field]">
                <a>查看示例</a>
                <div class="exampleImg" :class="exampleSize[field] == 364?'on':''" slot="content">
                  <img
                    :src="baseURL+ exampleImage[field]"
                    alt=""
                  />
                </div>
              </Poptip>
            </div>
        </FormItem>
        <template v-for="item in control">
            <template v-if="item.value === valueModel">
                <use-component :validate="validate" :errorsValidate="errorsValidate" @changeValue="changeValue" :rules="item.componentsModel"></use-component>
            </template>
        </template>
    </div>
</template>

<script>
import build from './build';
import components from './index';
import Setting from '@/setting';

export default {
  name: 'switchBuild',
  mixins: [build, components],
  components: {
    useComponent: () => import('./useComponent')
  },
  props: {
    control: {
      type: Array,
      default() {
        return [];
      }
    }
  },
  data() {
    return {
      baseURL: Setting.apiBaseURL.replace(/adminapi/, '')
    };
  },
  created() {
    this.valueModel = this.valueModel === null ? 0 : this.valueModel;
  },
  methods: {
    changeValue(e) {
      this.$emit('changeValue', { field: e.field, value: e.value });
    },
    getSwitch(e, name) {
      let value = null;
      if (!this.options.length) {
        return e ? (name ? '开启' : 1) : (name ? '关闭' : 0);
      }
      this.options.map(item => {
        if (e && item.trueValue !== undefined) {
          value = name ? item.label : item.trueValue;
        } else if (!e && item.falseValue !== undefined) {
          value = name ? item.label : item.falseValue;
        }
      });
      return value;
    }
  }
};
</script>

<style scoped>
    @import url('./css/build.css');
    .exampleImg img {
      width: 204px;
      vertical-align: middle;
    }
</style>
