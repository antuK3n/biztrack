<?php

namespace App\Support;

/**
 * Generalised linear models, in PHP, because there is no longer a second engine.
 *
 * The fitted half of Renewal Risk used to be computed elsewhere, by a statistics
 * runtime this project shelled out to over HTTP. That runtime has been removed,
 * and with it the argument that the fit could not honestly live here. So it lives
 * here: this class is the arithmetic, RenewalModelAnalytics is the domain reading
 * of it, and the pair together reproduce the coefficient table, the standard
 * errors and the discrimination figures that screen has always shown.
 *
 * ── WHY THIS IS SMALL, AND WHY IT IS ALLOWED TO BE ──────────────────────────
 *
 * A general-purpose GLM library is a large and thankless thing: link functions,
 * weights, contrasts, rank-deficiency handling, families nobody uses. None of
 * that is needed. One model is fitted in this codebase — a binomial logit with a
 * handful of columns and a few hundred rows — and everything below is scoped to
 * exactly that. Iteratively reweighted least squares on that shape is a couple of
 * dozen lines of well-understood arithmetic, it converges in single-figure
 * iterations, and it is verified row for row against a frozen golden fixture in
 * RenewalModelFitTest. A model this size does not need a library; it needs a
 * check, and it has one.
 *
 * The one thing deliberately NOT hidden is the coefficient covariance. Standard
 * errors are the reason this screen is allowed to make a claim at all — an
 * estimate without one is a number, not a finding — so the inverse of X'WX is
 * kept from the final iteration and returned rather than discarded, and every
 * fit hands back an estimate, a standard error, a z and a two-sided p together.
 *
 * ── WHAT IS NOT HERE ────────────────────────────────────────────────────────
 *
 * No rank-deficiency recovery. If two columns are collinear the normal equations
 * are singular and binomial() returns null rather than silently dropping a column
 * and reporting a table with a term missing from it. The caller's job is to not
 * ask: RenewalModelAnalytics folds thin factor levels and drops constant terms
 * BEFORE it gets here, and names what it dropped in the payload. A fit that
 * quietly repaired its own design matrix would produce a coefficient table that
 * is complete-looking and wrong, which is the failure mode this whole feature is
 * arranged to avoid.
 */
final class Glm
{
    /**
     * Iteration cap.
     *
     * Newton's method on a well-posed logit converges in five or six steps; this
     * is a stop, not a schedule. Hitting it means the fit is running off — the
     * classic cause is a factor level that separates the outcome perfectly — and
     * the caller is told so rather than handed the last iterate as if it were an
     * answer.
     */
    public const MAX_ITERATIONS = 100;

    /**
     * Relative change in deviance that counts as converged.
     *
     * Tighter than it needs to be on purpose. Every figure this feeds is rounded
     * to three decimal places before anybody sees it, so the tolerance exists to
     * put the iterate far enough inside that rounding that the last digit is
     * decided by the data and not by where the loop happened to stop.
     *
     * That is not hypothetical, and it is the one place this port and the engine
     * it replaced disagree at published precision. The older engine stopped at a
     * relative deviance change of 1e-8, which on the live register is six
     * iterations; on `renewal_stage = in_progress` — a level that very nearly
     * separates the outcome, so the likelihood is almost flat along it — the
     * seventh iteration still moves the curvature in the fourth significant
     * figure, and with it the standard error (1.13797 to 1.13867) and the
     * reported z (-5.052 to -5.049). Run to convergence, the old engine agrees
     * with this one to every digit. The estimate never moved; only the precision
     * of the answer about how well it is pinned down did, and the more converged
     * of the two is the one worth printing next to a coefficient a licensing
     * officer is invited to argue with.
     */
    public const TOLERANCE = 1.0e-11;

    /** Below this a fitted weight is treated as zero and the row stops contributing. */
    private const MINIMUM_WEIGHT = 1.0e-10;

