<template>
	<view>
		<view class="w-full relative z-2 gradient-box" :style="{ 'padding-top': sysHeight + 'px' }">
			<view class="w-full px-20 pl-20 h-80 flex-between-center">
				<text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff" @click="goPage(3)"></text>
				<text class="fs-34 fw-500 text--w111-fff">提交订单</text>
				<text></text>
			</view>
			<view class="px-20 mt-30">
				<view class="allAddress">
					<!-- isDisplay 0 两个都展示 1 展示配送 2展示核销 -->
					<!-- store_self_mention 核销开关 -->
					<view class='address add1 flex-between-center rd-24rpx' @tap='onAddress(addressInfo.real_name)' v-if="!store_id">
						<view v-if="currentAddress.real_name">
							<view class="fs-30 text--w111-333 fw-500 acea-row row-middle">
								<text>{{currentAddress.real_name}}</text> <text class='pl-20'>{{currentAddress.phone}}</text>
								<text class='default acea-row row-center-wrapper ml-8' v-if="currentAddress.is_default">默认</text>
							</view>
							<view class="fs-24 text--w111-666 mt-12">{{currentAddress.province}}{{currentAddress.city}}{{currentAddress.district}}{{currentAddress.street}}{{currentAddress.detail}}</view>
						</view>
						<view class='fs-30 text--w111-333 fw-500 lh-42rpx flex-y-center' v-else>
							<text class="pl-8">设置收货地址</text>
						</view>
						<text class="iconfont icon-ic_rightarrow fs-32 text--w111-666 ml-20"></text>
					</view>
					<view class="address add1 acea-row row-between-wrapper rd-24rpx" v-else>
						<block v-if="storeList.length > 0">
							<view class="w-full">
								<view class="w-full flex-between-center dashed-b">
									<view>
										<view class="flex-center">
											<view class="fs-30 w-410 line1 fw-500 lh-42rpx">{{ currentStore.name }}</view>
										</view>
										<view class="fs-24 w-410 line1 text--w111-666 lh-34rpx mt-16">{{ currentStore.address }}{{ currentStore.detailed_address }}</view>
									</view>
									<view class="_map relative" :style="{ backgroundImage: 'url(' + imgHost + '/statics/images/order/order_map_bg.png' + ')' }" @tap="showMaoLocation">
										<view class="store_logo bg--w111-fff rd-8rpx">
											<image class="w-full h-full rd-8rpx relative z-2" :src="currentStore.image"></image>
										</view>
									</view>
								</view>
							</view>
						</block>
						<block v-else>
							<view>暂无门店信息</view>
						</block>
					</view>
					<view class="line">
						<image src="/static/images/line.jpg"></image>
					</view>
				</view>
			</view>
		</view>
		<view class="order-submission">
			<view class="px-20">
				<view class="bg--w111-fff rd-16rpx my-20">
					<view class="bg--w111-fff rd-16rpx p-24 flex-y-center" v-if="userId == 0">
						<image src="@/static/images/def_avatar.png" class="w-60 h-60 rd-50-p111-"></image>
						<view class="flex-1 pl-20">
							<view class="fs-28 lh-40rpx fw-500">游客</view>
							<view class="fs-24 lh-34rpx text--w111-999 mt-4"></view>
						</view>
					</view>
					<view class="bg--w111-fff rd-16rpx p-24 flex-y-center" v-else>
						<image :src="userInfo.avatar" class="w-60 h-60 rd-50-p111-"></image>
						<view class="flex-1 pl-20">
							<view class="flex-y-center">
								<view class="fs-28 lh-40rpx fw-500">{{ userInfo.nickname }}</view>
								<view class="svip flex-center" v-if="userInfo.isMember == 1">SVIP</view>
								<view class="vip flex-center" v-if="userInfo.level_status == 1">
									<text class="iconfont icon-huiyuandengji"></text>
									V{{ userInfo.level_grade }}
								</view>
							</view>
							<view class="fs-24 lh-34rpx text--w111-999 mt-4">ID:{{ userId }}</view>
						</view>
					</view>
					<view class="bg--w111-fff rd-16rpx p-24 flex-y-center border-top" v-if="shippingType == 2">
						<view class="flex-1">
							<view class="fs-24 text--w111-999 lh-34rpx">我的姓名</view>
							<view class="flex-between-center mt-6">
								<input type="text" v-model="userInfo.real_name" :focus="contactsFocus" class="w-78 h-36 fs-26 fw-500 lh-36rpx" />
								<text class="iconfont icon-ic_edit fs-28 text--w111-999 pl-16" @tap="clearInput(0)"></text>
							</view>
						</view>
						<view class="flex-1 pl-46">
							<view class="fs-24 text--w111-999 lh-34rpx">我的电话</view>
							<view class="flex-between-center mt-6">
								<input type="number" v-model="userInfo.phone" :focus="telFocus" class="w-222 h-36 fs-30 fw-500 lh-30rpx" />
								<text class="iconfont icon-ic_edit fs-28 text--w111-999 pl-16" @tap="clearInput(1)"></text>
							</view>
						</view>
					</view>
					<view class='address add1 flex-between-center border-line rd-b-24rpx' @tap='onAddress(currentAddress.real_name)' v-if="shippingType == 3">
						<view v-if="currentAddress.real_name">
							<view class="fs-30 text--w111-333 fw-500 acea-row row-middle">
								<text>{{currentAddress.real_name}}</text> <text class='pl-20'>{{currentAddress.phone}}</text>
								<text class='default acea-row row-center-wrapper ml-8' v-if="currentAddress.is_default">默认</text>
							</view>
							<view class="fs-24 text--w111-666 mt-12">{{currentAddress.province}}{{currentAddress.city}}{{currentAddress.district}}{{currentAddress.street}}{{currentAddress.detail}}</view>
						</view>
						<view class='fs-30 text--w111-333 fw-500 lh-42rpx flex-y-center' v-else>
							<text class="pl-8">设置收货地址</text>
						</view>
						<text class="iconfont icon-ic_rightarrow fs-32 text--w111-666 ml-20"></text>
					</view>
				</view>
				<view class="bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32">
					<view class="flex order-goods" v-for="(item, index) in cartInfo" :key="index">
						<view class="w-176 h-176 relative">
							<image class="w-176 h-176 rd-16rpx" :src="item.productInfo.attrInfo.image" v-if="item.productInfo.attrInfo"></image>
							<image class="w-176 h-176 rd-16rpx" :src="item.productInfo.image" v-else></image>
							<view class="over flex-center fs-24 text--w111-fff" v-if="!item.is_valid">{{ shippingType == 1 ? '非到店' : '不送达' }}</view>
						</view>
						<view class="flex-1 flex-col justify-between pl-20">
							<view @click="goDetail(item.productInfo.id)">
								<view class="w-464 line1 fs-28 lh-40rpx" :class="item.is_valid ? 'text--w111-333' : 'text--w111-ccc'">{{ item.productInfo.store_name }}</view>
								<view class="w-322 fs-22 lh-30rpx line1 mt-12" :class="item.is_valid ? 'text--w111-999' : 'text--w111-ccc'" v-if="item.productInfo.attrInfo">
									{{ item.productInfo.attrInfo.suk }}
								</view>
								<view class="flex items-end flex-wrap mt-12 w-full">
									<BaseTag :text="label.label_name" :color="label.color" :background="label.bg_color" :borderColor="label.border_color" :circle="label.border_color ? true : false" :imgSrc="label.icon" v-for="(label, idx) in item.productInfo.store_label" :key="idx"></BaseTag>
								</view>
							</view>
							<view class="flex-between-center">
								<baseMoney :money="item.productInfo.attrInfo ? item.productInfo.attrInfo.price : item.productInfo.price" symbolSize="20" integerSize="36" decimalSize="20" :color="item.is_valid ? '#333' : '#cccccc'" weight></baseMoney>
								<view class="flex-y-center" v-if="[0, 6, 13].includes(goodsType) && news == 0">
									<text class="iconfont icon-ic_Reduce fs-24" :class="item.cart_num > 1 ? 'text--w111-333' : 'text--w111-999'" @click.stop="addCart(0, item)"></text>
									<input type="number" maxlength="3" class="w-72 h-36 rd-4rpx bg--w111-f5f5f5 flex-center text-center fs-24 text--w111-333 mx-6" @input="setValue($event, item)" v-model="item.cart_num" />
									<text class="iconfont icon-ic_increase fs-24 text--w111-333" @click.stop="addCart(1, item)"></text>
								</view>
								<text class="fs-28 text--w111-666" v-else>×{{ item.cart_num }}</text>
							</view>
						</view>
					</view>
					<view class="cell flex justify-between mt-32" v-if="giftCount > 0">
						<text class="text--w111-333 fs-28">赠品</text>
						<view class="flex-1 flex-y-center justify-end" @tap="showGiftDrawer = true">
							<view class="flex">
								<view class="w-64 h-64 mr-8" v-for="(item, index) in giveCartInfo" :key="index">
									<image class="h-full w-full rd-8rpx" :src="item.productInfo.attrInfo.image" v-if="item.productInfo.attrInfo"></image>
									<image class="h-full w-full rd-8rpx" :src="item.productInfo.image" v-else></image>
								</view>
								<view class="w-64 h-64 rd-8rpx bg--w111-f5f5f5 flex-center mr-8" v-if="giveData.give_coupon.length">
									<text class="gold iconfont icon-a-ic_discount1"></text>
								</view>
								<view class="w-64 h-64 rd-8rpx bg--w111-f5f5f5 flex-center mr-8" v-if="giveData.give_integral > 0">
									<text class="gold iconfont icon-ic_badge11"></text>
								</view>
							</view>
							<view class="fs-26 text--w111-666">
								共{{ giftCount }}件
								<text class="iconfont icon-ic_rightarrow"></text>
							</view>
						</view>
					</view>
					<view class="cell flex-y-center mt-32" @tap="openDeliveryDrawer">
						<text class="text--w111-333 fs-28">配送方式</text>
						<view class="flex-1 flex justify-end">
							<view class="flex-center w-128 h-50 rd-6rpx bg-w111-E9F2FE fs-24 text-w111-2A7EFB">
								<text v-if="shippingType == 1">商城配送</text>
								<text v-else-if="shippingType == 2">到店自提</text>
								<text v-else-if="isStoreDeliveryType == 1">快递发货</text>
								<text v-else-if="isStoreDeliveryType == 2">同城闪送</text>
							</view>
						</view>
						<text class="iconfont icon-ic_rightarrow ml-10 fs-24 text--w111-999" v-if="isDisplay.length > 1"></text>
					</view>
					<view class="cell flex-y-center mt-32" v-if="isStoreDeliveryType == 2" @tap="openEstimateDrawer">
						<text class="text--w111-333 fs-28">配送时间</text>
						<text class="flex-1 text-right">{{deliveryTimeText}}</text>
						<text class="iconfont icon-ic_rightarrow ml-10 fs-24 text--w111-999" v-if="dateList.length"></text>
					</view>
					<view class="cell flex justify-between flex-y-center mt-32" v-if="textareaStatus">
						<text class="text--w111-333 fs-28">留言</text>
						<textarea class="w-450 fs-28 text-right h-auto" :auto-height="true" wrap-style="wrap" max-height="100px" placeholder-class="placeholder" placeholder="请先与商家沟通一致再留言哦～" :always-embed="true" :adjust-position="true" cursor-spacing="30" v-if="!coupon.coupon" @input="bindHideKeyboard" :value="mark" :maxlength="150" name="mark"></textarea>
					</view>
				</view>
				<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32">
					<view class="cell flex-between-center" v-if="[0, 6, 13].includes(type) && !noCoupon && goodsType != 7 && priceGroup.firstOrderPrice==0" @tap='couponTap'>
						<text class="text--w111-333 fs-28">优惠券</text>
						<view>
							<text class="text--w111-333 fs-28">{{couponTitle}}</text>
							<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999 pl-8"></text>
						</view>
					</view>
					<view class="cell flex-between-center" v-if="[0, 6, 13].includes(type) && integral_ratio_status == 1">
						<text class="text--w111-333 fs-28">积分抵扣</text>
						<view class="flex-y-center">
							<view class="flex-y-center">
								{{ useIntegral ? '剩余积分' : '当前积分' }}
								<text class="Regular fs-36 pl-8 pr-10 text-w111-FF7E00">{{ integral || 0 }}</text>
							</view>
							<text class="iconfont fs-32" :class="useIntegral ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect text--w111-ccc'" @tap="ChangeIntegral"></text>
						</view>
					</view>
				</view>
				<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if="type == 4">
					<view class="cell flex-between-center">
						<text class="text--w111-333 fs-28">可用积分</text>
						<text>{{ userInfo.integral }}</text>
					</view>
					<view class="cell flex-between-center">
						<text class="text--w111-333 fs-28">抵扣积分</text>
						<text>{{ totalIntegral }}</text>
					</view>
				</view>
				<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32">
					<view class="cell flex-between-center">
						<text class="text--w111-333 fs-28">商品总价</text>
						<text class="text--w111-333 fs-36 Regular">￥{{ priceGroup.sumPrice }}</text>
					</view>
					<view class="cell flex-between-center" v-if="priceGroup.firstOrderPrice > 0">
						<text class="text--w111-333 fs-28">新人首单优惠</text>
						<text class="text--w111-333 fs-36 Regular font-color">-￥{{ priceGroup.firstOrderPrice }}</text>
					</view>
					<view class="cell flex-between-center" v-if="priceGroup.storePostage + priceGroup.storePostageDiscount > 0">
						<view class="flex-center">
							<text class="text--w111-333 fs-28">配送运费</text>
							<text class="flex-center ml-14 fs-24 text-w111-FF7E00">
								<text class="iconfont icon-icon_tip mr-4 fs-24"></text>
								<text v-if="isStoreDeliveryType == 2">满{{priceGroup.sameCityStoreFreePostage}}免配送费，还差{{priceGroup.poorFreeShipping}}元</text>
								<text v-if="isStoreDeliveryType == 1 || shippingType == 1">满{{priceGroup.storeFreePostage}}包邮，还差{{priceGroup.poorFreeShipping}}元</text>
							</text>
						</view>
						<text class="text--w111-333 fs-36 Regular font-color">
							￥{{ (parseFloat(priceGroup.storePostage) + parseFloat(priceGroup.storePostageDiscount)).toFixed(2) }}
						</text>
					</view>
					<view class="cell flex-between-center" v-if="priceGroup.vipPrice > 0 && userInfo.vip && [0, 6, 13].includes(type)">
						<text class="text--w111-333 fs-28">用户等级优惠</text>
						<text class="text--w111-333 fs-36 Regular font-color">-￥{{ parseFloat(priceGroup.vipPrice).toFixed(2) }}</text>
					</view>
					<view class="cell flex-between-center" v-if="priceGroup.storePostageDiscount > 0">
						<text class="text--w111-333 fs-28">会员运费优惠</text>
						<text class="text--w111-333 fs-36 Regular font-color">-￥{{ parseFloat(priceGroup.storePostageDiscount).toFixed(2) }}</text>
					</view>
					<view class="cell flex-between-center" v-if="coupon_price > 0">
						<text class="text--w111-333 fs-28">优惠券抵扣</text>
						<text class="text--w111-333 fs-36 Regular font-color">-￥{{ parseFloat(coupon_price).toFixed(2) }}</text>
					</view>
					<view class="cell flex-between-center" v-if="integral_price > 0">
						<text class="text--w111-333 fs-28">积分抵扣</text>
						<text class="text--w111-333 fs-36 Regular font-color">-￥{{ parseFloat(integral_price).toFixed(2) }}</text>
					</view>
					<view class="cell flex-between-center" v-for="(item, index) in promotions_detail" :key="index" v-show="parseFloat(item.promotions_price)">
						<text class="text--w111-333 fs-28">{{ item.title }}</text>
						<text class="text--w111-333 fs-36 Regular font-color">-￥{{ parseFloat(item.promotions_price).toFixed(2) }}</text>
					</view>
				</view>
			</view>
			<view class="pb-safe">
				<view class="h-76" v-if="rangeOut"></view>
				<view class="h-76" v-if="priceGroup.poorDeliveryPrice && !groupDrawerVisible && !rangeOut"></view>
				<view class="h-80" v-if="!svip_status && svip_price > 0"></view>
				<view class="h-96"></view>
				<view class="h-20"></view>
			</view>
			<view class="fixed-lb w-full bg--w111-fff pb-safe z-20">
				<view class="h-76 bg-w111-E9F2FE flex-between-center px-32 fs-26 text-w111-2A7EFB" v-if="rangeOut">
					<view class="flex-y-center">
						<text class="iconfont icon-icon_tip fs-32"></text>
						<view class="pl-10">
							<text>当前地址不在配送范围内</text>
						</view>
					</view>
				</view>
				<!-- 去凑单 -->
				<view class="h-76 bg-w111-E9F2FE flex-between-center px-32 fs-26 text-w111-2A7EFB" v-if="priceGroup.poorDeliveryPrice && !groupDrawerVisible && !rangeOut" @tap="openGroupDrawer">
					<view class="flex-y-center">
						<text class="iconfont icon-icon_tip fs-32"></text>
						<view class="pl-10">
							<text>还差{{priceGroup.poorDeliveryPrice}}元起送</text>
						</view>
					</view>
					<view>
						<text>去凑单</text>
						<text class="iconfont icon-ic_rightarrow fs-24"></text>
					</view>
				</view>
				<view class="h-80 bg--w111-FFF0D1 flex-between-center px-20" v-if="!svip_status && svip_price > 0">
					<view class="flex-y-center">
						<image src="@/static/img/vip_leval.png" class="w-36 h-36"></image>
						<view class="pl-8">
							<text class="fs-24 text--w111-7E4B06">开通 SVIP会员 预计省</text>
							<text class="font-color fs-28">¥{{ svip_price }}</text>
							<text class="fs-24 text--w111-7E4B06">元</text>
						</view>
					</view>
					<view class="fs-24 text--w111-7E4B06" @click="goPage(1, '/pages/annex/vip_paid/index')">
						<text>立即开通</text>
						<text class="iconfont icon-ic_rightarrow fs-24"></text>
					</view>
				</view>
				<view class="pl-32 pr-20 h-96 flex-between-center">
					<view class="flex items-baseline">
						<text class="fs-24 fw-500 text--w111-333">合计</text>
						<baseMoney :money="totalPrice || 0" symbolSize="26" integerSize="44" decimalSize="26" color="#FF7E00" weight></baseMoney>
						<text class="pl-12" v-if="type == 4 && totalIntegral > 0">+{{ totalIntegral }}积分</text>
					</view>
					<view class="min-w-168 h-72 px-32 rd-36rpx flex-center text--w111-fff fs-26 bg-primary" v-if="priceGroup.poorDeliveryPrice && !rangeOut">差{{priceGroup.poorDeliveryPrice}}元起送</view>
					<view class="w-168 h-72 rd-36rpx flex-center text--w111-fff fs-26 bg-primary" @tap.stop="payDrawerChange" v-else-if="canSubmit && !rangeOut">提交订单</view>
					<view class="w-168 h-72 rd-36rpx flex-center text--w111-fff fs-26 bg-color-hui" v-else>提交订单</view>
				</view>
			</view>
		</view>
		<view class="alipaysubmit" v-html="formContent"></view>
		<tuiModal :show="isAddress" title="更新地址" content="当前地址功能已更新，请重新修改" @click="handleClick" @cancel="hideModal"></tuiModal>
		<!-- 赠品抽屉 -->
		<giftDrawer :visible="showGiftDrawer" :giveCartInfo="giveCartInfo" :giveData="giveData" @closeDrawer="closeDrawer"></giftDrawer>
		<couponListWindow :coupon="coupon" @ChangCouponsClone="ChangCouponsClone" :openType="openType" :cartId="cartId" @ChangCoupons="ChangCoupons"></couponListWindow>
		<base-drawer mode="bottom" :visible="showAddressDrawer" backgroundColor="transparent" @close="closeAddressDrawer">
			<view class="w-full bg--w111-fff rd-t-40rpx py-32">
				<view class="text-center fs-32 text--w111-333 fw-500">选择地址</view>
				<view class="mt-64 px-32">
					<view class="mb-38 flex-between-center" v-for="(item, index) in addressList" :key="index" :class="{ 'font-num': addressActive == index }" @tap="tapAddress(item, index)">
						<text class="iconfont icon-ic_location5 fs-36"></text>
						<view class="flex-1 pl-40">
							<view class="fs-28 fw-500">
								{{ item.real_name }}
								<text class="phone">{{ item.phone }}</text>
							</view>
							<view class="w-560 line1 mt-4">{{ item.province }}{{ item.city }}{{ item.district }}{{ item.street }}{{ item.detail }}</view>
						</view>
					</view>
					<view v-if="!addressList.length">
						<emptyPage title="暂无地址信息～" src="/statics/images/noAddress.png"></emptyPage>
					</view>
				</view>
				<view class="mx-20 pb-safe">
					<view class="mt-52 h-72 flex-center rd-36px bg-primary fs-26 text--w111-fff" @tap="goAddressPages">添加地址</view>
				</view>
			</view>
		</base-drawer>
		<base-drawer mode="bottom" :visible="showPayDrawer" background-color="transparent" mask maskClosable @close="closePayDrawer">
			<view class="w-full bg--w111-f5f5f5 rd-t-40rpx py-32">
				<view class="text-center fs-32 text--w111-333 fw-500">支付方式</view>
				<view class="mt-54 px-32">
					<view class="w-full h-136 rd-24rpx bg--w111-fff pay-card flex-between-center" @tap="goPay(0)">
						<view class="flex-y-center">
							<image class="w-52 h-52" :src="imgHost +  '/statics/images/order/yue_pay.png'"></image>
							<view class="flex-col ml-20">
								<text class="fs-28">线上支付</text>
								<text class="pt-8 fs-22 text--w111-999">微信/支付宝扫码支付</text>
							</view>
						</view>
						<text class="iconfont icon-ic_rightarrow fs-32 text--w111-999 "></text>
					</view>
					<view class="w-full h-136 rd-24rpx bg--w111-fff pay-card flex-between-center mt-24" @tap="goPay(1)">
						<view class="flex-y-center">
							<image class="w-52 h-52" :src="imgHost +  '/statics/images/order/xianxia_pay.png'"></image>
							<view class="flex-col ml-20">
								<text class="fs-28">线下支付</text>
								<text class="pt-8 fs-22 text--w111-999">选择线下付款方式</text>
							</view>
						</view>
						<text class="iconfont icon-ic_rightarrow fs-32 text--w111-999 "></text>
					</view>
					<view v-if="userId" class="w-full h-136 rd-24rpx bg--w111-fff pay-card flex-between-center mt-24" @tap="goPay(2)">
						<view class="flex-y-center">
							<image class="w-52 h-52" :src="imgHost +  '/statics/images/order/xianxia_pay.png'"></image>
							<view class="flex-col ml-20">
								<text class="fs-28">余额支付</text>
								<text class="pt-8 fs-22 text--w111-999">当前余额￥{{userInfo.now_money}}</text>
							</view>
						</view>
						<text class="iconfont icon-ic_rightarrow fs-32 text--w111-999 "></text>
					</view>
				</view>
				<view class="mx-20 pb-safe">
					<view class="mt-52 h-72 flex-center rd-36px bg--w111-fff fs-26 text-primary-con btn-border" @click="closePayDrawer">取消</view>
				</view>
			</view>
		</base-drawer>
		<base-drawer mode="bottom" :visible="isStore" backgroundColor="transparent" @close="closeStore">
			<view class="product-window store">
				<view class="storeTitle">选择门店<text class="iconfont icon-guanbi5" @click="closeStore"></text></view>
				<scroll-view scroll-y="true" class="scroll-view">
					<view class="storeList">
						<view class="item" :class="store_id == item.id?'on':''" v-for="(item,index) in storeList" :key="index" @click="tapStore(item,index)">
							<text class="iconfont icon-ic_complete"></text>
							<view class="name line1">{{item.name}}</view>
							<view class="address acea-row row-middle">
								<view class="iconfont icon-ic_location51"></view>
								<view class="info">{{item.address}}</view>
							</view>
							<view class="time acea-row row-middle">
								<view class="iconfont icon-icon_clock"></view>
								<view>营业时间：{{item.day_time}}</view>
							</view>
							<view class="iconfont icon-xuanzhong6" v-if="store_id == item.id"></view>
						</view>
					</view>
				</scroll-view>
			</view>
		</base-drawer>
		<!-- 配送方式 -->
		<baseDrawer :visible="deliveryDrawerVisible" mode="bottom" backgroundColor="transparent" @close="closeDeliveryDrawer">
			<view class="rd-t-40rpx bg--w111-fff">
				<view class="">
					<view class="flex-center h-108 rd-t-40rpx fw-500 fs-32">配送方式</view>
					<view class="abs-rt flex-center w-100 h-108">
						<view class="flex-center w-36 h-36 rd-50-p111- bg--w111-eee" @tap="closeDeliveryDrawer">
							<text class="iconfont icon-ic_close fs-24"></text>
						</view>
					</view>
				</view>
				<view class="py-16 fs-28">
					<view class="flex-y-center h-104 px-24" @tap="isStoreDeliveryTypeChange(1)">
						<text>快递发货</text>
						<text class="flex-1 px-12 text--w111-999" v-if="priceGroup.storeFreePostage">满{{priceGroup.storeFreePostage}}包邮</text>
						<text class="flex-1 px-12 text--w111-999" v-else>包邮</text>
						<text :class="['iconfont fs-40', isStoreDeliveryTypeChecked == 1 ? 'icon-a-ic_CompleteSelect text-w111-2A7EFB' : 'icon-ic_unselect text--w111-ccc']"></text>
					</view>
					<view class="flex-y-center h-104 px-24" @tap="isStoreDeliveryTypeChange(2)">
						<text>同城闪送</text>
						<text class="flex-1 px-12 text--w111-999" v-if="priceGroup.sameCityStoreFreePostage">满{{priceGroup.sameCityStoreFreePostage}}免配送费</text>
						<text class="flex-1 px-12 text--w111-999" v-else>免配送费</text>
						<text :class="['iconfont fs-40', isStoreDeliveryTypeChecked == 2 ? 'icon-a-ic_CompleteSelect text-w111-2A7EFB' : 'icon-ic_unselect text--w111-ccc']"></text>
					</view>
					<view class="flex-y-center h-104 px-24" @tap="isStoreDeliveryTypeChange(0)">
						<text>到店自提</text>
						<text class="flex-1 px-12 text--w111-999"></text>
						<text :class="['iconfont fs-40', isStoreDeliveryTypeChecked == 0 ? 'icon-a-ic_CompleteSelect text-w111-2A7EFB' : 'icon-ic_unselect text--w111-ccc']"></text>
					</view>
				</view>
				<view class="p-20">
					<view class="flex-center h-72 rd-36rpx bg-w111-2A7EFB fw-500 fs-26 text--w111-fff" @tap="isStoreDeliveryTypeConfirm">确定</view>
				</view>
				<view class="pb-safe"></view>
			</view>
		</baseDrawer>
		<!-- 选择送达时间 -->
		<baseDrawer :visible="estimateDrawerVisible" mode="bottom" backgroundColor="transparent" @close="closeEstimateDrawer">
			<view class="rd-t-40rpx bg--w111-fff">
				<view class="">
					<view class="flex-center h-108 rd-t-40rpx fw-500 fs-32">选择送达时间</view>
					<view class="abs-rt flex-center w-100 h-108">
						<view class="flex-center w-36 h-36 rd-50-p111- bg--w111-eee" @tap="closeEstimateDrawer">
							<text class="iconfont icon-ic_close fs-24"></text>
						</view>
					</view>
				</view>
				<view class="flex h-560">
					<scroll-view scroll-y="true" class="w-230 bg--w111-f5f5f5 fs-24">
						<view v-for="(item, index) in dateList" :key="index" :class="{ 'bg--w111-fff': index == dateIndex }" class="flex-y-center h-94 pl-34" @tap="dateChange(index)">{{index > 1 ? item.monthday : index ? '明天' : '今天'}} (周{{item.weekday}}) </view>
					</scroll-view>
					<scroll-view scroll-y="true" class="flex-1 min-w-0 fs-28">
						<view v-if="!dateIndex" class="flex-between-center h-100 pl-34 pr-32" @tap="timeChange(-1)">
							<view class="">尽快送达</view>
							<text v-if="timeIndex == -1" class="iconfont icon-ic_complete fs-36 text-w111-2A7EFB"></text>
						</view>
						<template v-for="(item, index) in timeList">
							<view v-if="dateIndex || item.start >= currentTime" :key="index" class="flex-between-center h-100 pl-34 pr-32" @tap="timeChange(index)">
								<view class="">{{item.start}}-{{item.end}}</view>
								<text v-if="index == timeIndex" class="iconfont icon-ic_complete fs-36 text-w111-2A7EFB"></text>
							</view>
						</template>
					</scroll-view>
				</view>
				<view class="pb-safe"></view>
			</view>
		</baseDrawer>
		<!-- 去凑单弹窗 -->
		<groupOrderDrawer :visible="groupDrawerVisible" :zIndex="10" :storeId="store_id" :userId="userId" :news="news" :cartInfo="cartInfo" :cartId="cartId" :poorDeliveryPrice="priceGroup.poorDeliveryPrice" @close="closeGroupDrawer" @cartId="groupOrderSuccess"></groupOrderDrawer>
	</view>
