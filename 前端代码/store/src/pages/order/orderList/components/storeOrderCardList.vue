<template>
  <div class="store-order-card-list">
    <Spin fix v-if="loading"></Spin>
    <!-- 表头 -->
    <div class="order-table-head">
      <div class="col col-check">
        <Dropdown transfer @on-click="$emit('all-pages', $event)">
          <a href="javascript:void(0)" class="check-all-link">
            <span>全选({{ isAll == 1 ? (total - checkUidList.length) : checkUidList.length }})</span>
            <Icon type="ios-arrow-down" />
          </a>
          <DropdownMenu slot="list">
            <DropdownItem name="0">当前页</DropdownItem>
            <DropdownItem name="1">所有页</DropdownItem>
          </DropdownMenu>
        </Dropdown>
      </div>
      <div class="col col-product" v-show="colVisible.product">商品</div>
      <div class="col col-price" v-show="colVisible.price">单价</div>
      <div class="col col-qty" v-show="colVisible.qty">数量</div>
      <div class="col col-coupon" v-show="colVisible.coupon_deduct">优惠券抵扣</div>
      <div class="col col-craft-staff" v-show="colVisible.craft_staff">手艺人</div>
      <div class="col col-sales-staff" v-show="colVisible.sales_staff">销售人</div>
      <div class="col col-customer" v-show="colVisible.customer">客户</div>
      <div class="col col-amount" v-show="colVisible.amount">金额</div>
      <div class="col col-source" v-show="colVisible.source">来源</div>
      <div class="col col-store" v-show="colVisible.store">下单门店</div>
      <div class="col col-status" v-show="colVisible.status">状态</div>
      <div class="col col-action" v-show="colVisible.action">操作</div>
    </div>

    <div v-if="!list.length && !loading" class="order-empty">暂无数据</div>

    <!-- 订单块 -->
    <div
      v-for="row in list"
      :key="row.id"
      class="order-block"
      :class="{ 'is-checked': isOrderChecked(row.id) }"
    >
      <div class="order-block-head">
        <Checkbox
          :value="isOrderChecked(row.id)"
          @on-change="(val) => toggleOrder(row.id, val)"
          class="order-check"
        />
        <span class="head-item">下单时间：{{ row.add_time }}</span>
        <span class="head-item">订单编号：{{ row.order_id }}</span>
        <span v-if="row.is_debt_repay && row.debt_repay_origin_order_sn" class="head-item">补交单号：{{ row.debt_repay_origin_order_sn }}</span>
        <Tag v-if="row.order_type_label" color="default" class="head-tag">{{ row.order_type_label }}</Tag>
        <Tag v-if="row.is_debt_repay" color="orange" class="head-tag">补交订单</Tag>
        <span class="head-item head-gendan">
          跟单人员：
          <template v-if="row.refund_status == 0 && row.order_type != 2">
            <a @click="$emit('gendan', row)" v-if="row.gendan_staff_name">{{ row.gendan_staff_name }}</a>
            <a v-else @click="$emit('gendan', row)">未设置</a>
          </template>
          <template v-else>
            <span v-if="row.gendan_staff_name">{{ row.gendan_staff_name }}</span>
            <span v-else>-</span>
          </template>
        </span>
        <a v-if="row.order_type != 2" class="head-detail" @click="$emit('order-detail', row)">订单详情 - 备注</a>
      </div>

      <table class="order-block-table" cellspacing="0" cellpadding="0">
        <colgroup>
          <col class="col-check" />
          <col v-if="colVisible.product" class="col-product" />
          <col v-if="colVisible.price" class="col-price" />
          <col v-if="colVisible.qty" class="col-qty" />
          <col v-if="colVisible.coupon_deduct" class="col-coupon" />
          <col v-if="colVisible.craft_staff" class="col-craft-staff" />
          <col v-if="colVisible.sales_staff" class="col-sales-staff" />
          <col v-if="colVisible.customer" class="col-customer" />
          <col v-if="colVisible.amount" class="col-amount" />
          <col v-if="colVisible.source" class="col-source" />
          <col v-if="colVisible.store" class="col-store" />
          <col v-if="colVisible.status" class="col-status" />
          <col v-if="colVisible.action" class="col-action" />
        </colgroup>
        <tbody>
          <tr v-for="(line, lineIdx) in getOrderLines(row)" :key="lineIdx">
            <td class="td-check" v-if="lineIdx === 0" :rowspan="getOrderLines(row).length"></td>
            <td class="td-product" v-if="colVisible.product">
              <div class="product-cell">
                <div class="product-img" v-viewer v-if="line.image">
                  <img v-lazy="line.image" />
                </div>
                <div class="product-info">
                  <a v-if="row.order_type != 2" class="product-name" @click="$emit('order-detail', row)">{{ line.name }}</a>
                  <span v-else class="product-name product-name-plain">{{ line.name }}</span>
                  <Tag v-if="line.typeTag" size="small" class="product-tag">{{ line.typeTag }}</Tag>
                </div>
              </div>
            </td>
            <td class="td-price" v-if="colVisible.price">¥ {{ line.price }}</td>
            <td class="td-qty" v-if="colVisible.qty">x {{ line.qty }}</td>
            <td class="td-coupon" v-if="colVisible.coupon_deduct">
              <span v-if="line.couponPrice > 0" class="coupon-deduct">-¥{{ line.couponPrice }}</span>
              <span v-else>-</span>
            </td>
            <td class="td-craft-staff" v-if="colVisible.craft_staff">
              <template v-if="row.refund_status == 0 && showCraftStaffColumn(row)">
                <a @click="$emit('yeji', row, 'craft')" v-if="line.craftStaff">{{ line.craftStaff }}</a>
                <a v-else @click="$emit('yeji', row, 'craft')">未分配</a>
              </template>
              <template v-else-if="showCraftStaffColumn(row)">
                <span v-if="line.craftStaff">{{ line.craftStaff }}</span>
                <span v-else>未分配</span>
              </template>
              <span v-else>-</span>
            </td>
            <td class="td-sales-staff" v-if="colVisible.sales_staff">
              <template v-if="row.refund_status == 0 && showSalesStaffColumn(row)">
                <a @click="$emit('yeji', row, 'sales')" v-if="line.salesStaff">{{ line.salesStaff }}</a>
                <a v-else @click="$emit('yeji', row, 'sales')">未分配</a>
              </template>
              <template v-else-if="showSalesStaffColumn(row)">
                <span v-if="line.salesStaff">{{ line.salesStaff }}</span>
                <span v-else>未分配</span>
              </template>
              <span v-else>-</span>
            </td>
            <td
              class="td-customer"
              v-if="colVisible.customer && lineIdx === 0"
              :rowspan="getOrderLines(row).length"
            >
              <template v-if="hasUserId(row)">
                <a
                  href="javascript:void(0)"
                  class="customer-name customer-link"
                  @click.prevent="openUserDetail(row)"
                >{{ getCustomerDisplay(row) }}</a>
                <a
                  v-if="getCustomerPhone(row)"
                  href="javascript:void(0)"
                  class="customer-phone customer-link"
                  @click.prevent="openUserDetail(row)"
                >{{ getCustomerPhone(row) }}</a>
              </template>
              <template v-else>
                <span class="customer-name">{{ getCustomerDisplay(row) }}</span>
                <div class="customer-phone" v-if="getCustomerPhone(row)">{{ getCustomerPhone(row) }}</div>
              </template>
              <div class="customer-vip" v-if="row.user_level_name">{{ row.user_level_name }}</div>
              <div style="color: #ed4014" v-if="row.delete_time != null">(已注销)</div>
            </td>
            <td
              class="td-amount"
              v-if="colVisible.amount && lineIdx === 0"
              :rowspan="getOrderLines(row).length"
            >
              <div class="amount-main">¥ {{ getPayAmount(row) }}</div>
              <div class="amount-pay-type">
                <a
                  @click="$emit('remark', row)"
                  v-if="(row.pay_type == 'combination' || row.pay_type == 'cash') && row.order_type != 2 && row.cash_choose != 10"
                >{{ row.pay_type_name }}</a>
                <span v-else>{{ row.pay_type_name }}</span>
              </div>
              <div class="amount-sub" v-if="getAmountSub(row)">{{ getAmountSub(row) }}</div>
              <div
                v-if="getPendingDebt(row) > 0"
                class="amount-debt"
              >(欠款 ¥{{ getPendingDebt(row).toFixed(2) }})</div>
            </td>
            <td
              class="td-source"
              v-if="colVisible.source && lineIdx === 0"
              :rowspan="getOrderLines(row).length"
            >
              <template v-if="editableSource && row.refund_status == 0 && row.order_type != 2">
                <a @click="$emit('source', row)" v-if="row.source_name">{{ row.source_name }}</a>
                <a v-else @click="$emit('source', row)">未设置</a>
              </template>
              <span v-else>{{ row.source_name || '-' }}</span>
            </td>
            <td
              class="td-store"
              v-if="colVisible.store && lineIdx === 0"
              :rowspan="getOrderLines(row).length"
            >{{ row.store_name || '-' }}</td>
            <td
              class="td-status"
              v-if="colVisible.status && lineIdx === 0"
              :rowspan="getOrderLines(row).length"
            >
              <Tag color="success" size="medium" v-show="row.status == 3">{{ row.status_name.status_name }}</Tag>
              <Tag color="success" size="medium" v-show="row.status == 4">{{ row.status_name.status_name }}</Tag>
              <Tag color="success" size="medium" v-show="row.status == 2 && row.refund_status == 0">{{ row.status_name.status_name }}</Tag>
              <Tag color="success" size="medium" v-show="(row.status == 1 || row.status == 5 || row.status == 0) && row.refund_status == 0">{{ row.status_name.status_name }}</Tag>
              <Tag color="error" size="medium" v-show="(row.status == 1 || row.status == 2 || row.status == 5 || row.status == 0) && row.refund_status != 0">{{ row.status_name.status_name }}</Tag>
              <Tag color="error" size="medium" v-if="!row.is_all_refund && row.refund.length">部分退款中</Tag>
              <Tag color="error" size="medium" v-if="row.is_all_refund && row.refund.length && row.refund_type != 6">退款中</Tag>
            </td>
            <td
              class="td-action"
              v-if="colVisible.action && lineIdx === 0"
              :rowspan="getOrderLines(row).length"
            >
              <slot name="action" :row="row"></slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
