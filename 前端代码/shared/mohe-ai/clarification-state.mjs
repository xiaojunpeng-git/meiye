// Guidance is a server-owned, versioned control-plane state, not a new chat.
export const GUIDANCE_SCHEMA = 'mohe-clarification-v2';
const positive = value => Number.isSafeInteger(value) && value > 0;
const text = value => typeof value === 'string' && value.length > 0;
const invalid = () => { throw new Error('引导信息不完整，请重新确认任务状态。'); };

export function validateClarification(value, requiredSchema) {
  if (!value || !text(value.id) || !Array.isArray(value.fields) || !value.fields.length) invalid();
  const modern = value.schema_version === GUIDANCE_SCHEMA;
  if ((requiredSchema === GUIDANCE_SCHEMA && !modern) || (value.schema_version && !modern)) invalid();
  if (modern && (!positive(value.step_revision) || !positive(value.intent_revision) || !positive(value.round_no)
    || ![3, 4, 5].includes(value.max_clarification_rounds) || value.round_no > value.max_clarification_rounds
    || !Array.isArray(value.confirmed_summary))) invalid();
  const validateFields = fields => {
    const keys = new Set();
    fields.forEach(field => {
      if (!field || !text(field.key) || ['__proto__', 'prototype', 'constructor'].includes(field.key) || keys.has(field.key) || !text(field.label)) invalid();
      keys.add(field.key);
      if (field.type === 'date') return;
      if ((field.type && !['select', 'single_choice'].includes(field.type)) || !Array.isArray(field.options) || !field.options.length) invalid();
      const options = new Set();
      field.options.forEach(option => { if (!option || !text(option.label) || !text(option.value) || options.has(option.value)) invalid(); options.add(option.value); });
    });
    // One semantic question: a single choice OR the two endpoints of a period.
    if (modern && !(fields.length === 1 || (fields.length === 2 && fields.every(field => field.type === 'date')))) invalid();
  };
  validateFields(value.fields);
  if (modern) {
    if (value.confirmed_summary.some(item => !item || !text(item.label) || typeof item.value !== 'string')) invalid();
    if (value.revisable_steps != null && (!Array.isArray(value.revisable_steps) || value.revisable_steps.length > 5)) invalid();
    const ids = new Set();
    (value.revisable_steps || []).forEach(step => {
      if (!step || !text(step.id) || ids.has(step.id) || !text(step.question) || !Array.isArray(step.fields) || !step.fields.length || !step.choices || typeof step.choices !== 'object') invalid();
      ids.add(step.id); validateFields(step.fields);
    });
  }
  return value;
}

export function clarificationKey(value) {
  return JSON.stringify([value.id, value.schema_version || 'legacy', value.step_revision || 0, value.intent_revision || 0]);
}

export function clarificationSubmission(value, choices, submissionId, reviseId = null) {
  const payload = { clarification_id: value.id, choices: { ...choices } };
  if (value.schema_version === GUIDANCE_SCHEMA) {
    if (!text(submissionId)) invalid();
    Object.assign(payload, { schema_version: GUIDANCE_SCHEMA, step_revision: value.step_revision,
      intent_revision: value.intent_revision, client_submission_id: submissionId });
    if (reviseId !== null) {
      if (!(value.revisable_steps || []).some(step => step.id === reviseId)) invalid();
      payload.revise_clarification_id = reviseId;
    }
  } else if (reviseId !== null) invalid();
  return payload;
}
