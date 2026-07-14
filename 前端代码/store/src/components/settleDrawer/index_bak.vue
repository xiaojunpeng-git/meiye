<template>
  <Drawer
    ref="drawer"
	title='选择支付方式'
	:class-name="isRecharge?'recharge-drawer':'settle-drawer'"
    :value="visible"
    @on-visible-change="visibleChange"
  >
    <div class="acea-row row-between">
      <div class="flex-1 leftCon acea-row row-between row-column">
		<div>
			<div class="text-wlll-909399 fs-16 text-center">应收金额</div>
			<div class="text-wlll-303133 fs-32 fw-600 text-center">￥<span class="fs-36">{{ money }}</span></div>
			<div v-if="activePay==1" class="acea-row row-between-wrapper pl-16 pr-16 mt-24 h-60 mr-40 border-1-DCDEE2 rd-4 bg-w111-FAFAFA text-wlll-303133 fs-16">
				<div>微信/支付宝</div>
				<div><span class="fs-20 mr-4">{{money}}</span>元</div>
			</div>
			<div v-else-if="activePay==3">
				<div class="acea-row row-between-wrapper pl-16 pr-16 mt-24 h-60 mr-40 border-1-DCDEE2 rd-4 bg-w111-FAFAFA text-wlll-303133 fs-16">
					<div>余额收款</div>
					<div><span class="fs-20 mr-4">{{money}}</span>元</div>
				</div>
				<div class="text-wlll-303133 mt-12 fs-16 ml-16" v-if="nowMoney>=money">当前会员余额：{{nowMoney}}，支付后剩余：{{this.$computes.Sub(nowMoney, money || 0)}}元</div>
				<div class="text-wlll-E93323 mt-12 fs-16 ml-16" v-else>当前会员余额：{{nowMoney}}，余额不足，请切换支付方式</div>
			</div>
			<div v-else-if="activePay==2" class="mr-40">
				<div class="acea-row row-between-wrapper pl-16 pr-16 mt-24 h-60 border-1-1890FF rd-4 text-wlll-303133 fs-16">
					<div>现金收款</div>
					<div><span class="fs-20 mr-4">{{ collection }}</span>元</div>
				</div>
				<div class="text-wlll-303133 mt-14 fs-16 text-right mr-16">找零：{{money > collection? 0: this.$computes.Sub(collection, money || 0)}}元</div>
				<div class="text-wlll-E93323 mt-14 fs-16 text-right mr-16" v-if="money > collection">付款金额不足</div>
			</div>
			<div v-else class="mr-40 h-60 mt-24">
			  <Input
			    ref="inputs"
			    :key="type"
			    v-model.trim="payNum"
			    placeholder="请点击输入框聚焦扫码或输入编码号"
			    @on-enter="cashBnt"
			  ></Input>
			  <div class="tips-wrap" :class="{ balance: type === 'yue' }"></div>
			</div>
		</div>
		<div class="mr-40 flex-1 acea-row row-column row-right">
			<div class="acea-row row-between-wrapper">
				<div v-for="(item,index) in list" :key="index" v-if="item.status" @click="typeChange(item)" class="h-90 rd-8 border-1-DCDEE2 acea-row row-center-wrapper pointer" :class="[item.num==2 || isRecharge==1?'payOn2':'payOn3 row-column',item.id==activePay?'activeOn':'']">
					<div v-if="!item.value">
						<span class="iconfont icona-ic_wechatpay text-wlll-45D040 fs-27"></span>
						<span class="iconfont icona-ic_alipay text-wlll-248BFF fs-27 ml-10"></span>
					</div>
					<div v-else>
						<span class="iconfont fs-27" :class="[item.icon,item.id == 2?'text-wlll-FF4F43':'text-wlll-FE9951']"></span>
					</div>
					<div class="fs-16 text-wlll-303133" :class="item.num==2?'ml-10':''">{{item.label}}</div>
				</div>
			</div>
			<div class="bg-w111-F5F5F5 rd-8 pl-12-5 pr-12-5 pt-12-5 pb-12-5 mt-12">
				<div class="w-490">
				  <div class="keypad">
				    <div class="left">
				      <Button v-for="item in numList" :key="item" @click="numTap(item)">{{
				        item
				      }}</Button>
				    </div>
				    <div class="right">
				      <Button @click="delNum"
				        ><Icon type="ios-backspace-outline"
				      /></Button>
				      <Button @click="delNum(-1)">C</Button>
				      <Button v-if="activePay == 2" class="enter" @click="payFun">确认</Button>
					  <Button v-else-if="activePay == ''" class="enter">确认</Button>
					  <Button v-else class="enter" @click="payMony">确认</Button>
				    </div>
				  </div>
				</div>
			</div>
		</div>
      </div>
	  <div class="ticket w-303 bg-w111-FAFAFA rd-4 pl-40 pr-40 pt-40 text-wlll-303133 relative" v-if="isRecharge==0">
		  <div class="text-center fs-16 mb-30 fw-500">交易明细</div>
		  <div class="acea-row row-between-wrapper mb-26">
			  <div>商品总额</div>
			  <div>¥{{ priceInfo.sumPrice || 0 }}</div>
		  </div>
		  <div class="acea-row row-between-wrapper mb-26">
			  <div>积分抵扣</div>
			  <div>-¥{{ priceInfo.deductionPrice || 0 }}</div>
		  </div>
		  <div class="acea-row row-between-wrapper mb-26">
			  <div>优惠券抵扣</div>
			  <div>-¥{{ priceInfo.couponPrice || 0 }}</div>
		  </div>
		  <div class="acea-row row-between-wrapper mb-26">
			  <div>会员优惠</div>
			  <div>-¥{{ priceInfo.vipPrice || 0 }}</div>
		  </div>
		  <div class="acea-row row-between-wrapper mb-26" v-if="priceInfo.firstOrderPrice > 0">
			  <div>首单优惠</div>
			  <div>-¥{{ priceInfo.firstOrderPrice || 0 }}</div>
		  </div>
		  <div class="acea-row row-between-wrapper mb-26"
		    v-for="(item, index) in priceInfo.promotionsDetail"
		    :key="index"
		  >
		    <div>{{ item.title }}：</div>
		    <div>-¥{{ item.promotions_price || 0 }}</div>
		  </div>
		  <div class="item acea-row row-between-wrapper mb-26" v-if="submitData.payPrice">
		    <div>改价优惠：</div>
		    <div>-¥{{ $computes.Sub(submitData.payPrice,submitData.resultPayPrice) }}</div>
		  </div>
		  <div class="acea-row row-between-wrapper mb-26 pt-26 border-dashed-top-1-CCCCCC">
			  <div>应付金额</div>
			  <div class="text-wlll-FF7700">¥{{money}}</div>
		  </div>
		  <div class="w-303 h-10 absolute bottom-f16 left-0"><img class="w-full h-full" src="../../assets/images/juchi.png"/></div>
	  </div>
    </div>
	<Modal
	  v-model="userInfoShow"
	  class-name="vertical-center-modal"
	  footer-hide
	  title="是否切换此用户"
	  width="340"
	>
	  <div class="search_user_info">
	    <div class="picture">
	      <img :src="modalUserInfo.avatar" alt="" />
	    </div>
	    <p class="user_name">{{ modalUserInfo.real_name }}</p>
	    <p class="user_id">ID:{{ modalUserInfo.uid }}</p>
	    <p class="user_phone">手机号：{{ modalUserInfo.phone }}</p>
	    <div class="sure_btn" @click="checkUser">确定</div>
	  </div>
	</Modal>
	<Modal v-model="payShow" footer-hide class-name="payStyle-modal vertical-center-modal">
		<div>
			<div class="text-center fs-20 text-wlll-303133 mt-30 fw-500">扫码收款<span class="ml-4">¥{{money}}</span></div>
			<div class="w-316 h-46 auto mt-30">
			  <Input
			    ref="input"
			    :key="type"
			    v-model.trim="payNum"
			    :placeholder="activePay==3?'请聚焦扫描用户会员码或输入会员编码':'请聚焦扫描微信/支付宝付款码'"
			    @on-enter="payFun"
			  ></Input>
			</div>
			<div class="w-240 h-155 auto mt-46 relative">
				<img src="@/assets/images/payImg.png" class="w-full h-full"/>
				<div class="mask acea-row row-center-wrapper" v-if="payIng">
					<span class="iconfont iconic_loading fs-23 mr-5"></span>正在支付
				</div>
			</div>
		</div>
	</Modal>
  </Drawer>
