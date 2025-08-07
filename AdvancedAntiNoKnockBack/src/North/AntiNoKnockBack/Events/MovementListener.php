<?php

namespace North\AntiNoKnockBack\Events\MovementListener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use North\AntiNoKnockBack\Main;

class MovementListener implements Listener {

    private Main $plugin;
    private array $lastPositions = [];
    private const POSITION_HISTORY_LENGTH = 5;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $from = $event->getFrom();
        $to = $event->getTo();
        if ($this->isVerticalOnlyMovement($from, $to)) {
            return;
        }

        $this->trackPlayerMovement($player, $to);
        $this->analyzeMovementPattern($player, $to);
    }

    private function isVerticalOnlyMovement(Vector3 $from, Vector3 $to): bool {
        return abs($from->x - $to->x) < 0.001 &&
            abs($from->z - $to->z) < 0.001 &&
            abs($from->y - $to->y) >= 0.001;
    }

    private function trackPlayerMovement(Player $player, Vector3 $newPosition): void {
        $uuid = $player->getUniqueId()->toString();

        if (!isset($this->lastPositions[$uuid])) {
            $this->lastPositions[$uuid] = [];
        }

        $this->lastPositions[$uuid][] = [
            'time' => microtime(true),
            'position' => $newPosition->asVector3()
        ];

        if (count($this->lastPositions[$uuid]) > self::POSITION_HISTORY_LENGTH) {
            array_shift($this->lastPositions[$uuid]);
        }
    }

    private function analyzeMovementPattern(Player $player, Vector3 $newPosition): void {
        $uuid = $player->getUniqueId()->toString();
        $statsCalculator = $this->plugin->getStatsCalculator();
        if ($statsCalculator->isUnderKBWatch($player)) {
            $statsCalculator->recordMovement($player, $newPosition);
            $this->checkAbnormalMovement($player, $newPosition);
        }
    }

    private function checkAbnormalMovement(Player $player, Vector3 $newPosition): void {
        $uuid = $player->getUniqueId()->toString();
        $positionHistory = $this->lastPositions[$uuid] ?? [];

        if (count($positionHistory) < 2) return;

        $totalDistance = 0.0;
        $totalTime = 0.0;

        for ($i = 1; $i < count($positionHistory); $i++) {
            $prev = $positionHistory[$i - 1];
            $current = $positionHistory[$i];

            $totalDistance += $prev['position']->distance($current['position']);
            $totalTime += $current['time'] - $prev['time'];
        }

        $averageSpeed = $totalTime > 0 ? $totalDistance / $totalTime : 0;
        $kbConfig = $this->plugin->getKBConfig();
        if ($averageSpeed > $kbConfig['max_normal_speed'] ?? 2.5) {
            $this->handleSuspiciousMovement($player, $averageSpeed);
        }
    }

    private function handleSuspiciousMovement(Player $player, float $speed): void {
        $playerName = $player->getName();
        $statsCalculator = $this->plugin->getStatsCalculator();

        $statsCalculator->incrementAbnormalMovementCount($player);

        $this->plugin->getLogger()->warning(
            "Mouvement anormal détecté pour $playerName - " .
            "Vitesse: " . round($speed, 2) . " blocs/sec"
        );

        $suspicionLevel = $statsCalculator->calculateSuspicionLevel($player);
        if ($suspicionLevel > 0.7) {
            $this->plugin->applyPunishment($player, $suspicionLevel);
        }
    }

    public function clearPlayerData(Player $player): void {
        unset($this->lastPositions[$player->getUniqueId()->toString()]);
    }
}