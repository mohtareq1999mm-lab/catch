<?php

namespace App\Services\Coupon;

use App\Enums\EligibilityRuleType;

/**
 * Backend-authoritative coupon Rule Catalog metadata.
 *
 * Single source of truth for DISPLAY: rule identifiers come from
 * EligibilityRuleType (the same enum consumed by RuleTreeValidator and
 * EligibilityEngine), and value constraints mirror
 * RuleTreeValidator::validateValue. Labels/descriptions are display-only
 * companions (en/ar, following the notifications payload convention) and
 * are NEVER used for validation — RuleTreeValidator remains authoritative.
 *
 * No customer data is read here: the response is fully static.
 */
final class CouponRuleMetadata
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $rules = [];
        foreach (EligibilityRuleType::cases() as $case) {
            $rules[] = self::for($case);
        }

        return $rules;
    }

    public static function grammar(): array
    {
        return [
            'supported' => true,
            'operators' => ['AND', 'OR'],
            'max_depth' => RuleTreeValidator::MAX_DEPTH,
            'nested_groups_allowed' => true,
            'empty_group_allowed' => false,
            'null_allowed' => true,
            'duplicate_rules_allowed' => true,
            'unknown_rule_behavior' => 'reject_422',
            'malformed_node_behavior' => 'reject_422',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function for(EligibilityRuleType $rule): array
    {
        return match ($rule) {
            EligibilityRuleType::MIN_COMPLETED_ORDERS => self::base($rule,
                labelEn: 'Minimum completed orders',
                labelAr: 'الحد الأدنى من الطلبات المكتملة',
                descEn: 'Requires the customer to have at least the specified number of qualifying completed orders (status completed and payment successful).',
                descAr: 'يشترط أن يكون لدى العميل عدد محدد على الأقل من الطلبات المكتملة المؤهلة (مكتملة ومدفوعة بنجاح).',
                valueType: 'integer', valueRequired: true, valueExample: 3,
                min: 0, context: 'customer_history'),
            EligibilityRuleType::MAX_COMPLETED_ORDERS => self::base($rule,
                labelEn: 'Maximum completed orders',
                labelAr: 'الحد الأقصى من الطلبات المكتملة',
                descEn: 'Requires the customer to have at most the specified number of qualifying completed orders.',
                descAr: 'يشترط ألا يتجاوز عدد الطلبات المكتملة المؤهلة للعميل العدد المحدد.',
                valueType: 'integer', valueRequired: true, valueExample: 10,
                min: 0, context: 'customer_history'),
            EligibilityRuleType::MIN_TOTAL_SPEND => self::base($rule,
                labelEn: 'Minimum total spend',
                labelAr: 'الحد الأدنى لإجمالي الإنفاق',
                descEn: 'Requires the customer qualifying spend (sum of converted_total_price in base/catalog currency, 2-decimal precision) to be at least the specified amount.',
                descAr: 'يشترط ألا يقل إجمالي إنفاق العميل المؤهل (مجموع converted_total_price بعملة الأساس بدقة منزلتين) عن المبلغ المحدد.',
                valueType: 'decimal', valueRequired: true, valueExample: '100.00',
                min: 0, context: 'customer_history'),
            EligibilityRuleType::MAX_TOTAL_SPEND => self::base($rule,
                labelEn: 'Maximum total spend',
                labelAr: 'الحد الأقصى لإجمالي الإنفاق',
                descEn: 'Requires the customer qualifying spend (sum of converted_total_price in base/catalog currency, 2-decimal precision) to be at most the specified amount.',
                descAr: 'يشترط ألا يتجاوز إجمالي إنفاق العميل المؤهل (مجموع converted_total_price بعملة الأساس بدقة منزلتين) المبلغ المحدد.',
                valueType: 'decimal', valueRequired: true, valueExample: '500.00',
                min: 0, context: 'customer_history'),
            EligibilityRuleType::FIRST_ORDER_AFTER => self::date($rule,
                labelEn: 'First order after',
                labelAr: 'أول طلب بعد تاريخ',
                descEn: 'Requires the customer first qualifying order to be strictly after the specified UTC datetime. Customers with no qualifying orders fail.',
                descAr: 'يشترط أن يكون أول طلب مؤهل للعميل بعد التاريخ المحدد بتوقيت UTC حصراً. العملاء بلا طلبات مؤهلة لا يحققون الشرط.'),
            EligibilityRuleType::FIRST_ORDER_BEFORE => self::date($rule,
                labelEn: 'First order before',
                labelAr: 'أول طلب قبل تاريخ',
                descEn: 'Requires the customer first qualifying order to be strictly before the specified UTC datetime. Customers with no qualifying orders fail.',
                descAr: 'يشترط أن يكون أول طلب مؤهل للعميل قبل التاريخ المحدد بتوقيت UTC حصراً. العملاء بلا طلبات مؤهلة لا يحققون الشرط.'),
            EligibilityRuleType::LAST_ORDER_AFTER => self::date($rule,
                labelEn: 'Last order after',
                labelAr: 'آخر طلب بعد تاريخ',
                descEn: 'Requires the customer most recent qualifying order to be strictly after the specified UTC datetime. Customers with no qualifying orders fail.',
                descAr: 'يشترط أن يكون أحدث طلب مؤهل للعميل بعد التاريخ المحدد بتوقيت UTC حصراً. العملاء بلا طلبات مؤهلة لا يحققون الشرط.'),
            EligibilityRuleType::LAST_ORDER_BEFORE => self::date($rule,
                labelEn: 'Last order before',
                labelAr: 'آخر طلب قبل تاريخ',
                descEn: 'Requires the customer most recent qualifying order to be strictly before the specified UTC datetime. Customers with no qualifying orders fail.',
                descAr: 'يشترط أن يكون أحدث طلب مؤهل للعميل قبل التاريخ المحدد بتوقيت UTC حصراً. العملاء بلا طلبات مؤهلة لا يحققون الشرط.'),
            EligibilityRuleType::MIN_COUPONS_USED => self::base($rule,
                labelEn: 'Minimum coupons used',
                labelAr: 'الحد الأدنى للكوبونات المستخدمة',
                descEn: 'Requires the customer to have used a coupon in at least the specified number of qualifying orders (order count, not distinct codes).',
                descAr: 'يشترط أن يكون العميل قد استخدم كوبوناً في عدد محدد على الأقل من الطلبات المؤهلة (عدد الطلبات وليس الأكواد المميزة).',
                valueType: 'integer', valueRequired: true, valueExample: 1,
                min: 0, context: 'customer_history'),
            EligibilityRuleType::MAX_COUPONS_USED => self::base($rule,
                labelEn: 'Maximum coupons used',
                labelAr: 'الحد الأقصى للكوبونات المستخدمة',
                descEn: 'Requires the customer to have used a coupon in at most the specified number of qualifying orders (order count, not distinct codes).',
                descAr: 'يشترط ألا يتجاوز استخدام العميل للكوبونات (عدد الطلبات وليس الأكواد المميزة) العدد المحدد.',
                valueType: 'integer', valueRequired: true, valueExample: 5,
                min: 0, context: 'customer_history'),
            EligibilityRuleType::CLAIMED => self::base($rule,
                labelEn: 'Has claimed',
                labelAr: 'قام بالمطالبة',
                descEn: 'Requires the customer to hold an active unexpired claim or a redeemed claim for this coupon. Expired claims count as not claimed. Value is ignored.',
                descAr: 'يشترط أن يكون لدى العميل مطالبة نشطة غير منتهية أو مطالبة مستردة لهذا الكوبون. المطالبات المنتهية تعتبر غير موجودة. القيمة يتم تجاهلها.',
                valueType: 'none', valueRequired: false, valueExample: null,
                context: 'claim_state'),
            EligibilityRuleType::NOT_CLAIMED => self::base($rule,
                labelEn: 'Has not claimed',
                labelAr: 'لم يقم بالمطالبة',
                descEn: 'Requires the customer to hold no active unexpired claim and no redeemed claim for this coupon. Value is ignored.',
                descAr: 'يشترط ألا يكون لدى العميل مطالبة نشطة غير منتهية ولا مطالبة مستردة لهذا الكوبون. القيمة يتم تجاهلها.',
                valueType: 'none', valueRequired: false, valueExample: null,
                context: 'claim_state'),
            EligibilityRuleType::HAS_ASSIGNMENT => self::base($rule,
                labelEn: 'Has assignment',
                labelAr: 'لديه تخصيص',
                descEn: 'Requires the customer to hold a usable assignment for this coupon (exists, not expired, used below max_uses). Value is ignored.',
                descAr: 'يشترط أن يكون لدى العميل تخصيص صالح لهذا الكوبون (موجود وغير منتهٍ والاستخدام أقل من الحد). القيمة يتم تجاهلها.',
                valueType: 'none', valueRequired: false, valueExample: null,
                context: 'assignment_state'),
            EligibilityRuleType::AREA_IN => [
                ...self::base($rule,
                    labelEn: 'Delivery area in list',
                    labelAr: 'منطقة التوصيل ضمن القائمة',
                    descEn: 'Requires the checkout delivery governorate (governorates.id, active only) to be in the allowed list. Accepts a single id or a non-empty array of ids; strict positive integers only. Without checkout context the rule defers (passes, enforced at checkout); with context null/unknown/inactive areas fail.',
                    descAr: 'يشترط أن تكون محافظة التوصيل (governorates.id النشطة فقط) ضمن القائمة المسموحة. يقبل معرفاً واحداً أو مصفوفة غير فارغة من المعرفات الصحيحة الموجبة فقط. بدون سياق التوصيل يتم تأجيل التقييم (ينجح ويُفرض عند إتمام الطلب)؛ مع السياق تفشل القيم الفارغة أو غير المعروفة أو غير النشطة.',
                    valueType: 'area_list', valueRequired: true, valueExample: [1, 2],
                    context: 'checkout'),
                'evaluation' => [
                    'claim' => true,
                    'apply' => true,
                    'checkout' => true,
                    'fast_checkout' => true,
                    'defers_without_context' => true,
                ],
            ],
            EligibilityRuleType::HAS_EMAIL => self::base($rule,
                labelEn: 'Has email',
                labelAr: 'لديه بريد إلكتروني',
                descEn: 'Checks strict email presence (trimmed, RFC-valid; verification ignored). Value true or null requires an email; value false requires no email.',
                descAr: 'يتحقق من وجود بريد إلكتروني صالح (بدون مسافات وصالح وفق المعيار؛ حالة التحقق لا تؤثر). القيمة true أو null تشترط وجود بريد؛ والقيمة false تشترط عدم وجوده.',
                valueType: 'boolean_or_null', valueRequired: false, valueExample: true,
                allowedValues: [true, false, null], context: 'customer_profile'),
            EligibilityRuleType::REGISTERED_AFTER => self::date($rule,
                labelEn: 'Registered after',
                labelAr: 'مسجل بعد تاريخ',
                descEn: 'Requires the customer account (users.created_at) to be strictly after the specified UTC datetime.',
                descAr: 'يشترط أن يكون حساب العميل (users.created_at) بعد التاريخ المحدد بتوقيت UTC حصراً.',
                context: 'customer_profile'),
            EligibilityRuleType::REGISTERED_BEFORE => self::date($rule,
                labelEn: 'Registered before',
                labelAr: 'مسجل قبل تاريخ',
                descEn: 'Requires the customer account (users.created_at) to be strictly before the specified UTC datetime.',
                descAr: 'يشترط أن يكون حساب العميل (users.created_at) قبل التاريخ المحدد بتوقيت UTC حصراً.',
                context: 'customer_profile'),
        };
    }

    /**
     * @param mixed $valueExample
     * @param list<mixed>|null $allowedValues
     * @return array<string, mixed>
     */
    private static function base(
        EligibilityRuleType $rule,
        string $labelEn,
        string $labelAr,
        string $descEn,
        string $descAr,
        string $valueType,
        bool $valueRequired,
        mixed $valueExample,
        ?int $min = null,
        ?array $allowedValues = null,
        string $context = 'customer_history',
    ): array {
        return [
            'type' => $rule->value,
            'label' => ['en' => $labelEn, 'ar' => $labelAr],
            'description' => ['en' => $descEn, 'ar' => $descAr],
            'value_type' => $valueType,
            'value_required' => $valueRequired,
            'value_example' => $valueExample,
            'min' => $min,
            'max' => null,
            'allowed_values' => $allowedValues,
            'date_format' => null,
            'context' => $context,
            'evaluation' => [
                'claim' => true,
                'apply' => true,
                'checkout' => true,
                'fast_checkout' => true,
                'defers_without_context' => false,
            ],
        ];
    }

    private static function date(
        EligibilityRuleType $rule,
        string $labelEn,
        string $labelAr,
        string $descEn,
        string $descAr,
        string $context = 'customer_history',
    ): array {
        return [
            ...self::base($rule,
                labelEn: $labelEn, labelAr: $labelAr,
                descEn: $descEn, descAr: $descAr,
                valueType: 'datetime', valueRequired: true,
                valueExample: '2024-01-01', context: $context),
            'date_format' => 'parseable datetime string (Y-m-d accepted), UTC, exclusive boundary',
        ];
    }
}
