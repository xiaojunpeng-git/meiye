<template>
  <div class="article-manager video-icon form-submit" id="shopp-manager">
    <div class="i-layout-page-header">
      <PageHeader class="product_tabs" hidden-breadcrumb>
        <div slot="title" class="acea-row row-middle">
          <router-link :to="{ path: `${roterPre}/setting/shop/document` }">
            <div class="font-sm after-line">
              <span class="iconfont iconfanhui"></span>
              <span class="pl10">返回</span>
            </div>
          </router-link>
          <span class="mr20 ml16">小票配置</span>
        </div>
      </PageHeader>
    </div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="flex justify-between warpper">
        <Form :model="formItem" :label-width="120">
          <!-- 桌码：流水、分类、编号 -->
          <FormItem label="小票头部：">
            <CheckboxGroup v-model="formItem.header">
              <Checkbox :label="0">商家名称</Checkbox>
            </CheckboxGroup>
          </FormItem>
          <!-- 普通、积分 -->
          <FormItem label="配送信息：">
            <CheckboxGroup v-model="formItem.delivery">
              <Checkbox :label="0">配送方式</Checkbox>
              <Checkbox :label="1">客户姓名</Checkbox>
              <Checkbox :label="2">客户电话</Checkbox>
              <Checkbox :label="3">收货地址</Checkbox>
            </CheckboxGroup>
          </FormItem>
          <FormItem label="买家备注：">
            <Checkbox
              v-model="formItem.buyer_remarks"
              :true-value="1"
              :false-value="0"
              >买家备注</Checkbox
            >
          </FormItem>
          <FormItem label="商品信息：">
            <CheckboxGroup v-model="formItem.goods">
              <Checkbox :label="0">商品基础信息</Checkbox>
              <Checkbox :label="1">规格编码</Checkbox>
            </CheckboxGroup>
          </FormItem>
          <!-- 普通、积分 -->
          <FormItem label="运费税费信息：">
            <Checkbox
              v-model="formItem.freight"
              :true-value="1"
              :false-value="0"
              >运费</Checkbox
            >
          </FormItem>
          <FormItem label="优惠信息：">
            <Checkbox
              v-model="formItem.preferential"
              :true-value="1"
              :false-value="0"
              >优惠总计</Checkbox
            >
          </FormItem>
          <!-- 积分：抵扣 -->
          <FormItem label="支付信息：">
            <CheckboxGroup v-model="formItem.pay">
              <Checkbox :label="0">支付方式</Checkbox>
              <Checkbox :label="1">实收金额</Checkbox>
            </CheckboxGroup>
          </FormItem>
          <FormItem label="其他订单信息：">
            <CheckboxGroup v-model="formItem.order">
              <Checkbox :label="0">订单编号</Checkbox>
              <Checkbox :label="1">下单时间</Checkbox>
              <Checkbox :label="2">支付时间</Checkbox>
              <Checkbox :label="3">打印时间</Checkbox>
            </CheckboxGroup>
          </FormItem>
          <FormItem label="推广二维码：">
            <Checkbox v-model="formItem.code" :true-value="1" :false-value="0"
              >选择系统链接</Checkbox
            >
            <div v-if="formItem.code" class="link">
              <div class="input-box">
                链接：{{ formItem.code_url }}
                <span class="change" @click="getLink(index)">{{
                  formItem.code_url ? '修改' : '选择'
                }}</span>
              </div>
            </div>
          </FormItem>
          <FormItem label="底部公告：">
            <Checkbox
              v-model="formItem.show_notice"
              :true-value="1"
              :false-value="0"
              >底部公告</Checkbox
            >
            <div v-if="formItem.show_notice">
              <Input
                v-model="formItem.notice_content"
                maxlength="50"
                show-word-limit
                type="textarea"
                placeholder="请输入公告内容"
                style="width: 500px"
              />
            </div>
          </FormItem>
        </Form>
        <div class="ticket-preview">
          <div class="out-line"></div>
          <div class="ticket-content">
            <div
              class="flex-center fs-18 fw-500 mt-6"
              v-show="formItem.header.includes(0)"
            >
              MOHE门店名称
            </div>
            <!-- 配送方式 -->
            <div
              class="btn-line mt-10 pt-10 pb-10"
              v-show="formItem.delivery.length"
            >
              <div class="flex" v-show="formItem.delivery.includes(0)">
                <div>配送方式：</div>
                <div>商家配送</div>
              </div>
              <div class="flex mt-10" v-show="formItem.delivery.includes(1)">
                <div>客户姓名：</div>
                <div>王一梅</div>
              </div>
              <div class="flex mt-10" v-show="formItem.delivery.includes(2)">
                <div>客户电话：</div>
                <div>13033445566</div>
              </div>
              <div class="flex mt-10" v-show="formItem.delivery.includes(3)">
                <div>收货信息：</div>
                <div class="flex-1">上海市浦东新区世界大道25号B座309室</div>
              </div>
            </div>
            <!-- 备注 -->
            <div class="btn-line pt-10 pb-10" v-show="formItem.buyer_remarks">
              <div class="flex">
                <div>买家备注：</div>
                <div class="flex-1">
                  买家备注信息买家备注信息买家备注信息买家备注信息买家备注信息
                </div>
              </div>
            </div>
            <!-- 商品 -->
            <div
              class="btn-line pt-10 pb-10"
              v-show="formItem.goods.includes(0)"
            >
              <div>商品</div>
              <div class="flex justify-between mt-10">
                <span>单价</span>
                <span>数量</span>
                <span>小计</span>
              </div>
            </div>
            <div
              class="btn-line pt-10 pb-10"
              v-show="formItem.goods.includes(0)"
            >
              <div>
                <div>
                  商品名称商品名称商品名称商品名称商品名称商品名称（规格1/规格2）
                </div>
                <div
                  class="flex justify-between mt-10"
                  v-show="formItem.goods.includes(1)"
                >
                  <div>规格编码</div>
                  <div>1234567899</div>
                </div>
                <div class="flex justify-between mt-10">
                  <span>100.0</span>
                  <span>2</span>
                  <span>200.0</span>
                </div>
              </div>
            </div>
            <div
              class="btn-line pt-10 pb-10"
              v-show="formItem.goods.includes(0)"
            >
              <div>
                <div>【会员价】商品名称商品名称商品名称商品名称商品名称</div>
                <div
                  class="flex justify-between mt-10"
                  v-show="formItem.goods.includes(1)"
                >
                  <div>规格编码</div>
                  <div>1234567899</div>
                </div>
                <div class="flex justify-between mt-10">
                  <span>100.0</span>
                  <span>2</span>
                  <span>200.0</span>
                </div>
              </div>
            </div>
            <!-- 共3件 -->
            <div class="btn-line flex justify-between pt-10 pb-10">
              <div>共3件</div>
              <div class="fw-500">合计：¥300.00</div>
            </div>
            <!-- 运费 -->
            <div
              class="btn-line flex justify-between pt-10 pb-10"
              v-show="formItem.freight"
            >
              <template>
                <div>运费</div>
                <div class="fw-500">¥10.00</div>
              </template>
            </div>
            <!-- 优惠 -->
            <div
              class="btn-line flex justify-between pt-10 pb-10"
              v-show="formItem.preferential"
            >
              <template>
                <div>优惠</div>
                <div class="fw-500">总计：-¥24.00</div>
              </template>
            </div>
            <!-- 支付信息 -->
            <div class="btn-line pt-10 pb-10" v-show="formItem.pay.length">
              <div v-show="formItem.pay.includes(0)">支付方式：微信支付</div>
              <div v-show="formItem.pay.includes(1)" class="mt-10 fw-500">
                实收：¥276.00
              </div>
            </div>
            <!-- 订单信息 -->
            <div class="pt-10 pb-10">
              <div v-show="formItem.order.includes(0)">
                订单编号：12223378900
              </div>
              <div v-show="formItem.order.includes(1)" class="mt-10">
                下单时间：2024/09/23 12:00:00
              </div>
              <div v-show="formItem.order.includes(2)" class="mt-10">
                支付时间：2024/09/23 12:00:00
              </div>
              <div v-show="formItem.order.includes(3)" class="mt-10">
                打印时间：2024/09/23 12:00:00
              </div>
            </div>
            <!-- 二维码 -->
            <div
              class="mt-20 flex-center"
              v-show="formItem.code"
              id="qrcode"
            ></div>
            <div class="mt-20 flex-center" v-if="formItem.show_notice">
              {{ formItem.notice_content }}
            </div>
          </div>
          <div class="bottom-notice">
            <img class="image" src="@/assets/images/p-btn.png" alt="" />
          </div>
        </div>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="fixed-card">
      <Button type="primary" class="submission" @click="save">保存</Button>
    </Card>
    <linkaddress ref="linkaddres" @linkUrl="linkUrl"></linkaddress>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import { printContent, printSaveContent } from '@/api/setting';
