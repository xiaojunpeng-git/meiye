<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Form :model="vipForm" :label-width="120">
        <Row :gutter="24" type="flex">
          <Col span="24">
            <FormItem label="会员卡激活：">
              <Switch
                size="large"
                v-model="vipForm.level_activate_status"
                :true-value="1"
                :false-value="0"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </Switch>
              <div class="upload-text">
                开启后用户等级功能不能直接使用，需要用户填写信息，激活后才能使用用户等级
              </div>
            </FormItem>
          </Col>
          <Col
            span="24"
            class="mt10"
            v-if="vipForm.level_activate_status === 1"
          >
            <FormItem label="会员卡信息：">
              <Table
                :columns="columns3"
                :data="listVip"
                ref="table"
                class="mt10 mb10 goods"
                :loading="loading"
                highlight-row
                no-userFrom-text="暂无数据"
                no-filtered-userFrom-text="暂无筛选结果"
                v-if="listVip.length > 0"
              >
                <!-- 必填 -->
                <template slot-scope="{ row, index }" slot="required">
                  <Checkbox v-model="listVip[index].required"></Checkbox>
                </template>
                <template slot-scope="{ row, index }" slot="action">
                  <a @click="delVip(row, index)">删除</a>
                </template>
              </Table>
              <Button @click="informationTap"> 选择信息 </Button>
            </FormItem>
          </Col>
          <Col
            span="24"
            class="mt10"
            v-if="vipForm.level_activate_status === 1"
          >
            <FormItem label="赠送积分：">
              <Switch
                size="large"
                v-model="vipForm.level_integral_status"
                :true-value="1"
                :false-value="0"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </Switch>
              <div class="upload-text mb10">
                用户激活会员卡后即赠送一定数额的积分
              </div>
              <Input
                v-model="vipForm.level_give_integral"
                placeholder="请输入赠送的积分"
                class="inputw"
                v-if="vipForm.level_integral_status === 1"
              ></Input>
              <span
                class="span-text"
                v-if="vipForm.level_integral_status === 1"
              >
                积分
              </span>
            </FormItem>
          </Col>
          <Col
            span="24"
            class="mt10"
            v-if="vipForm.level_activate_status === 1"
          >
            <FormItem label="赠送余额：">
              <Switch
                size="large"
                v-model="vipForm.level_money_status"
                :true-value="1"
                :false-value="0"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </Switch>
              <div class="upload-text mb10">
                用户激活会员卡后即赠送一定数额的储值余额
              </div>
              <Input
                v-model="vipForm.level_give_money"
                placeholder="请输入赠送的余额"
                class="inputw"
                v-if="vipForm.level_money_status === 1"
              />
              <span class="span-text" v-if="vipForm.level_money_status === 1">
                元
              </span>
            </FormItem>
          </Col>
          <Col
            span="24"
            class="mt10"
            v-if="vipForm.level_activate_status === 1"
          >
            <FormItem label="赠送优惠券：">
              <Switch
                size="large"
                v-model="vipForm.level_coupon_status"
                :true-value="1"
                :false-value="0"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </Switch>
              <div class="upload-text">用户激活会员卡后即赠送优惠券</div>

              <!-- <div
                class="item"
                v-for="(item, indexw) in promotionsData"
                :key="indexw"
              > -->
                <div
                  class="add-coupon"
                  @click="addCoupon"
                  v-if="vipForm.level_coupon_status === 1"
                >
                  + 添加优惠券
                </div>
                <Table
                  border
                  :columns="columns1"
                  :data="vipCopon"
                  ref="table"
                  class="table"
                  width="700"
                  v-if="
                    vipCopon.length > 0 && vipForm.level_coupon_status === 1
                  "
                >
                  <template slot-scope="{ row }" slot="coupon_price">
                    <span v-if="row.coupon_type == 1">
                      {{ row.coupon_price }}元
                    </span>
                    <span v-if="row.coupon_type == 2">
                      {{ parseFloat(row.coupon_price) / 10 }}折（{{
                        row.coupon_price.toString().split('.')[0]
                      }}%）
                    </span>
                  </template>
                  <template slot-scope="{ row }" slot="coupon_type">
                    <span v-if="row.coupon_type === 1">满减券</span>
                    <span v-else>折扣券</span>
                  </template>
                  <template slot-scope="{ row, index }" slot="status">
                    <a @click="delCoupon(index, indexw)">删除</a>
                  </template>
                </Table>
              <!-- </div> -->
            </FormItem>
          </Col>
          <Col>
            <FormItem>
              <div class="subBtn mt10" @click="handleSubmit('level')">保存</div>
            </FormItem>
          </Col>
        </Row>
      </Form>
    </Card>
    <!-- 选择信息 -->

    <information
      ref="information"
      @getInfoList="getInfoList"
      :listOne="listOne"
    ></information>
    <!-- 添加优惠券 -->
    <coupon-list
      ref="couponTemplates"
      @getCouponList="getCouponList"
      :discount="true"
    ></coupon-list>
  </div>
