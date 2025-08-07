<?php

declare(strict_types=1);

namespace North\AntiAutoClick\Detectors\ClickPatternDetector;

use North\AntiAutoClick\Utils\MathUtils;
use pocketmine\player\Player;

class ClickPatternDetector {
    private const MIN_CLICKS_FOR_ANALYSIS = 20;
    private const PERFECT_TIMING_THRESHOLD_MS = 5;
    private const LOW_VARIATION_THRESHOLD = 2.0;
    private const HUMAN_VARIATION_RANGE = [8.0, 25.0];

    public function analyze(array $clickData): array {
        $timestamps = $clickData['timestamps'] ?? [];

        if (count($timestamps) < self::MIN_CLICKS_FOR_ANALYSIS) {
            return ['status' => 'insufficient_data'];
        }

        $intervals = $this->calculateIntervals($timestamps);
        $statistics = $this->calculateStatistics($intervals);
        $patternType = $this->determinePatternType($intervals, $statistics);

        return [
            'status' => 'complete',
            'type' => $patternType,
            'stats' => $statistics,
            'intervals' => $intervals
        ];
    }

    private function calculateIntervals(array $timestamps): array {
        $intervals = [];
        $prev = null;

        foreach ($timestamps as $timestamp) {
            if ($prev !== null) {
                $intervals[] = ($timestamp - $prev) * 1000;
            }
            $prev = $timestamp;
        }

        return $intervals;
    }

    private function calculateStatistics(array $intervals): array {
        return [
            'mean' => MathUtils::calculateMean($intervals),
            'median' => MathUtils::calculateMedian($intervals),
            'variance' => MathUtils::calculateVariance($intervals),
            'std_dev' => MathUtils::calculateStandardDeviation($intervals),
            'min' => min($intervals),
            'max' => max($intervals)
        ];
    }

    private function determinePatternType(array $intervals, array $stats): string {
        if ($this->isPerfectTiming($intervals)) {
            return 'perfect_timing';
        }

        if ($stats['std_dev'] < self::LOW_VARIATION_THRESHOLD) {
            return 'low_variation';
        }

        if ($stats['std_dev'] >= self::HUMAN_VARIATION_RANGE[0] &&
            $stats['std_dev'] <= self::HUMAN_VARIATION_RANGE[1]) {
            return 'human_like';
        }

        if ($this->isAlternatingPattern($intervals)) {
            return 'alternating';
        }

        return 'unknown';
    }

    private function isPerfectTiming(array $intervals): bool {
        if (count($intervals) < 10) return false;

        $first = $intervals[0];
        foreach ($intervals as $interval) {
            if (abs($interval - $first) > self::PERFECT_TIMING_THRESHOLD_MS) {
                return false;
            }
        }
        return true;
    }

    private function isAlternatingPattern(array $intervals): bool {
        if (count($intervals) < 6) return false;

        $patternFound = true;
        $checkValue = $intervals[0];

        for ($i = 2; $i < count($intervals); $i += 2) {
            if (abs($intervals[$i] - $checkValue) > self::PERFECT_TIMING_THRESHOLD_MS) {
                $patternFound = false;
                break;
            }
        }

        if ($patternFound && count($intervals) > 1) {
            $checkValue = $intervals[1];
            for ($i = 3; $i < count($intervals); $i += 2) {
                if (abs($intervals[$i] - $checkValue) > self::PERFECT_TIMING_THRESHOLD_MS) {
                    $patternFound = false;
                    break;
                }
            }
        }

        return $patternFound;
    }

    public function getSuspicionLevel(array $analysis): float {
        switch ($analysis['type']) {
            case 'perfect_timing':
                return 0.95;
            case 'low_variation':
                return 0.85;
            case 'alternating':
                return 0.75;
            case 'human_like':
                return 0.15;
            default:
                return 0.5;
        }
    }
}