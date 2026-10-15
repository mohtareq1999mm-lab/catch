# ملحق الإصلاح — تكرار إصدار الـ Fulfillment (FIX-DUP ADDENDUM)

> تكملة مضافة فقط (append-only) لتقرير المسار المتوازي. لا تستبدل `FINAL_REPORT.md` ولا ملفات المسار الآخر. الحالة: **INCOMPLETE/BLOCKED** بسبب حادثة فقدان بيانات `catch` (انظر `DATA-LOSS-INCIDENT.md`).

## 1. الحالة النهائية لهذا المسار: INCOMPLETE/BLOCKED (إصلاح مطبق + تحقق معزول أخضر، لكن الإثبات الحي مستحيل حالياً)
- تم تطبيق إصلاح جوهري واحد + مجموعة اختبارات انحدار (regression) خضراء على قاعدة معزولة.
- كل اختبارات الكتابة الحية على `catch` أصبحت BLOCKED: القاعدة فارغة الآن (`orders=0 users=0 products=0 fulfillments=0` عبر tinker بعد الحادثة). لا محاولة استرداد (تحتاج موافقة صريحة).

## 2. قاعدة البيانات المستهدفة
- المتوقع: `mysql catch @ 127.0.0.1:3306 root/[redacted]`. تم إثباته قبل الحادثة (`.env` + `SELECT DATABASE(),@@hostname,@@port,VERSION()` → `catch/M-tareq/3306/MySQL 8.4.3` + `config database`). بعد الحادثة: نفس الهوية، لكن الجداول فارغة (مخطط سليم 126 جدولاً، بيانات مفقودة). لم يُعدَّل `.env` أبداً.

## 3. الملخص التنفيذي (موثق مقابل مستنتج)
- VERIFIED: الإصدار المفتاحي التلقائي كان يتجاوز إعادة استخدام الـ pending → صفوف مكررة حقيقية في `catch` قبل المسح: الطلب 13 (`id19 NULL-key pending 18:11:45` + `id20 auto-release-order-13 18:12:17`)، والطلبان 4 و5 (pending إضافي بجانب packing/picking) مما جمّد `FulfillmentOrderSyncService::allAtLeast` (الطلبات عالقة processing/pending).
- VERIFIED: الجذر في `app/Services/Fulfillment/FulfillmentService.php:59-109` (فحص المفتاح فقط عند keyed، بينما guard + pending-reuse داخل `if key===null`).
- INFERRED: لا يوجد. لم تُخترع قواعد عمل جديدة.
- ملاحظة ملكية: سطر `FulfillmentService.php` ضمن "تعديلات موجودة مسبقاً" في تقرير الحادثة — مقطع OF-DUP داخله هو تغيير هذا المسار، وليس مسبقاً.

## 4. التدفق الفعلي (مختصر)
`POST general/checkout` → `OrderService::addItemsInOrder` (txn) → `OrderCreationService::createOrder` (pending + flow columns) → items + `reserveForOrder` → `PaymentCheckoutHandler` → `OrderCreated` (afterCommit) → مستمعا الإصدار التلقائي (queued high, auto key) → `releaseForOrder` → `announce FulfillmentProgressed` → مزامنة queued → picking/batch/packing/shipment. التفصيل الكامل في عقد المسار المتوازي (لم يُمس).

## 5. تغطية النقاط
- إجمالي المسجل: 533 (`route:list`)؛ العائلات (flows/checkout/orders/WMS/shipments/inventory) مُصالحة من المصدر. عميق: مسارات الإصدار (keyless/auto/custom) + hardening. الباقي: تدقيق مصدري، التنفيذ الحي BLOCKED (لا صفوف آمنة + القاعدة فارغة).
- محذوف عمداً: لا شيء بصمت؛ كل BLOCKED بسبب مذكور.

