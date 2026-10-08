<?php

declare(strict_types=1);

namespace App\Services\Phase7;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;

trait HasCanonicalPhase7History
{
    public function delete()
    {
        throw new ImmutableRecordException('Canonical financial history cannot be deleted.');
    }

    public function save(array $options = [])
    {
        app(Phase7EventScope::class)->consumeRecord($this);

        return parent::save($options);
    }
}