import chongImg from '@/assets/images/chong.jpg';

export default {
  name: 'StoreOrderCardList',
  props: {
    list: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    colVisible: { type: Object, required: true },
    checkUidList: { type: Array, default: () => [] },
    isAll: { type: Number, default: 0 },
    total: { type: Number, default: 0 },
    editableSource: { type: Boolean, default: false },
  },
  methods: {
    isOrderChecked(id) {
      const oid = parseInt(id);
      if (this.isAll === 1) {
        return !this.checkUidList.includes(oid);
      }
      return this.checkUidList.includes(oid);
    },
    toggleOrder(id, checked) {
      this.$emit('check-item', { rowid: id, checked });
    },
    showCraftStaffColumn(row) {
      return Number(row && row.order_type) !== 1;
    },
    showSalesStaffColumn(row) {
      return Number(row && row.order_type) !== 2;
    },
    hasUserId(row) {
      return Number(row && row.uid) > 0;
    },
    openUserDetail(row) {
      if (!this.hasUserId(row)) return;
      this.$emit('user-info', row);
    },
    getCustomerDisplay(row) {
      const name = row.nickname || row.real_name || '游客';
      if (this.hasUserId(row)) {
        return name + ' / ' + row.uid;
      }
      return name + (row.uid !== undefined && row.uid !== null && row.uid !== '' ? ' / ' + row.uid : '');
    },
    getCustomerPhone(row) {
      const phone = row.user_phone || '';
      if (!phone) return '';
      if (String(phone).startsWith('+')) return phone;
      return '+86 ' + phone;
    },
    getPayAmount(row) {
      if (row.paid <= 0) return 0;
      const raw = String(row.pay_price || '0');
      return raw.split(',')[0];
    },
    parseCombinationLines(row) {
      const lines = row.combination_pay_lines;
      return Array.isArray(lines) ? lines : [];
    },
    isCombinationDebtLine(line) {
      return (line && line.pay_sub_type === 'debt') || Number(line && line.type) === 10;
    },
    isCombinationBalanceLine(line) {
      if (!line) return false;
      const subType = line.pay_sub_type || '';
      return subType === 'balance' || subType === 'card_upgrade';
    },
    formatPayLineAmount(price) {
      return Number(price || 0).toFixed(2);
    },
    getOrderDebtAmount(row) {
      const debtAmount = Number(row.debt_amount || 0);
      if (debtAmount > 0) return debtAmount;
      if (row.pay_type === 'combination') {
        return this.parseCombinationLines(row).reduce((sum, line) => {
          if (!this.isCombinationDebtLine(line)) return sum;
          return sum + Number(line.price || 0);
        }, 0);
      }
      return 0;
    },
    getPendingDebt(row) {
      const pending = row.pending_debt_amount != null
        ? Number(row.pending_debt_amount)
        : Math.max(0, Number(row.debt_amount || 0) - Number(row.repaid_debt_amount || 0));
      return pending > 0 ? pending : 0;
    },
    getAmountSub(row) {
      const parts = [];
      if (row.pay_type === 'combination') {
        const lines = this.parseCombinationLines(row);
        if (lines.length) {
          lines.forEach((line) => {
            const price = Number(line.price || 0);
            if (price <= 0 || this.isCombinationDebtLine(line)) return;
            if (this.isCombinationBalanceLine(line)) {
              const label = line.pay_sub_type === 'card_upgrade' ? '卡升级抵扣' : '余额支付';
              parts.push(`${label} ¥ ${this.formatPayLineAmount(price)}`);
              return;
            }
            const name = line.name || '实收';
            parts.push(`${name} ¥ ${this.formatPayLineAmount(price)}`);
          });
        } else {
          const debt = this.getOrderDebtAmount(row);
          const yue = Number(row.yue_pay_price || 0);
          const pay = Number(String(row.pay_price || '0').split(',')[0] || 0);
          const other = Math.max(0, pay - debt - yue);
          if (other > 0) {
            parts.push(`实收 ¥ ${other.toFixed(2)}`);
          }
        }
      } else {
        if (row.yue_pay_price && parseFloat(row.yue_pay_price) > 0) {
          parts.push('余额支付 ¥ ' + row.yue_pay_price);
        }
        if (row.cash_pay_price && parseFloat(row.cash_pay_price) > 0 && row.pay_type === 'cash') {
          parts.push('现金 ¥ ' + row.cash_pay_price);
        }
      }
      return parts.length ? '(' + parts.join('，') + ')' : '';
    },
    getProductTypeTag(productType) {
      const map = { 0: '商品', 5: '卡项', 6: '服务' };
      return map[productType] || '';
    },
    getCartInfoList(row) {
      const info = row._info;
      if (!info) return [];
      if (Array.isArray(info)) return info;
      if (typeof info === 'object') {
        return Object.keys(info).map((key) => info[key]);
      }
      return [];
    },
    resolveCartInfo(val) {
      if (!val) return null;
      if (val.cart_info && typeof val.cart_info === 'object') {
        return val.cart_info;
      }
      if (val.productInfo || val.store_name != null || val.cart_num != null) {
        return val;
      }
      return null;
    },
    getOrderLines(row) {
      if (row.order_type == 0) {
        const lines = [];
        this.getCartInfoList(row).forEach((val) => {
          const cartInfo = this.resolveCartInfo(val);
          if (!cartInfo) return;
          if (cartInfo.cart_type != null && Number(cartInfo.cart_type) > 0) return;
          const pi = cartInfo.productInfo || {};
          const attr = pi.attrInfo || {};
          const image = attr.image || pi.image || '';
          let name = pi.store_name || pi.title || '';
          if (cartInfo.is_gift) {
            name = '[赠品] ' + name;
          }
          const spec = this.orderListSpecSuk({ cart_info: cartInfo });
          if (spec) name += ' | ' + spec;
          const qty = Number(cartInfo.cart_num || 1) || 1;
          let unitPrice = cartInfo.truePrice;
          if (unitPrice == null || unitPrice === '') {
            if (cartInfo.pay_price != null && cartInfo.pay_price !== '') {
              unitPrice = (Number(cartInfo.pay_price) / qty).toFixed(2);
            } else {
              unitPrice = cartInfo.sum_price || attr.price || '0.00';
            }
          }
          lines.push({
            image,
            name: name || '—',
            price: unitPrice,
            qty: cartInfo.cart_num || 1,
            couponPrice: parseFloat(cartInfo.coupon_price || 0) || 0,
            salesStaff: val.line_yeji_sales_staff || row.yeji_sales_staff || '',
            craftStaff: val.line_yeji_craft_staff || row.yeji_craft_staff || '',
            typeTag: this.getProductTypeTag(
              pi.product_type != null ? pi.product_type : cartInfo.product_type
            ),
          });
        });
        if (lines.length) return lines;
      }
      if (row.order_type == 1) {
        return [{
          image: chongImg,
          name: row.recharge_list_label || ((row.service_object_label ? '[' + row.service_object_label + ']' : '[本人]') + '充值订单'),
          price: row.paid > 0 ? row.pay_price : 0,
          qty: 1,
          salesStaff: row.yeji_sales_staff || row.yeji_staff || '',
          craftStaff: '',
          typeTag: '充值',
        }];
      }
      if (row.order_type == 2) {
        return [{
          image: row.link_img || '',
          name: row.link_name || '核销订单',
          price: row.paid > 0 ? row.pay_price : 0,
          qty: 1,
          salesStaff: '',
          craftStaff: row.yeji_craft_staff || row.yeji_staff || '',
          typeTag: '核销',
        }];
      }
      return [{
        image: '',
        name: '—',
        price: '0.00',
        qty: 1,
        salesStaff: row.yeji_sales_staff || '',
        craftStaff: row.yeji_craft_staff || '',
        typeTag: '',
      }];
    },
    orderListSpecSuk(val) {
      try {
        const cartInfo = this.resolveCartInfo(val) || (val && val.cart_info);
        const a = cartInfo && cartInfo.productInfo && cartInfo.productInfo.attrInfo;
        if (!a || a.suk === undefined || a.suk === null) return '';
        const t = String(a.suk).replace(/^\s+|\s+$/g, '').replace(/^　+|　+$/g, '');
        if (!t || t === '默认' || t === '默认规格') return '';
        return t;
      } catch (e) {
        return '';
      }
    },
  },
};
</script>

