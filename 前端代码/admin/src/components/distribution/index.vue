<template>
  <!-- 支付订单 -->
  <div class="order-bgc">
    <div class="putSupplier" v-for="(item,index) in newArrayData" :key="index">
      <div class="header acea-row row-between-wrapper">
        <div class="left acea-row row-middle">
          <div class="pictrue" :id="'qrCodeUrl'+index"></div>
          <div class="info">
            <div><span class="name">收货人：</span>{{item.real_name}}</div>
            <div><span class="name">收货地址：</span>{{item.user_address}}</div>
            <div><span class="name">手机号：</span><span>{{item.user_phone}}</span></div>
          </div>
        </div>
        <div class="info">
          <div><span class="name">订单编号：</span>{{item.order_id}}</div>
          <div><span class="name">支付时间：</span>{{item.pay_time}}</div>
          <div><span class="name">支付方式：</span>{{item.pay_type_name}}</div>
        </div>
      </div>
      <div class="mt20">
        <Table border :columns="columns" :data="item.goodsList" :disabled-hover="true">
          <template slot-scope="{ row }" slot="store_name">
            <div class="line2">{{row.store_name}}</div>
          </template>
          <template slot-scope="{ row }" slot="suk">
            <div class="line2">{{row.suk}}</div>
          </template>
        </Table>
      </div>
      <div class="bottom acea-row row-between-wrapper">
        <div class="acea-row row-middle">
          <div class="item"><span class="name">运费：</span>{{item.freight_price}}</div>
          <div class="item"><span class="name">优惠：</span>{{item.coupon_price}}</div>
          <div class="item"><span class="name">会员折扣：</span>{{item.vip_true_price}}</div>
          <div class="item"><span class="name">积分抵扣：</span>{{item.deduction_price}}</div>
        </div>
        <div class="pricePay">实付金额：{{item.pay_price}}</div>
      </div>
      <div class="bottom acea-row">
        <div class="name">用户备注：<span class="con">{{item.mark || '-'}}</span></div>
      </div>
      <div class="h50">
        <div class="delivery" v-if="site_name || refund_address || refund_phone">
          <div v-if="site_name">店铺信息：{{ site_name }}</div>
          <div v-if="refund_address">地址：{{ refund_address }}</div>
          <div v-if="refund_phone">联系方式：{{ refund_phone }}</div>
        </div>
      </div>
    </div>
<!--  注意：后续要是加内容使页面撑大，记得查看下打印是否在同一张  -->
  </div>
</template>
<script>
import { distributionInfo } from '@/api/supplier';
import { distributionOrder } from '@/api/order';
import QRCode from 'qrcodejs2';
import Setting from '@/setting';
export default {
  data() {
    return {
      columns: [
        {
          title: '商品编号',
          key: 'index',
          align: 'center',
          width: 60
        },
        {
          title: '商品名称',
          slot: 'store_name',
          align: 'center',
          minWidth: 253
        },
        {
          title: '商品规格',
          slot: 'suk',
          align: 'center',
          width: 219
        },
        // {
        //     title: '商品条码',
        //     key: 'bar_code',
        //     align: 'center',
        //     width: 109
        // },
        // {
        //     title: '商品编码',
        //     key: 'code',
        //     align: 'center',
        //     width: 109
        // },
        {
          title: '单价',
          key: 'truePrice',
          align: 'center',
          width: 100
        },
        {
          title: '数量',
          key: 'cart_num',
          align: 'center',
          width: 60
        },
        {
          title: '金额',
          key: 'subtotal',
          align: 'center',
          width: 100
        }
      ],
      data: {},
      goods: [],
      BaseURL: Setting.apiBaseURL.replace(/adminapi/, ''),
      newArrayData: [],
      site_name: '',
      refund_phone: '',
      refund_address: ''
    };
  },
  created() {
    this.getDistribution();
  },
  mounted() {
    this.$nextTick(function() {
      const that = this;
      setTimeout(function() {
        that.creatQrCode();
      }, 200);
    });
  },
  methods: {
    // 生成二维码
    creatQrCode() {
      const url = this.BaseURL;
      let qrcode = '';
      this.newArrayData.forEach((item, index) => {
        const obj = document.getElementById('qrCodeUrl' + index);
        qrcode = new QRCode(obj, {
          text: url, // 需要转换为二维码的内容
          width: 90,
          height: 90,
          colorDark: '#000000',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.H
        });
      });
    },
    getDistribution() {
      const header = this.$route.query.status == 1 ? distributionInfo : distributionOrder;
      header(this.$route.query.id).then(res => {
        this.data = res.data;
        this.goods = res.data.list;
        const data = res.data;
        const newArray = [];
        this.site_name = data.site_name;
        this.refund_phone = data.refund_phone;
        this.refund_address = data.refund_address;
        data.list.forEach((i, indexi) => {
          const listArray = [];
          const goods = i.list;
          const count = Math.ceil(goods.length / 6);
          for (let j = 0; j < count; j++) {
            const list = goods.slice(j * 6, j * 6 + 6);
            if (list.length) {
              listArray.push(list);
            }
          }
          const num = 6 - listArray[listArray.length - 1].length;
          if (num) {
            for (let z = 0; z < num; z++) {
              listArray[listArray.length - 1].push({
                cart_num: '',
                index: '',
                store_name: '',
                subtotal: '',
                suk: '',
                truePrice: ''
              });
            }
          }
          listArray.forEach((x) => {
            newArray.push({
              real_name: i.real_name,
              user_address: i.user_address,
              user_phone: i.user_phone,
              freight_price: i.freight_price,
              coupon_price: i.coupon_price,
              vip_true_price: i.vip_true_price,
              deduction_price: i.deduction_price,
              use_integral: i.use_integral,
              pay_price: i.pay_price,
              mark: i.mark,
              order_id: i.order_id,
              pay_time: i.pay_time,
              pay_type_name: i.pay_type_name,
              goodsList: x
            });
          });
        });
        this.newArrayData = newArray;
      }).catch(err => {
        this.$Message.error(err.msg);
      });
    }
  }
};

