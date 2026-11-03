# دليل حسابات وصلاحيات نظام إدارة المستودعات (WMS Users & Credentials Reference)

تم توحيد جميع حسابات المستخدمين في نظام المستودعات (WMS) مع مستخدمي النظام الأساسيين وتجهيز طاقم تشغيلي متكامل لعمليات المستودع وتأكيد ربطهم بجدول `warehouse_users` ومستودع القاهرة الرئيسي (`WH-CAIRO-CENTRAL` / `MAIN`).

---

## 1. جدول الحسابات الموحدة وبيانات الدخول (Master Credentials Table)

> **ملاحظة أمان:** كلمة المرور الموحدة لجميع حسابات الاختبار والتشغيل هي: `password123`  
> (باستثناء حساب `admin@demo.com` يقبل أيضاً `password` بحسب البيئة).

| الفئة / الدور | الاسم | البريد الإلكتروني (Email) | كلمة المرور | الدور في النظام (Role) | المستودع المسند إليه | الغرض التشغيلي |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Super Admin** | Shop Owner & Super Admin | `admin@demo.com` | `password123` | `super_admin`, `warehouse-manager` | Cairo Hub (`ID: 1`) | الإدارة الشاملة، الصلاحيات الكاملة لجميع أقسام المتجر والمستودع |
| **Super Admin** | Super Administrator | `admin@gmail.com` | `password123` | `super_admin`, `warehouse-manager` | Cairo Hub (`ID: 1`) | إدارة عمليات WMS والتكامل واختبار الـ Endpoints |
| **Management** | مصطفى كامل | `manager@wms.com` | `password123` | `warehouse-manager` | Cairo Hub (`ID: 1`) | إدارة المستودع، ضبط المواقع، تعديل المخزون، فتح وإلغاء الـ Fulfillments |
| **Supervision** | كريم سامي | `supervisor@wms.com` | `password123` | `warehouse-supervisor` | Cairo Hub (`ID: 1`) | الإشراف الميداني، إنشاء وإسناد الدفعات (Batches)، تجاوز الأخطاء (Override) |
| **Picker 1** | أحمد طارق | `picker1@wms.com` | `password123` | `picker` | Cairo Hub (`ID: 1`) | عامل التقاط مخصص لإسناد وتنفيذ مهام جمع الأصناف من الرفوف |
| **Picker 2** | محمد يوسف | `picker2@wms.com` | `password123` | `picker` | Cairo Hub (`ID: 1`) | عامل التقاط لتنفيذ مهام الـ Wave/Batch Picking ومسح الباركود |
| **Picker 3** | خالد حسن | `picker3@wms.com` | `password123` | `picker` | Cairo Hub (`ID: 1`) | عامل التقاط بديل لاختبار التوزيع المتزامن وتوزيع الأحمال |
| **Picker (Lead)** | أحمد علي | `picker@wms.com` | `password123` | `picker` | Cairo Hub (`ID: 1`) | الحساب التوافقي الأساسي لمهام الجمع السريع |
| **Packer 1** | محمود سيد | `packer1@wms.com` | `password123` | `packer` | Cairo Hub (`ID: 1`) | عامل تغليف على طاولة التغليف `PACK-STATION-01` وتجهيز الطرود |
| **Packer 2** | إبراهيم عادل | `packer2@wms.com` | `password123` | `packer` | Cairo Hub (`ID: 1`) | عامل تغليف مخصص لإنشاء الطرود (Packages) وإدخال الأوزان وختمها |
| **Packer 3** | تامر فتحي | `packer3@wms.com` | `password123` | `packer` | Cairo Hub (`ID: 1`) | عامل تغليف لاختبار المحطات المتعددة وتأكيد جاهزية الشحن |
| **Packer (Lead)** | خالد رضا | `packer@wms.com` | `password123` | `packer` | Cairo Hub (`ID: 1`) | الحساب التوافقي الأساسي لمهام التغليف |
| **Customer** | محمد العميل التجريبي | `customer@wms.com` | `password123` | `customer` | — | عميل طلبات WMS لاختبار تدفقات الشراء والدفع والاسترجاع |
| **Customer (Demo)** | Test Customer | `test@g.com` | `password123` | `customer` | — | العميل الأساسي الموحد للنظام لاختبار الطلبات العامة وتطبيق المتجر |

