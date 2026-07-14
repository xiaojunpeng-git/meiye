<template>
	<Modal v-model="modals" scrollable title="库存管理" width="800" class="order_box" @on-cancel="cancel" footer-hide>
		<Card :bordered="false" dis-hover class="cards">
			<Alert type="warning" show-icon class="mt10" v-if="goodsType!=6">完成出入库后，系统将自动生成一笔其他入库 / 出库单据，可于库存管理模块查看</Alert>
			<div class="batch" v-if="specType && goodsType!=6">
				<div class="name" @click="batchTap">批量<span class="iconfont iconxiayi"></span></div>
				<div class="input acea-row row-center-wrapper" v-if="batchShow">
					<Input v-model="batchStock" type='number' style="width: 150px;" @on-change="inputTap">
					<Select v-model="batchPm" slot="append" style="width: 60px" @on-change="batchStockTap">
						<Option :value="1">入库</Option>
						<Option :value="0">出库</Option>
					</Select>
					</Input>
				</div>
			</div>
			<Table :columns="goodsType==6?reservationColumns:columns" border ref="selection" :data="stockData" :loading="loading" no-data-text="暂无数据"
				highlight-row no-filtered-data-text="暂无筛选结果" max-height="450">
				<template slot-scope="{ row, index }" slot="image">
					<div class="product-data">
						<img class="image" :src="row.image" />
					</div>
				</template>
				<template slot-scope="{ row, index }" slot="time">
					<div v-for="(item,jindex) in row.reservationTimeData" :key="jindex" class="time h-32 lh-32px">{{item.show_time}}</div>
				</template>
				<template slot-scope="{ row, index }" slot="timeStock">
					<div v-for="(item,jindex) in row.reservationTimeData" :key="jindex" class="time h-32 lh-32px">{{item.stock}}</div>
				</template>
				<template slot-scope="{ row, index }" slot="timeNum">
					<InputNumber v-for="(item,jindex) in row.reservationTimeData" :key="jindex" class="time w-100 fs-12 mr14" :max="9999999999" :min="0" :precision='0' v-model="stockData[index].reservationTimeData[jindex].num"/>
				</template>
				<template slot-scope="{ row, index }" slot="servicePrice">
					<InputNumber v-for="(item,jindex) in row.reservationTimeData" :key="jindex" class="time w-100 fs-12 mr14" :max="9999999999" :min="0" v-model="stockData[index].reservationTimeData[jindex].service_price"/>
				</template>
				<template slot-scope="{ row, index }" slot="num">
					<div class="acea-row row-middle">
						<Input v-model="row.changeNum" type='number' style="width: 150px;" @on-change="changeTap(row)">
						<Select v-model="row.pm" slot="append" style="width: 60px" @on-change="stockTap(row)">
							<Option :value="1">入库</Option>
							<Option :value="0">出库</Option>
						</Select>
						</Input>
						<span class="ml20">={{row.resultNum}}</span>
					</div>
				</template>
			</Table>
			<div class="footer acea-row row-right">
				<Button class="mr" @click="cancel">取消</Button>
				<Button type="primary" @click="productSaveStocks">提交</Button>
			</div>
		</Card>
	</Modal>
</template>

