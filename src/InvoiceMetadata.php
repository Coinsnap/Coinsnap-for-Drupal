<?php

namespace Drupal\drupalcommerce_coinsnap;

/**
 * Order and customer data sent with an invoice, so the merchant can find the order in Coinsnap / BTCPay.
 *
 * Reads the Commerce order through its public API. Every read is guarded, so a missing field or module
 * (shipping, promotions, a phone field) never stops the checkout. Plain values only, empty values skipped.
 */
final class InvoiceMetadata {

  const PLATFORM = 'drupal_commerce';

  // Order fields some sites add for a customer note; Commerce itself has none.
  const NOTE_FIELDS = ['field_customer_note', 'field_customer_comment', 'field_customer_comments', 'field_order_comment', 'field_order_comments', 'field_comment', 'field_comments', 'field_note', 'field_notes'];

  // Phone fields on the billing profile; Commerce itself has none.
  const PHONE_FIELDS = ['field_phone', 'field_telephone', 'field_phone_number', 'phone', 'telephone'];

  /**
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   * @param string $orderUrl  Admin URL of the order, or ''.
   */
  public static function fromOrder($order, string $orderUrl = '', string $pluginVersion = ''): array {
    $id = (string) self::get(function () use ($order) { return $order->id(); });
    $number = self::get(function () use ($order) { return $order->getOrderNumber(); });
    $profile = self::get(function () use ($order) { return $order->getBillingProfile(); });
    $address = self::get(function () use ($profile) { return $profile ? $profile->get('address')->first() : NULL; });
    $addr = function (string $method) use ($address) {
      return self::get(function () use ($address, $method) { return $address ? $address->$method() : NULL; });
    };
    $name = trim(self::str($addr('getGivenName')) . ' ' . self::str($addr('getFamilyName')));

    $meta = [
      // The webhook compares the invoice's orderId (BTCPay: metadata.orderId) with the order ID; keep it.
      'orderId' => $id,
      // The order number is assigned when the order is placed, which is after the payment; until then the ID.
      'orderNumber' => self::str($number) !== '' ? $number : $id,
      'orderUrl' => $orderUrl,
      'platform' => self::PLATFORM,
      'pluginVersion' => $pluginVersion,
      'storeId' => self::get(function () use ($order) { return $order->getStoreId(); }),
      'storeName' => self::get(function () use ($order) { $s = $order->getStore(); return $s ? $s->label() : NULL; }),
      // Personal data: the module has no "send customer data" setting, so it is always sent.
      'customerName' => $name,
      'buyerName' => $name,
      'buyerEmail' => self::get(function () use ($order) { return $order->getEmail(); }),
      'buyerPhone' => self::firstField($profile, self::PHONE_FIELDS),
      'buyerCompany' => $addr('getOrganization'),
      'buyerAddress1' => $addr('getAddressLine1'),
      'buyerAddress2' => $addr('getAddressLine2'),
      'buyerCity' => $addr('getLocality'),
      'buyerZip' => $addr('getPostalCode'),
      'buyerState' => $addr('getAdministrativeArea'),
      'buyerCountry' => $addr('getCountryCode'),
      'buyerMessage' => self::firstField($order, self::NOTE_FIELDS),
      'currency' => self::get(function () use ($order) { return $order->getTotalPrice()->getCurrencyCode(); }),
      'orderTotal' => self::num(self::get(function () use ($order) { return $order->getTotalPrice()->getNumber(); })),
      'subtotal' => self::num(self::get(function () use ($order) { return $order->getSubtotalPrice()->getNumber(); })),
      'language' => self::get(function () { return \Drupal::languageManager()->getCurrentLanguage()->getId(); }),
    ];

    // Order and item adjustments: shipping, tax, promotions, the module's own Bitcoin discount, ...
    $sums = [];
    foreach ((self::get(function () use ($order) { return $order->collectAdjustments(); }) ?? []) as $adjustment) {
      $type = self::get(function () use ($adjustment) { return $adjustment->getType(); });
      $amount = self::num(self::get(function () use ($adjustment) { return $adjustment->getAmount()->getNumber(); }));
      if (is_string($type) && $amount !== NULL) {
        $sums[$type] = ($sums[$type] ?? 0) + $amount;
      }
    }
    $meta['shippingTotal'] = isset($sums['shipping']) ? round($sums['shipping'], 2) : NULL;
    $meta['taxTotal'] = isset($sums['tax']) ? round($sums['tax'], 2) : NULL;
    $discount = 0.0;
    foreach ($sums as $type => $sum) {
      if ($sum < 0 && $type !== 'tax') {
        $discount += abs($sum);
      }
    }
    $meta['discountTotal'] = $discount > 0 ? round($discount, 2) : NULL;

    $meta['couponCodes'] = self::get(function () use ($order) {
      if (!$order->hasField('coupons')) {
        return [];
      }
      return array_values(array_filter(array_map(function ($c) { return (string) $c->getCode(); }, $order->get('coupons')->referencedEntities()), 'strlen'));
    }) ?? [];

    $meta['shippingMethod'] = self::get(function () use ($order) {
      if (!$order->hasField('shipments')) {
        return NULL;
      }
      $names = [];
      foreach ($order->get('shipments')->referencedEntities() as $shipment) {
        $method = $shipment->getShippingMethod();
        $names[] = $method ? (string) $method->label() : (string) $shipment->label();
      }
      return implode(', ', array_filter($names, 'strlen'));
    });

    $lineItems = [];
    $names = [];
    $codes = [];
    foreach ((self::get(function () use ($order) { return $order->getItems(); }) ?? []) as $item) {
      $entity = self::get(function () use ($item) { return $item->getPurchasedEntity(); });
      $qty = self::num(self::get(function () use ($item) { return $item->getQuantity(); }));
      $line = self::clean([
        'name' => self::get(function () use ($item) { return $item->getTitle(); }),
        'sku' => self::get(function () use ($entity) { return ($entity && method_exists($entity, 'getSku')) ? $entity->getSku() : NULL; }),
        'productId' => self::get(function () use ($entity) { return ($entity && method_exists($entity, 'getProductId')) ? $entity->getProductId() : NULL; }),
        'variationId' => self::get(function () use ($entity) { return $entity ? $entity->id() : NULL; }),
        'quantity' => $qty,
        'price' => self::num(self::get(function () use ($item) { return $item->getUnitPrice()->getNumber(); })),
        // After promotions and other item adjustments.
        'total' => self::num(self::get(function () use ($item) { return $item->getAdjustedTotalPrice()->getNumber(); })),
      ]);
      if (!isset($line['name']) && !isset($line['sku'])) {
        continue;
      }
      $lineItems[] = $line;
      if (isset($line['name'])) {
        $names[] = ($qty !== NULL && $qty != 1 ? self::qty($qty) . ' x ' : '') . $line['name'];
      }
      $code = $line['sku'] ?? $line['productId'] ?? NULL;
      if ($code !== NULL) {
        $codes[] = (string) $code;
      }
    }
    $meta['lineItems'] = $lineItems;
    $meta['itemDesc'] = $names !== [] ? mb_substr(implode(', ', $names), 0, 250) : NULL;
    $meta['itemCode'] = $codes !== [] ? mb_substr(implode(', ', $codes), 0, 250) : NULL;

    return self::clean($meta);
  }

  // The first of these fields the entity has, as a plain string (phone, note).
  private static function firstField($entity, array $fields) {
    return self::get(function () use ($entity, $fields) {
      if (!$entity) {
        return NULL;
      }
      foreach ($fields as $field) {
        if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
          $value = $entity->get($field)->first()->getValue();
          $value = is_array($value) ? ($value['value'] ?? reset($value)) : $value;
          if (is_scalar($value) && trim((string) $value) !== '') {
            return (string) $value;
          }
        }
      }
      return NULL;
    });
  }

  private static function get(callable $read) {
    try {
      return $read();
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  private static function str($v): string {
    return is_scalar($v) ? trim((string) $v) : '';
  }

  private static function num($v): ?float {
    return is_numeric($v) ? round((float) $v, 8) : NULL;
  }

  private static function qty(float $q): string {
    return floor($q) == $q ? (string) (int) $q : (string) $q;
  }

  private static function clean(array $data): array {
    $out = [];
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        if ($value !== []) {
          $out[$key] = $value;
        }
      }
      elseif (is_int($value) || is_float($value)) {
        $out[$key] = $value;
      }
      elseif (($s = self::str($value)) !== '') {
        $out[$key] = $s;
      }
    }
    return $out;
  }

}
