<template>
    <div>
		<div class="i-layout-page-header">
		  <PageHeader class="product_tabs" hidden-breadcrumb>
		    <div slot="title" class="acea-row row-middle">
		      <router-link :to="{ path: listRoutePath }">
		        <div class="font-sm after-line">
		          <span class="iconfont iconfanhui"></span>
		          <span class="pl10">返回</span>
		        </div>
		      </router-link>
		      <span v-text="$route.params.id ? '编辑门店' : '添加门店'" class="mr20 ml16"></span>
		    </div>
		  </PageHeader>
		</div>
		<div class="article-manager ivu-mt">
		    <Card :bordered="false" dis-hover :padding="16">
				<div class="new_tab">
					<Tabs v-model="currentTab">
						<TabPane :label="'基础设置'" name="1" />
						<TabPane :label="'运营设置'" name="2" />
						<TabPane :label="'配送设置'" name="3" />
						<TabPane :label="'商品设置'" name="4" v-if="!formItem.id" />
						<TabPane :label="'手续费设置'" name="5" />
					</Tabs>
				</div>
		        <Form ref="formItem" class="mt20" :model="formItem" :label-width="labelWidth" :label-position="labelPosition" :rules="ruleValidate" @submit.native.prevent>
		            <Row type="flex" :gutter="24" v-show="currentTab == 1">
						<Col span="24" v-if="openErp">
							<FormItem label="erp门店：" prop="erp_shop_id">
								<Button @click="tapErp">{{formItem.erp_shop_id?formItem.erp_shop_id:"请选择erp门店"}}</Button>
							</FormItem>
						</Col>
						<Col span="24">
						    <FormItem label="门店照片：" prop="image">
								<div class="picBox" @click="modalPicTap('单选', 'image')">
									<div class="pictrue" v-if="formItem.image"><img v-lazy="formItem.image"></div>
									<div class="upLoad" v-else>
										<div class="iconfont">+</div>
									</div>
								</div>
								<div class="tips"> 建议尺寸：70 * 70px</div>
						    </FormItem>
						</Col>
		                <Col span="24">
							<FormItem label="门头照片：" prop="background_image">
								<div class="picBox" @click="modalPicTap('单选', 'background_image')">
									<div class="pictrue" v-if="formItem.background_image"><img v-lazy="formItem.background_image"></div>
									<div class="upLoad" v-else>
										<div class="iconfont">+</div>
									</div>
								</div>
								<div class="tips"> 建议尺寸：375 * 192px</div>
							</FormItem>
						</Col>
						<Col span="24">
						    <FormItem label="门店名称：" prop="name" label-for="name">
						        <Input v-model="formItem.name"  maxlength="20" show-word-limit  placeholder="请输入门店名称" class="inputW"/>
						    </FormItem>
						</Col>
                  <Col span="24">
                    <FormItem label="床位数：" prop="space_num" label-for="space_num">
                      <Input v-model="formItem.space_num"  maxlength="20" show-word-limit  placeholder="请输入床位数" class="inputW"/>
                    </FormItem>
                  </Col>
						<Col span="24">
							<FormItem label="门店简介：" label-for="introduction">
								<Input v-model="formItem.introduction"  maxlength="100" show-word-limit :rows="4" :autosize="{maxRows:4,minRows: 4}" type="textarea" placeholder="请输入门店简介" class="inputW"/>
							</FormItem>
						</Col>
						<Col span="24">
							<FormItem label="门店区域：" prop="manage_region_path" label-for="manage_region_path">
							  <Cascader
							      :data="manageRegionTree"
							      placeholder="请选择门店区域"
							      change-on-select
							      v-model="formItem.manage_region_path"
							      filterable
								  class="inputW"
							  ></Cascader>
							</FormItem>
						</Col>
						<Col span="24">
							<FormItem label="营业状态：" label-for="is_show" prop="is_show">
								<Switch size="large" v-model="formItem.is_show" :false-value="0" :true-value="1">
									<span slot="open" :true-value="1">开启</span>
									<span slot="close" :false-value="0">关闭</span>
								</Switch>
							</FormItem>
						</Col>
						<Col span="24">
							<FormItem label="营业时间：" label-for="day_time"  prop="day_time">
								<TimePicker type="timerange" @on-change="onchangeTime" v-model="formItem.day_time"  format="HH:mm:ss" :value="formItem.day_time" placement="bottom-end" placeholder="请选择营业时间" class="inputW" ></TimePicker>
							</FormItem>
						</Col>
						<Col span="24" v-if="formItem.id == 0">
						    <FormItem label="管理员账号：" prop="store_account" label-for="store_account">
						        <Input v-model="formItem.store_account"  placeholder="请输入管理员账号" class="inputW"/>
						    </FormItem>
						</Col>
						<Col span="24"  v-if="formItem.id == 0">
						    <FormItem label="管理员密码：" prop="store_password" label-for="store_password">
						        <Input type="password" password v-model="formItem.store_password"  placeholder="请输入管理员密码" class="inputW"/>
						    </FormItem>
						</Col>
		                <Col span="24">
		                    <FormItem label="门店手机号：" label-for="phone" prop="phone">
		                        <Input v-model="formItem.phone"  placeholder="请输入门店手机号" class="inputW"/>
		                    </FormItem>
		                </Col>
						<Col span="24">
							<FormItem label="门店地址：" label-for="address" prop="address">
								<Cascader :data="addresData" :load-data="loadData" v-model="formItem.addressSelect" @on-change="addchack" class="inputW"></Cascader>
							</FormItem>
						</Col>
						<Col span="24">
							<FormItem label="门店详细地址：" label-for="detailed_address" prop="detailed_address">
								<div class="acea-row row-middle">
									<Input v-if="storeAddress" disabled v-model="storeAddress" class="w-240"/>
									<Input search enter-button="查找位置" v-model="formItem.detailed_address"  placeholder="输入详细地址" class="w-300 ml-6" @on-search="onSearch" />
								</div>
								<div class="tip">提示：为减少误差，建议门店地址与定位地区保持一致</div>
							</FormItem>
						</Col>
						<Col span="24" v-if="isApi">
							<Maps v-if="mapKey" ref="mapChild" class="map-sty" :mapKey="mapKey" :lat="Number(formItem.latitude || 34.34127)" :lon="Number(formItem.longitude || 108.93984)" :address="storeAddress+formItem.detailed_address" @getCoordinates="getCoordinates" />
						</Col>
		            </Row>
					<Row type="flex" :gutter="24" v-show="currentTab == 2">
						<Col span="24">
						  <FormItem label="门店类型：" label-for="type" prop="type">
						    <RadioGroup v-model="formItem.type">
						      <Radio :label="1">
						        <Icon type="social-apple"></Icon>
						        <span>自营</span>
						      </Radio>
						      <Radio :label="2">
						        <Icon type="social-android"></Icon>
						        <span>加盟</span>
						      </Radio>
						    </RadioGroup>
						    <div class="tips">自营店不支持自主上传商品，加盟店有自主上传商品的权限</div>
						  </FormItem>
						</Col>
						<Col span="24" v-if="formItem.type==2">
							<FormItem label="商品免审：" label-for="product_verify_status" prop="product_verify_status">
								<Switch size="large" v-model="formItem.product_verify_status" :false-value="0" :true-value="1">
									<span slot="open" :true-value="1">开启</span>
									<span slot="close" :false-value="0">关闭</span>
								</Switch>
							</FormItem>
						</Col>
						<Col span="24" v-if="formItem.type==2">
						  <FormItem label="自主添加商品：" label-for="product_status" prop="product_status">
						    <Switch size="large" v-model="formItem.product_status" :false-value="0" :true-value="1">
						      <span slot="open" :true-value="1">开启</span>
						      <span slot="close" :false-value="0">关闭</span>
						    </Switch>
						  </FormItem>
						</Col>
						<Col span="24" v-if="formItem.type==2">
						  <FormItem label="使用平台余额：" label-for="use_system_money" prop="use_system_money">
						    <Switch size="large" v-model="formItem.use_system_money" :false-value="0" :true-value="1">
						      <span slot="open" :true-value="1">开启</span>
						      <span slot="close" :false-value="0">关闭</span>
						    </Switch>
						  </FormItem>
						</Col>
						<Col span="24">
						  <FormItem label="门店调价：" label-for="product_change_price_status" prop="product_change_price_status">
						    <Switch size="large" v-model="formItem.product_change_price_status" :false-value="0" :true-value="1">
						      <span slot="open" :true-value="1">开启</span>
						      <span slot="close" :false-value="0">关闭</span>
						    </Switch>
							<div class="tips">开启门店调价功能，支持在指定价格区间内，调整商品售价</div>
						  </FormItem>
						</Col>
            <Col span="24">
						  <FormItem label="自建优惠券：" label-for="coupon_self_built_status" prop="coupon_self_built_status">
						    <Switch size="large" v-model="formItem.coupon_self_built_status" :false-value="0" :true-value="1" @on-change="onCouponSelfBuiltStatusChange">
						      <span slot="open" :true-value="1">开启</span>
						      <span slot="close" :false-value="0">关闭</span>
						    </Switch>
                <div class="tips">开启自建优惠券功能，门店可新增门店专属优惠券</div>
						  </FormItem>
						</Col>
            <Col span="24" v-show="formItem.coupon_self_built_status">
						  <FormItem label="优惠券免审：" label-for="coupon_verify_status" prop="coupon_verify_status">
						    <Switch size="large" v-model="formItem.coupon_verify_status" :false-value="0" :true-value="1">
						      <span slot="open" :true-value="1">开启</span>
						      <span slot="close" :false-value="0">关闭</span>
						    </Switch>
                <div class="tips">开启后，门店自建优惠券无需平台审核</div>
						  </FormItem>
						</Col>
						<Col span="24">
						  <FormItem label="门店隔离：" label-for="is_alone" prop="is_alone">
						    <Switch size="large" v-model="formItem.is_alone" :false-value="0" :true-value="1">
						      <span slot="open" :true-value="1">开启</span>
						      <span slot="close" :false-value="0">关闭</span>
						    </Switch>
							<div class="tips">开启后，该门店在移动端门店列表中不再显示，仅可通过扫描门店推广码进入，用户进入隔离门店后不可切换至其他门店。</div>
						  </FormItem>
						</Col>
						<Col span="24">
						  <FormItem label="自建商品分类：" label-for="product_category_status" prop="product_category_status">
						    <Switch size="large" v-model="formItem.product_category_status" :false-value="0" :true-value="1">
						      <span slot="open" :true-value="1">开启</span>
						      <span slot="close" :false-value="0">关闭</span>
						    </Switch>
                <div class="tips">开启后，门店可自建商品分类并为门店商品设置门店分类</div>
						  </FormItem>
						</Col>
					</Row>
					<Row type="flex" :gutter="24" v-show="currentTab == 3">
						<Col span="24">
							<FormItem label="配送方式：" label-for="delivery_type" prop="delivery_type">
								<!-- <RadioGroup v-model="formItem.delivery_type">
									<Radio :label="1">门店配送+到店核销</Radio>
									<Radio :label="2">门店配送</Radio>
									<Radio :label="3">到店核销</Radio>
								</RadioGroup> -->
                <CheckboxGroup v-model="formItem.delivery_type" @on-change="onDeliveryTypeChange">
                  <Checkbox label="1">快递发货</Checkbox>
                  <Checkbox label="3" v-if="cityDeliveryStatus">同城配送</Checkbox>
                  <Checkbox label="2">到店自提</Checkbox>
                </CheckboxGroup>
								<div class="tips">设置此门店支持的配送方式</div>
							</FormItem>
						</Col>
						<!-- <Col span="24" v-if="formItem.delivery_type !=3">
							<FormItem label="配送范围(半径)：" label-for="valid_range" prop="valid_range">
								<InputNumber :max="100000" v-model="formItem.valid_range" :formatter="value => `${formItem.valid_range}`" :parser="value => value.replace('%', '')" style="width: 90px;"></InputNumber><span class="ml10">km</span>
							</FormItem>
						</Col> -->
						<!-- <Col span="24" v-if="formItem.delivery_type !=3">
							<FormItem label="同城配送：" label-for="city_delivery_status" prop="city_delivery_status">
								<Switch size="large" v-model="formItem.city_delivery_status" :false-value="0" :true-value="1">
									<span slot="open" :true-value="1">开启</span>
									<span slot="close" :false-value="0">关闭</span>
								</Switch>
							</FormItem>
						</Col> -->
						<!-- <Col span="24" v-if="formItem.city_delivery_status && formItem.delivery_type !=3">
							<FormItem label="第三方配送：" label-for="city_delivery_type" prop="city_delivery_type">
								<RadioGroup v-model="formItem.city_delivery_type">
									<Radio :label="1">达达快送</Radio>
									<Radio :label="2">UU跑腿</Radio>
									<Radio :label="0">均不使用</Radio>
								</RadioGroup>
							</FormItem>
						</Col> -->
            <Col span="24" v-if="cityDeliveryStatus" v-show="formItem.delivery_type.includes('3')">
							<FormItem label="同城配送：" label-for="city_delivery_type" prop="city_delivery_type">
								<RadioGroup v-model="formItem.city_delivery_type">
                  <Radio :label="0">商家自配</Radio>
                  <Radio :label="2">UU跑腿</Radio>
									<Radio :label="1">达达快送</Radio>
								</RadioGroup>
                <div class="tips">设置同城配送的配送渠道，用户下单后，自动转配送单至对应渠道</div>
							</FormItem>
						</Col>
						<Col span="24">
							<FormItem label="默认配送方式：">
								<RadioGroup v-model="formItem.default_delivery">
								  <Radio :label="1">配送</Radio>
								  <Radio :label="2">到店</Radio>
								</RadioGroup>
								<div class="tips">
                  用户进入门店主页时，默认选择的配送方式；下方商品按照此处配送方式展示
                  <Poptip placement="bottom" trigger="hover" width="256" transfer padding="8px">
                    <a>示例</a>
                    <div class="exampleImg" slot="content">
                      <img
                        :src="`${baseURL}/statics/system/default_delivery_poptip.png`"
                        alt=""
                      />
                    </div>
                  </Poptip>
                </div>
							</FormItem>
						</Col>
					</Row>
					<Row type="flex" :gutter="24" v-show="currentTab == 4">
						<Col span="24" v-if="!formItem.id">
						  <FormItem label="同步商品：">
						    <RadioGroup v-model="formItem.applicable_type">
						      <Radio :label="1">
						        <Icon type="social-apple"></Icon>
						        <span>全部商品</span>
						      </Radio>
						      <Radio :label="2">
						        <Icon type="social-android"></Icon>
						        <span>指定商品</span>
						      </Radio>
							  <Radio :label="3">
							    <Icon type="social-android"></Icon>
							    <span>暂不同步</span>
							  </Radio>
						    </RadioGroup>
						  </FormItem>
						</Col>
						<Col span="24" v-if="!formItem.id && formItem.applicable_type == 2" >
						  <FormItem label="选择商品：" label-for="product_id" prop="">
							<div class="box">
							  <div class="box-item" v-for="(item,index) in goodsList" :key="index">
								<img :src="item.image" alt="">
								<Icon class="icon" type="ios-close-circle" size="20" @click="bindDelete(index)" />
							  </div>
							  <div class="upload-box" @click="modals = true"><Icon type="ios-camera-outline" size="36" /></div>
							</div>
						  </FormItem>
						</Col>
					</Row>
					<Row type="flex" :gutter="24" v-show="currentTab == 5">
						<Col span="24">
							<FormItem label="手续费率：" label-for="store_rate_type" prop="store_rate_type">
								<RadioGroup v-model="formItem.store_rate_type">
								  <Radio :label="1">统一配置</Radio>
								  <Radio :label="2">自定义费率</Radio>
								</RadioGroup>
							</FormItem>
						</Col>
						<Col span="24">
							<FormItem label="收银订单费率(%)：" label-for="store_cashier_order_rate" prop="store_cashier_order_rate">
								<InputNumber v-if="formItem.store_rate_type==1" :max="100000" :min='0' disabled v-model="cashierOrderRate" class="inputW"></InputNumber>
								<InputNumber v-else :max="100000" :min='0' v-model="formItem.store_cashier_order_rate" class="inputW"></InputNumber>
								<div class="tips">门店收银台订单费率(%)</div>
							</FormItem>
						</Col>
						<Col span="24">
							<FormItem label="分配订单费率(%)：" label-for="store_self_order_rate" prop="store_self_order_rate">
								<InputNumber v-if="formItem.store_rate_type==1" :max="100000" :min='0' disabled v-model="selfOrderRate" class="inputW"></InputNumber>
								<InputNumber v-else :max="100000" :min='0' v-model="formItem.store_self_order_rate" class="inputW"></InputNumber>
								<div class="tips">商城用户选择门店配送的订单费率(%)</div>
							</FormItem>
						</Col>
						<Col span="24">
							<FormItem label="核销订单费率(%)：" label-for="store_writeoff_order_rate" prop="store_writeoff_order_rate">
								<InputNumber v-if="formItem.store_rate_type==1" :max="100000" :min='0' disabled v-model="writeoffOrderRate" class="inputW"></InputNumber>
								<InputNumber v-else :max="100000" :min='0' v-model="formItem.store_writeoff_order_rate" class="inputW"></InputNumber>
								<div class="tips">商城用户选择到店核销的订单费率(%)</div>
							</FormItem>
						</Col>
						<Col span="24">
							<FormItem label="储值订单返点(%)：" label-for="store_recharge_order_rate" prop="store_recharge_order_rate">
								<InputNumber v-if="formItem.store_rate_type==1" :max="100000" :min='0' disabled v-model="rechargeOrderRate" class="inputW"></InputNumber>
								<InputNumber v-else :max="100000" :min='0' v-model="formItem.store_recharge_order_rate" class="inputW"></InputNumber>
								<div class="tips">门店给用户储值余额订单给门店返点(%)</div>
							</FormItem>
						</Col>
						<Col span="24">
							<FormItem label="付费会员返点(%)：" label-for="store_svip_order_rate" prop="store_svip_order_rate">
								<InputNumber v-if="formItem.store_rate_type==1" :max="100000" :min='0' disabled v-model="svipOrderRate" class="inputW"></InputNumber>
								<InputNumber v-else :max="100000" :min='0' v-model="formItem.store_svip_order_rate" class="inputW"></InputNumber>
								<div class="tips">门店给用户购买付费会员订单给门店返点(%)</div>
							</FormItem>
						</Col>
					</Row>
		            <Spin size="large" fix v-if="spinShow"></Spin>
		        </Form>
		    </Card>
			<Card :bordered="false" dis-hover class="fixed-card" :style="{left: `${!menuCollapse?'236px':isMobile?'0':'60px'}`}">
			  <Form>
			    <FormItem>
			      <Button v-if="currentTab !== '1'" @click="upTab">上一步</Button>
			      <Button
			          type="primary"
			          class="ml10"
			          v-if="($route.params.id && Number(currentTab) < 5) || (!$route.params.id && Number(currentTab) < 5)"
			          @click="downTab('formItem')"
			      >下一步</Button
			      >
			      <Button
			          type="primary"
			          class="ml10"
			          @click="handleSubmit('formItem')"
			          v-if="$route.params.id || (!$route.params.id && currentTab == 5)"
			      >保存</Button
			      >
			    </FormItem>
			  </Form>
			</Card>
		    <Modal v-model="modalPic" width="960px" scrollable  footer-hide closable :title='picTit == "image"?"上传门店照片":"上传门头照片"' :mask-closable="false" :z-index="1">
		        <uploadPictures :isChoice="isChoice" @getPic="getPic" :gridBtn="gridBtn" :gridPic="gridPic" v-if="modalPic"></uploadPictures>
		    </Modal>
			<Modal v-model="modalErp" width="700px" scrollable  footer-hide closable title='erp门店' :mask-closable="false" :z-index="1">
				<erpList ref="refErp" @getProductId="getProductId"></erpList>
			</Modal>
		</div>
        <Modal v-model="modals" title="商品列表"  class="paymentFooter" scrollable width="900" :footer-hide="true">
          <goods-list :chooseType="91" ref="goodslist"  @getProductId="getGoodsId" v-if="modals" :ischeckbox="true" :isLive="true" :storeType="1"></goods-list>
        </Modal>
    </div>
