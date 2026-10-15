# التقرير النهائي — تدقيق شامل للـ API (INCOMPLETE/BLOCKED — حادثة فقدان بيانات)

> الحالة النهائية: **INCOMPLETE/BLOCKED** — ليس DONE. كل ما يلي موثّق بالأدلة، بدون اختلاق.

## 1. الحالة النهائية
- **INCOMPLETE/BLOCKED**: اكتملت مرحلة الاكتشاف القرائي بأدلة VERIFIED. لكن قاعدة `catch` مُسحت بتشغيل اختبار غير آمن (`RefreshDatabase` → `migrate:fresh`) — المستخدمون 8→0، الطلبات 13→0، المنتجات 4824→0. كل اختبارات الكتابة/التكامل/التزامن أصبحت BLOCKED حتى قرار الاسترداد. إصلاحات HIGH + hygiene مطبّقة ككود (C-002) لكن سلوكها الحي غير مثبت. لا ندّعي أي تغطية غير مثبتة.

## 2. قاعدة البيانات المستهدفة
- المتوقع: `mysql 127.0.0.1:3306 catch root [REDACTED]` — VERIFIED مطابق في `.env`.
- الإثبات الحي (قبل الحادثة): `SELECT DATABASE()=catch, @@hostname=M-tareq, @@port=3306, VERSION()=8.4.3` — VERIFIED. التفاصيل: `error/database-target-verification.md`.
- بعد الحادثة (VERIFIED قراءة فقط): `db=catch, users=0, orders=0, products=0, tables=126`. الـ schema سليم، البيانات مفقودة. التفاصيل: `error/DATA-LOSS-INCIDENT.md` + `error.md`.

## 3. الملخص التنفيذي (Verified مقابل Inferred)
- **Verified**: الهيكل (Laravel 10.30.1, PHP 8.2.30)، مصدرا الـ routes (`routes/api.php` + `packages/marvel/.../Routes.php` عبر `RestApiServiceProvider`)، جدول التشغيل **533 route (515 api/v1)**، عدّادات النطاقات (general 76, admin 108, dashboard 21, coupons 20, products 19, ...)، التكرارات (`me` ×2، `digital/download` ×2، `broadcasting/auth` ×3)، مخاطر الترتيب (آمنة حالياً، هشّة بدون guards)، التعليقات الكاذبة (OTP نشط رغم DISABLED، throttle الدخول 5 لا 10)، ثغرات HIGH (OTP enumeration، `update-contact` IDOR، contacts العامة، `assign-role` escalation)، الكاتب القانوني (`OrderService::changeOrderStatus`)، سلطة المخزون (SKU لا placements)، نطاق المستودعات fail-closed، عقد اللغة (`lang` header لا `Accept-Language`)، انحراف الترجمة (`message.php` en 668 مقابل ar 613).
- **Inferred** (غير مثبت): قابلية استغلال SQLi في `order_by`، ربط social بالبريد غير المؤكد، تفاصيل idempotency للاستلام/التحويل، مسارات Marvel القديمة، أي نجاح E2E حقيقي.
- **سبب الكسر الرئيسي**: ليس عيباً واحداً في الكود بل (أ) فجوات أمنية موثقة تحتاج إصلاحاً minimal، و (ب) حادثة تشغيلية (مسح DB) أوقفت التحقق التكاملي.

## 4. التدفق الفعلي (checkout → fulfillment → shipment)
- `POST checkout` → `OrderService::addItemsInOrder` (txn واحدة) → `OrderCreationService` (pending + items + flow_values) → `reserve` → (online zero-value إكمال محلي / online gateway + callback verify + `completeLocked` / cod/cashier commit) → `OrderCreated` بعد الـ commit.
- `changeOrderStatus` الكاتب الوحيد (txn واحدة: flow gate → inputs → guards مالية/تسليم → mirrors → history → invoice عند أول مغادرة pending → coupon/promotion/reservation → txn → cancel cascade → events after-commit).
- `releaseForOrder` (مستودع صريح/افتراضي/fail) → picking (claim/record/confirm/skip/reallocate) → complete-picking → packing (assign/start/pack/verify) → packages (add/seal/void) → `createForFulfillment` → dispatch/deliver/cancel. كل أمر محروس بـ `WmsAdminController::authorizeWarehouseScope` (قراءة 404-mask، كتابة 403). التفاصيل + الجدول الانتقالي + Mermaid: `error/flow-contract.md`.

