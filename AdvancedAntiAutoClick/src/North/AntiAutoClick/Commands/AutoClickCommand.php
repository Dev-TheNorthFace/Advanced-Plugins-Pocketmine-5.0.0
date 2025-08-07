<?php

declare(strict_types=1);

namespace North\AntiAutoClick\Commands\AutoClickCommand;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use North\AntiAutoClick\Main;
use North\AntiAutoClick\Utils\AlertManager;
use North\AntiAutoClick\Detectors\CPSTracker;

class AutoClickCommand extends Command {

    private Main $plugin;
    private CPSTracker $tracker;
    private AlertManager $alertManager;

    public function __construct(Main $plugin, CPSTracker $tracker, AlertManager $alertManager) {
        parent::__construct(
            "autoclick",
            "Gère le système AntiAutoClick",
            "/autoclick <stats|flag|reset|watchlist> [joueur] [raison]",
            ["ac"]
        );

        $this->plugin = $plugin;
        $this->tracker = $tracker;
        $this->alertManager = $alertManager;
        $this->setPermission("anticheat.command");
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (empty($args)) {
            $sender->sendMessage($this->getUsage());
            return false;
        }

        switch (strtolower($args[0])) {
            case "stats":
                return $this->handleStatsCommand($sender, $args);
            case "flag":
                return $this->handleFlagCommand($sender, $args);
            case "reset":
                return $this->handleResetCommand($sender, $args);
            case "watchlist":
                return $this->handleWatchlistCommand($sender);
            case "freeze":
                return $this->handleFreezeCommand($sender, $args);
            default:
                $sender->sendMessage("§cUsage: " . $this->getUsage());
                return false;
        }
    }

    private function handleStatsCommand(CommandSender $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage("§cUsage: /autoclick stats <joueur>");
            return false;
        }

        $playerName = $args[1];
        $player = $this->plugin->getServer()->getPlayerExact($playerName);

        if ($player === null && !$sender->hasPermission("anticheat.check.offline")) {
            $sender->sendMessage("§cJoueur introuvable ou hors ligne");
            return false;
        }

        $analysis = $this->tracker->getClickPatternAnalysis($playerName);
        $history = $this->tracker->getCpsHistory($playerName);

        $sender->sendMessage("§6=== Stats de §e" . $playerName . "§6 ===");
        $sender->sendMessage("§bCPS Actuel: §f" . round($analysis['cps'], 1));
        $sender->sendMessage("§bMoyenne: §f" . round($analysis['average'], 1));
        $sender->sendMessage("§bStabilité: §f" . round($analysis['stability'], 1) . "%");
        $sender->sendMessage("§bHistorique (30s): §f" . implode(", ", array_map(fn($v) => round($v, 1), $history)));

        if ($sender->hasPermission("anticheat.stats.advanced")) {
            $sender->sendMessage("§3Médiane: §f" . round($analysis['median'], 1));
            $sender->sendMessage("§3Min/Max: §f" . round($analysis['min'], 1) . "§7/§f" . round($analysis['max'], 1));
        }

        return true;
    }

    private function handleFlagCommand(CommandSender $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage("§cUsage: /autoclick flag <joueur> [raison]");
            return false;
        }

        $playerName = $args[1];
        $player = $this->plugin->getServer()->getPlayerExact($playerName);

        if ($player === null) {
            $sender->sendMessage("§cJoueur introuvable");
            return false;
        }

        $reason = count($args) > 2 ? implode(" ", array_slice($args, 2)) : "Flag manuel";
        $cps = $this->tracker->calculateCurrentCPS($playerName);

        $this->alertManager->handleViolation($player, "manual_flag", $cps);
        $sender->sendMessage("§aFlag ajouté pour §2" . $playerName . "§a: §f" . $reason);

        return true;
    }

    private function handleResetCommand(CommandSender $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage("§cUsage: /autoclick reset <joueur>");
            return false;
        }

        $playerName = $args[1];
        $this->tracker->resetPlayerData($playerName);
        $sender->sendMessage("§aDonnées réinitialisées pour §2" . $playerName);

        return true;
    }

    private function handleWatchlistCommand(CommandSender $sender): bool {
        $watchlist = $this->plugin->getWatchlist();

        if (empty($watchlist)) {
            $sender->sendMessage("§aAucun joueur dans la watchlist");
            return true;
        }

        $sender->sendMessage("§6=== Watchlist (§e" . count($watchlist) . "§6) ===");
        foreach ($watchlist as $entry) {
            $analysis = $this->tracker->getClickPatternAnalysis($entry['player']);
            $sender->sendMessage(
                "§7- §c" . $entry['player'] .
                " §7(§f" . $entry['count'] . " flags§7) - " .
                "§bCPS: §f" . round($analysis['cps'], 1)
            );
        }

        return true;
    }

    private function handleFreezeCommand(CommandSender $sender, array $args): bool {
        if (count($args) < 2 || !($sender instanceof Player)) {
            $sender->sendMessage("§cUsage: /autoclick freeze <joueur>");
            return false;
        }

        $player = $this->plugin->getServer()->getPlayerExact($args[1]);
        if ($player === null) {
            $sender->sendMessage("§cJoueur introuvable");
            return false;
        }

        $this->alertManager->sendFreezeMessage($player);
        $sender->sendMessage("§aJoueur §2" . $player->getName() . " §agelé");

        return true;
    }
}