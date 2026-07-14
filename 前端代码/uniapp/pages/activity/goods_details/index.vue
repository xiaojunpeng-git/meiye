<template>
	<view :style="colorStyle">
		<skeleton :show="showSkeleton" :isNodes="isNodes" ref="skeleton" loading="chiaroscuro" selector="skeleton"
			bgcolor="#FFF"></skeleton>
		<view class="product-con skeleton" :style="{visibility: showSkeleton ? 'hidden' : 'visible'}">
			<view>
				<view class="w-full fixed-lt z-99" :style="[navBarStyle]">
					<view class="w-full px-20 flex-between-center" :style="[menuBgStyle]">
						<!-- #ifdef MP -->
						<view class="fixed flex-center opac" :style="[menuButtonStyle]">
						<!-- #endif -->
						<!-- #ifndef MP -->
						<view class="menu_box flex-center opac">
						<!-- #endif -->
							<view class="iconfont icon-ic_left fs-34 text-w111-000" @tap="goPage(3)"></view>
							<text class="menu_line"></text>
							<view class="iconfont icon-ic_menu3 fs-32 text-w111-000" @tap="moreNav"></view>
						</view>
						<!-- #ifdef MP -->
						<view class="fixed rd-50-p111- flex-center opac"
							:style="[shareButtonStyle]"
							@tap="listenerActionSheet">
						<!-- #endif -->
						<!-- #ifndef MP -->
						<view class="w-60 h-60 rd-50-p111- flex-center opac" @tap="listenerActionSheet">
						<!-- #endif -->
							<view class="iconfont icon-ic_share1 fs-32 text-w111-000"></view>
						</view>
					</view>
				</view>
				<homeList
					:navH="46 + sysHeight"
					:currentPage="currentPage"
					:sysHeight="sysHeight"
					:openNavList="diyProduct.navList"></homeList>
				<view>
					<!-- 商品轮播图 -->
					<productConSwiper
						class="skeleton-rect"
						:currents='attrPicIndex'
						:sku="selectSku ? selectSku.suk : ''"
						:showSku="showSkuBox"
						:autoHeight="diyProduct.pictureConfig"
						:showDot="diyProduct.swiperDot"
						:imgUrls="productSwiper"
						:videoline="productVideoLink"
						:pageHide="pageHide"
						@videoPause="videoPause"
						@changeAttrPic="changeAttrPic"
						@attrPicIndex="attrPicIndexs"
						@previewImageOpen="previewImageOpen"
						></productConSwiper>
					<view class="mirror-image">
						<image :src="productSwiper[attrPicIndex]"></image>
					</view>
					<view class="activity-card seckill-card rd-t-40rpx relative w-full h-152 flex justify-between"
						:style="{backgroundImage:infoCardBg}" v-if="type == 1">
						<view class="flex items-baseline text--w111-fff">
							<text class="fs-28 fw-600 lh-40rpx pr-16">秒杀价</text>
							<baseMoney :money="attribute.productSelect.price" symbolSize="32" integerSize="56" decimalSize="32"  color="#ffffff"></baseMoney>
							<baseMoney :money="attribute.productSelect.ot_price" symbolSize="32" integerSize="32" decimalSize="32"  line color="#ffffff" class="ml-14"></baseMoney>
						</view>
						<view class="flex-col" v-if="status == 1">
							<text class="fs-20 text--w111-fff lh-28rpx text-center pb-4">距秒杀结束还剩</text>
							<countDown
							:is-day="false"
							tip-text=" "
							day-text=" "
							hour-text=":"
							minute-text=":"
							second-text=" "
							:datatime="datatime"
							bgColor="#ffffff"
							colors="#e93323"
							></countDown>
						</view>
						<view class="text-white fs-28 mt-10 mr-20" v-else-if="status == 2">即将开始</view>
						<view class="text-white fs-28 mt-10 mr-20" v-else>活动已结束</view>
					</view>
					<view class="activity-card rd-t-40rpx relative w-full h-152 flex justify-between"
						:style="{backgroundImage:infoCardBg}" v-if="type == 3">
						<view class="flex items-baseline text--w111-fff mt-8">
							<text class="fs-28 fw-600 lh-40rpx pr-16">拼团价</text>
							<baseMoney :money="attribute.productSelect.price" symbolSize="32" integerSize="56" decimalSize="32" color="#ffffff"></baseMoney>
							<baseMoney
							:money="storeInfo.product_price"
							symbolSize="32"
							integerSize="32"
							decimalSize="32"
							line color="#ffffff"
							class="ml-14"></baseMoney>
						</view>
						<view class="pink-badge mt-10 flex-center fs-26 fw-500">{{storeInfo.people}}人团</view>
					</view>
					<view class="activity-card rd-t-40rpx relative w-full h-152 flex justify-between"
						:style="{backgroundImage:infoCardBg}" v-if="type == 6">
						<view class="flex items-baseline text--w111-fff mt-8">
							<text class="fs-28 fw-600 lh-40rpx pr-16">预售价</text>
							<baseMoney :money="attribute.productSelect.price" symbolSize="32" integerSize="56" decimalSize="32" color="#ffffff"></baseMoney>
							<baseMoney :money="storeInfo.ot_price" symbolSize="32" integerSize="32" decimalSize="32" line color="#ffffff" class="ml-14"></baseMoney>
						</view>
					</view>
					<!-- 商品介绍卡片 -->
					<view class="info_card rd-40rpx relative" :style="{marginTop: type ==1 || type == 3 || type == 6 ? '-52rpx' : ''}">
						<view class="px-32">
							<view class="w-full pt-36">
								<!-- 骨架屏展示 -->
								<view v-if="showSkeleton">
									<view class="w-full h-46 skeleton-rect mt-20" ></view>
									<view class="mt-24 flex">
										<view class="w-200 h-46 skeleton-rect"></view>
										<view class="w-100 h-46 skeleton-rect ml-24"></view>
									</view>
									<view class="mt-24 flex-between-center">
										<view class="w-200 h-46 skeleton-rect"></view>
										<view class="w-200 h-46 skeleton-rect"></view>
										<view class="w-200 h-46 skeleton-rect"></view>
									</view>
									<view class="mt-24 flex-between-center">
										<view class="w-96 h-46 skeleton-rect"></view>
										<view class="flex-1 h-46 skeleton-rect ml-22"></view>
									</view>
									<view class="mt-24 flex-between-center">
										<view class="w-96 h-46 skeleton-rect"></view>
										<view class="flex-1 h-46 skeleton-rect ml-22"></view>
									</view>
									<view class="mt-24 flex-between-center">
										<view class="w-96 h-46 skeleton-rect"></view>
										<view class="flex-1 h-46 skeleton-rect ml-22"></view>
									</view>
									<view class="mt-24 flex">
										<view class="w-400 h-200 skeleton-rect"></view>
										<view class="w-400 h-200 skeleton-rect ml-24"></view>
									</view>
								</view>
								<!-- sku小图 -->
								<view class="flex mb-32" v-if="skuArr.length && skuArr.length > 1">
									<view class="flex-1 flex-center">
										<view :class="isSwiper ? 'active_pic' : 'scroll_pic'" @tap="selectSwiper">
											<image :src="storeInfo.image"></image>
										</view>
										<view class="w-76 h-80 flex-col flex-center fs-22 text--w111-333 lh-30rpx">
											<text>{{skuArr.length}}款</text>
											<text>可选</text>
										</view>
									</view>
									<scroll-view scroll-x="true" scroll-with-animation :scroll-left="scrollToView"
										class="white-nowrap vertical-middle w-530" show-scrollbar="false">
										<view class="flex w-full">
											<view class="inline-block mr-16"
												:class="item.suk == attrValue && !isSwiper ? 'active_pic' : 'scroll_pic'"
												v-for="(item, index) in skuArr" :key="index"
												@tap="changeAttrPic(index)">
												<image :src="item.small_image"></image>
											</view>
										</view>
									</scroll-view>
								</view>
								<view class="flex items-baseline mb-24" v-if="type == 7">
									<baseMoney
										:money="attribute.productSelect.price"
										symbolSize="32"
										integerSize="48"
										preFix="到手价"
										preFixSize="22"
										textColor="var(--view-theme)"
										decimalSize="32" weight></baseMoney>
								</view>
								<view class="flex-y-center font-red mb-24" :class="skuArr.length && skuArr.length > 1 ? 'mt-44' : ''"
									v-if="type == 4">
									<text class="lh-40rpx fs-40 SemiBold pl-8">{{attribute.productSelect.integral}}</text>
									<text class="fs-36 fw-500 pingfang pl-4">积分</text>
									<text class="fs-28 lh-40rpx px-8 text--w111-666">+</text>
									<text class="lh-40rpx fs-40 SemiBold">{{attribute.productSelect.price}}</text>
									<text class="fs-36 fw-500 pingfang pl-4">元</text>
								</view>
								<!-- 商品名称 -->
								<view class="fs-30 text--w111-333 fw-500 lh-42rpx"
									:class="skuArr.length && skuArr.length > 1 ? 'mt-32' : ''"
									>
									<text v-if="storeInfo.brand_name" class="brand-tag">{{ storeInfo.brand_name }}</text>{{  storeInfo.title || storeInfo.store_name }}
								</view>
								<!-- 预售信息 -->
								<view class="mt-16 fs-24 lh-36rpx" v-if="type == 6">
									<view>预售时间: {{storeInfo.presale_start_time}} ~ {{storeInfo.presale_end_time}}</view>
									<view class="mt-12">预售结束后{{ storeInfo.presale_day }}天内发货</view>
								</view>
								<!-- 库存销量 -->
								<view class="flex-between-center mt-20 text--w111-999 fs-22 lh-30rpx">
									<text class="line-through" v-if="type == 6 && storeInfo.ot_price">￥{{storeInfo.ot_price}}</text>
									<text class="line-through" v-else-if="type != 6 && attribute.productSelect.ot_price">￥{{attribute.productSelect.ot_price}}</text>
									<text>限量：{{ storeInfo.quota_show || storeInfo.stock }}{{  storeInfo.unit_name || ''}}</text>
									<text v-if="type == 4">已兑换：{{ storeInfo.sales}}{{  storeInfo.unit_name || ''}}</text>
									<text v-else-if="type == 6">已预定：{{ storeInfo.sales}}{{  storeInfo.unit_name || ''}}</text>
									<text v-else>销量：{{ storeInfo.sales}}{{  storeInfo.unit_name || ''}}</text>
								</view>
								<!-- 商品标签 -->
								<view class="flex items-end flex-wrap mt-24 w-full"
									v-if="storeInfo.store_label && storeInfo.store_label.length">
									<BaseTag
										:text="label.label_name"
										:color="label.color"
										:background="label.bg_color"
										:borderColor="label.border_color"
										:circle="label.border_color ? true : false"
										:imgSrc="label.icon"
										size="middle"
										v-for="(label, idx) in storeInfo.store_label" :key="idx"></BaseTag>
								</view>
							</view>
						</view>
					</view>
					<view class="px-20">
						<!-- 活动 SKU选择 服务保障 -->
						<view class="rd-24rpx bg--w111-fff mt-20">
							<view class="flex-between-center h-100 px-20" @tap="selecAttr"
								v-if="attribute.productAttr.length">
								<view class="flex-y-center">
									<text class="fs-26 text--w111-888">选择</text>
									<view class="ml-32 text--w111-333 fs-26 w-560 line1">{{ attrValue }}</view>
								</view>
								<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666"></text>
							</view>
							<view class="flex-between-center h-100 px-20" v-if="storeInfo.ensure && storeInfo.ensure.length"
								@tap="()=>{showServiceDrawer = true}">
								<view class="flex-y-center">
									<text class="fs-26 text--w111-888">服务</text>
									<view class="ml-32 text--w111-333 fs-26 w-524 line1">
										<text v-for="(label,i) in storeInfo.ensure" :key="i">{{label.name}}.</text>
									</view>
								</view>
								<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666"></text>
							</view>
							<view class="flex-between-center h-100 px-20"
								v-if="storeInfo.specs && storeInfo.specs.length"
								@tap="seeSpecs">
								<view class="flex-y-center">
									<text class="fs-26 text--w111-888">参数</text>
									<view class="ml-32 text--w111-333 fs-26 w-524 line1">{{specsInfo.specsTitle}}</view>
								</view>
								<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666"></text>
							</view>
							<view class="acea-row row-between h-146 pl-20 pr-20 pt-30" v-if="isMention && Object.keys(storeList).length" @tap="showStoreList">
								<view class="acea-row">
									<text class="fs-26 text--w111-888">门店</text>
									<view class="ml-32 text--w111-333 fs-26 w-524 line1">
										<view class="fs-26"><text class="iconfont icon-ic_mall fs-30 mr-12"></text>{{storeList.name}}</view>
										<view class="fs-22 text--w111-666 mt-18 line1">{{ storeList.address }}{{ ", " + storeList.detailed_address }}</view>
									</view>
								</view>
								<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666" v-if="shopType == 1 && belongShow && storeShow"></text>
							</view>
						</view>
						<!-- 拼团详情 -->
						<view class="rd-24rpx bg--w111-fff mt-20 p-24" :class="pink.length?'':'pb-0'" v-if="type == 3">
							<view class="flex fs-24 notice pb-24" :class="pink.length?'border-bottom':''">
								<text class="text-red">已拼{{pink_ok_sum}}{{storeInfo.unit_name || ''}}</text>丨
								<view class='swiper'>
									<swiper :indicator-dots="false" autoplay interval="2500" duration="500"
										vertical="true" circular="true">
										<block v-for="(item,index) in itemNew" :key='index'>
											<swiper-item>
												<view class='line1'>{{item}}</view>
											</swiper-item>
										</block>
									</swiper>
								</view>
							</view>
							<view class="mt-38" v-if="pink.length">
								<view class="flex-between-center pink-cell" v-for='(item,index) in pink' :key='index'>
									<view class="flex-1 flex-y-center">
										<image :src='item.avatar' class="w-64 h-64 rd-50-p111-"></image>
										<view class="flex-y-center fs-26 pl-24">
											<text>还差 <text class="text-red">{{item.count}}</text>人，</text>
											<text class="pl-8">还剩</text>
											<count-down
											:is-day="false"
											tip-text=" "
											day-text=" "
											hour-text=":"
											minute-text=":"
											second-text=" "
											colors="#333"
											dotColor="#333"
											:datatime="item.stop_time">
											</count-down>
										</view>
									</view>
									<view class="w-120 h-56 rd-30rpx bg-red flex-center text--w111-fff fs-24"
										@tap="spellBnt(item)">去拼团</view>
								</view>
							</view>
						</view>
						<!-- 拼团玩法 -->
						<view class="rd-24rpx bg--w111-fff mt-20 pt-32 pl-24 pr-24 pb-32" v-if="type == 3">
							<view class="flex-between-center">
								<text class="fs-30 fw-500 lh-42rpx">拼团玩法</text>
							</view>
							<view class="flex-between-center mt-38 px-28">
								<view class="w-118 flex-col flex-center">
									<view class="w-80 h-80 rd-50-p111- bg-primary-light flex-center mb-24">
										<text class="iconfont icon-ic_user fs-44 text-red"></text>
									</view>
									<text class="fs-26 lh-36rpx">开团/参团</text>
								</view>
								<image class="dot-line" src="../static/arrow.png"></image>
								<view class="w-118 flex-col flex-center">
									<view class="w-80 h-80 rd-50-p111- bg-primary-light flex-center mb-24">
										<text class="iconfont icon-ic_invite fs-44 text-red"></text>
									</view>
									<text class="fs-26 lh-36rpx">邀请好友</text>
								</view>
								<image class="dot-line" src="../static/arrow.png"></image>
								<view class="w-118 flex-col flex-center">
									<view class="w-80 h-80 rd-50-p111- bg-primary-light flex-center mb-24">
										<text class="iconfont icon-ic_box fs-44 text-red"></text>
									</view>
									<text class="fs-26 lh-36rpx">满员发货</text>
								</view>
							</view>
						</view>
						<!-- 评价卡片 -->
						<view class="rd-24rpx bg--w111-fff mt-20 py-32"
							v-if="replyCount"
							@tap="goPage(1,'/pages/goods/goods_comment_list/index?product_id=' + storeInfo.product_id)">
							<view class="px-20 flex-between-center">
								<view>
									<text class="text--w111-333 fs-30 fw-500">评价</text>
									<text class="fs-24 text--w111-666 pl-8">({{replyCount}})</text>
								</view>
								<view class="flex-y-center">
									<text class="fs-28 text-red">99.0%</text>
									<text class="fs-24 text--w111-999 pr-12">好评率</text>
									<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666"></text>
								</view>
							</view>
							<!-- 滑动内容 -->
							<block v-if="replyCount">
								<userEvaluation :reply="reply" @changeLogin="changeLogin" :fromTo="1"></userEvaluation>
							</block>
						</view>
						<!-- 底部操作按钮 -->
						<view class="page_footer bg--w111-fff-s111-80 w-full z-99 fixed-lb pb-safe">
							<view class="w-full h-104 pl-32 pr-20 flex">
								<view class="flex">
									<view class="flex-col flex-center mr-38" @tap="goPage(2,'/pages/index/index')"
										v-if="diyProduct.menuList.includes(3)">
										<text class="iconfont icon-ic_mall fs-40"></text>
										<text class="fs-18">首页</text>
									</view>
									<view class="flex-col flex-center mr-38" @tap="listenerActionSheet"
										v-if="diyProduct.menuList.includes(4)">
										<text class="iconfont icon-ic_transmit1 fs-40"></text>
										<text class="fs-18">分享</text>
									</view>
									<!-- #ifdef MP -->
									<button class="flex-col flex-center mr-38 bg-transparent" hover-class='none' open-type='contact'
										v-if="diyProduct.menuList.includes(0) && routineContact">
										<text class="iconfont icon-ic_customerservice fs-40"></text>
										<text class="fs-18">客服</text>
									</button>
									<button class="flex-col flex-center mr-38 bg-transparent" hover-class='none'
										v-else-if="diyProduct.menuList.includes(0) && !routineContact" @tap="goGoodCall">
										<text class="iconfont icon-ic_customerservice fs-40"></text>
										<text class="fs-18">客服</text>
									</button>
									<!-- #endif -->
									<!-- #ifdef H5 || APP-PLUS -->
									<view class="flex-col flex-center mr-38" v-if="diyProduct.menuList.includes(0)"
										@tap="goGoodCall">
										<text class="iconfont icon-ic_customerservice fs-40"></text>
										<text class="fs-18">客服</text>
									</view>
									<!-- #endif -->
									<view class="flex-col flex-center mr-38" @tap="setCollect" v-if="diyProduct.menuList.includes(1)">
										<text class="iconfont fs-40" :class="storeInfo.userCollect ? 'icon-ic_star1' : 'icon-ic_star'"></text>
										<text class="fs-18">收藏</text>
									</view>
									<view class="flex-col flex-center mr-38" @tap="goPage(2,'/pages/order_addcart/order_addcart')"
										v-if="diyProduct.menuList.includes(2)">
										<text class="iconfont icon-ic_ShoppingCart fs-40"></text>
										<text class="fs-18 white-nowrap">购物车</text>
									</view>
								</view>
								<!-- 秒杀下单按钮 -->
								<view class="flex-1 grid-column-2 self-center grid-gap-16rpx" v-if="type == 1">
									<view class="h-72 flex-center join_cart rd-36px text--w111-fff fs-26" @tap="openAlone">单独购买</view>
									<view class="h-72 flex-center bg-red rd-36px text--w111-fff fs-26"
										v-if="status == 1 && attribute.productSelect.quota > 0 && attribute.productSelect.stock > 0" @tap="goCat">立即购买</view>
									<view class="h-72 flex-center bg-hui rd-36px text--w111-fff fs-26"
										v-if="[1,3].includes(status) && (attribute.productSelect.quota <= 0 || attribute.productSelect.stock <= 0)">已售罄</view>
									<view  class="h-72 flex-center bg-hui rd-36px text--w111-fff fs-26"
										v-if="[0,2].includes(status)">{{status == 2 ? '未开始' : '已结束'}}</view>
								</view>
								<!-- 拼团下单按钮 -->
								<view class="flex-1 grid-column-2 self-center grid-gap-16rpx" v-if="type == 3">
									<view class="h-72 flex-center join_cart rd-36px text--w111-fff fs-26" @tap="openAlone">单独购买</view>
									<view class="h-72 flex-center bg-red rd-36px text--w111-fff fs-26"
										v-if=" attribute.productSelect.quota > 0 && attribute.productSelect.stock > 0" @tap="goCat">立即开团</view>
									<view class="h-72 flex-center bg-hui rd-36px text--w111-fff fs-26"
										v-if="attribute.productSelect.quota <= 0 || attribute.productSelect.stock <= 0">已售罄</view>
								</view>
								<!-- 积分下单按钮 -->
								<view class="flex-between-center flex-1" v-if="type == 4">
									<view class="w-460 h-72 flex-center bg-red rd-36px text--w111-fff fs-26"
									v-if="attribute.productSelect.quota > 0 && attribute.productSelect.stock > 0" @tap="goCat">立即兑换</view>
									<view class="w-460 h-72 flex-center bg-hui rd-36px text--w111-fff fs-26"
										v-else>已售罄</view>
								</view>
								<!-- 预售按钮 -->
								<view class="flex-between-center flex-1" v-if="type == 6">
									<view class="w-460 h-72 flex-center bg-hui rd-36px text--w111-fff fs-26"
										v-if="[1,3].includes(storeInfo.presale_pay_status)">{{storeInfo.presale_pay_status === 1?'未开始':'已结束'}}</view>
									<view class="w-460 h-72 flex-center bg-red rd-36px text--w111-fff fs-26"
									v-else-if="attribute.productSelect.stock > 0" @tap="goCat">立即购买</view>
									<view class="w-460 h-72 flex-center bg-hui rd-36px text--w111-fff fs-26"
										v-else>已售罄</view>
								</view>
								<!-- 新人专享按钮 -->
								<view class="flex-between-center flex-1" v-if="type == 7">
									<view class="w-460 h-72 flex-center bg-red rd-36px text--w111-fff fs-26"
									v-if="attribute.productSelect.stock > 0" @tap="goCat">立即购买</view>
									<view class="w-460 h-72 flex-center bg-hui rd-36px text--w111-fff fs-26"
										v-else>已售罄</view>
								</view>
							</view>
						</view>
						<view class="rd-24rpx bg--w111-fff mt-20" id="past3">
							<view class="fs-30 lh-40rpx fw-500 flex-center py-32">商品详情</view>
							<view class="conter">
								<!-- #ifndef APP-PLUS -->
								<jyf-parser :html="storeInfo.description" ref="article" :tag-style="tagStyle"></jyf-parser>
								<!-- #endif -->
								<!-- #ifdef APP-PLUS -->
								<view v-html="storeInfo.description"></view>
								<!-- #endif -->
							</view>
						</view>
						<view class="pb-safe h-200"></view>
						<!-- sku弹窗 -->
						<product-window
						ref='productWindow'
						:attr='attribute'
						:limitNum="1"
						:cusPreviewImg="1"
						:storeInfo="storeInfo"
						:productId="(type==1 || type == 6)?storeInfo.id:storeInfo.product_id"
						:type="type"
						isExtends
						:storeId='storeId'
						@myevent="onMyEvent"
						@ChangeAttr="ChangeAttr"
						@ChangeCartNum="ChangeCartNum"
						@attrVal="attrVal"
						@iptCartNum="iptCartNum"
						@getImg="showImg"
						@deliveryFun="deliveryFun"
						@onAddressId="onAddressId"
						@onstoreId="onstoreId"
						@storeCount="storeCount"
						@onConfirm="onConfirm"></product-window>
						<cusPreviewImg ref="cusPreviewImg" :list="skuArr" @changeSwitch="changeSwitch"></cusPreviewImg>
						<!-- #ifdef H5 || APP-PLUS -->
						<zb-code ref="qrcode" :show="codeShow" :cid="cid" :val="codeVal" :size="size" :unit="unit"
							:background="background" :foreground="foreground" :pdground="pdground" :icon="codeIcon"
							:iconSize="iconsize" :onval="onval" :loadMake="loadMake" @result="qrR" />
						<!-- #endif -->
						<!-- 分享按钮 -->
						<view class="generate-posters" :class="posters ? 'on' : ''">
							<view class="generateCon acea-row row-middle">
								<!-- #ifdef H5 -->
								<button class="item" hover-class="none" v-if="weixinStatus === true"
									@tap="H5ShareBox = true">
									<view class="pictrue">
										<image src="../../../static/images/weixin.png"></image>
									</view>
									<view class="">分享给好友1</view>
								</button>
								<!-- #endif -->
								<!-- #ifdef MP -->
								<button class="item" open-type="share" hover-class="none">
									<view class="pictrue">
										<image src="../../../static/images/weixin.png"></image>
									</view>
									<view class="">分享给好友</view>
								</button>
								<!-- #endif -->
								<!-- #ifdef APP-PLUS -->
								<view class="item" @tap="appShare('WXSceneSession')">
									<view class="pictrue">
										<image src="../../../static/images/weixin.png"></image>
									</view>
									<view class="">分享给好友</view>
								</view>
								<view class="item" @tap="appShare('WXSceneTimeline')">
									<view class="pictrue">
										<image src="../static/weixinCircle.png"></image>
									</view>
									<view class="">分享朋友圈</view>
								</view>
								<!-- #endif -->
								<view class="item" @tap="getpreviewImage">
									<view class="pictrue">
										<image src="../../../static/images/changan.png"></image>
									</view>
									<view class="">预览发图</view>
								</view>
								<!-- #ifndef H5  -->
								<button class="item" hover-class="none" @tap="savePosterPath">
									<view class="pictrue">
										<image src="../../../static/images/haibao.png"></image>
									</view>
									<view class="">保存海报</view>
								</button>
								<!-- #endif -->
							</view>
							<view class="generateClose flex-center" @tap="posterImageClose">取消</view>
						</view>
						<div class="fixed-center" v-if="posters && !posterImageStatus">
							<image class="poster-loading" :src="imgHost + '/statics/images/poster_loading.png'" mode="widthFix" ></image>
						</div>
						<!-- 海报展示 -->
						<view class="poster-mask" v-if="posterImageStatus || posters"></view>
						<view class="poster-pop" v-if="posterImageStatus">
							<image :src="posterImage"></image>
						</view>
						<canvas class="canvas" canvas-id="myCanvas" v-if="canvasStatus"></canvas>
						<!-- 发送给朋友图片 -->
						<view class="fixed-lt w-full h-full z-1000" v-if="H5ShareBox">
							<image class="w-full h-full" :src="imgHost + '/statics/images/share-info.png'" @tap="H5ShareBox = false">
							</image>
						</view>
					</view>
				</view>
			</view>
		</view>
		<specs :specsInfo="specsInfo" @myevent="mySpecs"></specs>
		<base-drawer mode="bottom" :visible="showServiceDrawer" background-color="transparent" mask maskClosable @close="()=>{showServiceDrawer = false}">
		  <view class="w-full bg--w111-fff rd-t-40rpx py-32">
		    <view class="text-center fs-32 text--w111-333 fw-500">服务</view>
		    <view class="mt-64 px-32">
		      <view class="mb-38" v-for="(item,index) in storeInfo.ensure" :key="index">
		        <view class="flex-y-center">
		          <image class="w-34 h-34" :src="item.image"></image>
		          <text class="pl-12 text--w111-333 fs-28 fw-500">{{item.name}}</text>
		        </view>
		        <view class="mt-6 pl-40 fs-22 text--w111-999"> {{item.desc}} </view>
		      </view>
		    </view>
		    <view class="mx-20 pb-safe">
		      <view class="mt-52 h-72 flex-center rd-36px bg-red fs-26 text--w111-fff" @tap="showServiceDrawer = false">确定</view>
		    </view>
		  </view>
		</base-drawer>
	</view>
