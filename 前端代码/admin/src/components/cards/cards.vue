<template>
<!-- 卡片样式 -->
  <div>
    <!--v-if="item.count && item.count!='0.00'"-->
    <Row type="flex" align="middle" :gutter="16" class="ivu-mt">
      <Col
        :xs="24"
        :sm="24"
        :md="12"
        :lg="12"
        :xl="8"
        :xxl="{ span: item.col }"
        class="ivu-mb"
        v-for="(item, index) in cardLists"
        :key="index"
      >
        <Card shadow :padding="0" class="card_cent">
          <div class="card_box">
            <div
              class="card_box_cir"
              :class="{
                one: index % 5 == 0,
                two: index % 5 == 1,
                three: index % 5 == 2,
                four: index % 5 == 3,
                five: index % 5 == 4,
              }"
            >
              <div
                class="card_box_cir1"
                :class="{
                  one1: index % 5 == 0,
                  two1: index % 5 == 1,
                  three1: index % 5 == 2,
                  four1: index % 5 == 3,
                  five1: index % 5 == 4,
                }"
              >
                <span
                  class="iconfont"
                  :class="item.className"
                  v-if="item.type"
                ></span>
                <Icon :type="item.className" v-else />
              </div>
            </div>
            <div class="card_box_txt">
              <template v-if="item.split_display">
                <span class="sp1">{{ item.normal_count != null ? item.normal_count : (item.count || 0) }}</span>
                <span class="sp-sub">院：{{ item.salon_count != null ? item.salon_count : 0 }}</span>
                <span class="sp-sub">合：{{ item.total_count != null ? item.total_count : (item.count || 0) }}</span>
                <span class="sp2" v-text="item.name"></span>
              </template>
              <template v-else>
                <span class="sp1" v-text="item.count || 0"></span>
                <span class="sp2" v-text="item.name"></span>
              </template>
            </div>
          </div>
        </Card>
      </Col>
    </Row>
  </div>
</template>

<script>
export default {
  name: 'cards',
  data() {
    return {};
  },
  props: {
    cardLists: Array
  },
  data() {
    return {};
  },
  methods: {},
  created() {}
};
</script>

<style scoped lang="stylus">

/deep/.ivu-card-body{
	min-height: 110px;
	height: auto;
}
/deep/.card_box{
	padding: 0 25px;
}
.card_box_cir1 >>> .ivu-icon {
  font-size: 26px;
  color: #fff;
}

.one {
  background: #E4ECFF;
}

.two {
  background: #FFF3E0;
}

.three {
  background: #EAF9E1;
}

.four {
  background: #FFEAF4;
}

.five {
  background: #F1E4FF;
}

.one1 {
  background: #4D7CFE;
}

.two1 {
  background: #FFAB2B;
}

.three1 {
  background: #6DD230;
}

.four1 {
  background: #FF85C0;
}

.five1 {
  background: #B37FEB;
}

.card_box {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  /* justify-content: center */
  padding: 25px;
  box-sizing: border-box;
  border-radius: 4px;

  .card_box_cir {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 20px;
    display: flex;
    justify-content: center;
    align-items: center;

    .card_box_cir1 {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      display: flex;
      justify-content: center;
      align-items: center;
    }
  }

  .card_box_txt {
    .sp1 {
      display: block;
      color: #252631;
      font-size: 24px;
      line-height: 1.2;
      word-break: break-all;
    }

    .sp-sub {
      display: block;
      color: #667085;
      font-size: 13px;
      line-height: 1.35;
      margin-top: 2px;
    }

    .sp2 {
      display: block;
      color: #98A9BC;
      font-size: 12px;
      margin-top: 4px;
    }
  }
}

.card_box_cir1 .iconfont {
  color: #fff;
  font-size: 23px;
}
@media (min-width: 1600px) {
  .ivu-col-span-xxl-4-8 {
    display: block;
    flex: 0 0 20%;
    max-width: 20%;
  }
}
</style>
