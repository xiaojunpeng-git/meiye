<template>
  <div>
    <Modal
      :value="value"
      :title="editId > 0 ? '编辑店员' : '新建店员'"
      width="1274"
      :mask-closable="false"
      :styles="{ top: '40px' }"
      @on-cancel="handleClose"
    >
      <Form
        v-if="value"
        ref="formInline"
        :model="formInline"
        :rules="ruleValidate"
        :label-width="110"
        :label-position="labelPosition"
        @submit.native.prevent
      >
        <Tabs v-model="activeTab">
          <TabPane label="基本信息" name="basic">
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="所属门店：">
                  <Input :value="currentStoreName || '-'" readonly />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="店员名称：" prop="staff_name">
                  <Input v-model="formInline.staff_name" placeholder="请输入店员名称" />
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="商城用户：">
                  <div v-if="formInline.uid">
                    {{ userName }}（ID：{{ formInline.uid }}）
                    <span class="link-text" @click="customer">换绑</span>
                    <span class="link-text" @click="delCustomer">解绑</span>
                  </div>
                  <div v-else class="link-text" @click="customer">+选择用户</div>
                  <div class="tips">选择用户后，该店员可在移动端有商家端入口</div>
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="店员头像：" prop="avatar">
                  <div class="avatar-row">
                    <div class="avatar-preview" v-if="formInline.avatar">
                      <img :src="resolveStaffAvatar(formInline.avatar)" alt="avatar" />
                    </div>
                    <div class="avatar-options" v-if="!hasMallAvatar">
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
                    <a v-else class="link-text ml10" @click="modalPicTap('单选', 'avatar')">更换头像</a>
                  </div>
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="手机号码：" prop="phone">
                  <Input v-model="formInline.phone" placeholder="请输入手机号码" />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="店员权限：" prop="roles">
                  <Select
                    v-model="formInline.roles"
                    multiple
                    clearable
                    filterable
                    transfer
                    placeholder="请选择店员权限"
                  >
                    <Option
                      v-for="item in roleList"
                      :value="String(item.value)"
                      :key="item.value"
                    >{{ item.label }}</Option>
                  </Select>
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="选择职位：" prop="position">
                  <Select v-model="formInline.position" clearable filterable transfer placeholder="请选择职位">
                    <Option v-for="item in positionData" :value="item.value" :key="item.value">{{ item.label }}</Option>
                  </Select>
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="选择职级：" prop="position_level">
                  <Select v-model="formInline.position_level" clearable filterable transfer placeholder="请选择职级">
                    <Option v-for="item in positionLevelData" :value="item.value" :key="item.value">{{ item.label }}</Option>
                  </Select>
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="店长开关：">
                  <i-switch v-model="formInline.is_manager" :true-value="1" :false-value="0" size="large">
                    <span slot="open">是</span>
                    <span slot="close">否</span>
                  </i-switch>
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="销售/手艺人：">
                  <i-switch v-model="formInline.can_choose" :true-value="1" :false-value="0" size="large">
                    <span slot="open">是</span>
                    <span slot="close">否</span>
                  </i-switch>
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

          <TabPane label="登录设置" name="login">
            <Alert show-icon>需要操作收银台的人才需要设置</Alert>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="店员账号：">
                  <Input v-model="formInline.account" placeholder="请输入店员账号" />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="店员密码：">
                  <Input
                    v-model="formInline.pwd"
                    type="text"
                    :placeholder="editId > 0 ? '不修改请留空' : '请输入店员密码'"
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
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="工号：">
                  <Input v-model="formInline.employee_number" placeholder="请输入工号" />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="身份证号：">
                  <Input v-model="formInline.id_card" placeholder="请输入身份证号" />
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="年龄：">
                  <Input v-model="formInline.age" placeholder="请输入年龄" />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="劳动关系所在地：">
                  <Input v-model="formInline.join_area" placeholder="请输入劳动关系所在地" />
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
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
            </Row>
            <Row :gutter="24">
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
                <FormItem label="生日类型：">
                  <Select v-model="formInline.birthday_type" clearable transfer placeholder="请选择生日类型">
                    <Option :value="1">农历</Option>
                    <Option :value="2">新历</Option>
                  </Select>
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="籍贯：">
                  <Input v-model="formInline.birthday_area" placeholder="请输入籍贯" />
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
              <Col :span="12">
                <FormItem label="现居地：">
                  <Input v-model="formInline.now_area" placeholder="请输入现居地" />
                </FormItem>
              </Col>
              <Col :span="12">
                <FormItem label="工资状态：">
                  <i-switch v-model="formInline.salary_status" :true-value="1" :false-value="0" size="large">
                    <span slot="open">显示</span>
                    <span slot="close">隐藏</span>
                  </i-switch>
                </FormItem>
              </Col>
            </Row>
            <Row :gutter="24">
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
        <Button type="primary" class="ml14" @click="handleSubmit">保存</Button>
      </div>
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
    <Modal
      v-model="modalUser"
      width="960px"
      scrollable
      footer-hide
      closable
      title="请选择商城用户"
      :mask-closable="false"
      :z-index="1100"
    >
      <customerInfo @imageObject="imageObject" />
    </Modal>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import {
  systemRoleList,
  workMemberList,
  postStaff,
  getStaffInfo,
  position,
  positionLevel,
} from '@/api/staff.js';
import Setting from '@/setting';
import { findFirstRequiredError } from '@/utils/requiredCheck';
import uploadPictures from '@/components/uploadPictures';
import customerInfo from '@/components/customerInfo';