## 6. النتائج (الخطورة، الدليل، الحالة)
- F-DUP-001 (BLOCKER): تكرار auto بعد keyless — `FulfillmentService.php:59-109`؛ reproduction صفوف 13 الحقيقية؛ الحالة: FIXED (الكود) + معزول أخضر، حي BLOCKED.
- F-DUP-002 (MUST-FIX): keyless يكرر بجانب active — نفس الكتلة `93-100` (pending فقط)؛ صفوف 4/5؛ الحالة: FIXED بنفس الإصلاح (إعادة استخدام أي non-cancelled).
- F-DUP-003 (INFO): صفوف legacy (`fulfillment_status NULL`, `inventory_state none`, `payment_method NULL`) — خارج الإنشاء القانوني؛ الحالة: موثق، إصلاح البيانات BLOCKED (ممنوع لمس صفوف حقيقية).
- تصحيحات: `warehouses.status` (ليس is_active) و`order_flow_statuses.status_id` والكود سليم؛ قيم الدفع تطابق الثوابت. لا إصلاح.

## 7. الإصلاحات (ملفات دقيقة)
- `app/Services/Fulfillment/FulfillmentService.php`: `isAutomaticKey()` جديد؛ `!isSplitIntent` تشترك في foreign-guard + pending-reuse (+claim عند NULL فقط) + active-reuse (+claim عند NULL فقط)؛ المفاتيح المخصصة تحافظ على bypass (splits). لا تغيير API/رسائل/صلاحيات.
- `tests/Feature/Fulfillment/AutoReleaseDedupeRegressionTest.php` (جديد): 3 اختبارات (keyless→auto نفس الصف + claim؛ keyless مع active لا يكرر؛ split مخصص ينشئ ثانياً).
- المراجعة المستقلة: لا BLOCKER؛ 3 SHOULD-FIX مؤجلة (نطاق المفتاح عبر الطلبات، تشدد اللاحقة، دلالات terminal) — خارج النطاق عمداً.

## 8. أدلة الاختبار
- `php -l` سليم. المعزول (`$env:DB_DATABASE='catch_verify'`, ليس إثبات بيانات حقيقية): `FulfillmentHardeningTest` 6/6، الجديد 3/3، `AutoReleaseWiringTest` 16 ناجح + 5 فاشل لغة (مثبت مسبقاً عبر stash). الأوامر والمخرجات في `verification-evidence.md` (مسارنا) — صالحة تاريخياً.
- `catch` قبل المسح: قراءات SELECT فقط (13 طلباً، 16 تنفيذاً، jobs=0، failed=10 Meilisearch/mpdf). بعد المسح: tinker `0/0/0/0`. لا كتابة حية، لا مدفوعات، لا صفوف وهمية، لا migrations.
- التزام الاحتواء: بعد اكتشاف الحادثة، توقفت كل التنفيذات (لا tests/tinker-writes/migrations) من هذا المسار.

## 9. الترجمات
- المحدد `lang` header فقط؛ مفاتيح المسار (`second_rejected/no_physical/...`) موجودة en+ar؛ لا رسائل مغيرة؛ الواجهة الأمامية BLOCKED (لا مصدر SPA).

## 10. سلامة البيانات
- قبل/بعد: لا كتابة على `catch` من هذا المسار (SELECT + معزول فقط). لا تنظيف مطلوب. القلق المتبقي: الصفوف المكررة الموجودة مسبقاً فُقدت مع المسح — عند الاسترداد من نسخة، أعد فحص التكرار قبل أي إصلاح بيانات.

## 11. العوائق/المخاطر المتبقية
1. `catch` فارغة — المتطلب: قرار الاسترداد (نسخة موثوقة + توافق) بموافقة صريحة؛ مانع لكل E2E حي.
2. لا سجلات آمنة للكتابة الحية حتى بعد الاسترداد حتى تُصنف.
3. التزامن متعدد العمليات على MySQL غير مثبت (BLOCKED).
4. المدفوعات الحية/الاستردادات خارج الحدود (لا sandbox مثبت).

