<template>
  <div
    class="community"
    :style="{
      background: bottomBgColor,
      marginTop: mTop + 'px',
      paddingTop: topConfig + 'px',
      paddingBottom: bottomConfig + 'px',
      paddingLeft: prConfig + 'px',
      paddingRight: prConfig + 'px',
    }"
  >
    <div
      class="nav acea-row row-between-wrapper"
      :style="{
        background: `linear-gradient(90deg,${headerBgColorLeft} 0%,${headerBgColorRight} 100%)`,
        borderRadius: bgRadius,
      }"
    >
      <div
        class="title"
        v-if="titleConfig"
        :style="
          (titleTabVal == 2 ? 'fontStyle:' : 'fontWeight:') +
          titleText +
          ';color:' +
          titleColor +
          ';fontSize:' +
          titleNumber +
          'px;'
        "
      >
        {{ titleTxtConfig }}
      </div>
      <img v-else :src="imgUrl" alt="" />
      <div
        class="more"
        :style="{
          color: headerBntColor,
          fontSize: bntNumber + 'px',
        }"
      >
        {{ rightBntConfig
        }}<span
          class="iconfont iconjinru"
          :style="{
            fontSize: bntNumber + 'px',
          }"
        ></span>
      </div>
      <img class="clover" src="@/assets/images/clover.png" />
    </div>
    <div
      v-if="!styleConfig"
      class="list on acea-row row-middle"
      :style="{
        background: bgColor,
        borderRadius: bgRadius2,
      }"
    >
      <div
        class="item"
        :style="{
          marginRight: videoSpace2 + 'px',
        }"
        v-for="(item, index) in videoList"
        :key="index"
      >
        <div class="pictrue">
          <img
            :style="{
              borderRadius: imgRadius,
            }"
            v-if="item.image"
            :src="item.image"
          />
          <div
            v-else
            class="empty-box"
            :style="{
              borderRadius: imgRadius,
            }"
          >
            <img src="../../assets/images/shan.png" />
          </div>
          <div class="mask" :style="{ borderRadius: imgRadius }"></div>
          <div class="bottom">
            <div class="title line1">{{ item.desc }}</div>
            <div class="acea-row row-middle mt5">
              <div class="user">
                <img src="@/assets/images/f.png" />
              </div>
              <div class="name line1">{{ item.type_name }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div
      v-else
      class="list acea-row row-between"
      :style="{
        background: bgColor,
        borderRadius: bgRadius2,
      }"
    >
      <div
        class="item"
        :style="{
          marginBottom: videoSpace + 'px',
        }"
        v-for="(item, index) in videoList"
        :key="index"
      >
        <div class="pictrue">
          <img
            :style="{
              borderRadius: imgRadius,
            }"
            v-if="item.image"
            :src="item.image"
          />
          <div
            v-else
            class="empty-box"
            :style="{
              borderRadius: imgRadius,
            }"
          >
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="info">
          <div class="title">{{ item.desc }}</div>
          <div class="txtPic acea-row row-between-wrapper">
            <div class="acea-row row-middle">
              <div class="user">
                <img src="@/assets/images/f.png" />
              </div>
              <div class="name line1">{{ item.type_name }}</div>
            </div>
            <div class="num">
              <span
                class="iconfont iconic_Like"
                :style="{
                  color: toneConfig
                    ? `${likeSuccessColor}`
                    : `${colorStyle.theme}`,
                }"
              ></span
              >212
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { mapState } from 'vuex';

