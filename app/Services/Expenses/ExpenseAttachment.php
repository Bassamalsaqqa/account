<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use InvalidArgumentException;

final class ExpenseAttachment
{
    public function assertPath(int $companyId, string $path): void
    {
        if (! preg_match('#^expenses/'.preg_quote((string) $companyId, '#').'/[A-Za-z0-9_-]+\.(pdf|jpe?g|png)$#D', $path)) {
            throw new InvalidArgumentException('Expense attachment requires a private same-company path.');
        }
    }

    public function validate(int $companyId, ?string $path, ?string $name, ?string $mime, ?int $size): void
    {
        if ($path === null) {
            if ($name !== null || $mime !== null || $size !== null) {
                throw new InvalidArgumentException('Incomplete attachment metadata.');
            }

            return;
        }
        $this->assertPath($companyId, $path);
        if ($name === null || trim($name) === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1F]/', $name)
            || ! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true) || $size === null || $size < 1 || $size > 10485760) {
            throw new InvalidArgumentException('Invalid private expense attachment.');
        }
    }
}
