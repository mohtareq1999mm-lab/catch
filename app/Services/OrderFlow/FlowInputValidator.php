<?php

namespace App\Services\OrderFlow;

use App\Exceptions\FlowInputValidationException;
use App\Models\OrderFlow\FlowInput;
use App\Models\OrderFlow\OrderFlow;
use Illuminate\Support\Collection;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\PickupLocation;

/**
 * Domain validator for Dynamic Flow Inputs.
 *
 * Pure service (no HTTP, no auth): loads the flow's ACTIVE input
 * definitions, allow-lists submitted keys, and validates required /
 * type / source / custom rules for one context ('checkout' or
 * 'transition:<status_code>'). Fail-closed: unknown keys, missing
 * required inputs, wrong types, and inactive/unknown source records
 * all throw FlowInputValidationException (mapped to HTTP 422).
 *
 * Authorization note: source-record visibility is enforced here
 * (active countries/governorates/warehouses/pickup locations only).
 * Row-level shop scoping, if ever needed, plugs into sourceAllowed().
 */
class FlowInputValidator
{
    /**
     * Validate submitted values for a flow + context.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> validated (normalized) values, keyed by input key
     *
     * @throws FlowInputValidationException
     */
    public function validate(OrderFlow $flow, array $values, string $context): array
    {
        // All definitions (active + inactive) keyed by input key.
        // Inactive inputs are IGNORED (retired contract: values neither
        // validated nor persisted) so cached frontend schemas keep working
        // after an admin deactivates an input. Truly UNKNOWN keys fail.
        /** @var Collection<int, FlowInput> $definitions */
        $definitions = $flow->inputs()->ordered()->get()->keyBy('key');

        $errors = [];
        $validated = [];

        foreach ($values as $key => $value) {
            if (!$definitions->has($key)) {
                $errors[$key][] = __('checkout.flow_input_unknown', ['key' => $key]);

                continue;
            }

            $input = $definitions->get($key);

            if (!$input->is_active) {
                continue;
            }

            $fieldErrors = $this->validateValue($input, $value);

            if (!empty($fieldErrors)) {
                $errors[$key] = $fieldErrors;

                continue;
            }

            $validated[$key] = $this->normalizeValue($input, $value);
        }

        foreach ($definitions as $key => $input) {
            if (!$input->isRequiredInContext($context)) {
                continue;
            }

            if (!array_key_exists($key, $values) || $this->isEmpty($values[$key])) {
                $errors[$key][] = __('checkout.flow_input_required', ['key' => $key]);
            }
        }

        if (!empty($errors)) {
            throw new FlowInputValidationException(
                __('checkout.flow_input_invalid'),
                $errors,
            );
        }

        return $validated;
    }

