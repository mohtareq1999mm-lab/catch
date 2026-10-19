# توثيق معمارية ونظام إشعارات الـ FCM (Firebase Cloud Messaging)

يوثق هذا الملف النظام المتكامل لإشعارات الـ Push Notifications عبر Firebase (FCM) في المشروع، موضحاً المكونات البرمجية المستخدمة، تدفق البيانات من الباك إند إلى تطبيق الموبايل، وكيفية إرسال الإشعارات تلقائياً، والتعامل مع رموز الأجهزة غير الصالحة.

---

## 1. المكتبات والأدوات المستخدمة (Tech Stack & Dependencies)

* **المكتبة الأساسية:** `kreait/firebase-php` (Official PHP SDK for Firebase).
* **إدارة الحسابات المتعددة (Multi-Client):** يدعم النظام مشروعي Firebase مختلفين (`client_a` و `client_b`) لخدمة أكثر من تطبيق موبايل (مثلاً: تطبيق العملاء وتطبيق المناديب/المتاجر).
* **نظام الطوابير (Queues):** إرسال الإشعارات يتم بشكل غير متزامن (Asynchronous) عبر `SendFcmNotificationJob` على الـ High Priority Queue لضمان عدم تعطيل استجابة الـ API وسرعة الوصول.
* **قنوات Laravel Notifications:** تم تسجيل قناة مخصصة باسم `'fcm'` داخل `ChannelManager` لربط إشعارات Laravel القياسية بـ Firebase مباشرة.

---

## 2. هيكل الملفات والمكونات في الكود (File Structure & Components)

```
app/
├── Http/Controllers/Api/General/
│   └── DeviceTokenController.php       # تسجيل وحذف توكن FCM للأجهزة
├── Jobs/
│   └── SendFcmNotificationJob.php     # وظيفة الإرسال في الخلفية (Queue Job) ومعالجة التوكنز التالفة
├── Models/
│   └── DeviceToken.php                 # موديل جدول الأجهزة وتوكنز FCM
├── Notifications/
│   ├── Channels/
│   │   └── FcmChannel.php              # القناة المخصصة ('fcm') لنظام Laravel Notifications
│   └── User*Notification.php           # أكثر من 25 إشعار يرسل للموبايل تلقائياً
├── Providers/
│   └── AppServiceProvider.php          # تسجيل القناة 'fcm' في ChannelManager
└── Services/Firebase/
    ├── FcmService.php                  # الاتصال بـ Kreait Firebase وإرسال Multicast
    └── FirebaseProjectResolver.php     # تحديد ملف شهادات الاعتماد (Credentials) لكل عميل
config/
└── firebase.php                        # إعدادات العملاء وتحديد مسارات الشهادات
```

---

## 3. رحلة الإشعار خطوة بخطوة من البداية حتى وصوله للموبايل (End-to-End Lifecycle)

```
[1. تطبيق الموبايل]
       │
       │ أ) تسجيل الدخول (POST /api/v1/token) ──► الحصول على Sanctum Token
       │ ب) الحصول على FCM Token من Firebase SDK بالموبايل
       │ ج) إرسال FCM Token إلى الباك إند:
       │    POST /api/v1/general/device-tokens { token, client, platform }
       ▼
[2. DeviceTokenController@store]
       │
       │ حفظ/تحديث التوكن وربطه بـ user_id في جدول `device_tokens`
       ▼
[3. حدث في النظام (Business Event)]
       │ (مثال: طلب جديد، تغيير حالة الطلب، نجاح الدفع، عروض Flash Sale)
       │
       ▼
[4. تشغيل الإشعار ($user->notify(new UserOrderCreatedNotification($order)))]
       │
       │ الإشعار يحدد عبر via(): ['database', 'fcm', 'broadcast']
       ▼
[5. قناة FcmChannel]
       │
       │ أ) استخراج العنوان (Title) والرسالة (Body) وترجمة النص بناءً على لغة التطبيق (ar/en)
       │ ب) إرفاق البيانات الإضافية (order_id, type, etc.)
       │ ج) إطلاق SendFcmNotificationJob مع تمرير user_id المستهدف
       ▼
[6. وظيفة الطابور SendFcmNotificationJob]
       │
       │ أ) جلب التوكنز الخاصة بهذا المستخدم فقط من جدول `device_tokens` (عزل أمني تام)
       │ ب) تجميع التوكنز بحسب الـ client (مثلاً client_a)
       │ ج) استدعاء FcmService::sendToClient
       ▼
[7. FcmService و Kreait Firebase SDK]
       │
       │ أ) قراءة ملف الـ Service Account JSON المناسب من storage/app/firebase/
       │ ب) بناء رسالة Firebase مع Highest Possible Priority
       │ ج) إرسال البث المتعدد (sendMulticast) إلى خوادم Google FCM
       ▼
[8. خوادم Google FCM] ──► وصول الإشعار الفوري لشاشة الموبايل (Push Notification)
       │
       ▼ (في حال إرجاع أجهزة غير صالحة)
[9. التنظيف التلقائي للتوكنز التالفة]
       │
       │ في حال قام المستخدم بحذف التطبيق أو انتهاء صلاحية التوكن (NotFound Error):
       │ تقوم SendFcmNotificationJob بحذف التوكن فوراً من جدول `device_tokens`.
```

---

## 4. تفاصيل المكونات التقنية

