<?php

declare(strict_types=1);

namespace AutoDiscount\Hook;

use AutoDiscount\AutoDiscount;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

/**
 * Gère les hooks front-office du module AutoDiscount.
 */
class HookManager extends BaseHook
{
    public static function getSubscribedHooks(): array
    {
        return [
            'order-invoice.before-discount' => [
                ['type' => 'front', 'method' => 'onOrderInvoiceBeforeDiscount'],
            ],
        ];
    }

    /**
     * Affiché avant la zone "discount" de la page order-invoice (récapitulatif commande).
     */
    public function onOrderInvoiceBeforeDiscount(HookRenderEvent $event): void
    {
        $autoDiscountAmount = (float) $this->getSession()->get(AutoDiscount::SESSION_KEY, 0);

        if ($autoDiscountAmount <= 0) {
            return;
        }

        $event->add($this->render('order-invoice-auto-discount.html.twig', [
            'auto_discount_amount' => $autoDiscountAmount,
        ]));
    }
}
