<script setup>
import CircleUserRound from '@lucide/vue/dist/esm/icons/circle-user-round.mjs'
import { computed, nextTick, reactive, ref, watch } from 'vue'

/**
 * 会员选择大弹窗内的建档步骤，不提供自己的遮罩或弹窗。
 *
 * onSubmit(payload) 只接收用户录入的资料。归属门店和组织必须由后端根据当前
 * 登录上下文强制确定，不能信任客户端传值。推荐返回：
 * - 成功：{ result: { status: 'succeeded' }, data: { member } }
 * - 手机号冲突：{ result: { status: 'conflict', code, message },
 *   data: { existingMember } }
 * - 结果未知：{ result: { status: 'result_unknown', message } }
 *
 * 创建成功后组件触发 created；调用方再使用现有 selectorContext 对应的会员选择
 * 命令完成收银工作台回填。组件不会在浏览器伪造会员或直接改写收银状态。
 */
const props = defineProps({
  currentStoreName: {
    type: String,
    default: ''
  },
  profileFields: {
    type: Array,
    default: () => []
  },
  memberLevels: {
    type: Array,
    default: () => []
  },
  memberTags: {
    type: Array,
    default: () => []
  },
  onSubmit: {
    type: Function,
    default: null
  },
  onSelectServicePerson: {
    type: Function,
    default: null
  },
  initialKeyword: {
    type: String,
    default: ''
  },
  initialProfileMode: {
    type: String,
    default: 'quick'
  },
  lockProfileMode: {
    type: Boolean,
    default: false
  },
  cancelLabel: {
    type: String,
    default: '返回查询'
  },
  submitLabel: {
    type: String,
    default: '保存并选择'
  },
  allowSelectExisting: {
    type: Boolean,
    default: true
  },
  mode: {
    type: String,
    default: 'create'
  },
  initialMember: {
    type: Object,
    default: null
  }
})

const emit = defineEmits(['cancel', 'created', 'select-existing', 'mode-change'])

const PHONE_PATTERN = /^1[3-9]\d{9}$/
const BUILTIN_PROFILE_PARAMS = new Set([
  'avatar',
  'real_name',
  'realname',
  'name',
  'nickname',
  'phone',
  'mobile',
  'sex',
  'gender',
  'birthday',
  'birth_date',
  'card_id',
  'id_card',
  'address',
  'addres',
  'level',
  'level_id',
  'label_id',
  'label_ids',
  'tag_ids',
  'store_id',
  'organization_id',
  'salesman_id',
  'exclusive_service_person_id',
  'mark',
  'remark',
  'note'
])

const panelRef = ref(null)
const fullProfile = ref(props.initialProfileMode === 'full')
const activeTab = ref('basic')
const isSubmitting = ref(false)
const isSelectingServicePerson = ref(false)
const isOutcomeUncertain = ref(false)
const submitMessage = ref('')
const submitMessageKind = ref('')
const fieldErrors = ref({})
const existingMember = ref(null)
const avatarFile = ref(null)
const avatarPreview = ref('')
const customValues = ref({})
const servicePerson = ref(null)

const form = reactive({
  name: '',
  phone: '',
  sex: '',
  birthday: '',
  idCard: '',
  address: '',
  memberLevelId: '',
  memberTagIds: [],
  note: ''
})

const normalizedLevels = computed(() => normalizeOptions(props.memberLevels))
const normalizedTags = computed(() => normalizeOptions(props.memberTags))
const visibleProfileFields = computed(() => props.profileFields
  .filter((field) => field && typeof field === 'object' && fieldIsEnabled(field))
  .filter((field) => !BUILTIN_PROFILE_PARAMS.has(profileParam(field).toLowerCase()))
  .map((field, index) => normalizeProfileField(field, index)))
const servicePersonName = computed(() => firstText(servicePerson.value, [
  'name',
  'staffName',
  'employeeName',
  'realName',
  'nickname'
]) || '')
const canSubmit = computed(() => !isSubmitting.value && !isOutcomeUncertain.value)
const isEditing = computed(() => props.mode === 'edit')
const phoneReadonly = computed(() => isEditing.value)
watch(
  () => props.initialKeyword,
  (value) => applyInitialKeyword(value),
  { immediate: true }
)

watch(
  () => props.initialMember,
  (member) => initializeMember(member),
  { immediate: true }
)

watch(
  visibleProfileFields,
  (fields) => {
    const next = { ...customValues.value }
    fields.forEach((field) => {
      if (next[field.key] === undefined || next[field.key] === null) {
        next[field.key] = cloneFieldValue(field.defaultValue)
      }
    })
    customValues.value = next
  },
  { immediate: true }
)

function applyInitialKeyword(value) {
  const keyword = String(value || '').trim()
  if (!keyword) return
  if (/^\d{11}$/.test(keyword)) {
    if (!form.phone) form.phone = keyword
    return
  }
  if (!form.name) form.name = keyword
}

function initializeMember(member) {
  if (!member || typeof member !== 'object') return
  form.name = firstText(member, ['name', 'realName', 'memberName'])
  form.phone = firstText(member, ['phone', 'mobile'])
  form.sex = firstValue(member, ['sex', 'gender']) ?? ''
  form.birthday = firstText(member, ['birthday', 'birthDate'])
  form.idCard = firstText(member, ['idCard', 'id_card', 'cardId'])
  form.address = firstText(member, ['address', 'fullAddress'])
  form.memberLevelId = firstValue(member, ['memberLevelId', 'levelId', 'member_level_id']) ?? ''
  const tags = firstValue(member, ['memberTagIds', 'tagIds', 'labelIds', 'member_tag_ids'])
  form.memberTagIds = Array.isArray(tags) ? [...tags] : []
  form.note = firstText(member, ['note', 'remark', 'memo'])
  const service = firstValue(member, ['exclusiveServiceStaffRecord', 'exclusiveServiceStaff', 'exclusiveStaff'])
  if (service && typeof service === 'object') servicePerson.value = service
  const values = firstValue(member, ['profileFields', 'customFields', 'customFieldValues', 'custom_field_values'])
  if (Array.isArray(values)) {
    customValues.value = Object.fromEntries(values.map((field, index) => [
      String(firstValue(field, ['key', 'fieldKey', 'field_key', 'id']) || `profile-field-${index}`),
      cloneFieldValue(firstValue(field, ['value', 'displayValue', 'content']))
    ]))
  } else if (values && typeof values === 'object') {
    customValues.value = { ...values }
  }
  clearSubmitState()
}

