<template>
	<view class="specs">
		<checkbox-group @change="checkboxChange">
		    <view class="list acea-row" :class="administer?'on':''" v-for="(item, index) in attrsList" :key="index">
				<!-- #ifndef MP -->
				<checkbox class="checkbox" v-if="administer" :value="(item.id).toString()" :checked="item.checked" />
				<!-- #endif -->
				<!-- #ifdef MP -->
				<checkbox class="checkbox" v-if="administer" :value="item.id" :checked="item.checked" />
				<!-- #endif -->
				<view class="listCon">
					<view class="item acea-row row-middle">
						<view class="name">规格名称</view>
						<view class="info">{{item.suk}}</view>
					</view>
					<view class="item acea-row row-middle">
						<view class="name">剩余库存</view>
						<view class="stock">{{item.stock}}</view>
					</view>
					<view class="item acea-row row-middle">
						<view>修改库存</view>
						<view class="acea-row row-middle">
							<view class="itemn acea-row row-middle" :class="item.current == indexn?'on':''" v-for="(itemn, indexn) in navList" :key="indexn" @click="navTap(item,indexn)">
								<text class="iconfont" :class="item.current == indexn?'icon-ic_Selected':'icon-ic_unselect'"></text>
								<text>{{itemn}}</text>
							</view>
						</view>
					</view>
					<view class="item acea-row row-middle">
						<view class="name">库存</view>
						<input type="number" :disabled="administer" v-model="item.currentStock" placeholder="请填写库存" placeholder-class="placeholder"/>
					</view>
				</view>
		    </view>
		</checkbox-group>	
		<view class="footer on acea-row row-between-wrapper" v-if="administer">
			<checkbox-group @change="checkboxAllChange">
				<checkbox value="all" :checked="isAllSelect" />
				<text class='checkAll'>全选</text>
			</checkbox-group>
			<view class="acea-row row-middle">
				<view class="bnt acea-row row-center-wrapper" @click="manageTap">取消</view>
				<view class="bnt on acea-row row-center-wrapper" @click="batchEdit">批量修改</view>
			</view>
		</view>
		<view class="footer acea-row row-between-wrapper" v-else>
			<view class="bnt acea-row row-center-wrapper" @click="manageTap">批量操作</view>
			<view class="bnt on acea-row row-center-wrapper" @click="define">保存</view>
		</view>
		<edit-stock :visible='visibleStock' :goodsInfo='goodsInfo' :storeNum='storeNum' @closeDrawer='stockCloseDrawer' @successChange='successChange'></edit-stock>
	</view>
</template>

<script>
import editStock from './components/editStock/index.vue';
import {
	getStoreGetAttrs,
	postStoreUpdateAttrs,
} from "@/api/admin";
export default {
	components: {
		editStock
	},
	data() {
		return {
			navList:['入库','出库'],
			
			
			attrsList:[],
			id:0,
			administer:false,
			isAllSelect: false,
			visibleStock: false,
			goodsInfo:{
				id:0,
				spec_type:1,
				attr_value:{}
			},
			storeNum:1
		}
	},
	onShow() {
	},
	onLoad(option) {
		this.id = option.id;
		this.storeNum = parseInt(option.storeNum);
		this.getAttrsList();
	},
	methods:{
		navTap(item,index){
			item.current = index
		},
		//批量获取id集合
		getIds(){
			let ids = []
			this.attrsList.forEach(item=>{
				if(item.checked){
					ids.push(item.id)
				}
			})
			return ids
		},
		batchEdit(){
			if(!this.getIds().length){
				this.$util.Tips({
					title: '请选择商品规格'
				});
				return
			}
			this.goodsInfo.id = this.id;
			this.visibleStock = true;
		},
		stockCloseDrawer(){
			this.visibleStock = false;
		},
		define(){
			let data = {
				attr_value:[]
			}
			data.type = 'stock';
			this.attrsList.forEach(item=>{
				let obj = {
					unique:item.unique,
					stock:item.current? parseInt(item.stock)-parseInt(item.currentStock) : parseInt(item.stock)+parseInt(item.currentStock)
				}
				data.attr_value.push(obj)
			})
			postStoreUpdateAttrs(this.id,data).then(res=>{
				this.$util.Tips({
					title: res.msg
				},() => {
					uni.navigateTo({
						url: '/pages/admin/goods/index'
					})
				});
			}).catch(err=>{
				this.$util.Tips({
					title: err
				});
			})
		},
		getAttrsList(){
			getStoreGetAttrs(this.id).then(res=>{
				let data = res.data;
				data.forEach(item => {
					item.checked = false;
					item.currentStock = 0;
					item.current = 0;
				})
				this.attrsList = data;
			}).catch(err=>{
				this.$util.Tips({
					title: err
				});
			})
		},
		checkboxChange(event) {
			let idList = event.detail.value;
			this.attrsList.forEach((item) => {
				if (idList.indexOf(item.id + '') !== -1) {
					item.checked = true;
				} else {
					item.checked = false;
				}
			})
			if (idList.length == this.attrsList.length) {
				this.isAllSelect = true;
			} else {
				this.isAllSelect = false;
			}
		},
		forGoods(val) {
			let that = this;
			if (!that.attrsList.length) return
			that.attrsList.forEach((item) => {
				if (val) {
					item.checked = true;
				} else {
					item.checked = false;
				}
			})
		},
		checkboxAllChange(event) {
			let value = event.detail.value;
			if (value.length) {
				this.isAllSelect = true;
				this.forGoods(1)
			} else {
				this.isAllSelect = false;
				this.forGoods(0)
			}
		},
		manageTap() {
			this.administer = !this.administer;
		},
		priceCloseDrawer(){
			this.visibleStock = false
		},
		successChange(e){
			this.visibleStock = false
			this.attrsList.forEach(item=>{
				if(item.checked){
					item.currentStock = e.currentStock;
					item.current = e.current
				}
			})
			this.manageTap();
		}
	}
}
</script>

