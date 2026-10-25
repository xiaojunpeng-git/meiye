<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import ChevronDown from '@lucide/vue/dist/esm/icons/chevron-down.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import Store from '@lucide/vue/dist/esm/icons/store.mjs'
import CircleUserRound from '@lucide/vue/dist/esm/icons/circle-user-round.mjs'
import StoreFullscreenButton from './StoreFullscreenButton.vue'
import { configuredNavigationGroups } from '../../../../shared/storeNavigationGroups.js'
import { ORDER_RECORD_TYPES } from '@/constants/orderRecordTypes'
import { STORE_REPORT_TABS } from '@/constants/storeBusinessReports'
import { queryStoreBusinessReportCatalog } from '@/services/storeBusinessReportApi'
import { loadBasicReportCatalog } from '@/services/basicReportApi'

const props = defineProps({ entries: { type: Array, default: () => [] }, rules: { type: Array, default: () => [] }, storeName: String, operator: String, operatorProfile: { type: Object, default: () => ({}) }, canSwitchStores: Boolean, pendingHangCount: Number, inventoryOpen: Boolean, activeInventoryKey: String, contextId: String })
const emit = defineEmits(['navigate', 'switch-store', 'password', 'logout'])
const route = useRoute()
const root = ref(null)
const openGroup = ref('')
const menuPosition = ref({})
const reports = ref([])
const reportError = ref('')
const reportsLoading = ref(false)
const basicReports = ref([])
const basicReportError = ref('')
const basicReportsLoading = ref(false)
let requestId = 0
let basicRequestId = 0
const quickKeys = ['cashier', 'hang', 'room', 'reservation', 'member', 'fund']
// 快捷入口与同一配置树求交：岗位未授权或后台停用功能/祖先时，两处同时隐藏。
const quickEntries = computed(() => {
  const visible = new Set(groups.value.flatMap(group => group.entries.filter(row => row.entry).map(row => row.entry.key)))
  return quickKeys.map(key => props.entries.find(item => item.key === key)).filter(entry => entry && visible.has(entry.key))
})
const reportEntry = computed(() => props.entries.find(item => item.featureCode === 'cashier.v3.data'))
const basicReportEntry = computed(() => props.entries.find(item => item.navigationKey === 'report.basic'))
// 身份/路由白名单仅负责找到原处理器；名称、层级、排序、显示开关全部取配置树。
const navigationHandlers = computed(() => [
  ...props.entries.filter(entry => entry.key !== 'inventory-presale-claim' && entry.navigationKey !== 'report.basic'),
  ...props.entries.filter(entry => entry.key === 'inventory-presale-claim').map(entry => ({ ...entry, navigationKey: 'inventory.presale_claim' })),
  ...props.entries.filter(entry => entry.key === 'order').flatMap(entry => ORDER_RECORD_TYPES.map(type => ({ ...entry,
    key: `order-${type.key}`, navigationKey: `order.${type.key}`, orderType: type.key,
    to: { ...entry.to, query: { tab: type.key } } }))),
  ...(reportEntry.value ? reports.value.map(report => ({ ...reportEntry.value, key: `report-${report.code}`,
    navigationKey: `report.${report.code}`, reportCode: report.code,
    to: { name: 'cashier-v3-store-business-reports', params: { report: report.code } } })) : []),
  // 基础报表以服务端目录提供的稳定code直达原页面；层级仍由同一持久化导航树决定。
  ...(basicReportEntry.value ? basicReports.value.map(report => ({ ...basicReportEntry.value,
    key: `basic-report-${report.code}`, navigationKey: `report.${report.code}`, reportCode: report.code,
    to: { name: 'cashier-v3-basic-reports', params: { report: report.code } } })) : []),
  { key: 'account-password', navigationKey: 'account.password', accountAction: 'password' },
  { key: 'account-logout', navigationKey: 'account.logout', accountAction: 'logout' }
])
const groups = computed(() => configuredNavigationGroups(props.rules, navigationHandlers.value))
const selectedGroup = computed(() => {
  const exact = groups.value.find(group => group.entries.some(row => row.entry?.navigationKey && isActive(row.entry)))
  return exact?.key || groups.value.find(group => group.entries.some(row => row.entry && isActive(row.entry)))?.key || ''
})
// 当前位置只读取已授权、已启用的实际导航树；打开其他菜单不改变它。
// 同一路由优先定位订单类型/报表/库存子入口，再保留配置树中的祖先标题。
const currentLocation = computed(() => {
  const group = groups.value.find(item => item.key === selectedGroup.value)
  if (!group) return []
  const matches = group.entries.filter(row => row.entry && isActive(row.entry))
  const specificity = row => (row.entry.orderType || row.entry.reportCode || row.entry.inventory ? 1000 : 0) + row.depth
  const current = matches.reduce((best, row) => !best || specificity(row) > specificity(best) ? row : best, null)
  if (!current) return []
  const ancestors = []
  for (const row of group.entries) {
    ancestors.length = row.depth
    ancestors.push(row.label)
    if (row === current) return [group.label, ...ancestors]
  }
  return []
})
function isActive(entry) {
  if (entry.accountAction) return false
  if (entry.reportCode) return !props.inventoryOpen && route.name === entry.to?.name && route.params.report === entry.reportCode
  if (entry.orderType) return !props.inventoryOpen && route.name === entry.to?.name && String(route.query.tab || route.query.recordType || 'all') === entry.orderType
  if (entry.inventory && !entry.to) return props.inventoryOpen && props.activeInventoryKey === entry.inventory.key
  if (props.inventoryOpen) return false
  return (entry.activeRouteNames || [entry.to?.name]).includes(route.name)
}
function choose(entry) { openGroup.value = ''; if (entry.accountAction) emit(entry.accountAction); else emit('navigate', entry) }
// 用实际按钮边界定位：正常左对齐，宽弹层靠近视口右侧时才向左避让；不依赖菜单名称或顺序。
function positionMenu() {
  const trigger = root.value?.querySelector('.store-topbar__group[aria-expanded="true"]')
  const menu = root.value?.querySelector('.store-menu:not(.store-menu--account)')
  if (!trigger || !menu) return
  const anchor = trigger.getBoundingClientRect()
  const container = root.value.getBoundingClientRect()
  const left = Math.max(8, Math.min(anchor.left, document.documentElement.clientWidth - menu.getBoundingClientRect().width - 8))
  menuPosition.value = { left: `${left - container.left}px`, top: `${anchor.bottom - container.top}px` }
}
function resizeMenu() { nextTick(positionMenu) }
// 分类只打开菜单，不导航、不重建草稿；报表清单复用原服务目录与原页面登记清单。
async function toggle(group) {
  openGroup.value = openGroup.value === group.key ? '' : group.key
  await nextTick()
  positionMenu()
}
// 服务端报表目录仍裁剪实际可用报表；后台规则决定它们显示在哪个父菜单下。
async function loadReports() {
  if (!reportEntry.value || reportsLoading.value || reports.value.length) return
  const id = ++requestId
  reportsLoading.value = true; reportError.value = ''
  try {
    const catalog = await queryStoreBusinessReportCatalog()
    if (id !== requestId) return
    const allowed = new Set((Array.isArray(catalog) ? catalog : []).map(item => item.code))
    reports.value = STORE_REPORT_TABS.filter(item => !item.hidden && allowed.has(item.code))
  } catch (_) { if (id === requestId) reportError.value = '报表目录加载失败，可进入报表页面查看或重试。' }
  finally { if (id === requestId) reportsLoading.value = false }
}
/** 两个报表目录独立失败、独立过期，不能因新增目录失败而隐藏旧报表。 */
async function loadBasicReports() {
  if (!basicReportEntry.value || basicReportsLoading.value || basicReports.value.length) return
  const id = ++basicRequestId
  basicReportsLoading.value = true; basicReportError.value = ''
  try {
    const catalog = await loadBasicReportCatalog(false)
    if (id !== basicRequestId) return
    basicReports.value = (Array.isArray(catalog) ? catalog : []).filter(report => report.available)
  } catch (_) { if (id === basicRequestId) basicReportError.value = '基础报表目录加载失败，请刷新后重试。' }
  finally { if (id === basicRequestId) basicReportsLoading.value = false }
}
function outside(event) { if (!root.value?.contains(event.target)) openGroup.value = '' }
function escape(event) { if (event.key === 'Escape') openGroup.value = '' }
watch(() => route.fullPath, () => { openGroup.value = '' })
// 切账号/门店时丢弃旧目录和在途请求，不能把旧会话菜单带入新工作台。
watch(() => props.contextId, () => {
  requestId++; basicRequestId++; reports.value = []; basicReports.value = []
  reportsLoading.value = false; basicReportsLoading.value = false; reportError.value = ''; basicReportError.value = ''; openGroup.value = ''
  void loadReports(); void loadBasicReports()
})
watch(reportEntry, () => { void loadReports() }, { immediate: true })
watch(basicReportEntry, () => { void loadBasicReports() }, { immediate: true })
// 子菜单加载或配置改变可能撑宽面板，重新避让视口边界。
watch(groups, resizeMenu)
onMounted(() => { document.addEventListener('pointerdown', outside); document.addEventListener('keydown', escape); window.addEventListener('resize', resizeMenu) })
onBeforeUnmount(() => { requestId++; basicRequestId++; document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', escape); window.removeEventListener('resize', resizeMenu) })
</script>

<template>
  <header ref="root" class="store-navigation">
    <div class="store-topbar">
      <nav id="cashier-primary-nav" class="store-topbar__groups" aria-label="门店端主菜单" @scroll="positionMenu">
        <button v-for="group in groups" :key="group.key" type="button" class="store-topbar__group" :class="{ 'is-active': selectedGroup === group.key, 'is-open': openGroup === group.key }" :aria-expanded="openGroup === group.key" :aria-controls="'store-menu-' + group.key" @click="toggle(group)">{{ group.label }}<ChevronDown :size="13" /></button>
      </nav>
      <nav v-if="currentLocation.length" class="store-topbar__location" aria-label="当前位置" :title="currentLocation.join(' › ')">
        <ol><li v-for="(label, index) in currentLocation" :key="index" :aria-current="index === currentLocation.length - 1 ? 'page' : undefined"><span v-if="index" class="store-topbar__location-separator" aria-hidden="true">›</span><span class="store-topbar__location-label">{{ label }}</span></li></ol>
      </nav>
    </div>
    <!-- 门店与账号属于第二行上下文；顶部呈现配置菜单及当前所在层级。 -->
    <div class="store-contextbar">
      <button v-if="canSwitchStores" class="store-topbar__brand" type="button" aria-label="切换门店" @click="emit('switch-store')"><span class="store-topbar__switch-mark" aria-hidden="true"><RefreshCw :size="17" :stroke-width="1.9" /></span><strong>{{ storeName }}</strong></button>
      <div v-else class="store-topbar__brand"><Store :size="21" /><strong>{{ storeName }}</strong></div>
    <nav class="store-quickbar" aria-label="常用快捷入口">
      <button v-for="entry in quickEntries" :key="entry.key" type="button" class="cashier-nav__item" :class="{ 'cashier-nav__item--active': isActive(entry) }" :aria-current="isActive(entry) ? 'page' : undefined" @click="choose(entry)"><component :is="entry.icon" class="cashier-nav__icon" :size="20" :stroke-width="1.9" /><span class="cashier-nav__label">{{ entry.label }}</span><span v-if="entry.key === 'hang' && pendingHangCount > 0" class="cashier-nav__badge">{{ pendingHangCount }}</span></button>
    </nav>
      <StoreFullscreenButton />
      <button type="button" class="store-topbar__account store-topbar__account-button" aria-label="查看操作员信息" :aria-expanded="openGroup === 'account'" aria-controls="store-operator-profile" @click="openGroup = openGroup === 'account' ? '' : 'account'"><CircleUserRound :size="19" /><span>{{ operator }}</span></button>
    </div>
    <!-- 账号操作不属于业务授权；所属菜单大类停用时仍保留退出入口，避免锁住会话。 -->
    <!-- 本人信息只读展示；切账号或门店时仍由现有上下文监听清理浮层。 -->
    <section v-if="openGroup === 'account'" id="store-operator-profile" class="store-menu store-menu--account" aria-label="操作员信息">
      <dl class="store-operator-profile">
        <div><dt>姓名</dt><dd>{{ operatorProfile.name || operator || '未设置' }}</dd></div>
        <div><dt>岗位</dt><dd>{{ operatorProfile.positions?.length ? operatorProfile.positions.join('、') : '未设置岗位' }}</dd></div>
        <div><dt>手机号</dt><dd>{{ operatorProfile.phone || '未设置手机号' }}</dd></div>
      </dl>
      <template v-if="!groups.some(group => group.account_menu)">
      <button type="button" class="store-menu__link" @click="openGroup = ''; emit('password')">修改密码</button>
      <button type="button" class="store-menu__link" @click="openGroup = ''; emit('logout')">退出系统</button>
      </template>
    </section>
    <template v-for="group in groups" :key="group.key">
      <section v-if="openGroup === group.key" :id="'store-menu-' + group.key" :style="menuPosition" class="store-menu" :class="{ 'store-menu--has-children': group.entries.some(row => row.depth > 0) }" :aria-label="group.label + '功能'">
        <div class="store-menu__links">
          <template v-for="row in group.entries" :key="row.id + ':' + (row.entry?.key || 'heading')">
            <button v-if="row.entry" type="button" class="store-menu__link" :class="{ 'is-active': isActive(row.entry), 'store-menu__parent': row.hasChildren }" :data-rule-id="row.id" :data-depth="row.depth" :style="{ paddingLeft: 12 + row.depth * 20 + 'px' }" @click="choose(row.entry)">{{ row.label }}</button>
            <div v-else class="store-menu__heading" :data-rule-id="row.id" :data-depth="row.depth" :style="{ paddingLeft: 12 + row.depth * 20 + 'px' }">{{ row.label }}</div>
          </template>
        </div>
        <p v-if="reportsLoading && group.entries.some(row => row.entry?.featureCode === 'cashier.v3.data')" class="store-menu__hint">正在加载报表目录…</p>
        <p v-else-if="reportError && group.entries.some(row => row.entry?.featureCode === 'cashier.v3.data')" class="store-menu__hint" role="alert">{{ reportError }}</p>
        <p v-if="basicReportsLoading && group.entries.some(row => row.entry?.featureCode === 'cashier.v3.data')" class="store-menu__hint">正在加载基础报表目录…</p>
        <p v-else-if="basicReportError && group.entries.some(row => row.entry?.featureCode === 'cashier.v3.data')" class="store-menu__hint" role="alert">{{ basicReportError }}</p>
      </section>
    </template>
  </header>
</template>

<style scoped>
.store-navigation{position:relative;z-index:30;min-width:0;flex:none;background:#fff}
/* 工作区保留桌面最小宽度；顶部单独限制到视口，避免当前位置落在屏外。 */
.store-topbar{height:40px;display:flex;align-items:center;gap:24px;padding:0 20px;background:#18243a;color:#d8e2f0;box-sizing:border-box;max-width:100vw}
.store-topbar__brand{display:flex;align-items:center;gap:9px;max-width:230px;min-width:120px;background:none;border:0;color:#334861;font:inherit;white-space:nowrap}
/* 切店入口采用紧凑28px浅色方框；只改变图标外观，仍使用原切店操作。 */
.store-topbar__switch-mark{display:inline-grid;flex:none;width:28px;height:28px;place-items:center;border:1px solid #d9e4ef;border-radius:7px;background:#f0f5fb;color:#6483a5}
button.store-topbar__brand:hover .store-topbar__switch-mark,button.store-topbar__brand:focus-visible .store-topbar__switch-mark{background:#e4edf7}
button.store-topbar__brand{cursor:pointer}.store-topbar__brand strong{overflow:hidden;text-overflow:ellipsis}
.store-topbar__groups{display:flex;align-self:stretch;gap:8px;overflow-x:auto;min-width:0;scrollbar-width:none}
/* 位置栏位于主菜单右侧；窄屏允许菜单横滑、长名称省略，完整层级仍可悬停查看。 */
.store-topbar__location{margin-left:auto;min-width:0;max-width:45%;flex:0 1 auto;font-size:12px;color:#aebed3}
.store-topbar__location ol{display:flex;align-items:center;min-width:0;margin:0;padding:0;list-style:none}
.store-topbar__location li{display:flex;align-items:center;min-width:0;flex:0 3 auto;white-space:nowrap}
.store-topbar__location li:last-child{flex:0 1 auto;color:#fff}
.store-topbar__location-label{overflow:hidden;text-overflow:ellipsis}
.store-topbar__location-separator{flex:none;margin:0 8px;color:#7f93af}
.store-topbar__group{display:flex;align-items:center;justify-content:center;gap:7px;padding:0 16px;white-space:nowrap;background:transparent;border:0;border-bottom:3px solid transparent;color:inherit;font:inherit;cursor:pointer}
.store-topbar__group:hover,.store-topbar__group.is-open{background:#253752;color:#fff}.store-topbar__group.is-active{color:#fff;border-bottom-color:#4096ff}
.store-topbar__account{margin-left:auto;display:flex;align-items:center;gap:8px;min-width:0;max-width:220px;font-size:13px;white-space:nowrap}.store-topbar__account span{overflow:hidden;text-overflow:ellipsis}
.store-topbar__account-button{border:0;background:none;color:inherit;cursor:pointer}.store-menu.store-menu--account{top:92px;left:auto;right:16px}
.store-contextbar{height:52px;display:flex;align-items:center;gap:12px;padding:0 16px;border-bottom:1px solid #dce4ee;color:#45566e;min-width:0}
.store-quickbar{height:52px;display:flex;align-items:center;gap:8px;padding:0;overflow-x:auto;scrollbar-width:thin;min-width:0;flex:1}
.store-quickbar .cashier-nav__item{height:36px;min-height:36px;padding:0 16px;min-width:90px;width:auto;flex:none;border:1px solid transparent;background:transparent;color:#45566e;cursor:pointer;font:inherit;gap:8px}
.store-quickbar .cashier-nav__item:hover{background:#f1f5fb}.store-quickbar .cashier-nav__item--active{background:#146bd3;border-color:#4395ef;color:#fff;box-shadow:inset 3px 0 0 #b5d7ff}
.store-menu{position:absolute;top:40px;max-height:min(65vh,620px);min-width:240px;width:360px;max-width:calc(100vw - 32px);overflow:auto;padding:10px;background:#fff;border:1px solid #dce4ee;border-radius:0 0 10px 10px;box-shadow:0 12px 30px #172b4d29;z-index:40}
/* 一级菜单独占一行，二级按钮按配置顺序逐行排成三列；分组边界不能混排。 */
.store-menu--has-children{width:760px}
.store-menu__links{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:4px 8px}
.store-menu__links>[data-depth="0"],.store-menu__heading,.store-menu__parent{grid-column:1 / -1}
.store-menu__links>[data-depth]{min-width:0}
.store-menu__link{padding:10px 12px;border:0;border-radius:5px;display:block;background:transparent;color:#334861;cursor:pointer;text-align:left;font:inherit;font-size:13px;line-height:1.5;word-break:break-word}
.store-menu__link:hover,.store-menu__link.is-active{background:#eaf3ff;color:#1266cb}.store-menu__heading,.store-menu__parent{font-weight:600;background:#f5f7fb}.store-menu__heading{padding:10px 12px;color:#536780;font-size:13px;line-height:1.5}.store-menu__links>[data-depth="0"]:not(:first-child){margin-top:6px}
.store-menu__hint{padding:8px 12px;font-size:13px;color:#718197}
.store-operator-profile{margin:0;padding:4px 2px}.store-operator-profile>div{display:grid;grid-template-columns:56px minmax(0,1fr);gap:12px;padding:9px 10px;font-size:13px;line-height:1.6}.store-operator-profile dt{color:#7a8799}.store-operator-profile dd{margin:0;color:#334861;overflow-wrap:anywhere}
button:focus-visible{outline:2px solid #4096ff;outline-offset:-2px}
@media(max-width:900px){.store-topbar{gap:8px;padding:0 10px}.store-topbar__group{padding:0 10px}.store-topbar__account{max-width:110px}.store-topbar__brand{min-width:0;max-width:145px;font-size:13px}}
@media(max-width:600px){.store-topbar{height:40px;min-height:40px;gap:8px}.store-topbar__groups{flex:1; height:40px}.store-topbar__location{max-width:42%}.store-topbar__location-separator{margin:0 4px}.store-topbar__account{max-width:160px}.store-menu{top:40px;width:calc(100vw - 24px)}.store-quickbar{padding:0 8px}.store-quickbar .cashier-nav__item{min-width:74px;padding:0 10px}}
</style>
