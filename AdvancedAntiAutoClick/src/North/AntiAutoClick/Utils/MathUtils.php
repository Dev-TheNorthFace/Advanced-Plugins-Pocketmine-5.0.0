<?php

declare(strict_types=1);

namespace North\AntiAutoClick\Utils\MathUtils;

class MathUtils {

    /**
     * Calcule la moyenne d'un tableau de valeurs
     */
    public static function calculateMean(array $values): float {
        if (empty($values)) return 0.0;
        return array_sum($values) / count($values);
    }

    /**
     * Calcule la médiane d'un tableau de valeurs
     */
    public static function calculateMedian(array $values): float {
        if (empty($values)) return 0.0;

        sort($values);
        $count = count($values);
        $mid = (int)floor(($count - 1) / 2);

        return ($count % 2 === 0)
            ? ($values[$mid] + $values[$mid + 1]) / 2
            : $values[$mid];
    }

    /**
     * Calcule la variance d'un échantillon
     */
    public static function calculateVariance(array $values): float {
        if (count($values) < 2) return 0.0;

        $mean = self::calculateMean($values);
        $sum = 0.0;

        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return $sum / count($values);
    }

    /**
     * Calcule l'écart-type
     */
    public static function calculateStandardDeviation(array $values): float {
        return sqrt(self::calculateVariance($values));
    }

    /**
     * Calcule le coefficient de variation (pour normaliser la dispersion)
     */
    public static function calculateCoefficientOfVariation(array $values): float {
        $mean = self::calculateMean($values);
        if ($mean === 0.0) return 0.0;

        return (self::calculateStandardDeviation($values) / $mean) * 100;
    }

    /**
     * Calcule l'asymétrie (skewness) de la distribution
     */
    public static function calculateSkewness(array $values): float {
        if (count($values) < 3) return 0.0;

        $mean = self::calculateMean($values);
        $stdDev = self::calculateStandardDeviation($values);
        $sum = 0.0;

        foreach ($values as $value) {
            $sum += pow(($value - $mean) / $stdDev, 3);
        }

        return ($sum / count($values)) * (count($values) / ((count($values) - 1) * (count($values) - 2));
    }

    /**
     * Calcule le kurtosis (aplatissement) de la distribution
     */
    public static function calculateKurtosis(array $values): float {
        if (count($values) < 4) return 0.0;

        $mean = self::calculateMean($values);
        $stdDev = self::calculateStandardDeviation($values);
        $sum = 0.0;

        foreach ($values as $value) {
            $sum += pow(($value - $mean) / $stdDev, 4);
        }

        $n = count($values);
        return ($sum / $n) * ($n * ($n + 1) / (($n - 1) * ($n - 2) * ($n - 3)))
            - (3 * pow($n - 1, 2) / (($n - 2) * ($n - 3)));
    }

    /**
     * Calcule l'entropie de l'échantillon (mesure du désordre)
     */
    public static function calculateEntropy(array $values): float {
        if (empty($values)) return 0.0;

        $total = array_sum($values);
        if ($total === 0.0) return 0.0;

        $entropy = 0.0;
        foreach ($values as $value) {
            if ($value > 0) {
                $p = $value / $total;
                $entropy -= $p * log($p);
            }
        }

        return $entropy;
    }

    /**
     * Normalise un tableau de valeurs entre 0 et 1
     */
    public static function normalizeArray(array $values): array {
        if (empty($values)) return [];

        $min = min($values);
        $max = max($values);
        $range = $max - $min;

        if ($range === 0.0) {
            return array_fill(0, count($values), 0.5);
        }

        return array_map(function($x) use ($min, $range) {
            return ($x - $min) / $range;
        }, $values);
    }

    /**
     * Calcule la corrélation entre deux tableaux de valeurs
     */
    public static function calculateCorrelation(array $x, array $y): float {
        if (count($x) !== count($y) || count($x) < 2) return 0.0;

        $n = count($x);
        $sumX = array_sum($x);
        $sumY = array_sum($y);

        $sumXY = 0.0;
        $sumX2 = 0.0;
        $sumY2 = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumX2 += $x[$i] ** 2;
            $sumY2 += $y[$i] ** 2;
        }

        $numerator = $sumXY - ($sumX * $sumY / $n);
        $denominator = sqrt(($sumX2 - ($sumX ** 2 / $n)) * ($sumY2 - ($sumY ** 2 / $n)));

        return $denominator === 0.0 ? 0.0 : $numerator / $denominator;
    }
}