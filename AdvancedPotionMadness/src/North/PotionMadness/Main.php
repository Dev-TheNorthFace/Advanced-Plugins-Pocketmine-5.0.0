<?php

namespace North\PotionMadness\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\effect\VanillaEffects;

class Main extends PluginBase implements Listener {
    private $isActive = false;
    private $task;
    private $config;

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "effects" => [
                "positive" => [
                    "strength" => ["name" => "Force", "id" => VanillaEffects::STRENGTH(), "amplifier" => 1],
                    "speed" => ["name" => "Vitesse", "id" => VanillaEffects::SPEED(), "amplifier" => 1],
                    "jump_boost" => ["name" => "Saut amélioré", "id" => VanillaEffects::JUMP_BOOST(), "amplifier" => 0],
                    "regeneration" => ["name" => "Régénération", "id" => VanillaEffects::REGENERATION(), "amplifier" => 0],
                    "resistance" => ["name" => "Résistance", "id" => VanillaEffects::RESISTANCE(), "amplifier" => 0],
                    "invisibility" => ["name" => "Invisibilité", "id" => VanillaEffects::INVISIBILITY(), "amplifier" => 0]
                ],
                "negative" => [
                    "slowness" => ["name" => "Lenteur", "id" => VanillaEffects::SLOWNESS(), "amplifier" => 1],
                    "mining_fatigue" => ["name" => "Fatigue", "id" => VanillaEffects::MINING_FATIGUE(), "amplifier" => 0],
                    "blindness" => ["name" => "Cécité", "id" => VanillaEffects::BLINDNESS(), "amplifier" => 0],
                    "nausea" => ["name" => "Nausée", "id" => VanillaEffects::NAUSEA(), "amplifier" => 0],
                    "poison" => ["name" => "Poison", "id" => VanillaEffects::POISON(), "amplifier" => 0],
                    "weakness" => ["name" => "Faiblesse", "id" => VanillaEffects::WEAKNESS(), "amplifier" => 0]
                ]
            ],
            "duration_min" => 5,
            "duration_max" => 15,
            "interval" => 20
        ]);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if($command->getName() === "potionmadness") {
            if(!isset($args[0])) return false;

            if($args[0] === "start") {
                if($this->isActive) {
                    $sender->sendMessage("§cLe Potion Madness est déjà actif!");
                    return true;
                }

                $this->isActive = true;
                $this->task = $this->getScheduler()->scheduleRepeatingTask(new PotionTask($this), $this->config->get("interval") * 20);
                $sender->sendMessage("§aPotion Madness activé!");
                return true;
            }

            if($args[0] === "stop") {
                if(!$this->isActive) {
                    $sender->sendMessage("§cLe Potion Madness n'est pas actif!");
                    return true;
                }

                $this->isActive = false;
                $this->getScheduler()->cancelTask($this->task->getTaskId());
                $sender->sendMessage("§aPotion Madness désactivé!");
                return true;
            }
        }
        return false;
    }

    public function applyRandomEffect(): void {
        $effects = $this->config->get("effects");
        $allEffects = array_merge($effects["positive"], $effects["negative"]);
        $randomEffect = $allEffects[array_rand($allEffects)];

        $duration = rand($this->config->get("duration_min"), $this->config->get("duration_max")) * 20;
        $effectInstance = new EffectInstance($randomEffect["id"], $duration, $randomEffect["amplifier"]);

        $prefix = isset($effects["positive"][array_search($randomEffect, $effects["positive"])]) ? "§a💪" : "§c☠";

        foreach($this->getServer()->getOnlinePlayers() as $player) {
            $player->getEffects()->add($effectInstance);
            $player->sendMessage($prefix . " Effet appliqué: " . $randomEffect["name"] . " (" . ($duration/20) . "s)");
        }
    }
}

class PotionTask extends Task {
    private $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $this->plugin->applyRandomEffect();
    }
}