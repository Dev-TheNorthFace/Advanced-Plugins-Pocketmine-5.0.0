<?php

declare(strict_types=1);

namespace North\AntiAutoClick\Commands\FlagCommand;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use North\AntiAutoClick\Main;
use North\AntiAutoClick\Utils\AlertManager;

class FlagCommand extends Command {

    private Main $plugin;
    private AlertManager $alertManager;

    public function __construct(Main $plugin, AlertManager $alertManager) {
        parent::__construct(
            "flagclick",
            "Signaler un joueur pour auto-click",
            "/flagclick <joueur> [raison]",
            ["flagac"]
        );
        $this->setPermission("anticheat.flag");
        $this->plugin = $plugin;
        $this->alertManager = $alertManager;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: " . $this->getUsage());
            return false;
        }

        $targetName = $args[0];
        $target = $this->plugin->getServer()->getPlayerExact($targetName);

        if ($target === null) {
            $sender->sendMessage(TextFormat::RED . "Joueur introuvable!");
            return false;
        }

        $reason = count($args) > 1 ? implode(" ", array_slice($args, 1)) : "Signalement manuel";
        $cps = $this->plugin->getCpsTracker()->getRecentCps($targetName, 1);

        $this->alertManager->handleViolation($target, "manual_flag", $cps, 0, $reason);

        $sender->sendMessage(TextFormat::GREEN . "§a[AntiAC] §2" . $targetName . " §asignalé pour: §f" . $reason);

        $this->plugin->addToWatchlist($targetName, [
            'type' => 'manual',
            'staff' => $sender->getName(),
            'reason' => $reason,
            'timestamp' => time()
        ]);

        return true;
    }

    public function generateFlagId(Player $player): string {
        return substr(md5($player->getName() . microtime()), 0, 8);
    }
}