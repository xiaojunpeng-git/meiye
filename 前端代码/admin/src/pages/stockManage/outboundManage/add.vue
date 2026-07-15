<template>
	<Modal
		:value="value"
		title="添加出库单"
		width="1274"
		:mask-closable="false"
		:styles="{ top: '40px' }"
		class-name="stock-outbound-form-modal"
		@on-cancel="handleClose"
	>
		<Form
			v-if="value"
			class="formValidate"
			ref="formValidate"
			:rules="ruleValidate"
			:model="formValidate"
			:label-width="labelWidth"
			:label-position="labelPosition"
			@submit.native.prevent
		>
			<FormItem label="出库类型：" prop="order_type" label-for="order_type">
				<RadioGroup v-model="formValidate.order_type" @on-change="storeType">
					<Radio v-for="item in orderList" :key="item.id" :label="item.id">{{ item.name }}</Radio>
				</RadioGroup>
				<div class="tips" v-for="item in orderList" :key="'d' + item.id" v-if="item.id == active">{{ item.des }}</div>
			</FormItem>
			<FormItem label="出库日期：" prop="stock_time">
				<DatePicker
					v-model="formValidate.stock_time"
					type="date"
					:options="options"
					placeholder="请选择出库日期"
					transfer
					style="width: 50%"
				/>
			</FormItem>
			<FormItem label="备注：" prop="remark">
				<Input v-model="formValidate.remark" placeholder="请输入备注" style="width: 50%" />
			</FormItem>
			<FormItem label="出库商品：" prop="" required>
				<div>
					<Button type="primary" class="mr-15" @click="addGoodsSattr">选择商品</Button>
					<Button type="primary" :disabled="!goodsIds.length" @click="batchDel">批量删除</Button>
				</div>
				<vxe-table
					ref="xTable"
					class="mt25"
					:data="goodsData"
					@checkbox-all="selectAllEvent"
					@checkbox-change="selectChangeEvent"
				>
					<vxe-column type="checkbox" width="60"></vxe-column>
					<vxe-column
						v-for="(item, index) in tableHeader"
						:key="index"
						:field="item.title"
						:min-width="item.minWidth || '100'"
						:fixed="item.fixed"
					>
						<template #header>
							<div class="acea-row row-middle">
								<div>{{ item.title }}</div>
								<Poptip
									:ref="'popoverRef_' + item.slot"
									placement="top"
									transfer
									v-if="['goodProduct', 'spoiledGoods', 'inbound'].indexOf(item.slot) != -1"
								>
									<span class="iconfont iconbianji1 fs-12 ml-8"></span>
									<template #content>
										<div class="pop-title">批量设置</div>
										<div class="mt-14 flex-between-center">
											<Input type="number" @on-change="batchSet" class="w-85" v-model="batchNum" />
											<div class="w-132 acea-row row-right row-middle">
												<Button class="ml-10" @click="closeBatchSet(item.slot)">取消</Button>
												<Button type="primary" class="ml-10" @click="batchSetConfirm(item.slot)">确认</Button>
											</div>
										</div>
									</template>
								</Poptip>
							</div>
						</template>
						<template #default="{ row }">
							<template v-if="item.key">{{ row[item.key] }}</template>
							<template v-if="item.slot === 'store_names'">
								<Tooltip :transfer="true" theme="dark" max-width="200" :delay="300" :content="row.store_names">
									<div class="line2">{{ row.store_names }}</div>
								</Tooltip>
							</template>
							<template v-if="item.slot === 'store_name'">
								<Tooltip :transfer="true" theme="dark" max-width="200" :delay="300" :content="row.store_name">
									<div class="line2">{{ row.store_name }}</div>
								</Tooltip>
							</template>
							<template v-if="item.slot === 'goodProduct'">
								<InputNumber :controls="false" v-model="row.goodProduct" :min="0" :max="9999999999" class="priceBox" />
							</template>
							<template v-if="item.slot === 'spoiledGoods'">
								<InputNumber :controls="false" v-model="row.spoiledGoods" :min="0" :max="9999999999" class="priceBox" />
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
			<Button type="primary" class="ml14" :disabled="openSubimit" @click="handleSubmit('formValidate')">保存</Button>
		</div>
		<selectGoodsBox v-model="sattrModals" @getProductId="getAtterId" @on-cancel="cancel"></selectGoodsBox>
	</Modal>
</template>

