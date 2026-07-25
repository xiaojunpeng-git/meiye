<template>
  <div class="writeoff-preview writeoff-workbench" @click="moreOpen = false">
    <header class="preview-header">
      <div class="brand">
        <button class="back-btn" type="button" @click="$emit('exit')">
          <Icon type="ios-arrow-back" /> 返回
        </button>
        <div class="brand-text">
          <div class="brand-title">{{ mode === 'replace' ? '收银台 · 项目替换' : '收银台 · 项目核销' }}</div>
        </div>
      </div>
      <div class="mode-tabs">
        <button type="button" :class="{ active: mode === 'batch' }" @click="switchMode('batch')">
          <Icon type="md-checkmark-circle-outline" /> 项目核销
        </button>
        <button type="button" :class="{ active: mode === 'replace' }" :disabled="!hasMember" @click="switchMode('replace')">
          <Icon type="md-swap" /> 项目替换
        </button>
      </div>
      <div class="header-right">
        <button class="help-btn" type="button" @click="openInstructions">
          <Icon type="ios-help-circle-outline" /> 操作说明
        </button>
      </div>
    </header>

    <div v-if="loadingOptions" class="loading-mask">
      <Icon type="ios-loading" class="spin" />
      <span>正在加载会员汇总与卡项…</span>
    </div>

    <main v-else-if="mode === 'batch'" class="preview-main">
      <aside class="card-panel">
        <section class="member-card">
          <div class="member-card-top">
            <div class="member-meta">
              <div class="member-name-row">
                <strong>{{ hasMember ? memberName : '未选择会员' }}</strong>
                <span v-if="hasMember" class="member-phone">{{ displayPhone }}</span>
              </div>
              <span class="member-id">{{ hasMember ? ('会员ID ' + activeUid) : '请先选择会员后再核销' }}</span>
            </div>
            <button class="member-switch-btn" type="button" @click="openMemberSelect">
              {{ hasMember ? '更换会员' : '选择会员' }}
            </button>
          </div>

          <div class="member-stats">
            <div class="stat-row-3">
              <div class="stat-cell">
                <span class="stat-label">账户余额</span>
                <b class="stat-value">{{ fmtMoney(summaryField('now_money')) }}</b>
              </div>
              <div class="stat-cell">
                <span class="stat-label">本金</span>
                <b class="stat-value">{{ fmtMoney(summaryField('ben_money')) }}</b>
              </div>
              <div class="stat-cell">
                <span class="stat-label">赠金</span>
                <b class="stat-value">{{ fmtMoney(summaryField('give_money')) }}</b>
              </div>
            </div>
            <div class="stat-row-3">
              <div class="stat-cell">
                <span class="stat-label">次卡权益</span>
                <b class="stat-value">{{ fmtMoney(summaryField('times_card_balance')) }}</b>
              </div>
              <div class="stat-cell">
                <span class="stat-label">余次</span>
                <b class="stat-value-sm">{{ fmtTimes(summaryField('remaining_times')) }}</b>
              </div>
              <div class="stat-cell">
                <span class="stat-label">有效卡</span>
                <b class="stat-value-sm">{{ fmtCount(summaryField('valid_card_count')) }}</b>
              </div>
            </div>
            <div class="stat-total" title="账户余额 + 次卡权益">
              <span>总可用余额（账户＋次卡）</span>
              <strong>{{ fmtMoney(summaryField('total_available_balance')) }}</strong>
            </div>
          </div>
        </section>

        <div v-if="hasMember" class="filter-chips">
          <button
            v-for="item in cardFilters"
            :key="item.value"
            type="button"
            :class="{ active: cardFilter === item.value }"
            @click="cardFilter = item.value"
          >{{ item.label }}</button>
        </div>

        <div v-if="hasMember" class="card-search">
          <Input
            v-model="cardKeyword"
            clearable
            prefix="ios-search"
            placeholder="搜索卡名称、卡号或项目名称"
          />
        </div>

        <div class="card-list">
          <template v-if="hasMember">
            <article
              v-for="card in filteredCards"
              :key="card.holder_id"
              :class="['card-item', { active: activeCardId === card.holder_id, selected: selectedCount(card) > 0 }]"
              @click="focusCard(card)"
            >
              <div class="card-line">
                <div class="card-icon"><Icon type="ios-card" /></div>
                <div class="card-name">
                  <strong>{{ card.card_name }}</strong>
                  <span>{{ card.card_no ? ('卡号 ' + card.card_no) : '暂无卡号' }}</span>
                </div>
                <div v-if="selectedCount(card)" class="selected-count">已选 {{ selectedCount(card) }}</div>
              </div>
              <div class="card-stats">
                <span>可用项目 <b>{{ availableProjectCount(card) }}</b></span>
                <span>剩余次数 <b>{{ remainingTimes(card) }}</b></span>
                <span :class="{ warning: card.expiring }">{{ card.expiryText }}</span>
              </div>
            </article>
            <div v-if="!filteredCards.length" class="empty-state">
              <Icon type="ios-folder-open-outline" />
              <p>{{ cardListEmptyText }}</p>
            </div>
          </template>
          <div v-else class="empty-state empty-state--member">
            <Icon type="ios-person-outline" />
            <p>未选择会员</p>
            <button type="button" class="select-member-btn select-member-btn--light" @click="openMemberSelect">选择会员</button>
          </div>
        </div>
      </aside>

      <section class="project-panel">
        <div class="project-toolbar">
          <div class="toolbar-title-row">
            <h1>选择本次要核销的项目</h1>
            <span class="toolbar-dot" aria-hidden="true">·</span>
            <p>{{ projectPanelHint }}</p>
          </div>
          <div class="toolbar-actions">
            <button
              v-if="hasMember"
              type="button"
              class="outline-btn clear-selected-btn"
              :disabled="!selectedProjectCount"
              @click="clearBatchSelection"
            >清空已选</button>
            <button
              v-if="hasMember"
              type="button"
              :class="['filter-selected-btn', { active: onlySelected }]"
              @click="onlySelected = !onlySelected"
            >
              <Icon type="md-funnel" /> 只看已选（{{ selectedProjectCount }}）
            </button>
          </div>
        </div>

        <div v-if="!hasMember" class="large-empty">
          <Icon type="ios-person-outline" />
          <h3>未选择会员</h3>
          <p>请在左侧会员卡点击「选择会员」，选择后将在本页加载卡项</p>
          <button type="button" class="select-member-btn" @click="openMemberSelect">选择会员</button>
        </div>

        <div v-else class="project-groups">
          <section
            v-for="card in visibleProjectCards"
            :key="card.holder_id"
            :class="['project-group', {
              focused: activeCardId === card.holder_id,
              selected: selectedCount(card) > 0,
            }]"
          >
            <header class="project-group-head">
              <div class="group-card-title">
                <span class="mini-card"><Icon type="ios-card" /></span>
                <div class="group-card-meta">
                  <strong class="group-card-name" :title="card.card_name">{{ card.card_name }}</strong>
                  <span class="group-card-sep" aria-hidden="true">·</span>
                  <span class="group-card-no">{{ card.card_no ? ('卡号 ' + card.card_no) : '暂无卡号' }}</span>
                </div>
              </div>
              <div class="group-summary-wrap">
                <span
                  v-if="pendingStaffCount(card) > 0"
                  :class="['group-pending', { error: staffSubmitAttempted }]"
                >{{ pendingStaffCount(card) }}项待分配</span>
                <div class="group-summary">已选 {{ selectedCount(card) }} 项 / {{ selectedTimes(card) }} 次</div>
              </div>
            </header>

            <div class="project-table-head">
              <span>项目</span><span>剩余/总次</span><span>本次次数</span><span>本次核销金额</span><span>服务对象</span><span>手艺人分配</span>
            </div>
            <div
              v-for="project in filteredProjects(card)"
              :key="project.cart_info_id"
              :data-cart-info-id="project.cart_info_id"
              :data-staff-missing="isStaffMissing(project) ? '1' : '0'"
              :class="projectRowClass(project)"
            >
              <div class="project-name-cell" @click="toggleProject(project)">
                <span
                  class="check-box"
                  :class="{ checked: project.selected }"
                  role="checkbox"
                  :aria-checked="project.selected ? 'true' : 'false'"
                >
                  <Icon v-if="project.selected" type="md-checkmark" />
                </span>
                <div class="project-name-text">
                  <strong>{{ project.product_name }}</strong>
                  <small>{{ project.source_type || ('权益#' + project.cart_info_id) }}</small>
                </div>
              </div>
              <div class="times-cell" @click.stop><b>{{ project.available_times }}</b> / {{ project.write_times }}</div>
              <div class="quantity-stepper" @click.stop>
                <button type="button" :disabled="!project.selected || project.qty <= 1" @click="changeQty(project, -1)">−</button>
                <span>{{ project.selected ? project.qty : 0 }}</span>
                <button type="button" :disabled="!project.selected || project.qty >= project.available_times" @click="changeQty(project, 1)">＋</button>
              </div>
              <div class="amount-cell" @click.stop>
                <b>¥{{ project.selected ? displayAmount(project) : 0 }}</b>
              </div>
              <div class="service-object" @click.stop>
                <button type="button" :class="{ active: project.serviceObject === '本人' }" @click="project.serviceObject = '本人'">本人</button>
                <button type="button" :class="{ active: project.serviceObject === '朋友' }" @click="project.serviceObject = '朋友'">朋友</button>
              </div>
              <button
                type="button"
                :class="staffEntryClass(project)"
                :title="staffEntryTitle(project)"
                @click.stop="openStaffAllocation(card, project)"
              >
                <Icon type="ios-people-outline" />
                <span>{{ staffEntryLabel(project) }}</span>
              </button>
            </div>
          </section>
          <div v-if="!visibleProjectCards.length" class="large-empty">
            <Icon type="ios-folder-open-outline" />
            <h3>暂无项目</h3>
            <p>{{ onlySelected ? '当前没有已选项目，可关闭“只看已选”' : '暂无可核销项目' }}</p>
          </div>
        </div>

        <footer v-if="hasMember" class="batch-footer">
          <div class="summary-pills">
            <span><b>{{ selectedCardCount }}</b> 张卡</span>
            <span><b>{{ selectedProjectCount }}</b> 种项目</span>
            <span><b>{{ totalSelectedTimes }}</b> 次</span>
            <span class="amount">本次核销金额 <b>¥{{ footerAmount }}</b></span>
          </div>
          <div class="footer-actions">
            <div class="more-actions-wrap" @click.stop>
              <button type="button" class="more-actions-btn" @click="moreOpen = !moreOpen">
                <Icon type="ios-apps-outline" /> 更多操作
                <Tooltip
                  content="包含订单详情、核销记录、项目替换记录、预约、卡转让等功能"
                  placement="top"
                  transfer
                  :max-width="280"
                >
                  <span
                    class="more-info-icon"
                    role="img"
                    aria-label="更多操作说明"
                    @click.stop.prevent
                  >ⓘ</span>
                </Tooltip>
                <Icon :type="moreOpen ? 'ios-arrow-up' : 'ios-arrow-down'" />
              </button>
              <div v-if="moreOpen" class="more-actions-menu">
                <div class="menu-caption">更多操作</div>
                <button
                  v-for="action in legacyActions"
                  :key="action.key"
                  type="button"
                  :class="{ danger: action.key === 'cancel' }"
                  @click="handleLegacyAction(action)"
                >
                  <span class="menu-icon"><Icon :type="action.icon" /></span>
                  <span><strong>{{ action.label }}</strong><small>{{ action.tip }}</small></span>
                </button>
              </div>
            </div>
            <div :class="['makeup-control', { active: makeupEnabled }]">
              <label class="makeup-switch">
                <input v-model="makeupEnabled" type="checkbox" />
                <span class="switch-track"><i></i></span>
                <span><Icon type="ios-calendar-outline" /> 补单日期</span>
              </label>
              <input v-if="makeupEnabled" v-model="makeupDate" class="makeup-date-input" type="datetime-local" />
              <span v-else class="makeup-placeholder">未启用</span>
            </div>
            <button
              type="button"
              class="primary-btn"
              :disabled="!hasMember || !selectedProjectCount || submitting"
              @click="openBatchConfirm"
            >
              {{ submitting ? '提交中…' : '核对并提交' }} <Icon type="ios-arrow-forward" />
            </button>
          </div>
        </footer>
      </section>
    </main>

    <main v-else class="replacement-page">
      <div class="replacement-layout">
        <section class="replacement-workspace" ref="replaceWorkspace">
          <div
            class="step-block"
            :class="{
              'step-warn': replaceAttempted && !replacementCardId,
              'step-done': !!replacementCardId,
            }"
            data-replace-step="1"
          >
            <div class="step-title">
              <span>1</span>
              <h2>选择一张卡</h2>
              <p>项目替换不能跨卡</p>
            </div>
            <div v-if="replaceAttempted && !replacementCardId" class="step-tip warn">请先选择一张卡</div>
            <div class="replace-card-list">
              <button
                v-for="card in cards"
                :key="'rpl-' + card.holder_id"
                type="button"
                :class="{ active: replacementCardId === card.holder_id }"
                @click="chooseReplacementCard(card.holder_id)"
              >
                <span class="replace-card-icon"><Icon type="ios-card" /></span>
                <Tooltip
                  :content="card.card_name || '未命名卡项'"
                  placement="top"
                  transfer
                  :max-width="360"
                  class="replace-card-name-tip"
                >
                  <strong class="replace-card-name">{{ card.card_name || '未命名卡项' }}</strong>
                </Tooltip>
                <span class="replace-card-tail">
                  <small class="replace-card-no">{{ card.card_no || card.verify_code || '—' }}</small>
                  <span
                    v-if="replacementCardId === card.holder_id"
                    class="replace-check"
                  ><Icon type="md-checkmark" /></span>
                </span>
              </button>
            </div>
          </div>

          <div
            class="step-block"
            :class="{
              'step-warn': replaceAttempted && replacementCardId && !replacementSourceLines.length,
              'step-done': replacementSourceLines.length > 0,
            }"
            data-replace-step="2"
          >
            <div class="step-title">
              <span>2</span>
              <h2>添加来源项目</h2>
              <p>同一项目可重复添加，默认1次</p>
              <b v-if="replacementSourceLines.length">{{ replacementSourceLines.length }} 条</b>
            </div>
            <div v-if="replaceAttempted && replacementCardId && !replacementSourceLines.length" class="step-tip warn">请添加至少一个来源项目</div>
            <div v-if="replaceLoading" class="flow-placeholder">正在加载可替换项目…</div>
            <template v-else>
              <div class="source-project-grid">
                <button
                  v-for="project in replaceSources"
                  :key="'src-add-' + project.cart_info_id"
                  type="button"
                  class="source-add-card"
                  :class="{ added: sourceAddedTimes(project) > 0 }"
                  :disabled="!canAddReplacementSource(project)"
                  @click="addReplacementSource(project)"
                >
                  <strong>{{ project.name }}</strong>
                  <small class="source-surplus-line">
                    <span class="surplus-label">剩余次数</span>
                    <b class="surplus-num">{{ project.write_surplus_times }}次</b>
                    <span class="surplus-sep">·</span>
                    <span class="surplus-amount">单次核销 ¥{{ project.preview_amount || 0 }}</span>
                  </small>
                  <em v-if="sourceAddedTimes(project) > 0">已添加 {{ sourceAddedTimes(project) }} 次</em>
                  <em v-else class="add-once">＋添加一次</em>
                </button>
                <div v-if="!replaceSources.length" class="flow-placeholder" style="grid-column: 1 / -1;">该卡暂无可替换项目</div>
              </div>
              <div v-if="replacementSourceLines.length" class="source-line-list">
                <div class="source-line-head">
                  <span>已选 {{ replacementSourceLines.length }} 条、合计扣减 {{ replacementTotalTimes }} 次、约 ¥{{ replacementLocalAmount }}</span>
                </div>
                <div class="source-line-table-head">
                  <span>序号</span><span>项目名称 / 权益号</span><span>本次次数</span><span>扣减金额</span><span>删除</span>
                </div>
                <div
                  v-for="(line, idx) in replacementSourceLines"
                  :key="line.key"
                  :data-source-key="line.key"
                  :class="['source-line-row', { error: isReplacementSourceLineError(line) }]"
                >
                  <span class="source-line-index">{{ idx + 1 }}</span>
                  <div class="source-line-meta">
                    <strong>{{ line.name }}</strong>
                    <small>权益 #{{ line.cart_info_id }}</small>
                  </div>
                  <div class="quantity-stepper">
                    <button type="button" :disabled="line.times <= 1" @click="changeReplacementSourceTimes(line, -1)">−</button>
                    <span>{{ line.times }}</span>
                    <button type="button" :disabled="line.times >= maxTimesForSourceLine(line)" @click="changeReplacementSourceTimes(line, 1)">＋</button>
                  </div>
                  <div class="source-line-amount">¥{{ Number(line.unit_amount || 0) * Number(line.times || 0) }}</div>
                  <button
                    type="button"
                    class="source-line-remove"
                    title="删除这条已选来源"
                    @click="removeReplacementSource(line.key)"
                  >
                    <Icon type="ios-trash-outline" /> 删除
                  </button>
                </div>
              </div>
              <div v-else class="flow-placeholder">请点击上方项目添加来源；同一项目可点多次</div>
            </template>
          </div>

          <div
            class="step-block"
            :class="{
              'step-warn': replaceAttempted && replacementSourceLines.length > 0 && !replacementTargetId,
              'step-done': !!replacementTargetId,
            }"
            data-replace-step="3"
          >
            <div class="step-title">
              <span>3</span>
              <h2>选择新项目</h2>
              <p>仅显示当前门店已上架项目</p>
            </div>
            <div v-if="replaceAttempted && replacementSourceLines.length > 0 && !replacementTargetId" class="step-tip warn">请选择一个新项目</div>
            <div class="target-search">
              <Icon type="ios-search" />
              <input v-model.trim="targetKeyword" type="search" placeholder="搜索新项目名称" />
            </div>
            <div class="target-grid">
              <button
                v-for="target in filteredTargets"
                :key="'tgt-' + target.product_id"
                type="button"
                class="target-card"
                :class="{ active: replacementTargetId === target.product_id }"
                @click="onSelectReplacementTarget(target.product_id)"
              >
                <span class="target-card-body">
                  <strong>{{ target.name }}</strong>
                  <small v-if="target.category">{{ target.category }}</small>
                </span>
                <span v-if="replacementTargetId === target.product_id" class="replace-check"><Icon type="md-checkmark" /></span>
              </button>
              <div v-if="!filteredTargets.length" class="flow-placeholder" style="grid-column: 1 / -1;">
                {{ replaceTargets.length ? '没有匹配的新项目' : (targetEmptyTip || '当前没有可用的上架项目，请先上架项目后再进行替换') }}
              </div>
            </div>
          </div>
        </section>

        <aside class="replacement-summary">
          <div class="summary-scroll">
            <div class="summary-title">
              <div class="summary-title-main">
                <Icon type="md-swap" />
                <div>
                  <h2>替换结果预览</h2>
                  <p>金额以服务端核对为准</p>
                </div>
              </div>
            </div>

            <div class="flow-card">
              <div class="flow-label">扣减原项目</div>
              <div v-if="replacementSourceLines.length" class="flow-items">
                <div v-for="source in replacementSourceLines" :key="'flow-' + source.key" class="flow-source-row">
                  <span>{{ source.name }}</span>
                  <b>−{{ source.times }} 次</b>
                  <button
                    type="button"
                    class="flow-source-remove"
                    title="删除这条已选来源"
                    @click="removeReplacementSource(source.key)"
                  >删除</button>
                </div>
              </div>
              <div
                v-else
                class="flow-placeholder flow-guide"
                aria-hidden="false"
              >
                <template v-if="!replacementCardId">
                  请先在左侧<span class="step-ref">第1步</span>选择一张卡
                </template>
                <template v-else>
                  请在左侧<span class="step-ref">第2步</span>添加来源项目
                </template>
              </div>

              <div class="flow-arrow"><span></span><Icon type="md-arrow-down" /></div>

              <div class="flow-label">生成新项目</div>
              <div
                v-if="replacementTarget"
                class="new-project-preview"
              >
                <div>
                  <strong>{{ replacementTarget.name }}</strong>
                  <small>新增 1 次可用权益</small>
                </div>
                <b>¥{{ replacementLocalAmount }}</b>
              </div>
              <div
                v-else
                :class="[
                  'flow-placeholder',
                  'flow-guide',
                  { 'flow-placeholder-warn': replaceAttempted && !!replacementCardId && replacementSourceLines.length > 0 && !replacementTargetId },
                ]"
              >
                <template v-if="!replacementCardId">
                  请先在左侧<span class="step-ref">第1步</span>选择一张卡
                </template>
                <template v-else>
                  请在左侧<span class="step-ref">第3步</span>选择新项目
                </template>
              </div>
            </div>

            <div class="replacement-total">
              <span>新项目核销金额</span>
              <b>¥{{ replacementLocalAmount }}</b>
              <small>全部来源本次金额合计（共扣减 {{ replacementTotalTimes }} 次）</small>
            </div>

            <div class="no-order-note">
              <Icon type="ios-information-circle" />
              <div>
                <strong>本操作不是消费</strong>
                <p>不产生核销订单，不进入消耗统计；只产生 1 条项目替换变动记录。</p>
              </div>
            </div>

            <label class="remark-field">
              <span>替换备注（选填）</span>
              <textarea v-model="replacementRemark" maxlength="100" placeholder="例如：顾客护理方案调整"></textarea>
            </label>

            <div class="mock-records">
              <div class="records-head">
                <button
                  type="button"
                  class="records-toggle"
                  @click="replaceRecordsExpanded = !replaceRecordsExpanded"
                >
                  <span>近期替换记录（{{ replacementRecords.length }}）</span>
                  <Icon :type="replaceRecordsExpanded ? 'ios-arrow-up' : 'ios-arrow-down'" />
                </button>
                <button type="button" class="records-view-all" @click="openMemberReplacementList">
                  查看全部
                </button>
              </div>
              <template v-if="replaceRecordsExpanded">
                <div v-if="!replacementRecords.length" class="flow-placeholder">暂无近期记录</div>
                <button
                  v-for="record in replacementRecords"
                  :key="record.id || record.replacement_no"
                  type="button"
                  class="record-item record-item-clickable"
                  @click="openReplacementDetail(record)"
                >
                  <div class="record-item-main">
                    <strong>{{ record.replacement_no || ('记录#' + (record.id || '')) }}</strong>
                    <small v-if="formatUnixTime(record.operate_time || record.business_time)">
                      {{ formatUnixTime(record.operate_time || record.business_time) }}
                    </small>
                    <em v-if="recordTargetName(record)">→ {{ recordTargetName(record) }}</em>
                  </div>
                  <div class="record-item-side">
                    <b>¥{{ record.target_amount || 0 }}</b>
                    <span>查看明细 <Icon type="ios-arrow-forward" /></span>
                  </div>
                </button>
              </template>
            </div>
          </div>

          <div class="replace-footer">
            <div class="replace-footer-summary">
              扣减{{ replacementTotalTimes }}次 · 生成{{ replacementTargetId ? 1 : 0 }}个新项目 · 金额¥{{ replacementLocalAmount }}
            </div>
            <button
              type="button"
              class="primary-btn replace-submit"
              :disabled="submitting"
              :class="{ 'is-disabled': !canSubmitReplacement }"
              @click="openReplacementConfirm"
            >
              {{ submitting ? '提交中…' : '确认项目替换' }}
            </button>
          </div>
        </aside>
      </div>
    </main>

    <Modal v-model="confirmVisible" width="860" :footer-hide="true" class-name="writeoff-confirm-modal">
      <div class="confirm-modal">
        <div class="confirm-header">
          <span class="confirm-icon"><Icon :type="confirmType === 'batch' ? 'md-checkmark' : 'md-swap'" /></span>
          <div>
            <h2>{{ confirmType === 'batch' ? '确认本次项目核销' : '确认项目替换' }}</h2>
            <p>{{ confirmType === 'batch' ? '请最后核对每张卡的扣减项目和次数' : '确认后按来源次数合计扣减，并生成 1 次新项目' }}</p>
          </div>
        </div>

        <template v-if="confirmType === 'batch'">
          <div class="order-result-banner">
            <div><span>前台业务单</span><strong>1 笔</strong></div>
            <Icon type="ios-arrow-forward" />
            <div><span>涉及卡项</span><strong>{{ previewSummary.total_cards || selectedCardCount }} 张</strong></div>
            <Icon type="ios-arrow-forward" />
            <div><span>项目明细</span><strong>{{ previewSummary.total_projects || selectedProjectCount }} 项</strong></div>
            <Icon type="ios-arrow-forward" />
            <div><span>核销金额</span><strong>¥{{ previewSummary.total_amount != null ? previewSummary.total_amount : totalSelectedAmount }}</strong></div>
          </div>
          <div v-if="makeupEnabled" class="makeup-confirm-note">
            <Icon type="ios-calendar-outline" /> 本单按补单处理，核销日期：<strong>{{ makeupDisplayText }}</strong>
          </div>
          <div class="confirm-groups">
            <div v-for="group in confirmGroups" :key="group.key" class="confirm-group">
              <div class="confirm-card-name"><strong>{{ group.card_name }}</strong><span>{{ group.sub }}</span></div>
              <div class="confirm-line confirm-line-head">
                <span>项目名称</span>
                <span>手艺人分配</span>
                <span>本次次数</span>
                <span>本次核销金额</span>
              </div>
              <div v-for="line in group.lines" :key="line.key" class="confirm-line">
                <span class="confirm-name">{{ line.name }}</span>
                <span class="confirm-staff" :title="line.staffLabel">{{ line.staffLabel }}</span>
                <em>{{ line.times }} 次</em>
                <b>¥{{ line.amount }}</b>
              </div>
            </div>
          </div>
        </template>
        <template v-else>
          <div class="replace-confirm-flow">
            <div
              v-for="(source, idx) in (replacePreview.sources || replacementSourceLines)"
              :key="'cf-' + idx + '-' + (source.cart_info_id || source.key || source.name)"
            >
              <small>扣减</small>
              <strong>{{ source.name }}</strong>
              <span>−{{ source.times != null ? source.times : 1 }} 次 / ¥{{ source.amount != null ? source.amount : 0 }}</span>
            </div>
            <Icon type="md-arrow-forward" />
            <div class="target">
              <small>新增</small>
              <strong>{{ (replacePreview.target && replacePreview.target.name) || (replacementTarget && replacementTarget.name) }}</strong>
              <span>1 次 / ¥{{ replacePreview.target_amount != null ? replacePreview.target_amount : replacementLocalAmount }}</span>
            </div>
          </div>
          <div class="replace-warning">
            <Icon type="ios-alert" />
            <span><strong>不会生成核销订单</strong>，仅保存 1 条项目替换变动记录；新项目实际核销时才进入消耗统计。</span>
          </div>
        </template>

        <div class="confirm-footer">
          <span><Icon type="ios-information-circle" /> 提交后以服务端结果为准</span>
          <div>
            <button type="button" class="reset-btn" :disabled="submitting" @click="confirmVisible = false">返回修改</button>
            <button type="button" class="primary-btn" :disabled="submitting" @click="confirmSubmit">
              {{ submitting ? '提交中…' : '确认提交' }}
            </button>
          </div>
        </div>
      </div>
    </Modal>

    <Modal v-model="instructionsVisible" width="680" :footer-hide="true" class-name="instructions-modal">
      <div class="instructions-panel">
        <div class="instructions-head">
          <span><Icon :type="mode === 'replace' ? 'md-swap' : 'ios-help-circle-outline'" /></span>
          <div>
            <small>功能操作说明</small>
            <h2>{{ mode === 'replace' ? '项目替换怎么用？' : '项目核销工作台怎么用？' }}</h2>
          </div>
        </div>
        <template v-if="mode === 'replace'">
          <div class="instruction-sections instruction-sections-col">
            <div class="instruction-block"><h3><b>1</b>同一张卡</h3><p>只能从同一张卡中选择来源项目，不能跨卡。</p></div>
            <div class="instruction-block"><h3><b>2</b>可重复添加</h3><p>同一个项目可以重复添加，每条默认 1 次，次数可以修改。</p></div>
            <div class="instruction-block"><h3><b>3</b>上架新项目</h3><p>新项目只能选择当前门店已经上架的项目。</p></div>
            <div class="instruction-block"><h3><b>4</b>扣减与生成</h3><p>原项目会按选择次数扣减，并形成新项目。</p></div>
            <div class="instruction-block"><h3><b>5</b>金额</h3><p>新项目核销金额等于来源项目本次核销金额合计。</p></div>
            <div class="instruction-block"><h3><b>6</b>不是消费</h3><p>项目替换不是消费，不产生核销订单，不进入消费统计。</p></div>
            <div class="instruction-block full"><h3><b>7</b>可追溯记录</h3><p>系统只生成一条可追溯的项目替换记录。</p></div>
          </div>
        </template>
        <template v-else>
          <div class="instruction-sections">
            <div class="instruction-block">
              <h3><b>1</b>项目核销</h3>
              <p>跨一张或多张卡选择项目，设置次数、服务对象和手艺人后统一提交。</p>
            </div>
            <div class="instruction-block">
              <h3><b>2</b>项目替换</h3>
              <p>选择原项目及次数，替换为新的上架项目；替换不生成核销订单。</p>
            </div>
            <div class="instruction-block">
              <h3><b>3</b>更多操作</h3>
              <p>包含订单详情、核销记录、项目替换记录、预约、卡转让等低频功能。项目替换记录不混入订单列表。</p>
            </div>
            <div class="instruction-block">
              <h3><b>4</b>补单日期</h3>
              <p>用于补录历史业务日期，实际操作时间仍记录当前时间。</p>
            </div>
          </div>
          <div class="plain-rule">
            <Icon type="ios-information-circle" />
            <span>金额按整数计算；例如 1000 元分 3 次，依次为 333、333、334 元。</span>
          </div>
        </template>
        <button type="button" class="primary-btn instruction-close" @click="instructionsVisible = false">我知道了</button>
      </div>
    </Modal>

    <Modal v-model="successVisible" width="460" :footer-hide="true" class-name="prototype-success-modal">
      <div class="success-card">
        <div class="success-icon"><Icon type="md-checkmark" /></div>
        <h2>{{ successTitle }}</h2>
        <p>{{ successText }}</p>
        <div class="fake-order-no">{{ successNo }}</div>
        <div class="success-actions">
          <button
            v-if="successBatchId > 0"
            type="button"
            class="ghost-btn"
            :disabled="cancellingSuccess"
            @click="cancelSuccessBatch"
          >{{ cancellingSuccess ? '撤销中...' : '撤销本次项目核销' }}</button>
          <button type="button" class="primary-btn" @click="onSuccessClose">完成</button>
        </div>
      </div>
    </Modal>

    <!-- 项目替换记录列表：仅聚合现有 per-card records 接口，不混入订单/核销列表 -->
    <Modal
      v-model="replacementListVisible"
      width="720"
      :footer-hide="true"
      class-name="replacement-records-modal"
    >
      <div class="rpl-list-panel">
        <div class="rpl-list-head">
          <span class="rpl-list-icon"><Icon type="md-swap" /></span>
          <div>
            <h2>项目替换记录</h2>
            <p>仅展示项目替换变动，不产生核销订单</p>
          </div>
        </div>
        <div v-if="replacementListLoading" class="flow-placeholder">正在加载替换记录…</div>
        <div v-else-if="!memberReplacementRecords.length" class="flow-placeholder">当前会员暂无项目替换记录</div>
        <div v-else class="rpl-list-body">
          <button
            v-for="record in memberReplacementRecords"
            :key="'all-' + (record.id || record.replacement_no)"
            type="button"
            class="rpl-list-row"
            @click="openReplacementDetail(record)"
          >
            <div class="rpl-list-row-main">
              <strong>{{ record.replacement_no || ('记录#' + (record.id || '')) }}</strong>
              <small>
                <template v-if="formatUnixTime(record.operate_time || record.business_time)">
                  {{ formatUnixTime(record.operate_time || record.business_time) }}
                </template>
                <template v-if="cardNameByHolder(record.holder_id)">
                  · {{ cardNameByHolder(record.holder_id) }}
                </template>
              </small>
              <em v-if="recordTargetName(record)">生成 {{ recordTargetName(record) }}</em>
            </div>
            <div class="rpl-list-row-side">
              <b>¥{{ record.target_amount || 0 }}</b>
              <span>查看明细 <Icon type="ios-arrow-forward" /></span>
            </div>
          </button>
        </div>
        <button type="button" class="ghost-btn rpl-list-close" @click="replacementListVisible = false">关闭</button>
      </div>
    </Modal>

    <Drawer
      v-model="replacementDetailVisible"
      title="项目替换明细"
      width="420"
      :mask-closable="true"
      class-name="replacement-detail-drawer"
    >
      <div v-if="replacementDetailRecord" class="rpl-detail">
        <section class="rpl-detail-section">
          <h3>基本信息</h3>
          <div class="rpl-detail-grid">
            <div v-if="replacementDetailRecord.replacement_no">
              <span>替换单号</span>
              <b>{{ replacementDetailRecord.replacement_no }}</b>
            </div>
            <div v-if="replacementDetailRecord.id">
              <span>记录编号</span>
              <b>#{{ replacementDetailRecord.id }}</b>
            </div>
            <div v-if="cardNameByHolder(replacementDetailRecord.holder_id)">
              <span>所属卡项</span>
              <b>{{ cardNameByHolder(replacementDetailRecord.holder_id) }}</b>
            </div>
            <div v-if="replacementDetailRecord.holder_id">
              <span>卡项 ID</span>
              <b>{{ replacementDetailRecord.holder_id }}</b>
            </div>
            <div class="rpl-detail-amount">
              <span>替换金额</span>
              <b>¥{{ replacementDetailRecord.target_amount || 0 }}</b>
            </div>
            <div v-if="recordTotalTimes(replacementDetailRecord)">
              <span>合计扣减次数</span>
              <b>{{ recordTotalTimes(replacementDetailRecord) }} 次</b>
            </div>
            <div v-if="formatUnixTime(replacementDetailRecord.business_time)">
              <span>业务时间</span>
              <b>{{ formatUnixTime(replacementDetailRecord.business_time) }}</b>
            </div>
            <div v-if="formatUnixTime(replacementDetailRecord.operate_time)">
              <span>操作时间</span>
              <b>{{ formatUnixTime(replacementDetailRecord.operate_time) }}</b>
            </div>
            <div>
              <span>状态</span>
              <b>{{ Number(replacementDetailRecord.status) === 0 ? '有效' : ('状态 ' + replacementDetailRecord.status) }}</b>
            </div>
          </div>
        </section>

        <section class="rpl-detail-section">
          <h3>扣减原项目</h3>
          <div v-if="!recordSources(replacementDetailRecord).length" class="flow-placeholder">暂无来源明细</div>
          <div
            v-for="(src, idx) in recordSources(replacementDetailRecord)"
            :key="'detail-src-' + idx + '-' + (src.cart_info_id || idx)"
            class="rpl-detail-source"
          >
            <div class="rpl-detail-source-top">
              <strong>{{ src.name || ('权益#' + (src.cart_info_id || '')) }}</strong>
              <b v-if="src.times != null">−{{ src.times }} 次</b>
            </div>
            <div class="rpl-detail-source-meta">
              <span v-if="src.amount != null">扣减金额 ¥{{ src.amount }}</span>
              <span v-if="src.cart_info_id">权益 #{{ src.cart_info_id }}</span>
              <span v-if="src.before_surplus != null || src.after_surplus != null">
                余次 {{ src.before_surplus != null ? src.before_surplus : '—' }}
                → {{ src.after_surplus != null ? src.after_surplus : '—' }}
              </span>
            </div>
          </div>
        </section>

        <section class="rpl-detail-section">
          <h3>生成新项目</h3>
          <div v-if="!recordTarget(replacementDetailRecord)" class="flow-placeholder">暂无目标项目明细</div>
          <div v-else class="rpl-detail-target">
            <div>
              <strong>{{ recordTarget(replacementDetailRecord).name || ('项目#' + (recordTarget(replacementDetailRecord).product_id || '')) }}</strong>
              <small v-if="recordTarget(replacementDetailRecord).product_id">
                项目 ID {{ recordTarget(replacementDetailRecord).product_id }}
              </small>
              <small v-if="recordTarget(replacementDetailRecord).cart_info_id">
                权益 #{{ recordTarget(replacementDetailRecord).cart_info_id }}
              </small>
            </div>
            <b>¥{{ recordTarget(replacementDetailRecord).amount != null
              ? recordTarget(replacementDetailRecord).amount
              : (replacementDetailRecord.target_amount || 0) }}</b>
          </div>
        </section>

        <section class="rpl-detail-section">
          <h3>操作信息</h3>
          <div class="rpl-detail-grid">
            <div v-if="recordRemark(replacementDetailRecord)">
              <span>替换备注</span>
              <b>{{ recordRemark(replacementDetailRecord) }}</b>
            </div>
            <div v-else>
              <span>替换备注</span>
              <b class="muted">未填写</b>
            </div>
            <div>
              <span>操作人</span>
              <b class="muted">接口未返回</b>
            </div>
            <div class="rpl-detail-note">
              本记录为项目替换变动，不产生核销订单，不进入普通订单 / 核销订单列表。
            </div>
          </div>
        </section>
      </div>
    </Drawer>

    <yeji
      ref="yeji"
      :visible="yejiVisible"
      :isShouyi="true"
      :showApplyAll="true"
      :syncProduct="yejiSyncList"
      :yeji="setYeji"
      :staffIds="staffIds"
      @doChoose="onYejiChoose"
      @applyAll="onYejiApplyAll"
      @closeYeji="closeYeji"
    />
    <memberSet ref="memberSet" hide-guest picker-title="选择会员" @submitSuccess="onMemberPicked" />
  </div>
