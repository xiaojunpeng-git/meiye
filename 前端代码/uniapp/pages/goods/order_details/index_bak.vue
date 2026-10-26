<template>
	<view :style="colorStyle">
		<view class="w-full relative" :style="{'padding-top': sysHeight + 'px'}">
			<view class="w-full px-20 pl-20 h-80 flex-between-center relative z-80">
				<text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff" @tap="goPage(3)"></text>
				<text class="fs-34 fw-500 text--w111-fff">订单详情</text>
				<text></text>
			</view>
			<view class="px-20 relative z-80">
				<view class="flex-between-center h-160 mt-12">
					<view class="flex-1 text--w111-fff pl-12">
						<view class="fs-36 fw-500 lh-50rpx">{{orderInfo._status._title}}</view>
						<view class="fs-26 lh-36rpx mt-8">{{orderInfo._status._msg}}</view>
					</view>
					<image :src="orderInfo.status_pic" class="w-140 h-140"></image>
				</view>
				<orderStatusLine v-if="orderInfo.product_type == 6" :type="orderInfo.reservation_status"></orderStatusLine>
				<view class="bg--w111-fff rd-24rpx p-32 relative z-1"
					v-if="reservationDoor">
					<view class="pb-20 border-b-EEEEEE mb-20 fw-500 fs-30" v-if="orderInfo.store_delivery_type == 2 && [1, 2, 3, 4].includes(orderInfo._status._type)">
						<text v-if="[1, 2].includes(orderInfo._status._type)">预计送达时间：{{orderInfo.estimate_time}}</text>
						<text v-else-if="[3, 4].includes(orderInfo._status._type)">订单已送达</text>
					</view>
					<view class="flex-between-center">
						<view class="flex-y-center fs-30 lh-42rpx">
							<text class="iconfont icon-ic_location4 fs-30"></text>
							<text class="pl-12">{{orderInfo.real_name}}</text>
							<text class="pl-20">{{orderInfo.user_phone}}</text>
						</view>
						<view class="w-154 h-56 rd-28rpx flex-center bg--w111-f5f5f5 fs-24 lh-34rpx"
							v-if="(orderInfo.delivery_type == 'send' && orderInfo.verify_code) || orderInfo.product_type == 6"
							@tap="showQrcode = true">
							<text class="iconfont icon-ic_QRcode fs-24"></text>
							<text class="pl-10">核销码</text>
						</view>
					</view>
					<view v-if="orderInfo.user_address.trim()" class="fs-24 text--w111-999 lh-34rpx mt-12 w-full line2 pb-8">{{orderInfo.user_address}}</view>
					<!-- 第三方配送 -->
					<view class="bt pt-32 flex-between-center mt-32"
						v-if="orderInfo.delivery_type == 'send' && orderInfo.shipping_type == 2">
						<view class="fs-26 text--w111-666 lh-34rpx flex-col">
							<text>配送员: {{ orderInfo.delivery_info.porter_name }}</text>
							<text class="pt-14">联系电话: {{ orderInfo.delivery_info.porter_phone }}</text>
						</view>
						<view class="w-144 h-56 rd-30rpx flex-center fs-24 border bg--w111-fff" 
							@tap="makePhone(orderInfo.delivery_info.porter_phone)">拨打电话</view>
					</view>
					<image src="/static/images/line.jpg" class="bt_line abs-lb"></image>
				</view>
				<view class="bg--w111-fff rd-24rpx p-32 relative z-1" v-if="reservationStore">
					<view class="border-b-EEEEEE" v-if="configData.cross_store_verification == 1 && orderInfo.product_type == 6">
						<view class="flex-y-center">
							<view class="">
								<text class="iconfont icon-ic_mall fs-32"></text>
							</view>
							<view class="fs-28 fw-500 lh-40rpx pl-16 flex-1">
								<text>{{orderInfo.system_store.name}}</text>
							</view>
							<navigator :url="`/pages/store/list/index?storeId=${orderInfo.system_store.id}&product_id=${orderInfo.cartInfo[0].productInfo.pid || orderInfo.cartInfo[0].productInfo.id}`" hover-class="none" class="fs-28 text--w111-666">
								{{storeSum}}家适用门店<text class="iconfont icon-ic_rightarrow ml-4 fs-24 text--w111-999"></text>
							</navigator>
						</view>
						<view class="flex pt-20 pb-20">
							<image :src="system_store.image" class="w-128 h-128 rd-16rpx"></image>
							<view class="flex-1 min-w-0 pb-4 pl-20 fs-24 text--w111-999 relative">
								<view class="mt-6 flex-1">营业时间：每日{{orderInfo.system_store.day_time}} </view>
								<view class="lh-34rpx line2 mt-14">
									地址: {{orderInfo.system_store.address}}{{orderInfo.system_store.detailed_address}}
								</view>
								<text class="inline-block copy_btn fs-22 ml-32 abs-rb"
									@tap="copy(`${orderInfo.system_store.address}${orderInfo.system_store.detailed_address}`)">复制</text>
							</view>
						</view>
					</view>
					<template v-else>
						<view class="flex-between-center">
							<view class="h-128 flex-col justify-between">
								<view class="flex-y-center">
									<view class="w-48 h-48 rd-50-p111- flex-center bg-primary-light font-num">
										<text class="iconfont icon-a-ic_user1 fs-28"></text>
									</view>
									<view class="fs-28   fw-500 lh-40rpx pl-16">
										<text>{{orderInfo.real_name}}</text>
										<text class="pl-20">{{orderInfo.user_phone}}</text>
									</view>
								</view>
								<view class="flex-y-center">
									<view class="">
										<text class="iconfont icon-ic_mall fs-32"></text>
									</view>
									<view class="fs-28   fw-500 lh-40rpx pl-16">
										<text>{{orderInfo.system_store.name}}</text>
									</view>
								</view>
							</view>
							<image :src="system_store.image" class="w-128 h-128 rd-16rpx"></image>
						</view>
						<view class="mt-24 pb-24 border_bb flex justify-between">
							<view class="w-578 fs-24 text--w111-999">
								<view class="lh-34rpx line2">
									地址: {{orderInfo.system_store.address}}{{orderInfo.system_store.detailed_address}}
								</view>
								<view class="mt-12">营业时间：每日{{orderInfo.system_store.day_time}} </view>
							</view>
							<text class="inline-block copy_btn fs-22  ml-32"
								@tap="copy(`${orderInfo.system_store.address}${orderInfo.system_store.detailed_address}`)">复制</text>
						</view>
					</template>
					<view class="flex-between-center pt-24 fs-24">
						<view class="flex-y-center" @tap="showCodeChange">
							<text class="iconfont icon-ic_QRcode"></text>
							<text class="pl-8">出示核销码</text>
						</view>
						<view class="flex-y-center" @tap="makePhone(orderInfo.system_store.phone)">
							<text class="iconfont icon-ic_phone"></text>
							<text class="pl-8">联系门店</text>
						</view>
						<view class="flex-y-center" @tap="showMaoLocation">
							<text class="iconfont icon-ic_location"></text>
							<text class="pl-8">导航至门店</text>
						</view>
					</view>
				</view>
			</view>
			<view class="w-full bg-gradient abs-lt" :style="{height: 213 + sysHeight + 'px'}">
				<view class="w-full abs-lb white_jianbian z-20"></view>
			</view>
		</view>
		<view class="px-20 mt-20">
			<view class="p-32 flex-between-center rd-24rpx bg--w111-fff"
				v-if="orderInfo.delivery_type == 'send' && orderInfo.verify_code">
				<view class="fs-28 lh-40rpx">
					<view class="">
						<text>配送员：{{orderInfo.delivery_name}}</text>
					</view>
					<view class="mt-20">
						<text class="pt-14">电&nbsp;&nbsp;&nbsp;话：{{orderInfo.delivery_id}}</text>
						<text class="iconfont icon-ic_phone ml-12 fs-28 text-w111-theme" @tap="makePhone(orderInfo.delivery_id)"></text>
					</view>
				</view>
				<view class="w-188 h-134" v-if="orderInfo._status._type == 2">
					<image :src="`${imgHost}/statics/images/delivery_map.png`" mode="" class="w-188 h-134"></image>
				</view>
				<view class="relative w-98 h-98 rd-12rpx" v-if="orderInfo.delivery_voucher_img && orderInfo.delivery_voucher_img.length" @tap="getpreviewDeliveryVoucherImg(0)">
					<image :src="orderInfo.delivery_voucher_img[0]" mode="" class="w-98 h-98 rd-12rpx"></image>
					<view class="abs-lt flex-center min-w-42 h-32 rd-lt-br-12rpx bg-w111-333333-60 fs-20 text--w111-fff">+{{orderInfo.delivery_voucher_img.length}}</view>
					<view class="abs-rb flex-center w-24 h-24 rd-4rpx bg-w111-333333-60" style="right: 8rpx;bottom: 8rpx;">
						<text class="iconfont icon-ic_enlarge fs-24 text--w111-fff"></text>
					</view>
				</view>
			</view>
		</view>
		<view class="px-20 mt-20">
			<view class="rd-24rpx relative z-80 overflow bg--w111-FAFAFA">
				<!-- 门店配送 -->
				<navigator :url="`/pages/store/home/index?id=${orderInfo.system_store.id}`" hover-class="none" class="bg--w111-FAFAFA" v-if="orderInfo.shipping_type == 3">
					<view class="flex-y-center inline-flex max-w-full h-80 px-20">
						<view class="">
							<text class="iconfont icon-ic_mall fs-32"></text>
						</view>
						<view class="fs-28 lh-40rpx pl-12 flex-1 line1">
							<text>{{orderInfo.system_store.name}}</text>
						</view>
						<text class="iconfont icon-ic_rightarrow fs-28"></text>
					</view>
				</navigator>
				<view class="flex-y-center h-80 pr-24 pl-24 bg--w111-FAFAFA" v-if="configData.cross_store_verification == 1 && ([4, 5].includes(orderInfo.product_type) || (orderInfo.product_type == 6 && orderInfo.reservation_type == 3))">
					<view class="">
						<text class="iconfont icon-ic_mall fs-32"></text>
					</view>
					<view class="fs-28 fw-500 lh-40rpx pl-16 flex-1">
						<text>{{orderInfo.system_store.name}}</text>
					</view>
					<navigator :url="`/pages/store/list/index?storeId=${orderInfo.system_store.id}&product_id=${orderInfo.cartInfo[0].productInfo.pid || orderInfo.cartInfo[0].productInfo.id}`" hover-class="none" class="fs-28 text--w111-666">
						{{storeSum}}家适用门店<text class="iconfont icon-ic_rightarrow ml-4 fs-24 text--w111-999"></text>
					</navigator>
				</view>
				<view class="bg--w111-fff pt-32 pr-24 pl-24 rd-t-16rpx">
					<view class="acea-row row-between row-middle mb-26">
						<view class="fs-28 lh-40rpx">{{orderInfo.add_time_y}} {{orderInfo.add_time_h}}</view>
						<view class="fs-24 lh-40rpx text--w111-999"
						v-if="orderInfo.shipping_type === 2"
						 @tap="goPage(1,`/pages/admin/writeRecordList/index?id=${orderInfo.id}`)">核销记录<text class="iconfont icon-ic_rightarrow fs-24 ml-4"></text></view>
					</view>
					<view class="order-goods" v-for="(item,index) in cartInfo" :key="index">
						<view class="flex" @tap="goPage(1,`/pages/goods_details/index?id=${item.product_id}`)">
							<image class="w-136 h-136 rd-16rpx" :src='item.productInfo.attrInfo.image' v-if="item.productInfo.attrInfo"></image>
							<image class="w-136 h-136 rd-16rpx" :src='item.productInfo.image' v-else></image>
							<view class="flex-1 flex justify-between pl-20">
								<view class="w-360rpx">
									<view class="w-full line1 fs-28 lh-40rpx">{{item.productInfo.store_name}}</view>
									<view class="w-full line1 fs-24 text--w111-999 lh-34rpx mt-8">{{item.productInfo.attrInfo.suk}}</view>
									<view class="flex items-end flex-wrap mt-12 w-full">
										<BaseTag
											:text="label.label_name"
											:color="label.color"
											:background="label.bg_color"
											:borderColor="label.border_color"
											:circle="label.border_color ? true : false"
											:imgSrc="label.icon"
											v-for="(label, idx) in item.productInfo.store_label" :key="idx"></BaseTag>
									</view>
								</view>
								<view class="flex-1 flex-col items-end">
									<baseMoney :money="item.productInfo.attrInfo ? item.productInfo.attrInfo.price : item.productInfo.price" symbolSize="20" integerSize="36" decimalSize="20" color="#333" weight></baseMoney>
									<view class="fs-24 text--w111-999 lh-40rpx mt-10">共{{item.cart_num}}件</view>
									<view v-if="orderInfo.type == 11 && orderInfo._status._type == 3" class="btn w-120 h-50 rd-50rpx flex-center fs-24 text--w111-fff bg-color" @tap.stop="evaluateTap(item.unique,order_id)">去评价</view>
								</view>
							</view>
						</view>
						<!-- 订单商品操作按钮 核销状态文字 退款状态文字 -->
						<view class="flex-between-center" :class="orderInfo.product_type==6 && (!orderInfo.paid || ((orderInfo.status==0 || orderInfo.status==1) && !item.is_support_refund))?'':'mt-32'" v-if="orderInfo.type != 11">
							<view class="fs-24" v-if="[5,1,2,3].includes(status.type) && (orderInfo.shipping_type==2 || (orderInfo.delivery_type=='send' && orderInfo.shipping_type!=3) || orderInfo.product_type == 4)">
								<text v-if="item.is_writeoff">已核销</text>
								<text v-if="!item.is_writeoff && item.writeoffed_num > 0">已核销{{item.writeoffed_num}}件</text>
								<text v-if="!item.is_writeoff && item.write_surplus_times == item.write_times">未核销</text>
							</view>
							<text class="fs-24" v-if="item.refund_num && status.type !=-2">{{item.refund_num}}件退款中</text>
							<view class="flex-1 flex-y-center justify-end">
								<view class="btn w-144 h-56 rd-30rpx flex-center fs-24   border bg--w111-fff"
									v-if="orderInfo.is_apply_refund && orderInfo.refund_status === 0 && item.refund_num !=item.cart_num && Number(orderInfo.paid) && item.is_support_refund && orderInfo.type != 8 && orderInfo.type != 10"
									@tap.stop="openSubcribe(item,orderInfo.product_type)">申请售后</view>
								<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 text--w111-fff bg-color"
									v-if='evaluate==3 && item.is_reply==0 && pid != -1 && item.cart_type == 0' @tap.stop="evaluateTap(item.unique,order_id)">立即评价</view>
							</view>
						</view>
					</view>
					<view class="cell flex justify-between mt-32" v-if="giftCount > 0">
						<text class="fs-28">赠品</text>
						<view class="w-460 flex-y-center justify-end" @tap="showGiftDrawer = true">
							<view class="flex">
								<view class="w-64 h-64 mr-8" v-for="(item, index) in giveCartInfo" :key="item.id">
									<image class="h-full w-full rd-8rpx" :src="item.productInfo.attrInfo.image" v-if="item.productInfo.attrInfo"></image>
									<image class="h-full w-full rd-8rpx" :src="item.productInfo.image" v-else></image>
								</view>
								<view class="w-64 h-64 rd-8rpx bg--w111-f5f5f5 flex-center mr-8" v-if="giveData.give_coupon.length">
									<text class="gold iconfont icon-a-ic_discount1"></text>
								</view>
								<view class="w-64 h-64 rd-8rpx bg--w111-f5f5f5 flex-center mr-8" v-if="giveData.give_integral>0">
									<text class="gold iconfont icon-ic_badge11"></text>
								</view>
							</view>
							<view class="fs-26 text--w111-666">共{{giftCount}}件 <text class="iconfont icon-ic_rightarrow"></text> </view>
						</view>
					</view>
					<view class="cell flex justify-between mt-32" v-if="orderInfo.mark || orderInfo.refund_explain">
						<text class="fs-28">{{isGoodsReturn ? '退款留言' : '买家备注'}}</text>
						<text class="  fs-28 w-544 text-right">{{!isGoodsReturn?orderInfo.mark:orderInfo.refund_explain}}</text>
					</view>
					<view class="cell flex justify-between mt-32" v-if="orderInfo.refund_goods_explain">
						<text class="fs-28">退货留言</text>
						<text class="fs-28 w-544 text-right">{{orderInfo.refund_goods_explain}}</text>
					</view>
					<view class="pt-32 bt mt-32" v-if="orderInfo.product_type == 1 && Array.isArray(orderInfo.virtual_info)">
						<view class="flex-between-center">
							<text class="fs-28">卡密发货</text>
							<text class="fs-28 card-pasd" @tap="copyCard()">复制全部</text>
						</view>
						<view class="mt-16 bg--w111-f5f5f5 rd-16rpx p-24 lh-36rpx" v-for="(item,index) in orderInfo.virtual_info" :key="index">
							<view class="flex-between-center">
								<text class="fs-28">卡号:</text>
								<text class="fs-36 card-pasd iconfont icon-ic_copy" @tap="copy(item.card_no)"></text>
							</view>
							<view class="fs-26 text--w111-999 mt-8">{{item.card_no}}</view>
							<view class="flex-between-center mt-24">
								<text class="fs-28">密码:</text>
								<text class="fs-36 card-pasd iconfont icon-ic_copy" @tap="copy(item.card_pwd)"></text>
							</view>
							<view class="fs-26 text--w111-999 mt-8">{{item.card_pwd}}</view>
						</view>
					</view>
					<view class="mt-32" v-else-if="orderInfo.product_type == 1">
						<view class="flex-between-center mt-32">
							<text class="fs-28">卡密发货</text>
							<text class="fs-28 card-pasd" @tap="copy(orderInfo.virtual_info)">复制文本</text>
						</view>
						<view class="mt-16 bg--w111-f5f5f5 text--w111-999 rd-16rpx p-24 lh-36rpx"
							>{{orderInfo.virtual_info}}</view>
					</view>
					<!-- #ifdef H5 || APP-PLUS -->
					<view class="border-t-EEEEEE mt-32 h-88 lh-82rpx text-center" @click="goGoodCall">
						<text class="iconfont icon-ic_customerservice mr-16 fs-32"></text><text>联系客服</text>
					</view>
					<!-- #endif -->
					<!-- #ifdef MP -->
					<view class="border-t-EEEEEE mt-32 h-88 flex flex-center" @click="goGoodCall" v-if='routineContact == 0'>
						<button hover-class='none'>
							<text class="iconfont icon-ic_customerservice mr-16 fs-32"></text><text class="fs-28">联系客服</text>
						</button>
					</view>
					<view class="border-t-EEEEEE mt-32 h-88 flex flex-center fs-28" v-else>
						<button hover-class='none' open-type='contact'>
							<text class="iconfont icon-ic_customerservice mr-16 fs-32"></text><text class="fs-28">联系客服</text>
						</button>
					</view>
					<!-- #endif -->
				</view>
			</view>
			<!-- 卡项 -->
			<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if="orderInfo.type == 11">
				<view class="fw-500 fs-28">卡项权益</view>
				<view class="" v-if="cardRelatedVisible.length">
					<view v-for="item in cardRelatedVisible" :key="item.id" class="flex-y-center p-16 pr-30 rd-12rpx mt-20 bg--w111-f5f5f5">
						<image :src="item.cart_info.productInfo.attrInfo.image" mode="" class="w-96 h-96 rd-8rpx"></image>
						<view class="pr-16 pl-16 flex-1 min-w-0">
							<view class="fs-26 line1">{{ item.cart_info.productInfo.store_name }}</view>
							<view class="flex-y-center mt-14 fs-22 text--w111-999">
								<view class="">剩余{{ item.write_surplus_times }}次/共{{ item.write_times }}次 </view>
								<view v-if="item.product_type" class="pl-12 ml-12">待服务：{{ item.waiting_service }}</view>
							</view>
						</view>
						<template v-if="![0, 3, 4].includes(orderInfo._status._type) && !orderInfo.refund.length">
							<view v-if="item.is_reply" class="flex-center w-120 h-50 border-CCCCCC rd-50rpx fs-24">已评价</view>
							<navigator v-else-if="!item.write_surplus_times" :url="`/pages/goods/goods_comment_con/index?unique=${item.unique}&uni=${order_id}`" hover-class="none" class="flex-center w-120 h-50 border-CCCCCC rd-50rpx fs-24">去评价</navigator>
							<view v-else-if="item.product_type" @click="reserveCardRelated(item.id)" class="flex-center w-120 h-50 rd-50rpx bg-color fs-24 text--w111-fff">去预约</view>
							<view v-else @click="showCodeChange" class="flex-center w-120 h-50 rd-50rpx bg-color fs-24 text--w111-fff">去核销</view>
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
			<!-- #ifdef MP -->
			<!-- 桌码 -->
			<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if="orderInfo.type == 10">
				<view class="cell flex-between-center">
					<text class="fs-28">桌台号</text>
					<text class="fs-28">{{tableCode.category.name}}{{tableCode.table_number}}</text>
				</view>
				<view class="cell flex-between-center">
					<text class="fs-28">就餐人数</text>
					<text class="fs-28">{{tableCode.number_diners}}</text>
				</view>
			</view>
			<!-- #endif -->
			<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32">
				<view class="cell flex-between-center">
					<text class="fs-28">商品总价</text>
					<text class="fs-28">￥{{(parseFloat(orderInfo.total_price)+parseFloat(orderInfo.vip_true_price)).toFixed(2)}}</text>
				</view>
				<view class="cell flex-between-center" v-if="orderInfo.pay_postage > 0">
					<text class="fs-28">运费</text>
					<text class="fs-28">￥{{parseFloat(orderInfo.pay_postage).toFixed(2)}}</text>
				</view>
				<view class="cell flex-between-center" v-if="orderInfo.first_order_price > 0">
					<text class="fs-28">首单优惠</text>
					<text class="fs-28">-￥{{parseFloat(orderInfo.first_order_price).toFixed(2)}}</text>
				</view>
				<view class="cell flex-between-center" v-if="orderInfo.vip_true_price > 0">
					<text class="fs-28">会员优惠</text>
					<text class="fs-28">-￥{{parseFloat(orderInfo.vip_true_price).toFixed(2)}}</text>
				</view>
				<view class="cell flex-between-center" v-if='orderInfo.coupon_id'>
					<text class="fs-28">优惠券抵扣</text>
					<text class="fs-28">-￥{{parseFloat(orderInfo.coupon_price).toFixed(2)}}</text>
				</view>
				<view class="cell flex-between-center" v-if="orderInfo.use_integral > 0">
					<text class="fs-28">积分抵扣</text>
					<text class="fs-28">-￥{{parseFloat(orderInfo.deduction_price).toFixed(2)}}</text>
				</view>
				<!-- 积分订单需要展示的抵扣积分数量 -->
				<view class="cell flex-between-center" v-if="orderInfo.type == 4">
					<text class="fs-28">实付积分</text>
					<text class="fs-28">-{{orderInfo.pay_integral}}</text>
				</view>
				<!-- 抵扣积分 -->
				<view class="cell flex-between-center"
					v-for="(item,index) in orderInfo.promotions_detail" :key="index"
					v-show="parseFloat(item.promotions_price)">
					<text class="fs-28">{{item.title}}</text>
					<text class="fs-28">-￥{{parseFloat(item.promotions_price).toFixed(2)}}</text>
				</view>
				<view class="cell flex-between-center">
					<text class="fs-28">实付款</text>
					<text class="fs-28">￥{{parseFloat(orderInfo.pay_price).toFixed(2)}}</text>
				</view>
			</view>
			<!-- 自定义表单内容 -->
			<view v-if="orderInfo.custom_form && orderInfo.custom_form[0].length && orderInfo.product_type != 6 && isShow">
				<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-for="(j,jindex) in orderInfo.custom_form" :key="jindex">
					<view class="cell flex justify-between" v-for="(item,index) in j" :key="index">
						<text class="fs-28">{{item.titleConfig.value}}</text>
						<view v-if="item.name == 'uploadPicture' && item.value.length < 5"  class="w-462 flex justify-end">
							<view class='pictrue mr-8' v-for="(items,indexs) in item.value" :key="indexs">
							  <image class="w-88 h-88 rd-8rpx" :src='items' mode="aspectFill"></image>
							</view>
						</view>
						<scroll-view scroll-x="true" scroll-with-animation
							class="white-nowrap vertical-middle w-462" show-scrollbar="false"
								v-else-if="item.name == 'uploadPicture' && item.value.length >= 5">
							<view class="inline-block mr-12" v-for="(items,indexs) in item.value" :key="index">
								<image class="w-88 h-88 rd-8rpx" :src="items"></image>
							</view>
						</scroll-view>
						<view v-else-if="item.name == 'dateranges'" class="  fs-28">
						   <text v-if="item.value.length">{{item.value[0]+'/'+item.value[1]}}</text>
						</view>
						<text v-else class="fs-28">{{item.value}}</text>
					</view>
				</view>
			</view>
			<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32">
				<view class="cell flex-between-center">
					<text class="fs-28">订单编号</text>
					<view>
						<text class="fs-28 pr-12">{{orderInfo.order_id}}</text>
						<text class="inline-block copy_btn fs-22  " @tap="copy(orderInfo.order_id)">复制</text>
					</view>
				</view>
				<view class="cell flex-between-center" v-if="orderInfo.refunded_price">
					<text class="fs-28">退款金额</text>
					<text class="fs-28">￥{{orderInfo.refunded_price}}</text>
				</view>
				<view class="cell flex-between-center" v-if="orderInfo._add_time">
					<text class="fs-28">下单时间</text>
					<text class="fs-28">{{orderInfo._add_time}}</text>
				</view>
				<view class="cell flex-between-center" v-if="orderInfo._pay_time">
					<text class="fs-28">支付时间</text>
					<text class="fs-28">{{orderInfo._pay_time}}</text>
				</view>
				<view class="cell flex-between-center" v-if="orderInfo._shipping_time">
					<text class="fs-28">发货时间</text>
					<text class="fs-28">{{orderInfo._shipping_time}}</text>
				</view>
				<view class="cell flex-between-center" v-if="orderInfo._delivery_time">
					<text class="fs-28">收货时间</text>
					<text class="fs-28">{{orderInfo._delivery_time}}</text>
				</view>
				<view class="cell flex-between-center">
					<text class="fs-28">支付状态</text>
					<text class="fs-28">{{ orderInfo.paid ? '已支付' : '未支付' }}</text>
				</view>
				<view class="cell flex-between-center" v-if="orderInfo._status._type">
					<text class="fs-28">支付方式</text>
					<text class="fs-28">{{orderInfo._status._payType}}</text>
				</view>
			</view>
			<view v-if="orderInfo.status !=0">
				<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if='orderInfo.delivery_type=="express"'>
					<view class="cell flex-between-center">
						<text class="fs-28">配送方式</text>
						<text class="fs-28">发货</text>
					</view>
					<view class="cell flex-between-center">
						<text class="fs-28">快递公司</text>
						<text class="fs-28">{{orderInfo.delivery_name || ''}}</text>
					</view>
					<view class="cell flex-between-center">
						<text class="fs-28">快递单号</text>
						<text class="fs-28">{{orderInfo.delivery_id || ''}}</text>
					</view>
				</view>
				<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if='orderInfo.delivery_type=="send"'>
					<view class="cell flex-between-center">
						<text class="fs-28">配送方式</text>
						<text class="fs-28">送货</text>
					</view>
					<view class="cell flex-between-center">
						<text class="fs-28">配送人姓名</text>
						<text class="fs-28">{{orderInfo.delivery_name || ''}}</text>
					</view>
					<view class="cell flex-between-center">
						<text class="fs-28">联系电话</text>
						<text class="fs-28" @tap="makePhone(orderInfo.delivery_id)">{{orderInfo.delivery_id || ''}}</text>
					</view>
					<view class="cell flex-between-center" v-if="orderInfo.delivery_time">
						<text class="fs-28">送达时间</text>
						<text class="fs-28">{{orderInfo.delivery_time}}</text>
					</view>
				</view>
				<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32 flex" v-if="orderInfo.delivery_voucher || orderInfo.delivery_voucher_img">
					<view class="">
						<text class="fs-28">送达凭证</text>
					</view>
					<view class="flex-1">
						<view class="text-right" v-if="orderInfo.delivery_voucher">
							<text class="fs-28">{{orderInfo.delivery_voucher}}</text>
						</view>
						<view class="mt-24 mr-f12 mb-f12 text-right" v-if="orderInfo.delivery_voucher_img">
							<image v-for="(item, index) in orderInfo.delivery_voucher_img" :src="item" :key="index" class="w-100 h-100 rd-9 mr-12 mb-12" @tap="getpreviewDeliveryVoucherImg(index)"></image>
						</view>
					</view>
				</view>
				<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if='orderInfo.delivery_type=="fictitious" && orderInfo.product_type!=1'>
					<view class="cell flex-between-center fs-28">
						<text class="fs-28">虚拟发货</text>
						<text class="fs-28">已发货，请注意查收</text>
					</view>
					<view class="cell flex-between-center fs-28" v-if="orderInfo.fictitious_content">
						<text class="fs-28">虚拟备注</text>
						<text class="fs-28 text-right flex-1 pl-20">{{orderInfo.fictitious_content}}</text>
					</view>
				</view>
			</view>
		</view>
		<view class="h-200 pb-safe"></view>
		<view class='fixed-lb bt w-full bg--w111-fff pb-safe z-80' v-if="!isGoodsReturn">
			<view class="h-96 px-20 flex-y-center">
				<view class="more-box" v-if="(orderInfo.type == 9 && orderInfo.shipping_type == 1 && orderInfo._status._type == 2) || (orderInfo.type == 0&&orderInfo._status._type == 2)">
					<view class="more" @click="tabMore">更多</view>
					<view :class="{on:moreShow}" class="popover">
						<view class="arrow"></view>
						<view class="item" @tap="openAfter(`/pages/goods/${cartInfo.length > 1 ? 'goods_return_list' : 'goods_return'}/index?orderId=`+orderInfo.order_id+ '&id=' + orderInfo.id + '&store_id=' + orderInfo.store_id)"
