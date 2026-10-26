<template>
    <div class="mobile-page":style="{
		background:bottomBgColor,
		marginTop:mbConfig+'px',
		paddingTop:topConfig+'px',
		paddingBottom:bottomConfig+'px',
		paddingLeft:prConfig+'px',
		paddingRight:prConfig+'px'
		}">
        <div class="ranking" :style="{
			background:bgColor,
			borderRadius:bgRadius
		}">
			<div class="header acea-row row-between-wrapper">
				<div v-if="titleConfig" :style="(titleTabVal==2?'fontStyle:':'fontWeight:') + titleText+';color:'+titleColor+';fontSize:'+titleNumber+'px;'">{{titleTxtConfig}}</div>
				<img v-else :src="imgUrl" alt="" />
				<div :style="{
					color:headerBntColor,
					fontSize:bntNumber+'px'
				}">{{rightBntConfig}}<span class="iconfont iconjinru" :style="{
					fontSize:bntNumber+'px'
				}"></span></div>
			</div>
			<div class="list acea-row row-middle" v-if="styleConfig == 0">
				<div class="item" :style="{
					borderRadius:listRadius,
					background:`linear-gradient(172deg,${listBgColorLeft} 0%,${listBgColorRight} 100%)`
				}" v-for="(item,index) in 2" :key="index">
					<div class="title acea-row row-middle" :style="{
						color:toneConfig?classColor:colorStyle.theme
					}">
						<span class="iconfont iconic_fire"></span>{{index==0?'销量榜':'好评榜'}}
					</div>
					<div class="listCon">
						<div class="itemCon acea-row row-middle" v-for="(itemCon,indexn) in 3" :key="indexn">
							<div class="pictrue acea-row row-center-wrapper" :style="{
								borderRadius:imgRadius
							}">
								<img src="../../assets/images/shan.png"/>
								<img v-if="indexn == 0" class="img" src="../../assets/images/rank01.png"/>
								<img v-if="indexn == 1" class="img" src="../../assets/images/rank02.png"/>
								<img v-if="indexn == 2" class="img" src="../../assets/images/rank03.png"/>
							</div>
							<div class="right">
								<div>幸运美物</div>
								<div class="price" :style="{
									color:toneConfig?goodsPriceColor:colorStyle.theme
								}"><span class="lable">¥</span>350.00</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="list on" v-else v-else>
				<div class="item" :style="{
					borderRadius:listRadius,
					background:`linear-gradient(90deg,${listBgColorLeft} 0%,${listBgColorRight} 100%)`
				}" v-for="(item,index) in 2" :key="index">
					<div class="title acea-row row-middle" :style="{
						color:toneConfig?classColor:colorStyle.theme
					}">{{index == 0?'销量榜':'收藏榜'}}</div>
					<div class="acea-row row-middle">
						<div class="pictrue acea-row row-center-wrapper" :style="{
							borderRadius:imgRadius
						}">
							<img src="../../assets/images/shan.png"/>
							<img class="img" src="../../assets/images/rank04.png"/>
						</div>
						<div class="picList">
							<div class="picItem acea-row row-center-wrapper" :style="{
								borderRadius:imgRadius
							}">
								<img src="../../assets/images/shan.png"/>
								<img class="img" src="../../assets/images/rank05.png"/>
							</div>
							<div class="picItem acea-row row-center-wrapper" :style="{
								borderRadius:imgRadius
							}">
								<img src="../../assets/images/shan.png"/>
								<img class="img" src="../../assets/images/rank06.png"/>
							</div>
						</div>
					</div>
					<div class="name">小米新款高配版马卡龙...</div>
					<div class="acea-row row-middle">
						<div class="price" :style="{
							color:toneConfig?goodsPriceColor:colorStyle.theme
						}"><span class="label">¥</span>350.00</div>
						<div class="yprice">¥350.00</div>
					</div>
				</div>
			</div>
		</div>
    </div>
</template>