    /**
     * Build a design matrix from named rows and a term specification.
     *
     * Treatment contrasts, first level as the reference, intercept always in
     * column zero — the same parameterisation the coefficient table has always
     * been printed under, so `renewal_stagerejected` still means "rejected
     * against the reference level" and not something new.
     *
     * Levels are taken from the SPEC, never read off the data. That is the whole
     * reason they are a parameter: if the level set were inferred per call, a
     * refresh in which nobody filed a draft would renumber every contrast and two
     * coefficient tables from two nights would stop being comparable without
     * anything looking wrong.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{name: string, kind: string, levels?: list<string>}>  $terms
     * @return array{columns: list<string>, matrix: list<list<float>>}
     */
    public static function design(array $rows, array $terms): array
    {
        $columns = ['(Intercept)'];

        foreach ($terms as $term) {
            if (($term['kind'] ?? 'numeric') === 'factor') {
                // The reference level gets no column; that is what makes it the
                // reference. Its effect is inside the intercept.
                foreach (array_slice($term['levels'] ?? [], 1) as $level) {
                    $columns[] = $term['name'].$level;
                }

                continue;
            }

            $columns[] = $term['name'];
        }

        $matrix = [];

        foreach ($rows as $row) {
            $line = [1.0];

            foreach ($terms as $term) {
                if (($term['kind'] ?? 'numeric') === 'factor') {
                    $value = (string) ($row[$term['name']] ?? '');

                    foreach (array_slice($term['levels'] ?? [], 1) as $level) {
                        $line[] = $value === $level ? 1.0 : 0.0;
                    }

                    continue;
                }

                $line[] = (float) ($row[$term['name']] ?? 0.0);
            }

            $matrix[] = $line;
        }

        return ['columns' => $columns, 'matrix' => $matrix];
    }

    /**
     * Fit a binomial logit by iteratively reweighted least squares.
     *
     * The starting point is the textbook one — every fitted probability nudged
     * off its own outcome by half an observation — which keeps the first working
     * weights finite even on a column where every row went the same way. The loop
     * then stops on the relative change in deviance, because deviance is the
     * thing being minimised and a coefficient-wise stopping rule would declare
     * victory early on a poorly scaled column.
     *
     * `offset` is a per-row addition to the linear predictor that is NOT
     * estimated. It exists for one caller: calibration-in-the-large, which asks
     * "are these predictions too high or too low on average" by fitting an
     * intercept with the model's own log-odds pinned at a slope of one. Without
     * an offset that question cannot be asked at all.
     *
     * Returns null rather than a half-answer when the design is singular or the
     * iteration does not settle, so a caller cannot mistake a failed fit for a
     * weak one.
     *
     * @param  list<list<float>>  $matrix  design matrix, rows by columns
     * @param  list<float|int>  $y  outcomes in {0, 1}
     * @param  list<float>|null  $offset  fixed per-row term in the linear predictor
     * @return array{
     *     coefficients: list<float>,
     *     standard_errors: list<float>,
     *     z_values: list<float>,
     *     p_values: list<float>,
     *     covariance: list<list<float>>,
     *     deviance: float,
     *     iterations: int
     * }|null
     */
    public static function binomial(array $matrix, array $y, ?array $offset = null): ?array
    {
        $n = count($matrix);

        if ($n === 0 || $n !== count($y)) {
            return null;
        }

        $p = count($matrix[0]);

        if ($p === 0 || $n < $p) {
            return null;
        }

        $offset ??= array_fill(0, $n, 0.0);

        $mu = [];
        $eta = [];

        for ($i = 0; $i < $n; $i++) {
            // (y + 0.5) / 2 — half an observation of the other outcome, so the
            // first weight is never exactly zero however lopsided the column is.
            $start = ((float) $y[$i] + 0.5) / 2.0;
            $mu[$i] = $start;
            $eta[$i] = log($start / (1.0 - $start));
        }

        $deviance = self::deviance($y, $mu);
        $beta = array_fill(0, $p, 0.0);
        $covariance = null;
        $iterations = 0;

        for ($iteration = 1; $iteration <= self::MAX_ITERATIONS; $iteration++) {
            $iterations = $iteration;

            // The weighted least-squares step: weights are the binomial variance
            // at the current fit, the working response is the linear predictor
            // corrected by the residual on the link scale.
            $xtwx = self::zeroes($p, $p);
            $xtwz = array_fill(0, $p, 0.0);

            for ($i = 0; $i < $n; $i++) {
                $weight = $mu[$i] * (1.0 - $mu[$i]);

                if ($weight < self::MINIMUM_WEIGHT) {
                    continue;
                }

                $z = ($eta[$i] - $offset[$i]) + ((float) $y[$i] - $mu[$i]) / $weight;
                $row = $matrix[$i];

                for ($a = 0; $a < $p; $a++) {
                    $wxa = $weight * $row[$a];
                    $xtwz[$a] += $wxa * $z;

                    for ($b = $a; $b < $p; $b++) {
                        $xtwx[$a][$b] += $wxa * $row[$b];
                    }
                }
            }

            for ($a = 0; $a < $p; $a++) {
                for ($b = 0; $b < $a; $b++) {
                    $xtwx[$a][$b] = $xtwx[$b][$a];
                }
            }

            // The inverse rather than a solve, because the same matrix is the
            // coefficient covariance and throwing it away would mean refitting to
            // get the standard errors back.
            $inverse = self::invert($xtwx);

            if ($inverse === null) {
                return null;
            }

            $covariance = $inverse;
            $beta = [];

            for ($a = 0; $a < $p; $a++) {
                $sum = 0.0;

                for ($b = 0; $b < $p; $b++) {
                    $sum += $inverse[$a][$b] * $xtwz[$b];
                }

                $beta[$a] = $sum;
            }

            for ($i = 0; $i < $n; $i++) {
                $linear = $offset[$i];

                for ($a = 0; $a < $p; $a++) {
                    $linear += $matrix[$i][$a] * $beta[$a];
                }

                $eta[$i] = $linear;
                $mu[$i] = self::logistic($linear);
            }

            $updated = self::deviance($y, $mu);
            $settled = abs($updated - $deviance) / (abs($updated) + 0.1) < self::TOLERANCE;
            $deviance = $updated;

            if ($settled) {
                break;
            }

            if ($iteration === self::MAX_ITERATIONS) {
                return null;
            }
        }

        if ($covariance === null) {
            return null;
        }

        $standardErrors = [];
        $zValues = [];
        $pValues = [];

        for ($a = 0; $a < $p; $a++) {
            $variance = $covariance[$a][$a];
            $error = $variance > 0.0 ? sqrt($variance) : NAN;
            $z = $error > 0.0 ? $beta[$a] / $error : NAN;

            $standardErrors[$a] = $error;
            $zValues[$a] = $z;
            $pValues[$a] = is_nan($z) ? NAN : self::twoSidedNormalP($z);
        }

        return [
            'coefficients' => $beta,
            'standard_errors' => $standardErrors,
            'z_values' => $zValues,
            'p_values' => $pValues,
            'covariance' => $covariance,
            'deviance' => $deviance,
            'iterations' => $iterations,
        ];
    }

