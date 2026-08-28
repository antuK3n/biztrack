<?php

use App\Support\Glm;

/*
 * The arithmetic under the fitted renewal model, checked on its own.
 *
 * ── WHY THIS EXISTS ALONGSIDE RenewalModelFitTest ───────────────────────────
 *
 * That test feeds a captured register through the whole pipeline and compares the
 * result against a payload the previous statistics runtime produced for the same
 * input. It is the stronger evidence of the two and it is not duplicated here.
 *
 * But it is evidence of one thing: that this implementation agrees with the one it
 * replaced, on one dataset. It cannot say WHICH PART is right, and it cannot say
 * anything at all about the paths that dataset does not reach. A singular design,
 * a perfectly separating column, a fit that never settles — the golden dataset
 * takes none of those branches, and those branches are precisely where the
 * feature's honesty lives, because each of them is a `null` that RenewalModelAnalytics
 * turns into `available => false` and a stated reason. A branch that is never
 * exercised is a branch that is asserted about rather than tested.
 *
 * So the cases below are chosen so the right answer is known INDEPENDENTLY of any
 * implementation: derived in closed form, or countable by hand off the page. None
 * of the expected values was read from this code's output. Where a figure is a
 * standard published one — the two-sided normal p at z = 1.96 — it is written as
 * the published number rather than as whatever this file computes.
 */

/**
 * Expand a 2×2 contingency table into the row-wise design matrix and outcome
 * vector a logistic fit takes.
 *
 * @param  array{0: int, 1: int}  $atZero  [successes, failures] where x = 0
 * @param  array{0: int, 1: int}  $atOne  [successes, failures] where x = 1
 * @return array{0: list<list<float>>, 1: list<int>}
 */
function glmTwoByTwo(array $atZero, array $atOne): array
{
    $matrix = [];
    $outcomes = [];

    foreach ([[0.0, $atZero], [1.0, $atOne]] as [$x, $cells]) {
        foreach ([[1, $cells[0]], [0, $cells[1]]] as [$outcome, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $matrix[] = [1.0, $x];
                $outcomes[] = $outcome;
            }
        }
    }

    return [$matrix, $outcomes];
}

/*
 * ── THE FIT, AGAINST A CLOSED FORM ──────────────────────────────────────────
 *
 * A logistic regression on a single binary predictor is SATURATED: two parameters
 * describing two groups. It therefore has an exact maximum-likelihood solution
 * that can be written down without iterating anything, which makes it the one
 * case where "is IRLS converging to the right place" is a question with a
 * checkable answer rather than a plausible-looking table.
 *
 * For a table with a successes and b failures at x = 0, and c successes and d
 * failures at x = 1:
 *
 *   intercept = log(a / b)                      — the log-odds in the reference group
 *   slope     = log((c / d) / (a / b))          — the log odds ratio
 *   se(intercept) = sqrt(1/a + 1/b)
 *   se(slope)     = sqrt(1/a + 1/b + 1/c + 1/d)
 *
 * The standard errors come from the inverse of the observed information, which is
 * the same matrix binomial() keeps from its last iteration and hands back as the
 * covariance — so checking them checks that, and not merely the coefficients.
 */

