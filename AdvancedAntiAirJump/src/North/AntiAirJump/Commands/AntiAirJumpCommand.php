<?php

declare(strict_types=1);

namespace North\AntiAirJump\Commands\AntiAirJumpStatsCommand;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use North\AntiAirJump\Main;

class AntiAirJumpStatsCommand extends Command {

    private Main $plugin;

    public function __construct(Main $plugin) {
        parent::__construct("airstats", "Affiche les stats AntiAirJump");
        $this->setPermission("antiairjump.command.stats");
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
                $sender->sendMessage("§cJoueur introuvable!");
                return;
            }
        }

        if (!$target instanceof Player) {
            $sender->sendMessage("§cVous devez être un joueur!");
            return;
        }

        $stats = $this->plugin->getPlayerStats($target);
        $sender->sendMessage($stats);
    }
}