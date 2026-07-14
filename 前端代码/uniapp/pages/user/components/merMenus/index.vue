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
		merMenuData: {
			type: Object,
			default: () => {}
		}
	}
};
</script>

<template>
	<view v-if="parseInt(merMenuData.is_show)">
		<template1 v-if="merMenuData.style == 1" :menuData="merMenuData"></template1>
		<template2 v-if="merMenuData.style == 2" :menuData="merMenuData.list"></template2>
		<template3 v-if="merMenuData.style == 3" :menuData="merMenuData.list"></template3>
	</view>
</template>

<style></style>
