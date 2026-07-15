<template>
	<view class="detail-page">
		<view v-if="loading" class="state">加载中…</view>
		<template v-else-if="info.uid">
			<view class="header">
				<image class="avatar" :src="info.avatar || '/static/images/f.png'" mode="aspectFill" />
				<view class="header__body">
					<view class="name">{{ info.nickname || '客户' }}</view>
					<view class="sub" @click="callPhone(info.phone)">{{ maskPhone(info.phone) }} · ID {{ info.uid }}</view>
					<view class="sub">余额 {{ info.now_money || 0 }} · 积分 {{ info.integral || 0 }}</view>
				</view>
			</view>

			<view class="tabs">
				<view class="tab" :class="{ active: tab === 1 }" @click="switchTab(1)">订单信息</view>
				<view class="tab" :class="{ active: tab === 2 }" @click="switchTab(2)">服务记录</view>
				<view class="tab" :class="{ active: tab === 3 }" @click="switchTab(3)">会员档案</view>
			</view>

			<scroll-view v-if="tab < 3" scroll-y class="list" @scrolltolower="loadMore">
				<view v-for="(item, index) in orders" :key="index" class="order-card">
					<view class="order-card__row">
						<text class="order-id" @click="copyText(item.order_id)">{{ item.order_id }}</text>
						<text class="muted">{{ item.add_time }}</text>
					</view>
					<view class="muted" v-if="item.store_name">{{ item.store_name }}</view>
					<view class="muted" v-if="item.link_name">{{ item.link_name }}</view>
					<view class="muted" v-if="item.yeji_staff">{{ item.yeji_staff }}</view>
				</view>
				<view v-if="!orders.length && !orderLoading" class="state">暂无记录</view>
				<view v-if="orderLoading" class="state">加载中…</view>
			</scroll-view>

			<view v-else class="form card">
				<view class="form-row">
					<text class="label">姓名</text>
					<input class="input" v-model="form.real_name" :disabled="!canEdit" placeholder="请输入姓名" />
				</view>
				<view class="form-row">
					<text class="label">生日</text>
					<picker
						v-if="canEdit"
						mode="date"
						:value="form.birthday || '1990-01-01'"
						@change="onBirthday"
					>
						<view class="input">{{ form.birthday || '选择生日' }}</view>
					</picker>
					<text v-else class="input">{{ form.birthday || '-' }}</text>
				</view>
				<view class="form-row">
					<text class="label">性别</text>
					<picker v-if="canEdit" mode="selector" :range="sexOptions" :value="form.sex" @change="onSex">
						<view class="input">{{ sexOptions[form.sex] || '其他' }}</view>
					</picker>
					<text v-else class="input">{{ sexOptions[form.sex] || '其他' }}</text>
				</view>
				<view class="form-row">
					<text class="label">地址</text>
					<input class="input" v-model="form.addres" :disabled="!canEdit" placeholder="选填" />
				</view>
				<view class="form-row">
					<text class="label">备注</text>
					<input class="input" v-model="form.mark" :disabled="!canEdit" placeholder="选填" />
				</view>
				<view class="hint" v-if="!canEdit">当前身份仅可查看，不可编辑档案</view>
				<view class="save" v-if="canEdit" :class="{ disabled: saving }" @click="saveProfile">{{ saving ? '保存中…' : '保存' }}</view>
			</view>
		</template>
		<view v-else class="state">客户不存在或无权查看</view>
	</view>
</template>

<script>
import merchantGuard from '@/mixins/merchantGuard.js';
import {
	merchantCustomerDetail,
	merchantCustomerOrders,
	merchantCustomerUpdate,
} from '@/api/merchant.js';

