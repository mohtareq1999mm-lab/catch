<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Role;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */

    public function run()
    {
        $permissions = [

            // 🔓 Public (view only)
            'view-products',
            'view-product',
            'view-categories',
            'view-category',
            'view-shops',
            'view-shop',
            'view-types',
            'view-type',
            'view-tags',
            'view-tag',
            'view-authors',
            'view-author',
            'view-manufacturers',
            'view-manufacturer',
            'view-brands',
            'view-brand',
            'view-contacts',
            'view-coupons',
            'verify-coupon',
            'view-cms-pages',
            'view-cms-page',
            'view-faqs',
            'view-settings',
            "view-flash-sale",
            'create-flash-sale',
            'update-flash-sale',
            'delete-flash-sale',
            "view-attributes",
            "view-promotion",
            "delete-promotion",
            "create-promotion",
            "update-promotion",
            'view-country',
            'create-country',
            'update-country',
            'delete-country',
            'view-order',
            'view-orders',
            'view-invoices',
            'view-invoice',
            'view-invoice-download',
            'regenerate-invoice',
            'cancel-invoice',
            'issue-debit-note',
            'view-shipments',
            'view-shipment',
            'create-shipment',
            'update-shipment',
            // 🌊 Order Flows (definitions + dynamic inputs; transitions stay
            // on update-order-status; super_admin receives all via sync below)
            'view-order-flows',
            'create-order-flows',
            'update-order-flows',
            'manage-order-flow-inputs',
            'view-coupon-assignments',
            'create-coupon-assignment',
            'update-coupon-assignment',
            'delete-coupon-assignment',
            'update-user-activation',
            'view-activity-log',
            'view-notifications',
            'manage-notifications',

             'view-tags',
            'create-tags',
            'update-tags',
            'delete-tags',
            'view-city',
            'create-city',
            'update-city',
            'delete-city',

            'view-governorate',
            'create-governorate',
            'update-governorate',
            'delete-governorate',
            'manage-shipping-prices',

            // 💳 Payments (F-1 hardening: financial actions are distinct from
            // generic order-status updates; run this seeder after deploy)
            'payments.mark_paid',
            'payments.refund',
            'payments.verify',
            'payments.reconcile',

            // 👤 Customer
            'view-profile',
            'update-profile',
            'change-password',
            'view-orders',
            'view-order',
            'create-order',
            'track-order',
            'create-review',
            'update-review',
            'view-wishlist',
            'add-wishlist',
            'remove-wishlist',
            'toggle-wishlist',
            'view-refunds',
            'create-refund',
            'view-conversations',
            'create-conversation',
            'ask-question',
            'view-my-questions',
            'follow-shop',
            'view-followed-shops',

            // 👷 Staff / Store Owner
            'create-product',
            'update-product',
            'manage-digital-licenses',
            'manage-digital-access',
            'delete-product',
            'create-attribute',
            'update-attribute',
            'delete-attribute',
            'update-order-status',
            // F-1: financial payment actions (mark-paid, refund, verify,
            // reconcile) are granted alongside update-order-status.
            'payments.mark_paid',
            'payments.refund',
            'payments.verify',
            'payments.reconcile',
            'answer-question',
            'create-author',
            'create-manufacturer',
            'update-contact',
            'delete-contact',
            'delete-read-contacts',
            'view-analytics',
            'view-low-stock-products',
            'view-draft-products',
            'update-coupon',
            'create-store-notice',
            'update-store-notice',
            'delete-store-notice',
            'create-faq',
            'update-faq',
            'delete-faq',

            // 🏪 Store Owner
            'create-shop',
            'update-shop',
            'delete-shop',
            'manage-digital-licenses',
            'manage-digital-access',
            'view-my-shops',
            'add-staff',
            'remove-staff',
            'view-staffs',
            'transfer-shop-ownership',
            'view-withdraws',
            'create-withdraw',
            'create-coupon',
            'delete-coupon',

            // ✏️ Editor
            'create-cms-page',
            'update-cms-page',
            'delete-cms-page',
            'save-puck-page',

            // 🔐 Super Admin
            'view-admins',
            'view-vendors',
            'view-customers',
            'view-users',
            'create-user',
            'update-user',
            'delete-user',
            'ban-user',
            'activate-user',
            'make-admin',
            'create-type',
            'update-type',
            'delete-type',
            'create-category',
            'update-category',
            'delete-category',
            'import-category',
            'export-category',
            'import-brand',
            'export-brand',
            'import-product',
            'export-product',
            'delete-all-products',
            'export-analytics',
            'create-brand',
            'update-brand',
            'delete-brand',
            'create-tag',
            'update-tag',
            'delete-tag',
            'update-author',
            'delete-author',
            'update-manufacturer',
            'delete-manufacturer',
            'approve-withdraw',
            'approve-shop',
            'disapprove-shop',
            'view-new-shops',
            'update-settings',
            'delete-review',
            'delete-question',
            'create-shipping',
            'add-points',
            'approve-coupon',
            'disapprove-coupon',
            'view-abusive-reports',
            'accept-abusive-report',
            'reject-abusive-report',
            'view-roles',
            'view-role',
            'create-roles',
            'update-roles',
            'delete-roles',
            'view-banners',
            'create-banners',
            'update-banners',
            'delete-banners',
            'view-slider',
            "create-slider",
            "update-slider",
            "delete-slider",
            'assign-role',
            'remove-role',
            'edit-user',
            'approve-reviews',
            'delete-reviews',
            'create-review',
            'update-review',
            'restore-user',
            'view-site-reviews',
            'approve-site-reviews',
            'reject-site-reviews',

            // 💱 Currencies
            'view-currencies',
            'create-currency',
            'update-currency',
            'delete-currency',
            'view-exchange-rates',
            'create-exchange-rate',
            'update-exchange-rate',
            'delete-exchange-rate',
            'set-base-currency',
            'set-catalog-currency',

            //content-pages
            'view-content-pages',
            'create-content-pages',
            'update-content-pages',
            'delete-content-pages',

            //sections
            'view-sections',
            'create-sections',
            'update-sections',
            'delete-sections',

            //section-types
            'view-section-types',
            'create-section-types',
            'update-section-types',
            'delete-section-types',

            //static-pages
            'view-static-pages',
            'update-static-pages',
            'create-static-sections',
            'update-static-sections',
            'delete-static-sections',

            //fast-shipping
            'view-fast-shipping',
            'update-fast-shipping',

            // 📍 Pickup Locations
            'view-pickup-locations',
            'create-pickup-location',
            'update-pickup-location',
            'delete-pickup-location',

            // 🧩 Legacy role-as-permission compatibility (verified in code audit
            // 2026-10-13: hasPermissionTo/User::permission('store_owner'|'staff'|
            // 'customer'|'editor') and InstallCommand/AdminCreateCommand rely on
            // these rows existing with guard `api`. They are permissions, NOT
            // roles — `super_admin` stays a role only and is intentionally NOT
            // created here (see 2026_09_09 remove_super_admin_permission).)
            'editor',
            'store_owner',
            'staff',
            'customer',
        ];

        $customerPermission = [
            // 👤 Customer
            // 2026-10-13 M1: `customer` legacy permission row must be granted
            // to the customer role itself — User::permission('customer') and
            // InstallCommand parity rely on it (role≠permission in Spatie).
            'customer',
            'view-profile',
            'update-profile',
            'change-password',
            'view-orders',
            'view-order',
            'create-order',
            'track-order',
            'create-review',
            'update-review',
            'view-wishlist',
            'add-wishlist',
            'remove-wishlist',
            'toggle-wishlist',
            'view-refunds',
            'create-refund',
            'view-conversations',
            'create-conversation',
            'ask-question',
            'view-my-questions',
            'follow-shop',
            'view-followed-shops',
            'view-banners',
        ];

        $staffAndOnwner = [
            // 👷 Staff / Store Owner
            // 2026-10-13 M1: `staff` legacy permission must be held by the
            // staff role — BaseRepository/helpers gate on
            // hasPermissionTo('staff') (permission, not hasRole).
            'staff',
            'create-product',
            'update-product',
            'manage-digital-licenses',
            'manage-digital-access',
            'delete-product',
            'create-attribute',
            'update-attribute',
            'delete-attribute',
            'update-order-status',
            // F-1: financial payment actions (mark-paid, refund, verify,
            // reconcile) are granted alongside update-order-status.
            'payments.mark_paid',
            'payments.refund',
            'payments.verify',
            'payments.reconcile',
            'answer-question',
            'create-author',
            'create-manufacturer',
            'view-analytics',
            'view-low-stock-products',
            'view-draft-products',
            'update-coupon',
            'create-store-notice',
            'update-store-notice',
            'delete-store-notice',
            'create-faq',
            'update-faq',
            'delete-faq',
            'view-invoices',
            'view-invoice',
            'view-invoice-download',
            'regenerate-invoice',
            'correct-invoice',
            'cancel-invoice',
            'issue-debit-note',
            'view-shipments',
            'view-shipment',
            'create-shipment',
            'update-shipment',
            'view-coupon-assignments',
            'create-coupon-assignment',
            'update-coupon-assignment',
            'delete-coupon-assignment',
];

        $onwnerPermission = [
            // 🏪 Store Owner
            // 2026-10-13 M1: `store_owner` legacy permission must be held by
            // the seeded `owner` role — the runtime role is `owner` (D9-3) but
            // legacy gates check hasPermissionTo('store_owner') /
            // User::permission('store_owner') (StoreNoticeRepository,
            // BaseRepository, helpers). Without this, owner users fail those
            // gates and vendor broadcasts resolve empty.
            'store_owner',
            'create-shop',
            'update-shop',
            'delete-shop',
            'manage-digital-licenses',
            'manage-digital-access',
            'view-my-shops',
            'add-staff',
            'remove-staff',
            'view-staffs',
            'transfer-shop-ownership',
            'view-withdraws',
            'create-withdraw',
            'create-coupon',
            'delete-coupon',
        ];


        $editorPermission = [
            // ✏️ Editor
            // 2026-10-13 M1: `editor` legacy permission must be held by the
            // editor role — CmsPageController gates on
            // hasPermissionTo('editor') (permission, not hasRole).
            'editor',
            'create-cms-page',
            'update-cms-page',
            'delete-cms-page',
            'save-puck-page',
            'view-content-pages',
            'create-content-pages',
            'update-content-pages',
            'delete-content-pages',
            'view-sections',
            'create-sections',
            'update-sections',
            'delete-sections',
            'view-section-types',
            'create-section-types',
            'update-section-types',
            'delete-section-types',
            'view-static-pages',
            'update-static-pages',
            'create-static-sections',
            'update-static-sections',
            'delete-static-sections',
        ];

        $superAdminPermission = [
            // 🔐 Super Admin
            'view-admins',
            'manage-digital-licenses',
            'manage-digital-access',
            'view-role',
            'view-vendors',
            'view-customers',
            'view-users',
            'create-user',
            'update-user',
            'delete-user',
            'ban-user',
            'activate-user',
            'make-admin',
            'create-type',
            'update-type',
            'delete-type',
            'create-category',
            'update-category',
            'delete-category',
            'import-category',
            'export-category',
            'import-brand',
            'export-brand',
            'import-product',
            'export-product',
            'delete-all-products',
            'export-analytics',
            'create-brand',
            'update-brand',
            'delete-brand',
            'create-tag',
            'update-tag',
            'delete-tag',
            'update-author',
            'delete-author',
            'update-manufacturer',
            'delete-manufacturer',
            'approve-withdraw',
            'approve-shop',
            'disapprove-shop',
            'view-new-shops',
            'update-settings',
            'delete-review',
            'delete-question',
            'create-shipping',
            'add-points',
            'approve-coupon',
            'disapprove-coupon',
            'view-abusive-reports',
            'accept-abusive-report',
            'reject-abusive-report',
            'view-site-reviews',
            'approve-site-reviews',
            'reject-site-reviews',
            'view-currencies',
            'create-currency',
            'update-currency',
            'delete-currency',
            'view-exchange-rates',
            'create-exchange-rate',
            'update-exchange-rate',
            'delete-exchange-rate',
            'set-base-currency',
            'set-catalog-currency',
        ];

        $viewPermission = [
            'view-products',
            'view-product',
            'view-categories',
            'view-category',
            'view-shops',
            'view-shop',
            'view-types',
            'view-type',
            'view-tags',
            'view-tag',
            'view-authors',
            'view-author',
            'view-manufacturers',
            'view-manufacturer',
            'view-brands',
            'view-brand',
            'view-contacts',
            'view-contact',
            'view-coupons',
            'verify-coupon',
            'view-cms-pages',
            'view-cms-page',
            'view-faqs',
            'view-settings',
        ];

        // Creation set = union of the master list AND every role array.
        // Role syncs below pass plain string names (Spatie's syncPermissions
        // resolves them via findByName and throws PermissionDoesNotExist for
        // any name without a row — e.g. `correct-invoice`, which is only
        // referenced by a role array). Building the union here guarantees
        // every referenced permission exists, no matter which list it came
        // from. firstOrCreate keeps this idempotent across deploys.
        $allPermissionNames = array_unique(array_merge(
            $permissions,
            $customerPermission ?? [],
            $staffAndOnwner ?? [],
            $onwnerPermission ?? [],
            $editorPermission ?? [],
            $superAdminPermission ?? [],
            $viewPermission ?? []
        ));

        $permissionsData = [];
        foreach ($allPermissionNames as $permission) {
            $permissionsData[] = Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }

        // P9-7a root cause: lookups MUST be name+guard only. display_name is
        // translatable JSON whose encoding (escaped vs raw UTF-8) differs
        // across runtimes, so including it in the lookup silently misses and
        // duplicates roles on reseed. (WMS roles below already use this safe
        // pattern, which is why they never drifted.)
        $roleSuperAdmin = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Super Admin', 'ar' => 'مدير_النظام']],
        );
        // D9-3: the seeded runtime role is `owner`. The Marvel
        // `Role::STORE_OWNER` ('store_owner') constant is legacy vocabulary
        // (GraphQL abilities, older docs/tests) and is intentionally NOT
        // renamed or aliased here: no `store_owner` role is seeded and no
        // runtime code references it. Renaming either side would break the
        // other without a migration + client audit.
        $roleOwner = Role::firstOrCreate(
            ['name' => 'owner', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Owner', 'ar' => 'مالك']],
        );
        $roleStaff = Role::firstOrCreate(
            ['name' => 'staff', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Staff', 'ar' => 'موظف']],
        );
        $roleCustomer = Role::firstOrCreate(
            ['name' => 'customer', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Customer', 'ar' => 'عميل']],
        );
        $roleEditor = Role::firstOrCreate(
            ['name' => 'editor', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Editor', 'ar' => 'محرر']],
        );

        // D9-14: super_admin is synced AFTER seedWarehouseRoles() so the
        // fresh-seed grant includes the WMS permissions. Single sync (not
        // one before + one after) keeps reseed deterministic. Like every
        // other role sync in this seeder, syncPermissions REPLACES the
        // role's grant set: custom grants outside the seeded set are revoked
        // on reseed by design — custom grants belong on custom roles.
        // 2026-10-13: the granular `change-order-status.*` set (created by
        // OrderFlowService::syncTargetStatusPermissions / OrderStatusPermissionSeeder)
        // is merged here as well. Without this, re-running ONLY this seeder
        // would REVOKE the granular grants from super_admin (sync replaces),
        // breaking PATCH orders/{id}/status for every admin until the
        // OrderStatusPermissionSeeder runs again.
        $wmsPermissionNames = $this->seedWarehouseRoles();
        $granularPermissionNames = $this->ensureTargetStatusPermissions();
        $roleSuperAdmin->syncPermissions(array_merge(
            $permissionsData,
            Permission::whereIn('name', $wmsPermissionNames)
                ->where('guard_name', 'api')
                ->get()
                ->all(),
            Permission::whereIn('name', $granularPermissionNames)
                ->where('guard_name', 'api')
                ->get()
                ->all()
        ));
        // 2026-10-13 M2: staff holds `update-order-status`, so it must keep
        // the full granular set on reseed-only runs (same revoke bug as
        // super_admin — OrderFlowService::userCanTransitionTo requires the
        // granular target permission). Owner/customer/editor do NOT hold the
        // general gate, so they keep only whatever granular rows they already
        // hold (preserve, never wipe manually granted ones, never over-grant).
        $roleStaff->syncPermissions(array_merge(
            $staffAndOnwner,
            $granularPermissionNames
        ));
        $roleOwner->syncPermissions(array_merge(
            $onwnerPermission,
            $this->existingGranularForRole($roleOwner)
        ));
        $roleCustomer->syncPermissions(array_merge(
            $customerPermission,
            $this->existingGranularForRole($roleCustomer)
        ));
        $roleEditor->syncPermissions(array_merge(
            $editorPermission,
            $this->existingGranularForRole($roleEditor)
        ));

        // Bust Spatie's permission cache so newly created rows (e.g.
        // delete-all-products) take effect immediately — otherwise the new
        // row stays invisible until a cache clear and gates keep 403ing.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Granular `change-order-status.*` preservation for super_admin.
     *
     * The granular set is owned by OrderFlowService::syncTargetStatusPermissions()
     * (and bridged additively by OrderStatusPermissionSeeder). This seeder runs
     * BEFORE OrderStatusPermissionSeeder in DatabaseSeeder, so on a fresh seed
     * the rows may not exist yet — and on a reseed-only run this seeder must
     * NOT revoke them. Strategy: best-effort ensure via the service when its
     * tables exist, then always merge any existing `change-order-status.%`
     * rows from the DB. Returns permission names (possibly empty, never throws).
     *
     * @return array<int, string>
     */
    private function ensureTargetStatusPermissions(): array
    {
        $names = [];

        try {
            if (class_exists(\App\Services\OrderFlow\OrderFlowService::class)) {
                $names = (array) app(\App\Services\OrderFlow\OrderFlowService::class)->syncTargetStatusPermissions();
            }
        } catch (\Throwable $e) {
            report($e);
            $names = [];
        }

        try {
            $existing = Permission::query()
                ->where('guard_name', 'api')
                ->where('name', 'like', 'change-order-status.%')
                ->pluck('name')
                ->all();
            $names = array_values(array_unique(array_merge($names, $existing)));
        } catch (\Throwable $e) {
            report($e);
        }

        return $names;
    }

    /**
     * Existing granular `change-order-status.*` rows already held by a role.
     * Used to preserve (never wipe) manually granted granular targets on
     * reseed-only runs for roles that do NOT own the full granular set
     * (owner/customer/editor). Never throws; returns names (possibly empty).
     *
     * @return array<int, string>
     */
    private function existingGranularForRole(mixed $role): array
    {
        try {
            return $role->permissions()
                ->where('guard_name', 'api')
                ->where('permissions.name', 'like', 'change-order-status.%')
                ->pluck('permissions.name')
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Phase 13: warehouse staff roles. Pickers/packers receive execution
     * permissions ONLY — never financial permissions (update-order-status,
     * refunds, payment administration). Supervisor holds override.
     */
    private function seedWarehouseRoles(): array
    {
        $wmsPermissions = [
            'view-warehouse', 'manage-warehouse',
            'view-location', 'manage-location',
            'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'packing-execute',
            'fulfillment-override', 'inventory-adjust',
            // Granular operational permissions (§28 — additive, assignable).
            'fulfillment.create', 'fulfillment.cancel',
            'picking.claim', 'picking.complete',
            'packing.complete',
            'batch.manage', 'batch.operate',
            'order.cancel-during-fulfillment',
        ];
        foreach ($wmsPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $picker = Role::firstOrCreate(
            ['name' => 'picker', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Picker', 'ar' => 'عامل التقاط']]
        );
        $picker->syncPermissions(['view-warehouse', 'view-location', 'view-fulfillment', 'picking-execute', 'picking.claim']);

        $packer = Role::firstOrCreate(
            ['name' => 'packer', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Packer', 'ar' => 'عامل تغليف']]
        );
        $packer->syncPermissions(['view-warehouse', 'view-location', 'view-fulfillment', 'packing-execute']);

        $supervisor = Role::firstOrCreate(
            ['name' => 'warehouse-supervisor', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Warehouse Supervisor', 'ar' => 'مشرف مستودع']]
        );
        $supervisor->syncPermissions([
            'view-warehouse', 'view-location', 'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'packing-execute', 'fulfillment-override',
            'fulfillment.create', 'fulfillment.cancel',
            'picking.claim', 'picking.complete',
            'packing.complete',
            'batch.manage', 'batch.operate',
            'order.cancel-during-fulfillment',
        ]);

        $manager = Role::firstOrCreate(
            ['name' => 'warehouse-manager', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Warehouse Manager', 'ar' => 'مدير مستودع']]
        );
        $manager->syncPermissions($wmsPermissions);

        return $wmsPermissions;
    }
}
