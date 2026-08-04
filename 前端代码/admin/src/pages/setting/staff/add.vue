<template>
  <div>
    <Modal
      :value="value"
      :title="modalTitle"
      :width="modalWidth"
      class-name="staff-person-modal"
      :mask-closable="false"
      :styles="modalStyles"
      @on-cancel="handleClose"
    >
      <Form
        v-if="value"
        ref="formInline"
        class="staff-person-form"
        :class="{ 'is-narrow': isNarrowForm }"
        :model="formInline"
        :rules="ruleValidate"
        :label-width="formLabelWidth"
        :label-position="labelPosition"
        @submit.native.prevent
      >
        <Tabs v-model="activeTab">
          <TabPane label="基本信息" name="basic">
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="所属组织：" prop="org_id">
                  <OrganizationResourceSelector
                    v-model="formInline.org_id"
                    resource="organization"
                    picker-mode="modal"
                    :tree-mode="true"
                    selection-mode="org_only"
                    modal-title="选择所属组织"
                    trigger-placeholder="请选择所属组织"
                    placeholder="搜索组织名称"
                    :multiple="false"
                    :disabled-ids="[]"
                    :clearable="false"
                    @change="onOrgPick"
                  />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="当前任职门店：">
                  <Input
                    v-if="storeLocked"
                    :value="storeLockedLabel"
                    readonly
                  />
                  <OrganizationResourceSelector
                    v-else
                    v-model="formInline.store_id"
                    resource="store"
                    picker-mode="modal"
                    :tree-mode="true"
                    selection-mode="store_only"
                    modal-title="选择当前任职门店"
                    trigger-placeholder="可不选（无店直属）"
                    placeholder="搜索门店名称"
                    :multiple="false"
                    :disabled-ids="[]"
                    :clearable="true"
                    @change="onStorePick"
                  />
                  <div class="form-tip">
                    {{ storeLocked ? '已有任职门店时，调店请走调店流程。' : '可不选。选择门店后，所属组织会自动更新为该门店所在组织。' }}
                  </div>
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="员工姓名：" prop="staff_name">
                  <Input v-model="formInline.staff_name" placeholder="请输入员工姓名" />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="手机号码：" prop="phone">
                  <Input v-model="formInline.phone" placeholder="请输入手机号码" />
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="员工头像：" prop="avatar">
                  <div class="avatar-row">
                    <div class="avatar-preview" v-if="formInline.avatar">
                      <img :src="resolveStaffAvatar(formInline.avatar)" alt="avatar" />
                    </div>
                    <div class="avatar-options">
                      <div
                        class="avatar-option"
                        :class="{ active: isSameAvatar(formInline.avatar, defaultAvatars.male) }"
                        @click="selectDefaultAvatar('male')"
                      >
                        <img :src="defaultAvatars.male" alt="avatar-male" />
                      </div>
                      <div
                        class="avatar-option"
                        :class="{ active: isSameAvatar(formInline.avatar, defaultAvatars.female) }"
                        @click="selectDefaultAvatar('female')"
                      >
                        <img :src="defaultAvatars.female" alt="avatar-female" />
                      </div>
                      <div class="avatar-option upload" @click="modalPicTap('单选', 'avatar')">
                        <span class="iconfont iconjiahao1"></span>
                        <span>上传</span>
                      </div>
                    </div>
                  </div>
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="岗位：" prop="position_ids">
                  <Select
                    v-model="formInline.position_ids"
                    multiple
                    clearable
                    filterable
                    transfer
                    placeholder="请选择岗位"
                  >
                    <Option
                      v-for="item in jobOptions"
                      :value="item.value"
                      :key="item.value"
                    >{{ item.label }}</Option>
                  </Select>
                  <div class="form-tip">
                    总部可选全部启用岗位；是否能进入门店端由岗位入口与任职门店共同决定。
                  </div>
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="可作为销售人：">
                  <i-switch v-model="formInline.cashier_salesperson_enabled" :true-value="1" :false-value="0" size="large">
                    <span slot="open">是</span>
                    <span slot="close">否</span>
                  </i-switch>
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="可作为手艺人：">
                  <i-switch v-model="formInline.cashier_craftsman_enabled" :true-value="1" :false-value="0" size="large">
                    <span slot="open">是</span>
                    <span slot="close">否</span>
                  </i-switch>
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="人员类型：">
                  <RadioGroup v-model="formInline.employment_type_code" type="button">
                    <Radio label="internal" :disabled="!canEditEmploymentType">内部员工</Radio>
                    <Radio label="partner" :disabled="!canEditEmploymentType">合作方</Radio>
                    <Radio label="outsourced" :disabled="!canEditEmploymentType">外包</Radio>
                  </RadioGroup>
                  <div v-if="!canManageEmploymentType" class="form-tip">
                    当前账号无人员类型管理权限。
                  </div>
                  <div v-else-if="editId > 0 && !employmentTypeLoaded" class="form-tip scope-warn">
                    人员类型未能安全加载，本次不会修改该字段。
                  </div>
                  <div v-else-if="!formInline.employment_type_code" class="form-tip scope-warn">
                    该员工尚未分类，请选择人员类型。
                  </div>
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="在职状态：">
                  <i-switch v-model="formInline.status" :true-value="1" :false-value="0" size="large">
                    <span slot="open">在职</span>
                    <span slot="close">离职</span>
                  </i-switch>
                </FormItem>
              </Col>
            </Row>
          </TabPane>

          <TabPane label="数据权限" name="scope">
            <Alert show-icon>
              设置该人员可查看的数据范围。个人：本人任职相关数据；门店：系统按当前任职自动计算；组织：可多选组织及其下级。
            </Alert>
            <FormItem label="数据范围：">
              <RadioGroup v-model="formInline.scope_mode" @on-change="onScopeModeChange">
                <Radio label="personal">个人</Radio>
                <Radio label="store" :disabled="!hasAppointmentStore">门店</Radio>
                <Radio label="org">组织</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-if="formInline.scope_mode === 'personal'" label="说明：">
              <div class="form-tip scope-tip">
                可查看本人在当前及历史任职门店产生的数据。历史数据仍保留门店归属。
              </div>
            </FormItem>
            <FormItem v-if="formInline.scope_mode === 'store'" label="说明：">
              <div class="form-tip scope-tip">
                系统自动按当前任职门店及本人历史任职数据计算，无需手动选择门店。
              </div>
              <div v-if="!hasAppointmentStore" class="form-tip scope-warn">
                该人员没有当前任职门店，不能选择「门店」数据权限。请先选择当前任职门店，或改用「个人」「组织」。
              </div>
            </FormItem>
            <FormItem v-if="formInline.scope_mode === 'org'" label="可看组织：">
              <OrganizationResourceSelector
                v-model="formInline.org_ids"
                resource="organization"
                picker-mode="modal"
                :tree-mode="true"
                selection-mode="org_only"
                modal-title="选择可看组织"
                trigger-placeholder="请选择可看组织"
                placeholder="搜索组织名称"
                :multiple="true"
                :disabled-ids="[]"
              />
              <div class="form-tip">可多选组织；将查看所选组织及其下级组织、门店的数据（取并集）。不能直接选门店。</div>
            </FormItem>
            <FormItem label="手机端：">
              <i-switch
                v-model="formInline.mobile_enabled"
                :true-value="1"
                :false-value="0"
                :disabled="submitting || (editId > 0 && !mobileAuthLoaded) || !hasMobileCapablePosition"
                size="large"
                @on-change="onMobileEnabledChange"
              >
                <span slot="open">启用</span>
                <span slot="close">关闭</span>
              </i-switch>
              <div v-if="editId > 0 && !mobileAuthLoaded" class="form-tip scope-warn">
                手机端授权状态未能安全加载，本次保存不会修改该项。
              </div>
              <div v-else-if="!hasMobileCapablePosition" class="form-tip scope-warn">
                所选岗位未配置手机端功能，不能启用手机端。
              </div>
              <div v-else class="form-tip">
                岗位决定可使用的商家端功能；此开关只控制该员工是否实际开通手机端。
              </div>
            </FormItem>
          </TabPane>

          <TabPane label="登录设置" name="login">
            <Alert show-icon>
              统一内部账号：用于平台后台和门店端登录。手机端使用手机验证与手机端权限，不使用内部账号密码。账号禁止使用纯 11 位手机号格式。
            </Alert>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="登录账号：">
                  <Input v-model="formInline.account" placeholder="请输入统一内部账号" autocomplete="off" />
                  <div class="tips">禁止纯 11 位手机号格式</div>
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="登录密码：">
                  <Input
                    v-model="formInline.pwd"
                    type="password"
                    password
                    autocomplete="new-password"
                    :placeholder="editId > 0 ? '不修改请留空' : '请输入登录密码'"
                  />
                </FormItem>
              </Col>
            </Row>
          </TabPane>

          <TabPane label="其他信息" name="other">
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="关联企微：">
                  <Select v-model="formInline.work_member_id" clearable filterable transfer placeholder="请选择企微员工">
                    <Option v-for="item in workList" :value="item.value" :key="item.value">{{ item.label }}</Option>
                  </Select>
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="客服开关：">
                  <i-switch v-model="formInline.is_customer" :true-value="1" :false-value="0" size="large">
                    <span slot="open">显示</span>
                    <span slot="close">隐藏</span>
                  </i-switch>
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="可被预约：">
                  <i-switch v-model="formInline.is_reservable" :true-value="1" :false-value="0" size="large">
                    <span slot="open">是</span>
                    <span slot="close">否</span>
                  </i-switch>
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="工号：">
                  <Input v-model="formInline.employee_number" placeholder="请输入工号" />
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="身份证号：">
                  <Input v-model="formInline.id_card" placeholder="请输入身份证号" />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="年龄：">
                  <Input v-model="formInline.age" placeholder="请输入年龄" />
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="劳动关系所在地：">
                  <Input v-model="formInline.join_area" placeholder="请输入劳动关系所在地" />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="入职日期：">
                  <DatePicker
                    transfer
                    :editable="false"
                    clearable
                    type="date"
                    format="yyyy/MM/dd"
                    placeholder="请选择入职日期"
                    style="width: 100%"
                    :value="formInline.join_date"
                    @on-change="(val) => setDateField('join_date', val)"
                  />
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="生日日期：">
                  <DatePicker
                    transfer
                    :editable="false"
                    clearable
                    type="date"
                    format="yyyy/MM/dd"
                    placeholder="请选择生日日期"
                    style="width: 100%"
                    :value="formInline.birthday_date"
                    @on-change="(val) => setDateField('birthday_date', val)"
                  />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="合同起始日：">
                  <DatePicker
                    transfer
                    :editable="false"
                    clearable
                    type="date"
                    format="yyyy/MM/dd"
                    placeholder="请选择合同起始日"
                    style="width: 100%"
                    :value="formInline.contract_begin"
                    @on-change="(val) => setDateField('contract_begin', val)"
                  />
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="合同终止日：">
                  <DatePicker
                    transfer
                    :editable="false"
                    clearable
                    type="date"
                    format="yyyy/MM/dd"
                    placeholder="请选择合同终止日"
                    style="width: 100%"
                    :value="formInline.contract_end"
                    @on-change="(val) => setDateField('contract_end', val)"
                  />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="生日类型：">
                  <Select v-model="formInline.birthday_type" clearable transfer placeholder="请选择生日类型">
                    <Option :value="1">农历</Option>
                    <Option :value="2">新历</Option>
                  </Select>
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24" v-if="formInline.is_customer">
              <Col :span="12">
                <FormItem label="客服二维码：" prop="customer_url">
                  <div class="picBox" @click="modalPicTap('单选', 'customer_url')">
                    <div class="pictrue" v-if="formInline.customer_url"><img v-lazy="formInline.customer_url" /></div>
                    <div class="upLoad acea-row row-center-wrapper" v-else>
                      <span class="iconfont iconjiahao1"></span>
                    </div>
                  </div>
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="籍贯：">
                  <Input v-model="formInline.birthday_area" placeholder="请输入籍贯" />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="现居地：">
                  <Input v-model="formInline.now_area" placeholder="请输入现居地" />
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="工资状态：">
                  <i-switch v-model="formInline.salary_status" :true-value="1" :false-value="0" size="large">
                    <span slot="open">显示</span>
                    <span slot="close">隐藏</span>
                  </i-switch>
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="部门：">
                  <Input v-model="formInline.department" placeholder="请输入部门" />
                </FormItem>
              </Col>
            </Row>
          </TabPane>
        </Tabs>
      </Form>
      <div slot="footer">
        <Button @click="handleClose">取消</Button>
        <Button
          type="primary"
          class="ml14"
          :loading="submitting"
          :disabled="!canSaveStaff"
          @click="handleSubmit"
        >保存</Button>
      </div>
      <div v-if="!canSaveStaff" class="staff-save-deny-tip">{{ staffSaveDenyTip }}</div>
    </Modal>
    <Modal
      v-model="modalPic"
      width="960px"
      scrollable
      footer-hide
      closable
      title="上传图片"
      :mask-closable="false"
      :z-index="1100"
    >
      <uploadPictures :isChoice="isChoice" @getPic="getPic" v-if="modalPic" />
    </Modal>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import {
  workMemberList,
  postStaff,
  getStaffInfo,
  getPersonComplete,
} from '@/api/staff.js';
import { getJobPositions } from '@/api/store';
import Setting from '@/setting';
import { findFirstRequiredError } from '@/utils/requiredCheck';
import uploadPictures from '@/components/uploadPictures';
import OrganizationResourceSelector from '@/components/organization/OrganizationResourceSelector.vue';

