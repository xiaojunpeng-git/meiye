<template>
	<view :style="colorStyle" class="pt-24 pb-24 pr-20 pl-20">
		<view :style="[cardBackground]" class="rd-16rpx project-cover">
			<image v-if="isStandaloneProject && projectCoverImage" :src="projectCoverImage" mode="aspectFill" class="project-cover__image"></image>
			<view :class="{'card-mask':cardData.card_cover == 1 && cardData.product_type == 5 && !cardData.card_cover_image.includes('card_cover_image'), 'project-cover__shade': isStandaloneProject}" class="project-cover__content flex-col h-364 pt-24 pb-30 rd-16rpx text--w111-fff">
				<view class="flex-1">
					<view class="flex-y-center pr-24 pl-24">
						<image :src="cardData.store_image" mode="" class="w-48 h-48 rd-50-p111-"></image>
						<view class="flex-1 min-w-0 pr-16 pl-16 fw-500 fs-26">{{cardData.store_name}}</view>
						<text v-if="cardData.status == 1 && cardData.product_type == 5 && !isStandaloneProject" class="iconfont icon-ic_QRcode" @tap="showCodeChange"></text>
					</view>
					<view class="flex pr-24 pl-32 mt-48 lh-44rpx">
						<view class="w-300 fw-500 fs-32 line2" style="flex-shrink: 0;">{{cardData.card_name}}</view>
						<view class="fs-24 flex-1 text-right write-valid" v-if="cardData.write_valid == 1">有效期：永久有效</view>
						<view class="fs-24 flex-1 text-right write-valid" v-else-if="cardData.write_valid == 2">有效期至: {{cardData.write_end}}</view>
						<view class="fs-24 flex-1 text-right write-valid" v-else-if="cardData.write_valid == 3">有效期：{{cardData.write_start}}至{{cardData.write_end}}</view>
					</view>
				</view>
				<view class="flex-y-center text-center fs-24">
					<view class="flex-1">
						<view class="SemiBold fs-32">{{writeTimes}}</view>
						<view class="mt-4">总次数</view>
					</view>
					<view class="flex-1">
						<view class="SemiBold fs-32">{{writeTimes - writeSurplusTimes}}</view>
						<view class="mt-4">已核销</view>
					</view>
					<view v-if="cardData.product_type == 5" class="flex-1">
						<view class="SemiBold fs-32">{{waitingService}}</view>
						<view class="mt-4">预约占用</view>
					</view>
				</view>
			</view>
		</view>
            <view v-if="benefitsError" class="benefit-read-error" role="status">{{ benefitsError }}<text class="benefit-retry" @click="getCardBenefits">重新加载</text></view>
		<view v-if="cardData.product_type == 4" class="pt-56 pb-56 rd-16rpx mt-20 bg--w111-fff">
			<view class="flex-center">
				<!-- #ifdef H5 -->
				<image v-if="$wechat.isWeixin()" :src="qrc" mode="" class="w-316 h-316"></image>
				<w-qrcode v-else :options="config.qrc"></w-qrcode>
				<!-- #endif -->
				<!-- #ifdef MP -->
				<image :src="qrc" mode="" class="w-316 h-316"></image>
				<!-- #endif -->
				<!-- #ifdef APP-PLUS -->
				<w-qrcode :options="config.qrc"></w-qrcode>
				<!-- #endif -->
			</view>
			<view class="mt-36 fw-500 fs-28 font-color text-center">{{cardData.verify_code}}</view>
		</view>
		<view v-else-if="cardData.product_type == 5 && !isStandaloneProject" class="pr-14 pb-20 pl-20 rd-24rpx mt-20 bg--w111-fff">
			<view class="pt-32 fw-500 fs-28">卡项权益</view>

			<view>
				<view v-for="item in cardRelatedVisible" :key="item.id" class="flex-y-center p-16 pr-30 mt-20 bg--w111-f5f5f5-s111-80 rd-12rpx">
					<view class="w-96 h-96 rd-8rpx overflow">
						<image :src="item.cart_info.productInfo.attrInfo.image" mode="" class="w-full h-full"></image>
					</view>
					<view class="flex-1 min-w-0 pr-16 pl-16">
						<view class="fs-26 line1">{{item.cart_info.productInfo.store_name}}</view>
						<view class="flex-y-center mt-14 fs-22 text--w111-999">
							<view><text :class="[{'font-color':!item.write_surplus_times}]">剩余{{item.write_surplus_times}}次</text>/共{{item.write_times}}次 </view>

						</view>
                        <view v-if="Number(item.product_type) === 6" class="benefit-booking-usage" role="status">{{ benefitUsageText(item) }}</view>
					</view>
					<template v-if="cardData.status == 1">
						<view v-if="item.is_reply" class="flex-center w-120 h-50 border-CCCCCC rd-24rpx fs-24">已评价</view>
						<navigator v-else-if="!item.write_surplus_times" :url="`/pages/goods/goods_comment_con/index?unique=${item.unique}&uni=${cardData.oid}`" hover-class="none" class="flex-center w-120 h-50 border-CCCCCC rd-24rpx fs-24">去评价</navigator>
						<view v-else-if="item.product_type && mobileReservationOpen" class="benefit-actions">
                            <view v-if="!canReserveBenefit(item)" class="benefit-reserve-disabled" aria-disabled="true">{{ benefitDisabledText(item) }}</view>
                            <view v-else @click="reserveCardRelated(item.id)" class="flex-center w-120 h-50 rd-24rpx bg-color fs-24 text--w111-fff">去预约</view>
                            <view v-if="hasBookingOccupancy(item)" class="benefit-view-booking" role="button" @click="goReservationList">查看预约</view>
                        </view>
