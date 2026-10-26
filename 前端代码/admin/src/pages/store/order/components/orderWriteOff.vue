<template>
	<div>
	<Modal v-model="modals" scrollable title="订单核销" width="1000" class="order_box" @on-cancel="cancel" footer-hide>
		<Card :bordered="false" dis-hover>
			<Table :columns="columns" border ref="selection" :data="writeOffData" :loading="loading" no-data-text="暂无数据"
				highlight-row @on-selection-change="selectOne" no-filtered-data-text="暂无筛选结果">
				<template slot-scope="{ row, index }" slot="image">
					<div class="product-data">
						<img class="image" :src="row.cart_info.productInfo.image" />
						<div class="name line2">
							{{ row.cart_info.productInfo.store_name }}
						</div>
					</div>
				</template>
				<template slot-scope="{ row, index }" slot="value">
					<div>{{ row.cart_info.productInfo.attrInfo.suk }}</div>
				</template>
				<template slot-scope="{ row, index }" slot="sellPrice">
					<div>
						{{ row.cart_info.productInfo.attrInfo?row.cart_info.productInfo.attrInfo.price:row.cart_info.productInfo.price }}
					</div>
				</template>
				<template slot-scope="{ row, index }" slot="price">
					<div>{{ row.cart_info.truePrice }}</div>
				</template>
				<template slot-scope="{ row, index }" slot="writeOff">
					<div v-if="row.is_writeoff">已核销</div>
					<div v-else style="color: #F5222D;">待核销</div>
				</template>
				<template slot-scope="{ row, index }" slot="cartNum">
					<!--                     <div>{{ row.cart_info.cart_num }}</div>-->
					<div>{{ row.write_times }}</div>
				</template>
			</Table>
			<Button type="primary" @click="submitWriteOff">确认核销</Button>
		</Card>
	</Modal>
	<writeOffDebtRepay ref="debtRepay" @repaid="onDebtRepaid" />
	</div>
</template>

