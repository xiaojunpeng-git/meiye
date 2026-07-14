<template>
  <div class="detail">
    <div class="section">
      <div class="title">收货信息</div>
      <ul class="list">
        <li class="item">
          <div>收货人：</div>
          <div class="value">{{ orderDatalist.orderInfo.real_name }}</div>
        </li>
        <li class="item">
          <div>收货电话：</div>
          <div class="value">{{ orderDatalist.orderInfo.user_phone }}</div>
        </li>
      </ul>
    </div>
    <div class="section">
      <div class="title">用户信息</div>
      <ul class="list">
        <li class="item">
          <div>用户昵称：</div>
          <div class="value">
            {{
              orderDatalist.userInfo.uid
                ? orderDatalist.userInfo.nickname
                : "游客"
            }}
          </div>
        </li>
        <li class="item">
          <div>绑定电话：</div>
          <div class="value">{{ orderDatalist.userInfo.phone }}</div>
        </li>
      </ul>
    </div>
    <div class="section" v-if="orderDatalist.orderInfo.product_type == 6">
      <div class="title">预约信息</div>
      <ul class="list">
    	<li class="item">
    	  <div>服务类型：</div>
    	  <div class="value">{{orderDatalist.orderInfo.reservation_type==3?'上门服务':'到店服务'}}</div>
    	</li>
    	<li class="item">
    	  <div>预约模式：</div>
    	  <div class="value">{{orderDatalist.orderInfo.reservation_time_id>0?'购买时预约':'先买后约'}}</div>
    	</li>
    	<li class="item" v-if="orderDatalist.orderInfo.reservation_time">
    	  <div>预约日期：</div>
    	  <div class="value">{{orderDatalist.orderInfo.reservation_time}}</div>
    	</li>
    	<li class="item" v-if="orderDatalist.orderInfo.reservation_show_time">
    	  <div>预约时段：</div>
    	  <div class="value">{{orderDatalist.orderInfo.reservation_show_time}}</div>
    	</li>
    	<li class="item">
    	  <div>预约人：</div>
    	  <div class="value">{{orderDatalist.orderInfo.real_name}}</div>
    	</li>
    	<li class="item">
    	  <div>预约电话：</div>
    	  <div class="value">{{orderDatalist.orderInfo.user_phone}}</div>
    	</li>
      </ul>
      <div class="address acea-row row-middle" v-if="orderDatalist.orderInfo.user_address.trim()">
        <div>预约地址：</div>
        <div>{{orderDatalist.orderInfo.user_address}}</div>
      </div>
    </div>
	<div class="section">
      <div class="title">订单信息</div>
      <ul class="list">
        <li class="item">
          <div>创建时间：</div>
          <div class="value">{{ orderDatalist.orderInfo._add_time }}</div>
        </li>
        <li class="item">
          <div>商品总数：</div>
          <div class="value">{{ orderDatalist.orderInfo.total_num }}</div>
        </li>
        <li class="item">
          <div>商品总价：</div>
          <div class="value">￥{{ orderDatalist.orderInfo.total_price }}</div>
        </li>
		<li class="item" v-if="orderDatalist.orderInfo.pay_integral">
		  <div>实付积分：</div>
		  <div class="value">{{ orderDatalist.orderInfo.pay_integral }}</div>
		</li>
		<li class="item" v-if="orderDatalist.orderInfo.first_order_price>0">
		  <div>首单优惠：</div>
		  <div class="value">￥{{ orderDatalist.orderInfo.first_order_price }}</div>
		</li>
        <li class="item">
          <div>优惠券金额：</div>
          <div class="value">￥{{ orderDatalist.orderInfo.coupon_price }}</div>
        </li>
        <li class="item">
          <div>积分抵扣：</div>
          <div class="value">
            ￥{{ orderDatalist.orderInfo.deduction_price || 0.0 }}
          </div>
        </li>
        <li
          class="item"
          v-if="parseFloat(orderDatalist.orderInfo.use_integral)"
        >
          <div>使用积分：</div>
          <div class="value">
            {{ parseFloat(orderDatalist.orderInfo.use_integral) }}
          </div>
        </li>
        <li class="item">
          <div>支付邮费：</div>
          <div class="value">￥{{ orderDatalist.orderInfo.pay_postage }}</div>
        </li>
        <li class="item">
          <div>会员商品优惠：</div>
          <div class="value">
            ￥{{ orderDatalist.orderInfo.vip_true_price || 0.0 }}
          </div>
        </li>
        <li v-if="orderDatalist.orderInfo.first_order_price != 0" class="item">
          <div>新人首单优惠：</div>
          <div class="value">
            ￥{{ orderDatalist.orderInfo.first_order_price }}
          </div>
        </li>
        <li
          class="item"
          v-if="
            orderDatalist.orderInfo.shipping_type === 2 &&
              orderDatalist.orderInfo.refund_status === 0 &&
              orderDatalist.orderInfo.paid === 1
          "
        >
          <div>门店名称：</div>
          <div class="value">{{ orderDatalist.orderInfo._store_name }}</div>
        </li>
        <li
          class="item"
          v-if="
            orderDatalist.orderInfo.shipping_type === 2 &&
              orderDatalist.orderInfo.refund_status === 0 &&
              orderDatalist.orderInfo.paid === 1
          "
        >
          <div>消耗码：</div>
          <div class="value">{{ orderDatalist.orderInfo.verify_code }}</div>
        </li>
        <li class="item">
          <div>推广人：</div>
          <div class="value">
            {{ orderDatalist.userInfo.spread_name }}/ID:{{
              orderDatalist.userInfo.spread_uid
            }}
          </div>
        </li>
        <li class="item">
          <div>支付时间：</div>
          <div class="value">{{ orderDatalist.orderInfo._pay_time }}</div>
        </li>
        <li class="item">
          <div>支付方式：</div>
          <div class="value">
            {{ orderDatalist.orderInfo._status._payType }}
          </div>
        </li>
        <li class="item" v-if="orderDatalist.orderInfo.store_order_sn">
          <div>原订单号：</div>
          <div class="value">{{ orderDatalist.orderInfo.store_order_sn }}</div>
        </li>
        <li class="item" v-if="orderDatalist.orderInfo.card_upgrade_use_oid">
          <div>被升级订单号：</div>
          <div class="value">{{ orderDatalist.orderInfo.card_upgrade_use_oid }}</div>
        </li>
      </ul>
    </div>
    <div
      class="section"
      v-if="orderDatalist.orderInfo.promotions_detail.length"
    >
      <div class="title">活动信息</div>
      <ul class="list">
        <li
          class="item"
          v-for="(item, index) in orderDatalist.orderInfo.promotions_detail"
          :key="index"
        >
          <div>{{ item.title }}：</div>
          <div class="value">￥{{ item.promotions_price }}</div>
        </li>
      </ul>
    </div>
    <div v-if="isShow || ((orderDatalist.orderInfo.product_type == 6 && orderDatalist.orderInfo.reservation_time_id>0) || orderDatalist.orderInfo.product_type != 6)" class="section">
      <div class="title">自定义留言</div>
	  <div v-for="(j, jindex) in orderDatalist.orderInfo.custom_form" :key="jindex">
		<div class="item fs-14 mb10" v-if="orderDatalist.orderInfo.product_type == 6 && j.length">{{orderDatalist.orderInfo.custom_form_title}}{{jindex+1}}</div>
		<ul class="list">
		  <li
		    v-for="(item, index) in j"
		    :key="index"
		    class="item"
		    v-show="(item.value && ['uploadPicture','dateranges'].indexOf(item.name) == -1) || (item.value.length && ['uploadPicture','dateranges'].indexOf(item.name) != -1)"
		  >
		    <div class="txtVal">{{ item.titleConfig.value }}：</div>
		    <div v-if="item.name === 'dateranges'" class="value">{{ item.value[0]+'/'+item.value[1] }}</div>
		    <div v-else-if="item.name === 'uploadPicture'" class="value" v-viewer>
		      <div v-for="(img, i) in item.value" :key="i" class="image">
		        <img v-lazy="img" />
		      </div>
		    </div>
		    <div v-else class="value">{{ item.value || "-" }}</div>
		  </li>
		</ul>
	  </div>
    </div>
    <div class="section" v-if="orderDatalist.orderInfo.mark">
      <div class="title">买家留言</div>
      <ul class="list">
        <li class="item mark">
          <div>备注：</div>
          <div class="value">{{ orderDatalist.orderInfo.mark || "-" }}</div>
        </li>
      </ul>
    </div>

    <div class="section" v-if="orderDatalist.orderInfo.remark">
      <div class="title">订单备注</div>
      <ul class="list">
        <li class="item mark">
          <div class="item1" >备注：</div>
            <Tooltip max-width="300" placement="top-start">
							<span class="">{{orderDatalist.orderInfo.remark}}</span>
							<p slot="content">{{orderDatalist.orderInfo.remark || "-" }}</p>
						</Tooltip>
        </li>
      </ul>
    </div>
    <div
      class="section"
      v-if="orderDatalist.orderInfo.delivery_type === 'express'"
    >
      <div class="title">物流信息</div>
      <ul class="list">
        <li class="item">
          <div>快递公司：</div>
          <div class="value">
            {{ orderDatalist.orderInfo.delivery_name || "-" }}
          </div>
        </li>
        <li class="item">
          <div>快递单号：</div>
          <div class="value">
            {{ orderDatalist.orderInfo.delivery_id
            }}<span class="logisticsLook" @click="openLogistics">查询</span>
          </div>
        </li>
      </ul>
    </div>
    <div
      class="section"
      v-if="orderDatalist.orderInfo.delivery_type === 'send'"
    >
      <div class="title">配送信息</div>
      <ul class="list">
        <li class="item">
          <div>送货人姓名：</div>
          <div class="value">
            {{ orderDatalist.orderInfo.delivery_name || "-" }}
          </div>
        </li>
        <li class="item">
          <div>送货人电话：</div>
          <div class="value">{{ orderDatalist.orderInfo.delivery_id }}</div>
        </li>
      </ul>
    </div>
  </div>
