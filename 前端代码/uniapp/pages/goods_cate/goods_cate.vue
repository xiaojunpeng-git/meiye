<template>
  <!-- 商品分类 -->
	<view :style="colorStyle">
    <!-- 商品分类的三种样式布局 pdHeight:自定义导航diy设置的上下边距 -->
		<template1 v-if="activeIndex == 0" :level="level" :isFooter="isFooter" :pdHeight="pdHeight" ref="classOne"></template1>
		<template2 v-if="showTemplateTwo"  :level="level" :showType="activeIndex" :isFooter="isFooter" ref="classTwo"></template2>
		<template3 v-if="showTemplateThree" :showType="activeIndex" :isFooter="isFooter" ref="classThree"></template3>
		<template4 v-if="showTemplateFour"  :level="level" :isFooter="isFooter" :pdHeight="pdHeight" @toggleBar="toggleBar" ref="classFour"></template4>
		<pageFooter @newDataStatus="newDataStatus" v-show="showBar"></pageFooter>
	</view>
</template>

<script>
	import colors from "@/mixins/color";
	import template1 from './template/template1.vue';
	import template2 from './template/template2.vue';
	import template3 from './template/template3.vue';
	import template4 from './template/template4.vue';
	import pageFooter from '@/components/pageFooter/index.vue'
	import { colorChange } from '@/api/api.js';
	import { mapGetters } from 'vuex';
	export default {
		computed: {
			...mapGetters(['isLogin', 'uid', 'diyCategory','cartNum']),
			//diyCategory 暴露两个属性 level 和 index ,分别为分类等级和模板下标
			level(){
				return this.diyCategory.level
			},
			activeIndex(){
				return this.diyCategory.index
			},
			showTemplateTwo(){
				if(([2,3].includes(this.activeIndex) && this.level == 2) || ([1,2].includes(this.activeIndex) && this.level == 3)){
					return true
				}
			},
			showTemplateThree(){
				if([1,4].includes(this.activeIndex) && this.level == 2){
					return true
				}
			},
			showTemplateFour(){
				if((this.activeIndex == 5 && this.level == 2) || (this.activeIndex == 3 && this.level == 3)){
					return true
				}
			}
		},
		components: {
			template1,
			template2,
			template3,
			template4,
			pageFooter
		},
		mixins: [colors],
		provide(){
			return {
				parent:this
			}
		},
		data() {
			return {
				isFooter:false,
				showBar:false,
				pdHeight:0,
			}
		},
		onShow(){
			// // 这里是 单店 调用；
			let shopOperationType = uni.getStorageSync('shop_operation_type');
			if(shopOperationType ==3){
				setTimeout((res)=>{
					if(this.activeIndex == 0){
						this.$refs.classOne.getAllCategory();
					}
					if(this.showTemplateTwo){
						this.$refs.classTwo.getAllCategory();
					}else if(this.showTemplateThree){
						this.$refs.classThree.getAllCategory();
					}else if(this.showTemplateFour){
						this.$refs.classFour.getAllCategory();
					}
				},100)
			}
			if (this.cartNum > 0) {
				uni.setTabBarBadge({
					index: 3,
					text: this.cartNum > 99 ? '99+' : this.cartNum + ''
				})
			} else {
				uni.hideTabBarRedDot({
					index: 3
				})
			}
			if(this.isLogin){
				setTimeout((res)=>{
					if(this.showTemplateTwo){
						this.$refs.classTwo.getCartNum()
					}else if(this.showTemplateThree){
						this.$refs.classThree.getCartNum()
					}
				},100)
			}
		},
		methods: {
			// 商品详情页添加商品，返回详情页时要跟新购物车数据（为了页面效果返回时禁止调用接口）
			otherFun(e){
				if(!!e){
					if(this.showTemplateTwo){
						this.$refs.classTwo.updateFun(e)
					}else if(this.showTemplateThree){
						this.$refs.classThree.updateFun(e)
					}
				}
			},
			newDataStatus(val,num){
				this.isFooter = val ? true : false;
				this.showBar = val ? true : false;
				this.pdHeight = num;
			},
			toggleBar(val){
				this.showBar = val;
			},
		},
		onPageScroll(e) {
			if(this.showTemplateFour || this.showTemplateTwo){
				uni.$emit('scroll', e.scrollTop);
			}
		},
		onReachBottom() {
			if(this.showTemplateFour){
				this.$refs.classFour.productslist();
			}
			if(this.showTemplateTwo){
				uni.$emit('onReachBottom');
			}
		}
	}
</script>