<script>
	import {
		writeOffList,
		putWriteUpdate
	} from '@/api/order';
	import writeOffDebtRepay from './writeOffDebtRepay';
	export default {
		name: 'orderRecord',
		components: { writeOffDebtRepay },
		props: {
			orderNumId: String
		},
		data() {
			return {
				modals: false,
				loading: false,
				writeOffData: [],
				writeOffItem: [],
				orderOid: 0,
				debtLimitMsg: '可用次数已用完，请补交欠款',
				columns: [{
						type: "selection",
						width: 50,
						align: "center",
					},
					{
						title: "商品信息",
						slot: "image",
						align: "center",
						minWidth: 150,
					},
					{
						title: "规格",
						slot: "value",
						align: "center",
						minWidth: 90,
					},
					{
						title: "商品售价",
						slot: "sellPrice",
						align: "center",
						minWidth: 60,
					},
					{
						title: "商品优惠价",
						slot: "price",
						align: "center",
						minWidth: 60,
					},
					{
						title: "状态",
						slot: "writeOff",
						align: "center",
						minWidth: 30,
					},
					{
						title: "总数",
						slot: "cartNum",
						align: "center",
						minWidth: 30,
					},
					{
						title: "已核销",
						key: "writeoffed_num",
						align: "center",
						minWidth: 30,
					},
					{
						title: "待核销数量",
						key: "surplus_num",
						align: "center",
						minWidth: 130,
						render: (h, params) => {
							return h("div", [
								h("InputNumber", {
									props: {
										min: 1,
										max: params.row.numShow,
										// value: params.row.surplus_num || 1,
										value: params.row.product_type == 4?params.row.surplus_num:(params.row.surplus_num || 0),
										disabled: (!params.row.numShow || params.row
											.is_writeoff) ? true : false
									},
									on: {
										"on-change": (e) => {
											params.row.surplus_num = e || 1;
											if (this.writeOffItem.length) {
												this.writeOffItem.forEach((item) => {
													if (item.id == params.row.id) {
														item.surplus_num = params.row
															.surplus_num
													} else {
														this.writeOffItem.push(params.row)
													}
												})
											} else {
												this.writeOffItem.push(params.row)
											}
											this.selectData.forEach((v, index) => {
												if (v.cart_id === params.row.cart_id) {
													this.selectData.splice(index, 1, params
														.row);
												}
											});
										},
									},
								}),
							]);
						},
					}
				],
				selectData: []
			}
		},
		methods: {
			cancel() {
				this.$refs.selection.selectAll(false);
			},
			selectOne(data) {
				data.forEach((item) => {
					this.writeOffItem.forEach((j) => {
						if (item.id == j.id) {
							item.surplus_num = j.surplus_num
						}
					})
				})
				this.selectData = data;
			},
			// 核销列表
			getWriteOff(data) {
				this.orderOid = data.oid || 0;
				writeOffList(data).then(res => {
					let newObject = {
						title: "待服务",
						key: "unservice_num",
						align: "center",
						minWidth: 30
					}
					let index = this.columns.findIndex(item => item.key === newObject.key);
					if (index !== -1) {
						this.columns.splice(index, 1)
					}
					if (res.data.product_type == 6 || res.data.product_type==5) {
						let newIndex = this.columns.length - 1;
						this.columns.splice(newIndex, 0, newObject);
					}
					let data = res.data.cart_info;
					data.forEach((item) => {
						const effective = item.effective_write_surplus_times != null
							? Number(item.effective_write_surplus_times)
							: Number(item.write_surplus_times || item.surplus_num || 0);
						item.numShow = effective;
						if (item.product_type == 4 && effective > 0) {
							item.surplus_num = 1;
						} else if (effective > 0) {
							item.surplus_num = Math.min(Number(item.surplus_num || effective), effective);
						} else {
							item.surplus_num = 0;
						}
						if (item.is_writeoff || effective <= 0) {
							item._disabled = true
						}
					})
					this.writeOffData = data;
					this.writeOffItem = [];
				}).catch(err => {
					this.$Message.error(res.msg);
				})
			},
			submitWriteOff() {
				if (!this.selectData.length) {
					return this.$Message.error('请选择要核销的商品');
				}
				if (this.checkDebtWriteoffBlocked()) {
					return;
				}
				let cartIds = []
				this.selectData.forEach((v) => {
					cartIds.push({
						cart_id: v.cart_id,
						cart_num: v.surplus_num,
					});
				});
				putWriteUpdate(this.orderNumId, {
					'cart_ids': cartIds
				}).then(res => {
					this.$Message.success(res.msg);
					this.modals = false;
					this.$refs.selection.selectAll(false);
					this.$emit("submitSuccess");
				}).catch(err => {
					if ((err.msg || '') === this.debtLimitMsg) {
						this.showDebtRepayConfirm();
						return;
					}
					this.$Message.error(err.msg);
				})
			},
			checkDebtWriteoffBlocked() {
				const blocked = this.selectData.some((row) => {
					const effective = row.numShow != null ? Number(row.numShow) : Number(row.effective_write_surplus_times || 0);
					const num = Number(row.surplus_num || 0);
					return num > effective || (effective <= 0 && Number(row.write_surplus_times || 0) > 0);
				});
				if (blocked) {
					this.showDebtRepayConfirm();
					return true;
				}
				return false;
			},
			showDebtRepayConfirm() {
				this.$Modal.confirm({
					title: '提示',
					content: this.debtLimitMsg,
					okText: '去补交',
					cancelText: '取消',
					onOk: () => {
						if (this.$refs.debtRepay) {
							this.$refs.debtRepay.open(this.orderOid);
						}
					},
				});
			},
			onDebtRepaid() {
				if (this.orderOid) {
					this.getWriteOff({ oid: this.orderOid });
				}
			},
		}
	}
</script>

<style scoped lang="stylus">
	/deep/.ivu-table {
		max-height: 400px;
		overflow-x: hidden !important;
		overflow-y: auto !important;
	}

	/deep/.ivu-table-overflowX {
		overflow-x: hidden !important;
	}

	.order_box /deep/.ivu-btn {
		display: block;
		margin: 20px auto 0 auto;
		padding: 0 50px;
	}

	.order_box>>>.ivu-table th {
		background: #f8f8f9 !important;
	}

	.product-data {
		display: flex;
		align-items: center;
	}

	.product-data .name {
		line-height: 1.4;
		text-align: left;
		margin-left: 5px;
	}

	.product-data .image {
		width: 50px !important;
		height: 50px !important;
	}
</style>