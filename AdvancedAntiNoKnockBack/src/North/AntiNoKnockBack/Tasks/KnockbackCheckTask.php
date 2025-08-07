<?php

namespace North\AntiNoKnockBack\Tasks\KnockbackCheckTask;

use pocketmine\scheduler\Task;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use North\AntiNoKnockBack\Main;
use North\AntiNoKnockBack\Utils\StatsCalculator;
use pocketmine\utils\TextFormat;

class KnockbackCheckTask extends Task {

    private Main $plugin;
    private Player $player;
    private Vector3 $initialPosition;
    private float $checkTime;
    private bool $projectileTest;

    public function __construct(
        Main $plugin,
        Player $player,
        Vector3 $initialPosition,
        bool $projectileTest = false
    ) {
        $this->plugin = $plugin;
        $this->player = $player;
        $this->initialPosition = $initialPosition;
        $this->checkTime = microtime(true);
        $this->projectileTest = $projectileTest;
    }

    public function onRun(): void {
        if (!$this->player->isOnline()) {
            $this->getHandler()?->cancel();
            return;
        }

        $currentPos = $this->player->getPosition();
        $distanceMoved = $this->initialPosition->distance($currentPos);
        $statsCalculator = $this->plugin->getStatsCalculator();
        $exemptionChecker = $this->plugin->getExemptionChecker();
        if ($exemptionChecker->isExempt($this->player)) {
            $this->plugin->getLogger()->debug("Exemption pour " . $this->player->getName() . ": " . $exemptionChecker->getExemptionReason($this->player));
            $this->getHandler()?->cancel();
            return;
        }

        $config = $this->plugin->getKBConfig();
        $minExpectedKB = $config['min_expected_kb'];
        $elapsedTime = microtime(true) - $this->checkTime;
        if ($distanceMoved < 0.1) {
            $this->handleNoMovement($elapsedTime);
            return;
        }

        if ($distanceMoved < $minExpectedKB) {
            $this->handleInsufficientMovement($distanceMoved, $minExpectedKB);
            return;
        }

        $this->handleValidMovement($distanceMoved);
    }

    private function handleNoMovement(float $elapsedTime): void {
        $playerName = $this->player->getName();
        $statsCalculator = $this->plugin->getStatsCalculator();
        $statsCalculator->recordHit($this->player);
        if ($this->projectileTest) {
            $this->plugin->getLogger()->warning("ÉCHEC test projectile pour $playerName - Aucun mouvement détecté");

            $this->player->sendMessage(TextFormat::RED . "Anomalie Anti-Cheat: Réponse au KB anormale");

            $this->plugin->applyPunishment($this->player, $statsCalculator->calculateSuspicionLevel($this->player));
        } else {
            $this->plugin->getLogger()->debug("Suspicion NoKB pour $playerName - Distance: 0 blocs");
        }

        $this->getHandler()?->cancel();
    }

    private function handleInsufficientMovement(float $distance, float $expected): void {
        $playerName = $this->player->getName();
        $percentage = ($distance / $expected) * 100;
        $this->plugin->getLogger()->debug("KB réduit détecté pour $playerName: " . round($distance, 2) . " blocs (" . round($percentage) . "% de l'attendu)"
        );

        $this->plugin->getStatsCalculator()->recordMovement($this->player, $this->player->getPosition());
        if ($percentage < 50) {
            $this->plugin->applyPunishment($this->player, $this->plugin->getStatsCalculator()->calculateSuspicionLevel($this->player));
        }

        $this->getHandler()?->cancel();
    }

    private function handleValidMovement(float $distance): void {
        $playerName = $this->player->getName();
        $this->plugin->getLogger()->debug("KB normal détecté pour $playerName: " . round($distance, 2) . " blocs");
        $this->plugin->getStatsCalculator()->recordMovement($this->player, $this->player->getPosition());
        $this->getHandler()?->cancel();
    }

    public function onCancel(): void {
        unset(
            $this->plugin,
            $this->player,
            $this->initialPosition
        );
    }
}