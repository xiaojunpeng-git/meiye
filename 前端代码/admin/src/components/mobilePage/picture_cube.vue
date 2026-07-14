<template>
  <div
    class="mobile-page"
    :style="{
      background: bottomBgColor,
      marginTop: mTop + 'px',
      paddingTop: topConfig + 'px',
      paddingBottom: bottomConfig + 'px',
      paddingLeft: prConfig + 'px',
      paddingRight: prConfig + 'px',
    }"
  >
    <div class="advert" :style="{'height': style == 11 ? (379 - prConfig * 2)+'px' : ''}">
      <div class="advert-item-1 flex-1" :style="[grdiGap]" v-if="style === 0">
        <div v-for="(item, index) in picList" :key="index"
        >
          <img class="img" :src="item.image" v-if="item.image" :style="[imgBorderRadius]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
      </div>
      <div class="flex-1 grid-column-2" :style="[colGap]" v-if="style === 1">
        <div v-for="(item, index) in picList" :key="index">
          <img class="img" :src="item.image" v-if="item.image"  :style="[imgStyle,{'object-fit':item.objectFit}]" />
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
      </div>
      <div class="flex-1 grid-column-3" :style="[colGap]" v-if="style === 2">
        <div v-for="(item, index) in picList" :key="index">
          <img class="img" :src="item.image" v-if="item.image" :style="[imgStyle,{'object-fit':item.objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
      </div>
      <div class="advert-item-4 flex-1" :style="[grdiGap]" v-if="style === 3">
        <div class="pic1">
          <img class="img" :src="picList[0].image" v-if="picList[0].image" :style="[imgStyle]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic2">
          <img class="img" :src="picList[1].image" v-if="picList[1].image" :style="[imgStyle,{'object-fit':picList[1].objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic3">
          <img class="img" :src="picList[2].image" v-if="picList[2].image" :style="[imgBorderRadius]"/>
          <div class="empty-box" v-else >
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
      </div>
      <div class="advert-item-5 flex-1" :style="[grdiGap]" v-if="style === 4">
        <div class="pic1">
          <img class="img" :src="picList[0].image" v-if="picList[0].image" :style="[imgBorderRadius]"/>
          <div class="empty-box" v-else >
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic2" :style="[{'height': imageH/2 + imgConfig + 'px'}]">
          <img class="img" :src="picList[1].image" v-if="picList[1].image" :style="[imgStyle]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic3" :style="[{'height': imageH/2 + imgConfig + 'px'}]">
          <img class="img" :src="picList[2].image" v-if="picList[2].image" :style="[imgStyle,{'object-fit':picList[2].objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
      </div>
      <div class="advert-item-6 flex-1" :style="[grdiGap]" v-if="style === 5">
        <div class="pic1">
          <img class="img" :src="picList[0].image" v-if="picList[0].image" :style="[imgBorderRadius,{'height': imageH/2 + imgConfig + 'px'}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic2 h-full">
          <img class="img" :src="picList[1].image" v-if="picList[1].image"
            :style="[imgBorderRadius,{'height': imageH/4 + 'px','object-fit':picList[1].objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic3 h-full">
          <img class="img" :src="picList[2].image" v-if="picList[2].image"
            :style="[imgBorderRadius,{'height': imageH/4 + 'px','object-fit':picList[2].objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
      </div>
      <div class="advert-item-7 flex-1" :style="[grdiGap]" v-if="style === 6">
        <div class="pic1 h-full">
          <img class="img" :src="picList[0].image" v-if="picList[0].image" :style="[imgStyle]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic2 h-full">
          <img class="img" :src="picList[1].image" v-if="picList[1].image" :style="[imgStyle,{'object-fit':picList[1].objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic3">
          <img class="img" :src="picList[2].image" v-if="picList[2].image"
            :style="[imgBorderRadius,{'height': imageH + imgConfig + 'px','object-fit':picList[2].objectFit}]" />
          <div class="empty-box" v-else >
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
      </div>
      <div class="grid-column-2 flex-1" :style="[grdiGap]" v-if="style === 7">
        <div class="w-full" v-for="(item, index) in picList" :key="index">
          <img class="img" :src="item.image" v-if="item.image" :style="[imgStyle,{'object-fit':item.objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
      </div>
      <div class="advert-item-9 flex-1" :style="[grdiGap]" v-if="style === 8">
        <div class="pic1">
          <img class="img" :src="picList[0].image" v-if="picList[0].image" :style="[imgStyle,{'object-fit':picList[0].objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic2">
          <img class="img" :src="picList[1].image" v-if="picList[1].image" :style="[imgStyle,{'object-fit':picList[1].objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic3">
          <img class="img" :src="picList[2].image" v-if="picList[2].image" :style="[imgBorderRadius,{'height': templateH/2 + 'px','object-fit':picList[2].objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic4">
          <img class="img" :src="picList[3].image" v-if="picList[3].image" :style="[imgBorderRadius,{'height': templateH/2 + 'px','object-fit':picList[3].objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic5">
          <img class="img" :src="picList[4].image" v-if="picList[4].image" :style="[imgBorderRadius,{'height': templateH/2 + 'px','object-fit':picList[4].objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
      </div>
      <div class="advert-item-6 flex-1" :style="[grdiGap]" v-if="style === 9">
        <div class="pic1">
          <img class="img" :src="picList[0].image" v-if="picList[0].image" :style="[imgBorderRadius,{'height': imageH/2 + imgConfig * 2 + 'px'}]"/>
          <div class="empty-box" v-else >
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic2">
          <img class="img" :src="picList[1].image" v-if="picList[1].image" :style="[imgBorderRadius,{'height': imageH/4 + 'px','object-fit':picList[1].objectFit}]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
        <div class="pic3 flex-between-center">
          <div class="flex-1 h-full">
            <img class="img" :src="picList[2].image" v-if="picList[2].image" :style="[imgBorderRadius,{'height': imageH/4 + 'px','object-fit':picList[2].objectFit}]"/>
            <div class="empty-box" v-else >
              <img src="../../assets/images/shan.png" />
            </div>
          </div>
          <div class="flex-1 h-full" :style="{'margin-left': imgConfig + 'px'}">
            <img class="img" :src="picList[3].image" v-if="picList[3].image" :style="[imgBorderRadius,{'height': imageH/4 + 'px','object-fit':picList[3].objectFit}]"/>
            <div class="empty-box" v-else>
              <img src="../../assets/images/shan.png" />
            </div>
          </div>
        </div>
      </div>
      <template v-if="style === 10">
        <div class="w-full">
          <img class="img" :src="picList[0].image" v-if="picList[0].image" :style="[imgBorderRadius]"/>
          <div class="empty-box" v-else>
            <img src="../../assets/images/shan.png" />
          </div>
        </div>
      </template>
	  <template v-if="style === 11">
	    <div
	      class="advertItem11 acea-row"
	      v-for="(item, index) in docPicList"
	      :key="index"
	      :style="{
	        border: imgConfig + 'px solid ' + bottomBgColor,
	        width: (item.doc.w/375)*100 + '%',
	        height: (item.doc.h/375)*100 + '%',
	        left: (item.doc.startX/375)*100 + '%',
	        top: (item.doc.startY/375)*100 + '%',
	      }"
	    >
	      <img
	        class="img"
	        :src="item.img"
	        v-if="item.img"
	        :style="{ borderRadius: imgRadius }"
	      />
	      <div class="empty-box" v-else :style="{ borderRadius: imgRadius }">
	        <img src="../../assets/images/shan.png" />
	      </div>
	    </div>
	  </template>
    </div>
  </div>
