/** 系统上线年份（年份筛选下限，不含此前选项） */
export const TARGET_YEAR_MIN = 2026;
/** 相对当前年可往后选的年数 */
export const TARGET_YEAR_FORWARD = 3;

/** 可选年份列表：2026 ~ 今年+3 */
export function getTargetYearRange() {
	const current = new Date().getFullYear();
	const min = TARGET_YEAR_MIN;
	const max = current + TARGET_YEAR_FORWARD;
	const years = [];
	for (let y = min; y <= max; y++) {
		years.push(y);
	}
	return { min, max, current, years };
}

/** @param {boolean} withSuffix 是否带「年」后缀（ActionSheet 用） */
export function getTargetYearOptions(withSuffix = false) {
	const { years } = getTargetYearRange();
	return years.map((y) => (withSuffix ? `${y}年` : String(y)));
}

/** 将年份限制在可选范围内 */
export function clampTargetYear(year) {
	const { min, max, current } = getTargetYearRange();
	const n = parseInt(year, 10);
	if (!n || Number.isNaN(n)) return current;
	if (n < min) return min;
	if (n > max) return max;
	return n;
}

/** 设置目标页指标图标（对齐 mb_target_setting.html） */
export const METRIC_SETTING_STYLE = {
	revenue: { bg: '#F5F3FF', color: '#8B5CF6', uniType: 'wallet-filled', iconClass: 'revenue' },
	consume: { bg: '#EFF6FF', color: '#3B82F6', uniType: 'compose', iconClass: 'consumption' },
	new_customer: { bg: '#F0FDF4', color: '#22C55E', uniType: 'personadd-filled', iconClass: 'new' },
	old_customer: { bg: '#FEF3C7', color: '#F59E0B', uniType: 'person-filled', iconClass: 'old' },
	service: { bg: '#FDF2F8', color: '#EC4899', uniType: 'star-filled', iconClass: 'service' },
	point: { bg: '#F0FDFA', color: '#14B8A6', uniType: 'person', iconClass: 'appoint' },
	book: { bg: '#EEF2FF', color: '#6366F1', uniType: 'calendar-filled', iconClass: 'booking' },
	count: { bg: '#FFF7ED', color: '#F97316', uniType: 'cart-filled', iconClass: 'goods' },
};

export function getMetricSettingStyle(metricKey) {
	return METRIC_SETTING_STYLE[metricKey] || METRIC_SETTING_STYLE.revenue;
}

/** 设置页指标行附加样式字段（小程序 :class 不支持方法调用） */
export function withMetricSettingStyle(row) {
	const style = getMetricSettingStyle(row.metric_key);
	return {
		...row,
		settingIconClass: style.iconClass,
		settingUniType: style.uniType,
		settingIconColor: style.color,
	};
}

/** 月份滚轮展示文案 */
export const MONTH_PICKER_LABELS = [
	'1月(01日-31日)',
	'2月(01日-28日)',
	'3月(01日-31日)',
	'4月(01日-30日)',
	'5月(01日-31日)',
	'6月(01日-30日)',
	'7月(01日-31日)',
	'8月(01日-31日)',
	'9月(01日-30日)',
	'10月(01日-31日)',
	'11月(01日-30日)',
	'12月(01日-31日)',
];

/** 指标展示样式 */
export const METRIC_ICON = {
	revenue: { bg: '#8B5CF6', bar: 'progress-purple' },
	consume: { bg: '#3B82F6', bar: 'progress-blue' },
	new_customer: { bg: '#22C55E', bar: 'progress-green' },
	old_customer: { bg: '#F59E0B', bar: 'progress-yellow' },
	service: { bg: '#EC4899', bar: 'progress-pink' },
	point: { bg: '#14B8A6', bar: 'progress-teal' },
	book: { bg: '#6366F1', bar: 'progress-indigo' },
	count: { bg: '#F97316', bar: 'progress-orange' },
};

export function formatMetricValue(val, unit) {
	const n = Number(val || 0);
	if (unit === '元') {
		return '¥' + n.toLocaleString('zh-CN', { maximumFractionDigits: 2 });
	}
	return n.toLocaleString('zh-CN', { maximumFractionDigits: 2 }) + (unit || '');
}

export function rateClass(rate) {
	return isTargetAchieved(rate) ? 'high' : 'low';
}

