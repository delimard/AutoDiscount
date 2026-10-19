<?php

declare(strict_types=1);

namespace AutoDiscount\Controller;

use AutoDiscount\AutoDiscount;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Translation\Translator;

/**
 * Contrôleur de configuration du module AutoDiscount (back-office).
 */
class AutoDiscountConfigController extends BaseAdminController
{
    /**
     * Affiche la page de configuration.
     */
    #[Route('/admin/module/AutoDiscount', name: 'autodiscount_configure', methods: ['GET'])]
    public function configureAction(): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'AutoDiscount', AccessManager::VIEW)) {
            return $response;
        }

        return $this->render('autodiscount-configuration', [
            'rules' => AutoDiscount::getRules(),
        ]);
    }

    /**
     * Sauvegarde l'ensemble des paliers de remise.
     *
     * Données POST attendues :
     *   min_amount[]       – montants seuils (ex : 100, 200, 300)
     *   discount_percent[] – pourcentages correspondants (ex : 5, 8, 10)
     *
     */
    #[Route('/admin/module/AutoDiscount/save', name: 'autodiscount_save', methods: ['POST'])]
    public function saveAction(Request $request): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'AutoDiscount', AccessManager::UPDATE)) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        try {
            $minAmounts       = $request->request->all('min_amount');
            $discountPercents = $request->request->all('discount_percent');

            $rules = [];

            foreach ($minAmounts as $i => $rawMinAmount) {
                $rawDiscount = $discountPercents[$i] ?? '';

                // Ignorer les lignes vides
                if ($rawMinAmount === '' || $rawDiscount === '') {
                    continue;
                }

                $minAmount       = (float) $rawMinAmount;
                $discountPercent = (float) $rawDiscount;

                if ($minAmount < 0) {
                    throw new \InvalidArgumentException(
                        Translator::getInstance()->trans(
                            'Le montant seuil doit être positif.',
                            [],
                            AutoDiscount::DOMAIN_NAME
                        )
                    );
                }

                if ($discountPercent <= 0 || $discountPercent > 100) {
                    throw new \InvalidArgumentException(
                        Translator::getInstance()->trans(
                            'Le pourcentage de remise doit être compris entre 0 et 100.',
                            [],
                            AutoDiscount::DOMAIN_NAME
                        )
                    );
                }

                $rules[] = [
                    'min_amount'       => $minAmount,
                    'discount_percent' => $discountPercent,
                ];
            }

            // Tri croissant par seuil pour le stockage
            usort($rules, static fn ($a, $b) => $a['min_amount'] <=> $b['min_amount']);

            AutoDiscount::setConfigValue(AutoDiscount::CONFIG_RULES, json_encode($rules, JSON_THROW_ON_ERROR));

        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['success' => true]);
    }
}