</template>

<script>
import { postSearchUserInfo } from '@/api/user';
export default {
  model: {
    prop: 'visible',
    event: 'change',
  },
  props: {
    visible: {
      type: Boolean,
      default: false,
    },
    money: {
      type: [Number, String],
      default: 0,
    },
    collection: {
      type: [Number, String],
      default: 0,
    },
	priceInfo: {
		type: Object,
		default:{}
	},
	nowMoney: {
		type: [Number, String],
		default:0
	},
	submitData: {
		type: Object,
		default:{}
	},
    zIndex: {
      type: [Number, String],
      default: 9999,
    },
    type: {
      type: String,
      default: '',
    },
    verify: {
      type: Boolean,
      default: false,
    },
    list: {
      type: Array,
      default() {
        return [];
      },
    },
	isRecharge: {
	  type: [Number],
	  default:0
	},
  },
  data() {
    return {
      numList: ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '00', '.'],
      payNum: '',
	  userInfoShow:false,
	  modalUserInfo:{},
	  codeType:'',
	  activePay: '',
	  payShow:false,
	  payIng:false
    };
  },
  watch: {
    type(value) {
      this.$nextTick(() => {
        if (!value || (value === 'yue' && this.verify)) {
          this.$refs.input.focus();
        }
      });
    },
    zIndex(value) {
      let drawerEl = this.$refs.drawer.$el;
      drawerEl.querySelector('.ivu-drawer-mask').style.zIndex = value;
      drawerEl.querySelector('.ivu-drawer-wrap').style.zIndex = value;
    },
  },
  methods: {
    // 抽屉显示状态发生变化
    visibleChange(visible) {
      if (visible) {
        this.$nextTick(() => {
		  if(this.isRecharge == 0){
			this.$refs.inputs.focus();
		  }
        });
      } else {
        this.payNum = '';
      }
      this.$emit('change', visible);
    },
    // 选择支付方式
    typeChange(item) {
      this.payNum = '';
	  this.activePay = item.id;
      this.$emit('payPrice', item.value);
	  if(item.id == 3 && this.nowMoney<this.money){
		  return this.$Message.error('余额不足，请切换支付方式');
	  }
    },
	payMony(){
		if(this.activePay==3 && this.nowMoney<this.money){
			return this.$Message.error('余额不足，请切换支付方式');
		}
		if(this.activePay==3 && this.verify==0){
			this.payFun();
		}else{
			this.payShow = true;
			this.$nextTick(() => {
			  this.$refs.input.focus();
			});
		}
	},
    numTap(item) {
      this.$emit('numTap', item);
    },
    delNum(type) {
      this.$emit('delNum', type);
    },
	checkUser(){
		let that = this;
		this.userInfoShow = false;
		this.$emit('getUserId',this.modalUserInfo);
		//用户id查询时，只查询用户，不用走支付接口
		if(!this.codeType){
			this.payNum = '';
			return false
		}
		setTimeout(function(){
			that.payFun();
		},500)
	},
    // 支付宝、微信、现金、余额支付
    cashBnt() {
	  postSearchUserInfo({search:this.payNum}).then(res=>{
		  this.codeType = res.data.code_type;
		  let type = ''
		  if(res.data.code_type == 'user'){
			  type = 'yue'
		  }else{
			  type = ''
		  }
		  this.$emit('payPrice', type);
		  if(res.data.search_user && this.list[0].num==2){
			  this.userInfoShow = true;
			  this.modalUserInfo = res.data.user_info;
		  }else{
			  this.payFun();
		  }
	  }).catch(err=>{
		  this.$Message.error(err.msg);
	  })
    },
	payFun(){
		if (!this.payNum) {
		  if (this.type === '' || (this.type === 'yue' && this.verify)) {
		    return;
		  }
		}
		if (this.isURL(this.payNum)) {
		  this.payNum = this.getCodeFromLink(this.payNum);
		}
		this.$emit('cashBnt', this.payNum);
		this.payNum = '';
	},
    // 判断字符串是否为URL
    isURL(str) {
      const pattern = /^(http|https):\/\/[^ "]+$/;
      return pattern.test(str);
    },
    // 从URL中提取参数code
    getCodeFromLink(link) {
      const url = new URL(link);
      const searchParams = new URLSearchParams(url.search);
      const code = searchParams.get('code');
      return code;
    },
    // 取消收款
    handleCancel() {
      this.$emit('change', false);
    },
  },
};
</script>

