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
		agentMenuData: {
			type: Object,
			default: () => {}
		}
	}
};
</script>

<template>
	<view v-if="parseInt(agentMenuData.is_show)">
		<template1 v-if="agentMenuData.style == 1" :menuData="agentMenuData"></template1>
		<template2 v-if="agentMenuData.style == 2" :menuData="agentMenuData.list"></template2>
		<template3 v-if="agentMenuData.style == 3" :menuData="agentMenuData.list"></template3>
	</view>
</template>

<style></style>