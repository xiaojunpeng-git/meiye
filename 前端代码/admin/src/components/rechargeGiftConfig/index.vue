<template>
  <Modal
    v-model="visibleInner"
    title="赠送品项/优惠券"
    width="868"
    class-name="recharge-gift-modal"
    @on-cancel="handleClose"
  >
    <Form style="margin-top: 15px" :label-width="0">
      <FormItem>
        <Button type="primary" size="large" @click="addProduct">添加品项</Button>
        <Button size="large" style="margin-left: 20px" @click="addCoupon">添加优惠券</Button>
        <div class="table_out">
          <div class="head">
            <div class="body_title">类型</div>
            <div class="body_title" style="width: 30%">名称</div>
            <div class="body_title">数量</div>
            <div class="body_title" style="width: 30%">生效时间</div>
            <div class="body_title">有效天数</div>
            <div class="body_title">结束时间</div>
            <div class="body_title">操作</div>
          </div>
          <div class="body">
            <div class="heng_kuai" v-for="(row, index) in sendAll.product" :key="'p-' + index">
              <div class="body_kuai">
                <span v-if="row.product_type == 0">产品</span>
                <span v-else-if="row.product_type == 5">卡项</span>
                <span v-else-if="row.product_type == 6">项目</span>
                <span v-else>品项</span>
              </div>
              <div class="body_kuai name-cell">{{ row.store_name }}</div>
              <div class="body_kuai">
                <InputNumber style="width: 80%" v-model="row.num" :min="1" :max="9999999" />
              </div>
              <div class="body_kuai">——</div>
              <div class="body_kuai">——</div>
              <div class="body_kuai">——</div>
              <div class="body_kuai">
                <a @click="delSendProduct(index)">删除</a>
              </div>
            </div>
            <div class="heng_kuai" v-for="(row, index) in sendAll.coupon" :key="'c-' + index">
              <div class="body_kuai">券</div>
              <div class="body_kuai name-cell">{{ row.store_name }}</div>
              <div class="body_kuai">
                <InputNumber style="width: 80%" v-model="row.num" :min="1" :max="9999999" />
              </div>
              <div class="body_kuai">
                <DatePicker
                  :clearable="false"
                  transfer
                  v-model="row.begin_time_label"
                  type="date"
                  placeholder="选择日期"
                  @on-change="changeBeginCoupon($event, row, index)"
                  style="width: 100%"
                />
              </div>
              <div class="body_kuai">
                <InputNumber
                  @input="handleInput($event, row)"
                  style="width: 80%"
                  v-model="row.write_days"
                  :min="1"
                  :max="9999999"
                />
              </div>
              <div class="body_kuai">
                <span v-if="row.end_time_label">{{ row.end_time_label }}</span>
                <span v-else>未设置</span>
              </div>
              <div class="body_kuai">
                <a @click="delSendCoupon(index)">删除</a>
              </div>
            </div>
          </div>
        </div>
      </FormItem>
    </Form>
    <div slot="footer">
      <Button @click="handleClose">取消</Button>
      <Button type="primary" :loading="saving" @click="handleSave">保存</Button>
    </div>
    <Modal v-model="goodsModal" title="商品列表" footer-hide scrollable width="900">
      <goods-attr :goodsType="1" :flatSelect="true" ref="goodSattr" v-if="goodsModal" @getProductId="getAtterId" />
    </Modal>
    <coupon-list ref="couponTemplates" @nameId="onCouponSelected" />
  </Modal>
</template>

<script>
import goodsAttr from '@/components/goodsAttr';
import couponList from '@/components/couponList';
import { getRechargeGiftConfigApi, saveRechargeGiftConfigApi } from '@/api/marketing';
import { getProductGiftConfigApi, saveProductGiftConfigApi } from '@/api/product';

