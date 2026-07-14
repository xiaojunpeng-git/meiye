<template>
  <view class="pagebox">
	  <!-- #ifdef MP || APP-PLUS -->
	  <NavBar titleText="区域统计" :iconColor="iconColor" :textColor="iconColor" :bagColor="bagColor" :isScrolling="isScrolling" showBack></NavBar>
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
	  	<view class="fs-24 text--w111-fff acea-row row-middle" @click="goObjectSelect" style="margin-left: 50rpx;align-items: center">
			<view class="w-170 line1 text-right">{{ objectLabel || '请选择' }}</view>
			<text class="iconfont icon-ic_rightarrow fs-24"></text>
		</view>
	  </view>
	  <view class="relative w-710 rd-24rpx bg--w111-fff m-auto mt-98 pt-40" style="padding-bottom: 20px">
		  <view class="acea-row row-around row-middle text--w111-999 fs-24">
			  <view v-for="(item, index) in headerData" :key="index">
          <view @click="toDetail(item)">
			   	  <view @click="toDetail(item)">{{item.title}}</view>
				    <view class="fs-36 SemiBold mt-12 text--w111-333" @click="toDetail(item)">{{item.number}}</view>
          </view>
			  </view>
		  </view>
<!--		  <view class="charts mt-58">-->
<!--		    <qiun-data-charts-->
<!--		      type="area"-->
<!--		      :opts="optsArea"-->
<!--		      :chartData="chartDataArea"-->
<!--		      :canvas2d="true"-->
<!--			  :loadingType='0'-->
<!--			  :inScrollView='true'-->
<!--			  :onmovetip='true'-->
<!--			  :ontouch='true'-->
<!--			  :tapLegend='false'-->
<!--		      canvasId="vLLUOHjjWETeACUnaGqqvjTsnrZjPkVi"-->
<!--		    />-->
<!--		  </view>-->
	  </view>
<!--	  <view class="w-710 rd-16rpx bg&#45;&#45;w111-fff m-auto mt-20 pl-24 pr-24 pt-32" v-if="roundCake">-->
<!--		  <view class="fs-30 text-w111-303133 PingFang fw-500 mb-24">销售占比</view>-->
<!--		  <view class="charts">-->
<!--		      <qiun-data-charts-->
<!--		        type="ring"-->
<!--		        :opts="opts"-->
<!--		        :chartData="chartData"-->
<!--		        :canvas2d="true"-->
<!--		        canvasId="uXvvYBFpOeneDbDEzQEqVbhZuilumxlI"-->
<!--		      />-->
<!--		  </view>-->
<!--	  </view>-->
	  <view class="w-710 rd-16rpx bg--w111-fff m-auto mt-20 pl-24 pr-24 pt-32" style="margin-top: 30rpx">
      <view class="fs-30 text-w111-303133 PingFang fw-500 mb-24 dateOut">
        <view>门店排行</view>
        <view class="dataRange" v-if="current ==  4">{{ dataRange }}</view>
      </view>
      <view class="kuai_out">
          <view class="kuai" :class="show_type == 1?'kuai_choose':''" @click="changeType(1)">现金业绩</view>
          <view class="kuai" :class="show_type == 7?'kuai_choose':''" @click="changeType(7)">实际业绩</view>
          <view class="kuai" :class="show_type == 2?'kuai_choose':''" @click="changeType(2)">客户消耗金额</view>
          <view class="kuai" :class="show_type == 3?'kuai_choose':''" @click="changeType(3)">员工现金业绩</view>
          <view class="kuai" :class="show_type == 4?'kuai_choose':''" @click="changeType(4)">员工劳动业绩</view>
          <view class="kuai" :class="show_type == 5?'kuai_choose':''" @click="changeType(5)">员工点客</view>
          <view class="kuai" :class="show_type == 6?'kuai_choose':''" @click="changeType(6)">项目数排行</view>
      </view>
		  <view class="fs-24 text--w111-999 acea-row  row-middle" style="justify-content: space-between" v-if="ranking.length > 0">
		  	<view class="the_kuai_left">门店名称</view>
		  	<view  v-if="show_type < 3 || show_type == 7" class="the_kuai_right">金额</view>
		  	<view  v-if="show_type > 2 && show_type != 7" class="the_kuai">手艺人</view>
		  	<view  v-if="show_type == 3 || show_type == 4" class="the_kuai_right">业绩</view>
		  	<view  v-if="show_type == 5" class="the_kuai_right">数量</view>
		  	<view  v-if="show_type == 6" class="the_kuai_right">项目数</view>
		  </view>
		  <view>
		  	<view v-for="(item, index) in ranking" :key="index" class="fs-22 text--w111-333 h-90 border-b border-b-s b-w111-F1F1F1 acea-row row-middle" style="justify-content: space-between">
            <view class="the_kuai_left"  style="font-size: 14px;color: #2A7EFB" v-if="show_type < 3 || show_type == 7" @click="toStore(item.store_id)">{{item.name}}</view>
		     		<view class="the_kuai_right"  style="font-size: 14px"  v-if="show_type < 3 || show_type == 7">{{item.number}}</view>
            <view class="the_kuai_left"  style="font-size: 14px;color: #2A7EFB" v-if="show_type > 2 && show_type != 7" @click="toStore(item.store_id)">{{item.store_name}}</view>
            <view class="the_kuai"  style="font-size: 14px;color: #2A7EFB"  v-if="show_type > 2 && show_type != 7" @click="toStaff(item.staff_id)">{{item.staff_name}}</view>
            <view class="the_kuai_right"  style="font-size: 14px"  v-if="show_type > 2 && show_type != 7">{{item.yeji}}</view>
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
  </view>