## 5. تغطية الـ API الكاملة
- المكتشف: **533** (515 api/v1 + 18 vendor/infra — عدّادات النطاقات ESTIMATES غير مُجمّعة). العقود المكتملة: **0** (skeleton فقط). المحاوَل: **0 صالح** + **1 تشغيل ملوث** (INVALID). الناجح/الفاشل/المحظور/غير القابل: **0 / 0 / كل الكتابة BLOCKED / 18 N/A**.
- لكل نطاق (auth/RBAC, catalog, cart, coupons/promotions, checkout/orders/flows, payments/refunds, shipments/invoices, digital, WMS, CMS, notifications/reviews, analytics/tracking, infra): العدّادات في `error/api-domain-coverage.md`. لا نطاق مُستبعد. لا route صامت.
- المسارات المعلّقة/المعطّلة: `shipments/track` ×2 COMMENTED، `make-admin/add-points/attribute-values/shippings` COMMENTED، OTP ACTIVE رغم التعليق — جميعها `COMMENTED/NOT AN ENDPOINT` أو `SOURCE-DECLARED` موثقة، غير مختبرة كـ endpoints.
- التفاصيل الكاملة: `error/route-inventory.md` (538 سطراً).

## 6. النتائج (Findings)
- HIGH: SEC-001 (OTP)، SEC-002 (IDOR)، SEC-003 (contacts)، SEC-004 (role escalation) — التفاصيل + الملفات + الأسطر: `error/findings.md`. حالة SEC-001/002/003/004 + SEC-006/009/010 + RT-003/005: **FIXED ككود (C-002/C-006)**، السلوك الحي BLOCKED. SEC-005/007: ACCEPTED/DOCUMENTED بقرار المستخدم. SEC-008: cap مطبق + scope موثق.
- MED-HIGH: SEC-005 (login/token)، SEC-006 (reset).
- MEDIUM: SEC-007 (signed bearer)، SEC-008 (shipments scope)، SEC-009 (order-by)، SEC-010 (throttles).
- LOW: RT-003 (duplicates)، RT-005 (comment drift)، I18N drift.
- كلها **OPEN** عدا SEC-001/002/003/004/006/009/010 وRT-003/005 (**FIXED ككود، السلوك الحي BLOCKED**) وSEC-005/007 (**ACCEPTED/DOCUMENTED**) وSEC-008 (**cap مطبق + scope موثق**). الـ BLOCKER التشغيلي هو فقدان البيانات الأصلية + حظر المسح.

## 7. الإصلاحات
- **C-002 (هذا المسار)**: SEC-002 (self-only + throttle + 404)، SEC-003 (فصل contacts مع alias عام)، SEC-004 (حارس super-admin 403)، SEC-009 (allowlist + cap)، SEC-010 (throttles)، RT-003/005 (إزالة التكرارات + تصحيح التعليقات). الملفات: `Rest/Routes.php`، `routes/api.php`، `UserController.php`، `RoleAndPermissionController.php`، `UpdateContactRequest.php`. التوافق محفوظ (URIs/names unchanged، 533 ثابت). المراجعة المستقلة: BLOCKER B1 + S1-S4/N1-N2 مطبّقة. السلوك الحي BLOCKED (DB فارغة). السجل: `error/change-log.md` (C-002).
- **المسار الموازي**: موثّق في `FIX-DUP-ADDENDUM.md` (أخضر معزول، حي BLOCKED).
- **C-006 (هذا المسار، "fix all")**: SEC-001 (generic-200 + lockout رباعي المسارات)؛ SEC-006 (مفاتيح منفصلة)؛ SEC-008 cap؛ SEC-005/007 موثقة بقرار المستخدم. الملفات: `UserController.php` (+103/-8)، `ShipmentController.php` (cap). التوافق: لا routes/permission/envelope change عدا 429 الإضافية (ApiResponse shape)؛ لا مفاتيح ترجمة جديدة؛ لا migration. المراجعة: لا BLOCKER/MUST-FIX؛ المتبقي F1/F2/S3/guest-redemption/N7 موثق. السلوك الحي BLOCKED.

