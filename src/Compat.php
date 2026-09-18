<?php
declare(strict_types=1);

namespace GridWise;

final class Compat
{
    /**
     * PHP 8.0-compatible replacement for array_is_list() (added in PHP 8.1).
     */
    public static function isList(array $array): bool
    {
        $expected = 0;
        foreach ($array as $key => $_value) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }
}