## 12. TODO التالي (مرتب بالمخاطر)
1. قرار الاسترداد + إثبات الهوية والعد قبل/بعد (مستخدم).
2. حجر `RefreshDatabase` (DB-005) + حظر `--filter` الجزئي (TEST-005) — إلزامي قبل أي test.
3. بعد الاسترداد: إعادة فحص التكرار SELECT، ثم E2E معزول/آمن فقط.
4. المتابعات SHOULD-FIX (نطاق المفتاح، اللاحقة، terminal) بموافقة عمل.

## 14. دورة المهمة Iter-007 (إعادة الدخول 2026-10-14) — INCOMPLETE/BLOCKED
- اكتشاف قرائي فقط + وكيلان محدودان (مطابقة WMS: الطلبات 1–5 قانونية pre-release، 6–12 تجهيزات مرحلية by-design، لا نمط stall؛ المتحدي: MUST-FIX واحد + SHOULD-FIX + NICE — كلها مطبقة).
- الإصلاحات: نقل commit-guard لنهاية Seeder + تلميح التوكن + ضبط docblock (لا API/صلاحيات/ترحيل). `php -l` سليم. محاولة إعادة التسييد فشلت بأمان عند حارس Coupon (SEED-001 → backlog، صفر انحراف 12/7/3/7/20/1).
- المتبقي المحظور بدقة: تحقق runtime (241 ملف RefreshDatabase)، E2E حي (لا سجلات آمنة)، sandbox، concurrency. التفاصيل: `error/change-log.md` (C-004)، `error/iteration-log.md` (Iter-007)، `error/findings.md` (SEED-001/002).

## 15. تحقق Iter-008 (بدون تغيير سلوكي) — INCOMPLETE/BLOCKED
- تأكد وجود عناصر C-004 في الشجرة (grep+قراءة+lint): حارس واحد بنهاية التشغيل، حظر الإنتاج، تلميح التوكن، مفتاح مثبت، docblock مضبوط. قرار REFUND-009: يبقى committed (متسق لطلب مدفوع مكتمل).
- إعادة التسييد لم تُعَد (SEED-001 مثبت مسبقاً، صفر انحراف). إحصاء 12 طلباً + invariants أُعيد فحصها قرائياً. البوابات §11: الهوية PASS، routes PASS، دورة الحياة mapped، الأسباب الجذرية مثبتة، i18n/envelope متوافق، الباقي BLOCKED بأسباب دقيقة (DB-005، SEED-001، سجلات آمنة، sandbox).

## 16. بلاغ الطلب 13 وفشل التقاط (Iter-009) — INCOMPLETE/BLOCKED
- الطلب 13 غير موجود في `catch` (الأقصى 12) — إعادة إنتاج الحالة الدقيقة BLOCKED. الآلية مثبتة بالكود: تخطي العناصر بلا تموضع → 200+قائمة فارغة، والحل `assign-placement` ثم إعادة create-tasks. مسار الدخول 401 مترجم en+ar بلا تسرب (PASS، لا إصلاح).
- الإصلاح المطبق: مراقبة PICK-OBS (عداد + سياق سجل + تحذير، بلا PII ولا تغيير عقد)، سليم lint، مراجعة مستقلة بلا BLOCKER/MUST-FIX. التشغيل الحي UNVERIFIED-BLOCKED. للتأكيد في بيئتك: افحص `product_location_id IS NULL` في عناصر التنفيذ وعدد `picking_tasks` وحالة التنفيذ.

## 17. تعميم الإصلاح وقرارات العقد (Iter-010) — مغلق بموافقتك
- دُققت كل المواضع الشقيقة (إنشاء الدفعات يرفض بصوت عالٍ 422، إعادة المحاولة برسالة صريحة، التغليف بعدّ+تحذير، القوائم الفارغة صحيحة، المزامنة no-op) — لم يحتج أي منها لتغيير. قراراك الصريح: إبقاء 200+سجل وإبقاء التموضع اليدوي — خيارا الكسر/التخمين مغلقان بموافقة موثقة. لا دلتا كود جديدة.