const DATE_FIELDS = ['join_date', 'birthday_date', 'contract_begin', 'contract_end'];

function newRequestToken() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : ((r & 0x3) | 0x8);
    return v.toString(16);
  });
}

/** 基本信息区：从左到右、从上到下的必填检查顺序（只提示第一个） */
const REQUIRED_CHECK_ORDER = [
  {
    key: 'org_id',
    tab: 'basic',
    message: '所属组织未选择',
    isEmpty: (v) => !(Number(v) > 0),
  },
  {
    key: 'staff_name',
    tab: 'basic',
    message: '员工姓名未填写',
    isEmpty: (v) => !String(v || '').trim(),
  },
  {
    key: 'avatar',
    tab: 'basic',
    message: '员工头像未设置',
    isEmpty: (v) => !v,
  },
  {
    key: 'phone',
    tab: 'basic',
    message: '手机号码未填写',
    isEmpty: (v) => !String(v || '').trim(),
    validate: (v) => (/^1[3456789]\d{9}$/.test(String(v)) ? null : '手机号格式不正确'),
  },
  {
    key: 'position_ids',
    tab: 'basic',
    message: '岗位未选择',
    isEmpty: (v) => !Array.isArray(v) || v.length === 0,
  },
  {
    key: 'customer_url',
    tab: 'other',
    message: '客服二维码未上传',
    isEmpty: (v, form) => !!(form && form.is_customer) && !v,
  },
];

