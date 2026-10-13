<?php

namespace Tests\Feature\Localization;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Nested wildcard attribute localization (DB-free).
 *
 * Regression for D14: Marvel AttributeController@store/update (admin
 * `attributes` endpoints in the supplied Marvel routes) validate
 * `values.*.value`, `values.*.value.en`, `values.*.value.ar` without a
 * FormRequest attributes() override. (AttributeRequest carries the same rules
 * but is unwired — only Create/Update are reachable; the catalogue fix covers
 * all three by key shape.) The catalogue must supply matching
 * wildcard entries or failures render raw paths (`values.0.value.en`).
 *
 * Asserts real Validator output in both locales: localized attribute
 * names, no raw translation keys, stable machine field names.
 */
class NestedAttributeLocalizationTest extends TestCase
{
    /** @dataProvider locales */
    public function test_attribute_values_wildcards_are_localized(string $locale): void
    {
        app()->setLocale($locale);

        $validator = Validator::make(
            ['values' => [['value' => []]]],
            [
                'values.*.value' => ['required', 'array'],
                'values.*.value.en' => ['required', 'string', 'min:2', 'max:50'],
                'values.*.value.ar' => ['required', 'string', 'min:2', 'max:50'],
            ]
        );

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();

        $this->assertArrayHasKey('values.0.value.en', $errors);
        $this->assertArrayHasKey('values.0.value.ar', $errors);

        if ($locale === 'ar') {
            $this->assertStringContainsString('قيمة الخاصية بالإنجليزية', $errors['values.0.value.en'][0]);
            $this->assertStringContainsString('قيمة الخاصية بالعربية', $errors['values.0.value.ar'][0]);
        } else {
            $this->assertStringContainsString('attribute value (English)', $errors['values.0.value.en'][0]);
            $this->assertStringContainsString('attribute value (Arabic)', $errors['values.0.value.ar'][0]);
        }

        $json = json_encode($errors, JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression(
            '/"(validation|message|checkout|tracking|coupon|wms|auth)\.[A-Za-z_.]+"/',
            $json,
            "Raw translation key leaked [{$locale}]: {$json}"
        );
        // Raw dotted paths must not survive as display names either.
        $this->assertStringNotContainsString('values.0.value', $errors['values.0.value.en'][0]);
    }

    /** @dataProvider locales */
    public function test_flow_input_bulk_wildcards_stay_localized(string $locale): void
    {
        app()->setLocale($locale);

        $validator = Validator::make(
            ['inputs' => [['label' => ['en' => 'x']]]],
            [
                'inputs.*.key' => ['required', 'string'],
                'inputs.*.type' => ['required', 'string'],
            ]
        );

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();

        $this->assertArrayHasKey('inputs.0.key', $errors);
        $this->assertArrayHasKey('inputs.0.type', $errors);

        if ($locale === 'ar') {
            $this->assertStringContainsString('مفتاح المدخل', $errors['inputs.0.key'][0]);
            $this->assertStringContainsString('نوع المدخل', $errors['inputs.0.type'][0]);
        } else {
            $this->assertStringContainsString('input key', $errors['inputs.0.key'][0]);
            $this->assertStringContainsString('input type', $errors['inputs.0.type'][0]);
        }
    }

    /** @dataProvider locales */
    public function test_attribute_value_parent_key_is_localized(string $locale): void
    {
        app()->setLocale($locale);

        // `value` present but not an array → the `values.*.value` (array)
        // rule itself fails, exercising the parent wildcard key.
        $validator = Validator::make(
            ['values' => [['value' => 'not-an-array']]],
            ['values.*.value' => ['required', 'array']]
        );

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();

        $this->assertArrayHasKey('values.0.value', $errors);

        if ($locale === 'ar') {
            $this->assertStringContainsString('قيمة الخاصية', $errors['values.0.value'][0]);
        } else {
            $this->assertStringContainsString('attribute value', $errors['values.0.value'][0]);
        }
        $this->assertStringNotContainsString('values.0.value', $errors['values.0.value'][0]);
    }

    public static function locales(): array
    {
        return ['arabic' => ['ar'], 'english' => ['en']];
    }
}
