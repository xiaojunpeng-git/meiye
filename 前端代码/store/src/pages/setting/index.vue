<template>
	<div>
		<Card :bordered="false" dis-hover>
			<Tabs v-model="currentTab">
				<TabPane :label="'基础设置'" name="1" />
				<TabPane :label="'配送设置'" name="2" />
			</Tabs>
		</Card>
		<Card :bordered="false" dis-hover class="mb79">
			<Form ref="formItem" :model="formItem" :label-width="labelWidth" :label-position="labelPosition" :rules="ruleValidate" @submit.native.prevent>
				<Row type="flex" style="width: 720px;">
					<Col span="24" v-if="currentTab == 1">
						<FormItem label="门店照片：" required prop="image">
							<div class="picBox" @click="modalPicTap('单选', 'image')">
								<div class="pictrue" v-if="formItem.image"><img v-lazy="formItem.image"></div>
								<div class="upLoad acea-row row-center-wrapper"   v-else>
									<span class="iconfont icontupian"></span>
								</div>
							</div>
						    <div class="tips"> 建议尺寸：70 * 70px</div>
						</FormItem>
					</Col>
                    <Col span="24" v-if="currentTab == 1">
						<FormItem label="门头照片：" prop="background_image">
							<div class="picBox" @click="modalPicTap('单选', 'background_image')">
								<div class="pictrue" v-if="formItem.background_image"><img v-lazy="formItem.background_image"></div>
								<div class="upLoad acea-row row-center-wrapper"   v-else>
									<span class="iconfont icontupian"></span>
								</div>
							</div>
						    <div class="tips"> 建议尺寸：375 * 192px</div>
						</FormItem>
					</Col>
					<Col span="24" v-if="currentTab == 1">
						<FormItem label="门店名称：" prop="name" label-for="name">
							<Input v-model="formItem.name" maxlength="20" show-word-limit placeholder="请输入门店名称" class="inputW"/>
						</FormItem>
					</Col>
					<Col span="24" v-if="currentTab == 1">
						<FormItem label="门店简介："label-for="introduction">
							<Input v-model="formItem.introduction" maxlength="100" type="textarea" show-word-limit placeholder="请输入门店简介"  class="inputW" />
						</FormItem>
					</Col>
					<Col span="24" v-if="currentTab == 1">
						<FormItem label="门店手机号：" label-for="phone" prop="phone">
							<Input v-model="formItem.phone"  placeholder="请输入门店手机号"  class="inputW"/>
						</FormItem>
					</Col>
					<Col span="24" v-if="currentTab == 1">
						<FormItem label="营业状态：" label-for="is_show" prop="is_show">
							<Switch size="large" v-model="formItem.is_show" :false-value="0" :true-value="1">
								<span slot="open" :true-value="1">开启</span>
								<span slot="close" :false-value="0">关闭</span>
							</Switch>
						</FormItem>
					</Col>
					<Col span="24" v-if="formItem.is_show == 1 && currentTab == 1">
						<FormItem label="营业时间：" label-for="day_time" prop="day_time">
							<TimePicker type="timerange" @on-change="onchangeTime" v-model="formItem.day_time"  format="HH:mm:ss" :value="formItem.day_time" placement="bottom-end" placeholder="请选择营业时间" class="inputW" ></TimePicker>
						</FormItem>
					</Col>
					<Col span="24" v-if="currentTab == 2" key="delivery_type">
						<FormItem label="配送方式：" label-for="delivery_type" prop="delivery_type">
							<CheckboxGroup v-model="formItem.delivery_type" @on-change="onDeliveryTypeChange">
								<Checkbox label="1">快递发货</Checkbox>
								<Checkbox label="3" v-if="cityDeliveryStatus">同城配送</Checkbox>
								<Checkbox label="2">到店自提</Checkbox>
							</CheckboxGroup>
							<div class="tips">设置本门店支持的配送方式，开启同城配送后，请于同城配送 -> 配送设置中填写配送信息；且务必检查商品重量字段，将直接影响配送运费计算。</div>
						</FormItem>
					</Col>
					<!-- <Col span="24" v-if="currentTab == 2 && formItem.delivery_type !=3">
						<FormItem label="配送范围(半径)：" label-for="valid_range" prop="valid_range">
							<InputNumber :min="0.01" :max="100000" v-model="formItem.valid_range" :formatter="value => `${formItem.valid_range}`" :parser="value => value.replace('%', '')"></InputNumber><span style="margin-left: 10px;">km</span>
						</FormItem>
					</Col> -->
					<!-- <Col span="24" v-if="currentTab == 2 && formItem.delivery_type !=3">
						<FormItem label="同城配送：" label-for="city_delivery_status" prop="city_delivery_status">
							<Switch size="large" v-model="formItem.city_delivery_status" :false-value="0" :true-value="1">
								<span slot="open" :true-value="1">开启</span>
								<span slot="close" :false-value="0">关闭</span>
							</Switch>
						</FormItem>
					</Col> -->
					<!-- <Col span="24" v-if="formItem.city_delivery_status && formItem.delivery_type !=3 && currentTab == 2">
						<FormItem label="第三方配送：" required label-for="city_delivery_type" prop="city_delivery_type">
							<RadioGroup v-model="formItem.city_delivery_type" @on-change="deliveryType">
								<Radio :label="1">达达快送</Radio>
								<Radio :label="2">UU跑腿</Radio>
								<Radio :label="0">均不使用</Radio>
							</RadioGroup>
						</FormItem>
					</Col> -->
					<!-- <Col span="24" v-if="formItem.city_delivery_status && formItem.city_delivery_type>0 && formItem.delivery_type !=3 && currentTab == 2">
						<FormItem label="配送商品类型：">
							<Select v-model="formItem.business" placeholder="全部" class="input-add">
								<Option :value="item.key" v-for="item in businessList" :key="item.key">{{item.label}}</Option>
							</Select>
						</FormItem>
					</Col> -->
				    <Col span="24" v-if="currentTab == 1">
						<FormItem label="门店首页样式：" label-for="home_style" prop="home_style">
							<RadioGroup v-model="formItem.home_style">
							  <Radio :label="1">样式1</Radio>
							  <Radio :label="2">样式2</Radio>
							</RadioGroup>
						</FormItem>
				    </Col>
                    <Col span="24" v-if="currentTab == 2 && formItem.delivery_type.length" key="default_delivery">
						<FormItem label="默认配送方式：">
							<RadioGroup v-model="formItem.default_delivery">
							  <Radio :label="1" :disabled="!formItem.delivery_type.includes('1') && !formItem.delivery_type.includes('3')">配送</Radio>
							  <Radio :label="2" :disabled="!formItem.delivery_type.includes('2')">到店</Radio>
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
					<Col span="24" v-if="currentTab == 1">
						<FormItem label="客服类型：" label-for="customer_type" prop="customer_type">
							<RadioGroup v-model="formItem.customer_type">
								<Radio :label="1">电话</Radio>
								<Radio :label="2">二维码</Radio>
							</RadioGroup>
						</FormItem>
					</Col>
					<Col span="24" v-if="currentTab == 1">
						<FormItem label="门店地址：" label-for="address" prop="address">
							<Cascader :data="addresData" :load-data="loadData" v-model="formItem.addressSelect" @on-change="addchack" class="inputW"></Cascader>
						</FormItem>
					</Col>
					<Col span="24" v-if="currentTab == 1">
						<FormItem label="门店详细地址：" label-for="detailed_address" prop="detailed_address">
							<div class="acea-row row-middle">
								<Input v-if="formItem.address" disabled v-model="formItem.address" class="w-240"/>
								<Input search enter-button="查找位置" v-model="formItem.detailed_address"  placeholder="输入详细地址" class="w-300 ml-6" @on-search="onSearch" />
							</div>
							<div class="tip">提示：为减少误差，建议门店地址与定位地区保持一致</div>
						</FormItem>
					</Col>
					<Col span="24" v-if="isApi && currentTab == 1">
						<Maps v-if="mapKey" ref="mapChild" class="map-sty" :mapKey="mapKey" :lat="Number(formItem.latitude || 34.34127)" :lon="Number(formItem.longitude || 108.93984)" :address="formItem.address+formItem.detailed_address" @getCoordinates="getCoordinates" />
					</Col>
				</Row>
				<Spin size="large" fix v-if="spinShow"></Spin>
			</Form>
		</Card>
		<div class="h-100"></div>
		<Card :bordered="false" dis-hover class="fixed-card" :style="{left: `${!menuCollapse?'220px':isMobile?'0':'80px'}`}">
		    <Form>
		        <FormItem>
		            <Button
		                    type="primary"
		                    class="submission"
		                    @click="handleSubmit('formItem')"
		            >保存</Button
		            >
		        </FormItem>
		    </Form>
		</Card>
		<Modal v-model="modalPic" width="960px" scrollable  footer-hide closable title='上传门店照片' :mask-closable="false" :z-index="99">
			<uploadPictures :isChoice="isChoice" @getPic="getPic" :gridBtn="gridBtn" :gridPic="gridPic" v-if="modalPic"></uploadPictures>
		</Modal>
	</div>
