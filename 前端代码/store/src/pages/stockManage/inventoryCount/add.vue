<template>
	<Modal
		:value="value"
		:title="editId > 0 ? '编辑盘点单' : '添加盘点单'"
		width="1274"
		:mask-closable="false"
		:styles="{ top: '40px' }"
		class-name="inventory-count-form-modal"
		@on-cancel="handleClose"
	>
		<Form
			v-if="value"
			class="formValidate"
			ref="formValidate"
			:model="formValidate"
			:label-width="labelWidth"
			:label-position="labelPosition"
			@submit.native.prevent
		>
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
							<template v-if="item.slot === 'count_stock'">
							  <InputNumber
							    :controls="false"
							    v-model="row.count_stock"
							    :min="0"
							    :max="9999999999"
							    class="priceBox"
								:precision="qtyPrecision(row)"
								:step="qtyPrecision(row) > 0 ? 0.01 : 1"
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
								:precision="qtyPrecision(row)"
								:step="qtyPrecision(row) > 0 ? 0.01 : 1"
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
		<div slot="footer">
			<Button @click="handleClose">取消</Button>
			<Button class="ml14" :disabled="openSubimit" @click="save">保存草稿</Button>
			<Button type="primary" class="ml14" :disabled="openSubimit" @click="handleSubmit('formValidate')">完成盘点</Button>
		</div>
		<selectGoodsBox v-model="sattrModals" @getProductId="getAtterId" @on-cancel="cancel"></selectGoodsBox>
	</Modal>
</template>

<script>
	import { mapState } from "vuex";
	import {
		inventoryCountApi,
		productCountInfoApi
	} from "@/api/stockManage";
	import selectGoodsBox from '@/components/selectGoodsBox';
	import {
	  inventoryCount
	} from '../components/tableName.js';
	export default {
		name: "inventoryCountFormModal",
		components: {
		  selectGoodsBox
		},
		props: {
			value: { type: Boolean, default: false },
			editId: { type: Number, default: 0 }
		},
		data() {
			return {
				formValidate: {
					remark: ""
				},
				tableHeader: inventoryCount,
				goodsData: [],
				batchNum: 0,
				sattrModals: false,
				goodsIds: [],
				openSubimit: false
			};
		},
		computed: {
			...mapState("store/layout", ["isMobile"]),
			labelWidth() {
				return this.isMobile ? undefined : 120;
			},
			labelPosition() {
				return this.isMobile ? "top" : "right";
			},
		},
		watch: {
			value(val) {
				if (val) {
					this.openForm();
				}
			}
		},
		methods: {
			handleClose() {
				this.$emit('input', false);
			},
			resetForm() {
				this.formValidate = { remark: "" };
				this.goodsData = [];
				this.batchNum = 0;
				this.sattrModals = false;
				this.goodsIds = [];
				this.openSubimit = false;
				this.$nextTick(() => {
					this.$refs.formValidate && this.$refs.formValidate.resetFields();
				});
			},
			openForm() {
				this.resetForm();
				if (this.editId > 0) {
					this.productCountInfo();
				}
			},
			productCountInfo(){
				productCountInfoApi(this.editId).then(res=>{
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
			qtyPrecision(row) {
				return Number(row && row.decimal_scale) > 0 ? 2 : 0;
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
			  let cleanedValue = value.replace(/[^\d.]/g, '');
			  let parts = cleanedValue.split('.');
			  if (parts.length > 2) {
			    cleanedValue = parts[0] + '.' + parts.slice(1).join('');
			  }
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
					let stock = this.$computes.Sub(item.count_stock,item.stock);
					item.change_stock = isNaN(stock)? '-':stock;
					let defectiveStock = this.$computes.Sub(item.count_defective_stock,item.defective_stock);
					item.change_defective_stock = isNaN(defectiveStock)? '-':defectiveStock;
				});
				this.$refs.xTable.refreshColumn();
				this.closeBatchSet();
			},
			delGoods(row){
				let index = this.goodsData.findIndex(item=> item.id===row.id);
				this.goodsData.splice(index,1)
			},
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
				inventoryCountApi(this.editId, this.formValidate).then(res=>{
					this.openSubimit = true;
					this.$Message.success(res.msg);
					this.$emit('success');
					this.handleClose();
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
	.w-85{
		width: 85px !important;
	}
	/deep/.vxe-table--render-default .vxe-cell {
		font-size: 12px;
	}
	.ml14 {
		margin-left: 14px;
	}
</style>
