<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Documents\DocumentData;

final class PrintRenderer
{
    public function render(DocumentData $data): string
    {
        return app(DocumentRenderer::class)->html($data, printControls: true);
    }
}