it('recovers the exact maximum-likelihood solution of a saturated 2x2 table', function () {
    // x = 0: 10 late, 20 punctual (odds 0.5).  x = 1: 30 late, 10 punctual (odds 3).
    [$matrix, $outcomes] = glmTwoByTwo([10, 20], [30, 10]);

    $fit = Glm::binomial($matrix, $outcomes);

    expect($fit)->not->toBeNull();

    // log(0.5) and log(6), to a tolerance far tighter than the three decimal
    // places anything downstream is ever rounded to.
    expect($fit['coefficients'][0])->toBeGreaterThan(log(0.5) - 1e-9)->toBeLessThan(log(0.5) + 1e-9);
    expect($fit['coefficients'][1])->toBeGreaterThan(log(6.0) - 1e-9)->toBeLessThan(log(6.0) + 1e-9);

    /*
     * The standard errors are held to 1e-6 rather than 1e-9, and the gap is not
     * slack. They are read off the weights of the FINAL iterate, so they inherit
     * the convergence tolerance rather than the machine epsilon the coefficients
     * reach. 1e-6 is still three orders tighter than the third decimal place these
     * are published at, so a real error in a standard error cannot pass here.
     */
    $expectedInterceptError = sqrt(1 / 10 + 1 / 20);
    $expectedSlopeError = sqrt(1 / 10 + 1 / 20 + 1 / 30 + 1 / 10);

    expect(abs($fit['standard_errors'][0] - $expectedInterceptError))->toBeLessThan(1e-6);
    expect(abs($fit['standard_errors'][1] - $expectedSlopeError))->toBeLessThan(1e-6);

    // z is the estimate over its own error; checking it here is what makes the
    // p-value column downstream a derived figure rather than a third opinion.
    expect(abs($fit['z_values'][1] - log(6.0) / $expectedSlopeError))->toBeLessThan(1e-5);

    // Newton's method on a well-posed logit converges in single figures. If this
    // ever climbs toward MAX_ITERATIONS the step is wrong even when the answer is.
    expect($fit['iterations'])->toBeLessThan(10);
});

it('reproduces the observed group rates when the model is saturated', function () {
    /*
     * A saturated model has one parameter per group, so its fitted probabilities
     * must equal the rates actually observed — 10/30 and 30/40. This is the check
     * that predict() and binomial() share a parameterisation: a design matrix
     * built one way and read back another would still produce a plausible
     * coefficient table, and would fail here.
     */
    [$matrix, $outcomes] = glmTwoByTwo([10, 20], [30, 10]);

    $fit = Glm::binomial($matrix, $outcomes);
    $predicted = Glm::predict([[1.0, 0.0], [1.0, 1.0]], $fit['coefficients']);

    expect(abs($predicted[0] - 10 / 30))->toBeLessThan(1e-9);
    expect(abs($predicted[1] - 30 / 40))->toBeLessThan(1e-9);
});

it('recovers the log-odds of the sample when there is nothing but an intercept', function () {
    // 25 late out of 100: the only estimate available is the overall rate.
    $matrix = array_fill(0, 100, [1.0]);
    $outcomes = [...array_fill(0, 25, 1), ...array_fill(0, 75, 0)];

    $fit = Glm::binomial($matrix, $outcomes);

    expect(abs($fit['coefficients'][0] - log(25 / 75)))->toBeLessThan(1e-9);
    expect(abs($fit['standard_errors'][0] - sqrt(1 / 25 + 1 / 75)))->toBeLessThan(1e-6);

    // And the fitted probability is the observed rate, exactly.
    expect(abs(Glm::predict([[1.0]], $fit['coefficients'])[0] - 0.25))->toBeLessThan(1e-9);
});

it('subtracts a fixed offset from the intercept it estimates', function () {
    /*
     * The offset is a per-row term added to the linear predictor and NOT
     * estimated. It has exactly one caller — calibration-in-the-large, which pins
     * the model's own log-odds at a slope of one and asks what intercept is left
     * over — and if it were silently ignored that caller would report a
     * calibration intercept of zero for every model ever fitted, which is a
     * passing-looking number for a check that never ran.
     *
     * With a constant offset c and nothing but an intercept, the answer is forced:
     * the linear predictor must still reach the observed log-odds, so the fitted
     * intercept is logit(0.25) - c.
     */
    $matrix = array_fill(0, 100, [1.0]);
    $outcomes = [...array_fill(0, 25, 1), ...array_fill(0, 75, 0)];
    $offset = array_fill(0, 100, 0.4);

    $fit = Glm::binomial($matrix, $outcomes, $offset);

    expect(abs($fit['coefficients'][0] - (log(25 / 75) - 0.4)))->toBeLessThan(1e-9);

    // The offset must reach predict() too, or the calibration reading and the
    // figures it judges would be on two different scales.
    $withOffset = Glm::predict([[1.0]], $fit['coefficients'], [0.4]);
    expect(abs($withOffset[0] - 0.25))->toBeLessThan(1e-9);
});

