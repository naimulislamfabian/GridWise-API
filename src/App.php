<?php
declare(strict_types=1);

namespace GridWise;

use GridWise\LLM\Interpreter;
use GridWise\LLM\LLMInterpretationException;

final class App
{
    public static function handle(string $method, string $path, string $rawBody): array
    {
        if ($method === 'GET' && $path === '/health') {
            return [200, ['status' => 'ok']];
        }
        if ($method !== 'POST' || $path !== '/optimize-energy') {
            return [404, ['detail' => 'not found']];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || Compat::isList($decoded)) {
                return [400, ['detail' => 'invalid request']];
            }
        } catch (\JsonException) {
            return [400, ['detail' => 'invalid request']];
        }

        try {
            $request = RequestValidator::validate($decoded);
        } catch (RequestValidationException) {
            return [400, ['detail' => 'invalid request']];
        }

        try {
            $directives = Interpreter::interpret($request);
            $ctx = Directives::apply($request, $directives);
            $plan = Optimizer::optimize($request, $ctx);
            PlanValidator::validate($request, $ctx, $plan);

            $totalGrid = 0.0;
            $totalCost = 0.0;
            $peakGrid = 0.0;
            foreach ($plan as $p) {
                $totalGrid += $p['grid_kwh'];
                $totalCost += $p['grid_kwh'] * $request['hours'][$p['hour']]['tariff_bdt_per_kwh'];
                $peakGrid = max($peakGrid, $p['grid_kwh']);
            }
            $active = array_values(array_map(
                static fn(array $d): string => $d['directive_type'],
                array_filter($directives, static fn(array $d): bool => $d['applies'])
            ));
            $summary = 'Applied ' . count($active) . ' operator directive(s)'
                . ($active !== [] ? ' (' . implode(', ', $active) . ')' : '')
                . '; produced a valid 24-hour minimum-grid-cost schedule while restoring the battery to its initial energy.';

            return [200, [
                'scenario_id' => $request['scenario_id'],
                'directive_interpretation' => $directives,
                'hourly_plan' => $plan,
                'total_grid_kwh' => round($totalGrid, 6),
                'total_cost_bdt' => round($totalCost, 6),
                'peak_grid_kwh' => round($peakGrid, 6),
                'plan_summary' => $summary,
            ]];
        } catch (LLMInterpretationException $e) {
            $body = ['detail' => 'operator-note interpretation failed safely'];
            $debug = strtolower((string)(getenv('APP_DEBUG') ?: 'false'));
            if (in_array($debug, ['1', 'true', 'yes', 'on'], true)) {
                $body['reason'] = $e->getMessage();
            }
            return [500, $body];
        } catch (DirectiveConflictException|OptimizationException|PlanValidationException) {
            return [422, ['detail' => 'scenario is not safely optimizable']];
        } catch (\Throwable) {
            return [500, ['detail' => 'internal error']];
        }
    }
}