</template>

<script>
	const app = getApp();
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import zbCode from '@/components/zb-code/zb-code.vue'
	import {
		mapGetters
	} from "vuex";
	import {
		getSeckillDetail,
		activityCodeApi,
		seckillQRCode,
		getCombinationDetail,
		getIntegralProductDetail
	} from '@/api/activity.js';
	import {
		postCartAdd,
		collectAdd,
		collectDel,
		newcomerDetail,
		getProductDetail
	} from '@/api/store.js';
	import specs from '@/components/specs/index.vue';
	import productConSwiper from '@/components/productConSwiper/index.vue'
	import productWindow from '@/components/productWindow/index.vue'
	import userEvaluation from '@/components/userEvaluation/index.vue'
	import kefuIcon from '@/components/kefuIcon';
	import cusPreviewImg from '@/components/cusPreviewImg';
	import homeList from '@/components/homeList'
	import parser from "@/components/jyf-parser/jyf-parser";
	import countDown from '@/components/countDown';
	import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
	import { imageBase64 } from "@/api/public";
	import { toLogin } from '@/libs/login.js';
	import { silenceBindingSpread } from "@/utils";
	import { getUserInfo } from '@/api/user.js';
	import { TOKENNAME, HTTP_REQUEST_URL } from '@/config/app.js';
	import {Debounce} from '@/utils/validate.js'
	export default {
		computed:{
			infoCardBg(){
				if(this.type == 1){
					return 'url('+this.imgHost+'/statics/images/product/seckill_background.png'+')'
				}else if(this.type == 3){
					return 'url('+this.imgHost+'/statics/images/product/pink_background.png'+')'
				}else if(this.type == 6){
					return 'url('+this.imgHost+'/statics/images/product/presell_background.png'+')'
				}
			},
			navBarStyle(){
				return {
					background: this.pageScrollStatus ? '#fff' : 'transparent',
					paddingTop: (this.sysHeight) * 2 + 'rpx'
				}
			},
			menuBgStyle(){
				// #ifdef MP
				let res = wx.getMenuButtonBoundingClientRect();
				return {
					height: (res.height+10) + 'px',
				}
				// #endif
				// #ifndef MP
				return {
					height: 45 + 'px',
				}
				// #endif
			},
			// #ifdef MP
			shareButtonStyle(){
				let res = wx.getMenuButtonBoundingClientRect();
				return {
					width: res.height + 'px',
					height: res.height + 'px',
					top: res.top + 'px',
					left: (res.left - 38) + 'px'
				}
			},
			menuButtonStyle(){
				let res = wx.getMenuButtonBoundingClientRect();
				return {
					width: res.width + 'px',
					height: res.height + 'px',
					top: res.top + 'px',
					left: '11px',
					borderRadius: res.height + 'px',
				}
			},
			// #endif
			...mapGetters(['isLogin','diyProduct']),
		},
		data() {
			return {
				colorStyle:'--view-theme: #e93323;--view-priceColor:#e93323;--view-minorColorT:rgba(233, 51, 35, 0.1);--view-bntColor:#FE960F;',
				showSkeleton: true, //骨架屏显示隐藏
				isNodes: 0, //控制什么时候开始抓取元素节点,只要数值改变就重新抓取
				dataShow: 0,
				id: 0,
				time: 0,
				countDownHour: "00",
				countDownMinute: "00",
				countDownSecond: "00",
				storeInfo: {
					brand_name:''
				},
				attribute: {
					cartAttr: false,
					productAttr: [],
					productSelect: {},
					deliveryType:[],
					isType:0,
					relation_id:0
				},
				productValue: [],
				isOpen: false,
				attr: '请选择',
				attrValue: '',
				status: 1,
				isAuto: false,
				isShowAuth: false,
				iShidden: false,
				limitNum: 1, //限制本属性产品的个数；
				iSplus: false,
				replyCount: 0, //总评论数量
				reply: [], //评论列表
				replyChance: 0,
				navH: "",
				opacity: 0,
				scrollY: 0,
				topArr: [],
				toView: '',
				height: 0,
				heightArr: [],
				lock: false,
				scrollTop: 0,
				tagStyle: {
					img: 'width:100%;display:block;',
					table: 'width:100%',
					video: 'width:100%'
				},
				datatime: 0,
				navActive: 0,
				posters: false,
				weixinStatus: false,
				posterImageStatus: false,
				canvasStatus: false, //海报绘图标签
				storeImage: '', //海报产品图
				PromotionCode: '', //二维码图片
				posterImage: '', //海报路径
				posterbackgd: '/static/images/posterbackgd.png',
				actionSheetHidden: false,
				cart_num: '',
				homeTop: 20,
				returnShow: true,
				H5ShareBox: false, //公众号分享图片
				routineContact: 0,
				siteName:'', //商城名称
				themeColor:'#e93323',
				fontColor:'#e93323',
				skuArr:[],
				//二维码参数
				codeShow: false,
				cid: '1',
				codeVal: "", // 要生成的二维码值
				size: 200, // 二维码大小
				unit: 'upx', // 单位
				background: '#FFF', // 背景色
				foreground: '#000', // 前景色
				pdground: '#000', // 角标色
				codeIcon: '', // 二维码图标
				iconsize: 40, // 二维码图标大小
				lv: 3, // 二维码容错级别 ， 一般不用设置，默认就行
				onval: true, // val值变化时自动重新生成二维码
				loadMake: true, // 组件加载完成后自动生成二维码
				base64Show: 0,
				shareQrcode: 0,
				followCode:'',
				selectSku:{},
				currentPage:false,
				sysHeight: sysHeight,
				isMention: false, //是否存在到店核销
				imgHost:HTTP_REQUEST_URL,
				addressId:'',
				store_id :'',
				delivery_type:0,
				store_name:'',
				storeList:{},
				posterTitle:'',
				good_list:[],
				pageScrollStatus:false,
				showServiceDrawer: false,
				time_id: '',
        spid:0,
				type: '', //活动类型
				itemNew: [],
				pink_ok_sum: 0,
				pink: [],
				promotions_type:0,
				isSwiper: true, //默认展示轮播图
				productSwiper: [], //轮播图复制空间
				productVideoLink:'',
				isDown: false,
				specsInfo: {
					show: false,
					specs: [],
					specsTitle:''
				},
				showSkuBox:false,
				storeId:0, //跳转门店列表切换时获取门店id
				shopType: uni.getStorageSync('shop_operation_type'), //1：多门店+平台；2：单门店+平台；3、单门店
				belongShow: uni.getStorageSync('belongShow'),
				attrPicIndex: 0,
				scrollToView: 0,
				storeShow:true,
				pageHide: false
			}
		},
		components: {
			zbCode,
			productConSwiper,
			'productWindow': productWindow,
			userEvaluation,
			kefuIcon,
			cusPreviewImg,
			"jyf-parser": parser,
			countDown,
			homeList,
			baseDrawer,
			specs
		},
		onLoad(options) {
			let that = this;
      this.spid = options.spid || 0;
			// #ifdef MP
			//扫码携带参数处理
			if (options.scene) {
				let value = this.$util.getUrlParams(decodeURIComponent(options.scene));
				if (value.id) {
					this.id = value.id;
				} else {
					this.showSkeleton = false;
					return this.$util.Tips({
						title: '缺少参数无法查看商品'
					}, {
						tab: 3,
						url: 1
					});
				}
				//记录推广人uid
				if (value.spid) app.globalData.spid = value.spid;
				this.type = value.type;
				this.getActivityDetail();
			}
			// #endif

			if (options.id) {
				this.id = options.id;
				if (options.spid) app.globalData.spid = options.spid;
			}
			if(options.type && !options.scene){
				this.type = options.type;
			}
			this.time_id = options.time_id ? options.time_id : '';
			this.promotions_type = options.promotions_type || 0;
			this.getActivityDetail();
			this.$nextTick(() => {
				// #ifdef MP
				const menuButton = uni.getMenuButtonBoundingClientRect();
				const query = uni.createSelectorQuery().in(this);
				query
					.select('#home')
					.boundingClientRect(data => {
						this.homeTop = menuButton.top * 2 + menuButton.height - data.height;
					})
					.exec();
				// #endif
			})
			if(this.isLogin){
				silenceBindingSpread()
			}
		},
		onReady(){
			this.isNodes++;
			// #ifdef H5
			this.codeVal = window.location.origin + '/pages/activity/goods_details/index?id=' + this.id +
				'&spid=' + this.$store.state.app.uid + '&type=' + this.type
			// #endif
			// #ifdef APP-PLUS
			this.codeVal = HTTP_REQUEST_URL + '/pages/activity/goods_details/index?id=' + this.id +
				'&spid=' + this.$store.state.app.uid + '&type=' + this.type
			// #endif
		},
		onShow(){
			this.pageHide = false;
			uni.removeStorageSync('form_type_cart');
		},
		onHide() {
			this.pageHide = true;
		},
		mounted(){
			uni.$off('activeFn');
			uni.$on('activeFn', data => {
				if(data){
					this.storeList = data;
					this.storeId = data.id;
				}
			})
		},
		methods: {
			mySpecs() {
				this.$set(this.specsInfo, 'show', false);
			},
			// 获取配送方式
			deliveryFun(e){
				this.delivery_type = e;
			},
			// 获取配送地址id
			onAddressId(row){
				this.addressId=row.id
			},
			// 获取到店核销地址id
			onstoreId(row) {
			  this.storeList = row;
			  this.store_id = row.id;
			  this.storeId = row.id;
			  this.store_name = row.name;
			},
			storeCount(n){
				let show = n==1?false:true;
				this.storeShow = show;
			},
			/*
			 * 跳转门店列表
			 */
			showStoreList: function() {
				if(this.shopType !=1 || !this.belongShow || !this.storeShow) return
				let id = this.storeInfo.pid?this.storeInfo.pid:this.storeInfo.id
				uni.navigateTo({
					url:'/pages/store/list/index?type=1&isCollage=1&storeId='+this.storeList.id+'&product_id='+id
				})
			},
			changeLogin(){
				toLogin()
			},
			seeSpecs() {
				this.specsInfo.show = true;
			},
			moreNav(){
				this.currentPage = !this.currentPage
			},
			previewImageOpen(){
				this.showImg();
			},
			//点击sku图片打开轮播图
			showImg(index){
				this.$refs.cusPreviewImg.open(this.selectSku.suk)
			},
			//滑动轮播图选择商品
			changeSwitch(e){
				let productSelect = this.skuArr[e];
				this.$set(this,'selectSku',productSelect);
				var skuList = productSelect.suk.split(',');
				skuList.forEach((i,index)=>{
					this.$set(this.attribute.productAttr[index],'index',skuList[index]);
				})
				if (productSelect) {
					this.$set(this.attribute.productSelect, "image", productSelect.image);
					this.$set(this.attribute.productSelect, "price", productSelect.price);
					this.$set(this.attribute.productSelect, "stock", productSelect.stock);
					this.$set(this.attribute.productSelect, "unique", productSelect.unique);
					this.$set(this.attribute.productSelect, "cart_num", 1);
					this.$set(this.attribute.productSelect, "quota", productSelect.quota);
					this.$set(this.attribute.productSelect, "quota_show", productSelect.quota_show);
					this.$set(this.attribute.productSelect, "ot_price", productSelect.ot_price);
					this.$set(this, "attrValue", productSelect.suk);
					this.attrTxt = "已选择";
					this.attrPicIndex = e;
				}
			},
			qrR(res) {
				// #ifdef H5
				if(!this.$wechat.isWeixin() || this.shareQrcode != '1'){
					this.PromotionCode = res;
					this.followCode = ''
				}
				// #endif
				// #ifdef APP-PLUS
				this.PromotionCode = res;
				// #endif
			},
			// 图片预览；
			getpreviewImage: function() {
				if(this.posterImage){
					let photoList = [];
					photoList.push(this.posterImage)
					uni.previewImage({
						urls: photoList,
						current: this.posterImage
					});
				}else{
					this.$util.Tips({
						title: '您的海报尚未生成'
					});
				}
			},

			// app分享
			// #ifdef APP-PLUS
			appShare(scene) {
				let that = this
				let routes = getCurrentPages(); // 获取当前打开过的页面路由数组
				let curRoute = routes[routes.length - 1].$page.fullPath // 获取当前页面路由，也就是最后一个打开的页面路由
				uni.share({
					provider: "weixin",
					scene: scene,
					type: 0,
					href: `${HTTP_REQUEST_URL}${curRoute}`,
					title: that.storeInfo.title,
					summary: that.storeInfo.info,
					imageUrl: that.storeInfo.small_image,
					success: function(res) {
						uni.showToast({
							title: '分享成功',
							icon: 'success'
						})
					},
					fail: function(err) {
						uni.showToast({
							title: '分享失败',
							icon: 'none',
							duration: 2000
						})
					}
				});
			},
			// #endif
			/**
			 * 购物车手动填写
			 *
			 */
			iptCartNum(e) {
				this.$set(this.attribute.productSelect, 'cart_num', e);
				this.$set(this, "cart_num", e);
			},
			getActivityDetail() {
				let that = this;
				let funApi = '';
				if (that.type == 1) {
					// 秒杀活动
					funApi = getSeckillDetail(that.id, {time_id: that.time_id});
				} else if(that.type == 3){
					// 拼团活动
					funApi = getCombinationDetail(that.id);
				} else if(that.type == 4){
					// 积分兑换（仅限平台商品）
					funApi = getIntegralProductDetail(that.id);
				} else if(that.type == 6){
					//预售商品
					funApi = getProductDetail(that.id);
				}else if(that.type == 7){
					// 新人专享（仅限平台商品））
					funApi = newcomerDetail(that.id)
				}
				funApi.then(res => {
					this.$set(this.attribute, 'isType', res.data.storeInfo.type);
					this.$set(this.attribute, 'relation_id', res.data.storeInfo.relation_id);
					this.dataShow = 1;
					this.storeInfo = res.data.storeInfo;
					this.$set(this,'productSwiper', res.data.storeInfo.slider_image || res.data.storeInfo.images);
					this.$set(this,'productVideoLink',res.data.storeInfo.video_link);
					let specsTitle = [];
					this.storeInfo.specs.forEach(res => {
						specsTitle.push(res.name)
					})
					this.$set(this.specsInfo, 'specsTitle', specsTitle.join(' · '));
					this.$set(this.specsInfo, 'specs', this.storeInfo.specs);
					this.posterTitle = res.data.product_poster_title || '';
					if(this.type == 1){
						this.datatime = Number(res.data.storeInfo.last_time);
						this.status = res.data.storeInfo.status;
					}else if(this.type== 3){
						this.itemNew = res.data.pink_ok_list;
						this.pink_ok_sum = res.data.pink_ok_sum;
						this.pink = res.data.pink;
					}
					this.attribute.productAttr = res.data.productAttr;
					if (res.data.storeInfo.delivery_type.indexOf('2') != -1) {
						that.$set(that, 'isMention', true);
					}
					this.productValue = res.data.productValue;

					for(let key in res.data.productValue){
						let obj = res.data.productValue[key];
						that.skuArr.push(obj)
					}
					that.selectSku = that.skuArr[0];
					res.data.storeInfo.delivery_type.sort((x,y)=>x - y);
					that.$set(that.attribute, 'deliveryType', res.data.storeInfo.delivery_type);
					this.attribute.productSelect.num = res.data.storeInfo.num || 0;
					this.attribute.productSelect.once_num = res.data.storeInfo.once_num || 0;
					this.replyCount = res.data.replyCount;
					this.reply = res.data.reply;
					this.replyChance = res.data.replyChance;
					this.shareQrcode = res.data.share_qrcode;
					that.routineContact = Number(res.data.routine_contact_type);
					that.siteName = res.data.site_name;
					// #ifdef H5
					that.storeImage = that.storeInfo.image;
					if(this.$wechat.isWeixin() && this.shareQrcode=='1'){
						that.downloadFilePromotionCode();
					}else{
					    that.getImageBase64();
					}
					that.setShare();
					// #endif
					if (this.isLogin) {
						// #ifdef MP
						that.downloadFilePromotionCode();
						// #endif
					}
					// #ifndef H5
					that.downloadFilestoreImage();
					// #endif
					that.DefaultSelect();
					that.preloadImage();
					if(this.type == 1){
						app.globalData.openPages = `/pages/activity/goods_details/index?spid=${this.$store.state.app.uid}&id=${this.id}&type=1&time_id=${this.time_id}&spid=${this.storeInfo.uid}`
					}else{
						app.globalData.openPages =  `/pages/activity/goods_details/index?spid=${this.$store.state.app.uid}&id=${this.id}&type=${this.type}`
					}
					that.showSkeleton = false
				}).catch(err => {
					that.$util.Tips({
						title: err
					}, {
						tab: 3
					})
				});
			},
			preloadImage: function () {
				// 预加载海报生成动图
				let that = this;
			    uni.downloadFile({
					url: that.imgHost + '/statics/images/poster_loading.png',
					success: function (res) {
						// 图片下载成功，可以暂存图片路径或进行其他操作
						console.log('Image preloaded:', res.tempFilePath);
					},
			    });
			},
			setShare: function() {
				this.$wechat.isWeixin() &&
					this.$wechat.wechatEvevt([
						"updateAppMessageShareData",
						"updateTimelineShareData",
						"onMenuShareAppMessage",
						"onMenuShareTimeline"
					], {
						desc: this.storeInfo.info,
						title: this.storeInfo.title,
						link: location.href,
						imgUrl: this.storeInfo.image
					}).then(res => {}).catch(err => {});
			},
			/**
			 * 默认选中属性
			 *
			 */
			DefaultSelect: function() {
				let self = this
				let productAttr = self.attribute.productAttr;
				let value = [];

				if(this.type == 6 || this.type == 7){
					for (var key in this.productValue) {
						if (this.productValue[key].stock > 0) {
							value = this.attribute.productAttr.length ? key.split(",") : [];
							break;
						}
					}
				}else{
					for (var key in this.productValue) {
						if (this.productValue[key].quota > 0) {
							value = this.attribute.productAttr.length ? key.split(",") : [];
							break;
						}
					}
				}
				const productValue = Object.values(this.productValue);
				if (!value.length && productValue.length) {
					value = productValue[0].suk.split(",");
				}
				for (let i = 0; i < productAttr.length; i++) {
					this.$set(productAttr[i], "index", value[i]);
				}
				//sort();排序函数:数字-英文-汉字；
				let productSelect = this.productValue[value.join(",")];
				self.$set(self.attribute.productSelect,"store_name",self.storeInfo.title);
				if (productSelect && productAttr.length) {
					self.$set(self.attribute.productSelect, "image", productSelect.image);
					self.$set(self.attribute.productSelect, "price", productSelect.price);
					self.$set(self.attribute.productSelect, "stock", productSelect.stock);
					self.$set(self.attribute.productSelect, "unique", productSelect.unique);
					self.$set(self.attribute.productSelect, "quota", productSelect.quota);
					self.$set(self.attribute.productSelect, "quota_show", productSelect.quota_show);
					self.$set(self.attribute.productSelect, "ot_price", productSelect.ot_price);
					self.$set(self.attribute.productSelect, "cart_num", 1);
					if(this.type == 4){
						this.$set(this.attribute.productSelect, "integral", productSelect.integral);
					}
					self.$set(self, "attrValue", value.join(","));
					self.attrValue = value.join(",")
				} else if (!productSelect && productAttr.length) {
					self.$set(self.attribute.productSelect, "image", self.storeInfo.image);
					self.$set(self.attribute.productSelect, "price", self.storeInfo.price);
					self.$set(self.attribute.productSelect, "quota", 0);
					self.$set(self.attribute.productSelect, "quota_show", 0);
					self.$set(self.attribute.productSelect, "stock", 0);
					self.$set(self.attribute.productSelect, "unique", "");
					self.$set(self.attribute.productSelect, "product_price", 0);
					self.$set(self.attribute.productSelect, "cart_num", 0);
					if(this.type == 4){
						this.$set(this.attribute.productSelect, "integral", this.storeInfo.integral);
					}
					self.$set(self, "attrValue", "");
					self.$set(self, "attrTxt", "请选择");
				} else if (!productSelect && !productAttr.length) {
					self.$set(self.attribute.productSelect, "image", self.storeInfo.image);
					self.$set(self.attribute.productSelect, "price", self.storeInfo.price);
					self.$set(self.attribute.productSelect, "stock", self.storeInfo.stock);
					self.$set(self.attribute.productSelect, "quota", self.storeInfo.quota || 0);
					self.$set(self.attribute.productSelect,"unique",self.storeInfo.unique || "");
					self.$set(self.attribute.productSelect, "cart_num", 1);
					if(this.type == 4){
						this.$set(this.attribute.productSelect, "integral", this.storeInfo.integral);
					}
					self.$set(self, "attrValue", "");
					self.$set(self, "attrTxt", "请选择");
				}
			},
			selecAttr: function() {
				if(!this.isLogin){
					toLogin();
					return
				}
				if((this.storeInfo.delivery_type[0] == 2 || this.storeInfo.delivery_type[0] == 3) && !uni.getStorageSync('refuseLocation')){
					this.$refs.productWindow.showLocation();
				}
				this.currentPage = false;
				this.attribute.cartAttr = true
			},
			onMyEvent: function() {
				this.$set(this.attribute, 'cartAttr', false);
				this.$set(this, 'isOpen', false);
			},
			/**
			 * 购物车数量加和数量减
			 *
			 */
			ChangeCartNum: function(changeValue) {
				if (changeValue) {
					this.attribute.productSelect.cart_num++;
				}else{
					this.attribute.productSelect.cart_num--;
				}
			},
			attrPicIndexs(e){
				this.attrPicIndex = e;
			},
			selectSwiper(){
				this.attrPicIndex = 0;
				this.isSwiper = true;
				this.ChangeAttr(this.skuArr[0].suk);
				this.changeSwitch(0);
				this.$set(this,'productSwiper', this.storeInfo.slider_image || this.storeInfo.images);
				this.$set(this,'productVideoLink',this.storeInfo.video_link);
				this.showSkuBox = false;
			},
			changeAttrPic(index){
				let imgList = []
				this.skuArr.forEach(i=>{
					imgList.push(i.image)
				})
				this.isSwiper = false;
				this.ChangeAttr(this.skuArr[index].suk);
				this.changeSwitch(index);
				this.$set(this,'productVideoLink','');
				this.$set(this,'productSwiper', imgList);
				this.showSkuBox = true;
				this.scrollToView = (index-2)*48;
			},
			attrVal(val) {
				this.attribute.productAttr[val.indexw].index = this.attribute.productAttr[val.indexw].attr_values[val
					.indexn];
			},
			onConfirm(){
				this.$set(this.attribute, 'cartAttr', false);
				this.isOpen = true;
				if(this.cartType == 'cart'){
					this.goCat(undefined,'cart');
				}else{
					this.goCat(true,'buy');
				}
			},
			/**
			 * 属性变动赋值
			 *
			 */
			ChangeAttr: function(res) {
				this.$set(this, 'cart_num', 1);
				let productSelect = this.productValue[res];
				this.$set(this, "selectSku", productSelect);
				if (productSelect) {
					this.$set(this.attribute.productSelect, "image", productSelect.image);
					this.$set(this.attribute.productSelect, "price", productSelect.price);
					this.$set(this.attribute.productSelect, "stock", productSelect.stock);
					this.$set(this.attribute.productSelect, "unique", productSelect.unique);
					this.$set(this.attribute.productSelect, "cart_num", 1);
					this.$set(this.attribute.productSelect, "quota", productSelect.quota);
					this.$set(this.attribute.productSelect, "quota_show", productSelect.quota_show);
					this.$set(this.attribute.productSelect, "ot_price", productSelect.ot_price);
					this.$set(this, "attrValue", res);
					if(this.type == 4){
						this.$set(this.attribute.productSelect, "integral", productSelect.integral);
					}
					this.attrTxt = "已选择";
					this.skuArr.forEach((item,index)=>{
						if(res == item.suk){
							this.attrPicIndex = index
						}
					})
				} else {
					this.$set(this.attribute.productSelect, "image", this.storeInfo.image);
					this.$set(this.attribute.productSelect, "price", this.storeInfo.price);
					this.$set(this.attribute.productSelect, "stock", 0);
					this.$set(this.attribute.productSelect, "unique", "");
					this.$set(this.attribute.productSelect, "cart_num", 0);
					this.$set(this.attribute.productSelect, "quota", 0);
					this.$set(this.attribute.productSelect, "quota_show", 0);
					self.$set(self.attribute.productSelect, "product_price", 0);
					if(this.type == 4){
						this.$set(this.attribute.productSelect, "integral", this.storeInfo.integral);
					}
					this.$set(this, "attrValue", "");
					this.attrTxt = "已选择"

				}
			},
			setCollect: Debounce(function() {
				let that = this;
				if (this.isLogin === false) {
					toLogin();
				} else {
					let that = this;
					if (this.storeInfo.userCollect) {
						collectDel(this.type == 6 ? this.id : this.storeInfo.product_id).then(res => {
							that.$set(that.storeInfo, 'userCollect', !that.storeInfo.userCollect);
							return that.$util.Tips({
								title: res.msg
							});
						});
					} else {
						collectAdd(this.type == 6 ? this.id : this.storeInfo.product_id).then(res => {
							that.$set(that.storeInfo, 'userCollect', !that.storeInfo.userCollect);
							return that.$util.Tips({
								title: res.msg
							});
						});
					}
				}
			}),
			/*
			 *  单独购买
			 */
			openAlone: Debounce(function() {
				uni.navigateTo({
					url: `/pages/goods_details/index?id=${this.storeInfo.product_id}`
				})
			}),
			/*
			 *  下订单
			 */
			goCat: function() {
				if(this.isLogin){
					if((this.storeInfo.delivery_type[0] == 2 || this.storeInfo.delivery_type[0] == 3) && !uni.getStorageSync('refuseLocation')){
					   this.$refs.productWindow.showLocation();
					}
					var that = this;
					that.currentPage = false;
					var productSelect = this.productValue[this.attrValue];
					//打开属性
					if (this.isOpen)
						this.attribute.cartAttr = true
					else
						this.attribute.cartAttr = !this.attribute.cartAttr
					//只有关闭属性弹窗时进行加入购物车
					if (this.attribute.cartAttr === true && this.isOpen == false) return this.isOpen = true
					//如果有属性,没有选择,提示用户选择
					if (this.attribute.productAttr.length && productSelect === undefined && this.isOpen == true) return app
						.$util.Tips({
							title: '请选择属性'
						});
					postCartAdd({
						productId: that.type == 6 ? that.id : that.storeInfo.product_id,
						secKillId: that.type== 1 ? that.id : '', //秒杀活动传值
						combinationId: that.type== 3 ? that.id : '', //拼团活动传值
						storeIntegralId: that.type == 4 ? that.id : '', //积分兑换传值
						newcomerId: that.type == 7 ? that.id : '', //新人专享传值
						cartNum: that.attribute.productSelect.cart_num,
						uniqueId: productSelect !== undefined ? productSelect.unique : '',
						'new': 1,
						store_id:this.delivery_type == 1 ? 0 : this.store_id,
						addressId:this.addressId,
						delivery_type:this.delivery_type
					}).then(res => {
						this.isOpen = false
						let id = that.type == 1?that.id:that.storeInfo.product_id
						uni.navigateTo({
						  url: '/pages/goods/order_confirm/index?new=1&spid='+this.spid+'&cartId=' + res.data.cartId+'&delivery_type='+that.delivery_type+'&addressId='+that.addressId+'&store_id='+(that.delivery_type == 1 ? 0 : that.store_id)+'&store_name='+that.store_name+'&product_id=' + id
						});
					}).catch(err => {
						return this.$util.Tips({
							title: err
						});
					});
				}else{
					toLogin()
				}
			},
			/**
			 * 分享打开
			 *
			 */
			listenerActionSheet: function() {
				this.currentPage = false;
				// #ifdef H5
				if (this.$wechat.isWeixin() === true) {
					this.weixinStatus = true;
				}
				// #endif
				this.posters = true;
				this.goPoster();
			},
			// 分享关闭
			listenerActionClose: function() {
				this.posters = false;
			},
			//隐藏海报
			posterImageClose: function() {
				this.posterImageStatus = false;
				this.posters = false;
			},
			//替换安全域名
			setDomain: function(url) {
				url = url ? url.toString() : '';
				//本地调试打开,生产请注销
				if (url.indexOf("https://") > -1) return url;
				else return url.replace('http://', 'https://');
			},
			//获取海报产品图
			downloadFilestoreImage: function() {
				let that = this;
				uni.downloadFile({
					url: that.setDomain(that.storeInfo.image),
					success: function(res) {
						that.storeImage = res.tempFilePath;
						that.base64Show = 1;
					},
					fail: function() {
						return that.$util.Tips({
							title: ''
						});
						that.storeImage = '';
					},
				});
			},
			/**
			 * 获取产品分销二维码
			 * @param function successFn 下载完成回调
			 *
			 */
			downloadFilePromotionCode: function(successFn) {
				let that = this,data = {};
				if(this.type == 1){
					data = {
						time:this.datatime,
						status:this.status
					};
				}
				activityCodeApi(this.type,this.id, data).then(res => {
					uni.downloadFile({
						// #ifdef H5
						url: that.setDomain(res.data.wechatUrl ? res.data.wechatUrl : ''),
						// #endif
						// #ifdef MP
						url: that.setDomain(res.data.routineUrl ? res.data.routineUrl : ''),
						// #endif
						success: function(res) {
							that.$set(that, 'isDown', false);
							if (typeof successFn == 'function')
								successFn && successFn(res.tempFilePath);
							else
								that.$set(that, 'PromotionCode', res.tempFilePath);
						},
						fail: function() {
							that.$set(that, 'isDown', false);
							that.$set(that, 'PromotionCode', '');
						},
					});
				}).catch(err => {
					that.$set(that, 'isDown', false);
					that.$set(that, 'PromotionCode', '');
				});
			},
			getImageBase64: function(type) {
				let that = this;
				imageBase64(that.storeImage,that.followCode)
					.then(res => {
						that.storeImage = res.data.image;
						if(type){
							that.PromotionCode = res.data.code;
						}
						that.base64Show = 1;
					})
					.catch(() => {});
			},
			/**
			 * 生成海报
			 */
			goPoster: function() {
				let that = this;
				that.$set(that, 'canvasStatus', true);
				let arr2 = [that.posterbackgd, that.storeImage, that.PromotionCode];
				// #ifdef MP
				if(that.PromotionCode=='' && !that.isDown){
					return that.$util.Tips({
						title: '小程序二维码需要发布正式版后才能获取到'
					},function(){
						that.posters = false;
					});
				}
				// #endif
				if (that.isDown) return that.$util.Tips({
					title: '正在下载海报,请稍后再试！'
				},function(){
					that.posters = false;
				});

				uni.getImageInfo({
					src: that.PromotionCode,
					success() {
						let storeName = that.type == 6 || that.type == 7 ? that.storeInfo.store_name : that.storeInfo.title;
						let otPrice = that.type == 6?that.storeInfo.ot_price:that.storeInfo.product_price;
						if (arr2[2] == '') {
							//海报二维码不存在则从新下载
							that.downloadFilePromotionCode(function(msgPromotionCode) {
								arr2[2] = msgPromotionCode;
								if (arr2[2] == '')
									return that.$util.Tips({
										title: '海报二维码生成失败！'
									});
								that.$nextTick(()=>{
									setTimeout(()=>{
										that.$util.PosterCanvas(that.fontColor, that.themeColor, that.siteName, arr2, storeName,
										that.storeInfo.price, otPrice,that.posterTitle,
											function(tempFilePath) {
												that.$set(that, 'posterImage', tempFilePath);
												that.$set(that, 'posterImageStatus', true);
												that.$set(that, 'canvasStatus', false);
												that.$set(that, 'actionSheetHidden', !that
													.actionSheetHidden);
											});
									},1000)
								})
							});

						} else {
							//生成推广海报
							that.$nextTick(function(){
								setTimeout(()=>{
									that.$util.PosterCanvas(that.fontColor, that.themeColor, that.siteName, arr2, storeName, that.storeInfo.price, otPrice,that.posterTitle,
										function(tempFilePath) {
											that.$set(that, 'posterImage', tempFilePath);
											that.$set(that, 'posterImageStatus', true);
											that.$set(that, 'canvasStatus', false);
											that.$set(that, 'actionSheetHidden', !that.actionSheetHidden);
										});
								},1000)
							})
						}
					},
				});
			},

			/*
			 * 保存到手机相册
			 */
			// #ifdef MP
			savePosterPath: function() {
				let that = this;
				uni.getSetting({
					success(res) {
						if (!res.authSetting['scope.writePhotosAlbum']) {
							uni.authorize({
								scope: 'scope.writePhotosAlbum',
								success() {
									uni.saveImageToPhotosAlbum({
										filePath: that.posterImage,
										success: function(res) {
											that.posterImageClose();
											that.$util.Tips({
												title: '保存成功',
												icon: 'success'
											});
										},
										fail: function(res) {
											that.$util.Tips({
												title: '保存失败'
											});
										}
									})
								}
							})
						} else {
							uni.saveImageToPhotosAlbum({
								filePath: that.posterImage,
								success: function(res) {
									that.posterImageClose();
									that.$util.Tips({
										title: '保存成功',
										icon: 'success'
									});
								},
								fail: function(res) {
									that.$util.Tips({
										title: '保存失败'
									});
								},
							})
						}
					}
				})
			},
			// #endif
			//#ifdef APP-PLUS
			savePosterPath() {
				let that = this
				uni.saveImageToPhotosAlbum({
					filePath: that.posterImage,
					success: function(res) {
						that.posterImageClose();
						that.$util.Tips({
							title: '保存成功',
							icon: 'success'
						});
					},
					fail: function(res) {
						that.$util.Tips({
							title: '保存失败'
						});
					}
				});
			},
			// #endif
			setShareInfoStatus: function() {
				let data = this.storeInfo;
				let href = location.href;
				if (this.$wechat.isWeixin()) {
					this.posters = true;
					getUserInfo().then(res => {
						href =
							href.indexOf("?") === -1 ?
							href + "?spid=" + res.data.uid :
							href + "&spid=" + res.data.uid;

						let configAppMessage = {
							desc: data.store_info,
							title: data.store_name,
							link: href,
							imgUrl: data.image
						};
						this.$wechat.wechatEvevt(["updateAppMessageShareData", "updateTimelineShareData"],
							configAppMessage)
					});
				}
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
					this.$util.backPage();
				}
			},
			spellBnt(item) {
				if (this.isLogin) {
					uni.navigateTo({
						url: '/pages/activity/goods_combination_status/index?id=' + item.id
					})
				} else {
					toLogin()
				}
			},
			goGoodCall() {
				getUserInfo().then(res => {
					let userInfo = res.data;
					let url = `/pages/extension/customer_list/chat?productId=${this.storeInfo.product_id}`;
					let obj = {
						store_name: this.storeInfo.store_name,
						path: `/pages/activity/goods_details/index?id=${this.storeInfo.id}&type=${this.type}`,
						image:this.storeInfo.image
					}
					this.$util.getCustomer(userInfo,url,obj,1)
				})

			},
		},
		onPageScroll(object) {
			this.$set(this,'currentPage',false);
			if (object.scrollTop > 340) {
				this.pageScrollStatus = true;
			} else if (object.scrollTop < 340) {
				this.pageScrollStatus = false;
			}
		},
		//#ifdef MP
		onShareAppMessage() {
			return {
				title: this.storeInfo.title,
				path: app.globalData.openPages,
				imageUrl: this.storeInfo.image
			};
		},
		onShareTimeline() {
			let that = this;
			return {
				title: that.storeInfo.title,
				imageUrl: that.storeInfo.image,
				path: app.globalData.openPages,
			};
		},
		//#endif
	}
