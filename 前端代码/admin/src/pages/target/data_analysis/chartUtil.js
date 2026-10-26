export function generateMockTrendSeries(total, count = 5) {
  const t = Number(total || 0);
  if (!t || count <= 0) return Array(count).fill(0);
  const avg = t / count;
  const factors = [0.9, 1.05, 1.1, 0.95, 1.0];
  return factors.slice(0, count).map((f, i) => Math.round(avg * (factors[i] || 1)));
}

export function isTargetAchieved(rate) {
  return Number(rate || 0) >= 100;
}

export function getTargetAchieveResultText(rate) {
  const r = Number(rate || 0);
  if (r > 100) return '超额达标';
  if (r >= 100) return '达标';
  return '未达标';
}

export function buildTrendChartPaths(current, compare, width = 800, height = 320) {
  const padding = { top: 40, right: 40, bottom: 50, left: 60 };
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
    points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`).join(' ');
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
      value: Math.round((maxValue / 4) * i)
    }))
  };
}
