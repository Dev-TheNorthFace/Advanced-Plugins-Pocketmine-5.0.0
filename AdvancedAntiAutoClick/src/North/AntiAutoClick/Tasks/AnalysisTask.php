<?php

declare(strict_types=1);

namespace North\AntiAutoClick\Tasks\AnalysisTask;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use North\AntiAutoClick\Utils\HumanScoreCalculator;
use North\AntiAutoClick\Main;

class AnalysisTask extends AsyncTask {

    private array $playerData;
    private array $config;

    public function __construct(array $playerData, array $config) {
        $this->playerData = $playerData;
        $this->config = $config;
    }

    public function onRun(): void {
        $results = [];
        $calculator = new HumanScoreCalculator();
        foreach ($this->playerData as $playerName => $data) {
            if (count($data['timestamps']) < 5) {
                continue;
            }

            $currentCps = count(array_filter($data['timestamps'], function($t) {
                return microtime(true) - $t <= 1.0;
            }));

            $humanScore = $calculator->calculate($data);
            $humanLevel = $calculator->getHumanLevel($humanScore);
            $patternAnalysis = $this->analyzeClickPattern($data['timestamps']);

            $results[$playerName] = [
                'cps' => $currentCps,
                'score' => $humanScore,
                'level' => $humanLevel,
                'pattern' => $patternAnalysis,
                'violation' => $this->checkViolations($currentCps, $humanScore, $patternAnalysis)
            ];
        }

        $this->setResult($results);
    }

    private function analyzeClickPattern(array $timestamps): array {
        $intervals = [];
        $previous = null;

        foreach ($timestamps as $timestamp) {
            if ($previous !== null) {
                $intervals[] = $timestamp - $previous;
            }
            $previous = $timestamp;
        }

        $analysis = [
            'interval_variation' => $this->calculateVariation($intervals),
            'perfect_timing' => $this->checkPerfectTiming($intervals)
        ];

        return $analysis;
    }

    private function calculateVariation(array $intervals): float {
        if (count($intervals) < 2) return 0;

        $mean = array_sum($intervals) / count($intervals);
        $variance = 0.0;

        foreach ($intervals as $interval) {
            $variance += pow($interval - $mean, 2);
        }

        return sqrt($variance / count($intervals));
    }

    private function checkPerfectTiming(array $intervals): bool {
        if (count($intervals) < 10) return false;

        $first = $intervals[0];
        foreach ($intervals as $interval) {
            if (abs($interval - $first) > $this->config['click_delay_variance']) {
                return false;
            }
        }
        return true;
    }

    private function checkViolations(float $cps, int $score, array $pattern): string {
        if ($cps > $this->config['max_cps']) return 'high_cps';
        if ($score < $this->config['min_human_score']) return 'low_human_score';
        if ($pattern['perfect_timing']) return 'perfect_timing';
        if ($pattern['interval_variation'] < $this->config['min_variation']) return 'low_variation';

        return 'clean';
    }

    public function onCompletion(): void {
        $plugin = Server::getInstance()->getPluginManager()->getPlugin("AdvancedAntiAutoClick");
        if ($plugin instanceof Main && $plugin->isEnabled()) {
            $plugin->processAnalysisResults($this->getResult());
        }
    }
}