<template>
	<view>
		<!-- #ifdef MP || APP-PLUS -->
		<NavBar titleText="订单详情" :iconColor="iconColor" :textColor="iconColor" :isScrolling="isScrolling" showBack></NavBar>
		<!-- #endif -->
		<view class="headerBg">
			<view :style="{ height: `${getHeight.barTop}px` }"></view>
			<view :style="{ height: `${getHeight.barHeight}px` }"></view>
			<view class="inner"></view>
		</view>
		<view class="order-details pos-order-details">
			<view class="header">
				<view class="state">{{ title }}</view>
				<view v-if="orderInfo.status == 0 && orderInfo.paid == 0 && orderInfo.pay_type != 'offline'" class="data acea-row row-middle">
					需付款：¥{{ orderInfo.pay_price }}
					<countDown :isDay="false" tipText="支付剩余：" dayText=" " hourText="时" minuteText="分" secondText=" " dotColor="#FFFFFF" colors="#FFFFFF" :datatime="orderInfo.stop_time"
						:isSecond="false">
					</countDown>
				</view>
				<view v-if="orderInfo._status._type == -1" class="data">已取消，订单交易取消～</view>
				<view v-if="orderInfo._status._type == -2" class="data">已退款，订单已退款～</view>
				<view v-if="orderInfo._status._type == 1" class="data">用户已付款，需要您尽快送货哦～</view>
				<view v-if="orderInfo._status._type == 2" class="data">商品配送中，等待用户收货</view>
				<view v-if="orderInfo._status._type == 3" class="data">已收货,快去评价一下吧</view>
				<view v-if="orderInfo._status._type == 4" class="data">已评价，交易完成</view>
				<view v-if="orderInfo._status._type == 5" class="data">需用户出示二维码或数字即可核销</view>
			</view>
			<view @click="modify('1')" class="remarks acea-row row-middle h-100" v-if="goname != 'looks'">
				<text class="iconfont icon-ic_notes"></text>
				<text class="line1 ml-22" v-if="orderInfo.remark">{{orderInfo.remark}}</text>
				<text class="line1 ml-22 text--w111-ccc" v-else>订单未备注，点击添加备注信息</text>
			</view>
			<view class="address" v-if="(orderInfo.product_type==0 || orderInfo.product_type==6) && orderInfo.uid&&orderInfo.type!=10">
				<view class="flex-between-center">
					<view class="name">
						<text class="iconfont icon-ic_location4"></text>
						{{ orderInfo.real_name}}
						<text class="phone">{{ orderInfo.user_phone }}</text>
					</view>
					<text class="copy" @click="copyAddress">复制</text>
				</view>
				<view class="mt-12 acea-row" v-if="[1, 3].includes(orderInfo.shipping_type) || orderInfo.reservation_type==3">
					<text class="flex-1">地址：{{ orderInfo.user_address }}</text>
				</view>
				<view class="line">
					<image src="/static/images/line.jpg" />
				</view>
			</view>
			<view class="acea-row row-middle user-box">
				<image v-if="orderInfo.uid" :src="userInfo.avatar" class="image"></image>
				<image v-else src="/static/images/f.png" class="image"></image>
				<view class="text">
					<view class="acea-row row-middle name">
						{{orderInfo.uid?userInfo.nickname:'游客'}}
						<view v-if="userInfo.isMember" class="svip">SVIP</view>
						<view v-if="userInfo.level_grade" class="grade acea-row row-middle"><text class="iconfont icon-huiyuandengji"></text>V{{userInfo.level_grade}}</view>
					</view>
					<view v-if="userInfo.phone" class="">{{userInfo.phone}}（ID:{{userInfo.uid}}）</view>
					<view v-else class="">ID:{{userInfo.uid || 0}}</view>
				</view>
			</view>
			<!-- 拆单时 -->
			<view v-for="(j, indexw) in orderInfo.split" :key="indexw" v-if="orderInfo.split && orderInfo.split.length">
				<view class="splitTitle acea-row row-between-wrapper">
					<view>订单包裹{{indexw + 1}}</view>
					<view class="title">{{j._status._title}}</view>
				</view>
				<view class="pos-order-goods">
					<navigator :url="`/pages/admin/orderDetail/index?id=${j.order_id}`" hover-class="none" class="goods acea-row row-between-wrapper" v-for="(item, index) in j.cartInfo" :key="index">
						<view class="picTxt acea-row row-between-wrapper">
							<view class="pictrue">
								<image :src="item.productInfo.attrInfo?item.productInfo.attrInfo.image:item.productInfo.image" />
							</view>
							<view class="text acea-row row-between row-column">
								<view class="info line2">
									{{ item.productInfo.store_name }}
								</view>
								<view class="attr">{{ item.productInfo.attrInfo.suk }}</view>
							</view>
						</view>
						<view class="money">
							<view class="x-money">￥{{ item.productInfo.attrInfo?item.productInfo.attrInfo.price:item.productInfo.price }}</view>
							<view class="num">x {{ item.cart_num }}</view>
						</view>
					</navigator>
				</view>
			</view>
			<!-- 结束 -->
			<!-- 未拆单时，正常单 -->
			<view class="pos-order-goods split" v-if="orderInfo.cartInfo && orderInfo.cartInfo.length">
				<view class="title acea-row row-between-wrapper"
					v-if="(orderInfo.status == 0 && orderInfo.paid == 1 && orderInfo.shipping_type == 2) || orderInfo.status == 5 || (orderInfo.status == 2 && orderInfo.shipping_type == 2)">
					<text>共{{totalNmu}}件商品</text>
					<navigator class="btn" :url="'/pages/admin/writeRecordList/index?id='+orderInfo.id" hover-class="none">
						核销记录<text class="iconfont icon-ic_rightarrow"></text>
					</navigator>
				</view>
				<navigator :url="`/pages/goods_details/index?id=${item.product_id}`" hover-class="none" class="goods acea-row" v-for="(item, index) in orderInfo.cartInfo" :key="index">
					<view class="picTxt acea-row">
						<view class="pictrue">
							<image :src="item.productInfo.attrInfo?item.productInfo.attrInfo.image:item.productInfo.image" />
						</view>
						<view class="text">
							<view class="info line1">{{ item.productInfo.store_name }}</view>
							<view class="attr line1">{{ item.productInfo.attrInfo.suk }}</view>
						</view>
					</view>
					<view class="money">
						<BaseMoney :money="item.productInfo.attrInfo?item.productInfo.attrInfo.price:item.productInfo.price" symbolSize="20" integerSize="32" decimalSize="20"></BaseMoney>
						<view class="num">共{{ item.cart_num }}{{item.productInfo.unit_name}}</view>
						<view class="acea-row row-right">
							<view class="writeOff" v-if="item.refund_num  && orderInfo.refund_type != 6">{{item.refund_num}}件退款中</view>
							<view class="writeOff" v-if="orderInfo._status._type==2 && orderInfo.delivery_type == 'send'">
								<text v-if="item.refund_num">，</text>
								<text class="on" v-if="item.is_writeoff">已核销</text>
								<text v-if="!item.is_writeoff && item.surplus_num<item.cart_num">已核销{{parseInt(item.cart_num)-parseInt(item.surplus_num)}}件</text>
								<text v-if="!item.is_writeoff && item.surplus_num==item.cart_num">未核销</text>
							</view>
						</view>
					</view>
				</navigator>
				<view class="giveGoods">
					<view class="item acea-row row-between-wrapper" v-for="(item,index) in giveCartInfo" :key="index">
						<view class="picTxt acea-row row-middle">
							<view class="pictrue">
								<image :src="item.productInfo.attrInfo.image" v-if="item.productInfo.attrInfo"></image>
								<image :src="item.productInfo.image" v-else></image>
							</view>
							<view class="texts">
								<view class="name line1">[赠品]{{item.productInfo.store_name}}</view>
								<view class="limit line1" v-if="item.productInfo.attrInfo">{{item.productInfo.attrInfo.suk}}</view>
							</view>
						</view>
						<view class="num">x{{item.cart_num}}</view>
					</view>
					<view class="item acea-row row-between-wrapper" v-for="(item,index) in giveData.give_coupon" :key="index" v-if="giveData.give_coupon.length">
						<view class="picTxt acea-row row-middle">
							<view class="pictrue acea-row row-center-wrapper">
								<text class="iconfont icon-a-ic_discount1"></text>
							</view>
							<view class="texts">
								<view class="line1">[赠品]{{item.coupon_title}}</view>
							</view>
						</view>
					</view>
					<view class="item acea-row row-between-wrapper" v-if="giveData.give_integral>0">
						<view class="picTxt acea-row row-middle">
							<view class="pictrue acea-row row-center-wrapper">
								<text class="iconfont icon-ic_badge11"></text>
							</view>
							<view class="texts">
								<view class="line1">[赠品]{{giveData.give_integral}}积分</view>
							</view>
						</view>
					</view>
				</view>
				<view v-if="orderInfo.product_type == 6">
					<view class="mark acea-row row-between-wrapper" v-if="orderInfo.reservation_time">
						<view class="name">预约日期</view>
						<view class="value">{{ orderInfo.reservation_time }}</view>
					</view>
					<view class="mark acea-row row-between-wrapper" v-if="orderInfo.reservation_show_time">
						<view class="name">预约时段</view>
						<view class="value">{{ orderInfo.reservation_show_time }}</view>
					</view>
					<view class="mark acea-row row-between-wrapper">
						<view class="name">预约人数</view>
						<view class="value">{{ orderInfo.total_num }}</view>
					</view>
				</view>
				<view class="mark acea-row row-between-wrapper" v-if="orderInfo.mark">
					<view class="name">留言</view>
					<view class="value">{{orderInfo.mark}}</view>
				</view>
			</view>
			<view class="mt-20 bg--w111-fff rd-24rpx pt-32 pr-24 pl-24 pb-32" v-if="orderInfo.type == 11">
				<view class="fw-500 fs-28">卡项权益</view>
				<view class="">
					<view v-for="item in cardRelatedVisible" :key="item.id" class="flex-y-center p-16 pr-30 rd-12rpx mt-20 bg--w111-f5f5f5">
						<image :src="item.cart_info.productInfo.attrInfo.image" mode="" class="w-96 h-96 rd-8rpx"></image>
						<view class="pr-16 pl-16 flex-1 min-w-0">
							<view class="fs-26 line1">{{ item.cart_info.productInfo.store_name }}</view>
							<view class="mt-14 fs-22 text--w111-999">{{ item.cart_info.productInfo.attrInfo.suk }}</view>
						</view>
						<view class="fs-24">{{ item.write_times }}次</view>
					</view>
				</view>
				<view class="flex-center mt-20" v-if="cardRelated.length > 3">
					<view class="fs-22 text--w111-999" @click="toggleExpand">
						{{ isExpanded ? '收起' : '展开' }}
						<text :class="['iconfont ml-4 fs-24', isExpanded ? 'icon-ic_uparrow' : 'icon-ic_downarrow']"></text>
					</view>
				</view>
			</view>
			<!-- 结束 -->
			<view class="wrapper" v-if="orderInfo.type == 10">
				<view class="item acea-row row-between">
					<view>桌台号</view>
					<view class='conter'>{{orderInfo.table_info.category.name+' ('+orderInfo.table_info.table_number+'号)'}}</view>
				</view>
				<view class="item acea-row row-between">
					<view>就餐人数</view>
					<view class='conter'>{{orderInfo.table_info.number_diners}}</view>
				</view>
			</view>
			<view class='wrapper' v-if='orderInfo.delivery_type=="fictitious" && orderInfo.product_type!=1'>
				<view class='item acea-row row-between' v-if="orderInfo.fictitious_content">
					<view>虚拟备注</view>
					<view class='conter'>{{orderInfo.fictitious_content}}</view>
				</view>
			</view>
			<view class='wrapper' v-if="orderInfo.virtual_info && orderInfo.product_type==1">
				<view class="title">卡密发货</view>
				<view v-for="(item,index) in orderInfo.virtual_info" :key="index" v-if="Array.isArray(orderInfo.virtual_info)">
					<view class='item acea-row row-between'>
						<view>卡号</view>
						<view class='conter'>{{item.card_no}}</view>
					</view>
					<view class='item acea-row row-between'>
						<view>密码</view>
						<view class='conter'>{{item.card_pwd}}</view>
					</view>
				</view>
				<view v-else class="mt-16 bg--w111-f5f5f5 text--w111-999 rd-16rpx p-24 lh-36rpx">{{orderInfo.virtual_info}}</view>
			</view>
			<customForm :customForm="orderInfo.custom_form" :customFormTitle='orderInfo.custom_form_title' :productType="orderInfo.product_type" :reservationTimeId="orderInfo.reservation_time_id"></customForm>
			<view class="wrapper">
				<view class="item acea-row row-between">
					<view>订单编号</view>
					<view class="conter acea-row row-middle row-right">
						{{ orderInfo.order_id
		      }}
						<!-- #ifdef H5 -->
						<text class="copy copy-data" :data-clipboard-text="orderInfo.order_id">复制</text>
						<!-- #endif -->
						<!-- #ifdef MP -->
						<text class="copy copy-data" @click="copyNum(orderInfo.order_id)">复制</text>
						<!-- #endif -->
					</view>
				</view>
				<view class="item acea-row row-between">
					<view>下单时间</view>
					<view class="conter">{{ orderInfo._add_time }}</view>
				</view>
				<view class="item acea-row row-between">
					<view>支付状态</view>
					<view class="conter">
						{{ orderInfo.paid == 1 ? "已支付" : "未支付" }}
					</view>
				</view>
				<view class="item acea-row row-between">
					<view>支付方式</view>
					<view class="conter">{{ payType }}</view>
				</view>
				<view class="item acea-row row-between" v-if="orderInfo.refund_goods_explain">
					<view>退货留言</view>
					<view class='conter'>{{orderInfo.refund_goods_explain}}</view>
				</view>
				<view class="item acea-row row-between" v-if="orderInfo.refund_img && orderInfo.refund_img.length">
					<view>退款凭证</view>
					<view class="conter">
						<view class="pictrue" v-for="(item,index) in orderInfo.refund_img">
							<image :src="item" mode="aspectFill" @click='getpreviewImage(index,1)'></image>
						</view>
					</view>
				</view>
				<view class="item acea-row row-between" v-if="orderInfo.refund_goods_img && orderInfo.refund_goods_img.length">
					<view>退货凭证</view>
					<view class="conter">
						<view class="pictrue" v-for="(item,index) in orderInfo.refund_goods_img">
							<image :src="item" mode="aspectFill" @click='getpreviewImage(index,0)'></image>
						</view>
					</view>
				</view>
			</view>
			<view class="wrapper" v-if="orderInfo.store_delivery_type == 2 && orderInfo._status._type > 0">
				<view class="item acea-row row-between">
					<view>配送时间</view>
					<view class="conter">{{ orderInfo.estimate_time }}</view>
				</view>
				<view class="item acea-row row-between" v-if="[2, 3].includes(orderInfo._status._type)">
					<view>配送员</view>
					<view class="conter">{{ orderInfo.delivery_name }}</view>
				</view>
				<view class="item acea-row row-between" v-if="[2, 3].includes(orderInfo._status._type)">
					<view>配送员电话</view>
					<view class="conter">
						{{ orderInfo.delivery_id}}
						<text class="iconfont icon-ic_phone ml-12 fs-28 text-w111-2A7EFB" @tap="makePhone(orderInfo.delivery_id)"></text>
					</view>
				</view>
				<view class="item acea-row row-between" v-if="[3].includes(orderInfo._status._type)">
					<view>送达时间</view>
					<view class="conter">{{ orderInfo.delivery_time }}</view>
				</view>
			</view>
			<view class="wrapper">
				<view class='item acea-row row-between'>
					<view>商品总价</view>
					<view class='conter' v-if="statusType == -3">
						￥{{orderInfo.total_price}}</view>
					<view class='conter' v-else>
						￥{{(parseFloat(orderInfo.total_price)+parseFloat(orderInfo.vip_true_price)).toFixed(2)}}</view>
				</view>
				<view class="item acea-row row-between" v-if="orderInfo.pay_postage > 0">
					<view>配送运费</view>
					<view class="conter">￥{{ orderInfo.pay_postage }}</view>
				</view>
				<view v-if="orderInfo.first_order_price > 0" class='item acea-row row-between'>
					<view>首单优惠</view>
					<view class='conter'>-￥{{parseFloat(orderInfo.first_order_price).toFixed(2)}}</view>
				</view>
				<view v-if="orderInfo.vip_true_price > 0" class='item acea-row row-between'>
					<view>会员商品优惠</view>
					<view class='conter'>-￥{{parseFloat(orderInfo.vip_true_price).toFixed(2)}}</view>
				</view>
				<view class="item acea-row row-between" v-if='orderInfo.coupon_id'>
					<view>优惠券抵扣</view>
					<view class="conter">-￥{{ orderInfo.coupon_price }}</view>
				</view>
				<view class='item acea-row row-between' v-if="orderInfo.pay_integral > 0">
					<view>实付积分</view>
					<view class='conter'>{{orderInfo.pay_integral}}</view>
				</view>
				<view class='item acea-row row-between' v-if="orderInfo.use_integral > 0">
					<view>积分抵扣</view>
					<view class='conter'>-￥{{parseFloat(orderInfo.deduction_price).toFixed(2)}}</view>
				</view>
				<view class='item acea-row row-between' v-for="(item,index) in orderInfo.promotions_detail" :key="index" v-if="parseFloat(item.promotions_price)">
					<view>{{item.title}}</view>
					<view class='conter'>-￥{{parseFloat(item.promotions_price).toFixed(2)}}</view>
				</view>
				<view class="actualPay acea-row row-right row-middle">
					实付款：
					<BaseMoney :money="orderInfo.pay_price" symbolSize="24" integerSize="40" decimalSize="24" color="#FF7E00"></BaseMoney>
				</view>
			</view>
			<view class="height-add"></view>
			<view class="footer acea-row row-right row-middle" v-if="goname != 'looks'">
				<view class="bnt cancel" :class="openErp?'on':''" @click="modify('0')" v-if="types == 0 && $util.auth('mall-admin-order-change_price')">
					一键改价
				</view>
				<!-- types == -1 -->
				<view class="bnt cancel" v-if="$util.auth('mall-admin-order-remark')" @click="modify('1')">订单备注</view>
				<view class="bnt cancel" :class="openErp?'on':''" @click="modify('2',1)"
					v-if="(!orderInfo.refund || !orderInfo.refund.length) && (orderInfo.refund_type == 0 || orderInfo.refund_type == 1 || orderInfo.refund_type == 5) && orderInfo.paid && parseFloat(orderInfo.pay_price) >= 0 && $util.auth('mall-admin-order-refund')">
					立即退款
				</view>
				<view class="bnt cancel" :class="openErp?'on':''" @click="modify('2',0)" v-if="orderInfo.refund_type == 2">
					同意退货
				</view>
				<view class="bnt delivery" :class="openErp?'on':''" v-if="orderInfo.status == 0 && orderInfo.paid === 0 && $util.auth('mall-admin-order-offline')" @click="confirmShow = true">
					确认付款
				</view>
				<view class="bnt delivery" :class="openErp?'on':''" v-if="types == 1 && orderInfo.shipping_type === 1 && (orderInfo.pinkStatus === null || orderInfo.pinkStatus === 2) && $util.auth('mall-admin-order-delivery')"
					@click="goDelivery(orderInfo)">发送货</view>
				<view class="bnt" :class="openErp?'on':''" v-if="$util.auth('mall-admin-order-delivery') && orderInfo.store_delivery_type == 2 && orderInfo._status._type == 1 && orderInfo._status.is_reissue_order == 1"
					@click="orderReissueOrder(orderInfo)">重新发单</view>
				<view class="bnt delivery" :class="openErp?'on':''" v-if="$util.auth('mall-admin-order-delivery') && orderInfo.store_delivery_type == 2 && orderInfo._status._type == 1"
					@click="openDeliveryDrawer(orderInfo)">派单</view>
				<view class="bnt" :class="openErp?'on':''" v-if="$util.auth('mall-admin-order-delivery') && orderInfo.store_delivery_type == 2 && orderInfo._status._type == 2"
					@click="openDeliveryDrawer(orderInfo)">改派</view>
				<view class="bnt delivery" :class="openErp?'on':''" v-if="$util.auth('mall-admin-order-delivery') && orderInfo.store_delivery_type == 2 && orderInfo._status._type == 2"
					@click="deliveryConfirm(orderInfo)">确认送达</view>
				<view class="bnt delivery" v-if="orderInfo.delivery_type == 'express' && orderInfo._status._type == 2" @click="goLogistics(orderInfo)">查看物流
				</view>
				<view v-if="orderInfo.shipping_type == 2 &&
                (orderInfo.status == 0 || orderInfo.status == 5) &&
                orderInfo.paid == 1 &&
                orderInfo.refund_status === 0 && $util.auth('mall-admin-order-writeoff')" class="bnt delivery" @click="verify">立即核销</view>
			</view>
			<PriceChange :change="change" :orderInfo="orderInfo" :isRefund="isRefund" v-on:statusChange="statusChange($event)" v-on:closechange="changeclose($event)" v-on:savePrice="savePrice"
				:status="status"></PriceChange>
		</view>
		<view v-if="confirmShow" class="mask"></view>
		<view v-if="confirmShow" class="confirm-popup">
			<view class="title">确认付款</view>
			<view class="info">确认该订单用户已付款</view>
			<view class="acea-row btn-box">
				<view class="btn" @click="confirmShow = false">取消</view>
				<view class="btn primary" @click="offlinePay">确认</view>
			</view>
		</view>
		<home></home>
		<baseDrawer :visible="deliveryDrawerVisible" mode="bottom" backgroundColor="transparent" @close="closeDeliveryDrawer">
			<view class="pb-safe rd-t-40rpx bg--w111-f5f5f5">
				<view class="">
					<view class="flex-center h-108 rd-t-40rpx fw-500 fs-32">派单</view>
					<view class="abs-rt flex-center w-100 h-108">
						<view class="flex-center w-36 h-36 rd-50-p111- bg--w111-eee" @tap="closeDeliveryDrawer">
							<text class="iconfont icon-ic_close fs-24"></text>
						</view>
					</view>
				</view>
				<view class="pr-20 pb-20 pl-20">
					<view class="relative h-72 rd-36px bg--w111-fff">
						<view class="abs-lt flex-y-center w-80 h-72 pl-32">
							<text class="iconfont icon-ic_search fs-28 text--w111-999"></text>
						</view>
						<input v-model="keyword" class="flex-1 h-72 pl-80 fs-28" placeholder-class="text--w111-ccc" type="text" placeholder="请输入配送员名称/ID/手机号" confirm-type="search" @confirm="getStoreDeliveryList" />
					</view>
				</view>
				<scroll-view scroll-y="true" style="height: 1030rpx;">
					<view class="px-20">
						<view class="flex-y-center h-160 pr-40 pl-24 rd-16rpx mb-20 bg--w111-fff" v-for="(item, index) in deliveryList" :key="index" @tap="deliverySelect(item)">
							<image :src="item.avatar" mode="" class="w-96 h-96 rd-50-p111-"></image>
							<view class="flex-1 px-24 fs-28">
								<view class="mb-16 fw-500">{{item.wx_name}}</view>
								<view class="">{{item.phone}}</view>
							</view>
							<view class="flex-center w-56 h-56 rd-50-p111- bg-w111-EDF2F9" @tap="makePhone(item.phone)">
								<text class="iconfont icon-ic_Phone fs-28 text-w111-2A7EFB"></text>
							</view>
						</view>
					</view>
				</scroll-view>
			</view>
		</baseDrawer>
		<view v-if="deliveryConfirmShow" class="mask"></view>
		<view v-if="deliveryConfirmShow" class="confirm-popup">
			<view class="title">确认送达</view>
			<view class="info">送达后不可撤销，是否确认送达？</view>
			<view class="acea-row btn-box">
				<view class="btn" @click="deliveryConfirmShow = false">取消</view>
				<view class="btn primary" @click="onDeliveryConfirm">确认</view>
			</view>
		</view>
	</view>
