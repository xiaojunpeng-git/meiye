<template>
	<view class="flex-col" :style="{ minHeight: mainHeight+'px'}">
		<view class="w-full fixed-lt z-20" :style="{'padding-top': sysHeight + 'px'}">
			<view class="h-80 px-20 flex-between-center">
				<text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff" @tap="pageBack"></text>
			</view>
		</view>
		<view class="header-top relative" :style="[headerBg]">
			<view class="rule-btn w-124 flex-center fs-24 text--w111-fff" :style="{top: 100 + sysHeight + 'px'}"
				@tap="goRecord">申请记录</view>
		</view>
		<view class="bg-v-gradient pl-20 pr-20 pb-24 flex-1">
			<view class="bg--w111-fff rd-24rpx content-box">
				<view class="fs-30 fw-500 lh-42rpx">请填写以下信息</view>
				<view class="cell flex-between-center mt-64">
					<view class="fs-28 lh-40rpx">供应商名称</view>
					<input type="text" v-model="form.system_name" placeholder="请输入代理商名称" placeholder-class="text--w111-ccc" class="fs-28 text-right" />
				</view>
				<view class="cell flex-between-center mt-64">
					<view class="fs-28 lh-40rpx">用户姓名</view>
					<input type="text" v-model="form.name" placeholder="请输入姓名" placeholder-class="text--w111-ccc" class="fs-28 text-right" />
				</view>
				<view class="cell flex-between-center mt-64">
					<view class="fs-28 lh-40rpx">联系电话</view>
					<input type="number" v-model="form.phone" placeholder="请输入手机号" placeholder-class="text--w111-ccc" class="fs-28 text-right" />
				</view>
				<view class="cell flex-between-center mt-64">
					<view class="fs-28 lh-40rpx">验证码</view>
					<view class="flex-y-center">
						<input type="number" v-model="form.captcha" placeholder="请输入验证码" placeholder-class="text--w111-ccc" class="fs-28 text-right w-300" />
						<button class="code-btn w-168 h-56 flex-center fs-24 ml-20"
							:disabled="disabled" @tap="code">{{ text }}</button>
					</view>
				</view>
				<view class="fs-28 lh-40rpx mt-64">请上传营业执照及相关资质证明图片</view>
				<view class="fs-24 lh-34rpx text--w111-ccc mt-12">(图片最多可上传8张，图片格式支持JPG、PNG、JPEG）</view>
				<view class="grid-column-4 grid-gap-24rpx mt-24">
					<view class="relative w-148 h-148" v-for="(item,index) in form.images" :key="index">
						<image :src="item" mode="aspectFill" class="w-148 h-148 rd-16rpx"></image>
						<view class="abs-rt w-32 h-32 del-pic flex-center" @click="DelPic(index)">
							<text class="iconfont icon-ic_close text--w111-fff fs-24"></text>
						</view>
					</view>
					<view class="h-148 flex-col flex-center upload bg--w111-f5f5f5 text--w111-999 rd-16rpx"
						@click='uploadpic' v-if="form.images.length < 8">
							<text class="iconfont icon-ic_camera fs-42"></text>
						<text class="fs-24 lh-34rpx pt-8">上传图片</text>
					</view>
				</view>
				<view class="flex-y-center mt-32">
					<text class="iconfont fs-30" :class="isSelect ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect'" @tap="proviceSelect"></text>
					<text class="fs-24 text--w111-999 pl-12">已阅读并同意</text>
					<text class="font-red fs-24" @tap="getAgreement">《供应商协议》</text>
				</view>
				<view class="w-full h-88 rd-44rpx flex-center text--w111-fff fs-28 mt-48" 
					:class="isSelectStar ? 'bg-red' : 'bg-disabled'" @tap="submitSupply">提交申请</view>
			</view>
		</view>
		<Verify @success="success" captchaType="clickWord" :imgSize="{ width: '330px', height: '155px' }"
			ref="verify"></Verify>
		<tui-modal :show="showModal" maskClosable custom @cancel="hideModal">
			<view class="tui-modal-custom" @touchmove.stop.prevent>
				<view class="fs-32 fw-500 lh-44rpx text-center">供应商协议</view>
				<view class="fs-28 text--w111-666 lh-44rpx mt-24">
					<scroll-view scroll-y="true" style="max-height: 700rpx">
						<jyf-parser :html="supplierAgreement" ref="article" :tag-style="tagStyle"></jyf-parser>
					</scroll-view>
				</view>
				<view class="w-full h-72 rd-36rpx flex-center bg-red fs-26 text--w111-fff mt-32" @tap="hideModal">知道了</view>
			</view>
		</tui-modal>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import {toLogin} from '@/libs/login.js';
	import {mapGetters} from "vuex";
	import { spplyCreateApi, getCodeApi, registerVerify, userApply, getUserAgreement } from "@/api/user.js";
	import colors from "@/mixins/color";
	import sendVerifyCode from "@/mixins/SendVerifyCode";
	import { HTTP_REQUEST_URL } from '@/config/app';
	import Verify from '../components/verify/verify.vue';
	import tuiModal from "@/components/tui-modal/index.vue";
	import parser from "@/components/jyf-parser/jyf-parser";
	import home from '@/components/home/index.vue';
	export default {
		data() {
			return {
				sysHeight:sysHeight,
				form:{
					system_name:'',
					name:'',
					phone:'',
					captcha:'',
					images:[]
				},
				canvasWidth: "",
				canvasHeight: "",
				canvasStatus: false,
				isSelect: false,
				keyCode:'',
				id:0,
				showModal: false,
				supplierAgreement:'',
				tagStyle:{
					img: 'width:100%;display:block;',
				},
        homeHide: false
			}
		},
		components: {
			Verify,
			tuiModal,
			parser,
			home
		},
		mixins: [sendVerifyCode, colors],
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						// #ifndef MP
						this.getOrderProduct();
						// #endif
					}
				},
				deep: true
			}
		},
		computed:{
			...mapGetters(['isLogin']),
			isSelectStar(){
				if(this.form.system_name && this.form.name && this.form.phone && this.form.captcha && this.form.images.length ) return true
			},
			headerBg(){
				return {
					backgroundImage: 'url('+ HTTP_REQUEST_URL + '/statics/images/supplier/apply_header.png'+')'
				}
			},
			mainHeight(){
				let {windowHeight } = uni.getSystemInfoSync();
				return windowHeight
			}
		},
		onLoad(options) {
			this.id = options.id || 0;
			if(options.id){
				this.getInfo();
			}
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			/*** 删除图片* */
			DelPic: function(index) {
				let that = this, pic = this.form.images[index];
				that.form.images.splice(index, 1);
				that.$set(that.form, 'images', that.form.images);
			},
			/*** 上传文件 **/
			uploadpic: function() {
				let that = this;
				this.canvasStatus = true
				that.$util.uploadImageChange({count:8,url:'upload/image'}, function(res) {
					that.form.images.push(res.data.url);
				}, (res) => {
					this.canvasStatus = false
				}, (res) => {
					this.canvasWidth = res.w
					this.canvasHeight = res.h
				});
			},
			code(){
				if (!this.form.phone) return this.$util.Tips({
					title: '请填写手机号码'
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(this.form.phone)) return this.$util.Tips({
					title: '请输入正确的手机号码'
				});
				this.$refs.verify.show()
			},
			success(data) {
				this.$refs.verify.hide()
				getCodeApi()
					.then(res => {
						this.keyCode = res.data.key;
						this.getCode(data);
					})
					.catch(res => {
						this.$util.Tips({
							title: res
						});
					});
			},
			async getCode(data){
				let that = this;
				if (!this.form.phone) return that.$util.Tips({
					title: '请填写手机号码'
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(this.form.phone)) return this.$util.Tips({
					title: '请输入正确的手机号码'
				});
			
				await registerVerify({
						phone: that.form.phone,
						type: 'supplier',
						key: that.keyCode,
						captchaType: 'clickWord',
						captchaVerification: data.captchaVerification
					})
					.then(res => {
						that.$util.Tips({
							title: res.msg
						});
						that.sendCode();
					})
					.catch(res => {
						that.$util.Tips({
							title: res
						});
					});
			},
			proviceSelect(){
				this.isSelect = !this.isSelect;
			},
			getInfo(){
				userApply(this.id).then(res=>{
					let data = res.data;
					this.form.system_name = data.system_name;
					this.form.name = data.name;
					this.form.phone = data.phone;
					this.form.images = data.images;
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					});
				})
			},
			submitSupply(){
				if(!this.isSelectStar) return this.$util.Tips({
					title: '请完整填写表单信息'
				});
				if(!this.isSelect) return this.$util.Tips({
					title: '请阅读并同意协议'
				});
				spplyCreateApi(this.id,this.form).then(res=>{
					uni.showToast({
						title:res.msg
					})
					uni.navigateTo({
						url: '/pages/users/supplier/state?id='+ res.data.id
					})
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			},
			pageBack(){
				this.$util.backPage();
			},
			goRecord(){
				uni.navigateTo({
					url: '/pages/users/supplier/record'
				})
			},
			getAgreement(){
				getUserAgreement('supplier').then(res => {
					this.supplierAgreement = res.data.content;
					this.showModal = true;
				}).catch(err => {
					that.$util.Tips({
						title: err.msg
					});
				})
			},
			hideModal(){
				this.showModal = false;
			},
			
		}
	}
</script>

<style>
	.header-top{
		width: 100%;
		height: 358rpx;
		background-size: cover;
	}
	.rule-btn{
		height: 48rpx;
		background: rgba(0,0,0,0.15);
		border-radius: 24rpx 0 0 24rpx;
		position: absolute;
		right: 0;
	}
	.bg-v-gradient{
		background: linear-gradient(180deg, #FE7015 0%, #EB3B26 100%);
	}
	.content-box{
		padding: 48rpx 32rpx 40rpx;
	}
	.code-btn{
		border: 1px solid #e93323;
		color: #e93323 !important;
		border-radius: 28rpx;
		font-family: PingFang SC, PingFang SC;
		font-weight: 500;
	}
	.upload{
		border: 1rpx dashed #ccc;
	}
	.del-pic{
		background-color: #999;
		border-radius: 0 16rpx 0 16rpx;
	}
	.icon-a-ic_CompleteSelect, .font-red{
		color: #e93323;
	}
	.icon-ic_unselect{
		color: #ccc;
	}
	.bg-red{
		background-color: #e93323;
	}
	.bg-disabled{
		background-color: rgba(233, 51, 35, 0.5);
	}
	.modal-bottom{
		height: 136rpx;
		border-radius: 0 0 32rpx 32rpx;
		background-color: #fff;
	}
</style>
