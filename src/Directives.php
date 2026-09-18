<?php
declare(strict_types=1);

namespace GridWise;

final class DirectiveConflictException extends \RuntimeException {}

final class Directives
{
    public static function apply(array $request, array $directives): array
    {
        $baseSolar = array_map(static fn(array $h): float => $h['solar_kwh'], $request['hours']);
        $factors = array_fill(0, 24, null);
        $reserve = array_fill(0, 24, $request['battery']['minimum_energy_kwh']);
        $gridCap = array_fill(0, 24, INF);
        $noCharge = array_fill(0, 24, false);
        $noDischarge = array_fill(0, 24, false);

        foreach ($directives as $item) {
            if (!$item['applies']) {
                continue;
            }
            $adj = $item['structured_adjustment'];
            $hours = $adj['hours'] ?? [];
            switch ($item['directive_type']) {
                case 'solar_reduction':
                    $factor = (float)$adj['factor'];
                    foreach ($hours as $h) {
                        if ($factors[$h] !== null && abs((float)$factors[$h] - $factor) > 1e-12) {
                            throw new DirectiveConflictException('conflicting solar_reduction factors for the same hour');
                        }
                        $factors[$h] = $factor;
                    }
                    break;
                case 'minimum_battery_reserve':
                    $value = (float)$adj['minimum_energy_kwh'];
                    foreach ($hours as $h) {
                        $reserve[$h] = max($reserve[$h], $value);
                    }
                    break;
                case 'no_charge_window':
                    foreach ($hours as $h) {
                        $noCharge[$h] = true;
                    }
                    break;
                case 'no_discharge_window':
                    foreach ($hours as $h) {
                        $noDischarge[$h] = true;
                    }
                    break;
                case 'max_grid_window':
                    $value = (float)$adj['max_grid_kwh'];
                    foreach ($hours as $h) {
                        $gridCap[$h] = min($gridCap[$h], $value);
                    }
                    break;
            }
        }

        $effectiveSolar = [];
        for ($h = 0; $h < 24; $h++) {
            $effectiveSolar[$h] = $baseSolar[$h] * ($factors[$h] ?? 1.0);
        }

        return [
            'effective_solar_kwh' => $effectiveSolar,
            'reserve_floor_kwh' => $reserve,
            'grid_cap_kwh' => $gridCap,
            'no_charge' => $noCharge,
            'no_discharge' => $noDischarge,
        ];
    }
}
