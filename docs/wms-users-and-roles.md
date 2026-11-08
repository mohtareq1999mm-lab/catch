# دليل مستخدمي وأدوار نظام إدارة المستودعات (WMS Users & Roles Guide)

تم إعداد هذا المستند بناءً على بيانات الـ Seeder الخاص بنظام الـ WMS ولإدارة المستودعات:  
[`database/seeders/WarehouseDashboardSeeder.php`](file:///d:/work/meem/database/seeders/WarehouseDashboardSeeder.php)

---

## 1. جدول الحسابات السريع (Quick Credentials Table)

| الاسم الكامل | البريد الإلكتروني (Email) | كلمة المرور (Password) | نوع الحساب (Type) | الدور الوظيفي (Role) | المستودع المرتبط |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **WMS General Admin** | `wms.admin@example.com` | `password123` | `admin` | `super_admin`<br>`warehouse-manager` | `WH-MAIN` (القاهرة) |
| **WMS Supervisor** | `wms.supervisor@example.com` | `password123` | `admin` | `warehouse-supervisor` | `WH-MAIN` (القاهرة) |
| **Mostafa Picker** | `wms.picker@example.com` | `password123` | `admin` | `picker` | `WH-MAIN` (القاهرة) |
| **Khaled Packer** | `wms.packer@example.com` | `password123` | `admin` | `packer` | `WH-MAIN` (القاهرة) |
| **Customer Demo** | `wms.customer@example.com` | `password123` | `user` | `customer` | — (عميل متجر) |

---

## 2. تفاصيل الصلاحيات والمهام لكل مستخدم

### 1. مدير عام المستودعات (WMS General Admin)
* **البريد الإلكتروني:** `wms.admin@example.com`
* **كلمة المرور:** `password123`
* **نوع الحساب:** `admin`
* **الأدوار الممنوحة:** `super_admin` و `warehouse-manager`

#### الصلاحيات الممنوحة (Permissions):
يملك صلاحيات كاملة وشاملة لجميع عمليات الـ WMS ولوحة التحكم:
1. **المستودعات (Warehouses):**
   - استعراض، إنشاء، تعديل، تفعيل، تعطيل، وحذف المستودعات.
   - تعيين المستودع الافتراضي للنظام.
   - إدارة موظفي المستودع (عرض، تعيين، عزل موظفين بشكل فردي أو جماعي).
2. **المواقع والرفوف (Locations & Bins):**
   - إنشاء وإدارة وتعديل مواقع التخزين والالتقاط والاستلام والتعبئة.
   - فحص نسبة إشغال المواقع (`check-occupancy`).
   - تفريغ المنتجات ونقلها بين المواقع.
3. **أوامر التجهيز (Fulfillments):**
   - إنشاء وإلغاء وتعيين أوامر التجهيز.
   - التجاوز الإداري للقيود (`fulfillment-override`).
   - إصدار مهام الالتقاط والتغليف يدوياً أو آلياً.
4. **الدفعات والموجات (Batches & Waves):**
   - إنشاء دفعات الالتقاط وتعيينها لعمال الالتقاط.
   - مراقبة مؤشرات التقدم الحية وتأكيد الالتقاط الإجمالي للمواقع.
   - استئناف أو إعادة محاولة أو إلغاء الدفعات.
5. **التغليف والطرود (Packing & Packaging):**
   - إدارة وتعيين محطات التغليف (Packing Stations).
   - متابعة وإلغاء وإعادة تعيين مهام التغليف.
   - إدارة الطرود (إنشاء، إغلاق، إلغاء الطرود غير الصالحة).
6. **الشحن واللوجستيات (Shipments):**
   - استعراض وإنشاء بوالص الشحن فردياً أو بالجملة.
   - تسليم الشحنات لشركات الشحن (Dispatch) وتسجيل التسليم (Deliver).
7. **المخزون والباركود (Inventory & Barcodes):**
   - تسوية المخزون والاستلام والتحويل بين الخانات.
   - فحص أي باركود بالنظام وسحب تقارير الحركات.

---

### 2. مسؤول الالتقاط (Mostafa Picker)
* **البريد الإلكتروني:** `wms.picker@example.com`
* **كلمة المرور:** `password123`
* **نوع الحساب:** `admin`
* **الدور الممنوح:** `picker`

#### الصلاحيات الممنوحة (Permissions):
* **نطاق المستودع:** `view-warehouse` و `warehouses.show`, `view-location` و `locations.show`, `view-fulfillment`, `barcode.scan`.
* **صلاحيات الالتقاط التشغيلية:** `picking-execute`, `picking.claim`, `picking.complete`, `batch.operate`.
* **صلاحيات مسارات الـ API (Route Permissions):**
  - `picking-tasks.index` (استعراض قائمة مهام الالتقاط).
  - `picking-tasks.show` (استعراض تفاصيل مهمة التقاط).
  - `picking-tasks.claim` (حجز مهمة الالتقاط).
  - `picking-tasks.release` (إلغاء حجز المهمة).
  - `picking-tasks.confirm` (تأكيد التقاط صنف).
  - `picking-tasks.record-pick` (تسجيل كمية ملتقطة).
  - `picking-tasks.skip` (تخطي المهمة لتعذر الالتقاط).
  - `picking-tasks.reallocate` (إعادة توجيه المهمة لموقع آخر).
  - `batch.index` (استعراض دفعات الالتقاط).
  - `batch.show` (استعراض تفاصيل الدفعة).
  - `batch.next-task` (سحب المهمة التالية بالدفعة).
  - `batch.start` (بدء تنفيذ الدفعة).
  - `batch.refresh-progress` (تحديث نسبة إنجاز الدفعة).
  - `batch.complete` (إنهاء الدفعة).
  - `batch.confirm-location` (تأكيد الوصول لرف الدفعة).

---

### 3. مسؤول التغليف والوزن (Khaled Packer)
* **البريد الإلكتروني:** `wms.packer@example.com`
* **كلمة المرور:** `password123`
* **نوع الحساب:** `admin`
* **الدور الممنوح:** `packer`

#### الصلاحيات الممنوحة (Permissions):
* **نطاق المستودع:** `view-warehouse` و `warehouses.show`, `view-location` و `locations.show`, `view-fulfillment`, `barcode.scan`.
* **صلاحيات التغليف التشغيلية:** `packing-execute`, `packing.complete`, `manage-fulfillment` (لإبطال كرتونة مفتوحة عند الحاجة).
* **صلاحيات مسارات الـ API (Route Permissions):**
  - `packing-tasks.index` (استعراض قائمة مهام التغليف).
  - `packing-tasks.show` (استعراض تفاصيل مهمة التغليف).
  - `packing-tasks.assign` (إسناد المهمة لمحطة التغليف).
  - `packing-tasks.start` (بدء التغليف).
  - `packing-tasks.pack` (تسجيل وزن وأبعاد الكرتونة).
  - `packing-tasks.verify` (تأكيد محتويات الكرتونة).
  - `packing-tasks.cancel` (إلغاء المهمة مع ذكر السبب).
  - `packing-tasks.stations` (استعراض محطات التغليف).
  - `packing-stations.index` & `packing-stations.show` (استعراض محطات التغليف).
  - `packages.index` & `packages.show` (استعراض الطرود والكراتين).
  - `packages.create` (فتح كرتونة جديدة).
  - `packages.add-item` (إضافة أصناف داخل الكرتونة).
  - `packages.seal` (تشميع الكرتونة وتوليد باركود الشحن).
  - `packages.void` (إبطال كرتونة مفتوحة لتصحيح التعبئة).

---

### 4. مشرف المستودع (WMS Supervisor)
* **البريد الإلكتروني:** `wms.supervisor@example.com`
* **كلمة المرور:** `password123`
* **نوع الحساب:** `admin`
* **الدور الممنوح:** `warehouse-supervisor`

#### الصلاحيات الممنوحة (Permissions):
* يجمع صلاحيات الالتقاط والتغليف كاملة (`picker` + `packer`).
* إدارة الدفعات (`batch.create`, `batch.assign`, `batch.cancel`, `batch.retry`, `batch.pending-fulfillments`).
* إدارة أوامر التجهيز (`fulfillments.index`, `fulfillments.show`, `fulfillments.assign`, `fulfillments.create-tasks`, `fulfillments.complete-picking`, `fulfillments.create-packing-task`, `fulfillments.create-packing-tasks`).
* إدارة محطات التغليف (`packing-stations.create`, `packing-stations.update`, `packing-stations.delete`).
* الصلاحيات الإشرافية (`fulfillment-override`, `fulfillment.create`, `fulfillment.cancel`, `manage-warehouse`, `manage-location`, `manage-fulfillment`).

---

### 5. عميل المتجر التجريبي (Customer Demo)
* **البريد الإلكتروني:** `wms.customer@example.com`
* **كلمة المرور:** `password123`
* **نوع الحساب:** `user`
* **الدور الممنوح:** `customer`

---

## 3. نقاط فحص الـ API للمصادقة وتجربة الحسابات

### أ) تسجيل الدخول (Authentication)
* **المسار:** `POST /api/v1/admin-login` (أو `POST /api/v1/token`)
* **الـ Body:**
```json
{
  "email": "wms.packer@example.com",
  "password": "password123"
}
```
* **الـ Response:**
```json
{
  "status": 200,
  "message": "تم تسجيل دخول الأدمن بنجاح",
  "success": true,
  "data": {
    "token": "1|xxxxxxxxxxxxxxxxxxxxxxxx",
    "permissions": [...],
    "role": ["packer"]
  }
}
```

### ب) تجربة المسارات الأساسية مع كل مستخدم

| الرابط (Endpoint) | الطريقة (Method) | المستخدم المناسب | الوصف |
| :--- | :--- | :--- | :--- |
| `/api/v1/admin/warehouses/dashboard` | `GET` | `wms.admin` | لوحة التحكم والمؤشرات الإحصائية العامة |
| `/api/v1/admin/warehouses/permissions` | `GET` | الجميع | استعراض قائمة الصلاحيات الخاصة بالمستخدم المسجل |
| `/api/v1/admin/picking-tasks` | `GET` | `wms.picker` / `wms.supervisor` / `wms.admin` | مهام الالتقاط المعلقة والجاري تنفيذها |
| `/api/v1/admin/packing-tasks` | `GET` | `wms.packer` / `wms.supervisor` / `wms.admin` | مهام التغليف المفتوحة والجاهزة للفحص والوزن |
| `/api/v1/admin/packages?fulfillment_id={id}` | `GET` | `wms.packer` / `wms.supervisor` / `wms.admin` | طرود أمر التجهيز ومحتوياتها |
| `/api/v1/admin/barcode/scan` | `POST` | `wms.picker` / `wms.packer` | فحص باركود الرف أو المنتج (إرسال `{"code": "LOC-PICK-01"}`) |

