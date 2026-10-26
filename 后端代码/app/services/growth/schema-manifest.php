<?php
/** 由scripts/growth/build-schema-manifest.py生成；字段与唯一键的权威来源为database/upgrades迁移。 */
return json_decode(<<<'JSON'
{
  "growth_setting": {
    "columns": [
      "id",
      "enabled",
      "business_link_enabled",
      "templates_json",
      "updated_by",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_receipt": {
    "columns": [
      "id",
      "actor_key",
      "action",
      "request_key",
      "request_hash",
      "response_json",
      "created_at"
    ],
    "unique": {
      "uq_request": [
        "actor_key",
        "action",
        "request_key"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_event": {
    "columns": [
      "id",
      "event_type",
      "source_type",
      "source_id",
      "employee_id",
      "organization_id",
      "store_id",
      "actor_key",
      "business_date",
      "occurred_at",
      "settled_at",
      "recorded_at",
      "payload_json",
      "delivery_state"
    ],
    "unique": {
      "uq_event": [
        "event_type",
        "source_type",
        "source_id"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_content": {
    "columns": [
      "id",
      "kind",
      "title",
      "category",
      "description",
      "cover_asset_id",
      "owner_employee_id",
      "organization_id",
      "store_id",
      "created_by",
      "state",
      "draft_json",
      "current_publication_id",
      "created_at",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_publication": {
    "columns": [
      "id",
      "content_id",
      "title",
      "snapshot_json",
      "state",
      "confirmer_employee_id",
      "published_by",
      "created_at",
      "confirmed_at",
      "settled_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_asset": {
    "columns": [
      "id",
      "owner_key",
      "original_name",
      "format",
      "size_bytes",
      "storage_key",
      "preview_key",
      "state",
      "error_message",
      "page_count",
      "duration_seconds",
      "metadata_json",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_task": {
    "columns": [
      "id",
      "assignment_key",
      "employee_id",
      "employee_name",
      "organization_id",
      "store_id",
      "kind",
      "title",
      "publication_id",
      "source_type",
      "source_id",
      "plan_id",
      "stage_no",
      "required",
      "handler_employee_id",
      "starts_at",
      "due_at",
      "allow_late",
      "state",
      "conclusion",
      "rules_json",
      "result_type",
      "result_id",
      "assigned_by",
      "assigned_at",
      "started_at",
      "completed_at"
    ],
    "unique": {
      "uq_assignment": [
        "assignment_key",
        "employee_id"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_learning": {
    "columns": [
      "id",
      "task_id",
      "employee_id",
      "chapter_key",
      "position",
      "intervals_json",
      "confirmed",
      "session_key",
      "last_received_at",
      "updated_at"
    ],
    "unique": {
      "uq_chapter": [
        "task_id",
        "chapter_key"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_message": {
    "columns": [
      "id",
      "employee_id",
      "source_key",
      "title",
      "summary",
      "task_id",
      "created_at",
      "read_at"
    ],
    "unique": {
      "uq_message": [
        "employee_id",
        "source_key"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_task_action": {
    "columns": [
      "id",
      "task_id",
      "action",
      "reason",
      "before_json",
      "after_json",
      "actor_key",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_favorite": {
    "columns": [
      "employee_id",
      "content_id",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "employee_id",
        "content_id"
      ]
    }
  },
  "growth_learning_event": {
    "columns": [
      "id",
      "task_id",
      "chapter_key",
      "session_key",
      "event_key",
      "position",
      "received_at"
    ],
    "unique": {
      "uq_event": [
        "task_id",
        "chapter_key",
        "session_key",
        "event_key"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_map": {
    "columns": [
      "id",
      "title",
      "description",
      "sequential",
      "service_product_id",
      "draft_json",
      "release_id",
      "owner_employee_id",
      "created_by",
      "created_at",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_map_release": {
    "columns": [
      "id",
      "map_id",
      "snapshot_json",
      "published_by",
      "published_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_plan": {
    "columns": [
      "id",
      "title",
      "map_release_id",
      "sequential",
      "responsible_employee_id",
      "starts_at",
      "due_at",
      "state",
      "created_by",
      "owner_employee_id",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_plan_employee": {
    "columns": [
      "id",
      "plan_id",
      "employee_id",
      "mentor_employee_id",
      "state",
      "assigned_at",
      "withdrawn_at",
      "withdrawal_reason"
    ],
    "unique": {
      "uq_participant": [
        "plan_id",
        "employee_id"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_onboarding_rule": {
    "columns": [
      "id",
      "title",
      "enabled",
      "organization_id",
      "store_id",
      "position_id",
      "map_release_id",
      "relative_days",
      "responsible_employee_id",
      "owner_employee_id",
      "created_by",
      "created_at",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_onboarding_dispatch": {
    "columns": [
      "id",
      "rule_id",
      "employee_id",
      "source_event_key",
      "plan_id",
      "state",
      "failure_reason",
      "created_at"
    ],
    "unique": {
      "uq_dispatch": [
        "rule_id",
        "employee_id",
        "source_event_key"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_reminder": {
    "columns": [
      "id",
      "source_key",
      "employee_id",
      "task_id",
      "reminder_type",
      "due_at",
      "state",
      "sent_at"
    ],
    "unique": {
      "uq_reminder": [
        "source_key",
        "employee_id"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_question": {
    "columns": [
      "id",
      "type",
      "stem",
      "options_json",
      "answer_json",
      "basis",
      "explanation",
      "category",
      "score",
      "state",
      "owner_employee_id",
      "created_by",
      "created_at",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_paper": {
    "columns": [
      "id",
      "title",
      "draft_json",
      "created_by",
      "owner_employee_id",
      "release_id",
      "created_at",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_paper_release": {
    "columns": [
      "id",
      "paper_id",
      "title",
      "snapshot_json",
      "published_at",
      "published_by"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_exam": {
    "columns": [
      "id",
      "title",
      "kind",
      "paper_release_id",
      "state",
      "starts_at",
      "ends_at",
      "duration_seconds",
      "max_attempts",
      "pass_score",
      "grader_employee_id",
      "auto_publish",
      "show_analysis",
      "required_pass",
      "rules_json",
      "created_by",
      "owner_employee_id",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_attempt": {
    "columns": [
      "id",
      "task_id",
      "exam_id",
      "employee_id",
      "attempt_no",
      "previous_attempt_id",
      "state",
      "questions_json",
      "draft_answers_json",
      "final_answers_json",
      "grades_json",
      "answer_session_key",
      "started_at",
      "deadline_at",
      "saved_at",
      "submitted_at",
      "published_at",
      "score",
      "max_score",
      "passed"
    ],
    "unique": {
      "uq_attempt": [
        "task_id",
        "attempt_no"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_grade_adjustment": {
    "columns": [
      "id",
      "attempt_id",
      "previous_adjustment_id",
      "score",
      "passed",
      "grades_json",
      "reason",
      "actor_key",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_question_feedback": {
    "columns": [
      "id",
      "question_id",
      "employee_id",
      "description",
      "state",
      "resolution",
      "handler_employee_id",
      "created_at",
      "resolved_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_standard": {
    "columns": [
      "id",
      "title",
      "purpose",
      "release_id",
      "draft_json",
      "owner_employee_id",
      "created_by",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_standard_release": {
    "columns": [
      "id",
      "standard_id",
      "snapshot_json",
      "published_by",
      "published_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_capability": {
    "columns": [
      "id",
      "position_id",
      "title",
      "description",
      "standard_release_id",
      "evidence_required",
      "created_by",
      "owner_employee_id",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_activity": {
    "columns": [
      "id",
      "kind",
      "title",
      "description",
      "standard_release_id",
      "capability_id",
      "handler_employee_id",
      "evidence_required",
      "requires_confirmation",
      "required_submissions",
      "max_resubmissions",
      "due_at",
      "valid_days",
      "location",
      "event_at",
      "created_by",
      "owner_employee_id",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_submission": {
    "columns": [
      "id",
      "task_id",
      "employee_id",
      "attempt_no",
      "previous_submission_id",
      "snapshot_json",
      "state",
      "submitted_at",
      "reviewer_employee_id",
      "review_json",
      "reviewed_at"
    ],
    "unique": {
      "uq_attempt": [
        "task_id",
        "attempt_no"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_evaluation": {
    "columns": [
      "id",
      "task_id",
      "employee_id",
      "capability_id",
      "standard_release_id",
      "previous_evaluation_id",
      "preparation_json",
      "result_json",
      "conclusion",
      "score",
      "evaluator_employee_id",
      "occurred_at",
      "valid_until"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_coaching": {
    "columns": [
      "id",
      "task_id",
      "employee_id",
      "mentor_employee_id",
      "snapshot_json",
      "occurred_at",
      "recorded_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_attendance": {
    "columns": [
      "id",
      "task_id",
      "activity_id",
      "employee_id",
      "previous_attendance_id",
      "attended",
      "evidence_json",
      "confirmer_employee_id",
      "occurred_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_asset_reference": {
    "columns": [
      "id",
      "asset_id",
      "reference_type",
      "reference_id",
      "task_id",
      "created_at"
    ],
    "unique": {
      "uq_reference": [
        "asset_id",
        "reference_type",
        "reference_id"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_help_question": {
    "columns": [
      "id",
      "employee_id",
      "handler_employee_id",
      "task_id",
      "body",
      "state",
      "knowledge_content_id",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_help_reply": {
    "columns": [
      "id",
      "question_id",
      "employee_id",
      "body",
      "transfer_to",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_inspection": {
    "columns": [
      "id",
      "title",
      "store_id",
      "store_name",
      "executor_employee_id",
      "reviewer_employee_id",
      "standard_release_id",
      "due_at",
      "state",
      "result_json",
      "occurred_at",
      "submitted_at",
      "reviewed_at",
      "review_note",
      "created_by",
      "owner_employee_id",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_issue": {
    "columns": [
      "id",
      "inspection_id",
      "task_id",
      "item_key",
      "item_title",
      "store_id",
      "store_name",
      "description",
      "original_evidence_json",
      "responsible_employee_id",
      "reviewer_employee_id",
      "due_at",
      "state",
      "created_at",
      "closed_at",
      "closed_by"
    ],
    "unique": {
      "uq_problem": [
        "inspection_id",
        "item_key"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_rectification": {
    "columns": [
      "id",
      "issue_id",
      "employee_id",
      "previous_rectification_id",
      "snapshot_json",
      "submitted_at",
      "state"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_issue_review": {
    "columns": [
      "id",
      "issue_id",
      "rectification_id",
      "reviewer_employee_id",
      "passed",
      "note",
      "evidence_json",
      "reviewed_at"
    ],
    "unique": {
      "uq_review": [
        "rectification_id"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_issue_action": {
    "columns": [
      "id",
      "issue_id",
      "action",
      "reason",
      "before_json",
      "after_json",
      "actor_key",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_point_rule": {
    "columns": [
      "id",
      "title",
      "enabled",
      "event_type",
      "points",
      "require_passed",
      "first_only",
      "max_awards",
      "created_by",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_point_ledger": {
    "columns": [
      "id",
      "employee_id",
      "delta",
      "rule_id",
      "source_type",
      "source_id",
      "source_key",
      "source_group",
      "reversal_of",
      "reason",
      "evidence_json",
      "actor_key",
      "occurred_at"
    ],
    "unique": {
      "uq_source": [
        "employee_id",
        "source_key"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_level": {
    "columns": [
      "id",
      "title",
      "threshold",
      "enabled"
    ],
    "unique": {
      "uq_threshold": [
        "threshold"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_certificate_rule": {
    "columns": [
      "id",
      "title",
      "enabled",
      "event_type",
      "require_passed",
      "valid_days",
      "responsible_employee_id",
      "created_by",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_certificate": {
    "columns": [
      "id",
      "employee_id",
      "title",
      "certificate_number",
      "origin",
      "rule_id",
      "source_type",
      "source_id",
      "source_key",
      "state",
      "evidence_json",
      "issued_at",
      "expires_at",
      "created_by"
    ],
    "unique": {
      "uq_certificate_source": [
        "employee_id",
        "source_key"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_certificate_action": {
    "columns": [
      "id",
      "certificate_id",
      "action",
      "reason",
      "evidence_json",
      "old_expires_at",
      "new_expires_at",
      "source_event_id",
      "actor_key",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_delivery_attempt": {
    "columns": [
      "id",
      "event_id",
      "state",
      "error_message",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_appraisal": {
    "columns": [
      "id",
      "employee_id",
      "employee_name",
      "title",
      "period_start",
      "period_end",
      "standard_release_id",
      "handler_employee_id",
      "state",
      "created_by",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_appraisal_result": {
    "columns": [
      "id",
      "appraisal_id",
      "previous_result_id",
      "snapshot_json",
      "score",
      "passed",
      "reason",
      "handler_employee_id",
      "confirmed_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_objection": {
    "columns": [
      "id",
      "appraisal_id",
      "result_id",
      "employee_id",
      "handler_employee_id",
      "body",
      "evidence_json",
      "state",
      "resolution",
      "created_at",
      "resolved_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_onboarding_cursor": {
    "columns": [
      "rule_id",
      "last_job_id",
      "enabled_since",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "rule_id"
      ]
    }
  },
  "growth_export": {
    "columns": [
      "id",
      "actor_key",
      "query_json",
      "scope_json",
      "state",
      "row_count",
      "file_key",
      "error_message",
      "requested_at",
      "completed_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_review": {
    "columns": [
      "id",
      "title",
      "object_type",
      "object_id",
      "plan_id",
      "issue_id",
      "baseline_start",
      "baseline_end",
      "followup_start",
      "followup_end",
      "metrics_json",
      "snapshot_json",
      "background",
      "judgment",
      "next_steps",
      "followup_task_id",
      "owner_employee_id",
      "created_by",
      "created_at",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_inline_attempt": {
    "columns": [
      "id",
      "task_id",
      "chapter_key",
      "quiz_key",
      "employee_id",
      "question_id",
      "snapshot_json",
      "passed",
      "score",
      "submitted_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_task_dependency": {
    "columns": [
      "id",
      "parent_task_id",
      "child_task_id",
      "dependency_type",
      "require_passed"
    ],
    "unique": {
      "uq_child": [
        "parent_task_id",
        "child_task_id"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_result_reference": {
    "columns": [
      "id",
      "task_id",
      "source_task_id",
      "employee_id",
      "reason",
      "actor_key",
      "created_at"
    ],
    "unique": {
      "uq_task_reference": [
        "task_id"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_issue_training": {
    "columns": [
      "id",
      "issue_id",
      "task_id",
      "reason",
      "assigned_by",
      "assigned_at"
    ],
    "unique": {
      "uq_task": [
        "issue_id",
        "task_id"
      ],
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_level_state": {
    "columns": [
      "employee_id",
      "level_id",
      "level_title",
      "point_balance",
      "source_ledger_id",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "employee_id"
      ]
    }
  },
  "growth_level_event": {
    "columns": [
      "id",
      "employee_id",
      "previous_level_id",
      "previous_level_title",
      "level_id",
      "level_title",
      "point_balance",
      "source_ledger_id",
      "reason",
      "rules_json",
      "occurred_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_learning_sample": {
    "columns": [
      "event_id",
      "task_id",
      "chapter_key",
      "session_key",
      "sequence_no",
      "snapshot_json"
    ],
    "unique": {
      "uq_sequence": [
        "task_id",
        "chapter_key",
        "session_key",
        "sequence_no"
      ],
      "PRIMARY": [
        "event_id"
      ]
    }
  },
  "growth_onboarding_org_cursor": {
    "columns": [
      "rule_id",
      "last_org_employee_id",
      "enabled_since",
      "updated_at"
    ],
    "unique": {
      "PRIMARY": [
        "rule_id"
      ]
    }
  },
  "growth_onboarding_attempt": {
    "columns": [
      "id",
      "dispatch_id",
      "rule_id",
      "source_event_key",
      "state",
      "failure_reason",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_inspection_action": {
    "columns": [
      "id",
      "inspection_id",
      "action",
      "reason",
      "before_json",
      "after_json",
      "actor_key",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_task_job": {
    "columns": [
      "task_id",
      "job_id",
      "position_id",
      "position_name"
    ],
    "unique": {
      "PRIMARY": [
        "task_id",
        "job_id"
      ]
    }
  },
  "growth_setting_action": {
    "columns": [
      "id",
      "enabled_before",
      "enabled_after",
      "unfinished_tasks",
      "reason",
      "actor_key",
      "created_at"
    ],
    "unique": {
      "PRIMARY": [
        "id"
      ]
    }
  },
  "growth_help_scope": {
    "columns": [
      "question_id",
      "employee_id",
      "store_id",
      "organization_id"
    ],
    "unique": {
      "PRIMARY": [
        "question_id"
      ]
    }
  },
  "growth_message_target": {
    "columns": [
      "message_id",
      "target_type",
      "target_id"
    ],
    "unique": {
      "PRIMARY": [
        "message_id"
      ]
    }
  }
}
JSON
, true, 512, JSON_THROW_ON_ERROR);
