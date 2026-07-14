<template>
	<div :style="{
		background:bottomBgColor,
		marginTop:mTop+'px',
		paddingTop:topConfig+'px',
		paddingBottom:bottomConfig+'px',
		paddingLeft:prConfig+'px',
		paddingRight:prConfig+'px'
	}">
		<div v-if="styleConfig" class="userInfor acea-row row-between-wrapper" :style="{
			background: `linear-gradient(90deg,${bgColorLeft} 0%,${bgColorRight} 100%)`,
			borderRadius:bgRadius
			}">
		    <div class="left acea-row row-middle">
		        <div class="pictrue acea-row row-center-wrapper">
					<img :src="logoConfig" v-if="logoConfig"/>
		            <div class="empty-box" v-else>
						<img src="../../assets/images/shan.png"/>
					</div>
		        </div>
		        <div class="text">
		            <div class="name acea-row row-middle" :style="[userColorTxt]">用户名称<img src="../../assets/images/vip-diy.png"/></div>
		            <div class="acea-row row-middle">
		                <div class="progress" :style="{
							background:toneConfig?progressBgColor:'#eee'
						}">
		                    <div class="bgReds" :style="{
								background: toneConfig?`linear-gradient(90deg,${progressLeft} 0%,${progressRight} 100%)`:themeColor
							}"></div>
		                </div>
		                <div class="percent" :style="[progressNumColorTxt]">3000/1000000</div>
		            </div>
		            <!--<div class="phone"><span class="iconfont iconshouji"></span>13000000000</div>-->
		        </div>
		    </div>
		    <div class="right acea-row row-bottom">
		        <div class="item" v-if="checkType.slice(0,3).indexOf(1) != -1">
		            <div class="num" :style="[numColorTxt]">20</div>
		            <div :style="[titleColorTxt]">积分</div>
		        </div>
		        <div class="item" v-if="checkType.slice(0,3).indexOf(2) != -1">
		            <div class="num" :style="[numColorTxt]">200</div>
		            <div :style="[titleColorTxt]">余额</div>
		        </div>
		        <div class="item" v-if="checkType.slice(0,3).indexOf(0) != -1">
		            <div class="num" :style="[numColorTxt]">2</div>
		            <div :style="[titleColorTxt]">优惠券</div>
		        </div>
				<div class="item" v-if="checkType.slice(0,3).indexOf(4) != -1">
				    <div class="num" :style="[numColorTxt]">80</div>
				    <div :style="[titleColorTxt]">收藏</div>
				</div>
				<div class="item" v-if="checkType.slice(0,3).indexOf(5) != -1">
				    <div class="num" :style="[numColorTxt]">80</div>
				    <div :style="[titleColorTxt]">浏览</div>
				</div>
		        <div class="item" :style="[codeColorTxt]" v-if="checkType.slice(0,3).indexOf(3) != -1">
		            <div class="iconfont iconerweima-xingerenzhongxin"></div>
		            <div>会员码</div>
		        </div>
		    </div>
		</div>
		<div v-else class="userInfor" :style="{
			background: `linear-gradient(90deg,${bgColorLeft} 0%,${bgColorRight} 100%)`,
			borderRadius:bgRadius
			}">
			<div class="acea-row row-between-wrapper">
				<div class="left acea-row row-middle">
				    <div class="pictrue acea-row row-center-wrapper">
						<img :src="logoConfig" v-if="logoConfig"/>
				        <div class="empty-box" v-else>
				        	<img src="../../assets/images/shan.png"/>
				        </div>
				    </div>
				    <div class="text">
				        <div class="name acea-row row-middle" :style="[userColorTxt]">用户名称<img src="../../assets/images/vip-diy.png"/></div>
				        <div class="acea-row row-middle">
				            <div class="progress" :style="{
								background:toneConfig?progressBgColor:'#eee'
							}">
				                <div class="bgReds" :style="{
									background: toneConfig?`linear-gradient(90deg,${progressLeft} 0%,${progressRight} 100%)`:themeColor
								}"></div>
				            </div>
				            <div class="percent" :style="[progressNumColorTxt]">3000/1000000</div>
				        </div>
				        <!--<div class="phone"><span class="iconfont iconshouji"></span>13000000000</div>-->
				    </div>
				</div>
				<div class="right on acea-row row-bottom">
					<div class="item" :style="[codeColorTxt]" v-if="checkType.indexOf(3) != -1">
					    <div class="iconfont iconerweima-xingerenzhongxin"></div>
					    <div>会员码</div>
					</div>
				</div>
			</div>
			<div class="list acea-row row-around">
			    <div class="item" v-if="checkType.indexOf(1) != -1">
					<div class="num" :style="[numColorTxt]">20000</div>
			        <div :style="[titleColorTxt]">积分</div>
			    </div>
			    <div class="item" v-if="checkType.indexOf(2) != -1">
					<div class="num" :style="[numColorTxt]">200</div>
			        <div :style="[titleColorTxt]">余额</div>
			    </div>
			    <div class="item" v-if="checkType.indexOf(0) != -1">
					<div class="num" :style="[numColorTxt]">2</div>
			        <div :style="[titleColorTxt]">优惠券</div>
			    </div>
				<div class="item" v-if="checkType.indexOf(4) != -1">
					<div class="num" :style="[numColorTxt]">80</div>
				    <div :style="[titleColorTxt]">收藏</div>
				</div>
				<div class="item" v-if="checkType.indexOf(5) != -1">
					<div class="num" :style="[numColorTxt]">80</div>
				    <div :style="[titleColorTxt]">浏览</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { mapState, mapMutations } from 'vuex';
