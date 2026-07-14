<template>
  <div>
    <div class="seckill-box" :style="[boxStyle]">
      <div class="hd" :style="[headerStyle]">
        <div class="left acea-row row-middle">
		  <div class="text" v-if="titleConfig" :style="(titleTabVal==2?'fontStyle:':'fontWeight:') + titleText+';color:'+titleColor+';fontSize:'+titleNumber+'px;'">{{titleTxtConfig}}</div>
          <img v-else :src="styleConfig?imgUrl:imgColorUrl" alt="" />
        </div>
        <div class="right" :style="{
			color:styleConfig?headerBntColor:headerBntColor2,
			fontSize:bntNumber+'px'
		}">
          {{rightBntTxt}}
		  <span class="iconfont iconjinru" :style="{
			 fontSize:bntNumber+'px'
		  }"></span>
        </div>
      </div>
	  <div class="bg--w111-fff pt-10" :style="[boxContentStyle]" v-if="storeStyleConfig==0">
		  <div class="store overflow" :style="[storeSpacing]" v-for="item in numberConfig">
			  <div class="acea-row ml-12">
				  <div class="w-52 h-52 mr-10 bg-w111-F3F9FF acea-row row-center-wrapper" :style="[bgRadiusStore]">
				  	<img class="w-30 h-23 block" src="../../assets/images/shan.png"/>
				  </div>
				  <div class="picBox">
					  <div class="fs-15 text--w111-333 acea-row row-middle">
						  <span :style="[storeNameStyle]">小米之家旗舰店</span>
						  <div class="acea-row row-middle" v-if="checkboxInfo.includes(0)">
							  <div class="w-26 h-13 rd-2px acea-row row-center-wrapper fs-9 ml-6" :style="[deliveryStyle]">快递</div>
							  <div class="w-26 h-13 rd-2px acea-row row-center-wrapper fs-9 ml-4" :style="[cityDeliveryStyle]">闪送</div>
							  <div class="w-26 h-13 rd-2px acea-row row-center-wrapper fs-9 ml-4" :style="[storeStyle]">到店</div>
						  </div>
					  </div>
					  <div class="fs-11 text-wlll-909399" v-if="checkboxInfo.includes(1)">营业时间：10:00～23:00</div>
					  <div class="fs-11 text-wlll-909399">
						  <span class="iconfont icondingwei fs-12 mr-2 text-wlll-F7BA1E" v-if="checkboxInfo.includes(2) || checkboxInfo.includes(3)"></span>
						  <span v-if="checkboxInfo.includes(2)">距您12.8km</span>
						  <span class="ml-4 mr-4" v-if="checkboxInfo.includes(2) && checkboxInfo.includes(3)">|</span>
						  <span v-if="checkboxInfo.includes(3)">西安市莲湖区子午大道万达广场B2</span>
					  </div>
				  </div>
			  </div>
			  <div class="mt-9 flex w-full overflow ml-12">
				  <div class="mr-6" v-for="(j, index) in numberGoodsConfig">
					  <div class="w-72 h-72 bg-w111-F3F9FF acea-row row-center-wrapper" :style="[bgRadiusImg]">
					  	<img class="w-35 h-27 block" src="../../assets/images/shan.png"/>
					  </div>
					  <div class="fs-11 mt-2 text--w111-333" :style="[goodsNameStyle]" v-if="checkboxGoodsInfo.includes(0)">商品名称商...</div>
					  <div class="fs-14 SemiBold" :style="[goodsPrice]" v-if="checkboxGoodsInfo.includes(1)"><span class="fs-11">¥</span>199</div>
				  </div>
			  </div>
		  </div>
	  </div>
	  <div class="bg--w111-fff pt-12" :style="[boxContentStyle]" v-else-if="storeStyleConfig==1">
		  <div class="store pl-12 pr-12" :style="[storeSpacing]" v-for="item in numberConfig">
			  <div class="w-full h-139 bg-w111-F3F9FF acea-row row-center-wrapper" :style="[bgRadiusStore]">
				  <img class="w-65 h-50 block" src="../../assets/images/shan.png"/>
			  </div>
			  <div class="fs-15 text--w111-333 mt-8 acea-row row-middle">
				  <span :style="[storeNameStyle]">小米之家旗舰店</span>
				  <div class="acea-row row-middle" v-if="checkboxInfo.includes(0)">
					  <div class="w-26 h-13 rd-2px acea-row row-center-wrapper fs-9 ml-6" :style="[deliveryStyle]">快递</div>
					  <div class="w-26 h-13 rd-2px acea-row row-center-wrapper fs-9 ml-4" :style="[cityDeliveryStyle]">闪送</div>
					  <div class="w-26 h-13 rd-2px acea-row row-center-wrapper fs-9 ml-4" :style="[storeStyle]">到店</div>
				  </div>
			  </div>
			  <div class="fs-11 text-wlll-909399">
				  <span class="iconfont icondingwei fs-12 mr-2" v-if="checkboxInfo.includes(2) || checkboxInfo.includes(3)"></span>
				  <span v-if="checkboxInfo.includes(2)">距您12.8km</span>
				  <span class="ml-4 mr-4" v-if="checkboxInfo.includes(2) && checkboxInfo.includes(3)">|</span>
				  <span v-if="checkboxInfo.includes(3)">西安市莲湖区子午大道万达广场B2</span>
			  </div>
		  </div>
	  </div>
	  <div class="bg--w111-fff pt-10" :style="[boxContentStyle]" v-else>
		  <div class="store acea-row row-middle ml-10 mr-10" :style="[storeSpacing]" v-for="item in numberConfig">
			  <div class="w-68 h-68 mr-8 bg-w111-F3F9FF acea-row row-center-wrapper" :style="[bgRadiusStore]">
				  <img class="w-35 h-27 block" src="../../assets/images/shan.png"/>
			  </div>
			  <div class="flex-1">
				  <div class="fs-15 text--w111-333 fw-500 acea-row row-middle">
					  <span :style="[storeNameStyle]">小米之家旗舰店</span>
					  <div class="acea-row row-middle" v-if="checkboxInfo.includes(0)">
						  <div class="w-26 h-13 rd-2px acea-row row-center-wrapper fs-9 ml-6" :style="[deliveryStyle]">快递</div>
						  <div class="w-26 h-13 rd-2px acea-row row-center-wrapper fs-9 ml-4" :style="[cityDeliveryStyle]">闪送</div>
						  <div class="w-26 h-13 rd-2px acea-row row-center-wrapper fs-9 ml-4" :style="[storeStyle]">到店</div>
					  </div>
				  </div>
				  <div class="fs-10 text--w111-666" v-if="checkboxInfo.includes(1)">营业时间：10:00～23:00</div>
				  <div class="fs-11 text--w111-666" v-if="checkboxInfo.includes(3)">西安市莲湖区子午大道尚林路万达广场B2</div>
				  <div class="fs-11 text-wlll-909399" v-if="checkboxInfo.includes(2)"><span class="iconfont icondingwei fs-12 mr-2 text-wlll-F7BA1E"></span>距离当前12.8km</div>
			  </div>
		  </div>
	  </div>
    </div>
  </div>
