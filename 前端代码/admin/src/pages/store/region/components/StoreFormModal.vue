<template>
  <div>
    <Modal
      :value="value"
      :title="editId > 0 ? '编辑门店' : '添加门店'"
      width="1274"
      :mask-closable="false"
      :styles="{ top: '40px' }"
      class-name="store-form-modal"
      @on-cancel="handleClose"
    >
      <Form
        v-if="value"
        ref="formItem"
        class="store-form-modal-body"
        :model="formItem"
        :rules="ruleValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
        @submit.native.prevent
      >
        <Spin size="large" fix v-if="spinShow"></Spin>
        <Row :gutter="24">
          <Col span="12">
            <FormItem label="门店照片：" prop="image">
              <div class="picBox" @click="modalPicTap('image')">
                <div class="pictrue" v-if="formItem.image"><img v-lazy="formItem.image" /></div>
                <div class="upLoad" v-else><div class="iconfont">+</div></div>
              </div>
              <div class="tips">建议尺寸：70 * 70px</div>
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="门头照片：" prop="background_image">
              <div class="picBox" @click="modalPicTap('background_image')">
                <div class="pictrue" v-if="formItem.background_image"><img v-lazy="formItem.background_image" /></div>
                <div class="upLoad" v-else><div class="iconfont">+</div></div>
              </div>
              <div class="tips">建议尺寸：375 * 192px</div>
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="门店名称：" prop="name">
              <Input v-model="formItem.name" maxlength="20" show-word-limit placeholder="请输入门店名称" />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="床位数：" prop="space_num">
              <Input v-model="formItem.space_num" placeholder="请输入床位数" />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="门店简介：">
              <Input
                v-model="formItem.introduction"
                type="textarea"
                :rows="3"
                maxlength="100"
                show-word-limit
                placeholder="请输入门店简介"
              />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="所属组织：" prop="manage_region_id">
              <OrganizationStoreScopePicker
                v-model="organizationPickerStoreIds"
                :load-scope="loadOrganizationPickerScope"
                :selected-organization-id="formItem.manage_region_id"
                empty-label="请选择所属组织"
                selection-mode="org_only"
                @change="onOrganizationScopePick"
              />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="营业状态：" prop="is_show">
              <i-switch v-model="formItem.is_show" :true-value="1" :false-value="0" size="large">
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="营业时间：" prop="day_time">
              <TimePicker
                type="timerange"
                v-model="formItem.day_time"
                format="HH:mm:ss"
                placement="bottom-end"
                placeholder="请选择营业时间"
                style="width: 100%"
                @on-change="onchangeTime"
              />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="门店类型：" prop="type">
              <RadioGroup v-model="formItem.type">
                <Radio :label="1">自营</Radio>
                <Radio :label="2">加盟</Radio>
              </RadioGroup>
            </FormItem>
          </Col>
          <Col span="12" v-if="!editId">
            <FormItem label="管理员账号：" prop="store_account">
              <Input v-model="formItem.store_account" placeholder="请输入管理员账号" />
            </FormItem>
          </Col>
          <Col span="12" v-if="!editId">
            <FormItem label="管理员密码：" prop="store_password">
              <Input type="password" password v-model="formItem.store_password" placeholder="请输入管理员密码" />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="门店手机号：" prop="phone">
              <Input v-model="formItem.phone" placeholder="请输入门店手机号" />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="门店地址：" prop="address">
              <Cascader
                :data="addresData"
                :load-data="loadData"
                v-model="formItem.addressSelect"
                transfer
                style="width: 100%"
                @on-change="addchack"
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="详细地址：" prop="detailed_address">
              <div class="store-address-query">
                <div v-if="storeAddress" class="input-shell address-prefix">
                  <input :value="storeAddress" type="text" disabled tabindex="-1" />
                </div>
                <div class="input-shell address-detail">
                  <svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-4-4" />
                  </svg>
                  <input
                    v-model.trim="formItem.detailed_address"
                    type="search"
                    maxlength="100"
                    placeholder="输入详细地址"
                    @keyup.enter="onSearch"
                  />
                </div>
                <button class="shell-btn" type="button" @click="onSearch">
                  查找位置 <span class="enter-key">↵</span>
                </button>
              </div>
            </FormItem>
          </Col>
          <Col span="24" v-if="isApi && mapKey">
            <Maps
              ref="mapChild"
              class="map-sty"
              :mapKey="mapKey"
              :lat="Number(formItem.latitude || 34.34127)"
              :lon="Number(formItem.longitude || 108.93984)"
              :address="storeAddress + formItem.detailed_address"
              @getCoordinates="getCoordinates"
            />
          </Col>
        </Row>
      </Form>
      <div slot="footer">
        <Button @click="handleClose">取消</Button>
        <Button type="primary" :loading="saving" @click="handleSubmit">保存</Button>
      </div>
    </Modal>
    <Modal
      v-model="modalPic"
      width="960"
      scrollable
      footer-hide
      closable
      title="上传图片"
      :mask-closable="false"
      :z-index="1100"
    >
      <uploadPictures
        v-if="modalPic"
        isChoice="单选"
        :gridBtn="gridBtn"
        :gridPic="gridPic"
        @getPic="getPic"
      />
    </Modal>
  </div>