// import theme from "@/mixins/theme";
export default {
  name: 'home_userInfor',
  cname: '用户信息',
  configName: 'c_userInfor',
  icon: '#iconzujian-yonghuxinxi',
  type: 0, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'userInfor', // 外面匹配名称
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
    userColorTxt() {
      return {
        color: this.userColor
      };
    },
    numColorTxt() {
      return {
        color: this.numColor
      };
    },
    titleColorTxt() {
      return {
        color: this.titleColor
      };
    },
    codeColorTxt() {
      return {
        color: this.codeColor
      };
    },
    progressNumColorTxt() {
      return {
        color: this.progressNumColor
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
        cname: '用户信息',
        name: 'userInfor',
        timestamp: this.num,
        isHide: false,
        setUp: {
          tabVal: 0
        },
        titleLeft: '展示设置',
        titleImg: '默认头像',
        titleRight: '进度条',
        titleCurrency: '通用样式',
        titleTxt: '文字样式',
        userColor: {
          title: '用户名称',
          default: [
						    {
						       item: '#333'
						    }
          ],
          color: [
						    {
						        item: '#333'
						    }
          ]
        },
        numColor: {
          title: '数字样式',
          default: [
						    {
						       item: '#333'
						    }
          ],
          color: [
						    {
						        item: '#333'
						    }
          ]
        },
        titleColor: {
          title: '标题样式',
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
        codeColor: {
          title: '会员码',
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
        progressNumColor: {
          title: '进度样式',
          default: [
						    {
						       item: '#333'
						    }
          ],
          color: [
						    {
						        item: '#333'
						    }
          ]
        },
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
          title: '是否展示',
          name: 'checkboxInfo',
          userType: 1,
          type: [3, 1, 2],
          list: [
            {
							    id: 3,
							    name: '会员码'
            },
            {
							    id: 1,
							    name: '积分'
            },
            {
							    id: 2,
							    name: '余额'
            },
            {
							    id: 4,
							    name: '收藏'
            },
            {
              id: 0,
              name: '优惠券'
            },
            {
							    id: 5,
							    name: '浏览'
            }
          ]
        },
        logoConfig: {
          info: '建议：图片尺寸90px * 90px',
          url: '',
          type: 'code',
          delType: 1,
          name: '上传图片'
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
        progressColor: {
					    title: '进度条',
					    default: [
					        {
					           item: '#e93323'
					        },
					        {
					           item: '#ff8933'
					        }
					    ],
					    color: [
					        {
					            item: '#e93323'
					        },
					        {
					            item: '#ff8933'
					        }
					    ]
        },
        progressBgColor: {
          title: '进度条背景',
          default: [
						    {
						       item: '#EEEEEE'
						    }
          ],
          color: [
						    {
						        item: '#EEEEEE'
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
						       item: '#fff'
						    }
          ],
          color: [
						    {
						        item: '#fff'
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
					    title: '页面间距',
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
          val: 0,
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
      checkType: [],
      logoConfig: '',
      progressLeft: '',
      progressRight: '',
      progressBgColor: '',
      bgColorLeft: '',
      bgColorRight: '',
      bottomBgColor: '',
      topConfig: 0,
      bottomConfig: 0,
      prConfig: 0,
      mTop: '',
      bgRadius: 0,
      toneConfig: 0,
      themeColor: '',
      userColor: '',
      numColor: '',
      titleColor: '',
      codeColor: '',
      progressNumColor: ''
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
        this.userColor = data.userColor.color[0].item;
        this.numColor = data.numColor.color[0].item;
        this.titleColor = data.titleColor.color[0].item;
        this.codeColor = data.codeColor.color[0].item;
        this.progressNumColor = data.progressNumColor.color[0].item;
        this.styleConfig = data.styleConfig.tabVal;
        this.checkType = data.checkboxInfo.type;
        this.logoConfig = data.logoConfig.url;
        this.toneConfig = data.toneConfig.tabVal;
        this.progressLeft = data.progressColor.color[0].item;
        this.progressRight = data.progressColor.color[1].item;
        this.progressBgColor = data.progressBgColor.color[0].item;
        this.bgColorLeft = data.moduleColor.color[0].item;
        this.bgColorRight = data.moduleColor.color[1].item;
        this.themeColor = `linear-gradient(90deg,${this.colorStyle.theme} 0%,${this.colorStyle.gradient} 100%)`;
        this.bottomBgColor = data.bottomBgColor.color[0].item;
        this.topConfig = data.topConfig.val;
        this.bottomConfig = data.bottomConfig.val;
        this.prConfig = data.prConfig.val;
        this.mTop = data.mbConfig.val;
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
   .userInfor{
       padding: 14px 10px;
	   .list{
		   margin-top: 16px;
		   padding-top: 16px;
		   border-top:1px solid #eee;
		   .item{
			   font-size: 13px;
			   color: #999;
			   text-align: center;
			   .num{
				   font-size: 14px;
				   font-weight: 600;
				   color: #333333;
				   margin-left: 4px;
			   }
		   }
	   }
       .right{
		   &.on{
			   .item{
				   color: #666666;
			   }
		   }
           .item{
               text-align: center;
               font-weight: 400;
               color: #999;
               font-size: 11px;
               margin-left: 16px;
               .num{
                   font-size: 16px;
                   margin-bottom: 2px;
				   font-weight: 600;
				   color: #333333;
               }
           }
           .iconfont{
               font-weight: 400;
               //color: #333333;
               font-size: 15px;
               margin-bottom: 2px;
           }
       }
       .left{
           .pictrue{
               width: 45px;
               height: 45px;
               border-radius: 50%;
               margin-right: 10px;
			   img {
				   border-radius: 50%;
				   width: 100%;
				   height: 100%;
				   object-fit: cover;
			   }
               .empty-box{
                   border-radius: 50%;
				   background-color: #F3F9FF;
				   border: 1px solid #EEEEEE;
                   .iconfont-diy{
                       font-size: 20px;
                   }
				   img {
					   width: 26px;
					   height: 20px;
				   }
               }
           }
           .text{
               font-weight: 400;
               color: #333333;
               font-size: 16px;
			   .name{
				   img{
					   width: 38px;
					   height: 16px;
					   margin-left: 5px;
				   }
			   }
               .progress{
                   overflow: hidden;
                   background-color: #EEEEEE;
                   width: 60px;
                   height: 7px;
                   border-radius: 3px;
                   position: relative;
                   .bgReds{
                       width: 80%;
                       height: 100%;
                       transition: width 0.6s ease;
					   border-radius: 3px;
                   }
               }
			   .percent{
				   font-size: 10px;
				   margin-left: 4px;
			   }
               .phone{
                   font-weight: 400;
                   //color: #333;
                   font-size: 10px;
                   margin-top: 3px;
                   .iconshouji{
                       margin-right: 2px;
                       font-size: 12px;
                   }
               }
           }
       }
   }
</style>
