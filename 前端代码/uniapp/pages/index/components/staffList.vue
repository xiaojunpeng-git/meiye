<template>
	<view v-if="staffList.length" class="staff-module" :style="moduleStyle">
		<view class="staff-module__header">
			<text class="staff-module__title" :style="titleStyle">{{ titleText }}</text>
			<view v-if="showMore" class="staff-module__more" :style="textStyle" @tap="goMore">
				<text>查看全部</text>
				<text class="iconfont icon-ic_rightarrow fs-22 ml-4"></text>
			</view>
		</view>
		<scroll-view :scroll-x="layoutType === 0" :show-scrollbar="false" class="staff-module__scroll">
			<view class="staff-module__list" :class="`staff-module__list--${layoutType}`">
				<view
					v-for="item in staffList"
					:key="item.id"
					class="staff-module__card"
					:style="cardStyle"
				>
					<image class="staff-module__avatar" :src="item.avatar || defaultAvatar" mode="aspectFill" />
					<view class="staff-module__info">
						<text class="staff-module__name" :style="titleStyle">{{ item.staff_name }}</text>
						<text v-if="showField(0) && item.position_label" class="staff-module__meta" :style="textStyle">{{ item.position_label }}</text>
						<text v-if="showField(1) && item.position_level_label" class="staff-module__level" :style="textStyle">{{ item.position_level_label }}</text>
						<text v-if="showField(2) && item.staff_intro" class="staff-module__intro" :style="textStyle">{{ item.staff_intro }}</text>
					</view>
					<view v-if="showField(3)" class="staff-module__button" :style="buttonStyle" @tap.stop="goBooking(item)">去预约</view>
				</view>
			</view>
		</scroll-view>
	</view>
</template>

<script>
	import { homeStaffList } from '@/api/store.js';

	export default {
		name: 'staffList',
		props: {
			dataConfig: {
				type: Object,
				default: () => ({})
			},
			storeInfor: {
				type: Object,
				default: () => ({})
			}
		},
		data() {
			return {
				staffList: [],
				requestSerial: 0,
				defaultAvatar: '/static/images/def_avatar.png'
			};
		},
		computed: {
			currentStoreId() {
				return Number(this.storeInfor.storeId || uni.getStorageSync('user_store_id') || 0);
			},
			requestLimit() {
				const selected = Number(this.dataConfig.numberConfig && this.dataConfig.numberConfig.tabVal);
				return [4, 6, 8][selected] || 4;
			},
			selectedPositionIds() {
				const config = this.dataConfig.positionConfig || {};
				const values = Array.isArray(config.type) ? config.type : [];
				return values
					.map(Number)
					.filter((id, index, ids) => id > 0 && ids.indexOf(id) === index);
			},
			requestKey() {
				return `${this.currentStoreId}:${this.requestLimit}:${this.selectedPositionIds.join(',')}`;
			},
			layoutType() {
				return Number(this.dataConfig.styleConfig && this.dataConfig.styleConfig.tabVal) || 0;
			},
			titleText() {
				return (this.dataConfig.titleConfig && this.dataConfig.titleConfig.value) || '明星员工';
			},
			showMore() {
				return !this.dataConfig.moreConfig || Number(this.dataConfig.moreConfig.tabVal) === 0;
			},
			displayFields() {
				return this.dataConfig.checkboxInfo && Array.isArray(this.dataConfig.checkboxInfo.type)
					? this.dataConfig.checkboxInfo.type
					: [0, 1, 2, 3];
			},
			moduleStyle() {
				const top = this.numberValue('topConfig', 12) * 2;
				const bottom = this.numberValue('bottomConfig', 12) * 2;
				const side = this.numberValue('prConfig', 12) * 2;
				const margin = this.numberValue('mbConfig', 0) * 2;
				return {
					background: this.colorValue('moduleBgColor', '#f7f7f7'),
					padding: `${top}rpx ${side}rpx ${bottom}rpx`,
					marginTop: `${margin}rpx`
				};
			},
			titleStyle() {
				return { color: this.colorValue('titleColor', '#282828') };
			},
			textStyle() {
				return { color: this.colorValue('textColor', '#666666') };
			},
			cardStyle() {
				const fillet = this.dataConfig.fillet || {};
				return {
					background: this.colorValue('cardBgColor', '#ffffff'),
					borderRadius: `${Number(fillet.val || 12) * 2}rpx`
				};
			},
			buttonStyle() {
				return { background: this.colorValue('buttonColor', '#ff3b8d') };
			}
		},
		watch: {
			requestKey: {
				handler() {
					this.loadStaffList();
				},
				immediate: true
			}
		},
		methods: {
			colorValue(key, fallback) {
				const config = this.dataConfig[key];
				return config && config.color && config.color[0] ? config.color[0].item : fallback;
			},
			numberValue(key, fallback) {
				const config = this.dataConfig[key];
				return config && config.val !== undefined ? Number(config.val) : fallback;
			},
			showField(id) {
				return this.displayFields.map(Number).includes(Number(id));
			},
			loadStaffList() {
				const storeId = this.currentStoreId;
				const serial = ++this.requestSerial;
				if (!storeId) {
					this.staffList = [];
					return;
				}
				homeStaffList({
					store_id: storeId,
					limit: this.requestLimit,
					position_ids: this.selectedPositionIds.join(',')
				}).then((res) => {
					if (serial !== this.requestSerial) return;
					const data = res.data || {};
					this.staffList = Array.isArray(data.list) ? data.list : [];
				}).catch(() => {
					if (serial === this.requestSerial) this.staffList = [];
				});
			},
			goMore() {
				if (!this.currentStoreId) return;
				const positionQuery = this.selectedPositionIds.length
					? `&position_ids=${encodeURIComponent(this.selectedPositionIds.join(','))}`
					: '';
				uni.navigateTo({
					url: `/pages/activity/therapist_list/index?store_id=${this.currentStoreId}${positionQuery}`
				});
			},
			goBooking(staff) {
				if (!staff || !staff.id || !this.currentStoreId) return;
				uni.setStorageSync('selected_teacher', {
					id: Number(staff.id),
					staff_name: staff.staff_name || '',
					position_label: staff.position_label || '',
					position_level_label: staff.position_level_label || '',
					store_id: this.currentStoreId,
					store_name: this.storeInfor.storeName || ''
				});
				uni.navigateTo({ url: '/pages/users/user_card_list/index' });
			}
		}
	};