<script>
import { mapState, mapMutations } from 'vuex';
// import theme from "@/mixins/theme";
export default {
  name: 'home_ranking',
  cname: '排行榜',
  configName: 'c_ranking',
  icon: '#iconzujian-paihangbang',
  type: 1, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'ranking', // 外面匹配名称
  props: {
    index: {
      type: null,
      default: -1
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
        cname: '排行榜',
        name: 'ranking',
        timestamp: this.num,
        isHide: false,
        setUp: {
					  tabVal: 0
        },
        titleLeft: '头部设置',
        titleRight: '头部样式',
        titleGoods: '展示设置',
        titleRanking: '榜单样式',
        titleCurrency: '通用样式',
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
          info: '建议：51px * 16px',
          url: require('@/assets/images/ranking.png'),
          type: 'code',
          delType: 0,
          name: '上传图片'
        },
        titleTxtConfig: {
          title: '标题文字',
          value: '排行榜',
          place: '请输入标题文字',
          max: 6
        },
        rightBntConfig: {
          title: '右侧按钮',
          value: '更多',
          place: '请输入右侧按钮',
          max: 6
        },
        styleConfig: {
          title: '选择风格',
          tabVal: 0,
          type: 'ranking'
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
						        item: '#333333'
						    }
          ],
          color: [
						    {
						        item: '#333333'
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
						        item: '#999999'
						    }
          ],
          color: [
						    {
						        item: '#999999'
						    }
          ]
        },
        bntNumber: {
          title: '按钮字号',
          val: 12,
          min: 0
        },
        filletBg: {
          title: '榜单圆角',
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
        },
        filletImg: {
          title: '商品圆角',
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
        },
        listBgColor: {
          title: '榜单背景',
          name: 'listBgColor',
          default: [
            {
              item: '#FCEAE9'
            },
            {
              item: '#FCEAE9'
            }
          ],
          color: [
						    {
						        item: '#FCEAE9'
						    },
            {
							    item: '#FCEAE9'
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
        classColor: {
          title: '分类标题',
          name: 'classColor',
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
      bottomBgColor: '',
      confObj: {},
      pageData: {},
      topConfig: 0,
      bottomConfig: 0,
      prConfig: 0,
      headerBntColor: '',
      bntNumber: 0,
      rightBntConfig: '',
      titleTxtConfig: '',
      titleNumber: 0,
      titleColor: '',
      titleText: '',
      titleTabVal: 0,
      titleConfig: 0,
      imgUrl: '',
      styleConfig: 0,
      listRadius: 0,
      listBgColorLeft: '',
      listBgColorRight: '',
      imgRadius: 0,
      classColor: '',
      goodsPriceColor: '',
      toneConfig: 0,
      bgColor: '',
      mbConfig: 0,
      bgRadius: 0
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
        this.headerBntColor = data.headerBntColor.color[0].item;
        this.bntNumber = data.bntNumber.val;
        this.rightBntConfig = data.rightBntConfig.value;
        this.titleTxtConfig = data.titleTxtConfig.value;
        this.styleConfig = data.styleConfig.tabVal;
        this.titleNumber = data.titleNumber.val;
        this.titleColor = data.titleColor.color[0].item;
        const tabVal = data.titleText.tabVal;
        this.titleTabVal = tabVal;
        this.titleText = data.titleText.tabList[tabVal].style;
        this.titleConfig = data.titleConfig.tabVal;
        this.imgUrl = data.imgConfig.url;
        this.bottomBgColor = data.bottomBgColor.color[0].item;
        this.topConfig = data.topConfig.val;
        this.bottomConfig = data.bottomConfig.val;
        this.prConfig = data.prConfig.val;
        this.classColor = data.classColor.color[0].item;
        this.goodsPriceColor = data.goodsPriceColor.color[0].item;
        this.listBgColorLeft = data.listBgColor.color[0].item;
        this.listBgColorRight = data.listBgColor.color[1].item;
        this.toneConfig = data.toneConfig.tabVal;
        const bgColorLeft = data.moduleColor.color[0].item;
        const bgColorRight = data.moduleColor.color[1].item;
        this.bgColor = `linear-gradient(90deg,${bgColorLeft} 0%,${bgColorRight} 100%)`;
        this.mbConfig = data.mbConfig.val;
        const fillet = data.fillet.type;
        const filletVal = data.fillet.val;
        const valList = data.fillet.valList;
        this.bgRadius = fillet ? valList[0].val + 'px ' + valList[1].val + 'px ' + valList[3].val + 'px ' + valList[2].val + 'px' : filletVal + 'px';
        const filletBg = data.filletBg.type;
        const filletValBg = data.filletBg.val;
        const valListBg = data.filletBg.valList;
        this.listRadius = filletBg ? valListBg[0].val + 'px ' + valListBg[1].val + 'px ' + valListBg[3].val + 'px ' + valListBg[2].val + 'px' : filletValBg + 'px';
        const filletImg = data.filletImg.type;
        const filletValImg = data.filletImg.val;
        const valListImg = data.filletImg.valList;
        this.imgRadius = filletImg ? valListImg[0].val + 'px ' + valListImg[1].val + 'px ' + valListImg[3].val + 'px ' + valListImg[2].val + 'px' : filletValImg + 'px';
      }
    }
  }
};
</script>

