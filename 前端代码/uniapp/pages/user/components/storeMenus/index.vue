<script>
import template1 from './template1.vue';
import template2 from './template2.vue';
import template3 from './template3.vue';
export default {
	components: {
		template1,
		template2,
		template3
	},
	props: {
		storeMenuData: {
			type: Object,
			default: () => {}
		}
	}
};
</script>

<template>
	<view v-if="parseInt(storeMenuData.is_show)">
		<template1 v-if="storeMenuData.style == 1" :menuData="storeMenuData"></template1>
		<template2 v-if="storeMenuData.style == 2" :menuData="storeMenuData.list"></template2>
		<template3 v-if="storeMenuData.style == 3" :menuData="storeMenuData.list"></template3>
	</view>
</template>

<style></style>