</template>

<script>
import { mapState } from 'vuex';
export default {
  name: 'picture_cube',
  cname: '图片魔方',
  configName: 'c_picture_cube',
  icon: '#iconzujian-tupianmofang',
  type: 0, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'pictureCube', // 外面匹配名称
  props: {
    index: {
      type: null
    },
    num: {
      type: null
    }
  },
  computed: {
    ...mapState('admin/mobildConfig', ['defaultArray']),
    colGap() {
      return {
        'grid-column-gap': `${this.imgConfig}px`
      };
    },
    grdiGap() {
      return {
        'grid-gap': `${this.imgConfig}px`
      };
    },
    imgBorderRadius() {
      return {
        'border-radius': this.imgRadius
      };
    },
    imgStyle() {
      return {
        'border-radius': this.imgRadius,
        'height': this.imageH / 2 + 'px'
      };
    }
  },
  watch: {
    pageData: {
      handler(nVal) {
        this.setConfig(nVal);
      },
      deep: true
    },
    num: {
	  handler(nVal) {
	    const data = this.$store.state.admin.mobildConfig.defaultArray[nVal];
	    this.setConfig(data);
	  },
	  deep: true
    },
    defaultArray: {
      handler() {
        const data = this.$store.state.admin.mobildConfig.defaultArray[this.num];
        this.setConfig(data);
      },
      deep: true
    }
  },
  data() {
    return {
      // 默认初始化数据禁止修改
      defaultConfig: {
        cname: '图片魔方',
        name: 'pictureCube',
        timestamp: this.num,
        isHide: false,
        setUp: {
          tabVal: 0
        },
        titleLeft: '展示设置',
        titleShow: '展示设置',
        titleContent: '内容设置',
        titleRight: '图片魔方',
        titleCurrency: '通用样式',
        styleConfig: {
          title: '选择风格',
          tabVal: 0,
          count: 2,
          type: 'pictureCube'
        },
        picStyle: {
          tabVal: 0,
          picList: [],
          docPicList: []
        },

        menuConfig: {
          title: '',
          maxList: 1,
          isCube: 1,
          list: [
            {
              img: '',
              showFit: true,
              objectFit: 'cover',
              info: [
                {
                  title: '链接',
                  tips: '请输入链接',
                  value: '',
                  max: 100
                }
              ]
            }
          ]
        },
        imgConfig: {
          title: '图片间距',
          val: 0,
          min: 0
        },
        filletImg: {
          title: '图片圆角',
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
          val: 0,
          min: 0,
          valList: [{ val: 0 }, { val: 0 }, { val: 0 }, { val: 0 }]
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
          val: 0,
          min: 0
        },
        mbConfig: {
          title: '页面上间距',
          val: 0,
          min: 0
        }
      },
      pageData: {},
      style: 0,
      picList: [],
      docPicList: [],
      bottomBgColor: [],
      mTop: 0,
      topConfig: 0,
      bottomConfig: 0,
      prConfig: 0,
      imgRadius: 0,
      imgConfig: 0,
      imageH: 0,
      templateH: 0
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
      if (data.mbConfig) {
        this.style = data.styleConfig.tabVal;
        this.bottomBgColor = data.bottomBgColor.color[0].item;
        this.prConfig = data.prConfig.val;
        this.mTop = data.mbConfig.val;
        this.topConfig = data.topConfig.val;
        this.bottomConfig = data.bottomConfig.val;
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
        this.imgConfig = data.imgConfig.val;
        if (data.styleConfig.tabVal !== 11) {
          if (!data.picStyle.picList.length) {
            this.picList = [{ image: '', link: '', objectFit: 'cover' }];
          } else {
            this.picList = data.picStyle.picList;
          }
        } else {
          data.picStyle.docPicList.map((e, i) => {
            data.picStyle.docPicList[i].img = data.picStyle.picList[i].image;
            data.picStyle.docPicList[i].link = data.picStyle.picList[i].link;
          });
          this.$set(this, 'docPicList', data.picStyle.docPicList);
        }
        this.computedHeight();
      }
    },
    basePicHeight(index) {
      const that = this;
      const windowWidth = 375;
      const prConfig = this.prConfig;
      const img = new Image();
      img.src = that.picList[index].image;
      img.onload = function() {
        let height = 0;
        if (that.style == 2) {
          height = img.height * ((250 - prConfig) / img.width);
        } else {
          height = img.height * ((windowWidth - prConfig) / img.width);
        }
        that.$set(that, 'imageH', height);
      };
    },
    computedHeight() {
      if (this.picList.length) {
        this.$nextTick((e) => {
          if (this.style == 4) {
            this.basePicHeight(1);
          } else if (this.style == 8) {
            this.basePicHeight(0);
            this.computeTemplateEight();
          } else {
            this.basePicHeight(0);
          }
        });
      }
    },
    computeTemplateEight() {
      const that = this;
      const prConfig = this.prConfig;
      const img = new Image();
      img.src = that.picList[2].image;
      img.onload = function() {
        const height = img.height * ((250 - prConfig) / img.width);
        that.$set(that, 'templateH', height);
      };
    }
  }
};
</script>
<style scoped lang="less">
.empty-box {
  background: #f3f9ff;
  min-height: 140px;
  img {
    width: 65px;
    height: 50px;
  }

}
.advertItem11 {
	.empty-box{
		min-height: unset;
	}
}