</template>

<script>
import { mapState } from 'vuex';
// import theme from "@/mixins/theme";
import Setting from '@/setting';
export default {
  name: 'home_store_list',
  cname: '门店列表',
  icon: '#icona-zu10158',
  configName: 'c_home_store_list',
  type: 0, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'storeList', // 外面匹配名称
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
    ...mapState('admin/mobildConfig', ['defaultArray']),
    deliveryStyle() {
      return {
        background: this.deliveryBg,
        color: this.deliveryText
      };
    },
    cityDeliveryStyle() {
      return {
        background: this.cityDeliveryBg,
        color: this.cityDeliveryText
      };
    },
    storeStyle() {
      return {
        background: this.storeBg,
        color: this.storeText
      };
    },
    storeSpacing() {
      return {
        paddingBottom: this.storeNumber + 'px'
      };
    },
    storeNameStyle() {
      return {
        color: this.storeNameColor,
        fontWeight: this.storeName.tabVal ? 'normal' : 'bold'
      };
    },
    goodsNameStyle() {
      return {
        color: this.goodsNameColor,
        fontWeight: this.goodsName.tabVal ? 'normal' : 'bold'
      };
    },
    goodsPrice() {
      return {
        color: this.toneConfig ? this.goodsPriceColor : this.colorStyle.theme
      };
    },
    bgRadiusStore() {
      let borderRadius = `${this.filletStoreImg.val}px`;
      if (this.filletStoreImg.type) {
				  borderRadius =
				      `${this.filletStoreImg.valList[0].val}px ${this.filletStoreImg.valList[1].val}px ${this.filletStoreImg.valList[3].val}px ${this.filletStoreImg.valList[2].val}px`;
      }
      return {
        borderRadius: borderRadius
      };
    },
    bgRadiusImg() {
      let borderRadius = `${this.filletImg.val}px`;
      if (this.filletImg.type) {
				  borderRadius =
				      `${this.filletImg.valList[0].val}px ${this.filletImg.valList[1].val}px ${this.filletImg.valList[3].val}px ${this.filletImg.valList[2].val}px`;
      }
      return {
        borderRadius: borderRadius
      };
    },
    boxStyle() {
      return {
        background: this.bottomBgColor,
        marginTop: this.mTop + 'px',
        padding: this.topConfig + 'px ' + this.prConfig + 'px ' + this.bottomConfig + 'px ' + this.prConfig + 'px'
      };
    },
    headerStyle() {
      let borderRadius = `${this.fillet.val}px ${this.fillet.val}px 0 0`;
      if (this.fillet.type) {
				  borderRadius =
				      `${this.fillet.valList[0].val}px ${this.fillet.valList[1].val}px 0 0`;
      }
      return {
        borderRadius: borderRadius,
        backgroundImage: this.styleConfig ? 'url(' + this.imgBgUrl + ')' : `linear-gradient(90deg,${this.headerBgColorLeft} 0%,${this.headerBgColorRight} 100%)`
      };
    },
    boxContentStyle() {
      let borderRadius = `0 0 ${this.fillet.val}px ${this.fillet.val}px`;
      if (this.fillet.type) {
				  borderRadius =
				      `0 0 ${this.fillet.valList[3].val}px ${this.fillet.valList[2].val}px`;
      }
      return {
        borderRadius: borderRadius,
        background: `linear-gradient(90deg, ${this.moduleColor[0].item} 0%, ${this.moduleColor[1].item} 100%)`
      };
    }
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
  // mixins: [theme],
  data() {
    return {
      // 默认初始化数据禁止修改
      defaultConfig: {
        cname: '门店列表',
        name: 'storeList',
        timestamp: this.num,
        isHide: false,
        setUp: {
          tabVal: 0
        },
        titleLeft: '头部设置',
        titleStoreList: '展示风格',
        titleStore: '门店设置',
        titleGoods: '商品设置',
        titleRight: '头部样式',
        titleGoodsStyle: '商品样式',
        titleStoreStyle: '门店样式',
        titleCurrency: '通用样式',
        styleConfig: {
          title: '选择风格',
          tabVal: 1,
          tabList: [
            {
							  name: '背景色'
            },
						    {
						      name: '背景图片'
						    }
          ]
        },
        imgBgConfig: {
          info: '建议：710px * 96px',
          url: Setting.apiBaseURL.replace(/adminapi/, '') + 'statics/images/storeBg.png',
          type: 'code',
          delType: 0,
          name: '背景图片'
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
          info: '建议：140px * 32px',
          url: require('@/assets/images/store02.png'),
          type: 'code',
          delType: 0,
          name: '标题图片'
        },
        imgColorConfig: {
          info: '建议：140px * 32px',
          url: require('@/assets/images/store01.png'),
          type: 'code',
          delType: 0,
          name: '标题图片'
        },
        titleTxtConfig: {
          title: '标题文字',
          value: '推荐门店',
          place: '请输入标题文字',
          max: 6
        },
        rightBntConfig: {
          title: '右侧按钮',
          value: '更多',
          place: '请输入右侧按钮',
          max: 6
        },
        storeStyleConfig: {
          title: '选择风格',
          tabVal: 0,
          tabList: [
            {
							  name: '样式一'
            },
						    {
						      name: '样式二'
						    },
            {
							  name: '样式三'
            }
          ]
        },
        numberConfig: {
          title: '门店数量',
          val: 3,
          min: 1
        },
        numberGoodsConfig: {
          title: '商品数量',
          val: 5,
          min: 1
        },
        checkboxGoodsInfo: {
          title: '展示信息',
          name: 'checkboxInfo',
          type: [0, 1],
          list: [
            {
							    id: 0,
							    name: '商品名称'
            },
            {
							    id: 1,
							    name: '商品价格'
            }
          ]
        },
        checkboxInfo: {
          title: '展示信息',
          name: 'checkboxInfo',
          storeType: 1,
          type: [0, 1, 2, 3],
          list: [
            {
							    id: 0,
							    name: '配送方式'
            },
            {
							    id: 1,
							    name: '营业时间'
            },
            {
							    id: 2,
							    name: '门店距离'
            },
            {
							    id: 3,
							    name: '门店地址'
            }
          ]
        },
        headerBgColor: {
          title: '背景颜色',
          name: 'headerBgColor',
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
						        item: '#282828'
						    }
          ],
          color: [
						    {
						        item: '#282828'
						    }
          ]
        },
        titleNumber: {
          title: '标题字号',
          val: 16,
          min: 0
        },
        headerBntColor: {
          title: '按钮颜色',
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
        headerBntColor2: {
          title: '按钮颜色',
          name: 'headerBntColor2',
          default: [
						    {
						        item: '#999'
						    }
          ],
          color: [
						    {
						        item: '#999'
						    }
          ]
        },
        bntNumber: {
          title: '按钮字号',
          val: 12,
          min: 0
        },
        storeNumber: {
          title: '门店间距',
          val: 20,
          min: 0
        },
        storeName: {
          title: '门店名称',
          tabVal: 1,
          tabList: [
            {
							    name: '加粗',
							    style: 'bold'
            },
						    {
						        name: '正常',
						        style: 'normal'
						    }
          ]
        },
        storeNameColor: {
          title: '门店名称',
          name: 'storeNameColor',
          default: [
						    {
						        item: '#333333'
						    }
          ],
          color: [
						    {
						        item: '#333333'
						    }
          ]
        },
        deliveryBg: {
          title: '快递背景',
          name: 'deliveryBg',
          default: [
						    {
						        item: '#FAAD14'
						    }
          ],
          color: [
						    {
						        item: '#FAAD14'
						    }
          ]
        },
        deliveryText: {
          title: '快递字色',
          name: 'deliveryText',
          default: [
						    {
						        item: '#FFFFFF'
						    }
          ],
          color: [
						    {
						        item: '#FFFFFF'
						    }
          ]
        },
        cityDeliveryBg: {
          title: '闪送背景',
          name: 'cityDeliveryBg',
          default: [
						    {
						        item: '#FF7D00'
						    }
          ],
          color: [
						    {
						        item: '#FF7D00'
						    }
          ]
        },
        cityDeliveryText: {
          title: '闪送字色',
          name: 'cityDeliveryText',
          default: [
						    {
						        item: '#FFFFFF'
						    }
          ],
          color: [
						    {
						        item: '#FFFFFF'
						    }
          ]
        },
        storeBg: {
          title: '到店背景',
          name: 'storeBg',
          default: [
						    {
						        item: '#F53F3F'
						    }
          ],
          color: [
						    {
						        item: '#F53F3F'
						    }
          ]
        },
        storeText: {
          title: '到店字色',
          name: 'storeText',
          default: [
						    {
						        item: '#FFFFFF'
						    }
          ],
          color: [
						    {
						        item: '#FFFFFF'
						    }
          ]
        },
        filletStoreImg: {
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
          val: 6,
          min: 0,
          valList: [
            { val: 0 },
            { val: 0 },
            { val: 0 },
            { val: 0 }
          ]
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
          valList: [
            { val: 0 },
            { val: 0 },
            { val: 0 },
            { val: 0 }
          ]
        },
        goodsName: {
          title: '商品名称',
          tabVal: 1,
          tabList: [
            {
							    name: '加粗',
							    style: 'bold'
            },
						    {
						        name: '正常',
						        style: 'normal'
						    }
          ]
        },
        goodsNameColor: {
          title: '商品名称',
          name: 'goodsNameColor',
          default: [
						    {
						        item: '#333333'
						    }
          ],
          color: [
						    {
						        item: '#333333'
						    }
          ]
        },
        goodsPriceColor: {
          title: '商品价格',
          name: 'goodsPriceColor',
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
          valList: [
            { val: 0 },
            { val: 0 },
            { val: 0 },
            { val: 0 }
          ]
        }
      },
      pageData: {},
      bottomBgColor: '',
      mTop: 0,
      topConfig: 0,
      bottomConfig: 0,
      prConfig: 0,
      imgBgUrl: '',
      storeStyleConfig: 0,
      headerBgColorLeft: '',
      headerBgColorRight: '',
      titleConfig: 0,
      titleTabVal: 0,
      titleText: '',
      titleColor: '',
      titleNumber: 0,
      titleTxtConfig: '',
      styleConfig: 0,
      imgUrl: '',
      imgColorUrl: '',
      headerBntColor: '',
      headerBntColor2: '',
      bntNumber: 0,
      rightBntTxt: '',
      numberConfig: 1,
      deliveryBg: '',
      deliveryText: '',
      cityDeliveryBg: '',
      cityDeliveryText: '',
      storeBg: '',
      storeText: '',
      checkboxInfo: [],
      checkboxGoodsInfo: [],
      numberGoodsConfig: 1,
      filletStoreImg: {},
      storeNumber: 0,
      storeNameColor: '',
      storeName: {},
      filletImg: {},
      goodsName: {},
      goodsNameColor: '',
      toneConfig: 0,
      goodsPriceColor: '',
      fillet: {},
      moduleColor: []
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
        this.imgUrl = data.imgConfig.url;
        this.imgBgUrl = data.imgBgConfig.url;
        this.imgColorUrl = data.imgColorConfig.url;
        this.rightBntTxt = data.rightBntConfig.value;
        if (data.deliveryBg.title === '配送背景') {
          data.deliveryBg.title = '快递背景';
        }
        if (data.deliveryText.title === '配送字色') {
          data.deliveryText.title = '快递字色';
        }
        this.deliveryBg = data.deliveryBg.color[0].item;
        this.deliveryText = data.deliveryText.color[0].item;
        this.cityDeliveryBg = data.cityDeliveryBg ? data.cityDeliveryBg.color[0].item : this.defaultConfig.cityDeliveryBg.default[0].item;
        if (data.cityDeliveryBg === undefined) {
          data.cityDeliveryBg = this.defaultConfig.cityDeliveryBg;
        }
        this.cityDeliveryText = data.cityDeliveryText ? data.cityDeliveryText.color[0].item : this.defaultConfig.cityDeliveryText.default[0].item;
        if (data.cityDeliveryText === undefined) {
          data.cityDeliveryText = this.defaultConfig.cityDeliveryText;
        }
        this.storeBg = data.storeBg.color[0].item;
        this.storeText = data.storeText.color[0].item;
        this.headerBntColor = data.headerBntColor.color[0].item;
        this.headerBntColor2 = data.headerBntColor2.color[0].item;
        this.bntNumber = data.bntNumber.val;
        this.storeNumber = data.storeNumber.val;
        this.styleConfig = data.styleConfig.tabVal;
        this.headerBgColorLeft = data.headerBgColor.color[0].item;
        this.headerBgColorRight = data.headerBgColor.color[1].item;
        this.titleConfig = data.titleConfig.tabVal;
        this.titleTxtConfig = data.titleTxtConfig.value;
        this.moduleColor = data.moduleColor.color;
        this.bottomBgColor = data.bottomBgColor.color[0].item;
        this.mTop = data.mbConfig.val;
        this.topConfig = data.topConfig.val;
        this.bottomConfig = data.bottomConfig.val;
        this.prConfig = data.prConfig.val;
        const tabVal = data.titleText.tabVal;
        this.titleTabVal = tabVal;
        this.titleText = data.titleText.tabList[tabVal].style;
        this.checkboxInfo = data.checkboxInfo.type;
        this.checkboxGoodsInfo = data.checkboxGoodsInfo.type;
        this.filletStoreImg = data.filletStoreImg;
        this.storeNameColor = data.storeNameColor.color[0].item;
        this.storeName = data.storeName;
        this.filletImg = data.filletImg;
        this.fillet = data.fillet;
        this.goodsName = data.goodsName;
        this.goodsNameColor = data.goodsNameColor.color[0].item;
        this.goodsPriceColor = data.goodsPriceColor.color[0].item;
        this.toneConfig = data.toneConfig.tabVal;
        this.storeStyleConfig = data.storeStyleConfig.tabVal;
        this.numberConfig = data.numberConfig.val;
        this.numberGoodsConfig = data.numberGoodsConfig.val;
        this.titleColor = data.titleColor.color[0].item;
        this.titleNumber = data.titleNumber.val;
      }
    }
  }
};
</script>

<style scoped lang="stylus">
.store:last-child{
	padding-bottom: 10px !important;
}
.picBox{
	width: calc(100% - 62px);
}
.seckill-box{
	background: #fff

	.hd{
		display: flex
		justify-content: space-between
		align-items: center
		background-repeat: no-repeat;
		background-size: 100% 100%;
		width: 100%;
		height: 48px;
		padding: 0 12px;
		.right{
			color: #fff;
			font-size: 12px;
			.iconfont{
				font-size: 12px;
			}
		}
		.left{
			display: flex
			align-items: center
			.text{
				font-size: 16px;
				margin-right: 8px
			}
			.line{
				width: 1px;
				height: 14px;
				background: #DDDDDD;
				margin: 0 9px;
			}
			img{
				width: 70px
				height: 16px
			}
		}
	}
}
</style>