    /**
     * Fitted probabilities for a design matrix under a set of coefficients.
     *
     * @param  list<list<float>>  $matrix
     * @param  list<float>  $coefficients
     * @param  list<float>|null  $offset
     * @return list<float>
     */
    public static function predict(array $matrix, array $coefficients, ?array $offset = null): array
    {
        $out = [];

        foreach ($matrix as $index => $row) {
            $linear = $offset[$index] ?? 0.0;

            foreach ($coefficients as $column => $coefficient) {
                $linear += ($row[$column] ?? 0.0) * $coefficient;
            }

            $out[] = self::logistic($linear);
        }

        return $out;
    }

    /**
     * The inverse logit, written so neither tail overflows.
     *
     * exp() of a large positive number is INF and INF/(1+INF) is NAN, which would
     * turn one confident row into a NAN that then propagates through every
     * summary statistic in the payload. The branch costs nothing and removes the
     * failure entirely.
     */
    public static function logistic(float $eta): float
    {
        if ($eta >= 0.0) {
            return 1.0 / (1.0 + exp(-$eta));
        }

        $exponential = exp($eta);

        return $exponential / (1.0 + $exponential);
    }

    /**
     * Log-odds, clamped away from certainty.
     *
     * A predicted 0 or 1 has an infinite logit, and one of those would take any
     * regression run on these values with it. The clamp is the standard epsilon
     * and it is applied here rather than at each call site so every caller gets
     * the same finite scale.
     */
    public static function logit(float $probability, float $clamp = 1.0e-6): float
    {
        $bounded = min(max($probability, $clamp), 1.0 - $clamp);

        return log($bounded / (1.0 - $bounded));
    }

