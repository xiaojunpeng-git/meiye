<template>
	<div>
		<Card :bordered="false" dis-hover>
			<Tabs v-model="currentTab">
				<TabPane :label="'账户设置'" name="1" />
				<TabPane :label="'手续费'" name="2" />
			</Tabs>
		</Card>
		<Card :bordered="false" dis-hover class="mb79">
			<Form :model="formItem" :label-width="140">
				<div v-if="currentTab == 1">
					<FormItem label="提现银行开户行：">
						<Input maxlength="32" show-word-limit type="text" v-model="formItem.bank_address" placeholder="不超过32字" class="w-467"></Input>
					</FormItem>
					<FormItem label="银行卡号：" prop="bank_code">
						<Input maxlength="32" show-word-limit v-model="formItem.bank_code" type="text" placeholder="不超过32字" class="w-467"></Input>
					</FormItem>
					<FormItem label="支付宝账号：">
						<Input maxlength="32" show-word-limit v-model="formItem.alipay_account" placeholder="不超过32字" class="w-467"></Input>
					</FormItem>
					<FormItem label="支付宝收款码：">
						<div class="pictrueBox" @click="modalPicTap('dan', 'alipay')">
							<div class="pictrue" v-if="formItem.alipay_qrcode_url">
								<img v-lazy="formItem.alipay_qrcode_url" />
								<Input
								  v-model="formItem.alipay_qrcode_url"
								  style="display: none"
								></Input>
							</div>
							<div class="upLoad acea-row row-center-wrapper" v-else>
								<Input
								  v-model="formItem.alipay_qrcode_url"
								  style="display: none"
								></Input>
								<Icon type="ios-camera-outline" size="26" />
							</div>
						</div>
					</FormItem>
					<FormItem label="微信账号：">
						<Input maxlength="32" show-word-limit v-model="formItem.wechat" placeholder="不超过32字" class="w-467"></Input>
					</FormItem>
					<FormItem label="微信收款码：">
						<div class="pictrueBox" @click="modalPicTap('dan', 'weixin')">
							<div class="pictrue" v-if="formItem.wechat_qrcode_url">
								<img v-lazy="formItem.wechat_qrcode_url" />
								<Input
								  v-model="formItem.wechat_qrcode_url"
								  style="display: none"
								></Input>
							</div>
							<div class="upLoad acea-row row-center-wrapper" v-else>
								<Input
								  v-model="formItem.wechat_qrcode_url"
								  style="display: none"
								></Input>
								<Icon type="ios-camera-outline" size="26" />
							</div>
						</div>
					</FormItem>
				</div>
				<div v-if="currentTab == 2">
					<FormItem label="收银订单费率(%)：" label-for="store_cashier_order_rate" prop="store_cashier_order_rate">
						<InputNumber :max="100000" :min='0' :disabled="true" v-model="formItem.store_cashier_order_rate" class="w-467"></InputNumber>
						<div class="tips">门店收银台订单费率(%)</div>
					</FormItem>
					<FormItem label="分配订单费率(%)：" label-for="store_self_order_rate" prop="store_self_order_rate">
						<InputNumber :max="100000" :min='0' :disabled="true" v-model="formItem.store_self_order_rate" class="w-467"></InputNumber>
						<div class="tips">商城用户门店配送的订单费率(%)</div>
					</FormItem>
					<FormItem label="核销订单费率(%)：" label-for="store_writeoff_order_rate" prop="store_writeoff_order_rate">
						<InputNumber :max="100000" :min='0' :disabled="true" v-model="formItem.store_writeoff_order_rate" class="w-467"></InputNumber>
						<div class="tips">商城用户门店核销的订单费率(%)</div>
					</FormItem>
					<FormItem label="充值订单返点(%)：" label-for="store_recharge_order_rate" prop="store_recharge_order_rate">
						<InputNumber :max="100000" :min='0' :disabled="true" v-model="formItem.store_recharge_order_rate" class="w-467"></InputNumber>
						<div class="tips">门店给用户充值余额订单给门店返点(%)</div>
					</FormItem>
					<FormItem label="购买付费会员返点(%)：" label-for="store_svip_order_rate" prop="store_svip_order_rate">
						<InputNumber :max="100000" :min='0' :disabled="true" v-model="formItem.store_svip_order_rate" class="w-467"></InputNumber>
						<div class="tips">门店给用户购买付费会员订单给门店返点(%)</div>
					</FormItem>
				</div>
			</Form>
		</Card>
		<div class="h-100"></div>
		<Card :bordered="false" dis-hover class="fixed-card" :style="{left: `${!menuCollapse?'220px':isMobile?'0':'80px'}`}">
		    <Form>
		        <FormItem>
		            <Button
		                    type="primary"
		                    class="submission"
		                    @click="handleSubmit"
		            >保存</Button
		            >
		        </FormItem>
		    </Form>
		</Card>
		<Modal
		  v-model="modalPic"
		  width="950px"
		  scrollable
		  footer-hide
		  closable
		  title="上传商品图"
		  :mask-closable="false"
		  :z-index="99"
		>
		  <uploadPictures
		    :isChoice="isChoice"
		    @getPic="getPic"
		    :gridBtn="gridBtn"
		    :gridPic="gridPic"
		    v-if="modalPic"
		  ></uploadPictures>
		</Modal>
	</div>