export default {
	mixins: [merchantGuard],
	data() {
		return {
			uid: 0,
			loading: true,
			info: {},
			canEdit: false,
			tab: 1,
			orders: [],
			page: 1,
			limit: 20,
			loadend: false,
			orderLoading: false,
			form: {
				real_name: '',
				birthday: '',
				sex: 0,
				addres: '',
				mark: '',
			},
			sexOptions: ['其他', '男', '女'],
			saving: false,
		};
	},
	async onLoad(opt) {
		this.uid = Number((opt && opt.uid) || 0);
		const ok = await this.ensureMerchantAccess({
			permission: 'merchant.customer.view',
			fallbackMerchantHome: true,
		});
		if (!ok) return;
		if (!this.uid) {
			this.loading = false;
			return;
		}
		await this.loadDetail();
		this.reloadOrders();
	},
	methods: {
		contextParams() {
			return {
				active_store_id: this.$store.state.merchant.activeStoreId || 0,
				active_role: this.$store.state.merchant.activeRole || '',
			};
		},
		maskPhone(phone) {
			const p = String(phone || '');
			if (p.length < 7) return p || '-';
			return p.replace(/(\d{3})\d{4}(\d+)/, '$1****$2');
		},
		callPhone(phone) {
			if (!phone) return;
			uni.makePhoneCall({ phoneNumber: String(phone) });
		},
		copyText(text) {
			if (!text) return;
			uni.setClipboardData({ data: String(text) });
		},
		async loadDetail() {
			this.loading = true;
			try {
				const res = await merchantCustomerDetail(this.uid, this.contextParams());
				const info = (res && res.data) || {};
				this.info = info;
				this.canEdit = !!info.can_edit;
				this.form = {
					real_name: info.real_name || '',
					birthday: info.birthday || '',
					sex: Number(info.sex || 0),
					addres: info.addres || '',
					mark: info.mark || '',
				};
			} catch (e) {
				this.info = {};
				const msg = (e && (e.msg || e.message)) || '加载失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				this.loading = false;
			}
		},
		switchTab(t) {
			if (this.tab === t) return;
			this.tab = t;
			if (t < 3) this.reloadOrders();
		},
		reloadOrders() {
			this.page = 1;
			this.loadend = false;
			this.orders = [];
			this.fetchOrders();
		},
		loadMore() {
			if (this.loadend || this.orderLoading || this.tab >= 3) return;
			this.page += 1;
			this.fetchOrders();
		},
		async fetchOrders() {
			if (this.orderLoading || this.loadend) return;
			this.orderLoading = true;
			try {
				const res = await merchantCustomerOrders({
					...this.contextParams(),
					uid: this.uid,
					show_type: this.tab,
					page: this.page,
					limit: this.limit,
				});
				const data = (res && res.data) || {};
				const rows = Array.isArray(data.list) ? data.list : (Array.isArray(data) ? data : []);
				this.orders = this.orders.concat(rows);
				this.loadend = rows.length < this.limit;
			} catch (e) {
				this.loadend = true;
			} finally {
				this.orderLoading = false;
			}
		},
		onBirthday(e) {
			this.form.birthday = e.detail.value;
		},
		onSex(e) {
			this.form.sex = Number(e.detail.value || 0);
		},
		async saveProfile() {
			if (!this.canEdit || this.saving) return;
			this.saving = true;
			try {
				await merchantCustomerUpdate({
					...this.contextParams(),
					uid: this.uid,
					...this.form,
				});
				uni.showToast({ title: '保存成功', icon: 'success' });
				await this.loadDetail();
			} catch (e) {
				const msg = (e && (e.msg || e.message)) || '保存失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				this.saving = false;
			}
		},
	},
};
</script>

<style scoped>
.detail-page {
	min-height: 100vh;
	background: #f5f6f8;
	padding-bottom: 40rpx;
}
.header {
	display: flex;
	padding: 32rpx 24rpx;
	background: #fff;
}
.avatar {
	width: 112rpx;
	height: 112rpx;
	border-radius: 56rpx;
	background: #eee;
	margin-right: 20rpx;
}
.header__body {
	flex: 1;
}
.name {
	font-size: 34rpx;
	font-weight: 600;
	color: #222;
}
.sub {
	margin-top: 8rpx;
	font-size: 24rpx;
	color: #666;
}
.tabs {
	display: flex;
	background: #fff;
	margin-top: 16rpx;
}
.tab {
	flex: 1;
	text-align: center;
	padding: 24rpx 0;
	font-size: 28rpx;
	color: #666;
}
.tab.active {
	color: #e93323;
	font-weight: 600;
}
.list {
	height: calc(100vh - 360rpx);
	padding: 16rpx 24rpx;
	box-sizing: border-box;
}
.order-card {
	background: #fff;
	border-radius: 16rpx;
	padding: 24rpx;
	margin-bottom: 16rpx;
}
.order-card__row {
	display: flex;
	justify-content: space-between;
}
.order-id {
	font-size: 26rpx;
	color: #222;
}
.muted {
	margin-top: 8rpx;
	font-size: 24rpx;
	color: #999;
}
.card {
	margin: 16rpx 24rpx;
	background: #fff;
	border-radius: 16rpx;
	padding: 8rpx 24rpx 24rpx;
}
.form-row {
	display: flex;
	align-items: center;
	padding: 24rpx 0;
	border-bottom: 1rpx solid #f3f3f3;
}
.label {
	width: 120rpx;
	font-size: 28rpx;
	color: #666;
}
.input {
	flex: 1;
	font-size: 28rpx;
	color: #222;
}
.hint {
	margin-top: 16rpx;
	font-size: 22rpx;
	color: #aaa;
}
.save {
	margin-top: 32rpx;
	height: 80rpx;
	line-height: 80rpx;
	text-align: center;
	border-radius: 40rpx;
	background: #e93323;
	color: #fff;
	font-size: 30rpx;
}
.save.disabled {
	opacity: 0.6;
}
.state {
	padding: 80rpx 0;
	text-align: center;
	color: #aaa;
	font-size: 26rpx;
}
</style>
