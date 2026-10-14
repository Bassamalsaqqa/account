<?php

declare(strict_types=1);

return [
    // New company-scoped read-only turn flow; staged rollout keeps the legacy launcher available.
    'turn_enabled' => (bool) env('AI_TURN_ENABLED', false),
    // All three switches are mandatory. Approval includes provider, costs and privacy terms.
    'enabled' => env('AI_ASSISTANT_ENABLED', false),
    'provider_approved' => env('AI_PROVIDER_APPROVED', false),
    'provider' => env('AI_ASSISTANT_PROVIDER', 'none'),
    'speech_enabled' => env('AI_SPEECH_ENABLED', false),
    'voice_auto_submit_enabled' => (bool) env('AI_VOICE_AUTO_SUBMIT_ENABLED', false),
    'voice_auto_submit_quiet_ms' => (int) env('AI_VOICE_AUTO_SUBMIT_QUIET_MS', 1000),
    'voice_auto_submit_grace_ms' => (int) env('AI_VOICE_AUTO_SUBMIT_GRACE_MS', 1250),
    'questions' => ['enabled' => env('AI_QUESTIONS_ENABLED', false)],
    'disabled_company_ids' => array_filter(explode(',', (string) env('AI_DISABLED_COMPANY_IDS', ''))),
    'max_text_characters' => 4000,
    'max_lines' => 20,
    'max_input_tokens' => 6000,
    'max_output_tokens' => 4000,
    'proposal_ttl_seconds' => 600,
    'timeout_seconds' => 20,
    'minimum_confidence' => 85,
    'max_audio_seconds' => 60,
    'daily_company_requests' => 100,
    'daily_user_requests' => 25,
    // Integer USD micro-units, operational limits only; no money/ledger calculations.
    // Zero cost ceilings fail closed until an approved provider's limits are configured.
    'request_cost_ceiling_micros' => (int) env('AI_REQUEST_COST_CEILING_MICROS', 0),
    'daily_company_cost_ceiling_micros' => (int) env('AI_DAILY_COMPANY_COST_CEILING_MICROS', 0),
    'monthly_company_cost_ceiling_micros' => (int) env('AI_MONTHLY_COMPANY_COST_CEILING_MICROS', 0),
    'daily_user_cost_ceiling_micros' => (int) env('AI_DAILY_USER_COST_CEILING_MICROS', 0),
    'monthly_user_cost_ceiling_micros' => (int) env('AI_MONTHLY_USER_COST_CEILING_MICROS', 0),
];