export default {
  name: 'home_community',
  cname: '种草社区',
  configName: 'c_community',
  icon: '#iconzujian-zhongcaoshequ',
  type: 1, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'community', // 外面匹配名称
  props: {
    index: {
      type: null
    },
    num: {
      type: null
    },
    colorStyle: {
      type: null
    }
  },
  computed: {
    ...mapState('admin/mobildConfig', ['defaultArray'])
  },
  watch: {
    pageData: {
      handler(nVal, oVal) {
        this.setConfig(nVal, 1);
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
    defaultArray: {
      handler(nVal, oVal) {
        const data = this.$store.state.admin.mobildConfig.defaultArray[this.num];
        this.setConfig(data, 1);
      },
      deep: true
    }
  },
  data() {
    return {
      // 默认初始化数据禁止修改
      defaultConfig: {
        cname: '种草社区',
        name: 'community',
        timestamp: this.num,
        isHide: false,
        setUp: {
          tabVal: 0
        },
        titleLeft: '内容设置',
        titleHead: '头部设置',
        titleContent: '内容展示',
        titleRight: '头部样式',
        titleVideoStyle: '内容样式',
        titleCurrency: '通用样式',
        styleConfig: {
          title: '选择风格',
          tabVal: 0,
          tabList: [
            {
              name: '左右滑动展示'
            },
            {
              name: '两列展示（纵向）'
            }
          ]
        },
        titleConfig: {
          title: '标题类型',
          tabVal: 0,
          tabList: [
            {
              name: '图片'
            },
            {
              name: '文字'
            }
          ]
        },
        imgConfig: {
          info: '建议：128px * 32px',
          url: require('@/assets/images/community.png'),
          type: 'code',
          delType: 0,
          name: '上传图片'
        },
        titleTxtConfig: {
          title: '标题文字',
          value: '种草社区',
          place: '请输入标题文字',
          max: 6
        },
        rightBntConfig: {
          title: '右侧文字',
          value: '好物分享',
          place: '请输入右侧文字',
          max: 6
        },
        numberConfig: {
          title: '内容数量',
          val: 3,
          min: 1
        },
        selectConfig: {
          title: '内容话题',
          activeValue: '',
          list: [
            {
              activeValue: '',
              title: ''
            },
            {
              activeValue: '',
              title: ''
            }
          ]
        },
        headerBgColor: {
          title: '背景颜色',
          name: 'headerBgColor',
          default: [
            {
              item: '#E93323'
            },
            {
              item: '#FF7931'
            }
          ],
          color: [
            {
              item: '#E93323'
            },
            {
              item: '#FF7931'
            }
          ]
        },
        titleText: {
          title: '标题文字',
          tabVal: 0,
          tabList: [
            {
              name: '加粗',
              style: 'bold'
            },
            {
              name: '正常',
              style: 'normal'
            },
            {
              name: '倾斜',
              style: 'italic'
            }
          ]
        },
        titleColor: {
          title: '标题颜色',
          name: 'titleColor',
          default: [
            {
              item: '#fff'
            }
          ],
          color: [
            {
              item: '#fff'
            }
          ]
        },
        titleNumber: {
          title: '标题字号',
          val: 16,
          min: 0
        },
        headerBntColor: {
          title: '按钮文字',
          name: 'headerBntColor',
          default: [
            {
              item: '#fff'
            }
          ],
          color: [
            {
              item: '#fff'
            }
          ]
        },
        bntNumber: {
          title: '按钮大小',
          val: 12,
          min: 0
        },
        videoSpace: {
          title: '内容间距',
          val: 20,
          min: 0
        },
        videoSpace2: {
          title: '内容间距',
          val: 10,
          min: 0
        },
        filletImg: {
          title: '内容圆角',
          type: 0,
          list: [
            {
              val: '全部',
              icon: 'iconcaozuo-zhengti'
            },
            {
              val: '单个',
              icon: 'iconcaozuo-bianjiao'
            }
          ],
          valName: '圆角值',
          val: 8,
          min: 0,
          valList: [{ val: 0 }, { val: 0 }, { val: 0 }, { val: 0 }]
        },
        toneConfig: {
          title: '色调',
          tabVal: 0,
          tabList: [
            {
              name: '跟随主题风格'
            },
            {
              name: '自定义'
            }
          ]
        },
        likeSuccessColor: {
          title: '点赞成功',
          default: [
            {
              item: '#E93323'
            }
          ],
          color: [
            {
              item: '#E93323'
            }
          ]
        },
        moduleColor: {
          title: '组件背景',
          default: [
            {
              item: '#fff'
            },
            {
              item: '#fff'
            }
          ],
          color: [
            {
              item: '#fff'
            },
            {
              item: '#fff'
            }
          ]
        },
        bottomBgColor: {
          title: '底部背景',
          default: [
            {
              item: '#f5f5f5'
            }
          ],
          color: [
            {
              item: '#f5f5f5'
            }
          ]
        },
        topConfig: {
          title: '上边距',
          val: 0,
          min: 0
        },
        bottomConfig: {
          title: '下边距',
          val: 0,
          min: 0
        },
        prConfig: {
          title: '左右边距',
          val: 10,
          min: 0
        },
        mbConfig: {
          title: '页面上间距',
          val: 0,
          min: 0
        },
        fillet: {
          title: '背景圆角',
          type: 0,
          list: [
            {
              val: '全部',
              icon: 'iconcaozuo-zhengti'
            },
            {
              val: '单个',
              icon: 'iconcaozuo-bianjiao'
            }
          ],
          valName: '圆角值',
          val: 8,
          min: 0,
          valList: [{ val: 0 }, { val: 0 }, { val: 0 }, { val: 0 }]
        }
      },
      pageData: {},
      videoList: [],
      numberConfig: 0,
      headerBgColorLeft: '',
      headerBgColorRight: '',
      styleConfig: 0,
      titleConfig: 0,
      imgUrl: '',
      headerBntColor: '',
      rightBntConfig: '',
      titleTxtConfig: '',
      numberConfig: 0,
      titleText: 0,
      titleColor: '',
      titleNumber: 0,
      videoSpace: 0,
      videoSpace2: 0,
      imgRadius: 0,
      bgColor: '',
      bgRadius: 0,
      bgRadius2: 0,
      bottomBgColor: '',
      mTop: 0,
      topConfig: 0,
      bottomConfig: 0,
      prConfig: 0,
      bntNumber: 0,
      toneConfig: 0,
      likeSuccessColor: ''
    };
  },
  mounted() {
    this.$nextTick(() => {
      this.pageData =
        this.$store.state.admin.mobildConfig.defaultArray[this.num];
      this.setConfig(this.pageData);
    });
  },
  methods: {
    getVideoList(limit) {
      this.videoList = [
        {
          image: require('@/assets/images/shequ_1.png'),
          like_num: 120,
          type_image: require('@/assets/images/yonghu.png'),
          type_name: '浅笑回眸',
          desc: '啊啊啊啊啊啊啊！！就这条吊带裙绝了！',
          product_num: 3
        },
        {
          image: require('@/assets/images/shequ_2.png'),
          like_num: 120,
          type_image: require('@/assets/images/yonghu.png'),
          type_name: '国宝小熊猫',
          desc: '像我这种梨形身材又不想露腿的 就喜欢这种裙摆...',
          product_num: 3
        },
        {
          image: require('@/assets/images/shequ_4.png'),
          like_num: 120,
          type_image: require('@/assets/images/yonghu.png'),
          type_name: '阿秋',
          desc: '观看视频crmeb更多好礼等你来抢，每天都有哟～ 更多好礼请联…',
          product_num: 3
        }
      ];
    },
    setConfig(data, num) {
      if (!data) return;
      if (data.mbConfig) {
        this.headerBgColorLeft = data.headerBgColor.color[0].item;
        this.headerBgColorRight = data.headerBgColor.color[1].item;
        this.styleConfig = data.styleConfig.tabVal;
        this.titleConfig = data.titleConfig.tabVal;
        this.imgUrl = data.imgConfig.url;
        this.headerBntColor = data.headerBntColor.color[0].item;
        this.rightBntConfig = data.rightBntConfig.value;
        this.titleTxtConfig = data.titleTxtConfig.value;
        this.numberConfig = data.numberConfig.val;
        this.bntNumber = data.bntNumber.val;
        const tabVal = data.titleText.tabVal;
        this.titleTabVal = tabVal;
        this.titleText = data.titleText.tabList[tabVal].style;
        this.titleColor = data.titleColor.color[0].item;
        this.titleNumber = data.titleNumber.val;
        this.videoSpace = data.videoSpace.val;
        this.videoSpace2 = data.videoSpace2.val;
        this.toneConfig = data.toneConfig.tabVal;
        this.likeSuccessColor = data.likeSuccessColor.color[0].item;
        const filletImg = data.filletImg.type;
        const filletValImg = data.filletImg.val;
        const valListImg = data.filletImg.valList;
        this.imgRadius = filletImg
          ? valListImg[0].val +
            'px ' +
            valListImg[1].val +
            'px ' +
            valListImg[3].val +
            'px ' +
            valListImg[2].val +
            'px'
          : filletValImg + 'px';
        const bgColorLeft = data.moduleColor.color[0].item;
        const bgColorRight = data.moduleColor.color[1].item;
        this.bgColor = `linear-gradient(90deg,${bgColorLeft} 0%,${bgColorRight} 100%)`;
        const fillet = data.fillet.type;
        const filletVal = data.fillet.val;
        const valList = data.fillet.valList;
        this.bgRadius = fillet
          ? valList[0].val + 'px ' + valList[1].val + 'px 0 0'
          : filletVal + 'px ' + filletVal + 'px 0 0';
        this.bgRadius2 = fillet
          ? '0 0 ' + valList[3].val + 'px ' + valList[2].val + 'px'
          : '0 0 ' + filletVal + 'px ' + filletVal + 'px';
        this.bottomBgColor = data.bottomBgColor.color[0].item;
        this.mTop = data.mbConfig.val;
        this.topConfig = data.topConfig.val;
        this.bottomConfig = data.bottomConfig.val;
        this.prConfig = data.prConfig.val;
        if (num) {
          this.getVideoList(this.numberConfig);
        }
      }
    }
  }
};
</script>

<style scoped lang="less">
.community {
  .nav {
    width: 100%;
    height: 48px;
    padding: 0 12px;
    position: relative;
    overflow: hidden;

    img {
      width: 64px;
      height: 16px;
      display: block;
    }

    .clover {
      width: 52px;
      height: 54px;
      display: block;
      position: absolute;
      top: 9px;
      right: 91px;
    }

    .title {
      color: #333333;
      font-size: 15px;
    }

    .more {
      font-weight: 400;
      color: #999999;
      font-size: 12px;

      .iconfont {
        font-size: 12px;
      }
    }
  }

  .list {
    padding: 12px 10px;

    &.on {
      flex-wrap: nowrap;
      overflow: hidden;

      .item {
        width: 140px;
        margin-right: 10px;
        margin-bottom: 0;

        .pictrue {
          width: 140px;
          height: 175px;
          border-radius: 8px;

          .bottom {
            color: #fff;
            font-weight: 400;
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 6px 8px;
            z-index: 10;

            .title {
              font-size: 12px;
            }

            .name {
              font-size: 9px;
              width: 100px;
            }

            .user {
              width: 17px;
              height: 17px;
              border-radius: 50%;
              margin-right: 4px;

              img {
                width: 100%;
                height: 100%;
              }
            }
          }

          .mask {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 10;
            background: linear-gradient(
              180deg,
              rgba(0, 0, 0, 0) 0%,
              rgba(0, 0, 0, 0.4) 100%
            );
          }
        }
      }
    }

    .item {
      width: 48%;
      margin-bottom: 20px;

      &:nth-last-child(1) {
        margin-bottom: 0 !important;
      }

      .pictrue {
        width: 100%;
        height: 241px;
        position: relative;
        margin-right: unset;
        margin-bottom: unset;

        img {
          width: 100%;
          height: 100%;
          display: block;
          // object-fit: cover;
        }

        .empty-box {
          background-color: #f3f9ff;

          img {
            width: 65px;
            height: 50px;
            display: block;
          }
        }
      }

      .info {
        font-weight: 400;
        width: 100%;

        .title {
          font-size: 13px;
          color: #333333;
        }

        .num {
          font-size: 10px;
          color: #999999;

          .iconfont {
            margin-right: 5px;
            font-size: 12px;
          }
        }

        .txtPic {
          margin-top: 11px;

          .user {
            width: 16px;
            height: 16px;

            img {
              width: 100%;
              height: 100%;
              border-radius: 50%;
            }
          }

          .name {
            font-size: 10px;
            color: #999999;
            margin-left: 4px;
          }
        }
      }
    }
  }
}
</style>