<style scoped lang="stylus">
	.ranking{
		background-color: #fff;
		overflow: hidden;
		.header{
			padding: 0 10px;
			height: 48px;
			img {
				width: 51px;
				height: 16px;
				display: block;
			}
		}
		.list{
			flex-wrap: nowrap;
			overflow: hidden;
			padding: 0 0 15px 10px;
			&.on{
				display: inline-flex;
				.item{
					width: 252px;
					height: 233px;
					display: inline-block;
					.name{
						font-size: 13px;
						color: #282828;
						margin-top: 8px;
					}
					.price{
						font-size: 14px;
						font-weight: 600;
						color: #E93323;
						.label{
							font-size: 10px;
						}
					}
					.yprice{
						color: #999999;
						font-size: 10px;
						text-decoration: line-through;
						margin-left: 4px;
					}
					.title{
						font-size: 13px;
						height: 33px;
					}
					.pictrue{
						width: 148px;
						height: 148px;
						background: #F3F9FF;
						border-radius: 4px;
						position: relative;

						img {
							width: 65px;
							height: 50px;
							display: block;
						}

						.img{
							width: 36px;
							height: 36px;
							display: block;
							position: absolute;
							top:0;
							left:0
						}
					}
					.picList{
						margin-left: 6px;
						.picItem{
							width: 71px;
							height: 71px;
							background: #F3F9FF;
							border-radius: 4px;
							position: relative;
							img {
								width: 44px;
								height: 34px;
								display: block;
							}
							.img {
								width: 24px;
								height: 24px;
								display: block;
								position: absolute;
								top:0;
								left:0
							}
							&~.picItem{
								margin-top: 6px;
							}
						}
					}
				}
			}
			.item{
				width: 186px;
				height: 241px;
				background: #FCEAE9;
				border-radius: 8px;
				padding: 0 8px 9px 8px;
				margin-right: 10px;
				.title{
					font-size: 14px;
					font-weight: 600;
					color: #E93323;
					height: 40px;
					.iconfont{
						font-size: 13px;
						margin-right: 4px;
					}
				}
				.listCon{
					width: 170px;
					height: 192px;
					background: #FFFFFF;
					border-radius: 6px;
					padding: 7px;
					.itemCon{
						&~.itemCon{
							margin-top: 8px;
						}
						.pictrue{
							width: 54px;
							height: 54px;
							background: #F3F9FF;
							margin-right: 8px;
							position: relative;
							img {
								width: 36px;
								height: 28px;
								display: block;
							}
							.img{
								width: 15px;
								height: 16px;
								display: block;
								position: absolute;
								top:0;
								left:0;
							}
						}
						.right{
							font-size: 13px;
							color: #333333;
							.price{
								font-weight: 600;
								color: #E93323;
								font-size: 14px;
								.lable{
									font-size: 10px;
								}
							}
						}
					}
				}
			}
		}
	}
</style>
