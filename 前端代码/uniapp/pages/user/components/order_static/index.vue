<script>
import template1 from './template1.vue';
import template2 from './template2.vue';
export default {
	components: {
		template1,
		template2
	},
	props: {
		orderAdminData: {
			type: Object,
			default: () => {}
		},
		orderStaticData: {
			type: Object,
			default() {
				return () => {};
			}
		}
	}
};
</script>

<template>
	<view v-if="parseInt(orderStaticData.is_show)">
		<template1 v-if="orderStaticData.style == 1" :staticData="orderAdminData"></template1>
		<template2 v-if="orderStaticData.style == 2" :staticData="orderAdminData"></template2>
	</view>
</template>

<style></style>
