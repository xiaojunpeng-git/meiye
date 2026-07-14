<template>
  <div class="order-user">
    <div class="sel-user">
      <div class="avatar">
        <img :src="selectData.avatar || require('@/assets/images/tourist.png')" alt="头像" />
      </div>
      <div class="item-right">
        <div class="user">
          <div>{{ selectData.uid ? selectData.nickname : '游客' }}</div>
        </div>
        <div class="money" v-if="selectData.uid">
          <div>
            <span class="pr20" v-if="selectData.phone">{{
              selectData.phone
            }}</span
            >余额 <span class="num">{{ selectData.now_money || 0 }}</span>
          </div>
          <div>
            本金 <span class="num">{{ selectData.ben_money || 0 }}</span>
          </div>
          <div>
            赠金 <span class="num">{{ selectData.give_money || 0 }}</span>
          </div>
          <div>
            积分 <span class="num">{{ selectData.integral || 0 }}</span>
          </div>
        </div>
      </div>
    </div>
    <div class="cart-num">
      <div class="cart-num-left">
        <span>共</span>
        <span class="num">{{ total_num }}</span>
        <span>件商品</span>
      </div>
      <div v-if="pendingDebt > 0" class="cart-num-right">
        <span class="debt-label">欠款</span>
        <span class="debt-amount">¥{{ pendingDebtText }}</span>
        <Button type="primary" size="small" class="debt-repay-btn" @click="openDebtRepay">还款</Button>
      </div>
    </div>
    <div class="goods-list">
      <Table
        :key="tableKey"
        :columns="columns"
        ref="selection"
        :border="false"
        :data="writeOffData"
        :loading="loading"
        no-data-text="暂无数据"
        @on-selection-change="selectOne"
        no-filtered-data-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="image">
          <div class="product-data">
            <img class="image" :src="row.cart_info.productInfo.image" />
            <div class="name line2">
              <span class="is-gift" v-if="row.is_gift">赠送</span>
              {{ row.cart_info.productInfo.store_name }}
            </div>
          </div>
        </template>
        <template slot-scope="{ row }" slot="value">
          <div>{{ row.cart_info.productInfo.attrInfo.suk }}</div>
        </template>
        <template slot-scope="{ row }" slot="sellPrice">
          <div>
            {{
              row.cart_info.productInfo.attrInfo
                ? row.cart_info.productInfo.attrInfo.price
                : row.cart_info.productInfo.price
            }}
          </div>
        </template>
        <template slot-scope="{ row }" slot="pay_price">
          <div>
            {{
              row.cart_info.pay_price
            }}
          </div>
        </template>
        <template slot-scope="{ row }" slot="price">
          <div>{{ row.cart_info.truePrice }}</div>
        </template>
        <template slot-scope="{ row }" slot="writeOff">
          <div v-if="isDisplayOnlyGift(row)">-</div>
          <div v-else-if="row.is_writeoff !== null">
            <div v-if="row.is_writeoff">已消耗</div>
            <div
              v-else-if="row.writeoffed_num"
              style="color: #1890ff;"
            >
              已消耗 {{ row.writeoffed_num }} 件
            </div>
            <div v-else style="color: #F5222D;">待消耗</div>
          </div>
        </template>
        <template slot-scope="{ row }" slot="cartNum">
          <div v-if="isDisplayOnlyGift(row)">-</div>
          <div v-else>{{ row.write_times }}</div>
        </template>
        <template slot-scope="{ row }" slot="write_surplus_times">
                <div style="color: red;font-weight: bold">余：{{ row.write_surplus_times }}</div>
        </template>
        <template slot-scope="{ row, index }" slot="surplus_num">
          <div class="acea-row row-middle">
            <div class="surplus-num">
              <div
                class="operation reduce"
                :class="{off: row.value <= 1}"
                @click="reduce(row, index)"
              >
                <Icon type="md-remove-circle" />
              </div>
              <InputNumber
                :max="quantityMax(row)"
                :min="1"
                :readonly="quantityMax(row) <= 1"
                :precision="0"
                v-model="writeOffData[index].value"
                @input="clearYeji(index)"
              ></InputNumber>
              <div
                class="operation add"
                :class="{off: row.value >= quantityMax(row)}"
                @click="add(row, index)"
              >
                <Icon type="md-add-circle" />
              </div>
            </div>
          </div>
        </template>
        <template slot-scope="{ row, index }" slot="yeji_out">
          <div class="relative fs-14 pointer text-wlll-1890FF" @click="doYeji(row,index)" v-if="row.product_type != 0">
                <span v-if="syncAll[index].staffChoose.length ==  0" style="color: #1890FF;font-size: 13px">手艺人</span>
                <span v-else style="color: #1890FF;font-size: 13px">
                  手艺人<span style="color: #736a6a" v-for="(cItem,cindex) in syncAll[index].staffChoose">
                                  <span v-if="cindex == 0">:{{cItem.staff_name}}</span>
                                  <span v-else>,{{cItem.staff_name}}</span>
                                  <span v-if="cItem.is_dian == 1">(点)</span>
                                    <span v-else>(轮)</span>
                       </span>
                </span>
          </div>
        </template>
        <template slot-scope="{ row, index }" slot="service_object">
          <div class="service-object">
            <span :class="{ active: row.service_object === '本人' }" @click="setServiceObject(index, '本人')">本人</span>
            <span class="divider">/</span>
            <span :class="{ active: row.service_object === '朋友' }" @click="setServiceObject(index, '朋友')">朋友</span>
          </div>
        </template>
      </Table>
    </div>
    <yeji @doChoose="doChoose" @sureSync="sureSync" :isShouyi="true" :showApplyAll="false" :syncProduct="syncAll" :yeji="setYeji" :staffIds="staffIds" @closeYeji="closeYeji" :visible="yejiVisible" ref="yeji"></yeji>
    <reservation ref="reservation" @submitSuccess="submitSuccess"></reservation>
  </div>