<script>
	import {
		productAttrsApi,
		productSaveStocksApi
	} from '@/api/product.js'
	export default {
		name: 'stockEdit',
		props: {
		  goodsType:{
		     type:Number,
		     default:0
		  }
		},
		data() {
			return {
				id: 0,
				specType: 0,
				batchShow: false,
				batchStock: 0,
				batchPm: 1,
				modals: false,
				loading: false,
				stockData: [],
				columns: [{
						title: "图片",
						slot: "image",
						minWidth: 20
					},
					{
						title: "产品规格",
						key: "suk",
						minWidth: 90
					},
					{
						title: "商品条形码",
						key: "bar_code",
						minWidth: 35
					},
					{
						title: "商品编码",
						key: "code",
						minWidth: 35
					},
					{
						title: "当前库存",
						key: "stock",
						minWidth: 10
					},
					{
						title: "入/出库数量",
						slot: "num",
						minWidth: 200
					}
				],
				timeInputNumberValue:0, //批量设置单规格库存值
				tooltipVisible:false,//控制气泡的显示和隐藏
				reservationColumns:[
					{
						title: "图片",
						slot: "image",
						minWidth: 90
					},
					{
						title: "产品规格",
						key: "suk",
						minWidth: 90
					},
					{
						title: "时间段",
						slot: "time",
						minWidth: 90
					},
					{
						title: "当前预约数量",
						slot: "timeStock",
						minWidth: 90
					},
					{
						title: "预约数量",
						slot: "timeNum",
						minWidth: 90,
						renderHeader: (h, row) => {
							return h('div', [
							  h('Poptip',{
								props:{
									transfer:true,
									placement: 'top',
									trigger:'click',
									value: this.tooltipVisible,
								},
								on: {
									'on-popper-hide': () => {
										this.tooltipVisible = false; // 气泡隐藏时设置 visible 为 false
									}
								},
								scopedSlots:{
									default: () => h('span', {
										on: {
										  click: ($event) => {
											$event.stopPropagation();
										    this.tooltipVisible = true; // 点击单元格时显示气泡
											this.timeInputNumberValue = 0;
										  },
										}
									},[
										h('span', '预约数量'),
										h('span', {
											class: ['iconfont iconbianji11'],
											style: {
											  marginLeft: '6px',
											  color:'#AAAAAA',
											  fontSize:'12px'
											}
										})
									]),
								  content:()=>h('div',[
									h('div',{
										class:['fs-12 text-wlll-515A6E mb-12']
									},'批量修改'),
									h('InputNumber',{
										props:{
										  min:0,
										  max:99999999,
										  precision:0,
										  value: this.timeInputNumberValue
										},
										class:['w-85'],
										 on: {
											 'on-change': (value) => {
											   this.timeInputNumberValue = value;
											 }
										 }
									}),
									h('Button',{
										props:{
										  size:'small',
										},
										class:['ml-8'],
										on: {
										  click: () => {
											this.tooltipVisible = false;
										  }
										}
									},'取消'),
									h('Button',{
										props:{
										  type:'primary',
										  size:'small'
										},
										class:['ml-8'],
										on: {
										  click: () => {
											this.tooltipVisible = false;
										    this.handleButtonClick();
										  }
										}
									},'确定'),
								  ])
								},
							  })
							]);
						}
					},
					// {
					// 	title: "服务费",
					// 	slot: "servicePrice",
					// 	minWidth: 90
					// },
				]
			}
		},
		methods: {
			// 单规格批量设置库存;
			handleButtonClick(){
				this.stockData.forEach(item=>{
					item.reservationTimeData.forEach(j=>{
						j.num = this.timeInputNumberValue;
					})
				})
			},
			// 批量设置；
			countBatch() {
				this.batchStock = Math.abs(this.batchStock);
				this.stockData.forEach(item => {
					item.changeNum = this.batchStock;
					if (this.batchPm) {
						item.pm = 1;
						item.resultNum = parseInt(item.stock) + parseInt(item.changeNum);
					} else {
						item.pm = 0;
						if (parseInt(item.stock) <= 0) {
							item.resultNum = 0
						} else {
							let num = parseInt(item.stock) - parseInt(item.changeNum)
							item.resultNum = num <= 0 ? 0 : num;
						}
					}
				})
			},
			// 批量加减库存
			inputTap() {
				this.batchStock = this.batchStock.replace(/^\d{10}$/g, '0')
				this.countBatch();
			},
			// 批量设置入库或是出库
			batchStockTap() {
				this.countBatch();
			},
			// 单个设置
			countStock(row) {
				if (row.pm) {
					row.resultNum = parseInt(row.stock) + parseInt(row.changeNum);
				} else {
					if (parseInt(row.stock) <= 0) {
						row.resultNum = 0;
					} else {
						let num = parseInt(row.stock) - parseInt(row.changeNum)
						row.resultNum = num <= 0 ? 0 : num;
					}
				}
				this.stockData.forEach(item => {
					if (row.id == item.id) {
						item.changeNum = row.changeNum;
						item.resultNum = row.resultNum;
						item.pm = row.pm;
					}
				})
			},
			// 设置加减库存
			stockTap(row) {
				this.countStock(row);
			},
			// 设置入库或是出库
			changeTap(row) {
				row.changeNum = row.changeNum.replace(/^\d{10}$/g, "0")
				this.countStock(row);
			},
			batchTap() {
				this.batchShow = !this.batchShow;
			},
			productAttrs(data) {
				this.specType = data.spec_type;
				this.id = data.id;
				productAttrsApi(data.id).then(res => {
					let data = res.data;
					data.forEach(item => {
						item.resultNum = item.stock;
						item.changeNum = 0;
						item.pm = 1;
						if(item.reservationTimeData){
						   item.reservationTimeData.forEach(j=>{
							   j.num = j.stock
						   })
						}
					})
					this.stockData = data;
				}).catch(err => {
					this.$Message.error(err.msg);
				})
			},
			productSaveStocks() {
				let attrs = [];
				this.stockData.forEach(item => {
					let data = {
						"unique": item.unique,
						"pm": item.pm,
						"stock": item.changeNum
					}
					if(item.reservationTimeData){
					   data.reservation_time_data = [];
					   item.reservationTimeData.forEach(j=>{
						   let obj = {
							   id:j.id,
							   stock:j.num,
							   service_price:j.service_price
						   }
						  data.reservation_time_data.push(obj) 
					   })
					}
					attrs.push(data)
				})
				productSaveStocksApi({
					attrs: attrs
				}, this.id).then(res => {
					this.$Message.success('修改成功');
					this.cancel();
					this.$emit('stockChange', res.data.stock)
				}).catch(err => {
					this.$Message.error(err.msg);
				})
			},
			cancel() {
				this.modals = false;
				this.batchShow = false;
				this.batchPm = 1;
				this.batchStock = 0;
			}
		}
	}