<script>
	import { mapState } from 'vuex';
	import { outventoryAddApi } from '@/api/stockManage';
	import selectGoodsBox from '@/components/selectGoodsBox';
	import { outGoods, outGoodProduct } from '../components/tableName.js';
	import { formatDate } from '@/utils/validate';

	export default {
		name: 'outboundAdd',
		components: { selectGoodsBox },
		props: {
			value: { type: Boolean, default: false },
			editId: { type: Number, default: 0 }
		},
		data() {
			return {
				options: {
					disabledDate(date) {
						return date && date.valueOf() > Date.now();
					}
				},
				orderList: [
					{ id: '2', name: '过期退货', des: '已出库的商品因超过保质期无法正常使用，被退回仓库并按规定后续处置的业务' },
					{ id: '3', name: '试用出库', des: '为满足产品试用需求，将仓库商品发出给客户或相关方的出库业务' },
					{ id: '4', name: '报废出库', des: '仓库中因丧失使用价值、存在质量缺陷或达到报废标准的商品，从库存中发出并进行专门报废处置的出库业务' },
					{ id: '5', name: '良品转残次品', des: '因使用损坏、质量异常等原因不能作为正常商品售卖，转为残次品管理并对应减少库存数量的业务' },
					{ id: '6', name: '其他出库', des: '除常规的出库方式之外的特殊出库情况。调拨出库由调拨确认自动生成，不可在此手工创建。' }
				],
				formValidate: {
					order_type: '2',
					stock_time: '',
					remark: ''
				},
				ruleValidate: {
					order_type: [{ required: true, message: '请选择出库类型', trigger: 'change' }],
					stock_time: [{ required: true, type: 'date', message: '请选择出库日期', trigger: 'change' }]
				},
				tableHeader: outGoods,
				goodsData: [],
				batchNum: 0,
				sattrModals: false,
				goodsIds: [],
				openSubimit: false,
				active: '2'
			};
		},
		computed: {
			...mapState('admin/layout', ['isMobile']),
			labelWidth() {
				return this.isMobile ? undefined : 120;
			},
			labelPosition() {
				return this.isMobile ? 'top' : 'right';
			}
		},
		watch: {
			value(val) {
				if (val) this.openForm();
			}
		},
		methods: {
			handleClose() {
				this.$emit('input', false);
			},
			openForm() {
				this.resetForm();
			},
			resetForm() {
				this.goodsData = [];
				this.goodsIds = [];
				this.batchNum = 0;
				this.openSubimit = false;
				this.active = '2';
				this.tableHeader = outGoods;
				this.sattrModals = false;
				this.formValidate = {
					order_type: '2',
					stock_time: formatDate(new Date(Number(new Date().getTime())), 'yyyy-MM-dd'),
					remark: ''
				};
				this.$nextTick(() => {
					this.$refs.formValidate && this.$refs.formValidate.resetFields();
				});
			},
			storeType(e) {
				this.active = e;
				this.tableHeader = [];
				const header = e == 5 ? outGoodProduct : outGoods;
				const that = this;
				setTimeout(function() {
					that.tableHeader = header;
				});
			},
			addGoodsSattr() {
				this.sattrModals = true;
			},
			cancel() {
				this.sattrModals = false;
			},
			unique(arr) {
				const res = new Map();
				return arr.filter(item => !res.has(item.id) && res.set(item.id, 1));
			},
			getAtterId(data) {
				this.sattrModals = false;
				data.forEach(i => {
					i.goodProduct = 0;
					i.spoiledGoods = 0;
				});
				this.goodsData = this.unique(this.goodsData.concat(data));
			},
			batchSet(event) {
				this.batchNum = this.cleanPrice(event.target.value);
			},
			cleanPrice(value) {
				let cleanedValue = value.replace(/[^\d.]/g, '');
				const parts = cleanedValue.split('.');
				if (parts.length > 2) {
					cleanedValue = parts[0] + '.' + parts.slice(1).join('');
				}
				if (cleanedValue.includes('.')) {
					const [integerPart, decimalPart] = cleanedValue.split('.');
					cleanedValue = integerPart + '.' + decimalPart.slice(0, 2);
				}
				return cleanedValue;
			},
			closeBatchSet() {
				this.batchNum = 0;
				document.body.click();
			},
			batchSetConfirm(i) {
				const batchNum = this.batchNum;
				if (batchNum <= 0) {
					return this.$Message.error('批量设置必须大于0');
				}
				this.goodsData.map(item => {
					item[i] = parseFloat(batchNum) >= 0 ? parseFloat(batchNum) : null;
				});
				this.$refs.xTable.refreshColumn();
				this.closeBatchSet();
			},
			delGoods(row) {
				const index = this.goodsData.findIndex(item => item.id === row.id);
				this.goodsData.splice(index, 1);
			},
			batchDel() {
				this.goodsData = this.goodsData.filter(item => !this.goodsIds.some(ele => ele === item.id));
				this.goodsIds = [];
			},
			selectAllEvent(e) {
				if (e.checked) {
					this.goodsIds = this.goodsData.map(item => item.id);
				} else {
					this.goodsIds = [];
				}
			},
			selectChangeEvent(e) {
				const id = e.row.id;
				const index = this.goodsIds.indexOf(id);
				if (index !== -1) {
					this.goodsIds = this.goodsIds.filter(item => item !== id);
				} else {
					this.goodsIds.push(id);
				}
			},
			handleSubmit(name) {
				this.$refs[name].validate(valid => {
					if (!valid) {
						return this.$Message.error('请完善信息');
					}
					if (!this.goodsData.length) {
						return this.$Message.error('请选择商品');
					}
					const numArray = [];
					for (let i = 0; i < this.goodsData.length; i++) {
						if (this.goodsData[i].goodProduct <= 0 && this.formValidate.order_type == 5) {
							return this.$Message.error('请填写良品出库数量');
						}
						if (
							this.goodsData[i].goodProduct <= 0 &&
							this.goodsData[i].spoiledGoods <= 0 &&
							this.formValidate.order_type != 5
						) {
							return this.$Message.error('良品与残次品出库数量不能同时为0');
						}
						numArray.push({
							product_id: this.goodsData[i].product_id,
							unique: this.goodsData[i].unique,
							stock: this.goodsData[i].goodProduct,
							defective_stock: this.goodsData[i].spoiledGoods
						});
					}
					this.formValidate.out_product_detail = numArray;
					outventoryAddApi(this.formValidate)
						.then(res => {
							this.openSubimit = true;
							this.$Message.success(res.msg);
							this.handleClose();
							this.$emit('success');
						})
						.catch(err => {
							this.openSubimit = false;
							return this.$Message.error(err.msg);
						});
				});
			}
		}
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
	.ml14 {
		margin-left: 14px;
	}
</style>