## 8. أدلة الاختبار
- حلقة الإصلاح البيئي (C-007، MySQL الحقيقية `catch`، ملفات certified آمنة بأمر صريح لكل ملف): AuthenticationTest ‏19/19، UserPasswordResetTest ‏13/13، OtpLocalLoginTest ‏4/4، RoleAndPermissionTest ‏34/34 (منها اختبارا انحدار SEC-004 الجديدان)، UserControllerTest ‏68/71، UserAuthAdminTest ‏12/19 → **150 ناجح / 10 BLOCKED**.
- الحلقة المعزولة (catch_verify بقرار المستخدم، موسومة، ليست إثبات catch): نفس الملفات الستة + StatusOptions ‏17/17 + UnifiedFlow ‏24/24 + MarvelAdapter ‏4/4 + Forensic ‏98/98 → **293 ناجح / 10 BLOCKED** (نفس المجموعة، مستقلة عن البيانات). بقايا صفرية في both DBs.
- الدفعة الثالثة (C-011): ‏128/0 عبر 8 ملفات (Matrix/Inputs/Lifecycle/Checkout/Stock/Reservation/Social/Phone) + migration ‏nullable-email (مطبقة على verify فقط) + حارس DB-005 (C-010). حادثة DDL-trap (بقايا ذاتية 4 صفوف، نُظفت) → قاعدة: DDL البيئة خارج المعاملات.
- الدفعة الرابعة (C-012): ‏247/0 عبر 20 ملفاً + migration ‏nullable-variant-price + تصحيح enum صامت + 422-envelope. اكتشافان حقيقيان: service-intended nulls مقابل NOT NULL (×2)، واختبار ناجح لسبب خاطئ (مصادفة ‏100×0.85). بقايا صفرية؛ `catch` لم تُمس.
- الدفعة الخامسة (C-013): ‏33/0 (MarvelStatusAuthority + PaymentSystem) + قاعدة `catch_audit` خاصة. إلغاء 6 اختبارات reaper إلى عقد reservation + matrix unit test + FK ids حقيقية + canonical coupon. بقايا صفرية.
- الدفعة السادسة (C-014): ‏53/0 (invoice ×2 + digital + gift) + انضباط التزامن. أعمق ترجمة: COD-commit مقابل reservation-lifecycle + نطاق reaper (pending فقط، عن قصد).
- الدفعة السابعة (C-015): ‏47/0 (FinalArchitecture + PendingRedesign) + تعايش التصميمين (warehouse لنطاق mark-paid فقط).
- الدفعة الثامنة (C-016): ‏82/0 (notifications + coupons) + ترجمة reaper إلى عقد reservation + تشخيص Pusher-flake (DDL أول تشغيل).
- الدفعة التاسعة (C-017): ‏91/91 (NotifyLogs محظورة كملف + UserNotification + ExportCancel + BusinessRules + EventSystem) + حراس paid-cancel + single-currency EGP.
- الدفعة العاشرة/الحادية عشرة (C-018): ProductsEndpoint ‏77/77 + CategoriesEndpoint ‏40/41 (PAGINATION-001 مؤجلة بقرار) + `catch` أُعيد ملؤها (156/1010) دون مساس.
- الدفعة الثانية عشرة (C-019): TagCrud ‏13/13 + WishlistApi ‏36/36 (توصيل route ناقص + page-object). العشرة: اختبارات ضد routes غير مسجلة/معطلة (update-email ×4، social-login-token ×3، make-admin ×3) مع إثبات `route:list ERROR` لكل منها — التنفيذ مرفوض (features جديدة).
- بقايا صفرية: العدّادات ‏7/12/3/2/9 متطابقة قبل/بعد كل تشغيل. حادثة بيئة: MySQL كان متوقفاً mid-loop (تشغيل أحمر-كاذب مُستبعد)؛ أُعيد التشغيل على نفس datadir والعدّادات متطابقة.
- INVALID (لا يُستشهد به): `--filter=AuthenticationTest` القديم → `4 failed, 41 passed` أثناء الترحيل — ملوث.
- المراجعة المستقلة لملفات الاختبار: لا BLOCKER؛ M1/S1/S2/S3/N2 مطبّقة. التفاصيل: `error/verification-evidence-audit-track.md`.

