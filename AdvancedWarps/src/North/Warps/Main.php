<?php

declare(strict_types=1);

namespace North\Warps\Main;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use pocketmine\math\Vector3;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\VanillaItems;
use pocketmine\event\player\PlayerItemUseEvent;

class Main extends PluginBase implements Listener {

    private Config $warps;
    private array $teleportQueue = [];
    private array $cooldowns = [];
    private array $warpUsageLog = [];

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->warps = new Config($this->getDataFolder() . "warps.yml", Config::YAML);
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->processTeleportQueue();
        }), 20);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch(strtolower($command->getName())) {
            case "setwarp":
                return $this->setWarp($sender, $args);
            case "delwarp":
                return $this->delWarp($sender, $args);
            case "warp":
                return $this->warp($sender, $args);
            case "warps":
                return $this->listWarps($sender);
        }
        return false;
    }

    private function setWarp(CommandSender $sender, array $args): bool {
        if(!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "Cette commande doit être utilisée en jeu.");
            return false;
        }

        if(count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: /setwarp <nom> [public|private] [prix] [permission]");
            return false;
        }

        $warpName = strtolower($args[0]);
        $isPublic = isset($args[1]) && strtolower($args[1]) === "public";
        $price = isset($args[2]) ? (int)$args[2] : 0;
        $permission = isset($args[3]) ? $args[3] : "";

        $position = $sender->getPosition();
        $worldName = $position->getWorld()->getFolderName();

        $this->warps->set($warpName, [
            "x" => $position->getX(),
            "y" => $position->getY(),
            "z" => $position->getZ(),
            "world" => $worldName,
            "creator" => $sender->getName(),
            "public" => $isPublic,
            "price" => $price,
            "permission" => $permission,
            "created" => time()
        ]);
        $this->warps->save();

        $sender->sendMessage(TextFormat::GREEN . "Warp '{$warpName}' créé avec succès!");
        return true;
    }

    private function delWarp(CommandSender $sender, array $args): bool {
        if(count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: /delwarp <nom>");
            return false;
        }

        $warpName = strtolower($args[0]);

        if(!$this->warps->exists($warpName)) {
            $sender->sendMessage(TextFormat::RED . "Ce warp n'existe pas!");
            return false;
        }

        $warpData = $this->warps->get($warpName);
        if($sender instanceof Player && $warpData["creator"] !== $sender->getName() && !$sender->hasPermission("warpsystem.delete.others")) {
            $sender->sendMessage(TextFormat::RED . "Vous ne pouvez pas supprimer ce warp!");
            return false;
        }

        $this->warps->remove($warpName);
        $this->warps->save();

        $sender->sendMessage(TextFormat::GREEN . "Warp '{$warpName}' supprimé avec succès!");
        return true;
    }

    private function warp(CommandSender $sender, array $args): bool {
        if(!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "Cette commande doit être utilisée en jeu.");
            return false;
        }

        if(count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: /warp <nom>");
            return false;
        }

        $warpName = strtolower($args[0]);

        if(!$this->warps->exists($warpName)) {
            $sender->sendMessage(TextFormat::RED . "Ce warp n'existe pas!");
            return false;
        }

        $warpData = $this->warps->get($warpName);

        if(!$warpData["public"] && $warpData["creator"] !== $sender->getName() && !$sender->hasPermission("warpsystem.access.private")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas accès à ce warp privé!");
            return false;
        }

        if(!empty($warpData["permission"]) && !$sender->hasPermission($warpData["permission"])) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'accèder à ce warp!");
            return false;
        }

        $price = $warpData["price"];
        if($price > 0) {
            $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
            if($economy !== null) {
                $money = $economy->myMoney($sender);
                if($money < $price) {
                    $sender->sendMessage(TextFormat::RED . "Vous n'avez pas assez d'argent (nécessaire: {$price})!");
                    return false;
                }
                $economy->reduceMoney($sender, $price);
            }
        }

        $cooldown = $this->getConfig()->get("cooldown", 0);
        if(isset($this->cooldowns[$sender->getName()]) && time() - $this->cooldowns[$sender->getName()] < $cooldown) {
            $remaining = $cooldown - (time() - $this->cooldowns[$sender->getName()]);
            $sender->sendMessage(TextFormat::RED . "Vous devez attendre {$remaining} secondes avant de vous téléporter à nouveau!");
            return false;
        }

        $world = $this->getServer()->getWorldManager()->getWorldByName($warpData["world"]);
        if($world === null) {
            $sender->sendMessage(TextFormat::RED . "Le monde de ce warp n'est pas chargé!");
            return false;
        }

        $position = new Position($warpData["x"], $warpData["y"], $warpData["z"], $world);

        $delay = $this->getConfig()->get("teleport-delay", 5);
        $sender->sendMessage(TextFormat::YELLOW . "Téléportation dans {$delay} secondes. Ne bougez pas!");

        $this->teleportQueue[$sender->getName()] = [
            "position" => $position,
            "time" => time() + $delay,
            "original" => $sender->getPosition(),
            "warp" => $warpName
        ];

        $this->warpUsageLog[$sender->getName()][] = [
            "warp" => $warpName,
            "time" => time(),
            "price" => $price
        ];

        $this->getLogger()->info("{$sender->getName()} a utilisé le warp {$warpName}");

        return true;
    }

    private function listWarps(CommandSender $sender): bool {
        $warps = $this->warps->getAll();
        if(empty($warps)) {
            $sender->sendMessage(TextFormat::RED . "Aucun warp disponible!");
            return true;
        }

        $message = TextFormat::GOLD . "---- Liste des Warps ----\n";
        foreach($warps as $name => $data) {
            if(!$data["public"] && (!$sender instanceof Player || ($data["creator"] !== $sender->getName() && !$sender->hasPermission("warpsystem.access.private")))) {
                continue;
            }

            if(!empty($data["permission"]) && $sender instanceof Player && !$sender->hasPermission($data["permission"])) {
                continue;
            }

            $status = $data["public"] ? TextFormat::GREEN . "Public" : TextFormat::RED . "Privé";
            $price = $data["price"] > 0 ? TextFormat::YELLOW . "Prix: {$data["price"]}" : TextFormat::GREEN . "Gratuit";
            $message .= TextFormat::WHITE . "- {$name} {$status} {$price}\n";
        }

        $sender->sendMessage($message);
        return true;
    }

    private function processTeleportQueue(): void {
        foreach($this->teleportQueue as $playerName => $data) {
            $player = $this->getServer()->getPlayerExact($playerName);
            if($player === null) {
                unset($this->teleportQueue[$playerName]);
                continue;
            }

            if(time() >= $data["time"]) {
                $player->teleport($data["position"]);
                $player->sendMessage(TextFormat::GREEN . "Téléportation réussie vers '{$data["warp"]}'!");
                $this->cooldowns[$playerName] = time();
                unset($this->teleportQueue[$playerName]);
            }
        }
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();

        if(isset($this->teleportQueue[$playerName])) {
            $original = $this->teleportQueue[$playerName]["original"];
            if($event->getFrom()->distance($original) > 1) {
                unset($this->teleportQueue[$playerName]);
                $player->sendMessage(TextFormat::RED . "Téléportation annulée car vous avez bougé!");
            }
        }
    }

    public function onDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        if($entity instanceof Player) {
            $playerName = $entity->getName();
            if(isset($this->teleportQueue[$playerName])) {
                unset($this->teleportQueue[$playerName]);
                $entity->sendMessage(TextFormat::RED . "Téléportation annulée car vous avez pris des dégâts!");
            }
        }
    }

    public function onInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();

        if($item->equals(VanillaItems::COMPASS()) && $player->hasPermission("warpsystem.menu")) {
            $this->openWarpMenu($player);
            $event->cancel();
        }
    }

    public function onItemUse(PlayerItemUseEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();

        if($item->equals(VanillaItems::COMPASS()) && $player->hasPermission("warpsystem.menu")) {
            $this->openWarpMenu($player);
            $event->cancel();
        }
    }

    private function openWarpMenu(Player $player): void {
        $warps = $this->warps->getAll();
        $menu = InvMenu::create(InvMenu::TYPE_CHEST);
        $menu->setName(TextFormat::GOLD . "Menu des Warps");

        $inventory = $menu->getInventory();
        $index = 0;

        foreach($warps as $name => $data) {
            if(!$data["public"] && $data["creator"] !== $player->getName() && !$player->hasPermission("warpsystem.access.private")) {
                continue;
            }

            if(!empty($data["permission"]) && !$player->hasPermission($data["permission"])) {
                continue;
            }

            $item = VanillaItems::PAPER();
            $item->setCustomName(TextFormat::GOLD . $name);
            $lore = [
                TextFormat::WHITE . "Monde: " . $data["world"],
                TextFormat::WHITE . "Créé par: " . $data["creator"],
                $data["public"] ? TextFormat::GREEN . "Public" : TextFormat::RED . "Privé",
                $data["price"] > 0 ? TextFormat::YELLOW . "Prix: " . $data["price"] : TextFormat::GREEN . "Gratuit"
            ];
            $item->setLore($lore);
            $inventory->setItem($index++, $item);
        }

        $menu->setListener(function(Transaction $transaction) {
            $player = $transaction->getPlayer();
            $itemClicked = $transaction->getItemClicked();

            $warpName = str_replace(TextFormat::GOLD, "", $itemClicked->getCustomName());
            $player->chat("/warp " . $warpName);
            return $transaction->discard();
        });

        $menu->send($player);
    }

    public function onDisable(): void {
        $this->warps->save();
        $log = new Config($this->getDataFolder() . "usage_log.yml", Config::YAML);
        $log->setAll($this->warpUsageLog);
        $log->save();
    }
}