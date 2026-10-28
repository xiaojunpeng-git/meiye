/**
 * 调拨列表按可见的调出方生成查询范围。总部仓草稿还受 hq_location_id 限制，
 * 必须与选中的调出仓一致，不能沿用其他页面或仓库目录刷新留下的隐藏仓库。
 * 门店及“全部”保留原总部上下文；接收仓只缩小筛选，不替代调出仓上下文。
 * 返回值只描述查询条件，实际可读范围和操作能力仍由后端校验。
 */
export function platformTransferQueryScope(fromSelection, toSelection, headquartersLocationId) {
  const [fromType, fromId] = String(fromSelection || 'ALL:0').split(':')
  const [toType, toId] = String(toSelection || 'ALL:0').split(':')
  const query = { hq_location_id: Number(headquartersLocationId) || 0 }
  if (fromType === 'HQ' || fromType === 'STORE') {
    query.from_party_type = fromType
    query.from_party_id = fromType === 'HQ' ? 0 : Number(fromId) || 0
    if (fromType === 'HQ') {
      query.from_location_id = Number(fromId) || 0
      query.hq_location_id = query.from_location_id
    }
  }
  if (toType === 'HQ' || toType === 'STORE') {
    query.to_party_type = toType
    query.to_party_id = toType === 'HQ' ? 0 : Number(toId) || 0
    if (toType === 'HQ') query.to_location_id = Number(toId) || 0
  }
  return query
}