/*
 * ── THE REFUSALS ────────────────────────────────────────────────────────────
 *
 * Each of these returns null, and each null is what RenewalModelAnalytics turns
 * into `available => false` with a named reason instead of a coefficient table.
 * They are the reason the feature is allowed to claim anything at all: a fit that
 * repaired its own design and carried on would produce a complete-looking table
 * with an undefined number in it, which is worse than no table because it reads
 * as a finding.
 */

it('refuses a perfectly separating column rather than returning the last iterate', function () {
    /*
     * x predicts y exactly. The likelihood has no finite maximum — the true MLE is
     * an infinite coefficient — so every iteration makes the estimate larger and
     * the loop is stopped by MAX_ITERATIONS. The last iterate is a large number
     * with an enormous standard error, and handing it back would put "-14.17 (se
     * 378)" in front of an officer as though it meant something.
     */
    $matrix = [[1.0, 0.0], [1.0, 0.0], [1.0, 0.0], [1.0, 1.0], [1.0, 1.0], [1.0, 1.0]];
    $outcomes = [0, 0, 0, 1, 1, 1];

    expect(Glm::binomial($matrix, $outcomes))->toBeNull();
});

it('refuses a singular design rather than quietly dropping a column', function () {
    // Two identical predictors: X'WX cannot be inverted, and there is no unique
    // answer to report. See the Glm docblock — repairing this here would hand back
    // a table with a term missing that the screen presents as complete.
    $matrix = [];
    $outcomes = [];

    for ($i = 0; $i < 40; $i++) {
        $value = (float) ($i % 2);
        $matrix[] = [1.0, $value, $value];
        $outcomes[] = $i % 3 === 0 ? 1 : 0;
    }

    expect(Glm::binomial($matrix, $outcomes))->toBeNull();
});

it('refuses a design with more columns than rows, and a mismatched outcome vector', function () {
    // Fewer observations than parameters: nothing is identified.
    expect(Glm::binomial([[1.0, 2.0, 3.0]], [1]))->toBeNull();

    // An outcome vector that does not line up with the matrix is a caller bug, and
    // fitting the overlap would hide it.
    expect(Glm::binomial([[1.0], [1.0]], [1]))->toBeNull();

    expect(Glm::binomial([], []))->toBeNull();
});

/*
 * ── DISCRIMINATION, COUNTED BY HAND ─────────────────────────────────────────
 *
 * AUC here is the Mann-Whitney statistic: over every (positive, negative) pair,
 * the share the positive was scored above, counting a tie as half. On a handful of
 * rows that share can be counted off the page, which is the point of these cases —
 * they are not "does it return something in [0, 1]".
 */

it('computes AUC as the share of correctly ordered pairs', function () {
    /*
     * Scores 0.9, 0.8, 0.7, 0.6 with outcomes 1, 0, 1, 0.
     * Positives {0.9, 0.7}, negatives {0.8, 0.6}. Four pairs:
     *   0.9 > 0.8 win, 0.9 > 0.6 win, 0.7 < 0.8 loss, 0.7 > 0.6 win  →  3/4.
     */
    expect(Glm::auc([1, 0, 1, 0], [0.9, 0.8, 0.7, 0.6]))->toBe(0.75);

    /*
     * Positives {0.9, 0.4}, negatives {0.8, 0.3, 0.2}. Six pairs, five of them
     * ordered correctly — only 0.4 against 0.8 goes the wrong way  →  5/6.
     */
    expect(Glm::auc([1, 1, 0, 0, 0], [0.9, 0.4, 0.8, 0.3, 0.2]))
        ->toBeGreaterThan(5 / 6 - 1e-12)->toBeLessThan(5 / 6 + 1e-12);

    // Every positive above every negative, and the same set scored backwards.
    expect(Glm::auc([0, 0, 1, 1], [0.1, 0.2, 0.8, 0.9]))->toBe(1.0);
    expect(Glm::auc([1, 1, 0, 0], [0.1, 0.2, 0.8, 0.9]))->toBe(0.0);
});

