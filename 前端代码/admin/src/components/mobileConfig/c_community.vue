<template>
  <div class="mobile-config">
    <div v-for="(item, key) in rCom" :key="key">
      <component
        :is="item.components.name"
        :configObj="configObj"
        ref="childData"
        :configNme="item.configNme"
        :key="key"
        @getConfig="getConfig"
        :index="activeIndex"
        :num="item.num"
      ></component>
    </div>
    <rightBtn :activeIndex="activeIndex" :configObj="configObj"></rightBtn>
  </div>
</template>

<script>
import toolCom from '@/components/mobileConfigRight/index.js';
import rightBtn from '@/components/rightBtn/index.vue';
import { allTopicApi } from '@/api/community';
import { mapMutations } from 'vuex';
export default {
  name: 'c_community',
  componentsName: 'home_community',
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
          components: toolCom.c_set_up,
          configNme: 'setUp'
        }
      ],
      oneContent: [
        {
          components: toolCom.c_title,
          configNme: 'titleLeft'
        },
        {
          components: toolCom.c_radio,
          configNme: 'styleConfig'
        },
        {
          components: toolCom.c_title,
          configNme: 'titleHead'
        },
        {
          components: toolCom.c_radio,
          configNme: 'titleConfig'
        }
      ],
      oneContentImg: [
        {
          components: toolCom.c_upload_img,
          configNme: 'imgConfig'
        }
      ],
      oneContentText: [
        {
          components: toolCom.c_input_item,
          configNme: 'titleTxtConfig'
        }
      ],
      twoContent: [
        {
          components: toolCom.c_input_item,
          configNme: 'rightBntConfig'
        },
        {
          components: toolCom.c_title,
          configNme: 'titleContent'
        },
        {
          components: toolCom.c_slider,
          configNme: 'numberConfig'
        }
      ],
      threeContent: [
        {
          components: toolCom.c_select,
          configNme: 'selectConfig'
        }
      ],
      oneStyle: [
        {
          components: toolCom.c_title,
          configNme: 'titleRight'
        },
        {
          components: toolCom.c_bg_color,
          configNme: 'headerBgColor'
        }
      ],
      twoStyle: [
        {
          components: toolCom.c_radio,
          configNme: 'titleText'
        },
        {
          components: toolCom.c_bg_color,
          configNme: 'titleColor'
        },
        {
          components: toolCom.c_slider,
          configNme: 'titleNumber'
        }
      ],
      threeStyle: [
        {
          components: toolCom.c_bg_color,
          configNme: 'headerBntColor'
        },
        {
          components: toolCom.c_slider,
          configNme: 'bntNumber'
        },
        {
          components: toolCom.c_title,
          configNme: 'titleVideoStyle'
        }
      ],
      videoSpaceStyle: [
        {
          components: toolCom.c_slider,
          configNme: 'videoSpace'
        },
        {
          components: toolCom.c_fillet,
          configNme: 'filletImg'
        }
      ],
      videoSpaceStyle2: [
        {
          components: toolCom.c_slider,
          configNme: 'videoSpace2'
        },
        {
          components: toolCom.c_fillet,
          configNme: 'filletImg'
        }
      ],
      fourStyle: [
        {
          components: toolCom.c_radio,
          configNme: 'toneConfig'
        }
      ],
      fiveStyle: [
        {
          components: toolCom.c_bg_color,
          configNme: 'likeSuccessColor'
        }
      ],
      currencyStyle: [
        {
          components: toolCom.c_title,
          configNme: 'titleCurrency'
        },
        {
          components: toolCom.c_bg_color,
          configNme: 'moduleColor'
        },
        {
          components: toolCom.c_bg_color,
          configNme: 'bottomBgColor'
        },
        {
          components: toolCom.c_slider,
          configNme: 'topConfig'
        },
        {
          components: toolCom.c_slider,
          configNme: 'bottomConfig'
        },
        {
          components: toolCom.c_slider,
          configNme: 'prConfig'
        },
        {
          components: toolCom.c_slider,
          configNme: 'mbConfig'
        },
        {
          components: toolCom.c_fillet,
          configNme: 'fillet'
        }
      ],
      setUp: 0,
      type: 0,
      type2: 0,
      type3: 0
    };
  },
  watch: {
    num(nVal) {
      this.configObj = this.$store.state.admin.mobildConfig.defaultArray[nVal];
    },
    configObj: {
      handler(nVal, oVal) {
        this.$store.commit('admin/mobildConfig/UPDATEARR', {
          num: this.num,
          val: nVal
        });
      },
      deep: true
    },
    'configObj.setUp.tabVal': {
      handler(nVal, oVal) {
        this.setUp = nVal;
        var arr = [this.rCom[0]];
        if (nVal == 0) {
          this.getRComContent(arr, this.type, this.type2);
        } else {
          this.getRComStyle(arr, this.type, this.type2, this.type3);
        }
      },
      deep: true
    },
    'configObj.styleConfig.tabVal': {
      handler(nVal, oVal) {
        this.type = nVal;
        var arr = [this.rCom[0]];
        if (this.setUp == 0) {
          this.getRComContent(arr, nVal, this.type2);
        } else {
          this.getRComStyle(arr, nVal, this.type2, this.type3);
        }
      }
    },
    'configObj.toneConfig.tabVal': {
      handler(nVal, oVal) {
        this.type3 = nVal;
        var arr = [this.rCom[0]];
        if (this.setUp == 0) {
          this.getRComContent(arr, this.type, this.type2);
        } else {
          this.getRComStyle(arr, this.type, this.type2, nVal);
        }
      }
    },
    'configObj.titleConfig.tabVal': {
      handler(nVal, oVal) {
        this.type2 = nVal;
        var arr = [this.rCom[0]];
        if (this.setUp == 0) {
          this.getRComContent(arr, this.type, nVal);
        } else {
          this.getRComStyle(arr, this.type, nVal, this.type3);
        }
      }
    }
  },
  mounted() {
    this.$nextTick(() => {
      const value = JSON.parse(
        JSON.stringify(
          this.$store.state.admin.mobildConfig.defaultArray[this.num]
        )
      );
      this.configObj = value;
      this.allTopicList();
    });
  },
  methods: {
    getRComContent(arr, type, type2) {
      if (type == 0) {
        if (type2 == 0) {
          this.rCom = [
            ...arr,
            ...this.oneContent,
            ...this.oneContentImg,
            ...this.twoContent
          ];
        } else {
          this.rCom = [
            ...arr,
            ...this.oneContent,
            ...this.oneContentText,
            ...this.twoContent
          ];
        }
      } else {
        if (type2 == 0) {
          this.rCom = [
            ...arr,
            ...this.oneContent,
            ...this.oneContentImg,
            ...this.twoContent,
            ...this.threeContent
          ];
        } else {
          this.rCom = [
            ...arr,
            ...this.oneContent,
            ...this.oneContentText,
            ...this.twoContent,
            ...this.threeContent
          ];
        }
      }
    },
    getRComStyle(arr, type, type2, type3) {
      if (type == 1) {
        if (type2 == 0) {
          if (type3 == 0) {
            this.rCom = [
              ...arr,
              ...this.oneStyle,
              ...this.threeStyle,
              ...this.videoSpaceStyle,
              ...this.fourStyle,
              ...this.currencyStyle
            ];
          } else {
            this.rCom = [
              ...arr,
              ...this.oneStyle,
              ...this.threeStyle,
              ...this.videoSpaceStyle,
              ...this.fourStyle,
              ...this.fiveStyle,
              ...this.currencyStyle
            ];
          }
        } else {
          if (type3 == 0) {
            this.rCom = [
              ...arr,
              ...this.oneStyle,
              ...this.twoStyle,
              ...this.threeStyle,
              ...this.videoSpaceStyle,
              ...this.fourStyle,
              ...this.currencyStyle
            ];
          } else {
            this.rCom = [
              ...arr,
              ...this.oneStyle,
              ...this.twoStyle,
              ...this.threeStyle,
              ...this.videoSpaceStyle,
              ...this.fourStyle,
              ...this.fiveStyle,
              ...this.currencyStyle
            ];
          }
        }
      } else {
        if (type2 == 0) {
          this.rCom = [
            ...arr,
            ...this.oneStyle,
            ...this.threeStyle,
            ...this.videoSpaceStyle2,
            ...this.currencyStyle
          ];
        } else {
          this.rCom = [
            ...arr,
            ...this.oneStyle,
            ...this.twoStyle,
            ...this.threeStyle,
            ...this.videoSpaceStyle2,
            ...this.currencyStyle
          ];
        }
      }
    },
    // 话题列表；
    allTopicList() {
      allTopicApi()
        .then((res) => {
          const data = [];
          res.data.map((item) => {
            data.push({ title: item.name, activeValue: item.id.toString() });
          });
          this.configObj.selectConfig.list = data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 获取组件参数
    getConfig(data) {
      if (data.name == 'radio') {
        return;
      }
    },
    handleSubmit(name) {
      const obj = {};
      obj.activeIndex = this.activeIndex;
      obj.data = this.configObj;
      this.add(obj);
    },
    ...mapMutations({
      add: 'admin/mobildConfig/UPDATEARR'
    })
  }
};
</script>

<style scoped>
</style>
