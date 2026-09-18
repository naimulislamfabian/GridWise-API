<?php
declare(strict_types=1);

namespace GridWise;

final class RequestValidationException extends \InvalidArgumentException {}

final class RequestValidator
{
    private const TOP_KEYS = ['scenario_id', 'operator_notes', 'hours', 'battery'];
    private const HOUR_KEYS = ['hour', 'demand_kwh', 'solar_kwh', 'tariff_bdt_per_kwh'];
    private const BATTERY_KEYS = [
        'capacity_kwh', 'initial_energy_kwh', 'minimum_energy_kwh',
        'max_charge_kwh_per_hour', 'max_discharge_kwh_per_hour',
    ];

    public static function validate(array $input): array
    {
        self::exactKeys($input, self::TOP_KEYS, 'request');

        if (!is_string($input['scenario_id']) || trim($input['scenario_id']) === '') {
            throw new RequestValidationException('scenario_id must be a non-empty string');
        }

        if (!is_array($input['operator_notes']) || !Compat::isList($input['operator_notes']) || count($input['operator_notes']) < 1 || count($input['operator_notes']) > 3) {
            throw new RequestValidationException('operator_notes must be an array of 1-3 strings');
        }
        foreach ($input['operator_notes'] as $note) {
            if (!is_string($note) || trim($note) === '') {
                throw new RequestValidationException('operator_notes must contain non-empty strings');
            }
        }

        if (!is_array($input['hours']) || !Compat::isList($input['hours']) || count($input['hours']) !== 24) {
            throw new RequestValidationException('hours must contain exactly 24 entries');
        }

        $seen = [];
        $hours = [];
        foreach ($input['hours'] as $row) {
            if (!is_array($row) || Compat::isList($row)) {
                throw new RequestValidationException('each hours entry must be an object');
            }
            self::exactKeys($row, self::HOUR_KEYS, 'hour entry');
            $h = $row['hour'];
            if (!is_int($h) || $h < 0 || $h > 23 || isset($seen[$h])) {
                throw new RequestValidationException('hours must contain each integer 0..23 exactly once');
            }
            $seen[$h] = true;
            $hours[$h] = [
                'hour' => $h,
                'demand_kwh' => self::nonNegativeNumber($row['demand_kwh'], 'demand_kwh'),
                'solar_kwh' => self::nonNegativeNumber($row['solar_kwh'], 'solar_kwh'),
                'tariff_bdt_per_kwh' => self::nonNegativeNumber($row['tariff_bdt_per_kwh'], 'tariff_bdt_per_kwh'),
            ];
        }
        if (count($seen) !== 24) {
            throw new RequestValidationException('hours must contain each integer 0..23 exactly once');
        }
        ksort($hours);

        if (!is_array($input['battery']) || Compat::isList($input['battery'])) {
            throw new RequestValidationException('battery must be an object');
        }
        self::exactKeys($input['battery'], self::BATTERY_KEYS, 'battery');
        $battery = [];
        foreach (self::BATTERY_KEYS as $key) {
            $battery[$key] = self::nonNegativeNumber($input['battery'][$key], $key);
        }
        if ($battery['initial_energy_kwh'] > $battery['capacity_kwh']) {
            throw new RequestValidationException('initial_energy_kwh cannot exceed capacity_kwh');
        }
        if ($battery['minimum_energy_kwh'] > $battery['capacity_kwh']) {
            throw new RequestValidationException('minimum_energy_kwh cannot exceed capacity_kwh');
        }
        if ($battery['initial_energy_kwh'] < $battery['minimum_energy_kwh']) {
            throw new RequestValidationException('initial_energy_kwh cannot be below minimum_energy_kwh');
        }

        return [
            'scenario_id' => $input['scenario_id'],
            'operator_notes' => array_values($input['operator_notes']),
            'hours' => array_values($hours),
            'battery' => $battery,
        ];
    }

    private static function exactKeys(array $obj, array $expected, string $where): void
    {
        $actual = array_keys($obj);
        sort($actual);
        $copy = $expected;
        sort($copy);
        if ($actual !== $copy) {
            throw new RequestValidationException($where . ' has missing or extra fields');
        }
    }

    private static function nonNegativeNumber(mixed $value, string $name): float
    {
        if (is_bool($value) || (!is_int($value) && !is_float($value))) {
            throw new RequestValidationException($name . ' must be numeric');
        }
        $v = (float)$value;
        if (!is_finite($v) || $v < 0.0) {
            throw new RequestValidationException($name . ' must be finite and non-negative');
        }
        return $v;
    }
}
