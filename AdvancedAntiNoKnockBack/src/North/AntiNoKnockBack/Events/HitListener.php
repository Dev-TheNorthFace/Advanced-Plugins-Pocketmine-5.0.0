<?php

namespace North\AntiNoKnockBack\Events\HitListener;

use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\player\Player;
use pocketmine\entity\projectile\Projectile;
use North\AntiNoKnockBack\Main;
use North\AntiNoKnockBack\Tasks\KnockbackCheckTask;

class HitListener implements Listener {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        if ($event->isCancelled()) return;

        $victim = $event->getEntity();
        $attacker = $event instanceof EntityDamageByEntityEvent
            ? $event->getDamager()
            : null;

        if (!$victim instanceof Player) return;
        if ($this->shouldIgnoreDamage($event->getCause())) return;
        $this->prepareKnockbackAnalysis($victim, $attacker);
    }

    private function shouldIgnoreDamage(int $cause): bool {
        $ignoredCauses = [
            EntityDamageEvent::CAUSE_FALL,
            EntityDamageEvent::CAUSE_DROWNING,
            EntityDamageEvent::CAUSE_FIRE,
            EntityDamageEvent::CAUSE_SUFFOCATION,
            EntityDamageEvent::CAUSE_VOID,
            EntityDamageEvent::CAUSE_MAGIC,
            EntityDamageEvent::CAUSE_STARVATION
        ];

        return in_array($cause, $ignoredCauses, true);
    }

    private function prepareKnockbackAnalysis(Player $victim, $attacker): void {
        $config = $this->plugin->getKBConfig();
        $victimName = $victim->getName();
        $this->plugin->getStatsCalculator()->recordHit($victim, $victim->getPosition());
        $this->plugin->getScheduler()->scheduleDelayedTask(
            new KnockbackCheckTask(
                $this->plugin,
                $victim,
                $victim->getPosition(),
                false
            ),
            $config['check_delay_ticks'] ?? 10
        );

        if ($attacker instanceof Player) {
            $this->handlePlayerAttack($victim, $attacker);
        }
        elseif ($attacker instanceof Projectile && $attacker->getOwningEntity() instanceof Player) {
            $this->handleProjectileAttack($victim, $attacker);
        }

        $this->plugin->getLogger()->debug(
            "Hit enregistré pour $victimName - " .
            ($attacker ? "Attaquant: " . $attacker->getName() : "Dégâts environnementaux")
        );
    }

    private function handlePlayerAttack(Player $victim, Player $attacker): void {
        $victimStats = $this->plugin->getStatsCalculator()->getPlayerStats($victim);
        $attackerName = $attacker->getName();
        if ($victimStats['hits_received'] > 5 &&
            ($victimStats['kb_hits'] / $victimStats['hits_received']) < 0.3) {
            $this->plugin->getLogger()->warning(
                "Motif suspect détecté - Victim: $victimName, Attacker: $attackerName"
            );

            if ($this->plugin->getKBConfig()['simulate_knockback']) {
                $this->plugin->sendTestProjectile($victim);
            }
        }
    }

    private function handleProjectileAttack(Player $victim, Projectile $projectile): void {
        $projectileType = $projectile->getName();
        $shooter = $projectile->getOwningEntity();
        $this->plugin->getLogger()->debug(
            "Projectile $projectileType touchant $victimName - " .
            "Tiré par: " . ($shooter instanceof Player ? $shooter->getName() : "Inconnu")
        );

        if ($this->plugin->hasTestProjectile($victim->getName())) {
            $this->plugin->getLogger()->debug(
                "Analyse projectile de test pour $victimName"
            );
            $this->plugin->removeTestProjectile($victim->getName());
        }
    }
}