function firstValue(source, keys) {
  if (!source || typeof source !== 'object') return undefined
  for (const key of keys) {
    const value = source[key]
    if (value !== undefined && value !== null && value !== '') return value
  }
  return undefined
}

function firstText(source, keys) {
  const value = firstValue(source, keys)
  return value === undefined ? '' : String(value).trim()
}

function normalizeOptions(source) {
  const list = Array.isArray(source) ? source : []
  return list.map((item, index) => {
    if (item && typeof item === 'object') {
      const value = firstValue(item, ['value', 'id', 'levelId', 'tagId', 'labelId', 'key', 'code'])
      const label = firstText(item, ['label', 'name', 'levelName', 'tagName', 'title', 'text'])
      return {
        value: value === undefined ? String(index) : value,
        label: label || String(value ?? index)
      }
    }
    return { value: item, label: String(item ?? '') }
  }).filter((item) => item.label)
}

function profileParam(field) {
  return String(firstValue(field, ['param', 'fieldParam', 'builtinKey']) || '')
}

function fieldIsEnabled(field) {
  if (Object.prototype.hasOwnProperty.call(field, 'use') && !truthy(field.use)) return false
  if (Object.prototype.hasOwnProperty.call(field, 'enabled') && !truthy(field.enabled)) return false
  if (Object.prototype.hasOwnProperty.call(field, 'isEnabled') && !truthy(field.isEnabled)) return false
  if (Object.prototype.hasOwnProperty.call(field, 'is_enabled') && !truthy(field.is_enabled)) return false
  return true
}

function truthy(value) {
  if (typeof value === 'string') return !['0', 'false', 'off', 'disabled', 'no'].includes(value.trim().toLowerCase())
  return Boolean(value)
}

function normalizeProfileField(field, index) {
  // UserServices::handelExtendInfo persists extension values by `param`, or
  // by `info` for older custom fields without a param. Prefer those durable
  // storage keys over admin configuration ids so an edit can read back.
  const key = String(firstValue(field, ['param', 'fieldParam', 'builtinKey', 'fieldKey', 'field_key', 'key', 'info', 'id']) || `profile-field-${index}`)
  const format = String(firstValue(field, ['format', 'fieldType', 'field_type', 'type', 'controlType']) || 'text').toLowerCase()
  const label = firstText(field, ['info', 'label', 'name', 'title', 'fieldName']) || '档案字段'
  return {
    key,
    label,
    format,
    required: truthy(firstValue(field, ['required', 'isRequired', 'is_required', 'must']) || false),
    placeholder: firstText(field, ['tip', 'placeholder', 'hint']) || `${isChoiceField(format) ? '请选择' : '请输入'}${label}`,
    options: normalizeFieldOptions(field),
    defaultValue: firstValue(field, ['value', 'defaultValue', 'default_value']) ?? defaultFieldValue(format)
  }
}

function normalizeFieldOptions(field) {
  const source = firstValue(field, ['options', 'singlearr', 'optionList', 'option_list', 'items'])
  if (Array.isArray(source)) {
    return source.map((item, index) => {
      if (item && typeof item === 'object') {
        const value = firstValue(item, ['value', 'id', 'key', 'code'])
        return {
          value: value === undefined ? index : value,
          label: firstText(item, ['label', 'name', 'title', 'text']) || String(value ?? index)
        }
      }
      return { value: index, label: String(item ?? '') }
    }).filter((item) => item.label)
  }
  if (source && typeof source === 'object') {
    return Object.entries(source).map(([value, label]) => ({ value, label: String(label ?? value) }))
  }
  if (typeof source === 'string') {
    return source.split(/[,，|]/).map((label, index) => ({ value: index, label: label.trim() })).filter((item) => item.label)
  }
  return []
}

function defaultFieldValue(format) {
  return isMultipleField(format) ? [] : ''
}

function cloneFieldValue(value) {
  return Array.isArray(value) ? [...value] : value
}

function isChoiceField(format) {
  return ['radio', 'select', 'dropdown', 'checkbox', 'checks', 'multiple', 'multi_select', 'multiselect'].includes(format)
}

function isMultipleField(format) {
  return ['checkbox', 'checks', 'multiple', 'multi_select', 'multiselect'].includes(format)
}

function isWideField(field) {
  return ['textarea', 'longtext', 'radio', 'checkbox', 'checks', 'multiple', 'multi_select', 'multiselect'].includes(field.format)
}

function inputType(field) {
  if (['num', 'number', 'integer', 'decimal'].includes(field.format)) return 'number'
  if (['mail', 'email'].includes(field.format)) return 'email'
  if (field.format === 'phone') return 'tel'
  if (field.format === 'date') return 'date'
  if (['datetime', 'date_time'].includes(field.format)) return 'datetime-local'
  return 'text'
}

function shouldUseTextInput(field) {
  return ![
    'textarea',
    'longtext',
    'radio',
    'select',
    'dropdown',
    'checkbox',
    'checks',
    'multiple',
    'multi_select',
    'multiselect'
  ].includes(field.format)
}

