<template>
  <div class="org-prototype" @click="closeMenus">
    <svg class="svg-sprite" aria-hidden="true">
      <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></symbol>
      <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
      <symbol id="i-chevron" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
      <symbol id="i-more" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></symbol>
      <symbol id="i-home" viewBox="0 0 24 24"><path d="m3 11 9-8 9 8M5 10v11h14V10M9 21v-7h6v7"/></symbol>
      <symbol id="i-branch" viewBox="0 0 24 24"><circle cx="6" cy="5" r="2"/><circle cx="18" cy="7" r="2"/><circle cx="18" cy="17" r="2"/><path d="M6 7v7a3 3 0 0 0 3 3h7M8 6h5a5 5 0 0 1 5 5v4"/></symbol>
      <symbol id="i-building" viewBox="0 0 24 24"><path d="M4 21h16M6 21V5.7c0-.7.4-1.3 1.1-1.5l8-2.7c.9-.3 1.9.4 1.9 1.4V21M9 8h2M9 12h2M9 16h2M14 8h.1M14 12h.1M14 16h.1"/></symbol>
      <symbol id="i-store" viewBox="0 0 24 24"><path d="m3 9 2-6h14l2 6M5 13v8h14v-8M9 21v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/></symbol>
      <symbol id="i-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></symbol>
      <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></symbol>
      <symbol id="i-edit" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></symbol>
      <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
      <symbol id="i-filter" viewBox="0 0 24 24"><path d="M4 5h16M7 12h10M10 19h4"/></symbol>
      <symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.1M3 12h.1M3 18h.1"/></symbol>
      <symbol id="i-card" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
      <symbol id="i-x" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol>
      <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
      <symbol id="i-alert" viewBox="0 0 24 24"><path d="M10.3 3.5 2.4 17a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.5a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.1"/></symbol>
      <symbol id="i-history" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></symbol>
      <symbol id="i-phone" viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.4 19.4 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2.1Z"/></symbol>
    </svg>

    <div v-if="treeMobileOpen" class="tree-mask" @click="closeMobileTree"></div>

    <div class="workspace" :class="{ 'tree-collapsed': treeCollapsed }">
      <aside class="org-panel" :class="{ collapsed: treeCollapsed, 'mobile-open': treeMobileOpen }">
        <div class="org-panel-head">
          <div class="input-shell compact"><svg-icon name="search" /><input v-model.trim="treeSearch" type="search" placeholder="搜索组织" /></div>
          <button class="icon-button desktop-tree-toggle" title="收起组织树" type="button" @click="collapseTree"><svg-icon name="arrow" class-name="rotate-180" /></button>
          <button class="icon-button mobile-tree-close" title="关闭组织树" type="button" @click="closeMobileTree"><svg-icon name="x" /></button>
        </div>
        <div class="tree-toolbar"><span>组织导航</span><div><button type="button" @click="expandAll">展开全部</button><i></i><button type="button" @click="collapseAll">收起</button></div></div>
        <div v-if="treeState.visible || treeState.slow" class="empty-inline">{{ treeState.slow ? '数据较多，仍在加载，请稍候' : '正在加载组织树…' }}</div>
        <div v-else-if="treeState.error" class="empty-inline">
          {{ treeState.error }}
          <button class="text-button" type="button" @click="loadTree">重新加载</button>
        </div>
        <div v-else class="org-tree" role="tree">
          <organization-tree
            v-for="node in rootOrgs"
            :key="node.id"
            :node="node"
            :nodes="orgs"
            :selected-id="selectedOrgId"
            :search="treeSearch"
            @select="selectOrg"
            @toggle="toggleOrg"
            @more="openTreeMenu"
          />
          <div v-if="!rootOrgs.length" class="empty-inline">暂无组织数据</div>
        </div>
        <div class="tree-legend"><span><i class="legend-dot active"></i>营业门店</span><span><i class="legend-dot warning"></i>待完善</span></div>
      </aside>

      <section class="org-content">
        <div v-if="treeCollapsed" class="tree-reopen-bar">
          <button class="button secondary compact-button" type="button" @click="expandTree"><svg-icon name="branch" />展开组织树</button>
        </div>

        <div v-if="overviewState.visible || overviewState.slow" class="empty-inline" style="padding:24px">{{ overviewState.slow ? '数据较多，仍在加载，请稍候' : '正在加载组织概况…' }}</div>
        <div v-else-if="overviewState.error" class="empty-inline" style="padding:24px">
          {{ overviewState.error }}
          <button class="text-button" type="button" @click="loadOverview">重试</button>
          <small style="display:block;margin-top:8px;color:#929bad">可尝试缩小组织范围后再试</small>
        </div>
        <template v-else-if="!overviewState.loading && overview && selectedOrg.id">
          <div class="org-hero">
            <div class="breadcrumb">
              <template v-for="(item, index) in selectedPath">
                <svg-icon v-if="index" :key="`arrow-${item.id}`" name="chevron" />
                <button :key="item.id" type="button" @click="selectOrg(item.id)">{{ item.name }}</button>
              </template>
            </div>
            <div class="org-hero-main" :data-org-id="selectedOrg.id">
              <div>
                <div class="title-line"><h2 data-testid="org-title">{{ selectedOrg.name }}</h2><span class="tag soft">{{ selectedOrg.pid ? '下级组织' : '最高级组织' }}</span></div>
                <p>{{ selectedOrg.pid ? `上级组织：${parentOrgName}` : '最高级组织' }} · 更新于{{ selectedOrg.updated || '—' }}</p>
              </div>
              <div class="hero-actions" @click.stop>
                <button class="button secondary mobile-tree-open" type="button" @click.stop="openMobileTree"><svg-icon name="branch" />组织树</button>
                <div class="input-shell person-locate-shell" title="输入姓名或手机号，回车定位到所属组织" @click.stop>
                  <svg-icon name="search" />
                  <input
                    v-model.trim="personLocateKeyword"
                    type="search"
                    maxlength="20"
                    placeholder="找人"
                    :disabled="personLocateLoading"
                    @click.stop
                    @keydown.enter.prevent="locatePersonInTree"
                  />
                </div>
                <button v-auth="['admin-store-add_store']" class="button secondary" type="button" :disabled="!selectedOrgId" @click="goCreateStore"><svg-icon name="plus" />新建门店</button>
                <button v-auth="['setting-staff-index']" class="button secondary" type="button" :disabled="!selectedOrgId && !rootOrgs.length" @click="openCreateStaff()"><svg-icon name="plus" />新建人员</button>
                <button class="button secondary" type="button" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" :disabled="writeSubmitting" @click="openJobPositionModal"><svg-icon name="plus" />岗位策略</button>
                <button class="button primary" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" type="button" :disabled="writeSubmitting" :aria-disabled="(!canWrite || writeSubmitting).toString()" @click="openCreateOrgModal()"><svg-icon name="plus" />新增组织</button>
                <button class="button secondary" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" type="button" :disabled="writeSubmitting" :aria-disabled="(!canWrite || writeSubmitting).toString()" @click="openEditOrgModal"><svg-icon name="edit" />编辑组织</button>
                <button class="button secondary" type="button" title="停用后下级组织与所属门店将继承停用；不会批量改写下级自身状态" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" :disabled="writeSubmitting" @click="openOpsConfirm('org', selectedOrg.id, 0, selectedOrg.name)">停用组织</button>
                <button class="button secondary" type="button" title="恢复上级后，原本单独停用的下级组织和门店仍保持停用" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" :disabled="writeSubmitting" @click="openOpsConfirm('org', selectedOrg.id, 1, selectedOrg.name)">恢复组织</button>
                <button class="icon-button bordered" title="更多操作" type="button" @click="toggleHeroMenu"><svg-icon name="more" /></button>
                <div class="popover-menu" :class="{ open: heroMenuOpen }">
                  <button type="button" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" :disabled="writeSubmitting" :aria-disabled="(!canWrite || writeSubmitting).toString()" @click="onHeroMenu('edit')">编辑组织</button>
                  <button type="button" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" :disabled="writeSubmitting" :aria-disabled="(!canWrite || writeSubmitting).toString()" @click="onHeroMenu('add')">新增下级组织</button>
                  <button type="button" class="danger-text" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" :disabled="writeSubmitting" :aria-disabled="(!canWrite || writeSubmitting).toString()" @click="onHeroMenu('delete')">删除组织</button>
                </div>
              </div>
            </div>
            <div class="leader-strip">
              <div class="leader-label"><span>本层级负责人</span><small>从员工档案中选择</small></div>
              <div class="leader-list">
                <div v-for="person in selectedLeaders" :key="person.employee_id" class="leader-person">
                  <span class="avatar avatar-indigo">{{ avatarText(person.name) }}</span>
                  <div><span>{{ person.name }}</span><small>组织负责人</small></div>
                </div>
                <div v-if="!selectedLeaders.length" class="leader-person empty">暂未设置负责人</div>
              </div>
              <button class="text-button" type="button" @click="openLeaderDrawer">{{ canWrite ? '设置负责人' : '查看负责人候选' }} <svg-icon name="arrow" /></button>
            </div>
          </div>

          <div class="metric-grid">
            <div v-for="metric in metrics" :key="metric.label" class="metric-card" :data-metric="metric.label" :style="{ '--metric-color': metric.color, '--metric-ink': metric.ink }">
              <div class="metric-top"><span>{{ metric.label }}</span><span class="metric-icon"><svg-icon :name="metric.icon" /></span></div>
              <div class="metric-value" data-metric-value>{{ metric.value }}<small>{{ metric.unit }}</small></div>
            </div>
          </div>

          <button v-if="attentionTotal" class="attention-card" type="button" @click="showAttention">
            <span class="attention-icon"><svg-icon name="alert" /></span>
            <span>
              <strong>有 {{ attentionTotal }} 项组织信息待完善</strong>
              <small>{{ attentionSummaryText }}</small>
            </span>
            <span class="attention-action">查看详情 <svg-icon name="arrow" /></span>
          </button>

          <div class="content-tabs">
            <button
              v-for="tab in tabs"
              :key="tab.key"
              class="tab"
              :class="{ active: activeTab === tab.key }"
              type="button"
              @click="switchTab(tab.key)"
            >{{ tab.label }} <span v-if="tab.count !== undefined">{{ tab.count }}</span></button>
          </div>

          <div v-show="activeTab === 'overview'" class="tab-pane active">
            <div class="overview-grid">
              <section class="section-card">
                <div class="section-heading">
                  <div><h3>下级组织</h3><p>当前组织的直属下级层级</p></div>
                  <button class="text-button" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" type="button" :disabled="writeSubmitting" :aria-disabled="(!canWrite || writeSubmitting).toString()" @click="openCreateOrgModal(selectedOrg.id)">新增下级</button>
                </div>
                <div class="suborg-list">
                  <button v-for="child in childOrgs" :key="child.id" class="suborg-item" type="button" @click="selectOrg(child.id)">
                    <span class="suborg-icon"><svg-icon name="branch" /></span>
                    <span class="suborg-info">
                      <strong>{{ child.name }}</strong>
                      <small>{{ child.stores }} 家门店 · {{ child.employees }} 名员工{{ child.attention ? ` · ${child.attention} 项待完善` : '' }}</small>
                    </span>
                    <svg-icon name="arrow" />
                  </button>
                  <div v-if="!childOrgs.length" class="empty-inline">暂无下级组织</div>
                </div>
              </section>
              <section class="section-card people-summary">
                <div class="section-heading">
                  <div><h3>职位分布</h3><p>当前组织全部在职人员（主职位互斥）</p></div>
                  <button class="text-button" type="button" @click="openPeopleFromOverview">查看人员</button>
                </div>
                <div class="donut-row">
                  <div class="donut" :style="{ background: donutBackground }">
                    <span><strong>{{ selectedOrg.employees }}</strong><small>在职人员</small></span>
                  </div>
                  <div class="donut-legend">
                    <div v-for="role in roleLegend" :key="role.name" class="legend-item">
                      <i :style="{ background: role.color }"></i><span>{{ role.name }}</span><strong>{{ role.count }}</strong>
                    </div>
                    <div v-if="!roleLegend.length" class="empty-inline">暂无职位数据</div>
                  </div>
                </div>
              </section>
            </div>
            <section class="section-card store-section">
              <div class="section-heading">
                <div><h3>重点门店</h3><p>快速查看店长、人员与营业状态</p></div>
                <button class="text-button" type="button" @click="switchTab('stores')">查看全部 <svg-icon name="arrow" /></button>
              </div>
              <div class="store-rows">
                <div v-for="store in focusStores" :key="store.id" class="store-row" @click="openStoreDrawer(store)">
                  <div class="store-name-cell">
                    <span class="store-thumb"><svg-icon name="store" /></span>
                    <div><strong>{{ store.name }}</strong><small>{{ store.address || '—' }}</small></div>
                  </div>
                  <div class="row-leaders" v-if="store.managers && store.managers.length">
                    <span class="avatar avatar-indigo">{{ avatarText(store.managers[0].name) }}</span>
                    <span>{{ store.managers[0].name }}{{ store.managers.length > 1 ? ` 等${store.managers.length}人` : '' }}</span>
                  </div>
                  <span v-else-if="store.attention_codes && store.attention_codes.length" class="tag warning">未设置店长</span>
                  <span v-else class="tag neutral">—</span>
                  <div class="staff-count"><strong>{{ store.employee_count }}</strong>人</div>
                  <span class="status" :class="store.business_status === 'open' ? 'active' : 'inactive'">{{ statusLabel(store.business_status) }}</span>
                  <svg-icon name="chevron" />
                </div>
                <div v-if="!focusStores.length" class="empty-inline">当前组织下暂无门店</div>
              </div>
            </section>
          </div>

          <div v-show="activeTab === 'stores' || activeTab === 'employees'" class="tab-pane active">
            <section class="section-card full-card">
              <div class="list-toolbar">
                <div class="toolbar-right">
                  <div class="input-shell small">
                    <svg-icon name="search" />
                    <input v-model.trim="dataSearch" type="search" :placeholder="activeTab === 'employees' ? '搜索姓名或手机号' : '搜索门店'" @keyup.enter="reloadCurrentList" />
                  </div>
                  <button class="button secondary compact-button" type="button" @click="reloadCurrentList">查询 <span class="enter-key">↵</span></button>
                  <button v-if="activeTab === 'employees'" class="button secondary compact-button" type="button" @click="openOrgDirectModal">组织直属</button>
                  <button class="button secondary compact-button" type="button" @click="openTransferApplyModal">调店申请</button>
                  <div class="view-switch">
                    <button type="button" :class="{ active: dataLayout === 'list' }" @click="dataLayout = 'list'"><svg-icon name="list" /></button>
                    <button type="button" :class="{ active: dataLayout === 'card' }" @click="dataLayout = 'card'"><svg-icon name="card" /></button>
                  </div>
                </div>
              </div>
              <div v-if="activeTab === 'stores'" class="filter-chips">
                <button v-for="filter in filters" :key="filter.key" class="chip" type="button" :class="{ active: dataFilter === filter.key }" @click="setStoreFilter(filter.key)">{{ filter.label }}</button>
              </div>

              <div v-if="listState.visible || listState.slow" class="empty-inline">{{ listState.slow ? '数据较多，仍在加载，请稍候' : '正在加载…' }}</div>
              <div v-else-if="listState.error" class="empty-inline">
                {{ listState.error }}
                <button class="text-button" type="button" @click="reloadCurrentList">重试</button>
              </div>
              <template v-else>
                <div v-if="activeTab === 'stores' && dataLayout === 'list'">
                  <table class="data-table">
                    <thead><tr><th>门店</th><th>店长/副店长</th><th>所属组织</th><th>在职人数</th><th>状态</th><th>待完善</th><th>操作</th></tr></thead>
                    <tbody>
                      <tr v-for="store in storeList" :key="store.id" :data-store-id="store.id" :data-store-name="store.name" @click="openStoreDrawer(store)">
                        <td><div class="store-name-cell"><span class="store-thumb"><svg-icon name="store" /></span><div><strong>{{ store.name }}</strong><small>{{ store.phone || '—' }}</small></div></div></td>
                        <td><span v-if="store.managers && store.managers.length">{{ store.managers[0].name }}</span><span v-else class="tag warning">未设置</span></td>
                        <td>{{ store.org_name }}</td>
                        <td>{{ store.employee_count }} 人</td>
                        <td><span class="status" :class="store.business_status === 'open' ? 'active' : 'inactive'">{{ statusLabel(store.business_status) }}</span></td>
                        <td><span v-if="store.attention_codes && store.attention_codes.length" class="tag warning">缺店长</span><span v-else>—</span></td>
                        <td @click.stop>
                          <div class="table-ops">
                            <button v-auth="['setting-staff-index']" class="button secondary compact-button ops-btn" type="button" @click="openCreateStaff(store)">新建人员</button>
                            <button class="button primary compact-button ops-btn" type="button" @click="openStoreDrawer(store)">查看详情</button>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                  <div v-if="!storeList.length" class="empty-state">没有找到符合条件的门店</div>
                </div>
                <div v-else-if="activeTab === 'stores'">
                  <div class="card-list">
                    <article v-for="store in storeList" :key="store.id" class="store-card" @click="openStoreDrawer(store)">
                      <div class="store-card-head">
                        <div class="store-name-cell"><span class="store-thumb"><svg-icon name="store" /></span><div><strong>{{ store.name }}</strong><small>{{ store.org_name }}</small></div></div>
                        <span class="status" :class="store.business_status === 'open' ? 'active' : 'inactive'">{{ statusLabel(store.business_status) }}</span>
                      </div>
                      <div class="store-card-meta">
                        <span>店长<strong>{{ store.managers && store.managers.length ? store.managers[0].name : '待设置' }}</strong></span>
                        <span>在职员工<strong>{{ store.employee_count }} 人</strong></span>
                        <span>所属组织<strong>{{ store.org_name }}</strong></span>
                        <span>有效任职<strong>{{ store.employee_count }} 人</strong></span>
                      </div>
                    </article>
                  </div>
                  <div v-if="!storeList.length" class="empty-state">没有找到符合条件的门店</div>
                </div>
                <div v-else>
                  <table class="data-table">
                    <thead><tr><th>人员</th><th>所属组织</th><th>任职门店</th><th>角色</th><th>手机号</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                      <tr v-for="person in employeeList" :key="person.employee_id" @click="openPersonDrawer(person)">
                        <td>
                          <div class="person-cell">
                            <img v-if="person.avatar" class="avatar-img" :src="person.avatar" alt="" @error="onAvatarError" />
                            <span v-else class="avatar avatar-indigo">{{ avatarText(person.name) }}</span>
                            <div><strong>{{ person.name }}</strong><small>{{ person.scope_assignment_count ? `${person.scope_assignment_count} 家门店任职` : '组织直属人员' }}</small></div>
                          </div>
                        </td>
                        <td>{{ assignmentOrganizationText(person) }}</td>
                        <td>{{ assignmentStoreText(person) }}</td>
                        <td><div class="role-tags"><span v-for="role in displayRoles(person)" :key="role" class="role-tag">{{ role }}</span></div></td>
                        <td>{{ person.phone_masked }}</td>
                        <td><span class="status">在职</span></td>
                        <td @click.stop>
                          <div class="person-ops" data-person-ops>
                            <button class="button secondary compact-button ops-btn" type="button" @click="openPersonDrawer(person)">查看档案</button>
                            <button
                              v-auth="['setting-staff-index']"
                              class="button primary compact-button ops-btn ops-edit-main"
                              type="button"
                              :class="{ 'is-readonly-disabled': !canEditStaff }"
                              :disabled="!canEditStaff"
                              @click="openEditStaff(person)"
                            >编辑人员</button>
                            <div class="ops-more" :class="{ open: personOpsKey === listOpsKey(person) }">
                              <button
                                class="button secondary compact-button ops-btn"
                                type="button"
                                @click="togglePersonOps(listOpsKey(person), $event)"
                              >更多 ▾</button>
                              <div v-if="personOpsKey === listOpsKey(person)" class="ops-dropdown" @click.stop>
                                <button
                                  v-auth="['setting-staff-index']"
                                  class="ops-dropdown-item ops-edit-in-more"
                                  type="button"
                                  :class="{ 'is-readonly-disabled': !canEditStaff }"
                                  :disabled="!canEditStaff"
                                  @click="openEditStaff(person); closePersonOps()"
                                >编辑人员</button>
                                <button
                                  v-if="canWrite && personHasCurrentTenure(person)"
                                  class="ops-dropdown-item"
                                  type="button"
                                  @click="openCreateTransferApply(person); closePersonOps()"
                                >发起调店申请</button>
                                <button
                                  v-if="canEditStaff"
                                  class="ops-dropdown-item danger"
                                  type="button"
                                  @click="confirmLeavePerson(person); closePersonOps()"
                                >办理离职</button>
                                <button
                                  v-if="canWrite"
                                  class="ops-dropdown-item danger"
                                  type="button"
                                  @click="confirmSoftDeletePerson(person); closePersonOps()"
                                >删除人员档案</button>
                              </div>
                            </div>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                  <div v-if="!employeeList.length" class="empty-state">没有找到符合条件的人员</div>
                </div>
                <div class="pagination">
                  <span>共 {{ listTotal }} {{ activeTab === 'stores' ? '家门店' : '名人员' }}</span>
                  <div>
                    <button type="button" :disabled="listPage <= 1" @click="changeListPage(listPage - 1)">上一页</button>
                    <button type="button" class="active">{{ listPage }}</button>
                    <button type="button" :disabled="listPage * listLimit >= listTotal" @click="changeListPage(listPage + 1)">下一页</button>
                  </div>
                </div>
              </template>
            </section>
          </div>

          <div v-show="activeTab === 'permissions'" class="tab-pane active">
            <section class="section-card full-card permission-card">
              <div class="permission-intro">
                <span class="permission-icon"><svg-icon name="shield" /></span>
                <div>
                  <h3>权限范围清晰可见</h3>
                  <p>{{ canWrite ? '负责人与后台权限人员分开展示；可为已关联账号的权限人员调整范围。' : (writeStatus.reason_text || '当前为只读阶段，不可调整范围。') }}</p>
                </div>
                <button
                  v-if="canWrite"
                  class="button primary compact-button"
                  type="button"
                  :disabled="writeSubmitting"
                  @click="openGrantDrawer"
                >新增权限人员</button>
              </div>
              <div v-if="permState.visible || permState.slow" class="empty-inline">{{ permState.slow ? '数据较多，仍在加载，请稍候' : '正在加载权限…' }}</div>
              <div v-else-if="permState.error" class="empty-inline">{{ permState.error }} <button class="text-button" type="button" @click="loadPermissions">重试</button></div>
              <template v-else>
                <div class="drawer-list-title">本级负责人</div>
                <div class="permission-table">
                  <div v-for="person in permissionLeaders" :key="'L'+person.employee_id" class="permission-row" :class="{ 'has-anomaly': !!person.anomaly }">
                    <div class="permission-person">
                      <span class="avatar avatar-indigo">{{ avatarText(person.name) }}</span>
                      <div>
                        <strong>{{ person.name }}</strong>
                        <small>{{ person.permission_status_text }}</small>
                        <small v-if="person.anomaly_text" class="anomaly-text">{{ person.anomaly_text }}</small>
                      </div>
                    </div>
                    <div class="permission-scope">
                      <strong>{{ person.store_names_preview || '—' }}</strong>
                      <small>{{ person.store_count ? `共 ${person.store_count} 家门店` : '暂无门店范围' }}</small>
                    </div>
                    <div class="permission-tags">
                      <span class="tag" :class="permTagClass(person.permission_status)">{{ person.permission_status_text }}</span>
                      <span v-if="person.anomaly" class="tag danger">异常</span>
                    </div>
                    <button class="table-action" type="button" data-action="view-scope" @click="openPermissionDrawer(person)">查看范围</button>
                  </div>
                  <div v-if="!permissionLeaders.length" class="empty-inline">暂未设置本层级负责人</div>
                </div>
                <div class="drawer-list-title" style="margin-top:18px">后台权限人员</div>
                <div class="permission-table">
                  <div v-for="person in permissionHolders" :key="'A'+person.org_admin_id" class="permission-row" :class="{ 'has-anomaly': !!person.anomaly }">
                    <div class="permission-person">
                      <span class="avatar avatar-teal">{{ avatarText(person.name) }}</span>
                      <div>
                        <strong>{{ person.name }}</strong>
                        <small>{{ person.account || '无账号绑定' }}</small>
                        <small v-if="person.anomaly_text" class="anomaly-text">{{ person.anomaly_text }}</small>
                      </div>
                    </div>
                    <div class="permission-scope">
                      <strong>{{ person.store_names_preview || '—' }}</strong>
                      <small>{{ person.store_count ? `共 ${person.store_count} 家门店` : '暂无门店范围' }}</small>
                    </div>
                    <div class="permission-tags">
                      <span class="tag" :class="permTagClass(person.permission_status)">{{ person.permission_status_text }}</span>
                      <span v-if="person.anomaly" class="tag danger">异常</span>
                    </div>
                    <button class="table-action" type="button" data-action="view-scope" @click="openPermissionDrawer(person)">{{ canWrite ? '调整范围' : '查看范围' }}</button>
                    <button
                      v-if="canWrite && person.org_admin_id"
                      class="table-action danger-text"
                      type="button"
                      :disabled="writeSubmitting"
                      @click="revokeGrant(person)"
                    >撤销权限</button>
                  </div>
                  <div v-if="!permissionHolders.length" class="empty-inline">当前组织暂无后台权限人员</div>
                </div>
              </template>
              <div class="permission-note"><strong>说明</strong><span>负责人不会自动成为后台权限人员；授权仅建立组织关系，不创建账号、不改密码和系统角色。</span></div>
            </section>
          </div>

          <div v-show="activeTab === 'logs'" class="tab-pane active">
            <section class="section-card full-card">
              <div class="section-heading">
                <div><h3>变更记录</h3><p>负责人、组织关系、权限范围等重要操作均会保留记录</p></div>
                <div class="toolbar-right">
                  <div class="input-shell small"><svg-icon name="search" /><input v-model.trim="logSearch" type="search" placeholder="搜索操作人或内容" @keyup.enter="loadLogs(1)" /></div>
                  <button class="button secondary compact-button" type="button" @click="loadLogs(1)">查询 <span class="enter-key">↵</span></button>
                </div>
              </div>
              <div v-if="logState.visible || logState.slow" class="empty-inline">{{ logState.slow ? '数据较多，仍在加载，请稍候' : '正在加载…' }}</div>
              <div v-else-if="logState.error" class="empty-inline">{{ logState.error }} <button class="text-button" type="button" @click="loadLogs(logPage)">重试</button></div>
              <div v-else class="timeline">
                <div v-for="log in logList" :key="log.id" class="timeline-item">
                  <div class="timeline-time">{{ log.add_time_text || '—' }}</div>
                  <span class="timeline-dot"><svg-icon name="history" /></span>
                  <div class="timeline-content">
                    <strong>{{ log.title || log.action }}</strong>
                    <p>{{ log.summary || log.remark || '—' }}</p>
                    <small>操作人：{{ log.operator_display || log.operator_name || '—' }} · 操作来源：平台后台</small>
                  </div>
                </div>
                <div v-if="!logList.length" class="empty-inline">暂无变更记录</div>
              </div>
              <div v-if="logTotal > logLimit" class="pagination">
                <span>共 {{ logTotal }} 条</span>
                <div>
                  <button type="button" :disabled="logPage <= 1" @click="loadLogs(logPage - 1)">上一页</button>
                  <button type="button" class="active">{{ logPage }}</button>
                  <button type="button" :disabled="logPage * logLimit >= logTotal" @click="loadLogs(logPage + 1)">下一页</button>
                </div>
              </div>
            </section>
          </div>
        </template>
      </section>
    </div>

    <div v-if="treeMenu.open" class="tree-context-menu" :style="{ top: `${treeMenu.y}px`, left: `${treeMenu.x}px` }" @click.stop>
      <button type="button" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" :disabled="writeSubmitting" :aria-disabled="(!canWrite || writeSubmitting).toString()" @click="onTreeMenu('edit')">编辑组织</button>
      <button type="button" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" :disabled="writeSubmitting" :aria-disabled="(!canWrite || writeSubmitting).toString()" @click="onTreeMenu('add')">新增下级组织</button>
      <button type="button" class="danger-text" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" :disabled="writeSubmitting" :aria-disabled="(!canWrite || writeSubmitting).toString()" @click="onTreeMenu('delete')">删除组织</button>
    </div>

    <div v-if="drawer.open" class="drawer-layer open" aria-hidden="false">
      <button class="drawer-mask" aria-label="关闭" type="button" @click="closeDrawer"></button>
      <aside class="drawer">
        <div class="drawer-head">
          <div><span class="drawer-kicker">{{ drawer.kicker }}</span><h2>{{ drawer.title }}</h2></div>
          <div class="drawer-head-actions">
            <template v-if="drawer.mode === 'person' && drawer.person">
              <button
                v-auth="['setting-staff-index']"
                class="button primary compact-button"
                type="button"
                :class="{ 'is-readonly-disabled': !canEditStaff }"
                :disabled="!canEditStaff"
                @click="openEditStaff(drawer.person)"
              >编辑人员</button>
              <button
                v-if="canWrite && personHasCurrentTenure(drawer.person, true)"
                class="button secondary compact-button"
                type="button"
                @click="openCreateTransferApply(drawer.person, true)"
              >发起调店申请</button>
              <div class="ops-more" :class="{ open: personOpsKey === 'drawer-person' }">
                <button
                  class="button secondary compact-button"
                  type="button"
                  @click="togglePersonOps('drawer-person', $event)"
                >更多 ▾</button>
                <div v-if="personOpsKey === 'drawer-person'" class="ops-dropdown ops-dropdown-right" @click.stop>
                  <button
                    v-if="canEditStaff"
                    class="ops-dropdown-item danger"
                    type="button"
                    @click="confirmLeavePerson(drawer.person); closePersonOps()"
                  >办理离职</button>
                  <button
                    v-if="canWrite"
                    class="ops-dropdown-item danger"
                    type="button"
                    @click="confirmSoftDeletePerson(drawer.person); closePersonOps()"
                  >删除人员档案</button>
                </div>
              </div>
            </template>
            <button class="icon-button" type="button" @click="closeDrawer"><svg-icon name="x" /></button>
          </div>
        </div>
        <div class="drawer-body">
          <template v-if="drawer.mode === 'leaders'">
            <div class="form-section">
              <div class="form-section-title">{{ canWrite ? '选择在职员工设为负责人' : '查看在职员工（只读）' }}</div>
              <div v-if="canWrite && draftLeaders.length" class="permission-note" style="margin-bottom:10px">
                <strong>已选</strong><span>{{ draftLeaders.map((x) => x.name).join('、') }}（{{ draftLeaders.length }} 人）</span>
              </div>
              <div class="input-shell employee-search">
                <svg-icon name="search" />
                <input v-model.trim="drawer.search" type="search" placeholder="搜索姓名或手机号" @keyup.enter="loadLeaderCandidates(1)" />
              </div>
              <button class="button secondary compact-button" type="button" style="margin:10px 0" @click="loadLeaderCandidates(1)">查询 <span class="enter-key">↵</span></button>
              <div v-if="candidateState.visible || candidateState.slow" class="empty-inline">{{ candidateState.slow ? '数据较多，仍在加载，请稍候' : '加载中…' }}</div>
              <div v-else-if="candidateState.error" class="empty-inline">{{ candidateState.error }} <button class="text-button" type="button" @click="loadLeaderCandidates(candidatePage)">重试</button></div>
              <div v-else class="employee-options">
                <div
                  v-for="person in leaderCandidates"
                  :key="person.employee_id"
                  class="employee-option"
                  :class="{ 'readonly-option': !canWrite, selected: isDraftLeader(person.employee_id) }"
                  @click="toggleDraftLeader(person)"
                >
                  <span class="avatar avatar-indigo">{{ avatarText(person.name) }}</span>
                  <div>
                    <strong>{{ person.name }}</strong>
                    <small>{{ person.phone_masked }} · {{ person.assignment_count }} 家门店任职</small>
                  </div>
                  <span v-if="canWrite && isDraftLeader(person.employee_id)" class="tag success">已选</span>
                </div>
                <div v-if="!leaderCandidates.length" class="empty-inline">没有找到匹配的员工</div>
              </div>
              <div class="pagination" v-if="candidateTotal">
                <span>共 {{ candidateTotal }} 人</span>
                <div>
                  <button type="button" :disabled="candidatePage <= 1" @click="loadLeaderCandidates(candidatePage - 1)">上一页</button>
                  <button type="button" class="active">{{ candidatePage }}</button>
                  <button type="button" :disabled="candidatePage * candidateLimit >= candidateTotal" @click="loadLeaderCandidates(candidatePage + 1)">下一页</button>
                </div>
              </div>
            </div>
            <div v-if="!canWrite" class="permission-note"><strong>只读</strong><span>{{ writeStatus.reason_text || READONLY_TIP }}</span></div>
          </template>

          <template v-else-if="drawer.mode === 'store'">
            <div class="drawer-store-hero">
              <span class="store-thumb"><svg-icon name="store" /></span>
              <div><h3>{{ drawer.store.name }}</h3><p>{{ drawer.store.org_name }}</p></div>
              <span class="status" :class="drawer.store.business_status === 'open' ? 'active' : 'inactive'" style="margin-left:auto">{{ statusLabel(drawer.store.business_status) }}</span>
            </div>
            <div class="detail-stat-grid">
              <div class="detail-stat"><span>在职人员</span><strong>{{ drawer.store.employee_count }} 人</strong></div>
              <div class="detail-stat"><span>店长/副店长</span><strong>{{ (drawer.store.managers || []).length }} 人</strong></div>
              <div class="detail-stat"><span>有效任职</span><strong>{{ drawer.store.employee_count }} 人</strong></div>
            </div>
            <div class="form-section">
              <div class="form-section-title">门店信息</div>
              <div class="permission-note"><strong>地址</strong><span>{{ drawer.store.address || '—' }}</span></div>
              <div class="permission-note"><strong>电话</strong><span>{{ drawer.store.phone || '—' }}</span></div>
            </div>
            <div class="form-section">
              <div class="form-section-title">门店停用 / 恢复</div>
              <div class="permission-note">
                <strong>做什么</strong>
                <span>停用后本店门店后台、收银台、手机端业务入口立即不可用，禁止新增业务；恢复后按原任职与授权继续生效。</span>
              </div>
              <div class="permission-note">
                <strong>不影响什么</strong>
                <span>历史订单、支付与账务仍保留；总部仍可查询。若所属组织仍停用，恢复门店后业务仍可能不可用。</span>
              </div>
              <div v-if="canWrite" class="auth-actions">
                <button class="button secondary compact-button" type="button" :disabled="writeSubmitting" @click="openOpsConfirm('store', drawer.store.id, 0, drawer.store.name)">停用门店</button>
                <button class="button primary compact-button" type="button" :disabled="writeSubmitting" @click="openOpsConfirm('store', drawer.store.id, 1, drawer.store.name)">恢复门店</button>
              </div>
            </div>
            <div v-if="canWrite" class="form-section">
              <div class="form-section-title">门店所属组织</div>
              <div class="form-field">
                <label>调整门店归属组织</label>
                <select v-model.number="draftStoreOrgId" :disabled="writeSubmitting">
                  <option v-for="org in orgs" :key="org.id" :value="Number(org.id)">{{ org.name }}</option>
                </select>
                <span class="field-hint">仅调整门店所属组织，不涉及员工调店</span>
              </div>
              <button
                class="button primary compact-button"
                type="button"
                :disabled="writeSubmitting || !draftStoreOrgId || draftStoreOrgId === Number(drawer.store.org_id)"
                @click="submitStoreOrgBind"
              >{{ writeSubmitting ? '保存中…' : '保存归属' }}</button>
            </div>
            <div class="drawer-list-title">店长/副店长</div>
            <div v-for="m in (drawer.store.managers || [])" :key="m.employee_id" class="drawer-person">
              <span class="avatar avatar-indigo">{{ avatarText(m.name) }}</span>
              <div><strong>{{ m.name }}</strong><small>{{ m.position }} · {{ m.phone_masked }}</small></div>
              <span class="tag neutral">在职</span>
            </div>
            <div v-if="!(drawer.store.managers || []).length" class="permission-note"><strong>待完善</strong><span>该营业门店尚未设置有效店长或副店长</span></div>
            <div class="drawer-list-title">门店人员</div>
            <div v-if="storeStaffState.visible || storeStaffState.slow" class="empty-inline">{{ storeStaffState.slow ? '数据较多，仍在加载，请稍候' : '加载中…' }}</div>
            <div v-else-if="storeStaffState.error" class="empty-inline">{{ storeStaffState.error }} <button class="text-button" type="button" @click="loadStoreStaff(1)">重试</button></div>
            <template v-else>
              <div v-for="person in storeStaffList" :key="person.employee_id" class="drawer-person">
                <span class="avatar avatar-indigo">{{ avatarText(person.name) }}</span>
                <div><strong>{{ person.name }}</strong><small>{{ (person.roles || []).join(' / ') }} · {{ person.phone_masked }}</small></div>
                <span class="tag neutral">在职</span>
              </div>
              <div v-if="!storeStaffList.length" class="empty-inline">暂无门店人员</div>
              <div class="pagination" v-if="storeStaffTotal > storeStaffLimit">
                <span>共 {{ storeStaffTotal }} 人</span>
                <div>
                  <button type="button" :disabled="storeStaffPage <= 1" @click="loadStoreStaff(storeStaffPage - 1)">上一页</button>
                  <button type="button" class="active">{{ storeStaffPage }}</button>
                  <button type="button" :disabled="storeStaffPage * storeStaffLimit >= storeStaffTotal" @click="loadStoreStaff(storeStaffPage + 1)">下一页</button>
                </div>
              </div>
            </template>
          </template>

          <template v-else-if="drawer.mode === 'person'">
            <div class="drawer-store-hero">
              <img v-if="drawer.person.avatar || (personAuth.employee && personAuth.employee.avatar)" class="avatar-img lg" :src="(personAuth.employee && personAuth.employee.avatar) || drawer.person.avatar" alt="" @error="onAvatarError" />
              <span v-else class="avatar avatar-indigo">{{ avatarText(drawer.person.name) }}</span>
              <div><h3>{{ (personAuth.employee && personAuth.employee.name) || drawer.person.name }}</h3><p>{{ drawer.person.phone_masked }}</p></div>
              <span class="status" style="margin-left:auto">{{ personAuth.employee && personAuth.employee.status === 1 ? '账号正常' : '已停用' }}</span>
            </div>
            <div v-if="personAuthLoading" class="empty-inline">正在加载人员档案…</div>
            <template v-else>
              <div class="form-section" style="margin-top:20px">
                <div class="form-section-title">1. 基础档案</div>
                <div class="permission-note"><strong>姓名</strong><span>{{ (personAuth.employee && personAuth.employee.name) || drawer.person.name }}</span></div>
                <div class="permission-note"><strong>手机</strong><span>{{ drawer.person.phone_masked }}</span></div>
                <div class="permission-note"><strong>账号状态</strong><span>{{ personAuth.employee && personAuth.employee.status === 1 ? '正常' : '已停用' }}</span></div>
                <div class="permission-note"><strong>头像</strong><span>{{ (personAuth.employee && personAuth.employee.avatar) || drawer.person.avatar ? '已设置' : '未设置' }}</span></div>
              </div>

              <div class="form-section">
                <div class="form-section-title">2. 归属与任职</div>
                <div class="permission-note"><strong>所属组织</strong><span>{{ personOrgNamesText }}</span></div>
                <template v-if="currentPersonTenure">
                  <div class="permission-note"><strong>当前任职门店</strong><span>{{ currentPersonTenure.store_name || ('门店#' + currentPersonTenure.store_id) }}</span></div>
                  <div class="permission-note"><strong>当前岗位</strong><span>{{ jobNamesOf(currentPersonTenure) }}</span></div>
                  <div class="permission-note"><strong>在职状态</strong><span>{{ Number(currentPersonTenure.status) === 1 ? '在职' : '已结束' }}</span></div>
                  <div class="permission-note"><strong>入职时间</strong><span>{{ formatUnixTime(currentPersonTenure.add_time || currentTenureStartTime) }}</span></div>
                </template>
                <div v-else class="permission-note"><strong>当前任职门店</strong><span>无当前任职门店</span></div>
                <div class="auth-actions" style="margin:10px 0">
                  <button class="button secondary compact-button" type="button" @click="openPersonTransferRecords">调店记录</button>
                </div>
                <div class="drawer-list-title">任职历史（只读）</div>
                <div v-for="row in personTenureHistoryRows" :key="`th-${row.key}`" class="auth-write-box">
                  <div class="permission-note"><strong>{{ row.store_name }}</strong><span>{{ row.status_text }}</span></div>
                  <div class="permission-note"><strong>起止时间</strong><span>{{ row.period_text }}</span></div>
                  <div class="permission-note"><strong>调离/离职原因</strong><span>{{ row.reason || '—' }}</span></div>
                  <div v-if="row.can_resume" class="auth-actions" style="margin-top:10px">
                    <button class="button secondary compact-button" type="button" :disabled="writeSubmitting" @click="openTenureConfirm(row.staff, 'resume')">复职</button>
                  </div>
                </div>
                <div v-if="!personTenureHistoryRows.length" class="empty-inline">暂无任职历史</div>
              </div>

              <div class="form-section">
                <div class="form-section-title">3. 岗位与功能入口</div>
                <div class="permission-note"><strong>说明</strong><span>岗位决定能使用哪些功能；入口只表示能否登录对应端。这里只读预览，调整请点「编辑人员」或到岗位策略。</span></div>
                <div class="permission-note"><strong>当前岗位</strong><span>{{ currentPersonTenure ? jobNamesOf(currentPersonTenure) : '无当前任职岗位' }}</span></div>
                <div class="auth-write-box">
                  <div class="permission-note"><strong>平台后台</strong><span>{{ previewCoverText(personAuth.function_preview && personAuth.function_preview.platform) }} · {{ platformEntryText }}</span></div>
                </div>
                <div v-for="row in ((personAuth.function_preview && personAuth.function_preview.by_staff) || [])" :key="`fp-${row.staff_id}`" class="auth-write-box">
                  <div class="permission-note"><strong>{{ storeNameByStaffId(row.staff_id) }}</strong><span>按有效岗位计算</span></div>
                  <div class="permission-note"><strong>Vue 3 门店端</strong><span>{{ previewCoverText(row.preview && row.preview.channels && row.preview.channels.store_v3) }} · {{ entryStatusText(row.staff_id, 'store_v3') }}</span></div>
                  <div class="permission-note"><strong>手机端</strong><span>{{ previewCoverText(row.preview && row.preview.channels && row.preview.channels.mobile) }} · {{ entryStatusText(row.staff_id, 'mobile') }}</span></div>
                </div>
                <div v-if="!((personAuth.function_preview && personAuth.function_preview.by_staff) || []).length" class="empty-inline">暂无 Vue 3 门店端入口预览</div>
              </div>

              <div class="form-section">
                <div class="form-section-title">4. 数据权限</div>
                <div class="permission-note"><strong>说明</strong><span>数据权限由人员决定，只影响能看哪些数据，不改变岗位功能。这里只读预览，调整请点「编辑人员」。</span></div>
                <div v-if="(personAuth.data_scopes || []).length" class="auth-write-box">
                  <div v-for="scope in personAuth.data_scopes" :key="`ds-${scope.id}`" class="permission-note">
                    <strong>{{ dataScopeSourceLabel(scope) }}</strong>
                    <span>{{ dataScopeModeLabel(scope.scope_mode) }}{{ dataScopeDetailText(scope) }}</span>
                  </div>
                </div>
                <div v-else class="empty-inline">默认个人数据范围（仅本人参与的数据）</div>
              </div>

              <div class="form-section">
                <div class="form-section-title">5. 组织管理授权</div>
                <div class="permission-note"><strong>说明</strong><span>组织管理授权独立于人员归属和门店任职，会决定可管理的组织范围。</span></div>
                <div v-for="grant in personOrganizationAdminGrants" :key="`oga-${grant.org_admin_id}`" class="auth-write-box">
                  <div class="permission-note"><strong>{{ grant.org_name || ('组织#' + grant.org_id) }}</strong><span>{{ grant.scope_mode === 'custom' ? '自定义门店范围' : '继承组织范围' }} · 账号 {{ grant.account || '—' }}</span></div>
                  <div v-if="canWrite" class="auth-actions" style="margin-top:10px">
                    <button class="button secondary compact-button danger-button" type="button" :disabled="writeSubmitting" @click="revokePersonOrganizationGrant(grant)">解除组织管理授权</button>
                  </div>
                </div>
                <div v-if="!personOrganizationAdminGrants.length" class="empty-inline">未配置组织管理授权</div>
              </div>

              <div class="form-section">
                <div class="form-section-title">6. 授权与变更记录</div>
                <div class="permission-note"><strong>说明</strong><span>岗位、入口、数据权限和任职相关操作记录，便于追溯。</span></div>
                <div v-for="log in personAudits" :key="log.id" class="permission-note">
                  <strong>{{ log.action }}</strong><span>{{ log.operator_name }} · {{ log.reason || '—' }}</span>
                </div>
                <div v-if="!personAudits.length" class="empty-inline">暂无记录</div>
              </div>
            </template>
          </template>

          <template v-else-if="drawer.mode === 'grant'">
            <div class="form-section">
              <div class="form-section-title">搜索权限候选</div>
              <div class="input-shell small">
                <svg-icon name="search" />
                <input v-model.trim="grantSearch" type="search" placeholder="姓名 / 账号 / 手机号" @keyup.enter="loadGrantCandidates(1)" />
              </div>
              <button class="button secondary compact-button" type="button" style="margin-top:8px" @click="loadGrantCandidates(1)">查询</button>
            </div>
            <div v-if="grantCandidateState.visible || grantCandidateState.slow" class="empty-inline">正在加载候选…</div>
            <div v-else-if="grantCandidateState.error" class="empty-inline">{{ grantCandidateState.error }}</div>
            <div v-else class="employee-options">
              <div
                v-for="item in grantCandidates"
                :key="item.admin_id"
                class="employee-option"
                :class="{ selected: Number(grantForm.admin_id) === Number(item.admin_id), 'readonly-option': item.already_granted }"
                @click="selectGrantCandidate(item)"
              >
                <span class="avatar avatar-teal">{{ avatarText(item.employee_name || item.account) }}</span>
                <div>
                  <strong>{{ item.employee_name || item.real_name || '—' }}</strong>
                  <small>账号 {{ item.account }} · 员工 #{{ item.employee_id }} · 管理员 #{{ item.admin_id }}</small>
                  <small>{{ item.phone_masked }}</small>
                </div>
                <span v-if="item.already_granted" class="tag warning">已授权</span>
                <span v-else-if="Number(grantForm.admin_id) === Number(item.admin_id)" class="tag success">已选</span>
              </div>
              <div v-if="!grantCandidates.length" class="empty-inline">暂无可用候选（需先创建并绑定员工的后台账号）</div>
            </div>
            <div class="form-section" style="margin-top:16px">
              <div class="form-section-title">范围模式</div>
              <div class="role-tags">
                <button type="button" class="tag" :class="grantForm.scope_mode === 'inherit' ? 'success' : 'soft'" @click="grantForm.scope_mode = 'inherit'">继承组织</button>
                <button type="button" class="tag" :class="grantForm.scope_mode === 'custom' ? 'success' : 'soft'" @click="grantForm.scope_mode = 'custom'">自定义可管门店</button>
              </div>
            </div>
            <div v-if="grantForm.scope_mode === 'custom'" class="form-section">
              <div class="form-section-title">可管理门店</div>
              <div class="employee-options">
                <div
                  v-for="store in orgStoreOptions"
                  :key="'g'+store.id"
                  class="employee-option"
                  :class="{ selected: grantForm.allowed_store_ids.indexOf(Number(store.id)) >= 0 }"
                  @click="toggleGrantStore(store.id)"
                >
                  <span class="avatar avatar-teal"><svg-icon name="store" /></span>
                  <div><strong>{{ store.name }}</strong><small>ID {{ store.id }}</small></div>
                  <span v-if="grantForm.allowed_store_ids.indexOf(Number(store.id)) >= 0" class="tag success">已选</span>
                </div>
                <div v-if="!orgStoreOptions.length" class="empty-inline">暂无有效门店</div>
              </div>
            </div>
          </template>

          <template v-else-if="drawer.mode === 'permission'">
            <div class="permission-intro" style="margin:0 0 18px" data-drawer="permission">
              <span class="permission-icon"><svg-icon name="shield" /></span>
              <div>
                <h3>{{ drawer.person.permission_status_text }}</h3>
                <p>{{ drawer.person.store_names_preview || '暂无门店范围' }}（共 {{ drawer.person.store_count || 0 }} 家）</p>
                <p v-if="drawer.person.anomaly_text" class="anomaly-text">{{ drawer.person.anomaly_text }}</p>
              </div>
            </div>
            <div v-if="drawer.person.anomaly" class="permission-note anomaly-note">
              <strong>异常</strong><span>{{ drawer.person.anomaly_text || drawer.person.anomaly }}</span>
            </div>
            <template v-if="canWrite && canEditPermissionPerson(drawer.person)">
              <div class="form-section">
                <div class="form-section-title">范围模式</div>
                <div class="role-tags">
                  <button type="button" class="tag" :class="draftScopeMode === 'inherit' ? 'success' : 'soft'" @click="draftScopeMode = 'inherit'">继承组织</button>
                  <button type="button" class="tag" :class="draftScopeMode === 'custom' ? 'success' : 'soft'" @click="draftScopeMode = 'custom'">自定义可管门店</button>
                </div>
              </div>
              <div v-if="draftScopeMode === 'custom'" class="form-section">
                <div class="form-section-title">可管理门店（当前组织有效门店）</div>
                <div v-if="!permissionRangeReady" class="empty-inline">{{ permState.error || '正在加载可管门店…' }}</div>
                <div v-else class="employee-options">
                  <div
                    v-for="store in permissionStoreOptions"
                    :key="store.id"
                    class="employee-option"
                    :class="{ selected: draftAllowedStoreIds.indexOf(Number(store.id)) >= 0 }"
                    @click="toggleDraftAllowedStore(store.id)"
                  >
                    <span class="avatar avatar-teal"><svg-icon name="store" /></span>
                    <div><strong>{{ store.name }}</strong><small>ID {{ store.id }}</small></div>
                    <span v-if="draftAllowedStoreIds.indexOf(Number(store.id)) >= 0" class="tag success">已选</span>
                  </div>
                  <div v-if="!permissionStoreOptions.length" class="empty-inline">暂无有效门店</div>
                </div>
              </div>
            </template>
            <div v-else class="permission-note"><strong>{{ canWrite ? '说明' : '只读' }}</strong><span>{{ canWrite ? '仅已关联有效后台账号的权限人员可调整范围；负责人请到负责人设置。' : (writeStatus.reason_text || READONLY_TIP) }}</span></div>
          </template>
        </div>
        <div v-if="drawer.mode === 'leaders' || drawer.mode === 'permission' || drawer.mode === 'grant'" class="drawer-footer">
          <button class="button secondary" type="button" @click="closeDrawer">关闭</button>
          <button
            v-if="drawer.mode === 'leaders'"
            class="button primary"
            :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }"
            type="button"
            :disabled="!canWrite || writeSubmitting"
            @click="submitLeaders"
          >{{ canWrite ? (writeSubmitting ? '保存中…' : '保存负责人') : '不可保存' }}</button>
          <button
            v-else-if="drawer.mode === 'grant'"
            class="button primary"
            :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting || !grantForm.admin_id }"
            type="button"
            :disabled="!canWrite || writeSubmitting || !grantForm.admin_id"
            @click="submitGrant"
          >{{ writeSubmitting ? '保存中…' : '保存授权' }}</button>
          <button
            v-else
            class="button primary"
            :class="{ 'is-readonly-disabled': !canWrite || !canEditPermissionPerson(drawer.person) || writeSubmitting || !permissionRangeReady }"
            type="button"
            :disabled="!canWrite || !canEditPermissionPerson(drawer.person) || writeSubmitting || !permissionRangeReady"
            @click="submitPermission"
          >{{ canWrite && canEditPermissionPerson(drawer.person) ? (writeSubmitting ? '保存中…' : (permissionRangeReady ? '保存范围' : '加载中…')) : '不可保存' }}</button>
        </div>
      </aside>
    </div>

    <div v-if="rolePublishModal.open" class="modal-layer open" @click.self="closeRolePublishModal">
      <div class="modal-dialog modal-wide" role="dialog" aria-modal="true" style="width:960px;max-width:96vw;" @click.stop>
        <div class="modal-head">
          <h2>角色模板发布</h2>
          <button class="icon-button" type="button" @click="closeRolePublishModal"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <div class="permission-note"><strong>功能说明</strong><span>这里保留历史角色模板的发布记录；门店端权限请在「岗位策略」按门店端功能配置，历史模板不会授予门店端权限。</span></div>
          <div class="permission-note"><strong>使用步骤</strong><span>① 新建或编辑模板并配置三端功能 → ② 发布到门店或组织 → ③ 门店人员页面选择已允许的模板。停用后不可新增选择，已绑定人员不会自动清权。</span></div>
          <div class="list-toolbar" style="margin:12px 0;gap:8px;display:flex;flex-wrap:wrap;align-items:center;">
            <input v-model.trim="rolePublishModal.keyword" type="text" placeholder="搜索模板名称" style="min-width:180px" @keyup.enter="loadRoleTemplates" />
            <button class="button primary compact-button" type="button" @click="loadRoleTemplates">查询 <span class="enter-key">↵</span></button>
            <button class="button primary compact-button" type="button" :disabled="!canWrite || writeSubmitting" @click="openRoleTemplateCreate">新建总部门店角色模板</button>
          </div>
          <table class="data-table">
            <thead><tr><th>ID</th><th>模板名称</th><th>说明</th><th>门店可选</th><th>状态</th><th>有效发布数</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="row in rolePublishModal.templates" :key="row.id">
                <td>{{ row.id }}</td>
                <td>{{ row.role_name }}</td>
                <td>{{ row.remark || '—' }}</td>
                <td>{{ Number(row.allow_store_select)===1?'是':'否' }}</td>
                <td>{{ Number(row.status)===1?'启用':'停用' }}</td>
                <td>{{ row.publish_count || 0 }}</td>
                <td>
                  <button class="table-action" type="button" :disabled="!canWrite" @click="openRoleTemplateEdit(row)">编辑</button>
                  <button class="table-action" type="button" :disabled="!canWrite || Number(row.status)!==1" @click="openRolePublishForm(row)">发布</button>
                  <button class="table-action" type="button" @click="openRolePublishRecords(row)">发布记录</button>
                  <button v-if="Number(row.status)===1" class="table-action" type="button" :disabled="!canWrite || writeSubmitting" @click="disableRoleTemplateRow(row)">停用</button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="!rolePublishModal.templates.length" class="empty-inline role-empty-box">
            <p>暂无总部门店角色模板。</p>
            <p class="muted">历史模板不再用于门店端授权；请到「岗位策略」配置门店端和手机端权限。</p>
            <div style="margin-top:12px;display:flex;gap:8px;justify-content:center;">
              <button class="button primary compact-button" type="button" :disabled="!canWrite" @click="openRoleTemplateCreate">新建总部门店角色模板</button>
              <button class="button secondary compact-button" type="button" @click="loadRoleTemplates">刷新列表</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="rolePublishModal.formOpen" class="modal-layer open" @click.self="closeRolePublishForm">
      <div class="modal-dialog" role="dialog" aria-modal="true" style="width:720px;max-width:96vw;" @click.stop>
        <div class="modal-head">
          <h2>发布到门店</h2>
          <button class="icon-button" type="button" @click="closeRolePublishForm"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <div class="permission-note" v-if="rolePublishModal.selected">
            <strong>发布模板</strong>
            <span>{{ rolePublishModal.selected.role_name }} (#{{ rolePublishModal.selected.id }})</span>
          </div>
          <div class="permission-note"><strong>说明</strong><span>岗位决定能操作哪些功能，人员数据权限决定能看到哪些数据。发布到组织时，该组织及下级组织下的门店均可获得模板副本。</span></div>
          <div class="auth-row">
            <label>发布范围</label>
            <select v-model="rolePublishModal.scope_type" :disabled="writeSubmitting">
              <option value="store">指定门店</option>
              <option value="org">组织（含下级门店）</option>
            </select>
          </div>
          <div v-if="rolePublishModal.scope_type === 'store'" class="auth-row">
            <label>目标门店</label>
            <OrganizationResourceSelector
              v-model="rolePublishModal.store_id"
              resource="store"
              picker-mode="modal"
              modal-title="选择目标门店"
              trigger-placeholder="请选择门店"
              placeholder="搜索门店名称"
              :multiple="false"
            />
          </div>
          <div v-else class="auth-row">
            <label>目标组织</label>
            <OrganizationResourceSelector
              v-model="rolePublishModal.org_id"
              resource="organization"
              picker-mode="modal"
              modal-title="选择目标组织"
              trigger-placeholder="请选择组织"
              placeholder="搜索组织"
              :multiple="false"
            />
          </div>
          <div class="auth-row">
            <label>渠道</label>
            <select v-model="rolePublishModal.channel" :disabled="writeSubmitting">
              <option value="store_backend">历史门店模板</option>
              <option value="cashier">历史收银模板</option>
            </select>
          </div>
          <div class="auth-row">
            <label>允许门店选用</label>
            <select v-model.number="rolePublishModal.allow_store_select" :disabled="writeSubmitting">
              <option :value="1">是</option>
              <option :value="0">否</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="button secondary" type="button" :disabled="writeSubmitting" @click="closeRolePublishForm">取消</button>
          <button class="button primary" type="button" :disabled="!canWrite || writeSubmitting" @click="submitRolePublish">{{ writeSubmitting ? '发布中…' : '确认发布' }}</button>
        </div>
      </div>
    </div>

    <div v-if="rolePublishModal.recordsOpen" class="modal-layer open" @click.self="closeRolePublishRecords">
      <div class="modal-dialog modal-wide" role="dialog" aria-modal="true" style="width:960px;max-width:96vw;" @click.stop>
        <div class="modal-head">
          <h2>已发布记录{{ rolePublishModal.selected ? (' · ' + rolePublishModal.selected.role_name) : '' }}</h2>
          <button class="icon-button" type="button" @click="closeRolePublishRecords"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <table class="data-table">
            <thead><tr><th>ID</th><th>门店</th><th>渠道</th><th>门店角色</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="p in rolePublishModal.publishes" :key="p.id">
                <td>{{ p.id }}</td>
                <td>{{ p.store_name || ('门店#' + p.store_id) }}</td>
                <td>{{ p.channel === 'cashier' ? '历史收银模板' : '历史门店模板' }}</td>
                <td>{{ p.store_role_name || p.store_role_id }}</td>
                <td>{{ Number(p.status)===1?'有效':'停用' }}</td>
                <td>
                  <button v-if="Number(p.status)===1" class="table-action" type="button" :disabled="!canWrite || writeSubmitting" @click="disableRolePublishRow(p)">停用</button>
                  <span v-else>—</span>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="!rolePublishModal.publishes.length" class="empty-inline">该模板暂无发布记录</div>
        </div>
        <div class="modal-footer">
          <button class="button secondary" type="button" @click="closeRolePublishRecords">关闭</button>
        </div>
      </div>
    </div>

    <div v-if="jobPositionModal.open" class="modal-layer open" @click.self="closeJobPositionModal">
      <div class="modal-dialog modal-wide" role="dialog" aria-modal="true" style="width:1274px;max-width:96vw;" @click.stop>
        <div class="modal-head">
          <h2>岗位策略管理</h2>
          <button class="icon-button" type="button" @click="closeJobPositionModal"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <div class="permission-note"><strong>做什么</strong><span>岗位决定员工可以操作哪些功能，人员数据权限决定员工可以看到哪些数据。人员只选择岗位，不再单独选择角色模板。</span></div>
          <div class="permission-note"><strong>门店可用</strong><span>开启后，门店在新建或编辑本店员工时可以选择这个岗位；关闭后，只有总部可以配置，已经绑定的人员不会自动失去权限。平台后台权限只在总部平台生效。</span></div>
          <div class="jp-toolbar">
            <div class="input-shell small jp-search">
              <svg-icon name="search" />
              <input v-model.trim="jobPositionModal.keyword" type="search" placeholder="搜索岗位名称" @keyup.enter="loadJobPositionList" />
            </div>
            <select v-model="jobPositionModal.status" class="jp-status-filter" aria-label="岗位启用状态" @change="loadJobPositionList">
              <option :value="1">启用</option>
              <option value="">全部</option>
            </select>
            <button class="button primary compact-button" type="button" @click="loadJobPositionList">查询 <span class="enter-key">↵</span></button>
            <button class="button primary compact-button" type="button" :disabled="!canWrite || writeSubmitting" @click="openCreateJobPosition">新建岗位</button>
          </div>
          <div class="job-list-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>岗位名称</th>
                  <th>启用状态</th>
                  <th>门店可用</th>
                  <th>适用端</th>
                  <th>操作</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in jobPositionModal.list" :key="row.id">
                  <td>{{ row.name }}</td>
                  <td>
                    <label
                      class="jp-switch"
                      :class="{ 'is-on': Number(row.status) === 1, 'is-busy': isJobPositionToggleBusy(row.id), 'is-disabled': !canWrite || writeSubmitting || isJobPositionToggleBusy(row.id) }"
                      :title="Number(row.status) === 1 ? '当前已启用：关闭后将阻止门店新增选择该岗位' : '当前已停用：开启后，若门店可用则门店可选'"
                      :aria-label="Number(row.status) === 1 ? '启用状态：启用，点击可停用' : '启用状态：停用，点击可启用'"
                      @click.prevent="onJobPositionStatusSwitch(row)"
                    >
                      <input
                        class="jp-switch-input"
                        type="checkbox"
                        :checked="Number(row.status) === 1"
                        tabindex="-1"
                        :aria-checked="Number(row.status) === 1 ? 'true' : 'false'"
                        :disabled="!canWrite || writeSubmitting || isJobPositionToggleBusy(row.id)"
                      />
                      <span class="jp-switch-track" aria-hidden="true"><span class="jp-switch-thumb" /></span>
                      <span class="jp-switch-text">{{ Number(row.status) === 1 ? '启用' : '停用' }}</span>
                    </label>
                  </td>
                  <td>
                    <label
                      class="jp-switch"
                      :class="{ 'is-on': Number(row.allow_store_select) === 1, 'is-busy': isJobPositionToggleBusy(row.id), 'is-disabled': !canWrite || writeSubmitting || isJobPositionToggleBusy(row.id) || Number(row.status) !== 1 }"
                      :title="jobPositionAllowSelectTooltip(row)"
                      :aria-label="Number(row.allow_store_select) === 1 ? '门店可用：可用，点击可改为不可用' : '门店可用：不可用，点击可改为可用'"
                      @click.prevent="onJobPositionAllowSelectSwitch(row)"
                    >
                      <input
                        class="jp-switch-input"
                        type="checkbox"
                        :checked="Number(row.allow_store_select) === 1"
                        tabindex="-1"
                        :aria-checked="Number(row.allow_store_select) === 1 ? 'true' : 'false'"
                        :disabled="!canWrite || writeSubmitting || isJobPositionToggleBusy(row.id) || Number(row.status) !== 1"
                      />
                      <span class="jp-switch-track" aria-hidden="true"><span class="jp-switch-thumb" /></span>
                      <span class="jp-switch-text">{{ Number(row.allow_store_select) === 1 ? '可用' : '不可用' }}</span>
                    </label>
                  </td>
                  <td><span class="jp-channels">{{ jobPositionChannelsLabel(row) }}</span></td>
                  <td><button class="table-action" type="button" @click="openEditJobPosition(row)">编辑</button></td>
                </tr>
              </tbody>
            </table>
            <div v-if="!jobPositionModal.list.length" class="empty-inline">暂无岗位</div>
          </div>
        </div>
      </div>
    </div>

    <JobPositionFormModal
      ref="jobPositionFormModal"
      v-model="jobPositionModal.formOpen"
      :position-id="jobPositionModal.selectedId"
      :submitting="writeSubmitting"
      @save="onJobPositionFormSave"
    />

    <div
      v-if="jobPositionConfirmModal.open"
      class="modal-layer open jp-confirm-layer"
      @click.self="resolveJobPositionConfirm(false)"
    >
      <div class="modal-dialog modal-dialog-sm" role="dialog" aria-modal="true" @click.stop>
        <div class="modal-head">
          <h2>确认操作</h2>
          <button class="icon-button" type="button" @click="resolveJobPositionConfirm(false)" aria-label="关闭"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <p>{{ jobPositionConfirmModal.content }}</p>
        </div>
        <div class="modal-footer">
          <button class="button secondary" type="button" @click="resolveJobPositionConfirm(false)">取消</button>
          <button class="button primary" type="button" @click="resolveJobPositionConfirm(true)">确定</button>
        </div>
      </div>
    </div>

    <div v-if="opsConfirmModal.open" class="modal-layer open" @click.self="closeOpsConfirm">
      <div class="modal-dialog" role="dialog" aria-modal="true" @click.stop>
        <div class="modal-head">
          <h2>{{ opsConfirmTitle }}</h2>
          <button class="icon-button" type="button" @click="closeOpsConfirm"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <p>{{ opsConfirmBody }}</p>
          <div class="auth-row" style="margin-top:12px;">
            <label>原因</label>
            <input v-model.trim="opsConfirmModal.reason" type="text" placeholder="请填写原因" :disabled="writeSubmitting" />
          </div>
        </div>
        <div class="modal-foot">
          <button class="button secondary" type="button" :disabled="writeSubmitting" @click="closeOpsConfirm">取消</button>
          <button class="button primary" type="button" :disabled="writeSubmitting" @click="submitOpsConfirm">确认</button>
        </div>
      </div>
    </div>

    <div v-if="tenureConfirmModal.open" class="modal-layer open" @click.self="closeTenureConfirm">
      <div class="modal-dialog" role="dialog" aria-modal="true" @click.stop>
        <div class="modal-head">
          <h2>{{ tenureConfirmTitle }}</h2>
          <button class="icon-button" type="button" @click="closeTenureConfirm"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <p>{{ tenureConfirmBody }}</p>
          <div class="auth-row" style="margin-top:12px;">
            <label>原因</label>
            <input v-model.trim="tenureConfirmModal.reason" type="text" placeholder="请填写原因" :disabled="writeSubmitting" />
          </div>
        </div>
        <div class="modal-foot">
          <button class="button secondary" type="button" :disabled="writeSubmitting" @click="closeTenureConfirm">取消</button>
          <button class="button primary" type="button" :disabled="writeSubmitting" @click="submitTenureConfirm">确认</button>
        </div>
      </div>
    </div>

    <staff-form-modal
      v-model="staffModal.open"
      scene="organization"
      :edit-id="staffModal.editId"
      :staff-id="staffModal.staffId"
      :default-store-id="staffModal.defaultStoreId"
      :allowed-store-ids="staffModal.allowedStoreIds"
      :default-org-id="staffModal.defaultOrgId"
      :can-save="canEditStaff"
      :save-deny-tip="staffSaveDenyTip"
      @success="onStaffCreated"
    />
    <role-template-form-modal
      v-model="rolePublishModal.editOpen"
      :template-id="rolePublishModal.editId"
      @success="onRoleTemplateSaved"
    />
    <store-form-modal
      v-model="storeModal.open"
      :default-region-id="storeModal.organizationId"
      @success="onStoreCreated"
    />

    <div v-if="orgFormModal.open" class="modal-layer open" @click.self="closeOrgFormModal">
      <div class="modal-dialog" role="dialog" aria-modal="true" @click.stop>
        <div class="modal-head">
          <h2>{{ orgFormModal.mode === 'create' ? '新增组织' : '编辑组织' }}</h2>
          <button class="icon-button" type="button" @click="closeOrgFormModal"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-field full">
              <label><em>*</em>上级组织</label>
              <select v-model.number="orgFormModal.pid" :disabled="orgFormParentDisabled || writeSubmitting">
                <option v-for="opt in orgFormParentOptions" :key="`pid-${opt.id}`" :value="Number(opt.id)">{{ opt.name }}</option>
              </select>
            </div>
            <div class="form-field full">
              <label><em>*</em>组织名称</label>
              <input v-model.trim="orgFormModal.name" type="text" placeholder="请输入组织名称" :disabled="writeSubmitting" />
            </div>
            <div class="form-field">
              <label>排序</label>
              <input v-model.number="orgFormModal.sort" type="number" min="0" step="1" placeholder="0" :disabled="writeSubmitting" />
              <span class="field-hint">数字越小越靠前</span>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="button secondary" type="button" :disabled="writeSubmitting" @click="closeOrgFormModal">取消</button>
          <button class="button primary" type="button" :disabled="writeSubmitting" @click="submitOrgForm">{{ writeSubmitting ? '保存中…' : '保存' }}</button>
        </div>
      </div>
    </div>

    <div v-if="deleteModal.open" class="modal-layer open" @click.self="closeDeleteModal">
      <div class="modal-dialog modal-dialog-sm" role="dialog" aria-modal="true" @click.stop>
        <div class="modal-head">
          <h2>{{ deleteModal.step === 1 ? '删除组织' : '再次确认删除' }}</h2>
          <button class="icon-button" type="button" @click="closeDeleteModal"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <p v-if="deleteModal.loading">正在检查下级组织、门店、负责人和管理授权…</p>
          <template v-else-if="deleteModal.blockers.length">
            <p>当前不能删除组织「{{ deleteModal.name }}」，请先处理以下关联关系：</p>
            <div v-for="blocker in deleteModal.blockers" :key="blocker.type" class="permission-note">
              <strong>{{ blocker.title }}</strong><span>{{ (blocker.items || []).join('、') }}</span>
            </div>
          </template>
          <p v-else-if="deleteModal.error">{{ deleteModal.error }}</p>
          <p v-else-if="deleteModal.step === 1">确定要删除组织「{{ deleteModal.name }}」吗？删除后不可通过本操作恢复。</p>
          <p v-else>此操作不可恢复，请再次确认删除组织「{{ deleteModal.name }}」。</p>
        </div>
        <div class="modal-footer">
          <button class="button secondary" type="button" :disabled="writeSubmitting" @click="closeDeleteModal">取消</button>
          <button v-if="deleteModal.step === 1" class="button secondary danger-button" type="button" :disabled="writeSubmitting || deleteModal.loading || !deleteModal.canDelete" @click="deleteModal.step = 2">{{ deleteModal.canDelete ? '继续删除' : '存在阻断项' }}</button>
          <button v-else class="button primary danger-button" type="button" :disabled="writeSubmitting" @click="submitDeleteOrg">{{ writeSubmitting ? '删除中…' : '确认删除' }}</button>
        </div>
      </div>
    </div>

    <div v-if="orgDirectModal.open" class="modal-layer open" @click.self="orgDirectModal.open = false">
      <div class="modal-dialog modal-wide" role="dialog" aria-modal="true" style="width:720px;max-width:96vw;" @click.stop>
        <div class="modal-head">
          <h2>组织直属人员</h2>
          <button class="icon-button" type="button" @click="orgDirectModal.open = false"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <p class="muted">“移除名册”只删除组织名册关系，不影响门店任职或组织管理授权；完整移除会同时撤销本组织的管理授权。</p>
          <div class="list-toolbar" style="margin-bottom:12px;">
            <input v-model.trim="orgDirectModal.phone" class="input" placeholder="手机号" style="width:140px;" :disabled="!canEditStaff" />
            <input v-model.trim="orgDirectModal.name" class="input" placeholder="姓名" style="width:120px;" :disabled="!canEditStaff" />
            <input v-model.trim="orgDirectModal.jobTitle" class="input" placeholder="组织岗位" style="width:120px;" :disabled="!canEditStaff" />
            <button class="button primary compact-button" type="button" :disabled="!canEditStaff || writeSubmitting" @click="saveOrgDirect">添加/更新</button>
          </div>
          <table class="data-table">
            <thead><tr><th>姓名</th><th>手机号</th><th>岗位</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="row in orgDirectModal.list" :key="row.id">
                <td>{{ row.name }}</td>
                <td>{{ row.phone }}</td>
                <td>{{ row.job_title || '—' }}</td>
                <td>
                  <button class="table-action" type="button" :disabled="!canEditStaff" @click="removeOrgDirect(row)">移除名册</button>
                  <button v-if="canWrite" class="table-action danger-text" type="button" :disabled="writeSubmitting" @click="removeOrgDirectCompletely(row)">完整移除</button>
                  <button class="table-action" type="button" :disabled="!canEditStaff" @click="leaveEmployeeGlobal(row)">全局离职</button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="!orgDirectModal.list.length" class="empty-inline">暂无组织直属人员</div>
        </div>
      </div>
    </div>

    <div v-if="createTransferModal.open" class="modal-layer open" @click.self="closeCreateTransferApply">
      <div class="modal-dialog" role="dialog" aria-modal="true" style="width:520px;max-width:96vw;" @click.stop>
        <div class="modal-head">
          <h2>发起调店申请</h2>
          <button class="icon-button" type="button" @click="closeCreateTransferApply"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <div class="permission-note"><strong>员工</strong><span>{{ createTransferModal.personName }}</span></div>
          <div class="permission-note"><strong>当前门店</strong><span>{{ createTransferModal.fromStoreName || ('门店#' + createTransferModal.fromStoreId) }}</span></div>
          <div class="form-field" style="margin-top:12px">
            <label>目标门店</label>
            <select v-model.number="createTransferModal.toStoreId" :disabled="writeSubmitting">
              <option :value="0">请选择目标门店</option>
              <option
                v-for="s in createTransferStoreOptions"
                :key="`ct-${s.id}`"
                :value="Number(s.id)"
              >{{ s.name || ('门店#' + s.id) }}</option>
            </select>
          </div>
          <div class="form-field" style="margin-top:12px">
            <label>调店原因</label>
            <textarea v-model.trim="createTransferModal.reason" rows="3" placeholder="请填写调店原因" :disabled="writeSubmitting"></textarea>
          </div>
          <p class="muted" style="margin-top:10px">提交后需总部审批通过才会结束原店任职并在目标店生效，不会直接改任职。</p>
          <div class="auth-actions" style="margin-top:16px;justify-content:flex-end">
            <button class="button secondary compact-button" type="button" :disabled="writeSubmitting" @click="closeCreateTransferApply">取消</button>
            <button class="button primary compact-button" type="button" :disabled="writeSubmitting" @click="submitCreateTransferApply">{{ writeSubmitting ? '提交中…' : '提交申请' }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="transferModal.open" class="modal-layer open" @click.self="transferModal.open = false">
      <div class="modal-dialog modal-wide" role="dialog" aria-modal="true" style="width:960px;max-width:96vw;" @click.stop>
        <div class="modal-head">
          <h2>调店申请（总部审批）</h2>
          <button class="icon-button" type="button" @click="transferModal.open = false"><svg-icon name="x" /></button>
        </div>
        <div class="modal-body">
          <div class="list-toolbar" style="margin-bottom:12px;">
            <select v-model="transferModal.status" @change="loadTransferApplies">
              <option value="">全部状态</option>
              <option value="pending">待处理</option>
              <option value="executed">已执行</option>
              <option value="rejected">已驳回</option>
              <option value="cancelled">已取消</option>
            </select>
            <button class="button secondary compact-button" type="button" @click="loadTransferApplies">刷新</button>
          </div>
          <table class="data-table">
            <thead><tr><th>ID</th><th>员工</th><th>原店→目标店</th><th>原因</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="row in transferModal.list" :key="row.id">
                <td>{{ row.id }}</td>
                <td>{{ row.employee_id }} / staff {{ row.source_staff_id }}</td>
                <td>{{ row.from_store_id }} → {{ row.to_store_id }}</td>
                <td>{{ row.reason }}</td>
                <td>{{ row.status }}</td>
                <td>
                  <template v-if="row.status === 'pending'">
                    <button class="table-action" type="button" :disabled="!canWrite" @click="approveTransfer(row)">批准执行</button>
                    <button class="table-action" type="button" :disabled="!canWrite" @click="rejectTransfer(row)">驳回</button>
                  </template>
                  <span v-else>—</span>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="!transferModal.list.length" class="empty-inline">暂无调店申请</div>
          <p class="muted">批准时将要求选择目标门店角色；门店端发起申请不改任职。</p>
        </div>
      </div>
    </div>

    <div v-if="toastMessage" class="toast-stack"><div class="toast"><span class="toast-icon"><svg-icon name="alert" /></span><span>{{ toastMessage }}</span></div></div>
  </div>
</template>

<script>
import {
  getOrganizationTree,
  getOrganizationWorkspaceOverview,
  getOrganizationWorkspaceStores,
  getOrganizationWorkspaceEmployees,
  getOrganizationLeaderCandidates,
  getOrganizationWorkspacePermissions,
  getOrganizationAdminCandidates,
  getOrganizationChangeLog,
  getOrganizationWriteStatus,
  saveOrganization,
  deleteOrganization,
  getOrganizationDeleteBlockers,
  bindOrganizationStore,
  saveOrganizationLeaders,
  saveOrganizationAdminPermission,
  grantOrganizationAdmin,
  revokeOrganizationAdminGrant,
  getOrganizationOrgEmployees,
  saveOrganizationOrgEmployee,
  deleteOrganizationOrgEmployee,
  removeOrganizationEmployeeCompletely,
  leaveOrganizationEmployee,
  softDeleteOrganizationEmployeeArchive,
  getOrganizationTransferApplies,
  createOrganizationTransferApply,
  approveOrganizationTransferApply,
  rejectOrganizationTransferApply,
  getEmployeeAuthBundle,
  getEmployeeAuthAudits,
  saveEmployeeAuthPlatform,
  getRoleTemplates,
  getRoleTemplateDetail,
  saveRoleTemplate,
  disableRoleTemplate,
  publishRoleTemplate,
  disableRolePublish,
  getRolePublishes,
  getJobPositions,
  getJobPositionDetail,
  saveJobPosition,
  publishJobPosition,
  disableJobPositionPublish,
  saveEmployeeAuthJobs,
  saveEmployeeAuthDataScope,
  saveEmployeeAuthEntries,
  saveEmployeeAuthTenure,
  saveOrganizationOpsStatus,
  saveStoreOpsStatus
} from '@/api/store';
import SvgIcon from './components/SvgIcon';
import OrganizationTree from './components/OrganizationTree';
import StaffFormModal from '@/pages/setting/staff/add.vue';
import StoreFormModal from '../components/StoreFormModal';
import OrganizationResourceSelector from '@/components/organization/OrganizationResourceSelector.vue';
import RoleTemplateFormModal from './components/RoleTemplateFormModal.vue';
import JobPositionFormModal from './components/JobPositionFormModal.vue';
import { resolveOrgWriteToken } from '@/api/orgWriteHelpers';
import { merchantStoreListApi } from '@/api/setting';
import Setting from '@/setting';

const READONLY_TIP = '当前为组织架构只读阶段，写入未开放';
const UNKNOWN_WRITE_TIP = '提交结果未知，请确认是否已生效后再操作';

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

function emptyLoadState() {
  return { loading: false, visible: false, slow: false, error: '', seq: 0, timer1: null, timer8: null };
}

export default {
  name: 'OrganizationWorkspace',
  components: { SvgIcon, OrganizationTree, StaffFormModal, StoreFormModal, OrganizationResourceSelector, RoleTemplateFormModal, JobPositionFormModal },
  data() {
    return {
      orgs: [],
      selectedOrgId: 0,
      overview: null,
      treeSearch: '',
      personLocateKeyword: '',
      personLocateLoading: false,
      treeCollapsed: false,
      treeMobileOpen: false,
      heroMenuOpen: false,
      treeMenu: { open: false, node: null, x: 0, y: 0 },
      activeTab: 'overview',
      dataView: 'store',
      dataLayout: 'list',
      dataFilter: 'all',
      dataSearch: '',
      storeList: [],
      employeeList: [],
      listTotal: 0,
      listPage: 1,
      listLimit: 10,
      permissionLeaders: [],
      permissionHolders: [],
      logList: [],
      logTotal: 0,
      logPage: 1,
      logLimit: 10,
      logSearch: '',
      leaderCandidates: [],
      candidateTotal: 0,
      candidatePage: 1,
      candidateLimit: 10,
      storeStaffList: [],
      storeStaffTotal: 0,
      storeStaffPage: 1,
      storeStaffLimit: 10,
      toastMessage: '',
      toastTimer: null,
      drawer: { open: false, mode: '', title: '', kicker: '', store: null, person: null, search: '' },
      writeStatus: {
        write_enabled: false,
        can_write: false,
        reason_code: 'WRITE_DISABLED',
        reason_text: READONLY_TIP,
        source_mode: '',
        is_super_admin: false
      },
      draftLeaders: [],
      draftScopeMode: 'inherit',
      draftAllowedStoreIds: [],
      permissionStoreOptions: [],
      orgStoreOptions: [],
      permissionRangeReady: false,
      draftStoreOrgId: 0,
      pendingRequestToken: '',
      pendingWriteFingerprint: '',
      orgFormModal: { open: false, mode: 'create', orgId: 0, pid: 0, name: '', sort: 0 },
      deleteModal: { open: false, step: 1, orgId: 0, name: '', loading: false, canDelete: false, blockers: [], error: '' },
      writeSubmitting: false,
      treeState: emptyLoadState(),
      overviewState: emptyLoadState(),
      listState: emptyLoadState(),
      permState: emptyLoadState(),
      logState: emptyLoadState(),
      candidateState: emptyLoadState(),
      storeStaffState: emptyLoadState(),
      grantCandidateState: emptyLoadState(),
      staffModal: { open: false, editId: 0, staffId: 0, defaultStoreId: 0, allowedStoreIds: [], defaultOrgId: 0 },
      personAuth: {},
      personAuthLoading: false,
      jobPositionOptions: [],
      authForms: {
        platform: { admin_id: 0 },
        jobs: {},
        entries: {},
        dataScope: { scope_mode: 'personal', org_ids: [], store_ids: [] }
      },
      rolePublishModal: {
        open: false,
        formOpen: false,
        recordsOpen: false,
        editOpen: false,
        editId: 0,
        keyword: '',
        templates: [],
        selected: null,
        store_id: 0,
        store_ids: [],
        org_id: 0,
        scope_type: 'store',
        channel: 'store_backend',
        allow_store_select: 1,
        publishes: []
      },
      jobPositionModal: {
        open: false,
        formOpen: false,
        keyword: '',
        status: 1,
        list: [],
        selectedId: 0,
        help: {},
        togglingIds: {},
        form: {
          id: 0,
          name: '',
          status: 1,
          remark: '',
          allow_store_select: 0,
          use_platform: 0,
          use_store: 1,
          use_mobile: 0,
          channel_rules: {}
        },
        publish: { scope_type: 'store', scope_id: 0 },
        publishes: []
      },
      jobPositionConfirmModal: {
        open: false,
        content: '',
        resolver: null
      },
      opsConfirmModal: {
        open: false,
        target: 'org',
        id: 0,
        status: 0,
        name: '',
        reason: ''
      },
      tenureConfirmModal: {
        open: false,
        action: '',
        staff: null,
        reason: ''
      },
      personAudits: [],
      storeModal: { open: false, organizationId: 0, organizationName: '' },
      orgDirectModal: { open: false, list: [], phone: '', name: '', jobTitle: '' },
      transferModal: { open: false, list: [], status: 'pending', employeeId: 0 },
      createTransferModal: {
        open: false,
        personName: '',
        employeeId: 0,
        sourceStaffId: 0,
        fromStoreId: 0,
        fromStoreName: '',
        toStoreId: 0,
        reason: ''
      },
      /** 调店目标：全部门店（启用），不限当前组织 */
      transferAllStoreOptions: [],
      personOpsKey: '',
      grantSearch: '',
      grantCandidates: [],
      grantForm: { employee_id: 0, admin_id: 0, scope_mode: 'inherit', allowed_store_ids: [] },
      tabLoaded: { stores: false, employees: false, permissions: false, logs: false },
      filters: [
        { key: 'all', label: '全部' },
        { key: 'open', label: '营业中' },
        { key: 'closed', label: '停用/未营业' },
        { key: 'attention', label: '待完善' },
        { key: 'direct', label: '直属门店' }
      ],
      defaultAvatar: '/static/images/staff/avatar_male.png',
      READONLY_TIP
    };
  },
  computed: {
    canWrite() {
      return !!(this.writeStatus && this.writeStatus.can_write);
    },
    hasStaffMaintainAuth() {
      // fail-closed：access 缺失/空/异常默认不可编辑；仅明确总部超管或具备人员维护权限才放行
      const info = (this.$store.state.admin.user && this.$store.state.admin.user.info) || {};
      if (this.isExplicitHqSuperAdmin(info)) return true;
      // 后端写状态 can_write 已要求 level=0 超管（非信任前端自报）
      if (this.writeStatus && this.writeStatus.can_write) return true;
      const access = info.access;
      if (!Array.isArray(access) || access.length === 0) return false;
      return access.indexOf('setting-staff-index') !== -1;
    },
    canEditStaff() {
      // 人员保存：全局写门禁开启 + 人员维护功能权限；不再要求超管 can_write
      const writeEnabled = !!(this.writeStatus && this.writeStatus.write_enabled);
      return writeEnabled && this.hasStaffMaintainAuth;
    },
    staffSaveDenyTip() {
      if (!(this.writeStatus && this.writeStatus.write_enabled)) {
        return (this.writeStatus && this.writeStatus.reason_text) || READONLY_TIP;
      }
      if (!this.hasStaffMaintainAuth) {
        return '当前岗位未配置“人员维护”权限，请联系总部管理员授权。';
      }
      return '当前岗位未配置“人员维护”权限，请联系总部管理员授权。';
    },
    rootOrgs() {
      return this.orgs.filter((org) => !org.parentId);
    },
    selectedOrg() {
      const fromTree = this.orgById(this.selectedOrgId) || {};
      const ov = this.overview || {};
      return {
        id: ov.org_id || fromTree.id || 0,
        name: ov.org_name || fromTree.name || '',
        pid: ov.pid != null ? ov.pid : (fromTree.parentId || 0),
        sort: ov.sort != null ? ov.sort : (fromTree.sort || 0),
        updated: ov.update_time_text || fromTree.updated || '',
        directStores: ov.direct_store_count != null ? ov.direct_store_count : (fromTree.directStores || 0),
        stores: ov.all_store_count != null ? ov.all_store_count : (fromTree.stores || 0),
        employees: ov.employee_count != null ? ov.employee_count : (fromTree.employees || 0),
        leaders: ov.leaders || [],
        attention: (ov.attention && ov.attention.total) || fromTree.attention || 0
      };
    },
    parentOrgName() {
      const pid = this.selectedOrg.pid;
      if (!pid) return '—';
      const p = this.orgById(pid);
      return (p && p.name) || '—';
    },
    selectedPath() {
      if (this.overview && this.overview.breadcrumb && this.overview.breadcrumb.length) {
        return this.overview.breadcrumb;
      }
      const path = [];
      const visited = new Set();
      let current = this.orgById(this.selectedOrgId);
      while (current && current.id != null) {
        const id = Number(current.id);
        if (visited.has(id)) break;
        visited.add(id);
        path.unshift({ id: current.id, name: current.name });
        if (!current.parentId) break;
        current = this.orgById(current.parentId);
      }
      return path;
    },
    childOrgs() {
      if (this.overview && this.overview.child_orgs) {
        return this.overview.child_orgs.map((c) => ({
          id: c.id,
          name: c.name,
          stores: c.all_store_count,
          employees: c.employee_count,
          attention: c.attention_count
        }));
      }
      return this.orgs
        .filter((org) => Number(org.parentId) === Number(this.selectedOrgId))
        .map((c) => ({
          id: c.id,
          name: c.name,
          stores: c.stores,
          employees: c.employees,
          attention: c.attention
        }));
    },
    selectedLeaders() {
      return (this.overview && this.overview.leaders) || [];
    },
    focusStores() {
      return (this.overview && this.overview.focus_stores) || [];
    },
    attentionTotal() {
      return (this.overview && this.overview.attention && this.overview.attention.total) || 0;
    },
    attentionSummaryText() {
      const a = (this.overview && this.overview.attention) || {};
      const parts = [];
      if (a.no_leader_org_count) parts.push(`${a.no_leader_org_count} 个组织未设置负责人`);
      if (a.no_manager_store_count) parts.push(`${a.no_manager_store_count} 家营业门店未设置店长/副店长`);
      return parts.join('，') || '存在待完善项';
    },
    tabs() {
      return [
        { key: 'overview', label: '概况' },
        { key: 'stores', label: '门店', count: this.selectedOrg.stores },
        { key: 'employees', label: '员工', count: this.selectedOrg.employees },
        { key: 'permissions', label: '权限范围' },
        { key: 'logs', label: '变更记录' }
      ];
    },
    personOrganizationAdminGrants() {
      const grants = [];
      const seen = new Set();
      ((this.personAuth && this.personAuth.platform) || []).forEach((account) => {
        (account.org_admins || []).forEach((grant) => {
          const orgAdminId = Number(grant.id || grant.org_admin_id || 0);
          if (!orgAdminId || seen.has(orgAdminId)) return;
          seen.add(orgAdminId);
          grants.push({
            ...grant,
            org_admin_id: orgAdminId,
            org_id: Number(grant.org_id || 0),
            account: account.account || account.real_name || ''
          });
        });
      });
      return grants;
    },
    metrics() {
      return [
        { label: '下级组织', value: this.childOrgs.length, unit: '个', icon: 'branch', color: '#eeeeff', ink: '#5b5bd6' },
        { label: '直属门店', value: this.selectedOrg.directStores, unit: '家', icon: 'store', color: '#e8f7f3', ink: '#1f9d70' },
        { label: '全部门店', value: this.selectedOrg.stores, unit: '家', icon: 'building', color: '#ebf4ff', ink: '#4384cf' },
        { label: '在职人员', value: this.selectedOrg.employees, unit: '人', icon: 'users', color: '#fff3e8', ink: '#d9822b' },
        { label: '本级负责人', value: this.selectedLeaders.length, unit: '人', icon: 'shield', color: '#f5efff', ink: '#8854c7' }
      ];
    },
    roleLegend() {
      return (this.overview && this.overview.position_distribution) || [];
    },
    donutBackground() {
      const total = this.roleLegend.reduce((sum, item) => sum + item.count, 0) || 1;
      let start = 0;
      const stops = this.roleLegend.map((item) => {
        const end = start + (item.count / total) * 100;
        const stop = `${item.color} ${start.toFixed(1)}% ${end.toFixed(1)}%`;
        start = end;
        return stop;
      });
      return stops.length ? `conic-gradient(${stops.join(',')})` : '#eef1f6';
    },
    orgFormParentOptions() {
      const modal = this.orgFormModal;
      if (!modal.open) return [];
      if (modal.mode === 'create') {
        if (!this.rootOrgs.length) {
          return [{ id: 0, name: '（创建最高级组织）' }];
        }
        return this.orgs.map((o) => ({ id: Number(o.id), name: o.name }));
      }
      const orgId = Number(modal.orgId);
      const isRoot = this.rootOrgs.some((r) => Number(r.id) === orgId);
      if (isRoot) return [{ id: 0, name: '最高级组织' }];
      const excluded = new Set([orgId, ...this.collectDescendantIds(orgId)]);
      return this.orgs
        .filter((o) => !excluded.has(Number(o.id)))
        .map((o) => ({ id: Number(o.id), name: o.name }));
    },
    orgFormParentDisabled() {
      if (!this.orgFormModal.open || this.orgFormModal.mode !== 'edit') return false;
      return this.rootOrgs.some((r) => Number(r.id) === Number(this.orgFormModal.orgId));
    },
    opsConfirmTitle() {
      const m = this.opsConfirmModal;
      if (m.target === 'store') return Number(m.status) === 1 ? '恢复门店' : '停用门店';
      return Number(m.status) === 1 ? '恢复组织' : '停用组织';
    },
    opsConfirmBody() {
      const m = this.opsConfirmModal;
      const name = m.name || '';
      if (m.target === 'store') {
        return Number(m.status) === 1
          ? `确认恢复门店「${name}」？恢复后，原任职与授权按各自有效状态继续生效；若所属组织仍停用，业务仍不可用。历史数据不受影响。`
          : `确认停用门店「${name}」？停用后门店端、手机端业务入口立即不可用，禁止新增业务写入；历史数据保留，总部仍可查询并恢复。`;
      }
      return Number(m.status) === 1
        ? `确认恢复组织「${name}」？恢复上级后，原本单独停用的下级组织和门店仍保持停用，不会一并自动恢复。`
        : `确认停用组织「${name}」？停用后，下级组织与所属门店将继承停用（业务入口立即不可用）；不会批量改写下级自身状态。历史数据保留。`;
    },
    tenureConfirmTitle() {
      const action = this.tenureConfirmModal.action;
      if (action === 'resume') return '本店复职确认';
      if (action === 'delete') return '删除本店关系确认';
      return '本店停职确认';
    },
    tenureConfirmBody() {
      const staff = this.tenureConfirmModal.staff || {};
      const storeName = staff.store_name || '本店';
      const action = this.tenureConfirmModal.action;
      if (action === 'resume') {
        return `确认恢复「${storeName}」任职？复职会新开任职期间，不会自动恢复岗位和入口，需重新设置。其他门店不受影响。`;
      }
      if (action === 'delete') {
        return `确认删除「${storeName}」关系？删除后本人看不到本店历史；其他门店不受影响；门店订单和账务仍保留。此操作会建立人员-门店隔离，避免组织范围重新暴露旧历史。`;
      }
      return `确认办理「${storeName}」停职？停职只关闭本店任职、岗位、入口和本店来源数据授权，不影响其他门店与员工主档，历史订单仍保留。`;
    },
    currentPersonTenure() {
      const list = (this.personAuth && this.personAuth.store_assignments) || [];
      return list.find((a) => Number(a.status) === 1) || null;
    },
    currentTenureStartTime() {
      const cur = this.currentPersonTenure;
      if (!cur) return 0;
      const periods = Array.isArray(cur.tenure_periods) ? cur.tenure_periods : [];
      const active = periods.find((p) => Number(p.status) === 1 && !Number(p.end_time)) || periods[0];
      return Number((active && active.start_time) || cur.add_time || 0);
    },
    personOrgNamesText() {
      const dirs = (this.personAuth && this.personAuth.direct_memberships) || [];
      const names = dirs.map((d) => d.org_name).filter(Boolean);
      if (names.length) return names.join('、');
      const person = (this.drawer && this.drawer.person) || {};
      const fromList = (person.direct_memberships || []).map((d) => d.org_name).filter(Boolean);
      if (fromList.length) return fromList.join('、');
      return (this.selectedOrg && this.selectedOrg.name) || '—';
    },
    personTenureHistoryRows() {
      const assignments = (this.personAuth && this.personAuth.store_assignments) || [];
      const storeNameMap = {};
      const assignmentByStaffId = {};
      assignments.forEach((a) => {
        storeNameMap[Number(a.store_id)] = a.store_name || (`门店#${a.store_id}`);
        assignmentByStaffId[Number(a.id || a.staff_id || 0)] = a;
      });
      const rows = [];
      const tenure = (this.personAuth && this.personAuth.tenure) || [];
      tenure.forEach((p, idx) => {
        const storeId = Number(p.store_id || 0);
        const start = Number(p.start_time || 0);
        const end = Number(p.end_time || 0);
        const statusOn = Number(p.status) === 1 && !end;
        const staffId = Number(p.staff_id || 0);
        const assignment = assignmentByStaffId[staffId] || null;
        rows.push({
          key: `t-${p.id || idx}`,
          staff_id: staffId,
          staff: assignment,
          store_name: storeNameMap[storeId] || (`门店#${storeId}`),
          period_text: `${this.formatUnixTime(start)} ~ ${end ? this.formatUnixTime(end) : '至今'}`,
          reason: p.reason || this.tenureActionLabel(p.action),
          status_text: statusOn ? '当前有效' : (this.tenureActionLabel(p.action) || '已结束'),
          can_resume: !statusOn && !!assignment && Number(assignment.status) !== 1
        });
      });
      if (!rows.length) {
        assignments.filter((a) => Number(a.status) !== 1).forEach((a) => {
          rows.push({
            key: `a-${a.id}`,
            store_name: a.store_name || (`门店#${a.store_id}`),
            period_text: `${this.formatUnixTime(a.add_time)} ~ —`,
            reason: '历史任职',
            status_text: '已结束'
          });
        });
      }
      return rows;
    },
    platformEntryText() {
      const list = (this.personAuth && this.personAuth.platform) || [];
      if (!list.length) return '未绑定平台账号';
      const on = list.some((p) => Number(p.status) === 1);
      return on ? '入口已开通' : '入口已关闭';
    },
    createTransferStoreOptions() {
      const fromId = Number(this.createTransferModal.fromStoreId || 0);
      const opts = Array.isArray(this.transferAllStoreOptions) && this.transferAllStoreOptions.length
        ? this.transferAllStoreOptions
        : (Array.isArray(this.orgStoreOptions) ? this.orgStoreOptions : []);
      return opts.filter((s) => Number(s.id) !== fromId);
    }
  },
  created() {
    const entryTab = String((this.$route && this.$route.query && this.$route.query.tab) || '');
    if (entryTab === 'stores') {
      this.$router.replace(`${Setting.roterPre}/store/store/index`);
      return;
    }
    if (entryTab === 'people' || entryTab === 'employees') {
      this.$router.replace(`${Setting.roterPre}/setting/staff/index`);
      return;
    }
    this.applyReturnQuery();
    this.loadWriteStatus().finally(() => {
      this.loadTree();
    });
    this._onDocClickCloseOps = (e) => {
      const t = e && e.target;
      if (!t || !t.closest) {
        this.closePersonOps();
        return;
      }
      if (!t.closest('.ops-more') && !t.closest('[data-person-ops]')) {
        this.closePersonOps();
      }
    };
    document.addEventListener('click', this._onDocClickCloseOps);
  },
  activated() {
    this.applyReturnQuery(true);
  },
  watch: {
    '$route.query'(val, oldVal) {
      const entryTab = String((val && val.tab) || '');
      if (entryTab === 'stores') {
        this.$router.replace(`${Setting.roterPre}/store/store/index`);
        return;
      }
      if (entryTab === 'people' || entryTab === 'employees') {
        this.$router.replace(`${Setting.roterPre}/setting/staff/index`);
        return;
      }
      const r = Number((val && val._r) || 0);
      const oldR = Number((oldVal && oldVal._r) || 0);
      if (r && r !== oldR) {
        this.applyReturnQuery(true);
        return;
      }
      const tab = String((val && val.tab) || '');
      const oldTab = String((oldVal && oldVal.tab) || '');
      const orgId = Number((val && (val.org_id || val.return_org_id)) || 0);
      const oldOrgId = Number((oldVal && (oldVal.org_id || oldVal.return_org_id)) || 0);
      if (tab !== oldTab || orgId !== oldOrgId) {
        this.applyReturnQuery(false);
        if ((this.activeTab === 'stores' || this.activeTab === 'employees') && this.selectedOrgId) {
          this.reloadCurrentList();
        }
      }
    }
  },
  beforeDestroy() {
    if (this._onDocClickCloseOps) {
      document.removeEventListener('click', this._onDocClickCloseOps);
    }
    clearTimeout(this.toastTimer);
    this.clearLoadTimers(this.treeState);
    this.clearLoadTimers(this.overviewState);
    this.clearLoadTimers(this.listState);
    this.clearLoadTimers(this.permState);
    this.clearLoadTimers(this.logState);
    this.clearLoadTimers(this.candidateState);
    this.clearLoadTimers(this.storeStaffState);
    this.clearLoadTimers(this.grantCandidateState);
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
    applyReturnQuery(forceRefresh) {
      const q = (this.$route && this.$route.query) || {};
      const orgId = Number(q.org_id || q.return_org_id || 0);
      const tab = String(q.tab || '');
      const refreshKey = Number(q._r || 0);
      if (orgId > 0) {
        this.selectedOrgId = orgId;
      }
      if (tab === 'stores' || tab === 'store') {
        this.activeTab = 'stores';
        this.dataView = 'store';
      } else if (tab === 'people' || tab === 'employees') {
        this.activeTab = 'employees';
        this.dataView = 'people';
      } else if (tab === 'permissions' || tab === 'permission') {
        this.activeTab = 'permissions';
      }
      if (forceRefresh || refreshKey > 0) {
        this.tabLoaded = { stores: false, employees: false, permissions: false, logs: false };
        if (this.orgs.length) {
          this.loadTree();
        }
      }
    },
    expandOrgAncestors(orgId) {
      let current = this.orgById(orgId);
      const guard = new Set();
      while (current && current.parentId && !guard.has(Number(current.id))) {
        guard.add(Number(current.id));
        const parent = this.orgById(current.parentId);
        if (!parent) break;
        this.$set(parent, 'open', true);
        current = parent;
      }
      const self = this.orgById(orgId);
      if (self) this.$set(self, 'open', true);
    },
    pickLocatePerson(list, keyword) {
      const kw = String(keyword || '').trim();
      const rows = Array.isArray(list) ? list : [];
      if (!rows.length) return null;
      const exact = rows.find((p) => String(p.name || '') === kw);
      if (exact) return exact;
      const nameHit = rows.find((p) => String(p.name || '').indexOf(kw) >= 0);
      if (nameHit) return nameHit;
      return rows[0];
    },
    resolvePersonOrgId(person) {
      if (!person) return 0;
      const directs = person.direct_memberships || [];
      if (directs.length && Number(directs[0].org_id) > 0) {
        return Number(directs[0].org_id);
      }
      const assigns = person.assignments || [];
      for (let i = 0; i < assigns.length; i++) {
        const oid = Number(assigns[i].org_id || 0);
        if (oid > 0) return oid;
      }
      return 0;
    },
    locatePersonInTree() {
      const keyword = String(this.personLocateKeyword || '').trim();
      if (!keyword) {
        this.showToast('请输入姓名或手机号');
        return;
      }
      const rootId = Number((this.rootOrgs[0] && this.rootOrgs[0].id) || 0);
      if (!rootId) {
        this.showToast('组织树未加载完成');
        return;
      }
      if (this.personLocateLoading) return;
      this.personLocateLoading = true;
      getOrganizationWorkspaceEmployees({
        org_id: rootId,
        scope: 'all',
        keyword,
        page: 1,
        limit: 50
      })
        .then((res) => {
          const list = (res.data && res.data.list) || [];
          const person = this.pickLocatePerson(list, keyword);
          if (!person) {
            this.showToast('未找到该人员');
            return;
          }
          const orgId = this.resolvePersonOrgId(person);
          if (!orgId || !this.orgById(orgId)) {
            this.showToast('未找到该人员所属组织');
            return;
          }
          this.focusOrgPerson(orgId, keyword, person.name || keyword);
        })
        .catch((err) => {
          this.showToast((err && err.msg) || '查找人员失败');
        })
        .finally(() => {
          this.personLocateLoading = false;
        });
    },
    focusOrgPerson(orgId, keyword, personName) {
      const next = Number(orgId);
      if (!next) return;
      this.expandOrgAncestors(next);
      this.selectedOrgId = next;
      this.activeTab = 'employees';
      this.dataView = 'people';
      this.dataFilter = 'all';
      this.dataSearch = String(keyword || '');
      this.listPage = 1;
      this.tabLoaded.employees = false;
      this.closeMenus();
      this.closeMobileTree();
      this.loadOverview();
      this.loadEmployees();
      this.showToast(`已定位：${personName || keyword}`);
    },
    goCreateStore() {
      const orgId = Number(this.selectedOrgId || 0);
      if (!orgId) {
        this.showToast('请先选择组织');
        return;
      }
      const orgName = (this.selectedOrg && this.selectedOrg.name) ||
        ((this.orgs || []).find((o) => Number(o.id) === orgId) || {}).name ||
        '';
      this.storeModal = {
        open: true,
        organizationId: orgId,
        organizationName: String(orgName || '')
      };
    },
    onStoreCreated() {
      this.storeModal.open = false;
      this.activeTab = 'stores';
      this.dataView = 'store';
      this.tabLoaded.stores = false;
      this.tabLoaded.employees = false;
      this.loadTree();
      this.loadOverview();
      this.loadStores();
    },
    resolveAllowedStoreIds() {
      const fromOptions = (this.orgStoreOptions || []).map((s) => Number(s.id)).filter((id) => id > 0);
      if (fromOptions.length) return fromOptions;
      return (this.storeList || []).map((s) => Number(s.id)).filter((id) => id > 0);
    },
    ensureOrgStoreOptions() {
      if ((this.orgStoreOptions || []).length) {
        return Promise.resolve(this.orgStoreOptions);
      }
      const orgId = Number(this.selectedOrgId || 0);
      if (!orgId) return Promise.resolve([]);
      return getOrganizationWorkspacePermissions(orgId).then((res) => {
        const data = res.data || {};
        this.orgStoreOptions = data.org_store_options || [];
        return this.orgStoreOptions;
      });
    },
    openCreateStaff(store) {
      // 建档入口属于当前组织范围，不要求先点到最末级门店；
      // 页面尚未完成首个组织选中时，使用已加载的第一个根组织作为默认范围。
      const orgId = Number(this.selectedOrgId || (this.rootOrgs[0] && this.rootOrgs[0].id) || 0);
      if (!orgId) {
        this.showToast('请先选择组织');
        return;
      }
      if (!this.selectedOrgId) this.selectedOrgId = orgId;
      if (!this.canEditStaff) {
        this.showToast(this.staffSaveDenyTip);
        return;
      }
      this.ensureOrgStoreOptions()
        .then((options) => {
          const allowed = (options || []).map((s) => Number(s.id)).filter((id) => id > 0);
          if (!allowed.length) {
            this.showToast('请先创建门店');
            return;
          }
          const defaultStoreId = store && store.id ? Number(store.id) : 0;
          this.staffModal = {
            open: true,
            editId: 0,
            staffId: 0,
            defaultStoreId: defaultStoreId > 0 && allowed.includes(defaultStoreId) ? defaultStoreId : 0,
            allowedStoreIds: allowed,
            defaultOrgId: orgId
          };
        })
        .catch((err) => {
          this.showToast((err && err.msg) || '无法加载组织门店');
        });
    },
    /**
     * 人员列表「编辑」：
     * - editId 固定传 employee_id（person_complete 路径参数）
     * - staffId 传当前任职 staff_id（可选 query），禁止把 staff_id 当作 person_complete/:id
     */
    openEditStaff(person) {
      if (!this.canEditStaff) {
        this.showToast(this.staffSaveDenyTip);
        return;
      }
      const orgId = Number(this.selectedOrgId || 0);
      if (!orgId) {
        this.showToast('请先选择组织');
        return;
      }
      const employeeId = Number((person && person.employee_id) || 0);
      if (!(employeeId > 0)) {
        this.showToast('缺少员工信息');
        return;
      }
      const assignments = (person && person.assignments) || [];
      const current = assignments.find((a) => Number(a.status) === 1 || Number(a.is_current) === 1) ||
        assignments[0] ||
        null;
      const staffId = Number((current && (current.staff_id || current.id)) || person.staff_id || 0);
      this.ensureOrgStoreOptions()
        .then((options) => {
          const allowed = (options || []).map((s) => Number(s.id)).filter((id) => id > 0);
          // 先关掉再开，强制弹窗清空并触发重新加载，避免连续点不同人串数据
          const nextModal = {
            open: true,
            editId: employeeId,
            staffId: staffId > 0 ? staffId : 0,
            defaultStoreId: 0,
            allowedStoreIds: allowed,
            defaultOrgId: orgId
          };
          if (this.staffModal && this.staffModal.open) {
            this.staffModal = {
              open: false,
              editId: 0,
              staffId: 0,
              defaultStoreId: 0,
              allowedStoreIds: allowed,
              defaultOrgId: orgId
            };
            this.$nextTick(() => {
              this.staffModal = nextModal;
            });
            return;
          }
          this.staffModal = nextModal;
        })
        .catch((err) => {
          this.showToast((err && err.msg) || '无法加载组织门店');
        });
    },
    onStaffCreated() {
      this.staffModal = {
        open: false,
        editId: 0,
        staffId: 0,
        defaultStoreId: 0,
        allowedStoreIds: [],
        defaultOrgId: Number(this.selectedOrgId || 0)
      };
      this.activeTab = 'employees';
      this.dataView = 'people';
      this.tabLoaded.stores = false;
      this.tabLoaded.employees = false;
      this.loadTree();
      this.loadOverview();
      this.loadEmployees();
    },
    goCreateAdminAccount(person) {
      const employeeId = Number((person && person.employee_id) || 0);
      if (!employeeId) {
        this.showToast('缺少员工信息，无法创建后台账号');
        return;
      }
      const pre = (Setting.roterPre || '/admin').replace(/\/$/, '');
      this.$router.push({
        path: `${pre}/setting/system_admin/index`,
        query: {
          employee_id: employeeId,
          lock_employee: 1,
          from: 'organization',
          return_org_id: Number(this.selectedOrgId || 0)
        }
      });
    },
    openGrantDrawer() {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      this.resetWriteToken();
      this.grantSearch = '';
      this.grantCandidates = [];
      this.grantForm = { employee_id: 0, admin_id: 0, scope_mode: 'inherit', allowed_store_ids: [] };
      this.drawer = {
        open: true,
        mode: 'grant',
        title: '新增权限人员',
        kicker: '组织授权',
        store: null,
        person: null,
        search: ''
      };
      this.ensureOrgStoreOptions().finally(() => {
        this.loadGrantCandidates(1);
      });
    },
    loadGrantCandidates(page) {
      const orgId = Number(this.selectedOrgId || 0);
      if (!orgId) return;
      const seq = this.beginLoad('grantCandidateState');
      getOrganizationAdminCandidates(orgId, {
        keyword: this.grantSearch,
        page: page || 1,
        limit: 20
      })
        .then((res) => {
          if (!this.endLoad('grantCandidateState', seq)) return;
          const data = res.data || {};
          this.grantCandidates = data.list || [];
        })
        .catch((err) => {
          this.endLoad('grantCandidateState', seq, (err && err.msg) || '候选加载失败');
          this.grantCandidates = [];
        });
    },
    selectGrantCandidate(item) {
      if (!item || item.already_granted) return;
      this.grantForm.employee_id = Number(item.employee_id || 0);
      this.grantForm.admin_id = Number(item.admin_id || 0);
    },
    toggleGrantStore(storeId) {
      const id = Number(storeId);
      const idx = this.grantForm.allowed_store_ids.indexOf(id);
      if (idx >= 0) this.grantForm.allowed_store_ids.splice(idx, 1);
      else this.grantForm.allowed_store_ids.push(id);
    },
    submitGrant() {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      const orgId = Number(this.selectedOrgId || 0);
      const employeeId = Number(this.grantForm.employee_id || 0);
      const adminId = Number(this.grantForm.admin_id || 0);
      if (!orgId || !employeeId || !adminId) {
        this.showToast('请选择具体员工及后台账号');
        return;
      }
      const scopeMode = this.grantForm.scope_mode === 'custom' ? 'custom' : 'inherit';
      const allowed = scopeMode === 'inherit'
        ? []
        : this.grantForm.allowed_store_ids.slice().map(Number).sort((a, b) => a - b);
      const body = {
        employee_id: employeeId,
        admin_id: adminId,
        scope_mode: scopeMode,
        allowed_store_ids: allowed
      };
      const headers = this.writeHeadersFor('grantOrganizationAdmin', { orgId, ...body });
      this.writeSubmitting = true;
      grantOrganizationAdmin(orgId, body, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '授权成功');
          this.closeDrawer();
          this.loadPermissions();
          this.loadOverview();
        })
        .catch((err) => { this.handleWriteCatch(err); })
        .finally(() => { this.writeSubmitting = false; });
    },
    revokeGrant(person) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      const orgId = Number(this.selectedOrgId || 0);
      const orgAdminId = Number((person && person.org_admin_id) || 0);
      if (!orgId || !orgAdminId) return;
      // eslint-disable-next-line no-alert
      if (!window.confirm(`确认撤销「${person.name || '该人员'}」的组织权限？不会删除后台账号和员工。`)) {
        return;
      }
      const body = {};
      const headers = this.writeHeadersFor('revokeOrganizationAdminGrant', { orgId, orgAdminId });
      this.writeSubmitting = true;
      revokeOrganizationAdminGrant(orgId, orgAdminId, body, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '已撤销');
          this.loadPermissions();
          this.loadOverview();
        })
        .catch((err) => { this.handleWriteCatch(err); })
        .finally(() => { this.writeSubmitting = false; });
    },
    revokePersonOrganizationGrant(grant) {
      if (!this.canWrite || this.writeSubmitting || !grant) {
        if (!this.canWrite) this.blockWrite();
        return;
      }
      const orgId = Number(grant.org_id || 0);
      const orgAdminId = Number(grant.org_admin_id || 0);
      if (!orgId || !orgAdminId) return;
      // eslint-disable-next-line no-alert
      if (!window.confirm(`确认解除「${grant.org_name || '该组织'}」的组织管理授权？该账号将失去该组织范围的数据管理权限。`)) {
        return;
      }
      const headers = this.writeHeadersFor('revokeOrganizationAdminGrant', { orgId, orgAdminId });
      this.writeSubmitting = true;
      revokeOrganizationAdminGrant(orgId, orgAdminId, { request_token: headers['X-Request-Token'] }, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '已解除组织管理授权');
          return this.refreshPersonAuth();
        })
        .then(() => {
          this.loadTree();
          this.loadOverview();
          if (Number(this.selectedOrgId) === orgId) this.loadPermissions();
        })
        .catch((err) => this.handleWriteCatch(err))
        .finally(() => { this.writeSubmitting = false; });
    },
    orgById(id) {
      return this.orgs.find((org) => Number(org.id) === Number(id));
    },
    avatarText(name) {
      const s = String(name || '');
      return s ? s.slice(-1) : '?';
    },
    onAvatarError(e) {
      if (e && e.target) e.target.src = this.defaultAvatar;
    },
    statusLabel(status) {
      if (status === 'open' || status === 'active') return '营业中';
      if (status === 'closed' || status === 'inactive') return '停用/未营业';
      return '—';
    },
    permTagClass(status) {
      if (status === 'INHERIT') return 'success';
      if (status === 'CUSTOM') return 'neutral';
      if (status === 'NO_ACCOUNT' || status === 'NO_ORG_PERMISSION' || status === 'UNLINKED_LEGACY') return 'warning';
      return 'neutral';
    },
    assignmentStoreText(person) {
      const list = person.assignments || [];
      if (!list.length) {
        return (person.direct_memberships || []).length ? '无任职门店' : '—';
      }
      if (list.length === 1) return list[0].store_name;
      return `${list[0].store_name} 等${list.length}家`;
    },
    assignmentOrganizationText(person) {
      const names = [];
      (person.assignments || []).forEach((item) => {
        const name = String(item.organization_name || '').trim();
        if (name && !names.includes(name)) names.push(name);
      });
      (person.direct_memberships || []).forEach((item) => {
        const name = String(item.org_name || '').trim();
        if (name && !names.includes(name)) names.push(name);
      });
      if (!names.length) return '—';
      if (names.length === 1) return names[0];
      return `${names[0]} 等${names.length}个组织`;
    },
    displayRoles(person) {
      const roles = Array.isArray(person.roles) ? person.roles.slice() : [];
      const jobs = [];
      ((person && person.assignments) || []).forEach((a) => {
        ((a && a.job_names) || (a && a.jobs) || []).forEach((n) => {
          const name = typeof n === 'string' ? n : (n && (n.name || n.position_name));
          if (name && !jobs.includes(name)) jobs.push(name);
        });
      });
      const merged = roles.length ? roles : jobs;
      return merged.length ? merged : ['未设岗位'];
    },
    blockWrite() {
      this.showToast((this.writeStatus && this.writeStatus.reason_text) || READONLY_TIP);
    },
    showToast(message) {
      this.toastMessage = message;
      clearTimeout(this.toastTimer);
      this.toastTimer = setTimeout(() => { this.toastMessage = ''; }, 2800);
    },
    writeHeadersFor(action, payload) {
      const bound = resolveOrgWriteToken(
        {
          token: this.pendingRequestToken,
          fingerprint: this.pendingWriteFingerprint
        },
        action,
        payload,
        newRequestToken
      );
      this.pendingRequestToken = bound.token;
      this.pendingWriteFingerprint = bound.fingerprint;
      return { 'X-Request-Token': bound.token };
    },
    resetWriteToken() {
      this.pendingRequestToken = '';
      this.pendingWriteFingerprint = '';
    },
    finishWriteSuccess() {
      this.resetWriteToken();
    },
    finishWriteBusinessFail() {
      // 明确业务拒绝且事务未提交：废弃旧 token
      this.resetWriteToken();
    },
    handleWriteCatch(err) {
      const kind = (err && err.__orgWriteKind) || '';
      if (kind === 'business_fail') {
        this.finishWriteBusinessFail();
        this.showToast((err && err.msg) || '操作失败');
        return;
      }
      // unknown：不清 token、不关弹窗、不报成功
      this.showToast((err && err.msg) || UNKNOWN_WRITE_TIP);
    },
    collectDescendantIds(orgId) {
      const ids = [];
      const walk = (pid) => {
        this.orgs
          .filter((o) => Number(o.parentId) === Number(pid))
          .forEach((o) => {
            ids.push(Number(o.id));
            walk(o.id);
          });
      };
      walk(orgId);
      return ids;
    },
    defaultCreateParentId(explicitPid) {
      const pid = Number(explicitPid || 0);
      if (pid > 0) return pid;
      if (!this.rootOrgs.length) return 0;
      if (this.selectedOrgId) return Number(this.selectedOrgId);
      const nonRoot = this.orgs.find((o) => Number(o.parentId) > 0);
      if (nonRoot) return Number(nonRoot.parentId);
      return Number(this.rootOrgs[0].id);
    },
    openCreateOrgModal(explicitPid) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      this.resetWriteToken();
      const parentId = this.defaultCreateParentId(explicitPid);
      this.orgFormModal = {
        open: true,
        mode: 'create',
        orgId: 0,
        pid: parentId,
        name: '',
        sort: 0
      };
    },
    openEditOrgModal() {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      const org = this.selectedOrg;
      if (!org || !org.id) return;
      this.resetWriteToken();
      this.orgFormModal = {
        open: true,
        mode: 'edit',
        orgId: Number(org.id),
        pid: Number(org.pid || 0),
        name: org.name || '',
        sort: Number(org.sort || 0)
      };
    },
    closeOrgFormModal() {
      if (this.writeSubmitting) return;
      this.orgFormModal.open = false;
    },
    submitOrgForm() {
      if (!this.canWrite || this.writeSubmitting) return;
      const modal = this.orgFormModal;
      const name = String(modal.name || '').trim();
      if (!name) {
        this.showToast('请输入组织名称');
        return;
      }
      const pid = Number(modal.pid || 0);
      if (modal.mode === 'create' && this.rootOrgs.length > 0 && pid <= 0) {
        this.showToast('已存在最高级组织，请选择上级组织');
        return;
      }
      const payload = {
        pid,
        name,
        sort: Number(modal.sort || 0)
      };
      const orgId = modal.mode === 'edit' ? Number(modal.orgId) : 0;
      const action = modal.mode === 'edit' ? 'saveOrganization:edit' : 'saveOrganization:create';
      const headers = this.writeHeadersFor(action, { orgId, ...payload });
      this.writeSubmitting = true;
      saveOrganization(orgId, payload, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '保存成功');
          this.orgFormModal.open = false;
          this.loadWriteStatus();
          this.loadTree();
          if (modal.mode === 'edit') this.loadOverview();
        })
        .catch((err) => { this.handleWriteCatch(err); })
        .finally(() => { this.writeSubmitting = false; });
    },
    openDeleteOrgModal(orgId, orgName) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      const id = Number(orgId || this.selectedOrgId);
      if (!id) return;
      const name = orgName || (this.orgById(id) && this.orgById(id).name) || this.selectedOrg.name || '';
      this.resetWriteToken();
      this.deleteModal = { open: true, step: 1, orgId: id, name, loading: true, canDelete: false, blockers: [], error: '' };
      getOrganizationDeleteBlockers(id)
        .then((res) => {
          if (!this.deleteModal.open || Number(this.deleteModal.orgId) !== id) return;
          const data = (res && res.data) || {};
          this.deleteModal.loading = false;
          this.deleteModal.canDelete = !!data.can_delete;
          this.deleteModal.blockers = Array.isArray(data.blockers) ? data.blockers : [];
        })
        .catch((err) => {
          if (!this.deleteModal.open || Number(this.deleteModal.orgId) !== id) return;
          this.deleteModal.loading = false;
          this.deleteModal.error = (err && err.msg) || '删除检查失败，请稍后重试';
        });
    },
    closeDeleteModal() {
      if (this.writeSubmitting) return;
      this.deleteModal.open = false;
      this.deleteModal.step = 1;
    },
    submitDeleteOrg() {
      if (!this.canWrite || this.writeSubmitting) return;
      const orgId = Number(this.deleteModal.orgId);
      if (!orgId) return;
      const payload = { orgId };
      const headers = this.writeHeadersFor('deleteOrganization', payload);
      this.writeSubmitting = true;
      deleteOrganization(orgId, { request_token: headers['X-Request-Token'] }, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '删除成功');
          this.deleteModal.open = false;
          this.deleteModal.step = 1;
          if (Number(this.selectedOrgId) === orgId) {
            this.selectedOrgId = 0;
          }
          this.loadWriteStatus();
          this.loadTree();
        })
        .catch((err) => { this.handleWriteCatch(err); })
        .finally(() => { this.writeSubmitting = false; });
    },
    submitStoreOrgBind() {
      if (!this.canWrite || this.writeSubmitting || !this.drawer.store) return;
      const storeId = Number(this.drawer.store.id);
      const orgId = Number(this.draftStoreOrgId);
      if (!storeId || !orgId || orgId === Number(this.drawer.store.org_id)) return;
      const payload = { store_id: storeId, org_id: orgId };
      const headers = this.writeHeadersFor('bindOrganizationStore', payload);
      this.writeSubmitting = true;
      bindOrganizationStore({ ...payload, request_token: headers['X-Request-Token'] }, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '保存成功');
          this.drawer.store.org_id = orgId;
          const org = this.orgById(orgId);
          if (org) this.drawer.store.org_name = org.name;
          this.reloadCurrentList();
          this.loadOverview();
          this.loadPermissions();
          this.loadLogs(this.logPage);
        })
        .catch((err) => { this.handleWriteCatch(err); })
        .finally(() => { this.writeSubmitting = false; });
    },
    loadWriteStatus() {
      return getOrganizationWriteStatus()
        .then((res) => {
          const data = (res && res.data) || {};
          this.writeStatus = {
            write_enabled: !!data.write_enabled,
            can_write: !!data.can_write,
            reason_code: data.reason_code || 'WRITE_DISABLED',
            reason_text: data.reason_text || READONLY_TIP,
            source_mode: data.source_mode || '',
            is_super_admin: !!data.is_super_admin
          };
        })
        .catch(() => {
          this.writeStatus = {
            write_enabled: false,
            can_write: false,
            reason_code: 'WRITE_DISABLED',
            reason_text: READONLY_TIP,
            source_mode: '',
            is_super_admin: false
          };
        });
    },
    canEditPermissionPerson(person) {
      if (!person || !person.org_admin_id) return false;
      return person.permission_status === 'INHERIT' || person.permission_status === 'CUSTOM';
    },
    isDraftLeader(employeeId) {
      const id = Number(employeeId);
      return this.draftLeaders.some((x) => Number(x.employee_id) === id);
    },
    toggleDraftLeader(person) {
      if (!this.canWrite || !person) return;
      const id = Number(person.employee_id);
      const idx = this.draftLeaders.findIndex((x) => Number(x.employee_id) === id);
      if (idx >= 0) {
        this.draftLeaders.splice(idx, 1);
      } else {
        this.draftLeaders.push({
          employee_id: id,
          name: person.name || '',
          sort: this.draftLeaders.length + 1
        });
      }
    },
    toggleDraftAllowedStore(storeId) {
      const id = Number(storeId);
      const idx = this.draftAllowedStoreIds.indexOf(id);
      if (idx >= 0) this.draftAllowedStoreIds.splice(idx, 1);
      else this.draftAllowedStoreIds.push(id);
    },
    submitLeaders() {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      const orgId = Number(this.selectedOrgId);
      if (!orgId) return;
      const leaders = this.draftLeaders.map((x, i) => ({
        employee_id: Number(x.employee_id),
        sort: i + 1
      }));
      const payload = { orgId, leaders };
      const headers = this.writeHeadersFor('saveOrganizationLeaders', payload);
      this.writeSubmitting = true;
      saveOrganizationLeaders(orgId, { leaders }, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '保存成功');
          this.closeDrawer();
          this.loadOverview();
          this.loadPermissions();
        })
        .catch((err) => { this.handleWriteCatch(err); })
        .finally(() => { this.writeSubmitting = false; });
    },
    submitPermission() {
      if (!this.canWrite || !this.canEditPermissionPerson(this.drawer.person) || !this.permissionRangeReady) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      const orgAdminId = Number(this.drawer.person.org_admin_id);
      const scopeMode = this.draftScopeMode === 'custom' ? 'custom' : 'inherit';
      const allowed = scopeMode === 'inherit' ? [] : this.draftAllowedStoreIds.slice().map(Number).sort((a, b) => a - b);
      const body = { scope_mode: scopeMode, allowed_store_ids: allowed };
      const headers = this.writeHeadersFor('saveOrganizationAdminPermission', { orgAdminId, ...body });
      this.writeSubmitting = true;
      saveOrganizationAdminPermission(orgAdminId, body, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '保存成功');
          this.closeDrawer();
          this.loadPermissions();
        })
        .catch((err) => { this.handleWriteCatch(err); })
        .finally(() => { this.writeSubmitting = false; });
    },
    clearLoadTimers(state) {
      if (!state) return;
      clearTimeout(state.timer1);
      clearTimeout(state.timer8);
      state.timer1 = null;
      state.timer8 = null;
    },
    beginLoad(stateKey) {
      const state = this[stateKey];
      this.clearLoadTimers(state);
      const seq = (state.seq || 0) + 1;
      state.seq = seq;
      state.loading = true;
      state.visible = false;
      state.slow = false;
      state.error = '';
      state.timer1 = setTimeout(() => {
        if (state.seq === seq && state.loading) {
          state.visible = true;
        }
      }, 1000);
      state.timer8 = setTimeout(() => {
        if (state.seq === seq && state.loading) {
          state.slow = true;
        }
      }, 8000);
      return seq;
    },
    endLoad(stateKey, seq, error) {
      const state = this[stateKey];
      if (state.seq !== seq) return false;
      this.clearLoadTimers(state);
      state.loading = false;
      state.visible = false;
      state.slow = false;
      state.error = error || '';
      return true;
    },
    flattenTree(nodes, parentId, openMap, acc) {
      const visited = acc.visited || (acc.visited = new Set());
      (nodes || []).forEach((node) => {
        const id = Number(node.id);
        if (!id || visited.has(id)) return;
        visited.add(id);
        const prev = openMap[id];
        acc.list.push({
          id,
          parentId: parentId == null ? null : Number(parentId),
          name: node.name,
          stores: 0,
          directStores: 0,
          employees: 0,
          attention: 0,
          leaders: 0,
          sort: node.sort != null ? Number(node.sort) : 0,
          updated: node.update_time_text || '',
          open: prev != null ? prev : (parentId == null),
          agent_count: node.agent_count || 0
        });
        this.flattenTree(node.children || [], id, openMap, acc);
      });
      return acc.list;
    },
    loadTree() {
      const seq = this.beginLoad('treeState');
      const openMap = {};
      this.orgs.forEach((o) => { openMap[o.id] = o.open; });
      getOrganizationTree()
        .then((res) => {
          if (!this.endLoad('treeState', seq)) return;
          const roots = res.data || [];
          this.orgs = this.flattenTree(roots, null, openMap, { list: [], visited: new Set() });
          if (!this.selectedOrgId && this.rootOrgs.length) {
            this.selectedOrgId = Number(this.rootOrgs[0].id);
          } else if (this.selectedOrgId && !this.orgById(this.selectedOrgId) && this.rootOrgs.length) {
            this.selectedOrgId = Number(this.rootOrgs[0].id);
          }
          if (this.selectedOrgId) {
            this.loadOverview();
          }
        })
        .catch((err) => {
          this.endLoad('treeState', seq, (err && err.msg) || '组织树加载失败');
        });
    },
    loadOverview() {
      if (!this.selectedOrgId) return;
      const orgId = Number(this.selectedOrgId);
      this.overview = null;
      this.storeList = [];
      this.employeeList = [];
      this.permissionLeaders = [];
      this.permissionHolders = [];
      this.orgStoreOptions = [];
      this.logList = [];
      this.tabLoaded = { stores: false, employees: false, permissions: false, logs: false };
      const seq = this.beginLoad('overviewState');
      getOrganizationWorkspaceOverview({ org_id: orgId })
        .then((res) => {
          if (!this.endLoad('overviewState', seq)) return;
          if (Number(this.selectedOrgId) !== orgId) return;
          this.overview = res.data || null;
          if (this.activeTab === 'stores' || this.activeTab === 'employees') this.reloadCurrentList();
          if (this.activeTab === 'permissions') this.loadPermissions();
          if (this.activeTab === 'logs') this.loadLogs(1);
        })
        .catch((err) => {
          if (!this.endLoad('overviewState', seq, (err && err.msg) || '概况加载失败')) return;
          if (Number(this.selectedOrgId) !== orgId) return;
          this.overview = null;
        });
    },
    selectOrg(id) {
      const next = Number(id);
      if (!next || next === Number(this.selectedOrgId)) {
        this.closeMenus();
        this.closeMobileTree();
        return;
      }
      this.selectedOrgId = next;
      this.activeTab = 'overview';
      this.dataFilter = 'all';
      this.dataSearch = '';
      this.listPage = 1;
      this.closeMenus();
      this.closeMobileTree();
      const node = this.orgById(next);
      if (node) this.$set(node, 'open', true);
      this.loadOverview();
    },
    switchTab(key) {
      const isListTab = key === 'stores' || key === 'employees';
      const changedListTab = isListTab && key !== this.activeTab;
      this.activeTab = key;
      if (key === 'stores') {
        this.dataView = 'store';
      } else if (key === 'employees') {
        this.dataView = 'people';
      }
      if (changedListTab) this.listPage = 1;
      if (key === 'stores' && !this.tabLoaded.stores) {
        this.reloadCurrentList();
      } else if (key === 'employees' && !this.tabLoaded.employees) {
        this.reloadCurrentList();
      } else if (key === 'permissions' && !this.tabLoaded.permissions) {
        this.loadPermissions();
      } else if (key === 'logs' && !this.tabLoaded.logs) {
        this.loadLogs(1);
      }
    },
    switchDataView(view) {
      this.dataView = view;
      this.activeTab = view === 'people' ? 'employees' : 'stores';
      this.listPage = 1;
      this.reloadCurrentList();
    },
    setStoreFilter(key) {
      this.dataFilter = key;
      this.listPage = 1;
      this.reloadCurrentList();
    },
    reloadCurrentList() {
      if (this.dataView === 'store') this.loadStores();
      else this.loadEmployees();
    },
    changeListPage(page) {
      this.listPage = page;
      this.reloadCurrentList();
    },
    loadStores() {
      if (!this.selectedOrgId) return;
      const orgId = Number(this.selectedOrgId);
      const seq = this.beginLoad('listState');
      const params = {
        org_id: orgId,
        scope: this.dataFilter === 'direct' ? 'direct' : 'all',
        keyword: this.dataSearch,
        page: this.listPage,
        limit: this.listLimit
      };
      if (this.dataFilter === 'open' || this.dataFilter === 'closed') params.status = this.dataFilter;
      if (this.dataFilter === 'attention') params.attention = 'attention';
      getOrganizationWorkspaceStores(params)
        .then((res) => {
          if (!this.endLoad('listState', seq)) return;
          if (Number(this.selectedOrgId) !== orgId || this.dataView !== 'store') return;
          const data = res.data || {};
          this.storeList = data.list || [];
          this.listTotal = data.count || 0;
          this.tabLoaded.stores = true;
        })
        .catch((err) => {
          if (!this.endLoad('listState', seq, (err && err.msg) || '门店加载失败')) return;
          if (Number(this.selectedOrgId) !== orgId || this.dataView !== 'store') return;
          this.storeList = [];
          this.listTotal = 0;
        });
    },
    openOrgDirectModal() {
      if (!this.selectedOrgId) {
        this.showToast('请先选择组织');
        return;
      }
      this.orgDirectModal = { open: true, list: [], phone: '', name: '', jobTitle: '' };
      this.loadOrgDirectList();
    },
    loadOrgDirectList() {
      const orgId = Number(this.selectedOrgId);
      getOrganizationOrgEmployees({ org_id: orgId, page: 1, limit: 100 })
        .then((res) => {
          this.orgDirectModal.list = (res.data && res.data.list) || [];
        })
        .catch((err) => this.showToast((err && err.msg) || '加载直属人员失败'));
    },
    saveOrgDirect() {
      if (!this.canEditStaff) {
        this.showToast(this.staffSaveDenyTip);
        return;
      }
      const token = newRequestToken();
      this.writeSubmitting = true;
      saveOrganizationOrgEmployee({
        org_id: Number(this.selectedOrgId),
        phone: this.orgDirectModal.phone,
        name: this.orgDirectModal.name,
        job_title: this.orgDirectModal.jobTitle,
        request_token: token
      }, { 'X-Request-Token': token })
        .then(() => {
          this.showToast('已保存组织直属');
          this.orgDirectModal.phone = '';
          this.orgDirectModal.name = '';
          this.orgDirectModal.jobTitle = '';
          this.loadOrgDirectList();
          this.loadEmployees();
          this.loadTree();
          this.loadOverview();
        })
        .catch((err) => this.showToast((err && err.msg) || '保存失败'))
        .finally(() => { this.writeSubmitting = false; });
    },
    removeOrgDirect(row) {
      if (!this.canEditStaff || !row || !row.id) {
        if (!this.canEditStaff) this.showToast(this.staffSaveDenyTip);
        return;
      }
      const token = newRequestToken();
      deleteOrganizationOrgEmployee(row.id, { 'X-Request-Token': token })
        .then(() => {
          this.showToast('已移除组织名册关系，管理授权未改变');
          this.loadOrgDirectList();
          this.loadEmployees();
          this.loadTree();
          this.loadOverview();
        })
        .catch((err) => this.showToast((err && err.msg) || '移除失败'));
    },
    removeOrgDirectCompletely(row) {
      if (!this.canWrite || !row || !row.id) {
        if (!this.canWrite) this.blockWrite();
        return;
      }
      const orgName = (this.selectedOrg && this.selectedOrg.name) || '当前组织';
      // eslint-disable-next-line no-alert
      if (!window.confirm(`确认完整移除「${row.name || '该人员'}」与「${orgName}」的关系？这会同时撤销其在本组织的管理权限，不影响其他组织、门店任职、后台账号和员工档案。`)) {
        return;
      }
      const orgId = Number(this.selectedOrgId || 0);
      const token = newRequestToken();
      this.writeSubmitting = true;
      removeOrganizationEmployeeCompletely(orgId, Number(row.id), { request_token: token }, { 'X-Request-Token': token })
        .then((res) => {
          this.showToast((res && res.msg) || '已完整移除');
          this.loadOrgDirectList();
          this.loadEmployees();
          this.loadPermissions();
          this.loadTree();
          this.loadOverview();
        })
        .catch((err) => this.showToast((err && err.msg) || '完整移除失败'))
        .finally(() => { this.writeSubmitting = false; });
    },
    leaveEmployeeGlobal(row) {
      this.confirmLeavePerson(row);
    },
    listOpsKey(person) {
      return `list-${Number((person && person.employee_id) || 0)}`;
    },
    togglePersonOps(key, evt) {
      if (evt && evt.stopPropagation) evt.stopPropagation();
      this.personOpsKey = this.personOpsKey === key ? '' : key;
    },
    closePersonOps() {
      this.personOpsKey = '';
    },
    personHasCurrentTenure(person, preferAuth = false) {
      if (preferAuth && this.drawer && this.drawer.mode === 'person') {
        return !!(this.currentPersonTenure && Number(this.currentPersonTenure.id) > 0);
      }
      const assignments = (person && person.assignments) || [];
      // 列表接口仅返回有效任职；有 assignments 即视为有当前门店
      if (assignments.length > 0) return true;
      if (preferAuth) {
        const authList = (this.personAuth && this.personAuth.store_assignments) || [];
        return authList.some((a) => Number(a.status) === 1);
      }
      return false;
    },
    resolveCurrentTenure(person, preferAuth = false) {
      if (preferAuth || (this.drawer.open && this.drawer.mode === 'person' && Number((this.drawer.person || {}).employee_id) === Number((person || {}).employee_id))) {
        if (this.currentPersonTenure) return this.currentPersonTenure;
      }
      const assignments = (person && person.assignments) || [];
      const hit = assignments[0] || null;
      if (hit) {
        return {
          id: Number(hit.staff_id || hit.id || 0),
          store_id: Number(hit.store_id || 0),
          store_name: hit.store_name || '',
          status: 1
        };
      }
      const authList = (this.personAuth && this.personAuth.store_assignments) || [];
      return authList.find((a) => Number(a.status) === 1) || null;
    },
    formatUnixTime(ts) {
      const n = Number(ts || 0);
      if (!n) return '—';
      const d = new Date(n * 1000);
      if (Number.isNaN(d.getTime())) return '—';
      const p = (x) => String(x).padStart(2, '0');
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
    },
    tenureActionLabel(action) {
      const map = {
        hire: '入职',
        resume: '复职',
        suspend: '停职',
        delete: '删除关系',
        leave: '离职',
        transfer: '调离'
      };
      return map[String(action || '')] || '';
    },
    entryStatusText(staffId, channel) {
      const a = ((this.personAuth && this.personAuth.store_assignments) || []).find((x) => Number(x.id) === Number(staffId));
      if (!a) return '未知';
      const entries = Array.isArray(a.channel_entries) ? a.channel_entries : [];
      const hit = entries.find((e) => String(e.channel || e.entry || '') === String(channel));
      if (!hit) {
        return '入口未开通';
      }
      return Number(hit.status) === 1 ? '入口已开通' : '入口未开通';
    },
    dataScopeDetailText(scope) {
      if (!scope) return '';
      if (scope.scope_mode === 'org') {
        const ids = Array.isArray(scope.org_ids) ? scope.org_ids : [];
        return ids.length ? `（已选 ${ids.length} 个组织）` : '';
      }
      if (scope.scope_mode === 'store' || scope.scope_mode === 'store_self') {
        return '（按当前任职门店自动计算）';
      }
      return '';
    },
    confirmLeavePerson(person) {
      if (!this.canEditStaff || !person || !person.employee_id) {
        if (!this.canEditStaff) this.showToast(this.staffSaveDenyTip);
        return;
      }
      const name = person.name || person.employee_id;
      if (!window.confirm(`确认办理「${name}」离职？\n将结束当前任职并停止当前门店权限；工资、订单与任职历史会保留。`)) return;
      const token = newRequestToken();
      leaveOrganizationEmployee(person.employee_id, {}, { 'X-Request-Token': token })
        .then(() => {
          this.showToast('已办理离职');
          this.loadOrgDirectList && this.loadOrgDirectList();
          this.loadEmployees();
          if (this.drawer.open && this.drawer.mode === 'person') this.refreshPersonAuth();
        })
        .catch((err) => this.showToast((err && err.msg) || '离职失败'));
    },
    confirmSoftDeletePerson(person) {
      if (!this.canWrite || !person || !person.employee_id) return;
      const name = person.name || person.employee_id;
      const ok = window.confirm(
        `确认软删除「${name}」的人员档案？\n这是数据清理操作：档案将不再出现在人员列表中，但不会物理删除订单、工资依据和任职历史。\n此操作不可通过本页一键恢复。`
      );
      if (!ok) return;
      const again = window.confirm('再次确认：确定软删除该人员档案？');
      if (!again) return;
      const token = newRequestToken();
      softDeleteOrganizationEmployeeArchive(person.employee_id, {}, { 'X-Request-Token': token })
        .then(() => {
          this.showToast('已软删除人员档案');
          this.closeDrawer();
          this.loadEmployees();
          this.loadOrgDirectList && this.loadOrgDirectList();
        })
        .catch((err) => this.showToast((err && err.msg) || '删除失败'));
    },
    ensureTransferAllStoreOptions() {
      if ((this.transferAllStoreOptions || []).length) {
        return Promise.resolve(this.transferAllStoreOptions);
      }
      return merchantStoreListApi().then((res) => {
        const raw = (res && res.data) || [];
        const list = Array.isArray(raw) ? raw : (raw.list || []);
        this.transferAllStoreOptions = list
          .map((s) => ({
            id: Number(s.id || s.store_id || 0),
            name: String(s.name || s.store_name || '')
          }))
          .filter((s) => s.id > 0);
        return this.transferAllStoreOptions;
      });
    },
    openCreateTransferApply(person, preferAuth = false) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      const tenure = this.resolveCurrentTenure(person, preferAuth);
      if (!tenure || !Number(tenure.id || tenure.staff_id)) {
        this.showToast('该人员无当前任职门店，不能发起调店');
        return;
      }
      this.ensureTransferAllStoreOptions()
        .then((options) => {
          const fromId = Number(tenure.store_id || 0);
          const targets = (options || []).filter((s) => Number(s.id) !== fromId);
          if (!targets.length) {
            this.showToast('暂无其它可用门店可作为调店目标');
            return;
          }
          this.createTransferModal = {
            open: true,
            personName: (person && person.name) || '',
            employeeId: Number((person && person.employee_id) || 0),
            sourceStaffId: Number(tenure.id || tenure.staff_id || 0),
            fromStoreId: fromId,
            fromStoreName: tenure.store_name || '',
            toStoreId: 0,
            reason: ''
          };
        })
        .catch((err) => this.showToast((err && err.msg) || '无法加载门店列表'));
    },
    closeCreateTransferApply() {
      this.createTransferModal.open = false;
    },
    submitCreateTransferApply() {
      const m = this.createTransferModal;
      if (!m.open) return;
      if (!(Number(m.toStoreId) > 0)) {
        this.showToast('请选择目标门店');
        return;
      }
      if (!String(m.reason || '').trim()) {
        this.showToast('请填写调店原因');
        return;
      }
      if (this.writeSubmitting) return;
      this.writeSubmitting = true;
      const token = newRequestToken();
      createOrganizationTransferApply({
        request_token: token,
        source_staff_id: Number(m.sourceStaffId),
        to_store_id: Number(m.toStoreId),
        reason: String(m.reason).trim()
      }, { 'X-Request-Token': token })
        .then((res) => {
          this.showToast((res && res.msg) || '调店申请已提交');
          this.closeCreateTransferApply();
          this.openTransferApplyModal(Number(m.employeeId) || 0);
        })
        .catch((err) => this.showToast((err && err.msg) || '提交失败'))
        .finally(() => { this.writeSubmitting = false; });
    },
    openPersonTransferRecords() {
      const empId = Number((this.drawer.person && this.drawer.person.employee_id) || (this.personAuth.employee && this.personAuth.employee.id) || 0);
      this.openTransferApplyModal(empId);
    },
    openTransferApplyModal(employeeId = 0) {
      this.transferModal.open = true;
      this.transferModal.employeeId = Number(employeeId) || 0;
      this.loadTransferApplies();
    },
    loadTransferApplies() {
      getOrganizationTransferApplies({
        status: this.transferModal.status || '',
        employee_id: Number(this.transferModal.employeeId || 0) || undefined,
        page: 1,
        limit: 50
      })
        .then((res) => {
          this.transferModal.list = (res.data && res.data.list) || [];
        })
        .catch((err) => this.showToast((err && err.msg) || '加载调店申请失败'));
    },
    approveTransfer(row) {
      if (!this.canWrite || !row) return;
      const rolesRaw = window.prompt('目标门店角色 ID（可选，多个用英文逗号；留空则沿用原岗位）', '');
      if (rolesRaw == null) return;
      const roles = String(rolesRaw).split(',').map((x) => parseInt(x, 10)).filter((n) => n > 0);
      const token = newRequestToken();
      approveOrganizationTransferApply(row.id, { roles }, { 'X-Request-Token': token })
        .then(() => {
          this.showToast('已批准并执行调店');
          this.loadTransferApplies();
          this.loadEmployees();
        })
        .catch((err) => this.showToast((err && err.msg) || '批准失败'));
    },
    rejectTransfer(row) {
      if (!this.canWrite || !row) return;
      const reason = window.prompt('请填写驳回原因', '');
      if (reason == null || !String(reason).trim()) {
        this.showToast('请填写驳回原因');
        return;
      }
      const token = newRequestToken();
      rejectOrganizationTransferApply(row.id, { reject_reason: String(reason).trim() }, { 'X-Request-Token': token })
        .then(() => {
          this.showToast('已驳回');
          this.loadTransferApplies();
        })
        .catch((err) => this.showToast((err && err.msg) || '驳回失败'));
    },
    loadEmployees() {
      if (!this.selectedOrgId) return;
      const orgId = Number(this.selectedOrgId);
      const seq = this.beginLoad('listState');
      getOrganizationWorkspaceEmployees({
        org_id: orgId,
        scope: 'all',
        keyword: this.dataSearch,
        page: this.listPage,
        limit: this.listLimit
      })
        .then((res) => {
          if (!this.endLoad('listState', seq)) return;
          if (Number(this.selectedOrgId) !== orgId || this.dataView !== 'people') return;
          const data = res.data || {};
          this.employeeList = data.list || [];
          this.listTotal = data.count || 0;
          this.tabLoaded.employees = true;
        })
        .catch((err) => {
          if (!this.endLoad('listState', seq, (err && err.msg) || '人员加载失败')) return;
          if (Number(this.selectedOrgId) !== orgId || this.dataView !== 'people') return;
          this.employeeList = [];
          this.listTotal = 0;
        });
    },
    loadPermissions() {
      if (!this.selectedOrgId) return Promise.resolve();
      const orgId = Number(this.selectedOrgId);
      const seq = this.beginLoad('permState');
      return getOrganizationWorkspacePermissions(orgId)
        .then((res) => {
          if (!this.endLoad('permState', seq)) return;
          if (Number(this.selectedOrgId) !== orgId) return;
          const data = res.data || {};
          this.permissionLeaders = data.leaders || [];
          this.permissionHolders = data.permission_holders || [];
          this.orgStoreOptions = (data.org_store_options || []).map((s) => ({
            id: Number(s.id),
            name: s.name || ''
          }));
          this.tabLoaded.permissions = true;
          if (this.drawer.open && this.drawer.mode === 'permission' && this.drawer.person) {
            this.applyPermissionRangeOptions(true);
          }
        })
        .catch((err) => {
          if (!this.endLoad('permState', seq, (err && err.msg) || '权限加载失败')) return;
          if (Number(this.selectedOrgId) !== orgId) return;
          if (!this.tabLoaded.permissions) {
            this.permissionLeaders = [];
            this.permissionHolders = [];
          }
          if (this.drawer.open && this.drawer.mode === 'permission' && this.drawer.person) {
            this.applyPermissionRangeOptions(false);
          }
        });
    },
    applyPermissionRangeOptions(success) {
      if (success) {
        this.permissionStoreOptions = this.orgStoreOptions.slice();
        this.permissionRangeReady = true;
      } else {
        this.permissionRangeReady = false;
      }
    },
    initPermissionDraft(person) {
      const mode = String(person.scope_mode || 'inherit').toLowerCase();
      this.draftScopeMode = mode === 'custom' ? 'custom' : 'inherit';
      this.draftAllowedStoreIds = (person.allowed_store_ids || []).map(Number).filter((id) => id > 0);
    },
    loadLogs(page) {
      if (!this.selectedOrgId) return;
      this.logPage = page || 1;
      const orgId = Number(this.selectedOrgId);
      const seq = this.beginLoad('logState');
      getOrganizationChangeLog({
        org_id: orgId,
        keyword: this.logSearch,
        page: this.logPage,
        limit: this.logLimit
      })
        .then((res) => {
          if (!this.endLoad('logState', seq)) return;
          if (Number(this.selectedOrgId) !== orgId) return;
          const data = res.data || {};
          this.logList = data.list || [];
          this.logTotal = data.count || 0;
          this.tabLoaded.logs = true;
        })
        .catch((err) => {
          if (!this.endLoad('logState', seq, (err && err.msg) || '变更记录加载失败')) return;
          if (Number(this.selectedOrgId) !== orgId) return;
          this.logList = [];
          this.logTotal = 0;
        });
    },
    toggleOrg(node) { this.$set(node, 'open', !node.open); },
    expandAll() { this.orgs.forEach((org) => this.$set(org, 'open', true)); },
    collapseAll() { this.orgs.forEach((org) => this.$set(org, 'open', false)); },
    collapseTree() { this.treeCollapsed = true; this.closeMenus(); },
    expandTree() { this.treeCollapsed = false; },
    openMobileTree() { this.treeMobileOpen = true; this.treeCollapsed = false; },
    closeMobileTree() { this.treeMobileOpen = false; },
    showAttention() {
      this.switchTab('stores');
      this.dataView = 'store';
      this.setStoreFilter('attention');
    },
    closeMenus() {
      this.heroMenuOpen = false;
      this.treeMenu = { open: false, node: null, x: 0, y: 0 };
    },
    toggleHeroMenu() {
      this.treeMenu.open = false;
      this.heroMenuOpen = !this.heroMenuOpen;
    },
    onHeroMenu(action) {
      this.heroMenuOpen = false;
      if (action === 'edit') this.openEditOrgModal();
      else if (action === 'add') this.openCreateOrgModal(this.selectedOrg.id);
      else if (action === 'delete') this.openDeleteOrgModal(this.selectedOrg.id, this.selectedOrg.name);
    },
    openTreeMenu({ node, event }) {
      this.heroMenuOpen = false;
      const point = event && event.currentTarget ? event.currentTarget.getBoundingClientRect() : { left: 120, bottom: 120 };
      this.treeMenu = {
        open: true,
        node,
        x: Math.min(window.innerWidth - 180, point.left),
        y: Math.min(window.innerHeight - 100, point.bottom + 4)
      };
    },
    onTreeMenu(action) {
      const node = this.treeMenu.node;
      this.closeMenus();
      if (!node) return;
      if (action === 'edit') {
        this.selectOrg(node.id);
        this.$nextTick(() => this.openEditOrgModal());
      } else if (action === 'add') {
        this.openCreateOrgModal(node.id);
      } else if (action === 'delete') {
        this.openDeleteOrgModal(node.id, node.name);
      }
    },
    openLeaderDrawer() {
      if (this.canWrite) this.resetWriteToken();
      this.draftLeaders = (this.selectedLeaders || []).map((x, i) => ({
        employee_id: Number(x.employee_id),
        name: x.name || '',
        sort: i + 1
      }));
      this.drawer = {
        open: true,
        mode: 'leaders',
        title: this.canWrite ? `设置${this.selectedOrg.name}负责人` : `查看${this.selectedOrg.name}负责人候选`,
        kicker: this.canWrite ? '人员关系' : '人员关系（只读）',
        store: null,
        person: null,
        search: ''
      };
      this.loadLeaderCandidates(1);
    },
    loadLeaderCandidates(page) {
      this.candidatePage = page || 1;
      const seq = this.beginLoad('candidateState');
      getOrganizationLeaderCandidates({
        keyword: this.drawer.search || '',
        page: this.candidatePage,
        limit: this.candidateLimit
      })
        .then((res) => {
          if (!this.endLoad('candidateState', seq)) return;
          if (this.drawer.mode !== 'leaders') return;
          const data = res.data || {};
          this.leaderCandidates = data.list || [];
          this.candidateTotal = data.count || 0;
        })
        .catch((err) => {
          if (!this.endLoad('candidateState', seq, (err && err.msg) || '候选人加载失败')) return;
          this.leaderCandidates = [];
          this.candidateTotal = 0;
        });
    },
    openStoreDrawer(store) {
      if (this.canWrite) this.resetWriteToken();
      this.draftStoreOrgId = Number(store.org_id || 0);
      this.drawer = {
        open: true,
        mode: 'store',
        title: '门店详情',
        kicker: '门店与人员',
        store,
        person: null,
        search: ''
      };
      this.loadStoreStaff(1);
    },
    loadStoreStaff(page) {
      if (!this.drawer.store || !this.selectedOrgId) return;
      this.storeStaffPage = page || 1;
      const orgId = Number(this.selectedOrgId);
      const storeId = Number(this.drawer.store.id);
      const seq = this.beginLoad('storeStaffState');
      getOrganizationWorkspaceEmployees({
        org_id: orgId,
        store_id: storeId,
        page: this.storeStaffPage,
        limit: this.storeStaffLimit
      })
        .then((res) => {
          if (!this.endLoad('storeStaffState', seq)) return;
          if (!this.drawer.open || this.drawer.mode !== 'store' || Number(this.drawer.store.id) !== storeId) return;
          const data = res.data || {};
          this.storeStaffList = data.list || [];
          this.storeStaffTotal = data.count || 0;
        })
        .catch((err) => {
          if (!this.endLoad('storeStaffState', seq, (err && err.msg) || '门店人员加载失败')) return;
          this.storeStaffList = [];
          this.storeStaffTotal = 0;
        });
    },
    openPeopleFromOverview() {
      this.activeTab = 'employees';
      this.dataView = 'people';
      this.listPage = 1;
      this.loadEmployees();
    },
    authHelpText(key) {
      const help = (this.personAuth && this.personAuth.help) || {};
      const fallback = {
        jobs: '岗位决定能做什么功能。一人可在同一门店兼任多个岗位，功能权限按有效岗位取并集；岗位不会自动扩大数据查看范围。',
        entries: '入口只决定能不能登录对应端。开通前须已有有效岗位覆盖该端功能，否则无法保存空入口。',
        data_scope: '数据权限由人员决定。总部可设个人/组织/门店；门店只能设个人或本店。未配置时默认只能看本人参与的数据。',
        function_preview: '功能权限预览只读展示当前岗位计算结果，请到岗位策略里调整，不要在人员上直接勾功能菜单。'
      };
      return help[key] || fallback[key] || '';
    },
    jobNamesOf(assignment) {
      const jobs = (assignment && assignment.job_positions) || [];
      const names = jobs.map((j) => j.position_name || j.name).filter(Boolean);
      return names.length ? names.join(' / ') : '未设岗位';
    },
    jobOptionsForAssignment(assignment) {
      const map = {};
      (this.jobPositionOptions || []).forEach((opt) => {
        map[Number(opt.value)] = opt;
      });
      ((assignment && assignment.job_positions) || []).forEach((j) => {
        const id = Number(j.position_id || j.id || 0);
        if (id > 0 && !map[id]) {
          map[id] = { value: id, label: j.position_name || ('岗位#' + id), keep_only: !!j.keep_only };
        }
      });
      const hints = (((this.personAuth.selectable_hints || {}).by_store || {})[assignment.store_id]) ||
        (((this.personAuth.selectable_hints || {}).by_store || {})[String(assignment.store_id)]) ||
        [];
      hints.forEach((opt) => {
        const id = Number(opt.value || opt.id || 0);
        if (id > 0 && !map[id]) {
          map[id] = { value: id, label: opt.label || opt.name || ('岗位#' + id), keep_only: false };
        }
      });
      return Object.keys(map).map((k) => map[k]).sort((a, b) => Number(a.value) - Number(b.value));
    },
    storeNameByStaffId(staffId) {
      const hit = (this.personAuth.store_assignments || []).find((a) => Number(a.id) === Number(staffId));
      return (hit && hit.store_name) || ('任职#' + staffId);
    },
    previewCoverText(channelPreview) {
      if (!channelPreview) return '未覆盖';
      if (channelPreview.covered) {
        const n = Array.isArray(channelPreview.rule_ids) ? channelPreview.rule_ids.length : 0;
        return n > 0 ? `已覆盖（约 ${n} 项功能）` : '已覆盖';
      }
      return '未覆盖：请先设置含该端的有效岗位';
    },
    dataScopeSourceLabel(scope) {
      if (!scope) return '授权';
      if (scope.source_type === 'store') return `门店来源 #${scope.source_store_id || ''}`;
      return '总部来源';
    },
    dataScopeModeLabel(mode) {
      if (mode === 'org') return '已选组织范围';
      if (mode === 'store' || mode === 'store_self') return '门店自动范围';
      return '个人';
    },
    loadJobPositionOptions() {
      return getJobPositions({ status: 1, page: 1, limit: 100 })
        .then((res) => {
          const list = (res.data && res.data.list) || [];
          this.jobPositionOptions = list.map((row) => ({
            value: Number(row.id),
            label: row.name,
            keep_only: false
          }));
        })
        .catch(() => {
          this.jobPositionOptions = [];
        });
    },
    syncAuthFormsFromBundle() {
      const plat = (this.personAuth.platform || [])[0];
      this.authForms.platform = {
        admin_id: plat ? Number(plat.id) : Number((this.authForms.platform && this.authForms.platform.admin_id) || 0)
      };
      const jobsMap = {};
      const entriesMap = {};
      (this.personAuth.store_assignments || []).forEach((a) => {
        const sid = Number(a.id);
        const jobIds = ((a.job_positions || []).map((j) => Number(j.position_id || j.id || 0)).filter((x) => x > 0));
        this.$set(jobsMap, sid, jobIds);
        const entryState = { store_v3: 0, mobile: 0 };
        ((a.channel_entries || [])).forEach((e) => {
          const ch = String(e.channel || '');
          if (Object.prototype.hasOwnProperty.call(entryState, ch)) {
            entryState[ch] = Number(e.status) === 1 ? 1 : 0;
          }
        });
        this.$set(entriesMap, sid, entryState);
      });
      this.authForms.jobs = jobsMap;
      this.authForms.entries = entriesMap;
      const hqScope = (this.personAuth.data_scopes || []).find((s) => String(s.source_type) === 'hq') || null;
      this.authForms.dataScope = {
        scope_mode: (hqScope && hqScope.scope_mode) || 'personal',
        org_ids: hqScope && Array.isArray(hqScope.org_ids) ? hqScope.org_ids.slice() : [],
        store_ids: hqScope && Array.isArray(hqScope.store_ids) ? hqScope.store_ids.slice() : []
      };
    },
    refreshPersonAuth() {
      const employeeId = Number((this.drawer.person && (this.drawer.person.employee_id || this.drawer.person.id)) || 0);
      if (!employeeId) return Promise.resolve();
      this.personAuthLoading = true;
      return Promise.all([
        getEmployeeAuthBundle(employeeId),
        getEmployeeAuthAudits(employeeId, { page: 1, limit: 20 }),
        this.loadJobPositionOptions()
      ])
        .then(([authRes, auditRes]) => {
          this.personAuth = (authRes && authRes.data) || {};
          this.personAudits = ((auditRes && auditRes.data && auditRes.data.list) || []);
          this.syncAuthFormsFromBundle();
        })
        .catch(() => {
          this.personAuth = {};
          this.personAudits = [];
        })
        .finally(() => {
          this.personAuthLoading = false;
        });
    },
    runEmployeeAuthWrite(action, apiCall, payload) {
      if (!this.canWrite) {
        this.blockWrite();
        return Promise.resolve();
      }
      if (this.writeSubmitting) return Promise.resolve();
      const employeeId = Number((this.drawer.person && (this.drawer.person.employee_id || this.drawer.person.id)) || 0);
      if (!employeeId) {
        this.showToast('员工无效');
        return Promise.resolve();
      }
      const body = Object.assign({}, payload);
      const headers = this.writeHeadersFor(action, body);
      body.request_token = headers['X-Request-Token'];
      this.writeSubmitting = true;
      return apiCall(employeeId, body, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '保存成功');
          return this.refreshPersonAuth();
        })
        .catch((err) => this.handleWriteCatch(err))
        .finally(() => { this.writeSubmitting = false; });
    },
    savePlatformAuthBind() {
      const adminId = Number(this.authForms.platform.admin_id || 0);
      if (!adminId) {
        this.$Message && this.$Message.required
          ? this.$Message.required('账号 ID未填写')
          : this.showToast('请填写平台账号 ID');
        return;
      }
      const roles = (this.personAuth.platform_role_options || []).map((o) => Number(o.value)).filter((x) => x > 0).slice(0, 1);
      if (!roles.length) {
        this.showToast('暂无可用平台角色，请先配置岗位对应功能后再绑定');
        return;
      }
      this.resetWriteToken();
      return this.runEmployeeAuthWrite('employee_auth_platform', saveEmployeeAuthPlatform, {
        admin_id: adminId,
        action: 'bind',
        roles,
        reason: '开通平台入口'
      });
    },
    savePlatformEntry(p, action) {
      this.resetWriteToken();
      return this.runEmployeeAuthWrite('employee_auth_platform', saveEmployeeAuthPlatform, {
        admin_id: Number(p.id),
        action,
        roles: [],
        reason: action === 'enable' ? '开通平台入口' : '关闭平台入口'
      });
    },
    saveStaffJobs(a) {
      const positionIds = (this.authForms.jobs[a.id] || []).map(Number).filter((x) => x > 0);
      this.resetWriteToken();
      return this.runEmployeeAuthWrite('employee_auth_jobs', saveEmployeeAuthJobs, {
        staff_id: Number(a.id),
        store_id: Number(a.store_id),
        position_ids: positionIds,
        reason: '保存本店岗位'
      });
    },
    saveStaffEntries(a) {
      const state = this.authForms.entries[a.id] || {};
      const entries = [
        { channel: 'store_v3', status: Number(state.store_v3) === 1 ? 1 : 0 },
        { channel: 'mobile', status: Number(state.mobile) === 1 ? 1 : 0 }
      ];
      this.resetWriteToken();
      return this.runEmployeeAuthWrite('employee_auth_entries', saveEmployeeAuthEntries, {
        staff_id: Number(a.id),
        entries,
        reason: '保存本店入口'
      });
    },
    saveDataScope() {
      const form = this.authForms.dataScope || {};
      const scopeMode = form.scope_mode || 'personal';
      let orgIds = [];
      let storeIds = [];
      if (scopeMode === 'org') {
        orgIds = Array.isArray(form.org_ids) ? form.org_ids.map(Number).filter((x) => x > 0) : [];
        if (!orgIds.length) {
          this.$Message && this.$Message.required
            ? this.$Message.required('组织范围未选择')
            : this.showToast('请选择组织范围');
          return;
        }
      } else if (scopeMode === 'store') {
        storeIds = Array.isArray(form.store_ids) ? form.store_ids.map(Number).filter((x) => x > 0) : [];
        if (!storeIds.length) {
          this.$Message && this.$Message.required
            ? this.$Message.required('门店范围未选择')
            : this.showToast('请选择门店范围');
          return;
        }
      }
      this.resetWriteToken();
      return this.runEmployeeAuthWrite('employee_auth_data_scope', saveEmployeeAuthDataScope, {
        source_type: 'hq',
        source_store_id: 0,
        scope_mode: scopeMode,
        org_ids: orgIds,
        store_ids: storeIds,
        reason: '保存总部数据权限'
      });
    },
    openTenureConfirm(staff, action) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      this.tenureConfirmModal = {
        open: true,
        action,
        staff,
        reason: ''
      };
    },
    closeTenureConfirm() {
      this.tenureConfirmModal.open = false;
    },
    submitTenureConfirm() {
      const m = this.tenureConfirmModal;
      const reason = String(m.reason || '').trim();
      if (!reason) {
        this.$Message && this.$Message.required
          ? this.$Message.required('原因未填写')
          : this.showToast('请填写原因');
        return;
      }
      if (!m.staff) return;
      this.resetWriteToken();
      return this.runEmployeeAuthWrite('employee_auth_tenure', saveEmployeeAuthTenure, {
        staff_id: Number(m.staff.id),
        action: m.action,
        reason
      }).then(() => {
        this.closeTenureConfirm();
      });
    },
    openOpsConfirm(target, id, status, name) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      this.opsConfirmModal = {
        open: true,
        target,
        id: Number(id),
        status: Number(status) === 1 ? 1 : 0,
        name: name || '',
        reason: ''
      };
    },
    closeOpsConfirm() {
      this.opsConfirmModal.open = false;
    },
    submitOpsConfirm() {
      const m = this.opsConfirmModal;
      const reason = String(m.reason || '').trim();
      if (!reason) {
        this.$Message && this.$Message.required
          ? this.$Message.required('原因未填写')
          : this.showToast('请填写原因');
        return;
      }
      if (!m.id) {
        this.showToast('目标无效');
        return;
      }
      if (this.writeSubmitting) return;
      this.resetWriteToken();
      const payload = { status: m.status, reason };
      const headers = this.writeHeadersFor(m.target === 'store' ? 'store_ops_status' : 'organization_ops_status', {
        id: m.id,
        ...payload
      });
      payload.request_token = headers['X-Request-Token'];
      this.writeSubmitting = true;
      const api = m.target === 'store' ? saveStoreOpsStatus : saveOrganizationOpsStatus;
      api(m.id, payload, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '操作成功');
          this.closeOpsConfirm();
          if (m.target === 'store') {
            if (this.drawer.open && this.drawer.mode === 'store' && this.drawer.store) {
              this.drawer.store.business_status = m.status === 1 ? 'open' : 'closed';
            }
            this.reloadCurrentList();
          } else {
            this.loadOverview();
            this.loadTree();
          }
        })
        .catch((err) => this.handleWriteCatch(err))
        .finally(() => { this.writeSubmitting = false; });
    },
    openJobPositionModal() {
      this.jobPositionModal.open = true;
      this.jobPositionModal.formOpen = false;
      this.resetJobPositionForm();
      this.loadJobPositionList();
    },
    closeJobPositionModal() {
      if (this.jobPositionConfirmModal.open) {
        this.resolveJobPositionConfirm(false);
      }
      this.jobPositionModal.open = false;
      this.jobPositionModal.formOpen = false;
    },
    closeJobPositionForm() {
      this.jobPositionModal.formOpen = false;
    },
    resetJobPositionForm() {
      this.jobPositionModal.selectedId = 0;
      this.jobPositionModal.publishes = [];
      this.jobPositionModal.help = {};
      this.jobPositionModal.publish = { scope_type: 'store', scope_id: 0 };
      this.jobPositionModal.form = {
        id: 0,
        name: '',
        status: 1,
        remark: '',
        allow_store_select: 0,
        use_platform: 0,
        use_store: 1,
        use_mobile: 0,
        channel_rules: {}
      };
    },
    openCreateJobPosition() {
      this.resetJobPositionForm();
      this.jobPositionModal.selectedId = 0;
      this.jobPositionModal.formOpen = true;
    },
    openEditJobPosition(row) {
      this.jobPositionModal.selectedId = Number(row.id) || 0;
      this.jobPositionModal.formOpen = true;
    },
    onJobPositionFormPublish(publish) {
      this.jobPositionModal.publish = {
        scope_type: (publish && publish.scope_type) || 'store',
        scope_id: Number((publish && publish.scope_id) || 0)
      };
      this.jobPositionModal.form.id = Number(this.jobPositionModal.selectedId) || 0;
      this.submitJobPositionPublish();
    },
    closeJobPositionFormAfterSave() {
      this.$set(this.jobPositionModal, 'formOpen', false);
      this.$set(this.jobPositionModal, 'selectedId', 0);
      this.jobPositionModal.formOpen = false;
      this.jobPositionModal.selectedId = 0;
      this.$nextTick(() => {
        if (this.$refs.jobPositionFormModal && this.$refs.jobPositionFormModal.forceClose) {
          this.$refs.jobPositionFormModal.forceClose();
        }
      });
    },
    onJobPositionFormSave(formPayload) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      this.resetWriteToken();
      const payload = {
        id: Number(formPayload.id || 0),
        name: String(formPayload.name || '').trim(),
        status: Number(formPayload.status) === 1 ? 1 : 0,
        remark: formPayload.remark || '',
        allow_store_select: Number(formPayload.allow_store_select) === 1 ? 1 : 0,
        is_store_manager: Number(formPayload.is_store_manager) === 1 ? 1 : 0,
        use_platform: Number(formPayload.use_platform) === 1 ? 1 : 0,
        use_store: Number(formPayload.use_store) === 1 ? 1 : 0,
        use_mobile: Number(formPayload.use_mobile) === 1 ? 1 : 0,
        platform_rules: Number(formPayload.use_platform) === 1 ? (formPayload.platform_rules || []) : [],
        store_v3_rules: Number(formPayload.use_store) === 1 ? (formPayload.store_v3_rules || []) : [],
        mobile_rules: Number(formPayload.use_mobile) === 1 ? (formPayload.mobile_rules || []) : []
      };
      const headers = this.writeHeadersFor('job_position_save', payload);
      payload.request_token = headers['X-Request-Token'];
      this.writeSubmitting = true;
      saveJobPosition(payload, headers)
        .then((res) => {
          // 先关编辑页，再刷列表，避免大权限树回写卡住关窗
          this.closeJobPositionFormAfterSave();
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '保存成功');
          this.loadJobPositionList();
          this.loadJobPositionOptions();
        })
        .catch((err) => this.handleWriteCatch(err))
        .finally(() => { this.writeSubmitting = false; });
    },
    loadJobPositionList() {
      getJobPositions({ keyword: this.jobPositionModal.keyword || '', status: this.jobPositionModal.status, page: 1, limit: 50 })
        .then((res) => {
          this.jobPositionModal.list = (res.data && res.data.list) || [];
        })
        .catch((err) => this.showToast((err && err.msg) || '加载岗位失败'));
    },
    isJobPositionToggleBusy(id) {
      return !!this.jobPositionModal.togglingIds[Number(id)];
    },
    setJobPositionToggleBusy(id, busy) {
      this.$set(this.jobPositionModal.togglingIds, Number(id), !!busy);
    },
    jobPositionAllowSelectTooltip(row) {
      if (Number(row.status) !== 1) {
        return '岗位已停用：请先启用后再修改门店可用；停用期间门店不能新增选择';
      }
      return Number(row.allow_store_select) === 1
        ? '当前门店可用：关闭后门店不能再新增选择，已绑定不会清除'
        : '当前门店不可用：开启后，门店新建/编辑本店员工时可选择该岗位';
    },
    jobPositionChannelsLabel(row) {
      const parts = [];
      if (Number(row.use_platform) === 1) parts.push('平台');
      if (Number(row.use_store) === 1) parts.push('门店端');
      if (Number(row.use_mobile) === 1) parts.push('手机');
      return parts.length ? parts.join(' / ') : '—';
    },
    confirmJobPositionAction(content) {
      return new Promise((resolve) => {
        if (this.jobPositionConfirmModal.resolver) {
          this.jobPositionConfirmModal.resolver(false);
        }
        this.jobPositionConfirmModal = {
          open: true,
          content: String(content || ''),
          resolver: resolve
        };
      });
    },
    resolveJobPositionConfirm(ok) {
      const resolve = this.jobPositionConfirmModal.resolver;
      this.jobPositionConfirmModal = { open: false, content: '', resolver: null };
      if (typeof resolve === 'function') resolve(!!ok);
    },
    normalizeJobChannelRules(channelRules) {
      const src = channelRules && typeof channelRules === 'object' ? channelRules : {};
      const out = {};
      ['platform', 'store_v3', 'mobile'].forEach((ch) => {
        const item = src[ch];
        if (item == null) {
          out[ch] = { rules: '' };
          return;
        }
        if (typeof item === 'object') {
          out[ch] = { rules: String(item.rules || '') };
        } else {
          out[ch] = { rules: String(item || '') };
        }
      });
      return out;
    },
    buildJobPositionSavePayload(base, patch) {
      const src = Object.assign({}, base || {}, patch || {});
      return {
        id: Number(src.id || 0),
        name: String(src.name || '').trim(),
        status: Number(src.status) === 1 ? 1 : 0,
        remark: src.remark || '',
        allow_store_select: Number(src.allow_store_select) === 1 ? 1 : 0,
        is_store_manager: Number(src.is_store_manager) === 1 ? 1 : 0,
        use_platform: Number(src.use_platform) === 1 ? 1 : 0,
        use_store: Number(src.use_store) === 1 ? 1 : 0,
        use_mobile: Number(src.use_mobile) === 1 ? 1 : 0,
        channel_rules: this.normalizeJobChannelRules(src.channel_rules)
      };
    },
    async onJobPositionStatusSwitch(row) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (!row || !row.id || this.writeSubmitting || this.isJobPositionToggleBusy(row.id)) return;
      const nextStatus = Number(row.status) === 1 ? 0 : 1;
      const content = nextStatus === 1
        ? '确认启用该岗位？'
        : '停用后门店不能再新增选择，已绑定人员不会自动清除权限，是否继续？';
      const ok = await this.confirmJobPositionAction(content);
      if (!ok) return;
      await this.saveJobPositionListToggle(row, { status: nextStatus });
    },
    async onJobPositionAllowSelectSwitch(row) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (!row || !row.id || this.writeSubmitting || this.isJobPositionToggleBusy(row.id)) return;
      if (Number(row.status) !== 1) {
        this.showToast('岗位已停用，请先启用后再修改门店可用');
        return;
      }
      const nextAllow = Number(row.allow_store_select) === 1 ? 0 : 1;
      if (nextAllow === 0) {
        const ok = await this.confirmJobPositionAction(
          '关闭门店可用后，门店不能再新增选择该岗位；已经绑定的人员不会自动失去权限，是否继续？'
        );
        if (!ok) return;
      } else {
        const ok = await this.confirmJobPositionAction(
          '开启后门店在新建或编辑本店员工时可选择该岗位。若岗位含平台后台权限，门店员工仍不会获得平台入口，是否继续？'
        );
        if (!ok) return;
      }
      await this.saveJobPositionListToggle(row, { allow_store_select: nextAllow });
    },
    async saveJobPositionListToggle(row, patch) {
      const positionId = Number(row.id);
      const prevStatus = Number(row.status) === 1 ? 1 : 0;
      const prevAllow = Number(row.allow_store_select) === 1 ? 1 : 0;
      if (Object.prototype.hasOwnProperty.call(patch, 'status')) {
        this.$set(row, 'status', Number(patch.status) === 1 ? 1 : 0);
      }
      if (Object.prototype.hasOwnProperty.call(patch, 'allow_store_select')) {
        this.$set(row, 'allow_store_select', Number(patch.allow_store_select) === 1 ? 1 : 0);
      }
      this.setJobPositionToggleBusy(positionId, true);
      this.writeSubmitting = true;
      try {
        if (Object.prototype.hasOwnProperty.call(patch, 'status') &&
          !Object.prototype.hasOwnProperty.call(patch, 'allow_store_select')) {
          this.resetWriteToken();
          const statusPayload = {
            id: positionId,
            status: Number(patch.status) === 1 ? 1 : 0,
            status_only: 1
          };
          const statusHeaders = this.writeHeadersFor('job_position_status', {
            id: positionId,
            ...statusPayload
          });
          statusPayload.request_token = statusHeaders['X-Request-Token'];
          const statusRes = await saveJobPosition(statusPayload, statusHeaders);
          this.finishWriteSuccess();
          this.showToast((statusRes && statusRes.msg) || '保存成功');
          this.loadJobPositionList();
          this.loadJobPositionOptions();
          return;
        }
        const detailRes = await getJobPositionDetail(positionId);
        const data = (detailRes && detailRes.data) || {};
        // 详情接口在兼容旧实例时可能缺少主键；列表行主键是当前操作目标，必须优先保留。
        const p = Object.assign({}, row, data.position || {}, { id: positionId });
        const payload = this.buildJobPositionSavePayload({
          id: positionId || Number(p.id) || Number(row.id) || 0,
          name: p.name || row.name || '',
          status: p.status == null ? Number(row.status) : Number(p.status),
          remark: p.remark == null ? (row.remark || '') : p.remark,
          allow_store_select: p.allow_store_select == null
            ? Number(row.allow_store_select) : Number(p.allow_store_select),
          is_store_manager: p.is_store_manager == null
            ? Number(row.is_store_manager) : Number(p.is_store_manager),
          use_platform: p.use_platform == null ? Number(row.use_platform) : Number(p.use_platform),
          use_store: p.use_store == null ? Number(row.use_store) : Number(p.use_store),
          use_mobile: p.use_mobile == null ? Number(row.use_mobile) : Number(p.use_mobile),
          channel_rules: data.channel_rules || row.channel_rules || {}
        }, patch);
        if (!payload.name) {
          throw Object.assign(new Error('岗位名称无效'), { msg: '岗位名称无效', __orgWriteKind: 'business_fail' });
        }
        this.resetWriteToken();
        const headers = this.writeHeadersFor('job_position_save', payload);
        payload.request_token = headers['X-Request-Token'];
        const res = await saveJobPosition(payload, headers);
        this.finishWriteSuccess();
        this.showToast((res && res.msg) || '保存成功');
        this.loadJobPositionList();
        this.loadJobPositionOptions();
      } catch (err) {
        this.$set(row, 'status', prevStatus);
        this.$set(row, 'allow_store_select', prevAllow);
        this.handleWriteCatch(err);
      } finally {
        this.setJobPositionToggleBusy(positionId, false);
        this.writeSubmitting = false;
      }
    },
    selectJobPosition(row, openForm) {
      getJobPositionDetail(Number(row.id))
        .then((res) => {
          const data = (res && res.data) || {};
          const p = data.position || row;
          this.jobPositionModal.selectedId = Number(p.id);
          this.jobPositionModal.help = data.help || {};
          this.jobPositionModal.publishes = data.publishes || [];
          this.jobPositionModal.form = {
            id: Number(p.id),
            name: p.name || '',
            status: Number(p.status) === 1 ? 1 : 0,
            remark: p.remark || '',
            allow_store_select: Number(p.allow_store_select) === 1 ? 1 : 0,
            use_platform: Number(p.use_platform) === 1 ? 1 : 0,
            use_store: Number(p.use_store) === 1 ? 1 : 0,
            use_mobile: Number(p.use_mobile) === 1 ? 1 : 0,
            channel_rules: this.normalizeJobChannelRules(data.channel_rules || {})
          };
          if (openForm) {
            this.jobPositionModal.formOpen = true;
          }
        })
        .catch((err) => this.showToast((err && err.msg) || '加载岗位详情失败'));
    },
    submitJobPositionSave() {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      const form = this.jobPositionModal.form || {};
      if (!String(form.name || '').trim()) {
        this.$Message && this.$Message.required
          ? this.$Message.required('岗位名称未填写')
          : this.showToast('请填写岗位名称');
        return;
      }
      if (this.writeSubmitting) return;
      this.resetWriteToken();
      const payload = this.buildJobPositionSavePayload(form);
      const headers = this.writeHeadersFor('job_position_save', payload);
      payload.request_token = headers['X-Request-Token'];
      this.writeSubmitting = true;
      saveJobPosition(payload, headers)
        .then((res) => {
          this.closeJobPositionFormAfterSave();
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '保存成功');
          this.loadJobPositionList();
          this.loadJobPositionOptions();
        })
        .catch((err) => this.handleWriteCatch(err))
        .finally(() => { this.writeSubmitting = false; });
    },
    submitJobPositionPublish() {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      const positionId = Number(this.jobPositionModal.form.id || this.jobPositionModal.selectedId || 0);
      const scopeId = Number(this.jobPositionModal.publish.scope_id || 0);
      if (!positionId) {
        this.showToast('请先保存岗位');
        return;
      }
      if (!scopeId) {
        this.$Message && this.$Message.required
          ? this.$Message.required('发布目标未选择')
          : this.showToast('请选择发布目标');
        return;
      }
      if (this.writeSubmitting) return;
      this.resetWriteToken();
      const payload = {
        position_id: positionId,
        scope_type: this.jobPositionModal.publish.scope_type || 'store',
        scope_id: scopeId
      };
      const headers = this.writeHeadersFor('job_position_publish', payload);
      payload.request_token = headers['X-Request-Token'];
      this.writeSubmitting = true;
      publishJobPosition(payload, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '发布成功');
          this.jobPositionModal.selectedId = positionId;
          this.loadJobPositionList();
          if (this.$refs.jobPositionFormModal && this.$refs.jobPositionFormModal.reloadDetail) {
            this.$refs.jobPositionFormModal.reloadDetail();
          }
        })
        .catch((err) => this.handleWriteCatch(err))
        .finally(() => { this.writeSubmitting = false; });
    },
    disableJobPublishRow(row) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      this.resetWriteToken();
      const payload = {};
      const headers = this.writeHeadersFor('job_position_publish_disable', { id: Number(row.id) });
      payload.request_token = headers['X-Request-Token'];
      this.writeSubmitting = true;
      disableJobPositionPublish(Number(row.id), payload, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '已停用');
          this.loadJobPositionList();
          if (this.$refs.jobPositionFormModal && this.$refs.jobPositionFormModal.reloadDetail) {
            this.$refs.jobPositionFormModal.reloadDetail();
          }
        })
        .catch((err) => this.handleWriteCatch(err))
        .finally(() => { this.writeSubmitting = false; });
    },
    openRolePublishModal() {
      this.rolePublishModal.open = true;
      this.rolePublishModal.formOpen = false;
      this.rolePublishModal.recordsOpen = false;
      this.rolePublishModal.editOpen = false;
      this.rolePublishModal.selected = null;
      this.rolePublishModal.store_id = 0;
      this.rolePublishModal.org_id = 0;
      this.rolePublishModal.scope_type = 'store';
      this.rolePublishModal.publishes = [];
      this.loadRoleTemplates();
    },
    closeRolePublishModal() {
      this.rolePublishModal.open = false;
      this.rolePublishModal.formOpen = false;
      this.rolePublishModal.recordsOpen = false;
      this.rolePublishModal.editOpen = false;
    },
    openRoleTemplateCreate() {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      this.rolePublishModal.editId = 0;
      this.rolePublishModal.editOpen = true;
    },
    openRoleTemplateEdit(row) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      this.rolePublishModal.editId = Number(row.id) || 0;
      this.rolePublishModal.editOpen = true;
    },
    onRoleTemplateSaved() {
      this.loadRoleTemplates();
    },
    disableRoleTemplateRow(row) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      this.resetWriteToken();
      const payload = {};
      const headers = this.writeHeadersFor('role_template_disable', { id: Number(row.id) });
      payload.request_token = headers['X-Request-Token'];
      this.writeSubmitting = true;
      disableRoleTemplate(Number(row.id), payload, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '模板已停用');
          this.loadRoleTemplates();
        })
        .catch((err) => this.handleWriteCatch(err))
        .finally(() => { this.writeSubmitting = false; });
    },
    openRolePublishForm(row) {
      this.rolePublishModal.selected = row;
      this.rolePublishModal.store_id = 0;
      this.rolePublishModal.org_id = 0;
      this.rolePublishModal.scope_type = 'store';
      this.rolePublishModal.channel = 'store_backend';
      this.rolePublishModal.allow_store_select = Number(row.allow_store_select) === 1 ? 1 : 0;
      this.rolePublishModal.formOpen = true;
    },
    closeRolePublishForm() {
      this.rolePublishModal.formOpen = false;
    },
    openRolePublishRecords(row) {
      this.rolePublishModal.selected = row;
      this.rolePublishModal.recordsOpen = true;
      this.loadRolePublishes(row);
    },
    closeRolePublishRecords() {
      this.rolePublishModal.recordsOpen = false;
    },
    loadRoleTemplates() {
      getRoleTemplates({ keyword: this.rolePublishModal.keyword || '', page: 1, limit: 50 })
        .then((res) => {
          this.rolePublishModal.templates = (res.data && res.data.list) || [];
        })
        .catch((err) => this.showToast((err && err.msg) || '加载模板失败'));
    },
    loadRolePublishes(row) {
      getRolePublishes({ template_role_id: Number(row.id), store_id: 0 })
        .then((res) => {
          this.rolePublishModal.publishes = (res.data && res.data.list) || [];
        })
        .catch((err) => this.showToast((err && err.msg) || '加载发布记录失败'));
    },
    submitRolePublish() {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      const tpl = this.rolePublishModal.selected;
      if (!tpl || !tpl.id) {
        this.showToast('请选择模板');
        return;
      }
      const scopeType = this.rolePublishModal.scope_type === 'org' ? 'org' : 'store';
      const storeId = Number(this.rolePublishModal.store_id || 0);
      const orgId = Number(this.rolePublishModal.org_id || 0);
      if (scopeType === 'store' && !storeId) {
        this.$Message && this.$Message.required
          ? this.$Message.required('目标门店未选择')
          : this.showToast('请选择门店');
        return;
      }
      if (scopeType === 'org' && !orgId) {
        this.$Message && this.$Message.required
          ? this.$Message.required('目标组织未选择')
          : this.showToast('请选择组织');
        return;
      }
      this.resetWriteToken();
      const payload = {
        template_role_id: Number(tpl.id),
        store_id: scopeType === 'store' ? storeId : 0,
        org_id: scopeType === 'org' ? orgId : 0,
        channel: this.rolePublishModal.channel || 'store_backend',
        allow_store_select: Number(this.rolePublishModal.allow_store_select) === 1 ? 1 : 0
      };
      const headers = this.writeHeadersFor('role_template_publish', payload);
      payload.request_token = headers['X-Request-Token'];
      this.writeSubmitting = true;
      publishRoleTemplate(payload, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '发布成功');
          this.rolePublishModal.formOpen = false;
          this.loadRoleTemplates();
          this.loadRolePublishes(tpl);
        })
        .catch((err) => this.handleWriteCatch(err))
        .finally(() => { this.writeSubmitting = false; });
    },
    disableRolePublishRow(row) {
      if (!this.canWrite) {
        this.blockWrite();
        return;
      }
      if (this.writeSubmitting) return;
      this.resetWriteToken();
      const payload = {};
      const headers = this.writeHeadersFor('role_template_publish_disable', { id: Number(row.id) });
      payload.request_token = headers['X-Request-Token'];
      this.writeSubmitting = true;
      disableRolePublish(Number(row.id), payload, headers)
        .then((res) => {
          this.finishWriteSuccess();
          this.showToast((res && res.msg) || '已停用');
          if (this.rolePublishModal.selected) this.loadRolePublishes(this.rolePublishModal.selected);
          this.loadRoleTemplates();
        })
        .catch((err) => this.handleWriteCatch(err))
        .finally(() => { this.writeSubmitting = false; });
    },
    openPersonDrawer(person) {
      this.drawer = {
        open: true,
        mode: 'person',
        title: '人员信息',
        kicker: '统一员工档案',
        store: null,
        person,
        search: ''
      };
      this.personAuth = {};
      this.personAudits = [];
      this.refreshPersonAuth();
    },
    openPermissionDrawer(person) {
      const editable = this.canWrite && this.canEditPermissionPerson(person);
      if (editable) this.resetWriteToken();
      this.initPermissionDraft(person);
      this.permissionStoreOptions = this.orgStoreOptions.slice();
      this.permissionRangeReady = this.tabLoaded.permissions && !this.permState.error;
      this.drawer = {
        open: true,
        mode: 'permission',
        title: editable ? `调整${person.name || ''}的管理范围` : `查看${person.name || ''}的管理范围`,
        kicker: editable ? '权限范围' : '权限范围（只读）',
        store: null,
        person,
        search: ''
      };
      if (editable && !this.permissionRangeReady) {
        this.loadPermissions();
      }
    },
    closeDrawer() {
      this.drawer.open = false;
      this.closePersonOps();
    }
  }
};
</script>

