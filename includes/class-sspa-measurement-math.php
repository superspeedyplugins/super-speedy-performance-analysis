<?php
defined('ABSPATH') || exit;
/** Pure comparison arithmetic extracted from History completion bcd2f51 (delta). */
class SSPA_Measurement_Math {
    public static function delta($before, $after) {
        if (null === $before || null === $after) { return array('absolute' => null, 'percent' => null, 'direction' => 'unknown'); }
        $absolute = (float) $after - (float) $before;
        return array('absolute' => round($absolute, 2), 'percent' => 0.0 !== (float) $before ? round(($absolute / abs((float) $before)) * 100, 1) : null,
            'direction' => abs($absolute) < 0.01 ? 'unchanged' : ($absolute > 0 ? 'higher' : 'lower'));
    }
    public static function distribution($values) {
        sort($values, SORT_NUMERIC); $n = count($values);
        return array('samples' => $n, 'median' => $n ? $values[(int) ceil($n * .5) - 1] : null, 'p95' => $n ? $values[(int) ceil($n * .95) - 1] : null);
    }
}
