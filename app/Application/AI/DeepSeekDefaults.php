<?php

declare(strict_types=1);

namespace App\Application\AI;

final class DeepSeekDefaults
{
    public const string PROVIDER = 'deepseek';

    public const string MODEL = 'deepseek-flash';

    public const string REGION = 'global';

    public const string CAPABILITY = 'text_structured';

    public const string CREDENTIAL_SOURCE = 'company_byok';

    public const string OPERATOR = 'Hangzhou DeepSeek Artificial Intelligence Co., Ltd.';

    public const string PRIVACY_URL = 'https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html';

    public const string RETENTION = 'Variable retention in China as described in published privacy policy; no zero-retention guarantee.';

    public const string PRICING_REFERENCE = 'https://api-docs.deepseek.com/quick_start/pricing/';

    public const string PRICING_CHECKED_AT = '2026-10-12';

    public const int INPUT_MICROS_PER_MILLION = 300000;   // USD 0.30 / M tokens (peak/cache-miss)

    public const int OUTPUT_MICROS_PER_MILLION = 1200000; // USD 1.20 / M tokens

    public const int AUDIO_MICROS_PER_MINUTE = 0;         // DeepSeek does not provide audio models

    /**
     * @return array{operator: string, retention: string, policy_url: string}
     */
    public static function privacy(): array
    {
        return [
            'operator' => self::OPERATOR,
            'retention' => self::RETENTION,
            'policy_url' => self::PRIVACY_URL,
        ];
    }

    /**
     * @return array{reference: string, checked_at: string, input_micros_per_million: int, output_micros_per_million: int, audio_micros_per_minute: int}
     */
    public static function pricing(): array
    {
        return [
            'reference' => self::PRICING_REFERENCE,
            'checked_at' => self::PRICING_CHECKED_AT,
            'input_micros_per_million' => self::INPUT_MICROS_PER_MILLION,
            'output_micros_per_million' => self::OUTPUT_MICROS_PER_MILLION,
            'audio_micros_per_minute' => self::AUDIO_MICROS_PER_MINUTE,
        ];
    }

    /**
     * Positive policy defaults constrained by positive platform ceilings.
     * A disabled/zero platform ceiling still fails closed in AiRequestBudget;
     * these defaults never activate or increase installation limits.
     *
     * @return array<string, int>
     */
    public static function safeLimits(): array
    {
        $platformLimits = [
            'request_micros' => (int) config('ai_assistant.request_cost_ceiling_micros', 0),
            'daily_company_micros' => (int) config('ai_assistant.daily_company_cost_ceiling_micros', 0),
            'monthly_company_micros' => (int) config('ai_assistant.monthly_company_cost_ceiling_micros', 0),
            'daily_user_micros' => (int) config('ai_assistant.daily_user_cost_ceiling_micros', 0),
            'monthly_user_micros' => (int) config('ai_assistant.monthly_user_cost_ceiling_micros', 0),
            'daily_company_requests' => (int) config('ai_assistant.daily_company_requests', 100),
            'daily_user_requests' => (int) config('ai_assistant.daily_user_requests', 25),
        ];

        // Conservative baseline defaults: USD 0.01/req, USD 0.05 daily co, USD 0.50 monthly co, USD 0.02 daily user, USD 0.20 monthly user.
        $defaults = [
            'request_micros' => 10000,
            'daily_company_micros' => 50000,
            'monthly_company_micros' => 500000,
            'daily_user_micros' => 20000,
            'monthly_user_micros' => 200000,
            'daily_company_requests' => 10,
            'daily_user_requests' => 5,
        ];

        $resolved = [];
        foreach ($defaults as $key => $defaultVal) {
            $ceiling = $platformLimits[$key];
            if ($ceiling > 0) {
                $resolved[$key] = min($defaultVal, $ceiling);
            } else {
                $resolved[$key] = $defaultVal;
            }
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    public static function disclosure(): array
    {
        return [
            'operator' => self::OPERATOR,
            'privacy_url' => self::PRIVACY_URL,
            'retention' => self::RETENTION,
            'pricing' => [
                'reference' => self::PRICING_REFERENCE,
                'checked_at' => self::PRICING_CHECKED_AT,
                'input_micros_per_million' => self::INPUT_MICROS_PER_MILLION,
                'output_micros_per_million' => self::OUTPUT_MICROS_PER_MILLION,
                'audio_micros_per_minute' => self::AUDIO_MICROS_PER_MINUTE,
                'input_display' => '0.30',
                'output_display' => '1.20',
            ],
            'conservative_caps' => [
                'request_usd' => '0.01',
                'daily_company_usd' => '0.05',
                'monthly_company_usd' => '0.50',
            ],
            'billed_test_notice' => 'Connection test performs an authenticated API call to DeepSeek and incurs token usage against your account.',
        ];
    }
}