function resolveApiOrigin() {
  return String(Setting.apiBaseURL || '')
    .replace(/\/adminapi\/?$/i, '')
    .replace(/\/storeapi\/?$/i, '')
    .replace(/\/+$/, '');
}

function buildDefaultAvatars() {
  const base = resolveApiOrigin();
  return {
    male: `${base}/static/images/staff/avatar_male.png`,
    female: `${base}/static/images/staff/avatar_female.png`,
  };
}

const DEFAULT_AVATARS = buildDefaultAvatars();

function getDefaultStaffForm() {
  return {
    store_id: '',
    org_id: 0,
    staff_name: '',
    avatar: DEFAULT_AVATARS.male,
    account: '',
    pwd: '',
    phone: '',
    work_member_id: '',
    position_ids: [],
    scope_mode: 'personal',
    org_ids: [],
    store_ids: [],
    employee_id: 0,
    staff_id: 0,
    is_customer: 0,
    can_choose: 1,
    cashier_salesperson_enabled: 1,
    cashier_craftsman_enabled: 1,
    is_reservable: 1,
    is_fencheng: 0,
    mobile_enabled: 0,
    employment_type_code: 'internal',
    employment_type_version: 0,
    customer_url: '',
    status: 1,
    salary_status: 1,
    department: '',
    employee_number: '',
    join_date: '',
    id_card: '',
    birthday_date: '',
    birthday_type: 1,
    age: 0,
    join_area: '',
    birthday_area: '',
    now_area: '',
    contract_begin: '',
    contract_end: '',
  };
}