<!--						<view v-else @tap="showCodeChange" class="flex-center w-120 h-50 rd-24rpx bg-color fs-24 text&#45;&#45;w111-fff">去核销</view>-->
					</template>
				</view>
			</view>
			<view class="flex-center mt-20" v-if="cardRelated.length > 3">
				<view class="fs-22 text--w111-999" @click="toggleExpand">
					{{ isExpanded ? '收起' : '展开' }}
					<text :class="['iconfont ml-4 fs-24', isExpanded ? 'icon-ic_uparrow' : 'icon-ic_downarrow']"></text>
				</view>
			</view>
		</view>
		<view class="pt-6 pb-6 rd-16rpx mt-20 bg--w111-fff">
			<navigator :url="`/pages/admin/writeRecordList/index?id=${cardData.oid}`" hover-class="none" class="flex-between-center pt-26 pb-26 pr-22 pl-24 lh-40rpx">
				<view class="fs-28">核销记录</view>
				<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999"></text>
			</navigator>
			<view v-if="(cardData.product_type == 5 || isStandaloneProject) && mobileReservationOpen" @click="goReservationList" hover-class="none" class="flex-between-center pt-26 pb-26 pr-22 pl-24 lh-40rpx">
				<view class="fs-28">{{ hasAnyBookingOccupancy ? '查看预约' : '预约单' }}</view>
				<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999"></text>
			</view>
		</view>
		<view class="pb-safe">
			<view class="p-20">
				<view class="h-80"></view>
			</view>
		</view>
		<view class="fixed-lb w-full pb-safe bg--w111-fff">
			<view class="p-20" :class="{ 'project-actions': isStandaloneProject && mobileReservationOpen }">
				<view v-if="isStandaloneProject && standaloneProject" class="standalone-booking-usage" role="status">{{ benefitUsageText(standaloneProject) }}</view>
				<navigator :url="`/pages/goods_details/index?id=${cardData.pid || cardData.product_id}`" hover-class="none" class="repurchase-button flex-center h-80 rd-40rpx fw-500 fs-28">再次购买</navigator>
                <button v-if="isStandaloneProject && mobileReservationOpen" :disabled="!canReserveStandalone || reservationPreparing" :loading="reservationPreparing" @click="reserveStandaloneProject" class="project-reserve-button flex-center h-80 rd-40rpx fw-500 fs-28 bg-color text--w111-fff">{{ canReserveStandalone ? '去预约' : benefitDisabledText(standaloneProject) }}</button>
			</view>
		</view>
		<verifyModal v-if="showQrcode" :qrcode="config.qrc" :qrc="qrc" :verifyCode="cardData.verify_code" :writeDay="cardData.write_day" :writeTimes="cardData.write_times" :writeOff="cardData.write_times" :productType="cardData.product_type" @closeModal="closeModal"></verifyModal>
	</view>