>批量售后</view>
						<view class="item" @tap="invoiceApply">申请开票</view>
					</view>
				</view>
				<view class="flex-y-center justify-end flex-1">
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24   border bg--w111-fff"
					v-if="invoice_func && !invoiceData && orderInfo.type != 8 && status.type !=-2 && (orderInfo.type != 9 || orderInfo.shipping_type == 2) && (orderInfo.type != 0 || orderInfo._status._type != 2) && !orderInfo.is_user_del" @tap="invoiceApply">申请开票</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24   border bg--w111-fff"
					v-if="invoice_func && invoiceData && invoiceData.is_invoice == -1" @tap="invoiceApply">重新开票</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24   border bg--w111-fff"
					v-if="invoice_func && invoiceData && invoiceData.is_invoice != -1" @tap="goPage(1,`/pages/users/user_invoice_order/index?order_id=${orderInfo.order_id}`)">查看发票</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 text--w111-fff bg-color"
					v-if="orderInfo.type == 11 && orderInfo.paid && cartInfo[0].is_support_refund && hasSurplusTimes && !orderInfo.refund.length" @tap="openSubcribe(cartInfo[0],orderInfo.product_type)">申请售后</view>	
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24   border bg--w111-fff"
					v-if="status.type == 0 || status.type == -9" @tap="showModalChange(3)">取消订单</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 text--w111-fff bg-color"
					v-if="status.type==0 && !orderInfo.is_user_del" @tap='pay_open()'>立即支付</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 text--w111-fff bg-color"
					v-if="orderInfo.product_type == 6 && orderInfo.reservation_status != -1 && orderInfo.paid && !orderInfo.is_del" @tap='goPage(1,`/pages/goods/reservation_list/index?orderId=${orderInfo.id}`)'>查看预约</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 text--w111-fff bg-color"
					v-if="orderInfo.paid && orderInfo.product_type == 6 && orderInfo.reservation_status == -1" @tap='goPage(1,`/pages/activity/reservation/index?orderId=${orderInfo.id}`)'>立即预约</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24   border bg--w111-fff"
					v-if="orderInfo.type==3 && orderInfo.paid && orderInfo.refund_status==0"
					@tap="goJoinPink">查看拼团</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24   border bg--w111-fff"
					v-if="orderInfo.delivery_type == 'express' && [3, 5].includes(status.class_status) && [2, 3, 4].includes(status.type) && !split.length"
					@tap="goPage(1,`/pages/goods/goods_logistics/index?orderId=${orderInfo.order_id}`)">查看物流</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 font-num con_border bg--w111-fff"
					v-if="orderInfo.type == 9"
					@tap="goPage(1,`/pages/store/group_goods/index?collage_id=${orderInfo.activity_id}`)"
					>拼单详情</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 border bg--w111-fff"
					v-if="(status.type==4 &&  !split.length) ||status.type==-2 || orderInfo._status._type == -1 || orderInfo._status._type == 3" @tap='showModalChange(2)'>删除订单</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 font-num con_border bg--w111-fff"
					v-if="(status.class_status==5 || orderInfo.is_user_del) && orderInfo.type!=4" @tap='goOrderConfirm'>{{orderInfo.is_user_del ? '重新购买' : '再次购买'}}</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 font-num con_border bg--w111-fff"
					v-else-if="orderInfo.is_apply_refund && orderInfo.refund_status == 0 && cartInfo.length>1 && orderInfo.paid && orderInfo.type != 10 && (orderInfo.type != 9 || orderInfo.shipping_type == 2) && (orderInfo.type != 0 || orderInfo._status._type != 2)"
					@tap="openAfter(`/pages/goods/${cartInfo.length > 1 ? 'goods_return_list' : 'goods_return'}/index?orderId=`+orderInfo.order_id+ '&id=' + orderInfo.id + '&store_id=' + orderInfo.store_id)"
					>批量售后</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 text--w111-fff bg-color"
					v-if="status.class_status==3 && !split.length" @tap='confirmOrder()'>确认收货</view>
				</view>
			</view>
		</view>
		<verifyModal
		v-if="showQrcode"
		:qrcode="config.qrc"
		:qrc="qrc"
		:verifyCode="orderInfo._verify_code"
		:writeDay="orderInfo.write_day"
		:writeTimes="orderInfo.write_times"
		:writeOff="orderInfo.write_off"
		:productType="orderInfo.product_type"
		:writeType='orderInfo._status._type'
		:orderType='orderInfo.type'
		@closeModal="()=>{showQrcode = false}"></verifyModal>
		<view class="mask more-mask" v-if="moreBtn" @tap="moreBtn = false"></view>
		<invoice-picker :inv-show="invShow" :is-special="special_invoice" :inv-checked="invChecked" :order-id='order_id'
			:inv-list="invList" :is-order="1" @inv-close="invClose" @inv-change="invSub" @inv-cancel="invCancel">
		</invoice-picker>
		<!-- 赠品抽屉 -->
		<giftDrawer :visible="showGiftDrawer" :giveCartInfo="giveCartInfo" :giveData="giveData" @closeDrawer="closeDrawer"></giftDrawer>
		<!-- 确认框 -->
		<tuiModal
			:show="showModal"
			:title="modalTitle"
			:content="modalContent"
			:maskClosable="false"
			:confirmText="confirmText"
			@click="handleTap"
			@cancel="hideModal"></tuiModal>
		<home :isHide="homeHide"></home>
	</view>