<style lang="stylus" scoped>
::-webkit-scrollbar {
  display: none;
}
.iconic_loading{
	animation: loading 1s linear infinite;
}
@keyframes loading {
	from { transform: rotate(0deg);}
	50%  { transform: rotate(180deg);}
	to   { transform: rotate(360deg);}
}
.mask{
	position: absolute;
	top:0;
	right:0;
	left:0;
	bottom:0;
	background-color: rgba(255,255,255,0.9)
	width: 100%;
	height: 100%;
}
/deep/.settle-drawer{
	.ivu-drawer{
		width: 945px !important;
	}
}
/deep/.recharge-drawer{
	.ivu-drawer{
		width: 602px !important;
	}
}
/deep/.payStyle-modal{
	.ivu-modal{
		width: 396px !important;
	}
	.ivu-input{
		height: 46px;
	}
	.ivu-modal-body{
		padding-bottom: 45px;
	}
}
.activeOn{
	border: 1px solid #1890FF;
	background: rgba(24,144,255,0.04);
}
.payOn2{
	width: 49% !important;
}
.payOn3{
	width: 32% !important;
}
.leftCon{
	height: calc(100vh - 101px);
	padding-bottom: 15px;
}
/deep/.ivu-drawer-body{
	padding: 25px 40px;
}
/deep/.ivu-input{
	height: 60px;
	font-size: 16px !important;
	padding: 0 16px;
}
.ticket{
	height: calc(100vh - 120px);
}
.search_user_info {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;

  .picture {
    width: 110px;
    height: 110px;
    margin: 20px 0 20px;

    img {
      width: 100%;
      height: 100%;
      border-radius: 50%;
    }
  }

  .user_name {
    font-size: 18px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.85);
    margin-bottom: 14px;
  }

  .user_id {
    font-size: 12px;
    font-weight: 400;
    color: #999999;
  }

  .user_phone {
    font-size: 14px;
    font-weight: 400;
    color: rgba(0, 0, 0, 0.85);
    margin: 14px 0 40px;
  }

  .sure_btn {
    width: 176px;
    height: 46px;
    line-height: 46px;
    text-align: center;
    color: #fff;
    font-size: 16px;
    background: #1890FF;
    border-radius: 6px;
    margin-bottom: 30px;
	cursor: pointer;
  }
}
.keypad {
	display: flex;
	.left {
		flex: 0 0 75%;
		display: flex;
		flex-wrap: wrap;
		.ivu-btn {
		  width: calc((100% - 21px) / 3)
		}
	}
	.right {
		flex: 0 0 25%;
		display: flex;
		flex-direction: column;
		/deep/.ivu-icon{
			font-weight: 600 !important;
			font-size: 31px;
		}
	}
	.ivu-btn {
	  height: 62px;
	  border: 0;
	  border-radius: 8px;
	  margin: 3.5px;
	  font-weight: 500;
	  font-size: 28px !important;
	  line-height: 62px;
	  color: #303133;
	  background-color: #fff;

	  &:focus {
	    box-shadow: none;
	  }
	}
	.enter {
	  height: 131px;
	  background-color: #1890FF;
	  font-weight: 500;
	  font-size: 22px !important;
	  line-height: 131px;
	  color: #FFFFFF;
	  border-radius: 8px;
	}
}
</style>
