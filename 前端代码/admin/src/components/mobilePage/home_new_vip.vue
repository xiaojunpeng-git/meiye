<template>
	<div :style="{
		background:bottomBgColor,
		marginTop:mTop+'px',
		paddingTop:topConfig+'px',
		paddingBottom:bottomConfig+'px',
		paddingLeft:prConfig+'px',
		paddingRight:prConfig+'px'
	}">
		<div class="newVip" v-if="styleConfig == 0" :style="{
			borderRadius:bgRadius
		}">
		    <div class="header acea-row row-between-wrapper">
		        <div class="title" >新人专享福利</div>
		        <div class="more">更多优惠<span class="iconfont iconjinru"></span></div>
		    </div>
			<div class="integral" v-if="checkboxInfo.indexOf(0) != -1" :style="{
				background: toneConfig?integralBgColor:colorStyle.theme,
				borderColor:toneConfig?integralBgColor:colorStyle.theme
			}">
				<div class="integralCon acea-row row-middle">
					<img src="../../assets/images/integral02.png" />
					<span>新用户注册即可</span>
					<span :style="{
						color:toneConfig?tipsColor:colorStyle.theme
					}">赠送积分</span>
				</div>
			</div>
			<div class="coupon" v-if="checkboxInfo.indexOf(1) != -1">
				<div class="title">专属优惠券</div>
				<div class="list acea-row row-middle" :style="{
					background: toneCouponConfig?`linear-gradient(90deg,${couponBgColorLeft} 0%,${couponBgColorRight} 100%)`:themeColor2
				}">
					<div class="item" v-for="(item, index) in 4" :key="index" :style="{
						marginRight:spacingConfig+'px'
					}">
						<div class="money" :style="{
							color:toneCouponConfig?couponMoneyColor:colorStyle.theme
						}"><span class="lable">¥</span>70</div>
						<div class="type" :style="{
							color:toneCouponConfig?couponTypeColor:colorStyle.theme
						}">通用券</div>
						<div class="sill">满500可用</div>
					</div>
					<div class="pocket" :style="{
						background: toneCouponConfig?`linear-gradient(90deg,${vipBgColorLeft} 0%,${vipBgColorRight} 100%)`:themeColor2
					}">
						<div class="money"><span class="lable">¥</span>200</div>
						<div class="tips">新人专享优惠券</div>
						<div class="bnt" :style="{
							color:toneCouponConfig?bntTxtColor:colorStyle.theme
						}">一键领取</div>
					</div>
				</div>
			</div>
			<div class="goods" v-if="checkboxInfo.indexOf(2) != -1">
				<div class="title">新人商品专区</div>
				<div class="list acea-row row-middle">
					<div class="item" v-for="(item,index) in 5" :key="index">
						<div class="pictrue acea-row row-center-wrapper">
							<img src="../../assets/images/shan.png"/>
						</div>
						<div class="name">小米蓝牙耳机</div>
						<div class="money" :style="{
							color:toneGoodsConfig?priceColor:colorStyle.theme
						}"><span class="lable">¥</span>350</div>
					</div>
				</div>
			</div>
		</div>
		<div class="newVip2" v-else :style="{
			background:bgColor,
			borderRadius:bgRadius
		}">
			<div class="header acea-row row-between-wrapper">
				<div class="left acea-row row-middle">
					<img src="../../assets/images/newVip.png"/>
					<div class="line"></div>
					<div>超值优惠 限时专享</div>
				</div>
				<div class="right">去逛逛<span class="iconfont iconjinru"></span></div>
			</div>
			<div class="conter" v-if="checkboxInfo.indexOf(1) != -1 || checkboxInfo.indexOf(2) != -1">
				<div class="coupon" v-if="checkboxInfo.indexOf(1) != -1">
					<div class="title">新人红包</div>
					<div class="list acea-row row-middle">
						<div class="item" v-for="(item, index) in 4" :key="index" :style="{
							marginRight:spacingConfig2+'px',
							background:toneCouponConfig? couponBgColor2:colorStyle.theme
						}">
							<div class="money" :style="{
								color:toneCouponConfig?couponMoneyColor:colorStyle.theme
							}"><span class="lable">¥</span>70</div>
							<div class="sill" :style="{
								background: toneCouponConfig?`linear-gradient(90deg,${bntBgColorRight} 0%,${bntBgColorLeft} 100%)`:themeColor
							}">满200可用</div>
							<img src="../../assets/images/newVip02.png"/>
						</div>
					</div>
				</div>
				<div class="goods" v-if="checkboxInfo.indexOf(2) != -1">
					<div class="title">新人商品专区</div>
					<div class="list acea-row row-middle">
						<div class="item" v-for="(item, index) in 5" :key="index">
							<div class="pictrue acea-row row-center-wrapper">
								<img src="../../assets/images/shan.png"/>
							</div>
							<div class="money">¥70</div>
							<div class="name">小米蓝牙耳机</div>
						</div>
					</div>
				</div>
			</div>
			<div class="integral acea-row row-between-wrapper" v-if="checkboxInfo.indexOf(0) != -1">
				<div class="picTxt acea-row row-middle">
					<div class="pictrue">
						<img src="../../assets/images/integrals01.png"/>
					</div>
					<div class="text">
						<div class="name acea-row row-middle">
							新用户注册领积分
							<div class="points" :style="{
								background:toneConfig?integralTxtColor:colorStyle.theme,
								color:toneConfig?integralTxtColor:colorStyle.theme
							}">
							    <div class="pointsCon acea-row row-center-wrapper">
									<img src="../../assets/images/points.png"/>+20
								</div>
							</div>
						</div>
						<div class="tips">新用户注册后即可获得积分</div>
					</div>
				</div>
				<div class="bnt" :style="{
					background: toneConfig?`linear-gradient(90deg,${bntColorLeft} 0%,${bntColorRight} 100%)`:themeColor
				}">去看看</div>
			</div>
		</div>
	</div>