### أ. تسجيل أجهزة الموبايل (`DeviceTokenController`)
* **Endpoint:** `POST /api/v1/general/device-tokens`
* **الموديل:** `App\Models\DeviceToken`
* **الجدول:** `device_tokens` (الحقول: `uuid`, `user_id`, `token`, `client`, `platform`, `last_used_at`).
* **الميزة:** التوكن عالمي وفريد (`unique`)؛ إذا سجل مستخدم آخر دخوله من نفس الجهاز، يتم نقله تلقائياً للمستخدم الجديد وتحديث وقت الاستخدام.

### ب. قناة الإشعار المخصصة (`FcmChannel`)
* مسجلة في `AppServiceProvider`:
  ```php
  $this->app->make(ChannelManager::class)->extend('fcm', function () {
      return new FcmChannel();
  });
  ```
* تدعم الـ Multi-language تلقائياً: إذا كان محتوى الإشعار مصفوفة `['ar' => '...', 'en' => '...']` تقوم القناة باختيار النص المناسب للغة التطبيق الحالية قبل الإرسال.

### ج. وظيفة الإرسال في الخلفية (`SendFcmNotificationJob`)
* تعمل على طابور عالي الأولوية: `frontend.queue` أو `queue.queues.high`.
* **الحماية الأمنية (Security Isolation):** ترفض الإرسال إذا لم يتم تحديد `notifiableUserId` لمنع بث الإشعارات لجميع أجهزة النظام بالخطأ.
* **معالجة الدفعات (Chunking):** تقوم بتقسيم التوكنز إلى دفعات (500 توكن لكل دفعة) لتفادي استهلاك الذاكرة.

### د. خدمة Firebase (`FcmService`)
* تقوم بتحميل شهادة كل عميل (`client_a` أو `client_b`) كـ Singleton في الذاكرة.
* تستخدم `sendMulticast` لإرسال الإشعار لجميع أجهزة المستخدم في طلب شبكي واحد.
* ترصد استجابات Firebase وتلتقط أخطاء `NotFound` و `not a valid FCM registration token` وتعيد قائمة بالتوكنز التالفة ليتم حذفها.

---

## 5. الإشعارات المربوطة بـ FCM في النظام

جميع هذه الإشعارات تقوم بالإرسال للموبايل تلقائياً عبر `via(): ['database', 'fcm', 'broadcast']`:

| فئة الإشعار | كلاس الإشعار (Notification Class) | الحدث المشغّل |
| :--- | :--- | :--- |
| **الطلبات (Orders)** | `UserOrderCreatedNotification` | إنشاء طلب جديد بنجاح |
| | `UserOrderDeliveredNotification` | تم تسليم الطلب للعميل |
| | `UserOrderCancelledNotification` | إلغاء الطلب |
| | `UserOrderRefundedNotification` | استرداد مالي للطلب |
| | `NewOrderNotification` | تنبيه الإدارة بطلب جديد |
| **المدفوعات (Payments)** | `UserPaymentSucceededNotification` | نجاح عملية الدفع الإلكتروني |
| | `UserPaymentFailedNotification` | فشل عملية الدفع |
| **الكوبونات والعروض** | `UserCouponAssignedNotification` | إهداء أو تخصيص كوبون للمستخدم |
| | `UserCouponAvailableNotification` | توفر كوبون عام جديد |
| | `UserCouponUsedNotification` | استخدام الكوبون في طلب |
| | `UserPromotionAvailableNotification` | إطلاق حملة ترويجية |
| | `UserPromotionEndingSoonNotification` | تنبيه بقرب انتهاء العرض |
| **المنتجات والمخزون** | `UserProductBackInStockNotification` | توفر منتج من قائمة الرغبات بالمخزون |
| | `UserProductPriceDropNotification` | انخفاض سعر منتج يتابعه العميل |
| | `UserProductDiscountChangedNotification` | تعديل نسبة الخصم على منتج |
| **عروض Flash Sale** | `UserFlashSaleAvailableNotification` | بدء تخفيضات الفلاش سيل |
| | `UserFlashSaleEndingSoonNotification` | تنبيه بقرب نهاية الفلاش سيل |
| **السلة والتقييمات** | `UserAbandonedCartNotification` | تذكير بالسلة المتروكة |
| | `UserReviewApprovedNotification` | موافقة الإدارة على مراجعة العميل |
| | `UserReviewRejectedNotification` | رفض مراجعة العميل |
| **المنتجات الرقمية** | `UserDigitalProductsAvailableNotification` | جاهزية الملفات الرقمية للتحميل |
| **الأمان والحسابات** | `AdminLoggedInNotification` | إشعار بتسجيل دخول مسؤول جديد |

---

## 6. إعدادات المتغيرات البيئية (`.env` Configuration)

لكي يعمل الإرسال الفعلي لـ Firebase، يجب ضبط المتغيرات التالية في ملف `.env`:

```dotenv
# إعدادات العميل A (مثال: تطبيق العملاء)
FIREBASE_CLIENT_A_PROJECT_ID=your-firebase-project-id
FIREBASE_CLIENT_A_CREDENTIALS=client_a_service_account.json

# إعدادات العميل B (مثال: تطبيق الإدارة أو المناديب - إن وُجد)
FIREBASE_CLIENT_B_PROJECT_ID=your-second-firebase-project-id
FIREBASE_CLIENT_B_CREDENTIALS=client_b_service_account.json
```

**مكان وضع ملفات الشهادات:**
* يتم وضع ملف الـ JSON الصادر من Firebase Console داخل المجلد:
  `storage/app/firebase/client_a_service_account.json`
