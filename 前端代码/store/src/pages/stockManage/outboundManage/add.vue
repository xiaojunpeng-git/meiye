<template>
	<div class="form-submit">
		<div class="i-layout-page-header">
			<PageHeader class="product_tabs" hidden-breadcrumb>
				<div slot="title">
					<router-link :to="{ path: `${routePre}/outbound/manage` }">
						<div class="font-sm after-line">
							<span class="iconfont iconfanhui"></span>
							<span class="pl10">返回</span>
						</div>
					</router-link>
					<span class="mr20 ml16">添加出库单</span>
				</div>
			</PageHeader>
		</div>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<Form class="formValidate mt20" ref="formValidate" :rules="ruleValidate" :model="formValidate"
				:label-width="labelWidth" :label-position="labelPosition" @submit.native.prevent>
				<FormItem label="出库类型：" prop="order_type" label-for="order_type">
					<RadioGroup v-model="formValidate.order_type" @on-change='storeType'>
					  <Radio v-for="(item, index) in orderList" :key="item.id" :label="item.id">{{ item.name }}</Radio>
					</RadioGroup>
					<div class="tips" v-for="(item, index) in orderList" :key="item.id" v-if="item.id == active">{{item.des}}</div>
				</FormItem>
				<FormItem label="出库日期：" prop="stock_time">
					<DatePicker v-model="formValidate.stock_time" type="date" :options="options" placeholder="请选择入库日期" v-width="'50%'"/>
				</FormItem>
				<FormItem label="备注：" prop="remark">
					<Input v-model="formValidate.remark" placeholder="请输入备注" v-width="'50%'"></Input>
				</FormItem>
				<FormItem label="出库商品：" prop="" required>
					<div>
						<Button type="primary" class="mr-15" @click="addGoodsSattr">选择商品</Button>
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
											<div class="mt-14 acea-row row-between-wrapper flex-nowrap">
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
		<Card :bordered="false" dis-hover class="fixed-card">
			<Form>
				<FormItem>
					<Button type="primary" :disabled="openSubimit" class="submission" @click="handleSubmit('formValidate')">保存</Button>
				</FormItem>
			</Form>
		</Card>
		<selectGoodsBox v-model="sattrModals" @getProductId="getAtterId" @on-cancel="cancel"></selectGoodsBox>
	</div>
</template>

<script>
	import {
		mapState,
		mapMutations
	} from "vuex";
	import {
		outventoryAddApi
	} from "@/api/stockManage";
	import Setting from "@/setting";
	import selectGoodsBox from '@/components/selectGoodsBox';
	import {
	  outGoods,
	  outGoodProduct,
	} from '../components/tableName.js';
	import { formatDate } from '@/utils/validate';
	export default {
		name: "outboundAdd",
		components: {
		  selectGoodsBox
		},
		data() {
			return {
				routePre: Setting.routePre,
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
						id: '2',
						name: '过期退货',
						des: '已出库的商品因超过保质期无法正常使用，被退回仓库并按规定后续处置的业务'
					},
					{
						id: '3',
						name: '试用出库',
						des: '为满足产品试用需求，将仓库商品发出给客户或相关方的出库业务'
					},
					{
						id: '4',
						name: '报废出库',
						des: '仓库中因丧失使用价值、存在质量缺陷或达到报废标准的商品，从库存中发出并进行专门报废处置的出库业务'
					},
					{
						id: '5',
						name: '良品转残次品',
						des: '因使用损坏、质量异常等原因不能作为正常商品售卖，转为残次品管理并对应减少库存数量的业务'
					},
					{
						id: '6',
						name: '其他出库',
						des: '除常规的出库方式之外的特殊出库情况。调拨出库由调拨确认自动生成，不可在此手工创建。'
					}
				],
				formValidate: {
					order_type: "2",
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
				tableHeader: outGoods,
				goodsData: [],
				batchNum: 0,
				sattrModals: false,
				goodsIds: [], //用作批量删除
				openSubimit:false,
				active:'2'
			};
		},
		computed: {
			...mapState("store/layout", ["isMobile", "menuCollapse"]),
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
			...mapMutations("store/layout", ["setCopyrightShow"]),
			storeType(e){
				this.active = e;
				this.tableHeader = [];
				let header = []
				if(e==5){
					header = outGoodProduct
				}else{
					header = outGoods
				}
				let that = this;
				setTimeout(function(){
					that.tableHeader = header;
				})
			},
			addGoodsSattr(){
				this.sattrModals = true;
			},
			cancel(){
				this.sattrModals = false;
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
							return this.$Message.error('请选择商品');
						}
						let numArray = [];
						for(let i = 0; i < this.goodsData.length; i++){
							if(this.goodsData[i].goodProduct<=0 && this.formValidate.order_type ==5){
								return this.$Message.error('请填写良品出库数量');
							}
							if(this.goodsData[i].goodProduct<=0 && this.goodsData[i].spoiledGoods<=0 && this.formValidate.order_type !=5){
								return this.$Message.error('良品与残次品出库数量不能同时为0');
							}
							numArray.push({
								product_id:this.goodsData[i].product_id,
								unique:this.goodsData[i].unique,
								stock:this.goodsData[i].goodProduct,
								defective_stock:this.goodsData[i].spoiledGoods
							})
						}
						this.formValidate.out_product_detail = numArray;
						outventoryAddApi(this.formValidate).then(res=>{
							this.openSubimit = true;
							this.$Message.success(res.msg);
							this.$router.push({
								path: this.routePre + "/outbound/manage"
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
	.w-85{
		width: 85px !important;
	}
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
			left: 220px;
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