<?php
declare(strict_types=1);

namespace GridWise;

/**
 * Two-phase simplex for max c^T x subject to A x <= b, x >= 0.
 * Adapted to PHP from the standard competitive-programming tableau algorithm.
 */
final class Simplex
{
    private const EPS = 1e-9;
    private const BIG = 1e100;

    private int $m;
    private int $n;
    /** @var array<int,int> */
    private array $B;
    /** @var array<int,int> */
    private array $N;
    /** @var array<int,array<int,float>> */
    private array $D;

    public function __construct(array $A, array $b, array $c)
    {
        $this->m = count($b);
        $this->n = count($c);
        $this->B = array_fill(0, $this->m, 0);
        $this->N = array_fill(0, $this->n + 1, 0);
        $this->D = array_fill(0, $this->m + 2, array_fill(0, $this->n + 2, 0.0));

        for ($i = 0; $i < $this->m; $i++) {
            for ($j = 0; $j < $this->n; $j++) {
                $this->D[$i][$j] = (float)$A[$i][$j];
            }
        }
        for ($i = 0; $i < $this->m; $i++) {
            $this->B[$i] = $this->n + $i;
            $this->D[$i][$this->n] = -1.0;
            $this->D[$i][$this->n + 1] = (float)$b[$i];
        }
        for ($j = 0; $j < $this->n; $j++) {
            $this->N[$j] = $j;
            $this->D[$this->m][$j] = -(float)$c[$j];
        }
        $this->N[$this->n] = -1;
        $this->D[$this->m + 1][$this->n] = 1.0;
    }

    /** @return array{status:string,value:float,x:array<int,float>} */
    public function solve(): array
    {
        $r = 0;
        for ($i = 1; $i < $this->m; $i++) {
            if ($this->D[$i][$this->n + 1] < $this->D[$r][$this->n + 1]) {
                $r = $i;
            }
        }

        if ($this->D[$r][$this->n + 1] < -self::EPS) {
            $this->pivot($r, $this->n);
            if (!$this->simplex(1) || $this->D[$this->m + 1][$this->n + 1] < -self::EPS) {
                return ['status' => 'infeasible', 'value' => -self::BIG, 'x' => []];
            }
            if (abs($this->D[$this->m + 1][$this->n + 1]) > self::EPS) {
                return ['status' => 'infeasible', 'value' => -self::BIG, 'x' => []];
            }

            for ($i = 0; $i < $this->m; $i++) {
                if ($this->B[$i] === -1) {
                    $s = -1;
                    for ($j = 0; $j <= $this->n; $j++) {
                        if ($s === -1 || $this->D[$i][$j] < $this->D[$i][$s] - self::EPS ||
                            (abs($this->D[$i][$j] - $this->D[$i][$s]) <= self::EPS && $this->N[$j] < $this->N[$s])) {
                            $s = $j;
                        }
                    }
                    if ($s >= 0 && abs($this->D[$i][$s]) > self::EPS) {
                        $this->pivot($i, $s);
                    }
                }
            }
        }

        if (!$this->simplex(2)) {
            return ['status' => 'unbounded', 'value' => self::BIG, 'x' => []];
        }

        $x = array_fill(0, $this->n, 0.0);
        for ($i = 0; $i < $this->m; $i++) {
            if ($this->B[$i] < $this->n) {
                $x[$this->B[$i]] = $this->D[$i][$this->n + 1];
            }
        }
        return ['status' => 'optimal', 'value' => $this->D[$this->m][$this->n + 1], 'x' => $x];
    }

    private function pivot(int $r, int $s): void
    {
        $inv = 1.0 / $this->D[$r][$s];
        for ($i = 0; $i < $this->m + 2; $i++) {
            if ($i === $r) {
                continue;
            }
            for ($j = 0; $j < $this->n + 2; $j++) {
                if ($j === $s) {
                    continue;
                }
                $this->D[$i][$j] -= $this->D[$r][$j] * $this->D[$i][$s] * $inv;
            }
        }
        for ($j = 0; $j < $this->n + 2; $j++) {
            if ($j !== $s) {
                $this->D[$r][$j] *= $inv;
            }
        }
        for ($i = 0; $i < $this->m + 2; $i++) {
            if ($i !== $r) {
                $this->D[$i][$s] *= -$inv;
            }
        }
        $this->D[$r][$s] = $inv;
        [$this->B[$r], $this->N[$s]] = [$this->N[$s], $this->B[$r]];
    }

    private function simplex(int $phase): bool
    {
        $x = $phase === 1 ? $this->m + 1 : $this->m;
        while (true) {
            $s = -1;
            for ($j = 0; $j <= $this->n; $j++) {
                if ($phase === 2 && $this->N[$j] === -1) {
                    continue;
                }
                if ($s === -1 || $this->D[$x][$j] < $this->D[$x][$s] - self::EPS ||
                    (abs($this->D[$x][$j] - $this->D[$x][$s]) <= self::EPS && $this->N[$j] < $this->N[$s])) {
                    $s = $j;
                }
            }
            if ($s === -1 || $this->D[$x][$s] >= -self::EPS) {
                return true;
            }

            $r = -1;
            for ($i = 0; $i < $this->m; $i++) {
                if ($this->D[$i][$s] <= self::EPS) {
                    continue;
                }
                if ($r === -1) {
                    $r = $i;
                    continue;
                }
                $lhs = $this->D[$i][$this->n + 1] / $this->D[$i][$s];
                $rhs = $this->D[$r][$this->n + 1] / $this->D[$r][$s];
                if ($lhs < $rhs - self::EPS || (abs($lhs - $rhs) <= self::EPS && $this->B[$i] < $this->B[$r])) {
                    $r = $i;
                }
            }
            if ($r === -1) {
                return false;
            }
            $this->pivot($r, $s);
        }
    }
}