it('counts a tied pair as half a win, which is what a tie is', function () {
    // One positive, one negative, same score: the single pair is a tie.
    expect(Glm::auc([1, 0], [0.5, 0.5]))->toBe(0.5);

    /*
     * Positives {0.9, 0.5}, negatives {0.5, 0.1}. Three clear wins and one tie
     * between the two 0.5s  →  3.5 / 4. This is the case that fails if AUC is
     * computed by sweeping thresholds and joining the points with straight lines
     * instead of from mid-ranks.
     */
    expect(Glm::auc([1, 1, 0, 0], [0.9, 0.5, 0.5, 0.1]))->toBe(0.875);
});

it('reports AUC as null when one class is absent, rather than as a coin toss', function () {
    /*
     * With no negatives there is no pair to order and the statistic is undefined.
     * Returning 0.5 would put a number that reads as "no better than chance" next
     * to numbers that mean something — a different and much worse claim than "there
     * was nothing here to measure". horizon_auc leans on this directly: every cycle
     * one day from expiry was late, and that row must show a blank, not a 0.5.
     */
    expect(Glm::auc([1, 1, 1], [0.1, 0.2, 0.3]))->toBeNull();
    expect(Glm::auc([0, 0, 0], [0.1, 0.2, 0.3]))->toBeNull();
    expect(Glm::auc([], []))->toBeNull();
});

it('scores the Brier as the mean squared error against the outcome', function () {
    // ((0.75 - 1)^2 + (0.25 - 0)^2) / 2 = (0.0625 + 0.0625) / 2.
    expect(Glm::brier([1, 0], [0.75, 0.25]))->toBe(0.0625);

    // Certain and right, then certain and wrong: the ends of the scale.
    expect(Glm::brier([1, 0], [1.0, 0.0]))->toBe(0.0);
    expect(Glm::brier([1, 0], [0.0, 1.0]))->toBe(1.0);

    // A constant 0.5 scores 0.25 whatever the outcomes are, which is the reference
    // a skill score is read against.
    expect(Glm::brier([1, 0, 1, 1], [0.5, 0.5, 0.5, 0.5]))->toBe(0.25);

    expect(Glm::brier([], []))->toBeNull();
});

it('averages the positions of tied values when ranking', function () {
    // 20 occupies positions 2 and 3, so both take 2.5.
    expect(Glm::ranks([10.0, 20.0, 20.0, 30.0]))->toBe([1.0, 2.5, 2.5, 4.0]);

    // Ranks follow the values, not the order they arrived in.
    expect(Glm::ranks([30.0, 10.0, 20.0]))->toBe([3.0, 1.0, 2.0]);

    // All tied: every value takes the mean of 1..4.
    expect(Glm::ranks([7.0, 7.0, 7.0, 7.0]))->toBe([2.5, 2.5, 2.5, 2.5]);
});

it('interpolates quantiles the way every general-purpose package does', function () {
    /*
     * Type 7: linear interpolation at (n - 1) * p. On [1, 2, 3, 4] the median sits
     * at position 1.5, i.e. halfway between 2 and 3. Pinned because the nine
     * competing definitions disagree exactly where a decile boundary lives, and a
     * calibration table is ten decile boundaries.
     */
    $sorted = [1.0, 2.0, 3.0, 4.0];

    expect(Glm::quantile($sorted, 0.0))->toBe(1.0);
    expect(Glm::quantile($sorted, 0.25))->toBe(1.75);
    expect(Glm::quantile($sorted, 0.5))->toBe(2.5);
    expect(Glm::quantile($sorted, 0.75))->toBe(3.25);
    expect(Glm::quantile($sorted, 1.0))->toBe(4.0);

    // A single observation is its own every quantile.
    expect(Glm::quantile([5.0], 0.5))->toBe(5.0);
});

