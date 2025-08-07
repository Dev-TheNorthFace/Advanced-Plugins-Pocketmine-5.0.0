<?php

declare(strict_types=1);

namespace North\AntiSneakTP\Command\SneakTPStatsCommand;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use North\AntiSneakTP\Main;

class SneakTPStatsCommand extends Command {

    private AntiSneakTP $plugin;

    public function __construct(AntiSneakTP $plugin) {
        parent::__construct("sneaktpstats", "Affiche les stats SneakTP");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): void {
        if(!isset($args[0])) {
            $sender->sendMessage("Usage: /sneaktpstats <joueur>");
            return;
        }

        $player = $this->plugin->getServer()->getPlayerExact($args[0]);
        if($player === null) {
            $sender->sendMessage("Joueur introuvable");
            return;
        }

        $stats = $this->plugin->getSneakStats($player);
        if(empty($stats)) {
            $sender->sendMessage("§aAucune donnée SneakTP pour ce joueur");
            return;
        }

        $sender->sendMessage("§6Stats SneakTP pour " . $player->getName());
        $sender->sendMessage("§eSneak actif: " . ($stats["sneaking"] ? "§a✅" : "§c❌"));
        $sender->sendMessage("§eDistance déplacée: §f" . $stats["distance"] . " blocs " . ($stats["distance"] > 2.5 ? "§c❌" : "§a✅"));
        $sender->sendMessage("§eVitesse moyenne: §f" . $stats["avg_speed"] . " blocs/tick " . ($stats["avg_speed"] > 0.08 ? "§c❌" : "§a✅"));
        $sender->sendMessage("§eChunks traversés: §f" . $stats["chunks"] . " " . ($stats["chunks"] > 1 ? "§c❌" : "§a✅"));
        $sender->sendMessage("§eDurée sneak: §f" . $stats["duration"] . " sec");
        $sender->sendMessage("§eFlag SneakTP: " . ($stats["flagged"] ? "§cACTIF" : "§aINACTIF"));
    }
}