<?php

namespace App\DTOs\Coupon;

class EligibilityResult
{
    public function __construct(
        public readonly bool $isEligible,
        public readonly array $passedRules,
        public readonly array $failedRules,
        public readonly array $evaluatedMetrics,
    ) {}

    public static function eligible(array $passedRules, array $evaluatedMetrics): self
    {
        return new self(
            isEligible: true,
            passedRules: $passedRules,
            failedRules: [],
            evaluatedMetrics: $evaluatedMetrics,
        );
    }

    public static function ineligible(array $passedRules, array $failedRules, array $evaluatedMetrics): self
    {
        return new self(
            isEligible: false,
            passedRules: $passedRules,
            failedRules: $failedRules,
            evaluatedMetrics: $evaluatedMetrics,
        );
    }
}
