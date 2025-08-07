<?php

declare(strict_types=1);

namespace North\AntiAutoClick\Detectors\CPSTracker;

use pocketmine\player\Player;
use North\AntiAutoClick\Utils\MathUtils;

class CPSTracker {
    private const CLICK_HISTORY_DURATION = 5;
    private const SAMPLE_INTERVAL = 1;
    private const MAX_SAMPLES = 30;

    private array $clickData = [];

    public function recordClick(Player $player): void {
        $playerName = $player->getName();
        $currentTime = microtime(true);

        if (!isset($this->clickData[$playerName])) {
            $this->initPlayerData($playerName);
        }

        $this->clickData[$playerName]['clicks'][] = $currentTime;
        $this->cleanOldClicks($playerName, $currentTime);
    }

    private function initPlayerData(string $playerName): void {
        $this->clickData[$playerName] = [
            'clicks' => [],
            'cpsHistory' => [],
            'lastSampleTime' => microtime(true)
        ];
    }

    private function cleanOldClicks(string $playerName, float $currentTime): void {
        $threshold = $currentTime - self::CLICK_HISTORY_DURATION;

        $this->clickData[$playerName]['clicks'] = array_filter(
            $this->clickData[$playerName]['clicks'],
            fn($clickTime) => $clickTime >= $threshold
        );

        $this->clickData[$playerName]['clicks'] = array_values($this->clickData[$playerName]['clicks']);
    }

    public function updateCpsHistory(string $playerName): void {
        $currentTime = microtime(true);

        if (!isset($this->clickData[$playerName]) ||
            $currentTime - $this->clickData[$playerName]['lastSampleTime'] < self::SAMPLE_INTERVAL) {
            return;
        }

        $cps = $this->calculateCurrentCPS($playerName);
        $this->clickData[$playerName]['cpsHistory'][] = $cps;

        if (count($this->clickData[$playerName]['cpsHistory']) > self::MAX_SAMPLES) {
            array_shift($this->clickData[$playerName]['cpsHistory']);
        }

        $this->clickData[$playerName]['lastSampleTime'] = $currentTime;
    }

    public function calculateCurrentCPS(string $playerName): float {
        if (!isset($this->clickData[$playerName]) || count($this->clickData[$playerName]['clicks']) < 2) {
            return 0.0;
        }

        $clickTimes = $this->clickData[$playerName]['clicks'];
        $timeSpan = end($clickTimes) - reset($clickTimes);

        return $timeSpan > 0 ? count($clickTimes) / $timeSpan : 0.0;
    }

    public function getCpsHistory(string $playerName): array {
        return $this->clickData[$playerName]['cpsHistory'] ?? [];
    }

    public function getRecentCps(string $playerName, int $seconds = 1): float {
        if (!isset($this->clickData[$playerName])) {
            return 0.0;
        }

        $threshold = microtime(true) - $seconds;
        $recentClicks = array_filter(
            $this->clickData[$playerName]['clicks'],
            fn($clickTime) => $clickTime >= $threshold
        );

        return $seconds > 0 ? count($recentClicks) / $seconds : 0.0;
    }

    public function getClickPatternAnalysis(string $playerName): array {
        if (!isset($this->clickData[$playerName])) {
            return [
                'status' => 'insufficient_data',
                'cps' => 0.0,
                'stability' => 0.0
            ];
        }

        $cpsHistory = $this->getCpsHistory($playerName);
        $currentCps = $this->calculateCurrentCPS($playerName);

        return [
            'status' => 'complete',
            'cps' => $currentCps,
            'average' => MathUtils::calculateMean($cpsHistory),
            'median' => MathUtils::calculateMedian($cpsHistory),
            'stability' => $this->calculateStability($cpsHistory),
            'max' => max($cpsHistory + [0]),
            'min' => min($cpsHistory + [PHP_FLOAT_MAX])
        ];
    }

    private function calculateStability(array $cpsHistory): float {
        if (count($cpsHistory) < 2) {
            return 0.0;
        }

        $mean = MathUtils::calculateMean($cpsHistory);
        $stdDev = MathUtils::calculateStandardDeviation($cpsHistory);

        return $mean > 0 ? ($stdDev / $mean) * 100 : 0.0;
    }

    public function resetPlayerData(string $playerName): void {
        unset($this->clickData[$playerName]);
    }
}