---

## 2. جدول الصلاحيات الدقيقة لكل دور (Role Permissions Matrix)

### أ. دور عامل الالتقاط (`picker`)
مخصص للأجهزة المحمولة والماسحات اليدوية (Honeywell/Zebra Scanners) لجمع البضائع:
- `view-warehouse`: عرض بيانات المستودع المعين فيه.
- `view-location`: استعراض مواقع الرفوف وخانات الالتقاط.
- `view-fulfillment`: استعراض أمر التجهيز المرتبط بمهمته.
- `picking-execute`: بدء وتنفيذ مهام الالتقاط.
- `picking.claim`: استلام مهمة الالتقاط وربطها بنفسه من قائمة المهام المتاحة.
- `picking.complete`: إنهاء تسجيل التقاط الصنف وتأكيد نقله لمحطة التغليف.
- `barcode.scan`: صلاحية مسح الباركود للتحقق من المنتجات ومواقع الأرفف.

### ب. دور عامل التغليف (`packer`)
مخصص لمحطات التغليف والشحن (Packing Stations):
- `view-warehouse`: عرض بيانات المستودع ومحطات التغليف.
- `view-location`: استعراض مواقع التجميع والاستلام.
- `view-fulfillment`: استعراض الأصناف التي تم التقاطها وجاهزة للتعبئة.
- `packing-execute`: بدء مهمة التغليف على طاولة محددة (`PackingStation`).
- `packing.complete`: تأكيد وزن وتعبئة الطرد وختمه (`Package Seal`) لنقله للشحن.
- `barcode.scan`: مسح باركود الطرود والأصناف والملصقات (Shipping Labels).

### ج. دور مشرف العمليات (`warehouse-supervisor`)
- يمتلك جميع صلاحيات الـ `picker` والـ `packer`.
- `manage-fulfillment`: إدارة أوامر التجهيز وإعادة توزيع المهام.
- `batch.manage` & `batch.operate`: إنشاء دفعات الالتقاط وتجميع الطلبات المشتركة وإطلاقها.
- `fulfillment-override`: تخطي أي عقبة موقع أو صنف ناقص لحين الجرد.
- `fulfillment.create` & `fulfillment.cancel`: إنشاء أو إلغاء أوامر التجهيز.
- `view-shipments` & `view-shipment`: مراقبة الشحنات الجاهزة للخروج.

### د. دور مدير المستودع (`warehouse-manager`) و (`super_admin`)
- الصلاحيات الشاملة على مستوى الـ API:
  - إدارة المستودعات والمحطات والرفوف بالكامل (`manage-warehouse`, `manage-location`, `inventory-adjust`).
  - إطلاق الطلبات من إدارة الطلبات إلى التجهيز (`POST /admin/fulfillments/release`).
  - معالجة طلبات الاسترجاع (`view-refunds`, `payments.refund`).
  - متابعة تقارير الشحن وتحليلات الأداء.

---

## 3. التحقق من الربط الفعلي وإسناد المهام (Task Assignment Readiness)

جميع عمال الالتقاط والتغليف والإشراف تم ربطهم في قاعدة البيانات بالطريقتين المعياريتين:
1. **العمود المباشر:** حقل `users.warehouse_id = $warehouse->id`.
2. **جدول الربط الوسيط (`warehouse_users`):**
   ```sql
   SELECT u.email, u.name, r.name as role, wu.is_primary 
   FROM users u
   JOIN warehouse_users wu ON u.id = wu.user_id
   JOIN model_has_roles mhr ON u.id = mhr.model_id
   JOIN roles r ON mhr.role_id = r.id
   WHERE wu.warehouse_id = 1;
   ```
   هذا يضمن أن أي استعلام API لجلب العمال المتاحين لإسناد المهام في المستودع (`GET /api/v1/admin/warehouses/{id}/users` أو عند إنشاء دفعة أو إسناد مهمة) سيعيد هؤلاء المستخدمين فوراً بصفتهم عمالاً رسميين مسندين لهذا المستودع.

