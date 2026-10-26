<template>
	<view class="rest-page" :style="colorStyle">
		<view class="add-btn" @click="goAdd">
			<text class="iconfont icon-ic_increase"></text>
		</view>
		<view class="rest-card" v-for="item in list" :key="item.id">
			<view class="row acea-row row-between-wrapper">
				<text>休息日期：{{ item.date }}</text>
				<text class="tag">{{ item.type_txt }}</text>
			</view>
			<view class="remark" v-if="item.remark">备注：{{ item.remark }}</view>
			<view class="del-btn" @click="remove(item)">删除</view>
		</view>
		<emptyPage v-if="!list.length && !loading" title="暂无休息记录"></emptyPage>
		<view class="loading-tip" v-if="list.length">{{ loadTitle }}</view>
	</view>
</template>

<script>
import { staffTeacherRestList, staffTeacherRestDelete } from '@/api/store.js';
import emptyPage from '@/components/emptyPage.vue';
import colors from '@/mixins/color.js';

export default {
	components: { emptyPage },
	mixins: [colors],
	data() {
		return {
			list: [],
			page: 1,
			limit: 20,
			loading: false,
			loadend: false,
			loadTitle: '加载更多'
		};
	},
	onShow() {
		this.reload();
	},
	onReachBottom() {
		this.loadList();
	},
	methods: {
		reload() {
			this.page = 1;
			this.loadend = false;
			this.list = [];
			this.loadList();
		},
		loadList() {
			if (this.loading || this.loadend) return;
			this.loading = true;
			staffTeacherRestList({ page: this.page, limit: this.limit }).then(res => {
				const rows = res.data?.list || [];
				this.list = this.$util.SplitArray(rows, this.list);
				this.loadend = rows.length < this.limit;
				this.loadTitle = this.loadend ? '没有更多了' : '加载更多';
				this.page += 1;
				this.loading = false;
			}).catch(() => {
				this.loading = false;
			});
		},
		goAdd() {
			uni.navigateTo({ url: '/pages/admin/staff_center/rest/add' });
		},
		remove(item) {
			uni.showModal({
				title: '提示',
				content: '确认删除该条休息记录？',
				success: (res) => {
					if (!res.confirm) return;
					staffTeacherRestDelete(item.id).then(r => {
						this.$util.Tips({ title: r.msg || '已删除' });
						this.reload();
					}).catch(err => {
						this.$util.Tips({ title: err.msg || err || '删除失败' });
					});
				}
			});
		}
	}
};
</script>

<style scoped lang="scss">
.rest-page {
	min-height: 100vh;
	background: #f5f5f5;
	padding: 24rpx 24rpx 40rpx;
}
.add-btn {
	position: fixed;
	right: 40rpx;
	bottom: 120rpx;
	width: 100rpx;
	height: 100rpx;
	border-radius: 50%;
	background: #07cd9a;
	color: #fff;
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10;
	.iconfont {
		font-size: 44rpx;
	}
}
.rest-card {
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 20rpx;
	font-size: 28rpx;
	color: #282828;
}
.tag {
	color: #07cd9a;
}
.remark {
	margin-top: 12rpx;
	color: #666;
	font-size: 26rpx;
}
.del-btn {
	margin-top: 20rpx;
	text-align: right;
	color: #e93323;
	font-size: 26rpx;
}
.loading-tip {
	text-align: center;
	color: #999;
	font-size: 24rpx;
	padding: 20rpx 0;
}
</style>
