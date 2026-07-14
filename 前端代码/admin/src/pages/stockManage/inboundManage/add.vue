<template>
	<div class="form-submit">
		<div class="i-layout-page-header">
			<PageHeader class="product_tabs" hidden-breadcrumb>
				<div slot="title">
					<router-link :to="{ path: `${roterPre}/inbound/manage` }">
						<div class="font-sm after-line">
							<span class="iconfont iconfanhui"></span>
							<span class="pl10">返回</span>
						</div>
					</router-link>
					<span class="mr20 ml16">添加入库单</span>
				</div>
			</PageHeader>
		</div>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<Form class="formValidate mt20" ref="formValidate" :rules="ruleValidate" :model="formValidate"
				:label-width="labelWidth" :label-position="labelPosition" @submit.native.prevent>
				<FormItem label="入库类型：" prop="order_type" label-for="order_type">
					<RadioGroup v-model="formValidate.order_type" @on-change='storeType'>
					  <Radio v-for="(item, index) in orderList" :key="item.id" :label="item.id">{{ item.name }}</Radio>
					</RadioGroup>
					<div class="tips" v-for="(item, index) in orderList" :key="item.id" v-if="item.id == active">{{item.des}}</div>
				</FormItem>
				<FormItem label="售后单号：" prop="" required v-if="formValidate.order_type == 3">
					<!-- <Input v-model="refundOrderId" placeholder="请输入售后单号" v-width="'50%'"></Input>
					<span class="ml10 text-wlll-2d8cf0 pointer" @click="refundInfo">查询</span> -->
					<div v-if="refundOrderId">{{refundOrderId}}<span @click="refundOrder" class="ml-10 text-wlll-2d8cf0 pointer">切换</span></div>
					<div v-else @click="refundOrder" class="text-wlll-2d8cf0 pointer">+选择售后单据</div>
				</FormItem>
				<FormItem label="入库日期：" prop="stock_time">
					<DatePicker v-model="formValidate.stock_time" type="date" :options="options" placeholder="请选择入库日期" v-width="'50%'"/>
				</FormItem>
				<FormItem label="备注：" prop="remark">
					<Input v-model="formValidate.remark" placeholder="请输入备注" v-width="'50%'"></Input>
				</FormItem>
				<FormItem label="入库商品：" prop="" required>
					<div>
						<Button v-if="formValidate.order_type != 3" type="primary" class="mr-15" @click="addGoodsSattr">选择商品</Button>
						<Button type="primary" :disabled="!goodsIds.length" @click="batchDel">批量删除</Button>
					</div>
					<vxe-table ref="xTable" class="mt25" :data="goodsData" @checkbox-all="selectAllEvent"
						@checkbox-change="selectChangeEvent">
						<vxe-column type="checkbox" width="60"></vxe-column>
						<vxe-column v-for="(item, index) in tableHeader" :key="index" :field='item.title' :min-width="item.minWidth || '100'" :fixed="item.fixed">
							<template #header>
								<div class="acea-row row-middle">
									<div>{{item.title}}</div>
									<Poptip :ref="'popoverRef_' + item.slot" placement="top" transfer v-if="['goodProduct','spoiledGoods','inbound'].indexOf(item.slot) !=-1">
										<span class="iconfont iconbianji1 fs-12 ml-8"></span>
										<template #content>
											<div class="pop-title">批量设置</div>
											<div class="mt-14 flex-between-center">
												<Input type="number" @on-change="batchSet" class="w-85"
													v-model="batchNum" />
												<div class="w-132 acea-row row-right row-middle">
													<Button class="ml-10" @click="closeBatchSet(item.slot)">取消</Button>
													<Button type="primary" class="ml-10"
														@click="batchSetConfirm(item.slot)">确认</Button>
												</div>
											</div>
										</template>
									</Poptip>
								</div>
							</template>
							<template #default="{ row }">
								<template v-if="item.key">{{row[item.key]}}</template>
								<template v-if="item.slot === 'store_names'">
									<Tooltip
									  :transfer="true"
									  theme="dark"
									  max-width="200"
									  :delay="300"
									  :content="row.store_names"
									>
									  <div class="line2">{{ row.store_names }}</div>
									</Tooltip>
								</template>
								<template v-if="item.slot === 'store_name'">
									<Tooltip
									  :transfer="true"
									  theme="dark"
									  max-width="200"
									  :delay="300"
									  :content="row.store_name"
									>
									  <div class="line2">{{ row.store_name }}</div>
									</Tooltip>
								</template>
								<template v-if="item.slot === 'inbound'">
								  <InputNumber
								    :controls="false"
								    v-model="row.inbound"
								    :min="0"
								    :max="9999999999"
								    class="priceBox"
								  ></InputNumber>
								</template>
								<template v-if="item.slot === 'goodProduct'">
								  <InputNumber
								    :controls="false"
								    v-model="row.goodProduct"
								    :min="0"
								    :max="9999999999"
								    class="priceBox"
								  ></InputNumber>
								</template>
								<template v-if="item.slot === 'spoiledGoods'">
								  <InputNumber
								    :controls="false"
								    v-model="row.spoiledGoods"
								    :min="0"
								    :max="9999999999"
								    class="priceBox"
								  ></InputNumber>
								</template>
								<template v-if="item.slot === 'action'">
									<a @click="delGoods(row)">删除</a>
								</template>
							</template>
						</vxe-column>
					</vxe-table>
				</FormItem>
			</Form>
		</Card>
		<Card :bordered="false" dis-hover class="fixed-card"
			:style="{ left: `${!menuCollapse ? '236px' : isMobile ? '0' : '60px'}` }">
			<Form>
				<FormItem>
					<Button type="primary" :disabled="openSubimit" class="submission" @click="handleSubmit('formValidate')">保存</Button>
				</FormItem>
			</Form>
		</Card>
		<selectGoodsBox v-model="sattrModals" @getProductId="getAtterId" @on-cancel="cancel"></selectGoodsBox>
		<Modal v-model="orderModal" title="选择售后单" width="960" scrollable footer-hide>
		  <refundOrder @getOrderId="getOrderId"></refundOrder>
		</Modal>
	</div>
