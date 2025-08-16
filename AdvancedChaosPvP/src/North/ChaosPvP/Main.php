<?php

namespace North\ChaosPvP\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private $isRunning = false;
    private $kits = [];
    private $task;
    private $interval = 60;
    private $sameKitForAll = false;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->loadKits();
    }

    private function loadKits(): void {
        $config = new Config($this->getDataFolder() . "kits.yml", Config::YAML, [
            "kits" => [
                "Overkill" => [
                    "helmet" => "netherite_helmet",
                    "chestplate" => "netherite_chestplate",
                    "leggings" => "netherite_leggings",
                    "boots" => "netherite_boots",
                    "sword" => ["netherite_sword", "sharpness" => 5],
                    "items" => ["golden_apple" => 1],
                    "effects" => []
                ],
                "Archer" => [
                    "helmet" => "leather_helmet",
                    "chestplate" => "leather_chestplate",
                    "leggings" => "leather_leggings",
                    "boots" => "leather_boots",
                    "bow" => ["bow", "power" => 2],
                    "items" => ["arrow" => 16],
                    "effects" => []
                ],
                "Pauvre fermier" => [
                    "helmet" => "leather_helmet",
                    "chestplate" => "leather_chestplate",
                    "leggings" => "leather_leggings",
                    "boots" => "leather_boots",
                    "hoe" => "wooden_hoe",
                    "items" => [],
                    "effects" => []
                ],
                "Tank lent" => [
                    "helmet" => "diamond_helmet",
                    "chestplate" => "diamond_chestplate",
                    "leggings" => "diamond_leggings",
                    "boots" => "diamond_boots",
                    "sword" => "diamond_sword",
                    "items" => [],
                    "effects" => ["slowness" => 2]
                ],
                "Assassin" => [
                    "helmet" => "air",
                    "chestplate" => "air",
                    "leggings" => "air",
                    "boots" => "air",
                    "sword" => "iron_sword",
                    "items" => [],
                    "effects" => ["speed" => 2, "invisibility" => 1]
                ],
                "Pyromane" => [
                    "helmet" => "air",
                    "chestplate" => "air",
                    "leggings" => "air",
                    "boots" => "air",
                    "sword" => "wooden_sword",
                    "items" => ["tnt" => 3, "flint_and_steel" => 1],
                    "effects" => []
                ],
                "PVP nu" => [
                    "helmet" => "air",
                    "chestplate" => "air",
                    "leggings" => "air",
                    "boots" => "air",
                    "items" => [],
                    "effects" => []
                ],
                "Mini-chevalier" => [
                    "helmet" => "chainmail_helmet",
                    "chestplate" => "chainmail_chestplate",
                    "leggings" => "chainmail_leggings",
                    "boots" => "chainmail_boots",
                    "stick" => ["stick", "knockback" => 1],
                    "items" => [],
                    "effects" => []
                ]
            ],
            "settings" => [
                "interval" => 60,
                "same_kit_for_all" => false,
                "random_interval" => false,
                "min_interval" => 30,
                "max_interval" => 90
            ]
        ]);

        $this->kits = $config->get("kits");
        $settings = $config->get("settings");
        $this->interval = $settings["interval"];
        $this->sameKitForAll = $settings["same_kit_for_all"];
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool {
        if($cmd->getName() === "chaoskit") {
            if(!isset($args[0])) return false;

            switch(strtolower($args[0])) {
                case "start":
                    if($this->isRunning) {
                        $sender->sendMessage("§cLe mode Chaos est déjà activé !");
                        return true;
                    }
                    $this->isRunning = true;
                    $this->getServer()->broadcastMessage("§6§lCHAOS MODE ACTIVÉ ! §r§7Préparez-vous au carnage !");

                    $this->task = $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
                        private $plugin;

                        public function __construct(Main $plugin) {
                            $this->plugin = $plugin;
                        }

                        public function onRun(): void {
                            $this->plugin->changeKits();
                        }
                    }, $this->interval * 20);

                    $this->changeKits();
                    return true;

                case "stop":
                    if(!$this->isRunning) {
                        $sender->sendMessage("§cLe mode Chaos n'est pas activé !");
                        return true;
                    }
                    $this->isRunning = false;
                    $this->getScheduler()->cancelTask($this->task->getTaskId());
                    $this->getServer()->broadcastMessage("§6§lCHAOS MODE DÉSACTIVÉ !");
                    return true;

                case "interval":
                    if(!isset($args[1]) || !is_numeric($args[1])) return false;
                    $this->interval = (int)$args[1];
                    $sender->sendMessage("§aIntervalle changé à §e" . $this->interval . " secondes");
                    return true;

                default:
                    return false;
            }
        }
        return false;
    }

    private function changeKits(): void {
        $players = $this->getServer()->getOnlinePlayers();
        if(empty($players)) return;

        $kitNames = array_keys($this->kits);
        $selectedKit = $kitNames[array_rand($kitNames)];

        if($this->sameKitForAll) {
            $this->getServer()->broadcastMessage("§e⚡ Nouveau kit pour tous : §6" . $selectedKit . "§e !");
            foreach($players as $player) {
                $this->applyKit($player, $selectedKit);
            }
        } else {
            $this->getServer()->broadcastMessage("§e⚡ Kits changés aléatoirement !");
            foreach($players as $player) {
                $randomKit = $kitNames[array_rand($kitNames)];
                $player->sendMessage("§eTon nouveau kit : §6" . $randomKit);
                $this->applyKit($player, $randomKit);
            }
        }
    }

    private function applyKit(Player $player, string $kitName): void {
        $kit = $this->kits[$kitName];

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getEffects()->clear();

        $this->applyArmor($player, $kit);
        $this->applyWeapons($player, $kit);
        $this->applyItems($player, $kit);
        $this->applyEffects($player, $kit);
    }

    private function applyArmor(Player $player, array $kit): void {
        $armorMap = [
            "helmet" => 0,
            "chestplate" => 1,
            "leggings" => 2,
            "boots" => 3
        ];

        foreach($armorMap as $slot => $index) {
            if(isset($kit[$slot]) && $kit[$slot] !== "air") {
                $item = VanillaItems::{$kit[$slot]}();
                $player->getArmorInventory()->setItem($index, $item);
            }
        }
    }

    private function applyWeapons(Player $player, array $kit): void {
        if(isset($kit["sword"])) {
            if(is_array($kit["sword"])) {
                $item = VanillaItems::{$kit["sword"][0]}();
                if(isset($kit["sword"]["sharpness"])) {
                    $item->addEnchantment(new EnchantmentInstance(Enchantment::get(Enchantment::SHARPNESS), $kit["sword"]["sharpness"]));
                }
                $player->getInventory()->addItem($item);
            } else {
                $player->getInventory()->addItem(VanillaItems::{$kit["sword"]}());
            }
        }

        if(isset($kit["bow"])) {
            if(is_array($kit["bow"])) {
                $item = VanillaItems::{$kit["bow"][0]}();
                if(isset($kit["bow"]["power"])) {
                    $item->addEnchantment(new EnchantmentInstance(Enchantment::get(Enchantment::POWER), $kit["bow"]["power"]));
                }
                $player->getInventory()->addItem($item);
            } else {
                $player->getInventory()->addItem(VanillaItems::{$kit["bow"]}());
            }
        }

        if(isset($kit["stick"])) {
            if(is_array($kit["stick"])) {
                $item = VanillaItems::{$kit["stick"][0]}();
                if(isset($kit["stick"]["knockback"])) {
                    $item->addEnchantment(new EnchantmentInstance(Enchantment::get(Enchantment::KNOCKBACK), $kit["stick"]["knockback"]));
                }
                $player->getInventory()->addItem($item);
            } else {
                $player->getInventory()->addItem(VanillaItems::{$kit["stick"]}());
            }
        }

        if(isset($kit["hoe"])) {
            $player->getInventory()->addItem(VanillaItems::{$kit["hoe"]}());
        }
    }

    private function applyItems(Player $player, array $kit): void {
        if(isset($kit["items"])) {
            foreach($kit["items"] as $itemName => $count) {
                $player->getInventory()->addItem(VanillaItems::{$itemName}()->setCount($count));
            }
        }
    }

    private function applyEffects(Player $player, array $kit): void {
        if(isset($kit["effects"])) {
            $effectMap = [
                "speed" => VanillaEffects::SPEED(),
                "slowness" => VanillaEffects::SLOWNESS(),
                "invisibility" => VanillaEffects::INVISIBILITY()
            ];

            foreach($kit["effects"] as $effectName => $amplifier) {
                if(isset($effectMap[$effectName])) {
                    $player->getEffects()->add(new EffectInstance($effectMap[$effectName], 20 * 60 * 5, $amplifier - 1));
                }
            }
        }
    }
}