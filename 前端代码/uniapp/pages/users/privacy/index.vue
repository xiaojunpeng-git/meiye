<template>
	<view class="content">
		<view v-if="loading" class="agreement-state">正在加载{{title}}…</view>
		<jyf-parser v-else-if="content" :html="content" :tag-style="tagStyle"></jyf-parser>
		<view v-else class="agreement-state">
			<view>{{loadFailed ? title + '加载失败，请重试' : title + '暂未提供，请联系商家'}}</view>
			<button size="mini" @tap="loadAgreement">重新加载</button>
		</view>
	</view>
</template>

<script>
import parser from '@/components/jyf-parser/jyf-parser';
import { getUserAgreement } from '@/api/user.js';

const agreementTitles = {
	privacy: '隐私政策',
	user: '用户服务协议',
	cancel: '注销协议',
	supplier: '供应商入驻协议',
	apply_store: '加盟门店入驻协议',
	member: '会员服务协议'
};

export default {
	components: {
		'jyf-parser': parser
	},
	data() {
		return {
			tagStyle: {
				img: 'width:100%;display:block;',
				table: 'width:100%',
				video: 'width:100%'
			},
			content: '',
			type: 'privacy',
			loading: true,
			loadFailed: false
		};
	},
	computed: {
		title() {
			return agreementTitles[this.type];
		}
	},
	onLoad({ type } = {}) {
		this.type = Object.prototype.hasOwnProperty.call(agreementTitles, type) ? type : 'privacy';
		uni.setNavigationBarTitle({ title: this.title });
		return this.loadAgreement();
	},
	methods: {
		async loadAgreement() {
			this.loading = true;
			this.loadFailed = false;
			try {
				const { data } = await getUserAgreement(this.type);
				const html = data && typeof data.content === 'string' ? data.content : '';
				// 富文本编辑器的空段落不算协议正文。协议内容由当前站点提供。
				const hasText = html.replace(/<[^>]*>|&nbsp;|&#160;|&#xA0;/gi, '').trim();
				this.content = hasText ? html : '';
			} catch {
				this.content = '';
				this.loadFailed = true;
			} finally {
				this.loading = false;
			}
		}
	}
};
</script>

<style scoped lang="scss">
page {
	background-color: #fff;
}
.content {
	padding: 0 30rpx 40rpx 30rpx;
}
.agreement-state {
	padding: 60rpx 0;
	text-align: center;
	color: #666;
	button {
		margin-top: 24rpx;
	}
}
</style>
