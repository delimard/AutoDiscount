<?php

namespace AutoDiscount\EventListener;

use AutoDiscount\AutoDiscount;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Coupon\CouponConsumeEvent;
use Thelia\Core\Event\Customer\CustomerLoginEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Coupon\CouponManager;
use Thelia\TaxEngine\TaxEngine;

/**
 * Listener principal du module AutoDiscount.
 *
 * Recalcule et applique la remise automatique après chaque modification
 * du panier et lors du tunnel de commande.
 *
 * Priorité 9 sur les événements cart : on tourne APRÈS l'Action\Coupon
 * de Thelia (priorité 10) qui appelle updateOrderDiscount() et reset
 * cart->setDiscount() au seul discount coupon. En tournant après, on
 * peut combiner correctement couponDiscount + autoDiscount.
 */
class AutoDiscountListener implements EventSubscriberInterface
{
    public function __construct(
        private RequestStack             $requestStack,
        private EventDispatcherInterface $dispatcher,
        private CouponManager            $couponManager,
        private TaxEngine                $taxEngine
    ) {
    }

    // ─── Événements panier ──────────────────────────────────────────────────

    public function onCartAddItem(CartEvent $event): void
    {
        $this->applyAutoDiscount();
    }

    public function onCartUpdateItem(CartEvent $event): void
    {
        $this->applyAutoDiscount();
    }

    public function onCartDeleteItem(CartEvent $event): void
    {
        $this->applyAutoDiscount();
    }

    public function onCartClear(CartEvent $event): void
    {
        // Le panier est vidé : on supprime la remise automatique
        $this->clearAutoDiscount();
    }

    // ─── Événements de checkout ──────────────────────────────────────────────

    /**
     * Recalcule la remise quand le module de livraison est sélectionné.
     * C'est l'événement qui précède l'affichage de la page order-invoice.
     */
    public function onOrderSetDeliveryModule(OrderEvent $event): void
    {
        $this->applyAutoDiscount();
    }

    /**
     * Recalcule après connexion client (le CouponAction remet le discount à 0 à ce moment).
     */
    public function onCustomerLogin(CustomerLoginEvent $event): void
    {
        $this->applyAutoDiscount();
    }

    /**
     * Recalcule après application d'un coupon pour cumuler
     * la remise coupon avec la remise automatique du module.
     * Priorité 9 : après le Coupon action (128) qui set le discount coupon seul.
     */
    public function onCouponConsume(CouponConsumeEvent $event): void
    {
        $this->applyAutoDiscount();
    }

    /**
     * Recalcule après suppression de tous les coupons.
     */
    public function onCouponClearAll(): void
    {
        $this->applyAutoDiscount();
    }

    // ─── Logique principale ─────────────────────────────────────────────────

    /**
     * Calcule la remise automatique en TTC selon le montant TTC total du panier,
     * puis l'applique sur le panier et la commande en session.
     *
     * Thelia stocke le discount en TTC et le soustrait du total TTC.
     * Les paliers sont donc comparés au montant TTC brut (sans discount existant).
     */
    private function applyAutoDiscount(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return;
        }

        /** @var \Thelia\Core\HttpFoundation\Session\Session $session */
        $session = $request->getSession();
        if (null === $session) {
            return;
        }

        $cart = $session->getSessionCart($this->dispatcher);
        if (null === $cart) {
            return;
        }

        // Montant TTC brut (false = sans soustraire de remise existante).
        // La remise Thelia est en TTC, et les clients raisonnent en TTC.
        // On compare les paliers et on calcule le montant de remise en TTC.
        $taxCountry = $this->taxEngine->getDeliveryCountry();
        $taxState   = $this->taxEngine->getDeliveryState();
        $cartTotal  = (float) $cart->getTaxedAmount($taxCountry, false, $taxState);

        // Palier applicable (le plus élevé dont le seuil est atteint)
        $rule = AutoDiscount::findBestRule($cartTotal);

        $autoDiscountAmount = 0.0;
        if (null !== $rule) {
            $autoDiscountAmount = round($cartTotal * ((float) $rule['discount_percent'] / 100.0), 2);
        }

        // Remise coupons déjà présente en session
        $couponDiscount = (float) $this->couponManager->getDiscount();

        // Remise totale (ne peut pas dépasser le total panier)
        $totalDiscount = min($couponDiscount + $autoDiscountAmount, $cartTotal);

        // Mise à jour du panier
        $cart->setDiscount($totalDiscount);
        $cart->save();

        // Mise à jour de la commande en session
        $order = $session->getOrder();
        $order->setDiscount($totalDiscount);

        // Mémorisation du montant de la remise automatique
        $session->set(AutoDiscount::SESSION_KEY, $autoDiscountAmount);
    }

    /**
     * Supprime la remise automatique (panier vidé).
     */
    private function clearAutoDiscount(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return;
        }

        /** @var \Thelia\Core\HttpFoundation\Session\Session $session */
        $session = $request->getSession();
        if (null === $session) {
            return;
        }

        $session->set(AutoDiscount::SESSION_KEY, 0);

        // Le panier sera vide après l'événement CART_CLEAR,
        // la remise se remettra à 0 naturellement.
        $cart = $session->getSessionCart($this->dispatcher);
        if (null !== $cart) {
            $couponDiscount = (float) $this->couponManager->getDiscount();
            $cart->setDiscount($couponDiscount);
            $cart->save();
        }

        $order = $session->getOrder();
        if (null !== $order) {
            $couponDiscount = (float) $this->couponManager->getDiscount();
            $order->setDiscount($couponDiscount);
        }
    }

    // ─── Abonnements ────────────────────────────────────────────────────────

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité 9 : s'exécute APRÈS le Coupon action de Thelia (priorité 10)
            // qui appelle updateOrderDiscount() et reset $cart->setDiscount() à zéro.
            // En tournant après, on ajoute notre remise AUTO au-dessus du discount coupon.
            TheliaEvents::CART_ADDITEM    => ['onCartAddItem',    9],
            TheliaEvents::CART_UPDATEITEM => ['onCartUpdateItem', 9],
            TheliaEvents::CART_DELETEITEM => ['onCartDeleteItem', 9],
            TheliaEvents::CART_CLEAR      => ['onCartClear',      9],

            // Recalcule en milieu de tunnel de commande.
            // Le Coupon action n'écoute pas ces events => prio libre.
            TheliaEvents::ORDER_SET_DELIVERY_MODULE => ['onOrderSetDeliveryModule', 64],

            // Recalcule après login client (Coupon action remet discount à 0 à ce moment).
            TheliaEvents::CUSTOMER_LOGIN => ['onCustomerLogin', 9],

            // Cumul coupon + remise auto : on recalcule après que le Coupon action
            // ait mis à jour le discount (priorité 128 → on prend 9 pour passer après).
            TheliaEvents::COUPON_CONSUME   => ['onCouponConsume',  9],
            TheliaEvents::COUPON_CLEAR_ALL => ['onCouponClearAll', 9],
        ];
    }
}
