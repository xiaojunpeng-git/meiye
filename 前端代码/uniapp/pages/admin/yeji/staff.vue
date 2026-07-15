<template>
  <view class="pagebox">
	  <!-- #ifdef MP || APP-PLUS -->
	  <NavBar titleText="个人业绩" :iconColor="iconColor" :textColor="iconColor" :bagColor="bagColor" :isScrolling="isScrolling" showBack></NavBar>
	  <!-- #endif -->
	  <view class="headerBg">
	  	<view :style="{ height: `${getHeight.barTop}px` }"></view>
	  	<view :style="{ height: `${getHeight.barHeight}px` }"></view>
	  	<view class="inner"></view>
	  </view>
	  <view class="search fixed z-10 row-between-wrapper w-full h-98 pl-20 pr-20"  style="flex-shrink:0;display: flex;align-items: center">
	  	<view class="acea-row row-middle row-around w-480 h-50 rd-24px bg-w111-F5F5F5-10 fs-24 text--w111-fff" style="flex-shrink:0;">
	  		<view class="w-88 h-50 acea-row row-center-wrapper" :class="current==index?'active':''" v-for="(item, index) in dataList" :key="index" @click="dataTap(index)">{{item.name}}</view>
	  	</view>
      <view class="dataRange line1" v-if="current ==  4">{{ dataRange }}</view>
	  </view>
	  <view class="relative w-710 rd-24rpx bg--w111-fff m-auto mt-98 pt-40" style="padding-bottom: 20px">
      <view class="phone_out">
           <view style="margin-bottom: 20rpx">姓名：{{ staffInfo.staff_name || '—' }}</view>
           <view>电话：{{ staffInfo.phone || '—' }}</view>
      </view>
		  <view class="stats-grid text--w111-999 fs-24">
        <view class="stats-row">
          <view class="stats-cell">
            <view>销售业绩</view>
            <view class="stats-value fs-36 SemiBold text--w111-333">{{ staffInfo.moneyYeji }}</view>
          </view>
          <view class="stats-cell">
            <view>劳动业绩</view>
            <view class="stats-value fs-36 SemiBold text--w111-333">{{ staffInfo.optionYeji }}</view>
          </view>
          <view class="stats-cell" @click="salaryDetail" v-if="showSalaryBlock">
            <view>实发工资</view>
            <view class="stats-value fs-36 SemiBold text--w111-333" v-if="staffInfo.salary && staffInfo.salary.shifagongzi">{{ staffInfo.salary.shifagongzi }}</view>
            <view class="stats-value fs-36 SemiBold text--w111-333" v-else>0</view>
          </view>
          <view class="stats-cell">
            <view>客数</view>
            <view class="stats-value fs-36 SemiBold text--w111-333">{{ staffInfo.service_num }}</view>
          </view>
        </view>
        <view class="stats-row stats-row--gap">
          <view class="stats-cell">
            <view>指定客</view>
            <view class="stats-value fs-36 SemiBold text--w111-333">{{ staffInfo.service_zd }}</view>
          </view>
          <view class="stats-cell">
            <view>提成</view>
            <view class="stats-value fs-36 SemiBold text--w111-333">{{ staffInfo.service_commission }}</view>
          </view>
          <view class="stats-cell">
            <view>项目数</view>
            <view class="stats-value fs-36 SemiBold text--w111-333">{{ projectNumTop }}</view>
          </view>
          <view class="stats-cell stats-cell--spacer" v-if="showSalaryBlock"></view>
        </view>
      </view>
	  </view>
	  <view class="w-710 rd-16rpx bg--w111-fff m-auto mt-20 pl-24 pr-24">
      <view class="kuai_out">
          <view class="kuai" :class="show_type == 1?'kuai_choose':''" @click="changeType(1)">销售业绩</view>
          <view class="kuai" :class="show_type == 2?'kuai_choose':''" @click="changeType(2)">劳动业绩</view>
      </view>
		  <view>
		  	<view  v-for="(item, index) in ranking" :key="index" class="kuai_kuai fs-22 text--w111-333 border-b border-b-s b-w111-F1F1F1">
           <view class="kuai_kuai-left">
             <view class="orderId" @click="copyOrder(item.wx_order_id)">{{ item.wx_order_id }}</view>
             <view class="text--w111-999 names" v-if="item.type == 3 && item.user">{{ item.user.real_name }}（{{ item.service_object || '本人' }}）</view>
             <view class="text--w111-999 names" v-else-if="item.user">{{ item.user.real_name }}</view>
             <view style="font-size: 28rpx;margin-bottom:10rpx">{{ (item.cart && item.cart.store_name) || '—' }}</view>
             <view v-if="item.type == 3" class="labor-yeji-line">业绩：{{ item.price }}</view>
             <view v-if="item.type == 3" class="labor-yeji-line">参与：{{ item.labor_participant_names || '—' }}</view>
           </view>
		     		<view class="kuai_kuai-right">
              <view class="text--w111-999 names">{{ item.created_time }}</view>
              <view class="text--w111-999 names">{{ (item.user && item.user.phone) || '—' }}</view>
              <view style="font-size: 28rpx;margin-bottom:10rpx" v-if="item.type == 3">{{item.dian}}</view>
              <view style="font-size: 28rpx;margin-bottom:10rpx" v-else>{{item.yeji}}</view>
              <view v-if="item.type == 3">
                项目数：{{ item.project_num }}
              </view>
              <view v-if="item.type == 3" >
                提成：{{ item.commission }}
              </view>
            </view>
		  	</view>
        <view class="bg--w111-f5f5f5 p-20" v-if="!ranking.length && !pageloading">
              <emptyPage title="暂无员工业绩" src="/statics/images/noActivity.gif"></emptyPage>
        </view>
		  </view>
	  </view>
	  <view class="footer"></view>
	  <base-drawer mode="top" :visible="dataShow" background-color="transparent" zIndex='9' maskZIndex='8' mask maskClosable @close="closeData">
		  <view class="w-full bg--w111-fff rd-b-32rpx pl-20 pr-20 pb-52">
			  <!-- #ifdef MP || APP-PLUS -->
			  <view :style="{ height: `${getHeight.barTop}px` }"></view>
			  <view :style="{ height: `${getHeight.barHeight}px` }"></view>
			   <!-- #endif -->
			  <view class="h-98"></view>
			  <view class="acea-row row-between-wrapper mt-48" @click="dataPickerTap">
				  <view class="w-340 h-64 bg--w111-f5f5f5 rd-50rpx fs-24 text--w111-ccc acea-row row-center-wrapper">
					  <text class="text--w111-333" v-if="customizeData.length">{{customizeData[0]}}</text>
					  <text v-else>开始时间</text>
				  </view>
				  -
				  <view class="w-340 h-64 bg--w111-f5f5f5 rd-50rpx fs-24 text--w111-ccc acea-row row-center-wrapper">
					  <text class="text--w111-333" v-if="customizeData.length">{{customizeData[1]}}</text>
					  <text v-else>结束时间</text>
				  </view>
			  </view>
			  <view class="acea-row row-between-wrapper mt-48">
				  <view class="w-346 h-72 rd-50rpx border-2A7EFB-2 fs-26 text-w111-2A7EFB acea-row row-center-wrapper" @click="closeData">取消</view>
				  <view class="w-348 h-72 rd-50rpx bg-w111-2A7EFB fs-26 text--w111-fff acea-row row-center-wrapper" @click="confirmData">确定</view>
			  </view>
		  </view>
	  </base-drawer>
	  <uni-datetime-picker ref="dataPicker" type="daterange" @change='changeData'>
		  <view></view>
	  </uni-datetime-picker>
	  <storeList ref="storeList" :storeData='storeData' :storeShow='storeShow' @OnChangeStore='OnChangeStore' @changeClose='changeClose'></storeList>
  </view>