</template>
<script>
	const CACHE_CITY = {};
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import dayjs from '@/plugin/dayjs/dayjs.min.js';
	import {
		orderConfirm,
		postOrderComputed,
		getCouponsOrderPrice,
		orderCreate,
		adminUserAddressList,
		adminCartNum,
		getCashierApi,
		check_shipping
	} from '@/api/admin.js';
	import {
		storeListApi,
	} from '@/api/store.js';
	import {
		getnearbyStore
	} from '@/api/new_store.js';
	import {
		CACHE_LONGITUDE,
		CACHE_LATITUDE
	} from '@/config/cache.js';
	import couponListWindow from '@/components/couponListWindow';
	import giftDrawer from '../components/giftDrawer.vue';
	import groupOrderDrawer from '../components/groupOrderDrawer.vue';
	import tuiModal from '@/components/tui-modal/index.vue';
	import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
	import emptyPage from '@/components/emptyPage.vue';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from 'vuex';
	import colors from '@/mixins/color';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import {
		Debounce
	} from '@/utils/validate.js';
	export default {
		components: {
			couponListWindow,
			giftDrawer,
			tuiModal,
			baseDrawer,
			groupOrderDrawer,
			emptyPage
		},
		mixins: [colors],
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				sysHeight: sysHeight,
				addressInfoArea: [],
				cityShow: 2,
				display: false,
				timeranges: [],
				isShow: false,
				giveData: {
					give_integral: 0,
					give_coupon: []
				},
				giveCartInfo: [],
				confirm: [], //自定义留言
				id: 0,
				isAddress: false,
				textareaStatus: true,
				formContent: '',
				payType: 'weixin', //支付方式
				openType: 1, //优惠券打开方式 1=使用
				active: 0, //支付方式切换
				coupon: {
					coupon: false,
					list: [],
					statusTile: '立即使用'
				}, //优惠券组件
				showAddressDrawer: false, //地址组件
				addressInfo: {}, //地址信息
				pinkId: 0, //拼团id
				addressId: 0, //地址id
				couponId: 0, //优惠券id
				cartId: '', //购物车id
				type: 0, //活动类型
				activityId: 0, //活动ID
				BargainId: 0,
				combinationId: 0,
				seckillId: 0,
				discountId: 0,
				userInfo: {}, //用户信息
				mark: '', //备注信息
				couponTitle: '请选择', //优惠券
				coupon_price: 0, //优惠券抵扣金额
				promotions_detail: [], //优惠活动金额明细
				useIntegral: false, //是否使用积分
				integral_price: 0, //积分抵扣金额
				integral: 0,
				ChangePrice: 0, //使用积分抵扣变动后的金额
				formIds: [], //收集formid
				status: 0,
				is_address: false,
				shippingType: 0,
				system_store: {},
				storePostage: 0,
				mydata: {},
				storeList: [],
				store_self_mention: 0,
				cartInfo: [],
				priceGroup: {},
				animated: false,
				totalPrice: 0,
				totalIntegral: 0,
				integralRatio: '0',
				orderKey: '',
				offlinePostage: '',
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				from: '',
				news: 1,
				integral_ratio_status: 1,
				header_type: '',
				pay_close: false,
				noCoupon: 0,
				valid_count: 0,
				discount_id: 0,
				store_id: 0,
				product_type: 1,
				newImg: [],
				isDisplay: [],
				goodsType: 0,
				timerangesIndex: 0,
				isFocus: false,
				latitude: '',
				longitude: '',
				showGiftDrawer: false,
				svip_status: false,
				svip_price: 0,
				contactsFocus: false,
				telFocus: false,
				userId: 0,
				addressList: [],
				addressActive: 0,
				userPhone: '',
				showPayDrawer: false,
				isCash: 0,
				deliveryType: 1,
				isStore: false,
				payDisabled: false,
				isStoreDeliveryType: 0, // 门店配送 1:快递发货 2:同城配送
				deliveryDrawerVisible: false, // 配送方式弹窗显示状态
				isStoreDeliveryTypeChecked: -1,
				estimateDrawerVisible: false, // 选择送达时间弹窗显示状态
				dateList: [],
				dateIndex: 0,
				timeList: [],
				currentTime: '',
				timeIndex: -1,
				groupDrawerVisible: false, // 凑单弹窗显示状态
				groupCartId: [],
				rangeOut: false,
			};
		},
		computed: {
			...mapGetters(['isLogin']),
			giftCount() {
				let count = 0;
				if (this.giveCartInfo.length) {
					count = this.giveCartInfo.length;
				}
				if (this.giveData.give_coupon.length) {
					count = count + this.giveData.give_coupon.length;
				}
				if (this.giveData.give_integral > 0) {
					count = count + 1;
				}
				return count;
			},
			canSubmit() {
				switch (this.shippingType){
					case 1:
					case 3:
						return Object.keys(this.addressInfo).length > 0;
					case 2:
						return !!(this.userInfo.real_name && this.userInfo.phone && this.userId);
					default:
						return false;
				}
			},
			currentAddress() {
				let address = this.addressList.find(item => {
					return item.id == this.addressId;
				});
				return address || this.addressList[0] || {};
			},
			currentStore() {
				let store = this.storeList.find(item => {
					return item.id == this.store_id;
				});
				return store || this.storeList[0] || {};
			},
			deliveryTimeText() {
				let dateText = '';
				let timeText = '';
				if (this.isStoreDeliveryType != 2) {
					return '';
				}
				const delivery_config = this.system_store.delivery_config;
				// 统一尽快送达
				if (delivery_config.delivery_time_type == 2) {
					return delivery_config.delivery_prompt || '尽快送达';
				}

				// 处理日期显示
				if (this.dateList && this.dateList[this.dateIndex]) {
					if (this.dateIndex > 1) {
						dateText = this.dateList[this.dateIndex].monthday;
					} else if (this.dateIndex === 1) {
						dateText = '明天';
					} else if (this.dateIndex === 0) {
						dateText = '今天';
					}
					dateText += `（周${this.dateList[this.dateIndex].weekday}）`;
				}

				// 处理时间显示
				if (this.timeList && this.timeList[this.timeIndex]) {
					timeText = `${this.timeList[this.timeIndex].start}-${this.timeList[this.timeIndex].end}`;
				} else {
					timeText = '尽快送达';
				}

				return dateText + timeText;
			},
		},
		watch: {
			currentStore(newValue, oldValue) {
				this.store_id = newValue.id;
			},
			currentAddress(newValue, oldValue) {
				this.addressInfo = newValue;
			}
		},
		onLoad: function(options) {
			// #ifdef H5
			this.from = this.$wechat.isWeixin() ? 'weixin' : 'weixinh5';
			// #endif
			// #ifdef MP
			this.from = 'routine';
			// #endif
			if (!options.cartId)
				return this.$util.Tips({
					title: '请选择要购买的商品'
				}, {
					tab: 3,
					url: 1
				});
			this.cartId = options.cartId;
			this.userId = options.uid ? parseInt(options.uid) : 0;
			this.news = options.news;
			this.is_address = options.is_address ? true : false;
			this.deliveryType = options.delivery_type || '1';
			this.store_id = Number(options.store_id) || 0;
			this.addressId = Number(options.addressId) || 0;

			// #ifndef APP-PLUS
			this.textareaStatus = true;
			// #endif
			if (this.isLogin) {
				this.checkShipping();
			} else {
				toLogin();
			}
		},
		/**
		 * 生命周期函数--监听页面显示
		 */
		onShow() {
			uni.$on('refresh', (data) => {
				this.addressInfo.province = data.address.province;
				this.$set(this.addressInfo, 'city', data.address.city);
				this.addressInfo.district = data.address.district;
				this.addressInfo.street = data.address.street;
				this.addressInfo.detail = data.detail;
				this.addressInfo.real_name = data.real_name;
				this.addressInfo.phone = data.phone;
				this.addressInfo.id = 0;
				this.showAddressDrawer = false;
				this.getConfirm();
			});
		},
		methods: {
			goPay(type) {
				if (this.payDisabled) {
					return;
				}
				this.payDisabled = true;
				this.isCash = type;
				this.formVerify();
			},
			getLocation() {
				uni.getLocation({
					type: 'gcj02',
					success: (res) => {
						this.latitude = res.latitude;
						this.longitude = res.longitude;
					}
				});
			},
			/**
			 * 获取门店列表数据
			 */
			getList: function() {
				let data = {
					page: 1,
					limit: 100,
					is_store: '', //查找所有门店列表
				};
				storeListApi(data)
					.then((res) => {
						let list = res.data.list.list || [];
						this.$set(this, 'storeList', list);
					})
					.catch((err) => {});
			},
			computedPrice: function() {
				let data = {
					addressId: this.shippingType == 2 || !this.addressInfo.id ? 0 : this.addressInfo.id,
					useIntegral: this.useIntegral ? 1 : 0,
					couponId: this.priceGroup.couponPrice == 0 ? 0 : this.couponId,
					shipping_type: this.shippingType,
					payType: this.payType,
					store_id: this.store_id,
					is_store_delivery_type: this.isStoreDeliveryType,
					real_name: this.addressInfo.real_name,
					phone: this.addressInfo.phone,
					address: this.addressInfo.province + this.addressInfo.city + this.addressInfo.district + this.addressInfo.street + this.addressInfo.detail,
				};
				let cartId = this.cartId;
				if (this.groupCartId.length) {
					const cartIdMap = new Map(this.groupCartId);
					cartIdMap.forEach(value => {
						cartId += `,${value}`;
					});
				}
				let cartIdArr = [...new Set(cartId.split(','))];
				data.cartId = cartIdArr.join(',');
				postOrderComputed(this.orderKey, this.userId, data)
					.then((res) => {
						let result = res.data.result;
						if (result) {
							this.totalPrice = result.payPrice;
							this.totalIntegral = result.usedIntegral;
							this.integral_price = result.deductionPrice;
							this.coupon_price = result.couponPrice;
							this.promotions_detail = result.promotionsDetail;
							this.integral = this.useIntegral ? result.surplusIntegral : this.userInfo.integral;
							this.$set(this.priceGroup, 'storePostage', this.shippingType == 2 ? 0 : result.pay_postage);
							this.$set(this.priceGroup, 'storePostageDiscount', result.storePostageDiscount);
							this.$set(this.priceGroup, 'storeFreePostage', result.storeFreePostage);
							this.$set(this.priceGroup, 'sameCityStoreFreePostage', Number(result.sameCityStoreFreePostage));
							this.$set(this.priceGroup, 'poorDeliveryPrice', Number(result.poorDeliveryPrice));
							this.$set(this.priceGroup, 'poorFreeShipping', Number(result.poorFreeShipping));
						}
					})
					.catch((err) => {
						this.$util.Tips({
							title: err
						});
					});
			},
			addressType: function(e, value) {
				let index = e;
				this.shippingType = parseInt(index);
				this.deliveryType = value;
				this.getConfirm();
			},
			bindPickerChange: function(e) {
				let value = e.detail.value;
				this.shippingType = value;
				this.computedPrice();
			},
			ChangCouponsClone: function() {
				this.$set(this.coupon, 'coupon', false);
			},
			/**
			 * 处理点击优惠券后的事件
			 *
			 */
			ChangCoupons: function(index) {
				let list = this.coupon.list;
				if (list[index].is_use) {
					list[index].use_title = '';
					list[index].is_use = 0;
					this.couponTitle = '请选择';
					this.couponId = 0;
				} else {
					list[index].use_title = '不使用';
					list[index].is_use = 1;
					this.couponTitle = list[index].title;
					this.couponId = list[index].id;
				}
				this.$set(this.coupon, 'coupon', false);
				this.$set(this.coupon, 'list', list);
				this.getConfirm(1);
			},
			/**
			 * 使用积分抵扣
			 */
			ChangeIntegral: function() {
				this.useIntegral = !this.useIntegral;
				this.computedPrice();
			},
			bindHideKeyboard: function(e) {
				this.mark = e.detail.value;
			},
			// 对象转数组
			objToArr(data) {
				let obj = Object.keys(data);
				let m = obj.map((key) => data[key]);
				return m;
			},
			/**
			 * 获取当前订单详细信息
			 *
			 */
			getConfirm: function(numType) {
				let that = this;
				let body = {
					new: that.news,
					shipping_type: that.shippingType,
					addressId: that.shippingType == 2 || !that.addressInfo.id ? 0 : that.addressInfo.id,
					store_id: that.store_id,
					couponId: that.couponId,
					is_store_delivery_type: that.isStoreDeliveryType,
					real_name: that.addressInfo.real_name,
					phone: that.addressInfo.phone,
					address: that.addressInfo.province + that.addressInfo.city + that.addressInfo.district + that.addressInfo.street + that.addressInfo.detail,
				};
				let cartId = that.cartId;
				if (that.groupCartId.length) {
					const cartIdMap = new Map(that.groupCartId);
					cartIdMap.forEach(value => {
						cartId += `,${value}`;
					});
				}
				let cartIdArr = [...new Set(cartId.split(','))];
				body.cartId = cartIdArr.join(',');
				orderConfirm(this.userId, body)
					.then((res) => {
						this.rangeOut = false;
						if (res.data.upgrade_addr == 1) {
							that.id = res.data.addressInfo.id;
							this.isAddress = true;
						}
						that.$set(that, 'goodsType', res.data.type);
						that.$set(that, 'userInfo', res.data.userInfo);
						that.$set(that, 'userPhone', res.data.userInfo.phone);
						that.$set(that, 'integral', res.data.userInfo.integral);
						that.$set(that, 'integralRatio', res.data.integralRatio);
						that.$set(that, 'offlinePostage', res.data.offlinePostage);
						that.$set(that, 'orderKey', res.data.orderKey);
						that.$set(that, 'valid_count', res.data.valid_count);
						that.$set(that, 'discount_id', res.data.discount_id);
						that.$set(that, 'priceGroup', res.data.priceGroup);
						that.$set(that, 'type', parseInt(res.data.type));
						that.$set(that, 'activityId', parseInt(res.data.activityId));
						that.$set(that, 'seckillId', parseInt(res.data.seckill_id));
						that.$set(that, 'BargainId', parseInt(res.data.bargain_id));
						that.$set(that, 'combinationId', parseInt(res.data.combination_id));
						that.$set(that, 'discountId', parseInt(res.data.discount_id));
						that.$set(that, 'integral_ratio_status', res.data.integral_ratio_status);
						that.$set(that, 'store_self_mention', res.data.store_self_mention);
						that.$set(that, 'svip_status', res.data.svip_status);
						that.$set(that, 'svip_price', res.data.svip_price);
						that.giveData.give_integral = res.data.give_integral;
						that.giveData.give_coupon = res.data.give_coupon;
						let cartInfo = res.data.cartInfo;
						let cartObj = [],
							giftObj = [];
						cartInfo.forEach((item) => {
							if (item.is_gift == 1) {
								giftObj.push(item);
							} else {
								cartObj.push(item);
							}
						});
						that.$set(that, 'cartInfo', cartObj);
						that.$set(that, 'giveCartInfo', giftObj);
						let giveType = -1;
						giftObj.forEach((item) => {
							if (item.product_type == 0) {
								return (giveType = 0);
							}
						});
						that.$set(that, 'product_type', res.data.product_type == 0 || giveType == 0 ? 0 : 1);
						that.$set(that, 'ChangePrice', that.totalPrice);
						that.getCouponList();
						that.computedPrice();
						that.$set(that, 'totalPrice', that.$util.$h.Add(parseFloat(res.data.priceGroup.totalPrice), parseFloat(res.data.priceGroup.storePostage)));
					})
					.catch((err) => {
						this.rangeOut = err === '地址有误、商家自配未开启、不在配送范围内!';
						return this.$util.Tips({
							title: err
						});
					});
			},
			/**
			 * 获取当前金额可用优惠券
			 *
			 */
			getCouponList: function() {
				let that = this;
				let data = {
					new: this.news,
					shipping_type: that.shippingType,
					store_id: that.store_id,
					is_store_delivery_type: that.isStoreDeliveryType,
				};
				let cartId = that.cartId;
				if (that.groupCartId.length) {
					const cartIdMap = new Map(that.groupCartId);
					cartIdMap.forEach(value => {
						cartId += `,${value}`;
					});
				}
				let cartIdArr = [...new Set(cartId.split(','))];
				data.cartId = cartIdArr.join(',');
				getCouponsOrderPrice(this.userId, data)
					.then((res) => {
						that.$set(that.coupon, 'list', res.data);
						that.openType = 1;
					})
					.catch((err) => {
						return that.$util.Tips({
							title: err
						});
					});
			},
			/*
			 * 获取默认收货地址或者获取某条地址信息
			 */
			getAddressInfo(callback) {
				adminUserAddressList(this.userId)
					.then((res) => {
						this.addressList = res.data;
						let index = -1;
						if (this.addressList.length) {
							index = this.addressList.findIndex(item => item.id == this.addressId);
							if (index == -1) {
								index = this.addressList.findIndex(item => item.is_default);
							}
							if (index == -1) {
								index = 0;
							}
						}
						if (index > -1) {
							this.addressActive = index;
							this.addressInfo = this.addressList[index];
						}
						callback();
					})
					.catch((err) => {
						this.$util.Tips({
							title: err
						});
					});
			},
			showMaoLocation() {
				this.isStore = true
			},
			couponTap: function() {
				this.coupon.coupon = true;
				this.coupon.list.forEach((item, index) => {
					if (item.id == this.couponId) {
						item.is_use = 1;
					} else {
						item.is_use = 0;
					}
				});
				this.$set(this.coupon, 'list', this.coupon.list);
			},
			car: function() {
				let that = this;
				that.animated = false;
			},
			onAddress() {
				this.showAddressDrawer = true;
			},
			goAddressPages() {
				uni.navigateTo({
					url: '/pages/users/user_address/addClient'
				});
			},
			tapAddress(item, index) {
				this.showAddressDrawer = false;
				this.addressActive = index;
				this.addressInfo = item;
				this.addressId = item.id;
				this.getConfirm();
			},
			payment: function(data) {
				orderCreate(this.orderKey, this.userId, data)
					.then((res) => {
						this.$Cache.clear('touristId');
						uni.hideLoading();
						if (!this.isCash) {
							this.payDisabled = false;
							this.showPayDrawer = false;
							uni.redirectTo({
								url: `/pages/behalf/cashier/index?order_id=${res.data.result.order_id}&uid=${this.userId}`
							});
						} else {
							let payData = {
								uni: res.data.result.order_id,
								paytype: this.isCash == 1 ? 'cash' : 'yue',
								quitUrl: ''
							};
							getCashierApi(this.userId, payData).then(response => {
								if (response.data.status == 'SUCCESS') {
									this.payDisabled = false;
									this.showPayDrawer = false;
									this.$util.Tips({
										title: '支付成功'
									}, {
										tab: 5,
										url: '/pages/admin/orderDetail/index?id=' + res.data.result.order_id
									});
								} else if (response.data.status == 'PAY_DEFICIENCY') {
									this.payDisabled = false;
									this.showPayDrawer = false;
									this.$util.Tips({
										title: response.msg
									});
								}
							}).catch(err => {
								this.payDisabled = false;
								this.showPayDrawer = false;
								this.$util.Tips({
									title: err
								})
							})
						}

					})
					.catch((err) => {
						this.payDisabled = false;
						uni.hideLoading();
						this.$util.Tips({
							title: err
						});
					});
			},
			clickTextArea() {
				this.$refs.textarea.focus();
			},
			bindDateChange: function(e, index) {
				this.confirm[index].value = e.target.value;
			},
			bindTimeChange: function(e, index) {
				this.confirm[index].value = e.target.value;
			},
			bindSelectChange: function(e, index, item) {
				this.confirm[index].value = item.wordsConfig.list[e.detail.value].val;
			},
			getTimeranges(index) {
				this.isShow = true;
				this.timerangesIndex = index;
			},
			confrim(e) {
				this.isShow = false;
				this.confirm[this.timerangesIndex].value = e.time;
				let arrayNew = [];
				e.val.forEach((item) => {
					arrayNew.push(Number(item));
				});
				this.timeranges = arrayNew;
			},
			formVerify() {
				let that = this;
				// if (!that.addressInfo.real_name && !that.shippingType && !that.product_type) {
				// 	this.payDisabled = false;
				// 	return that.$util.Tips({
				// 		title: '请选择收货地址'
				// 	});
				// }
				// 商城配送/门店配送
				if (that.shippingType == 1 || that.shippingType == 3) {
					if (!Object.keys(that.addressInfo).length) {
						this.payDisabled = false;
						return that.$util.Tips({
							title: '请选择收货地址'
						});
					}
					// if (that.userInfo.real_name == '' || that.userInfo.phone == '') {
					// 	this.payDisabled = false;
					// 	return that.$util.Tips({
					// 		title: '请填写联系人或联系人电话'
					// 	});
					// }
					// if (!/^1(3|4|5|7|8|9|6)\d{9}$/.test(that.userInfo.phone)) {
					// 	this.payDisabled = false;
					// 	return that.$util.Tips({
					// 		title: '请填写正确的手机号'
					// 	});
					// }
					// if (!/^[\u4e00-\u9fa5\w]{2,16}$/.test(that.userInfo.real_name)) {
					// 	this.payDisabled = false;
					// 	return that.$util.Tips({
					// 		title: '请填写您的真实姓名'
					// 	});
					// }
					// if (that.storeList.length == 0) {
					// 	this.payDisabled = false;
					// 	return that.$util.Tips({
					// 		title: '暂无门店,请选择其他方式'
					// 	});
					// }
				}
				// 门店自提
				if (this.type == 4 && this.totalIntegral > this.userInfo.integral) {
					this.payDisabled = false;
					return that.$util.Tips({
						title: '您的积分不足以抵扣本单积分金额'
					});
				}
				this.SubOrder();
			},
			SubOrder: function(e) {
				let that = this,
					data = {};
				// data = {
				// 	real_name: that.shippingType == 0 ? that.addressInfo.real_name : that.userInfo.real_name,
				// 	phone: that.shippingType == 0 ? that.addressInfo.phone : that.userInfo.phone,
				// 	address: that.shippingType == 0 ? that.addressInfo.province + that.addressInfo.city + that.addressInfo.district + that.addressInfo.street + that.addressInfo.detail : '',
				// 	addressId: that.deliveryType == 2 ? 0 : that.addressInfo.id,
				// 	couponId: that.priceGroup.couponPrice == 0 ? 0 : that.couponId,
				// 	useIntegral: that.useIntegral,
				// 	mark: that.mark,
				// 	shipping_type: that.$util.$h.Add(that.shippingType, 1),
				// 	cartId: that.cartId,
				// 	store_id: that.deliveryType == 1 ? 0 : that.store_id,
				// };
				data = {
					real_name: that.shippingType != 2 ? that.addressInfo.real_name : that.userInfo.real_name,
					phone: that.shippingType != 2 ? that.addressInfo.phone : that.userInfo.phone,
					address: that.shippingType != 2 ? that.addressInfo.province + that.addressInfo.city + that.addressInfo.district + that.addressInfo.street + that.addressInfo.detail : '',
					addressId: that.shippingType == 2 ? 0 : that.addressInfo.id,
					couponId: that.priceGroup.couponPrice == 0 ? 0 : that.couponId,
					useIntegral: that.useIntegral,
					mark: that.mark,
					shipping_type: that.shippingType,
					// cartId: that.cartId,
					store_id: that.store_id,
					is_store_delivery_type: that.isStoreDeliveryType,
					estimate_time: '',
				};
				let cartId = that.cartId;
				if (that.groupCartId.length) {
					const cartIdMap = new Map(that.groupCartId);
					cartIdMap.forEach(value => {
						cartId += `,${value}`;
					});
				}
				let cartIdArr = [...new Set(cartId.split(','))];
				data.cartId = cartIdArr.join(',');
				if (that.shippingType == 3 && that.isStoreDeliveryType == 2) {
					const delivery_config = that.system_store.delivery_config;
					if (delivery_config.delivery_time_type == 1) {
						const dateItem = that.dateList[that.dateIndex];
						const timeItem = that.timeList[that.timeIndex];
						data.estimate_time = `${dateItem.monthday} (周${dateItem.weekday}) ${that.timeIndex == -1 ? '尽快送达' : `${timeItem.start}-${timeItem.end}`}`;
					} else{
						data.estimate_time = delivery_config.delivery_prompt || '尽快送达';
					}
				}
				uni.showLoading({
					title: '订单支付中'
				});
				that.payment(data);
			},
			// 去详情页
			goDetail(id) {
				uni.navigateTo({
					url: `/pages/goods_details/index?id=${id}`
				});
			},
			// 加减商品
			addCart(type, item) {
				if (type == 1 && item.productInfo.stock == item.cart_num)
					return this.$util.Tips({
						title: '该产品没有更多库存了！'
					});
				if (!type && item.cart_num <= 1) {
					return
				}
				adminCartNum(this.userId, {
						id: item.id,
						number: type ? item.cart_num + 1 : item.cart_num - 1,
						tourist_uid: this.userId == 0 ? this.$Cache.get('touristId') : ''
					})
					.then((res) => {
						this.getConfirm();
					})
					.catch((err) => {
						return this.$util.Tips({
							title: err
						});
					});
			},
			setValue: Debounce(function(e, item) {
				let num = e.detail.value;
				if (!num) {
					return
				}
				if (item.productInfo.limit_num > 0 && num > item.productInfo.limit_num) {
					item.cart_num = item.productInfo.limit_num;
					return this.$util.Tips({
						title: '购物车数量不能大于限购数量'
					});
				}
				adminCartNum(this.userId, {
						id: item.id,
						number: num,
						tourist_uid: this.userId == 0 ? this.$Cache.get('touristId') : ''
					})
					.then((res) => {
						this.getConfirm();
						setTimeout(() => {
							this.computedPrice();
						}, 500);
					})
					.catch((err) => {
						return this.$util.Tips({
							title: err
						});
					});
			}),
			goPage(type, url) {
				if (type == 1) {
					uni.navigateTo({
						url
					});
				} else if (type == 2) {
					uni.switchTab({
						url
					});
				} else if (type == 3) {
					uni.navigateBack();
				}
			},
			closeDrawer() {
				this.showGiftDrawer = false;
			},
			hideModal() {
				this.isAddress = false;
			},
			handleClick(e) {
				let index = e.index;
				if (index == 1) {
					uni.navigateTo({
						url: '/pages/users/user_address/index?id=' + this.id + '&new=' + this.news + '&cartId=' + this.cartId + '&pinkId=' + this.pinkId + '&couponId=' + this.couponId
					});
				}
				this.isAddress = false;
			},
			clearInput(type) {
				if (type == 0) {
					this.userInfo.real_name = '';
					this.contactsFocus = true;
				} else {
					this.userInfo.phone = '';
					this.telFocus = true;
				}
			},
			payDrawerChange() {
				this.showPayDrawer = true;
			},
			checkShipping() {
				check_shipping({
					cartId: this.cartId,
					new: this.news,
					uid: this.userId,
				}).then(res => {
					// 商城 1：平台配送，2：到店自提，3：门店配送
					// 门店 1：快递发货，2：到店自提，3：同城配送
					res.data.type.sort((x, y) => x - y).forEach(x => Number(x)) //正序
					let isDisplay = res.data.type.map(Number);
					this.isDisplay = isDisplay;
					if (this.store_id) {
						// 门店
						switch (this.isDisplay[0]) {
							case 1:
								// 门店配送-快递发货
								this.shippingType = 3;
								this.isStoreDeliveryType = 1;
								break;
							case 2:
								// 门店自提
								this.shippingType = 2;
								this.isStoreDeliveryType = 0;
								break;
							case 3:
								// 门店配送-同城配送
								this.shippingType = 3;
								this.isStoreDeliveryType = 2;
								break;
						}
						this.getList();
						this.getNearbyStore();
						if (this.isDisplay.includes(1) || this.isDisplay.includes(3)) {
							this.getAddressInfo(this.getConfirm);
						} else {
							this.getConfirm();
						}
					} else {
						// 商城
						this.shippingType = 1;
						this.isStoreDeliveryType = 0;
						this.getAddressInfo(this.getConfirm);
					}
					// if (this.shippingType == 1) {
					// 	// 商城配送
					// 	this.getAddressInfo(this.getConfirm);
					// } else if (this.shippingType == 2) {
					// 	// 门店自提
					// 	this.getList();
					// 	this.getNearbyStore();
					// 	this.getConfirm();
					// } else if (this.shippingType == 3) {
					// 	// 门店配送
					// 	this.getList();
					// 	this.getNearbyStore();
					// 	this.getAddressInfo(this.getConfirm);
					// }
				});
			},
			closeStore() {
				this.isStore = false;
			},
			goStore() {
				this.isStore = true;
			},
			// 切换门店
			tapStore(item, index) {
				this.$set(this, 'store_id', item.id);
				this.store_id = item.id;
				this.isStore = false;
				this.getConfirm();
			},
			closePayDrawer() {
				this.showPayDrawer = false;
			},
			closeAddressDrawer() {
				this.showAddressDrawer = false;
			},
			// 打开配送方式弹窗
			openDeliveryDrawer() {
				if (this.shippingType == 1 || this.isDisplay.length < 2) {
					return;
				}
				this.isStoreDeliveryTypeChecked = this.isStoreDeliveryType;
				this.deliveryDrawerVisible = true;
			},
			// 关闭配送方式弹窗
			closeDeliveryDrawer() {
				this.deliveryDrawerVisible = false;
			},
			// 配送方式改变
			isStoreDeliveryTypeChange(value) {
				// this.isStoreDeliveryTypeChecked = value;
				this.isStoreDeliveryType = value;
				this.shippingType = this.isStoreDeliveryType ? 3 : 2;
				this.deliveryDrawerVisible = false;
				this.getConfirm();
			},
			// 配送方式确定
			isStoreDeliveryTypeConfirm() {
				// this.isStoreDeliveryType = this.isStoreDeliveryTypeChecked;
				// this.shippingType = this.isStoreDeliveryType ? 3 : 2;
				this.deliveryDrawerVisible = false;
				// this.getConfirm();
			},
			// 门店信息
			getNearbyStore() {
				getnearbyStore({
					store_id: this.store_id,
				}).then(res => {
					this.$set(this, 'system_store', res.data.info);
					const delivery_config = this.system_store.delivery_config;
					const delivery_time_type = delivery_config.delivery_time_type;
					// 买家可选定时送达
					if (delivery_time_type == 1) {
						const today = new Date();
						const currentTime = new Date(today.getFullYear(), today.getMonth(), today.getDate()).getTime();
						const selectable_days = delivery_config.selectable_days;
						let dateList = [];
						for (let i = 0; i < selectable_days; i++) {
							const date = new Date(currentTime + i * 24 * 60 * 60 * 1000);
							const m = date.getMonth() + 1;
							const d = date.getDate();
							dateList.push({
								monthday: `${m}月${d}日`,
								weekday: ['日', '一', '二', '三', '四', '五', '六'][date.getDay()],
								value: date.getTime(),
							});
						}
						this.dateList = dateList;
						const getTimeList = (startTime, endTime, step = 30) => {
							const baseDate = new Date().toISOString().slice(0, 10);
							let start = dayjs(`${baseDate} ${startTime}`);
							const end = dayjs(`${baseDate} ${endTime}`);
							const slots = [];
							while (start.isBefore(end)) {
								const next = dayjs(start).add(step, 'minute');
								slots.push({
									start: start.format('HH:mm'),
									end: next.isAfter(end) ? end.format('HH:mm') : next.format('HH:mm')
								});
								start = next;
							}
							return slots;
						};
						this.timeList = getTimeList(this.system_store.day_start, this.system_store.day_end);
					}
				});
			},
			// 打开选择送达时间弹窗
			openEstimateDrawer() {
				if (!this.dateList.length) {
					return;
				}
				this.estimateDrawerVisible = true;
				this.currentTime = dayjs().format('HH:mm');
				if (!this.dateIndex) {
					if (this.timeIndex != -1) {
						const timeIndex = this.timeList.findIndex(item => item.start >= this.currentTime);
						if (this.timeIndex < timeIndex) {
							this.timeIndex = timeIndex;
						}
					}
				}
			},
			// 关闭选择送达时间弹窗
			closeEstimateDrawer() {
				this.estimateDrawerVisible = false;
			},
			// 选择日期
			dateChange(index) {
				this.dateIndex = index;
				if (index) {
					this.timeIndex = 0;
				} else {
					this.timeIndex = -1;
				}
			},
			// 选择时间段
			timeChange(index) {
				this.timeIndex = index;
				this.estimateDrawerVisible = false;
			},
			openGroupDrawer() {
				this.groupDrawerVisible = true;
			},
			closeGroupDrawer() {
				this.groupDrawerVisible = false;
			},
			// 凑单操作
			groupOrderSuccess(productIdUnique, cartId) {
				// 商品与cartID映射
				const cartIdMap = new Map(this.groupCartId);
				if (cartId === 1) {
					cartIdMap.delete(productIdUnique);
				} else {
					if (!cartIdMap.has(productIdUnique)) {
						cartIdMap.set(productIdUnique, cartId);
					}
				}
				this.groupCartId = Array.from(cartIdMap);
				this.getConfirm();
			},
		}
	};