.img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.advert {
  display: flex;
  // background-color: #fff;
}
.advert-item-1{
  display: grid;
	grid-template-columns: 1fr; /* 一列 */
	grid-template-rows: auto auto; /* 两行 */
}
.advert-item-4 {
  display: grid;
  grid-template-rows: auto auto; /* 上面两行，下面一行 */
  grid-template-columns: 1fr 1fr; /* 上面两列等分 */
  .pic1{
    grid-row-start: 1;
    grid-row-end: 2;
    grid-column-start: 1;
    grid-column-end: 2;
  }
  .pic2{
    grid-row-start: 1;
    grid-row-end: 2;
    grid-column-start: 2;
    grid-column-end: 3;
  }
  .pic3{
    grid-row-start: 2;
    grid-row-end: 3;
    grid-column-start: 1;
    grid-column-end: 3;
  }
}

.advert-item-5 {
  display: grid;
  grid-template-rows: auto auto; /* 上面两行，下面一行 */
  grid-template-columns: 1fr 1fr; /* 上面两列等分 */
  .pic1{
    grid-row-start: 1;
    grid-row-end: 2;
    grid-column-start: 1;
    grid-column-end: 3;
  }
  .pic2{
    grid-row-start: 2;
    grid-row-end: 3;
    grid-column-start: 1;
    grid-column-end: 2;
  }
  .pic3{
    grid-row-start: 2;
    grid-row-end: 3;
    grid-column-start: 2;
    grid-column-end: 3;
  }
}

