<template>
  <!-- 个人资料 -->
	<view>
		<!-- #ifdef MP -->
		<NavBar showBack bagColor="#f5f5f5" titleText="个人资料"></NavBar>
		<!-- #endif -->
		<form @submit="formSubmit">
			<view class='personal-data' :style="colorStyle">
				<view class='list rd-24rpx mx-20'>
					<view class='item acea-row row-between-wrapper'>
						<view>头像</view>
						<view class="avatar-box" v-if="!mp_is_new" @click.stop='uploadpic'>
							<image :src="userInfo.avatar"></image>
						</view>
						<button v-else class="avatar-box" open-type="chooseAvatar" @chooseavatar="onChooseAvatar">
							<image :src="userInfo.avatar"></image>
						</button>
					</view>
					<view class='item acea-row row-between-wrapper'>
						<view>昵称</view>
						<view class='input'><input type='nickname' name='nickname' :value='userInfo.nickname' maxlength="16"></input>
						</view>
					</view>
					<view class='item acea-row row-between-wrapper'>
						<view>手机号码</view>
						<navigator url="/pages/users/user_phone/index" hover-class="none" class="input"
							v-if="!userInfo.phone">
							点击绑定手机号<text class="iconfont icon-ic_rightarrow"></text>
						</navigator>
						<view @click="isCancellation = true" class="input"
							v-else>
							{{userInfo.phone}}<text class="iconfont icon-ic_rightarrow"></text>
						</view>
						<view class='input acea-row row-between-wrapper' v-else>
							<input type='text' disabled='true' name='phone' :value='userInfo.phone' class='id'></input>
							<text class='iconfont icon-ic_rightarrow'></text>
						</view>
					</view>
					<view class='item acea-row row-between-wrapper'>
						<view>ID号</view>
						<view class='input acea-row row-between-wrapper'>
							<input type='text' :value='userInfo.uid' disabled='true' class='id'></input>
							<text class='iconfont icon-ic_lock ml-12'></text>
						</view>
					</view>
					<!-- #ifdef H5 -->
					<view class="item acea-row row-between-wrapper" v-if="userInfo.phone && !this.$wechat.isWeixin()">
						<view>密码</view>
						<navigator url="/pages/users/user_pwd_edit/index" hover-class="none" class="input grab">
							点击修改密码
							<text class="iconfont icon-ic_rightarrow"></text>
						</navigator>
					</view>
					<!-- #endif -->
				
					<!-- #ifdef APP-PLUS -->
					<view class="item acea-row row-between-wrapper" v-if="userInfo.phone">
						<view>密码</view>
						<navigator url="/pages/users/user_pwd_edit/index" hover-class="none" class="grab">
							点击修改密码
							<text class="iconfont icon-ic_rightarrow"></text>
						</navigator>
					</view>
					<!-- #endif -->
					<view class="item acea-row row-between-wrapper">
						<view>账号注销</view>
						<navigator url="/pages/users/user_cancellation/index" hover-class="none" class="input grab">
							注销后无法恢复
							<text class="iconfont icon-ic_rightarrow"></text>
						</navigator>
					</view>
					<view class='item acea-row row-between-wrapper'  v-for="(item,index) in userInfo.register_extend_info" :key="index">
						<view class="acea-row row-middle">{{item.info}}<text v-if="item.required==1" class="asterisk iconfont icon-xinghao"></text></view>
						<!-- text -->
						<view class='input' v-if="item.format == 'text'">
							<input type='text' v-model="item.value" :placeholder="item.tip" placeholder-class="placeholder"></input>
						</view>
						<!-- number -->
						<view class='input' v-if="item.format == 'num'">
							<input type='number' v-model="item.value" :placeholder="item.tip" placeholder-class="placeholder"></input>
						</view>
						<!-- email -->
						<view class='input' v-if="item.format == 'mail'">
							<input type='text' v-model="item.value" :placeholder="item.tip" placeholder-class="placeholder"></input>
						</view>
						<!-- data -->
						<view class="input acea-row row-middle row-right" v-if="item.format=='date'">
							<picker mode="date" :value="item.value" @change="bindDateChange($event,index)">
								<view class="acea-row row-right dater">
									<view class="grab" v-if="!item.value || item.value == ''">{{item.tip}}</view>
									<view v-else>{{item.value}}</view>
								</view>
							</picker>
							<text class='iconfont icon-xiangyou'></text>
						</view>
						<!-- id -->
						<view class='input' v-if="item.format == 'id'">
							<input type='idcard' v-model="item.value" :placeholder="item.tip" placeholder-class="placeholder"></input>
						</view>
						<!-- phone -->
						<view class='input' v-if="item.format == 'phone'">
							<input type='tel' v-model="item.value" :placeholder="item.tip" placeholder-class="placeholder"></input>
						</view>
						<!-- radio -->
						<view class="input" v-if="item.format=='radio'">
							<radio-group @change="radioChange($event,index)">
								<label class="label" v-for="(j ,jindex) in item.singlearr" :key="jindex">
									<!-- #ifndef MP -->
									<radio :value="jindex.toString()" :checked='item.value == jindex'/>
									<!-- #endif -->
									<!-- #ifdef MP -->
									<radio :value="jindex" :checked='item.value == jindex'/>
									<!-- #endif -->
									{{j}}
								</label>
							</radio-group>
						</view>
						<!-- address -->
						<view class="input acea-row row-middle row-right" @click="addressList" v-if="item.format=='address'">
							<input type='text' v-model="item.value" :placeholder="item.tip" placeholder-class="placeholder"></input>
						</view>
					</view>
				</view>
				<button class='modifyBnt' formType="submit">保存修改</button>

			</view>
		</form>
		<canvas canvas-id="canvas" v-if="canvasStatus"
			:style="{width: canvasWidth + 'px', height: canvasHeight + 'px',position: 'absolute',left:'-100000px',top:'-100000px'}"></canvas>
		<!-- #ifdef MP -->
		<authorize v-if="isShowAuth" @authColse="authColse" @onLoadFun="onLoadFun"></authorize>
		<!-- #endif -->
		<tui-modal :show="isCancellation" maskClosable custom @cancel="isCancellation = false">
			<view class="tui-modal-custom">
				<view class="fs-32 fw-500 lh-44rpx text-center">更换已绑定的手机号？</view>
				<view class="fs-30 text--w111-666 lh-42rpx text-center mt-22">当前绑定的手机号码为 {{userInfo.phone}}</view>
				<view class="flex-y-center">
					<view class="w-full h-72 rd-36rpx flex-center border b-solid b--w111-ccc text-primary-con fs-26 text--w111-fff mt-32 mr-16" @tap="bindPhone">更换</view>
					<view class="w-full h-72 rd-36rpx flex-center bg-red fs-26 text--w111-fff mt-32 ml-16" @tap="isCancellation = false">取消</view>
				</view>
			</view>
		</tui-modal>
	</view>
