<template>
	<view :style="colorStyle" :data-clipboard-text="storeInfo.command_word" id="copy">
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
							<view class="iconfont icon-ic_left fs-34 text-w111-000" @tap="backTap"></view>
							<text class="menu_line"></text>
							<view class="iconfont icon-ic_menu3 fs-32 text-w111-000" @tap="moreNav"></view>
						</view>
						<!-- #ifdef MP -->
						<view class="fixed rd-50-p111- flex-center opac"
							:style="[shareButtonStyle]"
							@tap="listenerActionSheet"
							v-show="diyProduct.openShare">
						<!-- #endif -->
						<!-- #ifndef MP -->
						<view class="w-60 h-60 rd-50-p111- flex-center opac" @tap="listenerActionSheet" v-show="diyProduct.openShare">
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
					class="skeleton-rect"></productConSwiper>
					<view class="mirror-image">
						<image :src="productSwiper[attrPicIndex]"></image>
					</view>
					<!-- 氛围图 -->
					<view class="atmosphere-card rd-t-40rpx relative w-full h-152 flex justify-between"
						:style="{backgroundImage:cardBg}" v-if="activityBg">
						<view class="flex items-baseline text--w111-fff pt-32 pl-40">
							<baseMoney :money="computedPrice.deduction.pay_price"
							symbolSize="32"
							integerSize="48"
							decimalSize="32"
							color="#ffffff"
							weight></baseMoney>
							<view class="fs-26 text--w111-fff pl-12"> 售价{{ attr.productSelect.price }} </view>
							<view class="fs-26 text--w111-fff pl-12" v-if="showVipPrice">会员价¥{{storeInfo.vip_price}}</view>
						</view>
					</view>
					<!-- 商品介绍卡片 -->
					<view class="info_card rd-40rpx relative">
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
								<view class="flex mb-32" v-if="storeInfo.spec_type">
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
								<!-- 价格 -->
								<view class="flex-y-center" v-if="!activityBg">
									<view class="flex items-baseline">
										<view class="flex items-baseline" v-if="computedPrice.deduction.pay_price < computedPrice.deduction.sum_price">
											<baseMoney
												:money="computedPrice.deduction.pay_price"
												symbolSize="32"
												integerSize="48"
												preFix="到手价"
												preFixSize="22"
												textColor="var(--view-theme)"
												decimalSize="32" weight></baseMoney>
											<view class="fs-22 text--w111-3d3d3d pl-12"> 售价{{ attr.productSelect.price }} </view>
										</view>
										<baseMoney
										    v-else
											:money="attr.productSelect.price"
											symbolSize="32"
											integerSize="48"
											preFixSize="22"
											textColor="var(--view-theme)"
											decimalSize="32" weight></baseMoney>
										<view class="svip-badge acea-row row-middle" v-if="showVipPrice">
											<view class="h-26 svip_rd fs-18 bg--w111-484643 text--w111-FDDAA4 px-8 acea-row row-middle">SVIP</view>
											<view class="px-8 Regular fw-600 acea-row row-middle">¥{{attr.productSelect.vip_price}}</view>
										</view>
									</view>
								</view>
								<!-- 活动标签 -->
								<view class="flex-between-center mt-24" v-if="discountInfo.discount.length || coupon.list.length || computedPrice.deduction.vip_price > 0">
									<scroll-view
										scroll-x="true"
										show-scrollbar="false"
										class="white-nowrap vertical-middle w-600">
										<view class="inline-block h-44 rd-8rpx text-center lh-44rpx fs-22 text-primary-con px-8 bg-primary-light mr-16"
											v-for="(item,index) in discountInfo.discount" :key="index + 'i'">{{item.desc}}</view>
										<view
											class="inline-block h-44 rd-8rpx text-center lh-44rpx fs-22 text-primary-con px-8 bg-primary-light mr-16"
											v-for="(item,index) in coupon.list" :key="index + 'k'"
											>
											<view v-if="item.coupon_type == 1">
												<template v-if="item.use_min_price>0">满{{ item.use_min_price }}减{{item.coupon_price}}</template>
												<template v-else>无门槛立减{{item.coupon_price}}</template>
											</view>
											<view v-else>
												<template v-if="item.use_min_price>0">满{{ item.use_min_price }}打{{ parseFloat(item.coupon_price)/10 }}折</template>
												<template v-else>无门槛立打{{ parseFloat(item.coupon_price)/10 }}折</template>
											</view>
										</view>
										<view
											class="inline-block h-44 rd-8rpx text-center lh-44rpx fs-22 text-primary-con px-8 bg-primary-light mr-16"
											v-if="computedPrice.deduction.vip_price > 0"
											>会员优惠</view>
									</scroll-view>
									<view class="fs-22 text-primary-con" @tap="openPerferentDrawer">查看 <text
											class="iconfont icon-ic_rightarrow fs-24"></text> </view>
								</view>
								<!-- 商品名称 -->
								<view class="mt-20 fs-30 text--w111-333 fw-500">
									<text v-if="storeInfo.brand_name" class="brand-tag">{{ storeInfo.brand_name }}</text>{{  storeInfo.store_name }}
								</view>
								<!-- 库存销量 -->
								<view class="flex-between-center mt-24 text--w111-999 fs-22 lh-30rpx">
									<text v-show="diyProduct.isOpen.includes(0)"><text class="line-through">￥{{  attr.productSelect.ot_price }}</text></text>
									<text v-show="diyProduct.isOpen.includes(2) && storeInfo.product_type !=6">库存：{{  attr.productSelect.stock }}{{  storeInfo.unit_name }}</text>
									<text v-show="diyProduct.isOpen.includes(1)">销量：{{  storeInfo.fsales || 0 }}{{  storeInfo.unit_name || ''}}</text>
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
						<view class="px-20">
							<!-- SVIP card -->
							<view class="h-80 rd-16rpx bg--w111-FFF0D1 flex-between-center mt-24 px-20"
								v-if="showVip">
								<view class="flex-y-center">
									<image src="@/static/img/vip_leval.png" class="w-36 h-36"></image>
									<view class="pl-8">
										<text class="fs-24 text--w111-7E4B06">开通 SVIP会员 预计省</text>
										<text class="text-primary-con fs-28 lh-40rpx px-4">{{diff}}</text>
										<text class="fs-24 text--w111-7E4B06">元</text>
									</view>
								</view>
								<view class="fs-24 text--w111-7E4B06" @tap="goPage(1,'/pages/annex/vip_paid/index')">
									<text>立即开通</text>
									<text class="iconfont icon-ic_rightarrow fs-24"></text>
								</view>
							</view>
							<!-- 排行榜 card -->
							<view class="h-80 rd-16rpx bg--w111-fff flex-between-center mt-24 px-20"
								@tap="goPage(1,'/pages/columnGoods/rank/index?type=' + storeInfo.rank_type)"
								v-if="storeInfo.rank > 0 && diyProduct.showRank">
								<view class="flex-y-center">
									<image :src="imgHost + '/statics/images/product/cup_icon.png'" class="w-32 h-32"></image>
									<image :src="imgHost + '/statics/images/product/rank_icon.png'" class="w-76 h-24 mx-8"></image>
									<text class="fs-26 text--w111-333">{{storeInfo.rank_type | rankType}}·第{{storeInfo.rank}}名</text>
								</view>
								<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666"></text>
							</view>
						</view>
					</view>
					<view class="px-20">
						<!-- 分销信息 -->
						<view class="rd-16rpx bg--w111-fff mt-20 px-20" v-if="brokerageFuncStatus == 1 && diyProduct.showSpread && (!diyProduct.spreadScope || (diyProduct.spreadScope == 1 && userInfo.is_promoter) || (diyProduct.spreadScope == 2 && !userInfo.is_promoter)) && storeInfo.is_brokerage">
							<view class="flex-between-center pt-20 pb-20" @tap="brokerageShare">
								<view class="flex-center fs-26">
									<image :src="`${imgHost}/statics/images/product/product_diy_spread_Integral.png`" mode="" class="w-28 h-28 mr-6"></image>
									<template v-if="isLogin">
										分享好友最高可赚
										<text class="px-4 fw-500 text-primary-con">¥{{ sharePacket.priceName }}</text>
										元
									</template>
									<template v-else>
										分享好友下单赚取佣金
									</template>
								</view>
								<view class="flex-center w-144 h-50 rd-24rpx bg--w111-423F3C fs-24 text-w111-FFF0D1">
									{{ isLogin ? '立即分享' : '去登录' }}
									<text class="iconfont icon-ic_rightarrow fs-24"></text>
								</view>
							</view>
							<view class="flex justify-around pt-20 pb-20 border-t-EEEEEE b--w111-f5f5f5">
								<view class="flex-col flex-center">
									<image :src="`${imgHost}/statics/images/product/product_diy_spread01.png`" mode="" class="w-80 h-80 mb-20"></image>
									<view class="fs-26 lh-36rpx">立即分享</view>
								</view>
								<view class="flex-center h-80">
									<image :src="`${imgHost}/statics/images/product/product_diy_spread_arrow.png`" mode="" class="w-46 h-10"></image>
								</view>
								<view class="flex-col flex-center">
									<image :src="`${imgHost}/statics/images/product/product_diy_spread02.png`" mode="" class="w-80 h-80 mb-20"></image>
									<view class="fs-26 lh-36rpx">分享海报</view>
								</view>
								<view class="flex-center h-80">
									<image :src="`${imgHost}/statics/images/product/product_diy_spread_arrow.png`" mode="" class="w-46 h-10"></image>
								</view>
								<view class="flex-col flex-center">
									<image :src="`${imgHost}/statics/images/product/product_diy_spread03.png`" mode="" class="w-80 h-80 mb-20"></image>
									<view class="fs-26 lh-36rpx">好友下单</view>
								</view>
								<view class="flex-center h-80">
									<image :src="`${imgHost}/statics/images/product/product_diy_spread_arrow.png`" mode="" class="w-46 h-10"></image>
								</view>
								<view class="flex-col flex-center">
									<image :src="`${imgHost}/statics/images/product/product_diy_spread04.png`" mode="" class="w-80 h-80 mb-20"></image>
									<view class="fs-26 lh-36rpx">佣金到账</view>
								</view>
							</view>
						</view>
						<!-- 活动 SKU选择 服务保障 -->
						<view class="rd-24rpx bg--w111-fff mt-20">
							<view class="flex-y-center h-100 rd-t-24rpx pl-20"
								v-if="activity.length && diyProduct.showService.includes(0)">
								<text class="fs-26 text--w111-888">活动</text>
								<view class="flex-y-center ml-32">
									<block v-for="(item, index) in activity" :key="index">
										<view
											class="inline-block px-16 h-48 lh-48rpx rd-24px bg-primary-light text-primary-con mr-16"
											v-if="item.type === '3'" @tap="goActivity(item)">
											<text class="iconfont icon-ic_user fs-28"></text>
											<text class="fs-24 pl-4">{{item.people || 2}}人团</text>
											<text class="iconfont icon-ic_rightarrow fs-24"></text>
										</view>
										<view
											class="inline-block px-16 h-48 lh-48rpx rd-24px bg-primary-light text-primary-con mr-16"
											v-if="item.type === '1'" @tap="goActivity(item)">
											<text class="iconfont icon-ic_clock fs-28"></text>
											<text class="fs-24 pl-4">限时秒杀</text>
											<text class="iconfont icon-ic_rightarrow fs-24"></text>
										</view>
										<view
											class="inline-block px-16 h-48 lh-48rpx rd-24px bg-primary-light text-primary-con mr-16"
											v-if="item.type === '2'" @tap="goActivity(item)">
											<text class="iconfont icon-ic_sale fs-28"></text>
											<text class="fs-24 pl-4">参与砍价</text>
											<text class="iconfont icon-ic_rightarrow fs-24"></text>
										</view>
									</block>
								</view>
							</view>
							<view class="flex-between-center h-100 px-20" @tap="selecAttr"
								v-if="attr.productAttr.length && diyProduct.showService.includes(1)">
								<view class="flex-y-center">
									<text class="fs-26 text--w111-888">选择</text>
									<view class="ml-32 text--w111-333 fs-26 w-560 line1">{{ attrValue }}</view>
								</view>
								<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666"></text>
							</view>
							<view class="flex-between-center h-100 px-20"
								v-if="storeInfo.ensure && storeInfo.ensure.length && diyProduct.showService.includes(2)"
								@tap="()=>{showServiceDrawer = true}">
								<view class="flex-y-center">
									<text class="fs-26 text--w111-888">服务</text>
									<view class="ml-32 text--w111-333 fs-26 w-524 line1">{{ensureInfo.ensureTitle}}</view>
								</view>
								<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666"></text>
							</view>
							<view class="flex-between-center h-100 px-20"
								v-if="storeInfo.specs && storeInfo.specs.length && diyProduct.showService.includes(3)"
								@tap="seeSpecs">
								<view class="flex-y-center">
									<text class="fs-26 text--w111-888">参数</text>
									<view class="ml-32 text--w111-333 fs-26 w-524 line1">{{specsInfo.specsTitle}}</view>
								</view>
								<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666"></text>
							</view>
							<view class="acea-row row-between h-146 pl-20 pr-20 pt-30" v-if="isMention && Object.keys(storeList).length && diyProduct.showService.includes(4)" @tap="showStoreList">
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
						<view class="rd-24rpx bg--w111-fff mt-20 py-32" v-if="storeInfo.product_type == 5">
							<view class="pl-20 fs-30 fw-500 text--w111-333">卡项权益</view>
							<view class="pr-14 pl-20">
								<navigator v-for="item in cardRelatedVisible" :key="item.id" :url="`/pages/goods_details/index?id=${item.product_id}`" hover-class="none" class="flex-y-center p-16 rd-12rpx mt-20 bg--w111-f5f5f5 fs-22 text--w111-333">
									<image :src="item.productInfo.attrInfo.image" mode="" class="w-96 h-96 rd-8rpx"></image>
									<view class="pr-16 pl-16 flex-1 min-w-0">
										<view class="fs-26 line1">{{ item.productInfo.store_name }}</view>
										<view class="mt-14 text--w111-999">{{ item.productInfo.attrInfo.suk }}</view>
									</view>
									<view class="text-right mr-4">
										<view class="">{{ item.write_times }}次</view>
										<view class="mt-14">价值 <text class="text-primary-con">¥{{ $util.$h.Mul(item.price, item.write_times) }}</text></view>
									</view>
									<text class="iconfont icon-ic_rightarrow ml-8 fs-24 text--w111-666"></text>
								</navigator>
							</view>
							<view class="flex-center mt-20" v-if="cardRelated.length > 3">
								<view class="fs-22 text--w111-999" @click="toggleExpand">
									{{ isExpanded ? '收起' : '展开' }}
									<text :class="['iconfont ml-4 fs-24', isExpanded ? 'icon-ic_uparrow' : 'icon-ic_downarrow']"></text>
								</view>
							</view>
						</view>
						<!-- 评价卡片 -->
						<view class="rd-24rpx bg--w111-fff mt-20 py-32"
							v-if="replyCount && diyProduct.showReply"
							@tap="goPage(1,'/pages/goods/goods_comment_list/index?product_id=' + id)">
							<view class="px-20 flex-between-center">
								<view>
									<text class="text--w111-333 fs-30 fw-500">评价</text>
									<text class="fs-24 text--w111-666 pl-8">({{replyCount}})</text>
								</view>
								<view class="flex-y-center">
									<text class="fs-28 text-primary-con">{{replyChance}}%</text>
									<text class="fs-24 text--w111-999 pr-12">好评率</text>
									<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666"></text>
								</view>
							</view>
							<!-- 滑动内容 -->
							<block v-if="replyCount">
								<userEvaluation :reply="reply" @changeLogin="changeLogin" :fromTo="1"></userEvaluation>
							</block>
						</view>
						<!-- 种草秀 -->
						<view class="rd-24rpx bg--w111-fff mt-20 py-30" v-if="communityCount && diyProduct.showCommunity">
							<navigator :url="`/pages/discover/goodsDiscover/index?id=${id}`" hover-class="none" class="px-20 flex-between-center">
								<view class="">
									<text class="text--w111-333 fs-30 fw-500">种草秀</text>
									<text class="fs-24 text--w111-666 pl-8">({{ communityCount }})</text>
								</view>
								<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666"></text>
							</navigator>
							<scroll-view scroll-x="true" show-scrollbar="false" class="white-nowrap vertical-middle w-690 mt-24 pl-24">
								<navigator :url="`/pages/discover/discoverVideo/index?id=${item.id}`" hover-class="none" class="inline-block mr-22" v-for="item in communityList" :key="item.id">
									<view class="flex">
										<image :src="item.image" mode="aspectFill" class="w-206 h-206 rd-16rpx"></image>
									</view>
								</navigator>
							</scroll-view>
						</view>
						<!-- 搭配购 -->
						<view class="rd-24rpx bg--w111-fff mt-20 py-30" v-if="discountsData.length && diyProduct.showMatch"
							@tap="goPage(1,'/pages/goods/match_pay/index?id=' + id)">
							<view class="px-20 flex-between-center">
								<view>
									<text class="text--w111-333 fs-30 fw-500">搭配购</text>
									<text class="fs-24 text--w111-666 pl-8">({{discountsData.length}})</text>
								</view>
								<text class="iconfont icon-ic_rightarrow fs-24 text--w111-666"></text>
							</view>
							<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-690 mt-24 pl-20"
								show-scrollbar="false">
								<view class="inline-block mr-20" v-for="item in discountsData" :key="item.id">
									<view class="flex">
										<image class="w-164 h-164 rd-16rpx bg-w111-f9f9f9" :src="item.image" mode="aspectFit"></image>
										<view class="flex-1 pl-24">
											<view class="w-278 text--w111-333 fs-26 break_word line1">{{item.title}}</view>
											<view class="w-278 mt-20 text-center lh-30rpx break_word fs-22 text--w111-999 line2">
												<text v-for="(items,index) in item.products" :key="index">{{items.store_name}} +</text>
											</view>
											<view class="flex-y-center fs-20 mt-20" v-if="item.max_discounts_price > 0">
												<text class="iconfont icon-a-ic_Money111 font-num fs-28"></text>
												<text class="text--w111-999 px-4">最多可省</text>
												<text class="font-color fs-24 fw-600">¥{{item.max_discounts_price}}</text>
											</view>
										</view>
									</view>
								</view>
							</scroll-view>
						</view>
						<!-- 优品推荐 -->
						<view class="rd-24rpx bg--w111-fff mt-20 py-32" v-if="good_list.length && diyProduct.showRecommend">
							<view class="pl-20 fs-30 fw-500 text--w111-333">优品推荐</view>
							<view class="grid-column-3 grid-gap-x-38rpx grid-gap-y-26rpx px-20 mt-28" v-if="good_list.length <= 6">
								<view v-for="(item, index) in good_list" :key="index"
									@tap="goPage(1,'/pages/goods_details/index?id=' + item.id)">
									<image class="w-198 h-198 rd-20rpx bg-w111-f9f9f9" :src="item.image" mode="aspectFit"></image>
									<view class="w-full line1 fs-26 lh-26rpx pt-16">{{ item.store_name }}</view>
									<view class="text-primary-con pt-14 fs-28 lh-28rpx fw-600">¥{{ item.price }}</view>
								</view>
							</view>
							<scroll-view scroll-x="true" class="w-690 mt-24 pl-20" show-scrollbar="false" v-else>
								<view class="white-nowrap vertical-middle">
									<view class="inline-block recommend_pro" v-for="(item,index) in good_list" :key="index"
										v-if="(index%2)==0" @tap="goPage(1,'/pages/goods_details/index?id=' + item.id)">
										<image class="w-198 h-198 rd-20rpx bg-w111-f9f9f9" :src="item.image" mode="aspectFit">
										</image>
										<view class="w-198 line1 fs-26 lh-26rpx pt-16 text--w111-333">{{item.store_name}}</view>
										<view class="text-primary-con pt-14 fs-28 lh-28rpx fw-600">¥{{item.price}}</view>
									</view>
								</view>
								<view class="white-nowrap vertical-middle mt-24">
									<view class="inline-block recommend_pro" v-for="(item,index) in good_list" :key="index"
										v-if="(index%2)!=0" @tap="goPage(1,'/pages/goods_details/index?id=' + item.id)">
										<image class="w-198 h-198 rd-20rpx" :src="item.image">
										</image>
										<view class="w-198 line1 fs-26 lh-26rpx pt-16 text--w111-333">{{item.store_name}}</view>
										<view class="text-primary-con pt-14 fs-28 lh-28rpx fw-600">¥{{item.price}}</view>
									</view>
								</view>
							</scroll-view>
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
									<view class="flex-col flex-center mr-38 animated relative"
										:class="m36f2e == true ? 'bounceIn' : ''"
										@tap="goPage(2,'/pages/order_addcart/order_addcart')"
										v-if="diyProduct.menuList.includes(2)">
										<text class="iconfont icon-ic_ShoppingCart fs-40"></text>
										<text class="num-badge bg-color" v-if="parseFloat(CartCount) > 0">{{ CartCount || 0 }}</text>
										<text class="fs-18">购物车</text>
									</view>
								</view>
								<view class="flex-1 flex-y-center" v-if="storeInfo.product_type == 6 || storeInfo.product_type == 5">
									<view v-if="storeInfo.stock>0 && storeInfo.product_type == 6 && mobileReservationOpen" @tap="selecAttr" class="w-full h-72 flex-center bg-color rd-36px text--w111-fff fs-26">立即预约</view>
									<view v-else-if="storeInfo.stock>0" @tap="selecAttr" class="w-full h-72 flex-center bg-color rd-36px text--w111-fff fs-26">立即购买</view>
									<view v-else class="w-full h-72 flex-center bg-hui rd-36px text--w111-fff fs-26">已售罄</view>
								</view>
								<view class="flex-1 grid-column-2 self-center grid-gap-16rpx"
									v-else-if="attr.productSelect.stock <= 0 && storeInfo.cart_button && diyProduct.showCart">
										<!-- 无库存允许加入购物车并且可视化开启了购物车 -->
									<view class="w-full h-72 flex-center join_cart rd-36px text--w111-fff fs-26" @tap="joinCart">加入购物车</view>
									<!-- 平台没有可以从门店购买 -->
									<view v-if="is_store_buy || delivery_type == 2 || delivery_type == 3" @tap="goBuy" class="w-full h-72 flex-center bg-color rd-36px text--w111-fff fs-26">门店购买</view>
									<view v-else class="w-full h-72 flex-center bg-hui rd-36px text--w111-fff fs-26">已售罄</view>
								</view>
								<view class="flex-1 flex-y-center"
									v-else-if="attr.productSelect.stock <= 0 && storeInfo.cart_button && !diyProduct.showCart">
										<!-- 无库存允许加入购物车并且可视化关闭了购物车 -->
										<!-- 平台没有可以从门店购买 -->
									<view v-if="is_store_buy || delivery_type == 2 || delivery_type == 3" @tap="goBuy" class="w-full h-72 flex-center bg-color rd-36px text--w111-fff fs-26">门店购买</view>
									<view v-else class="w-full h-72 flex-center bg-hui rd-36px text--w111-fff fs-26">已售罄</view>
								</view>
								<view class="flex-1 grid-column-2 self-center grid-gap-16rpx"
									v-else-if="attr.productSelect.stock >0 && storeInfo.cart_button && diyProduct.showCart">
									<!-- 有库存允许加入购物车并且可视化开启了购物车 -->
									<view class="w-full h-72 flex-center join_cart rd-36px text--w111-fff fs-26" @tap="joinCart">加入购物车</view>
									<view class="w-full h-72 flex-center bg-color rd-36px text--w111-fff fs-26"
										@tap="goBuy">{{is_store_buy || delivery_type == 2 || delivery_type == 3?'门店购买':'立即购买'}}</view>
								</view>
								<view class="flex-1 flex-y-center" v-else>
									<!-- 有库存不允许加入购物车并且可视化关闭了购物车 -->
									<view class="w-full h-72 flex-center bg-color rd-36px text--w111-fff fs-26"
										@tap="goBuy">{{is_store_buy || delivery_type == 2 || delivery_type == 3?'门店购买':'立即购买'}}</view>
								</view>
							</view>
						</view>
						<view class="rd-24rpx bg--w111-fff mt-20" id="past3">
							<view class="fs-30 fw-500 lh-40rpx flex-center py-32">商品详情</view>
							<view class="conter">
								<!-- #ifndef APP-PLUS -->
								<jyf-parser :html="description" ref="article" :tag-style="tagStyle"></jyf-parser>
								<!-- #endif -->
								<!-- #ifdef APP-PLUS -->
								<view v-html="description"></view>
								<!-- #endif -->
							</view>
						</view>
						<view class="pb-safe h-148"></view>
						<!-- 服务抽屉 -->
						<service-modal :visible="showServiceDrawer" :ensureInfo="ensureInfo" @closeDrawer="()=>{showServiceDrawer = false}"></service-modal>
						<!-- 优惠弹窗 -->
						<preferential-modal
							:visible="showPerferentDrawer"
							:discountInfo="discountInfo"
							:coupon="coupon"
							:computedPrice="computedPrice"
							@ChangCouponsUseState="ChangCouponsUseState"
							@closeDrawer="()=>{showPerferentDrawer = false}"
							></preferential-modal>
						<!-- sku弹窗 -->
						<productWindow
						ref='productWindow'
						:attr="attr"
						:isShow="1"
						:iSplus="1"
						:type="0"
						isExtends
						:storeInfo="storeInfo"
						:productId="storeInfo.id"
						:isStoreBuy="is_store_buy"
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
						@onConfirm="onConfirm" id="product-window"></productWindow>
						<specs :specsInfo="specsInfo" @myevent="mySpecs"></specs>
						<cusPreviewImg ref="cusPreviewImg" :list="skuArr" @changeSwitch="changeSwitch"></cusPreviewImg>
						<!-- #ifdef H5 || APP-PLUS -->
						<zb-code ref="qrcode" :show="codeShow" :cid="cid" :val="codeVal" :size="size" :unit="unit"
							:background="background" :foreground="foreground" :pdground="pdground" :icon="codeIcon"
							:iconSize="iconsize" :onval="onval" :loadMake="loadMake" @result="qrR" />
						<!-- #endif -->
						<!-- 分享按钮 -->
						<view class="m888edb9 pb-safe" :class="posters ? 'on' : ''">
							<view class="generateCon acea-row row-middle">
								<!-- #ifdef H5 -->
								<button class="item" hover-class="none" v-if="weixinStatus === true"
									@click="H5ShareBox = true">
									<view class="pictrue">
										<image src="../../static/images/weixin.png"></image>
									</view>
									<view class="">分享给好友</view>
								</button>
								<!-- #endif -->
								<!-- #ifdef MP -->
								<button class="item" open-type="share" hover-class="none">
									<view class="pictrue">
										<image src="../../static/images/weixin.png"></image>
									</view>
									<view class="">分享给好友</view>
								</button>
								<!-- #endif -->
								<!-- #ifdef APP-PLUS -->
								<view class="item" @tap="appShare('WXSceneSession')">
									<view class="pictrue">
										<image src="../../static/images/weixin.png"></image>
									</view>
									<view class="">分享给好友</view>
								</view>
								<view class="item" @tap="appShare('WXSceneTimeline')">
									<view class="pictrue">
										<image src="./static/weixinCircle.png"></image>
									</view>
									<view class="">分享朋友圈</view>
								</view>
								<!-- #endif -->
								<view class="item" @tap="getpreviewImage">
									<view class="pictrue">
										<image src="../../static/images/changan.png"></image>
									</view>
									<view class="">预览发图</view>
								</view>
								<!-- #ifndef H5  -->
								<button class="item" hover-class="none" @tap="savePosterPath">
									<view class="pictrue">
										<image src="../../static/images/haibao.png"></image>
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
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import zbCode from '@/components/zb-code/zb-code.vue'
	import {
		getProductDetail,
		getProductCtivity,
		getProductRecommend,
		getProductCode,
		collectAdd,
		collectDel,
		postCartAdd,
		getProductStoreDetail,
		productCardRelated
	} from '@/api/store.js';
	import {
		getUserInfo,
		userShare
	} from '@/api/user.js';
	import { getCoupons,setCouponReceive } from '@/api/api.js';
	import { getCartCounts } from '@/api/order.js';
	import { toLogin } from '@/libs/login.js';
	import { mapGetters, mapState } from 'vuex';
	import { imageBase64 } from '@/api/public';
	import countDown from '@/components/countDown';
	import productConSwiper from '@/components/productConSwiper';
	import productWindow from '@/components/productWindow';
	import specs from '@/components/specs/index.vue';
	import userEvaluation from '@/components/userEvaluation';
	import kefuIcon from '@/components/kefuIcon';
	import cusPreviewImg from '@/components/cusPreviewImg';
	import homeList from '@/components/homeList';
	import serviceModal from './components/serviceModal/index.vue';
	import preferentialModal from '@/components/preferentialModal/index.vue';
	import { silenceBindingSpread } from '@/utils';
	import {
		TOKENNAME,
		HTTP_REQUEST_URL
	} from '@/config/app.js';
	import {
		Debounce
	} from '@/utils/validate.js'
	import { isMobileReservationOpen } from '@/utils/mobileReservation.js';
	let app = getApp();
	import colors from "@/mixins/color";
	// #ifdef H5
	import ClipboardJS from '@/plugin/clipboard/clipboard.js';
	// #endif
	export default {
		components: {
			zbCode,
			productConSwiper,
			productWindow,
			userEvaluation,
			kefuIcon,
			cusPreviewImg,
			specs,
			countDown,
			homeList,
			serviceModal,
			preferentialModal
		},
		directives: {
			trigger: {
				inserted(el, binging) {
					el.click();
				}
			}
		},
		mixins: [colors],
		data() {
			let that = this;
			return {
				showSkeleton: true, //骨架屏显示隐藏
				isNodes: 0, //控制什么时候开始抓取元素节点,只要数值改变就重新抓取
				//属性是否打开
				coupon: {
					type: -1,
					list: [],
					count: []
				},
				attrTxt: '请选择', //属性页面提示
				attrValue: '', //已选属性
				m36f2e: false, //购物车动画
				id: 0, //商品id
				replyCount: 0, //总评论数量
				reply: [], //评论列表
				storeInfo: {
					brand_name: ''
				}, //商品详情
				productValue: [], //系统属性
				cart_num: 1, //购买数量
				isOpen: false, //是否打开属性组件
				actionSheetHidden: true,
				posterImageStatus: false,
				storeImage: '', //海报产品图
				PromotionCode: '', //二维码图片
				canvasStatus: false, //海报绘图标签
				posterImage: '', //海报路径
				posterbackgd: '/static/images/posterbackgd.png',
				sharePacket: {
					isState: true //默认不显示
				}, //分销商详细
				uid: 0, //用户uid
				circular: false,
				autoplay: false,
				interval: 3000,
				duration: 500,
				good_list: [],
				replyChance: 0,
				CartCount: 0,
				isDown: true,
				posters: false,
				weixinStatus: false,
				ensureInfo: {
					show: false,
					ensure: [],
					ensureTitle: ''
				},
				specsInfo: {
					show: false,
					specs: [],
					specsTitle:''
				},
				discountInfo: {
					show: false,
					discount: []
				},
				attr: {
					cartAttr: false,
					productAttr: [],
					productSelect: {},
					deliveryType: [],
					isType: 0,
					relation_id: 0
				},
				limitInfo: {
					discount_price: 0,
					price: 0,
					discount: 1,
					datatime: 0
				},
				promotions_type: 0,
				description: '',
				H5ShareBox: false, //公众号分享图片
				activity: [],
				lock: false,
				scrollTop: 0,
				tagStyle: {
					img: 'width:100%;display:block;',
					table: 'width:100%',
					video: 'width:100%'
				},
				returnShow: true, //判断顶部返回是否出现
				diff: '',
				is_money_level: 1,
				routineContact: 0,
				discountsData: [], //套餐数据
				siteName: '', //商城名称
				themeColor: '',
				fontColor: '',
				isMention: false, //是否存在到店核销
				showAnimate: true,
				skuArr: [],
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
				followCode: '',
				selectSku: {},
				currentPage: false,
				sysHeight: sysHeight,
				isShow: 0,
				imgHost: HTTP_REQUEST_URL,
				fromType: 0, //判断是否回退分类二和三刷新
				cartNum: 0,
				addressId: '',
				store_id: '',
				store_name: '',
				delivery_type: 0,
				is_store_buy:1, //判断平台是否有库存（0：平台有库存；1：平台没有库存）
				storeList:{},
				activityBg: '',
				posterTitle: '',
				showServiceDrawer:false,
				showPerferentDrawer:false,
				pageScrollStatus:false,
				cartType:'',
				computedPrice:{
					deduction:{
						pay_price:0,
						sum_price:0,
						vip_price:0
					}
				},
				isSwiper: true, //默认展示轮播图
				productSwiper: [], //轮播图复制空间
				productVideoLink:'', //轮播图视频链接复制空间
				userInfo: {},
				showSkuBox:false,
				storeId:0, //跳转门店列表切换时获取门店id
				shopType: uni.getStorageSync('shop_operation_type'), //1：多门店+平台；2：单门店+平台；3、单门店
				belongShow: uni.getStorageSync('belongShow'),
				attrPicIndex: 0,
				scrollToView: 0,
				cardRelated: [],
				isExpanded: false,
				communityCount: 0,
				communityList: [],
				storeShow:true,
				pageHide: false
			};
		},
		filters:{
			rankType(val){
				let obj = {
					1:'销量排行榜',
					2:'好评排行榜',
					3:'收藏排行榜'
				};
				return obj[val]
			}
		},
		computed:{
			...mapGetters(['isLogin','diyProduct']),
			mobileReservationOpen() {
				return isMobileReservationOpen();
			},
			...mapState({
				brokerageFuncStatus: state => state.app.brokerageFuncStatus,
				storeBrokerageStatu: state => state.app.storeBrokerageStatu,
			}),
			showVip(){
				return !this.is_money_level && Number(this.storeInfo.vip_price)>0 && this.storeInfo.price_type == 'member' && this.diyProduct.showSvip
			},
			cardBg(){
				return `url(${this.activityBg})`
			},
			showVipPrice(){
				if(Number(this.storeInfo.vip_price) > 0 && this.storeInfo.price_type == 'member'){
					return true
				}
			},
			computedPayPrice(){
				return this.computedPrice.deduction.pay_price
			},
			navBarStyle(){
				return {
					background: this.pageScrollStatus ? '#fff' : 'transparent',
					paddingTop: (this.sysHeight) * 2 + 'rpx'
				}
			},
			cardRelatedVisible() {
				if (this.isExpanded) {
					return this.cardRelated;
				} else {
					return this.cardRelated.slice(0, 3);
				}
			},
			// #ifndef MP
			menuBgStyle(){
				return {
					height: 45 + 'px',
				}
			},
			// #endif
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
			menuBgStyle(){
				let res = wx.getMenuButtonBoundingClientRect();
				return {
					height: (res.height+10) + 'px',
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
			}
			// #endif
		},
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV == true) {
						this.getCartCount();
					}
				},
				deep: true
			},
		},
		onLoad(options) {
			let that = this;
			var pages = getCurrentPages();
			that.returnShow = pages.length === 1 ? false : true;
			that.id = options.id;
			that.isShow = options.isShow;
			that.fromType = options.fromType;
			//扫码携带参数处理
			// #ifdef MP
			if (options.scene) {
				let value = that.$util.getUrlParams(decodeURIComponent(options.scene));
				if (value.id) options.id = value.id;
				//记录推广人uid
				if (value.spid) app.globalData.spid = value.spid;
			}
			if (!options.id) {
				this.showSkeleton = false;
				return that.$util.Tips({
					title: '缺少参数无法查看商品'
				}, {
					tab: 3,
					url: 1
				});
			} else {
				that.id = options.id;
			}
			if (that.isLogin && options.id) {
				that.downloadFilePromotionCode();
			}
			// #endif
			//记录推广人uid
			if (options.spid) app.globalData.spid = options.spid;
			// that.getGoodsDetails();
			// #ifdef H5
			that.isLogin && silenceBindingSpread();
			// #endif
			this.colorData();
		},
		onReady: function() {
			this.isNodes++;
			// #ifdef H5
			this.codeVal = window.location.origin + '/pages/goods_details/index?id=' + this.id +
				'&spid=' + this.$store.state.app.uid
			// #endif
			// #ifdef APP-PLUS
			this.codeVal = HTTP_REQUEST_URL + '/pages/goods_details/index?id=' + this.id +
				'&spid=' + this.$store.state.app.uid
			// #endif
		},
		onShow() {
			this.pageHide = false;
			this.skuArr = [];
			this.getGoodsDetails();
			if (this.storeLists) {
				this.storeList = this.storeLists;
				this.storeLists = null;
			}
		},
		onHide() {
			this.pageHide = true;
		},
		mounted(){
			uni.$off('activeFn');
			uni.$on('activeFn', data => {
				if(data){
					this.storeLists = data;
					this.storeId = data.id;
				}
			})
		},
		beforeDestroy() {
			// #ifdef H5
			if (this.clipboard) {
				this.clipboard.destroy();
			}
			// #endif
		},
		/**
		 * 用户点击右上角分享
		 */
		// #ifdef MP
		onShareAppMessage: function() {
			let that = this;
			that.$set(that, 'actionSheetHidden', !that.actionSheetHidden);
			userShare();
			return {
				title: that.storeInfo.store_name || '',
				imageUrl: that.storeInfo.image || '',
				path: '/pages/goods_details/index?id=' + that.id + '&spid=' + that.uid
			};
		},
		onShareTimeline() {
			let that = this;
			that.$set(that, 'actionSheetHidden', !that.actionSheetHidden);
			userShare();
			return {
				title: that.storeInfo.store_name || '',
				imageUrl: that.storeInfo.image || '',
				path: '/pages/goods_details/index?id=' + that.id + '&spid=' + that.uid
			};
		},
		// #endif
		methods: {
			// 获取配送方式
			deliveryFun(e) {
				this.delivery_type = e;
			},
			// 获取配送地址id
			onAddressId(row) {
				this.addressId = row.id
			},
			// 门店详情
			getStoreDetail(id,pid){
				getProductStoreDetail(pid?pid:this.id,{store_id:id}).then(res=>{
					let storeInfo = res.data.storeInfo;
					this.$set(this, 'storeInfo', storeInfo);
					this.$set(this, 'productValue', res.data.productValue);
					let skuArr = []
					for (let key in res.data.productValue) {
						let obj = res.data.productValue[key];
						skuArr.push(obj)
					}
					this.skuArr = skuArr;
					this.$set(this, "selectSku", this.skuArr[0]);
					this.DefaultSelect();
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					});
				})
			},
			// 获取到店核销地址id
			onstoreId(row,flag) {
				this.storeList = row;
				if(flag == 2 || flag == 3){
				   this.store_id = row.id;
				   this.storeId = row.id;
				   this.store_name = row.name;
				   this.getStoreDetail(row.id);
				}else{
				   this.store_id = '';
				   this.store_name = '';
				   this.getStoreDetail(0,this.storeInfo.pid);
				}
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
			changeLogin() {
				toLogin()
			},
			seeEnsure() {
				this.ensureInfo.show = true;
			},
			seeSpecs() {
				this.specsInfo.show = true;
			},
			seeDiscount() {
				this.discountInfo.show = true;
			},
			moreNav() {
				this.currentPage = !this.currentPage
			},
			previewImageOpen(){
				this.showImg();
			},
			//点击sku图片打开轮播图
			showImg(index) {
				this.$refs.cusPreviewImg.open(this.selectSku.suk)
			},
			//滑动轮播图选择商品
			changeSwitch(e) {
				let productSelect = this.skuArr[e];
				this.$set(this, 'selectSku', productSelect);
				var skuList = productSelect.suk.split(',');
				skuList.forEach((i, index) => {
					this.$set(this.attr.productAttr[index], 'index', skuList[index]);
				})
				if (productSelect) {
					this.$set(this.attr.productSelect, 'image', productSelect.image);
					this.$set(this.attr.productSelect, 'price', productSelect.price);
					this.$set(this.attr.productSelect, 'ot_price', productSelect.ot_price);
					this.$set(this.attr.productSelect, 'stock', productSelect.stock);
					this.$set(this.attr.productSelect, 'unique', productSelect.unique);
					this.$set(this.attr.productSelect, 'cart_num', 1);
					this.$set(this.attr.productSelect, 'vip_price', productSelect.vip_price);
					this.$set(this, 'attrValue', productSelect.suk);
					this.$set(this, 'attrTxt', '已选择');
					this.attrPicIndex = e;
				}
			},
			qrR(res) {
				// #ifdef H5
				if (!this.$wechat.isWeixin() || this.shareQrcode != '1') {
					this.PromotionCode = res;
					this.followCode = ''
				}
				// #endif
				// #ifdef APP-PLUS
				this.PromotionCode = res;
				// #endif
			},
			boxStatus(data) {
				this.showAnimate = data
			},
			followWX() {
				getProductCode(this.id).then(res => {
					this.followCode = res.data.code
					this.getImageBase64(1);
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				})
			},
			// 图片预览；
			getpreviewImage: function() {
				if (this.posterImage) {
					let photoList = [];
					photoList.push(this.posterImage)
					uni.previewImage({
						urls: photoList,
						current: this.posterImage
					});
				} else {
					this.$util.Tips({
						title: '您的海报尚未生成'
					});
				}
			},
			videoPause() {

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
					href: `${HTTP_REQUEST_URL}${curRoute}&spid=${that.uid}`,
					title: that.storeInfo.store_name,
					summary: that.storeInfo.store_info,
					imageUrl: that.storeInfo.small_image,
					success: function(res) {
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
			closeChange: function() {
				this.$set(this.sharePacket, 'isState', true);
			},
			goActivity: function(e) {
				let item = e;
				if (item.type == 1) {
					uni.navigateTo({
						url: `/pages/activity/goods_details/index?id=${item.id}&type=1&time=${item.time}&status=1`
					});
				} else if (item.type == 2) {
					uni.navigateTo({
						url: `/pages/activity/goods_bargain_details/index?id=${item.id}&spid=${this.uid}`
					});
				} else {
					uni.navigateTo({
						url: `/pages/activity/goods_details/index?id=${item.id}&type=3`
					});
				}
			},
			/**
			 * 购物车手动填写
			 *
			 */
			iptCartNum: function(e) {
				this.$set(this.attr.productSelect, 'cart_num', e);
			},
			backTap() {
				let pages = getCurrentPages(); // 获取当前打开过的页面路由数，
				let prevPage = pages[pages.length - 2];
				if (pages.length > 1) {
					uni.navigateBack()
				} else {
					uni.switchTab({
						url: '/pages/index/index'
					});
				}
			},
			/*
			 *去商品详情页
			 */
			goDetail(item) {
				if (item.activity.length == 0) {
					uni.redirectTo({
						url: '/pages/goods_details/index?id=' + item.id
					});
					return;
				}
				// 砍价
				if (item.activity && item.activity.type == 2) {
					uni.redirectTo({
						url: `/pages/activity/goods_bargain_details/index?id=${item.activity.id}&spid=${this.uid}`
					});
					return;
				}
				// 拼团
				if (item.activity && item.activity.type == 3) {
					uni.redirectTo({
						url: `/pages/activity/goods_details/index?id=${item.activity.id}&type=3`
					});
					return;
				}
				// 秒杀
				if (item.activity && item.activity.type == 1) {
					uni.redirectTo({
						url: `/pages/activity/goods_details/index?id=${item.activity.id}&type=1&time=${item.activity.time}&status=1`
					});
					return;
				}
			},
			/*
			 * 获取用户信息
			 */
			getUserInfo: function() {
				let that = this;
				getUserInfo().then(res => {
					that.$set(that.sharePacket, 'isState', that.sharePacket.priceName != 0 ? false : true);
					that.$set(that, 'uid', res.data.uid);
					that.$set(that, 'is_money_level', res.data.is_money_level);
					that.userInfo = res.data;
				});
			},
			/**
			 * 购物车数量加和数量减
			 *
			 */
			ChangeCartNum: function(changeValue) {
				//changeValue:是否 加|减
				//获取当前变动属性
				let productSelect = this.productValue[this.attrValue];
				//如果没有属性,赋值给商品默认库存
				if (productSelect === undefined && !this.attr.productAttr.length) productSelect = this.attr
					.productSelect;
				//无属性值即库存为0；不存在加减；
				if (productSelect === undefined) return;
				let stock = productSelect.stock || 0;
				let num = this.attr.productSelect;
				if (changeValue) {
					num.cart_num++;
					if (num.cart_num > stock) {
						this.$set(this.attr.productSelect, 'cart_num', stock ? stock : 1);
						this.$set(this, 'cart_num', stock ? stock : 1);
					}
				} else {
					num.cart_num--;
					if (num.cart_num < 1) {
						this.$set(this.attr.productSelect, 'cart_num', 1);
						this.$set(this, 'cart_num', 1);
					}
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
				this.$set(this,'productSwiper', this.storeInfo.slider_image);
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
				this.$set(this.attr.productAttr[val.indexw], 'index', this.attr.productAttr[val.indexw].attr_values[val
					.indexn]);
			},
			/**
			 * 属性变动赋值
			 *
			 */
			ChangeAttr: function(res) {
				let productSelect = this.productValue[res];
				this.$set(this, "selectSku", productSelect);
				if (productSelect && productSelect.stock >= 0 && productSelect.is_show) {
					this.getGoodsCtivity(productSelect.unique);
					this.$set(this.computedPrice.deduction, 'sum_price', productSelect.price);
					this.$set(this.attr.productSelect, 'image', productSelect.image);
					this.$set(this.attr.productSelect, 'price', productSelect.price);
					this.$set(this.attr.productSelect, 'ot_price', productSelect.ot_price);
					this.$set(this.attr.productSelect, 'stock', productSelect.stock);
					this.$set(this.attr.productSelect, 'unique', productSelect.unique);
					this.$set(this.attr.productSelect, 'cart_num', 1);
					this.$set(this.attr.productSelect, 'vip_price', productSelect.vip_price);
					this.$set(this, 'attrValue', res);
					this.$set(this, 'attrTxt', '已选择');
					this.skuArr.forEach((item,index)=>{
						if(res == item.suk){
							this.attrPicIndex = index
						}
					})
				} else {
					this.getGoodsCtivity(productSelect.unique);
					this.$set(this.attr.productSelect, 'image', this.storeInfo.image);
					this.$set(this.attr.productSelect, 'price', this.storeInfo.price);
					this.$set(this.attr.productSelect, 'ot_price', this.storeInfo.ot_price);
					this.$set(this.attr.productSelect, 'stock', 0);
					this.$set(this.attr.productSelect, 'unique', '');
					this.$set(this.attr.productSelect, 'cart_num', 0);
					this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
					this.$set(this, 'attrValue', '');
					this.$set(this, 'attrTxt', '请选择');
				}
			},
			// 推荐商品
			getGoodsList() {
				let that = this;
				getProductRecommend(that.id).then(res => {
					that.good_list = res.data || [];

				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				})
			},
			// 获取产品活动详情
			getGoodsCtivity(unique) {
				let that = this;
				getProductCtivity(that.id, {
					unique: unique ? unique : '',
					store_id: that.store_id
				}).then(res => {
					that.$set(that, 'activityBg', res.data.activity_background.image || '');
					that.$set(that, 'activity', res.data.activity ? res.data.activity : []);
					that.$set(that, 'discountsData', res.data.discounts_products ? res.data.discounts_products :
					[]); //套餐数据
					let promotions = res.data.promotions[0];
					if (res.data.promotions.length) {
						let discount = that.$util.$h.Div(promotions.discount, 100);
						let discountPrice = that.$util.$h.Mul(discount, this.storeInfo.price);
						that.$set(that.limitInfo, 'price', this.storeInfo.price || 0);
						that.$set(that.limitInfo, 'datatime', promotions.stop_time || 0);
						that.$set(that.limitInfo, 'discount', discount);
						that.$set(that.limitInfo, 'discount_price', discountPrice);
					}
					that.$set(that.discountInfo, 'discount', res.data.promotions);
					that.$set(that.coupon,'list',res.data.coupons);
					that.$set(that,'computedPrice',res.data.computed);
					that.$set(this.attr.productSelect, 'pay_price', res.data.computed.deduction.pay_price);
					that.showSkeleton = false;
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				})

			},
			/**
			 * 获取产品详情
			 *
			 */
			getGoodsDetails: function() {
				let that = this;
				getProductDetail(that.id)
					.then(res => {
						let storeInfo = res.data.storeInfo;
						if(storeInfo.type == 1){
						  if(storeInfo.delivery_type.indexOf('1') !=-1){//在门店首页确保选择门店配送或是到店核销
							  storeInfo.delivery_type.sort((x, y) => x - y);
							  storeInfo.delivery_type.push(storeInfo.delivery_type.shift());
						  }
						}else{
						  storeInfo.delivery_type.sort((x, y) => x - y);
						}
						that.$set(that.attr, 'deliveryType', storeInfo.delivery_type);
						if (res.data.storeInfo.delivery_type.indexOf('2') != -1) {
							that.$set(that, 'isMention', true);
						}
						that.$set(that, 'is_store_buy', res.data.is_store_buy || 0);
						that.$set(that, 'storeInfo', storeInfo);
						that.$set(that.attr, 'isType', res.data.storeInfo.type);
						that.$set(that.attr, 'relation_id', res.data.storeInfo.relation_id);
						that.$set(that,'productSwiper', storeInfo.slider_image);
						that.$set(that,'productVideoLink', storeInfo.video_link);
						that.$set(that.ensureInfo, 'ensure', storeInfo.ensure);
						let ensureTitle = [],specsTitle = [];
						storeInfo.ensure.forEach(res => {
							ensureTitle.push(res.name)
						})
						storeInfo.specs.forEach(res => {
							specsTitle.push(res.name)
						})
						that.$set(that.ensureInfo, 'ensureTitle', ensureTitle.join(' · '));
						that.$set(that.specsInfo, 'specsTitle', specsTitle.join(' · '));
						that.$set(that.specsInfo, 'specs', storeInfo.specs);
						that.$set(that, 'description', storeInfo.description);
						if (this.description) {
							this.description = this.description.replace(/<img/gi,'<img style="max-width:100%;height:auto;float:left;display:block" ');
							this.description = this.description.replace(/<video/gi,'<video style="width:100%;height:auto;display:block" ');
						}
						that.$set(that.attr, 'productAttr', res.data.productAttr);
						that.$set(that, 'productValue', res.data.productValue);
						for (let key in res.data.productValue) {
							let obj = res.data.productValue[key];
							that.skuArr.push(obj)
						}
						if (!that.skuArr.length) {
							that.skuArr = [{
								image: this.storeInfo.image,
								suk: this.storeInfo.store_name,
								price: this.storeInfo.price
							}];
						}
						this.$set(this, "selectSku", that.skuArr[0]);
						that.siteName = res.data.site_name;
						that.posterTitle = res.data.product_poster_title;
						that.$set(that, 'reply', res.data.reply);
						that.$set(that, 'replyCount', res.data.replyCount);
						that.$set(that, 'replyChance', res.data.replyChance);
						that.$set(that, 'communityCount', res.data.elegant_count);
						that.$set(that, 'communityList', res.data.elegant_list);
						that.$set(that.sharePacket, 'priceName', res.data.priceName);
						that.$set(that, 'shareQrcode', res.data.share_qrcode);
						that.$set(that, 'routineContact', Number(res.data.routine_contact_type));
						uni.setNavigationBarTitle({
							title: storeInfo.store_name.substring(0, 13) + '...'
						});
						that.$set(that, 'diff', that.$util.$h.Sub(storeInfo.price, storeInfo.vip_price));
						that.getGoodsList();
						that.getGoodsCtivity();
						// #ifdef H5
						that.$set(that, 'storeImage', that.storeInfo.image);
						that.ShareInfo();
						if (this.$wechat.isWeixin() && this.shareQrcode == '1') {
							that.followWX();
						} else {
							that.getImageBase64();
						}
						// #endif
						if (that.isLogin) {
							that.getUserInfo();
						}
						// #ifndef H5
						that.downloadFilestoreImage();
						// #endif
						that.DefaultSelect();
						that.getCartCount();
						that.preloadImage();
						if (storeInfo.command_word) {
							// #ifdef MP
							uni.setClipboardData({
								data: storeInfo.command_word,
								showToast: false,
								success: () => {
									uni.hideToast()
								},
								fail: () => {}
							});
							// #endif
							// #ifdef H5
							that.clipboard = new ClipboardJS('#copy');
							// #endif
						}
						if (storeInfo.product_type == 5) {
							that.productCardRelated();
						}
					}).catch(err => {
						//状态异常返回上级页面
						return that.$util.Tips({
							title: err.toString()
						}, {
							tab: 3,
							url: 1
						});
						this.showSkeleton = false
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
			/**
			 * 默认选中属性
			 *
			 */
			DefaultSelect: function() {
				let productAttr = this.attr.productAttr;
				let valueobj = [];
				let value = [];
				let productValue = Object.values(this.productValue).filter((item) => {
					return item.stock;
				});
				// 考虑有库存的默认选中
				let productValueDefault = productValue.find((item) => {
					return item.is_default_select;
				});
				// 不考虑有无库存的默认选中
				let productDefault = [],isDefault = true;
				if(Object.keys(this.productValue).length){
				    let obj = Object.values(this.productValue).find((item) => {
						return item.is_default_select;
					});
					productDefault = obj?obj:[],
					isDefault =  obj?true:false
				}
				if (productValueDefault) {
					value = productValueDefault.suk.split(',');
				} else if (productValue.length) {
					value = productValue[0].suk.split(',');
				}else{
					if(productDefault.length){
						productDefault.suk.split(',')
					}else{
						if(isDefault){
							value = []
						}else{
							value = Object.values(this.productValue)[0].suk.split(',');
						}
					}
				}
				for (let i = 0; i < productAttr.length; i++) {
					this.$set(productAttr[i], 'index', value[i]);
				}
				//sort();排序函数:数字-英文-汉字；
				let productSelect = this.productValue[value.join(',')];
				this.$set(this.attr.productSelect, 'store_name', this.storeInfo.store_name);
				if (productSelect && productAttr.length) {
					this.getGoodsCtivity(productSelect.unique);
					this.$set(this.attr.productSelect, 'image', productSelect.image);
					this.$set(this.attr.productSelect, 'price', productSelect.price);
					this.$set(this.attr.productSelect, 'ot_price', productSelect.ot_price);
					this.$set(this.attr.productSelect, 'stock', productSelect.stock);
					this.$set(this.attr.productSelect, 'unique', productSelect.unique);
					this.$set(this.attr.productSelect, 'cart_num', 1);
					this.$set(this, 'attrValue', value.join(','));
					this.$set(this.attr.productSelect, 'vip_price', productSelect.vip_price);
					this.$set(this, 'attrTxt', '已选择');
				} else if (!productSelect && productAttr.length) {
					this.getGoodsCtivity();
					this.$set(this.attr.productSelect, 'image', this.storeInfo.image);
					this.$set(this.attr.productSelect, 'price', this.storeInfo.price);
					this.$set(this.attr.productSelect, 'ot_price', this.storeInfo.ot_price);
					this.$set(this.attr.productSelect, 'stock', 0);
					this.$set(this.attr.productSelect, 'unique', '');
					this.$set(this.attr.productSelect, 'cart_num', 0);
					this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
					this.$set(this, 'attrValue', '');
					this.$set(this, 'attrTxt', '请选择');
				} else if (!productSelect && !productAttr.length) {
					this.getGoodsCtivity();
					this.$set(this.attr.productSelect, 'image', this.storeInfo.image);
					this.$set(this.attr.productSelect, 'price', this.storeInfo.price);
					this.$set(this.attr.productSelect, 'stock', this.storeInfo.stock);
					this.$set(this.attr.productSelect, 'ot_price', this.storeInfo.ot_price);
					this.$set(this.attr.productSelect, 'unique', this.storeInfo.unique || '');
					this.$set(this.attr.productSelect, 'cart_num', 1);
					this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
					this.$set(this, 'attrValue', '');
					this.$set(this, 'attrTxt', '请选择');
				}
			},
			openPerferentDrawer(){
				this.showPerferentDrawer = true;
			},
			/**
			 *
			 *
			 * 收藏商品
			 */
			setCollect: Debounce(function() {
				if (this.isLogin === false) {
					toLogin();
				} else {
					let that = this;
					if (this.storeInfo.userCollect) {
						collectDel(this.storeInfo.id).then(res => {
							that.$set(that.storeInfo, 'userCollect', !that.storeInfo.userCollect);
							return that.$util.Tips({
								title: res.msg
							});
						});
					} else {
						collectAdd(this.storeInfo.id).then(res => {
							that.$set(that.storeInfo, 'userCollect', !that.storeInfo.userCollect);
							return that.$util.Tips({
								title: res.msg
							});
						});
					}
				}
			}),
			/**
			 * 打开属性插件
			 */
			selecAttr: Debounce(function() {
				if(!this.isLogin){
					toLogin();
					return
				}
				if(this.storeInfo.product_type == 6 && this.storeInfo.stock>0 && isMobileReservationOpen()){
					uni.navigateTo({
						url: '/pages/activity/reservation/index?id=' + this.storeInfo.id+'&store_id=' + this.store_id+'&unique='+this.attr.productSelect.unique
					})
					return false
				}
				if((this.storeInfo.delivery_type[0] == 2 || this.storeInfo.delivery_type[0] == 3) && !uni.getStorageSync('refuseLocation')){
					this.$refs.productWindow.showLocation();
				}
				this.currentPage = false;
				this.$set(this.attr, 'cartAttr', true);
				this.$set(this, 'isOpen', true);
			}),
			onMyEvent: function() {
				this.$set(this.attr, 'cartAttr', false);
				this.$set(this, 'isOpen', false);
			},
			myEnsure() {
				this.$set(this.ensureInfo, 'show', false);
			},
			mySpecs() {
				this.$set(this.specsInfo, 'show', false);
			},
			myDiscount() {
				this.$set(this.discountInfo, 'show', false);
			},
			onConfirm(){
				this.$set(this.attr, 'cartAttr', false);
				this.isOpen = true;
				if(this.cartType == 'cart'){
					this.goCat(undefined,'cart');
				}else{
					this.goCat(true,'buy');
				}
			},
			/**
			 * 打开属性加入购物车
			 *
			 */
			joinCart: function(e) {
				this.currentPage = false;
				//是否登录
				if (!this.isLogin) {
					toLogin();
				} else {
					this.goCat(undefined,'cart');
				}
			},
			/*
			 * 加入购物车
			 */
			goCat: function(news,cartType) {
				if((this.storeInfo.delivery_type[0] == 2 || this.storeInfo.delivery_type[0] == 3) && !uni.getStorageSync('refuseLocation')){
				   this.$refs.productWindow.showLocation();
				}
				let that = this,productSelect = that.productValue[this.attrValue];
				this.cartType = cartType;
				//打开属性
				if (that.attrValue) {
					//默认选中了属性，但是没有打开过属性弹窗还是自动打开让用户查看默认选中的属性
					that.attr.cartAttr = !that.isOpen ? true : false;
				} else {
					if (that.isOpen) that.attr.cartAttr = true;
					else that.attr.cartAttr = !that.attr.cartAttr;
				}
				//只有关闭属性弹窗时进行加入购物车
				if (that.attr.cartAttr === true && that.isOpen === false) return (that.isOpen = true);
				//如果有属性,没有选择,提示用户选择
				if (that.attr.productAttr.length && productSelect === undefined && that.isOpen === true)
					return that.$util.Tips({
						title: '产品库存不足，请选择其它属性'
					});
				if (that.attr.productSelect.cart_num <= 0) {
					that.attr.productSelect.cart_num = 1
					that.isOpen = false
					return that.$util.Tips({
						title: '请先选择属性'
					});
				}
				let q = {
					cartNum: that.attr.productSelect.cart_num,
					new: news === undefined ? 0 : 1,
					uniqueId: that.attr.productSelect !== undefined ? that.attr.productSelect.unique : '',
					store_id: this.store_id,
					addressId: this.addressId,
					delivery_type: this.delivery_type,
				};
				if(that.is_store_buy || this.delivery_type == 2 || this.delivery_type == 3){
					q.productId = that.storeInfo.id; //重新调用门店详情后的赋值id
				} else {
					q.productId = that.id;
				}
				let delivery_type = that.delivery_type
				if (that.storeInfo.product_type == 5 && !that.cardRelated.length) {
					return that.$util.Tips({
						title: '该商品无有效的卡项权益',
					});
				}
				postCartAdd(q)
					.then(function(res) {
						that.isOpen = false;
						that.attr.cartAttr = false;
						let url = '/pages/goods/order_confirm/index?new=1&cartId=' + res.data.cartId +
									'&delivery_type=' + delivery_type + '&addressId=' + that.addressId +
									'&store_id=' + that.store_id + '&store_name=' + that.store_name +
									'&product_id=' + that.storeInfo.id + '&is_store=' + (that.store_id ? 1 : 0)
						if (news) {
							//领券下单
							if(that.computedPrice.coupon && that.computedPrice.coupon.id){
								if(!that.computedPrice.coupon.used){
									setCouponReceive(that.computedPrice.coupon.id).then(resp => {
										uni.navigateTo({
											url: url + '&couponId=' + resp.data.id + '&couponTitle=' + that.computedPrice.coupon.coupon_title
										});
									}).catch(err => {
										return that.$util.Tips({
											title: err
										});
									})
								}else{
									uni.navigateTo({
										url: url + '&couponId=' + that.computedPrice.coupon.id + '&couponTitle=' + that.computedPrice.coupon.coupon_title
									});
								}
							}else{
								uni.navigateTo({
									url: url
								});
							}
						} else {
							let obj = {
								id: that.id,
								cartNum: res.data.cartNum
							};
							if(that.fromType){
								uni.$emit('goodsCartNum',obj);
							}
							that.$util.Tips({
								title: '添加购物车成功',
								success: () => {
									that.cartNum = res.data.cartNum;
									that.getCartCount(true);
								}
							});
						}
					})
					.catch(err => {
						that.isOpen = false;
						return that.$util.Tips({
							title: err
						});
					});
			},
			/**
			 * 获取购物车数量
			 * @param boolean 是否展示购物车动画和重置属性
			 */
			getCartCount: function(isAnima) {
				let that = this;
				const isLogin = that.isLogin;
				if (isLogin) {
					getCartCounts(0).then(res => {
						that.CartCount = res.data.resultAll.count;
						this.$store.commit('indexData/setCartNum', that.CartCount + '')
						//加入购物车后重置属性
						if (isAnima) {
							that.m36f2e = true;
							setTimeout(function() {
								that.m36f2e = false;
							}, 500);
						}
					});
				}
			},
			/**
			 * 立即购买
			 */
			goBuy: function(e) {
				this.currentPage = false;
				if (!this.isLogin) {
					toLogin();
				} else {
					this.goCat(true,'buy');
				}
			},
			listenerActionSheet: function() {
				this.currentPage = false
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
				if (url.indexOf('https://') > -1) return url;
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
					}
				});
			},
			/**
			 * 获取产品分销二维码
			 * @param function successFn 下载完成回调
			 *
			 */
			downloadFilePromotionCode: function(successFn) {
				let that = this;
				getProductCode(that.id).then(res => {
						uni.downloadFile({
							url: that.setDomain(res.data.code),
							success: function(res) {
								that.$set(that, 'isDown', false);
								if (typeof successFn == 'function') successFn && successFn(res
									.tempFilePath);
								else that.$set(that, 'PromotionCode', res.tempFilePath);
							},
							fail: function() {
								that.$set(that, 'isDown', false);
								that.$set(that, 'PromotionCode', '');
							}
						});
					})
					.catch(err => {
						that.$set(that, 'isDown', false);
						that.$set(that, 'PromotionCode', '');
					});
			},
			getImageBase64: function(type) {
				let that = this;
				imageBase64(that.storeImage, that.followCode)
					.then(res => {
						that.storeImage = res.data.image;
						if (type) {
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
				if (that.PromotionCode == '' && !that.isDown) {
					return that.$util.Tips({
						title: '小程序二维码需要发布正式版后才能获取到'
					}, function() {
						that.posters = false;
					});
				}
				if (that.isDown)
					return that.$util.Tips({
						title: '正在下载海报,请稍后再试！'
					}, function() {
						that.posters = false;
					});
				// #endif
				uni.getImageInfo({
					src: that.PromotionCode,
					fail: function(res) {
						// #ifdef H5 || APP-PLUS
						return that.$util.Tips({
							title: res.errMsg
						});
						// #endif
						// #ifdef MP
						return that.$util.Tips({
							title: '小程序二维码需要发布正式版后才能获取到'
						});
						// #endif
					},
					success() {
						if (arr2[2] == '') {
							//海报二维码不存在则从新下载
							that.downloadFilePromotionCode(function(msgPromotionCode) {
								arr2[2] = msgPromotionCode;
								if (arr2[2] == '')
									return that.$util.Tips({
										title: '海报二维码生成失败！'
									});
								that.$nextTick(function() {
									setTimeout(()=>{
										that.$util.PosterCanvas(that.fontColor, that.themeColor,that.siteName,
											arr2, that.storeInfo.store_name, that.storeInfo
											.price, that.storeInfo.ot_price, that.posterTitle,
											function(tempFilePath) {
												that.$set(that, 'posterImage', tempFilePath);
												that.$set(that, 'posterImageStatus', true);
												that.$set(that, 'canvasStatus', false);
												that.$set(that, 'actionSheetHidden', !that.actionSheetHidden);
											});
									},1000)
								})
							});
						} else {
							//生成推广海报
							that.$nextTick(function() {
								setTimeout(()=>{
									that.$util.PosterCanvas(that.fontColor, that.themeColor, that.siteName,
										arr2, that.storeInfo.store_name, that.storeInfo.price, that.storeInfo.ot_price, that.posterTitle,
										function(tempFilePath) {
											that.$set(that, 'posterImage', tempFilePath);
											that.$set(that, 'posterImageStatus', true);
											that.$set(that, 'canvasStatus', false);
											that.$set(that, 'actionSheetHidden', !that.actionSheetHidden);
										});
								},1000)

							})

						}
					}
				});
			},

			/*
			 * 保存到手机相册
			 */
			// #ifdef MP
			copyCommand: function() {
				if (wx.navigateToMiniProgram) {
					wx.navigateToMiniProgram({
						appId: 'wxb036cafe2994d7d0',
						path: '/publish/ugc-publish/ugc-publish',
						extraData: {
							productInfo: {
								title: this.storeInfo.store_name,
								path: '/pages/goods_details/index?id=' + this.storeInfo.id,
								thumbUrl: this.storeInfo.image
							}
						}
					});
				}
			},
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
									});
								}
							});
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
								}
							});
						}
					}
				});
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


			//#ifdef H5
			ShareInfo() {
				let data = this.storeInfo;
				let href = location.href;
				if (this.$wechat.isWeixin()) {
					getUserInfo().then(res => {
						href = href.indexOf('?') === -1 ? href + '?spid=' + res.data.uid : href + '&spid=' + res.data.uid;
						let configAppMessage = {
							desc: data.store_info,
							title: data.store_name,
							link: href,
							imgUrl: data.image
						};
						this.$wechat
							.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData',
								'onMenuShareAppMessage',
								'onMenuShareTimeline'
							], configAppMessage)
							.then(res => {})
							.catch(err => {});
					});
				}
			},
			//#endif
			goDiscounts() {
				uni.navigateTo({
					url: '/pages/goods_details/discountsGoodsList?id=' + this.id
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
					uni.navigateBack();
				}

			},
			ChangCouponsUseState(index) {
				let that = this;
				that.coupon.list[index].is_use = true;
				that.$set(that.coupon, 'list', that.coupon.list);
				that.$set(that.coupon, 'coupon', false);
			},
			/**
			 * 领取完毕移除当前页面领取过的优惠券展示
			 */
			ChangCoupons: function(e) {

			},
			goGoodCall() {
				let userInfo = {}
				if(typeof this.userInfo === 'string'){
					userInfo = JSON.parse(this.userInfo)
				}else{
					userInfo = this.userInfo
				}
				let url = `/pages/extension/customer_list/chat?productId=${this.id}`
				let obj = {
					store_name: this.storeInfo.store_name,
					path: `/pages/goods_details/index.html?id=${this.storeInfo.id}`,
					image:this.storeInfo.image
				}
				this.$util.getCustomer(userInfo,url,obj,1)
			},
			colorData(){
				let color = [{
						themeColor: '#1DB0FC',
						fontColor: '#FD502F'
					},
					{
						themeColor: '#42CA4D',
						fontColor: '#FF7600'
					},
					{
						themeColor: '#e93323',
						fontColor: '#e93323'
					},
					{
						themeColor: '#FF448F',
						fontColor: '#FF448F'
					},
					{
						themeColor: '#FE5C2D',
						fontColor: '#FE5C2D'
					},
					{
						themeColor: '#E0A558',
						fontColor: '#DA8C18',
					}
				];
				setTimeout(() => {
					switch (this.colorNum) {
						case 1:
							this.themeColor = color[0].themeColor;
							this.fontColor = color[0].fontColor;
							break;
						case 2:
							this.themeColor = color[1].themeColor;
							this.fontColor = color[1].fontColor;
							break;
						case 3:
							this.themeColor = color[2].themeColor;
							this.fontColor = color[2].fontColor;
							break;
						case 4:
							this.themeColor = color[3].themeColor;
							this.fontColor = color[3].fontColor;
							break;
						case 5:
							this.themeColor = color[4].themeColor;
							this.fontColor = color[4].fontColor;
							break;
						case 6:
							this.themeColor = color[5].themeColor;
							this.fontColor = color[5].fontColor;
							break;
						default:
							this.themeColor = color[2].themeColor;
							this.fontColor = color[2].fontColor;
							break
					}
				}, 1)
			},
			productCardRelated() {
				productCardRelated(this.id).then((res) => {
					this.cardRelated = res.data;
				});
			},
			toggleExpand() {
				this.isExpanded = !this.isExpanded;
			},
			// 点击分销按钮
			brokerageShare() {
				if (!this.isLogin) {
					toLogin();
					return;
				}
				// 分销员点击出现分销海报
				if (this.userInfo.is_promoter) {
					this.listenerActionSheet()
				} else if (this.storeBrokerageStatu == 1) {
					// 指定分销
					uni.navigateTo({
						url: '/pages/users/distributor/apply'
					});
				} else if (this.storeBrokerageStatu == 3) {
					// 满额分销
					uni.navigateTo({
						url: '/pages/users/distributor/index'
					});
				}
			},
		},
		onPageScroll(object) {
			this.$set(this,'currentPage',false);
			if (object.scrollTop > 340) {
				this.pageScrollStatus = true;
			} else if (object.scrollTop < 340) {
				this.pageScrollStatus = false;
			}
			// 触发图片懒加载
			uni.$emit('scroll');
		},
	};