export default {
  name: 'setting_staff_add',
  components: { uploadPictures, OrganizationResourceSelector },
  props: {
    value: { type: Boolean, default: false },
    /** 编辑主键：组织场景必须为 employee_id；店员列表场景为 staff_id */
    editId: { type: Number, default: 0 },
    /** 组织场景可选：当前任职 staff_id，仅作 person_complete 的 query，不得当作路径 id */
    staffId: { type: Number, default: 0 },
    /** organization：组织工作台新建/编辑人员场景 */
    scene: { type: String, default: '' },
    defaultStoreId: { type: Number, default: 0 },
    allowedStoreIds: { type: Array, default: () => [] },
    defaultOrgId: { type: Number, default: 0 },
    /** 是否允许保存（写门禁 + 人员维护权限） */
    canSave: { type: Boolean, default: true },
    saveDenyTip: {
      type: String,
      default: '当前岗位未配置“人员维护”权限，请联系总部管理员授权。',
    },
  },
  data() {
    const validateUpload = (rule, value, callback) => {
      if (!this.formInline.avatar) {
        callback(new Error('请设置员工头像'));
      } else {
        callback();
      }
    };
    const validatePhone = (rule, value, callback) => {
      if (!value) {
        return callback(new Error('请填写手机号'));
      }
      if (!/^1[3456789]\d{9}$/.test(value)) {
        callback(new Error('手机号格式不正确!'));
      } else {
        callback();
      }
    };
    const validateUrl = (rule, value, callback) => {
      if (this.formInline.is_customer && !this.formInline.customer_url) {
        callback(new Error('请上传客服二维码'));
      } else {
        callback();
      }
    };
    const validateOrg = (rule, value, callback) => {
      if (!(Number(this.formInline.org_id) > 0)) {
        callback(new Error('请选择所属组织'));
      } else {
        callback();
      }
    };
    return {
      activeTab: 'basic',
      modalPic: false,
      isChoice: '单选',
      picTit: '',
      submitting: false,
      detailLoaded: false,
      employmentTypeLoaded: true,
      /** 编辑人员时必须由完整详情回显授权状态，防止读取失败后误撤权。 */
      mobileAuthLoaded: true,
      /** 新建人员自动默认值只应用一次，员工手工关闭后不再被岗位选择覆盖。 */
      mobileEnabledTouched: false,
      originalInternalAccount: '',
      /** 详情加载序号：连续切换人员时丢弃过期响应，防止串人 */
      loadSeq: 0,
      jobOptions: [],
      workList: [],
      defaultAvatars: { ...DEFAULT_AVATARS },
      formInline: getDefaultStaffForm(),
      viewportWidth: typeof window !== 'undefined' ? window.innerWidth : 1440,
      ruleValidate: {
        org_id: [{ required: true, validator: validateOrg, trigger: 'change' }],
        staff_name: [{ required: true, message: '请输入员工姓名', trigger: 'blur' }],
        avatar: [{ required: true, validator: validateUpload, trigger: 'change' }],
        phone: [{ required: true, validator: validatePhone, trigger: 'blur' }],
        position_ids: [{ required: true, type: 'array', min: 1, message: '请选择岗位', trigger: 'change' }],
        customer_url: [{ validator: validateUrl, trigger: 'change' }],
      },
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    ...mapState('admin/user', { userInfo: 'info' }),
    canSaveStaff() {
      if (!this.canSave) return false;
      // fail-closed：access 缺失/空/异常默认不可保存；仅明确总部超管或具备人员维护权限
      const info = this.userInfo || {};
      if (this.isExplicitHqSuperAdmin(info)) return true;
      const access = info.access;
      if (!Array.isArray(access) || access.length === 0) return false;
      return access.indexOf('setting-staff-index') !== -1;
    },
    canManageEmploymentType() {
      const info = this.userInfo || {};
      if (this.isExplicitHqSuperAdmin(info)) return true;
      const access = info.access;
      return Array.isArray(access) && access.indexOf('setting-staff-employment-type') !== -1;
    },
    canEditEmploymentType() {
      return this.canManageEmploymentType
        && (!(Number(this.editId) > 0) || this.employmentTypeLoaded);
    },
    staffSaveDenyTip() {
      if (!this.canSaveStaff) {
        return this.saveDenyTip || '当前岗位未配置“人员维护”权限，请联系总部管理员授权。';
      }
      return '';
    },
    isNarrowForm() {
      return Number(this.viewportWidth) <= 900;
    },
    modalWidth() {
      const w = Number(this.viewportWidth) || 1440;
      if (w <= 900) return Math.min(780, Math.max(320, w - 24));
      if (w <= 1100) return Math.min(980, w - 32);
      return Math.min(1200, w - 48);
    },
    modalStyles() {
      return {
        top: this.isNarrowForm ? '12px' : '24px',
        maxWidth: '96vw',
      };
    },
    formLabelWidth() {
      if (this.isNarrowForm) return 96;
      if (Number(this.viewportWidth) <= 1100) return 100;
      return 110;
    },
    labelPosition() {
      return this.isMobile || this.isNarrowForm ? 'top' : 'right';
    },
    modalTitle() {
      if (this.scene === 'organization' && !(this.editId > 0)) {
        return '新建人员';
      }
      return this.editId > 0 ? '编辑人员' : '新建人员';
    },
    /** 已有任职店员：后端编辑时会锁定原 store_id */
    storeLocked() {
      return this.editId > 0 && Number(this.formInline.staff_id || this.editId) > 0
        && Number(this.formInline.store_id) > 0;
    },
    storeLockedLabel() {
      const sid = Number(this.formInline.store_id || 0);
      return sid > 0 ? `门店 #${sid}` : '-';
    },
    hasAppointmentStore() {
      return Number(this.formInline.store_id) > 0;
    },
    hasMobileCapablePosition() {
      const selected = new Set((this.formInline.position_ids || []).map((id) => Number(id)));
      return this.jobOptions.some((position) => selected.has(Number(position.value))
        && Number(position.use_mobile) === 1);
    },
  },
  watch: {
    value(val) {
      if (val) {
        this.openForm();
      } else {
        // 关闭时作废进行中的详情请求，并清空表单，避免下次打开短暂显示上一人
        this.loadSeq += 1;
        this.detailLoaded = false;
        this.resetForm();
      }
    },
    editId() {
      if (this.value) {
        this.openForm();
      }
    },
    staffId() {
      if (this.value && Number(this.editId) > 0) {
        this.openForm();
      }
    },
    'formInline.position_ids': {
      deep: true,
      handler() {
        this.syncNewStaffMobileDefault();
        this.forceMobileOffWithoutEligibleJob();
      },
    },
  },
  mounted() {
    this._onViewportResize = () => {
      this.viewportWidth = window.innerWidth || 1440;
    };
    window.addEventListener('resize', this._onViewportResize, { passive: true });
    this._onViewportResize();
  },
  beforeDestroy() {
    if (this._onViewportResize) {
      window.removeEventListener('resize', this._onViewportResize);
    }
  },
  created() {
    this.workMember();
  },
  methods: {
    /** 仅当前端 info 明确 level=0 且非代理时视为总部超管（access 空时的唯一放行例外） */
    isExplicitHqSuperAdmin(info) {
      if (!info || typeof info !== 'object') return false;
      if (info.level === undefined || info.level === null || info.level === '') return false;
      if (Number(info.level) !== 0) return false;
      const adminType = (info.admin_type === undefined || info.admin_type === null || info.admin_type === '')
        ? 0
        : Number(info.admin_type);
      if (Number.isNaN(adminType) || adminType === 3) return false;
      return true;
    },
    getDefaultForm() {
      return getDefaultStaffForm();
    },
    normalizeDate(val) {
      if (!val || val === '0000-00-00' || val === '1899-11-30' || String(val).indexOf('1899') === 0) {
        return '';
      }
      return val;
    },
    setDateField(field, val) {
      this.formInline[field] = val || '';
    },
    resolveStaffAvatar(url) {
      if (!url) return '';
      const origin = resolveApiOrigin();
      const raw = String(url);
      const m = raw.match(/\/static\/images\/staff\/avatar_(male|female)\.(svg|png)/i);
      if (m) {
        return `${origin}/static/images/staff/avatar_${m[1].toLowerCase()}.png`;
      }
      if (raw.startsWith('/')) {
        return origin + raw;
      }
      if (/^https?:\/\/127\.0\.0\.1\/static\//i.test(raw)) {
        return origin + raw.replace(/^https?:\/\/127\.0\.0\.1/i, '');
      }
      return raw;
    },
    selectDefaultAvatar(type) {
      this.formInline.avatar = type === 'female' ? this.defaultAvatars.female : this.defaultAvatars.male;
      this.$refs.formInline && this.$refs.formInline.validateField('avatar');
    },
    handleClose() {
      this.$emit('input', false);
    },
    resetForm() {
      this.activeTab = 'basic';
      this.submitting = false;
      this.originalInternalAccount = '';
      this.formInline = this.getDefaultForm();
      this.employmentTypeLoaded = !(Number(this.editId) > 0);
      this.mobileAuthLoaded = !(Number(this.editId) > 0);
      this.mobileEnabledTouched = false;
      this.$nextTick(() => {
        this.$refs.formInline && this.$refs.formInline.clearValidate();
      });
    },
    isSameAvatar(current, target) {
      if (!current || !target) return false;
      if (current === target) return true;
      const a = String(current).split('?')[0];
      const b = String(target).split('?')[0];
      return a === b || a.endsWith(b.replace(/^https?:\/\/[^/]+/, '')) || b.endsWith(a.replace(/^https?:\/\/[^/]+/, ''));
    },
    openForm() {
      this.loadSeq += 1;
      this.detailLoaded = false;
      this.resetForm();
      this.loadJobOptions();
      if (this.editId > 0) {
        this.staffInfo();
        return;
      }
      this.detailLoaded = true;
      const defaultOrgId = Number(this.defaultOrgId || 0);
      if (defaultOrgId > 0) {
        this.formInline.org_id = defaultOrgId;
      }
      const defaultStoreId = Number(this.defaultStoreId || 0);
      if (defaultStoreId > 0) {
        const allowed = (this.allowedStoreIds || []).map(Number).filter((id) => id > 0);
        if (!allowed.length || allowed.includes(defaultStoreId)) {
          this.formInline.store_id = defaultStoreId;
        }
      }
    },
    onOrgPick(orgId, meta) {
      const oid = Number(orgId || (meta && meta.org_id) || 0);
      this.formInline.org_id = oid > 0 ? oid : 0;
      // 所属组织只能选组织：手动改组织时清空任职门店，避免组织与门店不一致
      if (!this.storeLocked) {
        this.formInline.store_id = '';
        this.pruneJobsForStore();
      }
      if (this.formInline.scope_mode === 'store' && !this.hasAppointmentStore) {
        this.formInline.scope_mode = 'personal';
      }
    },
    onStorePick(storeId, meta) {
      const sid = Number(storeId || (meta && meta.store_id) || 0);
      this.formInline.store_id = sid > 0 ? sid : '';
      // 选门店：自动回写所属组织
      const oid = Number((meta && meta.org_id) || 0);
      if (sid > 0 && oid > 0) {
        this.formInline.org_id = oid;
      } else if (!(sid > 0) && !this.storeLocked) {
        // 清除门店不改所属组织
      }
      this.pruneJobsForStore();
    },
    onScopeModeChange(mode) {
      if (mode === 'store' && !this.hasAppointmentStore) {
        this.$Message.warning('该人员没有当前任职门店，不能选择「门店」数据权限。请先选择当前任职门店，或改用「个人」「组织」。');
        this.$nextTick(() => {
          this.formInline.scope_mode = 'personal';
        });
      }
    },
    pruneJobsForStore() {
      // 总部岗位不与任职门店绑定，清空门店时不再裁剪已选岗位
    },
    applyPersonComplete(data, extraInfo = {}) {
      const scope = (data && data.scope) || {};
      const base = this.getDefaultForm();
      // 其它信息可用 read 补全扩展字段；组织场景禁止依赖 staff/read
      Object.keys(base).forEach((key) => {
        if (Object.prototype.hasOwnProperty.call(extraInfo, key) && extraInfo[key] !== undefined) {
          base[key] = extraInfo[key];
        }
      });
      const storeId = Number((data && data.store_id) || 0);
      let scopeMode = String(scope.scope_mode || 'personal');
      // 后端存 store_self，表单仅认 personal/store/org
      if (scopeMode === 'store_self') scopeMode = 'store';
      if (!['personal', 'store', 'org'].includes(scopeMode)) {
        scopeMode = 'personal';
      }
      const employmentTypeLoaded = !!(data
        && Object.prototype.hasOwnProperty.call(data, 'employment_type_code')
        && Object.prototype.hasOwnProperty.call(data, 'employment_type_version'));
      const mobileAuthLoaded = !!(data
        && Object.prototype.hasOwnProperty.call(data, 'mobile_enabled'));
      this.formInline = {
        ...base,
        employee_id: Number((data && data.employee_id) || 0),
        staff_id: Number((data && data.staff_id) || 0),
        org_id: Number((data && data.org_id) || base.org_id || 0),
        store_id: storeId > 0 ? storeId : '',
        staff_name: String((data && data.staff_name) || base.staff_name || ''),
        phone: String((data && data.phone) || base.phone || ''),
        avatar: (data && data.avatar) || base.avatar || this.defaultAvatars.male,
        account: String((data && data.account) || base.account || ''),
        pwd: '',
        position_ids: ((data && data.position_ids) || []).map(Number).filter((n) => n > 0),
        scope_mode: scopeMode,
        org_ids: (scope.org_ids || []).map(Number).filter((n) => n > 0),
        store_ids: (scope.store_ids || []).map(Number).filter((n) => n > 0),
        can_choose: Number((data && data.can_choose) != null ? data.can_choose : base.can_choose),
        cashier_salesperson_enabled: Number(
          (data && data.cashier_salesperson_enabled) != null
            ? data.cashier_salesperson_enabled
            : base.cashier_salesperson_enabled,
        ),
        cashier_craftsman_enabled: Number(
          (data && data.cashier_craftsman_enabled) != null
            ? data.cashier_craftsman_enabled
            : base.cashier_craftsman_enabled,
        ),
        is_fencheng: Number((data && data.is_fencheng) != null ? data.is_fencheng : base.is_fencheng),
        mobile_enabled: mobileAuthLoaded
          ? (Number(data.mobile_enabled) === 1 ? 1 : 0)
          : base.mobile_enabled,
        employment_type_code: employmentTypeLoaded && typeof data.employment_type_code === 'string'
          ? data.employment_type_code
          : '',
        employment_type_version: employmentTypeLoaded
          ? Number(data.employment_type_version || 0)
          : 0,
        status: Number((data && data.status) != null ? data.status : base.status),
      };
      this.employmentTypeLoaded = employmentTypeLoaded;
      this.mobileAuthLoaded = mobileAuthLoaded;
      this.mobileEnabledTouched = false;
      this.originalInternalAccount = String((data && data.account) || base.account || '');
      DATE_FIELDS.forEach((field) => {
        this.formInline[field] = this.normalizeDate(this.formInline[field]);
      });
      if (!this.formInline.avatar) {
        this.formInline.avatar = this.defaultAvatars.male;
      }
      if (scopeMode === 'store' && storeId > 0) {
        // 门店范围由服务端自动计算，前端不再维护 store_ids
      }
      this.pruneJobsForStore();
      this.detailLoaded = true;
    },
    /**
     * 校验详情响应是否对应当前选中人员，防止异步串人
     * @returns {boolean}
     */
    assertDetailMatchesRequest(data, expectEmployeeId, expectStaffId) {
      const respEmp = Number((data && data.employee_id) || 0);
      const respStaff = Number((data && data.staff_id) || 0);
      if (expectEmployeeId > 0 && respEmp > 0 && respEmp !== expectEmployeeId) {
        return false;
      }
      if (expectStaffId > 0 && respStaff > 0 && respStaff !== expectStaffId) {
        return false;
      }
      return true;
    },
    staffInfo() {
      const editId = Number(this.editId || 0);
      if (!(editId > 0)) return;
      const seq = this.loadSeq;

      // 组织工作台：editId=employee_id，只调 person_complete，禁止 staff/read
      if (this.scene === 'organization') {
        const employeeId = editId;
        const staffId = Number(this.staffId || 0);
        const params = staffId > 0 ? { staff_id: staffId } : {};
        getPersonComplete(employeeId, params)
          .then((cres) => {
            if (seq !== this.loadSeq || !this.value) return;
            const data = (cres && cres.data) || {};
            if (!this.assertDetailMatchesRequest(data, employeeId, staffId)) {
              this.detailLoaded = true;
              this.$Message.error('人员详情与所选人员不一致，已取消回填');
              return;
            }
            this.applyPersonComplete(data);
          })
          .catch((err) => {
            if (seq !== this.loadSeq || !this.value) return;
            this.detailLoaded = true;
            this.$Message.error((err && err.msg) || '加载人员失败');
          });
        return;
      }

      // 店员列表等：editId=staff_id；经 read 取 employee_id 后再 person_complete
      getStaffInfo(editId)
        .then((res) => {
          if (seq !== this.loadSeq || !this.value) return null;
          const info = (res.data && res.data.ps_info) || {};
          const employeeId = Number(info.employee_id || 0);
          const staffId = Number(info.id || editId);
          if (staffId > 0 && staffId !== editId) {
            this.detailLoaded = true;
            this.$Message.error('人员详情与所选人员不一致，已取消回填');
            return null;
          }
          if (employeeId > 0) {
            return getPersonComplete(employeeId, staffId > 0 ? { staff_id: staffId } : {})
              .then((cres) => {
                if (seq !== this.loadSeq || !this.value) return;
                const data = (cres && cres.data) || {};
                if (!this.assertDetailMatchesRequest(data, employeeId, staffId)) {
                  this.detailLoaded = true;
                  this.$Message.error('人员详情与所选人员不一致，已取消回填');
                  return;
                }
                this.applyPersonComplete(data, info);
              })
              .catch(() => {
                if (seq !== this.loadSeq || !this.value) return;
                // person_complete 失败时至少回显 read（非组织场景）
                this.applyPersonComplete({
                  employee_id: employeeId,
                  staff_id: staffId,
                  org_id: Number(info.org_id || 0),
                  store_id: Number(info.store_id || 0),
                  staff_name: info.staff_name,
                  phone: info.phone,
                  avatar: info.avatar,
                  account: info.account,
                  position_ids: [],
                  scope: { scope_mode: 'personal', org_ids: [], store_ids: [] },
                  can_choose: info.can_choose,
                  cashier_salesperson_enabled: info.cashier_salesperson_enabled,
                  cashier_craftsman_enabled: info.cashier_craftsman_enabled,
                  is_fencheng: info.is_fencheng,
                  status: info.status,
                }, info);
              });
          }
          return getPersonComplete(editId).then((cres) => {
            if (seq !== this.loadSeq || !this.value) return;
            this.applyPersonComplete((cres && cres.data) || {}, info);
          });
        })
        .catch(() => {
          if (seq !== this.loadSeq || !this.value) return;
          getPersonComplete(editId)
            .then((cres) => {
              if (seq !== this.loadSeq || !this.value) return;
              this.applyPersonComplete((cres && cres.data) || {});
            })
            .catch((err) => {
              if (seq !== this.loadSeq || !this.value) return;
              this.detailLoaded = true;
              this.$Message.error((err && err.msg) || '加载人员失败');
            });
        });
    },
    isDefaultAvatar(url) {
      if (!url) return false;
      if (this.isSameAvatar(url, this.defaultAvatars.male) || this.isSameAvatar(url, this.defaultAvatars.female)) {
        return true;
      }
      return /avatar_(male|female)\.(svg|png)(?:\?|$)/i.test(String(url));
    },
    modalPicTap(tit, picTit) {
      this.modalPic = true;
      this.picTit = picTit || '';
      this.$refs.formInline && this.$refs.formInline.validateField(picTit);
    },
    getPic(pc) {
      this.formInline[this.picTit] = pc.att_dir;
      this.modalPic = false;
      this.$refs.formInline && this.$refs.formInline.validateField(this.picTit);
    },
    workMember() {
      workMemberList()
        .then((res) => {
          this.workList = res.data || [];
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    loadJobOptions() {
      return getJobPositions({ keyword: '', page: 1, limit: 200 })
        .then((res) => {
          const list = (res && res.data && res.data.list) || [];
          this.jobOptions = list
            .filter((row) => Number(row.status) === 1)
            .map((row) => ({
              value: Number(row.id),
              label: row.name,
              use_platform: Number(row.use_platform) === 1 ? 1 : 0,
              use_store: Number(row.use_store) === 1 ? 1 : 0,
              use_cashier: Number(row.use_cashier) === 1 ? 1 : 0,
              use_mobile: Number(row.use_mobile) === 1 ? 1 : 0,
            }));
          this.pruneJobsForStore();
          this.syncNewStaffMobileDefault();
          this.forceMobileOffWithoutEligibleJob();
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '加载岗位失败');
        });
    },
    onMobileEnabledChange(enabled) {
      this.mobileEnabledTouched = true;
      this.formInline.mobile_enabled = enabled ? 1 : 0;
    },
    syncNewStaffMobileDefault() {
      if (Number(this.editId) > 0 || this.mobileEnabledTouched) return;
      this.formInline.mobile_enabled = this.hasMobileCapablePosition ? 1 : 0;
    },
    forceMobileOffWithoutEligibleJob() {
      if (this.hasMobileCapablePosition || !this.mobileAuthLoaded) return;
      // 岗位失去手机端能力时，员工总开关必须随之关闭；保存时后端会同步撤销两层授权。
      this.formInline.mobile_enabled = 0;
    },
    buildSubmitPayload() {
      const payload = { ...this.formInline };
      DATE_FIELDS.forEach((field) => {
        payload[field] = payload[field] ? payload[field] : null;
      });
      if (!payload.pwd) {
        delete payload.pwd;
      }
      delete payload.uid;
      delete payload.nickname;
      delete payload.image;
      delete payload.roles;
      delete payload.role_ids;
      delete payload.save_roles;
      delete payload.is_manager;
      delete payload.is_butler;
      delete payload.position;
      delete payload.position_level;
      delete payload.staff_id;
      delete payload.employee_id;

      payload.org_id = Number(this.formInline.org_id) || 0;
      payload.store_id = Number(this.formInline.store_id) || 0;
      payload.staff_name = String(this.formInline.staff_name || '').trim();
      payload.phone = String(this.formInline.phone || '').trim();
      payload.account = String(this.formInline.account || '').trim();
      if (Number(this.formInline.employee_id) > 0
        && payload.account === this.originalInternalAccount) {
        // 账号未改：后端保留现有统一账号，完整资料保存不触发账号/密码写入。
        delete payload.account;
      }
      payload.position_ids = Array.isArray(this.formInline.position_ids)
        ? this.formInline.position_ids.map((x) => Number(x)).filter((n) => n > 0)
        : [];
      payload.scope_mode = ['personal', 'store', 'org'].includes(this.formInline.scope_mode)
        ? this.formInline.scope_mode
        : 'personal';
      payload.org_ids = payload.scope_mode === 'org'
        ? (this.formInline.org_ids || []).map(Number).filter((n) => n > 0)
        : [];
      // 门店范围由服务端按任职自动计算，前端不再提交人工 store_ids（防扩权）
      payload.store_ids = [];
      payload.cashier_salesperson_enabled = Number(this.formInline.cashier_salesperson_enabled) === 1 ? 1 : 0;
      payload.cashier_craftsman_enabled = Number(this.formInline.cashier_craftsman_enabled) === 1 ? 1 : 0;
      // Legacy consumers still read can_choose. Keep it as an OR projection
      // while V3 uses the two role-specific authoritative switches.
      payload.can_choose = payload.cashier_salesperson_enabled === 1
        || payload.cashier_craftsman_enabled === 1 ? 1 : 0;
      payload.is_fencheng = Number(this.formInline.is_fencheng) === 1 ? 1 : 0;
      if (!(Number(this.editId) > 0) || this.mobileAuthLoaded) {
        payload.mobile_enabled = Number(this.formInline.mobile_enabled) === 1 ? 1 : 0;
      } else {
        delete payload.mobile_enabled;
      }
      if (this.canEditEmploymentType) {
        payload.employment_type_code = String(this.formInline.employment_type_code || '');
        payload.employment_type_version = Number(this.formInline.employment_type_version || 0);
      } else {
        delete payload.employment_type_code;
        delete payload.employment_type_version;
      }
      payload.request_token = newRequestToken();
      return payload;
    },
    handleSubmit() {
      if (this.submitting) return;
      if (!this.canSaveStaff) {
        this.$Message.error(this.staffSaveDenyTip);
        return;
      }
      if (this.canEditEmploymentType
        && !['internal', 'partner', 'outsourced'].includes(this.formInline.employment_type_code)) {
        this.activeTab = 'basic';
        this.$Message.required('请选择人员类型');
        return;
      }
      const first = findFirstRequiredError(REQUIRED_CHECK_ORDER, this.formInline);
      if (first) {
        if (first.tab) this.activeTab = first.tab;
        this.$Message.required(first.message);
        this.$nextTick(() => {
          this.$refs.formInline && this.$refs.formInline.validateField(first.key);
        });
        return;
      }
      if (this.formInline.scope_mode === 'store' && !this.hasAppointmentStore) {
        this.activeTab = 'scope';
        this.$Message.required('该人员没有当前任职门店，不能选择「门店」数据权限。请先选择当前任职门店，或改用「个人」「组织」。');
        return;
      }
      if (this.formInline.scope_mode === 'org'
        && (!(Array.isArray(this.formInline.org_ids) && this.formInline.org_ids.length))) {
        this.activeTab = 'scope';
        this.$Message.required('请选择可看组织');
        return;
      }

      const payload = this.buildSubmitPayload();
      if (this.isDefaultAvatar(payload.avatar) || !payload.avatar) {
        payload.avatar = this.isSameAvatar(payload.avatar, this.defaultAvatars.female)
          || /avatar_female/i.test(payload.avatar || '')
          ? '/static/images/staff/avatar_female.png'
          : '/static/images/staff/avatar_male.png';
      }
      const token = payload.request_token;
      const headers = {
        'X-Request-Token': token,
      };
      // 保存 URL：有 staff_id 用 staff_id；否则用 editId（可能为 employee_id / 0）
      const saveId = Number(this.formInline.staff_id) > 0
        ? Number(this.formInline.staff_id)
        : (Number(this.editId) || 0);

      this.submitting = true;
      postStaff(payload, saveId, headers)
        .then((res) => {
          const data = (res && res.data) || {};
          this.$Message.success((res && res.msg) || '保存成功');
          // 成功后用返回完整对象再关窗
          if (data && (data.employee_id || data.staff_id)) {
            this.applyPersonComplete(data);
          }
          this.$emit('success', data);
          this.handleClose();
        })
        .catch((err) => {
          const msg = (err && err.msg) || '保存失败';
          if (/未填写|未选择|未设置|未上传|请选择|请填写|请输入|必填/.test(msg)) {
            this.$Message.required(msg);
          } else {
            this.$Message.error(msg);
          }
        })
        .finally(() => {
          this.submitting = false;
        });
    },
  },
};
</script>

<style scoped lang="stylus">
/deep/.ivu-select-item, /deep/.ivu-select-input
  font-size 12px !important

.staff-person-form
  max-width 100%
  overflow-x hidden

  &.is-narrow
    /deep/ .ivu-col-span-12
      width 100% !important
      max-width 100% !important
      flex 0 0 100%

.tips
  font-size 12px
  color #999
  margin-top 6px

.form-tip
  font-size 12px
  color #808695
  margin-top 6px
  line-height 1.4

.scope-tip
  color #515a6e
  line-height 1.6

.scope-warn
  color #ed4014
  margin-top 6px

.ml14
  margin-left 14px
</style>

<style lang="stylus">
/* Modal 挂到 body，需非 scoped */
.staff-person-modal
  .ivu-modal
    max-width 96vw

  .ivu-modal-content
    max-width 100%
    overflow-x hidden

  .ivu-modal-body
    max-height calc(100vh - 168px)
    overflow-x hidden
    overflow-y auto

  .ivu-modal-footer
    overflow-x hidden
</style>

<style scoped lang="stylus">
/* keep avatar styles below */

.avatar-row
  display flex
  align-items center
  flex-wrap wrap
  gap 12px

.avatar-preview
  width 50px
  height 50px
  border-radius 4px
  overflow hidden
  border 1px solid #e8eaec

  img
    width 100%
    height 100%
    object-fit cover

.avatar-options
  display flex
  align-items center
  gap 10px

.avatar-option
  width 50px
  height 50px
  text-align center
  cursor pointer
  border 1px solid #e8eaec
  border-radius 4px
  padding 4px
  box-sizing border-box
  display flex
  align-items center
  justify-content center

  &.active
    border-color #2d8cf0

  img
    width 40px
    height 40px
    object-fit cover
    border-radius 4px

  span
    display block
    font-size 12px
    line-height 1.2

  &.upload
    flex-direction column
    gap 2px

.picBox
  display inline-block
  cursor pointer

  .upLoad
    width 58px
    height 58px
    line-height 58px
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

.staff-save-deny-tip
  margin 0 16px 12px
  padding 8px 10px
  color #d7475b
  background #fff0f2
  border-radius 8px
  font-size 12px
  line-height 1.4
</style>
