<template>
	<Modal
	    width="600"
	    v-model="modal"
	    title="修改价格">
	    <Form v-if="specType==0" ref="formData" :model="formData" :rules="ruleValidate" :label-width="120" @submit.native.prevent>
			<FormItem label="售价：">
				￥{{singleSpecData.price}}
			</FormItem>
			<FormItem label="调价区间：">
				<div class="text--w111-999" v-if="singleSpecData.price_range_status == 0">
					不支持调价
				</div>
				<div v-else>￥{{singleSpecData.price_range_min}} ~ ￥{{singleSpecData.price_range_max}}</div>
			</FormItem>
			<FormItem label="修改售价：" prop="price">
				<div class="text--w111-999" v-if="singleSpecData.price_range_status == 0">
					不支持调价
				</div>
				<div v-else>
					<InputNumber
					  v-model="formData.price"
					  :min="0"
					  :max="99999999"
					  v-width="'150'"
					></InputNumber>
					<span class="ml-10">元</span>
				</div>
			</FormItem>
	    </Form>
		<Table v-else border :columns="columns" :data="specData" no-data-text="暂无数据"
			highlight-row no-filtered-data-text="暂无筛选结果" max-height="450">
			<template slot-scope="{ row, index }" slot="price">
				{{row.price}}
			</template>
			<template slot-scope="{ row, index }" slot="priceInterval">
				<div v-if="row.price_range_status == 0" class="text--w111-999">不支持调价</div>
				<div v-else>
				  <span v-if="row.price_range_min>=0 && row.price_range_max==0"> {{row.price_range_min}} ~ 不限制</span>
				  <span v-else>{{row.price_range_min}} ~ {{row.price_range_max}}</span>
				</div>
			</template>
			<template slot-scope="{ row, index }" slot="priceChange">
				<div v-if="row.price_range_status == 0" class="text--w111-999">不支持调价</div>
				<div v-else>
					<InputNumber class="time w-100 fs-12 mr14" :max="9999999999" :min="0" v-model="specData[index].priceChange"/>
					<div class="fs-12 text-wlll-f5222d mt-3" v-if="
					row.priceChange < 0 || 
					(row.price_range_max>0 && row.priceChange>row.price_range_max) || 
					row.priceChange<row.price_range_min">
					   售价需在调价区间内
					</div>
				</div>
			</template>
		</Table>
	    <div slot="footer">
	      <Button @click="close">取消</Button>
	      <Button type="primary" @click="handleSubmit('formData')">确定</Button>
	    </div>
	</Modal>
</template>

<script>
	import { productSavePrice, productAttrsApi } from '@/api/product';
	export default {
	    name: 'adjustPrice',
	    data () {
	        return {
				columns: [
					{
						title: "规格名称",
						key: "suk",
						minWidth: 70
					},
					{
						title: "售价（元）",
						slot: "price",
						minWidth: 10
					},
					{
						title: "调价区间（元）",
						slot: "priceInterval",
						minWidth: 70
					},
					{
						title: "修改售价（元）",
						slot: "priceChange",
						minWidth: 20
					}
				],
				ruleValidate: {
					price: [
						{
							validator: (rule, value, callback) => {
							  if(this.formData.price<0 || (this.formData.price>this.singleSpecData.price_range_max && this.singleSpecData.price_range_max>0) || this.formData.price<this.singleSpecData.price_range_min){
								callback(new Error('售价需在调价区间内'));
							  }else {
							    callback();
							  }
							},
							required: true,
							trigger: 'blur'
						}
					]
				},
				formData: {
					price:0
				},
	            modal: false,
				id: 0,
				specType: 0,
				singleSpecData: {},
				specData: [],
	        }
	    },
	    methods: {
			productAttrs(data) {
				this.specType = data.spec_type;
				this.id = data.id;
				productAttrsApi(data.id).then(res => {
					let data = res.data;
					data.forEach(item=>{
						item.priceChange = parseFloat(item.price);
					})
					this.formData.price = parseFloat(data[0].price);
					this.singleSpecData = data[0];
					this.specData = data;
				}).catch(err => {
					this.$Message.error(err.msg);
				})
			},
			close(){
				this.modal = false;
			},
			productSave(){
				let attrs = [];
				this.specData.forEach(item=>{
					attrs.push({
						unique:item.unique,
						price:this.specType==0?this.formData.price:item.priceChange
					})
				})
				productSavePrice(this.id,{attrs:attrs}).then(res=>{
					this.$Message.success('修改成功');
					this.modal = false;
					this.$emit('priceChange', res.data.price)
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			handleSubmit(name){
				if(this.specType == 0){
					if(this.singleSpecData.price_range_status == 0){
						this.modal = false;
					}else{
						this.$refs[name].validate((valid) => {
						    if (valid) {
								this.productSave();
						    } else {
						        return false;
						    }
						});
					}
				}else{
					let flag = 0;
					for (let i = 0; i < this.specData.length; i++){
						let item = this.specData[i];
						if(item.price_range_status==1){
							flag = 1
						}
						if(item.price_range_max>0 || item.price_range_min>0){
							if(item.priceChange < 0 || (item.price_range_max>0 && item.priceChange>item.price_range_max) || item.priceChange<item.price_range_min){
								return this.$Message.error('售价需在调价区间内');
							}
						}
					}
					if(flag){
						this.productSave();
					}else{
						this.modal = false;
					}
				}
			}
		}    
	}
</script>

<style lang="stylus" scoped>
	/*定义滑块 内阴影+圆角*/
	/deep/::-webkit-scrollbar-thumb {
		-webkit-box-shadow: inset 0 0 6px #999;
	}
	
	/deep/::-webkit-scrollbar {
		width: 4px !important;
		/*对垂直流动条有效*/
	}
	
	/deep/.ivu-modal-footer {
		border-top: 0;
	}
	
	/deep/.ivu-table {
		overflow unset !important;
	}
	
	/deep/.ivu-table-cell {
		overflow unset !important;
		font-size 13px !important;
	}
	
	/deep/.ivu-table-border:after {
		background-color #fff;
	}
	
	/deep/.ivu-table-header table {
		border-top: 0 !important;
	}
	
	.ivu-table-wrapper-with-border {
		border 0 !important;
	}
	
	/deep/.ivu-table-border th,
	/deep/.ivu-table-border td {
		border-right 0 !important;
	}
</style>