</template>

<script>
	import { mapState } from "vuex";
	import { settingApi, settingSaveApi } from "@/api/capital"
	import uploadPictures from "@/components/uploadPictures";
	export default {
	    name: 'setting',
		components: {
		  uploadPictures,
		},
	    data () {
			return{
				currentTab:'1',
				modalPic: false,
				isChoice: "",
				gridBtn: {
				  xl: 4,
				  lg: 8,
				  md: 8,
				  sm: 8,
				  xs: 8,
				},
				gridPic: {
				  xl: 6,
				  lg: 8,
				  md: 12,
				  sm: 12,
				  xs: 12,
				},
				picTit: "",
				tableIndex: 0,
				formItem: {
					bank_address: "",
					bank_code: '',
					alipay_account: '',
					alipay_qrcode_url: '',//支付宝
					wechat: '',
					wechat_qrcode_url: '',//微信
					store_cashier_order_rate:0, //收银订单费率
					store_self_order_rate:0, //分配订单费率
					store_writeoff_order_rate:0,//核销订单费率
					store_recharge_order_rate:0, //充值订单返点
					store_svip_order_rate:0 //购买付费会员返点
				}
			}
		},
		computed: {
			...mapState('store/layout', [
				'isMobile','menuCollapse'
			])
		},
		mounted() {
			this.getList()
		},
		methods:{
			getList(){
				settingApi().then(res=>{
					let data = res.data;
					this.formItem.bank_address = data.bank_address
					this.formItem.bank_code = data.bank_code
					this.formItem.alipay_account = data.alipay_account
					this.formItem.alipay_qrcode_url = data.alipay_qrcode_url
					this.formItem.wechat = data.wechat
					this.formItem.wechat_qrcode_url = data.wechat_qrcode_url
					this.formItem.store_cashier_order_rate = Number(data.store_cashier_order_rate) || 0
					this.formItem.store_self_order_rate = Number(data.store_self_order_rate) || 0
					this.formItem.store_writeoff_order_rate = Number(data.store_writeoff_order_rate) || 0
					this.formItem.store_recharge_order_rate = Number(data.store_recharge_order_rate) || 0
					this.formItem.store_svip_order_rate = Number(data.store_svip_order_rate) || 0
				})
			},
			handleSubmit(){
				settingSaveApi(this.formItem).then(res=>{
					this.$Message.success(res.msg)
				}).catch(err=>{
					this.$Message.error(err.msg)
				})
			},
			// 获取单张图片信息
			getPic(pc) {
			  switch (this.picTit) {
			    case "weixin":
					  this.formItem.wechat_qrcode_url = pc.att_dir;
			     break;
				 case "alipay":
					this.formItem.alipay_qrcode_url = pc.att_dir
				 break
			  }
			  this.modalPic = false;
			},
			// 点击商品图
			modalPicTap(tit, picTit, index) {
			  this.modalPic = true;
			  this.isChoice = tit === "dan" ? "单选" : "多选";
			  this.picTit = picTit;
			  this.tableIndex = index;
			}
		}
	}
</script>

<style scoped lang="less">
	/deep/.ivu-tabs {
	    background-color: #ffffff;
	    padding: 3px 20px 0 20px;
	    border-radius: 6px;
	}
	/deep/.ivu-tabs-nav .ivu-tabs-tab {
	    padding: 4px 16px 20px !important;
	    font-weight: 500;
	}
	.pictrueBox{
		width: 72px;
		height: 72px;
		.upLoad {
		  width: 100%;
		  height: 100%;
		  line-height: 70px;
		  border: 1px dotted rgba(0, 0, 0, 0.1);
		  border-radius: 4px;
		  background: rgba(0, 0, 0, 0.02);
		  cursor: pointer;
		}
		.pictrue {
		  width: 100%;
		  height: 100%;
		  border: 1px dotted rgba(0, 0, 0, 0.1);
		  display: inline-block;
		  position: relative;
		  cursor: pointer;
		  img {
		    width: 100%;
		    height: 100%;
		  }
		}
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
</style>
