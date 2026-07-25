<template>
  <div class="content">
    <div class="goodsCard acea-row row-between">
      <div class="conter">
        <div class="cart">
          <div :style="'height:' + 100 + '%'" class="acea-row">
            <div class="acea-row row-between row-bottom cart-left">
              <div class="left-top">
                <div v-if="checkOut == 0" class="cart">
                  <div v-if="userInfo" class="title acea-row row-middle">
                    <div class="picture" @click="getUserDetail">
                      <img :src="userInfo.avatar" />
                    </div>
                    <div class="text">
                      <div class="textCon line1">
                        <div class="text-wrap">
                          <div class="name-wrap">
                            <span class="name">{{ userInfo.nickname }}</span>
                            <span v-if="userInfo.phone" class="phone mr10"
                              >手机号：{{ userInfo.phone }}</span>
					                   		<span @click="phoneTap" v-if="userInfo.uid && !userInfo.phone" class="fs-14 text-wlll-FF7700 pointer">完善手机号</span>
                                <span class="fs-14 text-wlll-FF7700 pointer">{{ userInfo.real_name }}</span>
                          </div>
                        </div>
<!--                        <Dropdown-->
<!--						  v-if="userInfo.uid"-->
<!--                          class="switchs"-->
<!--                          trigger="click"-->
<!--                          @on-click="changeMenu($event)"-->
<!--                        >-->
<!--                          <a href="javascript:void(0)">-->
<!--                            切换会员-->
<!--                            <Icon type="ios-arrow-down"></Icon>-->
<!--                          </a>-->
<!--                          <DropdownMenu slot="list">-->
<!--                            <DropdownItem name="1">查询会员</DropdownItem>-->
<!--                            <DropdownItem name="2">游客</DropdownItem>-->
<!--                          </DropdownMenu>-->
<!--                        </Dropdown>-->
<!--					          	<div @click="memberTap" class="fs-14 text-wlll-FF7700 pointer" >查询会员</div>-->
                      </div>
                      <div v-if="userInfo.uid" class="user-msg">
                        <div class="balance"
                          >积分<span class="num_two">{{
                            userInfo.integral
                          }}</span></div
                        >
                        <div class="balance"
                          >余额<span class="num">{{
                            userInfo.now_money
                          }}</span></div
                        >
                        <div style="padding-top:5px">
                        <div class="balance"
                        >本金<span class="num_two">{{
                            userInfo.ben_money
                          }}</span></div
                        >
                        <div class="balance"
                        >赠金<span class="num_two">{{
                            userInfo.give_money
                          }}</span></div
                        >
                        </div>
                      </div>
                    </div>
                    <Button @click="memberTap" class="lookUser" >查询会员</Button>
                  </div>
                  <div class="count">
                    <div class="cart-sel" style="display:flex;align-items: center">
                      已选购<span class="num">{{ cartSum }}</span
                      >件
<!--                      <div class="dingzhi_out">-->
<!--                        <input type="checkbox" v-model="createOrder.dingzhi" value="1"  class="selfCheckbox">-->
<!--                         定制卡-->
<!--                      </div>-->
                    </div>
                    <div class="count-r">
                      <!--                      <span class="coupon" @click="couponTap">优惠券</span>-->
                      <span class="clear" @click="delAll">
                        <img alt="" src="../../assets/images/clear.png" />
                        清空</span
                      >
                    </div>
                  </div>
                  <div class="listCon">
                    <div v-if="cartList.length" class="list">
                      <div
                        v-for="(data, proindex) in cartList"
                        :key="proindex + 'data'"
                        class="promotions"
                      >
                        <div
                          v-for="(pro, index) in data.promotions"
                          :key="index + 'pro'"
                          class="promotions-msg"
                        >
                          <div class="flex-1">
                            <span class="card">{{ pro.title }}</span>
                            <span class="desc">{{ pro.desc }}</span>
                          </div>
                          <div class="collect" @click="collectOrder(pro)">
                            {{ pro.promotions_type == 1 ? '去逛逛' : '去凑单' }}
                            <span class="iconfont iconjinru"></span>
                          </div>
                        </div>
                        <div
                          v-for="(item, indexs) in data.cart"
                          :key="indexs + 'car'"
                        >
                          <div class="item acea-row row-middle">
                          <div class="picture">
                            <img
                              v-if="item.productInfo.attrInfo"
                              :src="item.productInfo.attrInfo.image"
                            />
                            <img v-else :src="item.productInfo.image" />
                          </div>
                          <div class="text">
                            <div class="name line1">
                              {{ item.productInfo.store_name }}
                            </div>
                            <div
                              v-if="
                                item.productInfo.attrInfo &&
                                item.productInfo.spec_type
                              "
                              class="info"
                              @click="cartAttr(item)"
                            >
                              <div class="suk line1">
                                {{ item.productInfo.attrInfo.suk }}
                              </div>
                              <span class="iconfont iconxiayi"></span>
                            </div>
                            <div v-else class="info">默认</div>
                            <div class="sum_price" style="display: flex;align-items: center;flex-wrap: wrap;">
                              <div class="sum_price_price">¥ {{ item.sum_price }}</div>
                              <div v-if="item.coupon_info" class="coupon-info ml-10 mt-5">
                                <span class="coupon-tag">已使用优惠券</span>
                                <span class="coupon-name">{{ item.coupon_info.coupon_name }}</span>
                                <span class="coupon-amount">-¥{{ item.coupon_info.coupon_amount }}</span>
                              </div>
                            </div>
                          </div>

                          <div class="zengOut" style="justify-content: flex-end">
<!--                          <div-->
<!--                              class="zeng"-->
<!--                              :class="createOrder.giveIds.includes(item.id)?'zeng-active':''"-->
<!--                              @click="sendCart(item)"-->
<!--                          >-->
<!--                            赠送-->
<!--                          </div>-->
                          <div
                            class="zengDel"
                            @click="delCart(item, proindex, indexs, 'cart')"
                          >
                            删除
                          </div>
                          </div>
                          <div
                            class="cartBnt acea-row row-center-wrapper"
                          >
                            <div
                              :class="{
                                'text-wlll-1890FF': item.cart_num > 1,
                                'text-wlll-EEEEEE': item.cart_num <= 1,
                              }"
                              @[bindclick(item)]="calculate(item, 'reduce')"
                            >
                              <Icon type="md-remove-circle" size="24" />
                            </div>
                            <InputNumber
                              v-model="item.cart_num"
                              :max="item.productInfo.attrInfo.stock"
                              :min="1"
                              :readonly="item.product_type == 4 || item.product_type == 5 || item.productInfo.pid == 8154 || item.productInfo.id == 8154"
                              @on-blur="
                                (e) => {
                                  changeCart(e, item);
                                }
                              "
                            ></InputNumber>
                            <div
                              :class="{
                                'text-wlll-1890FF': item.cart_num < item.productInfo.attrInfo.stock,
                                'text-wlll-EEEEEE': item.product_type == 4 || item.product_type == 5 || item.cart_num >= item.productInfo.attrInfo.stock || item.productInfo.pid == 8154 || item.productInfo.id == 8154,
                              }"
                              @[bindclick(item)]="calculate(item, 'add')"
                            >
                              <Icon type="md-add-circle" size="24" />
                            </div>
                          </div>
                          </div>
                          <!-- 商品底部：销售/手艺人 + 余额支付 + 优惠券 + 卡升级 -->
                          <div class="cart-bottom-actions mt-6">
                            <div
                              class="acea-row row-middle cart-bottom-toolbar"
                              v-if="showCartItemYeji(item) || showCartItemPayRow(item) || showCartItemCoupon(item)"
                            >
                                <div class="toyeji" @click.stop="doYeji(item,2)" v-if="showCartItemYeji(item) && item.product_type == 6 && !item.true_dingzhi">
                                  销售<span style="color: #736a6a" v-for="(subItem,index) in setYejiAll">
                                      <span v-if="item.id == subItem.cart_id">
                                        <span v-for="(cItem,cindex) in subItem.staffChoose">
                                          <span v-if="cindex == 0">:{{cItem.staff_name}}</span>
                                          <span v-else>,{{cItem.staff_name}}</span>
                                        </span>
                                      </span>
                                    </span>
                                  /
                                  手艺人<span style="color: #736a6a" v-for="(subItem,index) in serviceYejiAll">
                                      <span v-if="item.id == subItem.cart_id">
                                        <span v-for="(cItem,cindex) in subItem.staffChoose">
                                          <span v-if="cindex == 0">:{{cItem.staff_name}}</span>
                                          <span v-else>,{{cItem.staff_name}}</span>
                                          <span v-if="cItem.is_dian == 1">(点)</span>
                                          <span v-else>(轮)</span>
                                        </span>
                                      </span>
                                    </span>
                                </div>
                                <div class="toyeji" @click.stop="doYeji(item,1)" v-else-if="showCartItemYeji(item)">
                                  销售
                                  <span style="color: #736a6a" v-for="(subItem,index) in setYejiAll">
                                      <span v-if="item.id == subItem.cart_id">
                                        <span v-for="(cItem,cindex) in subItem.staffChoose">
                                          <span v-if="cindex == 0">:{{cItem.staff_name}}</span>
                                          <span v-else>,{{cItem.staff_name}}</span>
                                        </span>
                                      </span>
                                    </span>
                                </div>
                              <span v-if="showCartItemPayRow(item)" class="yuePayBtn" @click.stop="toggleItemYuePay(item)">余额支付</span>
                              <div v-if="showCartItemPayRow(item) && item.show_yue_pay" class="yue-pay-input-wrap">
                                <Input
                                    v-model="item.yue_pay_amount"
                                    style="width: 160px"
                                    placeholder="输入金额"
                                    :maxlength="11"
                                    inputmode="decimal"
                                    @on-change="onItemYuePayChange(item, $event)"
                                    @on-input="onItemYuePayChange(item, $event)"
                                    @input.native="onItemYuePayChange(item, $event)"
                                />
                              </div>
                              <span
                                v-if="showCartItemCoupon(item)"
                                class="yuePayBtn"
                                :class="{ 'coupon-selected': item.coupon_id }"
                                @click.stop="itemCouponTap(item)"
                              >优惠券</span>
                              <span
                                class="toyeji"
                                v-if="showCartItemPayRow(item) && isCardUpgradeEligible(item)"
                                @click.stop="openCardUpgrade(item)"
                              >卡升级</span>
                              <span
                                v-if="showCartItemPayRow(item) && cashierDebtPayEnabled"
                                class="yuePayBtn"
                                @click.stop="toggleItemDebtPay(item)"
                              >欠款</span>
                              <div v-if="showCartItemPayRow(item) && cashierDebtPayEnabled && item.show_debt_pay" class="yue-pay-input-wrap">
                                <Input
                                    v-model="item.debt_pay_amount"
                                    style="width: 160px"
                                    placeholder="输入欠款金额"
                                    :maxlength="11"
                                    inputmode="decimal"
                                    @on-change="onItemDebtPayChange(item, $event)"
                                    @on-input="onItemDebtPayChange(item, $event)"
                                    @input.native="onItemDebtPayChange(item, $event)"
                                />
                              </div>
                              <div v-if="showCartItemPayRow(item) && item.card_upgrade_enabled && item.card_upgrade_amount > 0" class="card-upgrade-info">
                                <div class="card-upgrade-amount-box">
                                  <span class="card-upgrade-amount">¥{{ item.card_upgrade_amount }}</span>
                                  <span class="card-upgrade-close" @click.stop="cancelCardUpgrade(item)">×</span>
                                </div>
                              </div>
                            </div>
                            <div
                                v-if="item.product_type == 6 && !cartHasCustomCard && item.is_dingzhi"
                                class="service-object"
                            >
                                <span
                                    class="service-object-choice"
                                    :class="{ active: (item.service_object || '本人') === '本人' }"
                                    @click.stop="setCartItemServiceObject(item, '本人')"
                                >本人</span>
                              <span class="divider">/</span>
                              <span
                                  class="service-object-choice"
                                  :class="{ active: (item.service_object || '本人') === '朋友' }"
                                  @click.stop="setCartItemServiceObject(item, '朋友')"
                              >朋友</span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div v-if="invalidList.length" class="list promotions">
                      <div
                        v-for="(item, index) in invalidList"
                        :key="index"
                        class="item acea-row row-middle"
                      >
                        <div class="picture">
                          <img
                            v-if="item.productInfo.attrInfo"
                            :src="item.productInfo.attrInfo.image"
                          />
                          <img v-else :src="item.productInfo.image" />
                        </div>
                        <div class="text invalid">
                          <div class="name line1">
                            {{ item.productInfo.store_name }}
                          </div>
                          <div v-if="item.productInfo.attrInfo" class="info">
                            <div class="suk line1">
                              {{ item.productInfo.attrInfo.suk }}
                            </div>
                            <span class="iconfont iconxiayi"></span>
                          </div>
                          <div v-else class="info">默认</div>
                          <div class="end">该商品已失效</div>
                        </div>
                        <div
                          class="del"
                          @click="delCart(item, index, 1, 'inv')"
                        >
                          删除
                        </div>
                      </div>
                    </div>
                    <div
                      v-if="!invalidList.length && !cartList.length"
                      class="noCart acea-row row-center-wrapper"
                    >
                      <div>
                        <div class="picture">
                          <img src="@/assets/images/no-cart.png" />
                        </div>
                        <div class="tip">暂无商品，快去添加吧～</div>
                      </div>
                    </div>
                  </div>
                  <div class="footer">
                    <div class="left">
                      <div class="conInfo">
                        <div class="right">
                          <div class="storeBnt-wrap">
                            <div class="storeBnt" @click="storeTap">
                              <span class="text line1">{{
                                storeInfos ? storeInfos.staff_name : '切换店员'
                              }}</span>
                              <Icon
                                style="
                                  display: inline-block;
                                  padding-left: 10px;
                                "
                                type="ios-arrow-down"
                              />
                            </div>
                          </div>
                          <div class="discount">
                            优惠: ¥{{
                              this.$computes.Sub(
                                priceInfo.sumPrice || 0,
                                priceInfo.payPrice || 0
                              ) || 0
                            }}
                          </div>
                          <div
                            v-if="cartList.length"
                            class="detailed"
                            @click="discountCon"
                          >
                            明细
                          </div>
                          <span class="discount">实付: </span>
                          <span class="rmb">¥</span>
                          <span class="num">{{
                            cartSum && priceInfo.payPrice
                              ? priceInfo.payPrice
                              : 0
                          }}</span>
                        </div>
                      </div>
                    </div>
                    <div class="footer-bottom">
                      <Button :disabled="!cartList.length" @click="tryOpenSettle"
                        >立即结账</Button
                      >
                    </div>
                  </div>
                </div>
                <div v-else class="cart" style="padding-top: 15px">
                  <Form
                    ref="lodgeFrom"
                    :label-width="100"
                    :model="lodgeFrom"
                    @submit.native.prevent
                  >
                    <FormItem :labelWidth="20" label="" label-for="nickname">
                      <Row>
                        <Col>
                          <Input
                            v-model="lodgeFrom.keyword"
                            element-id="nickname"
                            enter-button
                            placeholder="请输入用户名称/ID/手机号"
                            search
                            style="width: 370px"
                            @on-search="storeSearch"
                          >
                          </Input>
                        </Col>
                      </Row>
                    </FormItem>
                  </Form>
                  <Table
                    ref="selection"
                    :columns="columns"
                    :data="tableHang"
                    :loading="loading"
                    class="tableList"
                    highlight-row
                    no-filtered-userFrom-text="暂无筛选结果"
                    no-userFrom-text="暂无数据"
                  >
                    <template slot="nickname" slot-scope="{ row }">
                      <div>{{ row.uid ? row.nickname : '游客' }}</div>
                    </template>
                    <template slot="action" slot-scope="{ row, index }">
                      <a @click="billHang(row, index)">提单</a>
                      <a class="ml10" @click="hangDel(row, index)">删除</a>
                    </template>
                  </Table>
                  <div class="acea-row row-right page mr5">
                    <Page
                      :current="lodgeFrom.page"
                      :page-size="lodgeFrom.limit"
                      :total="totalHang"
                      show-total
                      size="small"
                      @on-change="pageHangChange"
                    />
                  </div>
                </div>
                <div class="btn-group-vertical">
                  <Button :disabled="!cartList.length" @click="lodgeTap"
                    >挂单</Button
                  >
                  <Button :disabled="!userInfo.uid" @click="rechargeBnt"
                    >充值</Button
                  >
                  <Button :disabled="!cartList.length" @click="changePrice"
                    >改价</Button
                  >
                  <Button :disabled="!cartList.length" @click="remarks"
                    >备注</Button
                  >
                  <Button :disabled="!userInfo.uid" @click="toPay"
                  >消耗</Button
                  >
                  <Button :disabled="!userInfo.uid" @click="showSend" v-if="cashierOperatorGiftEnabled" style="position: relative">
                    赠送
                    <div class="icon-send-num" v-if="sendNum > 0">
                       {{ sendNum }}
                    </div>
                  </Button>
                  <Button
                    :disabled="!userInfo.uid"
                    :class="{ selected: createOrder.gendan_staff_id > 0 }"
                    @click="openGendanModal"
                  >{{ createOrder.gendan_staff_name || '跟单' }}</Button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="goods">
        <div class="acea-row" style="flex-wrap: nowrap; height: 100%">
          <div class="goodsCon">
            <div class="goods-top">
              <div class="min-w-494 acea-row row-middle w-full pt-17 pb-17 bg-w111-FFFFFF rd-20 pl-20 pr-20 pointer">
                <div class="goodsH pl-20 pr-20 h-42 fs-16 text-wlll-303133 acea-row row-center-wrapper mr-5" v-if="(currentCate != '99999' && (item.id == 1 || item.id == 4 || (item.id == 2 && cardNum>0) || (item.id == 3 && appointNum>0))) || (currentCate == '99999') && item.id == 4" v-for="(item, index) in headerList" :key="index" :class="item.id == activeID?'activeOn':''" @click="headerTap(item)">{{item.name}}</div>
                <Input
                    ref="input"
                    v-model="goodFrom.store_name"
                    :maxlength="20"
                    class="input"
                    element-id="name"
                    placeholder="搜索或扫码识别商品；扫会员码/微信付款码识别用户"
                    search
                    size="large"
                    @on-search="orderSearch"
                >
                  <template #prepend>
                    <Select
                        v-model="goodFrom.field_key"
                        style="width: 90px"
                    >
                      <Option value="all">全部</Option>
                      <Option value="store_name">商品名称</Option>
                      <Option value="id">ID</Option>
                      <Option value="bar_code">唯一码</Option>
                    </Select>
                  </template>
                  <template #append>
                    <span class="iconfont iconsousuo1" @click="orderSearch"></span>
                  </template>
                </Input>
              </div>
              <div class="acea-row flex-1">
                <div class="bg-w111-FFFFFF flex-1 rd-20 mt-20 pt-15 flex-1 acea-row row-center-wrapper" v-if="activeID == 4">
                  <div class="w-490">
                    <div class="w-full h-60 rd-4 border-1-1890FF acea-row row-between-wrapper pl-16 pr-16">
                      <span class="fs-20 fw-600">{{ collection }}</span>
                      <span class="fs-16">元</span>
                    </div>
                    <div class="keypad">
                      <div class="left">
                        <Button v-for="item in numList" :key="item" @click="numTap(item)">{{
                            item
                          }}</Button>
                      </div>
                      <div class="right">
                        <Button @click="delNum"
                        ><Icon type="ios-backspace-outline"
                        /></Button>
                        <Button @click="delNum(-1)">C</Button>
                        <Button class="enter" @click="joinCart(0,{},1)">确认</Button>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="bg-w111-FFFFFF rd-20 mt-20 pt-15 goodsList" :class="showCate?'on':''" v-else>
                  <div class="pl-20 pr-20">
                    <div class="acea-row row-middle">
                      <Tabs v-model="currentCate" @on-click='cateTap'>
                        <TabPane v-for="(item, index) in cateData" :key="index" :name='item.id+""' :label="item.cate_name"></TabPane>
                      </Tabs>
                      <div v-if="showCate" class="iconfont iconic_more2 ml-15 text-wlll-333333 fs-19" @click="openCate"></div>
                    </div>
                    <swiper
                        v-if="activityTypeArr.length"
                        :options="swiperOption"
                        @ready="readySwiper"
                        @click="clickSwiper"
                    >
                      <swiper-slide
                          v-for="(item, index) in activityTypeArr"
                          :key="index"
                          :class="{ active: swiperClickedIndex === index }"
                      >{{ item.desc }}</swiper-slide
                      >
                    </swiper>
                    <Alert v-if="swiperClickedIndex">
                      <div>
                        活动时间：{{
                          activityTypeArr[swiperClickedIndex].section_time[0]
                        }}
                        ~ {{ activityTypeArr[swiperClickedIndex].section_time[1] }}
                      </div>
                      <div style="margin-top: 14px">
                        活动内容：{{ activityTypeArr[swiperClickedIndex].desc }}
                      </div>
                    </Alert>
                  </div>
                  <div ref="listWrap" class="list-wrap" :style="{paddingLeft:picPd,paddingRight:picPd}" @scroll="pageChange">
                    <Row
                        v-if="
							goodData.length &&
							(goodFrom.cate_id !== '99999' || activityFrom.type)
						  "
                        class="list"
                    >
                      <Col
                          v-for="(item, index) in goodData"
                          :key="index"
                      >
                        <div
                            :class="{ on: item.stock }"
                            class="item acea-row row-middle pl-12 pr-12"
                            :style="{ width:picwidth, marginLeft: picmargin,marginRight: picmargin }"
                            @click="attrTap(item)"
                        >
                          <div
                              class="picture"
                          >
                            <img
                                :src="item.image"
                                alt="商品图"
                                style="width: 100%"
                            />
                            <div v-if="!item.stock && !item.cart_num && item.product_type == 0" class="absolute rd-8 top-0 left-0 right-0 bottom-0 fs-13 text-wlll-FFFFFF bg-w111-303133-60 acea-row row-center-wrapper">暂无库存</div>
                          </div>
                          <div class='ml-12 fs-16 txtCon'>
                            <div class="name text-wlll-303133 line2 fs-15" :class="!item.stock && !item.cart_num?'text-wlll-303133-50':''">
                              {{ item.store_name || item.title }}
                            </div>
                            <div class="stock text-wlll-f5222d mt-10 fw-600" :class="!item.stock && !item.cart_num?'text-wlll-F5222D-50':''">¥{{ item.price }}</div>
                          </div>
                          <div
                              v-if="item.cart_num && cartList.length"
                              class="icon-cart-num"
                          >
                            {{ item.cart_num > 99 ? '99+' : item.cart_num }}
                          </div>
                        </div>
                      </Col>
                    </Row>
                    <div
                        v-else-if="goodFrom.cate_id === '99999' && !activityFrom.type"
                    >
                      <activityCard
                          v-if="!activityFrom.type"
                          :uid="userInfo.uid"
                          @selectaActivity="selectaActivity"
                      >
                      </activityCard>
                    </div>
                    <div v-else class="noGood acea-row row-center-wrapper">
                      <div>
                        <div class="picture">
                          <img
                              :src="
								  require(`@/assets/images/${
									goodFrom.cate_id == '99999'
									  ? 'no-active.png'
									  : 'no-goods.png'
								  }`)
								"
                          />
                        </div>
                        <div class="tip">
                          {{
                            goodFrom.cate_id === '99999'
                                ? '暂无活动，敬请期待～'
                                : '暂无商品，先看看别的吧～'
                          }}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="goodClass" v-if="!showCate">
                  <div class="acea-row row-center-wrapper fs-16 text-wlll-303133 relative"><span class="iconfont iconic_more2-copy fs-19 absolute left-12" @click="openCate"></span>全部分类</div>
                  <div class="acea-row row-center-wrapper" style="flex-wrap: nowrap">
                    <div class="cateList">
                      <Tree :data="cateDataMore" @on-select-change='treeCate'></Tree>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <Modal
        v-model="modalUser"
        :mask-closable="false"
        :scrollable="true"
        closable
        footer-hide
        title="用户列表"
        width="950"
        class-name="user-modal"
    >
      <userList
          v-if="modalUser"
          ref="users"
          :uid="userInfo.uid || 0"
          @getUserId="getUserId"
      ></userList>
    </Modal>
    <settleDrawer
        ref="settlePay"
        v-model="settleVisible"
        :list="payList"
        :type="payType"
        :hideYuePayOption="hideYuePayOption"
        :forceCombinationPay="forceCombinationPay"
        :initCombinationInfo="initCombinationInfo"
        :lockYueEdit="lockSettleYueEdit"
        :lockDebtRepaySource="lockDebtRepaySource"
        :initialSource="debtRepayInitialSource"
        :money="settleMoney"
        :collection="collection"
        :priceInfo="priceInfo"
        :hasRemark="this.createOrder.remarkInfo"
        :nowMoney="userInfo.now_money"
        :submitData="submitData"
        :verify="yueVerify"
        :isRecharge="isRecharge"
        :z-index="zIndex"
        @payPrice="payPrice"
        @changeSource="changeSource"
        @numTap="numTap"
        @delNum="delNum"
        @cashBnt="cashBnt"
        @setBudan="setBudan"
        @saveRemark="saveRemark"
        @saveCombinationinfo="saveCombinationinfo"
        @getUserId="getUserId"
    ></settleDrawer>
    <recharge
        ref="recharge"
        v-model="rechargeVisible"
        :userInfo="userInfo"
        @getSuccess="getSuccess"
        @recharge="onRecharge"
    ></recharge>
    <couponList
        v-if="userInfo && cartList.length"
        ref="coupon"
        :couponId="couponTargetItem ? (couponTargetItem.coupon_id || 0) : -1"
        :targetCartId="couponTargetItem ? couponTargetItem.id : 0"
        :usedCouponIds="couponTargetItem ? getUsedCouponIds(couponTargetItem.id) : []"
        :cartList="cartList"
        :uid="userInfo.uid"
        :isPrice="Number(createOrder.is_price) || 0"
        :changePrice="Number(createOrder.change_price) || 0"
        :cartInfo="createOrder.cart_info"
        @getCouponId="getItemCouponId"
    ></couponList>
    <storeList
        ref="store"
        :storeInfo="storeInfos"
        @getStoreId="getStoreId"
        @getUserInfo="getUserInfo"
    ></storeList>
    <productAttr
        ref="attrs"
        :attr="attr"
        :disabled="disabled"
        :isCart="isCart"
        @ChangeAttr="ChangeAttr"
        @goCat="goCat"
    >
    </productAttr>
    <productAttr
        ref="skillAttrs"
        :attr="attr"
        :disabled="disabled"
        :isCart="isCart"
        isSkill
        @ChangeAttr="ChangeAttr"
        @goCat="goPay"
    ></productAttr>
    <Modal
        v-model="payTypeModal"
        footer-hide
        title="支付方式"
        @on-visible-change="changeModal"
    >
      <div class="payModal">
        <div class="type" @click="payPrice('cash')">
          <div class="img">
            <img alt="" src="../../assets/images/xpay.png" />
          </div>
          <div class="text">现金收款</div>
        </div>