</template>
<script>
// #ifdef MP || APP-PLUS
import NavBar from '@/components/NavBar.vue';
// #endif
import storeList from '../components/storeList/index.vue';
import {getAgentOrder, getStoreList} from "@/api/admin";
import {detailYeji,yejiInfo} from "@/api/yeji";
import qiunDataCharts from '../components/qiun-data-charts/components/qiun-data-charts/qiun-data-charts.vue';
import emptyPage from '@/components/emptyPage.vue';
import legacyMerchantRedirect from '@/mixins/legacyMerchantRedirect.js';
export default {
  name: 'agent',
  mixins: [legacyMerchantRedirect],
  components: {
	storeList,
    emptyPage,
	qiunDataCharts,
  	// #ifdef MP ||APP-PLUS
  	NavBar,
  	// #endif
  },
  data() {
    return {
      page: 1,
      limit: 20,
     show_type:1,
	  chartDataArea:{},
	  optsArea:{
		animation: true,
		background: "#FFFFFF",
		color: ["#1890FF"],
		padding: [15,15,15,15],
		enableScroll: true,
		scrollPosition:'left',
		legend: {},
		xAxis: {
		  disableGrid: true,
		  itemCount: 6,
		  scrollShow: true,
		  scrollAlign: 'left',
		  fontSize: 11,
		  fontColor:'#666',
		  marginTop: 3,
		  scrollColor:'#ccc',
		},
		yAxis: {
		  gridType: "dash",
		  dashLength: 2,
		  showTitle: true,
		  gridColor:'#D8D8D8',
		  data: [{
		  	calibration: true,
		  	position: 'left',
		  	title: this.currentHeader==1?'':'单位(元)',
		  	titleFontSize: 11,
		  	titleOffsetY:-5,
		  	titleFontColor:'#999',
		  	fontColor:'#666',
		  	fontSize: 11,
		  	axisLineColor:'#D8D8D8',
		  	format: (val) => {
		  		return val.toFixed(0)
		  	}
		  }, ],
		},
		extra: {
		  area: {
		    type: "curve",
		    opacity: 0.2,
		    addLine: true,
		    width: 2,
		    gradient: true,
		    activeType: "hollow"
		  },
		  tooltip: {
		  	// showCategory: true,
		  	showArrow: false,
		  	borderRadius: 6,
		  	bgColor:'#323131',
		  	bgOpacity: 0.6,
		  	fontSize: 12,
		  	legendShape: 'circle'
		  }
		}
	  },
	  chartData:{},
	  opts:{
		animation: true,
		rotate: false,
		rotateLock: false,
		background: "#FFFFFF",
		color: ["#2D8CF0","#21CCFF","#FF9900","#FFCD27","#F95C96","#ED4014"],
		padding: [5,5,5,5],
		dataLabel: true,
		enableScroll: false,
		legend: {
		  show: true,
		  position: "bottom",
		  lineHeight: 25
		},
		title: {
			name: ""
		},
		subtitle: {
			name: ""
		},
		extra: {
		  ring: {
		    ringWidth: 30,
		    activeOpacity: 0.5,
		    activeRadius: 10,
		    offsetAngle: 0,
		    labelWidth: 15,
		    border: false,
		    borderWidth: 3,
		    borderColor: "#FFFFFF"
		  }
		}
	  },
    staff_id:0,
    staffInfo:{},
	  bagColor: 'linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%)',
	  iconColor: '#FFFFFF',
	  isScrolling: false,
	  getHeight: this.$util.getWXStatusHeight(),
	  dataList: [
		  {name:'今天'},
		  {name:'昨天'},
		  {name:'上月'},
		  {name:'本月'},
		  {name:'自定义'}
	  ],
	  current:0,
	  headerData:[],
	  currentHeader:0,
	  storeData:[],
	  storeShow:false,
	  ranking:[],
	  orderby:'', //排序参数升序序 asc 降序 desc
	  storeId:0,
      loading: false,
      loadend: false,
      pageloading: false,
    sum_type:1,//1销售业绩 2劳动业绩
	  storeName:'全部门店',
	  dataShow:false,
	  dataRange:'',
	  roundCake:false, //圆饼统计图是否存在
	  customizeData:[]
    };
  },
  computed: {
    projectNumTop() {
      const v = this.staffInfo && this.staffInfo.project_num;
      return v !== undefined && v !== null && v !== '' ? v : '0.00';
    },
    /** 后端 yejiInfo 返回 salary_display：1 显示实发工资，2 隐藏（配置项 mobile_yeji_salary_display） */
    showSalaryBlock() {
      const d = this.staffInfo && this.staffInfo.salary_display;
      if (d === 2 || d === '2') return false;
      return true;
    },
  },
  onLoad(option) {
    this.staff_id=option.staff_id || 0;
    this.current=option.date_tap || 0;
    this.dataRange=option.dataRange || '';
    // 商家端本人业绩：无指定 staff_id 时收口到 Guard 页，禁止走旧 yejiInfo 任意 staff_id
    this._legacyOption = option;
    this.bootstrapAfterLegacyCheck();
  },
  onReachBottom: function() {
    this.agentStore();
  },
  methods: {
    async bootstrapAfterLegacyCheck() {
      const lookingOthers = this.staff_id && Number(this.staff_id) > 0;
      if (!lookingOthers) {
        const redirected = await this.redirectLegacyToMerchant(() => {
          const perms = (this.$store && this.$store.state.merchant.permissions) || [];
          if (Array.isArray(perms) && perms.indexOf('merchant.data.self') !== -1) {
            return '/pages/merchant/yeji/self';
          }
          return '/pages/merchant/home/index';
        });
        if (redirected) return;
      }
      //这里的 750 对应 css .charts 的 width
      this.cWidth = uni.upx2px(710);
      //这里的 500 对应 css .charts 的 height
      this.cHeight = uni.upx2px(500);
      if(this.dataRange == '') {
        let todayRange = this.$util.getCurrentTodayRange();
        this.dataRange = todayRange.start + '-' + todayRange.end;
      }else{
        this.customizeData = this.dataRange.split("-");
      }
      if(this.current > 0 && this.current != 4){
          this.dataTap(this.current);
      }else{
        this.agentStore();
        this.getInfo();
      }
    },
    copyOrder(key){
      uni.setClipboardData({
        data: key, // 要复制的文本
        success: () => {
          // 复制成功的提示反馈
          this.$util.Tips({
            title: '复制成功'
          });
        },
        fail: (error) => {
          // 复制失败的提示与调试信息
          this.$util.Tips({
            title: '复制失败'
          });
        }
      });
    },
    getInfo(){
      let that=this;
      yejiInfo({staff_id:this.staff_id,data:this.dataRange}).then(res=>{
        that.staffInfo = res.data;
      }).catch(err=>{
        this.$util.Tips({
          title: err
        })
      })
    },
    changeType(type){
        this.page=1;
        this.ranking=[];
         this.loadend=false;
         this.show_type=type;
         this.sum_type=type;
         this.agentStore();

    },
	changeData(e){
		this.customizeData = e;
		let start = e[0].split('-');
		let end = e[1].split('-');
		this.dataRange = `${start[0]}/${start[1]}/${start[2]}`+'-'+`${end[0]}/${end[1]}/${end[2]}`;
		this.dataShow = false;
    this.page=1;
    this.ranking=[];
    this.loadend=false;
		this.agentStore();
    this.getInfo();
	},
	dataPickerTap(){
		this.$refs.dataPicker.show();
	},
	closeData(){
		this.dataShow = false;
	},
	confirmData(){
		this.dataShow = false;
	},
    toDetail(item){
         let name=item.title;
         if(name == '实际收款金额'){
           uni.navigateTo({
             url: `/pages/admin/agent/shou_detail?number=${item.number}&data_range=`+this.dataRange+"&store_id="+this.storeId
           });
         }
    },
    salaryDetail(){
         uni.navigateTo({
             url: `/pages/admin/yeji/staff_detail?date=`+this.staffInfo.date+"&staff_id="+this.staff_id
        });
    },
	storeTap(){
		this.storeShow = !this.storeShow;
		this.dataShow = false;
	},
	changeClose(){
		this.storeShow = false;
	},
	storeList() {
		getStoreList().then(res=>{
			res.data.unshift({
				id:0,
				name:'全部门店'
			});
			this.storeData = res.data;
		}).catch(err=>{
			this.$util.Tips({
				title: err
			})
		})
	},
	OnChangeStore(row) {
    this.storeId = row.id;
    this.storeName = row.name;
    this.storeShow = false;
    this.page=1;
    this.ranking=[];
    this.loadend=false;
    this.agentStore();
	},
	tapHeader(index){
		this.currentHeader = index;
		this.agentOrder();
	},
	dataTap(index){
		this.current = index;
		if(index==4){
			this.dataShow = true;
		}else{
			this.dataShow = false;
			this.customizeData = [];
			if(index==0){
				let todayRange = this.$util.getCurrentTodayRange();
				this.dataRange = todayRange.start+'-'+todayRange.end;
			}else if(index==1){
				let yesterday = this.$util.getCurrentYesterdayRange();
				this.dataRange = yesterday.start+'-'+yesterday.end;
			}else if(index==2){
				let weekRange = this.$util.getLastMonthRange();
				this.dataRange = weekRange.start+'-'+weekRange.end;
			}else{
				let monthRange = this.$util.getCurrentMonthRange();
				this.dataRange = monthRange.start+'-'+monthRange.end;
			}
      this.page=1;
      this.ranking=[];
      this.loadend=false;
			this.agentStore();
      this.getInfo();
		}
	},
	agentOrder(){
		let data = {
			type:this.currentHeader+1,
			data:this.dataRange
		}
		getAgentOrder(data).then(res=>{
			let data = res.data;
			data.series.forEach(item=>{
				item.legendShape = 'circle'
			})
			this.chartDataArea = data;
		}).catch(err=>{
			this.$util.Tips({
				title: err
			})
		})
	},
    agentStore(type){
		let data = {
      sum_type:this.sum_type,
      staff_id:this.staff_id,
			data:this.dataRange,
      page:this.page,
      limit:this.limit
		}
    let that=this;
     detailYeji(data).then(res=>{
       var seckillList = res.data;
       var loadend = seckillList.length < that.limit;
       that.page++;
       that.ranking = that.ranking.concat(seckillList),
       that.pageloading = false;
       that.loadend = loadend;
		}).catch(err=>{
			this.$util.Tips({
				title: err
			})
		})
	},
	rankTap(name){
		switch (name) {
			case 'pay_price':
			      let orderby = ''
			      if(this.orderby=='pay_price desc'){
					orderby = 'pay_price'
				  }else if(this.orderby=='pay_price asc'){
					orderby = 'pay_price desc'
				  }else{
					orderby = 'pay_price asc'
				  }
			      this.orderby = orderby
			      break;
			case 'order_number':
			      let orderNumber = ''
			      if(this.orderby=='order_number desc'){
			      	 orderNumber = 'order_number'
			      }else if(this.orderby=='order_number asc'){
			      	 orderNumber = 'order_number desc'
			      }else{
			      	 orderNumber = 'order_number asc'
			      }
			      this.orderby = orderNumber
			      break;
			case 'unit_price':
			      let orderPrice = ''
			      if(this.orderby=='unit_price desc'){
			      	 orderPrice = 'unit_price'
			      }else if(this.orderby=='unit_price asc'){
			      	 orderPrice = 'unit_price desc'
			      }else{
			      	 orderPrice = 'unit_price asc'
			      }
			      this.orderby = orderPrice
			      break;
		}
    this.page=1;
    this.ranking=[];
    this.loadend=false;
		this.agentStore('rank');
	 }
  },
};
</script>