function toggleFullProfile() {
  if (props.lockProfileMode || isSubmitting.value || isOutcomeUncertain.value) return
  fullProfile.value = !fullProfile.value
  activeTab.value = 'basic'
  clearSubmitState()
  emit('mode-change', fullProfile.value ? 'full' : 'quick')
}

function chooseTab(tab) {
  activeTab.value = tab
}

function clearSubmitState() {
  submitMessage.value = ''
  submitMessageKind.value = ''
  existingMember.value = null
  fieldErrors.value = {}
}

function clearFieldError(key) {
  if (!fieldErrors.value[key]) return
  const next = { ...fieldErrors.value }
  delete next[key]
  fieldErrors.value = next
}

function onAvatarChange(event) {
  const file = event.target.files?.[0] || null
  if (!file) return
  if (!String(file.type || '').startsWith('image/')) {
    fieldErrors.value = { ...fieldErrors.value, avatar: '请选择图片文件' }
    event.target.value = ''
    return
  }
  avatarFile.value = file
  clearFieldError('avatar')
  const reader = new FileReader()
  reader.onload = () => {
    avatarPreview.value = typeof reader.result === 'string' ? reader.result : ''
  }
  reader.readAsDataURL(file)
}

function removeAvatar() {
  avatarFile.value = null
  avatarPreview.value = ''
  clearFieldError('avatar')
}

async function selectServicePerson() {
  if (isSelectingServicePerson.value || isSubmitting.value || isOutcomeUncertain.value) return
  if (typeof props.onSelectServicePerson !== 'function') {
    submitMessageKind.value = 'error'
    submitMessage.value = '暂未接入专属服务人选择。'
    return
  }
  isSelectingServicePerson.value = true
  submitMessage.value = ''
  try {
    const selection = await props.onSelectServicePerson({
      selectedRecord: servicePerson.value,
      currentStoreName: props.currentStoreName
    })
    const record = normalizeSelectedPerson(selection)
    if (record) servicePerson.value = record
  } catch (error) {
    submitMessageKind.value = 'error'
    submitMessage.value = readableError(error, '选择专属服务人失败，请稍后重试。')
  } finally {
    isSelectingServicePerson.value = false
  }
}

function normalizeSelectedPerson(selection) {
  if (!selection) return null
  if (Array.isArray(selection)) return selection[0] || null
  if (selection.record && typeof selection.record === 'object') return selection.record
  if (selection.selectedRecord && typeof selection.selectedRecord === 'object') return selection.selectedRecord
  if (Array.isArray(selection.records)) return selection.records[0] || null
  if (selection.data?.record && typeof selection.data.record === 'object') return selection.data.record
  return selection && typeof selection === 'object' ? selection : null
}

function clearServicePerson() {
  if (isSubmitting.value || isOutcomeUncertain.value) return
  servicePerson.value = null
}

function servicePersonId() {
  return firstValue(servicePerson.value, ['id', 'staffId', 'employeeId', 'userId']) ?? null
}

function isEmpty(value) {
  if (Array.isArray(value)) return value.length === 0
  return value === undefined || value === null || String(value).trim() === ''
}

function validateForm() {
  const errors = {}
  if (!form.name.trim()) errors.name = '请填写会员姓名'
  if (!form.phone.trim()) errors.phone = '请填写手机号'
  else if (!PHONE_PATTERN.test(form.phone.trim())) errors.phone = '请填写正确的手机号'

  if (fullProfile.value) {
    visibleProfileFields.value.forEach((field) => {
      if (field.required && isEmpty(customValues.value[field.key])) {
        errors[`profile.${field.key}`] = field.placeholder || `请填写${field.label}`
      }
    })
  }

  fieldErrors.value = errors
  if (!Object.keys(errors).length) return true
  if (Object.keys(errors).some((key) => key.startsWith('profile.'))) activeTab.value = 'archive'
  else activeTab.value = 'basic'
  nextTick(() => focusFirstInvalidField())
  return false
}

function focusFirstInvalidField() {
  const firstKey = Object.keys(fieldErrors.value)[0]
  if (!firstKey || !panelRef.value) return
  const field = panelRef.value.querySelector(`[data-error-key="${escapeAttribute(firstKey)}"]`)
  field?.querySelector('input, select, textarea, button')?.focus()
}