</template>

<script>
import yeji from '@/components/yeji';
import goodsList from "@/pages/hang/components/goodsList";
import reservation from "./reservation.vue";
import { writeCartList } from "@api/order";

export default {
  name: "userOrder",
  props: ["selectData", "orderNumId", "isCardNum"],
  components: { goodsList, reservation,yeji },
  data() {
    return {
      yejiVisible: false,
      isChange:false,
      writeOffData: [],
      staffIds: [],
      syncAll:[],
      yejiIndex:0,
      setYeji:{
        link_id:0,
        cart_id:0,
        price:0,
        goods_id:0,
        type:3,
        staffChoose:[]
      },
      writeOffItem: [],
      columnsCard:[
        {
          type: "selection",
          width: 50,
          align: "center",
        },
        {
          title: "商品",
          slot: "image",
          align: "left",
          minWidth: 150,
        },
        {
          title: "规格",
          slot: "value",
          align: "left",
          minWidth: 90,
        },
        {
          title: "剩余数量",
          slot: "write_surplus_times",
          align: "left",
          minWidth: 70,
        },
        {
          title: "总数",
          slot: "cartNum",
          align: "left",
          minWidth: 30,
        },
        {
          title: "待消耗数量",
          key: "write_surplus_times",
          slot: "surplus_num",
          width: 250,
        },
        {
          title: "操作",
          key: "yeji_option",
          slot: "yeji_out",
          width: 100,
        },
        {
          title: "服务对象",
          slot: "service_object",
          align: "center",
          width: 120,
        }
      ],
      columnsBak: [
        {
          type: "selection",
          width: 50,
          align: "center",
        },
        {
          title: "商品",
          slot: "image",
          align: "left",
          minWidth: 150,
        },
        {
          title: "实付金额",
          slot: "pay_price",
          align: "left",
          minWidth: 60,
        },
        {
          title: "剩余数量",
          slot: "write_surplus_times",
          align: "left",
          minWidth: 70,
        },
        {
          title: "总数",
          slot: "cartNum",
          align: "left",
          minWidth: 30,
        },
        {
          title: "待消耗数量",
          key: "write_surplus_times",
          slot: "surplus_num",
          width: 250,
        },
        {
          title: "操作",
          key: "yeji_option",
          slot: "yeji_out",
          width: 100,
        },
        {
          title: "服务对象",
          slot: "service_object",
          align: "center",
          width: 120,
        }
      ],
      selectDataList: [],
      loading: false,
      give_integral_img: require("@/assets/images/give_integral.png"),
      give_coupon_img: require("@/assets/images/give_coupon.png"),
      total_num: 0,
      pendingDebt: 0,
      isFullDebtOrder: false,
    };
  },
  computed: {
    pendingDebtText() {
      return Number(this.pendingDebt || 0).toFixed(2);
    },
    columns() {
      return Number(this.isCardNum) === 1 ? this.columnsCard : this.columnsBak;
    },
    tableKey() {
      return `${this.isCardNum || 0}-${this.selectData.id || 0}-${this.writeOffData.length}`;
    },
  },
  watch: {
    selectData: {
      handler: function(newV, oldV) {
        if (newV) {
          let data = {
            oid: newV.id,
          };
          if (newV.id) this.getWriteOff(data);
        }
      },
      deep: true,
    },
  },
  mounted() {
    let data = {
      oid: this.selectData.id,
    };
    if (this.selectData.id) this.getWriteOff(data);
  },
  methods: {
    /** 仅展示用赠送行（订单赠送积分/券），不可核销 */
    isDisplayOnlyGift(row) {
      return !!(row && row.is_gift && !row.cart_id);
    },
    deepClone(obj) {
      return JSON.parse(JSON.stringify(obj));
    },
    setServiceObject(index, value) {
      this.$set(this.writeOffData[index], 'service_object', value);
    },
   doYeji(row,index){
      let that=this;
      this.yejiIndex=index;
     let dianAttr=[];
      this.writeOffData.forEach((outItem,index) => {
           that.syncAll[index].value=outItem.value;
           that.syncAll[index].price=Math.round(Number(that.syncAll[index].once_price) * Number(that.syncAll[index].value))
      });
       this.staffIds=[];
       that.syncAll[index].staffChoose.forEach(function (item){
           that.staffIds.push(item.staff_id);
           if(item.is_dian == 1){
               dianAttr.push(item.staff_id);
          }
       })
       that.setYeji=this.deepClone(that.syncAll[index]);
       this.$refs.yeji.showAdd=true;
       that.yejiVisible=true;
       that.$refs.yeji.dianAttr=dianAttr;
   },
    clearYeji(index){
      this.syncAll[index].staffChoose=[];
    },
    doChoose(yeji){
      this.$set(this.syncAll,this.yejiIndex, yeji);
      this.closeYeji();
    },
    sureSync(yeji){
       this.$set(this.syncAll,this.yejiIndex, yeji);
       let staffChoose=this.deepClone(this.syncAll[this.yejiIndex].staffChoose);
       let len=staffChoose.length;
       let that=this;
       if(len > 0) {
         this.syncAll.forEach((item, index) => {
                if(index != that.yejiIndex){
                     var number=Math.floor(item.price/ len);
                     var theStaffChoose=staffChoose;
                     theStaffChoose.forEach((subItem,subIndex)=>{
                          subItem.yeji=number;
                     })
                    var yu=item.price % len;
                     if(len > 0 && yu > 0){
                        theStaffChoose[len-1].yeji= Number(theStaffChoose[len-1].yeji)+yu;
                     }
                     item.staffChoose=theStaffChoose;
                }
         })
       }
    },
    closeYeji(){
      this.yejiVisible=false;
    },
	submitSuccess(){
		let data = {
		  oid: this.selectData.id,
		};
		this.getWriteOff(data);
	},
	serviceTap(row){
		if(row.unservice_num == 0) return
		this.$refs.reservation.modals = true;
		this.$refs.reservation.reservationOrder(this.selectData.id);
	},
    reduce(row, index) {
      if (row.value <= 1) {
        return;
      }
      this.writeOffData[index].value--;
      this.syncAll[index].staffChoose=[];
    },
    effectiveMax(row) {
      if (!row || this.isDisplayOnlyGift(row)) return 0;
      const effective = row.effective_write_surplus_times != null
        ? Number(row.effective_write_surplus_times)
        : Number(row.write_surplus_times || 0);
      return Math.max(effective, 0);
    },
    quantityMax(row) {
      if (!row || this.isDisplayOnlyGift(row)) return 0;
      const effective = this.effectiveMax(row);
      const surplus = Number(row.write_surplus_times || 0);
      if (surplus <= 0) return effective;
      return Math.max(effective, 1);
    },
    isDebtWriteoffExhausted() {
      if (this.pendingDebt <= 0) return false;
      return (this.writeOffData || []).some((row) => {
        if (this.isDisplayOnlyGift(row)) return false;
        const surplus = Number(row.write_surplus_times || 0);
        const effective = this.effectiveMax(row);
        return surplus > 0 && effective <= 0;
      });
    },
    checkDebtWriteoffBlocked(list) {
      if (this.isDebtWriteoffExhausted() && !(list || []).length) {
        return true;
      }
      return (list || []).some((row) => {
        if (this.isDisplayOnlyGift(row)) return false;
        const effective = this.effectiveMax(row);
        const num = Number(row.value || 0);
        const surplus = Number(row.write_surplus_times || 0);
        if (surplus > 0 && effective <= 0) {
          return num > 0 || this.pendingDebt > 0;
        }
        return num > effective;
      });
    },
    add(row, index) {
      if (row.value >= this.effectiveMax(row)) {
        return;
      }
      this.writeOffData[index].value++;
      this.syncAll[index].staffChoose=[];
    },
    getVerifyData() {
      if (!this.selectDataList.length)
        return this.$Message.error("请选择要消耗的商品");
      let cartIds = [];
      this.selectDataList.forEach((v) => {
        cartIds.push({
          cart_id: v.cart_id,
          cart_num: v.surplus_num,
        });
      });
    },
    remarks() {
      this.$emit("remarks");
    },
    cancel() {
      this.$refs.selection.selectAll(false);
    },
    selectOne(data) {
      const ids = data.map((item) => {
        return item.id;
      });
      this.writeOffData.forEach((item) => {
        item._checked = ids.includes(item.id);
      });
      this.selectDataList = this.writeOffData.filter((item) => {
        return item._checked;
      });
      this.$emit('selectData', this.selectDataList);
    },
    openDebtRepay() {
      this.$emit('repay-debt');
    },
    getWriteOff(data, keepSelection = false) {
      let that=this;
      let savedServiceObject = {};
      if (keepSelection) {
        this.writeOffData.forEach((item) => {
          if (item.cart_id && item.service_object) {
            savedServiceObject[item.cart_id] = item.service_object;
          }
        });
      }
      writeCartList(data)
        .then((res) => {
          this.pendingDebt = Math.max(0, Number(res.data.pending_debt || 0));
          this.isFullDebtOrder = !!Number(res.data.is_full_debt_order || 0);
          const cart_info = res.data.cart_info || [];
          const createCartInfoItem = (image, store_name) => {
            return {
              is_gift: 1,
              write_surplus_times: 0,
              cart_info: {
                productInfo: {
                  image,
                  store_name,
                  attrInfo: {
                    suk: '-',
                    price: '-',
                  },
                },
                truePrice: '-',
              },
            };
          };
          this.total_num = cart_info.length;
          if (this.selectData.give_integral) {
            cart_info.push(createCartInfoItem(this.give_integral_img, `赠送${this.selectData.give_integral}积分`));
          }
          (this.selectData.give_coupon || []).forEach((item) => {
            cart_info.push(createCartInfoItem(this.give_coupon_img, item.coupon_title));
          });
          let sync=[];
          this.writeOffData = cart_info.map((item,index) => {
            var oncePrice=Number(item.cart_info.yeji) ;
            sync[index]={
              goods_id:item.product_id,
              price:oncePrice,
              once_price:oncePrice,
              true_price:item.cart_info.yeji,
              write_times:item.write_times,
              value:1,
              link_id:0,
              cart_id:item.cart_id,
              order_id:that.selectData.id,
              type:3,
              staffChoose:[]
            };
            return {
              ...item,
              value: Number(item.write_surplus_times || 0) > 0 && !this.isDisplayOnlyGift(item) ? 1 : 0,
              _disabled: this.isDisplayOnlyGift(item)
                || (item.is_writeoff == 1 && !(item.write_surplus_times > 0))
                || item.product_type == 0,
              _checked: false,
              service_object: savedServiceObject[item.cart_id] || '本人',
            };
          })
           this.syncAll=sync;
        })
        .catch((err) => {
          this.pendingDebt = 0;
          this.isFullDebtOrder = false;
          this.$Message.error(err.msg);
        });
    },
  },
};
</script>

