<?php

declare(strict_types=1);

namespace App\Support;

final class InvoiceConfirmation
{
    /** Optional due dates need no second confirmation; real credit/stock warnings do.
     * @param  array<string, mixed>  $preview
     */
    public static function canConfirmDirectly(array $preview): bool
    {
        if (($preview['can_execute'] ?? false) !== true || empty($preview['confirmation_token']) || ! empty($preview['blockers'])) {
            return false;
        }
        foreach ($preview['warnings'] ?? [] as $warning) {
            if (! is_array($warning) || ($warning['code'] ?? null) !== 'no_due_date') {
                return false;
            }
        }

        return true;
    }
}