## 18. سيد الحاويات الحقيقية وتغطية المنتجات (Iter-011) — مكتمل بالاختبار
- ثبت قرائياً: 11 حاوية حقيقية + 4 تموضعات تغطي 3/3 منتجات بكميات حية؛ `LocationSeeder` جغرافي (تسمية مضللة) والحاويات من سيدين غير قابلين لإعادة التشغيل.
- الجديد: `WarehouseBinMasterSeeder` مستقل (11 حاوية firstOrCreate، حظر إنتاج، تحذير انحراف بلا إعادة كتابة، تقرير تغطية بمحاكاة المخصص، صفر كتابة كميات — التدفق الحقيقي عبر stock-receipts فقط). التشغيل مثبت: 11/0، تغطية 3/3، انحراف PICK-01 محفوظ، العد 11/4 ثابت. المراجعة بلا BLOCKER. الفجوات (60 مقابل 100) تلميحات لا عيوب — لا تلفيق.

## 19. إثبات تموضع كل المنتجات وقبولك (Iter-012) — مكتمل بلا كتابة
- الربط الحي يثبت: ALPHA في PICK-01(50)+A-01-01(50)، BETA في A-01-02(60)، GAMMA في A-01-01(20)، ولا variants موجودة. قبلتَ صراحةً الاكتمال (لا نقل، لا قاعدة أخرى). أي SKU مستقبلي بلا تموضع يُستقبل عبر stock-receipts فقط — لا صفوف صفرية (عديمة الفائدة) ولا تلفيق كميات.

## 20. حادثة مسح ثانية أثناء تشغيل مصرّح به (Iter-014) — احتواء كامل
- نفذت 3 suites على `catch_verify` (معزول): 3/3 و6/6 و16+5-مطابق-للأساس. بعدها `catch` فارغة (كانت 12/7/3/7/11/4) مع جدول دخيل `content_page_section` لا تنشئه suites بل ملف المسار المتوازي فقط — الاشتباه القوي تشغيل متزامن غير محمي، مع إقرار بعدم اليقين الكامل. التفاصيل: `error/DATA-LOSS-INCIDENT-2.md`.
- من هذا المسار: توقف كل التنفيذات (حتى المعزولة) حتى حجر DB-005 والتنسيق. قرارات الاسترداد مطلوبة — لا إجراء متخذ.

## 13. جلسة FIX ALL ERROR (2026-10-14) — النطاق: آمن غير كاسر + تسييد مصرّح به
- قرار المستخدم: safe non-breaking only + seed عند الفراغ. الهوية مثبتة (`catch` فارغة 0/0/0 ثم 12/7/3 بعد التسييد).
- Safe fixes المؤكدة في الشجرة (C-002 من المسار المتوازي + تحقق هذه الجلسة): تعليقات routes، إزالة `GET me` المكرر، throttles، فصل contacts مع إبقاء `POST /contacts` عاماً، `updateContact` ذاتي، allowlist/limit، guard super_admin، `isAutomaticKey` المثبت (`\d+`)، تقسية Seeder (حظر الإنتاج، توكن غير مكرر، نطاق ORD-WMS-*).
- التسييد: `db:seed` بنجاح (12 طلباً كلها بـ flow، لا تكرار active). التوكن محجوب. التفاصيل: `error/SEED-RUN-2026-10-14.md` و`error/change-log.md` (C-003).
- غير مُتحقق حياً: سلوك runtime للإصلاحات (لا test: 241 ملف RefreshDatabase) — BLOCKED حتى حجر DB-005. البيانات الحقيقية المفقودة لم تُستعد (التسييد ليس استرداداً).
