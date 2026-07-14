<template>
	<!-- 添加新地址 -->
	<view :style="colorStyle">
		<form @submit="formSubmit">
			<view class='addAddress'>
				<view class="input-wrapper">
					<view class='list'>
						<view class='item acea-row row-between-wrapper'>
							<view class='name'>姓名</view>
							<input type='text' placeholder='请输入姓名' name='real_name' :value="userAddress.real_name" placeholder-class='placeholder'></input>
						</view>
						<view class='item acea-row row-between-wrapper'>
							<view class='name'>联系电话</view>
							<input type='number' maxlength="11" placeholder='请输入联系电话' name="phone" :value='userAddress.phone' placeholder-class='placeholder' pattern="\d*"></input>
						</view>
						<view class='item acea-row row-between-wrapper'>
							<view class='name'>所在地区</view>
							<view class="address acea-row row-between-wrapper">
								<view class="addressCon acea-row" @click="changeRegion">
									<text class="picker color-add" v-if="!addressInfo.length">请选择地址</text>
									<view v-else>
										<text class="picker">{{addressText}}</text>
									</view>
								</view>
								<view class="location1" @click="chooseLocation">
									<text class="iconfont icon-ic_location1 text-primary"></text>定位
								</view>
							</view>
						</view>
						<view class='item acea-row row-between-wrapper'>
							<view class='name'>详细地址</view>
							<view class="address">
								<input type='text' placeholder='请填写具体地址' name='detail' placeholder-class='placeholder' :value='userAddress.detail' class="detail"></input>
							</view>
						</view>
					</view>
				</view>
				<button class='keepBnt' form-type="submit">立即保存</button>
			</view>
		</form>
		<areaWindow ref="areaWindow" :display="display" :address="addressInfo" @submit="OnChangeAddress" @changeClose="changeClose"></areaWindow>
	</view>
</template>

