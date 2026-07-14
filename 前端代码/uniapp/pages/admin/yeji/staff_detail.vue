<template>
  <view class="pagebox">
	  <!-- #ifdef MP || APP-PLUS -->
	  <NavBar titleText="实际收款金额(元)" :iconColor="iconColor" :textColor="iconColor" :bagColor="bagColor" :isScrolling="isScrolling" showBack></NavBar>
	  <!-- #endif -->
    <view class="headerBg">
      <view :style="{ height: `${getHeight.barTop}px` }"></view>
      <view :style="{ height: `${getHeight.barHeight}px` }"></view>
      <view class="inner"></view>
    </view>
    <view class="search fixed z-10 acea-row row-between-wrapper w-full  pl-20 pr-20">
      <view class="top_kuai">
        <view>
            <view>实发工资(元)</view>
           <view class="money">{{ salary.shifagongzi }}</view>
        </view>
        <picker
            mode="multiSelector"
            :value="pickerIndex"
            :range="pickerRange"
            @change="onPickerChange"
            @cancel="showPicker = false"
        >
          <view class="date" v-if="form.date !=''">{{ form.date }}</view>
          <view class="date" v-else>选择日期</view>
        </picker>
      </view>
    </view>
    <view class="kuai_center">
      <view class="link_kuai" v-for="(item,index) in salary.detail">
             <view>{{ item.name }}</view>
             <view class="price">{{ item.value }}</view>
      </view>
    </view>
  </view>
</template>
<script>
// #ifdef MP || APP-PLUS
import NavBar from '@/components/NavBar.vue';
// #endif
import {
  salary
} from '@/api/yeji.js'
export default {
  name: 'agent',
  components: {
  	// #ifdef MP ||APP-PLUS
  	NavBar,
  	// #endif
  },
  data() {
    return {
      showPicker: false, // 控制选择器显示/隐藏
      pickerIndex: [0, 0], // 默认选中索引（年列、月列）
      pickerRange: [[], []], // 选择器数据：[年份列表, 月份列表]
      selectedMonth: '', // 最终选中的年月（格式：yyyy-mm）
      startYear: 2026, // 可选起始年
      endYear: new Date().getFullYear(),// 关键：动态获取今年（如2026）
      bagColor: 'linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%)',
      iconColor: '#FFFFFF',
      isScrolling: false,
      getHeight: this.$util.getWXStatusHeight(),
      total_price:0,
      salary:[],
      form:{
        date:'',
        staff_id:0
      }
    };
  },
  onLoad(option) {
    this.form.date=option.date || 0;
    this.showDate(this.form.date);
    this.form.staff_id=option.staff_id || 0;
    this.initPickerData();
    this.getSalary();
  },
  methods: {
    showDate(dateParam){
      // 3. 优先使用URL参数，无参数/格式错误则用今年当月
      if (dateParam && /^\d{4}-\d{2}$/.test(dateParam)) {
        // 解析参数为年、月（如2026-03 → year=2026, month=3）
        const [year, month] = dateParam.split('-').map(Number);
        // 校验年份是否在可选范围内（startYear ~ endYear）
        if (year >= this.startYear && year <= this.endYear && month >= 1 && month <= 12) {
          // 计算年份在列表中的索引（如2026-2020=6 → 索引6）
          const yearIdx = year - this.startYear;
          // 计算月份索引（月份是1-12 → 索引0-11）
          const monthIdx = month - 1;
          // 设置默认选中索引
          this.pickerIndex = [yearIdx, monthIdx];
          // 设置展示的年月
          this.form.date = dateParam;
          return; // 已设置参数对应的年月，无需走兜底逻辑
        }
      }

      // 4. 兜底：参数无效/无参数时，选中今年当月
      const now = new Date();
      const currentYear = now.getFullYear();
      const currentMonth = now.getMonth() + 1;
      const yearIdx = currentYear - this.startYear;
      const monthIdx = currentMonth - 1;
      this.pickerIndex = [yearIdx, monthIdx];
      this.form.date = `${currentYear}-${String(currentMonth).padStart(2, '0')}`;
    },
    // 初始化年、月数据
    initPickerData() {
      // 生成年份列表（如2020-2026）
      const yearList = [];
      for (let y = this.startYear; y <= this.endYear; y++) {
        yearList.push(y + '年');
      }
      // 生成月份列表（01-12月，补0）
      const monthList = [];
      for (let m = 1; m <= 12; m++) {
        monthList.push(String(m).padStart(2, '0') + '月');
      }
      this.pickerRange = [yearList, monthList];
    },
    // 选择年月后触发
    onPickerChange(e) {
      const [yearIdx, monthIdx] = e.detail.value;
      // 解析选中的年、月
      const year = this.startYear + yearIdx;
      const month = String(monthIdx + 1).padStart(2, '0');
      // 拼接为yyyy-mm格式（适配你的业务）
      this.form.date = `${year}-${month}`;
      this.showPicker = false;
      this.getSalary();
    },
    dataPickerTap(){
      this.$refs.dataPicker.show();
    },
    changeData(e){
      debugger
      this.customizeData = e;
      let start = e[0].split('-');
      let end = e[1].split('-');
      this.dataRange = `${start[0]}/${start[1]}/${start[2]}`+'-'+`${end[0]}/${end[1]}/${end[2]}`;
      this.dataShow = false;
    },
    getSalary(){
       let that=this;
      salary(this.form).then(function (res){
              that.salary=res.data
       })
     }
  },
};
</script>

<style lang="scss" scoped>
.date{
  color: #2A7EFB;
  background: #FFFFFF;
  width: 100px;
  text-align: center;
  height: 40px;
  line-height: 40px;
  border-radius: 5px;
}
.kuai_center{
  margin-top: 270rpx;
  background-color: #fff;
}
.link_kuai{
  display: inline-block;
  padding: 20rpx 20rpx;
  width: 49%;
  //border-left: 1px solid #d5d2d2;
  border-bottom: 1px solid #d5d2d2;
  text-align: left;
}
.price{
   color: #8d8686;
   margin-top: 20rpx;
}
.money{
   font-size: 60rpx;
}
.top_kuai{
  padding:50rpx 60rpx;
  width: 100%;
  color: #fff;
  display: flex;
  justify-content: space-between;
}
.money{
  margin-top: 20rpx;
}
.kuai_out{
  display: flex;
  gap:20px;
  margin-bottom: 20px;
  margin-top: 20px;
  font-size: 24rpx;
}
.kuai{
  padding-bottom: 10px;
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
    padding-bottom: 10px;
  }
  .active{
    border-radius: 120rpx;
    color: #2A7EFB;
    background: #FFFFFF;
  }
}
</style>