<style lang="scss" scoped>
	/deep/checkbox .uni-checkbox-input.uni-checkbox-input-checked {
		border: 1px solid #2A7EFB !important;
		background-color: #2A7EFB !important;
	}
	/deep/checkbox .wx-checkbox-input.wx-checkbox-input-checked {
		border: 1px solid #2A7EFB !important;
		background-color: #2A7EFB !important;
	}
	.specs{
		padding: 24rpx 20rpx 112rpx 20rpx;
		padding-bottom: calc(112rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		padding-bottom: calc(112rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		.list{
			background-color: #fff;
			border-radius: 24rpx;
			padding: 0 24rpx;
			margin-bottom: 20rpx;
			.listCon{
				flex: 1;
			}
			&.on {
				input{
					color: #999999 !important;
				}
			}
			.checkbox{
				margin: 32rpx 12rpx 0 0;
			}
			.item{
				min-height: 102rpx;
				font-size: 28rpx;
				font-family: PingFang SC, PingFang SC;
				font-weight: 400;
				
				&~.item{
					border-top: 1px solid #F1F1F1;
				}
				.itemn{
					margin-left: 50rpx;
					color: #999999;
					.iconfont {
						margin-top: 4rpx;
						color: #CCCCCC;
						margin-right: 14rpx;
					}
					&.on {
						color: #333333;
						.iconfont {
							color: #2A7EFB;
						}
					}
				}
				.name{
					color: #333333;
					width: 115rpx;
					margin-right: 39rpx;
				}
				.info{
					color: #999999;
					flex: 1;
				}
				.stock{
					font-size: 36rpx;
					font-family: 'Regular';
					color: #999;
				}
				input {
					font-size: 36rpx;
					font-family: 'Regular';
					color: #333333;
				}
				// #ifdef MP
				input {
					font-size: 30rpx;
				}
				// #endif
				.placeholder{
					font-size:28rpx;
				}
				.pictrue{
					width: 100rpx;
					height: 100rpx;
					image {
						width: 100%;
						height: 100%;
						border-radius: 16rpx;
					}
				}
			}
		}
		.footer {
			box-sizing: border-box;
			padding: 0 20rpx;
			width: 100%;
			height: 112rpx;
			background-color: #fff;
			position: fixed;
			bottom: 0;
			z-index: 30;
			height: calc(112rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
			height: calc(112rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
			padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
			padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/
			width: 100%;
			left: 0;
			&.on {
				height: 96rpx;
				height: calc(96rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
				height: calc(96rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
				.bnt{
					width: 160rpx;
					height: 64rpx;
					font-size: 24rpx;
					&.on{
						background-color: #2A7EFB;
						color: #fff;
						margin-left: 16rpx;
					}
				}
			}
			.bnt {
				width: 346rpx;
				height: 72rpx;
				border-radius: 200rpx;
				border: 1px solid #2A7EFB;
				color: #2A7EFB;
				font-size: 26rpx;
				font-family: PingFang SC, PingFang SC;
				font-weight: 500;
				&.on{
					background-color: #2A7EFB;
					color: #fff;
				}
			}
		}
	}
</style>