    /**
     * @return array<int, string>
     */
    private function validateValue(FlowInput $input, mixed $value): array
    {
        if ($this->isEmpty($value)) {
            return [];
        }

        $typeErrors = match ($input->type) {
            FlowInput::TYPE_TEXT => is_string($value) || is_numeric($value)
                ? [] : [__('checkout.flow_input_type_text', ['key' => $input->key])],
            FlowInput::TYPE_NUMBER => is_numeric($value)
                ? [] : [__('checkout.flow_input_type_number', ['key' => $input->key])],
            FlowInput::TYPE_BOOLEAN => is_bool($value) || in_array($value, [0, 1, '0', '1'], true)
                ? [] : [__('checkout.flow_input_type_boolean', ['key' => $input->key])],
            FlowInput::TYPE_DATE => $this->isParsableDate($value)
                ? [] : [__('checkout.flow_input_type_date', ['key' => $input->key])],
            FlowInput::TYPE_SELECT => is_scalar($value) || $value === null
                ? [] : [__('checkout.flow_input_type_select', ['key' => $input->key])],
            FlowInput::TYPE_MULTI_SELECT => is_array($value)
                ? [] : [__('checkout.flow_input_type_multi_select', ['key' => $input->key])],
            default => [__('checkout.flow_input_type_unknown', ['key' => $input->key])],
        };

        if (!empty($typeErrors)) {
            return $typeErrors;
        }

        $errors = [];

        if ($input->source) {
            $sourceErrors = $this->validateSource($input, $value);
            $errors = array_merge($errors, $sourceErrors);
        }

        $rules = $input->validation ?? [];

        if (in_array($input->type, [FlowInput::TYPE_TEXT, FlowInput::TYPE_SELECT], true) && !$input->source) {
            if (isset($rules['options']) && is_array($rules['options'])) {
                $candidates = $input->type === FlowInput::TYPE_MULTI_SELECT ? (array) $value : [$value];
                foreach ($candidates as $candidate) {
                    if (!in_array($candidate, $rules['options'], false)) {
                        $errors[] = __('checkout.flow_input_option_invalid', ['key' => $input->key]);
                        break;
                    }
                }
            }
        }

        if ($input->type === FlowInput::TYPE_TEXT && is_string($value)) {
            if (isset($rules['min']) && mb_strlen($value) < (int) $rules['min']) {
                $errors[] = __('checkout.flow_input_min', ['key' => $input->key, 'min' => $rules['min']]);
            }
            if (isset($rules['max']) && mb_strlen($value) > (int) $rules['max']) {
                $errors[] = __('checkout.flow_input_max', ['key' => $input->key, 'max' => $rules['max']]);
            }
            if (isset($rules['pattern']) && is_string($rules['pattern']) && @preg_match($rules['pattern'], '') !== false) {
                if (!preg_match($rules['pattern'], $value)) {
                    $errors[] = __('checkout.flow_input_pattern', ['key' => $input->key]);
                }
            }
        }

        if ($input->type === FlowInput::TYPE_NUMBER && is_numeric($value)) {
            if (isset($rules['min']) && (float) $value < (float) $rules['min']) {
                $errors[] = __('checkout.flow_input_min_value', ['key' => $input->key, 'min' => $rules['min']]);
            }
            if (isset($rules['max']) && (float) $value > (float) $rules['max']) {
                $errors[] = __('checkout.flow_input_max_value', ['key' => $input->key, 'max' => $rules['max']]);
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function validateSource(FlowInput $input, mixed $value): array
    {
        $candidates = $input->type === FlowInput::TYPE_MULTI_SELECT ? array_values((array) $value) : [$value];

        foreach ($candidates as $candidate) {
            if (!$this->sourceAllowed($input->source, $candidate)) {
                return [__('checkout.flow_input_source_invalid', ['key' => $input->key])];
            }
        }

        return [];
    }

    private function sourceAllowed(string $source, mixed $candidate): bool
    {
        if (!is_numeric($candidate)) {
            return false;
        }

        $id = (int) $candidate;

        return match ($source) {
            FlowInput::SOURCE_COUNTRIES => Country::query()->whereKey($id)->where('status', true)->exists(),
            FlowInput::SOURCE_GOVERNORATES => Governorate::query()->whereKey($id)->where('status', true)->exists(),
            FlowInput::SOURCE_WAREHOUSES => \App\Models\Fulfillment\Warehouse::query()->whereKey($id)->where('status', 'active')->exists(),
            FlowInput::SOURCE_PICKUP_LOCATIONS => PickupLocation::query()->whereKey($id)->where('status', true)->exists(),
            default => false,
        };
    }

    private function normalizeValue(FlowInput $input, mixed $value): mixed
    {
        return match ($input->type) {
            FlowInput::TYPE_NUMBER => $value + 0,
            FlowInput::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            FlowInput::TYPE_MULTI_SELECT => array_values((array) $value),
            FlowInput::TYPE_SELECT => is_numeric($value) && $input->source ? (int) $value : $value,
            default => $value,
        };
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function isParsableDate(mixed $value): bool
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        try {
            return (bool) date_create((string) $value);
        } catch (\Throwable) {
            return false;
        }
    }
}
