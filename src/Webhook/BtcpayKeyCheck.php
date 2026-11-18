<?php
declare(strict_types=1);

namespace Drupal\drupalcommerce_coinsnap\Webhook;

// Accepts a pasted BTCPay API key only when every right it has is limited to the configured store.
class BtcpayKeyCheck
{
    public const REQUIRED_PERMISSIONS = [
        'btcpay.store.cancreateinvoice',
        'btcpay.store.canviewinvoices',
        'btcpay.store.webhooks.canmodifywebhooks',
    ];

    // Null when the key is fine, otherwise the reason to show the admin.
    public static function problem(string $serverUrl, string $apiKey, string $storeId): ?string
    {
        $permissions = self::fetchPermissions($serverUrl, $apiKey);
        if ($permissions === null) {
            return 'The BTCPay Server could not confirm this API key. Check the server URL and the key.';
        }
        return self::permissionProblem($permissions, $storeId);
    }

    public static function permissionProblem(array $permissions, string $storeId): ?string
    {
        $granted = [];
        foreach ($permissions as $permission) {
            [$policy, $scope] = array_pad(explode(':', (string) $permission, 2), 2, '');
            if (strpos($policy, 'btcpay.store.') !== 0 || $scope === '' || $scope !== $storeId) {
                return 'The BTCPay API key may only have rights for this store. Create a key limited to store ' . $storeId . '.';
            }
            $granted[] = $policy;
        }
        if (array_diff(self::REQUIRED_PERMISSIONS, $granted) !== []) {
            return 'The BTCPay API key needs these rights for the store: ' . implode(', ', self::REQUIRED_PERMISSIONS) . '.';
        }
        return null;
    }

    private static function fetchPermissions(string $serverUrl, string $apiKey): ?array
    {
        if (!preg_match('#^https?://#i', $serverUrl) || $apiKey === '') {
            return null;
        }
        $curl = curl_init(rtrim($serverUrl, '/') . '/api/v1/api-keys/current');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: token ' . $apiKey, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $data = is_string($body) ? json_decode($body, true) : null;
        return ($status === 200 && is_array($data['permissions'] ?? null)) ? $data['permissions'] : null;
    }
}
