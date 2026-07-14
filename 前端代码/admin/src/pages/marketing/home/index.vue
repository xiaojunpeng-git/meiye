<template>
  <div>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="header"><span class="title">营销玩法</span>吸粉、老客带新客，提高下单转化率</div>
      <div class="list acea-row row-middle">
        <div class="item acea-row row-middle" v-for="(item, index) in markePlay" :key="index" @click="tapMarke(item)">
          <div class="iconfont" :class="item.icon"></div>
          <div class="text">
            <div class="name">{{item.name}}</div>
            <div class="line2">{{item.info}}</div>
          </div>
        </div>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="header"><span class="title">营销互动</span>增强互动，留存复购</div>
      <div class="list acea-row row-middle">
        <div class="item acea-row row-middle" v-for="(item, index) in markeInteract" :key="index" @click="tapMarke(item)">
          <div class="iconfont" :class="item.icon"></div>
          <div class="text">
            <div class="name">{{item.name}}</div>
            <div class="line2">{{item.info}}</div>
          </div>
        </div>
      </div>
    </Card>
  </div>
</template>

<script>
import Setting from "@/setting";
export default {
  data () {
    return {
      roterPre: Setting.roterPre,
      markePlay:[
        {
          icon:'iconyouhuiquan3',
          name:'优惠券',
          info:'包含满减券、满折券、会员券等',
          path:'/marketing/store_coupon_issue/index'
        },
        {
          icon:'iconmiaoshahuodong',
          name:'秒杀活动',
          info:'超级好货，限时秒杀',
          path:'/marketing/store_seckill/index'
        },
        {
          icon:'iconpintuanhuodong',
          name:'拼团活动',
          info:'邀请好友拼团，共享优惠',
          path:'/marketing/store_combination/index'
        },
        {
          icon:'iconkanjiahuodong',
          name:'砍价活动',
          info:'邀请好友砍价，裂变快速传播',
          path:'/marketing/store_bargain/index'
        },
        {
          icon:'iconxianshizhekou',
          name:'限时折扣',
          info:'限定时间内可指定商品进行折扣活动',
          path:'/marketing/discount/list'
        },
        {
          icon:'iconmansonghuodong',
          name:'满送活动',
          info:'满足门槛可获得优惠券，赠品或积分',
          path:'/marketing/discount/give'
        },
        {
          icon:'iconmanjianmanzhe',
          name:'满减满折',
          info:'可阶梯或循环满减满折',
          path:'/marketing/discount/full_discount'
        },
        {
          icon:'icondinjiannzhe',
          name:'第n件n折',
          info:'第2件半价，买1送1，自定义优惠',
          path:'/marketing/discount/pieces_discount'
        },
        {
          icon:'iconjifenshangcheng',
          name:'商城积分',
          info:'利用积分调动现有用户积极性，提高用户活跃度，增加用户粘性',
          path:'/marketing/store_integral/index'
        },
        {
          icon:'iconyuechongzhi',
          name:'余额充值',
          info:'商城余额充值助力提高客户粘性与活跃度',
          path:'/marketing/balance_recharge'
        },
        {
          icon:'iconyouhuitaocan',
          name:'套餐搭配',
          info:'提升商城的客单价和销售额',
          path:'/marketing/store_discounts/index'
        },
        {
          icon:'iconxinrenli2',
          name:'新人礼',
          info:'多重好礼引导注册',
          path:'/operation/newcomer'
        },
        {
          icon:'iconhuiyuanjihuo',
          name:'会员激活',
          info:'引导开卡粘性更强',
          path:'/operation/card'
        }
      ],
      markeInteract:[
        {
          icon:'iconmeiriqiandao',
          name:'每日签到',
          info:'用户每日签到领取各种奖励 增加用户黏性',
          path:'/marketing/sign_config'
        },
        {
          icon:'iconjiugonggechoujiang',
          name:'抽奖活动',
          info:'积分抽奖 趣味互动 提升积分价值',
          path:'/marketing/lottery/index'
        },
        {
          icon:'iconzhibo',
          name:'直播管理',
          info:'直播带货，所见即所得，实现红利变现',
          path:'/marketing/live/live_room'
        },
        {
          icon:'iconduanshipin1',
          name:'种草社区',
          info:'利用碎片化时间，实现高质量带货',
          path:'/community/content'
        },
        {
          icon:'iconwenzhang',
          name:'文章管理',
          info:'文章可关联商品进行推荐',
          path:'/cms/article/index'
        },
        {
          icon:'iconhuodongbeijingtu',
          name:'活动背景图',
          info:'增加活动氛围，丰富商城内容量',
          path:'/marketing/activity_background'
        },
        {
          icon:'iconhuodongbiankuang',
          name:'活动边框',
          info:'可批量展示在商品图上，增加氛围感',
          path:'/marketing/activity_frame'
        },
        {
          icon:'iconqudaoma',
          name:'渠道码',
          info:'统计营销渠道数据，推广更精准',
          path:'/marketing/channel_code'
        }
      ]
    }
  },
  mounted(){
  },
  methods:{
    tapMarke(item){
      this.$router.push({
        path: this.roterPre + item.path,
        query: {
          type:item.type?item.type:''
        }
      });
    }
  }
}
</script>

<style scoped lang="less">
.list{
  padding: 20px 0 1px 0;
  .item{
    width: 315px;
    height: 102px;
    background: #F5F5F5;
    border-radius: 6px;
    margin: 0 0 20px 20px;
    padding: 0 20px 0 24px;
    border: 1px solid #F5F5F5;
    cursor: pointer;
    &:hover{
      background: rgba(44,139,239,0.1);
      border-color: #2D8CF0;
    }
    .iconfont{
      font-size: 50px;
      color: #2D8CF0;
    }
    .text{
      margin-left: 13px;
      color: #999999;
      font-size: 12px;
      width: 205px;
      .name{
        color: #333333;
        font-size: 16px;

      }
    }
  }
}
.header{
  height: 44px;
  border-bottom: 1px solid #F5F5F5;
  padding: 0 20px;
  line-height: 44px;
  font-size: 12px;
  color: #333;
  .title{
    font-size: 14px;
    font-weight: 600;
    margin-right: 9px;
  }
}
</style>