## 9. الترجمات
- العقد: `lang: ar|en` (مفقود→en، غير مدعوم→ar الافتراضي) — مصحّح من `Accept-Language`. الملفات: 15+15 فعلية + ar.json. **تصحيح**: سكربت parity شُغّل (قراءة فقط) → 0 مفاتيح مفقودة في الاتجاهين (15 ملفاً، message 592/592) — انحراف الأسطر شكلي لا نقص مفاتيح؛ النتيجة PARTIAL (ar.json + تعمق 2+ + placeholders + اختبار كل endpoint لم تُنفّذ). `Handler` يستخدم `__('message.ERROR.*')` (لا تسرّب خام في العينة). الواجهة الأمامية: **BLOCKED / FRONTEND SOURCE NOT AVAILABLE**. التفاصيل: `error/translation-audit.md`.

## 10. سلامة البيانات
- قبل/بعد: قبل المسح (13/4824/8) وبعده (0/0/0) — مثبت. لا rollback ممكن (DDL `migrate:fresh` لا يُتراجع). لا تنظيف متبقٍ (الاختبار أعاد الترحيل فارغاً + seed flows=2).
- آثار خارجية: لم يُستدعَ gateway حي، لا refund حقيقي، لا شحن خارجي، لا اتصالات — VERIFIED (لم تُشغّل تلك المسارات).
- القلق المتبقي: البيانات الحية مفقودة؛ أي استنتاج تكاملي سابق لا يمكن إعادة تنفيذه حتى الاسترداد.

## 11. العوائق/المخاطر المتبقية (بأسباب دقيقة)
1. **استرداد `catch`**: لا نسخة موثقة من `catch`. `meemmarket_meem.sql` (DB مختلف، سيرفر مختلف، بيانات مختلفة) ليس بديلاً موثوقاً. السبب: يتطلب dump موثوقاً + موافقة صريحة (عملية مدمرة). التالي: زوّد الـ dump الموثوق + أكّد التوافق ثم اطلب الاستعادة.
2. **اختبارات الكتابة/التكامل/التزامن**: BLOCKED — لا سجلات آمنة + لا harness مُثبت. السبب: بعد المسح لا توجد سجلات أصلاً. التالي: بعد الاسترداد، صنّف سجلات test-eligible + snapshot/rollback + `--list-tests` أولاً.
3. **RefreshDatabase quarantine**: FIXED (C-010) — `tests/CreatesApplication.php` يرفض أي تشغيل ضد `catch` المشتركة ما لم يصرّح بـ `ALLOW_CATCH_TESTS=true` (مثبت بثلاثة مسارات). المتبقي: `--list-tests` إجباري كعادة.
4. **Legacy Marvel traits + fast-shipping + digital-only + warehouse-change**: UNKNOWN/BLOCKED — تحتاج تتبعاً قرائياً (آمن، يمكن إكماله بدون DB).
5. **Frontend + sandbox crypto + multi-process concurrency env**: BLOCKED — لا مصدر/بيئة. التالي: وفّر المصدر/الاعتمادات أو اقبل التوثيق كـ BLOCKED.

