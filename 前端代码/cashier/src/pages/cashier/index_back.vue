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
                              >手机号：{{ userInfo.phone }}</span
                            >
							<span @click="phoneTap" v-if="userInfo.uid && !userInfo.phone" class="fs-14 text-wlll-FF7700 pointer">完善手机号</span>
                          </div>
                        </div>
                        <Dropdown
						  v-if="userInfo.uid"
                          class="switchs"
                          trigger="click"
                          @on-click="changeMenu($event)"
                        >
                          <a href="javascript:void(0)">
                            切换会员
                            <Icon type="ios-arrow-down"></Icon>
                          </a>
                          <DropdownMenu slot="list">
                            <DropdownItem name="1">查询会员</DropdownItem>
                            <DropdownItem name="2">游客</DropdownItem>
                          </DropdownMenu>
                        </Dropdown>
						<div @click="memberTap" class="fs-14 text-wlll-FF7700 pointer" v-else>查询会员</div>
                      </div>
                      <div v-if="userInfo.uid" class="user-msg">
                        <span class="balance"
                          >积分<span class="num">{{
                            userInfo.integral
                          }}</span></span
                        >
                        <span class="balance"
                          >余额<span class="num">{{
                            userInfo.now_money
                          }}</span></span
                        >
                      </div>
                    </div>
                  </div>
                  <div class="count">
                    <div class="cart-sel">
                      已选购<span class="num">{{ cartSum }}</span
                      >件
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
                          :class="{ is_give: item.is_gift }"
                          class="item acea-row row-middle"
                        >
                          <div class="picture">
                            <img
                              v-if="item.productInfo.attrInfo"
                              :src="item.productInfo.attrInfo.image"
                            />
                            <img v-else :src="item.productInfo.image" />
                          </div>
                          <div v-if="!item.is_gift" class="text">
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
                            <div class="sum_price">
                              ¥ {{ item.sum_price }}
                              <div class="toyeji" @click="doYeji(item)">业绩分配</div>
                            </div>
                          </div>
                          <div v-else class="text">
                            <div class="give-name line1">
                              {{ item.productInfo.store_name }}
                            </div>
                            <div class="give-info">赠品</div>
                          </div>
                          <div
                            v-if="!item.is_gift"
                            class="del"
                            @click="delCart(item, proindex, indexs, 'cart')"
                          >
                            删除
                          </div>
                          <div
                            v-if="!item.is_gift"
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
                              :readonly="item.product_type == 4 || item.product_type == 5"
                              @on-blur="
                                (e) => {
                                  changeCart(e, item);
                                }
                              "
                            ></InputNumber>
                            <div
                              :class="{
                                'text-wlll-1890FF': item.cart_num < item.productInfo.attrInfo.stock,
                                'text-wlll-EEEEEE': item.product_type == 4 || item.product_type == 5 || item.cart_num >= item.productInfo.attrInfo.stock,
                              }"
                              @[bindclick(item)]="calculate(item, 'add')"
                            >
                              <Icon type="md-add-circle" size="24" />
                            </div>
                          </div>
                          <div v-else class="cartBnt">
                            <span>x{{ item.cart_num }}</span>
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
                      <Button :disabled="!cartList.length" @click="openSettle"
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
                  <Button
                    :disabled="!userInfo.uid || !cartList.length"
                    :class="{ selected: integral }"
                    @click="integralTap"
                    >积分</Button
                  >
                  <Button
                    :disabled="!userInfo.uid || !cartList.length"
                    :class="{ selected: couponId != 0 }"
                    @click="couponTap"
                    >优惠券</Button
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
                            <div v-if="!item.stock && !item.cart_num" class="absolute rd-8 top-0 left-0 right-0 bottom-0 fs-13 text-wlll-FFFFFF bg-w111-303133-60 acea-row row-center-wrapper">暂无库存</div>
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
      v-model="settleVisible"
      :list="payList"
      :type="payType"
      :money="settleMoney"
      :collection="collection"
      :verify="yueVerify"
      :z-index="zIndex"
      @payPrice="payPrice"
      @numTap="numTap"
      @delNum="delNum"
      @cashBnt="cashBnt"
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
      :couponId="couponId == 0 ? -1 : couponId"
      :cartList="cartList"
      :uid="userInfo.uid"
      @getCouponId="getCouponId"
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
        <div class="type" @click="payPrice('')">
          <div class="img">
            <img alt="" src="../../assets/images/wx_zfb_pay.png" />
          </div>
          <div class="text">微信/支付宝</div>
        </div>
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
    <user-details ref="userDetails" @operation="operation" @cardHolderOpen="cardHolderOpen"></user-details>
	<!-- 改价 -->
	<changePrice ref="changePrice" @submitSuccess='submitSuccess'></changePrice>
	<!-- 切换会员/会员设置 -->
	<memberSet ref="memberSet" @submitSuccess='getUserId'></memberSet>
    <yeji :yeji="setYeji" :staffIds="staffIds" @doChoose="doChoose" @closeYeji="closeYeji" :visible="yejiVisible" ref="yeji"></yeji>
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
  </div>
</template>