<style lang="scss" scoped>
.dataRange{
  color: #fff;
  font-size: 20rpx;
  margin-left: 10rpx;
}
/* 每条业绩：一行仅左右两栏；勿用全局 .acea-row（默认 flex-wrap:wrap 会把右栏挤到下一行） */
.kuai_kuai {
  display: flex;
  flex-direction: row;
  flex-wrap: nowrap;
  align-items: flex-start;
  justify-content: space-between;
  box-sizing: border-box;
  padding-bottom: 20rpx;
  margin-bottom: 20rpx;
}
.kuai_kuai-left {
  flex: 1;
  min-width: 0;
  padding-right: 16rpx;
  box-sizing: border-box;
}
.kuai_kuai-right {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  text-align: right;
  box-sizing: border-box;
}
.labor-staff-commission-line {
  width: 100%;
  flex-wrap: nowrap;
  align-items: flex-start;
  margin-top: 8rpx;
}
.labor-staff-names {
  flex: 1;
  min-width: 0;
  text-align: left;
}
.labor-commission {
  flex-shrink: 0;
  margin-left: 12rpx;
}
.names{
  font-size: 22rpx;
  margin-bottom: 10rpx;
}
.orderId{
  font-size: 22rpx;
  margin-bottom: 10rpx;
  color: #2A7EFB;
}
.title_out{
  display: flex;
  justify-content: space-between;
}
.phone_out{
  text-align: left;
  margin-bottom: 20px;
  margin-left: 130rpx;
  font-size: 26rpx;
}
.kuai_out{
  padding-top: 20rpx;
  display: flex;
  gap:20px;
  margin-bottom: 20px;
  margin-top: 20px;
  font-size: 24rpx;
  overflow-x: scroll;
  width: 100%;
  overflow-y: hidden;
}
.kuai{
  padding-bottom: 10px;
  flex-shrink: 0;
}
.kuai_choose{
     color: #2A7EFB;
     border-bottom: 2px solid #2A7EFB;
}
.stats-grid {
  width: 100%;
  padding: 0 8rpx;
  box-sizing: border-box;
}
.stats-row {
  display: flex;
  flex-direction: row;
  align-items: flex-start;
  justify-content: space-between;
}
.stats-row--gap {
  margin-top: 30rpx;
}
.stats-cell {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  text-align: center;
}
.stats-value {
  margin-top: 12rpx;
  line-height: 1.2;
  word-break: break-all;
}
.stats-cell--spacer {
  pointer-events: none;
  visibility: hidden;
}
.w-198{
  width: 60% !important;
}
.w-170{
  width: 40% !important;
  text-align: right;
}
  .footer{
	  height: calc(30rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
	  height: calc(30rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
  }
  .charts{
    width: 710rpx;
    height: 500rpx;
  }
  .pagebox {
  	position: relative;
  	overflow: hidden;
	.headerBg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		background: linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%);

		.inner {
			height: 200rpx;
		}
	}
	.search{
		background: linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%);
	}
	.active{
		border-radius: 120rpx;
		color: #2A7EFB;
		background: #FFFFFF;
	}
  }
</style>
