<template>
	<merchant-store-picker
		:value="value"
		mode="multiple"
		:label="label"
		:placeholder="placeholder"
		:snapshot="snapshot"
		:realtime="realtime"
		:disabled="disabled"
		@input="$emit('input', $event)"
		@change="$emit('change', $event)"
	/>
</template>

<script>
import MerchantStorePicker from '@/components/merchantStorePicker/index.vue';

/**
 * 多选组织/门店：支持半选与排除
 * snapshot=true 用于创建/分配固化；false 用于分析实时筛选
 */
export default {
	name: 'MerchantStoreMultiPicker',
	components: { MerchantStorePicker },
	props: {
		value: { type: Object, default: null },
		label: { type: String, default: '选择组织/门店' },
		placeholder: { type: String, default: '请选择组织或门店' },
		snapshot: { type: Boolean, default: false },
		realtime: { type: Boolean, default: true },
		disabled: { type: Boolean, default: false },
	},
};
</script>
