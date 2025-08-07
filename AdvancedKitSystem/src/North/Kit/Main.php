<?php

namespace North\Kit\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\Item;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\type\InvMenuTypeIds;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener {

    private Config $kitsConfig;
    private Config $playersConfig;
    private Config $logsConfig;
    private array $cooldowns = [];
    private const DATE_FORMAT = "d/m/Y H:i:s";

    public function onEnable(): void {
        $this->initConfigs();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->checkDependencies();
    }

    private function initConfigs(): void {
        $this->saveResources();
        $this->kitsConfig = new Config($this->getDataFolder() . "kits.yml", Config::YAML);
        $this->playersConfig = new Config($this->getDataFolder() . "players.yml", Config::YAML);
        $this->logsConfig = new Config($this->getDataFolder() . "logs.yml", Config::YAML);
    }

    private function saveResources(): void {
        foreach (["kits.yml", "players.yml", "logs.yml"] as $file) {
            $this->saveResource($file);
        }
    }

    private function checkDependencies(): void {
        if (!class_exists(InvMenu::class)) {
            $this->getLogger()->error("InvMenu requis ! Téléchargez-le sur poggit.pmmp.io");
            $this->getServer()->getPluginManager()->disablePlugin($this);
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "Commande réservée aux joueurs");
            return true;
        }