<style scoped lang="stylus">
.store-order-card-list
  margin-top 16px
  font-size 12px
  color #333
  position relative
  min-height 120px

.order-table-head
  display flex
  align-items center
  background #f7f7f7
  border 1px solid #e8eaec
  border-bottom none
  min-height 40px
  font-weight 500
  color #666
  .col
    padding 8px 10px
    text-align center
    box-sizing border-box
  .col-check
    width 90px
    flex-shrink 0
  .col-product
    flex 1
    min-width 200px
    text-align left
  .col-price
    width 90px
    flex-shrink 0
  .col-qty
    width 60px
    flex-shrink 0
  .col-coupon
    width 90px
    flex-shrink 0
  .col-craft-staff,
  .col-sales-staff
    width 90px
    flex-shrink 0
  .col-customer
    width 130px
    flex-shrink 0
  .col-amount
    width 120px
    flex-shrink 0
  .col-source
    width 90px
    flex-shrink 0
  .col-store
    width 120px
    flex-shrink 0
  .col-status
    width 90px
    flex-shrink 0
  .col-action
    width 100px
    flex-shrink 0

.check-all-link
  color #333
  font-size 12px
  font-weight normal

.order-empty
  padding 40px
  text-align center
  color #999
  border 1px solid #e8eaec