</script>

<style lang="stylus" scoped>
	/deep/.ivu-alert{
		margin-bottom: 10px !important;
	}
	/deep/.ivu-alert-warning .ivu-alert-icon{
		margin-top: 1px;
	}
	/*定义滑块 内阴影+圆角*/
	/deep/::-webkit-scrollbar-thumb {
		-webkit-box-shadow: inset 0 0 6px #999;
	}

	/deep/::-webkit-scrollbar {
		width: 4px !important;
		/*对垂直流动条有效*/
	}
	
	.time~.time{
		margin-top: 10px;
	}

	.footer {
		margin-top 20px;
	}

	.product-data .image {
		width: 50px !important;
		height: 50px !important;
	}

	.cards {
		position relative;
	}

	.batch {
		position absolute;
		right -20px;
		z-index 9;
		top: 88px;
		width: 176px;

		.input {
			width: 176px;
			height: 56px;
			background: #FFFFFF;
			box-shadow: 0px 2px 6px 0px rgba(0, 0, 0, 0.05);
			border-radius: 4px;
			margin-left -80px;
		}

		.name {
			font-size 13px;
			color: #1890FF;
			margin-bottom 10px;
			cursor pointer;

			.iconfont {
				font-size 12px;
				margin-left 4px;
			}
		}
	}

	/deep/.ivu-select-single .ivu-select-selection .ivu-select-placeholder,
	/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value {
		font-size 13px !important;
	}

	/deep/.ivu-input:focus {
		border-color #dcdee2 !important;
		box-shadow unset !important;
	}

	/deep/.ivu-input:hover {
		border-color #dcdee2 !important;
	}

	/deep/.ivu-input {
		border-right 0 !important;
		transition: unset !important;
	}

	/deep/.ivu-input-group-append {
		background-color #fff !important;
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

	/deep/.ivu-modal-body {
		padding-top 0 !important;
	}

	.ivu-table-wrapper-with-border {
		border 0 !important;
	}

	/deep/.ivu-table-border th,
	/deep/.ivu-table-border td {
		border-right 0 !important;
	}
</style>