it('keeps both tails of the link finite', function () {
    /*
     * exp() of a large positive number is INF, and INF / (1 + INF) is NAN. One such
     * row would propagate a NAN through the Brier score, the calibration bins and
     * the mean predicted rate — every summary in the payload at once — so the
     * branch in logistic() is load-bearing rather than defensive.
     */
    expect(Glm::logistic(1000.0))->toBe(1.0);
    expect(Glm::logistic(-1000.0))->toBe(0.0);
    expect(Glm::logistic(0.0))->toBe(0.5);
    expect(is_nan(Glm::logistic(1000.0)))->toBeFalse();
    expect(is_nan(Glm::logistic(-1000.0)))->toBeFalse();

    // And the inverse is clamped, so a predicted 0 or 1 cannot hand an infinite
    // log-odds to the calibration regression.
    expect(is_finite(Glm::logit(0.0)))->toBeTrue();
    expect(is_finite(Glm::logit(1.0)))->toBeTrue();
    expect(Glm::logit(0.5))->toBe(0.0);
});

it('computes the two-sided normal p-value to published values, including in the tail', function () {
    /*
     * The published figures. z = 1.96 is the one every reader knows, and it is here
     * as 0.0499958 rather than 0.05 because that is what it actually is.
     */
    expect(abs(Glm::twoSidedNormalP(1.96) - 0.0499957903))->toBeLessThan(1e-9);
    expect(abs(Glm::twoSidedNormalP(1.0) - 0.3173105079))->toBeLessThan(1e-9);
    expect(Glm::twoSidedNormalP(0.0))->toBe(1.0);

    // Symmetric in the sign of z.
    expect(Glm::twoSidedNormalP(-1.96))->toBe(Glm::twoSidedNormalP(1.96));

    /*
     * The reason erfc is written as the incomplete gamma rather than as
     * 2 * (1 - CDF): at z = 11.876 — the intercept's z on the golden fixture — the
     * naive form has no significant digits left and returns a flat 0. The honest
     * answer is around 1.58e-32, and a p-value column that collapses to zero in
     * the tail cannot be read for its order of magnitude, which is the only thing
     * a p-value is read for.
     */
    $tail = Glm::twoSidedNormalP(11.876);
    expect($tail)->toBeGreaterThan(0.0);
    expect(abs($tail - 1.577e-32))->toBeLessThan(1e-35);

    expect(abs(Glm::normalCdf(1.96) - 0.9750021049))->toBeLessThan(1e-9);
    expect(Glm::normalCdf(0.0))->toBe(0.5);
});

/*
 * ── THE DESIGN MATRIX ───────────────────────────────────────────────────────
 *
 * Treatment contrasts with the FIRST declared level as the reference, and the
 * levels taken from the specification rather than read off the data. The second
 * half is the one worth a test: if levels were inferred per call, a refresh in
 * which nobody filed a draft would renumber every contrast, and two coefficient
 * tables from two nights would stop being comparable with nothing looking wrong.
 */

