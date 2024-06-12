<?php

namespace Drupal\drupalcommerce_coinsnap\Controller;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Access\AccessException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the endpoint for payment notifications.
 */
class PaymentNotificationController implements ContainerInjectionInterface
{
    /**
     * The checkout order manager.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * Constructs a new PaymentNotificationController object.
     *
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     */
    public function __construct(EntityTypeManagerInterface $entity_type_manager)
    {
        $this->entityTypeManager = $entity_type_manager;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('entity_type.manager')
        );
    }

    /**
     * Provides the "notify" page. Also called the "IPN", "status", "webhook" page by payment providers.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function notifyPage(Request $request)
    {
        
        $payment_gateway_id = 'coinsnap';
        $payment_gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
        $payment_gateway = $payment_gateway_storage->load($payment_gateway_id);                       

        $payment_gateway_plugin = $payment_gateway->getPlugin();
        if (! $payment_gateway_plugin instanceof SupportsNotificationsInterface) {
            throw new AccessException('Invalid payment gateway provided.');
        }

        $response = $payment_gateway_plugin->onNotify($request);
        if (! $response) {
            $response = new Response('', 200);
        }

        return $response;
    }
}
