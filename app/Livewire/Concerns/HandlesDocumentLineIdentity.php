<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Support\Str;

trait HandlesDocumentLineIdentity
{
    /**
     * Ensure every document line and nested purchase lot has a unique, non-persistent UI identity key.
     */
    protected function ensureLineIdentities(): void
    {
        $seenRowKeys = [];

        foreach ($this->lines as $index => $line) {
            $key = $this->normalizeUiIdentityKey($line['row_key'] ?? null);
            if ($key === null || isset($seenRowKeys[$key])) {
                $key = $this->newUiIdentityKey();
            }
            $seenRowKeys[$key] = true;
            $this->lines[$index]['row_key'] = $key;

            /** @var mixed $rawLots */
            $rawLots = $line['lots'] ?? null;
            if (is_array($rawLots)) {
                $this->lines[$index]['lots'] = $this->ensureLotListIdentities($rawLots);
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $lots
     * @return list<array<string, mixed>>
     */
    protected function ensureLotListIdentities(array $lots): array
    {
        $seenLotKeys = [];
        $normalized = [];

        foreach (array_values($lots) as $lot) {
            if (! is_array($lot)) {
                continue;
            }

            $lotKey = $this->normalizeUiIdentityKey($lot['lot_key'] ?? null);
            if ($lotKey === null || isset($seenLotKeys[$lotKey])) {
                $lotKey = $this->newUiIdentityKey();
            }
            $seenLotKeys[$lotKey] = true;
            $lot['lot_key'] = $lotKey;
            $normalized[] = $lot;
        }

        return $normalized;
    }

    /**
     * Remove UI-only lot identity keys before persistence, validation payloads, or canonical digests.
     *
     * @param  array<int|string, mixed>  $lots
     * @return list<array<string, mixed>>
     */
    protected function stripUiKeysFromLots(array $lots): array
    {
        $clean = [];

        foreach (array_values($lots) as $lot) {
            if (! is_array($lot)) {
                continue;
            }
            unset($lot['lot_key']);
            $clean[] = $lot;
        }

        return $clean;
    }

    protected function lineRowKey(int $index): string
    {
        if (! isset($this->lines[$index])) {
            return $this->newUiIdentityKey();
        }

        $existing = $this->normalizeUiIdentityKey($this->lines[$index]['row_key'] ?? null);
        if ($existing !== null) {
            return $existing;
        }

        $generated = $this->newUiIdentityKey();
        $this->lines[$index]['row_key'] = $generated;

        return $generated;
    }

    /**
     * Preserve an existing row's UI identity when resetting or replacing the product in place.
     *
     * @param  array<string, mixed>  $replacement
     * @return array<string, mixed>
     */
    protected function preserveLineRowKey(int $index, array $replacement): array
    {
        $replacement['row_key'] = $this->lineRowKey($index);

        return $replacement;
    }

    /**
     * Resolve a line index from either a legacy integer offset or an immutable string row_key.
     * String keys match strictly by row_key and never coerce numeric strings into positional offsets.
     */
    protected function resolveLineIndex(int|string $lineRef): ?int
    {
        if (is_int($lineRef)) {
            return isset($this->lines[$lineRef]) ? $lineRef : null;
        }

        $normalized = $this->normalizeUiIdentityKey($lineRef);
        if ($normalized === null) {
            return null;
        }

        foreach ($this->lines as $index => $line) {
            if (($line['row_key'] ?? null) === $normalized) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * Resolve a nested lot index from either an integer offset or a string lot_key.
     */
    protected function resolveLotIndex(int $lineIndex, int|string $lotRef): ?int
    {
        if (! isset($this->lines[$lineIndex])) {
            return null;
        }

        /** @var mixed $lots */
        $lots = $this->lines[$lineIndex]['lots'] ?? null;
        if (! is_array($lots)) {
            return null;
        }

        if (is_int($lotRef)) {
            return isset($lots[$lotRef]) ? $lotRef : null;
        }

        $normalized = $this->normalizeUiIdentityKey($lotRef);
        if ($normalized === null) {
            return null;
        }

        foreach ($lots as $lotIndex => $lot) {
            if (is_array($lot) && ($lot['lot_key'] ?? null) === $normalized) {
                return (int) $lotIndex;
            }
        }

        return null;
    }

    /**
     * Reindex integer-keyed row UI state maps after removing a line at $removedIndex.
     *
     * @param  array<int|string, mixed>  $state
     * @return array<int, mixed>
     */
    protected function reindexRemovedLineMap(array $state, int $removedIndex): array
    {
        $reindexed = [];

        foreach ($state as $key => $value) {
            $intKey = (int) $key;
            if ($intKey === $removedIndex) {
                continue;
            }
            $newKey = $intKey > $removedIndex ? $intKey - 1 : $intKey;
            $reindexed[$newKey] = $value;
        }

        return $reindexed;
    }

    /**
     * Preserve UI row keys across whole-array replacements (e.g., AI form drafting) without leaking UI keys to AI payloads.
     *
     * @param  list<array<string, mixed>>  $previousLines
     * @param  list<array<string, mixed>>  $nextLines
     * @return list<array<string, mixed>>
     */
    protected function preserveRowKeysAcrossReplacement(array $previousLines, array $nextLines): array
    {
        $usedPrev = [];
        $assigned = [];

        // Pass 1: exact persisted row identity (public_id or id)
        foreach ($nextLines as $nextIdx => $nextLine) {
            foreach ($previousLines as $prevIdx => $prevLine) {
                if (isset($usedPrev[$prevIdx])) {
                    continue;
                }
                $samePublicId = isset($nextLine['public_id'], $prevLine['public_id'])
                    && $nextLine['public_id'] !== ''
                    && $nextLine['public_id'] === $prevLine['public_id'];
                $sameId = isset($nextLine['id'], $prevLine['id'])
                    && (int) $nextLine['id'] > 0
                    && (int) $nextLine['id'] === (int) $prevLine['id'];
                if ($samePublicId || $sameId) {
                    $key = $this->normalizeUiIdentityKey($prevLine['row_key'] ?? null);
                    if ($key !== null) {
                        $usedPrev[$prevIdx] = true;
                        $nextLine['row_key'] = $key;
                        $assigned[$nextIdx] = true;
                        $nextLines[$nextIdx] = $nextLine;
                        break;
                    }
                }
            }
        }

        // Pass 2: same slot if product matches or both slots are draft rows edited in place
        foreach ($nextLines as $nextIdx => $nextLine) {
            if (isset($assigned[$nextIdx]) || isset($usedPrev[$nextIdx])) {
                continue;
            }
            $prevLine = $previousLines[$nextIdx] ?? null;
            if (! is_array($prevLine)) {
                continue;
            }
            $sameProduct = ($prevLine['product_id'] ?? null) !== null
                && (int) $prevLine['product_id'] === (int) ($nextLine['product_id'] ?? 0);
            $sameEmptySlot = ($prevLine['product_id'] ?? null) === null && ($nextLine['product_id'] ?? null) === null;
            $filledFirstEmptyRow = ($prevLine['product_id'] ?? null) === null && count($nextLines) === count($previousLines);

            if ($sameProduct || $sameEmptySlot || $filledFirstEmptyRow) {
                $key = $this->normalizeUiIdentityKey($prevLine['row_key'] ?? null);
                if ($key !== null) {
                    $usedPrev[$nextIdx] = true;
                    $nextLine['row_key'] = $key;
                    $assigned[$nextIdx] = true;
                    $nextLines[$nextIdx] = $nextLine;
                }
            }
        }

        // Pass 3: match moved product rows before assigning new keys
        foreach ($nextLines as $nextIdx => $nextLine) {
            if (isset($assigned[$nextIdx]) || empty($nextLine['product_id'])) {
                continue;
            }
            foreach ($previousLines as $prevIdx => $prevLine) {
                if (isset($usedPrev[$prevIdx])) {
                    continue;
                }
                if ((int) ($prevLine['product_id'] ?? 0) === (int) $nextLine['product_id']
                    && ($prevLine['product_unit_id'] ?? null) === ($nextLine['product_unit_id'] ?? null)) {
                    $key = $this->normalizeUiIdentityKey($prevLine['row_key'] ?? null);
                    if ($key !== null) {
                        $usedPrev[$prevIdx] = true;
                        $nextLine['row_key'] = $key;
                        $assigned[$nextIdx] = true;
                        $nextLines[$nextIdx] = $nextLine;
                        break;
                    }
                }
            }
        }

        // Any newly introduced rows get fresh UUIDs (deduplicated defensively).
        $seen = [];
        foreach ($nextLines as $idx => $line) {
            $key = $this->normalizeUiIdentityKey($line['row_key'] ?? null);
            if ($key === null || isset($seen[$key])) {
                $key = $this->newUiIdentityKey();
            }
            $seen[$key] = true;
            $nextLines[$idx]['row_key'] = $key;

            /** @var mixed $rawLots */
            $rawLots = $line['lots'] ?? null;
            if (is_array($rawLots)) {
                $nextLines[$idx]['lots'] = $this->ensureLotListIdentities($rawLots);
            }
        }

        return $nextLines;
    }

    protected function newUiIdentityKey(): string
    {
        return (string) Str::uuid();
    }

    protected function normalizeUiIdentityKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }
}