</script>
<style lang="less" scoped>
/deep/.ivu-table-cell{
	padding: 0 !important;
}
.order-bgc {
  background-color: #fff;
}
/deep/.ivu-table th{
  background-color: #fff !important;
}
/deep/.ivu-table-header thead tr th:nth-of-type(1){
  padding-left:0 !important;
}
/deep/.ivu-table-header thead tr th{
  border-top: 1px solid #333;
}
/deep/.ivu-table td:nth-of-type(1){
  padding-left:0 !important;
}
/deep/.ivu-table-header table{
  //border-top:0!important;
}
/deep/.ivu-table-border th, /deep/.ivu-table-border td{
  border-right: 1px solid #333 !important;
}
/deep/.ivu-table-border th:nth-of-type(1), /deep/.ivu-table-border td:nth-of-type(1){
  border-left: 1px solid #333 !important;
}
/deep/.ivu-table th, /deep/.ivu-table td{
  border-bottom: 1px solid #333 !important;
  height: 48px !important;
  min-height: unset !important;
}
/deep/.ivu-table-wrapper-with-border{
  border-color: #333 !important;
  border: unset;
  //border-bottom: 1px solid #333;
  //border-right: 1px solid #333;
}
/deep/.ivu-table-border:after{
  background-color: #333;
  width: 0 !important;
  height: 0 !important;
}
/deep/.ivu-table:before{
  background-color: #333;
  width: 0 !important;
  height: 0 !important;
}
/deep/.ivu-table{
  color: #000;
}
.pricePay{
  font-weight: bold;
}
.bottom{
  color: rgba(0, 0, 0, 0.85);
  font-size: 12px;
  font-weight: 400;
  margin-top: 10px;
  .item{
    margin-right: 30px;
  }
  .name{
    font-weight: 600;
  }
  .con{
    width: 740px;
    font-weight: unset;
  }
}
.putSupplier{
  width: 794px;
  background-color: #fff;
  margin: 0 auto;
  padding-top: 11px;
  /*padding: 20px 20px 450px 20px;*/
  /*box-sizing: border-box;*/
  .header{
    .info{
      font-size: 12px;
      color: rgba(0, 0, 0, 0.85);
      .name{
        font-weight: 600;
      }
      div~div{
        margin-top: 10px;
      }
    }
    .left{
      width: 500px;
      .pictrue{
        width: 90px;
        height: 90px;
        margin-right: 20px;
        img{
          width: 100%;
          height: 100%;
        }
      }
      .info{
        flex: 1;
      }
    }
  }
}
.delivery {
  display: flex;
  justify-content: center;
  width: 794px;
  padding-top: 14px;
  border-top: 1px solid #DDDDDD;
  margin: 11px auto;
  font-size: 10px;
  color: #333333;

  div + div {
    margin-left: 30px;
  }
}
</style>
