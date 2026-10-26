<template>
  <div class="users">
    <div class="acea-row row-top content">
      <div class="left" :style="colorStyle">
        <div class="phone">
          <Tooltip
              :class="{ 'active-tip': active === 0 }"
              content="商品信息"
              placement="left-start"
              always
              theme="light"
          >
            <productInfo
                :showShare="productCardInfo.openShare"
                :showDot="productCardInfo.swiperDot"
                :isOpen="productCardInfo.isOpen"
                class="cup module"
                :class="{ active: active === 0 }"
                @click.native="editMode(0)"
            ></productInfo>
          </Tooltip>
          <Tooltip
              :class="{ 'active-tip': active === 1 }"
              content="会员信息"
              placement="left-start"
              always
              theme="light"
          >
            <div class="px-10 cup module" :class="{ active: active === 1 }"
                 @click="editMode(1)">
              <div class="h-40 rd-8px bg--w111-FFF0D1 flex-between-center px-10 mb-8">
                <div class="flex-y-center">
                  <img src="@/assets/images/vip_leval.png" class="w-18 h-18" />
                  <div class="pl-8">
                    <span class="fs-12 text--w111-7E4B06">开通 SVIP会员 预计省</span>
                    <span class="text-primary-con fs-14">29</span>
                    <span class="fs-12 text--w111-7E4B06">元</span>
                  </div>
                </div>
                <div class="fs-12 text--w111-7E4B06">
                  <span>立即开通</span>
                  <span class="mobiconfont icon-ic_rightarrow fs-12"></span>
                </div>
              </div>
            </div>
            <div class="mask" v-if="!productCardInfo.showSvip" @click="editMode(1)">已隐藏</div>
          </Tooltip>
          <Tooltip
              :class="{ 'active-tip': active === 2 }"
              content="排行榜"
              placement="left-start"
              always
              theme="light"
          >
            <div class="px-10 cup module" :class="{ active: active === 2 }"
                 @click="editMode(2)">
              <div class="h-40 rd-8px bg--w111-fff flex-between-center px-10 mb-8">
                <div class="flex-y-center">
                  <img src="@/assets/images/cup_icon.png" class="w-16 h-16" />
                  <img src="@/assets/images/ranking.png" class="w-38 h-12 mx-4" />
                  <span class="fs-13 text--w111-333">紧致抗皱套装热卖榜·第2名</span>
                </div>
                <span class="mobiconfont icon-ic_rightarrow fs-12 text--w111-666"></span>
              </div>
            </div>
            <div class="mask" v-if="!productCardInfo.showRank" @click="editMode(2)">已隐藏</div>
          </Tooltip>
          <Tooltip
              :class="{ 'active-tip': active === 8 }"
              content="分销信息"
              placement="left-start"
              always
              theme="light"
          >
            <spreadCard
                :showService="productCardInfo.showSpread"
                class="cup module"
                :class="{ active: active === 8 }"
                @click.native="editMode(8)"
            ></spreadCard>
            <div class="mask" v-if="!productCardInfo.showSpread" @click="editMode(8)">已隐藏</div>
          </Tooltip>
          <Tooltip
              :class="{ 'active-tip': active === 3 }"
              content="商品参数"
              placement="left-start"
              always
              theme="light"
          >
            <servieCard
                :showService="productCardInfo.showService"
                class="cup module"
                :class="{ active: active === 3 }"
                @click.native="editMode(3)"
            ></servieCard>
          </Tooltip>
          <Tooltip
              :class="{ 'active-tip': active === 4 }"
              content="商品评价"
              placement="left-start"
              always
              theme="light"
          >
            <replyCard
                :showReply="productCardInfo.showReply"
                class="cup module"
                :class="{ active: active === 4 }"
                @click.native="editMode(4)"
            ></replyCard>
          </Tooltip>
          <Tooltip
            :class="{ 'active-tip': active === 8 }"
            content="种草秀"
            placement="left-start"
            always
            theme="light"
          >
            <community
              :showCommunity="productCardInfo.showCommunity"
              class="cup module"
              :class="{ active: active === 9 }"
              @click.native="editMode(9)"
            ></community>
          </Tooltip>
          <Tooltip
              :class="{ 'active-tip': active === 5 }"
              content="搭配购"
              placement="left-start"
              always
              theme="light"
          >
            <matchCard
                :showMatch="productCardInfo.showMatch"
                class="cup module"
                :class="{ active: active === 5 }"
                @click.native="editMode(5)"
            ></matchCard>
          </Tooltip>
          <Tooltip
              :class="{ 'active-tip': active === 6 }"
              content="优品推荐"
              placement="left-start"
              always
              theme="light"
          >
            <div class="px-10 cup module" :class="{ active: active === 6 }"
                 @click="editMode(6)">
              <div class="rd-12rpx bg--w111-fff py-16">
                <div class="px-10 fs-15 fw-500 text--w111-333">优品推荐</div>
                <div class="grid-column-3 grid-gap-10px mt-14 px-10">
                  <div class="" v-for="item in 6" :key="item">
                    <img :src="pic" class="w-full h-104px rd-8px" />
                    <div class="w-full line-1 mt-6 fs-13">商品名称商品</div>
                    <div class="fs-14 red">¥199.00</div>
                  </div>
                </div>
              </div>
            </div>
            <div class="mask" v-if="!productCardInfo.showRecommend" @click="editMode(6)">已隐藏</div>
          </Tooltip>
          <Tooltip
              :class="{ 'active-tip': active === 7 }"
              content="底部菜单"
              placement="left-start"
              always
              theme="light"
          >
            <div class="px-10 cup module" :class="{ active: active === 7 }"
                 @click="editMode(7)">
              <div class="bg--w111-fff h-104 flex text--w111-333 pl-10 pr-10">
                <div class="flex text--w111-333">
                  <div class="flex-col flex-center mr-14" v-if="productCardInfo.menuList.includes(0)">
                    <span class="mobiconfont icon-ic_customerservice fs-20"></span>
                    <span class="fs-9">客服</span>
                  </div>
                  <div class="flex-col flex-center mr-14" v-if="productCardInfo.menuList.includes(1)">
                    <span class="mobiconfont icon-ic_star fs-40"></span>
                    <span class="fs-9">收藏</span>
                  </div>
                  <div class="flex-col flex-center mr-14" v-if="productCardInfo.menuList.includes(2)">
                    <span class="mobiconfont icon-ic_ShoppingCart fs-40"></span>
                    <span class="fs-9">购物车</span>
                  </div>
                  <div class="flex-col flex-center mr-14" v-if="productCardInfo.menuList.includes(3)">
                    <span class="mobiconfont icon-ic_mall fs-40"></span>
                    <span class="fs-9">首页</span>
                  </div>
                  <div class="flex-col flex-center mr-14" v-if="productCardInfo.menuList.includes(4)">
                    <span class="mobiconfont icon-ic_transmit1 fs-40"></span>
                    <span class="fs-9">分享</span>
                  </div>
                </div>
                <div class="flex-between-center flex-1 grid-gap-8px">
                  <div class="w-full h-36 flex-center join_cart rd-18px text--w111-fff fs-13" v-if="productCardInfo.showCart">加入购物车</div>
                  <div class="w-full h-36 flex-center pay_btn rd-18px text--w111-fff fs-13">立即购买</div>
                </div>
              </div>
            </div>
          </Tooltip>
        </div>
      </div>
      <div class="right">
        <infoCardConfig :showType="active" :productCardInfo="productCardInfo"></infoCardConfig>
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
import productInfo from './template/Product/infoCard';
import servieCard from './template/Product/serviceCard'
import replyCard from './template/Product/replyCard'
import matchCard from './template/Product/matchCard'
import spreadCard from './template/Product/spreadCard'
import infoCardConfig from './template/Product/templateConfig'
import community from './template/Product/community'
import { getProductDetail, saveProductDetail} from '@/api/diy';
import { mapState } from "vuex";
export default {
  name: 'ProductPage',
  components: {
    productInfo,
    servieCard,
    replyCard,
    matchCard,
    spreadCard,
    infoCardConfig,
    community,
  },
  props: {},
  data() {
    return {
      active:0,
      pic: require('@/assets/images/product_diy.png'),
      productCardInfo: {
        navList: [0, 1, 2, 3, 4], // 顶部菜单内容
        openShare: 1, //是否开启分享
        pictureConfig:0, //轮播图模式 0 固定方图 1 高度自适应
        swiperDot: 1, //是否展示轮播指示点
        isOpen:[0, 1 ,2], //是否展示 0 划线价 1 累计销量 2 库存
        showSvip: 1, //是否展示付费会员卡片
        showRank: 1, // 是否展示 排行榜卡片
        showService:[0, 1, 2, 3, 4], //服务区卡片 0 营销活动入口 1 sku选择 2 服务保障 3 参数 4门店
        showReply: 1, //是否展示评论区
        replyNum: 3, //评论数量
        showMatch: 1, //是否展示搭配购
        matchNum: 3, //搭配套餐数量
        showRecommend: 1, //是否展示推荐商品
        recommendNum: 12, //推荐商品数量
        menuList: [0,1,2], //底部左侧菜单
        showCart: 1, //是否显示购物车
        showSpread: 1, //是否显示分销信息
        spreadScope: 0, //分销信息展示 0 全部用户 1 仅分销员 2 普通用户
        showCommunity: 0, //是否显示种草
      },
      price: [], // 价格
      value: '',
      current: 1,
      colorStyle: '',
      order: {},
    }
  },
  computed:{
    ...mapState("admin/layout", ["menuCollapse", "isMobile"]),
  },
  created() {
    this.getProductDetail()
  },
  methods: {
    editMode(type) {
      this.active = type;
    },
    // 获取商品详情
    getProductDetail() {
      getProductDetail().then((res) => {
        this.productCardInfo = res.data;
      })
    },
    onSubmit() {
      this.$emit('parentFun', true)
      saveProductDetail({ product_detail_diy: this.productCardInfo })
        .then((res) => {
          this.$Message.success(res.msg)
        })
        .catch((err) => {
          this.$Message.error(err.msg)
        })
        .finally(() => {
          this.$emit('parentFun', false)
        })
    },
    isShowDataItem(value) {
      let result = this.optionstList.price_type.some((ele) => ele == value)
      return result
    },
  },
}
</script>
<style scoped lang="stylus">
/* 定义滑块 内阴影+圆角 */
::-webkit-scrollbar-thumb {
  -webkit-box-shadow: inset 0 0 6px #ddd;
}