.order-block
  border 1px solid #e8eaec
  border-top none
  &.is-checked
    background #f5faff

.order-block-head
  display flex
  align-items center
  flex-wrap wrap
  gap 8px 16px
  padding 8px 12px
  background #e8f4ff
  border-bottom 1px solid #e8eaec
  .order-check
    margin-right 4px
  .head-item
    color #666
  .head-tag
    margin 0
  .head-gendan
    a
      color #2d8cf0
  .head-detail
    margin-left auto
    color #2d8cf0
    white-space nowrap

.order-block-table
  width 100%
  table-layout fixed
  border-collapse collapse
  td
    padding 10px
    border-bottom 1px solid #f0f0f0
    border-right 1px solid #f0f0f0
    vertical-align middle
    text-align center
    word-break break-all
    &:last-child
      border-right none
  tr:last-child td
    border-bottom none
  .td-check
    width 90px
  .td-product
    text-align left
  .td-customer
    text-align center
  .customer-name
    color #2d8cf0
    display block
  .customer-link
    cursor pointer
    &:hover
      text-decoration underline
  .customer-phone
    color #999
    margin-top 4px
    font-size 11px
    display block
    &.customer-link
      color #2d8cf0
  .customer-vip
    color #999
    margin-top 2px
  .td-action
    a + a
      margin-left 10px
  .amount-main
    font-weight 500
  .amount-pay-type
    color #666
    margin-top 4px
  .amount-sub
    color #999
    font-size 11px
    margin-top 2px
  .amount-debt
    color #ff4d4f
    font-size 11px
    margin-top 4px
  .coupon-deduct
    color #ff7700

.product-cell
  display flex
  align-items flex-start
  .product-img
    width 48px
    height 48px
    flex-shrink 0
    margin-right 10px
    img
      width 100%
      height 100%
      object-fit cover
      border-radius 2px
  .product-info
    flex 1
    min-width 0
  .product-name
    color #7c6bae
    display block
    line-height 1.4
    cursor pointer
  .product-name-plain
    color #333
    cursor default
  .product-tag
    margin-top 4px

col.col-check
  width 90px
col.col-product
  width auto
col.col-price
  width 90px
col.col-qty
  width 60px
col.col-coupon
  width 90px
col.col-craft-staff
  width 90px
col.col-sales-staff
  width 90px
col.col-customer
  width 130px
col.col-amount
  width 120px
col.col-source
  width 90px
col.col-store
  width 120px
col.col-status
  width 90px
col.col-action
  width 100px
</style>
