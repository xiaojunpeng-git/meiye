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
                <button class="button primary" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" type="button" :disabled="writeSubmitting" :aria-disabled="(!canWrite || writeSubmitting).toString()" @click="openCreateOrgModal()"><svg-icon name="plus" />新增组织</button>
                <button class="button secondary" :class="{ 'is-readonly-disabled': !canWrite || writeSubmitting }" type="button" :disabled="writeSubmitting" :aria-disabled="(!canWrite || writeSubmitting).toString()" @click="openEditOrgModal"><svg-icon name="edit" />编辑组织</button>
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
                  <button class="text-button" type="button" @click="switchTab('stores'); dataView = 'people'">查看人员</button>
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

          <div v-show="activeTab === 'stores'" class="tab-pane active">
            <section class="section-card full-card">
              <div class="list-toolbar">
                <div class="segmented">
                  <button type="button" :class="{ active: dataView === 'store' }" @click="switchDataView('store')">按门店</button>
                  <button type="button" :class="{ active: dataView === 'people' }" @click="switchDataView('people')">按人员</button>
                </div>
                <div class="toolbar-right">
                  <div class="input-shell small">
                    <svg-icon name="search" />
                    <input v-model.trim="dataSearch" type="search" placeholder="搜索门店、姓名或手机号" @keyup.enter="reloadCurrentList" />
                  </div>
                  <button class="button secondary compact-button" type="button" @click="reloadCurrentList">查询 <span class="enter-key">↵</span></button>
                  <div class="view-switch">
                    <button type="button" :class="{ active: dataLayout === 'list' }" @click="dataLayout = 'list'"><svg-icon name="list" /></button>
                    <button type="button" :class="{ active: dataLayout === 'card' }" @click="dataLayout = 'card'"><svg-icon name="card" /></button>
                  </div>
                </div>
              </div>
              <div v-if="dataView === 'store'" class="filter-chips">
                <button v-for="filter in filters" :key="filter.key" class="chip" type="button" :class="{ active: dataFilter === filter.key }" @click="setStoreFilter(filter.key)">{{ filter.label }}</button>
              </div>

              <div v-if="listState.visible || listState.slow" class="empty-inline">{{ listState.slow ? '数据较多，仍在加载，请稍候' : '正在加载…' }}</div>
              <div v-else-if="listState.error" class="empty-inline">
                {{ listState.error }}
                <button class="text-button" type="button" @click="reloadCurrentList">重试</button>
              </div>
              <template v-else>
                <div v-if="dataView === 'store' && dataLayout === 'list'">
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
                        <td><button class="table-action" type="button" @click.stop="openStoreDrawer(store)">查看详情</button></td>
                      </tr>
                    </tbody>
                  </table>
                  <div v-if="!storeList.length" class="empty-state">没有找到符合条件的门店</div>
                </div>
                <div v-else-if="dataView === 'store'">
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
                    <thead><tr><th>人员</th><th>任职门店</th><th>角色</th><th>手机号</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                      <tr v-for="person in employeeList" :key="person.employee_id" @click="openPersonDrawer(person)">
                        <td>
                          <div class="person-cell">
                            <img v-if="person.avatar" class="avatar-img" :src="person.avatar" alt="" @error="onAvatarError" />
                            <span v-else class="avatar avatar-indigo">{{ avatarText(person.name) }}</span>
                            <div><strong>{{ person.name }}</strong><small>{{ person.scope_assignment_count }} 家门店任职</small></div>
                          </div>
                        </td>
                        <td>{{ assignmentStoreText(person) }}</td>
                        <td><div class="role-tags"><span v-for="role in person.roles" :key="role" class="role-tag">{{ role }}</span></div></td>
                        <td>{{ person.phone_masked }}</td>
                        <td><span class="status">在职</span></td>
                        <td><button class="table-action" type="button" @click.stop="openPersonDrawer(person)">查看档案</button></td>
                      </tr>
                    </tbody>
                  </table>
                  <div v-if="!employeeList.length" class="empty-state">没有找到符合条件的人员</div>
                </div>
                <div class="pagination">
                  <span>共 {{ listTotal }} {{ dataView === 'store' ? '家门店' : '名人员' }}</span>
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
                    <button class="table-action" type="button" data-action="view-scope" @click="openPermissionDrawer(person)">查看范围</button>
                  </div>
                  <div v-if="!permissionHolders.length" class="empty-inline">当前组织暂无后台权限人员</div>
                </div>
              </template>
              <div class="permission-note"><strong>说明</strong><span>这里展示的是最终生效范围。修改功能将在 O4 开放。</span></div>
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
          <button class="icon-button" type="button" @click="closeDrawer"><svg-icon name="x" /></button>
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
              <img v-if="drawer.person.avatar" class="avatar-img lg" :src="drawer.person.avatar" alt="" @error="onAvatarError" />
              <span v-else class="avatar avatar-indigo">{{ avatarText(drawer.person.name) }}</span>
              <div><h3>{{ drawer.person.name }}</h3><p>{{ drawer.person.phone_masked }}</p></div>
              <span class="status" style="margin-left:auto">在职</span>
            </div>
            <div class="form-section" style="margin-top:20px">
              <div class="form-section-title">任职关系（当前组织范围）</div>
              <div v-for="a in (drawer.person.assignments || [])" :key="a.staff_id" class="permission-note">
                <strong>{{ a.store_name }}</strong><span>{{ a.position || (a.roles || []).join(' / ') || '—' }}</span>
              </div>
              <div v-if="drawer.person.has_out_of_scope_assignments" class="permission-note">
                <strong>提示</strong><span>该员工在组织范围外还有任职（共 {{ drawer.person.total_assignment_count }} 家门店）</span>
              </div>
            </div>
            <div class="form-section">
              <div class="form-section-title">角色</div>
              <div class="role-tags"><span v-for="role in drawer.person.roles" :key="role" class="tag soft">{{ role }}</span></div>
            </div>
            <div class="permission-note"><strong>说明</strong><span>该抽屉只用于组织页面快速查看，档案修改统一进入店员管理。</span></div>
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
        <div v-if="drawer.mode === 'leaders' || drawer.mode === 'permission'" class="drawer-footer">
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
          <p v-if="deleteModal.step === 1">确定要删除组织「{{ deleteModal.name }}」吗？删除后不可通过本操作恢复。</p>
          <p v-else>此操作不可恢复，请再次确认删除组织「{{ deleteModal.name }}」。</p>
        </div>
        <div class="modal-footer">
          <button class="button secondary" type="button" :disabled="writeSubmitting" @click="closeDeleteModal">取消</button>
          <button v-if="deleteModal.step === 1" class="button secondary danger-button" type="button" :disabled="writeSubmitting" @click="deleteModal.step = 2">继续删除</button>
          <button v-else class="button primary danger-button" type="button" :disabled="writeSubmitting" @click="submitDeleteOrg">{{ writeSubmitting ? '删除中…' : '确认删除' }}</button>
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
  getOrganizationChangeLog,
  getOrganizationWriteStatus,
  saveOrganization,
  deleteOrganization,
  bindOrganizationStore,
  saveOrganizationLeaders,
  saveOrganizationAdminPermission,
} from '@/api/store';
import SvgIcon from './components/SvgIcon';
import OrganizationTree from './components/OrganizationTree';
import { resolveOrgWriteToken } from '@/api/orgWriteHelpers';

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
  components: { SvgIcon, OrganizationTree },
  data() {
    return {
      orgs: [],
      selectedOrgId: 0,
      overview: null,
      treeSearch: '',
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
        is_super_admin: false,
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
      deleteModal: { open: false, step: 1, orgId: 0, name: '' },
      writeSubmitting: false,
      treeState: emptyLoadState(),
      overviewState: emptyLoadState(),
      listState: emptyLoadState(),
      permState: emptyLoadState(),
      logState: emptyLoadState(),
      candidateState: emptyLoadState(),
      storeStaffState: emptyLoadState(),
      tabLoaded: { stores: false, permissions: false, logs: false },
      filters: [
        { key: 'all', label: '全部' },
        { key: 'open', label: '营业中' },
        { key: 'closed', label: '停用/未营业' },
        { key: 'attention', label: '待完善' },
        { key: 'direct', label: '直属门店' },
      ],
      defaultAvatar: '/static/images/staff/avatar_male.png',
      READONLY_TIP,
    };
  },
  computed: {
    canWrite() {
      return !!(this.writeStatus && this.writeStatus.can_write);
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
        attention: (ov.attention && ov.attention.total) || fromTree.attention || 0,
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
          attention: c.attention_count,
        }));
      }
      return this.orgs
        .filter((org) => Number(org.parentId) === Number(this.selectedOrgId))
        .map((c) => ({
          id: c.id,
          name: c.name,
          stores: c.stores,
          employees: c.employees,
          attention: c.attention,
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
        { key: 'stores', label: '门店与人员', count: this.selectedOrg.stores },
        { key: 'permissions', label: '权限范围' },
        { key: 'logs', label: '变更记录' },
      ];
    },
    metrics() {
      return [
        { label: '下级组织', value: this.childOrgs.length, unit: '个', icon: 'branch', color: '#eeeeff', ink: '#5b5bd6' },
        { label: '直属门店', value: this.selectedOrg.directStores, unit: '家', icon: 'store', color: '#e8f7f3', ink: '#1f9d70' },
        { label: '全部门店', value: this.selectedOrg.stores, unit: '家', icon: 'building', color: '#ebf4ff', ink: '#4384cf' },
        { label: '在职人员', value: this.selectedOrg.employees, unit: '人', icon: 'users', color: '#fff3e8', ink: '#d9822b' },
        { label: '本级负责人', value: this.selectedLeaders.length, unit: '人', icon: 'shield', color: '#f5efff', ink: '#8854c7' },
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
  },
  created() {
    this.loadWriteStatus().finally(() => {
      this.loadTree();
    });
  },
  beforeDestroy() {
    clearTimeout(this.toastTimer);
    this.clearLoadTimers(this.treeState);
    this.clearLoadTimers(this.overviewState);
    this.clearLoadTimers(this.listState);
    this.clearLoadTimers(this.permState);
    this.clearLoadTimers(this.logState);
    this.clearLoadTimers(this.candidateState);
    this.clearLoadTimers(this.storeStaffState);
  },
  methods: {
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
      if (!list.length) return '—';
      if (list.length === 1) return list[0].store_name;
      return `${list[0].store_name} 等${list.length}家`;
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
          fingerprint: this.pendingWriteFingerprint,
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
        sort: 0,
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
        sort: Number(org.sort || 0),
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
        sort: Number(modal.sort || 0),
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
      this.deleteModal = { open: true, step: 1, orgId: id, name };
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
            is_super_admin: !!data.is_super_admin,
          };
        })
        .catch(() => {
          this.writeStatus = {
            write_enabled: false,
            can_write: false,
            reason_code: 'WRITE_DISABLED',
            reason_text: READONLY_TIP,
            source_mode: '',
            is_super_admin: false,
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
          sort: this.draftLeaders.length + 1,
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
        sort: i + 1,
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
          stores: node.all_store_count != null ? node.all_store_count : (node.store_count || 0),
          directStores: node.direct_store_count != null ? node.direct_store_count : (node.store_count || 0),
          employees: node.employee_count || 0,
          attention: node.attention_count || 0,
          leaders: node.leader_count || 0,
          sort: node.sort != null ? Number(node.sort) : 0,
          updated: node.update_time_text || '',
          open: prev != null ? prev : (parentId == null),
          agent_count: node.agent_count || 0,
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
      this.tabLoaded = { stores: false, permissions: false, logs: false };
      const seq = this.beginLoad('overviewState');
      getOrganizationWorkspaceOverview({ org_id: orgId })
        .then((res) => {
          if (!this.endLoad('overviewState', seq)) return;
          if (Number(this.selectedOrgId) !== orgId) return;
          this.overview = res.data || null;
          if (this.activeTab === 'stores') this.reloadCurrentList();
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
      this.activeTab = key;
      if (key === 'stores' && !this.tabLoaded.stores) {
        this.reloadCurrentList();
      } else if (key === 'permissions' && !this.tabLoaded.permissions) {
        this.loadPermissions();
      } else if (key === 'logs' && !this.tabLoaded.logs) {
        this.loadLogs(1);
      }
    },
    switchDataView(view) {
      this.dataView = view;
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
        limit: this.listLimit,
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
    loadEmployees() {
      if (!this.selectedOrgId) return;
      const orgId = Number(this.selectedOrgId);
      const seq = this.beginLoad('listState');
      getOrganizationWorkspaceEmployees({
        org_id: orgId,
        scope: 'all',
        keyword: this.dataSearch,
        page: this.listPage,
        limit: this.listLimit,
      })
        .then((res) => {
          if (!this.endLoad('listState', seq)) return;
          if (Number(this.selectedOrgId) !== orgId || this.dataView !== 'people') return;
          const data = res.data || {};
          this.employeeList = data.list || [];
          this.listTotal = data.count || 0;
          this.tabLoaded.stores = true;
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
            name: s.name || '',
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
        limit: this.logLimit,
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
        y: Math.min(window.innerHeight - 100, point.bottom + 4),
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
        sort: i + 1,
      }));
      this.drawer = {
        open: true,
        mode: 'leaders',
        title: this.canWrite ? `设置${this.selectedOrg.name}负责人` : `查看${this.selectedOrg.name}负责人候选`,
        kicker: this.canWrite ? '人员关系' : '人员关系（只读）',
        store: null,
        person: null,
        search: '',
      };
      this.loadLeaderCandidates(1);
    },
    loadLeaderCandidates(page) {
      this.candidatePage = page || 1;
      const seq = this.beginLoad('candidateState');
      getOrganizationLeaderCandidates({
        keyword: this.drawer.search || '',
        page: this.candidatePage,
        limit: this.candidateLimit,
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
        search: '',
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
        limit: this.storeStaffLimit,
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
    openPersonDrawer(person) {
      this.drawer = {
        open: true,
        mode: 'person',
        title: '人员信息',
        kicker: '统一员工档案',
        store: null,
        person,
        search: '',
      };
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
        search: '',
      };
      if (editable && !this.permissionRangeReady) {
        this.loadPermissions();
      }
    },
    closeDrawer() {
      this.drawer.open = false;
    },
  },
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

@media (max-width: 820px)
  .mobile-tree-open
    display inline-flex

  .desktop-tree-toggle
    display none

  .mobile-tree-close
    display inline-grid
</style>
