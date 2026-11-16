<?php

namespace Tests\Unit\Support;

use Marvel\Support\NoticeTranslator;
use Marvel\Traits\ApiResponse;
use Tests\TestCase;

/**
 * One notice-key translator with the fail-closed rule (round-2 ticket #50).
 *
 * A key resolves through the message catalogue; a KEY-LIKE input that
 * resolves to itself never reaches a client — it becomes the approved
 * generic message. Already-translated prose passes through unchanged so the
 * existing "apiResponse(__('...'))" call sites keep their localized text.
 */
class NoticeTranslatorTest extends TestCase
{
    private function translator(): NoticeTranslator
    {
        return app(NoticeTranslator::class);
    }

    public function test_known_catalogue_key_is_localized(): void
    {
        $this->assertNotSame(
            'message.ERROR.SOMETHING_WENT_WRONG',
            $this->translator()->translate('message.ERROR.SOMETHING_WENT_WRONG')
        );
        $this->assertStringNotContainsString('SOMETHING_WENT_WRONG', $this->translator()->translate('message.ERROR.SOMETHING_WENT_WRONG'));
    }

    public function test_unresolved_key_like_input_fails_closed(): void
    {
        $generic = __('message.ERROR.SOMETHING_WENT_WRONG');

        foreach (['ERROR.DEFINITELY_MISSING_TRAIT_XYZ', 'guest_checkout.no_such_key_here'] as $raw) {
            $out = $this->translator()->translate($raw);
            $this->assertSame($generic, $out, "Raw key must not leak: {$raw}");
            $this->assertStringNotContainsString($raw, $out);
        }
    }

    public function test_already_translated_prose_passes_through(): void
    {
        $sentence = 'This is already a localized human sentence.';

        $this->assertSame($sentence, $this->translator()->translate($sentence));
    }

    public function test_response_trait_delegates_and_fails_closed(): void
    {
        $probe = new class {
            use ApiResponse;

            public function render()
            {
                return $this->apiResponse('ERROR.DEFINITELY_MISSING_TRAIT_XYZ', 200, true, ['ok' => 1]);
            }
        };

        $response = $probe->render();
        $data = $response->getData(true);

        $this->assertSame(200, $data['status']);
        $this->assertSame(__('message.ERROR.SOMETHING_WENT_WRONG'), $data['message']);
        $this->assertStringNotContainsString('DEFINITELY_MISSING_TRAIT_XYZ', $data['message']);
        $this->assertSame(['ok' => 1], $data['data']);
    }

    public function test_response_trait_keeps_known_localized_keys(): void
    {
        $probe = new class {
            use ApiResponse;

            public function render()
            {
                return $this->apiResponse('guest_checkout.session_required', 401, false);
            }
        };

        $data = $probe->render()->getData(true);

        $this->assertSame(__('guest_checkout.session_required'), $data['message']);
        $this->assertNotSame('guest_checkout.session_required', $data['message']);
    }
}
