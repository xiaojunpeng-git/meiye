#!/usr/bin/env node
/** 更正请求必须比较已保存来源，不能把列表的“—”占位符提交为旧值。 */
import assert from 'node:assert/strict'
import { orderAttributionExpectedValue } from '../../../前端代码/cashier-v3/src/services/orderAttributionExpectedValue.js'

const empty = { sourceExpectedValue: '', businessSourcePrimaryId: 0, businessSourceSecondaryId: 0 }
assert.equal(orderAttributionExpectedValue(empty, 'source', '—'), '')
assert.equal(orderAttributionExpectedValue(empty, 'business_date', '2026-10-03'), '2026-10-03')
assert.equal(orderAttributionExpectedValue({ businessSourcePrimaryId: 0, businessSourceSecondaryId: 0 }, 'source', '—'), '')
assert.equal(orderAttributionExpectedValue({ businessSourcePrimaryId: 1, businessSourceSecondaryId: 0 }, 'source', '—'), '—')
assert.equal(orderAttributionExpectedValue({ sourceExpectedValue: '旧来源', businessSourcePrimaryId: 1 }, 'source', '新显示名'), '旧来源')
assert.equal(orderAttributionExpectedValue({ businessSourcePrimaryId: 1 }, 'source', '旧来源'), '旧来源')
console.log('empty and non-empty source comparison values passed')