</template>
<script>
// #ifdef MP || APP-PLUS
import NavBar from '@/components/NavBar.vue';
// #endif
import {getAgentHeader, getAgentOrder, getAgentStore} from "@/api/admin";
import {agentYejiRanking,agentProjectRanking,agentDianke} from "@/api/yeji";
import { targetStoreOptionsTree } from '@/api/target.js';
import {
	initTargetObjectFilter,
	buildStoreFilterApiParams,
} from '../target/common/util.js';
import qiunDataCharts from '../components/qiun-data-charts/components/qiun-data-charts/qiun-data-charts.vue';
import emptyPage from '@/components/emptyPage.vue';
export default {
  name: 'agent',
  components: {
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
	  objectLabel: '',
	  filterStoreId: '',
	  filterStoreIds: [],
	  filterManageRegionId: '',
	  filterObjectType: '',
	  ranking:[],
	  orderby:'', //排序参数升序序 asc 降序 desc
      loading: false,
      loadend: false,
      pageloading: false,
    sum_type:1,//1销售业绩 2劳动业绩
	  dataShow:false,
	  dataRange:'',
	  roundCake:false, //圆饼统计图是否存在
	  customizeData:[]
    };
  },
  onLoad() {
    //这里的 750 对应 css .charts 的 width
    this.cWidth = uni.upx2px(710);
    //这里的 500 对应 css .charts 的 height
    this.cHeight = uni.upx2px(500);
	let todayRange = this.$util.getCurrentTodayRange();
	this.dataRange = todayRange.start+'-'+todayRange.end;
  },
  async onShow() {
	await this.syncObjectFilter();
	this.reloadAgentData();
  },
  onReachBottom: function() {
    this.agentStore();
  },
  methods: {
	getStoreFilterParams() {
		return buildStoreFilterApiParams({
			filterStoreId: this.filterStoreId,
			filterStoreIds: this.filterStoreIds,
			filterManageRegionId: this.filterManageRegionId,
			filterObjectType: this.filterObjectType,
		});
	},
	async syncObjectFilter() {
		await initTargetObjectFilter({
			getOptions: () => targetStoreOptionsTree(),
			applyFilter: (filter) => {
				this.objectLabel = filter.objectLabel;
				this.filterStoreId = filter.filterStoreId;
				this.filterStoreIds = filter.filterStoreIds || [];
				this.filterManageRegionId = filter.filterManageRegionId;
				this.filterObjectType = filter.filterObjectType;
			},
		});
	},
	reloadAgentData() {
		this.page = 1;
		this.ranking = [];
		this.loadend = false;
		this.agentHeader();
		this.agentOrder();
		this.agentStore();
	},
	goObjectSelect() {
		this.dataShow = false;
		uni.navigateTo({ url: '/pages/admin/target/select/object?mode=multiple&from=agent' });
	},
    changeType(type){
        this.page=1;
        this.ranking=[];
         this.loadend=false;
        this.show_type=type;
        if(type == 3){
            this.sum_type=1;
        }
      if(type == 4){
           this.sum_type=2;
      }
        this.agentStore();
    },
    toStaff(id){
      uni.navigateTo({
        url: '/pages/admin/yeji/staff?staff_id='+id+"&date_tap="+this.current+"&dataRange="+this.dataRange
      })
    },
    toStore(id){
      uni.navigateTo({
        url: '/pages/admin/yeji/store?store_id='+id+"&date_tap="+this.current+"&dataRange="+this.dataRange
      })
    },
	changeData(e){
		this.customizeData = e;
		let start = e[0].split('-');
		let end = e[1].split('-');
		this.dataRange = `${start[0]}/${start[1]}/${start[2]}`+'-'+`${end[0]}/${end[1]}/${end[2]}`;
		this.dataShow = false;
		this.agentOrder();
    this.page=1;
    this.ranking=[];
    this.loadend=false;
		this.agentStore();
		this.agentHeader();
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
         if(name == '现金业绩'){
           const storeParams = this.getStoreFilterParams();
           const q = Object.keys(storeParams).map(k => `${k}=${encodeURIComponent(storeParams[k])}`).join('&');
           uni.navigateTo({
             url: `/pages/admin/agent/shou_detail?number=${item.number}&data_range=`+this.dataRange+(q ? '&'+q : '')
           });
         }
	},
	agentHeader(){
		let data = {
			...this.getStoreFilterParams(),
			data:this.dataRange
		}
		getAgentHeader(data).then(res=>{
			this.headerData = res.data;
		}).catch(err=>{
			this.$util.Tips({
				title: err
			})
		})
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
			this.agentOrder();
      this.page=1;
      this.ranking=[];
      this.loadend=false;
			this.agentStore();
			this.agentHeader();
		}
	},
	agentOrder(){
		let data = {
			type:this.currentHeader+1,
			...this.getStoreFilterParams(),
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
 getYejiRanking(){
		let data = {
      sum_type:this.sum_type,
			...this.getStoreFilterParams(),
			data:this.dataRange,
      page:this.page,
      limit:this.limit
		}
    let that=this;
     agentYejiRanking(data).then(res=>{
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
    getDainke(){
      let data = {
        sum_type:this.sum_type,
        ...this.getStoreFilterParams(),
        data:this.dataRange,
        page:this.page,
        limit:this.limit
      }
      let that=this;
      agentDianke(data).then(res=>{
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
    getProjectRanking() {
      let data = {
        ...this.getStoreFilterParams(),
        data: this.dataRange,
        page: this.page,
        limit: this.limit
      };
      let that = this;
      agentProjectRanking(data).then(res => {
        var seckillList = res.data;
        var loadend = seckillList.length < that.limit;
        that.page++;
        that.ranking = that.ranking.concat(seckillList);
        that.pageloading = false;
        that.loadend = loadend;
      }).catch(err => {
        this.$util.Tips({
          title: err
        });
      });
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
	},
	agentStore(type) {
    if (this.loadend) return;
    if(this.show_type == 4 || this.show_type == 3){
      this.getYejiRanking();
    }else if(this.show_type == 5){
       this.getDainke();
    }else if(this.show_type == 6){
       this.getProjectRanking();
    }else if(this.show_type < 3 || this.show_type == 7) {
      let data = {
        orderby: this.orderby,
        data: this.dataRange,
        show_type: this.show_type,
        ...this.getStoreFilterParams(),
      }
      getAgentStore(data).then(res => {
        if (Array.isArray(res.data)) return;
        this.ranking = res.data.ranking;
        this.roundCake = res.data.chart.bing_data.length;
        if (type != 'rank') {
          let series = [];
          let data = {
            data: res.data.chart.bing_data
          }
          series.push(data)
          let obj = {
            series: series
          };
          this.chartData = obj;
        }
      }).catch(err => {
        this.$util.Tips({
          title: err
        })
      })
    }
	}
  },
};
</script>

<style lang="scss" scoped>
.dateOut{
  display: flex;justify-content: space-between;align-items: center
}
.dataRange{
  font-size: 20rpx;
}
.the_kuai{
  width: 33%;
  text-align: center;
}
.the_kuai_left{
  width: 33%;
  text-align: left;
}
.the_kuai_right{
  width: 33%;
  text-align: right;
}
.kuai_out{
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
.w-198{
  width: 60% !important;
}
.w-170{
  width: 40% !important;
  text-align: right;
}
  .footer{
	  height: calc(30rpx+ constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
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
