<template>
  <Drawer :width="list.length > 1 ? 1200 : 742" v-model="modals">
    <div slot="header">
      <div class="acea-row row-middle">
        <div>预约单</div>
        <div
          class="acea-row row-center-wrapper w-36 h-21 border-1-FF7700 rd-4 ml-6 fs-12 text-wlll-FF7700"
        >
          {{ reservationTypeText }}
        </div>
      </div>
    </div>
    <div class="acea-row h-full">
      <div class="left w-458 bg-w111-F5F5F5 px-25" v-if="list.length > 1">
        <div
          class="acea-row row-middle h-52 border-b-1-EAEAEA fs-14 text-wlll-606266 pl-26"
        >
          <div class="w-100">预约人</div>
          <div class="w-134">时段</div>
          <div class="w-78">模式</div>
          <div class="w-70">状态</div>
        </div>
        <div
          v-for="(item, index) in list"
          :key="index"
          @click="reservationTap(item.id)"
          :class="id == item.id ? 'on' : ''"
          class="acea-row row-middle pointer h-62 border-b-1-EAEAEA fs-14 text-wlll-303133 pl-26"
        >
          <div class="w-100">{{ item.reservation_name || '预约用户' }}</div>
          <div class="w-134">
            {{ item.reservation_start }}-{{ item.reservation_end }}
          </div>
          <div class="w-78">
            {{ item.reservation_type == 3 ? '上门' : '到店' }}
          </div>
          <div class="w-70" v-if="item.status == -1">已取消</div>
          <div class="w-70" v-else-if="item.status == 0">待服务</div>
          <div class="w-70" v-else-if="item.status == 3">待确认</div>
          <div class="w-70" v-else-if="item.status == 1">进行中</div>
          <div class="w-70" v-else-if="item.status == 2">已完成</div>
        </div>
      </div>
      <div class="flex-1 acea-row row-column">
        <div class="flex-1 conter pt-4 pr-20 pl-20">
          <div class="acea-row row-middle mt-20 fs-14" v-if="info.cart_info">
            <div class="text-wlll-606266 w-100 text-right">预约服务：</div>
            <Input
              :value="info.cart_info.productInfo.store_name"
              disabled
              class="flex-1"
            />
          </div>
          <div class="acea-row row-middle mt-20 fs-14" v-if="info.cart_info">
            <div class="text-wlll-606266 w-100 text-right">服务规格：</div>
            <Input
              :value="info.cart_info.productInfo.attrInfo.suk"
              disabled
              class="flex-1"
            />
          </div>
          <div class="acea-row mt-20 fs-14" v-if="addonProjectList.length">
            <div class="text-wlll-606266 w-100 text-right pt-5">增项服务：</div>
            <div class="flex-1 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 py-12 text-wlll-303133">
              <div v-for="(project, pIndex) in addonProjectList" :key="pIndex" :class="{ 'mt-12': pIndex > 0 }">
                <div class="acea-row row-between-wrapper">
                  <div class="line1 flex-1 pr-12">{{ project.product_name }}</div>
                  <div class="text-wlll-999">×{{ project.cart_num || 1 }}</div>
                </div>
                <div class="text-wlll-999 fs-12 mt-4" v-if="project.desc">{{ project.desc }}</div>
              </div>
            </div>
          </div>
          <div class="acea-row row-middle mt-20 fs-14" v-if="info.service_room || info.table_name">
            <div class="text-wlll-606266 w-100 text-right">服务房间：</div>
            <Input :value="info.service_room || info.table_name" disabled class="flex-1" />
          </div>
          <div class="acea-row row-middle mt-20 fs-14">
            <div class="text-wlll-606266 w-100 text-right">联系人：</div>
            <Input
              v-model="formValidate.reservation_name"
              placeholder="请输入联系人"
              :disabled="disabled"
              class="flex-1"
            />
          </div>
          <div class="acea-row row-middle mt-20 fs-14">
            <div class="text-wlll-606266 w-100 text-right">联系人电话：</div>
            <Input
              v-model="formValidate.reservation_phone"
              type="number"
              placeholder="请输入联系人电话"
              :disabled="disabled"
              class="flex-1"
            />
          </div>
          <div class="acea-row row-middle mt-20 fs-14">
            <div class="text-wlll-606266 w-100 text-right">预约日期：</div>
            <DatePicker
              v-model="formValidate.reservation_time"
              type="date"
              placeholder="请选择预约日期"
              :disabled="disabled"
              class="flex-1"
              transfer
            ></DatePicker>
          </div>
          <div class="acea-row row-middle mt-20 fs-14">
            <div class="text-wlll-606266 w-100 text-right">预约时间：</div>
            <Select
              v-model="formValidate.reservation_time_id"
              placeholder="请选择预约时间"
              :disabled="disabled"
              class="flex-1"
              transfer
              clearable
            >
              <Option
                :value="item.id"
                :key="item.id"
                v-for="item in reservationTime"
                >{{ item.show_time }}</Option
              >
            </Select>
          </div>
          <div
            class="acea-row row-middle mt-20 fs-14"
            v-if="info.reservation_type == 3"
          >
            <div class="text-wlll-606266 w-100 text-right">上门地址：</div>
            <Cascader
              v-model="formValidate.reservation_address_city_id"
              placeholder="请选择上门地址"
              :data="addresData"
              :load-data="loadData"
              :disabled="disabled"
              class="flex-1"
              transfer
              clearable
              @on-change="addchack"
            ></Cascader>
          </div>
          <div
            class="acea-row row-middle mt-20 fs-14"
            v-if="info.reservation_type == 3"
          >
            <div class="text-wlll-606266 w-100 text-right">详细地址：</div>
            <Input
              v-model="reservationAddress"
              placeholder="请输入详细地址"
              :disabled="disabled"
              class="flex-1"
            />
          </div>
          <div class="acea-row row-middle mt-20 fs-14">
            <div class="text-wlll-606266 w-100 text-right">服务人员：</div>
            <Select
              v-model="formValidate.service_staff_id"
              placeholder="请选择服务人员"
              :disabled="info.status == 1 || info.status == 2"
              class="flex-1"
              transfer
              clearable
            >
              <Option
                :value="item.value"
                :key="item.value"
                v-for="item in staffList"
                >{{ item.label }}</Option
              >
            </Select>
          </div>
          <div
            class="acea-row mt-20 fs-14"
            v-if="info.reservation_info && info.reservation_info.length"
          >
            <div class="text-wlll-606266 w-100 text-right lh-36">
              {{info.custom_form_title}}信息：
            </div>
            <div
              class="flex-1 border-1-DDDDDD bg-w111-F9F9F9 rd-4 pl-7 text-wlll-303133 pt-24"
            >
              <div class="acea-row">
                <div
                  class="acea-row mr-68 mb-30 pointer"
                  v-for="item in info.reservation_info"
                  :key="item.timestamp"
                >
                  <div>{{ item.titleConfig.value }}：</div>
                  <div v-if="item.name == 'dateranges'">
                    {{ item.value[0] + '/' + item.value[1] }}
                  </div>
                  <div
                    v-else-if="item.name == 'uploadPicture'"
                    v-viewer
                    class="acea-row"
                  >
                    <div
                      class="w-58 h-58 mr-8"
                      v-for="(imgSrc, i) in item.value"
                      :key="i"
                    >
                      <img :src="imgSrc" alt="" class="w-full h-full" />
                    </div>
                  </div>
                  <div v-else>{{ item.value }}</div>
                </div>
              </div>
            </div>
          </div>
          <div
            class="acea-row row-middle mt-20 fs-14"
            v-if="info.status == 1 || info.status == 2"
          >
            <div class="text-wlll-606266 w-100 text-right">开始服务时间：</div>
            <Input :value="info.service_time" disabled class="flex-1" />
          </div>
          <div class="acea-row row-middle mt-20 fs-14" v-if="info.status == 2">
            <div class="text-wlll-606266 w-100 text-right">结束服务时间：</div>
            <Input :value="info.service_end_time" disabled class="flex-1" />
          </div>
		  <div class="acea-row mt-20 fs-14 mb-30" v-if="info.service_describe || info.service_images.length">
		  	<div class="text-wlll-606266 w-100 text-right">服务凭证：</div>
		  	<div class="flex-1 border-1-DDDDDD bg-w111-F9F9F9 rd-4 pl-7 text-wlll-303133 pt-24 pb-24">
		  		<div>{{info.service_describe}}</div>
		  		<div class="acea-row mt-24">
		  			<div>图片：</div>
		  			<div class="acea-row flex-1" v-viewer>
		  				<div class="w-58 h-58 mr-8 mb5" v-for="(img, i) in info.service_images" :key="i">
		  					<img class="w-full h-full rd-4" :src="img"/>
		  				</div>
		  			</div>
		  		</div>
		  	</div>
		  </div>
        </div>
        <div class="footer acea-row row-center-wrapper" v-if="info.status != 2">
          <template v-if="disabled">
            <div
              @click="cancelTap"
              class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 pointer"
              v-if="info.status == 0 || info.status == 3"
            >
              取消预约
            </div>
            <div
              @click="disabled = false"
              class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 pointer ml-20"
              v-if="info.status == 0 || info.status == 3"
            >
              修改预约
            </div>
            <div
              @click="confirmTap"
              class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-FF7700 text-wlll-FFFFFF pointer ml-20"
              v-if="info.status == 3"
            >
              接单
            </div>
            <div
              @click="refuseTap"
              class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 pointer ml-20"
              v-if="info.status == 3"
            >
              拒绝
            </div>
            <div
              @click="serviceStart"
              class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF pointer ml-20"
              v-if="info.status == 0"
            >
              开始服务
            </div>
            <div
              @click="writeTap"
              class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF pointer ml-20"
              v-if="info.status == 1"
            >
              立即消耗
            </div>
          </template>
          <template v-else>
            <div
              @click="disabled = true"
              class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 pointer"
            >
              取消
            </div>
            <div
              @click="editTap"
              class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF pointer ml-20"
            >
              确认
            </div>
          </template>
        </div>
      </div>
    </div>
  </Drawer>
