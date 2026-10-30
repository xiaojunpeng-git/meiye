<template>
    <view v-if="token" class="gift-mask" :style="colorStyle" @touchmove.stop.prevent>
        <view class="gift-card" role="dialog" aria-label="好友赠券" aria-modal="true">
            <view class="gift-seal">礼</view>
            <view class="gift-heading">{{ received ? '好友的心意，已送达' : '好友送你一张优惠券' }}</view>
            <scroll-view scroll-y class="gift-content">
                <view v-if="received" class="gift-title">获得一张{{ received.title }}</view>
                <view v-if="received" class="gift-purpose">{{ received.description }}</view>
                <view v-if="received" class="gift-rule">{{ received.rule || received.scope_notice }}</view>
                <view v-if="received" class="gift-expiry">有效期至 {{ received.end_time }}</view>
                <view v-if="giftError" class="gift-rule" role="status">{{ giftError }}</view>
                <view v-if="claiming" class="gift-rule" role="status">正在接收好友的心意…</view>
            </scroll-view>
            <button v-if="received" class="gift-button" @tap="viewReceived">查看优惠券</button>
            <button v-else-if="!claiming" class="gift-button" @tap="receiveGift">{{ isLogin ? '重新领取' : '授权手机号并领取' }}</button>
            <view role="button" tabindex="0" class="gift-close" @tap="close">{{ received ? '继续逛商城' : '暂不领取' }}</view>
        </view>
    </view>
</template>
<script>
import { mapGetters } from 'vuex';
import colors from '@/mixins/color.js';
import { claimCouponShare } from '@/api/api.js';
import { getUserInfo } from '@/api/user.js';
import { toLogin } from '@/libs/login.js';
export default {
    mixins: [colors],
    props: { token: { type: String, default: '' } },
    data() { return { received: null, giftError: '', claiming: false, activeToken: '' }; },
    computed: { ...mapGetters(['isLogin']) },
    watch: {
        token: { immediate: true, handler(value) {
            if (!value || value === this.activeToken) return;
            this.activeToken = value; this.received = null; this.giftError = '';
            this.receiveGift();
        } },
        isLogin(value) { if (value && this.token && !this.received) this.receiveGift(); }
    },
    methods: {
        /** 首页只负责呈现；手机号授权和领取事务沿用原接口，不提前转移券归属。 */
        async receiveGift() {
            if (this.claiming || this.received || !this.token) return;
            const token = this.token;
            const login = () => toLogin(false, undefined, '/pages/activity/coupon_details/index?share_token=' + encodeURIComponent(token));
            if (!this.isLogin) { login(); return; }
            this.claiming = true; this.giftError = '';
            try {
                const profile = await getUserInfo();
                if (!profile.data.phone) { login(); return; }
                const res = await claimCouponShare(token);
                if (token !== this.token) return;
                this.received = res.data;
                // 成功后清除待领取入口；返回首页不会再次弹出或发起领取。
                if (uni.getStorageSync('pendingCouponGiftToken') === token) uni.removeStorageSync('pendingCouponGiftToken');
            } catch (error) { if (token === this.token) this.giftError = error.msg || '优惠券领取失败，请稍后重试'; }
            finally {
                this.claiming = false;
                // 仅处理异步请求期间切换到另一条分享链接的情况，失败不自动重试。
                if (this.token && token !== this.token) this.receiveGift();
            }
        },
        close() {
            if (this.claiming) return;
            if (uni.getStorageSync('pendingCouponGiftToken') === this.token) uni.removeStorageSync('pendingCouponGiftToken');
            this.$emit('close');
        },
        /** 查看已领取的那张券实例，不以同类券替代，也不再次请求领取。 */
        viewReceived() {
            if (!this.received) return;
            const coupon = this.received;
            this.close();
            uni.navigateTo({ url: '/pages/activity/coupon_details/index?from=user&id=' + coupon.coupon_id + '&used_id=' + coupon.coupon_user_id });
        }
    }
};
</script>
<style scoped>
/* 首页保持可见，白色卡片居中；操作区采用详情“去使用”的 88rpx 高度及主题渐变。 */
.gift-mask{position:fixed;inset:0;z-index:10010;background:rgba(33,20,25,.42);display:flex;align-items:center;justify-content:center;padding:40rpx 32rpx;box-sizing:border-box}
.gift-card{width:100%;max-width:660rpx;max-height:85vh;padding:44rpx 32rpx 24rpx;box-sizing:border-box;background:#fff;border-radius:32rpx;text-align:center;box-shadow:0 20rpx 64rpx rgba(66,20,35,.16)}
.gift-content{max-height:43vh}
.gift-seal{width:88rpx;height:88rpx;line-height:88rpx;margin:0 auto 24rpx;border-radius:50%;background:#f8e5c7;color:#8b6432;font-size:44rpx}
.gift-heading{color:#8c6a57;font-size:26rpx}.gift-title{font-size:36rpx;font-weight:600;margin:24rpx 0;color:var(--view-theme)}
.gift-purpose{font-size:30rpx;color:var(--view-theme);line-height:1.7}.gift-rule{font-size:26rpx;color:#777;margin-top:20rpx;line-height:1.7;white-space:pre-wrap}.gift-expiry{font-size:23rpx;color:#999;margin-top:24rpx}
.gift-button{display:flex;align-items:center;justify-content:center;width:100%;height:88rpx;margin:32rpx 0 0;padding:0;border:0;border-radius:44rpx;background:linear-gradient(-90deg,var(--view-gradient),var(--view-theme));color:#fff;font-size:28rpx;font-weight:500;line-height:normal;box-sizing:border-box}
.gift-button::after{border:0}.gift-close{padding:24rpx 0 8rpx;color:#888;font-size:24rpx}
</style>
