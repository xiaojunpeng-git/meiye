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
		menuData: {
			type: Object,
			default: () => {}
		},
		routineContact: {
			type: Number,
			default: 0
		}
	}
};
</script>

<template>
	<view v-if="parseInt(menuData.is_show)">
		<template1 v-if="menuData.style == 1" :menuData="menuData" :routineContact='routineContact'></template1>
		<template2 v-if="menuData.style == 2" :menuData="menuData.list" :routineContact='routineContact'></template2>
		<template3 v-if="menuData.style == 3" :menuData="menuData.list" :routineContact='routineContact'></template3>
	</view>
</template>

<style></style>
