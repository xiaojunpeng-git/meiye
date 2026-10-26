<script>
import propertyList from '@/utils/propertyList.js';
import template1 from './template1.vue';
import template2 from './template2.vue';
export default {
	components: {
		template1,
		template2
	},
	props: {
		userInfo: {
			type: Object,
			default: () => {}
		},
		orderData: {
			type: Object,
			default: () => {}
		},
		orderMenu: {
			type: Array,
			default() {
				return [];
			}
		},
		orderAdminData: {
			type: Object,
			default: () => {}
		}
	},
	watch: {
		orderData: {
			handler(nVal, oVal) {
				// this.iconFilter(nVal.style);
			},
			immediate: true,
			deep: true
		}
	},
	data() {
		return {
			property: []
		};
	},
	methods: {}
};
</script>

<template>
	<view>
		<template1 v-if="orderData.style == 1" :orderMenu="orderMenu" :notPayOrder="orderAdminData.not_pay_order" :commission="orderAdminData.commission"></template1>
		<template2 v-if="orderData.style == 2 || orderData.style == 3" :notPayOrder="orderAdminData.not_pay_order" :orderStyle="orderData.style" :orderMenu="orderMenu"></template2>
	</view>
</template>

<style lang="scss" scoped>

.text-primary-con {
	color: var(--view-theme);
}

</style>
