<?php

namespace North\AntiNoKnockBack\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use AntiNoKnockBack\commands\AntiKBCommand;
use North\AntiNoKnockBack\Events\{HitListener, MovementListener};
use North\AntiNoKnockBack\Utils\{ExemptionChecker, StatsCalculator};

class Main extends PluginBase {

    private Config $config;
    private ExemptionChecker $exemptionChecker;
    private StatsCalculator $statsCalculator;
    private array $testProjectiles = [];

    public function onEnable(): void {
        $this->initConfig();
        $this->registerComponents();
        $this->getLogger()->info(TextFormat::GREEN . "AntiNoKnockBack activé avec succès!");
    }

    private function initConfig(): void {
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "antikb" => [
                "max_no_kb_hits" => 3,
                "min_expected_kb" => 0.25,
                "tolerance_ms" => 300,
                "simulate_knockback" => true,
                "auto_freeze_on_confirmed" => true,
                "exempt_when_stuck" => true,
                "exempt_when_ping_above" => 250,
                "freeze_duration" => 30,
                "punishment_steps" => [
                    "warning" => 3,
                    "freeze" => 5,
                    "kick" => 8,
                    "ban" => 12
                ]
            ]
        ]);
    }

    private function registerComponents(): void {
        $this->exemptionChecker = new ExemptionChecker($this);
        $this->statsCalculator = new StatsCalculator($this);
        $pm = $this->getServer()->getPluginManager();
        $pm->registerEvents(new HitListener($this), $this);
        $pm->registerEvents(new MovementListener($this), $this);
        $this->getServer()->getCommandMap()->register("antikb", new AntiKBCommand($this));
    }

    public function getExemptionChecker(): ExemptionChecker {
        return $this->exemptionChecker;
    }

    public function getStatsCalculator(): StatsCalculator {
        return $this->statsCalculator;
    }

    public function getKBConfig(): array {
        return $this->config->get("antikb", []);
    }

    public function addTestProjectile(string $playerName): void {
        $this->testProjectiles[$playerName] = ($this->testProjectiles[$playerName] ?? 0) + 1;
    }

    public function removeTestProjectile(string $playerName): void {
        unset($this->testProjectiles[$playerName]);
    }

    public function hasTestProjectile(string $playerName): bool {
        return isset($this->testProjectiles[$playerName]);
    }

    public function reloadConfiguration(): void {
        $this->config->reload();
        $this->getLogger()->info(TextFormat::YELLOW . "Configuration rechargée!");
    }

    public function freezePlayer(Player $player, ?int $duration = null): void {
        $duration = $duration ?? $this->getKBConfig()["freeze_duration"];
        $player->setImmobile(true);

        $player->sendTitle(
            TextFormat::RED . "DÉTECTION ANTI-TRICHE",
            TextFormat::GOLD . "NoKnockBack détecté",
            20, 60, 20
        );

        $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(fn() => $player->setImmobile(false)),
            $duration * 20
        );
    }

    public function applyPunishment(Player $player, int $severity): void {
        $steps = $this->getKBConfig()["punishment_steps"];

        if ($severity >= $steps["ban"]) {
            $player->ban("Utilisation de NoKnockBack (Niveau $severity)");
        } elseif ($severity >= $steps["kick"]) {
            $player->kick("Suspicion de triche (NoKB)");
        } elseif ($severity >= $steps["freeze"]) {
            $this->freezePlayer($player);
        } elseif ($severity >= $steps["warning"]) {
            $player->sendMessage(TextFormat::RED . "Avertissement Anti-Cheat: Comportement anormal détecté");
        }
    }
}