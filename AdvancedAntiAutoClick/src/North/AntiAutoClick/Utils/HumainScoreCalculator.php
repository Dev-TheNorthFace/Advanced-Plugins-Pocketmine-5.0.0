<?php

declare(strict_types=1);

namespace North\AntiAutoClick\Utils\HumainScoreCalculator;

class HumanScoreCalculator {

    private const MAX_CPS_PENALTY = 30;
    private const PERFECT_TIMING_PENALTY = 40;
    private const BASE_SCORE = 100;

    public function calculate(array $clickData): int {
        if (count($clickData['timestamps']) < 10) {
            return self::BASE_SCORE;
        }

        $score = self::BASE_SCORE;
        $score -= $this->calculateCpsPenalty($clickData['cpsHistory']);
        $score -= $this->calculateTimingPenalty($clickData['clickDelays']);
        $score -= $this->calculatePatternPenalty($clickData['timestamps']);

        return max(0, $score);
    }

    private function calculateCpsPenalty(array $cpsHistory): int {
        $maxCps = max($cpsHistory);
        if ($maxCps <= 15) return 0;

        $penalty = min(
            self::MAX_CPS_PENALTY,
            ($maxCps - 15) * 2
        );

        return (int)$penalty;
    }

    private function calculateTimingPenalty(array $clickDelays): int {
        if (count($clickDelays) < 5) return 0;

        $firstDelay = $clickDelays[0];
        $perfectCount = 0;

        foreach ($clickDelays as $delay) {
            if (abs($delay - $firstDelay) <= 2) {
                $perfectCount++;
            }
        }

        $perfectRatio = $perfectCount / count($clickDelays);
        return $perfectRatio > 0.8 ? self::PERFECT_TIMING_PENALTY : 0;
    }

    private function calculatePatternPenalty(array $timestamps): int {
        if (count($timestamps) < 20) return 0;

        $intervals = [];
        $previous = null;

        foreach ($timestamps as $timestamp) {
            if ($previous !== null) {
                $intervals[] = $timestamp - $previous;
            }
            $previous = $timestamp;
        }

        $uniqueIntervals = array_unique($intervals);
        $variationScore = (count($uniqueIntervals) / count($intervals)) * 100;

        if ($variationScore < 30) return 25;
        if ($variationScore < 50) return 15;

        return 0;
    }

    public function getHumanLevel(int $score): string {
        if ($score >= 80) return "§aHumain";
        if ($score >= 60) return "§eIncertain";
        if ($score >= 40) return "§6Suspect";
        if ($score >= 20) return "§cRobot probable";
        return "§4Robot confirmé";
    }
}