</template>
<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import {
		getOrderDetail,
		getRefundOrderDetail,
		orderAgain,
		orderTake,
		orderCancel,
		cardOrderBenefits,
		orderCancelDel,
	} from '@/api/order.js';
	import {
		openOrderRefundSubscribe
	} from '@/utils/SubscribeMessage.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app.js';
	import {
		getUserInfo,
		invoiceList,
		makeUpinvoice,
		makeOnceInvoice
	} from '@/api/user.js';
	import {
		activityCodeApi
	} from '@/api/activity.js';
	import {
		getCodeData
	} from '@/api/store.js';
	import {
		getList
	} from '@/api/new_store.js';
	import ClipboardJS from "@/plugin/clipboard/clipboard.js";
	import {toLogin} from '@/libs/login.js';
	import {mapGetters} from "vuex";
	import colors from "@/mixins/color";
	import invoicePicker from '../components/invoicePicker';
	import verifyModal from '../components/verifyModal/index.vue'
	import giftDrawer from '../components/giftDrawer/index.vue'
	import orderStatusLine from '../components/orderStatusLine/index'
	import tuiModal from "@/components/tui-modal/index.vue"
	import home from '@/components/home/index.vue';
	export default {
		components: {
			invoicePicker,
			verifyModal,
			giftDrawer,
			tuiModal,
			home,
			orderStatusLine
		},
		mixins: [colors],
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				sysHeight:sysHeight,
				giveData: {
					give_integral: 0,
					give_coupon: []
				},
				giveCartInfo: [],
				config: {
					qrc: {
						code: "",
						size: 360, // 二维码大小
						level: 4, //等级 0～4
						bgColor: '#FFFFFF', //二维码背景色 默认白色
						color: ['#333', '#333'], //边框颜色支持渐变色
					}
				},
				order_id: '',
				evaluate: 0,
				cartInfo: [], //购物车产品
				pid: 0, //上级订单ID
				split: [], //分单商品
				orderInfo: {
					system_store: {},
					_status: {}
				}, //订单详情
				system_store: {},
				isGoodsReturn: false, //是否为退款订单
				status: {}, //订单底部按钮状态
				refund_close: false,
				isClose: false,
				pay_close: false,
				pay_order_id: '',
				totalPrice: '0',
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				routineContact: 0,
				express_num: '',
				invoice_func: false,
				invoiceData: {},
				invoice_id: 0,
				invChecked: '',
				moreBtn: false,
				invShow: false,
				special_invoice: false,
				invList: [],
				userInfo: {},
				isReturen: '',
				showQrcode:false,
				showGiftDrawer:false,
				showModal:false,
				modalTitle:'',
				modalContent:'',
				confirmText: '',
				modalType:0,
				orderId:'',
				qrc:'',
				// #ifdef MP
				tableCode:{},
				// #endif
				moreShow:false,
				homeHide: false,
				isShow:0, //自定义是否显示
				cardRelated: [],
				isExpanded: false,
				hasSurplusTimes: false,
				configData: this.$Cache.get('BASIC_CONFIG'),
				storeSum: 0,
			};
		},
		computed: {
			cardRelatedVisible() {
				if (this.isExpanded) {
					return this.cardRelated;
				} else {
					return this.cardRelated.slice(0, 3);
				}
			},
			reservationStore(){
				let obj = (this.orderInfo.product_type == 6 && this.orderInfo.reservation_type == 2) || (this.orderInfo.shipping_type === 2 && [0,4].includes(this.orderInfo.product_type)) || (this.orderInfo.product_type == 5 && [2,4].includes(this.orderInfo.shipping_type));
				return obj
			},
			reservationDoor(){
				let obj = (this.orderInfo.product_type == 6 && this.orderInfo.reservation_type == 3) || ([1,3].includes(this.orderInfo.shipping_type) && this.orderInfo.product_type==0);
				console.log(obj)
				return obj
			},
			giftCount(){
				let count = 0;
				if(this.giveCartInfo.length){
					count = this.giveCartInfo.length
				}
				if(this.giveData.give_coupon.length){
					count = count + this.giveData.give_coupon.length
				}
				if(this.giveData.give_integral > 0){
					count = count + 1
				}
				return count
			},
			...mapGetters(['isLogin']),
		},
		onLoad: function(options) {
			if (options.order_id) {
				this.$set(this, 'order_id', options.order_id);
				this.isReturen = options.isReturen;
			}
			if (options.invoice_id) {
				this.invoice_id = options.invoice_id
			}
			this.activityCodeApi();
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			if (this.isLogin) {
				this.getOrderInfo();
				this.getUserInfo();
			} else {
				toLogin()
			}
		},
    onPageScroll() {
			this.homeHide = true;
			this.moreShow = false;
		},
		methods: {
			onLoadFun() {
				this.getOrderInfo();
				this.getUserInfo();
				this.isShowAuth = false
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			getpreviewImage: function(index, num) {
				uni.previewImage({
					urls: num ? this.orderInfo.refund_img : this.orderInfo.refund_goods_img,
					current: num ? this.orderInfo.refund_img[index] : this.orderInfo.refund_goods_img[index]
				});
			},
			showModalChange(type){
				this.modalType = type;
				if(type == 1){
					this.modalTitle = '确认收货';
					this.modalContent = '为保障权益，请收到货确认无误后，再确认收货';
					this.confirmText = '确定';
				}else if(type == 2){
					this.modalTitle = '删除订单';
					this.modalContent = '确定删除该订单?';
					this.confirmText = '确定';
				}else if(type == 3){
					this.modalTitle = '温馨提示';
					this.modalContent = '确认取消该订单?';
					this.confirmText = '确定';
				}else if (type == 4) {
					this.modalTitle = '去处理';
					this.modalContent = '该订单有售后处理中，确认收货需先撤销售后申请';
					this.confirmText = '去撤销';
				}
				this.showModal = true;
			},
			handleTap(e){
				let index = e.index;
				if(index == 1){
					if(this.modalType == 1){
						orderTake(this.order_id).then(res => {
							this.showModal = false;
							this.getOrderInfo();
							return this.$util.Tips({
								title: '操作成功',
								icon: 'success'
							});
						}).catch(err => {
							return this.$util.Tips({
								title: err
							});
						})
					}else if(this.modalType == 2){
						orderCancel(this.order_id).then(res=>{
							this.showModal = false;
							return this.$util.Tips({
								title: '删除成功',
								icon: 'success'
							}, {
								tab: 3,
								url: '/pages/goods/order_list/index'
							});
						}).catch(err => {
							this.$util.Tips({
								title: err
							});
						})
					}else if(this.modalType == 3){
						this.showModal = false;
						orderCancelDel(this.order_id).then(()=>{
							// uni.reLaunch({
							// 	url:'/pages/goods/order_list/index'
							// })
							uni.navigateBack()
						})
					}else if (this.modalType == 4) {
						this.showModal = false;
						uni.navigateTo({
							url: `/pages/goods/order_after_details/index?isRefund=1&order_id=${this.orderInfo.refund[0].order_id}`
						})
					}
				}else{
					this.showModal = false;
				}

			},
			hideModal(){
				this.showModal = false;
				this.confirmText = '';
			},
			goGoodCall() {
				let url = `/pages/extension/customer_list/chat?orderId=${this.order_id}&isReturen=${this.isReturen}`
				let obj = {
					store_name: this.orderInfo.order_id,
					path: `/pages/goods/order_details/index?order_id=${this.orderInfo.order_id}`,
					image: ''
				}
				this.$util.getCustomer(this.userInfo,url,obj,1)
			},
			openAfter: function(e) {
				let page = e;
				// #ifdef MP
				uni.showLoading({
					title: '正在加载',
				})
				openOrderRefundSubscribe().then(res => {
					uni.hideLoading();
					uni.navigateTo({
						url: page,
					});
				}).catch(() => {
					uni.hideLoading();
				});
				// #endif
				// #ifndef MP
				uni.navigateTo({
					url: page
				})
				// #endif
			},
			/**
			 * 拨打电话
			 */
			makePhone: function(phone) {
				let that = this;
				// #ifdef APP-PLUS
				plus.device.dial(phone, true);
				// #endif
				// #ifdef MP || H5
				uni.makePhoneCall({
				    phoneNumber: phone
				});
				// #endif
			},
			showCodeChange(){
				if(!this.orderInfo.paid) return this.$util.Tips({
					title:'请支付后查看核销码'
				})
				this.showQrcode = true;
			},
			/**
			 * 打开地图
			 *
			 */
			showMaoLocation: function() {
				if (!this.system_store.latitude || !this.system_store.longitude) return this.$util.Tips({
					title: '缺少经纬度信息无法查看地图！'
				});
				uni.openLocation({
					latitude: parseFloat(this.system_store.latitude),
					longitude: parseFloat(this.system_store.longitude),
					scale: 8,
					name: this.system_store.name,
					address: this.system_store.address + this.system_store.detailed_address,
					success: function() {

					},
				});
			},
			/**
			 * 获取用户信息
			 *
			 */
			getUserInfo: function() {
				let that = this;
				getUserInfo().then(res => {
					that.userInfo = res.data;
				})
			},
			/**
			 * 获取订单详细信息
			 *
			 */
			getOrderInfo: function() {
				let that = this;
				uni.showLoading({
					title: "正在加载中"
				});
				let obj = '';
				if (that.isReturen) {
					obj = getRefundOrderDetail(this.order_id);
				} else {
					obj = getOrderDetail(this.order_id);
				}
				obj.then(res => {
					let _type = res.data._status._type;
					uni.hideLoading();
					that.giveData.give_coupon = res.data.give_coupon || [];
					that.giveData.give_integral = res.data.give_integral;
					that.$set(that, 'orderInfo', res.data);
					that.$set(that, 'pid', res.data.pid);
					that.$set(that, 'split', res.data.split?res.data.split:[]);
					that.$set(that, 'evaluate', _type == 3 ? 3 : 0);
					that.$set(that, 'system_store', res.data.system_store);
					that.$set(that, 'invoiceData', res.data.invoice);
					if (that.invoiceData) {
						that.invoiceData.pay_price = res.data.pay_price;
					}
					that.$set(that, 'invoice_func', res.data.invoice_func);
					that.$set(that, 'special_invoice', res.data.special_invoice);
					that.$set(that, 'routineContact', Number(res.data.routine_contact_type));
					let cartInfo = res.data.cartInfo;
					let cartObj = [],
						giftObj = [];
					cartInfo.forEach(item => {
						if (item.is_gift == 1) {
							giftObj.push(item)
						} else {
							cartObj.push(item)
						}
					})
					that.$set(that, 'cartInfo', cartObj);
					that.$set(that, 'giveCartInfo', giftObj);
					this.$nextTick(function() {
						that.config.qrc.code = that.orderInfo.verify_code
					})
					if (this.orderInfo.refund_status != 0) {
						this.isGoodsReturn = true;
					} else {
						this.isReturen = 0
					}
					if (that.invoice_id && !that.invoiceData) {
						that.invChecked = that.invoice_id || '';
						this.invoiceApply()
					}
					// #ifdef MP
					if (that.orderInfo.type == 10) {
						that.getCodeData();
					}
					// #endif
					that.getOrderStatus();
					if(this.orderInfo.custom_form && this.orderInfo.custom_form.length){
						this.orderInfo.custom_form.forEach(item=>{
							if(item.length){
								item.forEach(j=>{
									if(j.value){
										return this.isShow = 1
									}
								})
							}
						})
					}
					// 卡项权益
					if (that.orderInfo.type == 11) {
						that.cardOrderBenefits();
					}
					// 获取适用门店数量
					if (that.configData.cross_store_verification && [4, 5, 6].includes(that.orderInfo.product_type)) {
						this.getStoreList();
					}
				}).catch(err => {
					uni.hideLoading();
					if (err.status == 403) {
						uni.reLaunch({
							url: '/pages/goods/order_list/index'
						})
					} else {
						that.$util.Tips({
							title: err
						}, '/pages/goods/order_list/index');
					}
				});
			},
			// 不开发票
			invCancel() {
				this.invChecked = '';
				this.invTitle = '不开发票';
				this.invShow = false;
			},
			// 选择发票
			invSub(id) {
				this.invChecked = id;
				let data = {
					invoice_id: this.invChecked
				}
				let apiFun = makeUpinvoice;
				// 重新开票
				if (this.invoiceData && this.invoiceData.is_invoice == -1) {
					apiFun = makeOnceInvoice;
					data.id = this.invoiceData.id;
				} else{
					data.order_id = this.order_id;
				}
				apiFun(data).then(res => {
					uni.showToast({
						title: '申请成功',
						icon: 'success'
					});
					this.invShow = false;
					this.getOrderInfo()
				}).catch(err => {
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
			},
			// 关闭发票
			invClose() {
				this.invShow = false;
				this.getInvoiceList()
			},
			//申请开票
			invoiceApply() {
				this.getInvoiceList()
				this.moreBtn = false;
				this.invShow = true;
			},
			getInvoiceList() {
				uni.showLoading({
					title: '加载中'
				})
				invoiceList().then(res => {
					uni.hideLoading();
					this.invList = res.data.map(item => {
						item.id = String(item.id);
						return item;
					});
					const result = this.invList.find(item => item.id == this.invChecked);
					if (result) {
						let name = '';
						name += result.header_type === 1 ? '个人' : '企业';
						name += result.type === 1 ? '普通' : '专用';
						name += '发票';
						this.invTitle = name;
					}
				}).catch(err => {
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
			},
			more() {
				this.moreBtn = !this.moreBtn
			},
			copy: function(code) {
				let that = this;
				uni.setClipboardData({
					data: code
				});
			},
			copyCard(){
				let that = this;
				let strArr = [];
				this.orderInfo.virtual_info.forEach((item,index)=>{
					strArr.push(`卡号${index+1}: ${item.card_no}  密码${index+1}:${item.card_pwd}`)
				})
				uni.setClipboardData({
					data: strArr.toString()
				});
			},
			/**
			 * 设置底部按钮
			 *
			 */
			getOrderStatus: function() {
				let orderInfo = this.orderInfo || {},
					_status = orderInfo._status || {
						_type: 0
					},
					status = {};
				let type = parseInt(_status._type),
					delivery_type = orderInfo.delivery_type,
					seckill_id = orderInfo.seckill_id ? parseInt(orderInfo.seckill_id) : 0,
					bargain_id = orderInfo.bargain_id ? parseInt(orderInfo.bargain_id) : 0,
					discount_id = orderInfo.discount_id ? parseInt(orderInfo.discount_id) : 0,
					combination_id = orderInfo.combination_id ? parseInt(orderInfo.combination_id) : 0;
				status = {
					type: type == 9 ? -9 : type,
					class_status: 0,
					class_again: 0
				};
				if (type == 1 && combination_id > 0) status.class_status = 1; //查看拼团
				if (type == 2 && delivery_type == 'express') status.class_status = 2; //查看物流
				if (type == 2) status.class_status = 3; //确认收货
				if (type == 4 || type == 0) status.class_status = 4; //删除订单
				if (!seckill_id && !bargain_id && !combination_id && !discount_id && !orderInfo.type && (type == 3 ||
						type == 4)) status.class_status = 5; //再次购买（待评价、已完成）
				if (!seckill_id && !bargain_id && !combination_id && !discount_id && !orderInfo.type && (type == 1 || type == 2 ||
						type == 5)) status.class_again = 6; //再次购买 （待发货、待收货、部分核销）
				this.$set(this, 'status', status);
			},
			/**
			 * 去拼团详情
			 *
			 */
			goJoinPink: function() {
				uni.navigateTo({
					url: '/pages/activity/goods_combination_status/index?id=' + this.orderInfo.pink_id,
				});
			},
			/**
			 * 再此购买
			 *
			 */
			goOrderConfirm: function() {
				if(this.orderInfo.is_user_del && this.orderInfo.product_type==6){
					return uni.navigateTo({
						url: '/pages/goods_details/index?id=' + this.orderInfo.cartInfo[0].product_id
					})
				}else{
					let that = this;
					orderAgain(that.orderInfo.order_id).then(res => {
						let deliveryType = 1;
						let orderInfo = that.orderInfo
						if(orderInfo.shipping_type==1 && orderInfo.store_id>0){
							deliveryType = 3
						}else{
							deliveryType = orderInfo.shipping_type
						}
						return uni.navigateTo({
							url: '/pages/goods/order_confirm/index?new=1&cartId=' + res.data.cateId + '&store_id=' + that.orderInfo.store_id + '&delivery_type=' + deliveryType
						});
					}).catch(err => {
						return that.$util.Tips({
							title: err
						});
					});
				}
			},
			confirmOrder(orderId) {
				if (this.orderInfo.refund.length) {
					this.showModalChange(4);
					return;
				}
				let that = this;
				// #ifdef MP
				if (wx.openBusinessView && this.orderInfo.order_shipping_open && this.orderInfo.trade_no) {
					uni.showLoading({
						title: '加载中'
					});
					wx.openBusinessView({
						businessType: 'weappOrderConfirm',
						extraData: {
							transaction_id: this.orderInfo.trade_no
						},
						success() {},
						fail(err) {
							uni.hideLoading();
							return that.$util.Tips({
								title: err.errMsg
							});
						},
						complete() {
							uni.hideLoading();
						}
					});
				} else {
					that.showModalChange(1);
				}
				// #endif
				// #ifndef MP
				this.showModalChange(1);
				// #endif
			},
			pay_open() {
				uni.reLaunch({
					url: `/pages/goods/cashier/index?order_id=${this.order_id}&from_type=order`
				})
			},
			goPage(type, url){
				if(type == 1){
					uni.navigateTo({
						url
					})
				}else if(type == 2){
					uni.switchTab({
						url
					})
				}else if(type == 3){
					let pages = getCurrentPages();
					if(pages.length>1){
						uni.navigateBack();
					}else{
						uni.switchTab({
							url:'/pages/index/index'
						})
					}
					
				}

			},
			openSubcribe: function(item,productType) {
				let cartIds = [
					{
						cart_id:item.id,
						cart_num:parseInt(item.cart_num) - parseInt(item.refund_num)
					}
				]
				cartIds = JSON.stringify(cartIds);
				let page = `/pages/goods/goods_return/index?orderId=`+this.order_id+ '&id=' + this.orderInfo.id+ '&cartIds='+ cartIds+'&productType='+productType+'&store_id='+this.orderInfo.store_id;
				// #ifdef MP
				uni.showLoading({
					title: '正在加载',
				})
				openOrderRefundSubscribe().then(res => {
					uni.hideLoading();
					uni.navigateTo({
						url: page,
					});
				}).catch(() => {
					uni.hideLoading();
				});
				// #endif
				// #ifndef MP
				uni.navigateTo({
					url: page
				})
				// #endif
			},
			evaluateTap: function(unique, orderId) {
				uni.navigateTo({
					url: "/pages/goods/goods_comment_con/index?unique=" + unique + "&uni=" + orderId
				})
			},
			closeDrawer(){
				this.showGiftDrawer = false;
			},
			activityCodeApi(){
				activityCodeApi(91, 0, {
					order_id: this.order_id
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
			// #ifdef MP
			getCodeData() {
				getCodeData({
					tableId: this.orderInfo.activity_id
				}).then(res => {
					this.tableCode = res.data;
				});
			},
			// #endif
			tabMore() {
				this.moreShow = !this.moreShow;
			},
			// 卡项权益-展开
			toggleExpand() {
				this.isExpanded = !this.isExpanded;
			},
			// 卡项权益
			cardOrderBenefits() {
				cardOrderBenefits(this.orderInfo.id).then((res) => {
					this.hasSurplusTimes = res.data.every((item) => {
						return item.write_times == item.write_surplus_times;
					});
					this.cardRelated = res.data;
				});
			},
			// 卡项权益-去预约
			reserveCardRelated(id) {
				if (!this.orderInfo.paid) {
					return this.$util.Tips({
						title:'请支付后去预约'
					});
				}
				uni.navigateTo({
					url: `/pages/activity/reservation/index?orderId=${this.orderInfo.id}&cartInfoId=${id}`
				});
			},
			getStoreList() {
				getList({
					latitude: uni.getStorageSync('user_latitude'),
					longitude: uni.getStorageSync('user_longitude'),
					store_type: 1,
					keyword: "",
					province: 0,
					city: 0,
					area: 0,
					is_select: 0,
					is_sum: 1,
					product_id: this.orderInfo.cartInfo[0].productInfo.pid || this.orderInfo.cartInfo[0].productInfo.id,
				}).then(res => {
					this.storeSum = res.msg
				})
			},
			getpreviewDeliveryVoucherImg(index) {
				uni.previewImage({
					urls: this.orderInfo.delivery_voucher_img,
					current: this.orderInfo.delivery_voucher_img[index]
				});
			},
		}
	}
</script>
<style lang="scss" scoped>
	.w-188 {
		width: 188rpx;
	}
	.h-134 {
		height: 134rpx;
	}
	.min-w-42 {
		min-width: 42rpx;
	}
	.rd-lt-br-12rpx {
		border-radius: 12rpx 0 12rpx 0;
	}
	.white_jianbian{
		height:120rpx;
		background: linear-gradient(0deg, #F5F5F5 0%, rgba(245,245,245,0) 100%);
	}
	.bt_line{
		height:1px;
		width:680rpx;
		left:50%;
		transform: translateX(-50%);
	}
	.h-68{
		height:68rpx;
	}
	.order-goods ~ .order-goods{
		margin-top: 32rpx;
	}
	.con_border{
		border: 1px solid var(--view-theme);
		line-height:22rpx;
	}
	.cell input{
		width: 450rpx;
		text-align:right;
	}
	.cell .radio {
		margin: 0 22rpx;
		padding: 10rpx 0;
	}
	.cell ~ .cell{
		margin-top: 40rpx;
	}
	.SemiBold {
	  font-family: SemiBold;
	}
	.copy_btn{
		width: 68rpx;
		height: 36rpx;
		background: #F5F5F5;
		border-radius: 20rpx;
		text-align:center;
		line-height:36rpx;
	}
	.btn ~ .btn{
		margin-left: 16rpx;
	}
	.border{
		border: 1rpx solid #ccc;
	}
	.border_bb{
		border-bottom: 1px solid #eee;
	}
	.h-128{
		height:128rpx;
	}
	.w-578{
		width:578rpx;
	}
	.bg-primary-light{
		background: var(--view-minorColorT);
	}
	.mt-228{
		margin-top:228rpx;
	}
	.virtual_info{
		margin-top: -160rpx;
	}
	.bt{
		border-top:1px solid #eee;
	}
	.z-1{
		z-index:1;
	}
	.gold{
		color: #DCA658;
	}
	.card-pasd{
		color: #FF7D00;
	}
	.more-box{
		position: relative;
		.more{
			font-size: 24rpx;
			line-height: 34rpx;
			color: #333333;
			.iconfont{
				font-size: 24rpx;
			}
		}
		.popover{
			position: absolute;
			inset: -18rpx auto auto 20rpx;
			width: 160rpx;
			padding: 14rpx 0;
			border-radius: 16rpx;
			background: #FFFFFF;
			box-shadow: 0rpx 0rpx 16rpx 0rpx rgba(0,0,0,0.08);
			transform: translateY(-100%);
			visibility: hidden;
			opacity: 0;
			z-index: 1;
			transition: all .3s ease-in-out;
			&.on{
				visibility: visible;
				opacity: 1;
				z-index: 970;
			}
			.arrow{
				position: absolute;
				inset: auto auto -13rpx 8rpx;
				border-width: 7rpx 13rpx;
				border-style: solid;
				border-color: #FFFFFF transparent transparent;
			}
			.item{
				padding: 14rpx 24rpx;
				font-size: 28rpx;
				color: #333333;
			}
		}
	}
</style>
