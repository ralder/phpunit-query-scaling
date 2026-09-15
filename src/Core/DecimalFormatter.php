<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Core;

final class DecimalFormatter
{
    private function __construct() {}

    public static function format(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }
}