</script>

<style lang="scss">
	::v-deep uni-video {
		width: 100% !important;
	}

	::v-deep video {
		width: 100% !important;
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
	.info_card {
	  background: linear-gradient(180deg, #ffffff 0%, #ffffff 54%, rgba(255, 255, 255, 0) 100%);
	  margin-top: -34rpx;
	}
	.mirror-image {
		height: 34rpx;
		overflow-y: hidden;
		position: relative;
		&:after {
			content: '';
			width: 100%;
			height: 34rpx;
			position: absolute;
			left: 0;
			top: 0;
			background: rgba(0, 0, 0, 0.2);
		}
		image {
			width: 100%;
			transform: scaleY(-1);
		}
	}
	.atmosphere-card{
		margin-top: -34rpx;
		background-size: cover;
		background-repeat: no-repeat;
	}
	.bg--w111-484643{
		background: linear-gradient(90deg, #484643 0%, #1F1B17 100%);
	}
	.text--w111-FDDAA4{
		color: #FDDAA4;
	}
	.svip-badge{
		display: flex;
		align-items: center;
		height: 26rpx;
		border-radius: 14rpx;
		background-color: #F7E9CD;
		font-size: 22rpx;
		margin-left: 8rpx;
		position: relative;
		bottom: 4rpx;
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
		color: var(--view-theme);
	}
	.join_cart{
		background-color: var(--view-bntColor);
	}
	.text-primary-con{
		color: var(--view-theme);
	}
	.bg-primary-light{
		background: var(--view-minorColorT);
	}
	.recommend_pro ~ .recommend_pro{
		margin-left: 38rpx;
	}
	.page_footer{
		backdrop-filter: blur(10px);
		border-top: 1px solid #f5f5f5;
	}
	.num-badge{
		color: #fff;
		position: absolute;
		font-size: 18rpx;
		padding: 2rpx 10rpx 3rpx;
		border-radius: 200rpx;
		top: 0;
		right: -10rpx;
	}
	.bg-transparent{
		background: transparent;
	}

	.m888edb9 {
		width: 100%;
		background-color: #fff;
		position: fixed;
		left: 0;
		bottom: 0;
		z-index: 388;
		transform: translate3d(0, 100%, 0);
		transition: all 0.3s cubic-bezier(0.25, 0.5, 0.5, 0.9);
		border-top: 1rpx solid #eee;
		border-radius: 40rpx 40rpx 0 0;

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

	.m888edb9.on {
		transform: translate3d(0, 0, 0);
	}

	.m888edb9 .item {
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
</style>
