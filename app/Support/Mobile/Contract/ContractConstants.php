<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract;

final class ContractConstants
{
    public const int MAX_NESTING_DEPTH = 32;

    public const int MAX_PAYLOAD_BYTES = 1048576; // 1 MB

    public const int MAX_STRING_LENGTH = 65536; // 64 KB

    public const int MIN_SAFE_INTEGER = -9007199254740991; // -(2^53 - 1)

    public const int MAX_SAFE_INTEGER = 9007199254740991; // 2^53 - 1
}
