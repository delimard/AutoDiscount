<?php

namespace AutoDiscount\Hook;

use AutoDiscount\AutoDiscount;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

/**
 * Gère les hooks front-office du module AutoDiscount.
 */
class HookManager extends BaseHook
{
    /**
     * Hook back-office : page de configuration du module dans /admin/modules.
     * La page est désormais servie par AutoDiscountConfigController (route GET).
     * Cette méthode est conservée vide pour compatibilité.
     */
    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        // Rendu délégué au contrôleur – ne rien ajouter ici.
    }

    /**
     * Hook front-office : affiché avant la zone "discount" sur la page
     * order-invoice (récapitulatif commande / tunnel de paiement).
     */
    public function onOrderInvoiceBeforeDiscount(HookRenderEvent $event): void
    {
        $session = $this->getSession();
        if (null === $session) {
            return;
        }

        $autoDiscountAmount = (float) $session->get(AutoDiscount::SESSION_KEY, 0);

        if ($autoDiscountAmount <= 0) {
            return;
        }

        $event->add(
            $this->render('order-invoice-auto-discount.html', [
                'auto_discount_amount' => $autoDiscountAmount,
            ])
        );
    }
}
