<?php
declare(strict_types=1);

namespace GridWise;

final class OptimizationException extends \RuntimeException {}

final class Optimizer
{
    private const N = 24;
    private const G = 0;
    private const S = 24;
    private const C = 48;
    private const D = 72;
    private const E = 96;
    private const NV = 120;

    public static function optimize(array $request, array $ctx): array
    {
        $A = [];
        $b = [];
        $c = array_fill(0, self::NV, 0.0);

        for ($h = 0; $h < self::N; $h++) {
            $c[self::G + $h] = -$request['hours'][$h]['tariff_bdt_per_kwh']; // maximize negative cost
        }

        // Equality: grid + solar + discharge - charge = demand.
        for ($h = 0; $h < self::N; $h++) {
            $row = self::zeroRow();
            $row[self::G + $h] = 1.0;
            $row[self::S + $h] = 1.0;
            $row[self::D + $h] = 1.0;
            $row[self::C + $h] = -1.0;
            self::addEquality($A, $b, $row, $request['hours'][$h]['demand_kwh']);
        }

        // Battery transitions.
        for ($h = 0; $h < self::N; $h++) {
            $row = self::zeroRow();
            $row[self::E + $h] = 1.0;
            $row[self::C + $h] = -1.0;
            $row[self::D + $h] = 1.0;
            if ($h === 0) {
                $rhs = $request['battery']['initial_energy_kwh'];
            } else {
                $row[self::E + $h - 1] = -1.0;
                $rhs = 0.0;
            }
            self::addEquality($A, $b, $row, $rhs);
        }

        // End-of-day neutrality.
        $row = self::zeroRow();
        $row[self::E + 23] = 1.0;
        self::addEquality($A, $b, $row, $request['battery']['initial_energy_kwh']);

        // Variable upper/lower bounds as inequalities; x >= 0 is native to simplex.
        for ($h = 0; $h < self::N; $h++) {
            if (is_finite($ctx['grid_cap_kwh'][$h])) {
                self::addUpperBound($A, $b, self::G + $h, $ctx['grid_cap_kwh'][$h]);
            }
            self::addUpperBound($A, $b, self::S + $h, $ctx['effective_solar_kwh'][$h]);
            $maxCharge = $ctx['no_charge'][$h] ? 0.0 : $request['battery']['max_charge_kwh_per_hour'];
            $maxDischarge = $ctx['no_discharge'][$h] ? 0.0 : $request['battery']['max_discharge_kwh_per_hour'];
            self::addUpperBound($A, $b, self::C + $h, $maxCharge);
            self::addUpperBound($A, $b, self::D + $h, $maxDischarge);
            self::addUpperBound($A, $b, self::E + $h, $request['battery']['capacity_kwh']);
            self::addLowerBound($A, $b, self::E + $h, $ctx['reserve_floor_kwh'][$h]);
        }

        $solution = (new Simplex($A, $b, $c))->solve();
        if ($solution['status'] !== 'optimal') {
            throw new OptimizationException('no feasible optimization plan found');
        }
        $x = $solution['x'];

        $plan = [];
        $energy = $request['battery']['initial_energy_kwh'];
        for ($h = 0; $h < self::N; $h++) {
            $rawCharge = max(0.0, (float)$x[self::C + $h]);
            $rawDischarge = max(0.0, (float)$x[self::D + $h]);
            $net = self::clean($rawCharge - $rawDischarge);
            $solar = self::clean(max(0.0, (float)$x[self::S + $h]));

            if ($net > 0.0) {
                $action = 'charge';
                $amount = $net;
                $charge = $net;
                $discharge = 0.0;
            } elseif ($net < 0.0) {
                $action = 'discharge';
                $amount = -$net;
                $charge = 0.0;
                $discharge = -$net;
            } else {
                $action = 'idle';
                $amount = 0.0;
                $charge = 0.0;
                $discharge = 0.0;
            }

            $grid = self::clean($request['hours'][$h]['demand_kwh'] + $charge - $discharge - $solar);
            if ($grid < -0.001) {
                throw new OptimizationException('solver produced negative grid import');
            }
            $grid = max(0.0, $grid);
            $energy = self::clean($energy + $charge - $discharge);

            $plan[] = [
                'hour' => $h,
                'grid_kwh' => $grid,
                'solar_used_kwh' => $solar,
                'battery_action' => $action,
                'battery_kwh' => self::clean($amount),
                'battery_energy_after_kwh' => $energy,
            ];
        }
        return $plan;
    }

    private static function zeroRow(): array
    {
        return array_fill(0, self::NV, 0.0);
    }

    private static function addEquality(array &$A, array &$b, array $row, float $rhs): void
    {
        $A[] = $row;
        $b[] = $rhs;
        $A[] = array_map(static fn(float $v): float => -$v, $row);
        $b[] = -$rhs;
    }

    private static function addUpperBound(array &$A, array &$b, int $idx, float $upper): void
    {
        $row = self::zeroRow();
        $row[$idx] = 1.0;
        $A[] = $row;
        $b[] = $upper;
    }

    private static function addLowerBound(array &$A, array &$b, int $idx, float $lower): void
    {
        $row = self::zeroRow();
        $row[$idx] = -1.0;
        $A[] = $row;
        $b[] = -$lower;
    }

    private static function clean(float $x, int $digits = 6): float
    {
        if (abs($x) < 0.5 * (10 ** (-$digits))) {
            return 0.0;
        }
        return round($x, $digits);
    }
}