const DATE_FIELDS = ['join_date', 'birthday_date', 'contract_begin', 'contract_end'];

/** 基本信息区：从左到右、从上到下的必填检查顺序（只提示第一个） */
const REQUIRED_CHECK_ORDER = [
  {
    key: 'staff_name',
    tab: 'basic',
    message: '店员名称未填写',
    isEmpty: (v) => !String(v || '').trim(),
  },
  {
    key: 'avatar',
    tab: 'basic',
    message: '店员头像未设置',
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
    key: 'roles',
    tab: 'basic',
    message: '店员权限未选择',
    isEmpty: (v) => !Array.isArray(v) || v.length === 0,
  },
  {
    key: 'position',
    tab: 'basic',
    message: '职位未选择',
    isEmpty: (v) => !v || Number(v) === 0,
  },
  {
    key: 'position_level',
    tab: 'basic',
    message: '职级未选择',
    isEmpty: (v) => !v || Number(v) === 0,
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
    staff_name: '',
    avatar: DEFAULT_AVATARS.male,
    uid: 0,
    account: '',
    pwd: '',
    phone: '',
    work_member_id: '',
    roles: [],
    position: 0,
    position_level: 0,
    is_manager: 0,
    is_customer: 0,
    can_choose: 1,
    is_reservable: 1,
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
  name: 'clerkListAdd',
  components: { uploadPictures, customerInfo },
  props: {
    value: { type: Boolean, default: false },
    editId: { type: Number, default: 0 },
    currentStoreId: { type: Number, default: 0 },
    currentStoreName: { type: String, default: '' },
  },
  data() {
    const validateUpload = (rule, value, callback) => {
      if (!this.formInline.avatar) {
        callback(new Error('请设置店员头像'));
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
    const validatePosition = (rule, value, callback) => {
      if (!value || Number(value) === 0) {
        callback(new Error('请选择职位'));
      } else {
        callback();
      }
    };
    const validatePositionLevel = (rule, value, callback) => {
      if (!value || Number(value) === 0) {
        callback(new Error('请选择职级'));
      } else {
        callback();
      }
    };
    return {
      activeTab: 'basic',
      modalPic: false,
      isChoice: '单选',
      picTit: '',
      userName: '',
      hasMallAvatar: false,
      modalUser: false,
      roleList: [],
      positionData: [],
      positionLevelData: [],
      workList: [],
      defaultAvatars: { ...DEFAULT_AVATARS },
      formInline: getDefaultStaffForm(),
      ruleValidate: {
        staff_name: [{ required: true, message: '请输入店员名称', trigger: 'blur' }],
        avatar: [{ required: true, validator: validateUpload, trigger: 'change' }],
        phone: [{ required: true, validator: validatePhone, trigger: 'blur' }],
        roles: [{ required: true, message: '请选择店员权限', trigger: 'change', type: 'array' }],
        position: [{ required: true, validator: validatePosition, trigger: 'change' }],
        position_level: [{ required: true, validator: validatePositionLevel, trigger: 'change' }],
        customer_url: [{ validator: validateUrl, trigger: 'change' }],
      },
    };
  },
  computed: {
    ...mapState('store/layout', ['isMobile']),
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  watch: {
    value(val) {
      if (val) {
        this.openForm();
      }
    },
  },
  created() {
    this.positionList();
    this.positionLevelList();
    this.workMember();
  },
  methods: {
    getDefaultForm() {
      const form = getDefaultStaffForm();
      form.store_id = this.currentStoreId || '';
      return form;
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
      this.userName = '';
      this.hasMallAvatar = false;
      this.formInline = this.getDefaultForm();
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
      this.resetForm();
      this.loadRoles();
      if (this.editId > 0) {
        this.staffInfo();
      }
    },
    loadRoles() {
      systemRoleList(this.currentStoreId)
        .then((res) => {
          this.roleList = res.data || [];
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    staffInfo() {
      getStaffInfo(this.editId)
        .then((res) => {
          const info = res.data.ps_info || {};
          // 兼容期：历史管家等同店长展示
          if (Number(info.is_butler) === 1) {
            info.is_manager = 1;
          }
          delete info.is_butler;
          this.formInline = { ...this.getDefaultForm(), ...info };
          this.userName = info.nickname || '';
          this.formInline.pwd = '';
          this.formInline.store_id = this.currentStoreId || Number(this.formInline.store_id) || '';
          if (this.formInline.roles && this.formInline.roles.length) {
            this.formInline.roles = this.formInline.roles.map(String);
          }
          DATE_FIELDS.forEach((field) => {
            this.formInline[field] = this.normalizeDate(this.formInline[field]);
          });
          this.hasMallAvatar = !!(this.formInline.uid && this.formInline.avatar && !this.isDefaultAvatar(this.formInline.avatar));
          if (!this.formInline.avatar) {
            this.formInline.avatar = this.defaultAvatars.male;
          }
          this.loadRoles();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
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
      this.hasMallAvatar = false;
      this.$refs.formInline && this.$refs.formInline.validateField(this.picTit);
    },
    delCustomer() {
      this.formInline.uid = 0;
      this.userName = '';
      this.hasMallAvatar = false;
      this.formInline.avatar = this.defaultAvatars.male;
    },
    customer() {
      this.modalUser = true;
    },
    imageObject(e) {
      this.formInline.uid = e.uid;
      this.userName = e.name;
      if (e.image) {
        this.formInline.avatar = e.image;
        this.hasMallAvatar = true;
      } else {
        this.hasMallAvatar = false;
        if (!this.formInline.avatar || this.isDefaultAvatar(this.formInline.avatar)) {
          this.formInline.avatar = this.defaultAvatars.male;
        }
      }
      this.modalUser = false;
    },
    positionList() {
      position()
        .then((res) => {
          this.positionData = res.data || [];
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    positionLevelList() {
      positionLevel()
        .then((res) => {
          this.positionLevelData = res.data || [];
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
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
    buildSubmitPayload() {
      const payload = { ...this.formInline };
      payload.store_id = this.currentStoreId || payload.store_id;
      DATE_FIELDS.forEach((field) => {
        payload[field] = payload[field] ? payload[field] : null;
      });
      if (!payload.pwd) {
        delete payload.pwd;
      }
      return payload;
    },
    handleSubmit() {
      const first = findFirstRequiredError(REQUIRED_CHECK_ORDER, this.formInline);
      if (first) {
        if (first.tab) this.activeTab = first.tab;
        this.$Message.required(first.message);
        this.$nextTick(() => {
          this.$refs.formInline && this.$refs.formInline.validateField(first.key);
        });
        return;
      }
      const payload = this.buildSubmitPayload();
      if (this.isDefaultAvatar(payload.avatar) || !payload.avatar) {
        payload.avatar = this.isSameAvatar(payload.avatar, this.defaultAvatars.female)
          || /avatar_female/i.test(payload.avatar || '')
          ? '/static/images/staff/avatar_female.png'
          : '/static/images/staff/avatar_male.png';
      }
      postStaff(payload, this.editId)
        .then((res) => {
          this.$Message.success(res.msg);
          this.$emit('success');
          this.handleClose();
        })
        .catch((err) => {
          const msg = (err && err.msg) || '保存失败';
          if (/未填写|未选择|未设置|未上传|请选择|请填写|请输入|必填/.test(msg)) {
            this.$Message.required(msg);
          } else {
            this.$Message.error(msg);
          }
        });
    },
  },
};
</script>

<style scoped lang="stylus">
/deep/.ivu-select-item, /deep/.ivu-select-input
  font-size 12px !important

.tips
  font-size 12px
  color #999
  margin-top 6px

.ml14
  margin-left 14px

.ml10
  margin-left 10px

.link-text
  color #2d8cf0
  cursor pointer
  margin-left 10px

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
</style>
