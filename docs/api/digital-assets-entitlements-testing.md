# اختبار Digital Assets و Digital Entitlements محليًا (Postman + API)

> دليل عملي بالعربي البسيط. كل الأمثلة متحقق منها ضد الكود. المصطلحات التقنية والأسماء بالإنجليزي زي ما هي في الكود.

## 1. إيه اللي هنختبره؟

12 Endpoint للأدمن (7 أصول + 5 استحقاقات) + 4 endpoints للعميل (المكتبة/التحميل/الكشف/الريدايركت). التفاصيل الكاملة لكل Endpoint في `docs/api/` (ملفات `*digital*`).

## 2. التشغيل المحلي

```bash
# 1) شغّل MySQL محليًا ( Porto 3306, قاعدة catch حسب .env )
php artisan db:show        # لازم تشوف Database: catch على 127.0.0.1

# 2) كمّل أي migrations ناقصة (آمن: إضافات فقط، على local فقط)
php artisan migrate --force

# 3) شغّل السيرفر
php artisan serve          # http://localhost:8000

# 4) شغّل الـ workers (مهم! التفاصيل في قسم 9)
php artisan queue:work database --queue=high --tries=5 --timeout=90
php artisan queue:work database --queue=medium --tries=3 --timeout=60
```

> ملاحظة: أسماء الـ queues (`high`/`medium`) هي القيم الافتراضية. لو السيرفر عنده `QUEUE_HIGH`/`QUEUE_MEDIUM` مختلفة، استخدم القيم الفعلية من `php artisan tinker --execute="echo config('queue.queues.high').' / '.config('queue.queues.medium');"` — الكود بيقرأ `config('queue.queues.*')` دائمًا عن طريق `QueueName::high()/medium()`، فالـ worker لازم يسمع نفس الاسم المطبوع.

## 3. زرع البيانات (Seed)

```bash
php artisan digital:testing-seed --confirm-local
```

الأمر بيطبع البيئة والداتابيز الأول، ويرفض الإنتاج تمامًا، ويحتاج `--confirm-local` صريح. التفاصيل: `docs/testing/digital-assets-entitlements-seeding.md`.

الناتج: جدول `key → value` فيه كل الـ UUIDs (انسخها لبيئة Postman)، وحساب الأدمن `digital.admin@example.test`.

## 4. الدخول (Auth)

```
POST {{base_url}}/api/v1/admin-login
Content-Type: application/json

{ "email": "digital.admin@example.test", "password": "Test1234!LocalOnly" }
```

الرد `200 {status, message, success, data: {token, permissions, email_verified, role, expires_at}}`. خد `data.token` وحطه `Bearer` في كل طلبات الأدمن. (للعميل: `POST /api/v1/token` بنفس الشكل بحساب `digital.customer@example.test`.)

## 5. استيراد Postman

1. افتح Postman → Import → اختار `postman/Digital-Assets-Entitlements.postman_collection.json` (هتستورد valid v2.1: 8 folders / 34 requests).
2. Import ملف البيئة `postman/Digital-Assets-Entitlements.local.postman_environment.json` واختارها Active.
3. نفّذ `0 - Auth and Environment / Admin Login` الأول (بيحفظ `token` تلقائيًا).
4. انسخ الـ UUIDs من ناتج الـ seeder لمتغيرات البيئة (أو نفّذ `List Entitlements` اللي بيلقط أول uuid لوحده).
5. امشي بالترتيب: `1 → 2 → 3 → 4 → 5 → 6 → 7`. ملفات الـ PDF: علّم على حقل `file` واختار PDF حقيقي من جهازك (البايت بتتفحص — ملف نص متسمي pdf هيترفض!).

## 6. الصلاحيات المطلوبة لكل Endpoint (مؤكدة من الكود بعد الإصلاح)

| Endpoint | الصلاحية |
|---|---|
| `GET products/{id}/digital-assets` | `view-products` |
| `POST products/{id}/digital-assets` | `create-product` |
| `GET digital-assets/{uuid}` | `view-products` |
| `PUT digital-assets/{uuid}` | `update-product` |
| `DELETE digital-assets/{uuid}` | `delete-product` (route + constructor — كانت تطلب `update-product` زيادة قبل الإصلاح) |
| `POST digital-assets/{uuid}/replace` | `update-product` |
| `POST digital-assets/{uuid}/license-keys` | `manage-digital-licenses` فقط (كانت تطلب `update-product` زيادة قبل الإصلاح — التست `test_admin_bulk_import_...` يثبت النية) |
| `GET digital-entitlements` + `GET .../{uuid}` | `manage-digital-access` **و** `view-orders` معًا |
| `PATCH .../limit`, `POST .../revoke`, `POST .../restore` | `manage-digital-access` |