it('builds treatment contrasts against the first declared level', function () {
    $rows = [
        ['stage' => 'none', 'days' => 10.0],
        ['stage' => 'submitted', 'days' => 20.0],
        ['stage' => 'approved', 'days' => 30.0],
    ];

    $terms = [
        ['name' => 'stage', 'kind' => 'factor', 'levels' => ['none', 'submitted', 'approved']],
        ['name' => 'days', 'kind' => 'numeric', 'levels' => []],
    ];

    $design = Glm::design($rows, $terms);

    // The intercept is always column zero, the reference level gets no column of
    // its own, and the numeric term follows the dummies.
    expect($design['columns'])->toBe(['(Intercept)', 'stagesubmitted', 'stageapproved', 'days']);

    expect($design['matrix'])->toBe([
        [1.0, 0.0, 0.0, 10.0],
        [1.0, 1.0, 0.0, 20.0],
        [1.0, 0.0, 1.0, 30.0],
    ]);
});

it('takes factor levels from the specification, not from the rows it is given', function () {
    /*
     * No row here is 'approved', but the level was declared, so it keeps its
     * column — all zeroes. That is what makes two refreshes comparable: the
     * coefficient in position three means the same thing on a night when nobody
     * reached that stage as on a night when somebody did.
     */
    $design = Glm::design(
        [['stage' => 'none'], ['stage' => 'submitted']],
        [['name' => 'stage', 'kind' => 'factor', 'levels' => ['none', 'submitted', 'approved']]],
    );

    expect($design['columns'])->toBe(['(Intercept)', 'stagesubmitted', 'stageapproved']);
    expect($design['matrix'])->toBe([[1.0, 0.0, 0.0], [1.0, 1.0, 0.0]]);

    // A level the specification does not declare is not a column either: it reads
    // as the reference, which is what align() has already folded it onto.
    $unknown = Glm::design(
        [['stage' => 'invented']],
        [['name' => 'stage', 'kind' => 'factor', 'levels' => ['none', 'submitted']]],
    );

    expect($unknown['matrix'])->toBe([[1.0, 0.0]]);
});

it('recovers the coefficients a multi-column dataset was generated from', function () {
    /*
     * The closed-form cases above pin one and two parameters. This one covers the
     * shape they cannot — a numeric column and a dummy fitted together, which is
     * the shape the real model has — and it still has a known answer, by running
     * the model backwards.
     *
     * Three coefficients are CHOSEN, the implied probability is computed at each
     * combination of predictors, and the rows are emitted in exactly those
     * proportions. The data therefore follows the chosen curve by construction, so
     * maximum likelihood must land back on the coefficients that generated it. The
     * only thing standing between the fit and the exact values is that a group of
     * 200 rows can only represent a probability to the nearest 1/200, which is why
     * the tolerance is 0.01 and not 1e-9.
     *
     * Note what this would catch that a sign check would not: a step-halving bug,
     * or weights applied on the wrong scale, converges to a plausible answer with
     * the right signs and the wrong magnitudes.
     */
    $trueIntercept = 1.0;
    $trueDays = -0.3;
    $trueFlag = 0.8;

    $matrix = [];
    $outcomes = [];

    for ($days = 0; $days < 10; $days++) {
        foreach ([0, 1] as $flag) {
            $probability = Glm::logistic($trueIntercept + $trueDays * $days + $trueFlag * $flag);
            $late = (int) round(200 * $probability);

            for ($row = 0; $row < 200; $row++) {
                $matrix[] = [1.0, (float) $days, (float) $flag];
                $outcomes[] = $row < $late ? 1 : 0;
            }
        }
    }

    $fit = Glm::binomial($matrix, $outcomes);

    expect($fit)->not->toBeNull();

    expect(abs($fit['coefficients'][0] - $trueIntercept))->toBeLessThan(0.01);
    expect(abs($fit['coefficients'][1] - $trueDays))->toBeLessThan(0.01);
    expect(abs($fit['coefficients'][2] - $trueFlag))->toBeLessThan(0.01);

    // Every standard error finite and small — the marker that nothing separated.
    foreach ($fit['standard_errors'] as $error) {
        expect(is_finite($error))->toBeTrue();
        expect($error)->toBeLessThan(1.0);
    }

    expect($fit['iterations'])->toBeLessThan(10);
});