    /**
     * Area under the ROC curve, by the Mann-Whitney identity.
     *
     * The probability that a randomly chosen positive was scored above a randomly
     * chosen negative. Computed from mid-ranks rather than by sweeping thresholds
     * so ties count as half a win each, which is what a tie is.
     *
     * Null when one class is absent, because AUC is undefined there — not 0.5.
     * Reporting 0.5 for "there was nothing to separate" would put a coin-toss
     * figure on a screen next to figures that mean something.
     *
     * @param  list<float|int>  $y
     * @param  list<float>  $p
     */
    public static function auc(array $y, array $p): ?float
    {
        $outcomes = [];
        $scores = [];

        foreach ($y as $index => $outcome) {
            $score = $p[$index] ?? null;

            if ($score === null || is_nan((float) $score)) {
                continue;
            }

            $outcomes[] = (int) $outcome;
            $scores[] = (float) $score;
        }

        $positives = count(array_filter($outcomes, static fn (int $o): bool => $o === 1));
        $negatives = count($outcomes) - $positives;

        if ($positives === 0 || $negatives === 0) {
            return null;
        }

        $ranks = self::ranks($scores);
        $sum = 0.0;

        foreach ($outcomes as $index => $outcome) {
            if ($outcome === 1) {
                $sum += $ranks[$index];
            }
        }

        return ($sum - $positives * ($positives + 1) / 2.0) / ($positives * $negatives);
    }

    /**
     * Mean squared error of a probability against the outcome.
     *
     * Unlike AUC this punishes a confident wrong answer, which is the failure an
     * officer actually feels, and it is why both are reported rather than either.
     *
     * @param  list<float|int>  $y
     * @param  list<float>  $p
     */
    public static function brier(array $y, array $p): ?float
    {
        $total = 0.0;
        $count = 0;

        foreach ($y as $index => $outcome) {
            $score = $p[$index] ?? null;

            if ($score === null || is_nan((float) $score)) {
                continue;
            }

            $total += (((float) $score) - (float) $outcome) ** 2;
            $count++;
        }

        return $count === 0 ? null : $total / $count;
    }

    /**
     * Mid-ranks: tied values share the average of the positions they occupy.
     *
     * @param  list<float>  $values
     * @return list<float>
     */
    public static function ranks(array $values): array
    {
        $order = array_keys($values);
        usort($order, static fn (int $a, int $b): int => $values[$a] <=> $values[$b]);

        $ranks = array_fill(0, count($values), 0.0);
        $position = 0;
        $count = count($order);

        while ($position < $count) {
            $end = $position;

            while ($end + 1 < $count && $values[$order[$end + 1]] === $values[$order[$position]]) {
                $end++;
            }

            $average = ($position + $end + 2) / 2.0;

            for ($i = $position; $i <= $end; $i++) {
                $ranks[$order[$i]] = $average;
            }

            $position = $end + 1;
        }

        return $ranks;
    }

    /**
     * The sample quantile every general-purpose statistics package calls type 7:
     * linear interpolation between the two order statistics either side of
     * (n - 1) * probability.
     *
     * Named and pinned because there are nine of these definitions in circulation
     * and they disagree at the third decimal place on small samples, which is
     * exactly where a decile boundary lives.
     *
     * @param  list<float>  $sorted  ascending, non-empty
     */
    public static function quantile(array $sorted, float $probability): float
    {
        $count = count($sorted);

        if ($count === 1) {
            return $sorted[0];
        }

        $position = ($count - 1) * $probability;
        $lower = (int) floor($position);
        $fraction = $position - $lower;

        if ($lower + 1 >= $count) {
            return $sorted[$count - 1];
        }

        return $sorted[$lower] + $fraction * ($sorted[$lower + 1] - $sorted[$lower]);
    }

    /**
     * Two-sided p-value for a standard normal deviate: erfc(|z| / sqrt(2)).
     *
     * Written as the complementary error function rather than as 2 * (1 - CDF)
     * because the second form is catastrophic in the tail — 1 - 0.9999999... has
     * no significant digits left — and the tail is where the interesting
     * coefficients live. The intercept on this model sits at z = 11.9, where the
     * honest answer is 1.58e-32 and the naive one is 0.
     */
    public static function twoSidedNormalP(float $z): float
    {
        return self::erfc(abs($z) / M_SQRT2);
    }

    /**
     * The standard normal CDF, for callers that want the one-sided reading.
     */
    public static function normalCdf(float $z): float
    {
        return 0.5 * self::erfc(-$z / M_SQRT2);
    }