</template>

<script>
    import goodsList from '@/components/goodsList'
	import { keyApi, storeGetInfoApi, cityApi, storeUpdateApi, getRegionManageCascader, getResolveCity, getFinanceConfig } from '@/api/store';
	import { deliveryConfigApi } from '@/api/setting';
	import { erpConfig } from "@/api/erp";
	import { mapState } from 'vuex';
	import uploadPictures from '@/components/uploadPictures';
	import erpList from '../components/erpList.vue';
	import Maps from '@/components/map/map.vue'
	import Setting from "@/setting";

	function createDefaultFormItem() {
		return {
			space_num: '',
			city_delivery_status: 1,
			city_delivery_type: 0,
			default_delivery: 1,
			use_system_money: 1,
			is_alone: 0,
			product_change_price_status: 0,
			product_category_status: 0,
			delivery_type: [],
			product_id: [],
			region_id: 0,
			manage_region_id: 0,
			manage_region_path: [],
			id: 0,
			erp_shop_id: 0,
			store_account: '',
			store_password: '',
			image: '',
			background_image: '',
			name: '',
			introduction: '',
			phone: '',
			is_show: 1,
			day_time: [],
			address: '',
			detailed_address: '',
			latitude: '',
			longitude: '',
			addressSelect: [],
			product_verify_status: 0,
			product_status: 1,
			type: 1,
			applicable_type: 1,
			coupon_self_built_status: 0,
			coupon_verify_status: 0,
			store_rate_type: 1,
			store_cashier_order_rate: 0,
			store_self_order_rate: 0,
			store_writeoff_order_rate: 0,
			store_recharge_order_rate: 0,
			store_svip_order_rate: 0,
		};
	}

	export default {
		name: 'systemStore',
		components: { uploadPictures,Maps,erpList,goodsList },
		props: { },
		data () {
			let validatePhone = (rule, value, callback) => {
				if (!value) {
					return callback(new Error('请填写手机号'));
				} else if (!/^400[0-9]{7}|^1[3456789]\d{9}$|^0[0-9]{2,3}-[0-9]{7,8}/.test(value)) {
					callback(new Error('手机号格式不正确!'));
				} else {
					callback();
				}
			};
			let validateUpload = (rule, value, callback) => {
				if (!this.formItem.image) {
					callback(new Error('请上传门店照片'))
				} else {
					callback()
				}
			};
			const validateImgUpload = (rule, value, callback) => {
				if (!this.formItem.background_image) {
					callback(new Error('请上传门头照片'))
				} else {
					callback()
				}
			};
			let validateErp = (rule, value, callback) => {
				if (this.formItem.erp_shop_id == 0) {
					callback(new Error('请选择erp门店'))
				} else {
					callback()
				}
			};
			return {
        baseURL: Setting.apiBaseURL.replace(/adminapi/, ''),
				currentTab:'1',
				routerPre: Setting.roterPre,
				goodsList:[],
				modals:false,
				modalErp:false,
				openErp:false,
				formItem: createDefaultFormItem(),
				loadedRouteStoreId: '',
				cashierOrderRate:0,
				selfOrderRate:0,
				writeoffOrderRate:0,
				rechargeOrderRate:0,
				svipOrderRate:0,
				spinShow: false,
				addresData: [],
				ruleValidate: {
					// valid_range: [
					// 	{ required: true, message: '请输入配送范围' }
					// ],
          name: [
						{ required: true, message: '请输入门店名称', trigger: 'blur' }
					],
					erp_shop_id: [
						{ required: true, validator: validateErp, trigger: 'change' }
					],
					store_account: [
						{ required: true, message: '请输入管理员账号', trigger: 'blur' }
					],
					store_password: [
						{ required: true, message: '请输入管理员密码', trigger: 'blur' }
					],
					address: [
						{ required: true, message: '请选择门店地址', trigger: 'change' }
					],
					phone: [
						{ required: true, validator: validatePhone, trigger: 'blur' }
					],
					detailed_address: [
						{ required: true, message: '请输入详细地址', trigger: 'blur' }
					],
					image: [
						{ required: true, validator: validateUpload, trigger: 'change' }
					],
                    background_image: [
						{ required: true, validator: validateImgUpload, trigger: 'change' }
					],
					day_time: [
						{required: true,type: "array", message: "请选择营业时间",trigger: "change"},
						{validator(rule, value, callback, source, options)
							{
								if (value[0] === "") {
								callback("请选择营业时间");
								}
							 callback();//这个一定要有。不然无法验证通过
							}
						}
					],//TimePicker-timerange，自定义的
          delivery_type: [
            {
              required: true,
              type: 'array',
              validator: (rule, value, callback) => {
                if (Array.isArray(value) && value.length) {
                  callback();
                } else {
                  if (this.currentTab == 3) {
                    callback(new Error('请选择配送方式'));
                  } else {
                    callback();
                  }
                }
              },
            }
          ],
				},
				mapKey: '',
				grid: {
					xl: 20,
					lg: 20,
					md: 20,
					sm: 24,
					xs: 24
				},
				gridPic: {
					xl: 6,
					lg: 8,
					md: 12,
					sm: 12,
					xs: 12
				},
				gridBtn: {
					xl: 4,
					lg: 8,
					md: 8,
					sm: 8,
					xs: 8
				},
				modalPic: false,
				isChoice: '单选',
				pid:0,
				isApi:0,
				storeAddress:'',
        cityDeliveryStatus: 0,
				manageRegionTree: [],
			}
		},
		created () {
			this.initStaticData();
			this.loadPageByRoute();
		},
		activated () {
			this.loadPageByRoute();
		},
		watch: {
			'$route.params.id'(id) {
				this.loadPageByRoute(id);
			},
		},
		computed: {
			...mapState("admin/layout", ["isMobile","menuCollapse"]),
			labelWidth () {
				return this.isMobile ? undefined : 120;
			},
			labelPosition () {
				return this.isMobile ? 'top' : 'right';
			},
			listRoutePath () {
				if (this.$route.query.from === 'region') {
					return `${this.routerPre}/store/region/list`;
				}
				return `${this.routerPre}/store/store/index`;
			},
			listRouteQuery () {
				if (this.$route.query.from !== 'region') {
					return {};
				}
				return {
					from: 'region',
					tab: 'store',
					_r: Date.now(),
				};
			}
		},
		mounted: function () {},
		methods: {
			initStaticData() {
				if (this._staticDataReady) return;
				this._staticDataReady = true;
				this.getErpConfig();
				this.getKey();
				this.cityInfo({ pid: 0 });
				this.getDeliveryConfig();
				this.financeConfig();
			},
			loadPageByRoute(routeId) {
				const storeId = routeId !== undefined
					? (routeId ? String(routeId) : '')
					: (this.$route.params.id ? String(this.$route.params.id) : '');
				if (storeId && storeId === this.loadedRouteStoreId) return;
				this.currentTab = '1';
				this.loadManageRegionTree().then(() => {
					if (storeId) {
						this.loadedRouteStoreId = storeId;
						this.getInfo(storeId);
					} else {
						this.resetFormForCreate();
					}
				});
			},
			resetFormForCreate() {
				this.loadedRouteStoreId = '';
				this.isApi = 1;
				Object.assign(this.formItem, createDefaultFormItem());
				const manageRegionId = Number(this.$route.query.manage_region_id || 0);
				if (manageRegionId > 0) {
					this.formItem.manage_region_path = this.buildManageRegionPath(manageRegionId, this.manageRegionTree);
					this.syncManageRegionFromPath();
				}
				this.storeAddress = '';
			},
			applyStoreInfo(info, id) {
				Object.assign(this.formItem, createDefaultFormItem(), info, {
					id: Number(id),
					erp_shop_id: info.erp_shop_id || 0,
					day_time: info.timeVal || info.day_time || [],
					delivery_type: Array.isArray(info.delivery_type)
						? info.delivery_type.map(String)
						: (info.delivery_type ? String(info.delivery_type).split(',') : []),
					manage_region_id: info.manage_region_id || 0,
					manage_region_path: info.manage_region_path || [],
					store_cashier_order_rate: Number(info.store_cashier_order_rate) || 0,
					store_self_order_rate: Number(info.store_self_order_rate) || 0,
					store_writeoff_order_rate: Number(info.store_writeoff_order_rate) || 0,
					store_recharge_order_rate: Number(info.store_recharge_order_rate) || 0,
					store_svip_order_rate: Number(info.store_svip_order_rate) || 0,
				});
				if (!this.formItem.manage_region_path.length && this.formItem.manage_region_id) {
					this.formItem.manage_region_path = this.buildManageRegionPath(
						this.formItem.manage_region_id,
						this.manageRegionTree
					);
				}
				this.syncManageRegionFromPath();
				this.storeAddress = info.address || '';
			},
			financeConfig(){
				getFinanceConfig(0).then(res=>{
				    let data = res.data;
					this.cashierOrderRate = Number(data.store_cashier_order_rate) || 0;
					this.selfOrderRate = Number(data.store_self_order_rate) || 0;
					this.writeoffOrderRate = Number(data.store_writeoff_order_rate) || 0;
					this.rechargeOrderRate = Number(data.store_recharge_order_rate) || 0;
					this.svipOrderRate = Number(data.store_svip_order_rate) || 0;
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
		    //对象数组去重；
		    unique(arr) {
			  const res = new Map();
			  return arr.filter((arr) => !res.has(arr.product_id) && res.set(arr.product_id, 1))
		    },
		    getGoodsId (data) {
			  let list = this.goodsList.concat(data);
			  let uni = this.unique(list);
			  this.goodsList = uni;
			  this.$nextTick(res=>{
			    setTimeout(()=>{
				  this.modals = false
			    },300)
			  })
		    },
		    bindDelete (index) {
			  this.goodsList.splice(index, 1)
		    },
			getProductId(id){
				this.formItem.erp_shop_id = id;
				this.modalErp = false;
				this.$refs.formItem.validateField("erp_shop_id");
			},
			tapErp(){
				this.$refs.refErp.currentid = this.formItem.erp_shop_id;
				this.modalErp = true;
				this.$refs.formItem.validateField("erp_shop_id");
			},
			getErpConfig(){
				erpConfig().then(res=>{
					this.openErp = res.data.open_erp;
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			addchack(e,selectedData){
				this.formItem.addressSelect = e;
				this.formItem.address = (selectedData.map(o => o.label)).join("");
				this.storeAddress = (selectedData.map(o => o.label)).join("");
			},
			cityInfo(data){
				cityApi(data).then(res=>{
          console.log(res)
					this.addresData = res.data
				})
			},
			loadData(item, callback) {
				item.loading = true;
				cityApi({pid:item.value}).then(res=>{
					item.children = res.data;
					item.loading = false;
					callback();
				});
			},
			resolveCity(address){
				let data = {
					address:address
				}
				getResolveCity(data).then(res=>{
					let array = []
					res.data.forEach(item=>{
						array.push(item.id)
					})
					this.formItem.addressSelect = array
				}).catch(err=>{
					this.$Message.error(res.msg)
				})
			},
			// 地图信息获取
			getCoordinates(data) {
				this.formItem.latitude = data.location.lat || 34.34127
				this.formItem.longitude = data.location.lng || 108.93984
				if(data.address_reference){
					let landmark = data.address_reference.landmark_l2;
					this.formItem.detailed_address = landmark.title;
					this.formItem.latitude = landmark.location.lat || 34.34127;
					this.formItem.longitude = landmark.location.lng || 108.93984;
					let component = data.address_component;
					let town = data.address_reference.town.title;
					town = town == '丈八街道'?'丈八沟街道':town;
					let address = [component.province,component.city,component.district,town];
					this.storeAddress = address.join('');
					this.formItem.address = address.join('');
					this.resolveCity(address.join('/'));
				}
			},
			// 查找位置
			onSearch() {
				if(this.$refs.mapChild){
					this.$refs.mapChild.searchKeyword(this.storeAddress+this.formItem.detailed_address)
				}
			},
			// key值
			getKey () {
				keyApi().then(res => {
					this.mapKey = res.data.key
				}).catch(res => {
					this.$Message.error(res.msg)
				})
			},
			// 详情
			getInfo (id) {
			    let that = this;
			    that.spinShow = true;
			    storeGetInfoApi(id).then(res => {
					this.isApi = 1;
					this.applyStoreInfo(res.data.info || {}, id);
					that.spinShow = false;
				}).catch(function (res) {
					that.spinShow = false;
					that.loadedRouteStoreId = '';
					that.$Message.error(res.msg);
				})
			},
			// 选择图片
			modalPicTap (tit, picTit) {
				this.modalPic = true;
				this.picTit = picTit || "";
				this.$refs.formItem.validateField(picTit)
			},
			// 选中图片
			getPic (pc) {
				this.formItem[this.picTit] = pc.att_dir;
				this.modalPic = false;
				this.$refs.formItem.validateField(this.picTit)
			},
			// 营业时间
			onchangeTime (e) {
				this.formItem.day_time = e;
			},
			// 上一页；
			upTab() {
				this.currentTab = (Number(this.currentTab) - 1).toString();
				if(this.$route.params.id && this.currentTab == 4){
					this.currentTab = (Number(this.currentTab) - 1).toString();
				}
			},
			// 下一页；
			downTab(name) {
			  this.$refs[name].validate((valid) => {
			    if (valid) {
				  if(this.currentTab==3 && this.formItem.delivery_type != 3){
					  // if(this.formItem.valid_range == '' || this.formItem.valid_range<0){
					  // 	return this.$Message.error('请输入有效的门店范围');
					  // }
				  }
				  if(this.currentTab==4 && this.formItem.applicable_type == 2 && !this.goodsList.length){
					  return this.$Message.error('请选择指定商品');
				  }
			      this.currentTab = (Number(this.currentTab) + 1).toString();
				  if(this.$route.params.id && this.currentTab == 4){
					  this.currentTab = (Number(this.currentTab) + 1).toString();
				  }
			    }else{
			      this.$Message.warning("请完善数据");
			    }
			  })
			},
			// 提交
			handleSubmit (name) {
				this.$refs[name].validate((valid) => {
					if (valid) {
						if(this.formItem.day_time[0] == ''){
							this.formItem.day_time = ['00:00:00', '23:59:59']
						}
						// if((this.formItem.valid_range == ''||this.formItem.valid_range<0.01) && this.formItem.delivery_type != 3){
						// 	return this.$Message.error('请输入有效的门店范围');
						// }
						if(this.currentTab==4 && this.formItem.applicable_type == 2 && !this.goodsList.length){
							return this.$Message.error('请选择指定商品');
						}
						let product_id = []
						this.goodsList.forEach(item=>{
						  product_id.push(item.product_id)
						})
						this.formItem.product_id = product_id;
            if (!this.cityDeliveryStatus) {
              this.formItem.delivery_type = this.formItem.delivery_type.filter(item => item != '3');
            }
						this.syncManageRegionFromPath();
						this.formItem.cate_id = [];

						storeUpdateApi(this.formItem.id,this.formItem).then(async res => {
							this.$Message.success(res.msg);
							this.$router.push({ path: this.listRoutePath, query: this.listRouteQuery });
						}).catch(res => {
							this.$Message.error(res.msg);
						})
					} else {
						return false;
					}
				})

			},
      onCouponSelfBuiltStatusChange(value) {
        this.formItem.coupon_verify_status = 0;
      },
      // 配送方式改变
      onDeliveryTypeChange(value) {
        if (!value.includes('2')) {
          this.formItem.city_delivery_type = 0;
        }
        if ((value.includes('1') || value.includes('2')) && !value.includes('3')) {
          this.formItem.default_delivery = 1;
        } else if (!value.includes('1') && !value.includes('2') && value.includes('3')) {
          this.formItem.default_delivery = 2;
        }
      },
      // 获取商城同城配送开启状态
      getDeliveryConfig() {
        deliveryConfigApi().then((res) => {
          this.cityDeliveryStatus = Number(res.data.city_delivery_status);
        });
      },
			loadManageRegionTree() {
				return getRegionManageCascader().then(res => {
					this.manageRegionTree = this.attachRegionPid(res.data || []);
				}).catch(err => {
					this.$Message.error(err.msg);
				});
			},
			attachRegionPid(tree, pid = 0) {
				return (tree || [])
					.filter(item => item.value !== 0)
					.map(item => ({
						...item,
						pid,
						children: item.children && item.children.length
							? this.attachRegionPid(item.children, item.value)
							: [],
					}));
			},
			buildManageRegionPath(id, tree, prefix = []) {
				for (let i = 0; i < (tree || []).length; i++) {
					const node = tree[i];
					const path = prefix.concat([node.value]);
					if (Number(node.value) === Number(id)) {
						return path;
					}
					if (node.children && node.children.length) {
						const childPath = this.buildManageRegionPath(id, node.children, path);
						if (childPath.length) {
							return childPath;
						}
					}
				}
				return [];
			},
			syncManageRegionFromPath() {
				const path = this.formItem.manage_region_path || [];
				this.formItem.manage_region_id = path.length ? Number(path[path.length - 1]) : 0;
			},
		}
	}
</script>

<style scoped lang="stylus">
.tip{
	color: #ed4014;
}
.fixed-card {
    position: fixed;
    right: 0;
    bottom: 0;
    left: 200px;
    z-index: 25;
    box-shadow: 0 -1px 2px rgb(240, 240, 240);

    /deep/ .ivu-card-body {
      padding: 15px 16px 14px;
    }

    .ivu-form-item {
      margin-bottom: 0;
    }

    /deep/ .ivu-form-item-content {
      margin-right: 124px;
      text-align: center;
    }

    .ivu-btn {
      height: 36px;
      padding: 0 20px;
    }
}
/deep/.ivu-col-span-xs-24{
	max-width: 100% !important
}
.new_tab {
  /deep/.ivu-tabs-nav .ivu-tabs-tab{
    padding:4px 16px 20px !important;
    font-weight: 500;
  }
}
.tips {
  display: inline-bolck;
  font-size: 12px;
  font-weight: 400;
  color: #999;
}
.box{
  display: flex
  flex-wrap: wrap
  .box-item{
    position: relative
    margin-right: 20px
    width: 60px
    height: 60px
    margin-bottom: 10px

    img {
      width: 100%
      height: 100%
    }
    .icon{
      position: absolute;
      top:-10px;
      right: -10px;
    }
  }
  .upload-box{
    width: 60px
    height: 60px
    margin-bottom: 10px
    display: flex
    align-items: center
    justify-content: center
    background: #ccc
  }
}
	.map-sty {
		width: 90%;
		text-align: right;
		margin: 0 0 0 10%;
	}
	.footer{
		width: 100%;
		height: 50px;
		box-shadow: 0px -2px 4px 0px rgba(0, 0, 0, 0.05);
		margin-top: 50px;
	}
.btn /deep/.ivu-btn-primary{
		width: 86px;
	}
	.btn{
		margin-top: 20px;
	}
	.inputW{
		width: 400px;
	}
	.ivu-mt{
		min-width: 580px;
		margin-bottom: 45px;
	}
	.picBox{
		display: inline-block;
		cursor: pointer;
		.upLoad{
			width: 58px;
			height: 58px;
			line-height: 58px;
			border: 1px dotted rgba(0, 0, 0, 0.1);
			border-radius: 4px;
			background: rgba(0, 0, 0, 0.02);
		}
		.pictrue{
			width: 60px;
			height: 60px;
			border: 1px dotted rgba(0, 0, 0, 0.1);
			margin-right: 10px;
			img {
				width: 100%;
				height: 100%;
			}
		}
		.iconfont{
			color: #CCCCCC;
			font-size: 26px;
			text-align: center
		}
	}

</style>