/** 达成率是否达标（>= 100%） */
export function isTargetAchieved(rate) {
	return Number(rate || 0) >= 100;
}

/** 目标达成结果文案 */
export function getTargetAchieveResultText(rate) {
	const r = Number(rate || 0);
	if (r > 100) return '超额达标';
	if (r >= 100) return '达标';
	return '未达标';
}

/** 目标达成结果颜色 */
export function getTargetAchieveResultColor(rate) {
	return isTargetAchieved(rate) ? '#10B981' : '#EF4444';
}

/** 月度达成进度条色阶 */
export function getTargetAchieveProgressClass(rate) {
	return isTargetAchieved(rate) ? 'bar-high' : 'bar-low';
}

/** 月度达成率色阶 */
export function getTargetAchieveRateClass(rate) {
	return isTargetAchieved(rate) ? 'high' : 'low';
}

/** 每月完成情况展示字段（分析页/周期详情共用） */
export function mapMonthlyAchieveItem(item) {
	const rate = Number(item.rate || 0);
	const status = item.status || 'pending';
	const isFuture = status === 'not_started';
	const isPast = status === 'completed';
	const isCurrent = status === 'pending';
	let statusClass = 'not-started';
	let statusText = item.status_text || '未开始';
	const showResult = !isFuture;
	const progressWidth = isFuture ? 0 : Math.min(rate, 100);
	let progressClass = 'bar-low';
	let rateClassName = 'low';
	let resultText = '';
	let resultColor = '#999';
	let completedClass = false;
	if (isPast) {
		statusClass = 'completed';
	} else if (isCurrent) {
		statusClass = 'pending';
	}
	if (!isFuture) {
		rateClassName = getTargetAchieveRateClass(rate);
		progressClass = getTargetAchieveProgressClass(rate);
		resultText = getTargetAchieveResultText(rate);
		resultColor = getTargetAchieveResultColor(rate);
		completedClass = isTargetAchieved(rate);
	}
	return {
		month: item.month || item.label,
		target: Number(item.target ?? item.target_value ?? 0),
		actualTarget: Number(item.actual_target ?? item.target ?? 0),
		completed: Number(item.completed ?? item.completed_value ?? 0),
		rate,
		statusClass,
		statusText,
		showResult,
		progressWidth,
		progressClass,
		rateClass: rateClassName,
		resultText,
		resultColor,
		completedClass,
	};
}

/** 分析页达成率色阶（对齐 mb_target_analysis.html） */
export function analysisRateTier(rate) {
	return getTargetAchieveRateClass(rate);
}

/** 分析页数值简写 */
export function formatAnalysisNumber(num) {
	const n = Number(num || 0);
	if (Math.abs(n) >= 10000) {
		return (n / 10000).toFixed(1) + '万';
	}
	return n.toLocaleString('zh-CN', { maximumFractionDigits: 0 });
}

/** 生成趋势图模拟序列（总和接近 total） */
export function generateMockTrendSeries(total, count = 5) {
	const t = Number(total || 0);
	if (!t || count <= 0) return Array(count).fill(0);
	const avg = t / count;
	const factors = [0.9, 1.05, 1.1, 0.95, 1.0];
	return factors.slice(0, count).map((f, i) => Math.round(avg * (factors[i] || 1)));
}