<style src="./workspace.css"></style>
<style scoped lang="stylus">
.org-prototype
  color #172033
  font-size 14px
  line-height 1.5

.drawer-layer.open
  visibility visible
  opacity 1

.drawer-layer.open .drawer
  transform translateX(0)

.mobile-tree-open
  display none

.mobile-tree-close
  display none

.enter-key
  margin-left 2px
  font-weight 600

.avatar-img
  width 32px
  height 32px
  border-radius 50%
  object-fit cover

.avatar-img.lg
  width 48px
  height 48px

.readonly-option
  cursor default

.employee-option.selected
  outline 1px solid #5b5bd6
  background #f4f5ff

.avatar-indigo
  background #eeeeff
  color #5b5bd6

.avatar-teal
  background #e8f7f3
  color #1f9d70

.is-readonly-disabled
  opacity 0.55
  cursor not-allowed
  filter grayscale(0.15)

.is-readonly-disabled:hover
  opacity 0.55

.auth-write-box
  margin-top 10px
  padding 10px 12px
  border 1px solid #e7ebf3
  border-radius 10px
  background #fafbff

.auth-row
  display flex
  align-items flex-start
  gap 10px
  margin 8px 0
  label
    width 88px
    flex-shrink 0
    color #667085
    padding-top 6px
  input, select
    flex 1
    min-height 34px

.auth-roles .role-checks
  flex 1

.role-checks
  display flex
  flex-wrap wrap
  gap 8px 14px

.role-check
  display inline-flex
  align-items center
  gap 4px
  font-size 13px

.auth-actions
  display flex
  flex-wrap wrap
  gap 8px
  margin-top 8px

@media (max-width: 820px)
  .mobile-tree-open
    display inline-flex

  .desktop-tree-toggle
    display none

  .mobile-tree-close
    display inline-grid
</style>
