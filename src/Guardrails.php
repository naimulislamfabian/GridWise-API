<?php
declare(strict_types=1);

namespace GridWise;

final class GuardrailException extends \InvalidArgumentException {}

final class Guardrails
{
    public const SUPPORTED = [
        'solar_reduction', 'minimum_battery_reserve', 'no_charge_window',
        'no_discharge_window', 'max_grid_window', 'no_op',
    ];

    public static function validate(array $request, mixed $raw): array
    {
        if (is_array($raw) && !Compat::isList($raw) && array_key_exists('directive_interpretation', $raw)) {
            $raw = $raw['directive_interpretation'];
        }
        if (!is_array($raw) || !Compat::isList($raw)) {
            throw new GuardrailException('LLM output must contain a directive_interpretation array');
        }
        if (count($raw) !== count($request['operator_notes'])) {
            throw new GuardrailException('there must be exactly one interpretation per operator note');
        }

        $result = [];
        $requiredKeys = ['note_index', 'applies', 'directive_type', 'structured_adjustment', 'explanation'];
        foreach ($raw as $position => $item) {
            if (!is_array($item) || Compat::isList($item)) {
                throw new GuardrailException('each directive interpretation must be an object');
            }
            self::exactKeys($item, $requiredKeys, 'directive interpretation');
            if (!is_int($item['note_index']) || $item['note_index'] !== $position) {
                throw new GuardrailException('note_index must map each note exactly once in 0..N-1 order');
            }
            if (!is_bool($item['applies'])) {
                throw new GuardrailException('applies must be boolean');
            }
            $type = $item['directive_type'];
            if (!is_string($type) || !in_array($type, self::SUPPORTED, true)) {
                throw new GuardrailException('unsupported directive_type');
            }
            if (!is_string($item['explanation']) || trim($item['explanation']) === '') {
                throw new GuardrailException('explanation must be a non-empty string');
            }

            $adj = $item['structured_adjustment'];
            if ($type === 'no_op') {
                if ($item['applies'] !== false || $adj !== null) {
                    throw new GuardrailException('no_op requires applies=false and structured_adjustment=null');
                }
            } else {
                if ($item['applies'] !== true || !is_array($adj) || Compat::isList($adj)) {
                    throw new GuardrailException('non-no_op directives require applies=true and an adjustment object');
                }
                switch ($type) {
                    case 'solar_reduction':
                        self::exactKeys($adj, ['hours', 'factor'], 'structured_adjustment');
                        self::hours($adj['hours']);
                        self::number($adj['factor'], 'factor', 0.0, 1.0);
                        break;
                    case 'minimum_battery_reserve':
                        self::exactKeys($adj, ['hours', 'minimum_energy_kwh'], 'structured_adjustment');
                        self::hours($adj['hours']);
                        self::number($adj['minimum_energy_kwh'], 'minimum_energy_kwh', 0.0, $request['battery']['capacity_kwh']);
                        break;
                    case 'no_charge_window':
                    case 'no_discharge_window':
                        self::exactKeys($adj, ['hours'], 'structured_adjustment');
                        self::hours($adj['hours']);
                        break;
                    case 'max_grid_window':
                        self::exactKeys($adj, ['hours', 'max_grid_kwh'], 'structured_adjustment');
                        self::hours($adj['hours']);
                        self::number($adj['max_grid_kwh'], 'max_grid_kwh', 0.0, null);
                        break;
                }
            }
            $result[] = $item;
        }
        return $result;
    }

    private static function hours(mixed $value): array
    {
        if (!is_array($value) || !Compat::isList($value) || $value === []) {
            throw new GuardrailException('hours must be a non-empty array');
        }
        $previous = -1;
        $seen = [];
        foreach ($value as $h) {
            if (!is_int($h) || $h < 0 || $h > 23) {
                throw new GuardrailException('every directive hour must be an integer within 0..23');
            }
            if (isset($seen[$h]) || $h <= $previous) {
                throw new GuardrailException('directive hours must be unique and in ascending order');
            }
            $seen[$h] = true;
            $previous = $h;
        }
        return $value;
    }

    private static function number(mixed $value, string $name, float $min, ?float $max): float
    {
        if (is_bool($value) || (!is_int($value) && !is_float($value))) {
            throw new GuardrailException($name . ' must be numeric');
        }
        $v = (float)$value;
        if (!is_finite($v) || $v < $min || ($max !== null && $v > $max)) {
            throw new GuardrailException($name . ' is outside its allowed range');
        }
        return $v;
    }

    private static function exactKeys(array $obj, array $expected, string $where): void
    {
        $actual = array_keys($obj);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new GuardrailException($where . ' has missing or extra fields');
        }
    }
}
