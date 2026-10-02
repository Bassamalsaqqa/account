<?php

declare(strict_types=1);

namespace App\Domain\Sales\Documents;

/** Explicit public/print contract. No Eloquent objects, internal identifiers, costs or ledger fields. */
final readonly class DocumentData
{
    /** @param array<string, string|null> $company
     * @param  array<string, string|null>  $customer
     * @param  array<string, string|null>  $document
     * @param  list<array<string, string|null>>  $lines
     * @param  array<string, mixed>|null  $statement
     */
    public function __construct(
        public string $type,
        public string $locale,
        public array $company,
        public array $customer,
        public array $document,
        public array $lines = [],
        public ?array $statement = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type, 'document_locale' => $this->locale, 'company' => $this->company,
            'customer' => $this->customer, 'document' => $this->document, 'lines' => $this->lines, 'statement' => $this->statement];
    }
}