## 12. الـ TODOs التالية (مرتبة بالمخاطر)
1. **قرار الاسترداد** (user): زوّد backup `catch` الموثوق أو اقبل الفراغ أو أوقف المهمة. الإجراء: سؤال موجّه الآن.
2. **Quarantine**: DONE (C-010 guard + `--list-tests` إجباري + تشغيل ملفات certified بأمر صريح لكل ملف). المالك: lead. القبول: لا اختبار يعمل على `catch` دون تصريح — مثبت.
3. **إكمال التدقيق القرائي الآمن**: legacy traits، fast-shipping، digital-only، parity الترجمة، `SHOW CREATE TABLE` sweep، orphan hunt (قراءة فقط). المالك: lead. القبول: findings جديدة أو تأكيد.
4. **الإصلاحات الـ minimal** (بعد الاسترداد): SEC-002/003/004 + تعليقات + تكرارات — diff واحد متماسك + انحدار. المالك: lead. القبول: acceptance tests + re-audit.
5. **مصفوفة الاختبار**: صف لكل method+URI+سيناريو (rejection الآمنة أولاً بدون كتابة). المالك: lead. القبول: عدّادات honesty (discovered/attempted/passed/failed/blocked/N-A).
6. **إعادة التحقق**: كل gate في §11 بعد آخر تغيير + `route:list` لكل URL مُدّعى.

## الملفات
- `error/TODO.md`, `error/findings.md`, `error/change-log.md`, `error/iteration-log.md`, `error/translation-audit.md`, `error/verification-evidence.md`, `error/DATA-LOSS-INCIDENT.md`, `error.md` — كلها محدّثة.
- `error/route-inventory.md` (533)، `error/flow-contract.md`، `error/api-domain-coverage.md`، `error/test-matrix.md`، `error/db-integrity-audit.md`، `error/database-target-verification.md`، `error/api-contracts/README.md` — محدّثة.
- لا أوامر مدمرة أخرى نُفّذت. لا أسرار مكشوفة. لا PII.

## ملحق التوفيق بين المسارين (2026-10-14، بعد اكتشاف مسار متوازٍ)
- رُصد مسار متوازٍ طبّق إصلاح `FulfillmentService` (تكرار الإصدار keyless/auto/custom) + `AutoReleaseDedupeRegressionTest` + `error/FIX-DUP-ADDENDUM.md` + `error/change-log.md` (C-001). هذا المسار (التدقيق الشامل) لم يطبّق ذلك الإصلاح ولا يراجعه كـ DONE؛ التوثيق هنا يعترف به كعمل متوازٍ موثّق في ملفاته الخاصة.
- التعارضات المحلولة: `error/route-inventory.md` و`error/change-log.md` يملكهما المسار المتوازي حالياً (ملخص + C-001) — حُفظا كما هما. جدول الـ 533 الكامل لهذا المسار أُعيد توليده كـ `error/route-inventory-full-table.md` (539 سطراً، append-only) بدل الكتابة فوق ملخص المسار الآخر.
- §7 أعلاه ("لا إصلاحات مطبّقة") يخص هذا المسار فقط؛ الإصلاح الموازي موثّق في `FIX-DUP-ADDENDUM.md` وحالته INCOMPLETE/BLOCKED (أخضر معزول، حي BLOCKED). لا تضارب في الأدلة: حادثة المسح + الهوية + الـ census مشتركة ومتفقة (533، catch فارغة).
- `git status` يُظهر تعديلات `FulfillmentService.php` + `AuditInventoryPlacements.php` + `Routes.php` + `WmsPlacementAuditTest.php` — تُعزى للمسار المتوازي/السابق، ليست من هذا المسار (لا استدعاءات تحرير هنا بعد الاكتشاف).
