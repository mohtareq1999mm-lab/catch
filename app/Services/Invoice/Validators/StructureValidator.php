<?php

namespace App\Services\Invoice\Validators;

use App\Contracts\Services\Invoice\SnapshotValidatorInterface;
use App\Exceptions\SnapshotValidationException;

class StructureValidator implements SnapshotValidatorInterface
{
    private const REQUIRED_ROOT_KEYS = [
        'snapshot_version',
        'snapshot_schema',
        'customer',
        'billing_address',
        'shipping_address',
        'fulfillment',
        'items',
        'pricing_breakdown',
        'payment',
        'taxes',
        'metadata',
        'audit',
    ];

    private const REQUIRED_CUSTOMER_KEYS = ['id', 'name', 'phone'];

    private const REQUIRED_FULFILLMENT_KEYS = ['type', 'shipping_method', 'shipping_price'];

    private const REQUIRED_PRICING_KEYS = [
        'subtotal', 'promotion_discount', 'coupon_discount',
        'shipping_price', 'total', 'currency',
    ];

    private const REQUIRED_PAYMENT_KEYS = ['method', 'transaction_id', 'paid_at'];

    private const REQUIRED_METADATA_KEYS = ['system_version', 'locale', 'generated_at'];

    private const REQUIRED_AUDIT_KEYS = ['generated_by', 'generation_attempts'];

    private function ensureKeysExist(array $data, array $keys, string $context): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                $missing[] = $key;
            }
        }
        if ($missing) {
            throw new SnapshotValidationException(
                "Missing required fields in {$context}: " . implode(', ', $missing)
            );
        }
    }

    public function validate(array $snapshot): void
    {
        $this->ensureKeysExist($snapshot, self::REQUIRED_ROOT_KEYS, 'root');
        $this->ensureKeysExist($snapshot['customer'], self::REQUIRED_CUSTOMER_KEYS, 'customer');
        $this->ensureKeysExist($snapshot['fulfillment'], self::REQUIRED_FULFILLMENT_KEYS, 'fulfillment');
        $this->ensureKeysExist($snapshot['pricing_breakdown'], self::REQUIRED_PRICING_KEYS, 'pricing_breakdown');
        $this->ensureKeysExist($snapshot['payment'], self::REQUIRED_PAYMENT_KEYS, 'payment');
        $this->ensureKeysExist($snapshot['metadata'], self::REQUIRED_METADATA_KEYS, 'metadata');
        $this->ensureKeysExist($snapshot['audit'], self::REQUIRED_AUDIT_KEYS, 'audit');

        if (!is_array($snapshot['items'])) {
            throw new SnapshotValidationException('items must be an array');
        }

        if (!is_array($snapshot['taxes'])) {
            throw new SnapshotValidationException('taxes must be an array');
        }
    }
}