</template>

<script>
import information from '@/components/information';
import couponList from '@/components/couponList';
import { settingUser, setUser } from '@/api/user.js';

export default {
  components: {
    information,
    couponList,
  },
  data() {
    return {
      // 会员等级表单
      vipForm: {
        member_func_status: 0, // 等级启用
        sign_give_exp: '', //签到赠送
        order_give_exp: '', // 下单赠送
        invite_user_exp: '', // 邀请新用户
        level_activate_status: 1, // 会员卡激活开启 1开启 0 关闭
        level_extend_info: [], // 会员卡信息
        level_integral_status: 1, // 赠送积分开启
        level_give_integral: 8, // 赠送积分数量
        level_money_status: 1, // 赠送余额开启
        level_give_money: 15, // 赠送余额数量
        level_coupon_status: 1, // 赠送优惠券开启
        level_give_coupon: [], // 赠送优惠券数量
      },
      columns3: [
        {
          title: '信息',
          key: 'info',
          width: 120,
        },

        {
          title: '必填',
          slot: 'required',
          width: 70,
        },

        {
          title: '信息格式',
          key: 'label',
          minWidth: 120,
        },
        {
          title: '提示信息',
          key: 'tip',
          minWidth: 120,
        },
        {
          title: '操作',
          slot: 'action',
          minWidth: 80,
        },
      ],
      listVip: [],
      loading: false,
      promotionsData: [
        {
          threshold: 0,
          give_integral: 0,
          checkIntegral: false,
          checkCoupon: false,
          checkGoods: false,
          giveProducts: [],
          giveCoupon: [],
        },
      ],
      columns1: [
        {
          title: '优惠券名称',
          key: 'title',
          minWidth: 150,
        },
        {
          title: '类型',
          slot: 'coupon_type',
          minWidth: 80,
        },
        {
          title: '面值',
          slot: 'coupon_price',
          minWidth: 100,
        },
        {
          title: '最低消费额',
          key: 'use_min_price',
          minWidth: 100,
        },
        {
          title: '操作',
          slot: 'status',
          align: 'center',
          minWidth: 80,
        },
      ],
      vipCopon: [],
      indexCoupon: 0,
      tabVal: 'basic',
      listOne: [],
    };
  },
  created() {
    this.settingUser();
  },
  methods: {
    // 获取用户配置
    settingUser() {
      settingUser(this.tabVal).then((res) => {
        if (this.tabVal === 'basic') {
          this.authorizedPicture = res.data.h5_avatar;
          this.listOne = res.data.user_extend_info;
          this.tabVal = 'level';
          this.settingUser();
        } else if (this.tabVal === 'level') {
          this.vipForm = res.data;
          this.vipCopon = res.data.level_give_coupon;
          res.data.level_extend_info.forEach((item) => {
            if (item.required == 1 || item.required == true) {
              item.required = true;
            } else {
              item.required = false;
            }
          });
          this.listVip = res.data.level_extend_info;
        }
      });
    },
    delVip(row, index) {
      this.listVip.splice(index, 1);
    },
    informationTap() {
      this.$refs.information.isShow = true;
    },
    // 添加优惠券
    addCoupon() {
      // this.indexCoupon = index;
      this.$refs.couponTemplates.isTemplate = true;
      this.$refs.couponTemplates.tableList();
    },
    // 删除优惠券
    delCoupon(index, indexw) {
      if (this.tabVal === 'level') {
        this.vipCopon.splice(index, 1);
      }
      // this.promotionsData[indexw].giveCoupon.splice(index, 1);
    },
    // 表单提交
    handleSubmit(val) {
      let arrIds = this.vipCopon.map((item) => item.id);
      this.vipForm.level_give_coupon = Array.from(new Set(arrIds));
      this.vipForm.level_extend_info = this.listVip;
	  if(this.vipForm.level_integral_status && this.vipForm.level_give_integral<=0){
		  return this.$Message.error('赠送积分不能为0');
	  }
	  if(this.vipForm.level_money_status && this.vipForm.level_give_money<=0){
		  return this.$Message.error('赠送余额不能为0');
	  }
	  if(this.vipForm.level_coupon_status && !this.vipForm.level_give_coupon.length){
		  return this.$Message.error('优惠券不能为空');
	  }
      setUser(val, this.vipForm)
        .then((res) => {
          this.$Message.success(res.msg);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 优惠卷表格
    getCouponList(data) {
      // let indexCoupon = this.indexCoupon;
      this.$refs.couponTemplates.isTemplate = false;
      // data.forEach((j) => {
        // j.limit_num = 0;
        // j.indexCoupon = indexCoupon;
      // });
      // let list = this.promotionsData[indexCoupon].giveCoupon.concat(data);
      data.forEach(item => {
        const result = this.vipCopon.find(vipCoponItem => vipCoponItem.id == item.id);
        if (!result) {
          this.vipCopon.unshift(item);
        }
      });
      // let uni = this.unique(list);
      // this.vipCopon = uni;
    },
    // 对象数组去重
    unique(arr) {
      const res = new Map();
      return arr.filter((arr) => !res.has(arr.id) && res.set(arr.id, 1));
    },
    // 选择信息
    getInfoList(data) {
      let list = this.listVip.concat(data);
      let uni = this.uniqueVip(list);
      uni.forEach((item) => {
        if (item.required == 1 || item.required == true) {
          item.required = true;
        } else {
          item.required = false;
        }
      });
      this.listVip = uni;
      this.$refs.information.isShow = false;
    },
    // 对象数组去重；
    uniqueVip(arr) {
      const res = new Map();
      return arr.filter((arr) => !res.has(arr.info) && res.set(arr.info, 1));
    },
  },
};
</script>

<style lang="less" scoped>
/deep/.ivu-table-header th{
	padding: 8px 5px;
}
.upload-text {
  font-size: 12px;
  line-height: 16px;
  font-weight: 400;
  color: #cccccc;
  margin-top: 6px;
}
.goods /deep/.ivu-table-cell {
  line-height: 21px !important;
}
.goods {
  .icondrag {
    color: #ccc;
  }
}
.goods {
  width: 780px !important;
}
.inputw {
  width: 460px;
}
.span-text {
  margin-left: 8px;
  font-size: 12px;
}
.add-coupon {
  font-size: 12px;
  font-weight: 400;
  color: #1890ff;
  cursor: pointer;
  width: 100px;
}
/deep/ .vxe-table--render-default .vxe-table--header {
  font-size: 12px;
}
.subBtn {
  width: 54px;
  height: 32px;
  background: #2d8cf0;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
  color: #ffffff;
  text-align: center;
  line-height: 32px;
  cursor: pointer;
}
</style>