</template>

<script>
import { mapState, mapMutations } from 'vuex';
// import theme from "@/mixins/theme";
export default {
  name: 'home_new_vip',
  cname: '新人专享',
  configName: 'c_new_vip',
  icon: '#iconzujian-xinrenzhuanxiang',
  type: 1, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'newVip', // 外面匹配名称
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
        cname: '新人专享',
        name: 'newVip',
        timestamp: this.num,
        isHide: false,
        setUp: {
          tabVal: 0
        },
        titleLeft: '展示设置',
        titleContent: '内容展示',
        titleRight: '积分',
        titleCoupon: '优惠券样式',
        titleGoods: '新人商品专区',
        titleCurrency: '通用样式',
        styleConfig: {
          title: '选择风格',
          tabVal: 1,
          tabList: [
            {
							  name: '样式一'
            },
						    {
						      name: '样式二'
						    }
          ]
        },
        checkboxInfo: {
          title: '展示信息',
          name: 'checkboxInfo',
          type: [0, 1, 2],
          list: [
            {
							    id: 0,
							    name: '积分'
            },
            {
							    id: 1,
							    name: '优惠券'
            },
            {
							    id: 2,
							    name: '新人商品专区'
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
        tipsColor: {
          title: '赠送积分',
          name: 'tipsColor',
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
        priceColor: {
          title: '价格颜色',
          name: 'priceColor',
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
        integralBgColor: {
          title: '积分背景',
          name: 'integralBgColor',
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
        bntColor: {
          title: '按钮背景',
          name: 'bntColor',
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
        integralTxtColor: {
          title: '积分文字',
          name: 'integralTxtColor',
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
        toneCouponConfig: {
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
        couponMoneyColor: {
          title: '优惠金额',
          name: 'couponMoneyColor',
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
        bntTxtColor: {
          title: '按钮文字',
          name: 'bntTxtColor',
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
        couponTypeColor: {
          title: '优惠类型',
          name: 'couponTypeColor',
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
        spacingConfig: {
          title: '优惠券间距',
          val: 4,
          min: 0
        },
        spacingConfig2: {
          title: '优惠券间距',
          val: 10,
          min: 0
        },
        vipBgColor: {
          title: '新人专项背景',
          name: 'vipBgColor',
          default: [
						    {
						        item: '#FFA157'
						    },
            {
							    item: '#FF7F3F'
            }
          ],
          color: [
						    {
						        item: '#FFA157'
						    },
						    {
						        item: '#FF7F3F'
						    }
          ]
        },
        couponBgColor: {
          title: '优惠券背景',
          name: 'couponBgColor',
          default: [
						    {
						        item: '#FFBA87'
						    },
            {
							    item: '#FD5E35'
            }
          ],
          color: [
						    {
						        item: '#FFBA87'
						    },
						    {
						        item: '#FD5E35'
						    }
          ]
        },
        couponBgColor2: {
          title: '优惠券背景',
          name: 'couponBgColor2',
          default: [
						    {
						        item: '#F12A13'
						    }
          ],
          color: [
						    {
						        item: '#F12A13'
						    }
          ]
        },
        bntBgColor: {
          title: '按钮背景',
          name: 'bntBgColor',
          default: [
						    {
						        item: '#FF7931'
						    },
            {
							    item: '#E93323'
            }
          ],
          color: [
						    {
						        item: '#FF7931'
						    },
						    {
						        item: '#E93323'
						    }
          ]
        },
        toneGoodsConfig: {
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
      styleConfig: 0,
      checkboxInfo: [],
      toneConfig: 0,
      tipsColor: '',
      integralBgColor: '',
      bntColorLeft: '',
      bntColorRight: '',
      integralTxtColor: '',
      toneGoodsConfig: 0,
      priceColor: '',
      toneCouponConfig: 0,
      couponMoneyColor: '',
      bntTxtColor: '',
      couponTypeColor: '',
      spacingConfig: 0,
      spacingConfig2: 0,
      vipBgColorLeft: '',
      vipBgColorRight: '',
      couponBgColorLeft: '',
      couponBgColorRight: '',
      couponBgColor2: '',
      bntBgColor: '',
      bntBgColorLeft: '',
      bntBgColorRight: '',
      bgColor: '',
      bottomBgColor: '',
      mTop: 0,
      topConfig: 0,
      bottomConfig: 0,
      prConfig: 0,
      bgRadius: 0,
      themeColor: '',
      themeColor2: ''
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
        this.styleConfig = data.styleConfig.tabVal;
        this.checkboxInfo = data.checkboxInfo.type;
        this.toneConfig = data.toneConfig.tabVal;
        this.tipsColor = data.tipsColor.color[0].item;
        this.integralBgColor = data.integralBgColor.color[0].item;
        this.bntColorLeft = data.bntColor.color[0].item;
        this.bntColorRight = data.bntColor.color[1].item;
        this.integralTxtColor = data.integralTxtColor.color[0].item;
        this.toneGoodsConfig = data.toneGoodsConfig.tabVal;
        this.priceColor = data.priceColor.color[0].item;
        this.toneCouponConfig = data.toneCouponConfig.tabVal;
        this.couponMoneyColor = data.couponMoneyColor.color[0].item;
        this.bntTxtColor = data.bntTxtColor.color[0].item;
        this.couponTypeColor = data.couponTypeColor.color[0].item;
        this.spacingConfig = data.spacingConfig.val;
        this.spacingConfig2 = data.spacingConfig2.val;
        this.vipBgColorLeft = data.vipBgColor.color[0].item;
        this.vipBgColorRight = data.vipBgColor.color[1].item;
        this.couponBgColorLeft = data.couponBgColor.color[0].item;
        this.couponBgColorRight = data.couponBgColor.color[1].item;
        this.couponBgColor2 = data.couponBgColor2.color[0].item;
        this.bntBgColorLeft = data.bntBgColor.color[0].item;
        this.bntBgColorRight = data.bntBgColor.color[1].item;
        const bgColorLeft = data.moduleColor.color[0].item;
        const bgColorRight = data.moduleColor.color[1].item;
        this.bgColor = `linear-gradient(90deg,${bgColorLeft} 0%,${bgColorRight} 100%)`;
        this.themeColor = `linear-gradient(90deg,${this.colorStyle.theme} 0%,${this.colorStyle.gradient} 100%)`;
        this.themeColor2 = `linear-gradient(270deg,${this.colorStyle.theme} 0%,${this.colorStyle.gradient} 100%)`;
        this.bottomBgColor = data.bottomBgColor.color[0].item;
        this.mTop = data.mbConfig.val;
        this.topConfig = data.topConfig.val;
        this.bottomConfig = data.bottomConfig.val;
        this.prConfig = data.prConfig.val;
        const fillet = data.fillet.type;
        const filletVal = data.fillet.val;
        const valList = data.fillet.valList;
        this.bgRadius = fillet ? valList[0].val + 'px ' + valList[1].val + 'px ' + valList[3].val + 'px ' + valList[2].val + 'px' : filletVal + 'px';
      }
    }
  }
};
</script>

<style scoped lang="less">
	.newVip2{
		background: linear-gradient(90deg, #E93323 0%, #FF7931 100%);
		width: 100%;
		border-radius: 12px;
		padding: 0 12px 16px 12px;
		.header{
			height: 46px;
			.right{
				font-size: 12px;
				color: #FFFFFF;
				.iconfont{
					font-size: 12px;
				}
			}
			.left{
				font-size: 12px;
				font-weight: 400;
				color: #FFFFFF;
				.line{
					width: 1px;
					height: 10px;
					background-color: #fff;
					margin: 0 8px;
				}
				img {
					width: 78px;
					height: 14px;
					display: block;
				}
			}
		}
		.integral{
			height: 70px;
			background: #FFFFFF;
			border-radius: 8px;
			padding: 13px 10px;
			.picTxt{
				.pictrue{
					width: 44px;
					height: 44px;
					img {
						width: 100%;
						height: 100%;
						display: block;
					}
				}
				.text{
					margin-left: 10px;
					.tips{
						font-size: 12px;
						color: #999999;
						margin-top: 3px;
					}
					.name{
						font-size: 15px;
						font-family: PingFang SC, PingFang SC;
						font-weight: 500;
						color: #333333;
						.points{
							width: 40px;
							height: 16px;
							background: #FCEAE9;
							border-radius: 12px;
							font-size: 10px;
							color: #E93323;
							margin-left: 4px;
							.pointsCon{
								background: rgba(255,255,255,0.9);
								width: 100%;
								height: 100%;
							}
							img {
								width: 14px;
								height: 14px;
								display: block;
							}
						}
					}
				}
			}
			.bnt{
				width: 57px;
				height: 26px;
				background: linear-gradient(90deg, #FF7931 0%, #E93323 100%);
				border-radius: 13px;
				text-align: center;
				line-height: 26px;
				font-size: 11px;
				color: #fff;
			}
		}
		.conter{
			background: #FFFFFF;
			border-radius: 8px;
			overflow: hidden;
			padding-bottom: 10px;
			margin-bottom: 10px;
			.goods{
				margin-top: 10px;
				.title{
					font-size: 14px;
					font-weight: 500;
					color: #333333;
					padding: 0 10px;
				}
				.list{
					margin-top: 10px;
					padding-left: 10px;
					display: inline-flex;
					flex-wrap: nowrap;
					.item{
						width: 72px;
						margin-right: 8px;
						.pictrue{
							width: 100%;
							height: 72px;
							background: #F3F9FF;
							border-radius: 6px;
							img {
								width: 45px;
								height: 34px;
								display: block;
							}
						}
						.money{
							font-size: 16px;
							font-family: D-DIN-PRO, D-DIN-PRO;
							font-weight: 600;
							color: #333333;
							text-align: center;
							margin-top: 5px;
						}
						.name{
							font-size: 12px;
							font-family: PingFang SC, PingFang SC;
							font-weight: 400;
							color: #333333;
						}
					}
				}
			}
			.coupon{
				.title{
					font-size: 14px;
					color: #333333;
					padding: 12px 10px 10px 10px;
					font-weight: 500;
				}
				.list{
					padding-left: 10px;
					margin-top: 12px;
					display: inline-flex;
					flex-wrap: nowrap;

					.item{
						width: 76px;
						height: 74px;
						background: #F12A13;
						position: relative;
						border-radius: 6px 6px 15px 15px;
						margin-right: 12px;
						.money{
							width: 70px;
							height: 48px;
							background: #FFFFFF;
							border: 1px solid #FCEAE9;
							position: absolute;
							left:3px;
							top:-14px;
							text-align: center;
							font-size: 20px;
							font-family: D-DIN-PRO, D-DIN-PRO;
							font-weight: 600;
							color: #E93323;
							padding-top: 12px;
							border-radius: 6px 6px 0 0;
							.lable{
								font-size: 14px;
							}
						}
						.sill{
							position: absolute;
							bottom: 0;
							left:0;
							width: 100%;
							height: 39px;
							background: linear-gradient(90deg, #E93323 0%, #FF7931 100%);
							color: #fff;
							line-height: 46px;
							border-radius: 0 0 15px 15px;
							font-size: 10px;
							text-align: center;
						}
						img {
							position: absolute;
							left:0;
							width: 76px;
							height: 10px;
							bottom:32px;
						}
					}
				}
			}
		}
	}
	.newVip{
		padding: 16px 10px 10px 10px;
		background-color: #fff;
		.header{
			.title{
				font-size: 16px;
				color: #333333;
				font-family: PingFang SC, PingFang SC;
				font-weight: 500;
			}
			.more{
				font-size: 12px;
				color: #999999;
				.iconfont{
					font-size: 12px;
				}
			}
		}
		.integral{
			width: 100%;
			height: 40px;
			background: #F6ADA7;
			border:1px solid #F6ADA7;
			border-radius: 8px;
			margin-top: 10px;
			.integralCon{
				border-radius: 7px;
				background: rgba(255,255,255,0.9);
				font-size: 13px;
				color: #333333;
				width: 100%;
				height: 100%;
				padding: 0 16px;

				img {
					width: 27px;
					height: 27px;
					display: block;
					margin-right: 8px;
				}
			}
		}
		.coupon{
			.title{
				font-size: 14px;
				color: #333333;
				margin-top: 14px;
			}
			.list{
				margin-top: 10px;
				width: 100%;
				height: 105px;
				background: linear-gradient(270deg, #FD5E35 0%, #FFBA87 99%);
				border-radius: 12px;
				padding: 6px;
				position: relative;
				flex-wrap: nowrap;
				overflow: hidden;
				.pocket{
					width: 95px;
					height: 105px;
					background: linear-gradient(270deg, #FF7F3F 0%, #FFA157 100%);
					position: absolute;
					right: 0;
					top:0;
					border-radius: 0 12px 12px 0;
					color: #fff;
					text-align: center;
					.money{
						font-size: 20px;
						font-family: D-DIN-PRO, D-DIN-PRO;
						margin-top: 10px;
						.lable{
							font-size: 14px;
						}
					}
					.tips{
						font-size: 10px;
						margin-top: 5px;
					}
					.bnt {
						width: 68px;
						height: 26px;
						background: #FFFFFF;
						border-radius: 13px;
						text-align: center;
						line-height: 26px;
						color: #E93323;
						font-size: 11px;
						margin: 6px auto 0 auto;
					}
				}
				.item{
					background-image: url('../../assets/images/couponBg.png');
					background-repeat: no-repeat;
					background-size: 100% 100%;
					width: 72px;
					height: 94px;
					margin-right: 4px;
					.sill{
						font-size: 9px;
						font-weight: 400;
						color: #666666;
						text-align: center;
						margin-top: 2px;
					}
					.type{
						font-size: 11px;
						color: #E93323;
						font-weight: 500;
						text-align: center;
						margin-top: 7px;
					}
					.money{
						font-size: 20px;
						font-family: D-DIN-PRO, D-DIN-PRO;
						font-weight: 600;
						color: #E93323;
						height: 48px;
						line-height: 48px;
						text-align: center;
						width: 72px;
						.lable{
							font-size: 14px;
						}
					}
				}
			}
		}
		.goods{
			overflow: hidden;
			.title{
				font-size: 14px;
				color: #333333;
				font-weight: 500;
				margin-top: 14px;
			}
			.list{
				margin-top: 10px;
				flex-wrap: nowrap;
				display: inline-flex;
				.item{
					width: 79px;
					&~.item{
						margin-left: 6px;
					}
					.pictrue{
						width: 100%;
						height: 79px;
						background: #F3F9FF;
						border-radius: 6px;
						img {
							width: 45px;
							height: 34px;
						}
					}
					.name{
						font-size: 12px;
						color: #333333;
						margin-top: 5px;
					}
					.money{
						text-align: center;
						font-weight: 600;
						color: #E93323;
						font-size: 16px;
						.lable{
							font-size: 11px;
						}
					}
				}
			}
		}
	}
</style>