/** 构建 SVG 折线路径 */
export function buildTrendChartPaths(current, compare, width = 360, height = 200) {
	const padding = { top: 20, right: 20, bottom: 40, left: 50 };
	const chartWidth = width - padding.left - padding.right;
	const chartHeight = height - padding.top - padding.bottom;
	const all = [...(current || []), ...(compare || [])];
	const maxValue = Math.max(...all, 1) * 1.1;
	const toPoint = (data) =>
		(data || []).map((value, index) => {
			const x =
				padding.left +
				(index / Math.max((data.length || 1) - 1, 1)) * chartWidth;
			const y =
				padding.top + chartHeight - (Number(value) / maxValue) * chartHeight;
			return { x, y };
		});
	const pathFrom = (points) =>
		points
			.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`)
			.join(' ');
	const currentPts = toPoint(current);
	const comparePts = toPoint(compare);
	return {
		width,
		height,
		padding,
		maxValue,
		currentPath: pathFrom(currentPts),
		comparePath: pathFrom(comparePts),
		currentPts,
		comparePts,
		yLabels: [0, 1, 2, 3, 4].map((i) => ({
			y: padding.top + chartHeight - (i / 4) * chartHeight,
			value: Math.round((maxValue / 4) * i),
		})),
	};
}

/** canvas 绘制趋势对比图（小程序不支持 SVG） */
export function drawTrendChartCanvas(ctx, chartData, monthLabels = []) {
	if (!ctx || !chartData) return;
	const { width, height, padding, currentPts, comparePts, yLabels } = chartData;
	const chartHeight = height - padding.top - padding.bottom;
	const chartWidth = width - padding.left - padding.right;

	ctx.clearRect(0, 0, width, height);
	ctx.setFillStyle('#fafafa');
	ctx.fillRect(0, 0, width, height);

	ctx.setStrokeStyle('#f0f0f0');
	ctx.setLineWidth(1);
	for (let i = 0; i <= 4; i++) {
		const y = padding.top + (i / 4) * chartHeight;
		ctx.beginPath();
		ctx.moveTo(padding.left, y);
		ctx.lineTo(width - padding.right, y);
		ctx.stroke();
	}

	ctx.setFillStyle('#999');
	ctx.setFontSize(10);
	ctx.setTextAlign('right');
	(yLabels || []).forEach((yl) => {
		ctx.fillText(String(yl.value), padding.left - 10, yl.y + 4);
	});

	ctx.setTextAlign('center');
	ctx.setFontSize(11);
	(monthLabels || []).forEach((label, index) => {
		const x =
			padding.left +
			(index / Math.max(monthLabels.length - 1, 1)) * chartWidth;
		ctx.fillText(String(label), x, height - 15);
	});

	const drawLine = (points, color, lineWidth, dashed) => {
		if (!points || !points.length) return;
		ctx.setStrokeStyle(color);
		ctx.setLineWidth(lineWidth);
		if (dashed && ctx.setLineDash) {
			ctx.setLineDash([5, 5], 0);
		} else if (ctx.setLineDash) {
			ctx.setLineDash([], 0);
		}
		ctx.beginPath();
		points.forEach((p, i) => {
			if (i === 0) ctx.moveTo(p.x, p.y);
			else ctx.lineTo(p.x, p.y);
		});
		ctx.stroke();
		if (ctx.setLineDash) ctx.setLineDash([], 0);

		points.forEach((p) => {
			ctx.setFillStyle(color);
			ctx.beginPath();
			ctx.arc(p.x, p.y, 4, 0, 2 * Math.PI);
			ctx.fill();
			ctx.setStrokeStyle('#fff');
			ctx.setLineWidth(2);
			ctx.stroke();
		});
	};

	drawLine(comparePts, '#10B981', 2, true);
	drawLine(currentPts, '#8B5CF6', 3, false);
}

/** 排行接口 data 解析（兼容分页对象、完整响应与旧版数组） */
export function parseRankingResponse(res) {
	const data =
		res && typeof res === 'object' && res.data !== undefined && res.status !== undefined
			? res.data
			: res;
	if (data && Array.isArray(data.list)) {
		return {
			list: data.list,
			count: Number(data.count || 0),
			page: Number(data.page || 1),
		};
	}
	if (Array.isArray(data)) {
		return { list: data, count: data.length, page: 1 };
	}
	return { list: [], count: 0, page: 1 };
}

/** 员工排行达成率色阶 */
export function employeeRateTier(rate) {
	return isTargetAchieved(rate) ? 'excellent' : 'warning';
}

/** 目标进行状态 */
export function getTargetProgressStatus(detail) {
	const now = Math.floor(Date.now() / 1000);
	let start = Number(detail?.period_start || 0);
	let end = Number(detail?.period_end || 0);
	if (!start || !end) {
		const year = Number(detail?.year || 0);
		const month = Number(detail?.month || 0);
		if (year && month) {
			start = Math.floor(new Date(year, month - 1, 1).getTime() / 1000);
			end = Math.floor(new Date(year, month, 0, 23, 59, 59).getTime() / 1000);
		}
	}
	if (!start || !end) {
		return { text: '进行中', badgeClass: 'pending' };
	}
	if (now < start) return { text: '未开始', badgeClass: 'pending' };
	if (now > end) return { text: '已结束', badgeClass: 'done' };
	return { text: '进行中', badgeClass: 'active' };
}

/** 目标时间范围展示 */
export function formatTargetTimeRange(detail) {
	const start = Number(detail?.period_start || 0);
	const end = Number(detail?.period_end || 0);
	const fmt = (ts) => {
		const d = new Date(ts * 1000);
		return `${d.getFullYear()}.${d.getMonth() + 1}.${d.getDate()}`;
	};
	if (start && end) {
		return `${fmt(start)} - ${fmt(end)}`;
	}
	if (detail?.period_label) return detail.period_label;
	return '';
}

/** 排名变化（无历史数据时用稳定伪随机） */
export function getRankChangeText(staffId, targetId, rank) {
	const seed = Math.abs(
		parseInt(
			String(staffId)
				.split('')
				.reduce((s, c) => s + c.charCodeAt(0), targetId * 17 + rank * 3),
			10
		) % 5
	);
	if (seed <= 1) return { text: '持平', cls: '' };
	if (seed <= 3) return { text: `↑${seed - 1 || 1}`, cls: 'up' };
	return { text: `↓${seed - 3}`, cls: 'down' };
}

export function formatProductMetricName(p) {
	if (p.display_name) return p.display_name;
	const pn = p.product_name
		|| (p.product_items && p.product_items[0] && p.product_items[0].product_name);
	if (pn) return pn.endsWith('目标') ? pn : `${pn}目标`;
	return '品项目标';
}

export function mergeMetrics(card) {
	const list = [];
	(card.metrics || []).forEach((m, index) => {
		const row = { ...m, is_product: false };
		row.listKey = buildMetricListKey(row, index);
		row.rankKey = buildRankMetricKey(row);
		list.push(row);
	});
	(card.products || []).forEach((p, index) => {
		const row = {
			...p,
			metric_name: formatProductMetricName(p),
			is_product: true,
		};
		row.listKey = buildMetricListKey(row, index);
		row.rankKey = buildRankMetricKey(row);
		list.push(row);
	});
	return list;
}

export function buildMetricListKey(m, index = 0) {
	if (m.is_product) {
		return `p-${m.product_id || index}-${m.metric_key || ''}`;
	}
	return `m-${m.metric_key || index}`;
}

export function buildRankMetricKey(m) {
	if (!m) return '';
	if (m.is_product) {
		return (
			m.allocate_ref_key ||
			`product_${m.product_id || 0}_${m.metric_key || 'revenue'}`
		);
	}
	return m.metric_key || '';
}

/** 接口 catch 错误文案（兼容 request 返回字符串或 { msg }） */
export function getApiErrorMessage(err, fallback = '操作失败') {
	if (!err) return fallback;
	if (typeof err === 'string') return err;
	return err.msg || err.message || err.mag || fallback;
}

/** 目标值仅允许非负整数 */
export function toTargetInt(val) {
	if (val === '' || val === null || val === undefined) return 0;
	if (typeof val === 'number') {
		return Number.isFinite(val) ? Math.max(0, Math.round(val)) : 0;
	}
	const s = String(val).trim().replace(/,/g, '');
	if (!s) return 0;
	// API decimal(12,2) 如 "121.00" 须按数值解析，不可去掉小数点（否则会变成 12100）
	const n = parseFloat(s.replace(/[^\d.]/g, ''));
	if (Number.isNaN(n)) return 0;
	return Math.max(0, Math.round(n));
}

export function formatTargetInt(val) {
	return String(toTargetInt(val));
}

/** 输入框过滤为整数字符 */
export function sanitizeTargetInputValue(raw) {
	return String(raw || '').replace(/[^\d]/g, '');
}

/** 平均分配整数，余数给最后一人（如 100/3 → 33,33,34） */
export function averageAllocateInt(total, count) {
	const t = toTargetInt(total);
	if (!count || count <= 0) return [];
	const avg = Math.floor(t / count);
	const list = [];
	let allocated = 0;
	for (let i = 0; i < count; i++) {
		if (i === count - 1) {
			list.push(t - allocated);
		} else {
			list.push(avg);
			allocated += avg;
		}
	}
	return list;
}

/** 目标分配草稿（未保存目标时） */
export const TARGET_ALLOCATE_DRAFT_KEY = 'target_allocate_draft';

export function getAllocateDraft() {
	try {
		return uni.getStorageSync(TARGET_ALLOCATE_DRAFT_KEY) || {};
	} catch (e) {
		return {};
	}
}

export function setAllocateDraft(refKey, items) {
	const draft = getAllocateDraft();
	if (items && items.length) {
		draft[refKey] = items;
	} else {
		delete draft[refKey];
	}
	uni.setStorageSync(TARGET_ALLOCATE_DRAFT_KEY, draft);
}

export function clearAllocateDraft() {
	uni.removeStorageSync(TARGET_ALLOCATE_DRAFT_KEY);
}

/** 提交用：过滤有效分配行 */
export function normalizeAllocateItemsForSave(allocations) {
	return (allocations || [])
		.map((a) => ({
			...a,
			allocate_value: toTargetInt(a.allocate_value),
		}))
		.filter((a) => a.staff_id && a.allocate_value > 0);
}

/** 分配总额 */
export function sumAllocateValues(allocations) {
	return normalizeAllocateItemsForSave(allocations).reduce(
		(s, a) => s + a.allocate_value,
		0
	);
}

/**
 * 分配与目标不一致时的提示；无分配或一致时返回空字符串
 */
export function getAllocationMismatchMessage(allocations, targetValue, label = '') {
	const items = normalizeAllocateItemsForSave(allocations);
	if (!items.length) return '';
	const sum = items.reduce((s, a) => s + a.allocate_value, 0);
	const target = toTargetInt(targetValue);
	if (sum === target) return '';
	const prefix = label ? `${label}：` : '';
	return `${prefix}分配总额(${sum})须等于目标值(${target})，请重新分配`;
}

/**
 * 提交时取分配数据：优先表单；仅新建目标且表单为空时才读本地草稿
 */
export function resolveAllocationsForSubmit(formAllocations, draftRefKey, draft, useDraft) {
	if (formAllocations && formAllocations.length) {
		return formAllocations;
	}
	if (useDraft && draftRefKey && draft && draft[draftRefKey]) {
		return draft[draftRefKey];
	}
	return [];
}

/** 商品指标分配 ref_key（与后端一致） */
export function buildProductAllocateRefKey(productId, metricKey, index = 0) {
	const key = metricKey || 'revenue';
	if (productId > 0) {
		return `product_${productId}_${key}`;
	}
	return `product_idx_${index}_${key}`;
}

/** 目标对象：本次选择（页面间传递） */
export const TARGET_OBJECT_SELECT_KEY = 'target_object_select';
/** 目标对象：区域管理员上次选择（持久缓存） */
export const TARGET_OBJECT_CACHE_KEY = 'target_object_cache';
const TARGET_REGION_SUMMARY_NAME = '区域汇总';

/** 展示目标对象名称 */
export function getObjectDisplayName(item) {
	if (!item) return '';
	return String(item.name || item.label || '');
}

/** 是否为具体门店（目标设置页可默认填入） */
export function isSpecificStoreObject(item) {
	return !!item && Number(item.object_type) === 1 && Number(item.id) > 0;
}

/** 从树节点收集门店 ID（区域=下属全部门店，门店=自身） */
export function collectStoreIdsFromNode(node) {
	if (!node) return [];
	const type = Number(node.object_type);
	const id = Number(node.id) || 0;
	if (type === 1 && id > 0) {
		return [id];
	}
	if (type === 2) {
		const ids = [];
		(node.children || []).forEach((child) => {
			collectStoreIdsFromNode(child).forEach((sid) => ids.push(sid));
		});
		return ids;
	}
	return [];
}

/** 多选门店 → 列表/统计筛选项 */
export function getObjectFilterFromStoreIds(storeIds, storeNameMap = {}) {
	const ids = (storeIds || [])
		.map((id) => Number(id))
		.filter((id) => id > 0);
	let objectLabel = '请选择';
	if (ids.length === 1) {
		objectLabel = storeNameMap[ids[0]] || `门店${ids[0]}`;
	} else if (ids.length > 1) {
		objectLabel = `已选${ids.length}家门店`;
	}
	return {
		objectLabel,
		filterStoreIds: ids,
		filterStoreId: ids.length === 1 ? String(ids[0]) : '',
		filterManageRegionId: '',
		filterObjectType: ids.length === 1 ? '1' : '',
		isLastLevelStore: ids.length === 1,
	};
}

export function isMultiStoreSelection(item) {
	if (!item) return false;
	if (item.mode === 'multiple') return true;
	return Array.isArray(item.store_ids);
}

export function getObjectFilterFromItem(item) {
	if (!item) {
		return {
			objectLabel: '',
			filterStoreId: '',
			filterStoreIds: [],
			filterManageRegionId: '',
			filterObjectType: '',
			isLastLevelStore: false,
		};
	}
	if (isMultiStoreSelection(item)) {
		return getObjectFilterFromStoreIds(item.store_ids || []);
	}
	const objectType = Number(item.object_type);
	const id = item.id != null ? Number(item.id) : 0;
	return {
		objectLabel: getObjectDisplayName(item),
		filterStoreId: objectType === 1 && id > 0 ? String(id) : '',
		filterStoreIds: objectType === 1 && id > 0 ? [id] : [],
		filterManageRegionId: objectType === 2 && id > 0 ? String(id) : '',
		filterObjectType: objectType > 0 ? String(objectType) : '',
		isLastLevelStore: objectType === 1 && id > 0,
	};
}

/** 门店筛选参数（统计/列表接口） */
export function buildStoreFilterApiParams(filter = {}) {
	const params = {};
	const storeIds = filter.filterStoreIds || [];
	if (storeIds.length > 1) {
		params.store_ids = storeIds.join(',');
	} else if (storeIds.length === 1) {
		params.store_id = storeIds[0];
	} else if (filter.filterStoreId) {
		params.store_id = filter.filterStoreId;
	}
	if (filter.filterManageRegionId) {
		params.manage_region_id = filter.filterManageRegionId;
	}
	if (filter.filterObjectType) {
		params.object_type = filter.filterObjectType;
	}
	return params;
}

export function buildStoreNameMapFromTree(tree) {
	const map = {};
	const walk = (nodes) => {
		(nodes || []).forEach((n) => {
			if (Number(n.object_type) === 1 && Number(n.id) > 0) {
				map[Number(n.id)] = n.name || '';
			}
			walk(n.children);
		});
	};
	walk(tree);
	return map;
}

/** 解析目标对象树接口响应 */
export function parseTargetObjectTreeResponse(res) {
	if (!res) return [];
	if (Array.isArray(res)) return res;
	if (Array.isArray(res.data)) return res.data;
	if (res.data && typeof res.data === 'object') {
		if (Array.isArray(res.data.data)) return res.data.data;
		if (Array.isArray(res.data.list)) return res.data.list;
	}
	if (Array.isArray(res.list)) return res.list;
	return [];
}

/** 树节点拍平（用于匹配缓存） */
export function flattenTargetObjectTree(tree) {
	const list = [];
	const walk = (nodes) => {
		(nodes || []).forEach((n) => {
			if (!n) return;
			const children = n.children;
			list.push({
				id: n.id,
				name: n.name,
				object_type: n.object_type,
				label: n.label,
				desc: n.desc,
			});
			walk(children);
		});
	};
	walk(tree);
	return list;
}

export function findObjectInTree(tree, cache) {
	if (!cache || !tree?.length) return null;
	return findObjectInOptions(flattenTargetObjectTree(tree), cache);
}

/** 按关键词过滤目标对象树 */
export function filterObjectTreeByKeyword(tree, keyword) {
	const k = (keyword || '').trim();
	if (!k) return tree || [];
	const match = (name) => String(name || '').indexOf(k) >= 0;
	const filterNodes = (nodes) => {
		const out = [];
		(nodes || []).forEach((n) => {
			const children = filterNodes(n.children);
			if (match(n.name) || children.length) {
				out.push({ ...n, children });
			}
		});
		return out;
	};
	return filterNodes(tree);
}

export function saveTargetObjectCache(item) {
	if (item) {
		uni.setStorageSync(TARGET_OBJECT_CACHE_KEY, item);
	}
}

export function loadTargetObjectCache() {
	try {
		return uni.getStorageSync(TARGET_OBJECT_CACHE_KEY) || null;
	} catch (e) {
		return null;
	}
}

export function consumeTargetObjectSelect() {
	try {
		const sel = uni.getStorageSync(TARGET_OBJECT_SELECT_KEY);
		if (sel) {
			uni.removeStorageSync(TARGET_OBJECT_SELECT_KEY);
			return sel;
		}
	} catch (e) {
		/* ignore */
	}
	return null;
}

/** 根据门店选项接口推断默认选中项 */
export function resolveDefaultObjectFromOptions(options) {
	const list = options || [];
	if (!list.length) return null;
	const region = list.find((o) => Number(o.object_type) === 2 && Number(o.id) > 0);
	if (region) return region;
	const stores = list.filter((o) => Number(o.object_type) === 1 && Number(o.id) > 0);
	if (stores.length === 1) return stores[0];
	if (stores.length) return stores[0];
	const regionSummary = list.find((o) => Number(o.object_type) === 2);
	if (regionSummary) return regionSummary;
	return list[0];
}

export function findObjectInOptions(options, cache) {
	if (!cache || !options?.length) return null;
	return (
		options.find(
			(o) =>
				String(o.id) === String(cache.id) &&
				String(o.object_type) === String(cache.object_type)
		) || null
	);
}

/**
 * 初始化目标筛选对象：URL 参数 > 本次选择 > 缓存 > 接口默认
 */
export async function initTargetObjectFilter({ getOptions, applyFilter, urlOverride }) {
	if (urlOverride) {
		const item = {
			id: Number(urlOverride.id) || 0,
			name: urlOverride.name || '',
			object_type: Number(urlOverride.object_type) || 1,
		};
		saveTargetObjectCache(item);
		applyFilter(getObjectFilterFromItem(item));
		return;
	}

	const fromSelect = consumeTargetObjectSelect();
	if (fromSelect) {
		saveTargetObjectCache(fromSelect);
		if (isMultiStoreSelection(fromSelect)) {
			const selectIds = (fromSelect.store_ids || [])
				.map((id) => Number(id))
				.filter((id) => id > 0);
			if (selectIds.length) {
				const filter = getObjectFilterFromStoreIds(
					selectIds,
					fromSelect._storeNameMap || {}
				);
				applyFilter(filter);
				return;
			}
		} else {
			applyFilter(getObjectFilterFromItem(fromSelect));
			return;
		}
	}

	let options = [];
	try {
		const res = await getOptions();
		options = parseTargetObjectTreeResponse(res);
	} catch (e) {
		const cached = loadTargetObjectCache();
		if (cached) {
			if (isMultiStoreSelection(cached)) {
				applyFilter(getObjectFilterFromStoreIds(cached.store_ids || []));
			} else {
				applyFilter(getObjectFilterFromItem(cached));
			}
		}
		return;
	}

	const tree = Array.isArray(options) && options[0]?.children !== undefined ? options : [];
	const flat = tree.length ? flattenTargetObjectTree(tree) : options;

	const cached = loadTargetObjectCache();
	if (cached) {
		if (isMultiStoreSelection(cached)) {
			const cachedIds = (cached.store_ids || [])
				.map((id) => Number(id))
				.filter((id) => id > 0);
			if (cachedIds.length) {
				const nameMap = buildStoreNameMapFromTree(tree);
				applyFilter(getObjectFilterFromStoreIds(cachedIds, nameMap));
				return;
			}
		}
		const matched = findObjectInOptions(flat, cached) || findObjectInTree(tree, cached);
		if (matched) {
			applyFilter(getObjectFilterFromItem(matched));
			return;
		}
	}

	const def = resolveDefaultObjectFromOptions(flat);
	if (def) {
		applyFilter(getObjectFilterFromItem(def));
		saveTargetObjectCache(def);
	}
}

/** 目标模块 H5/小程序统一返回上一页 */
export function targetNavigateBack() {
	// #ifdef H5
	if (typeof window !== 'undefined' && window.history && window.history.length > 1) {
		window.history.back();
		return;
	}
	// #endif
	const pages = getCurrentPages();
	if (pages.length > 1) {
		uni.navigateBack();
	}
}

/** 目标模块导航栏：小程序用原生导航；H5 用 page-nav-bar 自定义标题栏 */
export function applyTargetNativeNavBar(title) {
	if (title) {
		uni.setNavigationBarTitle({ title: String(title) });
		// #ifdef H5
		if (typeof document !== 'undefined') {
			document.title = String(title);
		}
		// #endif
	}
	// #ifndef H5
	uni.setNavigationBarColor({
		frontColor: '#ffffff',
		backgroundColor: '#8B5CF6',
		animation: { duration: 0, timingFunc: 'easeIn' },
	});
	// #endif
}
