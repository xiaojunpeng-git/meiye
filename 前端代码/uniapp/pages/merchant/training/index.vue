<template>
	<view class="page">
		<view class="search-bar">
			<input
				class="search-input"
				v-model="keyword"
				placeholder="搜索资料名称"
				confirm-type="search"
				@confirm="reload"
			/>
			<view class="search-btn" @click="reload">查询</view>
		</view>
		<view v-if="loading && !list.length" class="empty">加载中…</view>
		<template v-else-if="list.length">
			<view class="card" v-for="item in list" :key="item.id">
				<view class="title">
					<text v-if="Number(item.is_required) === 1" class="tag">必读</text>
					{{ item.title }}
				</view>
				<view class="meta">
					{{ item.category || '培训资料' }} · {{ item.version || '-' }} · {{ item.file_name || '-' }}
				</view>
				<view class="summary">{{ item.summary || '请下载并按最新版资料操作。' }}</view>
				<view class="actions">
					<view
						class="btn"
						:class="{ disabled: !Number(item.allow_download) || downloadingId === item.id }"
						@click="onDownload(item)"
					>
						{{ downloadLabel(item) }}
					</view>
				</view>
			</view>
		</template>
		<view v-else class="empty">暂无可查看的培训资料</view>
	</view>
</template>

<script>
import merchantGuard from '@/mixins/merchantGuard.js';
import {
	merchantTrainingDocuments,
	merchantTrainingDocumentDownloadUrl,
} from '@/api/merchant.js';
import { HTTP_REQUEST_URL, TOKENNAME } from '@/config/app';
import store from '@/store';

export default {
	mixins: [merchantGuard],
	data() {
		return {
			keyword: '',
			list: [],
			loading: false,
			downloadingId: 0,
		};
	},
	async onShow() {
		const ok = await this.ensureMerchantAccess({
			permission: 'merchant.profile.view',
			fallbackMerchantHome: true,
		});
		if (!ok) return;
		this.reload();
	},
	methods: {
		contextParams() {
			return {
				active_store_id: this.$store.state.merchant.activeStoreId || 0,
				active_role: this.$store.state.merchant.activeRole || '',
			};
		},
		downloadLabel(item) {
			if (!Number(item.allow_download)) return '仅在线查看';
			if (this.downloadingId === item.id) return '下载中…';
			return '下载资料';
		},
		async reload() {
			this.loading = true;
			try {
				const res = await merchantTrainingDocuments({
					...this.contextParams(),
					page: 1,
					limit: 50,
					keyword: this.keyword,
				});
				this.list = (res && res.data && res.data.list) || [];
			} catch (e) {
				this.list = [];
				const msg = (e && (e.msg || e.message)) || '加载失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				this.loading = false;
			}
		},
		onDownload(item) {
			if (!item || !Number(item.allow_download) || this.downloadingId) return;
			const id = Number(item.id || 0);
			if (id <= 0) return;
			this.downloadingId = id;
			const path = merchantTrainingDocumentDownloadUrl(id, this.contextParams());
			const base = String(HTTP_REQUEST_URL || '').replace(/\/$/, '');
			const url = `${base}/api/${path.replace(/^\//, '')}`;
			const token = (store.state.app && store.state.app.token) || '';
			uni.downloadFile({
				url,
				header: token ? { [TOKENNAME]: 'Bearer ' + token } : {},
				success: (res) => {
					if (res.statusCode !== 200 || !res.tempFilePath) {
						uni.showToast({ title: '下载失败', icon: 'none' });
						return;
					}
					uni.openDocument({
						filePath: res.tempFilePath,
						showMenu: true,
						fail: () => {
							uni.showToast({ title: '已下载，当前环境无法直接打开', icon: 'none' });
						},
					});
				},
				fail: () => {
					uni.showToast({ title: '下载失败', icon: 'none' });
				},
				complete: () => {
					this.downloadingId = 0;
				},
			});
		},
	},
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	background: #f5f6f8;
	padding: 24rpx;
	padding-bottom: 40rpx;
}
.search-bar {
	display: flex;
	align-items: center;
	background: #fff;
	border-radius: 16rpx;
	padding: 12rpx 16rpx;
	margin-bottom: 20rpx;
}
.search-input {
	flex: 1;
	font-size: 28rpx;
	padding: 12rpx;
}
.search-btn {
	padding: 12rpx 24rpx;
	font-size: 26rpx;
	color: #fff;
	background: #e93323;
	border-radius: 12rpx;
}
.card {
	background: #fff;
	border-radius: 16rpx;
	padding: 28rpx 24rpx;
	margin-bottom: 20rpx;
}
.title {
	font-size: 32rpx;
	font-weight: 600;
	color: #222;
	line-height: 1.4;
}
.tag {
	display: inline-block;
	margin-right: 10rpx;
	padding: 2rpx 10rpx;
	font-size: 20rpx;
	font-weight: 500;
	color: #e93323;
	background: #fff1f0;
	border-radius: 6rpx;
	vertical-align: middle;
}
.meta,
.summary {
	margin-top: 12rpx;
	font-size: 24rpx;
	color: #888;
	line-height: 1.5;
}
.actions {
	margin-top: 20rpx;
}
.btn {
	display: inline-block;
	padding: 14rpx 28rpx;
	font-size: 26rpx;
	color: #fff;
	background: #e93323;
	border-radius: 12rpx;
}
.btn.disabled {
	opacity: 0.45;
}
.empty {
	text-align: center;
	color: #999;
	font-size: 26rpx;
	padding-top: 160rpx;
}
</style>
