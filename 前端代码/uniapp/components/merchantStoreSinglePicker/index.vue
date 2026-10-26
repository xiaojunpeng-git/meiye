<template>
	<merchant-store-picker
		:value="value"
		mode="single"
		:label="label"
		:placeholder="placeholder"
		:snapshot="snapshot"
		:realtime="false"
		:disabled="disabled"
		@input="$emit('input', $event)"
		@change="$emit('change', $event)"
	/>
</template>

<script>
import MerchantStorePicker from '@/components/merchantStorePicker/index.vue';

/** 单选门店：组织可浏览不可选，仅门店叶子可选，返回 store_id */
export default {
	name: 'MerchantStoreSinglePicker',
	components: { MerchantStorePicker },
	props: {
		value: { type: Object, default: null },
		label: { type: String, default: '选择门店' },
		placeholder: { type: String, default: '请选择一家门店' },
		snapshot: { type: Boolean, default: true },
		disabled: { type: Boolean, default: false },
	},
};
</script>