</template>

<script>
import yeji from '@/components/yeji';
import memberSet from '@/pages/cashier/components/memberSet';
import {
  writeoffBatchOptions,
  writeoffBatchPreview,
  writeoffBatchCommit,
  writeoffBatchCancel,
  projectReplacementOptions,
  projectReplacementPreview,
  projectReplacementCommit,
  projectReplacementRecords,
  makeTerminalRequestToken,
} from '@/api/order';

export default {
  name: 'WriteoffWorkbench',
  components: { yeji, memberSet },
  props: {
    uid: {
      type: Number,
      default: 0,
    },
    member: {
      type: Object,
      default: null,
    },
  },
  data() {
    return {
      activeUid: 0,
      activeMember: null,
      mode: 'batch',
      loadingOptions: false,
      submitting: false,
      user: null,
      memberSummary: null,
      cards: [],
      cardFilter: 'available',
      cardKeyword: '',
      activeCardId: 0,
      onlySelected: false,
      staffSubmitAttempted: false,
      confirmVisible: false,
      confirmType: 'batch',
      successVisible: false,
      successTitle: '',
      successText: '',
      successNo: '',
      successBatchId: 0,
      cancellingSuccess: false,
      instructionsVisible: false,
      moreOpen: false,
      makeupEnabled: false,
      makeupDate: '',
      previewSummary: {},
      previewLines: [],
      pendingIdempotencyKey: '',
      replacementCardId: 0,
      replacementSourceLines: [],
      replacementSourceSeq: 0,
      replacementTargetId: 0,
      targetKeyword: '',
      targetEmptyTip: '',
      replacementRemark: '',
      replaceSources: [],
      replaceTargets: [],
      replaceLoading: false,
      replacePreview: {},
      replacementRecords: [],
      replaceAttempted: false,
      replaceRecordsExpanded: false,
      replacementListVisible: false,
      replacementListLoading: false,
      memberReplacementRecords: [],
      replacementDetailVisible: false,
      replacementDetailRecord: null,
      yejiVisible: false,
      yejiCard: null,
      yejiProject: null,
      staffIds: [],
      setYeji: {
        link_id: 0,
        cart_id: 0,
        price: 0,
        once_price: 0,
        goods_id: 0,
        type: 3,
        value: 1,
        staffChoose: [],
      },
      legacyActions: [
        { key: 'order_detail', label: '订单详情', tip: '查看当前选中卡的订单信息', icon: 'ios-document-outline' },
        { key: 'writeoff_record', label: '核销记录', tip: '查看该订单历史核销记录', icon: 'ios-list' },
        { key: 'replacement_record', label: '项目替换记录', tip: '查看当前会员的项目替换变动（非订单）', icon: 'md-swap' },
        { key: 'reservation', label: '预约', tip: '为顾客安排下次到店时间', icon: 'ios-calendar-outline' },
        { key: 'transfer', label: '卡转让', tip: '将当前卡项转让给其他会员', icon: 'ios-swap' },
        { key: 'extend', label: '卡延期', tip: '调整当前卡项的有效期', icon: 'ios-time-outline' },
        { key: 'remark', label: '订单备注', tip: '补充本次核销说明', icon: 'ios-create-outline' },
        { key: 'print', label: '小票打印', tip: '打印当前核销业务小票', icon: 'ios-print-outline' },
      ],
      cardFilters: [
        { label: '有效卡', value: 'available' },
        { label: '即将到期', value: 'expiring' },
        { label: '已选卡', value: 'selected' },
        { label: '全部', value: 'all' },
      ],
    };
  },
  computed: {
    hasMember() {
      return Number(this.activeUid) > 0;
    },
    memberInfo() {
      return this.activeMember || this.member || this.user || {};
    },
    memberName() {
      const u = this.memberInfo;
      return u.real_name || u.nickname || '会员';
    },
    memberPhone() {
      const phone = String(
        (this.memberSummary && this.memberSummary.phone_masked)
          || this.memberInfo.phone
          || ''
      );
      if (phone.indexOf('*') >= 0) return phone;
      if (phone.length >= 7) {
        return `${phone.slice(0, 3)}****${phone.slice(-4)}`;
      }
      return phone || '—';
    },
    displayPhone() {
      return this.memberPhone;
    },
    memberAvatarText() {
      return String(this.memberName).slice(0, 1) || '会';
    },
    memberLabel() {
      if (!this.hasMember) return '未选择会员';
      return `${this.memberName} · ${this.memberPhone}`;
    },
    projectPanelHint() {
      return '可跨多张卡选择项目，一次核对并提交';
    },
    filteredCards() {
      const kw = String(this.cardKeyword || '').trim().toLowerCase();
      return this.cards.filter((card) => {
        if (this.cardFilter === 'available' && this.remainingTimes(card) <= 0) return false;
        if (this.cardFilter === 'expiring' && !card.expiring) return false;
        if (this.cardFilter === 'selected' && this.selectedCount(card) <= 0) return false;
        if (!kw) return true;
        // 卡名/卡号命中：整卡保留；仅项目命中：仍显示该卡（右侧按项目再滤）
        return this.cardMatchesKeyword(card, kw) || this.filteredProjects(card).length > 0;
      });
    },
    cardListEmptyText() {
      if (!this.cards.length) return '该会员暂无可核销卡项';
      if (String(this.cardKeyword || '').trim()) return '没有匹配的卡项';
      return '当前筛选下没有卡项';
    },
    visibleProjectCards() {
      return this.filteredCards.filter((card) => this.filteredProjects(card).length > 0);
    },
    selectedCards() {
      return this.cards.filter((card) => this.selectedCount(card) > 0);
    },
    selectedCardCount() {
      return this.selectedCards.length;
    },
    selectedProjectCount() {
      return this.cards.reduce((sum, card) => sum + this.selectedCount(card), 0);
    },
    totalSelectedTimes() {
      return this.cards.reduce((sum, card) => sum + this.selectedTimes(card), 0);
    },
    totalSelectedAmount() {
      return this.cards.reduce((sum, card) => sum + card.projects.reduce((amount, project) => {
        return amount + (project.selected ? this.allocatedAmount(project, project.qty) : 0);
      }, 0), 0);
    },
    footerAmount() {
      if (this.previewSummary && this.previewSummary.total_amount != null && this.selectedProjectCount) {
        return this.previewSummary.total_amount;
      }
      return this.totalSelectedAmount;
    },
    makeupDisplayText() {
      return this.formatMakeupDate(this.makeupDate) || '尚未选择，请返回设置';
    },
    confirmGroups() {
      if (this.previewLines && this.previewLines.length) {
        const map = {};
        this.previewLines.forEach((line) => {
          const key = String(line.holder_id || line.oid || line.card_name);
          if (!map[key]) {
            map[key] = {
              key,
              card_name: line.card_name || '卡项',
              sub: line.verify_code || '',
              lines: [],
            };
          }
          map[key].lines.push({
            key: `${line.cart_info_id}-${line.product_name}`,
            name: line.product_name,
            times: line.cart_num,
            staffLabel: this.staffLabelForCart(line.cart_info_id),
            amount: line.writeoff_amount,
          });
        });
        return Object.values(map);
      }
      return this.selectedCards.map((card) => ({
        key: card.holder_id,
        card_name: card.card_name,
        sub: card.verify_code,
        lines: card.projects.filter((p) => p.selected).map((p) => ({
          key: p.cart_info_id,
          name: p.product_name,
          times: p.qty,
          staffLabel: this.formatStaffLabels(p.staffChoose),
          amount: this.allocatedAmount(p, p.qty),
        })),
      }));
    },
    filteredTargets() {
      const keyword = this.targetKeyword.toLowerCase();
      return (this.replaceTargets || []).filter((target) => {
        return !keyword || `${target.name} ${target.category || ''}`.toLowerCase().includes(keyword);
      });
    },
    replacementTarget() {
      return (this.replaceTargets || []).find((item) => item.product_id === this.replacementTargetId) || null;
    },
    replacementTotalTimes() {
      return this.replacementSourceLines.reduce((sum, line) => sum + Number(line.times || 0), 0);
    },
    replacementLocalAmount() {
      if (this.replacePreview && this.replacePreview.target_amount != null) {
        return this.replacePreview.target_amount;
      }
      return this.replacementSourceLines.reduce((sum, line) => {
        const unit = Number(line.unit_amount || 0);
        return sum + unit * Number(line.times || 0);
      }, 0);
    },
    canSubmitReplacement() {
      return this.replacementSourceLines.length > 0
        && this.replacementTotalTimes > 0
        && !!this.replacementTargetId
        && !!this.replacementCardId;
    },
    replacementSourcesPayload() {
      return this.replacementSourceLines.map((line) => ({
        cart_info_id: Number(line.cart_info_id),
        times: Number(line.times || 1),
      }));
    },
    yejiSyncList() {
      const list = [];
      this.cards.forEach((card) => {
        card.projects.forEach((project) => {
          if (!project.selected) return;
          list.push(this.buildSyncPayload(card, project));
        });
      });
      return list;
    },
  },
  watch: {
    uid: {
      immediate: true,
      handler(val) {
        const uid = Number(val || 0);
        this.activeUid = uid;
        if (uid > 0) {
          if (this.member) {
            this.activeMember = { ...this.member };
          }
          this.reloadOptions();
        } else {
          this.resetMemberState();
        }
      },
    },
    member: {
      deep: true,
      handler(val) {
        if (val && Number(val.uid) === Number(this.activeUid) && Number(this.activeUid) > 0) {
          this.activeMember = { ...val };
        }
      },
    },
  },
  methods: {
    deepClone(obj) {
      return JSON.parse(JSON.stringify(obj || null));
    },
    errMsg(err) {
      return (err && (err.msg || err.message)) || '操作失败，请稍后重试';
    },
    resetMemberState() {
      this.user = null;
      this.memberSummary = null;
      this.cards = [];
      this.activeCardId = 0;
      this.activeMember = null;
      this.cardKeyword = '';
      this.cardFilter = 'available';
      this.onlySelected = false;
      this.previewSummary = {};
      this.previewLines = [];
      this.replacementCardId = 0;
      this.replacementSourceLines = [];
      this.replacementTargetId = 0;
      this.replaceSources = [];
      this.replaceTargets = [];
      this.mode = 'batch';
    },
    summaryField(key) {
      if (!this.hasMember || !this.memberSummary || this.loadingOptions) {
        return null;
      }
      const val = this.memberSummary[key];
      return val === undefined || val === null ? null : val;
    },
    fmtMoney(val) {
      if (val === null || val === undefined || val === '') return '--';
      const n = Number(val);
      if (!Number.isFinite(n)) return '--';
      return `¥${Math.trunc(n).toLocaleString('zh-CN')}`;
    },
    fmtTimes(val) {
      if (val === null || val === undefined || val === '') return '--';
      const n = Number(val);
      if (!Number.isFinite(n)) return '--';
      return `${Math.trunc(n)}次`;
    },
    fmtCount(val) {
      if (val === null || val === undefined || val === '') return '--';
      const n = Number(val);
      if (!Number.isFinite(n)) return '--';
      return `${Math.trunc(n)}张`;
    },
    openMemberSelect() {
      const picker = this.$refs.memberSet;
      if (!picker) return;
      picker.modal4 = true;
      if (typeof picker.searchUser === 'function') {
        picker.searchUser();
      }
    },
    onMemberPicked(row) {
      if (!row || !row.uid) {
        this.$Message.warning('请先选择会员');
        return;
      }
      this.activeUid = Number(row.uid);
      this.activeMember = {
        uid: this.activeUid,
        phone: row.phone || '',
        nickname: row.nickname || '',
        real_name: row.real_name || '',
        avatar: row.avatar || '',
      };
      this.activeCardId = 0;
      this.clearBatchSelection();
      this.$emit('member-change', { ...this.activeMember });
      this.reloadOptions();
    },
    focusCard(card) {
      if (!card) return;
      this.activeCardId = card.holder_id;
      this.$emit('active-card', {
        oid: card.oid,
        order_id: card.order_id,
        holder_id: card.holder_id,
        uid: this.activeUid,
      });
    },
    switchMode(mode) {
      if (mode === 'replace' && !this.hasMember) {
        this.$Message.warning('请先选择会员');
        return;
      }
      this.mode = mode;
      this.moreOpen = false;
      this.replaceAttempted = false;
      if (mode === 'replace' && this.cards.length && !this.replacementCardId) {
        this.chooseReplacementCard(this.cards[0].holder_id);
      }
    },
    formatExpiry(writeEnd) {
      const end = Number(writeEnd || 0);
      if (!end) return { text: '永久有效', expiring: false };
      const now = Math.floor(Date.now() / 1000);
      const date = new Date(end * 1000);
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      const expiring = end > now && (end - now) <= 30 * 24 * 3600;
      return { text: `${y}-${m}-${d} 到期`, expiring: expiring || end <= now };
    },
    formatMakeupDate(val) {
      if (!val) return '';
      const normalized = String(val).replace('T', ' ');
      if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(normalized)) {
        return `${normalized}:00`;
      }
      return normalized;
    },
    /** 项目核销仅允许商品类型=项目(6)；产品(0)等不得展示/选择/提交 */
    isWriteoffProjectType(raw) {
      if (!raw || raw.product_type === undefined || raw.product_type === null || raw.product_type === '') {
        return true;
      }
      return Number(raw.product_type) === 6;
    },
    mapProject(raw, card) {
      const remaining = Math.max(0, Number(raw.write_surplus_times || 0));
      const available = Math.max(0, Number(raw.available_times != null ? raw.available_times : remaining));
      const totalTimes = Math.max(0, Number(raw.write_times || 0));
      const usedTimes = Math.max(0, totalTimes - remaining);
      const totalAmount = Number(raw.pay_price || 0);
      const name = raw.product_name || raw.name || '';
      return {
        cart_info_id: Number(raw.cart_info_id),
        cart_id: String(raw.cart_id || ''),
        product_id: Number(raw.product_id || 0),
        product_type: raw.product_type === undefined || raw.product_type === null
          ? 6
          : Number(raw.product_type),
        oid: Number((card && card.oid) || raw.oid || 0),
        name,
        product_name: name,
        sku: raw.sku || raw.source_type || '',
        remaining,
        available_times: available,
        totalTimes,
        usedTimes,
        totalAmount,
        pay_price: totalAmount,
        write_times: totalTimes,
        write_surplus_times: remaining,
        already_times: usedTimes,
        rights_version: raw.rights_version || '',
        source_type: raw.source_type || '',
        next_writeoff_amount: raw.next_writeoff_amount,
        // 耗卡业绩单价（与后端 YejiCommission / 快照口径一致），用于手艺人业绩分摊
        unit_performance: Number(raw.unit_performance != null ? raw.unit_performance : 0),
        // 赠送项目豁免手艺人必填（与后端 BatchWriteoff 口径一致）
        is_gift: Number(raw.is_gift || 0) === 1 ? 1 : 0,
        selected: false,
        qty: available > 0 ? 1 : 0,
        serviceObject: '本人',
        staffChoose: [],
        staffIds: [],
        preview_amount: null,
      };
    },
    /** 本行耗卡业绩合计（commission 分摊基数）；不得使用核销金额 */
    projectPerformanceLineAmount(project) {
      const qty = Math.max(0, Number(project && project.qty || 0));
      const unit = Number(project && project.unit_performance || 0);
      return Math.round((unit * qty + Number.EPSILON) * 100) / 100;
    },
    mapCard(raw) {
      const expiry = this.formatExpiry(raw.write_end);
      const card = {
        id: Number(raw.holder_id),
        holder_id: Number(raw.holder_id),
        name: raw.card_name || '',
        card_name: raw.card_name || '',
        no: raw.card_no || '',
        card_no: raw.card_no || '',
        verify_code: raw.verify_code || '',
        oid: Number(raw.oid),
        store: '',
        order_id: raw.order_id || '',
        write_end: raw.write_end,
        expiry: expiry.text,
        expiryText: expiry.text,
        expiring: expiry.expiring,
        projects: [],
      };
      card.projects = (raw.projects || [])
        .filter((p) => this.isWriteoffProjectType(p))
        .map((p) => this.mapProject(p, card));
      return card;
    },
    maxQty(project) {
      return Math.max(0, Number(project.available_times != null ? project.available_times : project.remaining || 0));
    },
    async reloadOptions() {
      const uid = Number(this.activeUid || this.uid || 0);
      if (!uid) {
        this.memberSummary = null;
        return;
      }
      // 先清空汇总，避免短暂展示上一位会员余额
      this.memberSummary = null;
      this.loadingOptions = true;
      try {
        const res = await writeoffBatchOptions({
          uid,
          keyword: '',
        });
        const data = (res && res.data) || {};
        this.user = data.user || null;
        this.memberSummary = data.member_summary || null;
        if (this.memberSummary && this.memberSummary.balance_mismatch) {
          // 仅本地记录；不修正账户数据
          // eslint-disable-next-line no-console
          console.warn('[writeoff] member balance mismatch', this.memberSummary.balance_mismatch_detail);
        }
        if (this.user) {
          this.activeMember = {
            ...(this.activeMember || {}),
            uid,
            phone: this.user.phone || (this.activeMember && this.activeMember.phone) || '',
            nickname: this.user.nickname || (this.activeMember && this.activeMember.nickname) || '',
            real_name: this.user.real_name || (this.activeMember && this.activeMember.real_name) || '',
            avatar: this.user.avatar || (this.activeMember && this.activeMember.avatar) || '',
          };
        }
        this.cards = (data.cards || []).map((card) => this.mapCard(card));
        // 不自动选中卡项；用户点击卡后才聚焦
        if (this.activeCardId && !this.cards.some((c) => c.holder_id === this.activeCardId)) {
          this.activeCardId = 0;
        }
        if (this.mode === 'replace') {
          const keepId = this.replacementCardId && this.cards.some((c) => c.holder_id === this.replacementCardId)
            ? this.replacementCardId
            : 0;
          if (keepId) {
            await this.chooseReplacementCard(keepId);
          }
        }
        this.previewSummary = {};
        this.previewLines = [];
      } catch (err) {
        this.$Message.error(this.errMsg(err));
        this.cards = [];
        this.memberSummary = null;
      } finally {
        this.loadingOptions = false;
      }
    },
    availableProjectCount(card) {
      return (card.projects || []).filter((item) => item.available_times > 0).length;
    },
    remainingTimes(card) {
      return (card.projects || []).reduce((sum, item) => sum + Number(item.available_times || 0), 0);
    },
    selectedCount(card) {
      return (card.projects || []).filter((item) => item.selected).length;
    },
    selectedTimes(card) {
      return (card.projects || []).reduce((sum, item) => sum + (item.selected ? Number(item.qty || 0) : 0), 0);
    },
    cardMatchesKeyword(card, kw) {
      const key = String(kw || '').trim().toLowerCase();
      if (!key) return true;
      const name = String(card.card_name || card.name || '').toLowerCase();
      const no = String(card.card_no || card.no || '').toLowerCase();
      return name.indexOf(key) >= 0 || no.indexOf(key) >= 0;
    },
    projectMatchesKeyword(project, kw) {
      const key = String(kw || '').trim().toLowerCase();
      if (!key) return true;
      return String(project.product_name || '').toLowerCase().indexOf(key) >= 0;
    },
    filteredProjects(card) {
      const kw = String(this.cardKeyword || '').trim().toLowerCase();
      const cardHit = !kw || this.cardMatchesKeyword(card, kw);
      return (card.projects || []).filter((project) => {
        if (this.onlySelected && !project.selected) return false;
        if (!kw) return true;
        // 卡名/卡号命中：展示该卡全部项目；否则只展示项目名命中的行
        return cardHit || this.projectMatchesKeyword(project, kw);
      });
    },
    formatStaffLabels(staffChoose) {
      if (!staffChoose || !staffChoose.length) return '未分配';
      return staffChoose.map((s) => {
        const name = s.staff_name || s.name || '手艺人';
        const tag = Number(s.is_dian) === 1 ? '点' : '轮';
        return `${name}(${tag})`;
      }).join('、');
    },
    staffLabelForCart(cartInfoId) {
      const id = Number(cartInfoId);
      for (let i = 0; i < this.cards.length; i += 1) {
        const projects = this.cards[i].projects || [];
        for (let j = 0; j < projects.length; j += 1) {
          if (Number(projects[j].cart_info_id) === id) {
            return this.formatStaffLabels(projects[j].staffChoose);
          }
        }
      }
      return '未分配';
    },
    staffEntryTitle(project) {
      if (!project || !project.selected) return '';
      if (!this.projectRequiresStaff(project)) return '赠送项目无需分配手艺人';
      if (this.isStaffMissing(project)) {
        return this.staffSubmitAttempted ? '请选择手艺人后再提交' : '待选择手艺人';
      }
      return this.formatStaffLabels(project.staffChoose);
    },
    projectRequiresStaff(project) {
      return Number(project && project.is_gift) !== 1;
    },
    hasStaffAssigned(project) {
      return !!(project && project.staffChoose && project.staffChoose.length);
    },
    isStaffMissing(project) {
      return !!(project && project.selected && this.projectRequiresStaff(project) && !this.hasStaffAssigned(project));
    },
    pendingStaffCount(card) {
      return (card.projects || []).filter((project) => this.isStaffMissing(project)).length;
    },
    projectRowClass(project) {
      return {
        'project-row': true,
        checked: !!project.selected,
        'staff-pending': this.isStaffMissing(project) && !this.staffSubmitAttempted,
        'staff-error': this.isStaffMissing(project) && this.staffSubmitAttempted,
      };
    },
    staffEntryClass(project) {
      return {
        'staff-entry': true,
        disabled: !project.selected,
        assigned: project.selected && this.hasStaffAssigned(project),
        pending: this.isStaffMissing(project) && !this.staffSubmitAttempted,
        error: this.isStaffMissing(project) && this.staffSubmitAttempted,
        exempt: project.selected && !this.projectRequiresStaff(project),
      };
    },
    refreshStaffSubmitFlag() {
      if (!this.staffSubmitAttempted) return;
      const stillMissing = this.cards.some((card) => this.pendingStaffCount(card) > 0);
      if (!stillMissing) this.staffSubmitAttempted = false;
    },
    scrollToFirstStaffError() {
      this.$nextTick(() => {
        const root = this.$el;
        if (!root || !root.querySelector) return;
        const el = root.querySelector('.project-row.staff-error')
          || root.querySelector('.project-row[data-staff-missing="1"]');
        if (el && typeof el.scrollIntoView === 'function') {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
    },
    toggleProject(project) {
      if (!this.isWriteoffProjectType(project)) {
        this.$Message.warning('产品类型不能在项目核销中核销');
        return;
      }
      if (project.available_times <= 0) {
        this.$Message.warning('该项目暂无可用次数');
        return;
      }
      project.selected = !project.selected;
      if (project.selected) {
        if (!project.qty || project.qty > project.available_times) project.qty = 1;
      } else {
        project.staffChoose = [];
        project.preview_amount = null;
        this.refreshStaffSubmitFlag();
      }
      this.previewSummary = {};
      this.previewLines = [];
    },
    changeQty(project, delta) {
      project.qty = Math.max(1, Math.min(project.available_times, Number(project.qty || 1) + delta));
      project.preview_amount = null;
      if (project.staffChoose && project.staffChoose.length) {
        project.staffChoose = this.buildEqualStaffAllocation(
          project.staffChoose,
          this.projectPerformanceLineAmount(project)
        );
      }
      this.previewSummary = {};
      this.previewLines = [];
    },
    allocatedAmount(project, quantity) {
      return this.allocateInteger(
        project.pay_price,
        project.write_times,
        project.already_times,
        quantity,
      );
    },
    allocateInteger(totalAmount, totalTimes, alreadyTimes, currentTimes) {
      const total = Math.max(0, Math.trunc(Number(totalAmount || 0)));
      const times = Math.max(0, Math.trunc(Number(totalTimes || 0)));
      const current = Math.max(0, Math.trunc(Number(currentTimes || 0)));
      if (times <= 0 || current <= 0) return 0;
      const start = Math.min(Math.max(0, Math.trunc(Number(alreadyTimes || 0))), times);
      const end = Math.min(start + current, times);
      const allocatedTimes = Math.max(end - start, 0);
      if (!allocatedTimes) return 0;
      const base = Math.floor(total / times);
      let amount = base * allocatedTimes;
      if (end === times) amount += total % times;
      return amount;
    },
    displayAmount(project) {
      if (project.preview_amount != null) return project.preview_amount;
      return this.allocatedAmount(project, project.qty);
    },
    staffEntryLabel(project) {
      if (!project.selected) return '选择后分配';
      if (!this.projectRequiresStaff(project)) return '无需手艺人';
      if (!this.hasStaffAssigned(project)) {
        return this.staffSubmitAttempted ? '请选择手艺人' : '待选择手艺人';
      }
      return this.formatStaffLabels(project.staffChoose);
    },
    clearBatchSelection() {
      this.cards.forEach((card) => {
        card.projects.forEach((project) => {
          project.selected = false;
          project.qty = project.available_times > 0 ? 1 : 0;
          project.staffChoose = [];
          project.preview_amount = null;
        });
      });
      this.staffSubmitAttempted = false;
      this.previewSummary = {};
      this.previewLines = [];
    },
    buildEqualStaffAllocation(staffChoose, price) {
      const count = (staffChoose || []).length;
      if (!count) return [];
      const totalCents = Math.max(0, Math.round(Number(price || 0) * 100));
      const averageCents = Math.floor(totalCents / count);
      let remainderCents = totalCents - averageCents * count;
      return staffChoose.map((staff) => {
        const allocationCents = averageCents + (remainderCents-- > 0 ? 1 : 0);
        return {
          ...this.deepClone(staff),
          yeji: allocationCents / 100,
        };
      });
    },
    buildSyncPayload(card, project) {
      // yeji 弹窗与提交校验均以耗卡业绩为基数；核销金额另走 preview/commit 字段
      const perfLine = this.projectPerformanceLineAmount(project);
      const qty = Number(project.qty || 0);
      const once = qty > 0
        ? Math.round((Number(project.unit_performance || 0) + Number.EPSILON) * 100) / 100
        : 0;
      const staffChoose = this.buildEqualStaffAllocation(project.staffChoose || [], perfLine);
      return {
        goods_id: Number(project.product_id || 0),
        price: perfLine,
        once_price: once,
        true_price: Number(project.pay_price || 0),
        write_times: Number(project.write_times || 0),
        value: qty,
        link_id: 0,
        cart_id: String(project.cart_id),
        order_id: Number(card.oid || 0),
        type: 3,
        staffChoose,
      };
    },
    buildItemsPayload() {
      const items = [];
      this.cards.forEach((card) => {
        card.projects.forEach((project) => {
          if (!project.selected) return;
          if (!this.isWriteoffProjectType(project)) return;
          items.push({
            holder_id: card.holder_id,
            oid: card.oid,
            cart_info_id: project.cart_info_id,
            cart_num: Number(project.qty || 0),
            service_object: project.serviceObject === '朋友' ? '朋友' : '本人',
            rights_version: project.rights_version || '',
            sync: this.buildSyncPayload(card, project),
          });
        });
      });
      return items;
    },
    openStaffAllocation(card, project) {
      if (!project.selected) {
        this.$Message.warning('请先选择本次要核销的项目');
        return;
      }
      this.yejiCard = card;
      this.yejiProject = project;
      const sync = this.buildSyncPayload(card, project);
      this.setYeji = this.deepClone(sync);
      this.staffIds = (project.staffChoose || []).map((item) => item.staff_id);
      const dianAttr = (project.staffChoose || [])
        .filter((item) => Number(item.is_dian) === 1)
        .map((item) => item.staff_id);
      this.yejiVisible = true;
      this.$nextTick(() => {
        if (this.$refs.yeji) {
          this.$refs.yeji.showAdd = true;
          this.$refs.yeji.dianAttr = dianAttr;
        }
      });
    },
    onYejiChoose(yejiData) {
      if (!this.yejiProject) return;
      const source = this.deepClone(yejiData || {});
      // 保留弹窗按耗卡业绩生成的分配；再按本行口径独立重摊一次，避免引用共用
      this.yejiProject.staffChoose = this.buildEqualStaffAllocation(
        source.staffChoose || [],
        this.projectPerformanceLineAmount(this.yejiProject)
      );
      this.refreshStaffSubmitFlag();
      this.closeYeji();
    },
    onYejiApplyAll(payload) {
      const staffChoose = this.deepClone((payload && payload.staffChoose) || []);
      if (!staffChoose.length) {
        this.$Message.warning('请先选择手艺人');
        return;
      }
      this.cards.forEach((card) => {
        card.projects.forEach((project) => {
          if (!project.selected) return;
          if (!this.projectRequiresStaff(project)) return;
          // 每行独立深拷贝，按目标行自身耗卡业绩分摊
          project.staffChoose = this.buildEqualStaffAllocation(
            staffChoose,
            this.projectPerformanceLineAmount(project)
          );
        });
      });
      this.refreshStaffSubmitFlag();
      this.$Message.success('已应用到全部已选项目');
      this.closeYeji();
    },
    closeYeji() {
      this.yejiVisible = false;
      this.yejiCard = null;
      this.yejiProject = null;
    },
    openInstructions() {
      this.instructionsVisible = true;
    },
    handleLegacyAction(action) {
      this.moreOpen = false;
      // 项目替换记录独立入口：不走订单/核销记录弹窗，也不混入订单列表
      if (action && action.key === 'replacement_record') {
        this.openMemberReplacementList();
        return;
      }
      const card = this.cards.find((c) => c.holder_id === this.activeCardId) || this.cards[0];
      this.$emit('legacy-action', {
        key: action.key,
        oid: card && card.oid,
        order_id: card && card.order_id,
        holder_id: card && card.holder_id,
        uid: this.activeUid,
      });
    },
    recordSnapshot(record) {
      if (!record || typeof record !== 'object') return {};
      const snap = record.snapshot;
      return snap && typeof snap === 'object' && !Array.isArray(snap) ? snap : {};
    },
    recordSources(record) {
      const sources = this.recordSnapshot(record).sources;
      return Array.isArray(sources) ? sources : [];
    },
    recordTarget(record) {
      const target = this.recordSnapshot(record).target;
      return target && typeof target === 'object' ? target : null;
    },
    recordTargetName(record) {
      const target = this.recordTarget(record);
      if (!target) return '';
      return target.name || '';
    },
    recordRemark(record) {
      const remark = this.recordSnapshot(record).remark;
      return remark != null && String(remark) !== '' ? String(remark) : '';
    },
    recordTotalTimes(record) {
      const snap = this.recordSnapshot(record);
      if (snap.total_source_times != null && snap.total_source_times !== '') {
        return Number(snap.total_source_times) || 0;
      }
      return this.recordSources(record).reduce((sum, row) => sum + Number(row.times || 0), 0);
    },
    formatUnixTime(ts) {
      const n = Number(ts || 0);
      if (!n) return '';
      const d = new Date(n * 1000);
      if (Number.isNaN(d.getTime())) return '';
      const pad = (v) => String(v).padStart(2, '0');
      return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    },
    cardNameByHolder(holderId) {
      const id = Number(holderId || 0);
      if (!id) return '';
      const card = (this.cards || []).find((c) => Number(c.holder_id) === id);
      return card ? (card.card_name || card.name || '') : '';
    },
    openReplacementDetail(record) {
      if (!record) return;
      this.replacementDetailRecord = record;
      this.replacementDetailVisible = true;
    },
    async openMemberReplacementList() {
      if (!this.hasMember) {
        this.$Message.warning('请先选择会员后再查看项目替换记录');
        return;
      }
      const holderIds = Array.from(new Set(
        (this.cards || [])
          .map((c) => Number(c.holder_id || 0))
          .filter((id) => id > 0)
      ));
      if (!holderIds.length) {
        this.memberReplacementRecords = [];
        this.replacementListVisible = true;
        return;
      }
      this.replacementListLoading = true;
      this.replacementListVisible = true;
      try {
        // 复用现有按卡 records 接口聚合当前会员全部卡，不新增接口
        const results = await Promise.all(
          holderIds.map((holderId) => projectReplacementRecords(holderId)
            .then((res) => ((res && res.data && res.data.list) || []))
            .catch(() => []))
        );
        const map = new Map();
        results.forEach((list) => {
          (list || []).forEach((row) => {
            if (!row) return;
            const key = row.id != null ? `id-${row.id}` : `no-${row.replacement_no || Math.random()}`;
            if (!map.has(key)) map.set(key, row);
          });
        });
        this.memberReplacementRecords = Array.from(map.values()).sort((a, b) => {
          const tb = Number(b.operate_time || b.business_time || b.id || 0);
          const ta = Number(a.operate_time || a.business_time || a.id || 0);
          return tb - ta;
        });
      } catch (err) {
        this.memberReplacementRecords = [];
        this.$Message.error(this.errMsg(err));
      } finally {
        this.replacementListLoading = false;
      }
    },
    validateStaffBeforeSubmit() {
      const missing = [];
      this.cards.forEach((card) => {
        (card.projects || []).forEach((project) => {
          if (!this.isStaffMissing(project)) return;
          missing.push({
            card,
            project,
            label: `【${card.card_name || card.name}】${project.product_name || project.name}`,
          });
        });
      });
      return missing;
    },
    async openBatchConfirm() {
      if (!this.hasMember) {
        this.$Message.warning('请先选择会员');
        return;
      }
      if (!this.selectedProjectCount) return;
      if (this.makeupEnabled && !this.makeupDate) {
        this.$Message.warning('请先选择补单日期');
        return;
      }
      const missing = this.validateStaffBeforeSubmit();
      if (missing.length) {
        this.staffSubmitAttempted = true;
        this.$Message.error(`还有${missing.length}个项目未分配手艺人，请完成后再提交`);
        this.scrollToFirstStaffError();
        return;
      }
      this.staffSubmitAttempted = false;
      this.submitting = true;
      try {
        const payload = {
          uid: Number(this.activeUid),
          items: this.buildItemsPayload(),
          is_budan: this.makeupEnabled ? 1 : 0,
          budan_time: this.makeupEnabled ? this.formatMakeupDate(this.makeupDate) : '',
          remark: '',
        };
        const res = await writeoffBatchPreview(payload);
        const data = (res && res.data) || {};
        this.previewSummary = {
          total_amount: data.total_amount,
          total_times: data.total_times,
          total_cards: data.total_cards,
          total_projects: data.total_projects,
        };
        this.previewLines = data.items || [];
        // 回写服务端试算金额到本地行
        const amountMap = {};
        (data.items || []).forEach((line) => {
          amountMap[Number(line.cart_info_id)] = line.writeoff_amount;
        });
        this.cards.forEach((card) => {
          card.projects.forEach((project) => {
            if (project.selected && amountMap[project.cart_info_id] != null) {
              project.preview_amount = amountMap[project.cart_info_id];
              // 不按核销金额重摊手艺人业绩：耗卡业绩校验口径与核销金额可能不一致，
              // 重摊到核销金额会导致「业绩合计不能超过项目耗卡业绩」误拦截。
            }
          });
        });
        this.pendingIdempotencyKey = makeTerminalRequestToken('bw');
        this.confirmType = 'batch';
        this.confirmVisible = true;
      } catch (err) {
        this.$Message.error(this.errMsg(err));
      } finally {
        this.submitting = false;
      }
    },
    async chooseReplacementCard(holderId) {
      this.replacementCardId = holderId;
      this.replacementSourceLines = [];
      this.replacementTargetId = 0;
      this.replacePreview = {};
      this.targetEmptyTip = '';
      this.replaceAttempted = false;
      this.replaceRecordsExpanded = false;
      this.replaceLoading = true;
      try {
        const [optRes, recRes] = await Promise.all([
          projectReplacementOptions(holderId),
          projectReplacementRecords(holderId).catch(() => ({ data: { list: [] } })),
        ]);
        const data = (optRes && optRes.data) || {};
        this.replaceSources = data.sources || [];
        this.replaceTargets = data.targets || [];
        this.targetEmptyTip = data.target_empty_tip || '';
        this.replacementRecords = ((recRes && recRes.data && recRes.data.list) || []);
      } catch (err) {
        this.replaceSources = [];
        this.replaceTargets = [];
        this.$Message.error(this.errMsg(err));
      } finally {
        this.replaceLoading = false;
      }
    },
    usedTimesForCart(cartInfoId, excludeKey) {
      return this.replacementSourceLines.reduce((sum, line) => {
        if (excludeKey && line.key === excludeKey) return sum;
        if (Number(line.cart_info_id) !== Number(cartInfoId)) return sum;
        return sum + Number(line.times || 0);
      }, 0);
    },
    canAddReplacementSource(project) {
      const surplus = Number(project.write_surplus_times || 0);
      if (surplus <= 0) return false;
      return this.usedTimesForCart(project.cart_info_id) < surplus;
    },
    sourceAddedTimes(project) {
      return this.usedTimesForCart(project.cart_info_id);
    },
    isReplacementSourceLineError(line) {
      if (!this.replaceAttempted || !line) return false;
      const times = Number(line.times || 0);
      const max = this.maxTimesForSourceLine(line);
      return times < 1 || times > max;
    },
    onSelectReplacementTarget(productId) {
      this.replacementTargetId = productId;
      if (this.replaceAttempted && productId) {
        // 修正后错误态立即清除（步骤提示依赖 computed 条件）
      }
    },
    scrollToReplaceStep(step) {
      this.$nextTick(() => {
        const root = this.$refs.replaceWorkspace || this.$el;
        if (!root || !root.querySelector) return;
        const el = root.querySelector(`[data-replace-step="${step}"]`);
        if (el && typeof el.scrollIntoView === 'function') {
          el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    },
    scrollToReplaceErrorLine(key) {
      this.$nextTick(() => {
        const root = this.$refs.replaceWorkspace || this.$el;
        if (!root || !root.querySelector) return;
        const el = (key && root.querySelector(`[data-source-key="${key}"]`))
          || root.querySelector('.source-line-row.error')
          || root.querySelector('.step-block.step-warn');
        if (el && typeof el.scrollIntoView === 'function') {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
    },
    maxTimesForSourceLine(line) {
      const project = (this.replaceSources || []).find((item) => Number(item.cart_info_id) === Number(line.cart_info_id));
      const surplus = Number((project && project.write_surplus_times) || line.surplus || 0);
      const others = this.usedTimesForCart(line.cart_info_id, line.key);
      return Math.max(1, surplus - others);
    },
    addReplacementSource(project) {
      if (!this.canAddReplacementSource(project)) {
        this.$Message.warning('该项目剩余次数不足，无法再添加');
        return;
      }
      // 故意不去重：同一 cart_info_id / product_id 可重复添加多条
      this.replacementSourceSeq += 1;
      this.replacementSourceLines.push({
        key: `src_${this.replacementSourceSeq}_${project.cart_info_id}`,
        cart_info_id: Number(project.cart_info_id),
        product_id: Number(project.product_id || 0),
        name: project.name || '',
        times: 1,
        unit_amount: Number(project.preview_amount || 0),
        surplus: Number(project.write_surplus_times || 0),
      });
      this.replacePreview = {};
    },
    changeReplacementSourceTimes(line, delta) {
      const next = Number(line.times || 1) + Number(delta || 0);
      const max = this.maxTimesForSourceLine(line);
      if (next < 1 || next > max) return;
      line.times = next;
      this.replacePreview = {};
    },
    removeReplacementSource(key) {
      this.replacementSourceLines = this.replacementSourceLines.filter((line) => line.key !== key);
      this.replacePreview = {};
    },
    async openReplacementConfirm() {
      this.replaceAttempted = true;
      if (!this.replacementCardId) {
        this.$Message.warning('请先选择一张卡');
        this.scrollToReplaceStep(1);
        return;
      }
      if (!this.replacementSourceLines.length) {
        this.$Message.warning('请先添加来源项目');
        this.scrollToReplaceStep(2);
        return;
      }
      const badLine = this.replacementSourceLines.find((line) => this.isReplacementSourceLineError(line));
      if (badLine) {
        this.$Message.error('有来源次数不合法或超过剩余次数，请修改后再提交');
        this.scrollToReplaceErrorLine(badLine.key);
        return;
      }
      if (!this.replacementTargetId) {
        this.$Message.warning('请选择一个新项目');
        this.scrollToReplaceStep(3);
        return;
      }
      if (!this.canSubmitReplacement) return;
      this.submitting = true;
      try {
        const res = await projectReplacementPreview(this.replacementCardId, {
          sources: this.replacementSourcesPayload,
          target_product_id: this.replacementTargetId,
          remark: this.replacementRemark || '',
        });
        this.replacePreview = (res && res.data) || {};
        this.pendingIdempotencyKey = makeTerminalRequestToken('pr');
        this.confirmType = 'replace';
        this.confirmVisible = true;
      } catch (err) {
        this.$Message.error(this.errMsg(err));
      } finally {
        this.submitting = false;
      }
    },
    async confirmSubmit() {
      if (this.submitting) return;
      if (this.confirmType === 'batch') {
        await this.commitBatch();
      } else {
        await this.commitReplacement();
      }
    },
    async commitBatch() {
      this.submitting = true;
      try {
        // 提交前按本行耗卡业绩再对齐一次；不得清零，也不得用核销金额重摊
        this.cards.forEach((card) => {
          (card.projects || []).forEach((project) => {
            if (!project.selected || !project.staffChoose || !project.staffChoose.length) return;
            project.staffChoose = this.buildEqualStaffAllocation(
              project.staffChoose,
              this.projectPerformanceLineAmount(project)
            );
          });
        });
        const key = this.pendingIdempotencyKey || makeTerminalRequestToken('bw');
        if (!this.hasMember) {
          this.$Message.warning('请先选择会员');
          return;
        }
        const res = await writeoffBatchCommit({
          uid: Number(this.activeUid),
          items: this.buildItemsPayload(),
          idempotency_key: key,
          is_budan: this.makeupEnabled ? 1 : 0,
          budan_time: this.makeupEnabled ? this.formatMakeupDate(this.makeupDate) : '',
          remark: '',
        });
        const data = (res && res.data) || {};
        this.confirmVisible = false;
        this.successTitle = '项目核销成功';
        this.successText = `已完成本次所选卡项和项目的核销：${data.total_cards || this.selectedCardCount} 张卡、${data.total_projects || this.selectedProjectCount} 种项目，共 ${data.total_times || this.totalSelectedTimes} 次。`;
        this.successNo = data.batch_no || `业务单 #${data.batch_id || ''}`;
        this.successBatchId = Number(data.batch_id || 0);
        this.successVisible = true;
        this.$emit('success', data);
        await this.reloadOptions();
        this.clearBatchSelection();
      } catch (err) {
        this.$Message.error(this.errMsg(err));
      } finally {
        this.submitting = false;
      }
    },
    async commitReplacement() {
      this.submitting = true;
      try {
        const key = this.pendingIdempotencyKey || makeTerminalRequestToken('pr');
        const res = await projectReplacementCommit(this.replacementCardId, {
          sources: this.replacementSourcesPayload,
          target_product_id: this.replacementTargetId,
          idempotency_key: key,
          remark: this.replacementRemark || '',
        });
        const data = (res && res.data) || {};
        this.confirmVisible = false;
        this.successTitle = '项目替换成功';
        this.successText = '已生成 1 条项目替换变动记录，没有生成核销订单。';
        this.successNo = data.replacement_no || `变动单 #${data.id || ''}`;
        this.successBatchId = 0;
        this.successVisible = true;
        this.$emit('done', { type: 'replace', data });
        this.$emit('success', { type: 'replace', data });
        await this.reloadOptions();
        if (this.replacementCardId) {
          await this.chooseReplacementCard(this.replacementCardId);
        }
      } catch (err) {
        this.$Message.error(this.errMsg(err));
      } finally {
        this.submitting = false;
      }
    },
    onSuccessClose() {
      this.successVisible = false;
      this.successBatchId = 0;
      this.cancellingSuccess = false;
    },
    async cancelSuccessBatch() {
      const batchId = Number(this.successBatchId || 0);
      if (!(batchId > 0) || this.cancellingSuccess) return;
      this.cancellingSuccess = true;
      try {
        await writeoffBatchCancel(batchId, { remark: '核销页成功弹窗撤销' });
        this.$Message.success('撤销本次项目核销成功');
        this.successVisible = false;
        this.successBatchId = 0;
        await this.reloadOptions();
        this.$emit('success', { type: 'batch_cancel', batch_id: batchId });
      } catch (err) {
        this.$Message.error(this.errMsg(err));
      } finally {
        this.cancellingSuccess = false;
      }
    },
  },
};
</script>

<style lang="less" scoped>
@blue: #1890ff;
@orange: #ff7700;
@text: #303133;
@muted: #909399;
@line: #e8eaed;
@card-border: #DDE4ED;
@head-bg: #F7F9FC;
@page-bg: #EEF1F5;
@panel-border: #E3E8EF;

* { box-sizing: border-box; }
button, input, textarea { font: inherit; }
button { border: 0; }

.writeoff-preview { min-width: 1180px; height: 100%; max-height: 100%; display: flex; flex-direction: column; overflow: hidden; color: #1f2329; background: @page-bg; font-size: 14px; position: relative; }
.writeoff-preview.writeoff-workbench { min-width: 0; width: 100%; }
.preview-header {
  height: 62px;
  flex: 0 0 62px;
  padding: 0 20px;
  display: grid;
  grid-template-columns: minmax(220px, 1fr) auto minmax(120px, 1fr);
  align-items: center;
  gap: 12px;
  background: #fff;
  border-bottom: 1px solid @panel-border;
  box-shadow: 0 1px 0 rgba(25, 42, 61, .02);
}
.brand { display: flex; align-items: center; gap: 12px; min-width: 0; width: auto; }
.brand-text { min-width: 0; }
.back-btn { height: 34px; padding: 0 12px; border-radius: 9px; color: #606266; background: #f4f6f8; cursor: pointer; white-space: nowrap; flex: 0 0 auto; }
.back-btn .ivu-icon { margin-right: 2px; }
.brand-title { font-size: 17px; font-weight: 600; color: #1f2329; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.brand-subtitle { margin-top: 2px; color: #606266; font-size: 12px; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mode-tabs { display: flex; justify-self: center; padding: 3px; border-radius: 11px; background: #f4f6f8; }
.mode-tabs button { min-width: 124px; height: 36px; border-radius: 9px; color: #606266; background: transparent; cursor: pointer; transition: .2s; white-space: nowrap; }
.mode-tabs button .ivu-icon { margin-right: 5px; font-size: 16px; vertical-align: -1px; }
.mode-tabs button.active { color: @blue; background: #fff; font-weight: 600; box-shadow: 0 1px 4px rgba(27, 45, 70, .08); }
.header-right { display: flex; justify-content: flex-end; align-items: center; gap: 10px; min-width: 0; }
.help-btn { height: 34px; padding: 0 12px; border: 1px solid #cde3fb; border-radius: 9px; color: #2676bd; background: #f4f9ff; cursor: pointer; white-space: nowrap; }
.help-btn .ivu-icon { margin-right: 4px; font-size: 16px; vertical-align: -2px; }
.help-btn:hover { border-color: #83bfff; background: #eaf5ff; }
.select-member-btn { height: 36px; padding: 0 14px; border-radius: 9px; color: #fff; background: @blue; cursor: pointer; white-space: nowrap; font-weight: 600; }
.select-member-btn .ivu-icon { margin-right: 4px; }
.select-member-btn--light { margin-top: 12px; color: @blue; background: #fff; border: 1px solid #9bcaff; }
.large-empty .select-member-btn { margin-top: 14px; }
.loading-mask { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: @muted; }
.loading-mask .spin { font-size: 28px; animation: wb-spin 1s linear infinite; }
@keyframes wb-spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }

.preview-main { flex: 1; min-height: 0; display: flex; padding: 12px 14px; gap: 14px; overflow: hidden; }
.card-panel {
  width: 360px;
  flex: 0 0 360px;
  display: flex;
  flex-direction: column;
  min-height: 0;
  padding: 12px;
  border-radius: 14px;
  background: #fff;
  border: 1px solid @panel-border;
  box-shadow: 0 1px 3px rgba(25, 42, 61, .04);
}
.member-card {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 12px 10px;
  border-radius: 12px;
  color: #fff;
  background: linear-gradient(135deg, #3b8cff, #6aadff);
  box-shadow: 0 4px 12px rgba(24, 119, 255, .12);
}
.member-card-top { display: flex; align-items: flex-start; gap: 8px; }
.empty-state--member { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 28px 12px; color: @muted; }
.member-meta { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.member-name-row {
  display: flex;
  align-items: baseline;
  flex-wrap: nowrap;
  gap: 8px;
  min-width: 0;
}
.member-meta strong {
  font-size: 15px;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 46%;
}
.member-phone {
  font-size: 12px;
  opacity: .92;
  font-weight: 500;
  white-space: nowrap;
  flex: 0 0 auto;
}
.member-id { margin-top: 3px; opacity: .78; font-size: 11px; line-height: 1.2; }
.member-switch-btn {
  flex: 0 0 auto;
  height: 28px;
  padding: 0 9px;
  border-radius: 8px;
  color: #1677ff;
  background: rgba(255,255,255,.95);
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}
.member-stats { display: flex; flex-direction: column; gap: 6px; }
.stat-row-3 {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 4px 8px;
  padding: 7px 8px;
  border-radius: 9px;
  background: rgba(255,255,255,.12);
}
.stat-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px 10px;
  padding: 8px 9px;
  border-radius: 9px;
  background: rgba(255,255,255,.12);
}
.stat-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.stat-cell.muted .stat-value-sm { font-weight: 600; }
.stat-label { font-size: 11px; opacity: 1; color: #ffffff; line-height: 1.1; font-weight: 600; text-shadow: 0 1px 1px rgba(0, 0, 0, 0.22); }
.stat-value { font-size: 15px; line-height: 1.15; font-weight: 700; letter-spacing: .1px; color: #fff; }
.stat-value-sm { font-size: 13px; line-height: 1.2; font-weight: 600; color: #fff; }
.stat-total {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 7px 9px;
  border-radius: 8px;
  background: rgba(0, 0, 0, .14);
}
.stat-total span { font-size: 11px; opacity: 1; color: #ffffff; font-weight: 600; text-shadow: 0 1px 1px rgba(0, 0, 0, 0.2); }
.stat-total strong { font-size: 16px; line-height: 1; font-weight: 700; letter-spacing: .2px; }
.link-btn { padding: 4px; color: @blue; background: transparent; cursor: pointer; }
.filter-chips { display: flex; gap: 7px; margin: 10px 0 8px; }
.filter-chips button { flex: 1; height: 30px; border-radius: 8px; color: #606266; background: #f4f6f8; cursor: pointer; font-size: 12px; }
.filter-chips button.active { color: @blue; background: #eaf4ff; font-weight: 600; }
.card-search { margin: 0 0 8px; }
.card-search .ivu-input-wrapper { width: 100%; }
.card-search .ivu-input { border-radius: 8px; }
.card-list { flex: 1; min-height: 0; overflow-y: auto; padding: 1px 2px 8px; }
.card-item {
  padding: 14px;
  margin-bottom: 11px;
  border: 1px solid @card-border;
  border-radius: 12px;
  background: #fff;
  cursor: pointer;
  transition: .18s;
}
.card-item:hover { border-color: #b7d4f5; }
.card-item.active {
  border-color: @blue;
  background: #f7fbff;
  box-shadow: 0 0 0 1px rgba(24, 144, 255, .18);
}
.card-item.selected {
  border-color: @blue;
  background: #f7fbff;
}
.card-item.active.selected {
  border-color: @blue;
  background: #eef6ff;
  box-shadow: 0 0 0 1px rgba(24, 144, 255, .22);
}
.card-line { display: flex; align-items: center; }.card-icon { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 9px; color: @blue; background: #eaf4ff; font-size: 18px; }
.card-name { flex: 1; min-width: 0; display: flex; flex-direction: column; margin-left: 10px; }.card-name strong { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }.card-name span { margin-top: 3px; color: @muted; font-size: 11px; }
.selected-count { padding: 4px 8px; border-radius: 11px; color: @blue; background: #eaf4ff; font-size: 11px; font-weight: 600; }
.card-item.selected .selected-count {
  color: #fff;
  background: #0b5ec2;
}
.card-stats { display: flex; justify-content: space-between; margin-top: 12px; color: #777f89; font-size: 11px; }.card-stats b { color: @text; }.card-stats .warning { color: #f59a23; }
.empty-state, .large-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; color: @muted; }.empty-state { height: 180px; }.empty-state .ivu-icon, .large-empty .ivu-icon { font-size: 42px; }.empty-state p { margin-top: 8px; }

.project-panel {
  flex: 1;
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  border-radius: 14px;
  overflow: hidden;
  background: #fff;
  border: 1px solid @panel-border;
  box-shadow: 0 1px 3px rgba(25, 42, 61, .04);
}
.project-toolbar {
  height: 58px;
  min-height: 56px;
  max-height: 60px;
  padding: 0 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border-bottom: 1px solid @panel-border;
  background: #fff;
  flex: 0 0 auto;
}
.toolbar-title-row {
  display: flex;
  flex-direction: row;
  align-items: center;
  flex-wrap: nowrap;
  gap: 8px;
  min-width: 0;
  flex: 1;
  overflow: hidden;
}
.page-kicker { margin-bottom: 6px; color: @blue; font-size: 12px; font-weight: 600; letter-spacing: 1px; }
.project-toolbar h1 {
  margin: 0;
  flex: 0 0 auto;
  font-size: 16px;
  line-height: 1.2;
  white-space: nowrap;
  color: #1f2329;
  font-weight: 700;
}
.toolbar-dot {
  flex: 0 0 auto;
  color: #c0c4cc;
  font-size: 14px;
  line-height: 1;
}
.toolbar-title-row p {
  margin: 0;
  min-width: 0;
  color: #909399;
  font-size: 12px;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.toolbar-actions { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.project-search { width: 220px; height: 40px; display: flex; align-items: center; padding: 0 12px; border: 1px solid #dfe3e8; border-radius: 10px; }
.project-search input { flex: 1; min-width: 0; margin-left: 7px; border: 0; outline: 0; }
.project-search .ivu-icon { color: @muted; }
.outline-btn, .reset-btn {
  height: 34px;
  padding: 0 12px;
  border: 1px solid #dfe3e8;
  border-radius: 9px;
  color: #606266;
  background: #fff;
  cursor: pointer;
  white-space: nowrap;
}
.clear-selected-btn:disabled {
  color: #c0c4cc;
  border-color: #e8eaed;
  background: #f7f8fa;
  cursor: not-allowed;
}
/* 只看已选：默认浅蓝；开启后品牌蓝实底 */
.filter-selected-btn {
  height: 34px;
  padding: 0 12px;
  border: 1px solid @blue;
  border-radius: 9px;
  color: @blue;
  background: #eaf4ff;
  cursor: pointer;
  white-space: nowrap;
  font-weight: 600;
}
.filter-selected-btn .ivu-icon { margin-right: 4px; }
.filter-selected-btn:hover { background: #dceeff; border-color: #0f78e8; }
.filter-selected-btn.active {
  color: #fff;
  border-color: @blue;
  background: @blue;
  box-shadow: 0 2px 8px rgba(24, 144, 255, .28);
}
.filter-selected-btn.active:hover { background: #0f78e8; border-color: #0f78e8; }
.outline-btn.active { color: @blue; border-color: #9acbff; background: #f3f9ff; }
.project-groups {
  flex: 1;
  min-height: 0;
  padding: 12px 16px 10px;
  overflow-y: auto;
  background: #F5F7FA;
}
.project-group {
  margin-bottom: 12px;
  border: 1px solid @card-border;
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
  transition: border-color .15s, box-shadow .15s, background .15s;
}
/* 有已选项目：品牌蓝外边框 + 卡头浅蓝 */
.project-group.selected {
  border-color: @blue;
  box-shadow: 0 0 0 1px rgba(24, 144, 255, .12);
}
.project-group.selected .project-group-head,
.project-group.selected > header {
  background: #EAF4FF;
}
.project-group.focused {
  border-color: @blue;
  box-shadow: 0 0 0 1px rgba(24, 144, 255, .18);
}
.project-group-head,
.project-group > header {
  height: 54px;
  min-height: 52px;
  max-height: 56px;
  padding: 0 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border-bottom: 1px solid #E8EEF5;
  background: @head-bg;
}
.group-card-title {
  display: flex;
  align-items: center;
  min-width: 0;
  flex: 1;
}
.mini-card {
  width: 28px;
  height: 28px;
  flex: 0 0 28px;
  display: grid;
  place-items: center;
  border-radius: 8px;
  color: @blue;
  background: #eaf4ff;
}
.group-card-meta {
  display: flex;
  align-items: center;
  min-width: 0;
  margin-left: 10px;
  overflow: hidden;
}
.group-card-name {
  flex: 0 1 auto;
  min-width: 0;
  max-width: 52%;
  font-size: 15px;
  font-weight: 600;
  line-height: 1.2;
  color: #1f2329;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.group-card-sep {
  flex: 0 0 auto;
  margin: 0 6px;
  color: #c0c4cc;
  font-size: 13px;
  line-height: 1;
}
.group-card-no {
  flex: 0 0 auto;
  color: #909399;
  font-size: 13px;
  font-weight: 400;
  line-height: 1.2;
  white-space: nowrap;
}
.group-summary-wrap {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  flex: 0 0 auto;
  white-space: nowrap;
}
.group-pending {
  padding: 2px 8px;
  border-radius: 10px;
  color: #d48806;
  background: #fff7e6;
  border: 1px solid #ffd591;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.3;
}
.group-pending.error {
  color: #cf1322;
  background: #fff1f0;
  border-color: #ffa39e;
}
.group-summary {
  color: @blue;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}
.project-table-head, .project-row {
  display: grid;
  grid-template-columns: minmax(200px, 1.55fr) .52fr .72fr .75fr .66fr .72fr;
  align-items: center;
  gap: 9px;
  padding: 0 16px;
}
.project-table-head { height: 34px; color: #9aa0a8; background: #fafbfc; font-size: 11px; }
.project-table-head > span:first-child { padding-left: 30px; }
.project-row { min-height: 56px; border-top: 1px solid #f0f1f3; transition: background .15s, box-shadow .15s; background: #fff; }
.project-row:hover:not(.checked) { background: #F7FAFC; }
/* 已选：更明显浅蓝 + 左侧蓝条；校验错误优先于已选蓝 */
.project-row.checked {
  background: #E8F3FF;
  box-shadow: inset 3px 0 0 @blue;
}
.project-row.checked .project-name-text strong { font-weight: 700; color: #0f1b2d; }
.project-row.staff-pending {
  background: #E8F3FF;
  box-shadow: inset 3px 0 0 @blue;
}
.project-row.staff-error {
  background: #FFF1F0;
  box-shadow: inset 3px 0 0 #F5222D;
}
.project-row.staff-error .project-name-text strong { color: #a8071a; }
.project-name-cell {
  display: flex;
  align-items: center;
  min-width: 0;
  cursor: pointer;
  gap: 10px;
}
/* 20px 圆角方多选框；纵向对齐便于连续勾选 */
.check-box {
  width: 20px;
  height: 20px;
  flex: 0 0 20px;
  display: grid;
  place-items: center;
  border: 1.5px solid #8FA3B8;
  border-radius: 5px;
  color: #fff;
  background: #fff;
  transition: border-color .15s, background .15s, box-shadow .15s;
}
.project-name-cell:hover .check-box:not(.checked) {
  border-color: @blue;
  background: #EAF4FF;
}
.check-box.checked {
  border-color: @blue;
  background: @blue;
  box-shadow: 0 0 0 1px rgba(24, 144, 255, .15);
}
.check-box .ivu-icon { font-size: 14px; font-weight: 700; line-height: 1; }
.source-check {
  width: 18px;
  height: 18px;
  flex: 0 0 18px;
  display: grid;
  place-items: center;
  border: 1px solid #c9cdd3;
  border-radius: 5px;
  color: #fff;
}
.source-check.active { border-color: @blue; background: @blue; }
.project-name-text {
  min-width: 0;
  flex: 1;
  display: flex;
  flex-direction: column;
}
.project-name-text strong {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: #1f2329;
  font-size: 14px;
  line-height: 1.25;
}
.project-name-text small {
  margin-top: 3px;
  color: @muted;
  font-size: 11px;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.source-avatar, .target-avatar {
  width: 36px;
  height: 36px;
  flex: 0 0 36px;
  display: grid;
  place-items: center;
  margin-left: 10px;
  border-radius: 9px;
  color: #3979ae;
  background: #e9f5ff;
  font-weight: 600;
}
.times-cell b { color: @blue; font-size: 16px; }
.quantity-stepper { width: 104px; height: 32px; display: flex; align-items: center; border: 1px solid #dfe3e8; border-radius: 8px; overflow: hidden; }.quantity-stepper button { width: 31px; height: 100%; color: @blue; background: #f7faff; cursor: pointer; }.quantity-stepper button:disabled { color: #c7cbd1; cursor: not-allowed; }.quantity-stepper span { flex: 1; text-align: center; font-weight: 600; }
.amount-cell { display: flex; flex-direction: column; }.amount-cell b { color: #f05b36; font-size: 16px; }.service-object { display: flex; padding: 3px; border-radius: 8px; background: #f3f5f7; }.service-object button { flex: 1; padding: 5px 4px; border-radius: 6px; color: #777; background: transparent; cursor: pointer; font-size: 11px; }.service-object button.active { color: @blue; background: #fff; box-shadow: 0 1px 5px rgba(32,54,75,.1); }
.staff-entry {
  min-width: 0;
  height: 32px;
  padding: 0 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #b9d9fa;
  border-radius: 8px;
  color: @blue;
  background: #f3f9ff;
  cursor: pointer;
  white-space: nowrap;
  font-size: 11px;
  overflow: hidden;
}
.staff-entry > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
.staff-entry .ivu-icon { flex: 0 0 auto; margin-right: 4px; font-size: 16px; }
.staff-entry:hover { border-color: #7ebcff; background: #eaf5ff; }
.staff-entry.disabled { color: #a4abb3; border-color: #e1e4e8; background: #f7f8fa; cursor: not-allowed; }
.staff-entry.assigned { border-color: #7ebcff; background: #eaf5ff; font-weight: 600; }
/* 已选待分配：橙色提醒（提交前） */
.staff-entry.pending {
  color: #d48806;
  border-color: #fa8c16;
  background: #fff7e6;
  font-weight: 600;
}
.staff-entry.pending:hover { border-color: #d46b08; background: #fff1b8; }
/* 提交校验失败：红色错误 */
.staff-entry.error {
  color: #cf1322;
  border-color: #f5222d;
  background: #fff1f0;
  font-weight: 700;
}
.staff-entry.error:hover { border-color: #cf1322; background: #ffccc7; }
.staff-entry.exempt {
  color: #8c8c8c;
  border-color: #e8e8e8;
  background: #fafafa;
  font-weight: 500;
}
.large-empty { height: 100%; min-height: 260px; }.large-empty h3 { margin: 12px 0 4px; }.large-empty p { margin: 0; font-size: 12px; }
.batch-footer { flex: 0 0 auto; min-height: 78px; padding: 12px 18px; display: flex; align-items: center; justify-content: space-between; gap: 16px; border-top: 1px solid @panel-border; box-shadow: 0 -4px 14px rgba(25,42,61,.04); background: #fff; z-index: 2; }.summary-pills { display: flex; align-items: center; gap: 18px; color: #676f78; }.summary-pills span { white-space: nowrap; }.summary-pills b { color: @text; font-size: 18px; }.summary-pills .amount { padding-left: 18px; border-left: 1px solid @line; }.summary-pills .amount b { color: #f05b36; font-size: 23px; }.footer-actions { display: flex; align-items: center; gap: 9px; }
.more-actions-wrap { position: relative; }
.more-actions-btn {
  height: 40px;
  padding: 0 12px;
  display: inline-flex;
  align-items: center;
  border: 1px solid #dfe3e8;
  border-radius: 10px;
  color: #59636e;
  background: #fff;
  cursor: pointer;
}
.more-actions-btn > .ivu-icon:first-child { margin-right: 5px; }
.more-actions-btn > .ivu-icon:last-child { margin-left: 4px; color: #9ba2aa; }
.more-info-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  margin: 0 2px 0 6px;
  border-radius: 50%;
  color: #8aa0b8;
  font-size: 13px;
  line-height: 1;
  cursor: help;
  vertical-align: middle;
}
.more-info-icon:hover { color: @blue; background: #eaf4ff; }
.more-actions-menu { position: absolute; right: 0; bottom: 48px; z-index: 20; width: 320px; padding: 9px; border: 1px solid @line; border-radius: 13px; background: #fff; box-shadow: 0 14px 36px rgba(29,47,68,.17); }.menu-caption { padding: 5px 8px 8px; color: #9aa1a9; font-size: 10px; font-weight: 600; letter-spacing: .8px; }.more-actions-menu button { width: 100%; padding: 9px 10px; display: flex; align-items: center; border-radius: 9px; color: @text; background: #fff; text-align: left; cursor: pointer; }.more-actions-menu button:hover { background: #f4f8fc; }.more-actions-menu button.danger strong { color: #d95353; }.menu-icon { width: 32px; height: 32px; flex: 0 0 32px; display: grid; place-items: center; margin-right: 10px; border-radius: 8px; color: #3f83be; background: #edf6ff; font-size: 17px; }.more-actions-menu button > span:last-child { display: flex; flex-direction: column; }.more-actions-menu small { margin-top: 2px; color: @muted; font-size: 10px; }
.makeup-control { height: 42px; display: flex; align-items: center; padding: 0 11px; border: 1px solid #dfe3e8; border-radius: 10px; color: #69727c; background: #fff; }.makeup-control.active { border-color: #9bcaff; background: #f8fbff; }.makeup-switch { display: flex; align-items: center; cursor: pointer; white-space: nowrap; }.makeup-switch input { display: none; }.switch-track { width: 29px; height: 16px; position: relative; margin-right: 7px; border-radius: 10px; background: #c9ced4; transition: .2s; }.switch-track i { width: 12px; height: 12px; position: absolute; top: 2px; left: 2px; border-radius: 50%; background: #fff; transition: .2s; }.makeup-switch input:checked + .switch-track { background: @blue; }.makeup-switch input:checked + .switch-track i { left: 15px; }.makeup-switch .ivu-icon { margin-right: 3px; }.makeup-placeholder { margin-left: 8px; padding-left: 8px; border-left: 1px solid @line; color: #a3a8ae; font-size: 11px; }.makeup-date-input { width: 167px; margin-left: 8px; padding-left: 9px; border: 0; border-left: 1px solid @line; outline: 0; color: #4b5560; background: transparent; font-size: 11px; }
.primary-btn { height: 42px; padding: 0 22px; border-radius: 10px; color: #fff; background: @blue; box-shadow: 0 6px 14px rgba(24,144,255,.2); cursor: pointer; font-weight: 600; }.primary-btn:disabled { background: #b9c0c8; box-shadow: none; cursor: not-allowed; }.primary-btn .ivu-icon { margin-left: 5px; }


.replacement-page {
  flex: 1;
  min-height: 0;
  padding: 10px 12px 12px;
  overflow: hidden;
  background: @page-bg;
  display: flex;
  flex-direction: column;
}
.replacement-layout {
  flex: 1;
  min-height: 0;
  width: 100%;
  max-width: 1480px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 12px;
  align-items: stretch;
  overflow: hidden;
}
.replacement-workspace,
.replacement-summary {
  border-radius: 14px;
  background: #fff;
  border: 1px solid @panel-border;
  box-shadow: 0 1px 3px rgba(25, 42, 61, .04);
  min-height: 0;
}
.replacement-workspace {
  padding: 12px 14px 14px;
  overflow-x: hidden;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}
.replacement-summary {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 0;
}
.summary-scroll {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 14px 14px 10px;
}
.step-block {
  padding: 10px 12px 12px;
  border: 1px solid @card-border;
  border-radius: 12px;
  background: #fff;
}
.step-block + .step-block { margin-top: 10px; }
.step-block.step-warn { border-color: #ffd591; background: #fffdf8; }
.step-block.step-done { border-color: #b9d4ff; }
.step-title {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px 8px;
  margin-bottom: 8px;
  min-height: 28px;
}
.step-title > span {
  width: 22px;
  height: 22px;
  flex: 0 0 22px;
  display: grid;
  place-items: center;
  border-radius: 50%;
  color: #fff;
  background: @blue;
  font-size: 12px;
  font-weight: 700;
}
.step-title h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #1f2329;
  line-height: 1.2;
}
.step-title p {
  margin: 0;
  color: @muted;
  font-size: 12px;
  line-height: 1.2;
  white-space: nowrap;
}
.step-title > b { margin-left: auto; color: @blue; font-size: 12px; }
.step-tip {
  margin: 0 0 8px;
  padding: 6px 10px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
}
.step-tip.warn {
  color: #d48806;
  background: #fff7e6;
  border: 1px solid #ffd591;
}
.replace-card-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 8px;
  max-height: 148px;
  overflow-y: auto;
}
.replace-card-list button {
  min-width: 0;
  min-height: 48px;
  padding: 8px 10px;
  display: flex;
  align-items: center;
  text-align: left;
  border: 1px solid @card-border;
  border-radius: 10px;
  color: @text;
  background: #fff;
  cursor: pointer;
  gap: 8px;
}
.replace-card-list button:hover { background: #F7FAFC; border-color: #b7d4f5; }
.replace-card-list button.active {
  border-color: @blue;
  background: #EAF4FF;
  box-shadow: 0 0 0 1px rgba(24, 144, 255, .18);
}
.replace-card-icon {
  width: 28px;
  height: 28px;
  flex: 0 0 28px;
  display: grid;
  place-items: center;
  border-radius: 8px;
  color: @blue;
  background: #eaf4ff;
}
.replace-card-name-tip {
  flex: 1;
  min-width: 0;
  display: block;
  overflow: hidden;
}
.replace-card-name-tip /deep/ .ivu-tooltip-rel {
  display: block;
  width: 100%;
  min-width: 0;
  overflow: hidden;
}
.replace-card-name {
  display: block;
  width: 100%;
  min-width: 0;
  font-size: 15px;
  font-weight: 600;
  line-height: 1.3;
  color: #1f2329;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.replace-card-tail {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: 8px;
}
.replace-card-no {
  flex-shrink: 0;
  color: @muted;
  font-size: 13px;
  line-height: 1;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
.replace-check {
  width: 20px;
  height: 20px;
  flex: 0 0 20px;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  border-radius: 5px;
  color: #fff;
  background: @blue;
  font-size: 13px;
}
.source-project-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}
.source-add-card {
  min-width: 0;
  padding: 10px 11px;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  border: 1px solid @card-border;
  border-radius: 10px;
  color: @text;
  background: #fff;
  text-align: left;
  cursor: pointer;
}
.source-add-card:hover { background: #F7FAFC; border-color: #b7d4f5; }
.source-add-card.added { border-color: #9ec8f4; background: #f7fbff; }
.source-add-card:disabled { opacity: .5; cursor: not-allowed; }
.source-add-card strong {
  font-size: 14px;
  font-weight: 600;
  line-height: 1.3;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.source-surplus-line {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 4px;
  line-height: 1.35;
  font-size: 12px;
}
.source-surplus-line .surplus-label { color: @muted; font-weight: 400; }
.source-surplus-line .surplus-num {
  color: @blue;
  font-size: 14px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.source-surplus-line .surplus-sep { color: #c0c4cc; }
.source-surplus-line .surplus-amount {
  color: @orange;
  font-weight: 600;
}
.source-add-card em {
  margin-top: 2px;
  font-style: normal;
  font-size: 12px;
  font-weight: 600;
  color: @blue;
}
.source-add-card .add-once { color: @blue; }
.source-line-list {
  margin-top: 10px;
  border: 1px solid @card-border;
  border-radius: 12px;
  background: #fff;
  overflow: hidden;
}
.source-line-head {
  padding: 8px 12px;
  background: #F7F9FC;
  color: #606266;
  font-size: 12px;
  font-weight: 600;
}
.source-line-table-head,
.source-line-row {
  display: grid;
  grid-template-columns: 40px minmax(0, 1.4fr) 110px 88px 78px;
  gap: 8px;
  align-items: center;
  padding: 0 12px;
}
.source-line-table-head {
  height: 32px;
  color: #9aa0a8;
  background: #fafbfc;
  font-size: 11px;
  border-top: 1px solid #eef1f5;
}
.source-line-row {
  min-height: 52px;
  border-top: 1px solid #f0f1f3;
  background: #E8F3FF;
  box-shadow: inset 3px 0 0 @blue;
}
.source-line-row.error {
  background: #FFF1F0;
  box-shadow: inset 3px 0 0 #F5222D;
}
.source-line-index {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #fff;
  color: @blue;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 600;
  border: 1px solid #cde3fb;
}
.source-line-meta { min-width: 0; display: flex; flex-direction: column; }
.source-line-meta strong {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 13px;
}
.source-line-meta small { color: @muted; font-size: 11px; margin-top: 2px; }
.source-line-amount { color: #f05b36; font-weight: 700; font-size: 13px; }
.source-line-remove {
  flex: 0 0 auto;
  height: 30px;
  padding: 0 10px;
  border: 1px solid #ffbbb0;
  border-radius: 8px;
  color: #d4380d;
  background: #fff7f0;
  cursor: pointer;
  white-space: nowrap;
  font-size: 12px;
  font-weight: 600;
}
.source-line-remove .ivu-icon { margin-right: 2px; font-size: 14px; vertical-align: -2px; }
.source-line-remove:hover { border-color: #ff9c8a; background: #ffece3; }
.target-search {
  width: 100%;
  max-width: 420px;
  height: 36px;
  margin: 0 0 12px;
  padding: 0 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  border: 1px solid @card-border;
  border-radius: 9px;
  background: #fff;
}
.target-search .ivu-icon { color: @muted; font-size: 16px; }
.target-search input {
  flex: 1;
  min-width: 0;
  height: 100%;
  border: 0;
  outline: 0;
  background: transparent;
  font-size: 13px;
  color: @text;
}
.target-search input::placeholder { color: #c0c4cc; }
.target-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}
.target-card {
  min-width: 0;
  min-height: 64px;
  padding: 10px 11px;
  display: flex;
  align-items: flex-start;
  gap: 8px;
  border: 1px solid @card-border;
  border-radius: 10px;
  color: @text;
  background: #fff;
  text-align: left;
  cursor: pointer;
}
.target-card:hover { background: #F7FAFC; border-color: #b7d4f5; }
.target-card.active {
  border-color: @blue;
  background: #EAF4FF;
  box-shadow: 0 0 0 1px rgba(24, 144, 255, .16);
}
.target-card-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.target-card-body strong {
  font-size: 13px;
  font-weight: 600;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.target-card-body small { color: @muted; font-size: 11px; }
.summary-title { display: flex; align-items: center; gap: 12px; }
.summary-title-main { display: flex; align-items: center; min-width: 0; flex: 1; }
.summary-title-main > .ivu-icon {
  width: 34px;
  height: 34px;
  flex: 0 0 34px;
  display: grid;
  place-items: center;
  margin-right: 10px;
  border-radius: 10px;
  color: #fff;
  background: @blue;
  font-size: 18px;
}
.summary-title h2 { margin: 0; font-size: 16px; font-weight: 700; }
.summary-title p { margin: 3px 0 0; color: @muted; font-size: 11px; }
.flow-card {
  margin-top: 12px;
  padding: 12px;
  border: 1px solid @card-border;
  border-radius: 12px;
  background: #F7F9FC;
}
.flow-label { margin-bottom: 6px; color: #777f88; font-size: 11px; font-weight: 600; }
.flow-items > div {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 8px;
  align-items: center;
  padding: 8px 9px;
  margin-top: 6px;
  border-radius: 8px;
  background: #fff5f5;
}
.flow-items b { color: #e05252; }
.flow-source-remove {
  height: 26px;
  padding: 0 8px;
  border: 1px solid #ffbbb0;
  border-radius: 6px;
  color: #d4380d;
  background: #fff7f0;
  cursor: pointer;
  font-size: 11px;
  white-space: nowrap;
}
.flow-source-remove:hover { border-color: #ff9c8a; background: #ffece3; }
.flow-placeholder {
  padding: 12px;
  border: 1px dashed #ccd2d9;
  border-radius: 8px;
  color: @muted;
  text-align: center;
  font-size: 12px;
  background: #fff;
  pointer-events: none;
  user-select: none;
  cursor: default;
}
.flow-guide {
  line-height: 1.55;
}
.flow-guide .step-ref {
  color: @blue;
  font-weight: 700;
  margin: 0 2px;
}
.flow-placeholder-warn {
  color: #d48806;
  border-color: #ffd591;
  background: #fff7e6;
}
.flow-arrow {
  height: 34px;
  position: relative;
  display: grid;
  place-items: center;
  color: #9aa1aa;
}
.flow-arrow span {
  position: absolute;
  top: 6px;
  bottom: 6px;
  width: 1px;
  background: #dce0e5;
}
.flow-arrow .ivu-icon { position: relative; padding: 2px; background: #F7F9FC; }
.new-project-preview {
  display: flex;
  align-items: center;
  padding: 10px;
  border: 1px solid #b9d4ff;
  border-radius: 9px;
  background: #EAF4FF;
}
.new-project-preview > div { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.new-project-preview strong {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.new-project-preview small { margin-top: 3px; color: @muted; font-size: 10px; }
.new-project-preview > b { color: @blue; font-size: 17px; margin-left: 8px; }
.replacement-total {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 4px;
  margin-top: 12px;
  padding: 12px;
  border-radius: 11px;
  background: #fff6ee;
}
.replacement-total b { color: @orange; font-size: 22px; }
.replacement-total small { grid-column: 1 / 3; color: #a17a5c; font-size: 10px; }
.no-order-note {
  display: flex;
  margin-top: 10px;
  padding: 10px;
  border-radius: 10px;
  color: #6d5b2e;
  background: #fff9e9;
  font-size: 11px;
  line-height: 1.55;
}
.no-order-note .ivu-icon { margin: 2px 7px 0 0; color: #f0a11c; font-size: 17px; }
.no-order-note p { margin: 2px 0 0; }
.remark-field { display: block; margin-top: 12px; }
.remark-field > span { display: block; margin-bottom: 6px; color: #656d76; font-size: 11px; }
.remark-field textarea {
  width: 100%;
  height: 52px;
  padding: 8px 10px;
  resize: none;
  outline: none;
  border: 1px solid @card-border;
  border-radius: 9px;
}
.remark-field textarea:focus { border-color: @blue; }
.mock-records { margin-top: 12px; padding-top: 10px; border-top: 1px solid @line; }
.records-head {
  display: flex;
  align-items: center;
  gap: 8px;
}
.records-toggle {
  flex: 1;
  min-width: 0;
  height: 34px;
  padding: 0 4px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border: 0;
  background: transparent;
  color: #606266;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
}
.records-view-all {
  flex: 0 0 auto;
  height: 28px;
  padding: 0 10px;
  border: 1px solid #cde3fb;
  border-radius: 8px;
  background: #f4f9ff;
  color: #2676bd;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
}
.record-item {
  display: flex;
  flex-direction: column;
  margin-top: 8px;
  padding: 8px 9px;
  border-radius: 8px;
  background: #f6f8fa;
}
.record-item-clickable {
  width: 100%;
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  border: 1px solid @card-border;
  background: #fff;
  text-align: left;
  cursor: pointer;
  transition: .15s ease;
}
.record-item-clickable:hover {
  border-color: #b9d4ff;
  background: #F7FAFC;
}
.record-item-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.record-item-main strong {
  font-size: 12px;
  color: #1f2329;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.record-item-main small,
.record-item-main em {
  color: @muted;
  font-size: 11px;
  font-style: normal;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.record-item-side {
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
}
.record-item-side b { color: @orange; font-size: 14px; }
.record-item-side span {
  margin-top: 0;
  color: @blue;
  font-size: 11px;
  font-weight: 600;
}
.record-item strong { font-size: 11px; }
.record-item span { margin-top: 3px; color: @muted; font-size: 10px; }
.replace-footer {
  flex: 0 0 auto;
  padding: 10px 14px 12px;
  border-top: 1px solid @panel-border;
  background: #fff;
  box-shadow: 0 -4px 12px rgba(25, 42, 61, .04);
}
.replace-footer-summary {
  margin-bottom: 8px;
  color: #606266;
  font-size: 12px;
  line-height: 1.3;
}
.replace-submit {
  width: 100%;
  height: 44px;
  min-width: 0;
  padding: 0 16px;
  margin: 0;
  font-size: 15px;
  line-height: 1.2;
  white-space: nowrap;
}
.replace-submit.is-disabled {
  background: #b9c0c8;
  box-shadow: none;
  cursor: not-allowed;
}
.instruction-sections-col { grid-template-columns: 1fr 1fr; }
.instruction-block.full { grid-column: 1 / -1; }

.confirm-modal { padding: 4px 2px 0; }.confirm-header { display: flex; align-items: center; padding-bottom: 17px; border-bottom: 1px solid @line; }.confirm-icon { width: 43px; height: 43px; display: grid; place-items: center; margin-right: 12px; border-radius: 12px; color: #fff; background: @blue; font-size: 22px; }.confirm-header > div { flex: 1; }.confirm-header h2 { margin: 0; font-size: 20px; }.confirm-header p { margin: 4px 0 0; color: @muted; }
.order-result-banner { display: flex; align-items: center; justify-content: space-around; margin: 17px 0 13px; padding: 15px; border-radius: 12px; background: #f3f8ff; }.order-result-banner > div { display: flex; flex-direction: column; text-align: center; }.order-result-banner span { color: #7b8490; font-size: 11px; }.order-result-banner strong { margin-top: 3px; color: @blue; font-size: 18px; }.order-result-banner > .ivu-icon { color: #a7b8c9; }
.makeup-confirm-note { margin-bottom: 12px; padding: 10px 12px; border-radius: 9px; color: #7a5b24; background: #fff8e8; }.makeup-confirm-note .ivu-icon { margin-right: 5px; }.confirm-groups { max-height: 370px; overflow-y: auto; }.confirm-group { margin-top: 9px; border: 1px solid @line; border-radius: 10px; overflow: hidden; }.confirm-card-name { padding: 9px 12px; display: flex; justify-content: space-between; background: #fafbfc; }.confirm-card-name span { color: @muted; font-size: 11px; }.confirm-line { display: grid; grid-template-columns: 1.2fr 1.4fr 80px 100px; gap: 8px; align-items: center; padding: 9px 12px; border-top: 1px solid #f0f1f3; }.confirm-line-head { color: @muted; font-size: 11px; background: #f7f9fc; border-top: 0; }.confirm-line-head > span:nth-child(3),
.confirm-line-head > span:nth-child(4) { text-align: center; }.confirm-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.confirm-staff { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #4b5560; }.confirm-line em { color: #737b84; font-style: normal; text-align: center; }.confirm-line b { color: #f05b36; text-align: center; }
.replace-confirm-flow { display: grid; grid-template-columns: 1fr 1fr 40px 1.25fr; gap: 9px; align-items: stretch; margin: 20px 0; }.replace-confirm-flow > div { display: flex; flex-direction: column; padding: 14px; border-radius: 10px; background: #fff5f5; }.replace-confirm-flow > div.target { background: #f5f3ff; }.replace-confirm-flow small { color: @muted; }.replace-confirm-flow strong { margin: 6px 0; }.replace-confirm-flow span { color: #e05252; font-size: 11px; }.replace-confirm-flow .target span { color: #6652c9; }.replace-confirm-flow > .ivu-icon { align-self: center; justify-self: center; color: #939aa3; font-size: 20px; }
.replace-warning { display: flex; align-items: center; padding: 12px; border-radius: 10px; color: #755e32; background: #fff8e6; }.replace-warning .ivu-icon { margin-right: 8px; color: #ed9e18; font-size: 20px; }.confirm-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 18px; padding-top: 16px; border-top: 1px solid @line; }.confirm-footer > span { color: @muted; font-size: 11px; }.confirm-footer > div { display: flex; gap: 9px; }
.success-card { padding: 12px 18px 5px; display: flex; flex-direction: column; align-items: center; text-align: center; }.success-icon { width: 62px; height: 62px; display: grid; place-items: center; border-radius: 50%; color: #fff; background: #36bf76; box-shadow: 0 8px 20px rgba(54,191,118,.22); font-size: 31px; }.success-card h2 { margin: 17px 0 7px; }.success-card p { margin: 0; color: #777f88; line-height: 1.6; }.fake-order-no { width: 100%; margin: 16px 0; padding: 11px; border-radius: 9px; color: #56616c; background: #f5f7f9; font-family: monospace; }.success-actions { width: 100%; display: flex; flex-direction: column; gap: 10px; }
.success-actions .primary-btn,
.success-actions .ghost-btn { width: 100%; }
.ghost-btn {
  height: 42px;
  padding: 0 22px;
  border-radius: 10px;
  color: #d4380d;
  background: #fff7f0;
  border: 1px solid #ffbb96;
  cursor: pointer;
  font-weight: 600;
}
.ghost-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.success-card .primary-btn { width: 100%; }
.instructions-panel { padding: 4px 6px 7px; }
.instructions-head { display: flex; align-items: center; padding-bottom: 14px; border-bottom: 1px solid @line; }
.instructions-head > span { width: 42px; height: 42px; display: grid; place-items: center; margin-right: 12px; border-radius: 12px; color: #fff; background: linear-gradient(135deg, #1788ff, #66b2ff); font-size: 22px; }
.instructions-head small { color: @blue; font-weight: 600; }
.instructions-head h2 { margin: 3px 0 0; font-size: 18px; }
.instruction-sections { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; }
.instruction-block {
  padding: 12px 13px;
  border: 1px solid @card-border;
  border-radius: 10px;
  background: #fff;
}
.instruction-block h3 {
  margin: 0 0 6px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: #1f2329;
}
.instruction-block h3 b {
  width: 22px;
  height: 22px;
  display: grid;
  place-items: center;
  border-radius: 50%;
  color: @blue;
  background: #eaf4ff;
  font-size: 12px;
}
.instruction-block p {
  margin: 0;
  color: #606266;
  font-size: 12px;
  line-height: 1.55;
}
.instruction-intro { margin: 16px 0 12px; padding: 12px 14px; border-radius: 10px; color: #496986; background: #f0f7ff; line-height: 1.6; }
.instruction-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.instruction-steps > div { padding: 14px; display: flex; border: 1px solid @line; border-radius: 11px; }
.instruction-steps b { width: 26px; height: 26px; flex: 0 0 26px; display: grid; place-items: center; margin-right: 9px; border-radius: 50%; color: @blue; background: #eaf4ff; }
.instruction-steps span { display: flex; flex-direction: column; }
.instruction-steps small { margin-top: 5px; color: @muted; line-height: 1.55; }
.plain-rule { margin-top: 12px; padding: 11px 13px; display: flex; border-radius: 10px; color: #506d87; background: #f5f9fd; }
.plain-rule.warning { color: #765e2d; background: #fff8e7; }
.plain-rule .ivu-icon { margin: 1px 7px 0 0; font-size: 18px; }
.instruction-close { width: 100%; margin-top: 16px; }

/* 项目替换记录列表 / 明细 */
.rpl-list-panel { padding: 4px 2px 0; }
.rpl-list-head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
  padding-bottom: 12px;
  border-bottom: 1px solid @line;
}
.rpl-list-icon {
  width: 40px;
  height: 40px;
  display: grid;
  place-items: center;
  border-radius: 11px;
  color: #fff;
  background: @blue;
  font-size: 20px;
}
.rpl-list-head h2 { margin: 0; font-size: 18px; }
.rpl-list-head p { margin: 3px 0 0; color: @muted; font-size: 12px; }
.rpl-list-body {
  max-height: 52vh;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.rpl-list-row {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border: 1px solid @card-border;
  border-radius: 12px;
  background: #fff;
  text-align: left;
  cursor: pointer;
}
.rpl-list-row:hover {
  border-color: #b9d4ff;
  background: #F7FAFC;
}
.rpl-list-row-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.rpl-list-row-main strong {
  font-size: 14px;
  color: #1f2329;
}
.rpl-list-row-main small,
.rpl-list-row-main em {
  color: @muted;
  font-size: 12px;
  font-style: normal;
}
.rpl-list-row-side {
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
}
.rpl-list-row-side b { color: @orange; font-size: 18px; }
.rpl-list-row-side span { color: @blue; font-size: 12px; font-weight: 600; }
.rpl-list-close { width: 100%; margin-top: 14px; color: #606266; background: #fff; border-color: @card-border; }

.rpl-detail { padding: 4px 2px 16px; }
.rpl-detail-section {
  margin-bottom: 12px;
  padding: 12px;
  border: 1px solid @card-border;
  border-radius: 12px;
  background: #F7F9FC;
}
.rpl-detail-section h3 {
  margin: 0 0 10px;
  font-size: 13px;
  font-weight: 700;
  color: #303133;
}
.rpl-detail-grid {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.rpl-detail-grid > div {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}
.rpl-detail-grid > div > span {
  flex: 0 0 auto;
  color: @muted;
  font-size: 12px;
}
.rpl-detail-grid > div > b {
  flex: 1;
  text-align: right;
  font-size: 13px;
  font-weight: 600;
  color: #1f2329;
  word-break: break-all;
}
.rpl-detail-grid > div > b.muted { color: @muted; font-weight: 500; }
.rpl-detail-amount b { color: @orange !important; font-size: 18px !important; }
.rpl-detail-note {
  display: block !important;
  margin-top: 4px;
  padding: 8px 10px;
  border-radius: 8px;
  color: #6d5b2e;
  background: #fff9e9;
  font-size: 11px;
  line-height: 1.5;
}
.rpl-detail-source {
  padding: 10px;
  margin-top: 8px;
  border-radius: 10px;
  background: #fff5f5;
  border: 1px solid #ffd6d6;
}
.rpl-detail-source:first-of-type { margin-top: 0; }
.rpl-detail-source-top {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}
.rpl-detail-source-top strong { font-size: 13px; }
.rpl-detail-source-top b { color: #cf1322; font-size: 13px; }
.rpl-detail-source-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 10px;
  margin-top: 6px;
  color: @muted;
  font-size: 11px;
}
.rpl-detail-target {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px;
  border-radius: 10px;
  background: #EAF4FF;
  border: 1px solid #b9d4ff;
}
.rpl-detail-target > div {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.rpl-detail-target strong { font-size: 14px; }
.rpl-detail-target small { color: @muted; font-size: 11px; }
.rpl-detail-target > b { color: @blue; font-size: 18px; }

@media (max-width: 1400px) {
  .project-toolbar h1 { font-size: 15px; }
  .toolbar-title-row { gap: 6px; }
  .toolbar-title-row p { font-size: 11px; letter-spacing: -0.1px; }
  .toolbar-dot { margin: 0 -1px; }
}
@media (max-width: 1300px) {
  .card-panel { width: 340px; flex-basis: 340px; }
  .project-table-head, .project-row { grid-template-columns: minmax(180px, 1.4fr) .5fr .68fr .7fr .63fr .68fr; }
  .replacement-layout { grid-template-columns: minmax(0, 1fr) 320px; }
  .source-project-grid,
  .target-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