    /**
     * The complementary error function, as the regularised upper incomplete
     * gamma Q(1/2, x^2).
     *
     * Two expansions with a crossover, which is the standard treatment: the power
     * series converges quickly near zero and the continued fraction converges
     * quickly away from it, and each is hopeless where the other is good.
     */
    private static function erfc(float $x): float
    {
        if ($x < 0.0) {
            return 2.0 - self::erfc(-$x);
        }

        if ($x === 0.0) {
            return 1.0;
        }

        $squared = $x * $x;

        // exp(-x^2) * x / sqrt(pi) — the common factor of both expansions, kept
        // as one expression so the tail never rounds through 1.
        $scale = exp(-$squared) * $x / M_SQRTPI;

        if ($squared < 1.5) {
            // Series for P(1/2, x^2), i.e. erf(x); erfc is its complement, and
            // near zero the complement loses nothing.
            $term = 2.0;
            $sum = 2.0;

            for ($n = 1; $n < 200; $n++) {
                $term *= $squared / (0.5 + $n);
                $sum += $term;

                if (abs($term) < abs($sum) * 1.0e-17) {
                    break;
                }
            }

            return 1.0 - $sum * $scale;
        }

        // Modified Lentz evaluation of the continued fraction for Q(1/2, x^2).
        $tiny = 1.0e-300;
        $b = $squared + 0.5;
        $c = 1.0 / $tiny;
        $d = 1.0 / $b;
        $h = $d;

        for ($i = 1; $i < 500; $i++) {
            $an = -$i * ($i - 0.5);
            $b += 2.0;
            $d = $an * $d + $b;

            if (abs($d) < $tiny) {
                $d = $tiny;
            }

            $c = $b + $an / $c;

            if (abs($c) < $tiny) {
                $c = $tiny;
            }

            $d = 1.0 / $d;
            $delta = $d * $c;
            $h *= $delta;

            if (abs($delta - 1.0) < 1.0e-17) {
                break;
            }
        }

        return $scale * $h;
    }

    /**
     * Binomial deviance, the quantity IRLS is minimising.
     *
     * @param  list<float|int>  $y
     * @param  list<float>  $mu
     */
    private static function deviance(array $y, array $mu): float
    {
        $total = 0.0;

        foreach ($y as $index => $outcome) {
            $fitted = min(max($mu[$index], 1.0e-15), 1.0 - 1.0e-15);
            $total += ((float) $outcome) * log($fitted) + (1.0 - (float) $outcome) * log(1.0 - $fitted);
        }

        return -2.0 * $total;
    }

    /**
     * Gauss-Jordan inverse with partial pivoting.
     *
     * Null on a singular matrix rather than a pseudo-inverse. See the class
     * docblock: a design that cannot be inverted is a design the caller should
     * not have built, and quietly repairing it here would hand back a coefficient
     * table that looks complete.
     *
     * @param  list<list<float>>  $matrix
     * @return list<list<float>>|null
     */
    private static function invert(array $matrix): ?array
    {
        $size = count($matrix);
        $work = [];

        for ($i = 0; $i < $size; $i++) {
            $work[$i] = array_merge($matrix[$i], array_fill(0, $size, 0.0));
            $work[$i][$size + $i] = 1.0;
        }

        for ($column = 0; $column < $size; $column++) {
            $pivot = $column;

            for ($row = $column + 1; $row < $size; $row++) {
                if (abs($work[$row][$column]) > abs($work[$pivot][$column])) {
                    $pivot = $row;
                }
            }

            if (abs($work[$pivot][$column]) < 1.0e-13) {
                return null;
            }

            [$work[$column], $work[$pivot]] = [$work[$pivot], $work[$column]];

            $divisor = $work[$column][$column];

            for ($k = 0; $k < 2 * $size; $k++) {
                $work[$column][$k] /= $divisor;
            }

            for ($row = 0; $row < $size; $row++) {
                if ($row === $column) {
                    continue;
                }

                $factor = $work[$row][$column];

                if ($factor === 0.0) {
                    continue;
                }

                for ($k = 0; $k < 2 * $size; $k++) {
                    $work[$row][$k] -= $factor * $work[$column][$k];
                }
            }
        }

        $inverse = [];

        for ($i = 0; $i < $size; $i++) {
            $inverse[$i] = array_slice($work[$i], $size);
        }

        return $inverse;
    }

    /**
     * @return list<list<float>>
     */
    private static function zeroes(int $rows, int $columns): array
    {
        $out = [];

        for ($i = 0; $i < $rows; $i++) {
            $out[$i] = array_fill(0, $columns, 0.0);
        }

        return $out;
    }
}