</template>

<script>
	import {
		mapState,
		mapMutations
	} from "vuex";
	import {
		inventoryAddApi,
		refundInfoApi
	} from "@/api/stockManage";
	import Setting from "@/setting";
	import selectGoodsBox from '@/components/selectGoodsBox';
	import refundOrder from '@/components/refundOrder/index';
	import {
	  purchase,
	  returnGoods,
	  spoiledGoods,
	  otherGoods,
	} from '../components/tableName.js';
	import { formatDate } from '@/utils/validate';
	export default {
		name: "inboundAdd",
		components: {
		  selectGoodsBox,
		  refundOrder
		},
		data() {
			return {
				roterPre: Setting.roterPre,
				grid: {
					xl: 7,
					lg: 7,
					md: 12,
					sm: 24,
					xs: 24,
				},
				options: {
					disabledDate (date) {
					    return date && date.valueOf() > Date.now();
					}
				},
				orderList: [{
						id: '6',
						name: '初始入库',
						des: '指系统启用库存管理后的首次建账入库，用于登记期初良品/残次品库存。大批量建账请用列表页「初始入库导入」：须下载系统模板，勿改商品ID/SKU唯一值；整表校验通过才入账。'
					},
					{
						id: '1',
						name: '采购入库',
						des: '指企业采购的商品运抵仓库后，正式登记并纳入库存管理'
					},
					{
						id: '3',
						name: '退货入库',
						des: '指用户退回的商品，重新归入商城库存'
					},
					{
						id: '4',
						name: '残次品转良品',
						des: '指将残次品库存转换成可销售的良品库存，转之后残次品的库存减少，正常商品的库存对应增加'
					},
					{
						id: '2',
						name: '其他入库',
						des: '指除常规的入库方式之外的特殊入库情况。调拨入库由调拨确认自动生成，不可在此手工创建。'
					}
				],
				refundOrderId:'',
				formValidate: {
					order_type: '1',
					stock_time: "",
					remark: ""
				},
				ruleValidate: {
					order_type: [{
						required: true,
						message: '请选择入库类型',
						trigger: 'change'
					}],
					stock_time: [{
						required: true,
						type: 'date',
						message: '请选择入库日期',
						trigger: 'change'
					}]
				},
				tableHeader: purchase,
				goodsData: [],
				batchNum: 0,
				sattrModals: false,
				goodsIds: [], //用作批量删除
				refundId:0, //售后订单id
				openSubimit:false,
				active:'1',
				orderModal:false
			};
		},
		computed: {
			...mapState("admin/layout", ["isMobile", "menuCollapse"]),
			labelWidth() {
				return this.isMobile ? undefined : 120;
			},
			labelPosition() {
				return this.isMobile ? "top" : "right";
			},
		},
		created() {
			this.formValidate.stock_time = formatDate(new Date(Number(new Date().getTime())), 'yyyy-MM-dd')
		},
		mounted() {
			this.setCopyrightShow({
				value: false
			});
		},
		destroyed() {
			this.setCopyrightShow({
				value: true
			});
		},
		methods: {
			...mapMutations("admin/layout", ["setCopyrightShow"]),
			storeType(e){
				this.active = e;
				this.tableHeader = [];
				let header = []
				if(e==1){
					header = purchase
				}else if(e==3){
					header = returnGoods
				}else if(e==4){
					header = spoiledGoods
				}else if(e==2 || e==6){
					header = otherGoods
				}
				let that = this;
				setTimeout(function(){
					that.tableHeader = header;
				})
				this.goodsData = [];
			},
			addGoodsSattr(){
				this.sattrModals = true;
			},
			cancel(){
				this.sattrModals = false;
			},
			refundOrder(){
				this.orderModal = true;
			},
			getOrderId(e){
				refundInfoApi({
					order_id: e.order_id
				}).then(res=>{
					this.refundOrderId = e.order_id;
					this.orderModal = false;
					let data = res.data.productInfo;
					data.forEach((i)=>{
					  i.store_names = i.store_name;
					  i.store_name = i.sku;
					  i.goodProduct = 0;
					  i.spoiledGoods = 0;
					  i.inbound = 0;
					})
					this.refundId = res.data.orderInfo.id;
					this.goodsData = data;
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			//对象数组去重；
			unique(arr) {
			  const res = new Map();
			  return arr.filter((arr) => !res.has(arr.id) && res.set(arr.id, 1))
			},
			getAtterId(data){
			  this.sattrModals = false;
			  data.forEach((i)=>{
			    i.goodProduct = 0;
			    i.spoiledGoods = 0;
			    i.inbound = 0;
			  })
			  let list = this.goodsData.concat(data);
			  let uni = this.unique(list);
			  this.goodsData = uni;
			},
			batchSet(event){
				this.batchNum = this.cleanPrice(event.target.value);
			},
			cleanPrice(value) {
			  // 移除非数字和非小数点的字符
			  let cleanedValue = value.replace(/[^\d.]/g, '');
			  // 确保只有一个小数点
			  let parts = cleanedValue.split('.'); 
			  if (parts.length > 2) {
			    cleanedValue = parts[0] + '.' + parts.slice(1).join('');
			  }
			  // 确保小数点后最多有两位数字
			  if (cleanedValue.includes('.')) {
			    let [integerPart, decimalPart] = cleanedValue.split('.');
			    cleanedValue = integerPart + '.' + decimalPart.slice(0, 2);
			  }
			  return cleanedValue;
			},
			closeBatchSet(i){
				this.batchNum = 0;
				document.body.click()
				//this.$refs['popoverRef_' + i][0].doClose(); //关闭的
			},
			batchSetConfirm(i){
				let batchNum = this.batchNum;
				if(batchNum<=0){
					return this.$Message.error('批量设置必须大于0');
				}
				this.goodsData.map((item) => {
					item[i] = parseFloat(batchNum)>=0 ? parseFloat(batchNum):null;
				});
				this.$refs.xTable.refreshColumn();
				this.closeBatchSet();
			},
			// 删除单个商品
			delGoods(row){
				let index = this.goodsData.findIndex(item=> item.id===row.id);
				this.goodsData.splice(index,1)
			},
			// 批量删除商品
			batchDel(){
				this.goodsData = this.goodsData.filter(item => !this.goodsIds.some(ele=>ele===item.id));
				this.goodsIds = [];
			},
			selectAllEvent(e) {
				if(e.checked){
					let ids = [];
					this.goodsData.forEach(item=>{
						ids.push(item.id)
					})
					this.goodsIds = ids;
				}else{
					this.goodsIds = [];
				}
			},
			selectChangeEvent(e) {
				let id = e.row.id;
				let index = this.goodsIds.indexOf(id);
				if (index !== -1) {
				  this.goodsIds = this.goodsIds.filter((item) => item !== id);
				} else {
				  this.goodsIds.push(id);
				}
			},
			handleSubmit(name) {
				this.$refs[name].validate((valid) => {
					if (valid) {
						if(!this.goodsData.length){
							if(this.formValidate.order_type == 3){
								return this.$Message.error('请选择有效的售后单号');
							}else{
								return this.$Message.error('请选择商品');
							}
						}
						let numArray = [];
						for(let i = 0; i < this.goodsData.length; i++){
							if(this.goodsData[i].inbound<=0 && ['1','4'].indexOf(this.formValidate.order_type) !=-1){
								return this.$Message.error('请填写入库数量');
							}
							if(this.goodsData[i].goodProduct<=0 && this.goodsData[i].spoiledGoods<=0 && ['2','3','6'].indexOf(this.formValidate.order_type) !=-1){
								return this.$Message.error('良品与残次品入库数量不能同时为0');
							}
							if(this.formValidate.order_type == 3 && (parseInt(this.goodsData[i].goodProduct) + parseInt(this.goodsData[i].spoiledGoods)) > this.goodsData[i].stock){
								return this.$Message.error('残次品+良品数量小于等于可入库数量');
							}
							numArray.push({
								product_id:this.goodsData[i].product_id,
								unique:this.goodsData[i].unique,
								in:this.goodsData[i].inbound,
								stock:this.goodsData[i].goodProduct,
								defective_stock:this.goodsData[i].spoiledGoods
							})
						}
						this.formValidate.in_product_detail = numArray;
						if(this.formValidate.order_type == 3){
							this.formValidate.refund_order_id = this.refundId;
						}
						inventoryAddApi(this.formValidate).then(res=>{
							this.openSubimit = true;
							this.$Message.success(res.msg);
							this.$router.push({
								path: this.roterPre + "/inbound/manage"
							});
						}).catch(err=>{
							this.openSubimit = false;
							return this.$Message.error(err.msg);
						})
					} else {
						this.$Message.error("请完善信息");
					}
				});
			},
		},
	};
</script>

<style scoped lang="stylus">
	/deep/.vxe-table--render-default .vxe-cell {
		font-size: 12px;
	}
	.tips {
	  font-size: 12px;
	  color: #999999;
	}
	.form-submit {
		/deep/.ivu-card {
			border-radius: 0;
		}

		margin-bottom: 79px;

		.fixed-card {
			position: fixed;
			right: 0;
			bottom: 0;
			left: 200px;
			z-index: 99;
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
	}

	.after-line {
		display: inline-block;
		position: relative;
		margin-right: 16px;
	}

	.ml16 {
		margin-left: 16px;
	}
</style>