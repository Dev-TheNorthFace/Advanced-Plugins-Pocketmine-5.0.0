<?php

namespace North\AntiNoKnockBack\Commands\AntiKBCommand;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use North\AntiNoKnockBack\Main;
use North\AntiNoKnockBack\Utils\StatsCalculator;

class AntiKBCommand extends Command {

    private Main $plugin;

    public function __construct(Main $plugin) {
        parent::__construct(
            "antikb",
            "Gestion du système AntiNoKnockBack",
            "/antikb <stats|test|reload> [joueur]"
        );
        $this->setPermission("antikb.command");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (empty($args)) {
            $sender->sendMessage(TextFormat::RED . "Usage: " . $this->getUsage());
            return false;
        }

        switch (strtolower($args[0])) {
            case "stats":
                $this->handleStatsCommand($sender, $args);
                break;

            case "test":
                $this->handleTestCommand($sender, $args);
                break;

            case "reload":
                $this->handleReloadCommand($sender);
                break;

            case "freeze":
                $this->handleFreezeCommand($sender, $args);
                break;

            case "reset":
                $this->handleResetCommand($sender, $args);
                break;

            default:
                $sender->sendMessage(TextFormat::RED . "Sous-commande inconnue");
                $sender->sendMessage(TextFormat::YELLOW . "Disponible: stats, test, reload, freeze, reset");
        }

        return true;
    }

    private function handleStatsCommand(CommandSender $sender, array $args): void {
        $target = $this->resolveTargetPlayer($sender, $args[1] ?? null);
        if ($target === null) return;

        $stats = $this->plugin->getStatsCalculator()->getFormattedStats($target);
        $sender->sendMessage($stats);
    }

    private function handleTestCommand(CommandSender $sender, array $args): void {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "Commande réservée aux joueurs");
            return;
        }

        $target = $this->resolveTargetPlayer($sender, $args[1] ?? null);
        if ($target === null) return;

        $this->plugin->sendTestProjectile($target);
        $sender->sendMessage(TextFormat::GREEN . "Projectile de test envoyé à " . $target->getName());
    }

    private function handleReloadCommand(CommandSender $sender): void {
        $this->plugin->reloadConfiguration();
        $sender->sendMessage(TextFormat::GREEN . "Configuration rechargée avec succès!");
    }

    private function handleFreezeCommand(CommandSender $sender, array $args): void {
        $target = $this->resolveTargetPlayer($sender, $args[1] ?? null);
        if ($target === null) return;

        $duration = (int)($args[2] ?? $this->plugin->getKBConfig()['freeze_duration']);
        $this->plugin->freezePlayer($target, $duration);

        $sender->sendMessage(
            TextFormat::GREEN . $target->getName() .
            " a été freeze pendant " . $duration . " secondes"
        );
    }

    private function handleResetCommand(CommandSender $sender, array $args): void {
        $target = $this->resolveTargetPlayer($sender, $args[1] ?? null);
        if ($target === null) return;

        $this->plugin->getStatsCalculator()->resetPlayerStats($target);
        $sender->sendMessage(TextFormat::GREEN . "Statistiques reset pour " . $target->getName());
    }

    private function resolveTargetPlayer(CommandSender $sender, ?string $name): ?Player {
        if ($name === null) {
            if ($sender instanceof Player) {
                return $sender;
            }
            $sender->sendMessage(TextFormat::RED . "Veuillez spécifier un joueur");
            return null;
        }

        $target = $this->plugin->getServer()->getPlayerExact($name);
        if ($target === null) {
            $sender->sendMessage(TextFormat::RED . "Joueur introuvable ou hors ligne");
            return null;
        }

        return $target;
    }

    public function getPlugin(): Main {
        return $this->plugin;
    }
}