</template>

<script>
// 已完成 2，待服务 0，已取消 （0，1，2）外，服务中 1
import {
  getOrderDetail,
  getReservationStaffList,
  formatReservationStaffOptions,
  postOrderService,
  postOrderUpdate,
  getReservationTime,
  cityApi,
  postOrderConfirm,
  postOrderRefuse,
  getReservationTableList,
} from '@/api/reservation';
export default {
  name: 'reservation',
  data() {
    return {
      disabled: true,
      modals: false,
      list: [],
      id: 0, //预约单列表id；
      info: {}, //预约单详情；
      staffList: [],
      formValidate: {
        reservation_name: '',
        reservation_phone: '',
        reservation_time: '',
        reservation_time_id: 0,
        service_staff_id: 0,
        reservation_address_city_id: 0,
      },
      reservationTime: [],
      addresData: [],
      reservationAddress: '', //上门详细地址
      regionAddress: '', //上门地址
      refuseReason: '',
      tableList: [],
    };
  },
  computed: {
    reservationTypeText() {
      if (this.info.reservation_type == 2) {
        return '到店';
      } else if (this.info.reservation_type == 3) {
        return '上门';
      }
    },
    projectList() {
      if (Array.isArray(this.info.project_list) && this.info.project_list.length) {
        return this.info.project_list;
      }
      const cartInfo = this.info.cart_info || {};
      const productInfo = cartInfo.productInfo || {};
      if (!productInfo.store_name) return [];
      return [{
        product_name: productInfo.store_name,
        desc: (productInfo.attrInfo && productInfo.attrInfo.suk) || productInfo.store_info || '',
        cart_num: cartInfo.cart_num || 1,
      }];
    },
    addonProjectList() {
      return this.projectList.length > 1 ? this.projectList.slice(1) : [];
    },
  },
  watch: {
    info() {
      this.formValidate = {
        reservation_name: this.info.reservation_name || '预约用户',
        reservation_phone: this.info.reservation_phone,
        reservation_time: this.info.reservation_time,
        reservation_time_id: this.info.reservation_time_id,
        service_staff_id: this.info.service_staff_id,
        reservation_address_city_id: this.info.reservation_address_city_id,
      };
      this.disabled = true;
    },
  },
  mounted() {},
  methods: {
    reservationOrder(list) {
      this.list = list;
      this.reservationTap(this.list[0].id);
    },
    // 立即消耗
    writeTap() {
      this.$emit('writeTap', this.info);
    },
    writeCallback() {
      if (this.list.length > 1) {
        this.orderDetail(this.id);
      } else {
        this.modals = false;
      }
    },
    allStaffList() {
      const serviceDate = this.info.reservation_time
        ? String(this.info.reservation_time).split(' ')[0]
        : '';
      getReservationStaffList({
        store_id: this.info.store_id,
        service_date: serviceDate,
      })
        .then((res) => {
          this.staffList = formatReservationStaffOptions(res.data.list || []);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 开始服务
    serviceStart() {
      if (!this.formValidate.service_staff_id) {
        return this.$Message.error('请选择服务人员');
      }
      postOrderService(this.id, {
        status: 1,
        service_staff_id: this.formValidate.service_staff_id,
      })
        .then((res) => {
          this.$Message.success(res.msg);
          this.$emit('submitSuccess');
          if (this.list.length > 1) {
            if (
              this.formValidate.service_staff_id == this.info.service_staff_id
            ) {
              this.orderDetail(this.id);
            } else {
              this.list = this.list.filter((item) => item.id != this.id);
              if (this.list.length) {
                this.reservationTap(this.list[0].id);
              } else {
                this.modals = false;
              }
            }
          } else {
            this.modals = false;
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 取消预约
    cancelTap() {
      this.$emit('cancelTap', this.info);
    },
    async confirmTap() {
      await this.loadTableList();
      let confirmTableId = null;
      this.$Modal.confirm({
        title: '接单确认',
        render: (h) => {
          return h('div', [
            h('div', { style: { marginBottom: '10px', color: '#606266' } }, '请选择服务房间（可不选）'),
            h(
              'Select',
              {
                props: {
                  value: confirmTableId,
                  clearable: true,
                  transfer: true,
                  placeholder: '选择房间号',
                },
                style: { width: '100%' },
                on: {
                  'on-change': (val) => {
                    confirmTableId = val;
                  },
                },
              },
              this.tableList.map((item) =>
                h(
                  'Option',
                  {
                    props: {
                      value: item.id,
                      key: item.id,
                    },
                  },
                  item.remarks || item.table_number || `房间${item.id}`
                )
              )
            ),
          ]);
        },
        onOk: () => {
          const room = this.tableList.find((item) => Number(item.id) === Number(confirmTableId));
          return postOrderConfirm(this.info.id, {
            table_id: confirmTableId || 0,
            table_name: room ? room.remarks || String(room.table_number || '') : '',
          })
            .then((res) => {
              this.$Message.success(res.msg);
              this.orderDetail(this.info.id);
              this.$emit('submitSuccess');
            })
            .catch((err) => {
              this.$Message.error(err.msg);
              return Promise.reject();
            });
        },
      });
    },
    loadTableList() {
      return getReservationTableList()
        .then((res) => {
          this.tableList = res.data || [];
        })
        .catch(() => {
          this.tableList = [];
        });
    },
    refuseTap() {
      this.$Modal.confirm({
        title: '拒绝预约',
        render: (h) => {
          return h('Input', {
            props: {
              type: 'textarea',
              rows: 3,
              placeholder: '请填写拒绝原因',
              value: this.refuseReason,
            },
            on: {
              input: (val) => {
                this.refuseReason = val;
              },
            },
          });
        },
        onOk: () => {
          if (!this.refuseReason || !this.refuseReason.trim()) {
            this.$Message.error('请填写拒绝原因');
            return Promise.reject();
          }
          return postOrderRefuse(this.info.id, { refuse_reason: this.refuseReason.trim() })
            .then((res) => {
              this.$Message.success(res.msg);
              this.refuseReason = '';
              this.orderDetail(this.info.id);
              this.$emit('submitSuccess');
            })
            .catch((err) => {
              this.$Message.error(err.msg);
              return Promise.reject();
            });
        },
        onCancel: () => {
          this.refuseReason = '';
        },
      });
    },
    cancelCallback() {
      if (this.list.length > 1) {
        this.list = this.list.filter((item) => item.id != this.id);
        this.reservationTap(this.list[0].id);
      } else {
        this.modals = false;
      }
    },
    // 切换预约单
    reservationTap(id) {
      this.id = id;
      this.orderDetail(this.id);
      this.reservationTimeTap(this.id);
      this.cityInfo({ pid: 0 });
      this.allStaffList();
      this.loadTableList();
    },
    orderDetail(id) {
      getOrderDetail(id)
        .then((res) => {
          this.info = res.data;
          let address = res.data.reservation_address.split(' ');
          this.reservationAddress = address[address.length - 1];
          address.pop();
          this.regionAddress = address.join('/');
          for (let i = 0; i < this.list.length; i++) {
            if (this.list[i].id == id) {
              this.list[i].reservation_name = this.info.reservation_name;
              this.list[i].reservation_start = this.info.reservation_start;
              this.list[i].reservation_end = this.info.reservation_end;
              this.list[i].status = this.info.status;
              break;
            }
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 修改预约
    editTap() {
      if (!this.formValidate.reservation_phone) {
        return this.$Message.error('请输入联系人电话');
      }
      if (!this.formValidate.reservation_time) {
        return this.$Message.error('请选择预约日期');
      }
      if (!this.formValidate.reservation_time_id) {
        return this.$Message.error('请选择预约时间');
      }
      if (this.info.reservation_type == 3) {
        if (!this.formValidate.reservation_address_city_id.length) {
          return this.$Message.error('请选择上门地址');
        }
        if (!this.reservationAddress) {
          return this.$Message.error('请输入详细地址');
        }
      }
      let address = this.regionAddress + '/' + this.reservationAddress;
      let data = {
        ...this.formValidate,
        reservation_address: address,
      };
      postOrderUpdate(this.id, data)
        .then((res) => {
          this.$Message.success(res.msg);
          this.$emit('submitSuccess');
          this.disabled = true;
          if (this.list.length > 1) {
            if (
              this.formValidate.service_staff_id == this.info.service_staff_id
            ) {
              this.orderDetail(this.id);
            } else {
              this.list = this.list.filter((item) => item.id != this.id);
              if (this.list.length) {
                this.reservationTap(this.list[0].id);
              } else {
                this.modals = false;
              }
            }
          } else {
            this.modals = false;
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    reservationTimeTap(id) {
      getReservationTime(id)
        .then((res) => {
          this.reservationTime = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    cityInfo(data) {
      cityApi(data).then((res) => {
        this.addresData = res.data;
      });
    },
    loadData(item, callback) {
      item.loading = true;
      cityApi({ pid: item.value }).then((res) => {
        item.children = res.data;
        item.loading = false;
        callback();
      });
    },
    addchack(e, selectedData) {
      this.info.reservation_address_city_id = e;
      this.regionAddress = selectedData.map((o) => o.label).join('/');
    },
  },
};
</script>

<style scoped lang="less">
/deep/.ivu-input {
  height: 36px !important;
  border-radius: 4px !important;
}
/deep/.ivu-select-single .ivu-select-selection {
  height: 36px !important;
  border-radius: 4px !important;
}
/deep/.ivu-select-single .ivu-select-selection .ivu-select-placeholder,
/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value {
  height: 36px !important;
  line-height: 36px !important;
}
/deep/.ivu-input[disabled],
/deep/fieldset[disabled] .ivu-input {
  background-color: #f9f9f9 !important;
  cursor: auto;
  color: #303133;
}
/deep/.ivu-select-disabled .ivu-select-selection {
  background-color: #f9f9f9 !important;
  cursor: auto;
  color: #303133;
}
/deep/.ivu-drawer-body {
  padding: 0;
}

.footer {
  box-shadow: 0px -1px 11px 0px rgba(0, 0, 0, 0.06);
  height: 90px;
}

.conter {
  overflow: auto;
}

.left {
  overflow: auto;
}

.pointer:has(+ .on) {
  border: 0 !important;
}

.on {
  background-color: #fff;
  border-radius: 8px;
  position: relative;
  border: 0 !important;

  &::before {
    position: absolute;
    left: 0;
    width: 5px;
    height: 62px;
    background-color: #1890ff;
    border-radius: 8px 0 0 8px;
    content: ' ';
  }
}
</style>
