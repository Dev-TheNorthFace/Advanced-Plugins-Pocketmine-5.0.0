<?php

namespace North\AntiNoKnockBack\Utils\StatsCalculator;

use pocketmine\player\Player;
use pocketmine\math\Vector3;
use pocketmine\utils\TextFormat;
use North\AntiNoKnockBack\Main;

class StatsCalculator {

    private Main $plugin;
    private array $playerStats = [];
    private array $kbHistory = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function recordHit(Player $player, ?Vector3 $initialPosition = null): void {
        $uuid = $player->getUniqueId()->toString();

        if (!isset($this->playerStats[$uuid])) {
            $this->initPlayerStats($player);
        }

        $this->playerStats[$uuid]['hits_received']++;
        $this->playerStats[$uuid]['last_hit_time'] = microtime(true);

        if ($initialPosition !== null) {
            $this->kbHistory[$uuid][] = [
                'time' => microtime(true),
                'initial' => $initialPosition,
                'final' => null,
                'distance' => 0.0,
                'valid' => false
            ];
        }
    }

    public function recordMovement(Player $player, Vector3 $newPosition): void {
        $uuid = $player->getUniqueId()->toString();

        if (empty($this->kbHistory[$uuid])) return;

        $lastIndex = count($this->kbHistory[$uuid]) - 1;
        $lastRecord = &$this->kbHistory[$uuid][$lastIndex];

        if ($lastRecord['final'] === null) {
            $distance = $lastRecord['initial']->distance($newPosition);

            if ($distance > 0.05) {
                $lastRecord['final'] = $newPosition;
                $lastRecord['distance'] = $distance;
                $lastRecord['valid'] = true;

                $this->playerStats[$uuid]['kb_hits']++;
                $this->playerStats[$uuid]['total_kb_distance'] += $distance;
                $this->playerStats[$uuid]['last_kb_time'] = microtime(true);
            }
        }
    }

    public function calculateSuspicionLevel(Player $player): float {
        $stats = $this->getPlayerStats($player);

        if ($stats['hits_received'] < 5) {
            return 0.0;
        }

        $kbRatio = $stats['kb_hits'] / $stats['hits_received'];
        $avgDistance = $stats['total_kb_distance'] / max(1, $stats['kb_hits']);
        $timeSinceLastKB = isset($stats['last_kb_time']) ?
            microtime(true) - $stats['last_kb_time'] : PHP_FLOAT_MAX;
        $suspicion = (1 - $kbRatio) * 0.6
            + max(0, 1 - ($avgDistance / $this->plugin->getKBConfig()['min_expected_kb'])) * 0.4
            + ($timeSinceLastKB < 5.0 ? 0.2 : 0.0);

        return min(1.0, max(0.0, $suspicion));
    }

    public function getPlayerStats(Player $player): array {
        $uuid = $player->getUniqueId()->toString();
        return $this->playerStats[$uuid] ?? $this->initPlayerStats($player);
    }

    public function getFormattedStats(Player $player): string {
        $stats = $this->getPlayerStats($player);
        $suspicion = $this->calculateSuspicionLevel($player);

        $avgKb = $stats['hits_received'] > 0 ?
            $stats['total_kb_distance'] / max(1, $stats['kb_hits']) : 0.0;

        $kbPercentage = $stats['hits_received'] > 0 ?
            ($stats['kb_hits'] / $stats['hits_received']) * 100 : 0.0;

        $statusColor = $suspicion > 0.7 ? TextFormat::RED :
            ($suspicion > 0.4 ? TextFormat::YELLOW : TextFormat::GREEN);

        return TextFormat::GOLD . "---- Statistiques AntiKB ----\n" .
            TextFormat::YELLOW . "Joueur: " . TextFormat::WHITE . $player->getName() . "\n" .
            TextFormat::YELLOW . "Coups reçus: " . TextFormat::WHITE . $stats['hits_received'] . "\n" .
            TextFormat::YELLOW . "KB enregistrés: " . TextFormat::WHITE . $stats['kb_hits'] . " (" . round($kbPercentage, 1) . "%)\n" .
            TextFormat::YELLOW . "Distance KB moyenne: " . TextFormat::WHITE . round($avgKb, 2) . " blocs\n" .
            TextFormat::YELLOW . "Niveau suspicion: " . $statusColor . round($suspicion * 100) . "%\n" .
            TextFormat::YELLOW . "Dernier KB: " . TextFormat::WHITE .
            (isset($stats['last_kb_time']) ? $this->formatDuration(microtime(true) - $stats['last_kb_time']) : "Jamais");
    }

    public function resetPlayerStats(Player $player): void {
        $uuid = $player->getUniqueId()->toString();
        unset($this->playerStats[$uuid], $this->kbHistory[$uuid]);
    }

    private function initPlayerStats(Player $player): array {
        $uuid = $player->getUniqueId()->toString();

        $this->playerStats[$uuid] = [
            'hits_received' => 0,
            'kb_hits' => 0,
            'total_kb_distance' => 0.0,
            'last_hit_time' => null,
            'last_kb_time' => null
        ];

        return $this->playerStats[$uuid];
    }

    private function formatDuration(float $seconds): string {
        if ($seconds < 1) return round($seconds * 1000) . "ms";
        if ($seconds < 60) return round($seconds, 1) . "s";
        return floor($seconds / 60) . "m " . round($seconds % 60) . "s";
    }

    public function getRecentKBData(Player $player, int $count = 5): array {
        $uuid = $player->getUniqueId()->toString();

        return array_slice($this->kbHistory[$uuid] ?? [], -$count, $count, true);
    }
}