</template>


<script>
	import { keyApi, storeUpdateApi, storeGetInfoApi, cityApi, getBusiness, getResolveCity, deliveryConfigApi } from '@/api/setting';
	import { mapState,mapMutations } from "vuex";
	import uploadPictures from '@/components/uploadPictures';
	import Maps from '@/components/map/map.vue'
  import Setting from '@/setting';
	export default {
		name: 'systemStore',
		components: { uploadPictures,Maps },
		props: { },
		data () {
			const validatePhone = (rule, value, callback) => {
				if (!value) {
					return callback(new Error('请填写手机号'));
				} else if (!/^1[3456789]\d{9}$/.test(value)) {
					callback(new Error('手机号格式不正确!'));
				} else {
					callback();
				}
			};
			const validateRange = (rule, value, callback) => {
				if(value<=0){
					callback(new Error('请输入有效的配送范围'))
				}else{
					callback()
				}	
			}
			const validateUpload = (rule, value, callback) => {
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
			return {
        baseURL: Setting.apiBaseURL.replace(/storeapi/, ''),
				currentTab:'1',
				formItem: {
					image: '',
					background_image:'',
					name: '',
					introduction: '',
					phone: '',
					is_show: true,
					day_time: [],
					delivery_type: [],
					address: '',
					detailed_address: '',
					latitude:'',
					longitude:'',
					addressSelect:[],
					valid_range:0,
					city_delivery_status:1,
					city_delivery_type:0,
				    home_style:1,//门店首页样式
				    business:0,//同城配送商品类型
					customer_type:1,//客服类型1：电话，2：二维码
                default_delivery: 1
				},
				spinShow: false,
				addresData: [],
				ruleValidate: {
					name: [
						{ required: true, message: '请输入门店名称', trigger: 'blur' }
					],
					phone: [
						{ required: true, validator: validatePhone, trigger: 'blur' }
					],
					valid_range: [
						{ required: true, validator: validateRange, trigger: 'blur', type: 'number' }
					],
					address: [
						{ required: true, message: '请选择门店地址', trigger: 'change' }
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
                  callback(new Error('请选择配送方式'));
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
				businessList:[],
				cityDeliveryStatus: 0, // 商城同城配送开启状态
			}
		},
		created () {
			this.getDeliveryConfig();
			this.getKey();
			this.getInfo();
			let data = {pid:0};
			this.cityInfo(data);
		},
		computed: {
			...mapState('store/layout', [
				'isMobile','menuCollapse'
			]),
			labelWidth () {
				return this.isMobile ? undefined : 164;
			},
			labelPosition () {
				return this.isMobile ? 'top' : 'right';
			}
		},
		mounted: function () {
			this.setCopyrightShow({ value: false });
		},
		destroyed () {
		    this.setCopyrightShow({ value: true });
		},
		methods: {
			...mapMutations('store/layout', [
			    'setCopyrightShow'
			]),
			deliveryType(){
				if(this.formItem.city_delivery_type>0){
					this.getBusinessList();
				}
			},
			getBusinessList(){
				getBusiness(this.formItem.city_delivery_type).then(res=>{
					this.businessList = res.data;
				}).catch(err=>{
					this.$Message.error(err.msg)
				})
			},
			addchack(e,selectedData){
				this.formItem.addressSelect = e;
				this.formItem.address = (selectedData.map(o => o.label)).join("");
			},
			cityInfo(data){
				cityApi(data).then(res=>{
					this.addresData = res.data
				})
			},
			loadData (item, callback) {
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
					this.formItem.address = address.join('');
					this.resolveCity(address.join('/'));
				}
			},
			// 查找位置
			onSearch() {
				this.$refs.mapChild.searchKeyword(this.formItem.address+this.formItem.detailed_address)
			},
			// key值
			getKey () {
				keyApi().then(res => {
					this.mapKey = res.data.tengxun_map_key
				}).catch(res => {
					this.$Message.error(res.msg)
				})
			},
			// 详情
			getInfo () {
				let that = this;
				that.spinShow = true;
				storeGetInfoApi().then(res => {
					this.isApi = 1;
					this.formItem = res.data;
					this.$set(this.formItem,'valid_range',(this.formItem.valid_range)/1000)
					that.spinShow = false;
					this.deliveryType();
				}).catch(function (res) {
					that.spinShow = false;
					that.$Message.error(res.msg);
				})
			},
			// 选择图片
			modalPicTap (tit, picTit) {
				this.modalPic = true;
                this.picTit = picTit || "";
			},
			// 选中图片
			getPic (pc) {
                this.formItem[this.picTit] = pc.att_dir;
				this.modalPic = false;
			},
			// 营业时间
			onchangeTime (e) {
				this.formItem.day_time = e;
			},
			// 提交
			handleSubmit (name) {
				this.$refs[name].validate((valid) => {
					if (valid) {
						if(this.formItem.day_time[0] == ''){
							this.formItem.day_time = ['00:00:00', '23:59:59']
						}
            if (!this.cityDeliveryStatus) {
              this.formItem.delivery_type = this.formItem.delivery_type.filter(item => item != '3');
            }
						storeUpdateApi(this.formItem).then(async res => {
							this.$Message.success(res.msg);
						}).catch(res => {
							this.$Message.error(res.msg);
						})
					} else {
						this.$Message.error('请完善信息');
					}
				})
			},
      // 配送方式改变
      onDeliveryTypeChange(value) {
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
		}
	}
</script>

<style scoped lang="stylus">
/deep/.ivu-col-span-xs-24{
	max-width: 100% !important
}
.tip{
	color: #ed4014;
}
.ivu-form-item .tips {
      font-size: 12px;
      font-weight: 400;
      color: #999999;
  }
	/deep/.ivu-tabs {
	    background-color: #ffffff;
	    padding: 3px 20px 0 20px;
	    border-radius: 6px;
	}
	/deep/.ivu-tabs-nav .ivu-tabs-tab {
	    padding: 4px 16px 20px !important;
	    font-weight: 500;
	}
	.fixed-card {
	    position: fixed;
	    right: 0;
	    bottom: 0;
	    left: 200px;
	    z-index: 20;
	    box-shadow: 0 -1px 2px rgb(240, 240, 240);
		
	    /deep/ .ivu-card-body {
	        padding: 15px 16px 14px;
	    }
		
	    .ivu-form-item {
	        margin-bottom: 0;
	    }
		
	    /deep/ .ivu-form-item-content {
	        text-align: center;
	    }
		
	    .ivu-btn {
	        height: 36px;
	        padding: 0 20px;
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
	/deep/.ivu-btn-primary{
		width: 86px;
	}
	.inputW{
		width: 400px;
	}
	.ivu-mt{
		min-width: 580px;
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
			color: #898989;
		}
	}
</style>