</template>

<script>
import { mapState } from "vuex";
import uploadPictures from "@/components/uploadPictures";
import Maps from "@/components/map/map.vue";
import OrganizationStoreScopePicker from "@/components/organization/OrganizationStoreScopePicker.vue";
import {
  keyApi,
  storeGetInfoApi,
  cityApi,
  storeUpdateApi,
  getOrganizationResourceSelector,
} from "@/api/store";

export default {
  name: "StoreFormModal",
  components: { uploadPictures, Maps, OrganizationStoreScopePicker },
  props: {
    value: { type: Boolean, default: false },
    editId: { type: [Number, String], default: 0 },
    defaultRegionId: { type: [Number, String], default: 0 },
  },
  data() {
    const validatePhone = (rule, value, callback) => {
      if (!value) return callback(new Error("请填写手机号"));
      if (!/^1[3456789]\d{9}$/.test(value) && !/^0\d{2,3}-?\d{7,8}$/.test(value)) {
        return callback(new Error("手机号格式不正确"));
      }
      callback();
    };
    const validateUpload = (rule, value, callback) => {
      if (!this.formItem.image) callback(new Error("请上传门店照片"));
      else callback();
    };
    const validateOrg = (rule, value, callback) => {
      if (!Number(value)) return callback(new Error("请选择所属组织"));
      callback();
    };
    return {
      spinShow: false,
      saving: false,
      modalPic: false,
      picTit: "",
      isApi: 0,
      mapKey: "",
      storeAddress: "",
      addresData: [],
      organizationPickerStoreIds: [],
      formItem: this.emptyForm(),
      ruleValidate: {
        image: [{ required: true, validator: validateUpload, trigger: "change" }],
        name: [{ required: true, message: "请输入门店名称", trigger: "blur" }],
        phone: [{ required: true, validator: validatePhone, trigger: "blur" }],
        manage_region_id: [{ required: true, validator: validateOrg, trigger: "change" }],
        store_account: [
          {
            validator: (rule, value, callback) => {
              if (this.editId) return callback();
              if (!value) return callback(new Error("请输入管理员账号"));
              callback();
            },
            trigger: "blur",
          },
        ],
        store_password: [
          {
            validator: (rule, value, callback) => {
              if (this.editId) return callback();
              if (!value) return callback(new Error("请输入管理员密码"));
              callback();
            },
            trigger: "blur",
          },
        ],
      },
      gridPic: { xl: 6, lg: 8, md: 12, sm: 12, xs: 12 },
      gridBtn: { xl: 4, lg: 8, md: 8, sm: 8, xs: 8 },
    };
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 110;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
  },
  watch: {
    value(val) {
      if (val) this.open();
    },
  },
  methods: {
    emptyForm() {
      return {
        id: 0,
        image: "",
        background_image: "",
        name: "",
        space_num: "",
        introduction: "",
        manage_region_id: 0,
        is_show: 1,
        day_time: [],
        type: 1,
        store_account: "",
        store_password: "",
        phone: "",
        address: "",
        detailed_address: "",
        addressSelect: [],
        latitude: "",
        longitude: "",
        province: 0,
        city: 0,
        area: 0,
        street: 0,
        valid_range: 0,
        product_status: 1,
        product_verify_status: 0,
        delivery_type: ["1", "2"],
        default_delivery: 1,
      };
    },
    open() {
      this.formItem = this.emptyForm();
      this.organizationPickerStoreIds = [];
      this.storeAddress = "";
      this.isApi = 0;
      this.cityInfo({ pid: 0 });
      this.getKey();
      if (this.editId) {
        this.loadInfo(this.editId);
      } else if (this.defaultRegionId) {
        this.formItem.manage_region_id = Number(this.defaultRegionId) || 0;
      }
    },
    loadInfo(id) {
      this.spinShow = true;
      storeGetInfoApi(id)
        .then((res) => {
          this.isApi = 1;
          const info = res.data.info || {};
          const addressSelect = [];
          if (info.province) addressSelect.push(info.province);
          if (info.city) addressSelect.push(info.city);
          if (info.area) addressSelect.push(info.area);
          if (info.street) addressSelect.push(info.street);
          this.formItem = {
            ...this.emptyForm(),
            ...info,
            addressSelect,
            day_time: info.timeVal || info.day_time || [],
            manage_region_id: Number(info.manage_region_id || 0),
            type: Number(info.type || 1),
            is_show: Number(info.is_show != null ? info.is_show : 1),
          };
          this.storeAddress = info.address || "";
          this.$nextTick(() => this.onSearch());
        })
        .catch((err) => this.$Message.error(err.msg || "加载门店失败"))
        .finally(() => {
          this.spinShow = false;
        });
    },
    loadOrganizationPickerScope() {
      return getOrganizationResourceSelector({
        resource: "org_store_tree",
        page: 1,
        limit: 50,
      });
    },
    onOrganizationScopePick(scope = {}) {
      this.formItem.manage_region_id = Number(scope.orgId || 0);
      if (this.$refs.formItem) {
        this.$refs.formItem.validateField("manage_region_id");
      }
    },
    handleClose() {
      this.$emit("input", false);
    },
    modalPicTap(picTit) {
      this.picTit = picTit;
      this.modalPic = true;
    },
    getPic(pc) {
      this.formItem[this.picTit] = pc.att_dir;
      this.modalPic = false;
      if (this.$refs.formItem) this.$refs.formItem.validateField(this.picTit);
    },
    onchangeTime(e) {
      this.formItem.day_time = e;
    },
    cityInfo(data) {
      cityApi(data).then((res) => {
        this.addresData = res.data || [];
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
      (e || []).forEach((i, index) => {
        if (index === 0) this.formItem.province = i;
        else if (index === 1) this.formItem.city = i;
        else if (index === 2) this.formItem.area = i;
        else this.formItem.street = i;
      });
      this.formItem.address = (selectedData || []).map((o) => o.label).join("/");
      this.storeAddress = (selectedData || []).map((o) => o.label).join("");
    },
    getCoordinates(data) {
      this.formItem.latitude = (data.location && data.location.lat) || 34.34127;
      this.formItem.longitude = (data.location && data.location.lng) || 108.93984;
    },
    onSearch() {
      if (this.$refs.mapChild) {
        this.$refs.mapChild.searchKeyword(this.storeAddress + this.formItem.detailed_address);
      }
    },
    getKey() {
      keyApi()
        .then((res) => {
          this.mapKey = res.data.key;
        })
        .catch(() => {});
    },
    handleSubmit() {
      this.$refs.formItem.validate((valid) => {
        if (!valid) return;
        if (!this.formItem.day_time || !this.formItem.day_time[0]) {
          this.formItem.day_time = ["00:00:00", "23:59:59"];
        }
        this.formItem.manage_region_id = Number(this.formItem.manage_region_id || 0);
        if (!this.formItem.manage_region_id) {
          if (this.$Message && this.$Message.required) this.$Message.required("所属组织未选择");
          else this.$Message.error("请选择所属组织");
          return;
        }
        this.saving = true;
        storeUpdateApi(this.editId || 0, this.formItem)
          .then((res) => {
            this.$Message.success(res.msg || "保存成功");
            this.$emit("input", false);
            this.$emit("success");
          })
          .catch((err) => this.$Message.error(err.msg || "保存失败"))
          .finally(() => {
            this.saving = false;
          });
      });
    },
  },
};
</script>

<style scoped lang="stylus">
.tips
  font-size 12px
  color #999
  line-height 1.5
  margin-top 6px
.picBox
  display inline-block
  cursor pointer
  .upLoad
    width 58px
    height 58px
    line-height 58px
    text-align center
    border 1px dotted rgba(0, 0, 0, 0.1)
    border-radius 4px
    background rgba(0, 0, 0, 0.02)
  .pictrue
    width 60px
    height 60px
    border 1px dotted rgba(0, 0, 0, 0.1)
    img
      width 100%
      height 100%
      object-fit cover
.map-sty
  width 100%
  height 320px
.store-address-query
  display flex
  align-items center
  flex-wrap wrap
  gap 10px
  width 100%
.input-shell
  display flex
  align-items center
  gap 8px
  min-height 38px
  padding 0 11px
  border 1px solid #e7eaf0
  border-radius 9px
  background #fff
  transition .18s
  .icon
    flex none
    width 16px
    height 16px
    color #929bad
    fill none
    stroke currentColor
    stroke-width 1.8
    stroke-linecap round
    stroke-linejoin round
  input
    min-width 0
    width 100%
    border 0
    outline 0
    color #172033
    background transparent
    font inherit
    &::placeholder
      color #a4abba
    &:disabled
      color #5e687b
      cursor default
  &:focus-within
    border-color #b5b5ee
    box-shadow 0 0 0 3px rgba(91, 91, 214, .09)
.address-prefix
  width 240px
  max-width 100%
  background #f8fafc
.address-detail
  flex 1
  min-width 220px
.shell-btn
  display inline-flex
  align-items center
  justify-content center
  gap 4px
  min-height 38px
  padding 0 15px
  border 1px solid #d9dee8
  border-radius 9px
  color #172033
  background #fff
  font inherit
  font-weight 570
  white-space nowrap
  cursor pointer
  transition .18s
  &:hover
    color #5b5bd6
    border-color #bdbdf2
    background #fafaff
.enter-key
  margin-left 2px
  font-weight 600
</style>

<style lang="stylus">
/* Modal 挂到 body，需非 scoped 才能作用到弹层内 iView 控件 */
.store-form-modal
  .ivu-modal-body
    padding 20px 24px 8px
  .store-form-modal-body
    .ivu-input
      min-height 38px
      border-radius 9px
      border-color #e7eaf0
      color #172033
      &:hover, &:focus
        border-color #b5b5ee
      &:focus
        box-shadow 0 0 0 3px rgba(91, 91, 214, .09)
    .ivu-input-wrapper
      .ivu-input
        border-radius 9px
    .ivu-cascader
      .ivu-input
        min-height 38px
        border-radius 9px
        border-color #e7eaf0
    textarea.ivu-input
      min-height 78px
      padding-top 9px
      border-radius 9px
.ivu-cascader-transfer, .ivu-select-dropdown
  .ivu-cascader-menu-item, .ivu-cascader-menu
    font-family Inter, "PingFang SC", "Microsoft YaHei", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif
  .ivu-input, .ivu-cascader .ivu-input
    border-radius 9px
    border-color #e7eaf0
    &:focus
      border-color #b5b5ee
      box-shadow 0 0 0 3px rgba(91, 91, 214, .09)
</style>