</template>

<script>
import { getOrderInfo } from "@/api/order";

export default {
  name: "orderDetails",
  props: {
    id: {
      type: Number,
    },
  },
  data() {
    return {
      orderDatalist: {
        orderInfo: {},
        userInfo: {},
      },
	  isShow: 0
    };
  },
  watch: {
    id(val) {
      this.getOrderInfo();
    },
	orderDatalist(value){
		if (value.orderInfo && value.orderInfo.custom_form && value.orderInfo.custom_form.length) {
		  value.orderInfo.custom_form.forEach((item) => {
			  if(item.length){
				item.forEach(j=>{
					if (j.value) {
					  return (this.isShow = 1)
					}
				})
			  }
		  })
		}
	}
  },
  mounted() {
    this.getOrderInfo();
  },
  methods: {
    getOrderInfo() {
      getOrderInfo(this.id).then((res) => {
        this.orderDatalist = res.data;
      });
    },
  },
};
</script>

<style scoped lang="stylus">
.detail .section .list .item .txtVal{
  max-width 100px;
}
ul, li {
  list-style: none;
}

.address{
	color: #606266;
	font-size: 14px;
	font-weight: 400;
	margin-top: 16px;
}

.detail {
  .section {
    border-bottom: 1px dashed #EEEEEE;
    padding: 25px 0;

    &:first-child {
      padding-top: 0;
    }

    .title {
      font-size: 16px;
      color: #303133;
      margin-bottom: 16px;
      font-weight: 600;
    }

    .list {
      display: flex;
      flex-wrap: wrap;
      margin: 0 -16px -16px 0;
      color: #606266;
      font-size: 14px;
      font-weight: 400;

      .item {
        flex: 0 0 calc((100% - 48px) / 3);
        display: flex;
        margin: 0 16px 16px 0;
      }
      .item1 {
        display: flex;
        min-width: 50px;
        margin-bottom: 16px;
      }
      .item.mark{
        flex: 1;
      }
       .value {
        flex: 1;
        word-break: break-all;

        .image {
          display: inline-block;
          width: 40px;
          height: 40px;
          margin: 0 12px 12px 0;
          vertical-align: middle;
        }

        img {
          width: 100%;
          height: 100%;
        }
      }
    }
  }
}
</style>
