<script>
import propertyList from '@/utils/propertyList.js';
import template1 from './template1.vue';
import template2 from './template2.vue';
import template3 from './template3.vue';
import template4 from './template4.vue';
import template5 from './template5.vue';
// #ifdef MP || APP-PLUS
import topBar from '../topBar.vue';
// #endif
export default {
	components: {
		template1,
		template2,
		template3,
		template4,
		template5,
		// #ifdef MP || APP-PLUS
		topBar
		// #endif
	},
	props: {
		userInfo: {
			type: Object,
			default: () => {}
		},
		memberData: {
			type: Object,
			default: () => {}
		},
		orderAdminData: {
			type: Object,
			default: () => {
				commission:{}
			}
		},
		isScrolling: {
			type: Boolean,
			default: false
		},
		balanceStatus: {
			type: Number,
			default: 0
		}
	},
	data() {
		return {
			property: []
		};
	},
	watch: {
		memberData: {
			handler(nVal, oVal) {
				this.$nextTick((e) => {
					this.getPropertyArr(nVal.property);
				});
			},
			immediate: true,
			deep: true
		},
		userInfo: {
			handler(nVal, oVal) {
				this.$nextTick((e) => {
					this.getPropertyArr(this.memberData.property);
				});
			},
			deep: true
		}
	},
	methods: {
		getPropertyArr(arr) {
			let data = [];
			const propertyFilter = propertyList.filter((item) => {
				return arr.includes(item.value);
			});
			propertyFilter.forEach((item) => {
				if ((item.value == 0 && this.balanceStatus) || item.value != 0) {
					data.push({
						...item,
						value: this.userInfo[item.k]
					});
				}
			});
			data = data.filter((item) => {
				if (!['spread_user_count', 'spread_order_count', 'brokerage_price'].includes(item.k) || this.userInfo.spread_open) {
					return item;
				}
			});
			this.property = data;
		}
	}
};
</script>

<template>
	<view>
		<!-- #ifdef MP || APP-PLUS -->
		<topBar v-if="memberData.style != 5" :styleType="memberData.style" :isScrolling="isScrolling"></topBar>
		<!-- #endif -->
		<template1 v-if="memberData.style == 1" :perShowType="memberData.per_show_type" :userInfo="userInfo" :property="property"></template1>
		<template2 v-if="memberData.style == 2" :perShowType="memberData.per_show_type" :userInfo="userInfo" :property="property"></template2>
		<template3 v-if="memberData.style == 3" :perShowType="memberData.per_show_type" :userInfo="userInfo" :property="property"></template3>
		<template4 v-if="memberData.style == 4" :perShowType="memberData.per_show_type" :userInfo="userInfo" :commission="orderAdminData.commission"></template4>
		<template5
			v-if="memberData.style == 5"
			:perShowType="memberData.per_show_type"
			:userInfo="userInfo"
			:commission="orderAdminData.commission"
			:isScrolling="isScrolling"
			:property="property"
		></template5>
	</view>
</template>

<style lang="scss">
/deep/ .bind-phone {
	margin-top: 12rpx;
	background: rgba(255, 255, 255, 0.3);
	border-radius: 30px;
	width: max-content;
	text-align: center;
	font-size: 20rpx;
	font-weight: 400;
	color: #ffffff;
	line-height: 28rpx;
	padding: 6rpx 16rpx;
}
/deep/ .tips::before {
	content: '';
	position: absolute;
	bottom: -6rpx;
	left: calc(50% - 6rpx);
	width: 0;
	height: 0;
	border-left: 6rpx solid transparent;
	border-right: 6rpx solid transparent;
	border-top: 6rpx solid #ffd89c; /* 修改颜色以改变三角形颜色 */
}
</style>