</template>

<script>
	import verifyModal from '../components/verifyModal/index.vue';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app.js';
	import colors from '@/mixins/color.js';
	import {
		cardHolder
	} from '@/api/user.js';
	import {
		cardOrderBenefits, getReservationOrderInfo
	} from '@/api/order.js';
	import {
		activityCodeApi
	} from '@/api/activity.js';
	import { isMobileReservationOpen } from '@/utils/mobileReservation.js';
	import { goWithYuyueSubscribe } from '@/utils/SubscribeMessage.js';
	let timer = null;
	export default {
		components: {
			verifyModal,
		},
		mixins: [colors],
		data() {
			return {
				id: 0,
				reservationPreparing: false,
                benefitsLoading: false,
                benefitsError: '',
                benefitsEpoch: 0,
				cardData: {},
				cardRelated: [],
				isExpanded: false,
				writeTimes: 0,
				writeSurplusTimes: 0,
				showQrcode: false,
				config: {
					qrc: {
						code: "",
						size: 316, // 二维码大小
						level: 4, //等级 0～4
						bgColor: '#FFFFFF', //二维码背景色 默认白色
						color: ['#333', '#333'], //边框颜色支持渐变色
					}
				},
				qrc: '',
			}
		},
		computed: {
			isStandaloneProject() {
				// Gift issuance can wrap one project in a type-5 holder. Match the
				// issued product identity, never its name, one remaining use or price.
				if (Number(this.cardData.product_type) === 6) return true;
				return Number(this.cardData.product_type) === 5 && this.cardRelated.length === 1
					&& Number(this.cardRelated[0].product_type) === 6
					&& Number(this.cardRelated[0].product_id) === Number(this.cardData.product_id);
			},
			standaloneProject() {
				if (!this.isStandaloneProject) return null;
				const rows = this.cardRelated.filter(row => Number(row.product_type) === 6
					&& Number(row.product_id) === Number(this.cardData.product_id));
				// Ambiguous details must not silently select another issued right.
				return rows.length === 1 ? rows[0] : null;
			},
			projectCoverImage() {
				const info = this.standaloneProject?.cart_info || {};
				const image = info.productInfo?.attrInfo?.image || info.productInfo?.image || info.image || this.cardData.image || '';
				return image.charAt(0) === '/' ? `${HTTP_REQUEST_URL.replace(/\/$/, '')}${image}` : image;
			},
			canReserveStandalone() {
				return this.mobileReservationOpen && Number(this.cardData.status) === 1
					&& !this.cardData.is_card_upgraded && Number(this.writeSurplusTimes) > 0
					&& Number(this.standaloneProject?.id) > 0
					&& this.canReserveBenefit(this.standaloneProject);
			},
			cardBackground() {
				if (!Object.keys(this.cardData).length) {
					return {}
				}
				// A project image is media only; the themed fallback keeps white text readable.
				if (this.isStandaloneProject) return { 'background-color': 'var(--view-theme, #7b2941)' };
				if (this.cardData.product_type == 4 || this.cardData.card_cover == 1) {
					// 卡封面在商品表可保存本站绝对路径；小程序背景图必须使用当前客户的完整 HTTPS 地址。
					const cover = this.cardData.product_type == 4
						? `${HTTP_REQUEST_URL}/statics/images/card_cover_image.png`
						: this.cardData.card_cover_image;
					const coverUrl = cover && cover.charAt(0) === '/'
						? `${HTTP_REQUEST_URL.replace(/\/$/, '')}${cover}`
						: cover;
					return {
						'background': `url(${coverUrl}) center/cover no-repeat`,
					}
				} else {
					return {
						'background-color': this.cardData.card_cover_color
					}
				}
			},
			cardRelatedVisible() {
				if (this.isExpanded) {
					return this.cardRelated;
				} else {
					return this.cardRelated.slice(0, 3);
				}
			},
            hasAnyBookingOccupancy() {
                return this.cardRelated.some(item => this.hasBookingOccupancy(item));
            },
			waitingService() {
				// 计算待服务
				return this.cardRelated.filter((item) => {
					return item.product_type == 6;
				}).reduce((total, item) => {
					return total + Number(item.waiting_service || 0);
				}, 0);
			},
			mobileReservationOpen() {
				return isMobileReservationOpen();
			}
		},
		onLoad(options) {
			this.id = options.id;
			this.getCardHolder();
		},
        onShow() {
            // Returning from a cancellation must refresh quota before enabling
            // another booking. This read never cancels or modifies a reservation.
            if (Number(this.cardData.oid) > 0) this.getCardBenefits();
        },
		beforeDestroy() {
			if (timer) {
				clearTimeout(timer);
				timer = null;
			}
		},
		methods: {
			getCardHolder() {
				cardHolder(this.id).then((res) => {
					this.cardData = res.data;
					this.config.qrc.code = res.data.verify_code;
					this.writeTimes = res.data.write_times;
					this.writeSurplusTimes = res.data.write_surplus_times;
					if (this.cardData.product_type == 4) {
						timer = setTimeout(() => {
							this.getCardHolder();
						}, 30000);
					} else if ([5, 6].includes(Number(this.cardData.product_type))) {
						this.getCardBenefits();
					}
					this.activityCodeApi();
				});
			},
			// 卡项权益
            getCardBenefits() {
                const epoch = ++this.benefitsEpoch;
                this.benefitsLoading = true;
                this.benefitsError = '';
                return cardOrderBenefits(this.cardData.oid).then(res => {
                    if (epoch !== this.benefitsEpoch) return false;
                    this.cardRelated = Array.isArray(res.data) ? res.data : [];
                    return true;
                }).catch(() => {
                    if (epoch !== this.benefitsEpoch) return false;
                    // Retain the last readable rights but do not grant booking
                    // from stale data after a failed availability refresh.
                    this.benefitsError = '预约占用状态读取失败，请重新加载。';
                    return false;
                }).finally(() => {
                    if (epoch === this.benefitsEpoch) this.benefitsLoading = false;
                });
            },
            canReserveBenefit(item) {
                // Display quota is a hint only; entry refreshes it and the
                // final booking transaction still rechecks current rights.
                return !this.benefitsLoading && !this.benefitsError && item
                    && Number(item.write_surplus_times) > 0
                    && item.reservation_usage?.known === true
                    && Number(item.reservation_usage.available_quantity) > 0;
            },
            hasBookingOccupancy(item) {
                return Number(item?.reservation_usage?.shared_occupied_quantity || item?.reservation_usage?.occupied_quantity || 0) > 0;
            },
            benefitUsageText(item) {
                if (this.benefitsLoading) return '正在读取预约占用…';
                const usage = item?.reservation_usage;
                if (this.benefitsError || usage?.known !== true) return '预约状态待刷新';
                const count = Number(usage.shared_pool ? usage.shared_occupied_quantity : usage.occupied_quantity) || 0;
                return `${usage.shared_pool ? '共享预约占用' : '预约占用'}${count}次 · ${usage.shared_pool ? '共享可预约' : '当前可预约'}${usage.available_text}`;
            },
            benefitDisabledText(item) {
                if (this.benefitsLoading) return '读取中';
                if (this.benefitsError || item?.reservation_usage?.known !== true) return '状态待刷新';
                return item.reservation_usage.disabled_reason || '暂无可预约次数';
            },
			// 卡项权益-展开
			toggleExpand() {
				this.isExpanded = !this.isExpanded;
			},
			/** Reuse the purchased booking reader before navigating. The server
			 * validates ownership/SKU/quota; this action never creates a booking. */
            reserveStandaloneProject() {
                if (!this.canReserveStandalone) return;
                return this.preparePurchasedReservation(this.standaloneProject.id, true);
            },
            reserveCardRelated(id) {
                const item = this.cardRelated.find(row => Number(row.id) === Number(id));
                if (!this.mobileReservationOpen || !this.canReserveBenefit(item)) return;
                return this.preparePurchasedReservation(id, false);
            },
            async preparePurchasedReservation(id, standalone) {
                if (this.reservationPreparing) return;
                this.reservationPreparing = true;
                try {
                    if (!await this.getCardBenefits()) return;
                    const item = this.cardRelated.find(row => Number(row.id) === Number(id));
                    if (!this.canReserveBenefit(item)) return;
                    const orderId = this.cardData.oid;
                    if (standalone) {
                        const res = await getReservationOrderInfo(orderId, { cart_info_id: id });
                        if (Number(res.data?.cart_info_id) !== Number(id) || Number(res.data?.cart_num) <= 0) {
                            this.benefitsError = '该项目暂无可预约次数，请重新加载。';
                            return;
                        }
                    }
                    goWithYuyueSubscribe(`/pages/activity/reservation/index?orderId=${orderId}&cartInfoId=${id}`);
                } catch (error) {
                    this.benefitsError = '预约资料读取失败，请重新加载。';
                } finally {
                    this.reservationPreparing = false;
                }
            },
			goReservationList() {
				goWithYuyueSubscribe(`/pages/goods/reservation_list/index?orderId=${this.cardData.oid}`);
			},
			showCodeChange() {
				this.showQrcode = true;
			},
			activityCodeApi() {
				activityCodeApi(91, 0, {
					order_id: this.cardData.order_id
				}).then(res => {
					const {
						routineUrl,
						wechatUrl
					} = res.data;
					// #ifdef MP
					this.qrc = routineUrl;
					// #endif
					// #ifdef H5
					if (this.$wechat.isWeixin()) {
						this.qrc = wechatUrl;
					}
					// #endif
				});
			},
			closeModal() {
				this.showQrcode = false;
				this.getCardHolder();
			},
		},
	};