export default {
  name: 'RechargeGiftConfig',
  components: { goodsAttr, couponList },
  props: {
    visible: {
      type: Boolean,
      default: false
    },
    quotaId: {
      type: [Number, String],
      default: 0
    },
    productId: {
      type: [Number, String],
      default: 0
    }
  },
  data() {
    return {
      visibleInner: false,
      saving: false,
      goodsModal: false,
      sendAll: {
        product: [],
        coupon: []
      }
    };
  },
  watch: {
    visible(val) {
      this.visibleInner = val;
      if (val && this.effectiveId) {
        this.loadConfig();
      }
    },
    visibleInner(val) {
      if (!val) {
        this.$emit('close');
      }
    }
  },
  computed: {
    effectiveId() {
      return this.productId ? Number(this.productId) : Number(this.quotaId);
    },
    isProductMode() {
      return !!this.productId;
    }
  },
  methods: {
    loadConfig() {
      const request = this.isProductMode
        ? getProductGiftConfigApi(this.effectiveId)
        : getRechargeGiftConfigApi(this.effectiveId);
      request.then(res => {
        const data = res.data || {};
        this.sendAll = {
          product: JSON.parse(JSON.stringify(data.product || [])),
          coupon: JSON.parse(JSON.stringify(data.coupon || []))
        };
      }).catch(err => {
        this.$Message.error(err.msg || '加载赠送配置失败');
      });
    },
    handleClose() {
      this.visibleInner = false;
    },
    handleSave() {
      this.saving = true;
      const request = this.isProductMode
        ? saveProductGiftConfigApi(this.effectiveId, { sendAll: this.sendAll })
        : saveRechargeGiftConfigApi(this.effectiveId, { sendAll: this.sendAll });
      request.then(res => {
        this.$Message.success(res.msg || '保存成功');
        this.visibleInner = false;
        this.$emit('saved');
      }).catch(err => {
        this.$Message.error(err.msg || '保存失败');
      }).finally(() => {
        this.saving = false;
      });
    },
    addCoupon() {
      this.$refs.couponTemplates.isTemplate = true;
      this.$refs.couponTemplates.tableList();
    },
    onCouponSelected(ids) {
      const list = this.$refs.couponTemplates.couponList || [];
      ids.forEach(id => {
        const row = list.find(item => item.id === id);
        if (!row) return;
        this.sendAll.coupon.push(this.formatCouponRow({
          id: row.id,
          store_name: row.title,
          begin_time: row.start_use_time || 0,
          write_days: row.coupon_time || 1,
          end_time: row.end_use_time || 0
        }));
      });
    },
    formatCouponRow(item) {
      const row = {
        id: item.id,
        store_name: item.store_name || item.title || '',
        num: item.num || 1,
        begin_time: item.begin_time || 0,
        write_days: item.write_days || 1,
        end_time: item.end_time || 0,
        begin_time_label: '未设置',
        end_time_label: '未设置'
      };
      if (!row.begin_time) {
        row.begin_time = this.todayDateSecondTimestamp();
      }
      if (row.end_time > 0 && row.begin_time > 0 && !row.write_days) {
        row.write_days = this.diffDays(row.end_time, row.begin_time);
      }
      if (!row.write_days) {
        row.write_days = 1;
      }
      if (row.write_days > 0) {
        row.end_time = this.getEnd(row.begin_time, row.write_days);
      }
      if (row.begin_time > 0) {
        row.begin_time_label = this.formatSecondToDate(row.begin_time);
      }
      if (row.end_time > 0) {
        row.end_time_label = this.formatSecondToDate(row.end_time);
      }
      return row;
    },
    handleInput(value, row) {
      if (value == 0) {
        row.end_time = 0;
        row.end_time_label = '未设置';
      } else {
        row.end_time = this.getEnd(row.begin_time, value);
        row.end_time_label = this.formatSecondToDate(row.end_time);
      }
    },
    changeBeginCoupon(value, row, index) {
      row.begin_time_label = value;
      row.begin_time = Math.floor(new Date(value).getTime() / 1000);
      row.end_time = this.getEnd(row.begin_time, row.write_days);
      row.end_time_label = this.formatSecondToDate(row.end_time);
      this.$set(this.sendAll.coupon, index, row);
    },
    delSendProduct(index) {
      this.sendAll.product.splice(index, 1);
    },
    delSendCoupon(index) {
      this.sendAll.coupon.splice(index, 1);
    },
    addProduct() {
      this.goodsModal = true;
    },
    getAtterId(selectCardData) {
      this.goodsModal = false;
      selectCardData.forEach(res => {
        this.sendAll.product.push(this.formatProductRow(res));
      });
    },
    formatProductRow(res) {
      const data = {
        id: res.id,
        product_type: res.product_type,
        store_name: res.store_name,
        num: 1,
        begin_time: res.attrValue && res.attrValue[0] ? (res.attrValue[0].write_start || 0) : 0,
        write_days: res.attrValue && res.attrValue[0] ? (res.attrValue[0].write_days || 1) : 1,
        end_time: 0
      };
      if (!data.begin_time) {
        data.begin_time = this.todayDateSecondTimestamp();
      }
      if (data.write_days > 0) {
        data.end_time = this.getEnd(data.begin_time, data.write_days);
      }
      return data;
    },
    diffDays(begin, end) {
      const ONE_DAY = 24 * 60 * 60;
      if (!begin || !end) return 0;
      return Math.floor(Math.abs(begin - end) / ONE_DAY);
    },
    todayDateSecondTimestamp() {
      const now = new Date();
      const todayZero = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      return Math.floor(todayZero.getTime() / 1000);
    },
    formatSecondToDate(timestamp) {
      const timeNum = Number(timestamp);
      if (!timestamp || isNaN(timeNum) || timeNum.toString().length !== 10) {
        return '--';
      }
      const date = new Date(timeNum * 1000);
      if (date.toString() === 'Invalid Date') {
        return '--';
      }
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    },
    getEnd(startDate, days = 1) {
      const secTimestamp = Number(startDate);
      if (isNaN(secTimestamp) || secTimestamp.toString().length !== 10) {
        return 0;
      }
      const targetDate = new Date(secTimestamp * 1000);
      if (targetDate.toString() === 'Invalid Date') {
        return 0;
      }
      targetDate.setDate(targetDate.getDate() + Number(days));
      return Math.floor(targetDate.getTime() / 1000);
    }
  }
};
</script>

<style scoped lang="stylus">
.table_out
  border-top 1px solid rgb(191, 191, 191)
  border-left 1px solid rgb(191, 191, 191)
  margin-top 20px
.heng_kuai
  display flex
  width 100%
.head
  display flex
  background-color #efefef
  width 100%
  border-bottom 1px solid rgb(191, 191, 191)
.body_title, .body_kuai
  height 50px
  line-height 50px
  border-right 1px solid rgb(191, 191, 191)
  border-bottom 1px solid rgb(191, 191, 191)
  width 25%
  text-align center
  font-size 15px
.body_title
  font-weight bold
  font-size 16px
.name-cell
  width 30%
  white-space nowrap
  overflow hidden
  text-overflow ellipsis
  font-size 12px
</style>