<style lang="stylus" scoped>
.order-user {
  height: 100%;
}

.sel-user {
  display: flex;
  align-items center
  background-color rgba(255, 119, 0, 0.05)
  padding: 18px;
  border-radius: 10px;

  .avatar {
    width: 50px;
    height: 50px;
    margin-right: 10px;

    img {
      width: 100%;
      height: 100%;
      border-radius 50%
    }
  }

  .item-right {
    flex 1

    .user {
      font-size 18px
      font-weight: 600;
      color: rgba(0, 0, 0, 0.85);
    }

    .money {
      display: flex;
      align-items: flex-end;
      font-weight: 400;
      color: rgba(51, 51, 51, 0.85);
      font-size 12px

      .num {
        font-weight: 600;
        color: #F5222D;
        font-size 17px
        padding-right: 20px;
      }

      .pr20 {
        padding-right: 20px;
      }
    }
  }
}

.cart-num {
  display flex
  justify-content space-between
  font-weight: 500;
  align-items: flex-end;
  padding: 27px 10px 20px;

  .cart-num-left {
    color: #303133;
    font-size 16px;

    .num {
      color: #FF7700;
    }
  }

  .cart-num-right {
    display flex
    align-items center
    font-size 14px

    .debt-label {
      color: #606266
      margin-right 8px
    }

    .debt-amount {
      color: #F5222D
      font-size 18px
      font-weight 600
      margin-right 12px
    }

    .debt-repay-btn {
      min-width 64px
    }
  }

  .money {
    color: #F5222D;
    font-size 24px
    font-weight bold
  }
}

