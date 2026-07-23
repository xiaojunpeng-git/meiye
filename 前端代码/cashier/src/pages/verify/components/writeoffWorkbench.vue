<template>
  <div class="writeoff-preview writeoff-workbench" @click="moreOpen = false">
    <header class="preview-header">
      <div class="brand">
        <button class="back-btn" type="button" @click="$emit('exit')">
          <Icon type="ios-arrow-back" /> 返回
        </button>
        <div>
          <div class="brand-title">收银台 · 项目核销</div>
          <div class="brand-subtitle">{{ memberLabel }}</div>
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
        <button class="select-member-btn" type="button" @click="openMemberSelect">
          <Icon type="ios-person" /> 选择会员
        </button>
        <button class="help-btn" type="button" @click="openInstructions">
          <Icon type="ios-help-circle-outline" /> 操作说明
        </button>
      </div>
    </header>

    <div v-if="loadingOptions" class="loading-mask">
      <Icon type="ios-loading" class="spin" />
      <span>正在加载卡项与项目…</span>
    </div>

    <main v-else-if="mode === 'batch'" class="preview-main">
      <aside class="card-panel">
        <section class="member-card">
          <div class="avatar">{{ hasMember ? memberAvatarText : '未' }}</div>
          <div class="member-meta">
            <strong>{{ hasMember ? memberName : '未选择会员' }}</strong>
            <span v-if="hasMember">{{ memberPhone }} · 会员ID {{ activeUid }}</span>
            <span v-else>请先选择会员后再核销</span>
          </div>
          <div class="member-count">
            <b>{{ hasMember ? cards.length : '—' }}</b>
            <span>张卡</span>
          </div>
        </section>

        <div v-if="hasMember" class="card-search search-box">
          <Icon type="ios-search" />
          <input
            v-model.trim="cardKeyword"
            type="text"
            placeholder="搜索卡名称、卡号"
          />
          <Icon
            v-if="cardKeyword"
            type="md-close-circle"
            class="clear-icon"
            @click.native="cardKeyword = ''"
          />
        </div>
        <div v-else class="card-search-placeholder">选择会员后可搜索卡项</div>

        <div v-if="hasMember" class="filter-chips">
          <button
            v-for="item in cardFilters"
            :key="item.value"
            type="button"
            :class="{ active: cardFilter === item.value }"
            @click="cardFilter = item.value"
          >{{ item.label }}</button>
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
              <p>{{ cards.length ? '当前筛选下没有卡项' : '该会员暂无可核销卡项' }}</p>
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
            <p>可选择一张或多张卡中的项目，一次核对并提交</p>
          </div>
          <div class="toolbar-actions">
            <button
              v-if="hasMember"
              type="button"
              class="outline-btn"
              :disabled="!selectedProjectCount"
              @click="clearBatchSelection"
            >清空已选</button>
            <button
              v-if="hasMember"
              type="button"
              :class="['outline-btn', { active: onlySelected }]"
              @click="onlySelected = !onlySelected"
            >
              <Icon type="md-funnel" /> {{ onlySelected ? '显示全部' : '只看已选' }}
            </button>
          </div>
        </div>

        <div v-if="!hasMember" class="large-empty">
          <Icon type="ios-person-outline" />
          <h3>未选择会员</h3>
          <p>请点击右上角「选择会员」，选择后将在本页加载卡项</p>
          <button type="button" class="select-member-btn" @click="openMemberSelect">选择会员</button>
        </div>

        <div v-else class="project-groups">
          <section
            v-for="card in visibleProjectCards"
            :key="card.holder_id"
            :class="['project-group', { focused: activeCardId === card.holder_id }]"
          >
            <header>
              <div class="group-card-title">
                <span class="mini-card"><Icon type="ios-card" /></span>
                <div>
                  <strong>{{ card.card_name }}</strong>
                  <span>{{ card.card_no ? ('卡号 ' + card.card_no) : '暂无卡号' }}</span>
                </div>
              </div>
              <div class="group-summary">已选 {{ selectedCount(card) }} 项 / {{ selectedTimes(card) }} 次</div>
            </header>

            <div class="project-table-head">
              <span>项目</span><span>剩余/总次</span><span>本次次数</span><span>本次核销金额</span><span>服务对象</span><span>手艺人分配</span>
            </div>
            <div
              v-for="project in filteredProjects(card)"
              :key="project.cart_info_id"
              :class="['project-row', { checked: project.selected }]"
            >
              <div class="project-name-cell" @click="toggleProject(project)">
                <span :class="['check-box', { checked: project.selected }]">
                  <Icon v-if="project.selected" type="md-checkmark" />
                </span>
                <span class="project-avatar">{{ (project.product_name || '项').slice(0, 1) }}</span>
                <div>
                  <strong>{{ project.product_name }}</strong>
                  <small>{{ project.source_type || ('权益#' + project.cart_info_id) }}</small>
                </div>
              </div>
              <div class="times-cell"><b>{{ project.available_times }}</b> / {{ project.write_times }}</div>
              <div class="quantity-stepper">
                <button type="button" :disabled="!project.selected || project.qty <= 1" @click="changeQty(project, -1)">−</button>
                <span>{{ project.selected ? project.qty : 0 }}</span>
                <button type="button" :disabled="!project.selected || project.qty >= project.available_times" @click="changeQty(project, 1)">＋</button>
              </div>
              <div class="amount-cell">
                <b>¥{{ project.selected ? displayAmount(project) : 0 }}</b>
              </div>
              <div class="service-object">
                <button type="button" :class="{ active: project.serviceObject === '本人' }" @click="project.serviceObject = '本人'">本人</button>
                <button type="button" :class="{ active: project.serviceObject === '朋友' }" @click="project.serviceObject = '朋友'">朋友</button>
              </div>
              <button
                type="button"
                :class="['staff-entry', { disabled: !project.selected, assigned: project.selected && project.staffChoose.length }]"
                :title="staffEntryTitle(project)"
                @click="openStaffAllocation(card, project)"
              >
                <Icon type="ios-people-outline" />
                <span>{{ staffEntryLabel(project) }}</span>
              </button>
            </div>
          </section>
          <div v-if="!visibleProjectCards.length" class="large-empty">
            <Icon type="ios-folder-open-outline" />
            <h3>暂无项目</h3>
            <p>{{ onlySelected ? '当前没有已选项目，可关闭“只看已选”' : '该会员暂无可核销项目' }}</p>
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
        <section class="replacement-workspace">
          <div class="step-block">
            <div class="step-title"><span>1</span><div><h2>选择一张卡</h2><p>项目替换不能跨卡</p></div></div>
            <div class="replace-card-list">
              <button
                v-for="card in cards"
                :key="'rpl-' + card.holder_id"
                type="button"
                :class="{ active: replacementCardId === card.holder_id }"
                @click="chooseReplacementCard(card.holder_id)"
              >
                <span class="replace-card-icon"><Icon type="ios-card" /></span>
                <span>
                  <strong>{{ card.card_name }}</strong>
                  <small>{{ card.verify_code }} · 余 {{ remainingTimes(card) }} 次</small>
                </span>
                <Icon v-if="replacementCardId === card.holder_id" type="md-checkmark-circle" />
              </button>
            </div>
          </div>

          <div class="step-block">
            <div class="step-title">
              <span>2</span>
              <div>
                <h2>添加来源项目</h2>
                <p>同一项目可重复添加；每条默认 1 次，可单独改次数</p>
              </div>
              <b>{{ replacementSourceLines.length }} 条</b>
            </div>
            <div v-if="replaceLoading" class="flow-placeholder">正在加载可替换项目…</div>
            <template v-else>
              <div class="source-project-grid">
                <button
                  v-for="project in replaceSources"
                  :key="'src-add-' + project.cart_info_id"
                  type="button"
                  :disabled="!canAddReplacementSource(project)"
                  @click="addReplacementSource(project)"
                >
                  <span class="source-avatar">{{ (project.name || '项').slice(0, 1) }}</span>
                  <span class="source-meta">
                    <strong>{{ project.name }}</strong>
                    <small>剩余 {{ project.write_surplus_times }} 次 · 点击添加一条</small>
                  </span>
                  <span class="source-amount">单次约<b>¥{{ project.preview_amount || 0 }}</b></span>
                </button>
                <div v-if="!replaceSources.length" class="flow-placeholder" style="grid-column: 1 / -1;">该卡暂无可替换项目</div>
              </div>
              <div v-if="replacementSourceLines.length" class="source-line-list">
                <div class="source-line-head">
                  <span>已选来源（可逐条删除）</span>
                  <em>合计扣减 {{ replacementTotalTimes }} 次 · 约 ¥{{ replacementLocalAmount }}</em>
                </div>
                <div
                  v-for="(line, idx) in replacementSourceLines"
                  :key="line.key"
                  class="source-line-row"
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

          <div class="step-block">
            <div class="step-title"><span>3</span><div><h2>选择一个新项目</h2><p>仅展示当前门店已上架可用项目</p></div></div>
            <div class="target-search">
              <Icon type="ios-search" /><input v-model.trim="targetKeyword" placeholder="搜索新项目" />
            </div>
            <div class="target-grid">
              <button
                v-for="target in filteredTargets"
                :key="'tgt-' + target.product_id"
                type="button"
                :class="{ active: replacementTargetId === target.product_id }"
                @click="replacementTargetId = target.product_id"
              >
                <span class="target-avatar">{{ (target.name || '项').slice(0, 1) }}</span>
                <span>
                  <strong>{{ target.name }}</strong>
                  <small>{{ target.category || '项目' }}</small>
                </span>
                <Icon v-if="replacementTargetId === target.product_id" type="md-checkmark-circle" />
              </button>
              <div v-if="!filteredTargets.length" class="flow-placeholder" style="grid-column: 1 / -1;">
                {{ replaceTargets.length ? '没有匹配的新项目' : (targetEmptyTip || '当前没有可用的上架项目，请先上架项目后再进行替换') }}
              </div>
            </div>
          </div>
        </section>

        <aside class="replacement-summary">
          <div class="summary-title">
            <div class="summary-title-main">
              <Icon type="md-swap" />
              <div><h2>替换结果预览</h2><p>金额以服务端核对为准</p></div>
            </div>
            <button
              type="button"
              class="primary-btn replace-submit"
              :disabled="!canSubmitReplacement || submitting"
              @click="openReplacementConfirm"
            >
              {{ submitting ? '提交中…' : '确认项目替换' }}
            </button>
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
            <div v-else class="flow-placeholder">请添加来源项目</div>
            <div class="flow-arrow"><span></span><Icon type="md-arrow-down" /></div>
            <div class="flow-label">生成新项目</div>
            <div v-if="replacementTarget" class="new-project-preview">
              <span class="target-avatar">{{ (replacementTarget.name || '项').slice(0, 1) }}</span>
              <div><strong>{{ replacementTarget.name }}</strong><small>新增 1 次可用权益</small></div>
              <b>¥{{ replacementLocalAmount }}</b>
            </div>
            <div v-else class="flow-placeholder">请选择一个新项目</div>
          </div>
          <div class="replacement-total">
            <span>新项目核销金额</span><b>¥{{ replacementLocalAmount }}</b>
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

          <div v-if="replacementRecords.length" class="mock-records">
            <div class="record-head"><h3>近期替换记录</h3></div>
            <div v-for="record in replacementRecords" :key="record.id || record.replacement_no" class="record-item">
              <strong>{{ record.replacement_no }}</strong>
              <span>金额 ¥{{ record.target_amount || 0 }}</span>
            </div>
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
          <span><Icon :type="mode === 'batch' ? 'md-checkmark-circle-outline' : 'md-swap'" /></span>
          <div>
            <small>功能操作说明</small>
            <h2>{{ mode === 'batch' ? '项目核销怎么用？' : '项目替换是做什么的？' }}</h2>
          </div>
        </div>
        <template v-if="mode === 'batch'">
          <div class="instruction-intro">可选择一张或多张卡中的项目，一次核对并提交，不必再一张卡、一张卡重复操作。</div>
          <div class="instruction-steps">
            <div><b>1</b><span><strong>快速找到卡</strong><small>搜索卡名、卡号或项目，也可以按“即将到期、已选卡”筛选。</small></span></div>
            <div><b>2</b><span><strong>勾选本次项目</strong><small>可以跨卡选择，并分别调整次数、服务对象和手艺人。</small></span></div>
            <div><b>3</b><span><strong>核对后一次提交</strong><small>前台只生成 1 笔业务核销单，系统内部按卡保存扣减明细。</small></span></div>
          </div>
          <div class="plain-rule"><Icon type="ios-information-circle" /><span>金额只按整数计算；例如 1000 元分 3 次，依次为 333、333、334 元。</span></div>
        </template>
        <template v-else>
          <div class="instruction-intro">适合调整护理方案：在同一张卡内将来源项目替换成一个新项目；同一项目可以重复添加为多条来源。</div>
          <div class="instruction-steps">
            <div><b>1</b><span><strong>先选一张卡</strong><small>替换只能在同一张卡内完成，不能跨卡合并。</small></span></div>
            <div><b>2</b><span><strong>添加来源项目</strong><small>可重复点同一项目添加多条；每条默认 1 次，次数可单独修改。</small></span></div>
            <div><b>3</b><span><strong>选择上架新项目</strong><small>新项目新增 1 次，金额等于全部来源本次金额合计；仅可选当前门店已上架项目。</small></span></div>
          </div>
          <div class="plain-rule warning"><Icon type="ios-alert" /><span>项目替换不产生核销订单、不进入消费统计，只保留 1 条项目变动记录。</span></div>
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
      cards: [],
      cardFilter: 'available',
      cardKeyword: '',
      activeCardId: 0,
      onlySelected: false,
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
      const phone = String(this.memberInfo.phone || '');
      if (phone.length >= 7) {
        return `${phone.slice(0, 3)}****${phone.slice(-4)}`;
      }
      return phone || '—';
    },
    memberAvatarText() {
      return String(this.memberName).slice(0, 1) || '会';
    },
    memberLabel() {
      if (!this.hasMember) return '未选择会员';
      return `${this.memberName} · ${this.memberPhone}`;
    },
    filteredCards() {
      const keyword = String(this.cardKeyword || '').trim();
      const keywordLower = keyword.toLowerCase();
      return this.cards.filter((card) => {
        if (keyword) {
          // 完整 7 位卡号精确匹配；后 6 位/核销码不得冒充卡号
          if (/^[1-9]\d{6}$/.test(keyword)) {
            if (String(card.card_no || '') !== keyword) return false;
          } else {
            const hay = [
              card.card_name,
              card.name,
              card.card_no,
              card.no,
            ].join(' ').toLowerCase();
            if (!hay.includes(keywordLower)) return false;
          }
        }
        if (this.cardFilter === 'available') return this.remainingTimes(card) > 0;
        if (this.cardFilter === 'expiring') return card.expiring;
        if (this.cardFilter === 'selected') return this.selectedCount(card) > 0;
        return true;
      });
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
      card.projects = (raw.projects || []).map((p) => this.mapProject(p, card));
      return card;
    },
    maxQty(project) {
      return Math.max(0, Number(project.available_times != null ? project.available_times : project.remaining || 0));
    },
    async reloadOptions() {
      const uid = Number(this.activeUid || this.uid || 0);
      if (!uid) {
        return;
      }
      this.loadingOptions = true;
      try {
        const res = await writeoffBatchOptions({
          uid,
          keyword: '',
        });
        const data = (res && res.data) || {};
        this.user = data.user || null;
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
    filteredProjects(card) {
      return (card.projects || []).filter((project) => {
        if (this.onlySelected && !project.selected) return false;
        return true;
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
      return this.formatStaffLabels(project.staffChoose);
    },
    toggleProject(project) {
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
      if (!project.staffChoose || !project.staffChoose.length) return '手艺人';
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
          // 每行独立深拷贝，按目标行自身耗卡业绩分摊
          project.staffChoose = this.buildEqualStaffAllocation(
            staffChoose,
            this.projectPerformanceLineAmount(project)
          );
        });
      });
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
      const card = this.cards.find((c) => c.holder_id === this.activeCardId) || this.cards[0];
      this.$emit('legacy-action', {
        key: action.key,
        oid: card && card.oid,
        order_id: card && card.order_id,
        holder_id: card && card.holder_id,
        uid: this.activeUid,
      });
    },
    validateStaffBeforeSubmit() {
      const missing = [];
      this.cards.forEach((card) => {
        card.projects.forEach((project) => {
          if (!project.selected) return;
          if (!project.staffChoose || !project.staffChoose.length) {
            missing.push(`【${card.name}】${project.name}`);
          }
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
        this.$Message.error(`请先为以下项目选择手艺人：${missing.slice(0, 3).join('、')}${missing.length > 3 ? '…' : ''}`);
        return;
      }
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

* { box-sizing: border-box; }
button, input, textarea { font: inherit; }
button { border: 0; }

.writeoff-preview { min-width: 1180px; height: 100%; max-height: 100%; display: flex; flex-direction: column; overflow: hidden; color: @text; background: #f3f5f8; font-size: 14px; position: relative; }
.writeoff-preview.writeoff-workbench { min-width: 0; width: 100%; }
.preview-header { height: 72px; flex: 0 0 72px; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; background: #fff; border-bottom: 1px solid @line; box-shadow: 0 3px 12px rgba(22, 34, 51, .04); }
.brand { display: flex; align-items: center; gap: 12px; width: 320px; }
.back-btn { height: 36px; padding: 0 12px; border-radius: 9px; color: #606266; background: #f3f5f8; cursor: pointer; white-space: nowrap; }
.back-btn .ivu-icon { margin-right: 2px; }
.brand-title { font-size: 17px; font-weight: 600; }
.brand-subtitle { margin-top: 2px; color: @muted; font-size: 12px; }
.mode-tabs { display: flex; padding: 4px; border-radius: 12px; background: #f3f5f8; }
.mode-tabs button { min-width: 158px; height: 40px; border-radius: 9px; color: #606266; background: transparent; cursor: pointer; transition: .2s; }
.mode-tabs button .ivu-icon { margin-right: 5px; font-size: 17px; vertical-align: -1px; }
.mode-tabs button.active { color: @blue; background: #fff; font-weight: 600; box-shadow: 0 2px 9px rgba(27, 45, 70, .1); }
.header-right { min-width: 320px; display: flex; justify-content: flex-end; align-items: center; gap: 10px; }
.select-member-btn { height: 36px; padding: 0 14px; border-radius: 9px; color: #fff; background: @blue; cursor: pointer; white-space: nowrap; font-weight: 600; }
.select-member-btn .ivu-icon { margin-right: 4px; }
.select-member-btn--light { margin-top: 12px; color: @blue; background: #fff; border: 1px solid #9bcaff; }
.large-empty .select-member-btn { margin-top: 14px; }
.loading-mask { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: @muted; }
.loading-mask .spin { font-size: 28px; animation: wb-spin 1s linear infinite; }
@keyframes wb-spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }

.preview-main { flex: 1; min-height: 0; display: flex; padding: 18px; gap: 18px; overflow: hidden; }
.card-panel { width: 400px; flex: 0 0 400px; display: flex; flex-direction: column; min-height: 0; padding: 20px; border-radius: 18px; background: #fff; }
.member-card { display: flex; align-items: center; padding: 15px; border-radius: 13px; color: #fff; background: linear-gradient(135deg, #1677ff, #4fa1ff); box-shadow: 0 9px 20px rgba(24, 119, 255, .18); }
.empty-state--member { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 28px 12px; color: @muted; }
.avatar { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 50%; background: rgba(255,255,255,.22); border: 1px solid rgba(255,255,255,.45); font-weight: 600; }
.member-meta { flex: 1; display: flex; flex-direction: column; margin-left: 11px; }
.member-meta strong { font-size: 16px; }
.member-meta span { margin-top: 4px; opacity: .82; font-size: 12px; }
.member-count { display: flex; flex-direction: column; align-items: center; padding-left: 16px; border-left: 1px solid rgba(255,255,255,.28); }
.member-count b { font-size: 20px; }.member-count span { font-size: 11px; opacity: .8; }
.link-btn { padding: 4px; color: @blue; background: transparent; cursor: pointer; }
.card-search { margin: 18px 0 0; }
.card-search-placeholder { margin: 18px 0 0; height: 42px; display: flex; align-items: center; color: @muted; font-size: 13px; }
.search-box, .target-search { height: 42px; display: flex; align-items: center; padding: 0 13px; border: 1px solid #dfe3e8; border-radius: 10px; background: #fafbfc; }
.search-box:focus-within, .target-search:focus-within { border-color: @blue; background: #fff; box-shadow: 0 0 0 3px rgba(24,144,255,.08); }
.search-box .ivu-icon, .target-search .ivu-icon { color: #a3a9b1; font-size: 18px; }
.search-box input, .target-search input { flex: 1; min-width: 0; margin-left: 8px; border: 0; outline: 0; color: @text; background: transparent; }
.clear-icon { cursor: pointer; margin-left: 4px; color: #c0c4cc; }
.query-inline { height: 28px; padding: 0 10px; margin-left: 4px; border-radius: 7px; color: #fff; background: @blue; cursor: pointer; white-space: nowrap; font-size: 12px; }
.enter-key { opacity: .85; }
.filter-chips { display: flex; gap: 7px; margin: 12px 0; }
.filter-chips button { flex: 1; height: 31px; border-radius: 8px; color: #606266; background: #f4f6f8; cursor: pointer; font-size: 12px; }
.filter-chips button.active { color: @blue; background: #eaf4ff; font-weight: 600; }
.card-list { flex: 1; min-height: 0; overflow-y: auto; padding: 1px 2px 8px; }
.card-item { padding: 14px; margin-bottom: 9px; border: 1px solid @line; border-radius: 12px; background: #fff; cursor: pointer; transition: .18s; }
.card-item:hover { border-color: #7eb8ff; transform: translateY(-1px); }
.card-item.active {
  border-color: #0f6fe0;
  background: #e8f3ff;
  box-shadow: 0 0 0 2px rgba(15, 111, 224, .22);
}
.card-item.selected {
  border-color: #0b5ec2;
  background: linear-gradient(90deg, #d6ebff 0%, #eef6ff 55%, #fff 100%);
  box-shadow: 0 0 0 2px rgba(11, 94, 194, .18);
}
.card-item.active.selected {
  border-color: #084ea3;
  background: linear-gradient(90deg, #c5e2ff 0%, #e3f1ff 60%, #f7fbff 100%);
  box-shadow: 0 0 0 3px rgba(8, 78, 163, .28);
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

.project-panel { flex: 1; min-width: 0; min-height: 0; display: flex; flex-direction: column; border-radius: 18px; overflow: hidden; background: #fff; }
.project-toolbar { padding: 14px 24px 12px; display: flex; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 1px solid @line; flex: 0 0 auto; }
.toolbar-title-row { display: flex; align-items: baseline; flex-wrap: wrap; gap: 10px; min-width: 0; flex: 1; }
.page-kicker { margin-bottom: 6px; color: @blue; font-size: 12px; font-weight: 600; letter-spacing: 1px; }
.project-toolbar h1 { margin: 0; font-size: 23px; line-height: 1.25; white-space: nowrap; }
.toolbar-title-row p { margin: 0; color: @muted; font-size: 14px; line-height: 1.4; }
.toolbar-actions { display: flex; align-items: center; gap: 10px; flex: 0 0 auto; }.project-search { width: 220px; height: 40px; display: flex; align-items: center; padding: 0 12px; border: 1px solid #dfe3e8; border-radius: 10px; }.project-search input { flex: 1; min-width: 0; margin-left: 7px; border: 0; outline: 0; }.project-search .ivu-icon { color: @muted; }
.help-btn { height: 40px; padding: 0 13px; border: 1px solid #cde3fb; border-radius: 10px; color: #2676bd; background: #f4f9ff; cursor: pointer; white-space: nowrap; }.help-btn .ivu-icon { margin-right: 4px; font-size: 17px; vertical-align: -2px; }.help-btn:hover { border-color: #83bfff; background: #eaf5ff; }
.outline-btn, .reset-btn { height: 40px; padding: 0 15px; border: 1px solid #dfe3e8; border-radius: 10px; color: #606266; background: #fff; cursor: pointer; }.outline-btn.active { color: @blue; border-color: #9acbff; background: #f3f9ff; }
.project-groups { flex: 1; min-height: 0; padding: 10px 24px 8px; overflow-y: auto; background: #fbfcfe; }
.project-group { margin-bottom: 10px; border: 1px solid @line; border-radius: 13px; overflow: hidden; background: #fff; transition: .18s; }.project-group.focused { border-color: #0f6fe0; box-shadow: 0 3px 12px rgba(15,111,224,.14); }
.project-group > header { height: 48px; padding: 0 15px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #edf0f3; background: #fcfdff; }
.group-card-title { display: flex; align-items: center; }.mini-card { width: 31px; height: 31px; display: grid; place-items: center; border-radius: 8px; color: @blue; background: #eaf4ff; }.group-card-title > div { display: flex; flex-direction: column; margin-left: 10px; }.group-card-title span { margin-top: 3px; color: @muted; font-size: 11px; }.group-summary { color: @blue; font-size: 12px; font-weight: 600; }
.project-table-head, .project-row { display: grid; grid-template-columns: minmax(185px, 1.4fr) .52fr .72fr .75fr .66fr .72fr; align-items: center; gap: 9px; padding: 0 16px; }
.project-table-head { height: 34px; color: #9aa0a8; background: #fafbfc; font-size: 11px; }
.project-row { min-height: 60px; border-top: 1px solid #f0f1f3; transition: background .15s, box-shadow .15s; }
.project-row.checked {
  background: #e8f3ff;
  box-shadow: inset 3px 0 0 #0b5ec2;
}
.project-name-cell { display: flex; align-items: center; min-width: 0; cursor: pointer; }.check-box, .source-check { width: 18px; height: 18px; flex: 0 0 18px; display: grid; place-items: center; border: 1px solid #c9cdd3; border-radius: 5px; color: #fff; }.check-box.checked, .source-check.active { border-color: @blue; background: @blue; }.project-avatar, .source-avatar, .target-avatar { width: 36px; height: 36px; flex: 0 0 36px; display: grid; place-items: center; margin-left: 10px; border-radius: 9px; color: #3979ae; background: #e9f5ff; font-weight: 600; }.project-name-cell > div { min-width: 0; display: flex; flex-direction: column; margin-left: 10px; }.project-name-cell strong { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }.project-name-cell small { margin-top: 3px; color: @muted; font-size: 11px; }.times-cell b { color: @blue; font-size: 16px; }
.quantity-stepper { width: 104px; height: 32px; display: flex; align-items: center; border: 1px solid #dfe3e8; border-radius: 8px; overflow: hidden; }.quantity-stepper button { width: 31px; height: 100%; color: @blue; background: #f7faff; cursor: pointer; }.quantity-stepper button:disabled { color: #c7cbd1; cursor: not-allowed; }.quantity-stepper span { flex: 1; text-align: center; font-weight: 600; }
.amount-cell { display: flex; flex-direction: column; }.amount-cell b { color: #f05b36; font-size: 16px; }.service-object { display: flex; padding: 3px; border-radius: 8px; background: #f3f5f7; }.service-object button { flex: 1; padding: 5px 4px; border-radius: 6px; color: #777; background: transparent; cursor: pointer; font-size: 11px; }.service-object button.active { color: @blue; background: #fff; box-shadow: 0 1px 5px rgba(32,54,75,.1); }
.staff-entry { min-width: 0; height: 32px; padding: 0 8px; display: flex; align-items: center; justify-content: center; border: 1px solid #b9d9fa; border-radius: 8px; color: @blue; background: #f3f9ff; cursor: pointer; white-space: nowrap; font-size: 11px; overflow: hidden; }.staff-entry > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; }.staff-entry .ivu-icon { flex: 0 0 auto; margin-right: 4px; font-size: 16px; }.staff-entry:hover { border-color: #7ebcff; background: #eaf5ff; }.staff-entry.disabled { color: #a4abb3; border-color: #e1e4e8; background: #f7f8fa; cursor: not-allowed; }.staff-entry.assigned { border-color: #7ebcff; background: #eaf5ff; font-weight: 600; }
.large-empty { height: 100%; min-height: 260px; }.large-empty h3 { margin: 12px 0 4px; }.large-empty p { margin: 0; font-size: 12px; }
.batch-footer { flex: 0 0 auto; min-height: 82px; padding: 15px 24px; display: flex; align-items: center; justify-content: space-between; gap: 20px; border-top: 1px solid @line; box-shadow: 0 -6px 18px rgba(25,42,61,.04); background: #fff; z-index: 2; }.summary-pills { display: flex; align-items: center; gap: 18px; color: #676f78; }.summary-pills span { white-space: nowrap; }.summary-pills b { color: @text; font-size: 18px; }.summary-pills .amount { padding-left: 18px; border-left: 1px solid @line; }.summary-pills .amount b { color: #f05b36; font-size: 23px; }.footer-actions { display: flex; align-items: center; gap: 9px; }
.more-actions-wrap { position: relative; }.more-actions-btn { height: 42px; padding: 0 14px; border: 1px solid #dfe3e8; border-radius: 10px; color: #59636e; background: #fff; cursor: pointer; }.more-actions-btn > .ivu-icon:first-child { margin-right: 5px; }.more-actions-btn > .ivu-icon:last-child { margin-left: 5px; color: #9ba2aa; }.more-actions-menu { position: absolute; right: 0; bottom: 50px; z-index: 20; width: 320px; padding: 9px; border: 1px solid @line; border-radius: 13px; background: #fff; box-shadow: 0 14px 36px rgba(29,47,68,.17); }.menu-caption { padding: 5px 8px 8px; color: #9aa1a9; font-size: 10px; font-weight: 600; letter-spacing: .8px; }.more-actions-menu button { width: 100%; padding: 9px 10px; display: flex; align-items: center; border-radius: 9px; color: @text; background: #fff; text-align: left; cursor: pointer; }.more-actions-menu button:hover { background: #f4f8fc; }.more-actions-menu button.danger strong { color: #d95353; }.menu-icon { width: 32px; height: 32px; flex: 0 0 32px; display: grid; place-items: center; margin-right: 10px; border-radius: 8px; color: #3f83be; background: #edf6ff; font-size: 17px; }.more-actions-menu button > span:last-child { display: flex; flex-direction: column; }.more-actions-menu small { margin-top: 2px; color: @muted; font-size: 10px; }
.makeup-control { height: 42px; display: flex; align-items: center; padding: 0 11px; border: 1px solid #dfe3e8; border-radius: 10px; color: #69727c; background: #fff; }.makeup-control.active { border-color: #9bcaff; background: #f8fbff; }.makeup-switch { display: flex; align-items: center; cursor: pointer; white-space: nowrap; }.makeup-switch input { display: none; }.switch-track { width: 29px; height: 16px; position: relative; margin-right: 7px; border-radius: 10px; background: #c9ced4; transition: .2s; }.switch-track i { width: 12px; height: 12px; position: absolute; top: 2px; left: 2px; border-radius: 50%; background: #fff; transition: .2s; }.makeup-switch input:checked + .switch-track { background: @blue; }.makeup-switch input:checked + .switch-track i { left: 15px; }.makeup-switch .ivu-icon { margin-right: 3px; }.makeup-placeholder { margin-left: 8px; padding-left: 8px; border-left: 1px solid @line; color: #a3a8ae; font-size: 11px; }.makeup-date-input { width: 167px; margin-left: 8px; padding-left: 9px; border: 0; border-left: 1px solid @line; outline: 0; color: #4b5560; background: transparent; font-size: 11px; }
.primary-btn { height: 42px; padding: 0 22px; border-radius: 10px; color: #fff; background: @blue; box-shadow: 0 6px 14px rgba(24,144,255,.2); cursor: pointer; font-weight: 600; }.primary-btn:disabled { background: #b9c0c8; box-shadow: none; cursor: not-allowed; }.primary-btn .ivu-icon { margin-left: 5px; }

.replacement-page { flex: 1; min-height: 0; padding: 12px 24px 20px; overflow-y: auto; }
.replacement-layout { max-width: 1460px; min-height: 100%; margin: 0 auto; display: grid; grid-template-columns: minmax(700px, 1fr) 420px; gap: 18px; align-items: start; }.replacement-workspace, .replacement-summary { border-radius: 17px; background: #fff; }.replacement-workspace { padding: 18px 22px; }.replacement-summary { position: sticky; top: 0; padding: 18px 20px; }
.step-block + .step-block { margin-top: 26px; padding-top: 23px; border-top: 1px dashed #dfe3e8; }.step-title { display: flex; align-items: center; margin-bottom: 14px; }.step-title > span { width: 30px; height: 30px; display: grid; place-items: center; margin-right: 10px; border-radius: 50%; color: #fff; background: @blue; font-weight: 600; }.step-title > div { flex: 1; }.step-title h2 { margin: 0; font-size: 17px; }.step-title p { margin: 3px 0 0; color: @muted; font-size: 11px; }.step-title > b { color: @blue; }
.replace-card-list { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 9px; }.replace-card-list button { min-width: 0; padding: 11px; display: flex; align-items: center; text-align: left; border: 1px solid @line; border-radius: 11px; color: @text; background: #fff; cursor: pointer; }.replace-card-list button.active { border-color: @blue; background: #f5faff; box-shadow: 0 0 0 2px rgba(24,144,255,.07); }.replace-card-icon { width: 30px; height: 30px; flex: 0 0 30px; display: grid; place-items: center; border-radius: 8px; color: @blue; background: #eaf4ff; }.replace-card-list button > span:nth-child(2) { flex: 1; min-width: 0; display: flex; flex-direction: column; margin-left: 8px; }.replace-card-list strong { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 12px; }.replace-card-list small { margin-top: 3px; color: @muted; font-size: 9px; }.replace-card-list button > .ivu-icon { color: @blue; font-size: 17px; }
.source-project-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 9px; }.source-project-grid > button { padding: 12px; display: flex; align-items: center; border: 1px solid @line; border-radius: 11px; color: @text; background: #fff; text-align: left; cursor: pointer; }.source-project-grid > button.active { border-color: @blue; background: #f7fbff; }.source-project-grid > button:disabled { opacity: .5; cursor: not-allowed; }.source-avatar { margin-left: 9px; }.source-meta { flex: 1; min-width: 0; display: flex; flex-direction: column; margin-left: 10px; }.source-meta small { margin-top: 4px; color: @muted; font-size: 10px; }.source-amount { display: flex; flex-direction: column; align-items: flex-end; color: @muted; font-size: 10px; }.source-amount b { margin-top: 3px; color: #f05b36; font-size: 15px; }
.source-line-list { margin-top: 12px; border: 1px solid @line; border-radius: 12px; background: #fff; overflow: hidden; }
.source-line-head { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #f7f9fc; color: @muted; font-size: 12px; }
.source-line-head em { font-style: normal; color: @text; font-weight: 600; }
.source-line-row { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-top: 1px solid #eef1f5; }
.source-line-index { width: 22px; height: 22px; border-radius: 50%; background: #eaf4ff; color: @blue; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; }
.source-line-meta { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.source-line-meta small { color: @muted; font-size: 11px; margin-top: 2px; }
.source-line-remove {
  flex: 0 0 auto;
  height: 32px;
  padding: 0 12px;
  border: 1px solid #ffbbb0;
  border-radius: 8px;
  color: #d4380d;
  background: #fff7f0;
  cursor: pointer;
  white-space: nowrap;
  font-size: 12px;
  font-weight: 600;
}
.source-line-remove .ivu-icon { margin-right: 2px; font-size: 15px; vertical-align: -2px; }
.source-line-remove:hover { border-color: #ff9c8a; background: #ffece3; }
.target-search { width: 320px; margin-bottom: 11px; }.target-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 9px; }.target-grid > button { padding: 11px; display: flex; align-items: center; border: 1px solid @line; border-radius: 11px; color: @text; background: #fff; text-align: left; cursor: pointer; }.target-grid > button.active { border-color: @blue; background: #f5faff; }.target-avatar { margin-left: 0; color: #6d5bc4; background: #f0edff; }.target-grid button > span:nth-child(2) { flex: 1; min-width: 0; display: flex; flex-direction: column; margin-left: 9px; }.target-grid strong { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 12px; }.target-grid small { margin-top: 3px; color: @muted; font-size: 9px; }.target-grid .ivu-icon { color: @blue; font-size: 17px; }
.summary-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; }.summary-title-main { display: flex; align-items: center; min-width: 0; flex: 1; }.summary-title-main > .ivu-icon { width: 38px; height: 38px; flex: 0 0 38px; display: grid; place-items: center; margin-right: 10px; border-radius: 10px; color: #fff; background: #7667d8; font-size: 20px; }.summary-title h2 { margin: 0; font-size: 18px; }.summary-title p { margin: 3px 0 0; color: @muted; font-size: 11px; }
.flow-card { margin-top: 18px; padding: 15px; border: 1px solid @line; border-radius: 13px; background: #fbfcfe; }.flow-label { margin-bottom: 8px; color: #777f88; font-size: 11px; font-weight: 600; }.flow-items > div { display: grid; grid-template-columns: 1fr auto auto; gap: 8px; align-items: center; padding: 8px 9px; margin-top: 6px; border-radius: 8px; background: #fff; }.flow-items b { color: #e05252; }.flow-items em { color: #f05b36; font-style: normal; text-align: right; }.flow-source-remove { height: 26px; padding: 0 8px; border: 1px solid #ffbbb0; border-radius: 6px; color: #d4380d; background: #fff7f0; cursor: pointer; font-size: 11px; white-space: nowrap; }.flow-source-remove:hover { border-color: #ff9c8a; background: #ffece3; }.flow-placeholder { padding: 14px; border: 1px dashed #ccd2d9; border-radius: 8px; color: @muted; text-align: center; font-size: 11px; }.flow-arrow { height: 40px; position: relative; display: grid; place-items: center; color: #9aa1aa; }.flow-arrow span { position: absolute; top: 8px; bottom: 8px; width: 1px; background: #dce0e5; }.flow-arrow .ivu-icon { position: relative; padding: 2px; background: #fbfcfe; }.new-project-preview { display: flex; align-items: center; padding: 10px; border: 1px solid #d8d1ff; border-radius: 9px; background: #f8f7ff; }.new-project-preview > div { flex: 1; display: flex; flex-direction: column; margin-left: 9px; }.new-project-preview small { margin-top: 3px; color: @muted; font-size: 10px; }.new-project-preview > b { color: #6652c9; font-size: 17px; }
.replacement-total { display: grid; grid-template-columns: 1fr auto; gap: 4px; margin-top: 15px; padding: 14px; border-radius: 11px; background: #fff6ee; }.replacement-total b { color: @orange; font-size: 23px; }.replacement-total small { grid-column: 1 / 3; color: #a17a5c; font-size: 10px; }.no-order-note { display: flex; margin-top: 12px; padding: 11px; border-radius: 10px; color: #6d5b2e; background: #fff9e9; font-size: 11px; line-height: 1.55; }.no-order-note .ivu-icon { margin: 2px 7px 0 0; color: #f0a11c; font-size: 17px; }.no-order-note p { margin: 2px 0 0; }
.remark-field { display: block; margin-top: 14px; }.remark-field > span { display: block; margin-bottom: 6px; color: #656d76; font-size: 11px; }.remark-field textarea { width: 100%; height: 58px; padding: 9px 10px; resize: none; outline: none; border: 1px solid #dfe3e8; border-radius: 9px; }.remark-field textarea:focus { border-color: @blue; }.replace-submit { flex: 0 0 auto; height: 52px; min-width: 148px; padding: 0 18px; margin: 0; font-size: 16px; line-height: 1.2; white-space: nowrap; }
.mock-records { margin-top: 18px; padding-top: 15px; border-top: 1px solid @line; }.record-head { display: flex; justify-content: space-between; align-items: center; }.record-head h3 { margin: 0; font-size: 14px; }.record-item { display: flex; flex-direction: column; margin-top: 9px; padding: 9px; border-radius: 8px; background: #f6f8fa; }.record-item strong { font-size: 11px; }.record-item span { margin-top: 4px; color: @muted; font-size: 10px; }

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
.instructions-panel { padding: 4px 6px 7px; }.instructions-head { display: flex; align-items: center; padding-bottom: 17px; border-bottom: 1px solid @line; }.instructions-head > span { width: 45px; height: 45px; display: grid; place-items: center; margin-right: 12px; border-radius: 12px; color: #fff; background: linear-gradient(135deg, #1788ff, #66b2ff); font-size: 23px; }.instructions-head small { color: @blue; font-weight: 600; }.instructions-head h2 { margin: 3px 0 0; font-size: 20px; }.instruction-intro { margin: 16px 0 12px; padding: 12px 14px; border-radius: 10px; color: #496986; background: #f0f7ff; line-height: 1.6; }.instruction-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }.instruction-steps > div { padding: 14px; display: flex; border: 1px solid @line; border-radius: 11px; }.instruction-steps b { width: 26px; height: 26px; flex: 0 0 26px; display: grid; place-items: center; margin-right: 9px; border-radius: 50%; color: @blue; background: #eaf4ff; }.instruction-steps span { display: flex; flex-direction: column; }.instruction-steps small { margin-top: 5px; color: @muted; line-height: 1.55; }.plain-rule { margin-top: 12px; padding: 11px 13px; display: flex; border-radius: 10px; color: #506d87; background: #f5f9fd; }.plain-rule.warning { color: #765e2d; background: #fff8e7; }.plain-rule .ivu-icon { margin: 1px 7px 0 0; font-size: 18px; }.instruction-close { width: 100%; margin-top: 16px; }

@media (max-width: 1300px) {
  .card-panel { width: 340px; flex-basis: 340px; }
  .project-table-head, .project-row { grid-template-columns: minmax(165px, 1.25fr) .5fr .68fr .7fr .63fr .68fr; }
  .replacement-layout { grid-template-columns: minmax(650px, 1fr) 390px; }
}
</style>
