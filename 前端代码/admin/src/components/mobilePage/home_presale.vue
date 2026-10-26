<template>
  <div>
    <div class="seckill-box" :style="{
		background:bottomBgColor,
		marginTop:mTop+'px',
		paddingTop:topConfig+'px',
		paddingBottom:bottomConfig+'px',
		paddingLeft:prConfig+'px',
		paddingRight:prConfig+'px'
		}">
      <div class="hd" :style="
	    (styleConfig?
		'backgroundImage:url(' + imgBgUrl + ')':
	    `background:linear-gradient(90deg,${headerBgColorLeft} 0%,${headerBgColorRight} 100%)`)+
		';borderRadius:'+bgRadius
	  ">
        <div class="left acea-row row-middle">
		  <div class="text" v-if="titleConfig" :style="(titleTabVal==2?'fontStyle:':'fontWeight:') + titleText+';color:'+titleColor+';fontSize:'+titleNumber+'px;'">{{titleTxtConfig}}</div>
          <img v-else :src="styleConfig?imgUrl:imgColorUrl" alt="" />
		  <div class="line" :style="{
			  background:dividerColor
		  }"></div>
          <div class="tips" :style="{
			  color: styleConfig?tipsColor:tipsColor2
		  }">{{tipTxt}}</div>
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
      <div class="list-wrapper" :class="goodStyleConfig == 0?'on':(goodStyleConfig == 1 || goodStyleConfig == 2)?'on2':goodStyleConfig == 3?'on3':''" :style="{
		  background: bgColor,
		  borderRadius: bgRadius2
	  }">
	    <div v-if="goodStyleConfig == 0" class="itemOne acea-row" v-for="(item, index) in numberConfig" :key="index">
			<div class="empty-box" :style="{
				borderRadius: imgRadius
			}">
				<img src="../../assets/images/shan.png"/>
			</div>
			<div class="text">
				<div class="top">
					<div class="name line2" v-if="checkboxInfo.indexOf(0) != -1" :style="{
						fontWeight: goodsName,
						color: goodsNameColor
					}">秋季新品橙中爱马仕 黑标新骑士秘境.</div>
					<div class="pictrue">
						<img src="../../assets/images/label.png"/>
					</div>
				</div>
				<div class="bottom" :class="checkboxInfo.indexOf(1) != -1?'':'acea-row row-bottom'">
					<div class="bnt" :style="{
						background:toneConfig?goodsBntColorRight:colorStyle.theme
					}">
						<div class="bntCon acea-row row-middle">
							<div class="left">
							  <div class="price" v-if="checkboxInfo.indexOf(1) != -1" :style="{
							  	color:toneConfig?presalePriceColor:colorStyle.theme
							  }"><span>预售</span><span class="label">¥</span><span class="num">3200</span></div>
							</div>
							<div class="right" :style="{
								color:toneConfig?goodsBntTxtColor:'#fff',
								background: toneConfig?`linear-gradient(90deg,${goodsBntColorRight} 0%,${goodsBntColorLeft} 100%)`:themeColor
							}">敬请期待</div>
							<img src="../../assets/images/dian3.png"/>
						</div>
					</div>
				</div>
			</div>
		</div>
		<div class="itemTwo" v-if="goodStyleConfig == 1" v-for="(item2, index2) in numberConfig" :key="index2">
			<div class="empty-box" :style="{
				borderRadius: imgRadius
			}">
			  <img src="../../assets/images/shan.png"/>
			</div>
			<div :class="((checkboxInfo.indexOf(0) != -1 && checkboxInfo.length == 1 && !presaleConfig) || (checkboxInfo.indexOf(0) != -1 && checkboxInfo.indexOf(1) != -1 && checkboxInfo.length == 2) && !presaleConfig)?'item':(!checkboxInfo.length || (checkboxInfo.indexOf(1) != -1 && checkboxInfo.length==1)) && !presaleConfig?'item2':''">
				<div class="title line2" v-if="checkboxInfo.indexOf(0) != -1" :style="{
					fontWeight: goodsName,
					color: goodsNameColor
				}">秋季新品橙中爱马仕 黑标新骑士秘境</div>
				<div class="price" :class="!presaleConfig?'on':''" v-if="checkboxInfo.indexOf(1) != -1" :style="{
					color:toneConfig?presalePriceColor:colorStyle.theme
				}">¥<span class="num">3200.00</span></div>
				<div class="yprice" :class="checkboxInfo.indexOf(1) == -1 && !presaleConfig?'on':''" v-if="checkboxInfo.indexOf(2) != -1" :style="{
					color:goodsPriceColor
				}">¥3699.00</div>
				<div class="bnt" :class="checkboxInfo.indexOf(1) == -1 && !presaleConfig?'on':checkboxInfo.indexOf(1) != -1 && !presaleConfig?'on2':''" v-if="!presaleConfig" :style="{
					color:toneConfig?goodsBntTxtColor:'#fff',
					background: toneConfig?`linear-gradient(90deg,${goodsBntColorRight} 0%,${goodsBntColorLeft} 100%)`:themeColor
				}">去预定</div>
			</div>
		</div>
        <div
                v-if="goodStyleConfig == 2"
				class="list-item"
                v-for="(item, index) in numberConfig"
                :key="index"
        >
          <div class="img-box">
            <div class="empty-box" :style="{
				borderRadius: imgRadius
			}">
			  <img src="../../assets/images/shan.png"/>
			</div>
          </div>
          <div class="title line1" v-if="checkboxInfo.indexOf(0) != -1" :style="{
			  fontWeight: goodsName,
			  color: goodsNameColor
		  }">秋季新品橙中爱马仕 黑标新骑士秘境</div>
          <div class="price" v-if="checkboxInfo.indexOf(1) != -1" :style="{
			  color:toneConfig?presalePriceColor:colorStyle.theme
		  }">预售价<span class="lable">¥</span><span class="num">350.00</span></div>
        </div>
		<div class="itemThree" v-if="goodStyleConfig == 3" v-for="(item2, index2) in numberConfig" :key="index2">
			<div class="empty-box" :style="{
				borderRadius: imgRadius
			}">
			  <img src="../../assets/images/shan.png"/>
			</div>
			<div>
				<div class="title line1" v-if="checkboxInfo.indexOf(0) != -1" :style="{
					fontWeight: goodsName,
					color: goodsNameColor
				}">秋季新品橙中爱马仕 黑标新骑士秘境</div>
				<div class="price" v-if="checkboxInfo.indexOf(1) != -1" :style="{
					color:toneConfig?presalePriceColor:colorStyle.theme
				}">预售价<span class="lable">¥</span><span class="num">3200.00</span></div>
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
  name: 'home_presale',
  cname: '预售',
  icon: '#iconzujian-yushou',
  configName: 'c_presale',
  type: 1, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'presale', // 外面匹配名称
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
        cname: '预售',
        name: 'presale',
        timestamp: this.num,
        isHide: false,
        setUp: {
          tabVal: 0
        },
        titleLeft: '头部设置',
        titleGoodsList: '商品列表',
        titleGoods: '商品设置',
        titleRight: '头部样式',
        titleGoodsStyle: '商品样式',
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
          url: Setting.apiBaseURL.replace(/adminapi/, '') + 'statics/images/presaleBg.png',
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
          url: require('@/assets/images/presale02.png'),
          type: 'code',
          delType: 0,
          name: '标题图片'
        },
        imgColorConfig: {
          info: '建议：140px * 32px',
          url: require('@/assets/images/presale01.png'),
          type: 'code',
          delType: 0,
          name: '标题图片'
        },
        titleTxtConfig: {
          title: '标题文字',
          value: '预售专区',
          place: '请输入标题文字',
          max: 6
        },
        tipTxtConfig: {
          title: '提示文字',
          value: '火热预定中',
          place: '请输入提示文字',
          max: 15
        },
        rightBntConfig: {
          title: '右侧按钮',
          value: '更多',
          place: '请输入右侧按钮',
          max: 6
        },
        goodStyleConfig: {
          title: '选择风格',
          tabVal: 0,
          tabList: [
            {
							  name: '单列展示'
            },
						    {
						      name: '两列展示(纵向)'
						    },
            {
							  name: '三列展示'
            },
            {
							  name: '左右滑动展示'
            }
          ]
        },
        numberConfig: {
          title: '商品数量',
          val: 3,
          min: 1
        },
        checkboxInfo: {
          title: '展示信息',
          name: 'checkboxInfo',
          type: [0, 1, 2],
          list: [
            {
							    id: 0,
							    name: '商品名称'
            },
            {
							    id: 1,
							    name: '预售价格'
            },
            {
							    id: 2,
							    name: '划线价'
            }
          ]
        },
        presaleConfig: {
          title: '预售按钮',
          tabVal: 0,
          tabList: [
            {
							  name: '显示'
            },
						    {
						      name: '隐藏'
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
        tipsColor: {
          title: '提示文字',
          name: 'tipsColor',
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
        tipsColor2: {
          title: '提示文字',
          name: 'tipsColor2',
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
        dividerColor: {
          title: '分割线',
          name: 'dividerColor',
          default: [
						    {
						        item: '#DDDDDD'
						    }
          ],
          color: [
						    {
						        item: '#DDDDDD'
						    }
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
          title: '划线价',
          name: 'goodsPriceColor',
          default: [
						    {
						        item: '#999999'
						    }
          ],
          color: [
						    {
						        item: '#999999'
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
        presalePriceColor: {
          title: '预售价格',
          name: 'presalePriceColor',
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
        goodsBntColor: {
          title: '按钮颜色',
          name: 'goodsBntColor',
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
        goodsBntTxtColor: {
          title: '按钮文字',
          name: 'goodsBntTxtColor',
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
      imgUrl: '',
      imgBgUrl: '',
      tipsColor: '',
      tipsColor2: '',
      dividerColor: '',
      rightBntTxt: '',
      tipTxt: '',
      headerBntColor: '',
      headerBntColor2: '',
      bntNumber: 0,
      styleConfig: 0,
      headerBgColorLeft: '',
      headerBgColorRight: '',
      imgColorUrl: '',
      titleConfig: 0,
      titleTxtConfig: '',
      bgColor: '',
      bottomBgColor: '',
      mTop: 0,
      topConfig: 0,
      bottomConfig: 0,
      prConfig: 0,
      titleText: '',
      titleTabVal: 0,
      checkboxInfo: [],
      imgRadius: 0,
      bgRadius: 0,
      bgRadius2: 0,
      goodsName: '',
      goodsNameColor: '',
      goodsPriceColor: '',
      toneConfig: 0,
      goodsBntColorLeft: '',
      goodsBntColorRight: '',
      goodStyleConfig: 0,
      goodsBntTxtColor: '',
      presaleConfig: 0,
      numberConfig: 1,
      titleColor: '',
      titleNumber: 0,
      presalePriceColor: '',
      themeColor: ''
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
        this.tipsColor = data.tipsColor.color[0].item;
        this.tipsColor2 = data.tipsColor2.color[0].item;
        this.dividerColor = data.dividerColor.color[0].item;
        this.rightBntTxt = data.rightBntConfig.value;
        this.tipTxt = data.tipTxtConfig.value;
        this.headerBntColor = data.headerBntColor.color[0].item;
        this.headerBntColor2 = data.headerBntColor2.color[0].item;
        this.bntNumber = data.bntNumber.val;
        this.styleConfig = data.styleConfig.tabVal;
        this.headerBgColorLeft = data.headerBgColor.color[0].item;
        this.headerBgColorRight = data.headerBgColor.color[1].item;
        this.titleConfig = data.titleConfig.tabVal;
        this.titleTxtConfig = data.titleTxtConfig.value;
        const bgColorLeft = data.moduleColor.color[0].item;
        const bgColorRight = data.moduleColor.color[1].item;
        this.bgColor = `linear-gradient(90deg,${bgColorLeft} 0%,${bgColorRight} 100%)`;
        this.bottomBgColor = data.bottomBgColor.color[0].item;
        this.mTop = data.mbConfig.val;
        this.topConfig = data.topConfig.val;
        this.bottomConfig = data.bottomConfig.val;
        this.prConfig = data.prConfig.val;
        const tabVal = data.titleText.tabVal;
        this.titleTabVal = tabVal;
        this.titleText = data.titleText.tabList[tabVal].style;
        this.checkboxInfo = data.checkboxInfo.type;
        const filletImg = data.filletImg.type;
        const filletValImg = data.filletImg.val;
        const valListImg = data.filletImg.valList;
        this.imgRadius = filletImg ? valListImg[0].val + 'px ' + valListImg[1].val + 'px ' + valListImg[3].val + 'px ' + valListImg[2].val + 'px' : filletValImg + 'px';
        const fillet = data.fillet.type;
        const filletVal = data.fillet.val;
        const valList = data.fillet.valList;
        this.bgRadius = fillet ? valList[0].val + 'px ' + valList[1].val + 'px 0 0' : filletVal + 'px ' + filletVal + 'px 0 0';
        this.bgRadius2 = fillet ? '0 0 ' + valList[3].val + 'px ' + valList[2].val + 'px' : '0 0 ' + filletVal + 'px ' + filletVal + 'px';
        const goodsTabVal = data.goodsName.tabVal;
        this.goodsName = data.goodsName.tabList[goodsTabVal].style;
        this.goodsNameColor = data.goodsNameColor.color[0].item;
        this.goodsPriceColor = data.goodsPriceColor.color[0].item;
        this.toneConfig = data.toneConfig.tabVal;
        this.goodsBntColorLeft = data.goodsBntColor.color[1].item;
        this.goodsBntColorRight = data.goodsBntColor.color[0].item;
        this.goodStyleConfig = data.goodStyleConfig.tabVal;
        this.goodsBntTxtColor = data.goodsBntTxtColor.color[0].item;
        this.presaleConfig = data.presaleConfig.tabVal;
        this.numberConfig = data.numberConfig.val;
        this.titleColor = data.titleColor.color[0].item;
        this.titleNumber = data.titleNumber.val;
        this.presalePriceColor = data.presalePriceColor.color[0].item;
        this.themeColor = `linear-gradient(90deg,${this.colorStyle.theme} 0%,${this.colorStyle.gradient} 100%)`;
      }
    }
  }
};
</script>

<style scoped lang="stylus">
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
				height: 10px;
				background: #DDDDDD;
				margin: 0 9px;
			}
			img{
				width: 70px
				height: 16px
			}
			.tips {
				font-size: 13px
				color: #fff
				font-weight:400;
			}
		}
	}
	.list-wrapper{
		display: flex;
		justify-content: center;
		overflow: hidden;
		padding: 10px;
		width: 100%;
		&.on{
			display: block;
		}
		&.on2{
			flex-wrap: wrap;
			justify-content: flex-start;
		}
		&.on3{
			justify-content: flex-start;
			padding-right: 0;
		}
		.itemTwo,.itemThree{
			width: 48%;
			position: relative;
			margin-right: 11px;
			margin-top: 15px;

			.item{
				height: 50px;
			}

			.item2{
				height: 30px;
			}

			&:nth-child(1){
				margin-top: 0;
			}

			&:nth-child(2){
				margin-top: 0;
			}

			&:nth-of-type(2n){
				margin-right: 0;
			}

			.empty-box{
				width: 100%;
				height: 162px;
				background-color: #F3F9FF;
				img{
					width: 64px;
					height: 50px;
					display: block
				}
			}
			.title{
				font-size: 14px;
				color: #333333;
				margin-top: 8px;
			}
			.price{
				font-weight: 600;
				font-size: 12px;
				&.on{
					margin-top: 4px;
				}
				.num{
					font-size: 15px;
				}
			}
			.yprice{
				font-size: 11px;
				text-decoration: line-through;
				&.on{
					margin-top: 9px;
				}
			}
			.bnt{
				width: 57px;
				height: 26px;
				border-radius: 13px;
				text-align: center;
				line-height: 26px;
				position: absolute;
				right: 0;
				bottom:0;
				font-size: 12px;
				color: #FFFFFF;
				&.on{
					bottom:-4px;
				}
				&.on2{
					bottom:6px;
				}
			}
		}

		.itemThree{
			width: 112px;
			margin-top: 0;
			margin-right: 10px !important;
			.item{
				height: 45px;
			}
			.item2{
				height: 29px;
			}
			.empty-box{
				height: 112px;
				width: 112px;
			}
			.title{
				font-size: 13px;
				margin-top: 6px;
			}
			.price{
				font-size: 11px;
				margin-top: 3px;
				font-weight: 400;
				&.on{
					margin-top: 6px;
				}
				.num{
					font-weight: 600
				}
				.label{
					font-weight: 600
					margin-left: 4px;
				}
			}
		}

		.itemOne{
			position: relative;

			&~.itemOne{
				margin-top: 15px;
			}
			.empty-box{
				width: 120px;
				height: 120px;
				margin-right: 12px;
				background-color: #F3F9FF;
				img{
					width: 65px;
					height: 50px;
					display: block
				}
			}
			.text{
				flex: 1;
				.top{
					height: 77px;
				}
				.bottom{
					.bnt{
						width: 203px;
						height: 34px;
						background: #E93323;
						border-radius: 4px;
						margin-top: 8px;
						overflow: hidden;
						.bntCon{
							background:rgba(255,255,255,0.9);
							position: relative;
							border-radius: 4px;
							overflow: hidden;
							width: 100%;
							height: 100%;
							img {
								width: 24px;
								height: 42px;
								display: block;
								position: absolute;
								top:-1px;
								left:50%;
								margin-left: -10px;
							}
							.left{
								width: 50%;
								font-size: 11px;
								color: #E93323;
								line-height: 34px;
								padding-left: 10px;
							}
							.right{
								width: 50%;
								line-height: 33px;
								text-align: center;
								font-size: 13px;
								font-family: Alimama ShuHeiTi, Alimama ShuHeiTi;
								font-weight: 700;
								color: #fff;
							}
						}
					}
				}
				.name{
					font-size: 14px;
					color: #333333;
					margin-bottom: 8px;
				}
				.price{
					font-size: 11px;
					color: #E93323;
					.label{
						font-weight: 600;
						margin-left: 4px;
					}
					.num{
						font-size: 16px;
						font-weight: 600;
					}
				}
			}
		}

		.list-item{
			width: 31.47%;
			margin-top: 10px;

			&~.list-item{
				margin-left: 9px;
			}

			&:nth-of-type(3n-2){
				margin-left: 0;
			}

			&:nth-child(1),&:nth-child(2),&:nth-child(3){
				margin-top: 0;
			}

			.img-box{
				border-radius: 6px;
				position: relative;
				width: 100%
				height: 106px;

				.empty-box{
					background-color: #F3F9FF;
					img{
						width: 65px;
						height: 50px;
						display: block;
					}
				}
			}
			.title{
				margin-top: 8px
				font-size: 13px
				color: #333
			}
			.price{
				position: relative;
				color: #fff;
				font-size: 11px;

				.lable{
					font-size: 11px;
					font-weight: 600;
					margin-left: 2px;
				}

				.num{
					font-size: 15px;
					font-weight: 600;
				}

				img{
					width: 12px;
					height: 22px;
					display: block;
					position: absolute;
					left:-4px;
					top:0
				}
			}
			.yprice{
				color: #999;
				font-size: 12px;
				text-decoration: line-through;
			}
		}
	}
}
</style>
