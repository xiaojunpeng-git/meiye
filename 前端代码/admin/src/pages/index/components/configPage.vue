<template>
	<Card :bordered="false" dis-hover class="ivu-mb">
		<div class="title acea-row row-between-wrapper">
			<div>完善您的店铺搭建，快速开启您的生意～</div>
			<div class="bnt" @click="collapse">{{open?'收起':'展开'}}<span class="iconfont" :class='open?"iconshangyi":"iconxiayi"'></span></div>
		</div>
		<div class="acea-row mt20 h-282 overflow" v-if="open">
			<Menu :theme="theme" :active-name="sortName" width="205px">
			  <MenuGroup>
			    <MenuItem
			      :name="index"
			      class="menu-item"
			      v-for="(item, index) in configList"
			      :key="index"
				  @click.native="bindMenuItem(index)"
			    >
			      {{ item.name }}
			    </MenuItem>
			  </MenuGroup>
			</Menu>
			<div class="rightBox" v-for="(item, index) in configList" v-if="index == sortName">
				<div class="text">
					<div class="name">{{item.name}}</div>
					<div>{{item.info}}</div>
					<Button type="primary" class="bnt" @click="goPage(item.url)">{{item.bntName}}</Button>
				</div>
				<div class="pictrue">
					<img :src="item.image"/>
				</div>
			</div>
		</div>
	</Card>
</template>

<script>
	import Setting from '@/setting';
	export default {
		name: "configPage",
		data() {
			return {
				roterPre: Setting.roterPre,
				theme: 'light',
				sortName: 0,
				open:true,
				configList:[
					{
						name:'系统配置',
						info:'搭建您的基础网站，配置存储信息，设定第三方基础信息',
						image:require('@/assets/images/config01.png'),
						url:'/setting/shop/base',
						bntName:'去配置'
					},
					{
						name:'应用设置',
						info:'根据运营渠道，配置您的小程序/公众号/PC/APP',
						image:require('@/assets/images/config02.png'),
						url:'/app/wechat/base',
						bntName:'去设置'
					},
					{
						name:'店铺配置',
						info:'选择商城运营模式，装修页面，为您的商城挑选合适的样式',
						image:require('@/assets/images/config03.png'),
						url:'/setting/pages/devise',
						bntName:'去配置'
					},
					{
						name:'商品添加',
						info:'将售卖商品添加至商城后台，上架后即可售卖',
						image:require('@/assets/images/config04.png'),
						url:'/product/product_list',
						bntName:'去添加'
					},
					{
						name:'用户设置',
						info:'维护用户基础信息，根据运营需求开通会员等级及付费会员',
						image:require('@/assets/images/config05.png'),
						url:'/user/setup_user',
						bntName:'去设置'
					},
					{
						name:'商城设置',
						info:'配置商城交易、配送、支付信息',
						image:require('@/assets/images/config06.png'),
						url:'/setting/city/delivery/setting',
						bntName:'去设置'
					}
				]
			}
		},
		created(){
			const cachedState = localStorage.getItem('contentState');
			    if (cachedState !== null) {
			      this.open = JSON.parse(cachedState);
			    }
		},
		methods: {
			bindMenuItem(index) {
				this.sortName = index;
			},
			collapse(){
				this.open = !this.open;
				// 将状态保存到 localStorage
				localStorage.setItem('contentState', JSON.stringify(this.open));
			},
			goPage(url){
				this.$router.push({ path: this.roterPre + url });
			}
		}
	}
</script>

<style lang="stylus" scoped>
	/deep/.ivu-menu-item-group > ul{
		padding: 9px 0 !important
	}
	/deep/.ivu-menu-vertical .ivu-menu-item{
		padding: 12.5px 24px;
	}
	/deep/ .ivu-menu-vertical .ivu-menu-item-group-title {
	  display: none;
	}
	/deep/ .ivu-menu-vertical.ivu-menu-light:after {
	  display: none;
	}
	/deep/.ivu-btn{
		padding: 0 30px;
	}
	.ivu-mb{
		margin-bottom: 12px !important;
	}
	.title{
		font-size: 16px;
		color: #303133;
		font-weight: 600;
		cursor: pointer;
		.bnt{
			color: #909399;
			font-weight: 400;
			font-size: 14px;
			.iconfont{
				margin-left: 6px;
				font-size: 14px;
			}
		}
	}
	.ivu-menu-light{
		background: #FBFCFF;
	}
	.rightBox{
		width: calc(100% - 205px);
		height: 282px;
		background: linear-gradient( 94deg, #F8F9FC 0%, #DDEBFF 100%);
		border-left: 1px solid #F0F1F5;
		border-radius: 0 4px 4px 0;
		font-size: 14px;
		color: #909399;
		padding: 26px 32px;
		position: relative;
		.text{
			position: relative;
			z-index: 2;
		}
		.name{
			font-size: 20px;
			color: #303133;
			font-weight: 600;
			font-family: PingFang SC, PingFang SC;
			margin-bottom: 9px;
		}
		.bnt{
			position: absolute;
			top:175px;
		}
		.pictrue{
			width: 288px;
			height: 229px;
			position: absolute;
			right: 56px;
			top:22px;
			img{
				width: 100%;
				height: 100%;
			}
		}
	}
</style>