حساب التجربة السلبية: `digital.noperm@example.test` (أدمن من غير أي صلاحية → 403 مؤكد).

## 7. أمثلة سريعة (مؤكدة)

**إنشاء أصل ملف (multipart):** حقول `file` (PDF حقيقي) + `type=FILE` + `original_name` → `201` بدون `path`/`secret` في الرد.

**تزويد مفاتيح:** `POST .../license-keys` body `{"keys": ["TEST-ONLY-A", "TEST-ONLY-B"]}` → `201 {asset_uuid, created: 2, skipped_blank: 0, skipped_duplicate: 0}` والنص الصريح مش موجود في الرد. التكرار داخل نفس الطلب → `422 errors.keys.1`. إعادة نفس الطلب → `201 created: 0, skipped_duplicate: 2` (idempotent بالمحتوى).

**الحد:** `PATCH .../limit` body `{"limit": 10}` → `{previous_limit, download_limit: 10, unlimited: false}`. body `{}` → `{download_limit: 0, unlimited: true}` (غير محدود — مش صفر تحميلات!).

**الإلغاء/الاسترجاع:** `POST .../revoke` → `status: revoked` (التكرار 200 برضه). `POST .../restore` → `status: delivered, revoked_at: null`. ردّ الاتنين **من غير** `user`/`product` (عكس الـ show — مقصود وموثق).

## 8. أخطاء متوقعة ومعناها

| الحالة | الرد |
|---|---|
| مفيش توكن | `401 {"message": "Unauthenticated", "status": false}` |
| ناقص صلاحية | `403` برسالة الصلاحيات |
| uuid مش موجود | `404 {"message": "Resource Not Found", "status": false}` |
| uuid مش بصيغة UUID | `404 {"message": "Not Found"}` (مفيش route أصلًا) |
| validation | `422 {message, status: false, errors: {field: [...]}}` |
| منتج مش رقمي | `422` برسالة شكلها "ملف" لكن السبب نوع المنتج |
| تجاوز الحد | `429 {success: false, message, data: null}` |

## 9. الـ Queues والـ Workers (مهم — اقرأ ده)

المعمارية المحفوظة: `QUEUE_HIGH`/`QUEUE_MEDIUM` (default `high`/`medium`) → `config('queue.queues.*')` → `QueueName::high()/medium()`. ممنوع أي كود يستخدم أسماء ثابتة.

| الشغل | الـ queue | الدليل |
|---|---|---|
| تنفيذ الطلب الرقمي (`FulfillDigitalProducts` بعد الدفع) | **high** | `viaQueue → QueueName::high()` |
| إشعار "منتجاتك جاهزة" | **high** | نفس النمط |
| سجلات الـ audit (limit/revoke/restore) | **medium** | `LogActivityJob: onQueue(config('queue.queues.medium'))` |

أوامر التشغيل المحلية (connection من `.env`: `database`):

```bash
php artisan queue:work database --queue=high --tries=5 --timeout=90
php artisan queue:work database --queue=medium --tries=3 --timeout=60
php artisan queue:failed      # المعطل — راجع الـ logs لو الـ entitlement متعملش
```

> `php artisan queue:work` لوحده **مش** كفاية — لازم تحدد الـ queue بالاسم المطبوع من الـ config. التنفيذ idempotent (إعادة المحاولة مش بتكرر الاستحقاقات ولا المفاتيح — `firstOrCreate` + `lockForUpdate`).

## 10. استكشاف الأخطاء

- **401 على طول:** التوكن غلط/منتهي — سجّل دخول تاني. توكن العميل مش بيشتغل على endpoints الأدمن والعكس صحيح (نوع اليوزر مختلف).
- **403 غير متوقع:** راجع جدول قسم 6 — خصوصًا `license-keys` (محتاج `manage-digital-licenses` مش `update-product`) و`destroy` (محتاج `delete-product`).
- **422 مفاجئ:** اقرأ `errors` — أسماء الحقول حساسة (`keys.1` معناه العنصر التاني).
- **الـ entitlement متعملش بعد الدفع:** اتأكد الـ worker السامع `high` شغال + `php artisan queue:failed` + اللوجز. وأتأكد `digital.enabled` مش false.
- **كشف المفتاح 403:** غالبًا اتكشف قبل كده (one-time) أو الحوض فاضي (404 برسالة "not allocated").
- **تحميل 403 بعد revoke/restore:** طبيعي بعد الإلغاء. بعد الاسترجاع لو لسه مرفوض — شوف `expires_at` (الاسترجاع مش بيلغي الانتهاء!) أو العداد.
- **التستات بتفشل بـ counts غريبة:** غالبًا بقايا تجارب سابقة في نفس الداتابيز — التستات الجديدة بتستخدم transactions وآمنة، لكن ملفات الـ Digital القديمة بتفترض جدول فاضي (موثق في المصفوفة).