</template>
<script>
	import PriceChange from "../components/PriceChange/index.vue";
	import home from '../components/home/index.vue';
	import customForm from "@/components/customForm";
	import countDown from '@/components/countDown/index.vue'
	// #ifdef MP || APP-PLUS
	import NavBar from "@/components/NavBar.vue";
	// #endif
	// #ifdef H5
	import ClipboardJS from "@/plugin/clipboard/clipboard.js";
	// #endif
	import {
		getStoreOrderDetail,
		getAdminOrderDetail,
		getStoreRefundDetail,
		getAdminRefundDetail,
		setStoreOrderPrice,
		setAdminOrderPrice,
		setStoreRefundRemark,
		setAdminRefundRemark,
		setStoreOrderRemark,
		setAdminOrderRemark,
		setStoreOfflinePay,
		setOfflinePay,
		setStoreOrderRefund,
		setOrderRefund,
		storeOrderRefundAgree,
		orderRefundAgree,
		getStoreUserInfo,
		getUserInfo,
		setAdminOrderDelivery,
		orderReassignDeliveryApi,
		storeDeliveryListApi,
		confirmDelivery,
		orderReissueOrderApi,
	} from "@/api/admin";
	import {
		erpConfig
	} from "@/api/esp.js";
	import {
		cardOrderBenefits
	} from "@/api/order.js";
	import {
		isMoney
	} from '@/utils/validate.js'
	import dayjs from '@/plugin/dayjs/dayjs.min.js';

	export default {
		name: "AdminOrder",
		components: {
			PriceChange,
			customForm,
			countDown,
			home,
			// #ifdef MP || APP-PLUS
			NavBar,
			// #endif
		},
		filters: {
			dateFormat: function(value) {
				return dayjs(value * 1000).format('YYYY-MM-DD HH:mm:ss');
			},
		},
		data: function() {
			return {
				openErp: false,
				giveData: {
					give_integral: 0,
					give_coupon: []
				},
				giveCartInfo: [],
				totalNmu: 0,
				order: false,
				change: false,
				order_id: "",
				orderInfo: {
					_status: {}
				},
				status: "",
				title: "",
				payType: "",
				types: "",
				statusType: '',
				clickNum: 1,
				goname: '',
				isRefund: 0, //1是仅退款;0是同意退货退款
				iconColor: '#FFFFFF',
				isScrolling: false,
				getHeight: this.$util.getWXStatusHeight(),
				confirmShow: false,
				userInfo: {},
				storeNum:1,
				cardRelated: [],
				isExpanded: false,
				deliveryDrawerVisible: false,
				deliveryList: [],
				deliveryOrder: {},
				keyword: '',
				deliveryConfirmShow: false,
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
		},
		watch: {
			"$route.params.oid": function(newVal) {
				let that = this;
				if (newVal != undefined) {
					that.order_id = newVal;
					that.getIndex();
				}
			}
		},
		onLoad: function(option) {
			this.storeNum = parseInt(option.storeNum || 1);
			this.order_id = option.id;
			this.goname = option.goname
			this.statusType = option.types
		},
		onShow() {
			let self = this
			this.getIndex();
			this.getErpConfig();
			// #ifdef H5
			this.$nextTick(function() {
				var clipboard = new ClipboardJS('.copy-data');
				clipboard.on('success', function(e) {
					self.$util.Tips({
						title: '复制成功'
					})
				});
				clipboard.on('error', function(e) {
					self.$util.Tips({
						title: '复制失败'
					})
				});
			});
			// #endif
		},
		onPageScroll(e) {
			// #ifdef MP || APP-PLUS
			if (e.scrollTop > 50) {
				this.iconColor = '#333333';
				this.isScrolling = true;
			} else {
				this.iconColor = '#FFFFFF';
				this.isScrolling = false;
			}
			// #endif
		},
		methods: {
			verify() {
				let auth = this.storeNum?1:4;
				if (this.orderInfo.product_type == 4) {
					uni.navigateTo({
						url: '/pages/admin/writeOffCard/index?auth='+auth+'&id=' + this.orderInfo.id
					})
				} else if(this.orderInfo.product_type == 6){
					uni.navigateTo({
						url: '/pages/admin/reservation_write/index?id=' + this.orderInfo.id
					})
				} else if(this.orderInfo.type == 11) {
					uni.navigateTo({
						url: '/pages/admin/distribution/scanning/detail/card?auth='+auth+'&id=' + this.orderInfo.id + '&let=1&code=' + this.orderInfo.verify_code
					})
				} else {
					uni.navigateTo({
						url: '/pages/admin/distribution/scanning/detail/index?auth='+auth+'&id=' + this.orderInfo.id + '&let=1&code=' + this.orderInfo.verify_code
					})
				}
			},
			statusChange(e) {
				this.status = e;
			},
			goLogistics(orderInfo) {
				uni.navigateTo({
					url: '/pages/admin/logistics/index?orderId=' + orderInfo.order_id
				})
			},
			goDelivery(orderInfo) {
				if (this.openErp) return
				uni.navigateTo({
					url: '/pages/admin/delivery/index?id=' + orderInfo.order_id + '&listId=' + orderInfo.id + '&totalNum=' + orderInfo.total_num + '&orderStatus=' + orderInfo.status +
						'&comeType=2&productType=' + orderInfo.product_type+'&storeNum='+this.storeNum
				})
			},
			getErpConfig() {
				erpConfig().then(res => {
					this.openErp = res.data.open_erp;
				}).catch(err => {
					this.$util.Tips({
						title: err
					})
				})
			},
			getpreviewImage: function(index, num) {
				uni.previewImage({
					urls: num ? this.orderInfo.refund_img : this.orderInfo.refund_goods_img,
					current: num ? this.orderInfo.refund_img[index] : this.orderInfo.refund_goods_img[index]
				});
			},
			more: function() {
				this.order = !this.order;
			},
			modify: function(status, type) {
				if (status != 1 && this.openErp) return


				if (status == 2) {
					this.isRefund = type
					uni.navigateTo({
						url: '/pages/admin/refund/index?id=' + this.orderInfo.order_id + '&listId=' + this.orderInfo.id
					})
				} else {
					this.change = true;
					this.status = status;
				}
			},
			changeclose: function(msg) {
				this.change = msg;
			},
			getIndex: function() {
				let that = this;
				let obj = '';
				if (that.statusType == -3) {
					let funApi = '';
					if(this.storeNum){
						funApi = getAdminRefundDetail(that.order_id);
					}else{
						funApi = getStoreRefundDetail(that.order_id);
					}
					obj = funApi;
				} else {
					let funApi = '';
					if(this.storeNum){
						funApi = getAdminOrderDetail(that.order_id);
					}else{
						funApi = getStoreOrderDetail(that.order_id);
					}
					obj = funApi;
				}
				obj.then(res => {
					let num = 0;
					that.types = res.data._status._type;
					that.title = res.data._status._title;
					that.payType = res.data._status._payType;
					that.giveData.give_coupon = res.data.give_coupon;
					that.giveData.give_integral = res.data.give_integral;
					let cartObj = [],
						giftObj = [];
					res.data.cartInfo.forEach((item, index) => {
						num += item.cart_num
						if (item.is_gift == 1) {
							giftObj.push(item)
						} else {
							cartObj.push(item)
						}
					});
					this.totalNmu = num;
					res.data.cartInfo = cartObj;
					that.$set(that, 'giveCartInfo', giftObj);
					that.orderInfo = res.data;
					if (res.data.type == 11) {
						that.cardOrderBenefits();
					}
					that.getUserInfo();
				}).catch(err => {
					return that.$util.Tips({
						title: err.msg
					});
				})
			},
			objOrderRefund(data) {
				let that = this;
				let funApi = '';
				if(this.storeNum){
					funApi = setOrderRefund;
				}else{
					funApi = setStoreOrderRefund;
				}
				funApi(data).then(
					res => {
						that.change = false;
						that.$util.Tips({
							title: res.msg
						});
						that.getIndex();
					},
					err => {
						that.change = false;
						that.$util.Tips({
							title: err
						});
					}
				);
			},
			async savePrice(opt) {
				let that = this,
					data = {},
					price = opt.price,
					refund_price = opt.refund_price,
					refund_status = that.orderInfo.refund_status,
					remark = opt.remark;
				data.order_id = that.orderInfo.order_id;
				if (that.status == 0) {
					if (!isMoney(price)) {
						return that.$util.Tips({
							title: '请输入正确的金额'
						});
					}
					data.price = price;
					let funApi = '';
					if(this.storeNum){
						funApi = setAdminOrderPrice;
					}else{
						funApi = setStoreOrderPrice;
					}
					funApi(data).then(
						function() {
							that.change = false;
							that.$util.Tips({
								title: '改价成功',
								icon: 'success'
							})
							that.getIndex();
						},
						function() {
							that.change = false;
							that.$util.Tips({
								title: '改价失败',
								icon: 'none'
							})
						}
					);
				} else if (that.status == 2) {
					if (this.isRefund) {
						if (!isMoney(refund_price)) {
							return that.$util.Tips({
								title: '请输入正确的金额'
							});
						}
						data.price = refund_price;
						data.type = opt.type;
						this.objOrderRefund(data);
					} else {
						if (opt.type == 1) {
							let funApi = '';
							if(this.storeNum){
								funApi = orderRefundAgree;
							}else{
								funApi = storeOrderRefundAgree;
							}
							funApi(this.orderInfo.id).then(res => {
								that.change = false;
								that.$util.Tips({
									title: res.msg
								});
								that.getIndex();
							}).catch(err => {
								that.change = false;
								that.$util.Tips({
									title: err
								});
							})
						}
					}
				} else if (that.status == 8) {
					data.type = opt.type;
					data.refuse_reason = opt.refuse_reason;
					this.objOrderRefund(data);
				} else {
					if (!remark) {
						return this.$util.Tips({
							title: '请输入备注'
						})
					}
					data.remark = remark;
					let obj = '';
					if (that.statusType == -3) {
						let funApi = '';
						if(this.storeNum){
							funApi = setAdminRefundRemark(data);
						}else{
							funApi = setStoreRefundRemark(data);
						}
						obj = funApi;
					} else {
						let funApi = '';
						if(this.storeNum){
							funApi = setAdminOrderRemark(data);
						}else{
							funApi = setStoreOrderRemark(data);
						}
						obj = funApi;
					}
					obj.then(
						res => {
							that.change = false;
							this.$util.Tips({
								title: res.msg,
								icon: 'success'
							})
							this.orderInfo.remark = remark;
						},
						err => {
							that.change = false;
							that.$util.Tips({
								title: err
							});
						}
					);
				}
			},
			offlinePay: function() {
				if (this.openErp) return
				let funApi = '';
				if(this.storeNum){
					funApi = setOfflinePay;
				}else{
					funApi = setStoreOfflinePay;
				}
				funApi({
					order_id: this.orderInfo.order_id
				}).then(
					res => {
						this.confirmShow = false;
						this.$util.Tips({
							title: res.msg,
							icon: 'success'
						});
						this.getIndex();
					},
					err => {
						this.$util.Tips({
							title: err
						});
					}
				);
			},
			// #ifdef MP
			copyNum(id) {

				uni.setClipboardData({
					data: id,
					success: function() {}
				});
			},
			// #endif
			// #ifdef H5
			webCopy(item, index) {
				let items = item
				let indexs = index
				let self = this

				if (self.clickNum == 1) {
					self.clickNum += 1
					self.webCopy(items, indexs)
				}
			},
			// #endif
			getUserInfo() {
				let funApi = '';
				if(this.storeNum){
					funApi = getUserInfo;
				}else{
					funApi = getStoreUserInfo;
				}
				funApi(this.orderInfo.uid).then(res => {
					this.userInfo = res.data;
				});
			},
			cardOrderBenefits() {
				cardOrderBenefits(this.orderInfo.id).then((res) => {
					this.cardRelated = res.data;
				});
			},
			toggleExpand() {
				this.isExpanded = !this.isExpanded;
			},
			// 复制地址
			copyAddress() {
				uni.setClipboardData({
					data: this.orderInfo.real_name + this.orderInfo.user_phone + ' ' +this.orderInfo.user_address
				})
			},
			makePhone(phone) {
				uni.makePhoneCall({
					phoneNumber: phone
				})
			},
			openDeliveryDrawer(item) {
				this.deliveryDrawerVisible = true;
				this.deliveryOrder = item;
				this.getStoreDeliveryList();
			},
			closeDeliveryDrawer() {
				this.deliveryDrawerVisible = false;
			},
			getStoreDeliveryList() {
				storeDeliveryListApi({
					type: 1,
					relation_id: this.deliveryOrder.store_id,
					keyword: this.keyword,
				}).then(res => {
					this.deliveryList = res.data.list;
				});
			},
			setInfo(deliveryItem) {
				let that = this;
				let funApi = '';
				let params = {
					type: 2,
					delivery_type: 1,
					sh_delivery_name: deliveryItem.wx_name,
					sh_delivery_id: deliveryItem.phone,
					sh_delivery_uid: deliveryItem.uid,
				}
				funApi = setAdminOrderDelivery;
				funApi(that.deliveryOrder.id, params).then(
					res => {
						that.deliveryDrawerVisible = false;
						that.$util.Tips({
							title: res.msg,
							icon: 'success',
							mask: true
						})
						that.getIndex();
					},
					error => {
						that.$util.Tips({
							title: error
						})
					}
				);
			},
			orderReassignDelivery(deliveryItem) {
				orderReassignDeliveryApi(this.deliveryOrder.id, {
					sh_delivery_name: deliveryItem.wx_name,
					sh_delivery_id: deliveryItem.phone,
					sh_delivery_uid: deliveryItem.uid,
				}).then(res => {
					this.deliveryDrawerVisible = false;
					this.$util.Tips({
						title: res.msg,
						icon: 'success',
						mask: true
					})
					this.getIndex();
				});
			},
			deliverySelect(deliveryItem) {
				if (this.deliveryOrder._status._type == 2) {
					this.orderReassignDelivery(deliveryItem);
				} else{
					this.setInfo(deliveryItem);
				}
			},
			deliveryConfirm(item) {
				this.deliveryOrder = item;
				this.deliveryConfirmShow = true;
			},
			onDeliveryConfirm() {
				confirmDelivery({
					order_id: this.deliveryOrder.order_id,
				}).then(res => {
					this.deliveryConfirmShow = false;
					this.$util.Tips({
						title: res.msg,
						icon: 'success',
						mask: true
					})
					this.getIndex();
					// setTimeout(res => {
					// 	const index = this.list.findIndex(item => item.id == this.deliveryOrder.id);
					// 	if (index > -1) {
					// 		this.list.splice(index, 1);
					// 	}
					// }, 2000)
				})
			},
			// 重新发单
			orderReissueOrder(item) {
				orderReissueOrderApi(item.id, {
					store_id: item.store_id,
				}).then(res => {
					this.$util.Tips({
						title: res.msg,
						icon: 'success',
						mask: true,
					});
					this.getIndex();
				}).catch(err => {
					this.$util.Tips({
						title: err,
					});
				});
			},
		}
	};
</script>

<style lang="scss" scoped>
	.bg-w111-EDF2F9{
		background-color: #EDF2F9;
	}
	.pl-80 {
		padding-left: 80rpx;
	}
	.headerBg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		background-image: linear-gradient(360deg, #F5F5F5 0%, rgba(245, 245, 245, 0) 100%),
			linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%);
		background-position: left bottom, left top;
		background-repeat: no-repeat;
		background-size: 100% 120rpx, 100% 100%;

		.inner {
			height: 356rpx;
		}
	}

	.order-details {
		position: absolute;
		width: 100%;
		padding: 0 20rpx;
	}

	.height-add {
		height: calc(120rpx+ constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(120rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
	}

	.giveGoods {
		.item {
			padding: 14rpx 30rpx 14rpx 0;
			margin-left: 30rpx;
			border-top: 1px solid #eee;

			.picTxt {
				.pictrue {
					width: 76rpx;
					height: 76rpx;
					border-radius: 6rpx;
					background-color: #F5F5F5;
					color: #2a7efb;

					.iconfont {
						font-size: 34rpx;
					}

					image {
						width: 100%;
						height: 100%;
						border-radius: 6rpx;
					}

					margin-right: 16rpx;
				}

				.texts {
					width: 360rpx;
					color: #999999;
					font-size: 20rpx;

					.name {
						color: #333;
					}

					.limit {
						font-size: 20rpx;
						margin-top: 4rpx;
					}
				}
			}

			.num {
				color: #999999;
				font-size: 20rpx;
			}
		}
	}

	.splitTitle {
		width: 100%;
		height: 80rpx;
		background-color: #fff;
		margin-top: 17rpx;
		border-bottom: 1px solid #e5e5e5;
		padding: 0 30rpx;
	}

	.splitTitle .title {
		color: #2291f8;
	}

	/*商户管理订单详情*/
	.pos-order-details .header .data .order-num {
		font-size: 26upx;
		margin-bottom: 8upx;
	}

	.pos-order-details .remarks {
		padding-left: 32rpx;
		border-radius: 24rpx;
		background: #FFFFFF;
	}

	.pos-order-details .remarks .iconfont {
		font-size: 32rpx;
		color: #000000;
	}

	.pos-order-details .remarks input {
		flex: 1;
		height: 100rpx;
		padding-left: 20rpx;
		font-size: 28rpx;
	}

	.pos-order-details .remarks input::placeholder {
		color: #CCCCCC;
	}

	.pos-order-details .orderingUser {
		font-size: 26upx;
		color: #282828;
		padding: 0 30upx;
		height: 67upx;
		background-color: #fff;
		margin-top: 16upx;
		border-bottom: 1px solid #f5f5f5;
	}

	.pos-order-details .orderingUser .iconfont {
		font-size: 40upx;
		color: #2a7efb;
		margin-right: 15upx;
	}

	.pos-order-details .address {
		margin-top: 0;
	}

	.pos-order-details .footer .more {
		font-size: 27upx;
		color: #aaa;
		width: 100upx;
		height: 64upx;
		text-align: center;
		line-height: 64upx;
		margin-right: 25upx;
		position: relative;
	}

	.pos-order-details .footer .delivery {
		border-color: #2A7EFB !important;
		background: #2A7EFB;
		color: #FFFFFF !important;
	}

	.pos-order-details .footer .more .order .arrow {
		width: 0;
		height: 0;
		border-left: 11upx solid transparent;
		border-right: 11upx solid transparent;
		border-top: 20upx solid #e5e5e5;
		position: absolute;
		left: 15upx;
		bottom: -18upx;
	}

	.pos-order-details .footer .more .order .arrow:before {
		content: '';
		width: 0;
		height: 0;
		border-left: 9upx solid transparent;
		border-right: 9upx solid transparent;
		border-top: 19upx solid #fff;
		position: absolute;
		left: -10upx;
		bottom: 0;
	}

	.pos-order-details .footer .more .order {
		width: 200upx;
		background-color: #fff;
		border: 1px solid #eee;
		border-radius: 10upx;
		position: absolute;
		top: -200upx;
		z-index: 9;
	}

	.pos-order-details .footer .more .order .item {
		height: 77upx;
		line-height: 77upx;
	}

	.pos-order-details .footer .more .order .item~.item {
		border-top: 1px solid #f5f5f5;
	}

	.pos-order-details .footer .more .moreName {
		width: 100%;
		height: 100%;
	}

	/*订单详情*/
	.order-details .header {
		padding: 48rpx 0 30rpx 12rpx;
	}

	.order-details .header.on {
		background-color: #666 !important;
	}

	.order-details .header .pictrue {
		width: 110upx;
		height: 110upx;
	}

	.order-details .header .pictrue image {
		width: 100%;
		height: 100%;
	}

	.order-details .header .state {
		font-weight: 500;
		font-size: 36rpx;
		line-height: 50rpx;
		color: #FFFFFF;
	}

	.order-details .header .data {
		margin-top: 8rpx;
		font-size: 26rpx;
		line-height: 36rpx;
		color: #FFFFFF;
	}

	.order-details .header.on .data {
		margin-left: 0;
	}

	.order-details .header .data .time {
		margin-left: 20rpx;
	}

	.order-details .header .data .state {
		font-size: 30upx;
		font-weight: bold;
		color: #fff;
		margin-bottom: 7upx;
	}

	.order-details .nav {
		background-color: #fff;
		font-size: 26upx;
		color: #282828;
		padding: 25upx 0;
	}

	.order-details .nav .navCon {
		padding: 0 40upx;
	}

	.order-details .nav .navCon .on {
		font-weight: bold;
		color: #e93323;
	}

	.order-details .nav .progress {
		padding: 0 65upx;
		margin-top: 10upx;
	}

	.order-details .nav .progress .line {
		width: 100upx;
		height: 2upx;
		background-color: #939390;
	}

	.order-details .nav .progress .iconfont {
		font-size: 25upx;
		color: #939390;
		margin-top: -2upx;
		width: 30upx;
		height: 30upx;
		line-height: 33upx;
		text-align: center;
		margin-right: 0 !important;
	}

	.order-details .address {
		position: relative;
		padding: 32rpx 32rpx 40rpx;
		border-radius: 24rpx;
		margin-top: 20rpx;
		background: #FFFFFF;
		overflow: hidden;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #999999;
	}

	.order-details .address .name {
		font-weight: 500;
		font-size: 30rpx;
		line-height: 42rpx;
		color: #333333;
	}

	.order-details .address .name .iconfont {
		margin-right: 8rpx;
		font-size: 32rpx;
	}

	.order-details .address .name .phone {
		margin-left: 40upx;
	}
	
	.order-details .address .copy {
		display: inline-block;
		height: 36rpx;
		padding: 0 12rpx;
		border: 0;
		border-radius: 18rpx;
		margin-left: 8rpx;
		background: #F5F5F5;
		font-size: 22rpx;
		line-height: 36rpx;
		color: #333333;
	}

	.order-details .line {
		position: absolute;
		bottom: 0;
		left: 0;
		width: 100%;
		height: 4rpx;
	}

	.order-details .line image {
		width: 100%;
		height: 100%;
		display: block;
	}

	.order-details .wrapper {
		padding: 32rpx 24rpx;
		border-radius: 24rpx;
		margin-top: 20rpx;
		background: #FFFFFF;
	}
	
	.order-details .wrapper .title{
		font-family: PingFang SC, PingFang SC;
		font-weight: 500;
		font-size: 28rpx;
		color: #3D3D3D;
		margin-bottom: 32rpx;
	}

	.order-details .wrapper .item {
		font-size: 28rpx;
		line-height: 40rpx;
		color: #333333;
	}

	.order-details .wrapper .item~.item {
		margin-top: 24rpx;
	}

	.order-details .wrapper .item .conter {
		.pictrue {
			width: 80rpx;
			height: 80rpx;
			margin-left: 6rpx;

			image {
				width: 100%;
				height: 100%;
				border-radius: 6rpx;
			}
		}
	}

	.order-details .wrapper .item .conter .copy {
		height: 36rpx;
		padding: 0 12rpx;
		border: 0;
		border-radius: 18rpx;
		margin-left: 8rpx;
		background: #F5F5F5;
		font-size: 22rpx;
		line-height: 36rpx;
		color: #333333;
	}

	.order-details .wrapper .actualPay {
		margin-top: 26rpx;
	}

	.order-details .wrapper .actualPay .money {
		font-weight: bold;
		font-size: 30upx;
		color: #e93323;
	}

	.order-details .footer {
		width: 100%;
		height: 100upx;
		position: fixed;
		bottom: 0;
		left: 0;
		background-color: #fff;
		padding: 0 30upx;
		border-top: 1px solid #eee;
		height: calc(100rpx+ constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(100rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		padding-bottom: calc(0rpx+ constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		padding-bottom: calc(0rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
	}

	.order-details .footer .wait {
		color: #2a7efb;
		margin-right: 30rpx;
	}

	.order-details .footer .bnt {
		width: 144rpx;
		height: 56rpx;
		border: 1rpx solid #CCCCCC;
		line-height: 54rpx;
		text-align: center;
		border-radius: 28rpx;
		font-size: 24rpx;
		color: #333333;
		transform: rotateZ(360deg);

		&.on {
			color: #c5c8ce !important;
			background: #f7f7f7 !important;
			border: 1px solid #dcdee2 !important;
		}
	}

	.order-details .footer .bnt.default {
		color: #444;
		border: 1px solid #444;
	}

	.order-details .footer .bnt~.bnt {
		margin-left: 16rpx;
	}

	.pos-order-goods {
		padding: 32rpx 24rpx;
		border-radius: 24rpx;
		background: #FFFFFF;
	}

	.pos-order-goods.split {
		margin-top: 20rpx;
	}

	.pos-order-goods .title {
		height: 40rpx;
		margin-bottom: 32rpx;
		font-size: 28rpx;
		color: #333333;
	}

	.pos-order-goods .title .btn {
		font-size: 26rpx;
		color: #999999;
	}

	.pos-order-goods .title .btn .iconfont {
		font-size: 24rpx;
	}

	.pos-order-goods .goods~.goods {
		margin-top: 32rpx;
	}

	.pos-order-goods .goods .picTxt {
		flex: 1;
		min-width: 0;
	}

	.pos-order-goods .goods .picTxt .pictrue {
		width: 136rpx;
		height: 136rpx;
	}

	.pos-order-goods .goods .picTxt .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 16rpx;
	}

	.pos-order-goods .goods .picTxt .text {
		flex: 1;
		min-width: 0;
		padding-left: 20rpx;
	}

	.pos-order-goods .goods .picTxt .text .info {
		font-size: 28rpx;
		line-height: 40rpx;
		color: #333333;
	}

	.pos-order-goods .goods .picTxt .text .info .label {
		color: #ff4c3c;
	}

	.pos-order-goods .goods .picTxt .text .attr {
		margin-top: 8rpx;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #999999;
	}

	.pos-order-goods .goods .money {
		width: 144rpx;
		text-align: right;
	}

	.pos-order-goods .goods .money .writeOff {
		font-size: 24upx;
		margin-top: 17upx;
		color: #1890FF;
	}

	.pos-order-goods .goods .money .writeOff .on {
		color: #FF7E00;
	}

	.pos-order-goods .goods .money .x-money {
		color: #282828;
	}

	.pos-order-goods .goods .money .num {
		margin-top: 10rpx;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #999999;
	}

	.pos-order-goods .goods .money .y-money {
		color: #999;
		text-decoration: line-through;
	}

	.public-total {
		font-size: 28upx;
		color: #282828;
		border-top: 1px solid #eee;
		height: 92upx;
		line-height: 92upx;
		text-align: right;
		padding: 0 30upx;
		background-color: #fff;
	}

	.public-total .money {
		color: #ff4c3c;
	}

	.copy-data {
		font-size: 10px;
		color: #333;
		-webkit-border-radius: 1px;
		border-radius: 1px;
		border: 1px solid #666;
		padding: 0px 7px;
		margin-left: 12px;
		height: 20px;
	}

	.pos-order-goods .mark {
		margin-top: 32rpx;
		font-size: 28rpx;
		line-height: 40rpx;
		color: #333333;

		.name {
			width: 136rpx;
		}

		.value {
			flex: 1;
			text-align: right;
		}
	}

	.mask {
		z-index: 21;
	}

	.confirm-popup {
		position: fixed;
		top: 50%;
		right: 75rpx;
		left: 75rpx;
		z-index: 21;
		transform: translateY(-50%);
		border-radius: 32rpx;
		background: #FFFFFF;
		text-align: center;

		.title {
			padding: 40rpx 32rpx 0;
			font-weight: 500;
			font-size: 32rpx;
			line-height: 52rpx;
			color: #333333;
		}

		.info {
			padding: 24rpx 40rpx 0;
			font-size: 30rpx;
			line-height: 42rpx;
			color: #666666;
		}

		.btn-box {
			padding: 40rpx;
		}

		.btn {
			flex: 1;
			height: 72rpx;
			border: 1rpx solid #2A7EFB;
			border-radius: 36rpx;
			margin-left: 32rpx;
			font-weight: 500;
			font-size: 26rpx;
			line-height: 70rpx;
			color: #2A7EFB;
			transform: rotateZ(360deg);

			&.primary {
				background: #2A7EFB;
				color: #FFFFFF;
			}
		}
	}

	.user-box {
		padding: 24rpx;
		border-radius: 24rpx;
		margin-top: 20rpx;
		background: #FFFFFF;

		.image {
			width: 80rpx;
			height: 80rpx;
			border-radius: 50%;
		}

		.text {
			flex: 1;
			padding-left: 20rpx;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #999999;
		}

		.name {
			margin-bottom: 4rpx;
			font-weight: 500;
			font-size: 28rpx;
			line-height: 40rpx;
			color: #333333;
		}

		.svip {
			width: 56rpx;
			height: 26rpx;
			border-radius: 14rpx;
			margin-left: 12rpx;
			background: linear-gradient(90deg, #484643 0%, #1F1B17 100%);
			text-align: center;
			font-weight: 600;
			font-size: 18rpx;
			line-height: 26rpx;
			color: #FDDAA4;
		}

		.grade {
			height: 26rpx;
			padding: 0 10rpx;
			border: 1rpx solid #FACC7D;
			border-radius: 14rpx;
			margin-left: 10rpx;
			background: #FEF0D9;
			font-weight: 500;
			font-size: 18rpx;
			line-height: 24rpx;
			color: #DFA541;
			transform: rotateZ(360deg);

			.iconfont {
				margin-right: 6rpx;
				font-size: 18rpx;
			}
		}
	}
</style>