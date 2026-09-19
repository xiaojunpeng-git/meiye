<template>
  <div class="main">
    <div class="main-header bg-fff">
      <div class="main-title">
        <span>订单中心</span>
      </div>
    </div>
    <div class="main-content bg-fff">
      <div class="title">页面设置</div>
      <div class="form">
        <div class="form-item flex-y-center">
          <div class="form-label">选择风格</div>
          <div class="form-value">
            <Button type="primary" class="mr20" @click="changeStyleModal = true"
              >修改风格</Button
            >
            <span>风格{{ orderData.style }}</span>
          </div>
        </div>
      </div>
    </div>
    <div class="main-content bg-fff">
      <div class="title">订单入口</div>
      <div class="entry-tip">拖动可排序；名称、显示状态和图标保存后同步到会员端。</div>
      <draggable class="entry-list" :list="orderData.list" handle=".move-icon">
        <div class="entry-item" v-for="item in orderData.list" :key="item.key">
          <span class="iconfont-diy icondrag move-icon"></span>
          <div class="entry-key">{{ item.key }}</div>
          <Input v-model="item.title" class="entry-name" placeholder="入口名称" />
          <Select v-model="item.icon" class="entry-icon" placeholder="请选择图标">
            <Option v-for="icon in orderIconOptions" :key="icon.value" :value="icon.value">{{ icon.label }}</Option>
          </Select>
          <i-switch v-model="item.is_show" :true-value="1" :false-value="0" size="large">
            <span slot="open">显示</span><span slot="close">隐藏</span>
          </i-switch>
        </div>
      </draggable>
    </div>
    <Modal
      v-model="changeStyleModal"
      width="900px"
      scrollable
      closable
      title="风格选择器"
      :mask-closable="false"
      :z-index="999"
      @on-ok="saveStyle"
    >
      <div class="pic-box">
        <div
          class="pic-item cup"
          v-for="(item, index) in styleList"
          :key="index"
          @click="selectStyle(index)"
        >
          <div class="pic" :class="{ 'is-active': orderStyle == index + 1 }">
            <img :src="item.pic" alt="" />
          </div>
          <div class="text">风格{{ index + 1 }}</div>
        </div>
      </div>
    </Modal>
  </div>
</template>

<script>
import uploadPictures from "@/components/uploadPictures";
import propertyList from "@/plugins/propertyList";
import draggable from "vuedraggable";
export default {
  name: "",
  components: {
    uploadPictures,
    draggable,
  },
  data() {
    return {
      styleList: [
        {
          pic: require("@/assets/images/order-style-1.png"),
        },
        {
          pic: require("@/assets/images/order-style-2.png"),
        },
        {
          pic: require("@/assets/images/order-style-3.png"),
        },
      ],
      changeStyleModal: false,
      propertyList: propertyList,
      orderStyle: 1, // 默认选中风格
      orderEntryDefaults: [
        { key: "unpaid", title: "待付款", url: "/pages/goods/order_list/index?status=0", icon: "icon-ic_daifukuan", is_show: 1 },
        { key: "debt", title: "欠款", url: "/pages/users/debt/index", icon: "icon-ic_money", is_show: 1 },
        { key: "unshipped", title: "待发货", url: "/pages/goods/order_list/index?status=1", icon: "icon-ic_daifahuo", is_show: 0 },
        { key: "received", title: "待收货", url: "/pages/goods/order_list/index?status=10", icon: "icon-ic_daishouhuo", is_show: 0 },
        { key: "evaluated", title: "待评价", url: "/pages/goods/order_list/index?status=3", icon: "icon-ic_daipingjia", is_show: 1 },
        { key: "refund", title: "售后", url: "/pages/users/user_return_list/index", icon: "icon-ic_returnmoney", is_show: 1 },
      ],
      orderIconOptions: [
        { label: "待付款", value: "icon-ic_daifukuan" },
        { label: "欠款", value: "icon-ic_money" },
        { label: "待发货", value: "icon-ic_daifahuo" },
        { label: "待收货", value: "icon-ic_daishouhuo" },
        { label: "待评价", value: "icon-ic_daipingjia" },
        { label: "售后", value: "icon-ic_returnmoney" },
        { label: "通用订单", value: "icon-ic_order1" },
      ],
    };
  },
  computed: {
    orderData(val) {
      return this.$store.state.admin.userTemplateConfig.order;
    },
  },

  created() {},
  mounted() {
    this.orderStyle =
      this.$store.state.admin.userTemplateConfig.order.style;
    this.ensureOrderList();
  },
  methods: {
    ensureOrderList() {
      if (!Array.isArray(this.orderData.list) || !this.orderData.list.length) {
        this.$set(this.orderData, "list", this.orderEntryDefaults.map((item) => ({ ...item })));
      }
    },
    selectStyle(index) {
      this.orderStyle = index + 1;
    },
    saveStyle() {
      this.orderData.style = this.orderStyle;
    },
  },
};
</script>
<style lang="stylus" scoped>
.bg-fff {
  background-color: #fff;
}

.main {
  .entry-tip {
    margin: -8px 0 16px;
    font-size: 12px;
    color: #999999;
  }

  .entry-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .entry-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border: 1px solid #f0f0f0;
    border-radius: 6px;
    background: #fafafa;

    .move-icon {
      color: #bfbfbf;
      cursor: move;
    }

    .entry-key {
      width: 72px;
      font-size: 12px;
      color: #999999;
    }

    .entry-name {
      width: 130px;
    }

    .entry-icon {
      width: 150px;
    }
  }

  .main-header {
    font-size: 16px;
    padding: 20px 15px;
    margin: 6px 0 0;
    font-weight: 500;
    color: #333333;
    line-height: 16px;
  }

  .main-content {
    padding: 20px 15px;
    margin-top: 6px;

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
        margin-bottom: 30px;

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

          .box {
            width: 60px;
            height: 60px;
            margin-bottom: 10px;

            img {
              width: 100%;
              height: 100%;
            }
          }

          .upload-box {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            width: 100%;
            height: 100%;
            background: #fff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            border: 1px solid #EEEEEE;
            color: #BFBFBF;
          }
        }
      }
    }
  }
}

.pic-box {
  display: flex;
  flex-wrap: wrap;
  margin: 0 -6px;

  .pic-item .is-active {
    border: 1px solid #1890FF;

    img {
      transform: scale(1.05);
    }
  }

  .pic {
    width: 276px;
    height: 218px;
    background: #F5F5F5;
    border-radius: 4px;
    border: 1px solid #DDDDDD;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 6px;
    overflow: hidden;

    img {
      width: 216px;
      transition: all 0.7s;
      -webkit-user-drag: none;
    }

    img:hover {
      transform: scale(1.05);
    }
  }

  .text {
    text-align: center;
  }
}
</style>