::-webkit-scrollbar {
  width: 4px !important; /* 对垂直流动条有效 */
}

.content {
  display: flex;
  min-height: 600px;
  background-color: #F0F2F5;
  padding-right: 400px;

  .left {
    flex-direction: column;
    margin: 0 auto;

    .module {
      border: 2px solid #F5F5F5;
    }

    .module.active {
      border: 2px solid #1890FF;
    }

    .phone {
      margin-top: 20px;
      width: 375px;
      background-color: #F5F5F5;

      /deep/ .ivu-tooltip {
        width: 100%;
      }
    }
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
.w-16{
  width: 16px;
}
.h-16{
  height: 16px;
}
.w-38{
  width: 38px;
}
.h-12{
  height: 12px;
}
.mx-4{
  margin: 0 4px;
}
.join_cart{
  background-color: #FE960F;
}
.pay_btn{
  background: #e93323;
}
/deep/ .ivu-tooltip-inner {
  box-shadow: none;
}

/deep/ .active-tip .ivu-tooltip-light .ivu-tooltip-inner {
  background-color: #1890FF;
  color: #fff;
}

/deep/ .ivu-tooltip-light.ivu-tooltip-popper {
  z-index: 10;
}

/deep/ .active-tip .ivu-tooltip-light.ivu-tooltip-popper[x-placement^='left'] .ivu-tooltip-arrow:after {
  border-left-color: #1890FF;
}

/deep/ .main {
  position: relative;
}

/deep/ .mask {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  color: #fff;
  z-index: 9;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100% !important;
}
.mb-50{
	margin-bottom: 50px;
}
</style>