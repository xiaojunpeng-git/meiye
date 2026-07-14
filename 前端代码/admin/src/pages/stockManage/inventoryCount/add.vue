<template>
	<div class="form-submit">
		<div class="i-layout-page-header">
			<PageHeader class="product_tabs" hidden-breadcrumb>
				<div slot="title">
					<router-link :to="{ path: `${roterPre}/inventory/count` }">
						<div class="font-sm after-line">
							<span class="iconfont iconfanhui"></span>
							<span class="pl10">返回</span>
						</div>
					</router-link>
					<span
					  v-text="$route.params.id>0 ? '编辑盘点单' : '添加盘点单'"
					  class="mr20 ml16"
					></span>
				</div>
			</PageHeader>
		</div>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<Form class="formValidate mt20" ref="formValidate" :model="formValidate"
				:label-width="labelWidth" :label-position="labelPosition" @submit.native.prevent>
				<FormItem label="备注：" prop="remark">
					<Input v-model="formValidate.remark" placeholder="请输入备注" v-width="'50%'"></Input>
				</FormItem>
				<FormItem label="盘点商品：" prop="" required>
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
									<Poptip :ref="'popoverRef_' + item.slot" placement="top" transfer v-if="['count_stock','count_defective_stock'].indexOf(item.slot) !=-1">
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
								<template v-if="item.slot === 'count_stock'">
								  <InputNumber
								    :controls="false"
								    v-model="row.count_stock"
								    :min="0"
								    :max="9999999999"
								    class="priceBox"
									:precision="0"
									@on-change="goodsChange"
								  ></InputNumber>
								</template>
								<template v-if="item.slot === 'change_stock'">
								  <div v-if="row.change_stock>0" class="text-wlll-39c15b">{{row.change_stock}}</div>
								  <div v-else-if="row.change_stock<0" class="red">{{row.change_stock}}</div>
								  <div v-else>{{row.change_stock}}</div>
								</template>
								<template v-if="item.slot === 'count_defective_stock'">
								  <InputNumber
								    :controls="false"
								    v-model="row.count_defective_stock"
								    :min="0"
								    :max="9999999999"
								    class="priceBox"
									:precision="0"
									@on-change="defectiveChange"
								  ></InputNumber>
								</template>
								<template v-if="item.slot === 'change_defective_stock'">
								  <div v-if="row.change_defective_stock>0" class="text-wlll-39c15b">{{row.change_defective_stock}}</div>
								  <div v-else-if="row.change_defective_stock<0" class="red">{{row.change_defective_stock}}</div>
								  <div v-else>{{row.change_defective_stock}}</div>
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
					<Button :disabled="openSubimit" class="submission" @click="save">保存草稿</Button>
					<Button type="primary" :disabled="openSubimit" class="submission ml-10" @click="handleSubmit('formValidate')">完成盘点</Button>
				</FormItem>
			</Form>
		</Card>
		<Modal v-model="sattrModals" title="商品列表" footerHide  class="paymentFooter" scrollable width="900" @on-cancel="cancel">
		  <goods-attr :chooseType="94"  ref="goodSattr" v-if="sattrModals" @getProductId="getAtterId"></goods-attr>
		</Modal>
	</div>
</template>

<script>
	import {
		mapState,
		mapMutations
	} from "vuex";
	import {
		inventoryCountApi,
		productCountInfoApi
	} from "@/api/stockManage";
	import Setting from "@/setting";
	import goodsAttr from '@/components/goodsAttr';
	import {
	  inventoryCount
	} from '../components/tableName.js';
	export default {
		name: "inventoryCountAdd",
		components: {
		  goodsAttr
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
				formValidate: {
					remark: ""
				},
				tableHeader: inventoryCount,
				goodsData: [],
				batchNum: 0,
				sattrModals: false,
				goodsIds: [], //用作批量删除
				id:0, //盘点id
				openSubimit:false
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
			this.id = this.$route.params.id || 0
			if(this.id>0){
				this.productCountInfo();
			}
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
			productCountInfo(){
				productCountInfoApi(this.id).then(res=>{
					this.formValidate.remark = res.data.remark;
					res.data.product_detail.forEach(item=>{
						item.store_names = item.product_name;
						item.store_name = item.sku;
						if(item.count_defective_stock==-1){
							item.count_defective_stock = null;
							item.change_defective_stock = '-';
						}
						if(item.count_stock == -1){
							item.count_stock = null;
							item.change_stock = '-'
						}
					})
					this.goodsData = res.data.product_detail;
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			goodsChange(e){
				this.goodsData.forEach(item=>{
					let stock = this.$computes.Sub(item.count_stock,item.stock);
					item.change_stock = isNaN(stock)? '-':stock;
				})
				this.$refs.xTable.refreshColumn();
			},
			defectiveChange(e){
				this.goodsData.forEach(item=>{
					let stock = this.$computes.Sub(item.count_defective_stock,item.defective_stock);
					item.change_defective_stock = isNaN(stock)? '-':stock;
				})
				this.$refs.xTable.refreshColumn();
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
			    i.count_stock = null;
			    i.count_defective_stock = null;
				i.change_stock = '-';
				i.change_defective_stock = '-';
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
					let stock = this.$computes.Sub(item.count_stock,item.stock);
					item.change_stock = isNaN(stock)? '-':stock;
					let defectiveStock = this.$computes.Sub(item.count_defective_stock,item.defective_stock);
					item.change_defective_stock = isNaN(defectiveStock)? '-':defectiveStock;
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
			inventoryCount(status){
				if(!this.goodsData.length){
					return this.$Message.error('请选择商品');
				}
				let numArray = [];
				for(let i = 0; i < this.goodsData.length; i++){
					if((isNaN(this.goodsData[i].count_stock) || isNaN(this.goodsData[i].count_defective_stock)) && status){
						return this.$Message.error('良品与残次品盘点不可以为空');
					}
					numArray.push({
						product_id:this.goodsData[i].product_id,
						unique:this.goodsData[i].unique,
						stock:this.goodsData[i].stock,
						count_stock:this.goodsData[i].count_stock,
						defective_stock:this.goodsData[i].defective_stock,
						count_defective_stock:this.goodsData[i].count_defective_stock
					})
				}
				this.formValidate.status = status;
				this.formValidate.product_detail = numArray;
				inventoryCountApi(this.id,this.formValidate).then(res=>{
					this.openSubimit = true;
					this.$Message.success(res.msg);
					this.$router.push({
						path: this.roterPre + "/inventory/count"
					});
				}).catch(err=>{
					this.openSubimit = false;
					return this.$Message.error(err.msg);
				})
			},
			save(){
				this.inventoryCount(0);
			},
			handleSubmit(name) {
				this.$refs[name].validate((valid) => {
					if (valid) {
						this.inventoryCount(1)
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