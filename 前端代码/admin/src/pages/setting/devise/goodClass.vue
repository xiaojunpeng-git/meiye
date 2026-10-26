<template>
  <div class="goodClass flex">
    <div class="flex-1">
      <el-carousel
          ref="carousel"
          :interval="4000"
          :autoplay="false"
          :initial-index="activeIndex"
          type="card"
          trigger="click"
          height="700px" @change="swiperChange">
        <el-carousel-item v-for="(item, index) in level == 2 ? list1 : list2" :key="index">
          <img class="cate-pic" :class="activeIndex == index ? 'blue-border' : ''" :src="item.image"/>
        </el-carousel-item>
      </el-carousel>
    </div>
    <div class="w-400"></div>
    <div class="right">
      <div class="main">
        <div class="main-header bg-fff">
          <div class="main-title">
            <span>商品分类</span>
          </div>
        </div>
        <div class="main-content bg-fff">
          <div class="title">页面设置</div>
          <div class="form">
            <div class="form-item mt-20">
              <div class="form-label">分类等级</div>
              <div class="form-value ml-14">
                <RadioGroup v-model="level" @on-change="levelChange">
                  <Radio :label="2">二级分类</Radio>
                  <Radio :label="3">三级分类</Radio>
                </RadioGroup>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <Card
        :bordered="false"
        dis-hover
        class="fixed-card"
        :style="{ left: `${!menuCollapse ? '236px' : isMobile ? '0' : '60px'}` }"
    >
      <div class="acea-row row-center-wrapper">
        <Button class="bnt" type="primary" @click="onSubmit">保存</Button>
      </div>
    </Card>
  </div>
</template>

<script>
import {saveCategoryApi, getCategoryApi} from "@/api/diy";
import {mapState} from "vuex";

export default {
  name: 'goodClass',
  data() {
    return {
      list1: [
        {image: require('@/assets/images/cateClass/cate-1-2.png'), val: '1-2'},
        {image: require('@/assets/images/cateClass/cate-2-2.png'), val: '2-2'},
        {image: require('@/assets/images/cateClass/cate-3-2.png'), val: '3-2'},
        {image: require('@/assets/images/cateClass/cate-2-2-1.png'), val: '2-2-1'},
        {image: require('@/assets/images/cateClass/cate-3-2-1.png'), val: '3-2-1'},
        {image: require('@/assets/images/cateClass/cate-4.png'), val: '4-2'},
      ],
      list2: [
        {image: require('@/assets/images/cateClass/cate-1-3.png'), val: '1-3'},
        {image: require('@/assets/images/cateClass/cate-2-3.png'), val: '2-3'},
        {image: require('@/assets/images/cateClass/cate-3-3.png'), val: '3-3'},
        {image: require('@/assets/images/cateClass/cate-4.png'), val: '4-3'},
      ],
      level: 2,
      activeIndex: 0,
      activeStyle: '-1'
    }
  },
  computed: {
    ...mapState("admin/layout", ["menuCollapse", "isMobile"]),
  },
  created() {
    this.getInfo();
  },
  methods: {
    getInfo() {
      getCategoryApi().then(res => {
        this.level = res.data.level;
        this.$refs.carousel.setActiveItem(res.data.index);
      })
    },
    levelChange(e){
      this.$set(this,'activeIndex',0);
    },
    swiperChange(e) {
      this.activeIndex = e;
    },
    onSubmit(num) {
      saveCategoryApi({product_category_diy :{level: this.level,index: this.activeIndex}}).then(res => {
        this.$Message.success(res.msg);
      }).catch((err) => {
        this.$Message.error(err.msg);
      })
    }
  }
}
</script>
<style scoped lang="stylus">
.cate-pic {
  width: 300px;
}

.el-carousel__item {
  display: flex;
  justify-content: center;
  align-items: center;
}

.right {
  width: 400px;
  position: fixed;
  right: 0;
  top: 80px;
  height 100%;
  overflow-y scroll;
  background #fff;
  padding-bottom 150px;
}

.main {
  .main-header {
    font-size: 16px;
    padding: 20px 15px;
    margin: 6px 0 0;
    font-weight: 500;
    color: #333333;
    line-height: 16px;
    background: #fff;
  }

  .main-content {
    background: #fff;
    padding: 20px 15px;
    margin-top: 6px;
    border-top: 6px solid #f0f2f5;

    .title {
      font-size: 14px;
      font-weight: 400;
      color: #333333;
      line-height: 14px;
      margin-bottom: 20px;
    }

    .form {
      .form-item:last-child {
        margin-bottom: 0;
      }

      .form-item {
        display: flex;
        font-size: 12px;
        font-weight: 400;
        line-height: 12px;

        .form-label {
          color: #999999;
          line-height: 17px;
          margin-right: 46px;
          white-space: nowrap;
        }

        .form-value {
          color: #666666;

          /deep/ .ivu-radio-wrapper {
            margin-right: 43px;
          }

          /deep/ .ivu-checkbox-wrapper {
            margin-bottom: 22px;
            width: 84px;
            font-size: 12px;
          }
        }
      }
    }
  }
}

.fixed-card {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 200px;
  z-index: 99;
  box-shadow: 0 -1px 2px rgb(240, 240, 240);
  padding-right: 400px;
}
  .blue-border{
    border: 2px solid #1890FF;
  }
</style>
