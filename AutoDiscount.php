<?php

namespace AutoDiscount;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Thelia\Module\BaseModule;

/**
 * Module AutoDiscount
 *
 * Applique automatiquement une remise en pourcentage au panier
 * dès que son montant HT atteint un palier configuré en back-office.
 */
class AutoDiscount extends BaseModule
{
    /** @var string Domaine de traduction */
    const DOMAIN_NAME = 'autodiscount';

    /** @var string Clé de configuration Thelia pour les règles JSON */
    const CONFIG_RULES = 'autodiscount_rules';

    /** @var string Clé de session stockant le montant de remise automatique appliqué */
    const SESSION_KEY = 'autodiscount.applied_amount';

    // ─── Cycle de vie Thelia ─────────────────────────────────────────────────

    /**
     * Initialise la config par défaut lors de l'activation du module.
     */
    public function preActivation(ConnectionInterface $con = null): bool
    {
        if (!self::getConfigValue('is_initialized', false)) {
            // Aucune base de données nécessaire : les règles sont stockées
            // dans la table module_config de Thelia via setConfigValue.
            self::setConfigValue(self::CONFIG_RULES, '[]');
            self::setConfigValue('is_initialized', true);
        }

        return true;
    }

    /**
     * Auto-configure tous les services du module (autowiring Symfony).
     */
    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([THELIA_MODULE_DIR . ucfirst(self::getModuleCode()) . '/I18n/*'])
            ->autowire(true)
            ->autoconfigure(true);
    }

    // ─── Gestion des règles ───────────────────────────────────────────────────

    /**
     * Récupère les règles triées par seuil croissant.
     *
     * @return array<int, array{min_amount: float, discount_percent: float}>
     */
    public static function getRules(): array
    {
        $rulesJson = self::getConfigValue(self::CONFIG_RULES, '[]');
        $rules = json_decode($rulesJson, true);

        if (!is_array($rules)) {
            return [];
        }

        // Tri croissant par seuil
        usort($rules, static fn ($a, $b) => $a['min_amount'] <=> $b['min_amount']);

        return $rules;
    }

    /**
     * Retourne la règle applicable pour un montant de panier donné.
     * On prend toujours le palier le plus élevé dont le seuil est atteint.
     *
     * @param float $cartAmount
     * @return array{min_amount: float, discount_percent: float}|null
     */
    public static function findBestRule(float $cartAmount): ?array
    {
        $best = null;

        foreach (self::getRules() as $rule) {
            $minAmount = (float) ($rule['min_amount'] ?? 0);
            if ($cartAmount >= $minAmount) {
                // On garde le palier avec le seuil le plus élevé atteint
                if ($best === null || $minAmount > (float) $best['min_amount']) {
                    $best = $rule;
                }
            }
        }

        return $best;
    }
}
