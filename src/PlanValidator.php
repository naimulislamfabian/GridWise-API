<?php
declare(strict_types=1);

namespace GridWise;

final class PlanValidationException extends \RuntimeException {}

final class PlanValidator
{
    private const TOL = 0.005;

    public static function validate(array $request, array $ctx, array $plan): void
    {
        if (count($plan) !== 24) {
            throw new PlanValidationException('hourly_plan must contain 24 entries');
        }
        $previous = $request['battery']['initial_energy_kwh'];

        for ($h = 0; $h < 24; $h++) {
            $p = $plan[$h] ?? null;
            if (!is_array($p) || ($p['hour'] ?? null) !== $h) {
                throw new PlanValidationException('hourly_plan must contain hours 0..23 in order');
            }
            foreach (['grid_kwh', 'solar_used_kwh', 'battery_kwh', 'battery_energy_after_kwh'] as $field) {
                $v = $p[$field] ?? null;
                if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || (float)$v < -self::TOL) {
                    throw new PlanValidationException("hour {$h}: non-finite or negative {$field}");
                }
            }
            if ($p['solar_used_kwh'] > $ctx['effective_solar_kwh'][$h] + self::TOL) {
                throw new PlanValidationException("hour {$h}: solar overuse");
            }
            if ($p['grid_kwh'] > $ctx['grid_cap_kwh'][$h] + self::TOL) {
                throw new PlanValidationException("hour {$h}: grid cap violation");
            }
            if ($p['battery_energy_after_kwh'] < $ctx['reserve_floor_kwh'][$h] - self::TOL) {
                throw new PlanValidationException("hour {$h}: reserve violation");
            }
            if ($p['battery_energy_after_kwh'] > $request['battery']['capacity_kwh'] + self::TOL) {
                throw new PlanValidationException("hour {$h}: battery capacity violation");
            }

            $action = $p['battery_action'] ?? '';
            if (!in_array($action, ['charge', 'discharge', 'idle'], true)) {
                throw new PlanValidationException("hour {$h}: invalid battery_action");
            }
            $charge = $action === 'charge' ? (float)$p['battery_kwh'] : 0.0;
            $discharge = $action === 'discharge' ? (float)$p['battery_kwh'] : 0.0;
            if ($action === 'idle' && abs((float)$p['battery_kwh']) > self::TOL) {
                throw new PlanValidationException("hour {$h}: idle must have battery_kwh=0");
            }
            if ($charge > $request['battery']['max_charge_kwh_per_hour'] + self::TOL) {
                throw new PlanValidationException("hour {$h}: charge-rate violation");
            }
            if ($discharge > $request['battery']['max_discharge_kwh_per_hour'] + self::TOL) {
                throw new PlanValidationException("hour {$h}: discharge-rate violation");
            }
            if ($ctx['no_charge'][$h] && $charge > self::TOL) {
                throw new PlanValidationException("hour {$h}: no-charge directive violation");
            }
            if ($ctx['no_discharge'][$h] && $discharge > self::TOL) {
                throw new PlanValidationException("hour {$h}: no-discharge directive violation");
            }

            $expected = $previous + $charge - $discharge;
            if (!self::close((float)$p['battery_energy_after_kwh'], $expected)) {
                throw new PlanValidationException("hour {$h}: invalid battery state transition");
            }
            $lhs = (float)$p['grid_kwh'] + (float)$p['solar_used_kwh'] + $discharge;
            $rhs = $request['hours'][$h]['demand_kwh'] + $charge;
            if (!self::close($lhs, $rhs)) {
                throw new PlanValidationException("hour {$h}: energy-balance violation");
            }
            $previous = (float)$p['battery_energy_after_kwh'];
        }

        if (!self::close((float)$plan[23]['battery_energy_after_kwh'], $request['battery']['initial_energy_kwh'])) {
            throw new PlanValidationException('end-of-day battery neutrality violation');
        }
    }

    private static function close(float $a, float $b): bool
    {
        return abs($a - $b) <= self::TOL;
    }
}