.advert-item-6 {
  display: grid;
  grid-template-columns: 1fr 1fr; /* 左右两列 */
  grid-template-rows: auto; /* 自动行高 */
  align-items: stretch; /* 确保所有元素的高度一致 */
  .pic1{
    grid-column-start: 1;
    grid-column-end: 2;
    grid-row-start: 1;
    grid-row-end: 3;
  }
  .pic2{
    grid-column-start: 2;
    grid-column-end: 3;
    grid-row-start: 1;
    grid-row-end: 2;
  }
  .pic3{
    grid-column-start: 2;
    grid-column-end: 3;
    grid-row-start: 2;
    grid-row-end: 3;
  }
}

.advert-item-7 {
  display: grid;
  grid-template-columns: 1fr 1fr; /* 左右两列 */
  grid-template-rows: auto; /* 自动行高 */
  align-items: stretch; /* 确保所有元素的高度一致 */
  .pic1{
    grid-column-start: 1;
    grid-column-end: 2;
    grid-row-start: 1;
    grid-row-end: 2;
  }
  .pic2{
    grid-column-start: 1;
    grid-column-end: 2;
    grid-row-start: 2;
    grid-row-end: 3;
  }
  .pic3{
    grid-column-start: 2;
    grid-column-end: 3;
    grid-row-start: 1;
    grid-row-end: 3;
  }
}

.advert-item-9 {
  display: grid;
  grid-template-columns: repeat(6, 1fr); /* 6 列 */
  grid-template-rows: auto auto; /* 自动行高 */
  .pic1{
    grid-column-start: 1;
    grid-column-end: 4;
    grid-row-start: 1;
    grid-row-end: 2;
  }
  .pic2{
    grid-column-start: 4;
    grid-column-end: 7;
    grid-row-start: 1;
    grid-row-end: 2;
  }
  .pic3{
    grid-column-start: 1;
    grid-column-end: 3;
    grid-row-start: 2;
    grid-row-end: 3;
  }
  .pic4{
    grid-column-start: 3;
    grid-column-end: 5;
    grid-row-start: 2;
    grid-row-end: 3;
  }
  .pic5{
    grid-column-start: 5;
    grid-column-end: 7;
    grid-row-start: 2;
    grid-row-end: 3;
  }
}

.mobile-page {
  .advert {
    position: relative;

    .advertItem11 {
      position: absolute;
    }
  }
}
</style>