</script>

<style lang="scss" scoped>
    // Quota/status is text, not color alone; full names and action labels must
    // remain reachable at narrow member-app widths.
    .benefit-booking-usage{ font-size: 22rpx; line-height: 1.6; color: #80666d; margin-top: 8rpx; overflow-wrap: anywhere; }
    .benefit-actions{ flex-shrink: 0; text-align: center; max-width: 150rpx; }
    .benefit-reserve-disabled{ padding: 10rpx 12rpx; border-radius: 24rpx; background: #e7e7e7; color: #777; font-size: 22rpx; line-height: 1.4; }
    .benefit-view-booking{ margin-top: 12rpx; color: var(--view-theme, #7b2941); font-size: 22rpx; line-height: 1.5; }
    .benefit-read-error{ margin-top: 16rpx; font-size: 24rpx; color: #9a263d; line-height: 1.6; }
    .benefit-retry{ margin-left: 12rpx; text-decoration: underline; }
    .standalone-booking-usage{ flex-basis: 100%; font-size: 24rpx; color: #80666d; line-height: 1.6; margin-bottom: 12rpx; }
    .project-actions{ flex-wrap: wrap; }

	// Native images render in both H5 and mini-programs; shade only the project
	// cover so names/counts remain readable without changing ordinary card skins.
	.project-cover { position: relative; overflow: hidden; }
	.project-cover__image { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
	.project-cover__content { position: relative; }
	.project-cover__shade { background: linear-gradient(180deg, rgba(0,0,0,.22), rgba(0,0,0,.60)); }
	.project-actions { display: flex; gap: 20rpx; }
	.project-actions .repurchase-button, .project-actions .project-reserve-button { flex: 1; min-width: 0; }
	.project-reserve-button { margin: 0; padding: 0; border: 0; line-height: normal; }
	.project-reserve-button::after { border: 0; }
	.project-reserve-button[disabled] { opacity: .45; }

	// Keep the white action distinguishable from its white footer; include the border inside the original button size.
	.repurchase-button {
		box-sizing: border-box;
		border: 1px solid #d9d9d9;
		background: #fff;
		color: #000;
	}

	.card-mask {
		background: rgba(0, 0, 0, 0.3);
	}

	.write-valid {
		color: rgba(255, 255, 255, 0.6);
	}
</style>