</script>

<style scoped lang="scss">
	.staff-module { box-sizing: border-box; width: 100%; }
	.staff-module__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20rpx; }
	.staff-module__title { color: #282828; font-size: 32rpx; font-weight: 600; }
	.staff-module__more { display: flex; align-items: center; color: #999; font-size: 22rpx; }
	.staff-module__scroll { width: 100%; }
	.staff-module__list { display: flex; box-sizing: border-box; }
	.staff-module__list--0 { display: inline-flex; min-width: 100%; flex-wrap: nowrap; }
	.staff-module__list--1 { width: 100%; flex-wrap: wrap; }
	.staff-module__list--2 { width: 100%; flex-direction: column; }
	.staff-module__card { position: relative; box-sizing: border-box; display: flex; align-items: center; min-width: 292rpx; margin-right: 16rpx; padding: 20rpx; box-shadow: 0 6rpx 24rpx rgba(0, 0, 0, .04); }
	.staff-module__card:last-child { margin-right: 0; }
	.staff-module__list--1 .staff-module__card { width: calc(50% - 8rpx); min-width: 0; margin-bottom: 16rpx; }
	.staff-module__list--1 .staff-module__card:nth-child(2n) { margin-right: 0; }
	.staff-module__list--2 .staff-module__card { width: 100%; min-width: 0; margin-right: 0; margin-bottom: 16rpx; }
	.staff-module__list--2 .staff-module__card:last-child { margin-bottom: 0; }
	.staff-module__avatar { flex: none; width: 92rpx; height: 92rpx; border-radius: 50%; background: #f3f3f3; }
	.staff-module__info { display: flex; flex: 1; min-width: 0; margin-left: 16rpx; flex-direction: column; }
	.staff-module__name { color: #282828; font-size: 28rpx; font-weight: 600; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
	.staff-module__meta, .staff-module__level, .staff-module__intro { margin-top: 4rpx; color: #666; font-size: 21rpx; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
	.staff-module__button { flex: none; padding: 10rpx 18rpx; border-radius: 28rpx; color: #fff; font-size: 21rpx; }
	.staff-module__list--1 .staff-module__card { align-items: flex-start; flex-direction: column; }
	.staff-module__list--1 .staff-module__button { width: 100%; box-sizing: border-box; text-align: center; }
	.staff-module__list--1 .staff-module__avatar { width: 108rpx; height: 108rpx; }
</style>
