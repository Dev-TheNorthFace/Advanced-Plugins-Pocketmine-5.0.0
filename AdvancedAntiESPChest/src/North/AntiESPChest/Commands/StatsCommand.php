<?php

declare(strict_types=1);

namespace North\AntiESPChest\Commands\StatsCommand;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use North\AntiESPChest\Main;

class StatsCommand extends Command {

    private Main $plugin;

    public function __construct(Main $plugin) {
        parent::__construct(
            "espstats",
            "Affiche les statistiques de détection ESP",
            "/espstats [joueur]",
            ["estats"]
        );
        $this->setPermission("antiespchest.command.stats");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): void {
        if (!$this->testPermission($sender)) {
            return;
        }

        $target = $sender;
        if (isset($args[0])) {
            $target = $this->plugin->getServer()->getPlayerExact($args[0]);
            if ($target === null) {
                $sender->sendMessage(TF::RED . "Joueur introuvable!");
                return;
            }
        }

        if (!$target instanceof Player) {
            $sender->sendMessage(TF::RED . "Vous devez spécifier un joueur!");
            return;
        }

        $stats = $this->getDetailedStats($target);
        $sender->sendMessage($stats);
    }

    private function getDetailedStats(Player $player): string {
        $name = $player->getName();
        $data = $this->plugin->getPlayerData($name) ?? [];
        $header = TF::BOLD . TF::GOLD . "Statistiques AntiESPChest - " . TF::RESET . TF::WHITE . $name . "\n";
        $header .= TF::GRAY . str_repeat("═", 30) . "\n";
        $interactions = count($data["interactions"] ?? []);
        $hiddenInteractions = $data["hidden_interactions"] ?? 0;
        $hiddenRate = $interactions > 0 ? ($hiddenInteractions / $interactions * 100) : 0;
        $stats = TF::AQUA . "Interactions: " . TF::WHITE . $interactions . "\n";
        $stats .= TF::AQUA . "Coffres cachés: " . $this->colorRate($hiddenRate) . "\n";
        $stats .= TF::AQUA . "Ghost chests: " . TF::WHITE . ($data["ghost_interactions"] ?? 0) . "\n";
        $flags = $data["flags"] ?? 0;
        $status = $player->isImmobile() ? TF::RED . "GELÉ" : TF::GREEN . "ACTIF";
        $stats .= "\n" . TF::AQUA . "Détections: " . $this->getFlagLevel($flags) . "\n";
        $stats .= TF::AQUA . "Statut: " . $status . "\n";
        $movementCount = count($data["movements"] ?? []);
        $suspectTargets = count($data["suspect_targets"] ?? []);
        $stats .= "\n" . TF::AQUA . "Analyse mouvement: \n";
        $stats .= TF::WHITE . " - Historique: " . $movementCount . " points\n";
        $stats .= TF::WHITE . " - Cibles suspectes: " . $suspectTargets . "\n";
        if ($flags > 0) {
            $stats .= "\n" . TF::RED . "Dernier flag: " . TF::WHITE . ($data["last_flag_reason"] ?? "Inconnu");
        }

        return $header . $stats;
    }

    private function colorRate(float $rate): string {
        if ($rate > 50) return TF::RED . round($rate, 1) . "%";
        if ($rate > 25) return TF::YELLOW . round($rate, 1) . "%";
        return TF::GREEN . round($rate, 1) . "%";
    }

    private function getFlagLevel(int $flags): string {
        $thresholds = $this->plugin->getConfig()->getNested("actions", []);
        $kick = $thresholds["kick_threshold"] ?? 3;
        $ban = $thresholds["ban_threshold"] ?? 5;

        if ($flags >= $ban) return TF::DARK_RED . "$flags (BAN)";
        if ($flags >= $kick) return TF::RED . "$flags (KICK)";
        if ($flags > 0) return TF::YELLOW . "$flags (WARN)";
        return TF::GREEN . "$flags (OK)";
    }

    public function getPlugin(): Main {
        return $this->plugin;
    }
}