import linkaddress from '@/components/linkaddress';
import QRCode from 'qrcodejs2';
import Setting from '@/setting';
export default {
  name: 'content',
  components: { linkaddress },
  data() {
    return {
      formItem: {
        scene: 1,
        header: [0],
        delivery: [0, 1, 2, 3],
        buyer_remarks: 1,
        goods: [0],
        freight: 1,
        preferential: 1,
        pay: [0, 1],
        order: [0, 1, 2, 3],
        code: 0,
        code_url: '',
        show_notice: 1,
        notice_content: '感谢您的购物，欢迎再次光临！',
      },
      code: '',
      BaseURL: Setting.apiBaseURL.replace(/adminapi/, ''),
      id: this.$route.query.id,
      roterPre: Setting.roterPre,
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile', 'menuCollapse']),
    labelWidth() {
      return this.isMobile ? undefined : 120;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
    labelBottom() {
      return this.isMobile ? undefined : 15;
    },
  },
  created() {
    if (this.id) this.getPrintContent();
  },
  methods: {
    getPrintContent() {
      printContent(this.id, {
        scene: 1,
      }).then((res) => {
        if (Array.isArray(res.data)) {
          return;
        }
        res.data.preferential = res.data.preferential[0];
        this.formItem = res.data;
        if (res.data.code && res.data.code_url) {
          this.code = this.BaseURL + res.data.code_url;
          this.$nextTick((e) => {
            this.drawCode(this.code);
          });
        }
      });
    },
    save() {
      printSaveContent(this.id, this.formItem)
        .then((res) => {
          this.$Message.success('保存成功');
        })
        .catch((err) => {
          this.$Message.error('保存失败');
        });
    },
    getLink(index) {
      this.$refs.linkaddres.modals = true;
    },
    linkUrl(e) {
      this.formItem.code_url = e;
      let url = this.BaseURL + e;
      this.drawCode(url);
    },
    drawCode(url) {
      let qrcode = '';
      let obj = document.getElementById('qrcode');
      obj.innerHTML = '';
      qrcode = new QRCode(obj, {
        text: url, // 需要转换为二维码的内容
        width: 128,
        height: 128,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H,
      });
    },
  },
};
</script>
<style scoped lang="stylus">
/deep/ .ivu-checkbox-wrapper {
  font-size: 12px;
  margin-right: 30px;
}

/deep/ .ivu-checkbox {
  margin-right: 6px;
}

/deep/ .ivu-checkbox-inner {
  width: 14px;
  height: 14px;
  font-size: 12px;
}

/deep/ .ivu-checkbox-checked .ivu-checkbox-inner:after {
  top: 1px;
  left: 4px;
}

/deep/.ivu-card {
  border-radius: 0;
  font-size: inherit;
}

.warpper {
  max-width: 1200px;
}

.fixed-card {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 200px;
  z-index: 45;
  box-shadow: 0 -1px 2px rgb(240, 240, 240);

  /deep/ .ivu-card-body {
    padding: 15px 16px 14px;
    text-align: center;
  }

  .ivu-form-item {
    margin-bottom: 0;
  }

  /deep/ .ivu-form-item-content {
    margin-right: 124px;
    text-align: center;
  }

  .ivu-btn {
    height: 36px;
    padding: 0 20px;
  }
}

.link {
  background: #F6F7F9;
  border-radius: 2px;
  padding: 20px;
}

.change {
  color: #2D8CF0;
  cursor: pointer;
}

// 隐藏滚动条
.ticket-content::-webkit-scrollbar {
  display: none;
}

.ticket-preview {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.out-line {
  width: 271px;
  height: 7px;
  background: #EEEEEE;
  border-radius: 4px;
}

// 动画高度从0变为100%
@keyframes show {
  0% {
    margin-top: -70vh;
  }

  100% {
    margin-top: 0;
  }
}

.ticket-preview {
  overflow: hidden;
  height: 70vh;
}

.ticket-content {
  position: relative;
  top: -3px;
  animation: show 2s ease-in-out forwards;
  width: 260px;
  max-height: 70vh;
  overflow-y: scroll;
  overflow-x: hidden;
  background-color: #fff;
  padding: 20px 15px 15px 15px;
  box-shadow: 0px 4px 10px 0px rgba(0, 0, 0, 0.1);
  border-radius: 1px 1px 1px 1px;
  font-size: 12px;
  font-weight: 400;
  color: #333;
  line-height: 18px;

  // 下划线虚线
  .btn-line {
    border-bottom: 1px dashed #eee;
  }

  .star-line {
    font-family: 'Microsoft YaHei';
    font-size: 12px;
    display: flex;
    justify-content: center;
    overflow: hidden;
    white-space: nowrap;
  }

  .code {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;

    #qrcode {
      // margin: 25px 0 0px;
    }
  }
}

.bottom-notice {
  width: 260px;
  margin-left: 1px;
  height: 13px;
  position: relative;
}

.image {
  width: 100%;
  height: 100%;
  position: absolute;
  top: -6px;
}
</style>
