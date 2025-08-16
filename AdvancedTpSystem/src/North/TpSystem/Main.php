<?php

declare(strict_types=1);

namespace North\TpSystem\Main;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener {

    private array $tpaRequests = [];
    private array $tpaHereRequests = [];
    private array $combatPlayers = [];
    private array $disabledPlayers = [];
    private array $teleportHistory = [];
    private Config $config;
    private Config $playerSettings;

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "request_expire_time" => 30,
            "teleport_cost" => 100,
            "cost_per_block" => 1,
            "combat_block_time" => 15,
            "request_cooldown" => 10
        ]);
        $this->playerSettings = new Config($this->getDataFolder() . "player_settings.yml", Config::YAML);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "Cette commande ne peut être utilisée que dans le jeu.");
            return true;
        }

        switch(strtolower($command->getName())) {
            case "tpa":
                if(count($args) < 1) {
                    $sender->sendMessage(TextFormat::RED . "Utilisation: /tpa <joueur>");
                    return false;
                }

                $target = $this->getServer()->getPlayerByPrefix($args[0]);
                if($target === null) {
                    $sender->sendMessage(TextFormat::RED . "Joueur introuvable.");
                    return false;
                }

                $this->sendTpaRequest($sender, $target);
                return true;

            case "tpahere":
                if(count($args) < 1) {
                    $sender->sendMessage(TextFormat::RED . "Utilisation: /tpahere <joueur>");
                    return false;
                }

                $target = $this->getServer()->getPlayerByPrefix($args[0]);
                if($target === null) {
                    $sender->sendMessage(TextFormat::RED . "Joueur introuvable.");
                    return false;
                }

                $this->sendTpaHereRequest($sender, $target);
                return true;

            case "tpaccept":
                $this->acceptTpaRequest($sender);
                return true;

            case "tpdeny":
                $this->denyTpaRequest($sender);
                return true;

            case "tpcancel":
                $this->cancelTpaRequest($sender);
                return true;

            case "tptoggle":
                $this->toggleTpa($sender);
                return true;

            case "tprecent":
                $this->showRecentTeleports($sender);
                return true;

            case "tpsettings":
                $this->openSettingsUI($sender);
                return true;
        }

        return false;
    }

    private function sendTpaRequest(Player $sender, Player $target): void {
        if(isset($this->combatPlayers[$sender->getName()])) {
            $sender->sendMessage(TextFormat::RED . "Vous ne pouvez pas envoyer de demande de téléportation en combat.");
            return;
        }

        if(isset($this->disabledPlayers[$target->getName()])) {
            $sender->sendMessage(TextFormat::RED . "Ce joueur a désactivé les demandes de téléportation.");
            return;
        }

        if(isset($this->tpaRequests[$target->getName()][$sender->getName()])) {
            $sender->sendMessage(TextFormat::RED . "Vous avez déjà une demande en attente avec ce joueur.");
            return;
        }

        $this->tpaRequests[$target->getName()][$sender->getName()] = time() + $this->config->get("request_expire_time");

        $sender->sendMessage(TextFormat::GREEN . "Demande de téléportation envoyée à " . $target->getName());
        $target->sendMessage(TextFormat::AQUA . "💌 Demande de TP reçue de " . $sender->getName() . ". Tape /tpaccept ou /tpdeny");

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($sender, $target): void {
            if(isset($this->tpaRequests[$target->getName()][$sender->getName()])) {
                unset($this->tpaRequests[$target->getName()][$sender->getName()]);
                $sender->sendMessage(TextFormat::RED . "Votre demande de téléportation à " . $target->getName() . " a expiré.");
                $target->sendMessage(TextFormat::RED . "La demande de TP de " . $sender->getName() . " a expiré.");
            }
        }), $this->config->get("request_expire_time") * 20);
    }

    private function sendTpaHereRequest(Player $sender, Player $target): void {
        if(isset($this->combatPlayers[$sender->getName()])) {
            $sender->sendMessage(TextFormat::RED . "Vous ne pouvez pas envoyer de demande de téléportation en combat.");
            return;
        }

        if(isset($this->disabledPlayers[$target->getName()])) {
            $sender->sendMessage(TextFormat::RED . "Ce joueur a désactivé les demandes de téléportation.");
            return;
        }

        if(isset($this->tpaHereRequests[$target->getName()][$sender->getName()])) {
            $sender->sendMessage(TextFormat::RED . "Vous avez déjà une demande en attente avec ce joueur.");
            return;
        }

        $this->tpaHereRequests[$target->getName()][$sender->getName()] = time() + $this->config->get("request_expire_time");

        $sender->sendMessage(TextFormat::GREEN . "Demande de téléportation envoyée à " . $target->getName() . " pour qu'il vienne à vous.");
        $target->sendMessage(TextFormat::AQUA . "💌 Demande de TP reçue de " . $sender->getName() . " pour venir à lui. Tape /tpaccept ou /tpdeny");

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($sender, $target): void {
            if(isset($this->tpaHereRequests[$target->getName()][$sender->getName()])) {
                unset($this->tpaHereRequests[$target->getName()][$sender->getName()]);
                $sender->sendMessage(TextFormat::RED . "Votre demande de téléportation à " . $target->getName() . " a expiré.");
                $target->sendMessage(TextFormat::RED . "La demande de TP de " . $sender->getName() . " a expiré.");
            }
        }), $this->config->get("request_expire_time") * 20);
    }

    private function acceptTpaRequest(Player $player): void {
        $foundRequest = false;

        if(!empty($this->tpaRequests[$player->getName()])) {
            foreach($this->tpaRequests[$player->getName()] as $requesterName => $expireTime) {
                $requester = $this->getServer()->getPlayerExact($requesterName);
                if($requester !== null) {
                    $foundRequest = true;
                    unset($this->tpaRequests[$player->getName()][$requesterName]);

                    if(isset($this->combatPlayers[$requester->getName()]) || isset($this->combatPlayers[$player->getName()])) {
                        $player->sendMessage(TextFormat::RED . "La téléportation a été annulée car l'un des joueurs est en combat.");
                        $requester->sendMessage(TextFormat::RED . "La téléportation a été annulée car l'un des joueurs est en combat.");
                        return;
                    }

                    $this->teleportWithCost($requester, $player);
                    $this->addToHistory($requester, $player);
                    break;
                }
            }
        }

        if(!$foundRequest && !empty($this->tpaHereRequests[$player->getName()])) {
            foreach($this->tpaHereRequests[$player->getName()] as $requesterName => $expireTime) {
                $requester = $this->getServer()->getPlayerExact($requesterName);
                if($requester !== null) {
                    $foundRequest = true;
                    unset($this->tpaHereRequests[$player->getName()][$requesterName]);

                    if(isset($this->combatPlayers[$requester->getName()]) || isset($this->combatPlayers[$player->getName()])) {
                        $player->sendMessage(TextFormat::RED . "La téléportation a été annulée car l'un des joueurs est en combat.");
                        $requester->sendMessage(TextFormat::RED . "La téléportation a été annulée car l'un des joueurs est en combat.");
                        return;
                    }

                    $this->teleportWithCost($player, $requester);
                    $this->addToHistory($player, $requester);
                    break;
                }
            }
        }

        if(!$foundRequest) {
            $player->sendMessage(TextFormat::RED . "Vous n'avez aucune demande de téléportation en attente.");
        }
    }

    private function teleportWithCost(Player $from, Player $to): void {
        $cost = $this->calculateTeleportCost($from, $to);

        $from->sendMessage(TextFormat::GREEN . "Téléportation vers " . $to->getName() . " réussie!");
        $to->sendMessage(TextFormat::GREEN . $from->getName() . " s'est téléporté à vous!");

        $from->teleport($to->getPosition());
    }

    private function calculateTeleportCost(Player $from, Player $to): float {
        $baseCost = $this->config->get("teleport_cost");
        $distance = $from->getPosition()->distance($to->getPosition());
        $distanceCost = $distance * $this->config->get("cost_per_block");

        return $baseCost + $distanceCost;
    }

    private function denyTpaRequest(Player $player): void {
        $foundRequest = false;

        if(!empty($this->tpaRequests[$player->getName()])) {
            foreach($this->tpaRequests[$player->getName()] as $requesterName => $expireTime) {
                $requester = $this->getServer()->getPlayerExact($requesterName);
                if($requester !== null) {
                    $foundRequest = true;
                    unset($this->tpaRequests[$player->getName()][$requesterName]);
                    $player->sendMessage(TextFormat::RED . "Vous avez refusé la demande de téléportation de " . $requester->getName());
                    $requester->sendMessage(TextFormat::RED . $player->getName() . " a refusé votre demande de téléportation.");
                    break;
                }
            }
        }

        if(!$foundRequest && !empty($this->tpaHereRequests[$player->getName()])) {
            foreach($this->tpaHereRequests[$player->getName()] as $requesterName => $expireTime) {
                $requester = $this->getServer()->getPlayerExact($requesterName);
                if($requester !== null) {
                    $foundRequest = true;
                    unset($this->tpaHereRequests[$player->getName()][$requesterName]);
                    $player->sendMessage(TextFormat::RED . "Vous avez refusé la demande de téléportation de " . $requester->getName());
                    $requester->sendMessage(TextFormat::RED . $player->getName() . " a refusé votre demande de téléportation.");
                    break;
                }
            }
        }

        if(!$foundRequest) {
            $player->sendMessage(TextFormat::RED . "Vous n'avez aucune demande de téléportation en attente.");
        }
    }

    private function cancelTpaRequest(Player $player): void {
        $foundRequest = false;

        foreach($this->tpaRequests as $targetName => $requests) {
            if(isset($requests[$player->getName()])) {
                $target = $this->getServer()->getPlayerExact($targetName);
                if($target !== null) {
                    $foundRequest = true;
                    unset($this->tpaRequests[$targetName][$player->getName()]);
                    $player->sendMessage(TextFormat::GREEN . "Vous avez annulé votre demande de téléportation à " . $target->getName());
                    $target->sendMessage(TextFormat::RED . $player->getName() . " a annulé sa demande de téléportation.");
                    break;
                }
            }
        }

        if(!$foundRequest) {
            foreach($this->tpaHereRequests as $targetName => $requests) {
                if(isset($requests[$player->getName()])) {
                    $target = $this->getServer()->getPlayerExact($targetName);
                    if($target !== null) {
                        $foundRequest = true;
                        unset($this->tpaHereRequests[$targetName][$player->getName()]);
                        $player->sendMessage(TextFormat::GREEN . "Vous avez annulé votre demande de téléportation à " . $target->getName());
                        $target->sendMessage(TextFormat::RED . $player->getName() . " a annulé sa demande de téléportation.");
                        break;
                    }
                }
            }
        }

        if(!$foundRequest) {
            $player->sendMessage(TextFormat::RED . "Vous n'avez aucune demande de téléportation active à annuler.");
        }
    }

    private function toggleTpa(Player $player): void {
        if(isset($this->disabledPlayers[$player->getName()])) {
            unset($this->disabledPlayers[$player->getName()]);
            $player->sendMessage(TextFormat::GREEN . "Vous avez activé les demandes de téléportation.");
        } else {
            $this->disabledPlayers[$player->getName()] = true;
            $player->sendMessage(TextFormat::GREEN . "Vous avez désactivé les demandes de téléportation.");
        }
    }

    private function showRecentTeleports(Player $player): void {
        if(!isset($this->teleportHistory[$player->getName()]) || empty($this->teleportHistory[$player->getName()])) {
            $player->sendMessage(TextFormat::RED . "Vous n'avez aucun historique de téléportation récent.");
            return;
        }

        $player->sendMessage(TextFormat::GOLD . "📊 Historique des téléportations:");
        foreach($this->teleportHistory[$player->getName()] as $entry) {
            $type = $entry["type"] === "to" ? "vers" : "de";
            $player->sendMessage(TextFormat::AQUA . "- " . $type . " " . $entry["player"] . " à " . date("H:i:s", $entry["time"]));
        }
    }

    private function openSettingsUI(Player $player): void {
    }

    private function addToHistory(Player $from, Player $to): void {
        $this->addHistoryEntry($from, $to->getName(), "to");
        $this->addHistoryEntry($to, $from->getName(), "from");
    }

    private function addHistoryEntry(Player $player, string $otherPlayer, string $type): void {
        if(!isset($this->teleportHistory[$player->getName()])) {
            $this->teleportHistory[$player->getName()] = [];
        }

        array_unshift($this->teleportHistory[$player->getName()]], [
            "player" => $otherPlayer,
            "type" => $type,
            "time" => time()
        ]);

        if(count($this->teleportHistory[$player->getName()]) > 10) {
            array_pop($this->teleportHistory[$player->getName()]);
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $name = $player->getName();

        if(isset($this->tpaRequests[$name])) {
            foreach($this->tpaRequests[$name] as $requesterName => $expireTime) {
                $requester = $this->getServer()->getPlayerExact($requesterName);
                if($requester !== null) {
                    $requester->sendMessage(TextFormat::RED . "Votre demande de téléportation à " . $name . " a été annulée car le joueur s'est déconnecté.");
                }
            }
            unset($this->tpaRequests[$name]);
        }

        foreach($this->tpaRequests as $targetName => $requests) {
            if(isset($requests[$name])) {
                unset($this->tpaRequests[$targetName][$name]);
                $target = $this->getServer()->getPlayerExact($targetName);
                if($target !== null) {
                    $target->sendMessage(TextFormat::RED . "La demande de téléportation de " . $name . " a été annulée car le joueur s'est déconnecté.");
                }
            }
        }

        if(isset($this->tpaHereRequests[$name])) {
            foreach($this->tpaHereRequests[$name] as $requesterName => $expireTime) {
                $requester = $this->getServer()->getPlayerExact($requesterName);
                if($requester !== null) {
                    $requester->sendMessage(TextFormat::RED . "Votre demande de téléportation à " . $name . " a été annulée car le joueur s'est déconnecté.");
                }
            }
            unset($this->tpaHereRequests[$name]);
        }

        foreach($this->tpaHereRequests as $targetName => $requests) {
            if(isset($requests[$name])) {
                unset($this->tpaHereRequests[$targetName][$name]);
                $target = $this->getServer()->getPlayerExact($targetName);
                if($target !== null) {
                    $target->sendMessage(TextFormat::RED . "La demande de téléportation de " . $name . " a été annulée car le joueur s'est déconnecté.");
                }
            }
        }

        if(isset($this->combatPlayers[$name])) {
            unset($this->combatPlayers[$name]);
        }
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        if(!$entity instanceof Player) return;

        if($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if($damager instanceof Player) {
                $this->combatPlayers[$entity->getName()] = time() + $this->config->get("combat_block_time");
                $this->combatPlayers[$damager->getName()] = time() + $this->config->get("combat_block_time");
            }
        }
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        if(isset($this->combatPlayers[$player->getName()]) && $this->combatPlayers[$player->getName()] < time()) {
            unset($this->combatPlayers[$player->getName()]);
            $player->sendMessage(TextFormat::GREEN . "Vous n'êtes plus en mode combat.");
        }
    }
}