<!--        <div class="type" @click="payPrice('')">-->
<!--          <div class="img">-->
<!--            <img alt="" src="../../assets/images/wx_zfb_pay.png" />-->
<!--          </div>-->
<!--          <div class="text">微信/支付宝</div>-->
<!--        </div>-->
        <div class="type" @click="payPrice('yue')">
          <div class="img">
            <img alt="" src="../../assets/images/yue.png" />
          </div>
          <div class="text">余额收款</div>
        </div>
      </div>
    </Modal>
    <Modal v-model="modal" title="备注" class-name="remarks-modal">
      <Input
          v-model="createOrder.remarks"
          :rows="5"
          maxlength="200"
          placeholder="订单备注"
          show-word-limit
          type="textarea"
      />
      <div slot="footer">
        <Button type="primary" size="large" long @click="onSubmit">提交</Button>
      </div>
    </Modal>
    <Modal
        v-model="modalPay"
        class="modalPay"
        footer-hide
        width="430px"
        @on-cancel="modalPayCancel"
    >
      <div class="payPage">
        <div class="header acea-row row-center-wrapper">
          <div class="picture"><img src="../../assets/images/gold.png" /></div>
          <div class="text">应收金额(元)</div>
        </div>
        <div class="money">
          ¥<span class="num">{{
            priceInfo.payPrice ? priceInfo.payPrice : 0
          }}</span>
        </div>
        <Input
            ref="focusNum"
            v-model="payNum"
            placeholder="请点击输入框聚焦扫码或输入编码号"
            size="large"
            style="margin-top: 16px"
            type="url"
            @input="inputSaoMa"
        />
        <div class="process">
          <div class="picture">
            <img src="../../assets/images/process1.png" />
          </div>
          <div class="list acea-row row-between-wrapper">
            <div class="item one">
              <div class="name">
                {{
                  createOrder.pay_type == 'yue' ? '出示付款码' : '打开付款码'
                }}
              </div>
              <div>
                {{
                  createOrder.pay_type == 'yue'
                      ? '用户打开个人中心'
                      : '微信/支付宝付款码'
                }}
              </div>
            </div>
            <div class="item two">
              <div class="name">
                {{
                  createOrder.pay_type == 'yue' ? '扫描付款码' : '贴合付款盒子'
                }}
              </div>
              <div>
                {{ createOrder.pay_type == 'yue' ? '扫码枪' : '等待完成支付' }}
              </div>
            </div>
            <div class="item three">
              <div class="name">确认收款</div>
              <div>收银台确认</div>
            </div>
          </div>
        </div>
      </div>
    </Modal>
    <Modal
        v-model="modalCash"
        class="cash"
        footer-hide
        width="770px"
        @on-cancel="cancel"
    >
      <div class="cashPage acea-row">
        <div class="left">
          <div class="picture">
            <img src="../../assets/images/gold.png" />
          </div>
          <div class="text">应收金额(元)</div>
          <div class="money">
            ¥<span class="num">{{
              priceInfo.payPrice ? priceInfo.payPrice : 0
            }}</span>
          </div>
        </div>
        <div class="right">
          <div class="rightCon">
            <div class="top acea-row row-between-wrapper">
              <div>实际收款(元)</div>
              <div class="num">{{ collection }}</div>
            </div>
            <div class="center acea-row row-between-wrapper">
              <div>需找零(元)</div>
              <div
                  v-if="
                  this.$computes.Sub(
                    collection,
                    priceInfo.payPrice ? priceInfo.payPrice : 0
                  ) > 0
                "
                  class="num"
              >
                {{
                  this.$computes.Sub(
                      collection,
                      priceInfo.payPrice ? priceInfo.payPrice : 0
                  )
                }}
              </div>
              <div v-else class="num">0</div>
            </div>
            <div class="bottom acea-row">
              <div
                  v-for="(item, index) in numList"
                  :key="index"
                  :class="item == '.' ? 'spot' : ''"
                  class="item acea-row row-center-wrapper"
                  @click="numTap(item)"
              >
                {{ item }}
              </div>
              <div class="item acea-row row-center-wrapper" @click="delNum">
                <Icon type="ios-backspace" />
              </div>
            </div>
          </div>
          <Button type="primary" @click="cashBnt">确认</Button>
        </div>
      </div>
    </Modal>
    <Modal v-model="discount" footer-hide title="优惠明细" width="400">
      <div class="discountCon">
        <div class="item acea-row row-between-wrapper">
          <div>订单原价</div>
          <div>￥{{ priceInfo.sumPrice || 0 }}</div>
        </div>
        <div class="item acea-row row-between-wrapper">
          <div>会员优惠金额：</div>
          <div>￥{{ priceInfo.vipPrice || 0 }}</div>
        </div>
        <div class="item acea-row row-between-wrapper" v-if="priceInfo.firstOrderPrice > 0">
          <div>首单优惠：</div>
          <div>￥{{ priceInfo.firstOrderPrice || 0 }}</div>
        </div>
        <div class="item acea-row row-between-wrapper">
          <div>优惠券金额：</div>
          <div>￥{{ priceInfo.couponPrice || 0 }}</div>
        </div>
        <div class="item acea-row row-between-wrapper">
          <div>积分抵扣：</div>
          <div>￥{{ priceInfo.deductionPrice || 0 }}</div>
        </div>
        <div
            v-for="(item, index) in priceInfo.promotionsDetail"
            :key="index"
            class="item acea-row row-between-wrapper"
        >
          <div>{{ item.title }}：</div>
          <div>￥{{ item.promotions_price || 0 }}</div>
        </div>
        <div class="item acea-row row-between-wrapper" v-if="submitData.payPrice">
          <div>改价优惠：</div>
          <div>￥{{ $computes.Sub(submitData.payPrice,submitData.resultPayPrice) }}</div>
        </div>
      </div>
    </Modal>
    <Modal
        v-model="userInfoShow"
        class-name="vertical-center-modal"
        footer-hide
        title="是否切换此用户"
        width="340"
    >
      <div class="search_user_info">
        <div class="picture">
          <img :src="modalUserInfo.avatar" alt="" />
        </div>
        <p class="user_name">{{ modalUserInfo.real_name }}</p>
        <p class="user_id">ID:{{ modalUserInfo.uid }}</p>
        <p class="user_phone">手机号：{{ modalUserInfo.phone }}</p>
        <div class="sure_btn" @click="checkUser()">确定</div>
      </div>
    </Modal>
    <!-- 会员详情-->
    <user-details
      ref="userDetails"
      :debt-store-name="currentStoreName"
      @changeSuccess="changeSuccess"
      @operation="operation"
      @cardHolderOpen="cardHolderOpen"
      @debtRepay="onDebtRepayFromDetail"
    ></user-details>
    <!-- 改价 -->
    <changePrice ref="changePrice" @submitSuccess='submitSuccess'></changePrice>
    <!-- 切换会员/会员设置 -->
    <memberSet ref="memberSet" @submitSuccess='getUserId'></memberSet>
    <!--演示站侧边栏弹窗 不要删除-->
    <!--    <div class="open-image" v-if="openImage">-->
    <!--      <img src="@/assets/images/wechat_demo.gif" alt="">-->
    <!--      <span class="iconfont iconcha" @click="clears" ></span>-->
    <!--    </div>-->
    <Modal
        v-model="cardHolderShow"
        scrollable
        title="卡项详情"
        closable
        footer-hide
        width="900"
    >
      <cardHolder :cardHolder="cardHolderData"></cardHolder>
    </Modal>
    <Modal v-model="payStatus" footer-hide class-name="payStatus-modal vertical-center-modal">
      <div>
        <div class="text-wlll-303133 fs-20 text-center mt-30">支付失败</div>
        <div class="w-152 h-114 auto mt-42">
          <img src="@/assets/images/failPay.png" class="w-full h-full"/>
        </div>
        <div class="text-center fs-18 mt-10 text-wlll-303133">{{errorInfo}}</div>
        <div class="auto mt-42 w-172 h-50 rd-25 bg-w111-1890FF fs-18 text-wlll-FFFFFF acea-row row-center-wrapper pointer" @click="rePay">重新支付</div>
      </div>
    </Modal>
    <Modal v-model="paySuccess" footer-hide class-name="paySuccess-modal vertical-center-modal">
      <div class="acea-row row-center-wrapper row-column">
        <div class="w-70 h-90">
          <img src="@/assets/images/successPay.png" class="w-full h-full" />
        </div>
        <div class="fs-20 text-wlll-303133 mt-24">支付成功</div>
        <div style="display: flex;justify-content: space-between;;position: absolute;bottom: 40px;width: 96%">
              <Button class="addOrder jixu" @click="closePay">会员开单</Button>
              <Button class="addOrder jixu" @click="jixuPay">游客开单</Button>
              <Button class="addOrder" @click="toPay">去消耗</Button>
        </div>
      </div>
    </Modal>
    <Modal
      v-model="cardUpgradeVisible"
      class-name="vertical-center-modal card-upgrade-modal"
      title="卡升级选择旧卡"
      width="720"
    >
      <div v-if="cardUpgradeLoading" class="p-30 text-center">
        <div class="loading-spinner"></div>
        <div class="mt-10 text-wlll-909399">加载中...</div>
      </div>
      <div v-else>
        <!-- 搜索框 -->
        <div class="card-upgrade-search" style="margin-bottom: 24px;">
          <Input
            v-model="cardUpgradeSearch"
            placeholder="搜索卡名称或订单号"
            @input="handleCardUpgradeSearch"
            prefix="ios-search"
            class="card-upgrade-search-input"
          />
        </div>

        <div v-if="!filteredCardUpgradeList.length" class="p-40 text-center">
          <img src="@/assets/images/empty-card.png" class="w-60 h-60 auto mb-10" v-if="false" />
          <div class="text-wlll-909399 fs-15">暂无可用于卡升级的旧卡项</div>
        </div>

        <!-- 卡片列表 -->
        <div v-else class="card-upgrade-scroll">
          <div class="card-upgrade-grid">
            <div
              v-for="(c, idx) in filteredCardUpgradeList"
              :key="c._key || idx"
              class="card-upgrade-card"
              :class="{ 'card-upgrade-card-selected': cardUpgradeSelectedKey === c._key }"
              @click="selectCardUpgrade(c._key)"
            >
              <div class="card-upgrade-card-content">
                <div class="card-upgrade-card-order" v-if="c.order_id">订单号：{{ c.order_id }}</div>
                <div class="card-upgrade-card-title">{{ c.store_name || c.product_name || '旧卡' }}</div>
                <div class="card-upgrade-card-value">
                  <span class="text-wlll-303133">剩余：</span>
                  <span class="text-wlll-FF4D4F font-bold">¥{{ c.remain_value }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div slot="footer" class="card-upgrade-footer">
        <Button class="card-upgrade-btn-cancel" @click="closeCardUpgradeModal">取消</Button>
        <Button type="primary" :disabled="!cardUpgradeSelectedKey" class="card-upgrade-btn-confirm" @click="confirmCardUpgrade">确定</Button>
      </div>
    </Modal>
    <debt-reminder
      v-model="debtReminderVisible"
      :uid="userInfo.uid || 0"
      :user-name="userInfo.real_name || userInfo.nickname || ''"
      @repay="onDebtReminderRepay"
      @records="openDebtRecords"
    />
    <debt-repay
      v-model="debtRepayVisible"
      :row="debtRepayRow"
      :store-name="currentStoreName"
      @pay="onDebtRepayPay"
    />
    <yeji :canSy="canSy" :isSale="isSale" :showApplyAll="true" :yejiService="setYejiService" :syncProduct="syncProduct" :yeji="setYeji" :staffIds="staffIds" :staffIdsService="staffIdsService" @doChoose="doChoose" @doChooseService="doChooseService" @applyAll="applyYejiAll" @closeYeji="closeYeji" :visible="yejiVisible" ref="yeji"></yeji>
    <send :sendAll="createOrder.sendAll"  @closeSend="closeSend" :visible="sendVisible" ref="send"></send>
    <gendan-staff
      :visible="gendanVisible"
      :value="createOrder.gendan_staff_id"
      :staff-name="createOrder.gendan_staff_name"
      @confirm="onGendanConfirm"
      @close="gendanVisible = false"
    ></gendan-staff>
  </div>
</template>

<script>
import yeji from '@/components/yeji';
import send from '@/components/send';
import gendanStaff from '@/components/gendanStaff';
import userList from '@/components/userList';
import storeList from '@/components/storeList';
import couponList from '@/components/couponList';
import productAttr from './components/productAttr';
import memberSet from './components/memberSet';
import recharge from '@/components/recharge';
import activityCard from '@/components/activityCard';
import userDetails from '@/components/userDetail/userDetails';
import settleDrawer from '@/components/settleDrawer';
import changePrice from '@/components/changePrice';
import cardHolder from '@/components/cardHolder';
import debtReminder from '@/components/debtReminder';
import debtRepay from '@/components/debtRepay';
import { debtSummaryApi, debtRepayPayApi } from '@/api/debt';
import '../../assets/js/core.js';
import {
  cashierProduct,
  cashierCate,
  productCate,
  cashierUser,
  cashierCode,
  cashierCart,
  cashierDetail,
  cashierCartList,
  cashierCartNum,
  cashierchangeCart,
  cashierCartDel,
  cashierCompute,
  cashierCreate,
  cashierPay,
  postCashierSwitch,
  postCashierHang,
  getHangList,
  getHang,
  cashierHang,
  cashierGetAttr,
  swithUser,
  staffYeji,
  getService,
  cashierValidCardUpgradeList,
  cashierCouponList
} from '@/api/order';
import { checkOrderApi, getUserInfo, userSaveApi } from '@/api/user';
import { activityList, activityTypeList, cardRelated } from '@/api/product';
import Setting from '@/setting';
import util from '@/libs/util';

export default {
  name: 'index',
  components: {
    userList,
    storeList,
    productAttr,
    couponList,
    recharge,
    activityCard,
    userDetails,
    settleDrawer,
    changePrice,
    memberSet,
    cardHolder,
    debtReminder,
    debtRepay,
    yeji,
    send,
    gendanStaff
  },
  data() {
    return {
      sendNum:0,
      sendAllDirty: false,
      gendanVisible: false,
      syncProduct:[],
      yejiVisible: false,
      sendVisible: false,
      canSy:false,
      isSale:false,
      yejiServiceVisible: false,
      staffIds:[],
      staffIdsService:[],
      setYejiAll:[],
      serviceYejiAll:[],
      selectedProduct:[],
      giftProjectSelectedProducts: [],
      chooseProduct:{},
      setYeji:{
        link_id:0,
        cart_id:0,
        price:0,
        goods_id:0,
        type:2,
        staffChoose:[]
      },
      setYejiService:{
        link_id:0,
        cart_id:0,
        price:0,
        goods_id:0,
        type:2,
        staffChoose:[]
      },
      errorInfo:'',
      payStatus: false,
      paySuccess: false,
      openImage: true,
      loading: false,
      cashBntLoading: false,
      totalHang: 0,
      tableHang: [],
      activeHangon: -1,
      hangData: [],
      lodgeFrom: {
        keyword: '',
        page: 1,
        limit: 10,
      },
      currentid: '',
      columns: [
        {
          title: '选择',
          key: 'chose',
          width: 60,
          align: 'center',
          render: (h, params) => {
            let id = params.row.id;
            let flag = false;
            if (this.currentid === id) {
              flag = true;
            } else {
              flag = false;
            }
            let self = this;
            return h('div', [
              h('Radio', {
                props: {
                  value: flag,
                },
                on: {
                  'on-change': () => {
                    self.currentid = id;
                    self.activeHangon = params.index;
                    let data = {
                      uid: params.row.uid,
                    };
                    let touristId = params.row.tourist_uid;
                    if (params.row.uid) {
                      this.userInfoData(data);
                    } else {
                      this.setUp(touristId);
                    }
                  },
                },
              }),
            ]);
          },
        },
        {
          title: '用户',
          slot: 'nickname',
          minWidth: 70,
        },
        {
          title: '订单金额',
          key: 'price',
          minWidth: 70,
        },
        {
          title: '时间',
          key: '_add_time',
          minWidth: 70,
        },
        {
          title: '操作',
          slot: 'action',
          minWidth: 100,
          align: 'center',
        },
      ],
      checkOut: 0,
      modalUser: false,
      flag: true,
      goodFrom: {
        store_name: '',
        field_key: 'all',
        cate_id: '',
        page: 1,
        limit: 20,
        uid: 0,
        staff_id: 0,
      },
      activityFrom: {
        page: 1,
        limit: 20,
        type: 0,
        uid: 0,
        promotions_id: 0,
      },
      total: 0,
      goodData: [],
      cateData: [],
      cateDataMore: [],
      currentCate: '-1', //分类的当前id；
      currentTab: '2',
      codeNum: '',
      payNum: '',
      userInfo: {},
      storeInfos: {}, //门店店员信息
      storeList: [], //门店列表
      attr: {
        productAttr: [],
        productSelect: {},
      },
      storeInfo: {}, //商品信息
      productValue: [],
      attrValue: '', //已选属性
      productId: 0, //产品id
      seckillId: 0, //秒杀商品id
      cartList: [],
      isCart: 0,
      is_dingzhi:false,
      cartInfo: {
        //更改属性所需参数
        cart_id: 0,
        product_id: 0,
        unique: '',
      },
      modal: false,
      fapi: {},
      rule: [
        {
          type: 'input',
          field: 'remarks',
          title: '备注',
          props: {
            type: 'textarea',
            maxlength: 100,
            'show-word-limit': true,
          },
        },
      ],
      rule2: [
        {
          type: 'InputNumber',
          field: 'change_price',
          title: '实付款',
          value: 0,
          props: {
            min: 0,
          },
        },
      ],
      integral: false, //是否使用积分
      coupon: false, //是否使用优惠券
      couponId: 0, //优惠券id
      couponTargetItem: null, // 当前选择优惠券的购物车行
      modalPay: false,
      payTypeModal: false,
      cartSum: 0,
      priceInfo: {},
      createOrder: {
        sendAll: {
          'product':[],
          'coupon':[]
        },
        new: 0,
        dingzhi:0,
        is_budan:0,
        budan_time:'',
        is_gendan:0,
        gendan_staff_id:0,
        gendan_staff_name:'',
        giveIds:[],
        combination_info:[],
        remarkInfo:{
          water_number:'',
          remark:''
        },
        cash_choose:0,
        source:0,
        remarks: '',
        change_price: 0,
        cart_id: [], // 购物车id
        userCode: '',
        is_price: 0,
        auth_code: '',
        cart_info: []
      },
      modalCash: false,
      numList: ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '00', '.'],
      collectionArray: [],
      collection: 0,
      isOrderCreate: 0,
      discount: false,
      payType: '', // 支付方式
      orderId: '', //订单id
      seckillOrderId: '', //秒杀订单id
      clientHeight: 0,
      cartHeight: 0,
      goodsHeight: 0,
      invalidList: [],
      promotionsList: [],
      defaultcalc: false,
      orderSystem: {
        loadingMsg: null,
        timer: null,
      },
      disabled: false, //阻止属性弹窗多次提交
      unchangedPrice: 0,
      cumping: false, //加减节流
      modalUserInfo: {}, //搜索出来的用户信息
      userInfoShow: false, //扫码枪搜索用户弹窗状态
      settleVisible: false,
      payList: [
        {
          id:1,
          label: '微信/支付宝',
          value: '',
          status: true,
          icon: '',
          num:3 //显示3组样式环视2组样式
        },
        {
          id:2,
          label: '现金收款',
          value: 'cash',
          status: true,
          icon: 'iconicon_cash',
          num:3
        },
        {
          id:3,
          label: '余额收款',
          value: 'yue',
          status: true,
          icon: 'icona-icon_yue',
          num:3
        },
        {
          id:4,
          label: '组合收款',
          value: 'combination',
          status: true,
          icon: 'iconicon_cash',
          num:4
        },
      ],
      shadow: 0,
      rechargeVisible: false,
      settleMoney: 0,
      yueVerify: false,
      activityTypeArr: [],
      swiper: null,
      swiperClickedIndex: 0,
      swiperOption: {
        slidesPerView: 'auto',
        spaceBetween: 14,
        setWrapperSize: true,
      },
      rechargeData: {},
      zIndex: 9999,
      relation_id: 0,
      orderCartInfo:[],//用户订单改价的购物车信息（会变化）
      reservationCart:1, //预约商品 1是直接下单 2是加入购物车
      submitData:{}, //改价后数据
      cardHolderShow: false,
      cardHolderData: {},
      headerList: [
        {id:2,name:'项目',product_type:'6'},
        {id:3,name:'卡项',product_type:'4,5'},
        {id:1,name:'产品',product_type:'0'},
        // {id:4,name:'无码商品',product_type:''}
      ],
      activeID:2,//点击搜索旁边的头部，获取商品类型
      picwidth:0+'px',
      picmargin:'7px',
      picPd:'10px',
      showCate:true,
      productType:'6',
      appointNum:0,
      cardNum:0,
      isRecharge:0,
      hideYuePayOption: false,
      forceCombinationPay: false,
      lockSettleYueEdit: false,
      initCombinationInfo: [],
      cardUpgradeVisible: false,
      cardUpgradeLoading: false,
      cardUpgradeTarget: null,
      cardUpgradeList: [],
      cardUpgradeSelectedKey: '',
      cardUpgradeSearch: '',
      yuePaySetPriceTimer: null,
      debtReminderVisible: false,
      debtRepayVisible: false,
      debtRepayRow: {},
      isDebtRepay: 0,
      lockDebtRepaySource: false,
      debtRepayInitialSource: 0,
      debtRepayData: {},
      debtPaySetPriceTimer: null,
    };
  },
  computed: {
    currentStoreName() {
      return util.cookies.get('pageTitle') || (this.storeInfos && this.storeInfos.store_name) || '';
    },
    // 过滤后的卡片列表
    filteredCardUpgradeList() {
      if (!this.cardUpgradeSearch) {
        return this.cardUpgradeList;
      }
      const search = this.cardUpgradeSearch.toLowerCase();
      return this.cardUpgradeList.filter(card => {
        const name = (card.store_name || card.product_name || '').toLowerCase();
        const orderId = (card.order_id || '').toString().toLowerCase();
        return name.includes(search) || orderId.includes(search);
      });
    },
    /** 购物车是否含定制卡 */
    cartHasCustomCard() {
      const list = this.cartList || [];
      for (let i = 0; i < list.length; i++) {
        const cart = (list[i] && list[i].cart) || [];
        for (let j = 0; j < cart.length; j++) {
          if (this.isCustomCardProduct(cart[j])) return true;
        }
      }
      return false;
    },
    /** 前台收银是否允许操作员手动调整赠送（关闭时自动使用预设配置） */
    cashierOperatorGiftEnabled() {
      const v = this.priceInfo && this.priceInfo.cashier_operator_gift_switch;
      return v === undefined || v === null || v === '' ? true : Number(v) === 1;
    },
    /** 前台收银是否允许欠款 */
    cashierDebtPayEnabled() {
      const v = this.priceInfo && this.priceInfo.cashier_debt_pay_switch;
      return Number(v) === 1;
    },
  },
  watch: {
    goodData(value) {
      this.$nextTick(() => {
        if (value.length) {
          this.goodsHeight =
              this.$refs.listWrap.querySelector('.picture').clientWidth;
        }
      });
    },
    settleVisible(val) {
      this.collection = 0;
      this.collectionArray = [];
      this.isOrderCreate = 0;
      if (!val && this.isDebtRepay) {
        this.isDebtRepay = 0;
        this.lockDebtRepaySource = false;
        this.debtRepayInitialSource = 0;
        this.debtRepayData = {};
      }
    }
  },
  async created() {
    let clientWidth = document.documentElement.clientWidth;
    let pageLimt;
    if (clientWidth > 2260) {
      pageLimt = 30;
    } else if (clientWidth > 1580) {
      pageLimt = 30;
    } else if (clientWidth > 1270) {
      pageLimt = 30;
    } else {
      pageLimt = 30;
    }
    this.goodFrom.limit = pageLimt;
    this.activityFrom.limit = pageLimt;
    this.userInfo = JSON.parse(window.localStorage.getItem('cashierUser')) || {};
    if (!this.userInfo.uid) {
      this.setUp();
    }
    this.product_category_status = window.localStorage.getItem("product_category_status") || 0;
    if (this.product_category_status != 0) {
      this.relation_id = window.localStorage.getItem("store_id") || 0;
    }
    this.cateList();
    this.cateListMore();
    this.goodList();
    if (this.$route.query.uid || this.$route.query.tourist_uid) {
      let uid = this.$route.query.uid,
          touristId = this.$route.query.tourist_uid,
          staffId = this.$route.query.staff_id,
          index = this.$route.query.index;
      this.checkOut = 0;
      this.activeHangon = index;
      this.storeInfos.id = staffId;
      this.userInfo.uid = uid;
      this.userInfo.touristId = touristId
      let data = {
        uid,
      };
      if (uid != 0) {
        this.userInfoData(data, true);
        this.getSwithUser(data);
      } else {
        this.setUp(touristId, true);
        if (touristId) {
          this.getSwithUser({ tourist_uid: touristId });
        }
      }
    } else if (this.userInfo.uid) {
      this.getSwithUser({ uid: this.userInfo.uid });
      this.userInfoData({ uid: this.userInfo.uid }, true);
      const db = await this.$store.dispatch("store/db/database", {
        user: true,
      });
      const userInfo = db?db.get('cashier_user_info').value():{id:0};
      this.goodFrom.staff_id = userInfo.id;
    } else if (this.userInfo.touristId) {
      this.getSwithUser({ tourist_uid: this.userInfo.touristId });
    }
    try {
      //打开副屏
      window.Jsbridge.invoke('collectLoginSuccess',JSON.stringify({'p1-key':'p1-value'}));
    }catch (e){
    }
  },
  mounted() {
    this.$nextTick(() => {
      this.$refs.input.focus();
      document.addEventListener('keydown', this.handleKeydown);
      this.getPic();
    });
  },
  beforeDestroy() {
    document.removeEventListener('keydown', this.handleKeydown);
  },
  methods: {
    isCardLikeProduct(item) {
      // 仅“卡项/次卡”走“只能单独购买”的限制
      if (!item) return false;
      const productType = Number(item.product_type);
      return productType === 4 || productType === 5;
    },
    resolveProductName(item) {
      if (!item) return '';
      return String((item.productInfo && item.productInfo.store_name) || item.store_name || '');
    },
    isGiftProjectShell(item) {
      return this.resolveProductName(item) === '赠送项目';
    },
    cartHasGiftProjectShell() {
      const list = this.cartList || [];
      for (let i = 0; i < list.length; i++) {
        const cart = (list[i] && list[i].cart) || [];
        for (let j = 0; j < cart.length; j++) {
          if (this.isGiftProjectShell(cart[j])) return true;
        }
      }
      return false;
    },
    getGiftProjectCartItem() {
      const list = this.cartList || [];
      for (let i = 0; i < list.length; i++) {
        const cart = (list[i] && list[i].cart) || [];
        for (let j = 0; j < cart.length; j++) {
          if (this.isGiftProjectShell(cart[j])) return cart[j];
        }
      }
      return null;
    },
    cartHasCustomCardShellInCart() {
      const list = this.cartList || [];
      for (let i = 0; i < list.length; i++) {
        const cart = (list[i] && list[i].cart) || [];
        for (let j = 0; j < cart.length; j++) {
          if (this.isCustomCardShell(cart[j])) return true;
        }
      }
      return false;
    },
    getSendAllProductIds() {
      const products = (this.createOrder.sendAll && this.createOrder.sendAll.product) || [];
      return products
        .map((row) => Number(row && row.id))
        .filter((id) => id > 0);
    },
    getGiftProjectSelectedProducts() {
      if (Array.isArray(this.giftProjectSelectedProducts) && this.giftProjectSelectedProducts.length) {
        return this.giftProjectSelectedProducts;
      }
      if (Array.isArray(this.selectedProduct) && this.selectedProduct.length) {
        return this.selectedProduct;
      }
      if (this.cartHasGiftProjectShell()) {
        return this.getSendAllProductIds();
      }
      return [];
    },
    applyGiftProjectSelectedToAttr() {
      if (!this.isGiftProjectShell(this.storeInfo)) {
        return;
      }
      const saved = this.giftProjectSelectedProducts.length
        ? this.giftProjectSelectedProducts
        : (this.selectedProduct.length ? this.selectedProduct : this.getSendAllProductIds());
      if (!saved.length) {
        return;
      }
      const picked = saved.slice();
      this.giftProjectSelectedProducts = picked;
      this.selectedProduct = picked;
      if (this.attr && this.attr.productSelect) {
        this.$set(this.attr.productSelect, 'selectedProduct', picked);
      }
    },
    openGiftProjectPicker(item) {
      if (!item || !this.userInfo || this.userInfo.uid < 0) {
        return this.$Message.error('请添加或选择用户');
      }
      this.disabled = false;
      this.isCart = 1;
      this.cartInfo.cart_id = item.id;
      this.cartInfo.product_id = item.product_id;
      this.productId = item.product_id;
      this.$refs.attrs.productType = item.product_type;
      this.$refs.attrs.modals = true;
      this.goodsInfo(item.product_id);
    },
    /** 定制卡壳行（展示/余额/改价用；内项 pid=8154 的子项目不算壳） */
    isCustomCardShell(item) {
      if (!item) return false;
      if (item.true_dingzhi) return true;
      const productId = Number(item.product_id || 0);
      const pInfoId = Number(item.productInfo && (item.productInfo.id || 0));
      const pInfoPid = Number(item.productInfo && (item.productInfo.pid || 0));
      const name = String((item.productInfo && item.productInfo.store_name) || '');
      if (productId === 8154 || pInfoId === 8154) return true;
      // 门店侧定制卡壳可能仅挂 pid=8154，以商品名「定制卡」识别
      if (pInfoPid === 8154 && name.indexOf('定制卡') !== -1) return true;
      return false;
    },
    isCustomCardProduct(item) {
      if (!item) return false;
      const productId = Number(item.product_id || item.id || 0);
      const pid = Number(item.pid || 0);
      const pInfoId = Number(item.productInfo && (item.productInfo.id || 0));
      const pInfoPid = Number(item.productInfo && (item.productInfo.pid || 0));
      // 定制卡家族：8154 壳及其子商品
      return productId === 8154 || pid === 8154 || pInfoId === 8154 || pInfoPid === 8154;
    },
    /** 定制卡内的品项（有定制卡时除卡壳外的商品，含 pid=8154 子商品） */
    isCustomCardBundleItem(item) {
      return this.cartHasCustomCard && !this.isCustomCardShell(item);
    },
    /** 是否展示业绩操作区（定制卡内项目不展示，仅定制卡壳或普通品项） */
    showCartItemYeji(item) {
      if (!item) return false;
      if (this.isCustomCardBundleItem(item)) return false;
      if (this.cartHasCustomCard && this.isCustomCardShell(item)) return true;
      return !!item.is_dingzhi;
    },
    /** 余额/卡升级：仅定制卡壳或普通品项，定制卡内项目不可用 */
    showCartItemPayRow(item) {
      if (!item) return false;
      if (this.isCustomCardBundleItem(item)) return false;
      if (this.cartHasCustomCard && this.isCustomCardShell(item)) return true;
      return !!item.is_dingzhi;
    },
    /** 优惠券：定制卡挂在内部各项目；无定制卡时各行（项目/卡项）可选券 */
    showCartItemCoupon(item) {
      if (!item) return false;
      if (this.cartHasCustomCard) {
        return this.isCustomCardBundleItem(item);
      }
      if (this.isCustomCardShell(item)) return false;
      return !!item.is_dingzhi;
    },
    isYejiServiceTarget(row) {
      if (!row || Number(row.product_type) !== 6 || row.true_dingzhi) return false;
      if (this.cartHasCustomCard) return false;
      return !!row.is_dingzhi;
    },
    isYejiSaleTarget(row) {
      if (!row) return false;
      if (this.isCustomCardBundleItem(row)) return false;
      if (this.isCustomCardShell(row)) return !!row.is_dingzhi;
      return !!row.is_dingzhi;
    },
    isCardUpgradeEligible(item) {
      // 卡升级按钮：卡项/次卡 + 定制卡 都可用
      return this.isCardLikeProduct(item) || this.isCustomCardProduct(item);
    },
    /** 项目订单（无定制卡）下单/核销需传服务对象，与核销页一致 */
    projectServiceObjectPayload(item3) {
      if (!item3 || Number(item3.product_type) !== 6 || this.cartHasCustomCard) {
        return {};
      }
      const raw = String(item3.service_object || '本人').trim();
      const v = raw === '朋友' ? '朋友' : '本人';
      return { service_object: v };
    },
    setCartItemServiceObject(item, value) {
      if (!item) return;
      const v = value === '朋友' ? '朋友' : '本人';
      this.$set(item, 'service_object', v);
      if (this.yuePaySetPriceTimer) clearTimeout(this.yuePaySetPriceTimer);
      this.yuePaySetPriceTimer = setTimeout(() => this.setPrice(), 80);
    },
    toggleItemYuePay(item) {
      if (!item) return;
      if (this.isCustomCardBundleItem(item)) return;
      if (typeof item.show_yue_pay === 'undefined') this.$set(item, 'show_yue_pay', false);
      if (typeof item.yue_pay_amount === 'undefined') this.$set(item, 'yue_pay_amount', '');
      this.$set(item, 'show_yue_pay',true);
      if (item.show_yue_pay) this.onItemYuePayChange(item);
    },
    ensureCardUpgradeFields(item) {
      if (!item) return;
      if (typeof item.card_upgrade_enabled === 'undefined') this.$set(item, 'card_upgrade_enabled', false);
      if (typeof item.card_upgrade_amount === 'undefined') this.$set(item, 'card_upgrade_amount', 0);
      if (typeof item.card_upgrade_old_oid === 'undefined') this.$set(item, 'card_upgrade_old_oid', 0);
      if (typeof item.card_upgrade_old_cart_info_id === 'undefined') this.$set(item, 'card_upgrade_old_cart_info_id', 0);
      if (typeof item.card_upgrade_old_label === 'undefined') this.$set(item, 'card_upgrade_old_label', '');
    },
    async openCardUpgrade(item) {
      if (!item) return;
      if (this.isCustomCardBundleItem(item)) return;
      if (!this.userInfo || !this.userInfo.uid) {
        this.$Message.warning('请先选择会员');
        return;
      }
      this.ensureCardUpgradeFields(item);
      this.cardUpgradeTarget = item;
      this.cardUpgradeVisible = true;
      this.cardUpgradeLoading = true;
      this.cardUpgradeSelectedKey = '';
      try {
        const res = await cashierValidCardUpgradeList(this.userInfo.uid);
        const list = (res && res.data) ? res.data : [];
        // 给每条旧卡生成一个稳定 key（用于 RadioGroup）
        this.cardUpgradeList = (list || []).map((c) => ({
          ...c,
          _key: String([c.oid || 0, c.cart_info_id || c.cartInfoId || 0, c.product_id || 0].join('_'))
        }));

        // 检查是否已有卡升级选择，如果有，设置对应的卡片为选中状态
        if (item.card_upgrade_enabled && item.card_upgrade_old_oid > 0) {
          const existingCard = this.cardUpgradeList.find(card =>
            card.oid === item.card_upgrade_old_oid &&
            (card.cart_info_id === item.card_upgrade_old_cart_info_id ||
             card.cartInfoId === item.card_upgrade_old_cart_info_id)
          );
          if (existingCard) {
            this.cardUpgradeSelectedKey = existingCard._key;
          }
        }
      } catch (e) {
        this.cardUpgradeList = [];
        this.$Message.error((e && e.msg) || '获取旧卡列表失败');
      } finally {
        this.cardUpgradeLoading = false;
      }
    },
    closeCardUpgradeModal() {
      this.cardUpgradeVisible = false;
      this.cardUpgradeTarget = null;
      this.cardUpgradeList = [];
      this.cardUpgradeSelectedKey = '';
      this.cardUpgradeLoading = false;
      this.cardUpgradeSearch = '';
      this.cardUpgradeCurrentPage = 1;
    },

    // 选择卡片
    selectCardUpgrade(key) {
      this.cardUpgradeSelectedKey = key;
    },

    // 搜索卡片
    handleCardUpgradeSearch() {
      // 搜索时不需要重置页码，因为已经移除了分页
    },

    /** 定制卡内品项单价（用于行总价=单价×数量） */
    resolveBundleItemUnitPrice(item) {
      if (!item) return 0;
      let unit = item.truePrice !== undefined && item.truePrice !== null && item.truePrice !== ''
        ? Number(item.truePrice)
        : NaN;
      if (isNaN(unit) || unit <= 0) {
        unit = Number(item.sum_price || item.price || 0);
      }
      if (isNaN(unit) || unit <= 0) {
        const attr = item.productInfo && item.productInfo.attrInfo;
        unit = Number((attr && attr.price) || (item.productInfo && item.productInfo.price) || 0);
      }
      return isNaN(unit) || unit < 0 ? 0 : unit;
    },
    /** 改价快照可能是单价或行总价，统一为行总价（单价×数量） */
    normalizeBundleLineTotal(item, rawLineAmount) {
      const qty = Math.max(Number(item && item.cart_num ? item.cart_num : 1), 1);
      const val = Number(rawLineAmount);
      if (isNaN(val) || val < 0) return 0;
      const unit = this.resolveBundleItemUnitPrice(item);
      if (qty <= 1 || unit <= 0) {
        return Number(Math.max(0, val).toFixed(2));
      }
      const expectedLine = Number((unit * qty).toFixed(2));
      if (val >= expectedLine - 0.02) {
        return Number(Math.max(0, val).toFixed(2));
      }
      if (Math.abs(val - unit) < 0.02) {
        return expectedLine;
      }
      return Number(Math.max(0, val).toFixed(2));
    },
    /** 定制卡内品项行金额（不含定制卡壳本身） */
    getBundleItemLineAmount(item) {
      if (!item || this.isCustomCardShell(item)) return 0;
      if (Number(this.createOrder && this.createOrder.is_price) === 1) {
        if (item.change_price !== undefined && item.change_price !== null && item.change_price !== '') {
          return this.normalizeBundleLineTotal(item, item.change_price);
        }
        if (item.before_price !== undefined && item.before_price !== null && item.before_price !== '') {
          return this.normalizeBundleLineTotal(item, item.before_price);
        }
      }
      const qty = Math.max(Number(item.cart_num || 1), 1);
      const unit = this.resolveBundleItemUnitPrice(item);
      return Number((unit * qty).toFixed(2));
    },
    /** 定制卡内品项行金额（券后，不含定制卡壳本身） */
    getBundleItemLineAmountAfterCoupon(item) {
      if (!item || this.isCustomCardShell(item)) return 0;
      const goodsAmount = this.getBundleItemLineAmount(item);
      const couponDeduct = this.getItemCouponDeduct(item);
      return Math.max(0, Number((goodsAmount - couponDeduct).toFixed(2)));
    },
    /** 定制卡内品项行金额之和（券前，用于下单改价） */
    getCustomCardBundlePreCouponTotal() {
      if (!this.cartHasCustomCard) return 0;
      let total = 0;
      (this.cartList || []).forEach((group) => {
        (group.cart || []).forEach((item) => {
          if (!this.isCustomCardShell(item)) {
            total += this.getBundleItemLineAmount(item);
          }
        });
      });
      return Number(Math.max(0, total).toFixed(2));
    },
    /** 定制卡展示价 = 内部各品项券后行金额之和 */
    getCustomCardBundleTotal() {
      if (!this.cartHasCustomCard) return 0;
      let total = 0;
      (this.cartList || []).forEach((group) => {
        (group.cart || []).forEach((item) => {
          if (!this.isCustomCardShell(item)) {
            total += this.getBundleItemLineAmountAfterCoupon(item);
          }
        });
      });
      return Number(Math.max(0, total).toFixed(2));
    },
    /** 当前行「商品应付」：未改价为 单价×数量；改价后为改价行总价（已含数量） */
    getItemLineGoodsAmount(item) {
      if (!item) return 0;
      if (this.isCustomCardShell(item)) {
        return this.getCustomCardBundleTotal();
      }
      if (Number(this.createOrder && this.createOrder.is_price) === 1) {
        const line = Number(item.change_price || item.sum_price || 0);
        return Number(Math.max(0, line).toFixed(2));
      }
      const qty = Math.max(Number(item.cart_num || 1), 1);
      let unit = item.truePrice !== undefined && item.truePrice !== null && item.truePrice !== ''
        ? Number(item.truePrice)
        : NaN;
      if (isNaN(unit)) {
        unit = Number(item.sum_price || item.price || 0);
      }
      if (isNaN(unit)) unit = 0;
      return Number((unit * qty).toFixed(2));
    },
    getItemCouponDeduct(item) {
      if (!item) return 0;
      if (item.coupon_info && item.coupon_info.coupon_amount) {
        return Number(item.coupon_info.coupon_amount) || 0;
      }
      return Number(item.coupon_price || 0) || 0;
    },
    getItemLineAmountAfterCoupon(item) {
      return Math.max(0, Number((this.getItemLineGoodsAmount(item) - this.getItemCouponDeduct(item)).toFixed(2)));
    },
    buildCartCoupons() {
      const list = [];
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => {
          if (good && good.coupon_id && !this.isCustomCardShell(good)) {
            list.push({ cart_id: good.id, coupon_id: good.coupon_id });
          }
        });
      });
      return list;
    },
    getUsedCouponIds(excludeCartId) {
      const ids = [];
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => {
          if (good && good.coupon_id && good.id !== excludeCartId) {
            ids.push(good.coupon_id);
          }
        });
      });
      return ids;
    },

    // 调整余额支付金额，确保与卡升级金额总和不超过商品金额
    adjustYuePayWithCardUpgrade(item) {
      if (!item) return;

      const price = this.getItemLineGoodsAmount(item);
      const cardUpgradeAmount = Number(item.card_upgrade_amount || 0);
      const yuePayAmount = Number(item.yue_pay_amount || 0);

      // 计算剩余可支付金额
      const remainingAmount = price - cardUpgradeAmount;

      // 如果余额支付金额超过剩余可支付金额，调整余额支付金额
      if (yuePayAmount > remainingAmount) {
        if (remainingAmount > 0) {
          this.$set(item, 'yue_pay_amount', remainingAmount.toFixed(2));
        } else {
          this.$set(item, 'yue_pay_amount', '');
        }
      }
    },

    /** 选中优惠券后需清空所有明细上的余额支付与卡升级，避免与券后金额不一致 */
    clearCartYuePayAndCardUpgrade() {
      if (this.yuePaySetPriceTimer) {
        clearTimeout(this.yuePaySetPriceTimer);
        this.yuePaySetPriceTimer = null;
      }
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => {
          if (!good) return;
          this.$set(good, 'show_yue_pay', false);
          this.$set(good, 'yue_pay_amount', '');
          this.$set(good, 'card_upgrade_enabled', false);
          this.$set(good, 'card_upgrade_amount', 0);
          this.$set(good, 'card_upgrade_old_oid', 0);
          this.$set(good, 'card_upgrade_old_cart_info_id', 0);
          this.$set(good, 'card_upgrade_old_label', '');
          this.$set(good, 'show_debt_pay', false);
          this.$set(good, 'debt_pay_amount', '');
        });
      });
      if (this.cardUpgradeVisible) {
        this.closeCardUpgradeModal();
      }
    },

    // 取消卡升级
    cancelCardUpgrade(item) {
      if (!item) return;

      // 重置卡升级相关字段
      this.$set(item, 'card_upgrade_enabled', false);
      this.$set(item, 'card_upgrade_amount', 0);
      this.$set(item, 'card_upgrade_old_oid', 0);
      this.$set(item, 'card_upgrade_old_cart_info_id', 0);
      this.$set(item, 'card_upgrade_old_label', '');

      // 刷新价格/下单 payload
      this.setPrice();
    },
    confirmCardUpgrade() {
      const item = this.cardUpgradeTarget;
      if (!item) return;
      const selected = (this.cardUpgradeList || []).find((c) => String(c._key) === String(this.cardUpgradeSelectedKey));
      if (!selected) {
        this.$Message.warning('请选择旧卡');
        return;
      }
      const price = this.getItemLineGoodsAmount(item);
      const remainValue = Number(selected.remain_value || 0);
      const T = this.getOrderBalanceAndUpgradeCap();
      const totalYue = this.getCartYuePayTotal();
      const upgradeOthers = this.getCardUpgradeTotalExcludingItem(item);
      const globalCap = Math.max(0, Number((T - totalYue - upgradeOthers).toFixed(2)));
      const deduct = Number(Math.min(price, remainValue, globalCap).toFixed(2));
      this.ensureCardUpgradeFields(item);
      this.$set(item, 'card_upgrade_enabled', deduct > 0);
      this.$set(item, 'card_upgrade_amount', deduct);
      this.$set(item, 'card_upgrade_old_oid', Number(selected.oid || 0));
      this.$set(item, 'card_upgrade_old_cart_info_id', Number(selected.cart_info_id || selected.cartInfoId || 0));
      this.$set(item, 'card_upgrade_old_label', selected.store_name || selected.product_name || '旧卡');

      // 卡升级和余额支付可以同时使用，需要调整余额支付金额
      this.adjustYuePayWithCardUpgrade(item);

      // 刷新价格/下单 payload
      this.setPrice();
      this.closeCardUpgradeModal();
    },
    getCartCardUpgradeTotal() {
      let total = 0;
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => {
          const v = Number(good && good.card_upgrade_enabled ? good.card_upgrade_amount : 0);
          if (!isNaN(v) && v > 0) total += v;
        });
      });
      return Number(total.toFixed(2));
    },
    /** 除指定明细外，其他行的卡升级金额合计 */
    getCardUpgradeTotalExcludingItem(excludeItem) {
      let t = 0;
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => {
          if (!good || (excludeItem && good.id === excludeItem.id)) return;
          if (good.card_upgrade_enabled) {
            const v = Number(good.card_upgrade_amount || 0);
            if (!isNaN(v) && v > 0) t += v;
          }
        });
      });
      return Number(t.toFixed(2));
    },
    /**
     * 券/积分等之后的应付（与结算 payPrice 一致）：
     * 全单「余额支付合计 + 卡升级合计」不得超过该值（如 800 减 50 券 → 750）
     */
    getOrderBalanceAndUpgradeCap() {
      const p =
        this.priceInfo && this.priceInfo.payPrice != null && this.priceInfo.payPrice !== ''
          ? Number(this.priceInfo.payPrice)
          : 0;
      if (Number.isNaN(p)) return 0;
      return Math.max(0, Number(p.toFixed(2)));
    },
    getFirstCardUpgradeItem() {
      for (let i = 0; i < this.cartList.length; i++) {
        const cart = this.cartList[i].cart || [];
        for (let j = 0; j < cart.length; j++) {
          const good = cart[j];
          if (good && good.card_upgrade_enabled && Number(good.card_upgrade_amount || 0) > 0) return good;
        }
      }
      return null;
    },
    clampCardUpgrade(item) {
      if (!item) return;
      if (!item.card_upgrade_enabled) return;
      const lineCap = this.getItemLineAmountAfterCoupon(item);
      let v = Number(item.card_upgrade_amount || 0);
      if (isNaN(v) || v <= 0) {
        this.$set(item, 'card_upgrade_enabled', false);
        this.$set(item, 'card_upgrade_amount', 0);
        return;
      }
      const T = this.getOrderBalanceAndUpgradeCap();
      const totalYue = this.getCartYuePayTotal();
      const upgradeOthers = this.getCardUpgradeTotalExcludingItem(item);
      const globalCap = Math.max(0, Number((T - totalYue - upgradeOthers).toFixed(2)));
      let cap = Math.min(lineCap, globalCap);
      if (isNaN(cap)) cap = 0;
      if (v > cap) v = cap;
      this.$set(item, 'card_upgrade_amount', Number(v.toFixed(2)));
      if (Number(item.card_upgrade_amount) <= 0) this.$set(item, 'card_upgrade_enabled', false);
    },
    clampAllCardUpgrade() {
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => this.clampCardUpgrade(good));
      });
    },
    getItemPayCap(item) {
      const cap = this.getItemLineAmountAfterCoupon(item);
      return isNaN(cap) ? 0 : Number(cap.toFixed(2));
    },
    getItemYuePayCap(item) {
      // 单品上限 + 会员余额总上限 + 全单「券后应付」下余额+卡升级总上限
      const itemCap = this.getItemPayCap(item) - Number(item.card_upgrade_amount || 0);
      const nowMoney = Number(this.userInfo && this.userInfo.now_money ? this.userInfo.now_money : 0);
      let otherTotal = 0;
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => {
          if (!good || good.id === (item && item.id)) return;
          const v = Number(good.yue_pay_amount || 0);
          if (!isNaN(v) && v > 0) otherTotal += v;
        });
      });
      const remain = nowMoney - otherTotal;
      const yueCap = remain > 0 ? remain : 0;
      const T = this.getOrderBalanceAndUpgradeCap();
      const totalUpgrade = this.getCartCardUpgradeTotal();
      const globalYueCap = Math.max(0, Number((T - totalUpgrade - otherTotal).toFixed(2)));
      return Number(Math.max(0, Math.min(itemCap, yueCap, globalYueCap)).toFixed(2));
    },
    normalizeMoneyInput(val) {
      if (val === null || typeof val === 'undefined') return '';
      let s = String(val);
      s = s.replace(/[^\d.]/g, '');
      s = s.replace(/\.{2,}/g, '.');
      s = s.replace(/^\./g, '0.');
      const parts = s.split('.');
      if (parts.length > 2) s = parts[0] + '.' + parts.slice(1).join('');
      // 整数部分最多8位（小数点不计入“位数”）
      if (s.includes('.')) {
        const [a, b] = s.split('.');
        s = a.slice(0, 8) + '.' + (b || '');
      } else {
        s = s.slice(0, 8);
      }
      if (s.includes('.')) {
        const [a, b] = s.split('.');
        s = a + '.' + (b || '').slice(0, 2);
      }
      return s;
    },
    clampItemYuePay(item, incomingVal) {
      if (!item) return;
      if (this.isCustomCardBundleItem(item)) {
        this.$set(item, 'yue_pay_amount', '');
        this.$set(item, 'show_yue_pay', false);
        return '';
      }
      const cap = this.getItemYuePayCap(item);
      const raw = this.normalizeMoneyInput(
        typeof incomingVal === 'undefined' ? item.yue_pay_amount : incomingVal
      );
      if (raw === '') {
        this.$set(item, 'yue_pay_amount', '');
        return '';
      }

      // 允许中间态：以小数点结尾（例如 "12." / "0."）
      if (raw.endsWith('.')) {
        const intPart = raw.slice(0, -1);
        const intNum = intPart === '' ? 0 : Number(intPart);
        if (isNaN(intNum) || intNum < 0) {
          this.$set(item, 'yue_pay_amount', '');
          return '';
        }
        if (intNum > cap) {
          const next = cap.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
          this.$set(item, 'yue_pay_amount', next);
          return next;
        }
        this.$set(item, 'yue_pay_amount', raw);
        return raw;
      }

      let num = Number(raw);
      if (isNaN(num) || num < 0) num = 0;
      if (num > cap) num = cap;
      const next = num.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
      this.$set(item, 'yue_pay_amount', next);
      return next;
    },
    onItemYuePayChange(item, e) {
      // iView Input:
      // - @on-input 传入的是 value(string)
      // - @input.native 传入的是原生事件对象
      let incoming = item && item.yue_pay_amount;
      if (typeof e === 'string' || typeof e === 'number') {
        incoming = String(e);
      } else if (e && e.target && typeof e.target.value !== 'undefined') {
        incoming = e.target.value;
      }
      const next = this.clampItemYuePay(item, incoming);
      // 同步回写原生输入框的展示值（仅原生事件时可用）
      if (e && e.target && typeof e.target.value !== 'undefined' && e.target.value !== next) {
        e.target.value = next;
      }
      // 余额支付变动需要实时更新 createOrder.cart_info（用于下单传后端）
      if (this.yuePaySetPriceTimer) clearTimeout(this.yuePaySetPriceTimer);
      this.yuePaySetPriceTimer = setTimeout(() => {
        this.setPrice();
      }, 100);
    },
    getCartYuePayTotal() {
      let total = 0;
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => {
          const v = Number(good && good.yue_pay_amount ? good.yue_pay_amount : 0);
          if (!isNaN(v) && v > 0) total += v;
        });
      });
      return Number(total.toFixed(2));
    },
    getCartDebtPayTotal() {
      let total = 0;
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => {
          const v = Number(good && good.debt_pay_amount ? good.debt_pay_amount : 0);
          if (!isNaN(v) && v > 0) total += v;
        });
      });
      return Number(total.toFixed(2));
    },
    ensureDebtPayFields(item) {
      if (!item) return;
      if (typeof item.show_debt_pay === 'undefined') this.$set(item, 'show_debt_pay', false);
      if (typeof item.debt_pay_amount === 'undefined') this.$set(item, 'debt_pay_amount', '');
    },
    toggleItemDebtPay(item) {
      this.ensureDebtPayFields(item);
      this.$set(item, 'show_debt_pay', !item.show_debt_pay);
      if (!item.show_debt_pay) {
        this.$set(item, 'debt_pay_amount', '');
      } else {
        this.onItemDebtPayChange(item);
      }
    },
    getItemDebtPayCap(item) {
      const itemCap = this.getItemPayCap(item)
        - Number(item.card_upgrade_amount || 0)
        - Number(item.yue_pay_amount || 0);
      const T = this.getOrderBalanceAndUpgradeCap();
      const totalYue = this.getCartYuePayTotal();
      const totalUpgrade = this.getCartCardUpgradeTotal();
      const totalDebt = this.getCartDebtPayTotal();
      let otherDebt = 0;
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => {
          if (!good || good.id === (item && item.id)) return;
          const v = Number(good.debt_pay_amount || 0);
          if (!isNaN(v) && v > 0) otherDebt += v;
        });
      });
      const globalCap = Math.max(0, Number((T - totalYue - totalUpgrade - otherDebt).toFixed(2)));
      return Number(Math.max(0, Math.min(itemCap, globalCap)).toFixed(2));
    },
    clampItemDebtPay(item, incomingVal) {
      if (!item) return;
      if (this.isCustomCardBundleItem(item)) {
        this.$set(item, 'debt_pay_amount', '');
        this.$set(item, 'show_debt_pay', false);
        return '';
      }
      const cap = this.getItemDebtPayCap(item);
      const raw = this.normalizeMoneyInput(
        typeof incomingVal === 'undefined' ? item.debt_pay_amount : incomingVal
      );
      if (raw === '') {
        this.$set(item, 'debt_pay_amount', '');
        return '';
      }
      let next = raw;
      if (Number(next) > cap) next = cap > 0 ? String(cap.toFixed(2)) : '';
      this.$set(item, 'debt_pay_amount', next);
      return next;
    },
    onItemDebtPayChange(item, e) {
      const incoming = e && e.target ? e.target.value : (item && item.debt_pay_amount);
      const next = this.clampItemDebtPay(item, incoming);
      if (e && e.target && typeof e.target.value !== 'undefined' && e.target.value !== next) {
        e.target.value = next;
      }
      if (this.debtPaySetPriceTimer) clearTimeout(this.debtPaySetPriceTimer);
      this.debtPaySetPriceTimer = setTimeout(() => this.setPrice(), 100);
    },
    clampAllItemDebtPay() {
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => this.clampItemDebtPay(good));
      });
    },
    clampAllItemYuePay() {
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => this.clampItemYuePay(good));
      });
    },
    cartHasCardLike() {
      for (let i = 0; i < this.cartList.length; i++) {
        for (let j = 0; j < this.cartList[i].cart.length; j++) {
          if (this.isCardLikeProduct(this.cartList[i].cart[j])) return true;
        }
      }
      return false;
    },
    cartHasNonCardLike() {
      for (let i = 0; i < this.cartList.length; i++) {
        for (let j = 0; j < this.cartList[i].cart.length; j++) {
          if (!this.isCardLikeProduct(this.cartList[i].cart[j])) return true;
        }
      }
      return false;
    },
    getCartItemIds() {
      const ids = [];
      this.cartList.forEach((item) => {
        item.cart.forEach((good) => ids.push(good.id));
      });
      this.invalidList.forEach((item) => ids.push(item.id));
      return ids;
    },
    async clearCartSilently() {
      const ids = this.getCartItemIds();
      if (!ids.length) return;
      try {
        this.getSwithUser({ chang_cart_remove: 1 });
      } catch (e) {}
      try {
        await cashierCartDel(this.userInfo.uid, { ids });
        this.submitData = {};
        this.clear();
        this.invalidList = [];
        this.hangDataList();
      } catch (err) {
        this.$Message.error(err.msg || '清空购物车失败');
        throw err;
      }
    },
    showSend(){
      this.applyProductSendPreset();
      this.sendVisible=true;
    },
    applyProductSendPreset(force = false) {
      if (!force && this.sendAllDirty) {
        this.updateSendNum();
        return;
      }
      const merged = {
        product: [],
        coupon: []
      };
      const productMap = {};
      const couponMap = {};
      (this.cartList || []).forEach(group => {
        (group.cart || []).forEach(item => {
          const preset = item.send_config;
          if (!preset || (!(preset.product || []).length && !(preset.coupon || []).length)) return;
          const cartNum = Math.max(Number(item.cart_num) || 1, 1);
          (preset.product || []).forEach(row => {
            const key = `${row.id}_${row.product_type || 0}`;
            const rowNum = (Number(row.num) || 1) * cartNum;
            if (productMap[key]) {
              productMap[key].num += rowNum;
            } else {
              const copy = this.normalizeSendPresetProduct(row, rowNum);
              productMap[key] = copy;
              merged.product.push(copy);
            }
          });
          (preset.coupon || []).forEach(row => {
            const key = String(row.id);
            const rowNum = (Number(row.num) || 1) * cartNum;
            if (couponMap[key]) {
              couponMap[key].num += rowNum;
            } else {
              const copy = this.normalizeSendPresetCoupon(row, rowNum);
              couponMap[key] = copy;
              merged.coupon.push(copy);
            }
          });
        });
      });
      this.createOrder.sendAll = merged;
      this.updateSendNum();
    },
    updateSendNum() {
      let num = 0;
      (this.createOrder.sendAll.product || []).forEach(res => {
        num += Number(res.num) || 0;
      });
      (this.createOrder.sendAll.coupon || []).forEach(res => {
        num += Number(res.num) || 0;
      });
      this.sendNum = num;
    },
    normalizeSendPresetProduct(row, num) {
      const item = JSON.parse(JSON.stringify(row || {}));
      item.num = num || item.num || 1;
      return item;
    },
    normalizeSendPresetCoupon(row, num) {
      const item = JSON.parse(JSON.stringify(row || {}));
      item.num = num || item.num || 1;
      item.write_days = item.write_days || 1;
      if (!item.begin_time) {
        item.begin_time = this.todayDateSecondTimestamp();
      }
      if (item.write_days > 0) {
        item.end_time = this.getSendPresetEnd(item.begin_time, item.write_days);
      }
      item.begin_time_label = item.begin_time > 0
        ? this.formatSendPresetDate(item.begin_time)
        : '未设置';
      item.end_time_label = item.end_time > 0
        ? this.formatSendPresetDate(item.end_time)
        : '未设置';
      return item;
    },
    todayDateSecondTimestamp() {
      const now = new Date();
      const todayZero = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      return Math.floor(todayZero.getTime() / 1000);
    },
    formatSendPresetDate(timestamp) {
      const timeNum = Number(timestamp);
      if (!timestamp || isNaN(timeNum) || timeNum.toString().length !== 10) {
        return '--';
      }
      const date = new Date(timeNum * 1000);
      if (date.toString() === 'Invalid Date') {
        return '--';
      }
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    },
    getSendPresetEnd(startDate, days = 1) {
      const secTimestamp = Number(startDate);
      if (isNaN(secTimestamp) || secTimestamp.toString().length !== 10) {
        return 0;
      }
      const targetDate = new Date(secTimestamp * 1000);
      if (targetDate.toString() === 'Invalid Date') {
        return 0;
      }
      targetDate.setDate(targetDate.getDate() + Number(days));
      return Math.floor(targetDate.getTime() / 1000);
    },
    openGendanModal() {
      this.gendanVisible = true;
    },
    onGendanConfirm(data) {
      this.createOrder.gendan_staff_id = data.gendan_staff_id || 0;
      this.createOrder.gendan_staff_name = data.gendan_staff_name || '';
      this.createOrder.is_gendan = data.is_gendan || 0;
    },
    toPay(){
      let that=this;
      this.$router.push({
        path: `${Setting.roterPre}/verify/index`,
        query: {
          keyword: that.userInfo.phone,
        },
      });
    },
    doChoose(yeji){
      let hasAdd=false;
      let that=this;
      this.setYejiAll.forEach(function (item, index){
        if(item.cart_id == yeji.cart_id){
          hasAdd=true;
          that.setYejiAll[index]=yeji;
        }
      })
      if(!hasAdd){
        this.setYejiAll.push(yeji);
      }
      this.closeYeji();
    },
    doChooseService(yeji){
      let hasAdd=false;
      let that=this;
      this.serviceYejiAll.forEach(function (item, index){
        if(item.cart_id == yeji.cart_id){
          hasAdd=true;
          that.serviceYejiAll[index]=yeji;
        }
      })
      if(!hasAdd){
        this.serviceYejiAll.push(yeji);
      }
      this.closeYeji();
    },
    getFlatCartItems() {
      const items = [];
      (this.cartList || []).forEach((group) => {
        (group.cart || []).forEach((item) => items.push(item));
      });
      return items;
    },
    getCartItemCashBase(row) {
      const price = this.getItemLineGoodsAmount(row);
      const yuePay = Number(row.yue_pay_amount || 0) || 0;
      const cardUpgrade = Number(row && row.card_upgrade_enabled ? (row.card_upgrade_amount || 0) : 0) || 0;
      const couponDeduct = this.getItemCouponDeduct(row);
      const lineAfterCoupon = Math.max(Number((Number(price) - couponDeduct).toFixed(2)), 0);
      const cashBase = Math.max(Number((lineAfterCoupon - yuePay - cardUpgrade).toFixed(2)), 0);
      return { cashBase, yuePay, linePrice: price };
    },
    refreshStaffChooseAmounts(staffChoose, price, balancePrice = 0) {
      const len = (staffChoose || []).length;
      if (len <= 0) return staffChoose || [];
      const staffChooseCopy = staffChoose.map((s) => ({ ...s }));
      const total = Number(price) || 0;
      const number = Math.floor(total / len);
      staffChooseCopy.forEach((item) => {
        item.yeji = number;
      });
      const yu = total % len;
      if (len > 0 && yu > 0) {
        staffChooseCopy[len - 1].yeji = Number(staffChooseCopy[len - 1].yeji) + yu;
      }
      const bal = Number(balancePrice) || 0;
      if (bal > 0) {
        const t = Math.round(bal * 100);
        const nb = Math.floor(t / len);
        const yub = t % len;
        staffChooseCopy.forEach((item, idx) => {
          item.deduct_card_yeji = (nb + (idx === len - 1 ? yub : 0)) / 100;
        });
      } else {
        staffChooseCopy.forEach((item) => {
          item.deduct_card_yeji = 0;
        });
      }
      return staffChooseCopy;
    },
    /** 改价/优惠券/余额变动后，按最新行金额重算已分配的销售业绩 */
    refreshYejiAllPrices() {
      if (!this.setYejiAll || !this.setYejiAll.length) return;
      const itemMap = {};
      this.getFlatCartItems().forEach((row) => {
        itemMap[row.id] = row;
      });
      this.setYejiAll.forEach((entry, index) => {
        const row = itemMap[entry.cart_id];
        if (!row || !entry.staffChoose || !entry.staffChoose.length) return;
        const { cashBase, yuePay } = this.getCartItemCashBase(row);
        this.$set(this.setYejiAll, index, {
          ...entry,
          price: cashBase,
          balance_price: yuePay,
          staffChoose: this.refreshStaffChooseAmounts(entry.staffChoose, cashBase, yuePay),
        });
      });
    },
    buildStaffChooseFromTemplate(template, price, balancePrice = 0) {
      const len = (template || []).length;
      if (len <= 0) return [];
      const staffChoose = template.map((s) => ({
        staff_id: s.staff_id,
        staff_name: s.staff_name,
        position_label: s.position_label,
        position: s.position,
        position_level: s.position_level,
        position_level_label: s.position_level_label,
        yeji: 0,
        deduct_card_yeji: 0,
        is_dian: s.is_dian || 0,
      }));
      const total = Number(price) || 0;
      const number = Math.floor(total / len);
      staffChoose.forEach((item) => {
        item.yeji = number;
      });
      const yu = total % len;
      if (len > 0 && yu > 0) {
        staffChoose[len - 1].yeji = Number(staffChoose[len - 1].yeji) + yu;
      }
      const bal = Number(balancePrice) || 0;
      if (bal > 0) {
        const t = Math.round(bal * 100);
        const nb = Math.floor(t / len);
        const yub = t % len;
        staffChoose.forEach((item, idx) => {
          item.deduct_card_yeji = (nb + (idx === len - 1 ? yub : 0)) / 100;
        });
      }
      return staffChoose;
    },
    upsertYejiAllEntry(yeji) {
      let hasAdd = false;
      this.setYejiAll.forEach((item, index) => {
        if (item.cart_id == yeji.cart_id) {
          hasAdd = true;
          this.$set(this.setYejiAll, index, yeji);
        }
      });
      if (!hasAdd) {
        this.setYejiAll.push(yeji);
      }
    },
    upsertServiceYejiAllEntry(yeji) {
      let hasAdd = false;
      this.serviceYejiAll.forEach((item, index) => {
        if (item.cart_id == yeji.cart_id) {
          hasAdd = true;
          this.$set(this.serviceYejiAll, index, yeji);
        }
      });
      if (!hasAdd) {
        this.serviceYejiAll.push(yeji);
      }
    },
    syncOpenYejiFromAll(mode) {
      if (mode === 'yejiService') {
        const cur = this.serviceYejiAll.find((i) => i.cart_id == this.setYejiService.cart_id);
        if (cur) {
          this.setYejiService = JSON.parse(JSON.stringify(cur));
          this.staffIdsService = cur.staffChoose.map((s) => s.staff_id);
          if (this.$refs.yeji) {
            this.$refs.yeji.dianAttr = cur.staffChoose.filter((s) => s.is_dian == 1).map((s) => s.staff_id);
          }
        }
        return;
      }
      const cur = this.setYejiAll.find((i) => i.cart_id == this.setYeji.cart_id);
      if (cur) {
        this.setYeji = JSON.parse(JSON.stringify(cur));
        this.staffIds = cur.staffChoose.map((s) => s.staff_id);
      }
    },
    applyYejiAll({ mode, staffChoose, serviceStaffChoose, saleStaffChoose }) {
      if (mode === 'both') {
        const items = this.getFlatCartItems();
        const hasService = (serviceStaffChoose || []).length > 0;
        const hasSale = (saleStaffChoose || []).length > 0;
        if (!hasService && !hasSale) return;
        const finish = () => {
          if (hasService) this.syncOpenYejiFromAll('yejiService');
          if (hasSale) this.syncOpenYejiFromAll('yeji');
          this.$Message.success('已应用到全部商品');
          this.closeYeji();
        };
        if (hasService) {
          const serviceItems = items.filter((row) => this.isYejiServiceTarget(row));
          if (!serviceItems.length) {
            this.$Message.warning('购物车中没有可分配手艺人的项目');
            if (!hasSale) return;
          } else {
            Promise.all(serviceItems.map((row) => getService({ product_id: row.product_id }).then((res) => {
              const price = (Number(res.data.price) * Number(row.cart_num)).toFixed(2);
              this.upsertServiceYejiAllEntry({
                link_id: 0,
                cart_id: row.id,
                price: Number(price),
                goods_id: row.product_id,
                type: 2,
                staffChoose: this.buildStaffChooseFromTemplate(serviceStaffChoose, price, 0),
              });
            }))).then(() => {
              if (hasSale) {
                this.applySaleYejiToAll(saleStaffChoose);
              }
              finish();
            }).catch((err) => {
              this.$Message.error((err && err.msg) || '应用失败');
            });
            return;
          }
        }
        if (hasSale) {
          this.applySaleYejiToAll(saleStaffChoose);
          finish();
        }
        return;
      }
      const template = staffChoose || [];
      if (!template.length) return;
      const items = this.getFlatCartItems();
      if (mode === 'yejiService') {
        const serviceItems = items.filter((row) => this.isYejiServiceTarget(row));
        if (!serviceItems.length) {
          this.$Message.warning('购物车中没有可分配手艺人的项目');
          return;
        }
        Promise.all(serviceItems.map((row) => getService({ product_id: row.product_id }).then((res) => {
          const price = (Number(res.data.price) * Number(row.cart_num)).toFixed(2);
          this.upsertServiceYejiAllEntry({
            link_id: 0,
            cart_id: row.id,
            price: Number(price),
            goods_id: row.product_id,
            type: 2,
            staffChoose: this.buildStaffChooseFromTemplate(template, price, 0),
          });
        }))).then(() => {
          this.syncOpenYejiFromAll('yejiService');
          this.$Message.success('已应用到全部商品');
          this.closeYeji();
        }).catch((err) => {
          this.$Message.error((err && err.msg) || '应用失败');
        });
        return;
      }
      this.applySaleYejiToAll(template);
      this.syncOpenYejiFromAll('yeji');
      this.$Message.success('已应用到全部商品');
      this.closeYeji();
    },
    applySaleYejiToAll(template) {
      const items = this.getFlatCartItems();
      const saleItems = items.filter((row) => this.isYejiSaleTarget(row));
      if (!saleItems.length) {
        this.$Message.warning('购物车中没有可分配销售的商品');
        return false;
      }
      saleItems.forEach((row) => {
        const { cashBase, yuePay } = this.getCartItemCashBase(row);
        this.upsertYejiAllEntry({
          link_id: 0,
          cart_id: row.id,
          price: cashBase,
          balance_price: yuePay,
          goods_id: row.product_id,
          type: 2,
          staffChoose: this.buildStaffChooseFromTemplate(template, cashBase, yuePay),
        });
      });
      return true;
    },
    closeYeji(){
      this.yejiVisible=false;
      this.yejiServiceVisible=false;
    },
    closeSend(){
      this.sendVisible=false;
      if (this.$refs.send && this.$refs.send.sendAll) {
        const sendAll = this.$refs.send.sendAll;
        this.createOrder.sendAll = {
          product: JSON.parse(JSON.stringify(sendAll.product || [])),
          coupon: JSON.parse(JSON.stringify(sendAll.coupon || []))
        };
      }
      this.updateSendNum();
      this.sendAllDirty = this.sendNum > 0;
      if (this.cartHasGiftProjectShell()) {
        const sendProductIds = this.getSendAllProductIds();
        if (sendProductIds.length) {
          this.giftProjectSelectedProducts = sendProductIds.slice();
          this.selectedProduct = sendProductIds.slice();
        }
      }
    },
    addSendCart(){

    },
    doYeji(row,type){
      const { cashBase, yuePay, linePrice: price } = this.getCartItemCashBase(row);
      this.setYeji={
        link_id:0,
        cart_id:row.id,
        price:cashBase,
        balance_price: yuePay,
        goods_id:row.product_id,
        type:2,
        staffChoose:[]
      }
      this.staffIds=[];
      let that=this;
      this.setYejiAll.forEach(function (item, index){
        if(item.cart_id == row.id){
          that.setYeji=item;
          item.staffChoose.forEach(function (item){
            that.staffIds.push(item.staff_id);
          })
        }
      })
      this.setYeji.price = cashBase;
      this.setYeji.balance_price = yuePay;
      this.setYejiService={
        link_id:0,
        price:price,
        cart_id:row.id,
        goods_id:row.product_id,
        type:2,
        staffChoose:[]
      }
      this.staffIdsService=[];
      this.$refs.yeji.dianAttr=[];
      this.getServicePrice(row.product_id,row.cart_num,row.id);
      if(type == 2){
           this.canSy=true;
           this.$refs.yeji.activeName="yejiService";
      }else{
         this.canSy=false;
         this.isSale=true;
         this.$refs.yeji.activeName="yeji";
      }
      this.$refs.yeji.showAdd=true;
      this.yejiVisible=true;
    },
    getServicePrice(id,cart_num,cart_id){
      let that=this;
      let dianAttr=[];
      getService({product_id:id}).then(res=>{
          var price=(Number(res.data.price)*Number(cart_num)).toFixed(2);
           that.setYejiService.price=price;
           that.serviceYejiAll.forEach(function (item, index){
           if(item.cart_id == cart_id){
              that.setYejiService=item;
              that.setYejiService.price=price;
              item.staffChoose.forEach(function (item){
                  if(item.is_dian == 1){
                    dianAttr.push(item.staff_id);
                 }
                  that.staffIdsService.push(item.staff_id);
            })
          }
        })
        that.$refs.yeji.dianAttr=dianAttr;
      })
    },
    getPic(){
      let mainEl = this.$refs.listWrap;
      let col = Math.round((mainEl.clientWidth-20) / 252); //计算列数
      this.picwidth = Number((mainEl.clientWidth-col*14-20)/col) -1 +'px'
    },
    openCate(){
      this.showCate = !this.showCate;
      let that = this;
      setTimeout(function(){
        that.getPic()
      })
    },
    headerTap(item){
      this.activeID = item.id;
      this.$set(this,'currentCate','-1')
      if(item.id == 4){
        this.keyboard();
      }else{
        this.productType = item.product_type;
        this.$set(this.goodFrom,'cate_id','-1')
        this.orderSearch();
        this.cateList();
      }
    },
    phoneTap(){
      this.$refs.memberSet.formValidate.nickname = this.userInfo.nickname;
      this.$refs.memberSet.formValidate.uid = this.userInfo.uid;
      this.$refs.memberSet.isPhone = 1;
      this.$refs.memberSet.modal2 = true;
    },
    memberTap(){
      // 完整「选择会员」列表（modal4）；禁止打开小型「会员查询」（modal）
      this.$refs.memberSet.modal = false;
      this.$refs.memberSet.modal4 = true;
      this.$refs.memberSet.searchUser();
    },
    handleKeydown(event) {
      // INPUT是否获得焦点
      if (document.activeElement.tagName === 'INPUT') {
        return false;
      }
      if (document.activeElement.tagName === 'TEXTAREA') {
        return false;
      }
      // 支付弹窗是否显示
      if (this.settleVisible) {
        return false;
      }
      if (this.$refs.memberSet.modal || this.$refs.store.modals || this.$refs.attrs.modals || this.$refs.skillAttrs.modals) {
        return false;
      }
      // if (event.key === 'Enter') {
      //   this.orderSearch(this.goodFrom.store_name);
      // } else {
      //   this.goodFrom.store_name += event.key;
      // }
    },
    reloadList() {
      this.reloading = true;
      this.limitTemp = this.goodFrom.limit;
      this.goodFrom.limit *= this.goodFrom.page;
      this.goodFrom.page = 1;
      if (this.activityFrom.type) {
        this.limitTemp = this.activityFrom.limit;
        this.activityFrom.limit *= this.activityFrom.page;
        this.activityFrom.page = 1;
      }
    },
    getSwithUser(data) {
      swithUser(data)
          .then((res) => {})
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    ceshi() {
      this.$router.push({
        path: `${Setting.roterPre}/auxScreen/login`,
      });
    },
    jsToJava() {
      try {
        window.Jsbridge.invoke(
            'openCacheBox',
            JSON.stringify({ 'p1-key': 'p1-value' }),
            this.myFunction()
        );
      } catch (e) {}
    },
    myFunction() {
      console.log('myFunction called222');
    },
    getSuccess(e) {
      let money = this.$computes.Add(this.userInfo.now_money, e);
      this.userInfo.now_money = money;
      let storage = window.localStorage;
      storage.setItem('cashierUser', JSON.stringify(this.userInfo));
    },
    clear() {
      this.priceInfo.couponPrice = 0;
      this.priceInfo.payPrice = 0;
      this.priceInfo.deductionPrice = 0;
      this.priceInfo.totalPrice = 0;
      this.priceInfo.vipPrice = 0;
      this.priceInfo.firstOrderPrice = 0;
      this.priceInfo.sumPrice = 0;
      this.cartList = [];
      this.promotionsList = [];
      this.cartSum = 0;
      this.collection = 0;
      this.collectionArray = [];
      this.createOrder.change_price = 0;
      this.createOrder.remarks = '';
      this.sendNum=0;
      this.sendAllDirty = false;
      this.createOrder.giveIds=[];
      this.createOrder.is_gendan = 0;
      this.createOrder.gendan_staff_id = 0;
      this.createOrder.gendan_staff_name = '';
      this.createOrder.sendAll={
        'product':[],
        'coupon':[]
      };
      this.coupon = false;
      this.couponId = 0;
      this.couponTargetItem = null;
      this.integral = false;
      this.createOrder.is_price = 0;
      this.giftProjectSelectedProducts = [];
      this.activityFrom.type = 0;
      this.goodFrom.cate_id = '';
    },
    cancel() {
      this.collection = 0;
      this.collectionArray = [];
    },
    // 挂单区删除
    hangDel(row, index) {
      cashierHang(row.id)
          .then((res) => {
            if (this.tableHang.length == 1) {
              this.lodgeFrom.page = 1;
              this.hangList();
            } else {
              this.tableHang.splice(index, 1);
              this.totalHang = this.totalHang - 1;
            }
            this.hangData[index].is_check = 1;
            this.$Message.success(res.msg);
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    // 点击左侧挂单
    hangDataTap(index, item) {
      this.activeHangon = index;
      this.checkOut = 0;
      let touristId = item.tourist_uid;
      let data = {
        uid: item.uid,
      };
      this.activityFrom.type = 0;

      if (item.uid) {
        this.userInfoData(data);
      } else {
        this.setUp(touristId);
        this.getSwithUser({ tourist_uid: touristId });
      }
    },
    // 挂单列表
    hangList() {
      this.loading = true;
      let storeId = this.storeInfos.id;
      getHangList(storeId, this.lodgeFrom)
          .then((res) => {
            this.loading = false;
            this.tableHang = res.data.data;
            this.totalHang = res.data.count;
          })
          .catch((err) => {
            this.loading = false;
            this.$Message.error(err.msg);
          });
    },
    pageHangChange(e) {
      this.lodgeFrom.page = e;
      this.hangList();
    },
    // 提单；
    billHang(item, index) {
      this.checkOut = 0;
      this.activeHangon = index;
      let touristId = item.tourist_uid;
      let data = {
        uid: item.uid,
      };
      if (item.uid) {
        this.userInfoData(data);
      } else {
        this.setUp(touristId);
      }
    },
    //快速挂单列表（最左侧的）
    hangDataList() {
      let storeId = this.storeInfos.id;
      getHang(storeId)
          .then((res) => {
            this.hangData = res.data;
            this.defaultSel();
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    //保存挂单
    lodgeTap() {
      let userInfo = {
        avatar: require('@/assets/images/yonghu.png'),
        nickname: '游客',
        uid: 0,
        touristId: this.userInfo.touristId,
      };
      this.userInfo = userInfo;
      let storage = window.localStorage;
      storage.setItem('cashierUser', JSON.stringify(userInfo));
      setTimeout((e) => {
        this.hangDataTap(0, this.hangData[0]);
      }, 500);
    },
    //搜索挂单
    storeSearch() {
      this.lodgeFrom.page = 1;
      this.hangList();
    },
    //默认选中
    defaultSel(type) {
      let uid = this.userInfo.uid;
      let touristId = this.userInfo.touristId;
      if (uid) {
        let flag = 0;
        this.hangData.forEach((item, index) => {
          if (item.uid == uid) {
            flag = 1;
            this.activeHangon = index;
          }
        });

        if (!flag) {
          this.activeHangon = -1;
        }
      } else if (touristId) {
        this.activeHangon = -1;
        this.hangData.forEach((item, index) => {
          if (item.tourist_uid == touristId) {
            this.activeHangon = index;
          }
        });
        if (this.activeHangon == -1) {
          this.activeHangon = 0;
          this.userInfo.touristId = this.hangData[0].tourist_uid;
          this.getSwithUser({ tourist_uid: this.userInfo.touristId });
        }
      }
    },
    // 充值
    rechargeBnt() {
      this.rechargeVisible = true;
      this.$nextTick(() => {
        const recharge = this.$refs.recharge;
        if (!recharge) return;
        recharge.rechargeData.is_gendan = this.createOrder.is_gendan || 0;
        recharge.rechargeData.gendan_staff_id = this.createOrder.gendan_staff_id || 0;
        recharge.rechargeData.gendan_staff_name = this.createOrder.gendan_staff_name || '';
      });
    },
    //点击出现优惠明细
    discountCon() {
      this.discount = true;
    },
    saveRemark(remarkInfo){
        this.createOrder.remarkInfo=remarkInfo;
    },
    saveCombinationinfo(info){
        this.createOrder.combination_info=info;
    },
    setBudan(data){
       this.createOrder.is_budan=data.is_budan;
       this.createOrder.budan_time=data.budan_time;
    },
    //现金收款创建订单并支付
    cashBnt(payNum) {
      this.payNum = payNum;
      if (this.cashBntLoading) return;
      this.cashBntLoading = true;
      if (this.payType === 'yue') {
        this.createOrder.userCode = payNum;
      } else if (this.payType === '') {
      } else if (this.payType === '') {
        this.createOrder.auth_code = payNum;
      }
      if (this.isDebtRepay) {
        this.debtRepaySubmit(payNum);
        setTimeout(() => {
          this.cashBntLoading = false;
        }, 1000);
        return;
      }
      if (this.isOrderCreate) {
        this.getCashierPay(this.payType);
      } else {
        if (this.rechargeVisible) {
          this.rechargeBalance(payNum);
        } else {
          this.orderCreate();
        }
      }
      setTimeout(() => {
        this.cashBntLoading = false;
      }, 1000);
    },
    //清除计算机输入的数字
    delNum(type) {
      if (type === -1) {
        this.collectionArray = [];
      } else {
        this.collectionArray.pop();
      }
      this.collection = this.collectionArray.length
          ? this.collectionArray.join('')
          : 0;
    },
    //输入实际收款金额
    numTap(item) {
      if (this.defaultcalc === false) {
        this.collection = '';
        this.defaultcalc = true;
      }
      let x = String(this.collection).indexOf('.') + 1;
      let y = String(this.collection).length - x;
      if (x === 0 || y < 2 || !this.collectionArray.length) {
        if (this.collectionArray.join('') <= 9999999) {
          this.collectionArray.push(item);
        }
        this.collection =
            this.collectionArray.join('') > 99999999
                ? 99999999
                : this.collectionArray.join('');
      }
    },
    checkOrderTime(msg,mes,type) {
      let that = this;
      let num = 1;
      let timer = (this.orderSystem.timer = setInterval(function () {
        that.confirmOrder(timer, msg);
        num++;
        if (num >= 60) {
          clearInterval(timer);
          msg();
          that.$refs.settlePay.payShow = false;
          that.$refs.settlePay.payIng = false;
          if(type){
            that.isOrderCreate = 0
          }else{
            that.isOrderCreate = 1;
          }
          that.errorInfo = mes;
          that.payStatus = true;
          // that.$Message.success('支付失败');
        }
      }, 1000));
    },
    closePay(){
      this.activeHangon = -1;
      this.clear();
      this.setUp();
      this.paySuccess=false;
      this.$refs.memberSet.currentid=0;
      this.memberTap();
    },
    jixuPay(){
      this.activeHangon = -1;
      this.clear();
      this.setUp();
      this.$refs.memberSet.currentid=0;
      this.paySuccess=false;
    },
    confirmOrder(timer, msg, from) {
      let data = {
        order_id: this.orderId,
      };
      checkOrderApi(3, data)
          .then((res) => {
            if (res.data.status == true) {
              msg();
              this.$refs.settlePay.payIng = false;
              clearInterval(timer);
              this.isOrderCreate = 0;
              //this.$Message.success('支付成功');
              this.$refs.settlePay.payShow = false;
              this.paySuccess = true;
              this.refreshUserDebtRecords();
              let that = this;
              // setTimeout(function(){
              //   that.paySuccess = false;
              // },1000)
              this.goodList();
              this.modalPay = false;
              this.settleVisible = false;
              this.changePoints();
              let storage = window.localStorage;
              storage.setItem('cashierUser', JSON.stringify(this.userInfo));
              this.clear();
            }
          })
          .catch((err) => {
            msg();
            this.$Message.error(err.msg);
            this.$refs.settlePay.payShow = false;
            this.$refs.settlePay.payIng = false;
            clearInterval(timer);
          });
    },
    changeSource(data){
      this.createOrder.source=data.source;
    },
    payPrice(data) {
      let payType=data.type;
      this.payType=payType;
      this.createOrder.auth_code = '';
      this.createOrder.userCode = '';
      this.createOrder.cash_choose=data.cashChoose;
      if (payType == '' || payType == 'yue') {
      } else if (payType == 'cash') {
        // this.keyboard();
      }
      this.createOrder.integral = false;
      this.createOrder.coupon = this.buildCartCoupons().length > 0;
      this.createOrder.coupon_id = 0;
      this.createOrder.cart_coupons = this.buildCartCoupons();
      this.createOrder.pay_type = payType;
      this.createOrder.staff_id = this.storeInfos.id;
      this.collection = payType == 'cash' ? this.settleMoney : 0;
    },
    // 线上支付和余额支付
    confirm(payNum) {
      this.createOrder.userCode = payNum;
      this.createOrder.auth_code = payNum;
      if (this.isDebtRepay) {
        if (this.payType == 'yue') {
          if (!this.createOrder.userCode && this.priceInfo.is_cashier_yue_pay_verify) {
            return this.$Message.error('请扫描个人中心二维码');
          }
          this.debtRepaySubmit(payNum);
        } else if (this.payType == '') {
          if (!this.createOrder.auth_code) {
            return this.$Message.error('请扫描您的付款码');
          }
          this.debtRepaySubmit(payNum);
        }
        return;
      }
      if (this.payType == 'yue') {
        if (
            !this.createOrder.userCode &&
            this.priceInfo.is_cashier_yue_pay_verify
        ) {
          return this.$Message.error('请扫描个人中心二维码');
        }
        if (this.isOrderCreate) {
          this.getCashierPay('yue');
        } else {
          this.orderCreate();
        }
      } else if (this.payType == '') {
        if (!this.createOrder.auth_code) {
          return this.$Message.error('请扫描您的付款码');
        }
        if (this.isOrderCreate) {
          this.getCashierPay('');
        } else {
          this.orderCreate();
        }
      }
    },
    modalPayCancel() {
      this.$Message.destroy();
      if (this.orderSystem.timer) {
        clearInterval(this.orderSystem.timer);
        this.orderSystem.timer = null;
      }
    },
    getCashierPay(payType) {
      let data = {
        payType: payType,
        userCode: this.payNum,
        auth_code: this.payNum,
      };
      if (payType == 'cash') {
        if (parseFloat(this.settleMoney) > parseFloat(this.collection)) {
          return this.$Message.error('您付款金额不足');
        }
      }
      cashierPay(this.orderId, data)
          .then((res) => {
            this.payNum = '';
            if (res.data.status == 'SUCCESS') {
              this.isOrderCreate = 0;
              // this.$Message.success('支付成功');
              this.$refs.settlePay.payShow = false;
              this.paySuccess = true;
              this.refreshUserDebtRecords();
              let that = this;
              // setTimeout(function(){
              //   that.paySuccess = false;
              // },1000)
              this.settleVisible = false;
              this.modalCash = false;
              this.modalPay = false;
              this.changePoints();
              let storage = window.localStorage;
              storage.setItem('cashierUser', JSON.stringify(this.userInfo));
              this.clear();
              this.goodList();
              //现金收款打开钱箱
              if (payType == 'cash') {
                this.jsToJava();
              }
            } else if (res.data.status == 'PAY_ING') {
              // let msg = this.$Message.loading({
              //   content: '等待支付中...',
              //   duration: 0,
              // });
              let msg = function(){}
              this.$refs.settlePay.payShow = true;
              this.$refs.settlePay.payIng = true;
              this.orderSystem.loadingMsg = msg;
              this.orderId = res.data.order_id;
              this.checkOrderTime(msg,res.data.message);
            } else {
              this.isOrderCreate = 1;
              this.orderId = res.data.order_id;
              // this.$Message.error(res.data.message);
              this.errorInfo = res.data.message;
              this.payStatus = true;
            }
          })
          .catch((err) => {
            this.payNum = '';
            this.errorInfo = err.msg;
            this.payStatus = true;
            // this.$Message.error(err.msg);
          });
    },
    changeSuccess(){
      this.userInfoData({uid:this.userInfo.uid},true);
    },
    /** 下单前同步改价明细：内项传行总价，壳传0，后端合计为订单应付 */
    prepareCreateOrderPrice() {
      if (this.cartHasCustomCard) {
        this.createOrder.is_price = 1;
        this.setDingzhi();
      }
      this.setPrice();
      if (this.cartHasCustomCard) {
        const lineTotal = this.getCustomCardBundlePreCouponTotal();
        const cartCouponTotal = (this.buildCartCoupons().length && this.priceInfo)
          ? (Number(this.priceInfo.couponPrice) || 0)
          : 0;
        const finalPay = Math.max(Number((lineTotal - cartCouponTotal).toFixed(2)), 0);
        this.createOrder.change_price = lineTotal;
        if (this.priceInfo) {
          this.priceInfo.payPrice = finalPay;
        }
        this.settleMoney = finalPay;
      }
    },
    // 创建订单
    orderCreate() {
      if (this.payType == 'cash') {
        if (parseFloat(this.priceInfo.payPrice) > parseFloat(this.collection)) {
          return this.$Message.error('您付款金额不足');
        }
      }
      if (!this.cashierOperatorGiftEnabled) {
        this.applyProductSendPreset(true);
      }
      if (this.cartHasGiftProjectShell()) {
        const giftSelected = this.getGiftProjectSelectedProducts();
        if (!giftSelected.length) {
          return this.$Message.error('请选择赠送哪些项目');
        }
        this.createOrder.selectedProduct = giftSelected;
      } else {
        this.createOrder.selectedProduct = this.selectedProduct || [];
      }
      this.prepareCreateOrderPrice();
      this.createOrder.staff_id = this.storeInfos.id || 0;
      this.createOrder.cart_coupons = this.buildCartCoupons();
      this.createOrder.coupon = this.createOrder.cart_coupons.length > 0;
      this.createOrder.coupon_id = 0;
      this.createOrder.integral = false;
      this.createOrder.tourist_uid = this.userInfo.touristId;
      this.createOrder.new = 0;
      this.createOrder.can_hx = 0;
      if (this.activityFrom.type == 5) {
        this.createOrder.cart_id = [this.seckillOrderId];
        this.createOrder.new = 1;
      } else if (this.storeInfo.product_type == 6 && this.reservationCart == 1) {
        this.createOrder.new = 1;
        this.createOrder.can_hx = 1;
      }
      this.createOrder.setYejiAll=this.setYejiAll;
      this.createOrder.serviceYejiAll=this.serviceYejiAll;
      cashierCreate(this.userInfo.uid, this.createOrder)
          .then((res) => {
            let storage = window.localStorage;
            this.payNum = '';
            if (this.payType == 'yue') {
              this.settleVisible = false;
              this.payNum = '';
              this.createOrder.userCode = '';
              if (res.data.status == 'ORDER_CREATE') {
                this.isOrderCreate = 1;
                this.orderId = res.data.order_id;
                this.$Message.success(res.data.message);
              } else if (res.data.status == 'SUCCESS') {
                this.isOrderCreate = 0;
                this.setYejiAll=[];
                this.serviceYejiAll=[];
                // this.$Message.success('支付成功');
                this.$refs.settlePay.payShow = false;
                this.paySuccess = true;
                this.refreshUserDebtRecords();
                let that = this;
                // setTimeout(function(){
                //   that.paySuccess = false;
                // },1000)
                // let money = this.$computes.Sub(
                //     this.userInfo.now_money,
                //     this.priceInfo.payPrice
                // );
                that.userInfoData({uid:that.userInfo.uid},true);
                // this.userInfo.now_money = money;
                // this.payTypeModal = false;
                // storage.setItem('cashierUser', JSON.stringify(this.userInfo));
                if(!this.createOrder.new){
                  this.changePoints();
                  this.clear();
                }
              } else {
                this.isOrderCreate = 1;
                this.orderId = res.data.order_id;
                this.$Message.error(res.data.message);
              }
            }
            if (this.payType == 'cash' || this.payType == 'combination') {
              if (res.data.status == 'SUCCESS') {
                this.setYejiAll=[];
                this.serviceYejiAll=[];
                // this.$Message.success('支付成功');
                this.paySuccess = true;
                this.refreshUserDebtRecords();
                let that = this;
                // setTimeout(function(){
                //    that.paySuccess = false;
                // },1000)
                let yuePay=0;
                that.createOrder.combination_info.forEach(function (item, index) {
                  if(item.activePay == 3){
                    // activePay=3 可能是：余额支付(balance) 或 卡升级(card_upgrade)
                    // 本地余额展示只扣“真实余额支付”的部分，卡升级不应扣用户余额
                    const subType = item.pay_sub_type || 'balance';
                    if (subType === 'balance') {
                      yuePay = Number(yuePay) + Number(item.price);
                    }
                  }
                })
                if(yuePay > 0 && that.payType == 'combination'){
                  let money = that.$computes.Sub(
                      that.userInfo.now_money,
                      yuePay
                  );
                  this.userInfo.now_money = money;
                }
                storage.setItem('cashierUser', JSON.stringify(this.userInfo));
                if(!this.createOrder.new){
                  if (this.userInfo.uid) {
                    this.changePoints();
                  }
                  this.clear();
                }
                this.payTypeModal = false;
                this.settleVisible = false;
                this.jsToJava();
              }
            }
            if (this.payType == '') {
              this.payNum = '';
              this.createOrder.auth_code = '';
              if (res.data.status == 'ORDER_CREATE') {
                this.isOrderCreate = 1;
                this.orderId = res.data.order_id;
                this.$Message.success(res.data.message);
              } else if (res.data.status == 'PAY_ING') {
                // let msg = this.$Message.loading({
                //   content: '等待支付中5...',
                //   duration: 0,
                // });
                let msg = function(){}
                this.$refs.settlePay.payShow = true;
                this.$refs.settlePay.payIng = true;
                this.orderId = res.data.order_id;
                this.checkOrderTime(msg,res.data.message);
              } else if (res.data.status == 'SUCCESS') {
                this.setYejiAll=[];
                this.serviceYejiAll=[];
                // this.$Message.success('支付成功');
                this.$refs.settlePay.payShow = false;
                this.paySuccess = true;
                this.refreshUserDebtRecords();
                let that = this;
                // setTimeout(function(){
                //    that.paySuccess = false;
                // },1000)
                storage.setItem('cashierUser', JSON.stringify(this.userInfo));
                this.settleVisible = false;
                if(!this.createOrder.new){
                  this.changePoints();
                  this.clear();
                }
              } else {
                this.isOrderCreate = 1;
                this.orderId = res.data.order_id;
                this.errorInfo = res.data.message;
                this.payStatus = true;
                // this.$Message.error(res.data.message);
              }
            }
          })
          .catch((err) => {
            this.payNum = '';
            this.errorInfo = err.msg;
            this.payStatus = true;
            // this.$Message.error(err.msg);
          });
    },
    rePay(){
      this.payStatus = false;
      this.$nextTick(() => {
        this.$refs.settlePay.$refs.input.focus();
        this.$refs.settlePay.$refs.inputs.focus();
      });
    },
    //更新积分、更新左侧挂单、更新挂单（此函数支付成功调用）
    changePoints() {
      let usedIntegral = this.$computes.Sub(
          this.userInfo.integral,
          this.priceInfo.usedIntegral
      );
      this.userInfo.integral = usedIntegral;
      //顶部挂单列表中删除刚才支付成功的用户
      this.hangData.splice(this.activeHangon, 1);
      //重置默认选中
      this.activeHangon = 0;
      // this.hangDataTap(0, this.hangData[0]);
      //
      this.tableHang.forEach((item, index) => {
        if (item.uid) {
          if (this.userInfo.uid == item.uid) {
            this.tableHang.splice(index, 1);
          }
        } else {
          if (this.userInfo.touristId == item.tourist_uid) {
            this.tableHang.splice(index, 1);
          }
        }
      });
    },
    changeModal(n) {
      if (!n) {
        this.cartCompute();
      }
    },
    // 计算金额
    cartCompute(cartId, callbackFn) {
      let ids = [];
      if (cartId) {
        ids = [cartId];
      } else {
        if (!this.cartList.length) {
          this.priceInfo = {};
          return;
        }
        this.cartList.forEach((item) => {
          item.cart.forEach((good) => {
            ids.push(good.id);
          });
        });
      }
      this.createOrder.cart_id = ids;
      const cartCoupons = this.buildCartCoupons();
      let data = {
        integral: false,
        coupon: cartCoupons.length > 0,
        coupon_id: 0,
        cart_id: ids,
        cart_coupons: cartCoupons,
      };
      // 改价后选择优惠券：把改价明细带给后端按改价后金额计算
      if (
        this.createOrder &&
        Number(this.createOrder.is_price) === 1 &&
        Array.isArray(this.createOrder.cart_info) &&
        this.createOrder.cart_info.length
      ) {
        data.is_price = 1;
        data.change_price = this.createOrder.change_price || 0;
        data.cart_info = this.createOrder.cart_info;
      }
      if (cartId) {
        data.new = 1;
      }
      cashierCompute(this.userInfo.uid, data)
          .then((res) => {
            let data = res.data;
            this.priceInfo = data;
            this.settleMoney = data.payPrice;
            this.orderCartInfo = data.cartInfo;
            this.unchangedPrice = this.priceInfo.payPrice || 0;

            // 为每个商品设置优惠券信息和更新价格
            if (data.cartInfo && Array.isArray(data.cartInfo)) {
              this.cartList.forEach((item2, index2) => {
                item2.cart.forEach((item3, index3) => {
                  const cartInfo = data.cartInfo.find(item4 => item4.id === item3.id);
                  if (cartInfo) {
                    // 设置优惠券信息
                    if (cartInfo.coupon_info) {
                      this.$set(this.cartList[index2].cart[index3], 'coupon_info', cartInfo.coupon_info);
                      this.$set(this.cartList[index2].cart[index3], 'coupon_id', cartInfo.coupon_info.coupon_id || cartInfo.coupon_id || 0);
                    } else {
                      this.$set(this.cartList[index2].cart[index3], 'coupon_info', null);
                      if (!cartInfo.coupon_id) {
                        this.$set(this.cartList[index2].cart[index3], 'coupon_id', 0);
                      }
                    }
                    if (cartInfo.coupon_id) {
                      this.$set(this.cartList[index2].cart[index3], 'coupon_id', cartInfo.coupon_id);
                    }

                    // 更新商品价格（改价后单价/行总价）；定制卡壳展示价由 setDingzhi 合并内项，不用接口价覆盖
                    if (this.isCustomCardShell(item3)) {
                      return;
                    }
                    if (cartInfo.truePrice != null && cartInfo.truePrice !== '' && Number(cartInfo.truePrice) > 0) {
                      const unit = Number(cartInfo.truePrice);
                      const qty = Math.max(Number(item3.cart_num || 1), 1);
                      const linePrice = Number((unit * qty).toFixed(2));
                      this.$set(this.cartList[index2].cart[index3], 'truePrice', unit);
                      this.$set(this.cartList[index2].cart[index3], 'sum_price', unit);
                      if (Number(this.createOrder.is_price) === 1) {
                        this.$set(this.cartList[index2].cart[index3], 'change_price', linePrice);
                      }
                    } else if (cartInfo.true_price) {
                      const linePrice = Number(cartInfo.true_price);
                      const qty = Math.max(Number(item3.cart_num || 1), 1);
                      this.$set(this.cartList[index2].cart[index3], 'sum_price', linePrice);
                      this.$set(this.cartList[index2].cart[index3], 'change_price', linePrice);
                      this.$set(this.cartList[index2].cart[index3], 'truePrice', Number((linePrice / qty).toFixed(2)));
                    } else if (cartInfo.pay_price != null && cartInfo.pay_price !== '' && Number(cartInfo.pay_price) > 0) {
                      const qty = Math.max(Number(item3.cart_num || 1), 1);
                      const linePrice = this.normalizeBundleLineTotal(item3, cartInfo.pay_price);
                      const unit = qty > 0 ? Number((linePrice / qty).toFixed(2)) : linePrice;
                      this.$set(this.cartList[index2].cart[index3], 'truePrice', unit);
                      this.$set(this.cartList[index2].cart[index3], 'sum_price', unit);
                      this.$set(this.cartList[index2].cart[index3], 'change_price', linePrice);
                    }
                  }
                });
              });
            }

            if (cartId) {
              this.openSettle();
            }
            this.setPrice();
            this.setDingzhi();
            this.refreshYejiAllPrices();
            // 实付与待支付应以接口返回的 data.payPrice 为准
            const finalPay = Number(data.payPrice);
            if (!Number.isNaN(finalPay)) {
              this.priceInfo.payPrice = finalPay;
              this.settleMoney = finalPay;
            }
            // 回调
            if (typeof callbackFn == 'function') {
              callbackFn();
            }
          })
          .catch((err) => {
            this.$Message.error(err.msg);
            this.coupon = false;
          });
    },
    // 点击明细行优惠券
    itemCouponTap(item) {
      if (!this.userInfo.uid) {
        this.$Message.warning('请先选择用户再使用优惠券');
        return;
      }
      if (!item) return;
      if (this.isCustomCardShell(item)) {
        this.$Message.warning('定制卡请在包含的各项目上分别使用优惠券');
        return;
      }
      this.couponTargetItem = item;
      this.setPrice();
      this.$refs.coupon.modals = true;
      this.$refs.coupon.getList();
    },
    getItemCouponId(e) {
      const targetId = e.cart_id || (this.couponTargetItem && this.couponTargetItem.id);
      if (!targetId) return;
      const couponId = e.id || 0;
      if (couponId) {
        this.clearCartYuePayAndCardUpgrade();
      }
      this.cartList.forEach((group, index2) => {
        group.cart.forEach((good, index3) => {
          if (good.id === targetId) {
            this.$set(this.cartList[index2].cart[index3], 'coupon_id', couponId);
            if (!couponId) {
              this.$set(this.cartList[index2].cart[index3], 'coupon_info', null);
            }
          }
        });
      });
      this.$refs.coupon.modals = false;
      this.couponTargetItem = null;
      this.setPrice();
      this.cartCompute();
    },
    changePrice() {
      // 已选择明细优惠券时再改价：清空各行券，要求重新选择
      let hasItemCoupon = false;
      this.cartList.forEach((group) => {
        (group.cart || []).forEach((good) => {
          if (good && good.coupon_id) hasItemCoupon = true;
        });
      });
      if (hasItemCoupon) {
        this.cartList.forEach((group, index2) => {
          (group.cart || []).forEach((good, index3) => {
            this.$set(this.cartList[index2].cart[index3], 'coupon_id', 0);
            this.$set(this.cartList[index2].cart[index3], 'coupon_info', null);
          });
        });
        if (this.priceInfo) this.priceInfo.couponPrice = 0;
      }
      this.cartCompute(undefined, () => {
        const changePriceRef = this.$refs.changePrice;
        changePriceRef.cartInfo = this.orderCartInfo;
        changePriceRef.priceInfo = this.priceInfo;
        changePriceRef.ordeUpdateInfo();
        this.submitData = {};
        this.createOrder.cart_info = [];
        this.createOrder.is_price = 0;
        this.createOrder.change_price = 0;
        this.getSwithUser({ change_price: 0 });
        changePriceRef.priceModals = true;
      });
    },
    setPrice(){
      if (this._inSetPrice) {
        this._setPriceQueued = true;
        return;
      }
      this._inSetPrice = true;
      let that=this;
      try {
      let payPrice=0;
      this.createOrder.cart_info=[];
      this.cartList.forEach(function (item2,index2){
        item2.cart.forEach(function (item3,index3){
          var totalPrice=0;
          const isBundleItem = that.isCustomCardBundleItem(item3);
          // 余额支付金额：定制卡内项目不允许余额/卡升级
          const yuePayAmount = isBundleItem ? 0 : (Number(item3.yue_pay_amount || 0) || 0);
          const cardUpgradeAmount = isBundleItem ? 0 : (Number(item3 && item3.card_upgrade_enabled ? (item3.card_upgrade_amount || 0) : 0) || 0);
          const debtPayAmount = isBundleItem ? 0 : (Number(item3.debt_pay_amount || 0) || 0);
          const couponId = Number(item3.coupon_id || 0) || 0;
          let index = that.createOrder.giveIds.indexOf(item3.id);
          if (index > -1) {
              //有存在
              item3.change_price=0;
              item3.sum_price=0;
              that.createOrder.is_price = 1;
              that.createOrder.cart_info.push(Object.assign({'id':item3.id,'true_price':0,'yue_pay_amount':yuePayAmount,'card_upgrade_amount':cardUpgradeAmount,'debt_pay_amount':debtPayAmount,'debt_pay_amount':debtPayAmount,'coupon_id':couponId}, that.projectServiceObjectPayload(item3)));
              totalPrice=0;
           }else{
             //不存在
            const skipPayAccum = !!(item3 && item3.true_dingzhi);
            var price = that.isCustomCardBundleItem(item3)
              ? that.getBundleItemLineAmount(item3)
              : that.getItemLineGoodsAmount(item3);
            if (skipPayAccum) {
              price = 0;
            }
            if(that.createOrder.is_price == 1 || that.createOrder.giveIds.length > 0){
              if(!skipPayAccum && that.submitData.cartInfo) {
                that.submitData.cartInfo.forEach(function (item4, index4) {
                  if (item4.id == item3.id) {
                    price = Number(item4.true_price);
                  }
                })
              }
              if (!skipPayAccum) {
                that.createOrder.cart_info.push(Object.assign({'id':item3.id,'true_price':price,'yue_pay_amount':yuePayAmount,'card_upgrade_amount':cardUpgradeAmount,'debt_pay_amount':debtPayAmount,'debt_pay_amount':debtPayAmount,'coupon_id':couponId}, that.projectServiceObjectPayload(item3)));
                totalPrice=price;
              } else {
                that.createOrder.cart_info.push(Object.assign({'id':item3.id,'true_price':0,'yue_pay_amount':yuePayAmount,'card_upgrade_amount':cardUpgradeAmount,'debt_pay_amount':debtPayAmount,'debt_pay_amount':debtPayAmount,'coupon_id':0}, that.projectServiceObjectPayload(item3)));
                totalPrice = 0;
              }
            } else {
              // 未改价时也要把余额支付明细传给后端（true_price不参与后端计算）
              if (!skipPayAccum) {
                that.createOrder.cart_info.push(Object.assign({'id':item3.id,'yue_pay_amount':yuePayAmount,'card_upgrade_amount':cardUpgradeAmount,'debt_pay_amount':debtPayAmount,'debt_pay_amount':debtPayAmount,'coupon_id':couponId}, that.projectServiceObjectPayload(item3)));
              } else {
                that.createOrder.cart_info.push(Object.assign({'id':item3.id,'true_price':0,'yue_pay_amount':yuePayAmount,'card_upgrade_amount':cardUpgradeAmount,'debt_pay_amount':debtPayAmount,'debt_pay_amount':debtPayAmount,'coupon_id':0}, that.projectServiceObjectPayload(item3)));
              }
            }
            if(!skipPayAccum && item3.before_price) {
                // item3.change_price = item3.before_price;
                item3.sum_price = item3.before_price;
              }
          }
          payPrice=(Number(payPrice)+Number(totalPrice)).toFixed(2);
        })
      })
      if(that.createOrder.is_price == 1 || that.createOrder.giveIds.length > 0){
        // payPrice 为购物车行小计之和（改价后商品合计），不含优惠券、积分抵扣
        const lineTotal = Number(payPrice) || 0;
        that.createOrder.change_price = lineTotal;
        let finalPay = lineTotal;
        const cartCouponTotal = (that.buildCartCoupons().length && that.priceInfo)
          ? (Number(that.priceInfo.couponPrice) || 0)
          : 0;
        if (cartCouponTotal > 0) {
          finalPay -= cartCouponTotal;
        }
        that.priceInfo.payPrice = Math.max(Number(finalPay.toFixed(2)), 0);
      }
      that.setDingzhi();
      // 券后应付 cap 同时约束余额与卡升级；两轮收敛避免先后顺序导致的超额
      that.clampAllItemYuePay();
      that.clampAllCardUpgrade();
      that.clampAllItemDebtPay();
      that.clampAllItemYuePay();
      that.clampAllCardUpgrade();
      that.clampAllItemDebtPay();
      that.refreshYejiAllPrices();
      } finally {
        this._inSetPrice = false;
        if (this._setPriceQueued) {
          this._setPriceQueued = false;
          this.$nextTick(() => this.setPrice());
        }
      }
    },
    submitSuccess(data){
      const updatedArray = this.orderCartInfo.map(item1 => {
        const match = data.cartInfo.find(item2 => item2.id === item1.id);
        if (match) {
          return { ...item1, truePrice: Number(match.true_price) };
        }
        return item1;
      });
      this.cartList.forEach(function (item2,index2){
            item2.cart.forEach(function (item3,index3){
                    data.cartInfo.forEach(function (item4,index4){
                         if(item4.id == item3.id){
                                const linePrice = Number(item4.true_price);
                                const unitPrice = item3.cart_num > 0
                                  ? Number((linePrice / Number(item3.cart_num)).toFixed(2))
                                  : linePrice;
                                if (item3.productInfo && (item3.productInfo.pid == 8154 || item3.productInfo.id == 8154)) {
                                  return;
                                }
                                item3.change_price=linePrice;
                                item3.sum_price=unitPrice;
                                item3.before_price=linePrice;
                                item3.truePrice=unitPrice;
                         }
                    })
            })
      })
      data.resultPayPrice=Number(data.resultPayPrice);
      this.submitData = data;
      this.orderCartInfo = updatedArray;
      this.createOrder.cart_info = data.cartInfo;
      this.priceInfo.payPrice = data.resultPayPrice;
      this.$Message.success('改价成功');
      this.createOrder.is_price = 1;
      this.createOrder.change_price = data.resultPayPrice;
      this.getSwithUser({ change_price: data.resultPayPrice });
      this.setPrice();
      this.clampAllItemYuePay();
      this.refreshYejiAllPrices();

      // 改价成功后重新计算优惠券，确保基于改价后的价格
      if (this.buildCartCoupons().length) {
        this.cartCompute();
      }
    },
    remarks() {
      this.modal = true;
    },
    // 提交备注
    onSubmit() {
      this.modal = false;
    },
    removeDeletedCartState(cartIds) {
      if (!cartIds || !cartIds.length) return;
      const idSet = new Set(cartIds.map((id) => Number(id)));
      this.setYejiAll = this.setYejiAll.filter((item) => !idSet.has(Number(item.cart_id)));
      this.serviceYejiAll = this.serviceYejiAll.filter((item) => !idSet.has(Number(item.cart_id)));
      if (Array.isArray(this.createOrder.cart_info)) {
        this.createOrder.cart_info = this.createOrder.cart_info.filter(
          (item) => !idSet.has(Number(item.id))
        );
      }
      this.initCombinationInfo = [];
      this.forceCombinationPay = false;
      this.lockSettleYueEdit = false;
    },
    // 删除
    del(ids, type, index, num, name) {
      this.$Modal.confirm({
        title: '删除该购物车',
        content:
            '<p>确定要删除该购物车吗？</p><p>删除该购物车后将无法恢复，请谨慎操作！</p>',
        onOk: () => {
          cashierCartDel(this.userInfo.uid, ids)
              .then((res) => {
                this.$Message.success('删除成功');
                this.submitData = {};
                const deletedIds = (ids && ids.ids) ? ids.ids : [];
                this.removeDeletedCartState(deletedIds);
                // this.reloadList();
                // this.goodList(this.activityFrom.type);
                this.goodListRefresh();
                if (type) {
                  this.clear();
                  this.invalidList = [];
                  this.hangDataList();
                } else {
                  if (name == 'inv' && num) {
                    this.invalidList.splice(index, 1);
                  } else {
                    this.cartList[index].cart.splice(num, 1);
                    if (this.cartList.length) {
                      this.getCartList();
                      this.setPrice();
                    } else {
                      this.hangDataList();
                      this.clear();
                    }
                  }
                }
              })
              .catch((err) => {
                this.$Message.error(err.msg);
              });
        },
        onCancel: () => {},
      });
    },
    delAll() {
      let ids = [];
      if (!this.cartList.length && !this.invalidList.length)
        return this.$Message.warning('购物车暂无商品');
      this.cartList.forEach((item) => {
        item.cart.forEach((good) => {
          ids.push(good.id);
        });
      });

      this.getSwithUser({ chang_cart_remove: 1 });

      this.invalidList.forEach((item) => {
        ids.push(item.id);
      });
      this.del(
          {
            ids: ids,
          },
          1
      );
    },
    sendCart(item){
         item.is_gift=!item.is_gift;
         let index = this.createOrder.giveIds.indexOf(item.id);
         if (index > -1) {
             this.createOrder.giveIds.splice(index, 1);
         }else{
           this.createOrder.giveIds.push(item.id);
         }
         this.setPrice();
    },
    delCart(item, index, num, type) {
      let ids = [];
      ids.push(item.id);
      this.del(
          {
            ids: ids,
          },
          0,
          index,
          num,
          type
      );
    },
    // 点击切换属性
    cartAttr(item) {
      if (this.isGiftProjectShell(item)) {
        return this.$Message.warning('购物车中已有赠送项目');
      }
      this.disabled = false;
      this.$refs.attrs.modals = true;
      this.isCart = 1;
      this.cartInfo.cart_id = item.id;
      this.cartInfo.product_id = item.product_id;
      this.goodsInfo(item.product_id);
    },
    // 加入购物车
    async joinCart(num,datas,unblurred) {
      let that = this;
      if (num) {
        let productSelect = that.productValue[this.attrValue];
        //如果有属性,没有选择,提示用户选择
        if (that.attr.productAttr.length && productSelect === undefined && this.storeInfo.product_type != 5) {
          return this.$Message.warning('产品库存不足，请选择其它');
        }
      }
      // 定制卡只能添加一次（但允许与其他品项一起结账）
      const selectingCustomCard =
        this.isCustomCardShell(this.storeInfo) ||
        this.isCustomCardShell({ product_id: this.productId, productInfo: this.storeInfo });
      if (selectingCustomCard && this.cartHasCustomCardShellInCart()) {
        return this.$Message.warning('定制卡只能选择一张');
      }
      const selectingGiftProject =
        this.isGiftProjectShell(this.storeInfo) ||
        this.isGiftProjectShell({ product_id: this.productId, productInfo: this.storeInfo });
      if (selectingGiftProject) {
        const existingGift = this.getGiftProjectCartItem();
        if (existingGift) {
          return this.$Message.warning('购物车中已有赠送项目');
        }
        if (this.cartList.length || this.invalidList.length) {
          await this.clearCartSilently();
        }
      } else if (this.cartHasGiftProjectShell()) {
        return this.$Message.warning('购物车里有赠送项目，无法添加其他项目');
      }
      const selectingCardLike =
        this.isCardLikeProduct(this.storeInfo) ||
        this.isCardLikeProduct({ product_type: this.storeInfo.product_type, product_id: this.productId });
      if (selectingCardLike) {
        // 卡项只能单独购买：若购物车有其他品项 / 或已存在另一张卡项，直接清空再加购
        if ((this.cartList.length || this.invalidList.length) && (this.cartHasNonCardLike() || this.cartHasCardLike())) {
          await this.clearCartSilently();
        }
      } else {
        if (this.cartHasCardLike()) {
          return this.$Message.warning('卡项只能单独购买，不能与其他品项共同结账');
        }
      }
      if (this.activeHangon == -1) this.activeHangon = 0;
      // let uid = this.userInfo.uid;
      let uid = this.hangData[this.activeHangon].uid || this.userInfo.uid || 0;
      let data = {
        productId: unblurred?0:this.productId,
        seckillId: this.seckillId,
        cartNum: 1,
        uniqueId: num
            ? this.attr.productSelect !== undefined
                ? this.attr.productSelect.unique
                : ''
            : '',
        staff_id: this.storeInfos.id,
        tourist_uid: this.userInfo.touristId,
        new: Number(this.storeInfo.product_type === 6 && this.reservationCart==1),
        cart_type: unblurred?3:0,
        price: this.collection
      };
      let obj = {}
      if(this.storeInfo.product_type === 6 && this.reservationCart == 1){
        obj = {...data,...datas}
      }else{
        obj = {...data}
      }
      cashierCart(uid, obj)
          .then((res) => {
            if (selectingGiftProject) {
              this.giftProjectSelectedProducts = Array.isArray(this.selectedProduct)
                ? this.selectedProduct.slice()
                : [];
            }
            if (this.storeInfo.product_type === 6 && this.reservationCart==1 && !selectingGiftProject) {
              this.$refs.skillAttrs.modals = false;
              this.$refs.attrs.modals = false;
              this.cartCompute(res.data.cartId);
              return false;
            }
            this.$refs.attrs.modals = false;
            this.$Message.success('添加购物车成功');
            this.getCartList();
            if (this.activityFrom.type) {
              // this.reloadList();
              // this.goodList(this.activityFrom.type);
              this.goodListRefresh();
            } else {
              //如果是扫码查询商品摒弃直接加入购物车的情况下，在加入购物车成功以后，清空输入框的内容，重新请求列表
              this.goodFrom.store_name = '';
              // this.reloadList();
              // this.goodList();
              this.goodListRefresh();
            }
            this.hangDataList();
            this.disabled = true;
            this.collection = 0;
            this.collectionArray = [];
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    // 购物车加减
    cartChange(item) {
      let uid = item.uid;
      let data = {
        number: item.cart_num,
        id: item.id,
      };
      cashierCartNum(uid, data)
          .then((res) => {
            this.cartCompute();
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    changeCart(e, item) {
      let uid = item.uid;
      let data = {
        number: item.cart_num,
        id: item.id,
      };
      cashierCartNum(uid, data)
          .then((res) => {
            this.getCartList();
            this.cartCompute();
            this.goodListRefresh();
          })
          .catch((err) => {
            if (type === 'reduce' && item.cart_num > 1) {
              item.cart_num++;
            } else if (type === 'add' && item.cart_num < item.branch_stock) {
              item.cart_num--;
            }
            this.$Message.error(err.msg);
          });
    },
    calculate(item, type) {
      if (item.productInfo.pid == 8154 || item.productInfo.id == 8154 || this.isGiftProjectShell(item)) {
         return
      }
      if (this.cumping) return;
      if (type === 'reduce' && item.cart_num > 1) {
        item.cart_num--;
      } else if (type === 'add' && (item.cart_num < item.branch_stock || item.cart_type == 3)) {
        item.cart_num++;
      } else {
        return this.$Message.error(
            item.cart_num === 1 ? '数量最小为1' : '库存不足'
        );
      }
      let uid = item.uid;
      let data = {
        number: item.cart_num,
        id: item.id,
      };
      this.cumping = true;
      cashierCartNum(uid, data)
          .then((res) => {
            this.getCartList();
            this.cartCompute();
            this.goodListRefresh();
          })
          .catch((err) => {
            if (type === 'reduce' && item.cart_num > 1) {
              item.cart_num++;
            } else if (type === 'add' && item.cart_num < item.branch_stock) {
              item.cart_num--;
            }
            this.$Message.error(err.msg);
          });
    },
    changeCartAttr() {
      this.cartInfo.unique =
          this.attr.productSelect !== undefined
              ? this.attr.productSelect.unique
              : '';

      cashierchangeCart(this.cartInfo)
          .then((res) => {
            this.disabled = true;
            this.$Message.success(res.msg);
            this.$refs.attrs.modals = false;
            this.getCartList();
            this.cartCompute();
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    goCat(e,datas,num) {
     this.selectedProduct = (datas && datas.selectedProduct) ? datas.selectedProduct : [];
     const isGiftProject = this.isGiftProjectShell(this.storeInfo);
     if (isGiftProject) {
       const picked = Array.isArray(this.selectedProduct) ? this.selectedProduct.slice() : [];
       if (!picked.length) {
         return this.$Message.error('请选择赠送哪些项目');
       }
       this.giftProjectSelectedProducts = picked;
       this.selectedProduct = picked;
       this.$refs.attrs.modals = false;
       if (this.getGiftProjectCartItem()) {
         return;
       }
       if (e) {
         return;
       }
     }
	   this.reservationCart = num;
      if (e) {
        this.changeCartAttr();
      } else {
        this.joinCart(1,datas);
      }
      this.addStaff(datas);
    },
    addStaff(datas){
      return false;
        //服务人员加入业绩分配
       if(datas.service_staff_id > 0){
            var data={
               goods_id:this.chooseProduct.product_id,
               price:this.chooseProduct.price,
               staff_id:datas.service_staff_id,
            };
             let that=this;
              staffYeji(data).then((res)=>{
                    if(res.data){
                         that.doChoose(res.data);
                    }
             })
        }
    },
    //秒杀购买
    goPay() {
      if (this.storeInfo.product_type === 4) {
        // 次卡商品
        this.joinCart(0);
      } else {
        this.joinSkillCart(0);
      }
    },
    joinSkillCart(num) {
      let that = this;
      if (num) {
        let productSelect = that.productValue[this.attrValue];
        //如果有属性,没有选择,提示用户选择
        if (that.attr.productAttr.length && productSelect === undefined) {
          return this.$Message.warning('产品库存不足，请选择其它');
        }
      }
      let uid = this.userInfo.uid;
      let data = {
        productId: this.productId,
        secKillId: this.seckillId,
        cartNum: 1,
        uniqueId: this.attr.productSelect.unique,
        staff_id: this.storeInfos.id,
        tourist_uid: this.userInfo.touristId,
        new: 1,
      };
      cashierCart(uid, data)
          .then((res) => {
            this.seckillOrderId = res.data.cartId;
            this.$refs.skillAttrs.modals = false;
            this.cartComputeActivity(res.data.cartId);
            this.disabled = true;
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    // 获取用户详情
    getUserDetail() {
      if (this.userInfo.uid) {
        this.$refs.userDetails.modals = true;
        this.$refs.userDetails.activeName = 'card_holder';
        this.$refs.userDetails.getDetails(this.userInfo.uid);
      }
    },
    // 购物车列表
    getCartList() {
      let uid = this.userInfo.uid || 0;
      let staffId = this.storeInfos.id || 0;
      if (uid >= 0) {
        let data = { tourist_uid: this.userInfo.touristId };
        cashierCartList(uid, staffId, data)
            .then((res) => {
              this.cartList = res.data.valid;
              this.cartList.forEach((item2) => {
                (item2.cart || []).forEach((item3) => {
                  const qty = Math.max(Number(item3.cart_num || 1), 1);
                  const unit = this.resolveBundleItemUnitPrice(item3);
                  const lineAmount = Number((unit * qty).toFixed(2));
                  item3.before_price = item3.change_price != null && item3.change_price !== ''
                    ? this.normalizeBundleLineTotal(item3, item3.change_price)
                    : lineAmount;
                  if (typeof item3.show_yue_pay === 'undefined') this.$set(item3, 'show_yue_pay', false);
                  if (typeof item3.yue_pay_amount === 'undefined') this.$set(item3, 'yue_pay_amount', '');
                  this.ensureCardUpgradeFields(item3);
                  if (Number(item3.product_type) === 6 && typeof item3.service_object === 'undefined') {
                    this.$set(item3, 'service_object', '本人');
                  }
                });
              });
              this.invalidList = res.data.invalid;
              this.cartSum = res.data.count;
              this.setDingzhi();
              this.applyProductSendPreset();
              if (res.data.valid.length) {
                this.cartCompute();
              } else {
                this.clear();
              }
            })
            .catch((err) => {
              this.$Message.error(err.msg);
            })
            .finally((e) => {
              this.cumping = false;
            });
      } else {
        this.$Message.error('请添加或选择用户');
      }
    },
    //判断是否定制卡
    setDingzhi(){
      var isDingzhi=false;
      this.cartList.forEach((item) => {
        item.cart.forEach((good) => {
          good.is_dingzhi=true;
          good.true_dingzhi=false;
          if (this.isCustomCardProduct(good)) {
            isDingzhi=true;
          }
        });
      });
      if(isDingzhi) {
        const bundleTotal = this.getCustomCardBundleTotal();
        const bundleCartIds = new Set();
        this.cartList.forEach((item) => {
          item.cart.forEach((good) => {
            if (this.isCustomCardShell(good)) {
              good.is_dingzhi=true;
              good.true_dingzhi=true;
              this.$set(good, 'pay_price', bundleTotal);
              this.$set(good, 'sum_price', bundleTotal);
              this.$set(good, 'coupon_id', 0);
              this.$set(good, 'coupon_info', null);
              if (Number(this.createOrder.is_price) === 1) {
                this.$set(good, 'change_price', bundleTotal);
              }
            } else {
              bundleCartIds.add(good.id);
              good.is_dingzhi=false;
              good.true_dingzhi=false;
              this.$set(good, 'show_yue_pay', false);
              this.$set(good, 'yue_pay_amount', '');
              this.$set(good, 'card_upgrade_enabled', false);
              this.$set(good, 'card_upgrade_amount', 0);
              this.$set(good, 'card_upgrade_old_oid', 0);
              this.$set(good, 'card_upgrade_old_cart_info_id', 0);
              this.$set(good, 'card_upgrade_old_label', '');
            }
          });
        });
        if (bundleCartIds.size) {
          this.setYejiAll = this.setYejiAll.filter((row) => !bundleCartIds.has(row.cart_id));
          this.serviceYejiAll = this.serviceYejiAll.filter((row) => !bundleCartIds.has(row.cart_id));
        }
      }
      this.is_dingzhi=isDingzhi;
    },
    // 选择属性
    async attrTap(item) {
      // 一个卡项在购物车只能有一件
      if (item.product_type == 5 || item.product_type == 4) {
        for (let i = 0; i < this.cartList.length; i++) {
          for (let j = 0; j < this.cartList[i].cart.length; j++) {
            if (this.cartList[i].cart[j].product_id == item.product_id) {
              if (this.isGiftProjectShell(item)) {
                return this.$Message.warning('购物车中已有赠送项目');
              }
              return
            }
          }
        }
      }
      // 定制卡只能选择一张（但允许与其他品项一起结账）
      if (this.isCustomCardShell(item) && this.cartHasCustomCardShellInCart()) {
        return this.$Message.warning('定制卡只能选择一张');
      }
      const clickingGiftProject = this.isGiftProjectShell(item);
      if (clickingGiftProject) {
        const existingGift = this.getGiftProjectCartItem();
        if (existingGift) {
          return this.$Message.warning('购物车中已有赠送项目');
        }
        if (this.cartList.length || this.invalidList.length) {
          await this.clearCartSilently();
        }
        if (!(this.userInfo && this.userInfo.uid >= 0)) {
          return this.$Message.error('请添加或选择用户');
        }
        this.productId = item.product_id;
        this.storeInfo = Object.assign(
          {},
          item.productInfo || {},
          {
            store_name: this.resolveProductName(item),
            product_type: item.product_type,
          }
        );
        this.$refs.attrs.productType = item.product_type;
        this.disabled = false;
        this.reservationCart = 2;
        return this.joinCart(0);
      } else if (this.cartHasGiftProjectShell()) {
        return this.$Message.warning('购物车里有赠送项目，无法添加其他项目');
      }
      const clickingCardLike = this.isCardLikeProduct(item);
      if (clickingCardLike) {
        if ((this.cartList.length || this.invalidList.length) && (this.cartHasNonCardLike() || this.cartHasCardLike())) {
          await this.clearCartSilently();
        }
      } else {
        if (this.cartHasCardLike()) {
          return this.$Message.warning('卡项只能单独购买，不能与其他品项共同结账');
        }
      }
      this.$refs.attrs.productType = item.product_type;
      this.$refs.attrs.formValidate = {
        phone:'',
        real_name:'',
        reservation_time:'',
        reservation_time_id:0
      }
      this.disabled = false;
      if (this.userInfo && this.userInfo.uid >= 0) {
        this.productId = item.product_id;
        this.storeInfo = {};
        if(item.product_type == 5){
          // if (!item.stock) return this.$Message.error('暂无库存');
          if (this.activityFrom.type === '5') {
            this.seckillId = item.id;
            this.isCart = 0; //判断切换属性或是加入购物车：0加入购物车；1切换属性
            this.$refs.skillAttrs.modals = true;
            this.cashierGetAttr(item.id);
          } else if (item.spec_type || item.product_type == 6 || item.product_type == 5 || item.product_type == 4) {
            // 多规格
            this.isCart =0; //判断切换属性或是加入购物车：0加入购物车；1切换属性
            this.$refs.attrs.modals = true;
            this.goodsInfo(item.product_id || item.id);
          } else {
            // 0为单规格属性
            if (item.product_type === 4) {
              // 次卡商品
              this.isCart = 0;
              this.$refs.skillAttrs.modals = true;
              this.goodsInfo(item.product_id || item.id);
            } else {
              this.joinCart(0);
            }
          }
        }else{
          this.joinCart(0);
        }
      } else {
        this.$Message.error('请添加或选择用户');
      }
    },
    // 商品详情
    goodsInfo(id) {
      cashierDetail(id, this.userInfo.uid)
          .then((res) => {
            let data = res.data;
            this.storeInfo = data.storeInfo;
            this.productValue = data.productValue;
            this.$set(this.attr, 'productAttr', data.productAttr);
            if (res.data.storeInfo.product_type == 5) {
              this.getCardRelated(id);
            }
            this.DefaultSelect();
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    // 商品详情
    cashierGetAttr(id) {
      cashierGetAttr(id, this.userInfo.uid)
          .then((res) => {
            let data = res.data;
            this.storeInfo = data.storeInfo;
            this.productValue = data.productValue;
            this.$set(this.attr, 'productAttr', data.productAttr);
            this.DefaultSelect();
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    /**
     * 默认选中属性
     *
     */
    DefaultSelect: function () {
      const preservedGiftSelection = this.isGiftProjectShell(this.storeInfo)
        ? this.getGiftProjectSelectedProducts().slice()
        : [];
      this.selectedProduct = preservedGiftSelection.length ? preservedGiftSelection.slice() : [];
      let productAttr = this.attr.productAttr;
      let value = [];
      // for (var key in this.productValue) {
      //   if (this.productValue[key].stock > 0) {
      //     value = this.attr.productAttr.length ? key.split(',') : [];
      //     break;
      //   }
      // }
      const values = Object.values(this.productValue);
      const stockValues = values.filter((item) => item.stock > 0);
      const defaultValue = stockValues.find((item) => item.is_default_select > 0);
      if (defaultValue) {
        value = defaultValue.suk.split(',');
      } else {
        value = stockValues.length > 0 ? stockValues[0].suk.split(',') : [];
      }
      //isCart 1为触发购物车 0为商品
      if (this.isCart) {
        //购物车默认打开时，随着选中的属性改变
        let attrValue = [];
        this.cartList.forEach((item) => {
          item.cart.forEach((res) => {
            if (res.id == this.cartInfo.cart_id) {
              attrValue = res.productInfo.attrInfo.suk.split(',');
            }
          });
        });
        for (let i = 0; i < productAttr.length; i++) {
          this.$set(productAttr[i], 'index', attrValue[i]);
        }
      } else {
        for (let i = 0; i < productAttr.length; i++) {
          this.$set(productAttr[i], 'index', value[i]);
        }
      }
      //sort();排序函数:数字-英文-汉字；
      let productSelect = this.productValue[value.join(',')];
      this.chooseProduct=productSelect;
      if (productSelect && productAttr.length) {
        this.$set(
          this.attr.productSelect,
          'store_name',
          this.storeInfo.store_name
        );
        this.$set(this.attr.productSelect, 'image', productSelect.image);
        this.$set(this.attr.productSelect, 'price', productSelect.price);
        this.$set(this.attr.productSelect, 'card_num', productSelect.card_num);
        this.$set(this.attr.productSelect, 'card_num_type', productSelect.card_num_type);
        this.$set(
          this.attr.productSelect,
          'selectedProduct',
          preservedGiftSelection.length ? preservedGiftSelection.slice() : []
        );
        this.$set(this.attr.productSelect, 'stock', productSelect.stock);
        this.$set(this.attr.productSelect, 'unique', productSelect.unique);
        this.$set(this.attr.productSelect, 'cart_num', 1);
        this.$set(this, 'attrValue', value.join(','));
		this.$refs.attrs.reservationTimeData = productSelect.reservationTimeData;
      } else if (!productSelect && productAttr.length) {
        this.$set(
          this.attr.productSelect,
          'store_name',
          this.storeInfo.store_name
        );
        this.$set(this.attr.productSelect, 'image', this.storeInfo.image);
        this.$set(this.attr.productSelect, 'price', this.storeInfo.price);
        this.$set(this.attr.productSelect, 'card_num', this.storeInfo.card_num);
        this.$set(this.attr.productSelect, 'card_num_type', this.storeInfo.card_num_type);
        this.$set(this.attr.productSelect, 'selectedProduct',[]);
        this.$set(this.attr.productSelect, 'stock', 0);
        this.$set(this.attr.productSelect, 'unique', '');
        this.$set(this.attr.productSelect, 'cart_num', 0);
        this.$set(this, 'attrValue', '');
      } else if (!productSelect && !productAttr.length) {
        this.$set(
          this.attr.productSelect,
          'store_name',
          this.storeInfo.store_name
        );
        this.$set(this.attr.productSelect, 'card_num', this.storeInfo.card_num);
        this.$set(this.attr.productSelect, 'card_num_type', this.storeInfo.card_num_type);
        this.$set(this.attr.productSelect, 'image', this.storeInfo.image);
        this.$set(this.attr.productSelect, 'price', this.storeInfo.price);
        this.$set(this.attr.productSelect, 'stock', this.storeInfo.stock);
        this.$set(this.attr.productSelect, 'selectedProduct',[]);
        this.$set(
          this.attr.productSelect,
          'unique',
          this.storeInfo.unique || ''
        );
        this.$set(this.attr.productSelect, 'cart_num', 1);
        this.$set(this, 'attrValue', '');
      }
    },
    /**
     * 属性变动赋值
     *
     */
    ChangeAttr(res) {
      let productSelect = this.productValue[res];
      if (productSelect && productSelect.stock > 0) {
        this.$set(this.attr.productSelect, 'image', productSelect.image);
        this.$set(this.attr.productSelect, 'price', productSelect.price);
        this.$set(this.attr.productSelect, 'stock', productSelect.stock);
        this.$set(this.attr.productSelect, 'unique', productSelect.unique);
        this.$set(this.attr.productSelect, 'cart_num', 1);
        this.$set(
          this.attr.productSelect,
          'vip_price',
          productSelect.vip_price
        );
        this.$set(this, 'attrValue', res);
		this.$refs.attrs.reservationTimeData = productSelect.reservationTimeData;
      } else {
        this.$set(this.attr.productSelect, 'image', this.storeInfo.image);
        this.$set(this.attr.productSelect, 'price', this.storeInfo.price);
        this.$set(this.attr.productSelect, 'stock', 0);
        this.$set(this.attr.productSelect, 'unique', '');
        this.$set(this.attr.productSelect, 'cart_num', 0);
        this.$set(
          this.attr.productSelect,
          'vip_price',
          this.storeInfo.vip_price
        );
        this.$set(this, 'attrValue', '');
      }
    },
    storeTap() {
      this.$refs.store.modals = true;
      this.$refs.store.getList();
    },
    setUp(touristId, init) {
      let timestamp = new Date().getTime();
      let userInfo = {
        avatar: require('@/assets/images/yonghu.png'),
        nickname: '游客',
        uid: 0,
        touristId: touristId || timestamp,
      };
      if (!touristId) {
        this.getSwithUser({ tourist_uid: timestamp });
      }
      this.userInfo = userInfo;
      let storage = window.localStorage;
      storage.setItem('cashierUser', JSON.stringify(userInfo));
      if (init) return;
      this.getCartList();
      // this.reloadList();
      // this.goodList();
      this.goodListRefresh();
    },
    // 选择用户
    changeMenu(name) {
      if (name == 1) {
        this.memberTap();
      } else {
        this.activeHangon = -1;
        this.clear();
        this.setUp();
      }
    },
    // 修改用户
    setUser() {
      this.modalUser = true;
    },
    // 当前选中门店店员信息
    getStoreId(e) {
      this.clear();
      this.storeList.forEach((i) => {
        if (i.id == e.id) {
          sessionStorage.setItem('staffInfo', JSON.stringify(e));
          this.goodFrom.staff_id = e.id;
          this.storeInfos = i;
          this.getCartList();
          this.goodListRefresh();
          this.hangDataList();
          this.getSwithUser({ cashier_id: e.id });
        }
      });
    },
    // 门店店员信息以及门店店员列表
    getUserInfo(e) {
      this.storeInfos = e.users;
      this.storeList = e.storeList;
      this.goodFrom.staff_id = e.users.id;
      sessionStorage.setItem('staffInfo', JSON.stringify(e.users));
      if (this.userInfo) {
        this.getCartList();
      } else {
        this.setUp();
      }
      this.hangDataList();
    },
    // 收银台切换购物车用户
    cashierSwitch(data) {
      postCashierSwitch(data, this.storeInfos.id)
          .then((res) => {})
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    getUserId(e) {
      this.clear();
      let data = {
        uid: e.uid,
      };
      let dataSwitch = {
        uid: this.userInfo.touristId,
        to_uid: e.uid,
        is_tourist: 1,
      };
      this.cashierSwitch(dataSwitch);
      this.userInfoData(data);
      this.getSwithUser({ uid: e.uid });
    },
    checkUserDebtReminder() {
      const uid = this.userInfo && this.userInfo.uid;
      if (!uid) return;
      debtSummaryApi({ uid }).then((res) => {
        const data = res.data || {};
        if (Number(data.total_pending || 0) > 0 && Number(data.count || 0) > 0) {
          this.debtReminderVisible = true;
        }
      }).catch(() => {});
    },
    onDebtReminderRepay(row) {
      this.debtReminderVisible = false;
      this.debtRepayRow = { ...row };
      this.debtRepayVisible = true;
    },
    onDebtRepayFromDetail(data) {
      this.onDebtRepayPay(data);
    },
    openDebtRecords() {
      this.debtReminderVisible = false;
      if (this.$refs.userDetails) {
        this.$refs.userDetails.modals = true;
        this.$refs.userDetails.activeName = 'debt_record';
        this.$refs.userDetails.getDetails(this.userInfo.uid);
      }
    },
    refreshUserDebtRecords() {
      if (this.$refs.userDetails && this.$refs.userDetails.reloadDebtRecord) {
        this.$refs.userDetails.reloadDebtRecord(true);
      }
    },
    onDebtRepayPay(data) {
      this.openDebtRepaySettle(data);
    },
    openDebtRepaySettle(data) {
      this.isDebtRepay = 1;
      this.debtRepayData = { ...data };
      this.lockDebtRepaySource = true;
      this.debtRepayInitialSource = Number(data.original_source || 0);
      this.createOrder.source = this.debtRepayInitialSource;
      this.hideYuePayOption = false;
      this.forceCombinationPay = false;
      this.lockSettleYueEdit = false;
      this.initCombinationInfo = [];
      this.isRecharge = 0;
      this.isOrderCreate = 0;
      this.settleMoney = data.repay_amount;
      this.collection = data.repay_amount;
      this.payList.forEach((value, index, arr) => {
        value.status = true;
        value.num = 3;
        if (!this.userInfo.uid) {
          value.num = 2;
          if (value.value === 'yue') value.status = false;
        }
        if (value.status && (!index || !arr[index - 1].status)) {
          this.payType = value.value;
          this.createOrder.pay_type = value.value;
        }
      });
      this.yueVerify = !!this.priceInfo.is_cashier_yue_pay_verify;
      this.zIndex = this.resolveSettleZIndex();
      this.settleVisible = true;
      this.$nextTick(() => {
        const settle = this.$refs.settlePay;
        if (settle) {
          settle.combinationPay = 0;
          settle.combination_info = [];
          settle.activePay = 0;
          settle.payLabel = '请选择支付方式';
        }
      });
    },
    resolveSettleZIndex() {
      let zIndex = 9999;
      const userDetails = this.$refs.userDetails;
      if (userDetails && userDetails.modals && userDetails.$el) {
        const mask = userDetails.$el.querySelector('.ivu-drawer-mask');
        if (mask && mask.style.zIndex) {
          zIndex = 1 + Number(mask.style.zIndex);
        }
      }
      return zIndex;
    },
    debtRepaySubmit(payNum) {
      if (this.payType === 'cash') {
        if (parseFloat(this.settleMoney) > parseFloat(this.collection)) {
          return this.$Message.error('您付款金额不足');
        }
      }
      const combo = this.createOrder.combination_info || [];
      const payType = combo.length ? 'combination' : this.payType;
      const payload = {
        debt_id: this.debtRepayData.debt_id,
        debt_item_id: this.debtRepayData.debt_item_id,
        repay_amount: this.debtRepayData.repay_amount,
        pay_type: payType,
        source: this.createOrder.source || this.debtRepayInitialSource || 0,
        cash_choose: this.createOrder.cash_choose || 0,
        remark_info: this.createOrder.remarkInfo || {},
        budan_time: this.createOrder.budan_time || '',
        combination_info: combo,
        user_code: payType === 'yue' ? payNum : (this.createOrder.userCode || ''),
        auth_code: payType === '' ? payNum : (this.createOrder.auth_code || payNum || ''),
        is_budan: this.createOrder.is_budan || 0,
        setYejiAll: this.debtRepayData.setYejiAll || [],
      };
      debtRepayPayApi(payload).then((res) => {
        this.payNum = '';
        if (res.data.status === 'SUCCESS') {
          this.isDebtRepay = 0;
          this.lockDebtRepaySource = false;
          this.debtRepayInitialSource = 0;
          this.debtRepayData = {};
          this.settleVisible = false;
          this.paySuccess = true;
          this.refreshUserDebtRecords();
          this.userInfoData({ uid: this.userInfo.uid }, true);
          if (this.payType === 'cash') this.jsToJava();
        } else if (res.data.status === 'PAY_ING') {
          this.$Message.warning(res.data.message || '等待支付');
        } else {
          this.$Message.error(res.data.message || '还款失败');
        }
      }).catch((err) => {
        this.$Message.error(err.msg || '还款失败');
      });
    },
    checkUser() {
      this.userInfoShow = false;
      this.goodFrom.store_name = '';
      this.getUserId(this.modalUserInfo);
    },
    // 获取收银台用户信息
    userInfoData(data, init) {
      cashierUser(data)
          .then((res) => {
            this.userInfo = res.data;
            let storage = window.localStorage;
            storage.setItem('cashierUser', JSON.stringify(res.data));
            if (init) return;
            this.payList.forEach((value) => {
              value.status = true;
              value.num = 3;
            });
            this.hangDataList();
            this.getCartList();
            this.checkUserDebtReminder();
            // this.reloadList();
            // this.goodList();
            this.goodListRefresh();
            this.defaultSel(1);
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    //扫码枪扫码，针对带有字母的
    inputSaoMa(e) {
      // setTimeout定时器的作用是，等待扫码枪输入完，拿到完整的二维码信息，再调接口（扫码枪输入速度大概8~20毫秒，手动输速度大概是80毫秒），否则拿不到完整的二维信息。
      let val = e;
      if (val === '') return false;
      clearTimeout(this.endTimeout);
      this.endTimeout = null;
      this.endTimeout = setTimeout(() => {
        if (this.codeNum === val) {
          clearTimeout(this.endTimeout);
          if (val) {
            this.codeInfo({
              bar_code: val,
            });
          }
        }
      }, 500);
    },
    // 用户详情操作
    operation(type) {
      this.$refs.userDetails.modals = false;
      if (type === 1) {
        this.rechargeBnt();
      } else {
        this.setUser();
      }
    },
    codeInfo(data) {
      data.uid = this.userInfo ? this.userInfo.uid : 0;
      data.staff_id = this.storeInfos.id;
      data.tourist_uid = this.userInfo.touristId;
      if (this.userInfo == null) {
        this.codeNum = '';
        return this.$Message.error('请添加或选择用户');
      }
      cashierCode(data)
          .then((res) => {
            this.codeNum = '';
            let data = res.data;
            if (data.hasOwnProperty('userInfo')) {
              // 用户 Object.keys(this.userInfo).length
              if (this.userInfo) {
                this.$Modal.confirm({
                  title: '切换用户',
                  content: '<p>确定要切换用户吗？</p>',
                  onOk: () => {
                    this.userInfo = res.data.userInfo;
                    let storage = window.localStorage;
                    storage.setItem(
                        'cashierUser',
                        JSON.stringify(res.data.userInfo)
                    );
                    this.getCartList();
                  },
                  onCancel: () => {},
                });
              } else {
                this.userInfo = res.data.userInfo;
                let storage = window.localStorage;
                storage.setItem('cashierUser', JSON.stringify(res.data.userInfo));
              }
            }
            this.goodList();
            this.getCartList();
          })
          .catch((err) => {
            this.codeNum = '';
            this.$Message.error(err.msg);
          });
    },
    // 点击三级分类
    treeCate(e){
      this.cateTap(e,1)
    },
    //点击一级分类
    cateTap(data,num) {
      let item = num == 1? data[0] : this.cateData.find(i=> i.id==data);
      if(!num){
        this.cateDataMore.forEach(i=>{
          let selectedObj = function(i){
            if(i.children && i.children.length){
              i.children.forEach(j=>{
                j.selected = false;
                delete j.selected
                selectedObj(j)
              })
            }
          }
          if(i.id == item.id){
            this.$set(i, 'selected', true)
          }else{
            this.$set(i, 'selected', false)
            selectedObj(i)
          }
        })
      }
      if((item && this.goodFrom.cate_id == item.id) || !item) return;
      this.currentCate = String(num == 1?item.yid:item.id);
      this.goodFrom.cate_id = item.id;
      this.goodFrom.promotions_id = 0;
      this.activityFrom.type = 0;
      this.activityFrom.page = 1;
      this.goodFrom.page = 1;
      this.goodFrom.store_name = '';
      this.goodData = [];
      this.activityTypeArr = [];
      this.swiperClickedIndex = 0;
      this.activityFrom.promotions_id = 0;
      if (item.id !== '99999') {
        this.seckillId = 0;
        this.goodList();
      }
    },
    //三级分类列表
    cateListMore() {
      productCate({
        relation_id: this.relation_id
      }).then(res=>{
        res.data.forEach(item=>{
          item.yid = item.id;
          let getIds = function(item){
            if(item.children && item.children.length){
              item.children.forEach(j=>{
                j.yid = item.yid
                getIds(j)
              })
            }
          }
          getIds(item)
        })
        this.cateDataMore = res.data;
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    //一级分类列表
    cateList() {
      let form=this.goodFrom;
      form.relation_id=this.relation_id;
      cashierCate(form)
          .then((res) => {
            let all = [
              {
                cate_name: '全部商品',
                id: '-1',
              },
              {
                cate_name: '活动商品',
                id: '99999',
              },
            ];
            let data = [...all, ...res.data];
            this.cateData = data;
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    //商品列表
    goodList(type, scanData) {
      if (this.activityFrom.type) {
        this.activityFrom.uid = this.userInfo ? this.userInfo.uid : 0;
        this.activityFrom.type = type;
        this.activityFrom.staff_id = this.storeInfos.id;
        if (!this.userInfo.uid)
          this.activityFrom.tourist_uid = this.userInfo.touristId;
        activityList(this.activityFrom).then((res) => {
          let data = res.data;
          this.total = data.count;
          this.goodData = this.goodData.concat(data.list);
        });
      } else {
        this.goodFrom.uid = this.userInfo ? this.userInfo.uid : 0;
        if (!this.userInfo.uid)
          this.goodFrom.tourist_uid = this.userInfo.touristId;
        // scanData = scanData || {};
        this.goodFrom.product_type = this.productType;
        this.goodFrom.cate_id = this.goodFrom.cate_id == -1?'':this.goodFrom.cate_id;
        cashierProduct({ ...this.goodFrom })
            .then((res) => {
              let data = res.data;
              this.total = data.count;
              this.appointNum = data.count_4_5;
              this.cardNum = data.count_6;
              this.goodData = this.goodFrom.page == 1?data.list:this.goodData.concat(data.list);
              if (data.attrValue) {
                // 加入购物车
                this.attr.productSelect.unique = data.attrValue.unique;
                this.productId = data.attrValue.product_id;
                this.joinCart(1);
              }
              if (data.userInfo) {
                this.modalUserInfo = data.userInfo;
                this.userInfoShow = true;
              }
              this.goodFrom.store_name = '';
            })
            .catch((err) => {
              this.$Message.error(err.msg);
            });
      }
    },
    // 活动商品列表
    selectaActivity(type) {
      this.goodData = [];
      this.activityFrom.type = type;
      if (this.activityFrom.type != 5) {
        this.activityTypeList(type);
      }
      this.goodList(type);
    },
    cartComputeActivity(id) {
      const cartCoupons = this.buildCartCoupons();
      let data = {
        integral: false,
        coupon: cartCoupons.length > 0,
        coupon_id: 0,
        cart_id: [id],
        cart_coupons: cartCoupons,
        new: 1,
      };
      cashierCompute(this.userInfo.uid, data)
          .then((res) => {
            this.priceInfo = res.data;
            this.unchangedPrice = this.priceInfo.payPrice || 0;
            this.openSettle();
          })
          .catch((err) => {
            this.$Message.error(err.msg);
            this.coupon = false;
          });
    },
    // 去凑单
    collectOrder(item) {
      this.activeID = 1;
      this.currentCate = '99999';
      this.activityFrom.promotions_id = item.id;
      this.activityFrom.page = 1;
      this.activityFrom.type = item.promotions_type;
      this.activityTypeList(item.promotions_type);
      this.goodListRefresh();
    },
    //搜索
    orderSearch(data) {
      this.goodFrom.page = 1;
      this.goodData = [];
      if (this.activityFrom.type) {
        this.activityFrom.page = 1;
        this.activityFrom.store_name = this.goodFrom.store_name;
        this.goodList(this.activityFrom.type);
      } else {
        this.goodFrom.page = 1;
        this.goodList(null, data);
      }
    },
    pageChange(event) {
      if (
          Math.abs(
              event.target.scrollHeight -
              event.target.clientHeight -
              event.target.scrollTop
          ) < 1
      ) {
        if (this.activityFrom.type) {
          this.activityFrom.page++;
        } else {
          this.goodFrom.page++;
        }
        this.goodList(this.activityFrom.type);
      }
    },
    // 监听键盘函数
    keyboard() {
      let that = this;

      function delNums(item) {
        that.collectionArray.pop();
        that.collection = that.collectionArray.length
            ? that.collectionArray.join('')
            : 0;
      }

      function numTaps(item) {
        if (that.defaultcalc === false) {
          that.collection = '';
          that.defaultcalc = true;
        }
        let x = String(that.collection).indexOf('.') + 1;
        let y = String(that.collection).length - x;
        if (x === 0 || y < 2) {
          if (that.collectionArray.join('') <= 9999999) {
            that.collectionArray.push(item);
          }
          that.collection =
              that.collectionArray.join('') > 99999999
                  ? 99999999
                  : that.collectionArray.join('');
        }
      }

      document.onkeydown = function (event) {
        let e = event || window.event;
        let key = e.keyCode;
        if (that.modalCash) {
          event.stopPropagation(); // 阻止事件冒泡传递
          event.preventDefault(); //阻止默认事件原有功能
        }
        switch (key) {
          case 96:
          case 48:
            numTaps(0);
            break;
          case 97:
          case 49:
            numTaps(1);
            break;
          case 98:
          case 50:
            numTaps(2);
            break;
          case 99:
          case 51:
            numTaps(3);
            break;
          case 100:
          case 52:
            numTaps(4);
            break;
          case 101:
          case 53:
            numTaps(5);
            break;
          case 102:
          case 54:
            numTaps(6);
            break;
          case 103:
          case 55:
            numTaps(7);
            break;
          case 104:
          case 56:
            numTaps(8);
            break;
          case 105:
          case 57:
            numTaps(9);
            break;
          case 110:
            numTaps('.');
            break;
          case 190:
            numTaps('.');
            break;
          case 8:
            delNums();
            break;
          case 13:
            if(that.activeID == 4){
              that.joinCart(0,{},1)
            }
            break;
        }
      };
    },
    /** 结账前：未使用优惠券且存在可用券时提示（与原先逻辑一致） */
    tryOpenSettle() {
      if (!this.cartList.length) {
        return;
      }
      if (this.cartHasGiftProjectShell() && !this.getGiftProjectSelectedProducts().length) {
        return this.$Message.error('请选择赠送哪些项目');
      }
      if (!this.userInfo || !this.userInfo.uid) {
        this.openSettle();
        return;
      }
      if (this.buildCartCoupons().length > 0) {
        this.openSettle();
        return;
      }
      const ids = [];
      this.cartList.forEach((item) => {
        (item.cart || []).forEach((i) => {
          ids.push(i.id);
        });
      });
      if (!ids.length) {
        this.openSettle();
        return;
      }
      const data = { cart_id: ids };
      if (Number(this.createOrder.is_price) === 1 && this.createOrder.cart_info && this.createOrder.cart_info.length) {
        data.is_price = 1;
        data.change_price = Number(this.createOrder.change_price) || 0;
        data.cart_info = this.createOrder.cart_info;
      }
      cashierCouponList(this.userInfo.uid, data)
        .then((res) => {
          const list = res.data || [];
          const hasUsable = list.some((item) => item.can_use !== false);
          if (!hasUsable) {
            this.openSettle();
            return;
          }
          this.$Modal.confirm({
            title: '提示',
            content: '有可以使用的优惠券，是否重新选择？',
            okText: '是',
            cancelText: '否',
            onOk: () => {
              this.openFirstItemCouponPicker();
            },
            onCancel: () => {
              this.openSettle();
            },
          });
        })
        .catch(() => {
          this.openSettle();
        });
    },
    /** 打开第一个未使用优惠券的商品行选券弹窗 */
    openFirstItemCouponPicker() {
      const items = this.getFlatCartItems();
      const target = items.find((item) => !item.coupon_id);
      if (target) {
        this.itemCouponTap(target);
      }
    },
    // 打开结算抽屉
    openSettle() {
      this.isDebtRepay = 0;
      this.lockDebtRepaySource = false;
      this.debtRepayInitialSource = 0;
      this.debtRepayData = {};
      this.createOrder.source = 0;
      this.zIndex = 9999;
      this.collectionArray = [];
      const totalYuePay = this.getCartYuePayTotal();
      const totalDebtPay = this.getCartDebtPayTotal();
      const upgradeItem = this.getFirstCardUpgradeItem();
      const totalCardUpgrade = this.getCartCardUpgradeTotal();
      this.hideYuePayOption = true;
      this.forceCombinationPay = totalYuePay > 0 || totalCardUpgrade > 0 || totalDebtPay > 0;
      this.lockSettleYueEdit = totalYuePay > 0;
      const initList = [];
      if (totalYuePay > 0) {
        initList.push({
        type: 0,
        activePay: 3,
        price: String(totalYuePay),
        is_pay: false,
        name: '余额收款',
        remarkInfo: { water_number: '', remark: '' }
        });
      }
      if (upgradeItem && totalCardUpgrade > 0) {
        initList.push({
          type: 0,
          activePay: 3,
          pay_sub_type: 'card_upgrade',
          upgrade_old_oid: Number(upgradeItem.card_upgrade_old_oid || 0),
          upgrade_old_cart_info_id: Number(upgradeItem.card_upgrade_old_cart_info_id || 0),
          price: String(totalCardUpgrade),
          is_pay: false,
          name: '卡升级',
          remarkInfo: { water_number: '', remark: '' }
        });
      }
      if (totalDebtPay > 0) {
        initList.push({
          type: 10,
          activePay: 3,
          pay_sub_type: 'debt',
          price: String(totalDebtPay),
          is_pay: false,
          name: '欠款',
          remarkInfo: { water_number: '', remark: '' }
        });
      }
      this.initCombinationInfo = initList;
      this.payList.forEach((value, index, arr) => {
        value.status = true;
        value.num = 3;
        if(!this.userInfo.uid || this.priceInfo.yue_pay_status == 2){
          value.num = 2;
          if(value.value === 'yue'){
            value.status = false;
          }
        }
        if (value.status && (!index || !arr[index - 1].status)) {
          this.payType = value.value;
          this.createOrder.pay_type = value.value;
        }
      });
      this.yueVerify = !!this.priceInfo.is_cashier_yue_pay_verify;
      this.settleMoney = this.priceInfo.payPrice;
      this.collection = this.priceInfo.payPrice;
      if (this.forceCombinationPay) {
        this.payType = 'combination';
        this.createOrder.pay_type = 'combination';
        // 组合支付时订单级 cash_choose 须清零，避免残留旧卡录入(9)导致 cash_pay_price=0
        this.createOrder.cash_choose = 0;
      }
      this.payPrice({ type: this.payType, cashChoose: this.createOrder.cash_choose });
      if (this.$refs.settlePay) {
        this.$refs.settlePay.activePay = 0;
        this.$refs.settlePay.payLabel = '请选择支付方式';
        this.$refs.settlePay.source = 0;
      }
      this.isRecharge = 0;
      this.settleVisible = true;
    },
    onRecharge(e) {
      this.isDebtRepay = 0;
      this.lockDebtRepaySource = false;
      this.debtRepayInitialSource = 0;
      this.debtRepayData = {};
      const debtPay = Number(e.debt_pay_amount || 0);
      this.hideYuePayOption = false;
      this.forceCombinationPay = debtPay > 0;
      this.lockSettleYueEdit = false;
      this.initCombinationInfo = [];
      if (debtPay > 0) {
        this.initCombinationInfo = [{
          type: 10,
          activePay: 3,
          pay_sub_type: 'debt',
          price: String(debtPay),
          is_pay: false,
          name: '欠款',
          remarkInfo: { water_number: '', remark: '' }
        }];
      }

      for (let i = 0; i < this.payList.length; i++) {
        this.payList[i].status = this.payList[i].value !== 'yue';
        if (!this.payList[i].status) {
          continue;
        }
        if (!i || !this.payList[i - 1].status) {
          this.payType = this.payList[i].value;
        }
      }
      this.yueVerify = !!this.priceInfo.is_cashier_yue_pay_verify;
      this.settleMoney = e.price;
      this.collection = e.price;
      this.rechargeData.rechar_id = e.rechar_id;
      this.rechargeData.price = e.price;
      this.rechargeData.give_money = e.give_money;
      this.rechargeData.staffChoose=e.staffChoose;
      this.rechargeData.sendAll=e.sendAll;
      this.rechargeData.is_gendan = e.is_gendan || 0;
      this.rechargeData.gendan_staff_id = e.gendan_staff_id || 0;
      this.rechargeData.debt_pay_amount = debtPay;
      this.createOrder.is_gendan = e.is_gendan || 0;
      this.createOrder.gendan_staff_id = e.gendan_staff_id || 0;
      this.createOrder.gendan_staff_name = e.gendan_staff_name || '';
      this.zIndex =
          1 +
          Number(
              this.$refs.recharge.$el.querySelector('.ivu-modal-mask').style.zIndex
          );
      // this.$refs.settlePay.activePay = 1;
      this.$refs.settlePay.activePay = 0;
      this.$refs.settlePay.payLabel = '请选择支付方式';
      this.isRecharge = 1;
      this.settleVisible = true;
      if (debtPay > 0) {
        this.payType = 'combination';
        this.createOrder.pay_type = 'combination';
      }
      this.$nextTick(() => {
        const settle = this.$refs.settlePay;
        if (settle) {
          if (debtPay > 0) {
            settle.combinationPay = 1;
            settle.combination_info = JSON.parse(JSON.stringify(this.initCombinationInfo));
          } else {
            settle.combinationPay = 0;
            settle.combination_info = [];
          }
        }
      });
    },
    activityTypeList(type) {
      activityTypeList(type).then((res) => {
        this.activityTypeArr = [
          {
            desc: '全部',
            id: 0,
          },
          ...res.data,
        ];
      });
    },
    readySwiper(swiper) {
      this.swiper = swiper;
    },
    clickSwiper() {
      if (
          this.swiper.clickedIndex === undefined ||
          this.swiper.clickedIndex === this.swiperClickedIndex
      ) {
        return false;
      }
      this.swiperClickedIndex = this.swiper.clickedIndex;
      this.activityFrom.page = 1;
      this.activityFrom.promotions_id =
          this.activityTypeArr[this.swiperClickedIndex].id;
      this.goodData = [];
      this.goodList(this.activityFrom.type);
    },
    // 充值余额
    rechargeBalance(auth_code) {
      this.rechargeData.uid = this.userInfo.uid;
      this.rechargeData.pay_type = this.payType ? 4 : 3;
      this.rechargeData.real_pay_type = this.payType;
      this.rechargeData.auth_code = auth_code || '';
      this.rechargeData.combination_info=this.createOrder.combination_info;
      this.rechargeData.remarkInfo=this.createOrder.remarkInfo;
      this.rechargeData.cash_choose=this.createOrder.cash_choose;
      this.rechargeData.source=this.createOrder.source;
      this.rechargeData.is_budan=this.createOrder.is_budan;
      this.rechargeData.budan_time=this.createOrder.budan_time;
      this.rechargeData.is_gendan=this.createOrder.is_gendan;
      this.rechargeData.gendan_staff_id=this.createOrder.gendan_staff_id;
      this.rechargeData.debt_pay_amount = Number(this.rechargeData.debt_pay_amount || 0);
      userSaveApi(this.rechargeData)
          .then((res) => {
            let status = res.data.status;
            switch (status) {
              case 'SUCCESS':
                this.rechargeData.sendAll={
                  'product':[],
                  'coupon':[]
                };
                this.$refs.recharge.rechargeData.sendAll={
                  'product':[],
                  'coupon':[]
                };
                this.$refs.recharge.sendNum=0;
                this.rechargeVisible = false;
                // this.$Message.success('充值成功');
                this.$refs.settlePay.payShow = false;
                this.paySuccess = true;
                this.refreshUserDebtRecords();
                let that = this;
                // setTimeout(function(){
                //    that.paySuccess = false;
                // },1000)
                this.settleVisible = false;
                this.userInfoData({ uid: this.userInfo.uid });
                break;
              case 'PAY_ING':
                // let msg = this.$Message.loading({
                //   content: '等待支付中...',
                //   duration: 0,
                // });
                let msg = function(){};
                this.$refs.settlePay.payIng = true;
                this.checkOrderTime(msg,res.data.message,1);
                break;
              default:
                this.errorInfo = res.data.message;
                this.payStatus = true;
                // this.$Message.warning('支付失败');
                break;
            }
          })
          .catch((err) => {
            this.errorInfo = err.msg;
            this.payStatus = true;
            // this.$Message.error(err.msg);
          });
    },
    clears() {
      this.openImage = false;
    },
    getCardRelated(id) {
      cardRelated(id).then((res) => {
        this.attr.productAttr = res.data;
        this.$nextTick(() => {
          this.applyGiftProjectSelectedToAttr();
        });
      });
    },
    bindclick(item) {
      return (item.product_type == 4 || item.product_type == 5) ? null : 'click';
    },
    goodListRefresh() {
      if (this.activityFrom.type) {
        let limit = this.activityFrom.page * this.activityFrom.limit;
        activityList({
          ...this.activityFrom,
          page: 1,
          limit,
        }).then((res) => {
          let data = res.data;
          this.total = data.count;
          this.goodData = data.list;
        });
      } else {
        let limit = this.goodFrom.page * this.goodFrom.limit;
        this.goodFrom.cate_id = this.goodFrom.cate_id == -1?'':this.goodFrom.cate_id;
        this.goodFrom.product_type = this.productType;
        cashierProduct({
          ...this.goodFrom,
          page: 1,
          limit,
        })
            .then((res) => {
              let data = res.data;
              this.total = data.count;
              this.appointNum = data.count_4_5;
              this.cardNum = data.count_6;
              this.goodData = data.list;
            })
            .catch((err) => {
              this.$Message.error(err.msg);
            });
      }
    },
    cardHolderOpen(row) {
      this.cardHolderData = {};
      this.$nextTick(() => {
        this.cardHolderData = row;
        this.cardHolderShow = true;
      });
    },
  },
};
</script>

<style lang="stylus" scoped src="./index.styl"></style>