.goods-list {

  /deep/ table {
    width: 100% !important;
  }

  /deep/ .ivu-table {
    border 1px solid #f2f2f2;
    border-bottom: none;
  }
}


.product-data {
  display: flex;
  align-items: center;
  padding-right: 10px;
}

.product-data .name {
  line-height: 1.4;
  text-align: left;
  margin-left: 5px;
  .is-gift {
    font-size: 12px;
    border: 1px solid #f5222d;
    background: #f5222d;
    color: #fff;
    padding: 0px 4px;
    border-radius: 3px;
  }
}

.product-data .image {
  width: 50px !important;
  height: 50px !important;
  margin-right 5px
  border-radius 5px
}

.surplus-num {
  display: flex;
  align-items center

  /deep/ .ivu-input-number {
    width: 50px;
    border none
  }

  /deep/ .ivu-input-number-input {
    text-align center
  }

  /deep/ .ivu-input-number-handler-wrap {
    display: none;
  }

  .operation {
    margin: 0 5px;
    font-size 28px
    color: #000;
  }

  .operation.off {
    color: #EEEEEE;
  }

  .reduce {
    color: #1890FF;
  }

  .add {
    color: #1890FF;
  }
}

.badge {
  position: absolute;
  top: -3px;
  right: -9px;
  min-width: 12px;
  height: 12px;
  border-radius: 6px;
  background: #FF7700;
  text-align: center;
  font-weight: 500;
  font-size: 9px;
  line-height: 12px;
  color: #FFFFFF;
}
.btn {
  display:flex;
  justify-content: center;
  align-items: center;
  width: 120px;
  height: 44px;
  border-radius: 22px;
  background: #F2F3F5;
  cursor: pointer;
  font-size: 18px;
  color: rgba(0,0,0,0.85);
}
.pay{
  color: #FFFFFF;
  background: #FF7700;
}
.zent-input{
  width: 90%;
  margin-top: 10px;
  border-radius: 4px;
  height: 30px;
  border:1px solid #bfbfbf;
  box-shadow: 0 0 4px 0 rgba(237, 234, 255, 0.2);
  font-size: 16rpx;
  display: inline-block;
  flex: 1;
  min-width: 80px;
  padding: 0 12px;
  color: #323233;
  -webkit-appearance: none;
  -moz-appearance: none;
  appearance: none;
  outline: none;
}
.service-object {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  .divider {
    margin: 0 5px;
    color: #999;
  }
  span:not(.divider) {
    cursor: pointer;
    color: #666;
    &.active {
      color: #1890FF;
      font-weight: 500;
    }
  }
}
</style>