        switch (strtolower($command->getName())) {
            case "kit":
                $this->handleKitCommand($sender, $args);
                break;
            case "kitlist":
                $this->sendKitList($sender);
                break;
            case "kitlog":
                $this->showKitLog($sender, $args[0] ?? $sender->getName());
                break;
            case "kitcooldown":
                $this->showCooldown($sender, $args[0] ?? null);
                break;
        }
        return true;
    }

    private function handleKitCommand(Player $player, array $args): void {
        if (empty($args)) {
            $this->openKitMenu($player);
        } else {
            $this->giveKit($player, strtolower($args[0]));
        }
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        if (!$this->playersConfig->exists($player->getName() . ".starter")) {
            $this->giveKit($player, "starter");
            $this->playersConfig->set($player->getName() . ".starter", true);
            $this->playersConfig->save();
        }
    }

    private function openKitMenu(Player $player): void {
        $menu = InvMenu::create(InvMenuTypeIds::TYPE_CHEST);
        $menu->setName(TF::BOLD . TF::GOLD . "§l§6Menu des Kits");

        $validKits = array_filter($this->kitsConfig->getAll(), fn($k) => $this->hasPermission($player, $k));

        foreach ($validKits as $kitName => $kitData) {
            $item = Item::get($kitData["icon"] ?? Item::CHEST)
                ->setCustomName(TF::RESET . TF::BOLD . TF::GOLD . $kitName)
                ->setLore($this->generateKitLore($kitData));

            $menu->getInventory()->addItem($item);
            $menu->setListener(fn(InvMenuTransaction $t) => $this->onKitSelect($t, $kitName));
        }

        $menu->send($player);
    }

    private function generateKitLore(array $kitData): array {
        return [
            TF::RESET . TF::GRAY . $kitData["description"] ?? "Kit spécial",
            TF::RESET . TF::DARK_GRAY . "Cooldown: " . $this->formatCooldown($kitData["cooldown"] ?? 0),
            TF::RESET . TF::YELLOW . "Cliquez pour recevoir"
        ];
    }

    private function onKitSelect(InvMenuTransaction $transaction, string $kitName): InvMenuTransactionResult {
        $this->giveKit($transaction->getPlayer(), $kitName);
        return $transaction->discard();
    }

    private function giveKit(Player $player, string $kitName): void {
        $kitData = $this->kitsConfig->get($kitName);

        if (!$this->validateKitRequest($player, $kitName, $kitData)) {
            return;
        }

        $this->processKitDelivery($player, $kitName, $kitData);
        $player->sendMessage(TF::GREEN . "Kit reçu: " . $kitName);
    }

    private function validateKitRequest(Player $player, string $kitName, ?array $kitData): bool {
        if ($kitData === null) {
            $player->sendMessage(TF::RED . "Kit inconnu: " . $kitName);
            return false;
        }

        if (!$this->hasPermission($player, $kitName)) {
            $player->sendMessage(TF::RED . "Permission manquante");
            return false;
        }

        if ($this->isOnCooldown($player, $kitName)) {
            $player->sendMessage(TF::RED . "En cooldown: " . $this->getCooldownLeft($player, $kitName));
            return false;
        }

        if (!$player->getInventory()->canAddItem(Item::get(Item::STONE))) {
            $player->sendMessage(TF::RED . "Inventaire plein !");
            return false;
        }

        return true;
    }

    private function processKitDelivery(Player $player, string $kitName, array $kitData): void {
        $this->applyCooldown($player, $kitName, $kitData["cooldown"] ?? 0);
        $this->giveItems($player, $kitData["items"] ?? []);
        $this->applyEffects($player, $kitData["effects"] ?? []);
        $this->logKitUsage($player->getName(), $kitName);
    }

    private function applyCooldown(Player $player, string $kitName, int $cooldown): void {
        if ($cooldown <= 0) return;

        $key = $this->getCooldownKey($player, $kitName);
        $this->cooldowns[$key] = time() + $cooldown;

        $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(fn() => unset($this->cooldowns[$key])),
            $cooldown * 20
        );
    }

    private function giveItems(Player $player, array $items): void {
        foreach ($items as $itemData) {
            $player->getInventory()->addItem(Item::jsonDeserialize($itemData));
        }
    }

    private function applyEffects(Player $player, array $effects): void {
        foreach ($effects as $effectData) {
            $player->getEffects()->add(new EffectInstance(
                VanillaEffects::fromString($effectData["id"]),
                $effectData["duration"] ?? 600,
                $effectData["amplifier"] ?? 0,
                $effectData["visible"] ?? true
            ));
        }
    }

    private function logKitUsage(string $player, string $kitName): void {
        $logs = $this->logsConfig->get("logs", []);
        $logs[] = [
            "player" => $player,
            "kit" => $kitName,
            "time" => time(),
            "date" => date(self::DATE_FORMAT)
        ];
        $this->logsConfig->set("logs", $logs);
        $this->logsConfig->save();
    }

    private function hasPermission(Player $player, string $kitName): bool {
        return $player->hasPermission($this->kitsConfig->getNested("$kitName.permission", "kitsystem.kit.$kitName"));
    }

    private function isOnCooldown(Player $player, string $kitName): bool {
        $remaining = $this->getCooldownLeft($player, $kitName);
        return $remaining > 0;
    }

    private function getCooldownLeft(Player $player, string $kitName): int {
        $key = $this->getCooldownKey($player, $kitName);
        return ($this->cooldowns[$key] ?? 0) - time();
    }

    private function getCooldownKey(Player $player, string $kitName): string {
        return $player->getName() . "_" . $kitName;
    }

    private function sendKitList(Player $player): void {
        $player->sendMessage(TF::BOLD . TF::GOLD . "§l§6Kits disponibles:");

        foreach ($this->kitsConfig->getAll() as $kitName => $kitData) {
            if ($this->hasPermission($player, $kitName)) {
                $status = $this->isOnCooldown($player, $kitName)
                    ? TF::RED . " (Cooldown)"
                    : TF::GREEN . " (Disponible)";
                $player->sendMessage(TF::WHITE . "- " . $kitName . $status);
            }
        }
    }

    private function showKitLog(Player $player, string $target): void {
        $logs = array_filter(
            $this->logsConfig->get("logs", []),
            fn($log) => strcasecmp($log["player"], $target) === 0
        );

        if (empty($logs)) {
            $player->sendMessage(TF::RED . "Aucun log pour " . $target);
            return;
        }

        usort($logs, fn($a, $b) => $b["time"] <=> $a["time"]);
        $player->sendMessage(TF::BOLD . TF::GOLD . "§l§6Logs de " . $target);

        foreach (array_slice($logs, 0, 5) as $log) {
            $player->sendMessage(TF::GRAY . "- " . $log["kit"] . " le " . $log["date"]);
        }
    }

    private function showCooldown(Player $player, ?string $kitName): void {
        if ($kitName === null) {
            $this->showAllCooldowns($player);
        } else {
            $this->showSingleCooldown($player, $kitName);
        }
    }

    private function showAllCooldowns(Player $player): void {
        $player->sendMessage(TF::BOLD . TF::GOLD . "§l§6Vos cooldowns");

        foreach ($this->kitsConfig->getAll() as $kitName => $_) {
            if ($this->isOnCooldown($player, $kitName)) {
                $player->sendMessage(TF::WHITE . "- " . $kitName . ": " .
                    TF::RED . $this->formatTime($this->getCooldownLeft($player, $kitName)));
            }
        }
    }

    private function showSingleCooldown(Player $player, string $kitName): void {
        if ($this->kitsConfig->get($kitName) === null) {
            $player->sendMessage(TF::RED . "Kit inconnu");
            return;
        }

        if ($this->isOnCooldown($player, $kitName)) {
            $player->sendMessage(TF::RED . "Cooldown restant: " .
                $this->formatTime($this->getCooldownLeft($player, $kitName)));
        } else {
            $player->sendMessage(TF::GREEN . "Prêt à être utilisé !");
        }
    }

    private function formatTime(int $seconds): string {
        return match (true) {
            $seconds >= 86400 => round($seconds / 86400) . "j",
            $seconds >= 3600 => round($seconds / 3600) . "h",
            $seconds >= 60 => round($seconds / 60) . "m",
            default => $seconds . "s"
        };
    }

    private function formatCooldown(int $seconds): string {
        return $this->formatTime($seconds);
    }
}