</script>

<style lang="scss" scoped>
	.bg-w111-E9F2FE {
		background-color: #E9F2FE;
	}
	.text-w111-FF7E00{
		color: #FF7E00;
	}

	/deep/.uni-date-x--border {
		border: 0;
	}

	/deep/.uni-icons {
		font-size: 0 !important;
	}

	/deep/.uni-date-x {
		color: #999;
		font-size: 15px;
	}

	/deep/.uni-date__x-input {
		font-size: 15px;
	}

	// .height-add {
	// 	height: calc(176rpx+ constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
	// 	height: calc(176rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
	// }

	/deep/uni-checkbox[disabled] .uni-checkbox-input {
		background-color: #eee;
	}

	.alipaysubmit {
		display: none;
	}

	.abs-lt .active-card {
		&:after {
			right: -67rpx;
		}
	}

	.abs-ct .active-card {
		&:after {
			right: -67rpx;
		}

		&:before {
			left: -67rpx;
			transform: scaleX(-1);
		}
	}

	.abs-rt .active-card {
		&:after {
			left: -67rpx;
			-moz-transform: scaleX(-1);
			-webkit-transform: scaleX(-1);
			-o-transform: scaleX(-1);
			transform: scaleX(-1);
		}
	}

	.active-card {

		&:after,
		&:before {
			content: '';
			width: 67rpx;
			height: 76rpx;
			background-image: url('@/static/img/nav_circle_left.png');
			background-size: contain;
			background-repeat: no-repeat;
			position: absolute;
			bottom: 0;
			z-index: 4;
		}
	}

	.line {
		width: 680rpx;
		margin: auto;
		height: 3rpx;
	}

	.line image {
		width: 100%;
		height: 100%;
		display: block;
	}

	.address {
		background-color: #fff;
		box-sizing: border-box;
	}

	.add1 {
		padding: 40rpx 28rpx 40rpx 32rpx;
	}

	.add2 {
		padding: 36rpx 20rpx 0 32rpx;
	}

	.footer .transparent {
		opacity: 0;
	}

	._map {
		width: 188rpx;
		height: 104rpx;
		background-size: 100%;
		background-repeat: no-repeat;
	}

	.store_distance {
		position: absolute;
		top: 0;
		left: 30rpx;
		width: 130rpx;
		height: 36rpx;
		box-shadow: 0px 0px 16rpx 0px rgba(0, 0, 0, 0.0784);
	}

	.store_logo {
		position: absolute;
		top: 50%;
		left: 68rpx;
		width: 52rpx;
		height: 52rpx;
		box-shadow: 0px 0px 16rpx 0px rgba(0, 0, 0, 0.0784);
		padding: 6rpx;
		transform: translateY(-50%);

		&:after {
			content: '';
			position: absolute;
			bottom: -10rpx;
			left: 50%;
			transform: translateX(-50%);
			width: 0;
			height: 0;
			border-top: 20rpx solid #fff;
			border-right: 20rpx solid transparent;
			border-left: 20rpx solid transparent;
		}
	}

	.w-322 {
		width: 322rpx;
	}

	.w-450 {
		width: 450rpx;
	}

	.order-goods~.order-goods {
		margin-top: 32rpx;
	}

	.cell input {
		width: 450rpx;
		text-align: right;
	}

	.cell .radio {
		margin: 0 22rpx;
		padding: 10rpx 0;
	}

	.cell~.cell {
		margin-top: 40rpx;
	}

	.SemiBold {
		font-family: SemiBold;
	}

	.Regular {
		font-family: 'Regular';
	}

	.placeholder {
		color: #ccc;
	}

	.asterisk {
		position: absolute;
		color: red;
		left: 0;
	}

	.gradient-box {
		background: linear-gradient(180deg, $primary-admin 0%, $primary-admin 52%, rgba(233, 51, 35, 0) 100%);
	}

	.w-50p {
		width: 50%;
	}

	.h-auto {
		height: auto;
	}

	.text-primary-con {
		color: $primary-admin;
	}

	.bg-primary {
		background: $primary-admin;
	}

	.bg-primary-light {
		background: $light-primary-admin;
	}

	.rd-lt-24rpx {
		border-radius: 24rpx 0 0 0;
	}

	.rd-rt-24rpx {
		border-radius: 0 24rpx 0 0;
	}

	.z-2 {
		z-index: 2;
	}

	.gold {
		color: #dca658;
	}

	.over {
		width: 104rpx;
		height: 104rpx;
		border-radius: 50%;
		background-color: rgba(51, 51, 51, 0.6);
		position: absolute;
		top: 50%;
		left: 50%;
		transform: translate(-50%, -50%);
	}

	.con-border {
		border: 1px solid $primary-admin;
	}

	.clear-btn {
		border-radius: 0 12rpx 0 12rpx;
	}

	.svip {
		width: 56rpx;
		height: 26rpx;
		background: linear-gradient(270deg, #484643 0%, #1f1b17 100%);
		border-radius: 100rpx;
		font-size: 18rpx;
		font-weight: 600;
		color: #fddaa4;
		margin-left: 10rpx;
	}

	.vip {
		width: 64rpx;
		height: 26rpx;
		background: #fef0d9;
		border: 1px solid #facc7d;
		border-radius: 50rpx;
		font-size: 18rpx;
		font-weight: 500;
		color: #dfa541;
		margin-left: 10rpx;

		.iconfont {
			font-size: 20rpx;
			margin-right: 4rpx;
		}
	}

	.icon-a-ic_CompleteSelect {
		color: $primary-admin;
	}

	.pay-card {
		padding: 28rpx 32rpx;
	}

	.btn-border {
		border: 1rpx solid $primary-admin;
		;
	}

	.product-window {
		padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
		padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/

		&.store {
			background-color: #F5F5F5;
			border-radius: 40rpx 40rpx 0 0;
		}

		.storeTitle {
			text-align: center;
			height: 100rpx;
			line-height: 100rpx;
			font-size: 32rpx;
			font-weight: 500;
			color: #282828;
			position: relative;

			.iconfont {
				position: absolute;
				font-size: 35rpx;
				right: 30rpx;
			}
		}

		.scroll-view {
			max-height: 690rpx;
		}

		.storeList {
			.item {
				width: 690rpx;
				background: #FFFFFF;
				border-radius: 12rpx;
				margin: 0 auto 20rpx auto;
				padding: 28rpx 30rpx;
				border: 1px solid #fff;
				position: relative;
				overflow: hidden;

				&::before {
					display: none;
					content: "";
					position: absolute;
					top: 0;
					right: 0;
					border: 36rpx solid #2A7EFB;
					border-bottom-color: transparent;
					border-left-color: transparent;
				}

				.icon-ic_complete {
					display: none;
					position: absolute;
					top: 8rpx;
					right: 8rpx;
					font-size: 32rpx;
					color: #FFFFFF;
				}

				&.on {
					border-color: #2A7EFB;

					&::before {
						display: block;
					}

					.icon-ic_complete {
						display: block;
					}
				}

				.icon-xuanzhong6 {
					position: absolute;
					right: -6rpx;
					bottom: -6rpx;
					color: var(--view-theme);
					font-size: 54rpx;
				}

				.name {
					font-weight: 500;
					color: #333333;
					font-size: 28rpx;
				}

				.time {
					font-weight: 400;
					color: #888888;
					font-size: 22rpx;
					margin-top: 15rpx;

					.iconfont {
						font-size: 20rpx;
						margin-right: 8rpx;
					}
				}

				.address {
					font-weight: 400;
					color: #888888;
					font-size: 22rpx;
					margin-top: 13rpx;

					.iconfont {
						font-size: 20rpx;
						margin-right: 8rpx;
						margin-top: 6rpx;
					}

					.info {
						width: 590rpx;
						line-height: 1.5;
					}
				}
			}
		}
	}

	.address {
		.default {
			width: 60rpx;
			height: 34rpx;
			background: #E9F2FE;
			border-radius: 8rpx;
			color: #2A7EFB;
			font-size: 22rpx;
		}
	}

	.font-num {
		color: #2A7EFB !important;
	}

	.border-top {
		position: relative;
		padding-top: 32rpx;
		padding-bottom: 32rpx;

		&::before {
			content: "";
			position: absolute;
			top: 0%;
			left: 24rpx;
			right: 24rpx;
			border-top: 1rpx solid #EEEEEE;
			transform: rotateZ(360deg);
		}

		.flex-1 {
			position: relative;
			min-width: 0;

			&::before {
				content: "";
				position: absolute;
				top: 0;
				left: 0;
				bottom: 0;
				border-left: 1rpx solid #EEEEEE;
				transform: rotateZ(360deg);
			}

			&:first-child {
				padding-right: 28rpx;

				&::before {
					display: none;
				}
			}
		}
	}

	.border-line {
		position: relative;

		&::before {
			content: "";
			position: absolute;
			top: 0%;
			left: 24rpx;
			right: 24rpx;
			border-top: 1rpx solid #EEEEEE;
		}
	}

	/deep/.coupon-list {
		.bg-gradient {
			background: linear-gradient(90deg, #2A7EFB 0, #01ABF8 100%);
		}

		.font-num {
			color: #1794FA !important;
		}
	}
</style>