</script>

<style lang="scss">
	/deep/uni-video {
		width: 100% !important;
	}

	/deep/video {
		width: 100% !important;
	}
	/deep/ .styleAll{
		display: inline-block;
		min-width: 34rpx;
		height: 36rpx;
		border-radius: 8rpx;
		text-align: center;
		line-height: 36rpx;
		font-size: 28rpx;
		font-family:'Regular';
		padding: 0 2rpx;
	}
	.pink-cell{
		/deep/ .styleAll{
			font-size: 26rpx;
			font-family: unset;
			height: unset;
			line-height: unset;
		}
		/deep/ .timeTxt{
			margin: 0;
		}
	}
	.seckill-card /deep/ .timeTxt{
		padding: 0 6rpx;
	}
	.z-99{
		z-index: 99;
	}
	.menu_box{
		width: 154rpx;
		height: 58rpx;
		border-radius: 29rpx;
		z-index: 999;

	}
	.menu_line{
		width: 1px;
		height: 30rpx;
		background: #B3B3B3;
	}
	.opac{
		background-color: rgba(255,255,255,0.6);
		border: 1rpx solid rgba(0,0,0,0.1);
		justify-content: space-evenly;
	}
	.mirror-image{
		height: 34rpx;
		overflow-y: hidden;
		position: relative;
		&:after{
			content: '';
			width: 100%;
			height: 34rpx;
			position: absolute;
			left: 0;
			top: 0;
			background: rgba(0, 0, 0, 0.2);
		}
		image{
			width: 100%;
			transform: scaleY(-1);
		}
	}
	.info_card {
	  background: linear-gradient(180deg, #ffffff 0%, #ffffff 54%, rgba(255, 255, 255, 0) 100%);
	  margin-top: -32rpx;
	}
	.seckill-card{
		padding: 16rpx 20rpx 0 40rpx !important;
	}
	.activity-card{
		margin-top: -34rpx;
		background-size: cover;
		background-repeat: no-repeat;
		padding: 16rpx 40rpx 0 40rpx;
	}
	.top52{
		margin-top: -52rpx !important;
	}
	.pink-badge{
		min-width: 110rpx;
		height: 48rpx;
		padding: 0 16rpx;
		background-color: rgba(255,255,255,0.9);
		border-radius: 24rpx 0 24rpx 0;
		color: #e93323;
	}
	.text-red{
		color: #e93323;
	}
	.pink-cell ~ .pink-cell{
		margin-top: 64rpx;
	}
	.dot-line{
		width: 76rpx;
		height: 12rpx;
		margin-bottom: 66rpx;
	}
	.bg--w111-484643{
		background: linear-gradient(90deg, #484643 0%, #1F1B17 100%);
	}
	.text--w111-FDDAA4{
		color: #FDDAA4;
	}
	.svip_rd{
		border-radius: 14rpx 0 8rpx 14rpx;
	}
	.break_word {
	  overflow-wrap: break-word;
	  white-space: normal;
	}
	.white-nowrap {
	  white-space: nowrap;
	}
	.w-530{
		width: 530rpx;
	}
	.w-524{
		width: 524rpx;
	}
	.w-76{
		width: 76rpx;
	}
	.active_pic {
	  display: flex;
	  justify-content: center;
	  align-items: center;
	  background-color: #fff;
	  border-radius: 16rpx;
	  border: 1px solid #333;
	  padding: 4rpx;
	  image {
	    width: 68rpx;
	    height: 68rpx;
	    border-radius: 12rpx;
	  }
	}
	.scroll_pic {
		height:80rpx;
	  image {
	    width: 80rpx;
	    height: 80rpx;
	    border-radius: 16rpx;
	  }
	}
	.icon-ic_star1{
		color: #e93323;
	}
	.font-red{
		color: #e93323;
	}
	.bg-red{
		background-color: #e93323;
	}
	.join_cart{
		background-color: #FAAD14;
	}
	.bg-primary-light{
		background: rgba(233, 51, 35, 0.1);
	}
	.recommend_pro ~ .recommend_pro{
		margin-left: 38rpx;
	}
	.page_footer{
		backdrop-filter: blur(10px);
		border-top: 1px solid #f5f5f5;
	}
	.bg-transparent{
		background: transparent;
	}

	.generate-posters {
		width: 100%;
		background-color: #fff;
		position: fixed;
		left: 0;
		bottom: 0;
		z-index: 388;
		transform: translate3d(0, 100%, 0);
		transition: all 0.3s cubic-bezier(0.25, 0.5, 0.5, 0.9);
		border-top: 1rpx solid #eee;
		border-radius: 20rpx 20rpx 0 0;

		.generateCon {
			height: 220rpx;
		}

		.generateClose {
			width: 654rpx;
			height: 72rpx;
			background: #F5F5F5;
			border-radius: 36rpx;
			font-size: 26rpx;
			font-weight: 500;
			margin: 0 auto 40rpx;
		}

		.item {
			.pictrue {
				width: 86rpx;
				height: 86rpx;
				border-radius: 50%;
				margin: 0 auto 12rpx auto;

				image {
					width: 100%;
					height: 100%;
					border-radius: 50%;
				}
			}
		}
	}

	.generate-posters.on {
		transform: translate3d(0, 0, 0);
	}

	.generate-posters .item {
		flex: 1;
		text-align: center;
		font-size: 24rpx;
	}
	.canvas {
		z-index: 300;
		width: 750px;
		height: 1300px;
		position: relative;
		bottom: -10000rpx;
	}
	.poster-mask{
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background-color: rgba(0,0,0,0.8);
		z-index: 101;
		backdrop-filter: blur(5px);
	}
	.poster-loading{
		position: relative;
		width: 100%;
		z-index: 31;
	}
	.fixed-center{
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		z-index: 102;
	}
	.poster-pop {
		width: 460rpx;
		height: 800rpx;
		position: fixed;
		left: 50%;
		transform: translateX(-50%);
		z-index: 399;
		top: 50%;
		margin-top: -559rpx;
	}

	.poster-pop image {
		width: 100%;
		height: 100%;
		display: block;
		border-radius: 18rpx;
	}

	.poster-pop .close {
		width: 46rpx;
		height: 75rpx;
		position: fixed;
		right: 0;
		top: -73rpx;
		display: block;
	}

	.poster-pop .save-poster {
		background-color: #df2d0a;
		font-size: ：22rpx;
		color: #fff;
		text-align: center;
		height: 76rpx;
		line-height: 76rpx;
		width: 100%;
	}

	.poster-pop .keep {
		color: #fff;
		text-align: center;
		font-size: 25rpx;
		margin-top: 10rpx;
	}
	.conter {
		display: block;
		overflow: auto;
	}

	.conter img {
		display: block;
	}
	.notice .swiper {
		width: 360rpx;
		overflow: hidden;
		margin-left: 8rpx;
	}

	.notice .swiper swiper {
		height: 30rpx;
		width: 100%;
		overflow: hidden;
		font-size: 24rpx;
		color: #282828;
	}
	.SemiBold{
		font-family: 'SemiBold';
	}
	.border-bottom{
		border-bottom: 1rpx solid #eee;
	}
</style>