</template>

<script>
	import {
		getUserInfo,
		userEdit,
		getLogout
	} from '@/api/user.js';
	import tuiModal from '@/components/tui-modal/index.vue';
	import {
		switchH5Login,
		getCity
	} from '@/api/api.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import dayjs from "@/plugin/dayjs/dayjs.min.js";
	import colors from '@/mixins/color.js';
	import NavBar from '@/components/NavBar.vue';
	export default {
		components: {tuiModal,NavBar},
		mixins: [colors],
		data() {
			return {
				isCancellation: false,
				userInfo: {},
				loginType: 'h5', //app.globalData.loginType
				userIndex: 0,
				switchUserInfo: [],
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				canvasWidth: "",
				canvasHeight: "",
				canvasStatus: false,
				district: [],
				multiArray: [],
				multiIndex: [0, 0, 0],
				valueRegion: [0, 0, 0],
				region: ['省', '市', '区'],
				mp_is_new: this.$Cache.get('MP_VERSION_ISNEW') || false
			};
		},
		computed: mapGetters(['isLogin']),
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						// #ifndef MP
						this.getUserInfo();
						// #endif
					}
				},
				deep: true
			}
		},
		onLoad() {
			if (this.isLogin) {
				this.getUserInfo();
			} else {
				this.getIsLogin();
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		methods: {
			getIsLogin(){
				toLogin()
			},
			// 省市区地址处理逻辑；
			addressList(){
				this.getCityList();
			},
			// 获取地址数据
			getCityList() {
				let that = this;
				getCity().then(res => {
					this.district = res.data
					that.initialize();
				})
			},
			// 处理地址数据
			initialize: function() {
				let that = this,
					province = [],
					city = [],
					area = [];
				if (that.district.length) {
					let cityChildren = that.district[0].c || [];
					let areaChildren = cityChildren.length ? (cityChildren[0].c || []) : [];
					that.district.forEach(function(item) {
						province.push(item.n);
					});
					cityChildren.forEach(function(item) {
						city.push(item.n);
					});
					areaChildren.forEach(function(item) {
						area.push(item.n);
					});
					this.multiArray = [province, city, area]
				}
			},
			bindRegionChange(e,index) {
				let multiIndex = this.multiIndex,
					province = this.district[multiIndex[0]] || {
						c: []
					},
					city = province.c[multiIndex[1]] || {
						v: 0
					},
					multiArray = this.multiArray,
					value = e.detail.value;
			
				this.region = [multiArray[0][value[0]], multiArray[1][value[1]], multiArray[2][value[2]]]
				this.userInfo.register_extend_info[index].value = city.v;
				this.userInfo.register_extend_info[index].province = this.region[0];
				this.userInfo.register_extend_info[index].city = this.region[1];
				this.userInfo.register_extend_info[index].district = this.region[2];
				this.valueRegion = [0, 0, 0]
				this.initialize();
			},
			bindMultiPickerColumnChange(e) {
				let that = this,
					column = e.detail.column,
					value = e.detail.value,
					currentCity = this.district[value] || {
						c: []
					},
					multiArray = that.multiArray,
					multiIndex = that.multiIndex;
				multiIndex[column] = value;
				switch (column) {
					case 0:
						let areaList = currentCity.c[0] || {
							c: []
						};
						multiArray[1] = currentCity.c.map((item) => {
							return item.n;
						});
						multiArray[2] = areaList.c.map((item) => {
							return item.n;
						});
						break;
					case 1:
						let cityList = that.district[multiIndex[0]].c[multiIndex[1]].c || [];
						multiArray[2] = cityList.map((item) => {
							return item.n;
						});
						break;
					case 2:
						break;
				}
				// #ifdef MP || APP-PLUS
				this.$set(this.multiArray, 0, multiArray[0]);
				this.$set(this.multiArray, 1, multiArray[1]);
				this.$set(this.multiArray, 2, multiArray[2]);
				// #endif
				// #ifdef H5 
				this.multiArray = multiArray;
				// #endif
				this.multiIndex = multiIndex
			},
			radioChange(e, index){
				this.userInfo.register_extend_info[index].value = e.detail.value
			},
			bindDateChange: function(e, index) {
				this.userInfo.register_extend_info[index].value = e.target.value
			},
			/**
			 * 授权回调
			 */
			onLoadFun: function() {
				this.getUserInfo();
				this.isShowAuth = false;
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			/**
			 * 小程序设置
			 */
			Setting: function() {
				uni.openSetting({
					success: function(res) {}
				});
			},
			switchAccounts: function(index) {
				let userInfo = this.switchUserInfo[index],
					that = this;
				that.userIndex = index;
				if (that.switchUserInfo.length <= 1) return true;
				if (userInfo === undefined) return that.$util.Tips({
					title: '切换的账号不存在'
				});
				if (userInfo.user_type === 'h5') {
					uni.showLoading({
						title: '正在切换中'
					});
					switchH5Login().then(res => {
						uni.hideLoading();
						that.$store.commit("LOGIN", {
							'token': res.data.token,
							'time': this.$Cache.strTotime(res.data.expires_time) - this.$Cache.time()
						});
						that.getUserInfo();

					}).catch(err => {
						uni.hideLoading();
						return that.$util.Tips({
							title: err
						});
					})
				} else {
					that.$store.commit("LOGOUT");
					uni.showLoading({
						title: '正在切换中'
					});
					this.getIsLogin();
				}
			},
		
			/**
			 * 获取用户详情
			 */
			getUserInfo: function() {
				let that = this;
				getUserInfo().then(res => {
					res.data.register_extend_info.forEach(item=>{
						if(item.format == 'radio'){
							item.value = '0'
						}else{
							item.value = ''
							if(item.format == 'address'){
								item.province = "";
								item.city = "";
								item.district = "";
							}
						}
					})
					res.data.register_extend_info.forEach(item=>{
						res.data.extend_info.forEach(j=>{
							if(item.info === j.info){
								item.value = j.value
								if(item.format == 'address'){
									let region = [j.province, j.city, j.district];
									that.$set(that, 'region', region);
								}
							}
						})
					})
					that.$set(that, 'userInfo', res.data);
					let switchUserInfo = res.data.switchUserInfo || [];
					for (let i = 0; i < switchUserInfo.length; i++) {
						if (switchUserInfo[i].uid == that.userInfo.uid) that.userIndex = i;
						// 切割h5用户；user_type状态：h5、routine（小程序）、wechat（公众号）；注：只有h5未注册手机号时，h5才可和小程序或是公众号数据想通；
						//#ifdef H5
						if (
							!that.$wechat.isWeixin() &&
							switchUserInfo[i].user_type != "h5" &&
							switchUserInfo[i].phone === ""
						)
							switchUserInfo.splice(i, 1);
						//#endif
					}
					that.$set(that, "switchUserInfo", switchUserInfo);
				});
			},
			/**
			 * 上传文件
			 * 
			 */
			uploadpic: function() {
				let that = this;
				this.canvasStatus = true
				that.$util.uploadImageChange('upload/image', (res) => {
					let userInfo = that.switchUserInfo[that.userIndex];
					that.userInfo.avatar = res.data.url;
					that.switchUserInfo[that.userIndex] = userInfo;
					that.$set(that, 'switchUserInfo', that.switchUserInfo);
					this.canvasStatus = false
				}, (res) => {
					this.canvasStatus = false
				}, (res) => {
					this.canvasWidth = res.w
					this.canvasHeight = res.h
				});
			},
			// 微信头像获取
			onChooseAvatar(e) {
				const {
					avatarUrl
				} = e.detail
				console.log(avatarUrl)
				this.$util.uploadImgs('upload/image', avatarUrl, (res) => {
					this.userInfo.avatar = res.data.url
				}, (err) => {
					console.log(err)
				})
			},
			bindPhone(){
				uni.navigateTo({
					url:'/pages/users/user_phone/index'
				})
			},
			/**
			 * 提交修改
			 */
			formSubmit: function(e) {
				let that = this,
					value = e.detail.value,
					userInfo = that.switchUserInfo[that.userIndex];
				if (!value.nickname) return that.$util.Tips({
					title: '用户姓名不能为空'
				});
				value.avatar = this.userInfo.avatar;
				for (var i = 0; i < that.userInfo.register_extend_info.length; i++) {
					let data = that.userInfo.register_extend_info[i]
					if (data.required || data.value) {
						if (data.format === 'date' || data.format === 'address') {
							if (!data.value) {
								return that.$util.Tips({
									title: `${data.tip}`
								});
							}
						}
						if(data.format === 'text'){
							if (!data.value.trim()) {
								return that.$util.Tips({
									title: `${data.tip}`
								});
							}
						}
						if (data.format === 'num') {
							if (data.value <= 0) {
								return that.$util.Tips({
									title: `${data.tip}`
								});
							}
						}
						if (data.format === 'mail') {
							if (data.required) {
								if (!data.value) {
									return that.$util.Tips({
										title: `${data.tip}`
									});
								}
							}
							if (data.value) {
								if (!/^\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/.test(data.value)) {
									return that.$util.Tips({
										title: '请填写正确的邮箱'
									});
								}
							}
						}
						if (data.format === 'phone') {
							if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(data.value)) {
								return that.$util.Tips({
									title: `${data.tip}`
								});
							}
						}
						if (data.format === 'id') {
							if (data.required) {
								if (!data.value) {
									return that.$util.Tips({
										title: `${data.tip}`
									});
								}
							}
							if (data.value) {
								if (!/^[1-9]\d{7}((0\d)|(1[0-2]))(([0|1|2]\d)|3[0-1])\d{3}$|^[1-9]\d{5}[1-9]\d{3}((0\d)|(1[0-2]))(([0|1|2]\d)|3[0-1])\d{3}([0-9]|X)$/i.test(data.value)) {
									return that.$util.Tips({
										title: '请填写正确的身份证号码'
									});
								}
							}
						}
					}
				}
				value.extend_info = that.userInfo.register_extend_info;
				userEdit(value).then(res => {
					return that.$util.Tips({
						title: res.msg,
						icon: 'success'
					});
				}).catch(msg => {
					return that.$util.Tips({
						title: msg || '保存失败，您并没有修改'
					});
				});
			}
		}
	}
</script>

<style scoped lang="scss">
	.personal-data{
		padding-bottom: 50rpx;
	}
	.dater{
		width: 400rpx;
	}
	.grab{
		color: #999 !important;
	}
	.asterisk{
		color: red;
		font-size: 20rpx;
		margin-left: 6rpx;
	}
	.placeholder{
		color: #ccc;
	}
	.cartcolor {
		color: var(--view-theme);
		border: 1px solid var(--view-theme);
	}

	.personal-data .wrapper {
		margin: 10rpx 0;
		background-color: #fff;
		padding: 36rpx 30rpx 13rpx 30rpx;
	}

	.personal-data .wrapper .title {
		margin-bottom: 30rpx;
		font-size: 30rpx;
		color: #282828;
	}

	
	.personal-data .list {
		margin-top: 15rpx;
		background-color: #fff;
	}

	.personal-data .list .item {
		padding: 32rpx 20rpx 32rpx 24rpx;
		font-size: 30rpx;
		color: #333;
		.label{
			display: flex;
			align-items: center;
			margin-left: 50rpx;
			line-height: 30rpx;
		}
		radio-group{
			display: flex;
		}
		/deep/ uni-radio-input{
			margin-right: 12rpx;
			.uni-radio-input.uni-radio-input-checked:before {
				font-size: 26rpx;
			}
		}
		
	}

	.personal-data .list .item .phone {
		width: 160rpx;
		height: 56rpx;
		font-size: 30rpx;
		color: #fff;
		line-height: 56rpx;
		border-radius: 32rpx
	}

	.personal-data .list .item .pictrue {
		width: 88rpx;
		height: 88rpx;
	}

	.personal-data .list .item .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 50%;
	}

	.personal-data .list .item .input {
		max-width: 460rpx;
		text-align: right;
		color: #999;
		input{
			text-align: right;
		}
		/deep/.uni-input-input{
			font-size: 30rpx;
		}
		.picker{
			width: 400rpx;
		}
	}

	.personal-data .list .item .input .id {
		width: 414rpx;
	}

	.personal-data .list .item .input .iconfont {
		font-size: 30rpx;
		color: #999;
	}

	.personal-data .modifyBnt {
		font-size: 30rpx;
		color: #333;
		width: 710rpx;
		height: 98rpx;
		border-radius: 24rpx;
		text-align: center;
		line-height: 90rpx;
		margin: 20rpx auto 0 auto;
		background-color: #fff;
	}

	.personal-data .logOut {
		font-size: 30rpx;
		text-align: center;
		width: 690rpx;
		height: 90rpx;
		border-radius: 45rpx;
		margin: 30rpx auto 0 auto;
	}

	.avatar-box {
		width: 96rpx;
		height: 96rpx;

		image {
			width: 100%;
			height: 100%;
			border-radius: 50%;
			border: 1px solid #eee;
		}
	}
</style>