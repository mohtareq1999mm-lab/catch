<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Faqs;

class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $faqs = [
            [
                'en' => 'How do I place an order?',
                'ar' => 'كيف أطلب منتج؟',
                'desc_en' => 'Browse our catalog, add items to your cart, and proceed to checkout. You can pay online or choose cash on delivery.',
                'desc_ar' => 'تصفح الكتالوج وأضف المنتجات إلى سلة التسوق ثم تابع إلى الدفع. يمكنك الدفع عبر الإنترنت أو اختيار الدفع عند الاستلام.',
            ],
            [
                'en' => 'What payment methods do you accept?',
                'ar' => 'ما هي طرق الدفع المتاحة؟',
                'desc_en' => 'We accept credit/debit cards, cash on delivery, and payment via MyFatoorah gateway.',
                'desc_ar' => 'نقبل بطاقات الائتمان والخصم والدفع عند الاستلام والدفع عبر بوابة ماي فاتورة.',
            ],
            [
                'en' => 'How long does delivery take?',
                'ar' => 'كم تستغرق عملية التوصيل؟',
                'desc_en' => 'Standard delivery takes 2-5 business days depending on your governorate. Fast shipping is available in select areas within 24 hours.',
                'desc_ar' => 'التوصيل العادي يستغرق 2-5 أيام عمل حسب المحافظة. الشحن السريع متاح في مناطق محددة خلال 24 ساعة.',
            ],
            [
                'en' => 'What is your return policy?',
                'ar' => 'ما هي سياسة الإرجاع؟',
                'desc_en' => 'You can return unused products within 14 days of delivery. Contact our support team to initiate the return process.',
                'desc_ar' => 'يمكنك إرجاع المنتجات غير المستخدمة خلال 14 يوم من التوصيل. تواصل مع فريق الدعم لبدء عملية الإرجاع.',
            ],
            [
                'en' => 'How can I track my order?',
                'ar' => 'كيف أتتبع طلبي؟',
                'desc_en' => 'Once your order is shipped, you will receive a tracking number via email and SMS. You can also track it from your account dashboard.',
                'desc_ar' => 'بمجرد شحن طلبك، ستتلقى رقم تتبع عبر البريد الإلكتروني والرسائل النصية. يمكنك أيضاً التتبع من لوحة التحكم.',
            ],
            [
                'en' => 'Do you offer free shipping?',
                'ar' => 'هل توفرون شحن مجاني؟',
                'desc_en' => 'Yes, we offer free shipping on orders above a certain amount. The threshold varies by governorate.',
                'desc_ar' => 'نعم، نوفر شحن مجاني للطلبات فوق مبلغ معين. يختلف الحد الأدنى حسب المحافظة.',
            ],
            [
                'en' => 'Can I cancel my order?',
                'ar' => 'هل يمكنني إلغاء طلبي؟',
                'desc_en' => 'Orders can be cancelled within 24 hours of placement. Once shipped, you would need to request a return.',
                'desc_ar' => 'يمكن إلغاء الطلبات خلال 24 ساعة من تقديمها. بعد الشحن، يمكنك طلب الإرجاع بدلاً من ذلك.',
            ],
            [
                'en' => 'How do I use a coupon code?',
                'ar' => 'كيف أستخدم كود الخصم؟',
                'desc_en' => 'Enter your coupon code in the designated field at checkout. The discount will be applied automatically.',
                'desc_ar' => 'أدخل كود الخصم في الحقل المخصص عند الدفع. سيتم تطبيق الخصم تلقائياً على إجمالي الطلب.',
            ],
            [
                'en' => 'What if I received a damaged item?',
                'ar' => 'ماذا أفعل إذا استلمت منتج تالف؟',
                'desc_en' => 'Contact our support team within 48 hours of delivery with photos. We will arrange a replacement or refund.',
                'desc_ar' => 'تواصل مع فريق الدعم خلال 48 ساعة من التوصيل مع صور للمنتج. سنقوم بترتيب البديل أو الاسترداد.',
            ],
            [
                'en' => 'Do you ship outside Egypt?',
                'ar' => 'هل تشحنون خارج مصر؟',
                'desc_en' => 'Currently, we only deliver within Egypt. We are expanding to other countries soon.',
                'desc_ar' => 'حالياً، نوصل فقط داخل مصر. نحن نعمل على التوسع إلى دول أخرى قريباً.',
            ],
            [
                'en' => 'How can I contact customer support?',
                'ar' => 'كيف يمكنني التواصل مع دعم العملاء؟',
                'desc_en' => 'Via the contact form on our website, email at support@example.com, or by phone during business hours.',
                'desc_ar' => 'عبر نموذج الاتصال في الموقع، أو البريد الإلكتروني support@example.com، أو عبر الهاتف خلال ساعات العمل.',
            ],
            [
                'en' => 'Are my payment details secure?',
                'ar' => 'هل معلومات الدفع آمنة؟',
                'desc_en' => 'Yes, all transactions are encrypted and processed through secure payment gateways. We do not store card details.',
                'desc_ar' => 'نعم، جميع المعاملات مشفرة ويتم معالجتها عبر بوابات دفع آمنة. لا نقوم بتخزين معلومات بطاقتك.',
            ],
            [
                'en' => 'Can I change my delivery address?',
                'ar' => 'هل يمكنني تغيير عنوان التوصيل؟',
                'desc_en' => 'Address changes are possible within 2 hours of placing the order. Contact support immediately.',
                'desc_ar' => 'تغيير العنوان ممكن خلال ساعتين من تقديم الطلب. يرجى الاتصال بالدعم فوراً.',
            ],
            [
                'en' => 'How do I create an account?',
                'ar' => 'كيف أقوم بإنشاء حساب؟',
                'desc_en' => 'Click the profile icon and select Sign Up. Enter your name, email, and password to create your account.',
                'desc_ar' => 'اضغط على أيقونة الملف الشخصي واختر تسجيل. أدخل اسمك وبريدك الإلكتروني وكلمة المرور.',
            ],
            [
                'en' => 'What is the warranty on products?',
                'ar' => 'ما هو الضمان على المنتجات؟',
                'desc_en' => 'Warranty periods vary by product and brand. Each product page displays warranty information.',
                'desc_ar' => 'فترات الضمان تختلف حسب المنتج والعلامة التجارية. كل صفحة منتج تعرض معلومات الضمان.',
            ],
        ];

        foreach ($faqs as $faq) {
            Faqs::create([
                'faq_title' => [
                    'en' => $faq['en'],
                    'ar' => $faq['ar'],
                ],
                'faq_description' => [
                    'en' => $faq['desc_en'],
                    'ar' => $faq['desc_ar'],
                ],
            ]);
        }
    }
}