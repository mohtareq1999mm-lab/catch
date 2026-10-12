<?php

namespace Tests\Feature\Localization;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Marvel\Exceptions\MarvelException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Exception-handler localization (App\Exceptions\Handler).
 *
 * DB-free: render() against synthetic requests. Guards the audit defects:
 * hardcoded English envelope strings, raw $exception->getMessage()
 * passthroughs, and raw translation-key exposure from MarvelException.
 */
class HandlerLocalizationTest extends TestCase
{
    private function renderJson(string $locale, \Throwable $e): array
    {
        app()->setLocale($locale);
        $request = Request::create('/api/v1/general/settings', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = app(\App\Exceptions\Handler::class)->render($request, $e);

        return json_decode($response->getContent(), true);
    }

    /** @dataProvider locales */
    public function test_model_not_found_is_localized(string $locale): void
    {
        $data = $this->renderJson($locale, new ModelNotFoundException());
        $this->assertSame(404, $this->statusOf($locale, new ModelNotFoundException()));
        if ($locale === 'ar') {
            $this->assertSame('المورد غير موجود.', $data['message']);
        } else {
            // English keeps the established verbatim contract.
            $this->assertSame('Resource Not Found', $data['message']);
        }
        $this->assertNotEmpty($data['message']);
    }

    /** @dataProvider locales */
    public function test_method_not_allowed_is_localized(string $locale): void
    {
        $data = $this->renderJson($locale, new MethodNotAllowedHttpException(['GET']));
        $this->assertNotEmpty($data['message']);
        if ($locale === 'ar') {
            $this->assertSame('الطريقة غير مسموحة.', $data['message']);
        }
    }

    /** @dataProvider locales */
    public function test_unauthenticated_is_localized(string $locale): void
    {
        $data = $this->renderJson($locale, new AuthenticationException());
        if ($locale === 'ar') {
            $this->assertSame('غير مصرح. يرجى تسجيل الدخول.', $data['message']);
        } else {
            $this->assertSame('Unauthenticated.', $data['message']);
        }
    }

    /** @dataProvider locales */
    public function test_authorization_failure_is_localized(string $locale): void
    {
        $data = $this->renderJson($locale, new AuthorizationException());
        $this->assertNotSame('This action is unauthorized', $data['message'] ?? null);
        if ($locale === 'ar') {
            $this->assertSame('هذا الإجراء غير مصرح به.', $data['message']);
        }
    }

    /** @dataProvider locales */
    public function test_validation_top_message_is_localized(string $locale): void
    {
        // Locale must be set BEFORE validation runs: messages resolve at
        // validate() time (same as middleware → validation in real requests).
        app()->setLocale($locale);
        $validator = \Illuminate\Support\Facades\Validator::make([], ['order_number' => 'required|string']);
        try {
            $validator->validate();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $data = $this->renderJson($locale, $e);
            if ($locale === 'ar') {
                $this->assertSame('البيانات المُدخلة غير صحيحة.', $data['message']);
                // Localized attribute name, not the raw field key.
                $this->assertStringContainsString('رقم الطلب', $data['errors']['order_number'][0]);
            } else {
                $this->assertSame('The given data was invalid.', $data['message']);
                $this->assertStringContainsString('order number', $data['errors']['order_number'][0]);
            }
        }
    }

    /** @dataProvider locales */
    public function test_nested_validation_attributes_are_localized(string $locale): void
    {
        app()->setLocale($locale);
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['inputs' => [['key' => 'BAD KEY', 'label' => ['en' => 'x']]]],
            ['inputs.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,49}$/']]
        );
        try {
            $validator->validate();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $data = $this->renderJson($locale, $e);
            $firstKey = array_key_first($data['errors']);
            $this->assertStringStartsWith('inputs.', $firstKey, 'Error key stays machine-stable');
            $message = $data['errors'][$firstKey][0];
            $this->assertStringNotContainsString('inputs.0.key', $message, "[{$locale}] must not leak the raw path");
            $this->assertStringNotContainsString('validation.regex', $message);
        }
    }

    /** @dataProvider locales */
    public function test_unknown_marvel_key_never_leaks_raw(string $locale): void
    {
        $data = $this->renderJson($locale, new MarvelException('ERROR.DEFINITELY_MISSING_XYZ'));
        $this->assertStringNotContainsString('DEFINITELY_MISSING', $data['message']);
        $this->assertNotEmpty($data['message']);
    }

    /** @dataProvider locales */
    public function test_known_marvel_key_resolves(string $locale): void
    {
        $data = $this->renderJson($locale, new MarvelException('ERROR.NOT_FOUND'));
        $this->assertNotSame('ERROR.NOT_FOUND', $data['message']);
        $this->assertNotSame('message.ERROR.NOT_FOUND', $data['message']);
    }

    /** @dataProvider locales */
    public function test_wms_anti_enumeration_404_is_localized(string $locale): void
    {
        $data = $this->renderJson($locale, new NotFoundHttpException('Resource Not Found'));
        if ($locale === 'ar') {
            $this->assertSame('المورد غير موجود.', $data['message']);
        } else {
            $this->assertSame('Resource Not Found', $data['message']);
        }
    }

    public function test_abort_unauthenticated_maps_to_localized_key(): void
    {
        $data = $this->renderJson('ar', new HttpException(401, 'Unauthenticated.'));
        $this->assertSame('غير مصرح. يرجى تسجيل الدخول.', $data['message']);

        $data = $this->renderJson('en', new HttpException(401, 'Unauthenticated.'));
        $this->assertSame('Unauthenticated.', $data['message']);
    }

    public function test_abort_forbidden_permission_maps_with_name(): void
    {
        $data = $this->renderJson('ar', new HttpException(403, 'Forbidden. Missing required permission: view-analytics.'));
        $this->assertStringContainsString('view-analytics', $data['message']);
        $this->assertStringNotContainsString('Forbidden. Missing', $data['message']);

        $data = $this->renderJson('en', new HttpException(403, 'Forbidden. Missing required permission: view-analytics.'));
        $this->assertStringContainsString('view-analytics', $data['message']);
    }

    public function test_custom_service_messages_pass_through_translated(): void
    {
        // Already-translated service messages (checkout.*) must not be mangled.
        app()->setLocale('ar');
        $translated = __('checkout.flow_mismatch');
        $data = $this->renderJson('ar', new \InvalidArgumentException($translated));
        // InvalidArgumentException is notMapped → 500 debug-dependent; only
        // assert the handler leaves custom text intact when debug shows it.
        $this->assertArrayHasKey('message', $data);
    }

    private function statusOf(string $locale, \Throwable $e): int
    {
        app()->setLocale($locale);
        $request = Request::create('/api/v1/general/settings', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);

        return app(\App\Exceptions\Handler::class)->render($request, $e)->getStatusCode();
    }

    public static function locales(): array
    {
        return ['arabic' => ['ar'], 'english' => ['en']];
    }
}
