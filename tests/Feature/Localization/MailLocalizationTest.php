<?php

namespace Tests\Feature\Localization;

use Tests\TestCase;

/**
 * Customer mail + invoice PDF label localization (DB-free blade rendering).
 *
 * Covers the JSON phrase catalogue (resources/lang/ar.json) consumed by the
 * invoice PDF and the forget-password / digital-update mails, plus the
 * bilingual question-answered template (OTP-mail $locale precedent).
 */
class MailLocalizationTest extends TestCase
{
    /** @dataProvider jsonPhrases */
    public function test_json_phrases_resolve_in_arabic(string $phrase, array $replace = []): void
    {
        app()->setLocale('ar');
        $resolved = __($phrase, $replace);
        $this->assertNotSame($phrase, $resolved, "ar.json must translate [{$phrase}]");
        foreach ($replace as $key => $value) {
            $this->assertStringNotContainsString(':'.$key, $resolved);
        }
    }

    public static function jsonPhrases(): array
    {
        return [
            'invoice' => ['Invoice'],
            'total' => ['Total'],
            'unit price' => ['Unit Price'],
            'coupon discount' => ['Coupon Discount'],
            'generated on' => ['Generated on'],
            'password reset' => ['Password Reset Token'],
            'reset sentence' => ['Please copy the below token to reset your password.'],
            'thanks' => ['Thanks,'],
            'product update' => ['Product Update Notice'],
            'hello' => ['Hello :name,', ['name' => 'Layla']],
            'update sentence' => ['One new update is available of this product ":product". You can download the update from your user profile\'s download section.', ['product' => 'Ebook']],
            'view product' => ['View product'],
        ];
    }

    public function test_english_json_falls_back_to_source_phrase(): void
    {
        app()->setLocale('en');
        $this->assertSame('Invoice', __('Invoice'));
        $this->assertSame('Password Reset Token', __('Password Reset Token'));
    }

    private function renderMarkdown(string $view, array $data = []): string
    {
        // Same Markdown pipeline production Mailables use (it swaps in the
        // mail:: component namespace before rendering).
        return app(\Illuminate\Mail\Markdown::class)->render($view, $data)->toHtml();
    }

    public function test_forget_password_mail_renders_arabic(): void
    {
        app()->setLocale('ar');
        $html = $this->renderMarkdown('emails.forget-password', ['token' => '123456']);
        $this->assertStringContainsString('رمز إعادة تعيين كلمة المرور', $html);
        $this->assertStringContainsString('123456', $html);
        $this->assertStringNotContainsString('Password Reset Token', $html);
        $this->assertStringNotContainsString('Please copy the below token', $html);
    }

    public function test_forget_password_mail_renders_english(): void
    {
        app()->setLocale('en');
        $html = $this->renderMarkdown('emails.forget-password', ['token' => '123456']);
        $this->assertStringContainsString('Password Reset Token', $html);
    }

    public function test_question_answered_mail_arabic_branch(): void
    {
        $question = (object) ['question' => 'هل الشحن مجاني؟', 'answer' => 'نعم'];
        $product = (object) ['name' => 'هاتف'];
        $html = $this->renderMarkdown('emails.question.answered', [
            'question' => $question,
            'product' => $product,
            'url' => 'https://example.test/p/1',
            'locale' => 'ar',
        ]);
        $this->assertStringContainsString('عرض', $html);
        $this->assertStringContainsString('شكرًا', $html);
        $this->assertStringNotContainsString('View ', $html);
    }

    public function test_question_answered_mail_english_branch(): void
    {
        $question = (object) ['question' => 'Is shipping free?', 'answer' => 'Yes'];
        $product = (object) ['name' => 'Phone'];
        $html = $this->renderMarkdown('emails.question.answered', [
            'question' => $question,
            'product' => $product,
            'url' => 'https://example.test/p/1',
            'locale' => 'en',
        ]);
        $this->assertStringContainsString('View', $html);
        $this->assertStringContainsString('Thanks,', $html);
    }

    public function test_question_answered_defaults_to_english_without_locale(): void
    {
        $question = (object) ['question' => 'Q?', 'answer' => 'A'];
        $product = (object) ['name' => 'P'];
        $html = $this->renderMarkdown('emails.question.answered', [
            'question' => $question,
            'product' => $product,
            'url' => 'https://example.test/p/1',
        ]);
        $this->assertStringContainsString('Thanks,', $html);
    }
}