<script>
import yeji from '@/components/yeji';
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
import '../../assets/js/core.js';
import {
  cashierProduct,
  cashierCate,
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
  staffYeji
} from '@/api/order';
import { checkOrderApi, getUserInfo, userSaveApi } from '@/api/user';
import { activityList, activityTypeList, cardRelated } from '@/api/product';
import Setting from '@/setting';

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
    yeji
  },
  data() {
    return {
      yejiVisible: false,
      staffIds:[],
      setYejiAll:[],
      selectedProduct:[],
      chooseProduct:{},
      setYeji:{
        link_id:0,
        price:0,
        goods_id:0,
        type:2,
        staffChoose:[]
      },
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
      currentCate: 0, //分类的当前index；
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
      modalPay: false,
      payTypeModal: false,
      cartSum: 0,
      priceInfo: {},
      createOrder: {
        new: 0,
        remarks: '',
        change_price: 0,
        cart_id: [], // 购物车id
        userCode: '',
        is_price: 0,
        auth_code: '',
		cart_info: []
      },
      modalCash: false,
      numList: ['7', '8', '9', '4', '5', '6', '1', '2', '3', '0', '.'],
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
          label: '微信/支付宝',
          value: '',
          status: true,
        },
        {
          label: '现金收款',
          value: 'cash',
          status: true,
        },
        {
          label: '余额收款',
          value: 'yue',
          status: true,
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
    };
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
    settleVisible() {
      this.isOrderCreate = 0;
    }
  },
  created() {
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
    this.userInfo =
      JSON.parse(window.localStorage.getItem('cashierUser')) || {};
    if (!this.userInfo.uid) {
      this.setUp();
    }
    this.product_category_status = window.localStorage.getItem("product_category_status") || 0;
    if (this.product_category_status != 0) {
      this.relation_id = window.localStorage.getItem("store_id") || 0;
    }
    this.cateList();
    if (this.$route.query.uid || this.$route.query.tourist_uid) {
      let uid = this.$route.query.uid,
        touristId = this.$route.query.tourist_uid,
        staffId = this.$route.query.staff_id,
        index = this.$route.query.index;
      this.checkOut = 0;
      this.activeHangon = index;
      this.storeInfos.id = staffId;
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
      this.goodList();
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
    });
  },
  beforeDestroy() {
    document.removeEventListener('keydown', this.handleKeydown);
  },
  methods: {
    doChoose(yeji){
      let hasAdd=false;
      let that=this;
       this.setYejiAll.forEach(function (item, index){
            if(item.goods_id == yeji.goods_id){
              hasAdd=true;
              that.setYejiAll[index]=yeji;
            }
       })
      if(!hasAdd){
        this.setYejiAll.push(yeji);
      }
      this.closeYeji();
    },
    closeYeji(){
      this.yejiVisible=false;
    },
    doYeji(row){
      this.setYeji={
        link_id:0,
        price:row.sum_price,
        goods_id:row.product_id,
        type:2,
        staffChoose:[]
      }
      this.staffIds=[];
      let that=this;
      this.setYejiAll.forEach(function (item, index){
        if(item.goods_id == row.product_id){
             that.setYeji=item;
             item.staffChoose.forEach(function (item){
               that.staffIds.push(item.staff_id);
             })
        }
      })
      this.yejiVisible=true;
    },
	phoneTap(){
		this.$refs.memberSet.formValidate.nickname = this.userInfo.nickname;
		this.$refs.memberSet.formValidate.uid = this.userInfo.uid;
		this.$refs.memberSet.isPhone = 1;
		this.$refs.memberSet.modal2 = true;
	},
	memberTap(){
		this.$refs.memberSet.modal = true;
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
      if (event.key === 'Enter') {
        this.orderSearch(this.goodFrom.store_name);
      } else {
        this.goodFrom.store_name += event.key;
      }
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
      this.coupon = false;
      this.couponId = 0;
      this.integral = false;
      this.createOrder.is_price = 0;
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
    },
    //点击出现优惠明细
    discountCon() {
      this.discount = true;
    },
    //现金收款创建订单并支付
    cashBnt(payNum) {
      this.payNum = payNum;
      if (this.cashBntLoading) return;
      this.cashBntLoading = true;
      if (this.payType === 'yue') {
        this.createOrder.userCode = payNum;
      } else if (this.payType === '') {
        this.createOrder.auth_code = payNum;
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
      if (x === 0 || y < 2) {
        if (this.collectionArray.join('') <= 9999999) {
          this.collectionArray.push(item);
        }
        this.collection =
          this.collectionArray.join('') > 99999999
            ? 99999999
            : this.collectionArray.join('');
      }
    },
    checkOrderTime(msg) {
      let that = this;
      let num = 1;
      let timer = (this.orderSystem.timer = setInterval(function () {
        that.confirmOrder(timer, msg);
        num++;
        if (num >= 60) {
          clearInterval(timer);
          msg();
          that.isOrderCreate = 1;
          that.$Message.success('支付失败');
        }
      }, 1000));
    },
    confirmOrder(timer, msg) {
      let data = {
        order_id: this.orderId,
      };
      checkOrderApi(3, data)
        .then((res) => {
          if (res.data.status == true) {
            msg();
            clearInterval(timer);
            this.isOrderCreate = 0;
            this.$Message.success('支付成功');
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
        });
    },
    payPrice(payType) {
      this.payType = payType;
      this.createOrder.auth_code = '';
      this.createOrder.userCode = '';
      if (payType == '' || payType == 'yue') {
      } else if (payType == 'cash') {
        this.keyboard();
      }
      this.createOrder.integral = this.integral;
      this.createOrder.coupon = this.coupon;
      this.createOrder.coupon_id = this.couponId;
      if (this.coupon && !this.couponId)
        return this.$Message.error('请选择有效优惠券');
      this.createOrder.pay_type = payType;
      this.createOrder.staff_id = this.storeInfos.id;
      this.collection = payType == 'cash' ? this.settleMoney : 0;
    },
    // 线上支付和余额支付
    confirm(payNum) {
      this.createOrder.userCode = payNum;
      this.createOrder.auth_code = payNum;
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
        if (parseFloat(this.priceInfo.payPrice) > parseFloat(this.collection)) {
          return this.$Message.error('您付款金额不足');
        }
      }
      cashierPay(this.orderId, data)
        .then((res) => {
          this.payNum = '';
          if (res.data.status == 'SUCCESS') {
            this.isOrderCreate = 0;
            this.$Message.success('支付成功');
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
            let msg = this.$Message.loading({
              content: '等待支付中...',
              duration: 0,
            });
            this.orderSystem.loadingMsg = msg;
            this.orderId = res.data.order_id;
            this.checkOrderTime(msg);
          } else {
            this.isOrderCreate = 1;
            this.orderId = res.data.order_id;
            this.$Message.error(res.data.message);
          }
        })
        .catch((err) => {
          this.payNum = '';
          this.$Message.error(err.msg);
        });
    },

    // 创建订单
    orderCreate() {
      if (this.payType == 'cash') {
        if (parseFloat(this.priceInfo.payPrice) > parseFloat(this.collection)) {
          return this.$Message.error('您付款金额不足');
        }
      }
      this.createOrder.tourist_uid = this.userInfo.touristId;
	    this.createOrder.new = 0;
      if (this.activityFrom.type == 5) {
        this.createOrder.cart_id = [this.seckillOrderId];
        this.createOrder.new = 1;
      } else if (this.storeInfo.product_type == 6 && this.reservationCart == 1) {
        this.createOrder.new = 1;
      }
      this.createOrder.setYejiAll=this.setYejiAll;
      this.createOrder.selectedProduct=this.selectedProduct;
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
              this.$Message.success('支付成功');
              let money = this.$computes.Sub(
                this.userInfo.now_money,
                this.priceInfo.payPrice
              );
              this.userInfo.now_money = money;
              this.payTypeModal = false;
              storage.setItem('cashierUser', JSON.stringify(this.userInfo));
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
          if (this.payType == 'cash') {
            if (res.data.status == 'SUCCESS') {
              this.$Message.success('支付成功');
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
              let msg = this.$Message.loading({
                content: '等待支付中...',
                duration: 0,
              });
              this.orderId = res.data.order_id;
              this.checkOrderTime(msg);
            } else if (res.data.status == 'SUCCESS') {
              this.$Message.success('支付成功');
              storage.setItem('cashierUser', JSON.stringify(this.userInfo));
              this.settleVisible = false;
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
        })
        .catch((err) => {
          this.payNum = '';
          this.$Message.error(err.msg);
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
      this.hangDataTap(0, this.hangData[0]);
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
      let data = {
        integral: this.integral,
        coupon: this.coupon,
        coupon_id: this.couponId,
        cart_id: ids,
      };
      if (cartId) {
        data.new = 1;
      }
      cashierCompute(this.userInfo.uid, data)
        .then((res) => {
		  let data = res.data;
          this.priceInfo = data;
		  this.orderCartInfo = data.cartInfo;
          this.unchangedPrice = this.priceInfo.payPrice || 0;
          if (cartId) {
            this.openSettle();
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
    // 点击使用优惠券
    couponTap() {
      this.$refs.coupon.modals = true;
      this.$refs.coupon.currentid = this.couponId || 0;
      this.$refs.coupon.getList();
    },
    getCouponId(e) {
      this.couponId = e.id;
      this.coupon = true;
      this.$refs.coupon.modals = false;
      if (e.id) this.createOrder.is_price = 0;
      this.cartCompute();
    },
    closeCoupon() {
      this.coupon = false;
      this.couponId = 0;
      this.cartCompute();
    },
    // 是否使用积分
    integralTap() {
      if (!this.userInfo.uid) {
        this.$Message.warning('请先选择用户再使用积分');
        return;
      }
      this.integral = !this.integral;
      if (this.integral) this.createOrder.is_price = 0;
      this.cartCompute();
    },
    changePrice() {
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
    //消耗
    toPay(){
     let that=this;
      this.$router.push({
        path: `${Setting.roterPre}/verify/index`,
        query: {
          keyword: that.userInfo.phone,
        },
      });
    },
	submitSuccess(data){
		const updatedArray = this.orderCartInfo.map(item1 => {
		    const match = data.cartInfo.find(item2 => item2.id === item1.id);
		    if (match) {
		        return { ...item1, truePrice: match.true_price };
		    }
		    return item1;
		});
		this.submitData = data;
		this.orderCartInfo = updatedArray;
		this.createOrder.cart_info = data.cartInfo;
		this.priceInfo.payPrice = data.resultPayPrice;
		this.$Message.success('改价成功');
		this.createOrder.is_price = 1;
		this.createOrder.change_price = data.resultPayPrice;
		this.getSwithUser({ change_price: data.resultPayPrice });
	},
    remarks() {
      this.modal = true;
    },
    // 提交备注
    onSubmit() {
      this.modal = false;
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
      this.disabled = false;
      this.$refs.attrs.modals = true;
      this.isCart = 1;
      this.cartInfo.cart_id = item.id;
      this.cartInfo.product_id = item.product_id;
      this.goodsInfo(item.product_id);
    },
    // 加入购物车
    joinCart(num,datas) {
      let that = this;
      if (num) {
        let productSelect = that.productValue[this.attrValue];
        //如果有属性,没有选择,提示用户选择
        if (that.attr.productAttr.length && productSelect === undefined && this.storeInfo.product_type != 5) {
          return this.$Message.warning('产品库存不足，请选择其它');
        }
      }
      if (this.activeHangon == -1) this.activeHangon = 0;
      // let uid = this.userInfo.uid;
      let uid = this.hangData[this.activeHangon].uid || this.userInfo.uid || 0;
	  let data = {
        productId: this.productId,
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
      };
	  let obj = {}
	  if(this.storeInfo.product_type === 6 && this.reservationCart == 1){
		obj = {...data,...datas}
	  }else{
		obj = {...data}
	  }
      cashierCart(uid, obj)
        .then((res) => {
          if (this.storeInfo.product_type === 6 && this.reservationCart==1) {
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
      if (this.cumping) return;
      if (type === 'reduce' && item.cart_num > 1) {
        item.cart_num--;
      } else if (type === 'add' && item.cart_num < item.branch_stock) {
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
     this.selectedProduct=datas.selectedProduct;
	   this.reservationCart = num;
      if (e) {
        this.changeCartAttr();
      } else {
        this.joinCart(1,datas);
      }
      this.addStaff(datas);
    },
    addStaff(datas){
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
        this.$refs.userDetails.activeName = 'info';
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
            this.invalidList = res.data.invalid;
            this.cartSum = res.data.count;
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
    // 选择属性
    attrTap(item) {
      // 一个卡项在购物车只能有一件
      if (item.product_type == 5) {
        for (let i = 0; i < this.cartList.length; i++) {
          for (let j = 0; j < this.cartList[i].cart.length; j++) {
            if (this.cartList[i].cart[j].product_id == item.product_id) {
              return
            }
          }
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
        if (!item.stock) return this.$Message.error('暂无库存');
        if (this.activityFrom.type === '5') {
          this.seckillId = item.id;
          this.isCart = 0; //判断切换属性或是加入购物车：0加入购物车；1切换属性
          this.$refs.skillAttrs.modals = true;
          this.cashierGetAttr(item.id);
        } else if (item.spec_type || item.product_type == 6 || item.product_type == 5 || item.product_type == 4) {
          // 多规格
          this.isCart = 0; //判断切换属性或是加入购物车：0加入购物车；1切换属性
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
      this.selectedProduct=[];
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
        this.$set(this.attr.productSelect, 'selectedProduct',[]);
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
          this.hangDataList();
          this.getCartList();
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
    //点击分类
    cateTap(item, index) {
      this.currentCate = index;
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
      if (index !== 1) {
        this.seckillId = 0;
        this.goodList();
      }
    },
    //分类列表
    cateList() {
      cashierCate({
        relation_id: this.relation_id
      })
        .then((res) => {
          let all = [
            {
              cate_name: '全部商品',
              id: '',
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

        cashierProduct({ ...this.goodFrom })
          .then((res) => {
            let data = res.data;
            this.total = data.count;
            this.goodData = this.goodData.concat(data.list);
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
      let data = {
        integral: this.integral,
        coupon: this.coupon,
        coupon_id: this.couponId,
        cart_id: [id],
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
      this.currentCate = 1;
      this.activityFrom.promotions_id = item.id;
      this.activityFrom.page = 1;
      this.activityFrom.type = item.promotions_type;
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
        }
      };
    },
    // 打开结算抽屉
    openSettle() {
      this.payList.forEach((value, index, arr) => {
        value.status = true;
        if (value.value === 'yue' && (!this.userInfo.uid || this.priceInfo.yue_pay_status == 2)) {
          value.status = false;
        }
        if (value.status && (!index || !arr[index - 1].status)) {
          this.payType = value.value;
          this.createOrder.pay_type = value.value;
        }
      });
      this.yueVerify = !!this.priceInfo.is_cashier_yue_pay_verify;
      this.settleMoney = this.priceInfo.payPrice;
      this.collection = this.priceInfo.payPrice;
      this.payPrice(this.payType);
      this.settleVisible = true;
    },
    onRecharge(e) {
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
      this.rechargeData.staffChoose=e.staffChoose;
      this.zIndex =
        1 +
        Number(
          this.$refs.recharge.$el.querySelector('.ivu-modal-mask').style.zIndex
        );
      this.settleVisible = true;
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
      this.rechargeData.auth_code = auth_code || '';
      userSaveApi(this.rechargeData)
        .then((res) => {
          let status = res.data.status;
          switch (status) {
            case 'SUCCESS':
              this.$Message.success('充值成功');
              this.settleVisible = false;
              this.userInfoData({ uid: this.userInfo.uid });
              break;
            case 'PAY_ING':
              let msg = this.$Message.loading({
                content: '等待支付中...',
                duration: 0,
              });
              this.checkOrderTime(msg);
              break;
            default:
              this.$Message.warning('支付失败');
              break;
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    clears() {
      this.openImage = false;
    },
    getCardRelated(id) {
      cardRelated(id).then((res) => {
        this.attr.productAttr = res.data;
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
        cashierProduct({
          ...this.goodFrom,
          page: 1,
          limit,
        })
          .then((res) => {
            let data = res.data;
            this.total = data.count;
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

<style lang="stylus" scoped>
.toyeji {
  font-size: 15px;
  color: #1890FF;
  cursor: pointer;
  margin-left: 20px;
}
::-webkit-scrollbar-thumb {
  -webkit-box-shadow: inset 0 0 6px #ccc;
}

::-webkit-scrollbar {
  width: 2px !important;
  /* 对垂直流动条有效 */
}
/deep/.paySuccess-modal{
.ivu-modal{
  width: 396px !important;
}
.ivu-modal-content{
  height: 420px !important;
  display: flex;
  justify-content: center;
  align-items: center;
  border-radius: 10px;
}
}
/deep/.payStatus-modal{
.ivu-modal{
  width: 396px !important;
  min-height: 420px;
}
.ivu-modal-body{
  padding-bottom: 60px;
}
.ivu-modal-content{
  border-radius: 10px;
}
}
.keypad {
  display: flex;
  margin-top: 17.5px;
.left {
  flex: 0 0 75%;
  display: flex;
  flex-wrap: wrap;
.ivu-btn {
  width: calc((100% - 15px) / 3)
}
}
.right {
  flex: 0 0 25%;
  display: flex;
  flex-direction: column;
/deep/.ivu-icon{
  font-weight: 600 !important;
  font-size: 31px;
}
}
.ivu-btn {
  height: 62px;
  border: 0;
  border-radius: 8px;
  margin: 2.5px;
  font-weight: 500;
  font-size: 28px !important;
  line-height: 62px;
  color: #303133;
  background-color: #F9F9F9;

&:focus {
   box-shadow: none;
 }
}
.enter {
  height: 131px;
  background-color: #1890FF;
  font-weight: 500;
  font-size: 22px !important;
  line-height: 131px;
  color: #FFFFFF;
  border-radius: 8px;
}
}
.fs-12 {
  font-size: 12px !important;
}
.activeOn{
  background-color: #1890FF !important;
  color: #fff;
  border-radius: 50px;
}
/deep/.ivu-tree ul li{
  width: 212px;
//padding: 10px 10px;
  font-size: 16px;
  color: #303133;
  border-radius: 6px;
}
/deep/.ivu-tree-title{
  padding: 10px 4px;
  width: 159px;
  white-space: pre-wrap;
}
/deep/.ivu-tree-title:hover {
  background-color: unset;
  color: #1890FF;
}
/deep/.ivu-tree-arrow{
  padding: 10px 0;
}
/deep/.ivu-tree-arrow i{
  font-size: 16px;
  color: #303133;
}
/deep/.ivu-tree-title-selected{
  background: #F4F9FF;
  color: #1890FF;
  font-weight: 500;
}
/deep/li:has(> .ivu-tree-title-selected), /deep/li:has(> .ivu-tree-title-selected):hover{
  background: #F4F9FF;
  color: #1890FF;
}
::-webkit-scrollbar-thumb {
  -webkit-box-shadow: inset 0 0 6px #ccc;
}

::-webkit-scrollbar {
  width: 2px !important;
  /* 对垂直流动条有效 */
}

/deep/.change-price-modal {
.ivu-modal-content {
  border-radius: 10px;
}

.ivu-modal-body {
  padding: 30px 25px 50px;
}

.ivu-form-item:last-child {
  margin-bottom: 0;
}

.ivu-form-item-content {
  font-size: 14px !important;
  color: #303133;
}

.input-suffix {
  color: #909399;
}

.ivu-modal-footer {
  padding: 17px 25px;
  border-top: none;
}

.ivu-btn {
  height: 46px;
  border-radius: 23px;
  background: #1890FF;
  font-weight: 500;
  font-size: 16px !important;
}
}

.input-number {
  flex: 1;
  position: relative;
  display: flex;
  align-items: center;
  padding: 0 15px 0 0;
  border: 1px solid #DDDDDD;
  border-radius: 4px;

.ivu-input-number {
  flex: 1;
  height: 36px;
  border: none;

&-focused {
   box-shadow: none;
 }
}

/deep/.ivu-input-number-handler-wrap {
  display: none;
}

/deep/.ivu-input-number-input-wrap {
  height: 36px;
}

/deep/.ivu-input-number-input {
  height: 36px;
  padding: 0 15px;
}

&.discount {
   flex: none;
   width: 167px;
   margin-left: 12px;
 }
}

.changePrice {
  font-weight: 600;
  font-size: 14px;
  color: #F5222D;

.price {
  font-size: 17px;
  margin-left: 5px;
}
}

.tableList {
/deep/ .ivu-table-header table {
  border-top: 0 !important;
}

/deep/ .ivu-table th, /deep/ .ivu-table td {
  border-bottom: 0 !important;
  height: 34px !important;
}

/deep/ .ivu-table-cell {
  padding: 0 !important;
}

/deep/ .ivu-table th {
  color: #999999;
}
}

.left {
/deep/ .ivu-form-item {
  margin-bottom: 12px !important;
}
}

.header .ivu-btn {
  width: 56px;
  height: 28px;
  border-radius: 4px;
  border: 1px solid #FFFFFF;
  background-color: unset !important;
  color: #fff;

&:hover {
   border-color: #ccc;
   color: #ccc;
 }
}

.headerCard {
  background: #1890FF;
  border-radius: 0 !important;
}

.remark {
/deep/ .ivu-input-wrapper {
  width: 91% !important;
}

/deep/ .ivu-input-number {
  width: 91% !important;
}

/deep/ .ivu-form-item-content {
  margin-left: 63px !important;
}

/deep/ .ivu-form-item-label {
  width: 63px !important;
}
}

.noCart {
  height: 100%;
  display: flex;

.tip {
  text-align: center;
  color: #ccc;
  font-size: 14px;
}

.picture {
  width: 200px;
  height: 140px;
  margin: 20px 160px;

img {
  width: 100%;
  height: 100%;
}
}
}

.goodsCard {
  flex: 1;
  max-width: 100%;
  min-width: 1100px;
  height: calc(100vh - 155px);
  display: flex;
  flex-wrap: nowrap;
  padding: 20px;
  background-color: #F5F5F5;
}

.modalPay {
/deep/ .ivu-modal-body {
  padding: 0;
}
}

.cash {
/deep/ .ivu-modal-body {
  padding: 0 !important;
}
}

.discountCon {
.item {
  font-size: 15px;
  margin-bottom: 10px;
}
}

.content {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  flex-direction: column;
}

.cashPage {
  text-align: center;

.right {
  width: 488px;
  background: #F5F5F5;
  padding: 16px 16px 16px 0;
  border-radius: 0 6px 6px 0;

/deep/ .ivu-btn-primary {
  width: 100px;
}

.rightCon {
  width: 388px;
  height: 506px;
  margin: 35px auto 20px auto;
  background-color: #fff;
  border-radius: 14px;

.top {
  height: 80px;
  color: rgba(0, 0, 0, 0.65);
  font-size: 13px;
  padding: 0 20px;

.num {
  font-size: 42px;
  color: rgba(0, 0, 0, 0.85);
}
}

.center {
  width: 100%;
  height: 46px;
  background-color: #1890FF;
  font-size: 13px;
  color: #fff;
  padding: 0 20px;

.num {
  font-size: 27px;
}
}

.bottom {
  padding: 10px 0 0 8px;

.item {
  width: 108px;
  height: 62px;
  background: #FAFAFA;
  border-radius: 9px;
  border: 1px solid rgba(0, 0, 0, 0.15);
  color: #1890FF;
  font-size: 32px;
  margin-left: 12px;
  margin-top: 12px;
  cursor: pointer;

&.on {
   background: #1890FF;
   color: #FFFFFF;
   font-size: 20px;
 }

&.spot {
   padding-bottom: 15px;
 }
}
}
}
}

.left {
  width: 282px;
  padding: 16px 0 16px 16px;

.picture {
  width: 110px;
  height: 110px;
  margin: 180px auto 0 auto;

img {
  width: 100%;
  height: 100%;
}
}

.text {
  color: rgba(0, 0, 0, 0.45);
  font-size: 14px;
  margin-top: 14px;
}

.money {
  color: rgba(0, 0, 0, 0.85);
  font-size: 18px;

.num {
  font-size: 32px;
  margin-left: 5px;
}
}
}
}

.payPage {
  text-align: center;
  padding: 16px;

/deep/ .ivu-input {
  width: 394px !important;
  text-align: center;
}

.header {
  margin: 35px 0 3px 0;
}

.process {
  width: 394px;
  height: 158px;
  border: 1px dashed #D8D8D8;
  border-top: 1px dashed #fff;
  margin: -1px auto 43px;

&.on {
   border-top: 1px dashed #D8D8D8;
   margin-top: 20px;

.list {
  padding-left: 14px !important;
}
}

.list {
  padding: 6px 10px 0 3px;

.item {
  font-size: 12px;
  color: #666;

.name {
  color: #333;
  font-size: 13px;
  font-weight: bold;
}
}
}

.picture {
  width: 362px;
  height: 68px;
  margin: 24px auto 0 auto;

img {
  width: 100%;
  height: 100%;
}
}
}

.picture {
  width: 18px;
  height: 18px;

img {
  width: 100%;
  height: 100%;
}

margin-right: 7px;
}

.text {
  color: rgba(0, 0, 0, 0.45);
  font-size: 14px;
}

.money {
  font-size: 18px;
  color: rgba(0, 0, 0, 0.85);

.num {
  font-size: 32px;
  margin-left: 5px;
}
}

.tip {
  width: 310px;
  height: 26px;
  background: rgba(255, 126, 0, 0.1);
  border-radius: 13px;
  font-size: 13px;
  color: #FF7E00;
  margin: 10px auto 0 auto;

.icon {
  font-size: 16px;
  margin-right: 5px;
}
}

.bnt {
  width: 394px;
  height: 38px;
  margin: 28px 0 15px 0;
}
}

.goods {
  flex: 1;
  min-width: 0;
// width: calc(100% - 500px);
  height: 100%;

/deep/ .ivu-card-body {
  height: 100%;
  padding: 10px 0 0px 0 !important;
}

.smCode {
  padding: 0 16px;

/deep/ .ivu-input-large {
  height: 350px !important;
  text-align: center;
  font-size: 20px !important;
}
}

.goodsCon {
  flex: 1;
  min-width: 0;
  padding-left: 20px;
  height: 100%;
  display: flex;
  flex-direction: column;
  justify-content: space-between;

/deep/ .ivu-input-group {
.ivu-input {
  height: 50px;
  text-align: center;
  border: 0;
  background-color: #FAFAFA;
}
}
.input {
/deep/ .ivu-input-group-prepend, .ivu-input-group-append {
  border: 0;
  border-radius: 50px;
  background-color: #FAFAFA;
}

/deep/.ivu-input-search {
  border-radius: 0 10px 10px 0;
}
}

.goods-top {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
.goodsH:hover{
  background-color: #f5f5f5;
  border-radius: 50px;
}
.input {
  flex: 1;
  margin-left: 13px;
  width: unset;
}
/deep/.ivu-tabs{
  flex: 1;
}
/deep/.ivu-input-group-append{
  border-radius: 50px;
  border:0;
  padding-right: 16px;
  background-color: #FAFAFA;
.iconfont{
  color: #666;
  display: inline-block;
  height: 29px;
  line-height: 29px;
}
}
/deep/.ivu-tabs-bar{
  border-bottom: 0;
  margin-bottom: 0;
}
/deep/.ivu-tabs-ink-bar{
  background-color: #fff;
}
/deep/.ivu-tabs-tab{
  font-size: 16px;
  padding-right: 0;
}
/deep/.ivu-tabs-nav .ivu-tabs-tab-active{
  color: #1890FF;
  font-weight: 500;
}
/deep/.ivu-tabs-nav-prev, /deep/.ivu-tabs-nav-next{
  top:8px;
  background: #F5F5F5;
  border-radius: 50%;
  width: 24px;
  height: 24px;
  text-align: center;
  line-height: 24px;
  color: #303133;
}
/deep/.ivu-tabs-nav-wrap{
  padding: 0 40px;
}
/deep/.ivu-tabs-nav-scroll-disabled{
  display: unset;
  color: #bbb;
}
}

.page {
  margin-top: 0;
  padding: 10px 16px 10px 0;
}

.noGood {
  height: 100%;
  border-radius: 20px;
  background: #FFFFFF;

.picture {
  width: 180px;
  height: 140px;
}

img {
  width: 100%;
  height: 100%;
}

.tip {
  margin-top: 30px;
  font-size: 15px;
  text-align: center;
  color: #ccc;
}
}

.list-wrap {
  flex: 1;
  min-height: 0;
  padding-top: 20px;
  overflow-x: hidden;
  height: calc(100vh - 200px);
}

.list-wrap::-webkit-scrollbar {
  display: none;
}

.ivu-scroll-wrapper {
  flex: 1;
  min-height: 0;
}

/deep/.ivu-scroll-container {
  height: 100%;
}

.list {
.item {
  position: relative;
  width: 245px;
  height: 104px;
  background: #F9F9F9;
  border-radius: 10px;
  margin-bottom: 15px;
  cursor: pointer;

.txtCon{
  width: calc(100% - 104px)
}

&.on:hover {
   background-color: #1890FF;
   color: #fff !important;
.name {
  color: #fff;
}
.stock {
  color: #fff;
}
.money {
  color: #fff !important;
}
}

.icon-cart-num {
  position: absolute;
  top: -8px;
  right: -7px;
  padding: 5px 7px 3px;
  border-radius: 11px;
  background: #FF7700;
  font-size: 14px;
  line-height: 14px;
  color: #FFFFFF;
}

.no-stock {
  top: 0;
  left: 0;
  position: absolute;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.2);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;

.trip {
  background: #4E4E4E;
  width: 70px;
  height: 70px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  flex-direction: column;
  justify-content: center;
  color: #FFFFFF;
  font-size: 14px;
}
}

.picture {
  width: 80px;
  height: 80px;
  position: relative;

img {
  width: 100%;
  height: 100%;
  border-radius: 8px;
  font-size: 15px;
}
}
}

.item-shadow {
  width: 150px;
}
}
}

.goodsList{
  width: calc(100% - 260px);

&.on{
   width: 100%;
 }
}

.goodClass {
  width: 240px;
  border-radius: 20px;
  padding: 22px 8px 0 8px;
  background-color: #FFFFFF;
  margin-top: 20px;
  margin-left: 20px;

>div::-webkit-scrollbar {
  display: none;
}

.cateList{
  overflow-x: hidden;
  overflow-y: auto;
  height: calc(100vh - 200px);
  margin-left: 6px;
}

.item {
  cursor: pointer;
  width: 110px;
  height: 40px;
  text-align: center;
  line-height: 40px;
  margin-bottom: 18px;
  font-size: 16px;
  color: rgba(0, 0, 0, 0.85);
  border-radius: 20px;
  transition: all 0.1s;

&.on {
   background-color: #1890FF;
   color: #fff;
 }
}

.item:hover {
  background-color: #1890FF;
  color: #fff;
}
}
}

.conter {
  height: 100%;
  width: 595px;

/deep/ .ivu-card-body {
  height: 100%;
  padding: 0 !important;
}

.cart {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;

.title {
  padding: 0 16px;
  border: 2px solid #FF7700;
}

.left-top {
  width: 100%;
  height: 100%;
  display: flex;
  border-radius: 20px;
  background-color: #FFFFFF;
  overflow: hidden;

.cart {
  flex: 1;
  min-width: 0;
}

.btn-group-vertical {
  display: flex;
  flex-direction: column;
  padding: 27px 18px;
  border-left: 1px solid #EEEEEE;
  overflow-x: hidden;

.ivu-btn {
  flex-shrink: 0;
  width: 100px;
  height: 46px;
  border-color: #DCDEE0;
  border-radius: 50px;
  margin-bottom: 16px;
  font-size: 16px !important;
  color: #303133;
}

.ivu-btn[disabled] {
  color: #c5c8ce;
  border-color: #dcdee2;
}

.ivu-btn:not([disabled]):active {
  background-color: #F1F1F1;
}

.ivu-btn.selected {
  background-color: #F1F1F1;
}
}
}

.cart-left {
  display: flex;
  flex-direction: column;
  width: 100%;
  height: 100%;

.count {
  padding: 0px 24px 19px;
  border-bottom: 1px solid #EEEEEE;
  display: flex;
  align-items: center;
  justify-content: space-between;

.num {
  color: #FF7700;
  padding: 0 5px;
}

.cart-sel {
  font-size: 14px;
}

.count-r {
  display: flex;
  align-items: center;

.coupon {
  border-radius: 4px;
  border: 1px solid #FF7700;
  color: #FF7700;
  padding: 3px 10px;
  cursor: pointer;
  font-size: 14px;
}

.clear {
  display: flex;
  align-items: center;
  cursor: pointer;
  font-size: 14px;

img {
  width: 14px;
  height: 15px;
  margin: 0 6px 0 14px;
}
}
}
}
}

.tourist::-webkit-scrollbar {
  height: 4px !important;
}

.tourist {
  width: 100%;
  padding-left: 13px;
  padding-top: 15px;
  display: flex;
  overflow-x: auto;
  overflow-y: hidden;
  white-space: nowrap;
  /* 解决ios手机页面滑动卡顿问题 */
  -webkit-overflow-scrolling: touch;

.item-w1 {
  min-width: 100px;
}

.item-w2 {
  min-width: 140px;
}

.item {
  height: 38px;
  background: #F7F7F7;
  border-radius: 50px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.85);
  position: relative;
  padding-left: 7px;
  margin-bottom: 9px;
  margin-right: 12px;
  cursor: pointer;

.picture {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  margin-right: 6px;

img {
  width: 100%;
  height: 100%;
  border-radius: 50%;
}
}

.name {
  width: 50px;
}

.guadan {
  font-size: 10px;
  border: 1px solid #FF7700;
  color: #FF7700;
  padding: 0 3px;
  border-radius: 2px;
  margin-right: 12px;
}

&:hover {
   background: #FF7700;
   color: #fff;

.guadan {
  border: 1px solid #fff;
  color: #fff;
}
}

&.on {
   background: #FF7700;
   color: #fff;

.guadan {
  border: 1px solid #fff;
  color: #fff;
}
}
}
}

.right {
  width: 90px;

.navTabs {
  position: absolute;
  top: 15px;
  cursor: pointer;

img {
  display: block;
  width: 40px;
  height: 85px;
}

.label01 {
  z-index: 5;
  position: relative;
}

.label02 {
  margin-top: -16px;
}
}

.item {
  width: 72px;
  background: #F2F3F5;
  margin: 0 auto 13px auto;
  text-align: center;
  padding: 9px 0;
  cursor: pointer;
  position: relative;

.iconfont {
  position: absolute;
  font-size: 20px;
  top: -9px;
  right: -7px;
  color: #bbb;
}

&:hover {
   background-color: #1890FF;
   color: #fff;
 }

&.on {
   background-color: #1890FF;
   color: #fff;
 }
}
}

.title {
  flex-shrink: 0;
  height: 80px;
  background: rgba(255, 119, 0, 0.05);
  border-radius: 10px;
  margin: 27px 24px 24px;
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  overflow: hidden;

.picture {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  margin-right: 12px;
  position:relative;

img {
  width: 100%;
  height: 100%;
  border-radius: 50%;
}
.svip{
  width: 39px;
  height: 17px;
  display: block;
  position: absolute;
  border-radius: 0;
  bottom: -1px;
  left: 50%;
  margin-left: -19px;

}
}

.switchs {
  color: #FF7700;
  cursor: pointer;

a {
  font-size: 14px;
  color: #FF7700;
}
}

.text {
  font-size: 13px;
  font-weight: 400;
  color: rgba(51, 51, 51, 0.85);
  flex: 1;
  min-width: 0;

.textCon {
  display: flex;
  align-items: flex-start;

.name {
  font-size: 16px;
}

.phone {
  color: #999;
  font-size: 14px;
}
}

.text-wrap {
  flex: 1;
  min-width: 0;
}

.name-wrap {
  display: inline-flex;
  align-items: flex-start;
  max-width: 100%;
}

.user-msg {
  margin-top: 6px;
}

.balance {
  margin-right: 12px;
  font-size: 14px;

.num {
  font-weight: 600;
  color: #303133;
  font-size: 16px;
  line-height: 16px;
  margin-left: 4px;
}
}

.recharge {
  color: #1890FF;
  padding: 2px 4px;
  cursor: pointer;
  border-radius: 3px;
}

.recharge:hover {
  background-color: #1890FF;
  color: #fff;
}

.name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  color: rgba(0, 0, 0, 0.85);
  font-size: 14px;
  font-weight: 600;
  margin-right: 4px;
}
}
}

.listCon {
  overflow-x: hidden;
  flex: 1;

.promotions {
// border-bottom: 1px dashed #EEEEEE;

.promotions-msg {
  display: flex;
  justify-content: space-between;
  padding: 10px;
  color: #333333;
  font-size: 14px;
  border-bottom: 1px solid #f2f2f2;

.card {
  color: #FF7700;
  padding: 1px 6px;
  margin-right: 8px;
  border-radius: 3px;
  background-color: #Fcf0e2;
  font-size: 12px;
  white-space: nowrap;
}

.flex-1 {
  flex: 1;
  display: flex;
  align-items: center;
}

.collect {
  cursor: pointer;
  width: 70px;
  display: flex;
  align-items: center;
  flex-basis: max-content;

.iconjinru {
  font-size: 12px;
}
}
}

.is_give {
  height: 60px;

.picture {
  width: 40px;
  height: 40px;

img {
  width: 100%;
  height: 100%;
  border-radius: 5px;
}
}

.give-name {
  font-size: 12px;
  color: #333;
  max-width: 200px;
}

.give-info {
  font-size: 12px;
  color: #ccc;
}
}
}
}

.list::-webkit-scrollbar {
  width: 0 !important;
}

.list {
  -ms-overflow-style: none;
  overflow: -moz-scrollbars-none;
  overflow: hidden;
  overflow-y: scroll;

.item {
  padding: 15px 25px;
  position: relative;
  display: flex;
  flex-wrap: nowrap;
  height: 100%;

&~.item{
   position: relative;
&:before {
   content: "";
   position: absolute;
   top: 0;
   right: 24px;
   left: 24px;
   border-top: 1px dashed #EEEEEE;
 }
}

&:hover {
   background: rgba(24, 144, 255, 0.05);
 }

/deep/ .ivu-input-number-input {
  text-align: center;
}

/deep/ .ivu-input-number-controls-outside {
  width: 112px !important;
}

.picture {
  width: 70px;
  height: 70px;

img {
  width: 100%;
  height: 100%;
  border-radius: 5px;
}
}

.del {
  position: absolute;
  font-size: 15px;
  color: #1890FF;
  right: 25px;
  top: 24px;
  cursor: pointer;
  padding: 2px 7px;
}

.cartBnt {
  position: absolute;
  right: 25px;
  height: 28px;
  bottom: 19px;

.iconfont {
  width: 24px;
  height: 24px;
  background-color: #F2F3F5;
  text-align: center;
  line-height: 24px;
  color: rgba(0, 0, 0, 0.85);
  border-radius: 50%;
}

.iconjia {
  color: #fff;
  background-color: #1890FF;
  font-size: 12px;
}

.ivu-input-number {
  outline: unset;
  width: 60px;
  margin: 0 2px;
  text-align: center;
  font-size: 16px;
  font-family: PingFangSC-Semibold, PingFang SC;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.85);
  border: none;
  background-color: rgba(255, 255, 255, 0);

/deep/ .ivu-input-number-handler-wrap {
  display: none;
}
}
}

.text {
  flex: 1;
  color: #000;
  font-size: 18px;
  margin-left: 10px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  height: 100%;
  overflow: hidden;

.end {
  color: #999;
  font-size: 13px;
}

.name {
  font-size: 16px;
  margin-top: 5px;
  width: 82%;
}

.info {
  color: #999;
  font-size: 13px;
  cursor: pointer;
  padding: 4px 0 7px 0;
  display: flex;
  align-items: center;

.iconfont {
  font-size: 12px;
  margin-left: 5px;
}

.suk {
  max-width: 50%;
}
}

.sum_price {
  font-size: 16px;
  font-weight: 500;
  color: rgba(0, 0, 0, 0.85);
}

&.invalid {
.info {
  cursor: unset;
  display: flex;
  align-items: center;
}

.suk {
  max-width: 50%;
}

.name {
  color: #999;
}
}
}
}
}

.left {
  width: 100%;
  display: flex;
  align-items: center;
  background-color: #fff;
  padding: 5px 24px 0 24px;
}

.conInfo {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
  color: #000;

.storeBnt-wrap {
  flex: 1;
}

.storeBnt {
  padding-right: 10px;
  height: 40px;
  border-radius: 6px;
  color: #333333;
  font-size: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;

.text {
  max-width: 100px;
}
}

.right {
  flex: 1;
  display: flex;
  width: max-content;
  align-items: baseline;
  font-size: 14px;
  flex-wrap: nowrap;
  white-space: nowrap;

div {
  white-space: nowrap;
}

.rmb {
  font-weight: 600;
  font-size: 16px;
  color: rgba(245, 34, 45, 1);
}

.discount {
  font-size: 14px;
  padding: 0 9px;
}

.detailed {
  color: #909399;
  padding-left: 3px;
  cursor: pointer;
.iconfont{
  font-size: 14px;
  margin-left: 3px;
}
}

.num {
  color: rgba(245, 34, 45, 1);
  font-size: 24px;
  line-height: 22px;
  font-weight: 600;
  white-space: nowrap;
}
}

.num {
  font-size: 24px;
}
}

.footer {

.footer-bottom {
  display: flex;
  align-items: center;
  padding: 12px 24px 16px 24px;

.ivu-btn {
  flex: 1;
  height: 56px;
  border-color: #1890FF;
  border-radius: 53px;
  background-color: #1890FF;
  font-weight: 500;
  font-size: 20px !important;
  color: #FFFFFF;
}
}
}
}

.title {
  color: rgba(0, 0, 0, 0.85);

.text {
  font-size: 16px;
  font-weight: 500;
}

.picture {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  cursor: pointer;

img {
  width: 100%;
  height: 100%;
  border-radius: 50%;
}
}

.info {
  font-size: 14px;
  margin-left: 8px;
  cursor: pointer;

.iconfont {
  font-size: 12px;
  margin-left: 5px;
}

&:hover {
   color: #2d8cf0;
 }
}
}
}

.header {
  color: #fff;

.title {
  font-size: 18px;
  font-weight: 500;
}

.right {
.picture {
  width: 32px;
  height: 32px;
  border-radius: 50%;

img {
  width: 100%;
  height: 100%;
  border-radius: 50%;
}
}

.storeBnt {
  width: 80px;
  height: 32px;
  background: #FFFFFF;
  border-radius: 1px;
  color: #1890FF;
  font-size: 14px;
  text-align: center;
  line-height: 32px;
  margin-left: 10px;
  cursor: pointer;

&:hover {
   background-color: rgba(255, 255, 255, 0.9);
 }
}

.info {
  font-size: 14px;
  font-weight: 400;
  color: #fff;

span {
  padding: 0 8px;

& ~ span {
    border-left: 1px solid #DDDDDD;
  }
}
}

.bnt {
  margin-left: 20px;
}
}
}

footer {
  display: flex;
  background-color: #fff;

.footer {
  width: 500px;
  padding: 13px 17px 13px 17px;

.pay {
.bnt {
  border-radius: 6px;
  width: 30%;
  height: 0.32rem;
  border: 1px solid #1890FF;
  color: #1890FF;
  font-size: 0.11rem;
  text-align: center;
  font-weight: 500;
  cursor: pointer;

&.on {
   background: #1890FF;
   color: #fff;
 }

&.bntUid {
   background: #1890FF;
   color: #fff;
   cursor: unset;

&.on {
   background: #ccc;
   border: 1px solid #ccc;
   color: #fff;
 }
}
}

&.noCart {
.bnt {
  border: 1px solid #ccc !important;
  color: #ccc;
  cursor: unset;

&.on {
   border: 1px solid #1890FF;
   background: #ccc;
   color: #fff;
 }
}
}
}
}

.right {
  padding: 10px 17px 15px 17px;
  border-radius: 0 6px 6px 0;
  display: flex;
  flex: 1;
  box-shadow: 5px 0px 14px 0px rgba(0, 0, 0, 0.06);
  background-color: #fff;

/deep/ .ivu-btn-primary {
  width: 100px;
}

.rightCon {
  display: flex;
  align-items: center;

.top {
  height: 80px;
  color: rgba(0, 0, 0, 0.65);
  font-size: 13px;
  padding: 0 20px;

.num {
  font-size: 42px;
  color: rgba(0, 0, 0, 0.85);
}
}

.center {
  width: 100%;
  height: 46px;
  background-color: #1890FF;
  font-size: 13px;
  color: #fff;
  padding: 0 20px;

.num {
  font-size: 27px;
}
}

.item {
  width: 80px;
  height: 46px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #F2F3F5;
  color: #fff;
  cursor: unset;
  border-radius: 4px;
  margin-right: 14px;
  cursor: pointer;
  color: #000000;
  font-size: 17px;

&.on {
   background: #E7F3FF;
   color: #1890FF;
   font-size: 17px;
   font-weight: 400;
 }

&.spot {
   padding-bottom: 15px;
 }
}

.bottom {
  padding: 10px 0 0 8px;
}
}

.noCart {
  display: flex;
  align-items: center;

.item {
  background: #ccc;
  color: #fff;
  cursor: unset;
  width: 80px;
  height: 46px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 14px;
  border-radius: 4px;
  font-size: 16px;

&:nth-child(3) {
&:hover {
   background-color: #ccc;
 }
}

&:nth-child(4) {
&:hover {
   background-color: #ccc;
 }
}

&:nth-child(5) {
&:hover {
   background-color: #ccc;
 }
}

&.on {
   background-color: #ccc;
 }
}
}
}
}

/deep/ .ivu-page {
  font-size: 15px;
}

.payModal {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 67px 52px;

.type {
  display: flex;
  flex-direction: column;
  align-items: center;
  border: 1px solid #1890FF;
  padding: 33px 37px;
  border-radius: 6px;
  margin: 0 15px;
  cursor: pointer;

.img {
  width: 66px;
  height: 55px;
  margin-bottom: 33px;

img {
  width: 100%;
}
}

.text {
  white-space: nowrap;
}
}

.type:hover {
  background-color: #f2f2f2;
}
}

.goast {
  background: rgba(24, 144, 255, 0.1) !important;
  color: #1890FF !important;
}

.v-center {
  margin-top: 100px;
}

.search_user_info {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;

.picture {
  width: 110px;
  height: 110px;
  margin: 20px 0 20px;

img {
  width: 100%;
  height: 100%;
  border-radius: 50%;
}
}

.user_name {
  font-size: 18px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.85);
  margin-bottom: 14px;
}

.user_id {
  font-size: 12px;
  font-weight: 400;
  color: #999999;
}

.user_phone {
  font-size: 14px;
  font-weight: 400;
  color: rgba(0, 0, 0, 0.85);
  margin: 14px 0 40px;
}

.sure_btn {
  width: 176px;
  height: 46px;
  line-height: 46px;
  text-align: center;
  color: #fff;
  font-size: 16px;
  background: #1890FF;
  border-radius: 6px;
  margin-bottom: 30px;
}
}

/deep/.remarks-modal {
.ivu-modal-content {
  border-radius: 10px;
}

.ivu-modal-body {
  padding: 20px 25px;
}

.ivu-input {
  padding: 14px;
  border: 1px solid #DDDDDD;
  border-radius: 6px;

&:focus {
   border-color: #1890FF;
   box-shadow: none;
 }
}

.ivu-input-word-count {
  right: 14px;
  bottom: 14px;
}

.ivu-modal-footer {
  padding: 17px 25px;
  border-top: none;
}

.ivu-btn {
  height: 46px;
  border-radius: 23px;
  background: #1890FF;
  font-weight: 500;
  font-size: 16px !important;
}
}

/deep/.user-modal {
.ivu-modal-content {
  border-radius: 10px;
}
}

.swiper-container {
  width: 100%;
  margin-top: 20px;
}

.swiper-slide {
  width: auto;
  height: 36px;
  padding: 0 16px;
  border: 1px solid #CCCCCC;
  border-radius: 18px;
  background: #f9f9f9;
  font-size: 14px;
  line-height: 36px;
  color: #303133;
  cursor: pointer;

&.active {
   background: #F7FBFF;
   border: 1px solid #1890FF;
   color: #1890FF;
 }
}

.ivu-alert {
  padding: 20px 18px;
  border: 1px solid #1890FF;
  border-radius: 10px;
  margin-top: 20px;
  background: #F7FBFF;
  font-size: 14px;
  line-height: 22px;
  color: #1890FF;
}

.open-image {
  display: flex;
  align-items: center;
  justify-content: center;
  position: fixed;
  width: 130px;
  height: 580px;
  top: 50%;
  right: 40px;
  transform: translateY(-50%);
  z-index: 1000;
  cursor: pointer;

img {
  width: 130px;
}

.iconfont {
  position: absolute;
  top: -20px;
  right: -20px;
  font-size: 20px;
  color: #ddd;
}
}

/deep/.ivu-input:focus {
  box-shadow: none;
}
</style>