<script>
	import {getGeocoder,getCityList} from '@/api/user.js';
	import {mapGetters} from "vuex";
	import colors from '@/mixins/color.js';
	import areaWindow from '@/components/areaWindow';
	export default {
		components: {
			areaWindow,
		},
		mixins: [colors],
		data() {
			return {
				id: 0, //地址id
				userAddress: {}, //地址详情
				district: [],
				display: false,
				addressInfo: [],
				addressVal: '',
				latitude: '',
				longitude: '',
				city_id: 0,
				addressValue: "",
			};
		},
		computed: {
			...mapGetters(['isLogin']),
			addressText() {
				return this.addressInfo.map(v => v.label).join('/');
			}
		},
		onLoad(options) {
			
		},
		methods: {
			changeRegion() {
				this.display = true;
			},
			OnChangeAddress(address) {
				this.latitude = ''
				this.longitude = ''
				this.addressInfo = address;
			},
			// 关闭地址弹窗；
			changeClose: function() {
				this.display = false;
			},
			// 获取选中位置
			chooseLocation: function() {
				let self = this;
				uni.chooseLocation({
					success: (res) => {
						let latitude, longitude;
						latitude = res.latitude.toString();
						longitude = res.longitude.toString();
						this.latitude = res.latitude
						this.longitude = res.longitude
						getGeocoder({
							lat: latitude,
							long: longitude
						}).then(res => {
							const data = res.data;
							getCityList(data.address_component.province + '/' + data.address_component.city + '/' + data.address_component.district + '/' + (!data
								.address_reference.town ? '' : data.address_reference.town.title)).then(res => {
								self.addressInfo = res.data;
								self.userAddress.detail = data.formatted_addresses.recommend;
							}).catch(err => {
								self.$util.Tips({
									title: err
								});
							});
						})
					},
					fail: (err) => {
						console.log(err)
					}
				})
			},
			/**
			 * 提交用户添加地址
			 * 
			 */
			formSubmit: function(e) {
				let that = this,
					value = e.detail.value;
				if (!value.real_name) return that.$util.Tips({
					title: '请填写收货人姓名'
				});
				if (!value.phone) return that.$util.Tips({
					title: '请填写联系电话'
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(value.phone)) return that.$util.Tips({
					title: '请输入正确的手机号码'
				});
				if (!that.addressInfo.length) return that.$util.Tips({
					title: '请选择所在地区'
				});
				if (that.addressInfo.length < 3) return that.$util.Tips({
					title: '请补全所在地区信息'
				});
				if (!value.detail) return that.$util.Tips({
					title: '请填写详细地址'
				});
				value.id = 0;
				let regionArray = that.addressInfo;
				value.address = {
					province: regionArray[0].label,
					city: regionArray[1].label,
					district: regionArray[2].label,
					street: regionArray[3] ? regionArray[3].label : '',
					city_id: regionArray[regionArray.length - 1].id ? regionArray[regionArray.length - 1].id : that.city_id,
				};
				uni.$emit("refresh", value)
				uni.navigateBack()
			},
			identify() {
				const options = {
					type: 0, // 哪种方式解析，0：正则，1：树查找
					textFilter: [], // 预清洗的字段
					nameMaxLength: 4, // 查找最大的中文名字长度
				}
				const parseResult = AddressParse(this.addressValue.trim(), options)
				// type参数0表示使用正则解析，1表示采用树查找, textFilter地址预清洗过滤字段。
				if (this.addressValue.trim()) {
					getCityList(parseResult.province + '/' + parseResult.city + '/' + parseResult.area).then(res => {
						this.addressInfo = res.data;
						this.userAddress.phone = parseResult.phone;
						this.userAddress.real_name = parseResult.name;
						this.userAddress.detail = parseResult.detail;
					}).catch(err => {
						return this.$util.Tips({
							title: err
						});
					})
				}
			}
		}
	}
</script>

<style scoped lang="scss">
	.color-add {
		color: #cdcdcd;
	}

	.location1 {
		.iconfont {
			margin-right: 8rpx;
		}
	}

	.text-primary {
		color: $primary-admin;
	}

	.addAddress {
		padding: 24rpx 20rpx;
	}

	.addAddress .input-wrapper {
		margin-bottom: 20rpx;
	}

	.addAddress .list {
		padding: 8rpx 0;
		border-radius: 16rpx;
		background-color: #fff;
	}

	.addAddress .list .item {
		padding: 32rpx 24rpx;
		position: relative;
	}

	.addAddress .list .item .detail {
		width: 368rpx;
	}

	.addAddress .list .item .location {
		position: absolute;
		right: 46rpx;
		top: 50%;
		margin-top: -40rpx !important;
		font-size: 24rpx;
		text-align: center;
	}

	.addAddress .list .item .icon-dizhi {
		font-size: 36rpx !important;
	}

	.addAddress .list .item .name {
		width: 195rpx;
		font-size: 30rpx;
		color: #333;
	}

	.addAddress .list .item .address {
		flex: 1;
	}

	.addAddress .list .item .address .addressCon {
		width: 360rpx;
	}

	.addAddress .list .item .address .addressCon .tip {
		font-size: 21rpx;
		margin-top: 4rpx;
	}

	.addAddress .list .item input {
		flex: 1;
		font-size: 30rpx;
	}

	.placeholder {
		color: #ccc;
	}

	.addAddress .list .item .picker {
		width: 430rpx;
		font-size: 30rpx;
	}

	.addAddress .list .item .iconfont {
		font-size: 30rpx;
		margin-top: 4rpx;
	}

	.addAddress .keepBnt {
		position: fixed;
		right: 20rpx;
		bottom: 40rpx;
		left: 20rpx;
		height: 80rpx;
		border-radius: 40rpx;
		text-align: center;
		line-height: 80rpx;
		font-size: 28rpx;
		color: #fff;
		background-color: $primary-admin;
	}
</style>