function escapeAttribute(value) {
  return String(value).replace(/["\\]/g, '\\$&')
}

function buildPayload() {
  const quick = {
    profileMode: 'quick',
    name: form.name.trim(),
    phone: form.phone.trim()
  }
  if (!fullProfile.value) return quick

  const profileValues = {}
  visibleProfileFields.value.forEach((field) => {
    profileValues[field.key] = cloneFieldValue(customValues.value[field.key])
  })

  return {
    profileMode: 'full',
    name: form.name.trim(),
    phone: form.phone.trim(),
    avatarFile: avatarFile.value,
    sex: form.sex,
    birthday: form.birthday,
    idCard: form.idCard.trim(),
    address: form.address.trim(),
    memberLevelId: form.memberLevelId || null,
    memberTagIds: [...form.memberTagIds],
    exclusiveServicePersonId: servicePersonId(),
    note: form.note.trim(),
    profileFields: profileValues
  }
}

async function submitMember() {
  if (!canSubmit.value || !validateForm()) return
  if (typeof props.onSubmit !== 'function') {
    submitMessageKind.value = 'error'
    submitMessage.value = '暂未接入会员建档服务。'
    return
  }

  isSubmitting.value = true
  clearSubmitState()
  const payload = buildPayload()
  try {
    const response = await props.onSubmit(payload)
    handleSubmitResponse(response, payload)
  } catch (error) {
    handleSubmitResponse(error?.response?.data || error, payload, true)
  } finally {
    isSubmitting.value = false
  }
}

function handleSubmitResponse(response, payload, thrown = false) {
  const outcome = normalizeOutcome(response, thrown)
  applyServerFieldErrors(outcome.fieldErrors)

  if (outcome.existingMember) {
    existingMember.value = outcome.existingMember
    submitMessageKind.value = 'warning'
    submitMessage.value = props.allowSelectExisting
      ? (outcome.message || '该手机号已存在会员，请直接选择已有会员。')
      : '该手机号已存在会员，不能重复建档。'
    return
  }

  if (outcome.status === 'result_unknown' || outcome.status === 'pending') {
    isOutcomeUncertain.value = true
    submitMessageKind.value = 'warning'
    submitMessage.value = outcome.message || '建档结果正在确认，请勿重复提交。'
    return
  }

  if (outcome.status !== 'succeeded') {
    submitMessageKind.value = 'error'
    submitMessage.value = outcome.message || '会员建档失败，请检查后重试。'
    return
  }

  submitMessageKind.value = 'success'
  submitMessage.value = outcome.message || '会员建档成功。'
  emit('created', {
    member: outcome.member,
    response,
    payload
  })
}

function normalizeOutcome(response, thrown = false) {
  const source = response && typeof response === 'object' ? response : {}
  const data = source.data && typeof source.data === 'object' ? source.data : {}
  // ThinkPHP 的成功响应会把 V3 信封放在 data 内；页面组件也要读取
  // 该层，才能与桥接层的结果判断保持一致。
  const result = source.result && typeof source.result === 'object'
    ? source.result
    : (data.result && typeof data.result === 'object' ? data.result : {})
  const nestedData = data.data && typeof data.data === 'object' ? data.data : {}
  const rawStatus = firstText(result, ['status']) || firstText(data, ['status']) || firstText(source, ['status'])
  const code = firstText(result, ['code']) || firstText(data, ['code', 'errorCode']) || firstText(source, ['code', 'errorCode'])
  const status = normalizeStatus(rawStatus, source, thrown)
  const existing = firstObject([
    data.existingMember,
    nestedData.existingMember,
    source.existingMember,
    source.conflictingMember,
    data.conflictingMember,
    source.conflict?.existingMember,
    source.conflict?.conflictingMember,
    result.conflict?.existingMember,
    result.conflict?.conflictingMember,
    data.conflict?.existingMember,
    data.conflict?.conflictingMember
  ])
  const duplicate = /PHONE.*(EXIST|DUPLICATE|CONFLICT)|MEMBER.*(EXIST|DUPLICATE)/i.test(code)
  const member = firstObject([
    data.member,
    nestedData.member,
    source.member,
    status === 'succeeded' && looksLikeMember(data) ? data : null,
    status === 'succeeded' && looksLikeMember(source) ? source : null
  ])
  return {
    status,
    code,
    message: firstText(result, ['message']) || firstText(data, ['message', 'msg', 'errorMessage']) || firstText(source, ['message', 'msg', 'errorMessage']) || '',
    member,
    existingMember: existing || (duplicate && looksLikeMember(data) ? data : null),
    fieldErrors: source.fieldErrors || data.fieldErrors || result.fieldErrors || {}
  }
}

function normalizeStatus(rawStatus, source, thrown) {
  const status = String(rawStatus || '').trim().toLowerCase()
  if (['succeeded', 'success', 'completed', 'created', '成功', '已完成'].includes(status)) return 'succeeded'
  if (['processing', 'pending', 'pending_confirmation', 'confirming', '处理中', '待确认'].includes(status)) return 'pending'
  if (['result_unknown', 'unknown', '结果未知'].includes(status)) return 'result_unknown'
  if (['failed', 'failure', 'error', 'conflict', '失败', '冲突'].includes(status)) return 'failed'
  if (source?.success === true || source?.ok === true || looksLikeMember(source?.member) || looksLikeMember(source?.data?.member)) return 'succeeded'
  if (source === true) return 'succeeded'
  return thrown || source?.success === false || source?.ok === false ? 'failed' : 'failed'
}

function firstObject(candidates) {
  return candidates.find((candidate) => candidate && typeof candidate === 'object' && !Array.isArray(candidate)) || null
}

function looksLikeMember(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false
  return Boolean(firstValue(value, ['id', 'memberId', 'uid', 'memberNo', 'phone']))
}

function applyServerFieldErrors(errors) {
  if (!errors || typeof errors !== 'object' || Array.isArray(errors)) return
  const next = { ...fieldErrors.value }
  Object.entries(errors).forEach(([key, value]) => {
    const normalizedKey = ['real_name', 'memberName'].includes(key)
      ? 'name'
      : ['mobile'].includes(key)
        ? 'phone'
        : key.startsWith('profileFields.')
          ? key.replace('profileFields.', 'profile.')
          : key
    next[normalizedKey] = Array.isArray(value) ? String(value[0] || '') : String(value || '')
  })
  fieldErrors.value = next
}

function readableError(error, fallback) {
  if (!error) return fallback
  return error.message || error.msg || error.errorMessage || fallback
}

function chooseExistingMember() {
  if (!existingMember.value) return
  emit('select-existing', existingMember.value)
}

function cancelCreation() {
  if (isSubmitting.value || isOutcomeUncertain.value) return
  emit('cancel')
}
</script>

<template>
  <section ref="panelRef" class="member-creator" :aria-label="isEditing ? '编辑会员资料' : '新增会员资料'">
    <div class="member-creator__body">
      <template v-if="!fullProfile">
        <div class="member-creator__tabs-bar member-creator__tabs-bar--quick">
          <button v-if="!lockProfileMode" type="button" class="member-creator__button member-creator__button--primary member-creator__profile-toggle" :disabled="isSubmitting || isOutcomeUncertain" @click="toggleFullProfile">
            录入全部资料
          </button>
        </div>
        <div class="member-creator__quick">
        <div class="member-creator__quick-fields">
          <label class="member-creator__field" data-error-key="name">
            <span class="member-creator__label">会员姓名 <b>*</b></span>
            <input
              v-model="form.name"
              type="text"
              autocomplete="name"
              maxlength="50"
              placeholder="请输入会员姓名"
              :aria-invalid="Boolean(fieldErrors.name)"
              :disabled="isSubmitting || isOutcomeUncertain"
              @input="clearFieldError('name')"
            >
            <small v-if="fieldErrors.name" class="member-creator__field-error">{{ fieldErrors.name }}</small>
          </label>

          <label class="member-creator__field" data-error-key="phone">
            <span class="member-creator__label">手机号 <b>*</b></span>
            <input
              v-model="form.phone"
              type="tel"
              autocomplete="tel"
              inputmode="numeric"
              maxlength="11"
              placeholder="请输入手机号"
              :aria-invalid="Boolean(fieldErrors.phone)"
              :disabled="phoneReadonly || isSubmitting || isOutcomeUncertain"
              @input="clearFieldError('phone')"
            >
            <small v-if="fieldErrors.phone" class="member-creator__field-error">{{ fieldErrors.phone }}</small>
          </label>
        </div>
        <div v-if="currentStoreName" class="member-creator__store-note">
          <span>归属门店</span>
          <strong>{{ currentStoreName }}</strong>
        </div>
        </div>
      </template>

      <template v-else>
        <div class="member-creator__tabs-bar">
          <div class="member-creator__tabs" role="tablist" aria-label="新增会员资料">
            <button
              type="button"
              role="tab"
              :aria-selected="activeTab === 'basic'"
              :class="{ 'member-creator__tab--active': activeTab === 'basic' }"
              @click="chooseTab('basic')"
            >
              基本信息
            </button>
            <button
              type="button"
              role="tab"
              :aria-selected="activeTab === 'archive'"
              :class="{ 'member-creator__tab--active': activeTab === 'archive' }"
              @click="chooseTab('archive')"
            >
              档案信息
              <span v-if="visibleProfileFields.length" class="member-creator__tab-count">{{ visibleProfileFields.length }}</span>
            </button>
          </div>
          <button v-if="!lockProfileMode" type="button" class="member-creator__button member-creator__button--primary member-creator__profile-toggle" :disabled="isSubmitting || isOutcomeUncertain" @click="toggleFullProfile">
            收起完整资料
          </button>
        </div>

        <div class="member-creator__tab-body">
          <div v-show="activeTab === 'basic'" class="member-creator__basic" role="tabpanel">
            <div class="member-creator__form-grid">
              <label class="member-creator__field" data-error-key="name">
                <span class="member-creator__label">会员姓名 <b>*</b></span>
                <input v-model="form.name" type="text" autocomplete="name" maxlength="50" placeholder="请输入会员姓名" :aria-invalid="Boolean(fieldErrors.name)" :disabled="isSubmitting || isOutcomeUncertain" @input="clearFieldError('name')">
                <small v-if="fieldErrors.name" class="member-creator__field-error">{{ fieldErrors.name }}</small>
              </label>

              <label class="member-creator__field" data-error-key="phone">
                <span class="member-creator__label">手机号 <b>*</b></span>
                <input v-model="form.phone" type="tel" autocomplete="tel" inputmode="numeric" maxlength="11" placeholder="请输入手机号" :aria-invalid="Boolean(fieldErrors.phone)" :disabled="phoneReadonly || isSubmitting || isOutcomeUncertain" @input="clearFieldError('phone')">
                <small v-if="fieldErrors.phone" class="member-creator__field-error">{{ fieldErrors.phone }}</small>
              </label>

              <label class="member-creator__field">
                <span class="member-creator__label">性别</span>
                <select v-model="form.sex" :disabled="isSubmitting || isOutcomeUncertain">
                  <option value="">请选择</option>
                  <option :value="0">保密</option>
                  <option :value="1">男</option>
                  <option :value="2">女</option>
                </select>
              </label>

              <label class="member-creator__field">
                <span class="member-creator__label">生日</span>
                <input v-model="form.birthday" type="date" :disabled="isSubmitting || isOutcomeUncertain">
              </label>

              <label class="member-creator__field">
                <span class="member-creator__label">身份证号</span>
                <input v-model="form.idCard" type="text" maxlength="30" placeholder="请输入身份证号" :disabled="isSubmitting || isOutcomeUncertain">
              </label>

              <label class="member-creator__field">
                <span class="member-creator__label">详细地址</span>
                <input v-model="form.address" type="text" maxlength="200" placeholder="请输入详细地址" :disabled="isSubmitting || isOutcomeUncertain">
              </label>

              <label class="member-creator__field">
                <span class="member-creator__label">会员等级</span>
                <select v-model="form.memberLevelId" :disabled="isSubmitting || isOutcomeUncertain">
                  <option value="">请选择</option>
                  <option v-for="level in normalizedLevels" :key="String(level.value)" :value="level.value">{{ level.label }}</option>
                </select>
              </label>

              <div class="member-creator__field member-creator__field--readonly">
                <span class="member-creator__label">归属门店</span>
                <div class="member-creator__readonly-value">{{ currentStoreName || '当前登录门店' }}</div>
              </div>

              <div class="member-creator__field">
                <span class="member-creator__label">专属服务人</span>
                <div class="member-creator__selector-field">
                  <button type="button" :disabled="isSelectingServicePerson || isSubmitting || isOutcomeUncertain" @click="selectServicePerson">
                    {{ isSelectingServicePerson ? '选择中…' : servicePersonName || '选择人员' }}
                  </button>
                  <button v-if="servicePersonName" type="button" class="member-creator__selector-clear" aria-label="清空专属服务人" :disabled="isSubmitting || isOutcomeUncertain" @click="clearServicePerson">×</button>
                </div>
              </div>

              <fieldset class="member-creator__field member-creator__tag-field">
                <legend class="member-creator__label">会员标签</legend>
                <div v-if="normalizedTags.length" class="member-creator__tag-options">
                  <label v-for="tag in normalizedTags" :key="String(tag.value)">
                    <input v-model="form.memberTagIds" type="checkbox" :value="tag.value" :disabled="isSubmitting || isOutcomeUncertain">
                    <span>{{ tag.label }}</span>
                  </label>
                </div>
                <div v-else class="member-creator__readonly-value">暂无可选标签</div>
              </fieldset>

              <label class="member-creator__field member-creator__note-field">
                <span class="member-creator__label">备注</span>
                <textarea v-model="form.note" rows="2" maxlength="500" placeholder="请输入备注" :disabled="isSubmitting || isOutcomeUncertain" />
              </label>

              <div class="member-creator__field member-creator__avatar-field" data-error-key="avatar">
                <span class="member-creator__label">会员头像</span>
                <div class="member-creator__avatar-control">
                  <div class="member-creator__avatar" :class="{ 'member-creator__avatar--filled': avatarPreview }">
                    <img v-if="avatarPreview" :src="avatarPreview" alt="会员头像预览">
                    <CircleUserRound v-else :size="32" :stroke-width="1.6" aria-hidden="true" />
                  </div>
                  <div class="member-creator__avatar-actions">
                    <label class="member-creator__file-button">
                      选择头像
                      <input type="file" accept="image/*" :disabled="isSubmitting || isOutcomeUncertain" @change="onAvatarChange">
                    </label>
                    <button v-if="avatarPreview" type="button" class="member-creator__text-button" :disabled="isSubmitting || isOutcomeUncertain" @click="removeAvatar">移除</button>
                    <small v-if="fieldErrors.avatar" class="member-creator__field-error">{{ fieldErrors.avatar }}</small>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-show="activeTab === 'archive'" class="member-creator__archive" role="tabpanel">
            <div v-if="!visibleProfileFields.length" class="member-creator__empty">
              当前没有已启用的档案字段
            </div>
            <div v-else class="member-creator__form-grid member-creator__archive-grid">
              <div
                v-for="field in visibleProfileFields"
                :key="field.key"
                class="member-creator__field"
                :class="{ 'member-creator__field--wide': isWideField(field) }"
                :data-error-key="`profile.${field.key}`"
              >
                <span class="member-creator__label">{{ field.label }} <b v-if="field.required">*</b></span>

                <textarea
                  v-if="['textarea', 'longtext'].includes(field.format)"
                  v-model="customValues[field.key]"
                  rows="3"
                  :placeholder="field.placeholder"
                  :aria-invalid="Boolean(fieldErrors[`profile.${field.key}`])"
                  :disabled="isSubmitting || isOutcomeUncertain"
                  @input="clearFieldError(`profile.${field.key}`)"
                />

                <select
                  v-else-if="['select', 'dropdown'].includes(field.format)"
                  v-model="customValues[field.key]"
                  :aria-invalid="Boolean(fieldErrors[`profile.${field.key}`])"
                  :disabled="isSubmitting || isOutcomeUncertain"
                  @change="clearFieldError(`profile.${field.key}`)"
                >
                  <option value="">{{ field.placeholder }}</option>
                  <option v-for="option in field.options" :key="String(option.value)" :value="option.value">{{ option.label }}</option>
                </select>

                <div v-else-if="field.format === 'radio'" class="member-creator__choice-options">
                  <label v-for="option in field.options" :key="String(option.value)">
                    <input v-model="customValues[field.key]" type="radio" :name="`profile-${field.key}`" :value="option.value" :disabled="isSubmitting || isOutcomeUncertain" @change="clearFieldError(`profile.${field.key}`)">
                    <span>{{ option.label }}</span>
                  </label>
                </div>

                <div v-else-if="isMultipleField(field.format)" class="member-creator__choice-options member-creator__choice-options--multiple">
                  <label v-for="option in field.options" :key="String(option.value)">
                    <input v-model="customValues[field.key]" type="checkbox" :value="option.value" :disabled="isSubmitting || isOutcomeUncertain" @change="clearFieldError(`profile.${field.key}`)">
                    <span>{{ option.label }}</span>
                  </label>
                </div>

                <input
                  v-else-if="shouldUseTextInput(field)"
                  v-model="customValues[field.key]"
                  :type="inputType(field)"
                  :placeholder="field.placeholder"
                  :aria-invalid="Boolean(fieldErrors[`profile.${field.key}`])"
                  :disabled="isSubmitting || isOutcomeUncertain"
                  @input="clearFieldError(`profile.${field.key}`)"
                >

                <small v-if="fieldErrors[`profile.${field.key}`]" class="member-creator__field-error">{{ fieldErrors[`profile.${field.key}`] }}</small>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <section v-if="existingMember" class="member-creator__conflict" aria-live="polite">
      <div>
        <strong>{{ existingMember.name || existingMember.realName || existingMember.nickname || '已有会员' }}</strong>
        <span>{{ existingMember.phone || form.phone }}</span>
        <span v-if="existingMember.memberNo || existingMember.barCode">会员编号 {{ existingMember.memberNo || existingMember.barCode }}</span>
      </div>
      <button v-if="allowSelectExisting" type="button" class="member-creator__button member-creator__button--primary" @click="chooseExistingMember">选择此会员</button>
    </section>

    <div v-if="submitMessage" class="member-creator__message" :class="`member-creator__message--${submitMessageKind || 'info'}`" role="status">
      {{ submitMessage }}
    </div>

    <footer class="member-creator__footer">
      <button type="button" class="member-creator__button" :disabled="isSubmitting || isOutcomeUncertain" @click="cancelCreation">{{ cancelLabel }}</button>
      <button type="button" class="member-creator__button member-creator__button--primary" :disabled="!canSubmit" @click="submitMember">
        {{ isSubmitting ? '正在保存…' : (isEditing ? '保存修改' : submitLabel) }}
      </button>
    </footer>
  </section>
</template>

<style scoped>
.member-creator,
.member-creator * {
  box-sizing: border-box;
  letter-spacing: 0;
}

.member-creator {
  display: flex;
  width: 100%;
  height: auto;
  min-height: 0;
  flex: 1 1 auto;
  flex-direction: column;
  overflow: hidden;
  background: #fff;
  color: #1f2937;
}

.member-creator__text-button {
  border: 0;
  background: transparent;
  color: #3569ad;
  cursor: pointer;
  font: inherit;
}

.member-creator__profile-toggle {
  flex: 0 0 auto;
}

.member-creator__text-button:hover:not(:disabled) {
  color: #24558f;
}

.member-creator button:focus-visible,
.member-creator input:focus-visible,
.member-creator select:focus-visible,
.member-creator textarea:focus-visible,
.member-creator__file-button:focus-within {
  outline: 2px solid #79a7df;
  outline-offset: 2px;
}

.member-creator button:disabled,
.member-creator input:disabled,
.member-creator select:disabled,
.member-creator textarea:disabled {
  cursor: not-allowed;
  opacity: 0.58;
}

.member-creator__body {
  display: flex;
  min-height: 0;
  flex: 1 1 auto;
  flex-direction: column;
  overflow: hidden;
}

.member-creator__quick {
  display: grid;
  width: min(720px, 100%);
  min-height: 330px;
  align-content: start;
  gap: 22px;
  margin: auto;
  padding: 42px 34px;
}

.member-creator__quick-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
}

.member-creator__store-note {
  display: flex;
  min-height: 44px;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 10px 14px;
  border-left: 3px solid #91add2;
  background: #f5f8fc;
  color: #667085;
  font-size: 13px;
}

.member-creator__store-note strong {
  overflow: hidden;
  color: #344054;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.member-creator__tabs-bar {
  display: flex;
  height: 48px;
  flex: 0 0 auto;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 0 24px;
  border-bottom: 1px solid #e7ebf1;
}

.member-creator__tabs {
  display: flex;
  align-self: stretch;
  align-items: stretch;
  gap: 24px;
}

.member-creator__tabs-bar--quick {
  justify-content: flex-end;
}

.member-creator__tabs button {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 0 4px;
  border: 0;
  background: transparent;
  color: #667085;
  cursor: pointer;
  font-size: 14px;
}

.member-creator__tabs button::after {
  position: absolute;
  right: 0;
  bottom: -1px;
  left: 0;
  height: 2px;
  background: transparent;
  content: '';
}

.member-creator__tabs .member-creator__tab--active {
  color: #2f5f9b;
  font-weight: 600;
}

.member-creator__tabs .member-creator__tab--active::after {
  background: #4c7fbe;
}

.member-creator__tab-count {
  display: inline-flex;
  min-width: 20px;
  height: 20px;
  align-items: center;
  justify-content: center;
  padding: 0 5px;
  border-radius: 5px;
  background: #eef2f7;
  color: #667085;
  font-size: 11px;
}

.member-creator__tab-body {
  min-height: 0;
  flex: 1 1 auto;
  overflow: hidden;
}

.member-creator__basic,
.member-creator__archive {
  height: 100%;
  overflow: auto;
  padding: 20px 24px 26px;
}

.member-creator__avatar-field {
  min-width: 0;
}

.member-creator__avatar-control {
  display: flex;
  min-height: 56px;
  align-items: center;
  gap: 12px;
}

.member-creator__avatar {
  display: grid;
  width: 56px;
  height: 56px;
  flex: 0 0 auto;
  place-items: center;
  overflow: hidden;
  border: 1px solid #d9e0e9;
  border-radius: 8px;
  background: #f4f6f9;
  color: #8290a3;
}

.member-creator__avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.member-creator__avatar-actions {
  display: flex;
  min-width: 0;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.member-creator__avatar-actions .member-creator__field-error {
  flex-basis: 100%;
}

.member-creator__file-button {
  position: relative;
  display: inline-flex;
  height: 32px;
  align-items: center;
  padding: 0 10px;
  overflow: hidden;
  border: 1px solid #c9d3df;
  border-radius: 6px;
  background: #fff;
  color: #344054;
  cursor: pointer;
  font-size: 13px;
}

.member-creator__file-button input {
  position: absolute;
  width: 1px;
  height: 1px;
  opacity: 0;
}

.member-creator__text-button {
  height: 32px;
  padding: 0 6px;
  font-size: 13px;
}

.member-creator__form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px 20px;
}

.member-creator__field {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 7px;
  margin: 0;
  padding: 0;
  border: 0;
}

.member-creator__field--wide {
  grid-column: 1 / -1;
}

.member-creator__label {
  color: #475467;
  font-size: 13px;
  font-weight: 500;
  line-height: 20px;
}

.member-creator__label b {
  color: #c2414a;
  font-weight: 500;
}

.member-creator__field input[type='text'],
.member-creator__field input[type='tel'],
.member-creator__field input[type='email'],
.member-creator__field input[type='number'],
.member-creator__field input[type='date'],
.member-creator__field input[type='datetime-local'],
.member-creator__field select,
.member-creator__field textarea,
.member-creator__selector-field {
  width: 100%;
  border: 1px solid #cfd7e3;
  border-radius: 6px;
  background: #fff;
  color: #172033;
  font: inherit;
  font-size: 14px;
}

.member-creator__field input[type='text'],
.member-creator__field input[type='tel'],
.member-creator__field input[type='email'],
.member-creator__field input[type='number'],
.member-creator__field input[type='date'],
.member-creator__field input[type='datetime-local'],
.member-creator__field select {
  height: 40px;
  padding: 0 11px;
}

.member-creator__field textarea {
  min-height: 78px;
  resize: vertical;
  padding: 9px 11px;
  line-height: 20px;
}

.member-creator__note-field textarea {
  height: 56px;
  min-height: 56px;
}

.member-creator__field input[aria-invalid='true'],
.member-creator__field select[aria-invalid='true'],
.member-creator__field textarea[aria-invalid='true'] {
  border-color: #cc6970;
  box-shadow: 0 0 0 2px rgb(204 105 112 / 10%);
}

.member-creator__readonly-value {
  display: flex;
  height: 40px;
  align-items: center;
  overflow: hidden;
  padding: 0 11px;
  border: 1px solid #e1e6ed;
  border-radius: 6px;
  background: #f5f7fa;
  color: #667085;
  font-size: 14px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.member-creator__selector-field {
  display: flex;
  height: 40px;
  align-items: stretch;
  overflow: hidden;
}

.member-creator__selector-field > button:first-child {
  min-width: 0;
  flex: 1 1 auto;
  overflow: hidden;
  padding: 0 11px;
  border: 0;
  background: transparent;
  color: #3569ad;
  cursor: pointer;
  text-align: left;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.member-creator__selector-clear {
  width: 38px;
  flex: 0 0 auto;
  border: 0;
  border-left: 1px solid #e1e6ed;
  background: #f5f7fa;
  color: #667085;
  cursor: pointer;
  font-size: 18px;
}

.member-creator__tag-field {
  min-width: 0;
}

.member-creator__tag-options,
.member-creator__choice-options {
  display: flex;
  min-height: 40px;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
  padding: 8px 10px;
  border: 1px solid #d7dee8;
  border-radius: 6px;
  background: #f8fafc;
}

.member-creator__tag-options {
  max-height: 112px;
  overflow: auto;
}

.member-creator__tag-options label,
.member-creator__choice-options label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #344054;
  cursor: pointer;
  font-size: 13px;
}

.member-creator__tag-options input,
.member-creator__choice-options input {
  width: 16px;
  height: 16px;
  margin: 0;
  accent-color: #4c78b3;
}

.member-creator__field-error {
  color: #b33d46;
  font-size: 12px;
  line-height: 18px;
}

.member-creator__empty {
  display: grid;
  min-height: 250px;
  place-items: center;
  color: #7b8798;
  font-size: 14px;
}

.member-creator__conflict {
  display: flex;
  min-height: 62px;
  flex: 0 0 auto;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  margin: 0 24px 10px;
  padding: 10px 12px;
  border: 1px solid #ead9a9;
  border-radius: 6px;
  background: #fffaf0;
}

.member-creator__conflict > div {
  display: flex;
  min-width: 0;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 12px;
}

.member-creator__conflict strong {
  color: #4b3b18;
  font-size: 14px;
}

.member-creator__conflict span {
  color: #806c3d;
  font-size: 12px;
}

.member-creator__message {
  margin: 0 24px 10px;
  padding: 8px 11px;
  border-radius: 5px;
  font-size: 13px;
  line-height: 20px;
}

.member-creator__message--error {
  background: #fdf1f2;
  color: #9b3039;
}

.member-creator__message--warning {
  background: #fff8e8;
  color: #7d6327;
}

.member-creator__message--success {
  background: #edf8f1;
  color: #287449;
}

.member-creator__message--info {
  background: #eff5fc;
  color: #3569ad;
}

.member-creator__footer {
  display: flex;
  min-height: 64px;
  flex: 0 0 auto;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  padding: 12px 24px;
  border-top: 1px solid #e7ebf1;
  background: #fafbfd;
}

.member-creator__button {
  display: inline-flex;
  min-width: 92px;
  height: 38px;
  align-items: center;
  justify-content: center;
  padding: 0 15px;
  border: 1px solid #c9d3df;
  border-radius: 6px;
  background: #fff;
  color: #344054;
  cursor: pointer;
  font: inherit;
  font-size: 14px;
}

.member-creator__button--primary {
  border-color: #4777b3;
  background: #4777b3;
  color: #fff;
}

.member-creator__button:hover:not(:disabled) {
  border-color: #9caec5;
  background: #f7f9fc;
}

.member-creator__button--primary:hover:not(:disabled) {
  border-color: #35659f;
  background: #35659f;
}

@media (max-width: 960px) {
  .member-creator__footer,
  .member-creator__basic,
  .member-creator__archive {
    padding-right: 18px;
    padding-left: 18px;
  }

  .member-creator__tabs-bar {
    padding: 0 18px;
  }

  .member-creator__conflict,
  .member-creator__message {
    margin-right: 18px;
    margin-left: 18px;
  }
}

@media (max-width: 720px) {
  .member-creator__quick {
    padding: 28px 18px;
  }

  .member-creator__quick-fields,
  .member-creator__form-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .member-creator__field--wide {
    grid-column: 1;
  }

  .member-creator__conflict {
    align-items: stretch;
    flex-direction: column;
  }

  .member-creator__conflict .member-creator__button {
    width: 100%;
  }
}
</style>
