<?php

namespace Database\Seeders;

use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Database\Seeder;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as MarvelPermission;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

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
            'create-tags',
            'update-tags',
            'delete-tags',
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
            'view-flash-sale',
            'create-flash-sale',
            'update-flash-sale',
            'delete-flash-sale',
            'view-attributes',
            'view-promotion',
            'delete-promotion',
            'create-promotion',
            'update-promotion',
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
            'correct-invoice',
            'cancel-invoice',
            'issue-debit-note',
            'view-all-shipments',
            'view-shipments',
            'view-shipment',
            'create-shipment',
            'update-shipment',
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

            'view-city',
            'create-city',
            'update-city',
            'delete-city',

            'view-governorate',
            'create-governorate',
            'update-governorate',
            'delete-governorate',
            'manage-shipping-prices',

            // 💳 Payments
            'payments.mark_paid',
            'payments.refund',
            'payments.verify',
            'payments.reconcile',

            // 👤 Customer
            'view-profile',
            'update-profile',
            'change-password',
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
            'delete-all-products',
            'create-attribute',
            'update-attribute',
            'delete-attribute',
            'update-order-status',
            'answer-question',
            'create-author',
            'update-author',
            'delete-author',
            'create-manufacturer',
            'update-manufacturer',
            'delete-manufacturer',
            'update-contact',
            'delete-contact',
            'delete-read-contacts',
            'view-analytics',
            'export-analytics',
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
            'view-my-shops',
            'add-staff',
            'remove-staff',
            'view-staffs',
            'transfer-shop-ownership',
            'view-withdraws',
            'create-withdraw',
            'create-coupon',
            'delete-coupon',
            'approve-withdraw',
            'approve-shop',
            'disapprove-shop',
            'view-new-shops',

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
            'edit-user',
            'restore-user',
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
            'create-brand',
            'update-brand',
            'delete-brand',
            'create-tag',
            'update-tag',
            'delete-tag',
            'update-settings',
            'delete-review',
            'delete-reviews',
            'approve-reviews',
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
            'assign-role',
            'remove-role',
            'view-banners',
            'create-banners',
            'update-banners',
            'delete-banners',
            'view-slider',
            'create-slider',
            'update-slider',
            'delete-slider',
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

            // 🗂 Content Pages
            'view-content-pages',
            'create-content-pages',
            'update-content-pages',
            'delete-content-pages',

            // 📦 Sections
            'view-sections',
            'create-sections',
            'update-sections',
            'delete-sections',

            // 🏷 Section Types
            'view-section-types',
            'create-section-types',
            'update-section-types',
            'delete-section-types',

            // 📄 Static Pages
            'view-static-pages',
            'update-static-pages',
            'create-static-sections',
            'update-static-sections',
            'delete-static-sections',

            // ⚡ Fast Shipping
            'view-fast-shipping',
            'update-fast-shipping',
            'fast-shipping.view-settings',
            'fast-shipping.update-settings',

            // 📍 Pickup Locations
            'view-pickup-locations',
            'create-pickup-location',
            'update-pickup-location',
            'delete-pickup-location',

            // 🏭 Warehouses Granular Permissions
            'warehouses.index',
            'warehouses.show',
            'warehouses.create',
            'warehouses.update',
            'warehouses.set-default',
            'warehouses.activate',
            'warehouses.deactivate',
            'warehouses.delete',
            'warehouses.users.view',
            'warehouses.users.assign',
            'warehouses.users.remove-bulk',
            'warehouses.users.remove',
            'view-warehouse',
            'manage-warehouse',

            // 📍 Locations Granular Permissions
            'locations.index',
            'locations.show',
            'locations.check-occupancy',
            'locations.create',
            'locations.update',
            'locations.activate',
            'locations.deactivate',
            'locations.delete',
            'locations.empty-products',
            'view-location',
            'manage-location',

            // 🏭 Warehouse Workflow Granular Permissions
            'manage-warehouse-workflow',
            'warehouse-workflow.show',
            'warehouse-workflow.history',
            'warehouse-workflow.publish',

            // 📋 Fulfillments Granular Permissions
            'fulfillments.index',
            'fulfillments.show',
            'fulfillments.assign',
            'fulfillments.create-tasks',
            'fulfillments.complete-picking',
            'fulfillment-items.assign-placement',
            'fulfillments.create-packing-tasks',
            'fulfillments.create-packing-task',
            'view-fulfillment',
            'manage-fulfillment',
            'fulfillment-override',
            'fulfillment.create',
            'fulfillment.cancel',

            // ⛏️ Picking Tasks Granular Permissions
            'picking-tasks.index',
            'picking-tasks.show',
            'picking-tasks.claim',
            'picking-tasks.release',
            'picking-tasks.confirm',
            'picking-tasks.record-pick',
            'picking-tasks.skip',
            'picking-tasks.reallocate',
            'picking-execute',
            'picking.claim',
            'picking.complete',

            // 📦 Batches Granular Permissions
            'batch.index',
            'batch.show',
            'batch.pending-fulfillments',
            'batch.next-task',
            'batch.create',
            'batch.assign',
            'batch.start',
            'batch.refresh-progress',
            'batch.complete',
            'batch.confirm-location',
            'batch.cancel',
            'batch.retry',
            'batch.manage',
            'batch.operate',

            // 📦 Packing Tasks Granular Permissions
            'packing-tasks.index',
            'packing-tasks.show',
            'packing-tasks.assign',
            'packing-tasks.start',
            'packing-tasks.pack',
            'packing-tasks.verify',
            'packing-tasks.cancel',
            'packing-tasks.stations',
            'packing-execute',
            'packing.complete',

            // 🏷️ Packing Stations Granular Permissions
            'packing-stations.index',
            'packing-stations.show',
            'packing-stations.create',
            'packing-stations.update',
            'packing-stations.delete',

            // 📦 Packages Granular Permissions
            'packages.index',
            'packages.show',
            'packages.create',
            'packages.add-item',
            'packages.seal',
            'packages.void',

            // 📷 Barcode Scanning
            'barcode.scan',

            // 🚚 WMS Shipments Granular Permissions
            'wms-shipments.index',
            'wms-shipments.show',
            'wms-shipments.create',
            'wms-shipments.bulk-create',
            'wms-shipments.bulk-dispatch',
            'wms-shipments.bulk-deliver',
            'wms-shipments.dispatch',
            'wms-shipments.deliver',
            'wms-shipments.cancel',

            // 📊 Inventory Operations Granular Permissions
            'inventory.receive',
            'inventory.transfer',
            'inventory.product-placements',
            'inventory.overview',
            'inventory.operations.index',
            'inventory.operations.show',
            'inventory-adjust',

            // 🛑 Cancellation during fulfillment
            'order.cancel-during-fulfillment',

            // 📦 Admin Order Shipments Granular Permissions
            'orders.shipment.show',
            'orders.shipment.update-status',

            // 🌊 Order Statuses & Flows Granular Permissions
            'order-statuses.index',
            'order-statuses.show',
            'order-statuses.update',
            'order-flows.index',
            'order-flows.create',
            'order-flows.show',
            'order-flows.update',
            'order-flows.inputs.index',
            'order-flows.inputs.create',
            'order-flow-inputs.update',
            'order-flow-inputs.delete',

            // 💸 Admin Refunds Granular Permissions
            'admin-refunds.index',
            'admin-refunds.show',
            'admin-refunds.approve',
            'admin-refunds.reject',

            // 🎟️ Coupons Configuration & Admin Granular Permissions
            'coupons.validate-config',
            'coupons.usage-info',
            'coupons.suggest-fix',
            'coupons.targeting.show',
            'coupons.targeting.update',
            'coupons.targeting.delete',
            'coupons.distribute',
            'coupons.distributions.index',
            'coupons.distributions.show',

            // 👥 Admin Users Granular Permissions
            'admin-users.create',
            'admin-users.update-activation',
            'admin-users.delete',
            'admin-users.restore',
            'admin-users.view-trashed',
            'admin-users.delete-forever',
            'users.ban',
            'users.unblock',

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

        // Ensure granular order status permissions exist and are merged
        $granularPermissionNames = $this->ensureTargetStatusPermissions();

        // Creation set = union of master array, role arrays, Enum values, and dynamic statuses.
        // Guarantees every single system permission has a persistent database record.
        $allPermissionNames = array_unique(array_merge(
            $permissions,
            $customerPermission ?? [],
            $staffAndOnwner ?? [],
            $onwnerPermission ?? [],
            $editorPermission ?? [],
            $superAdminPermission ?? [],
            $viewPermission ?? [],
            $granularPermissionNames ?? [],
            MarvelPermission::getValues()
        ));

        foreach ($allPermissionNames as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }

        // Roles Setup
        $roleSuperAdmin = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Super Admin', 'ar' => 'مدير_النظام']],
        );
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

        // Seed dedicated WMS roles
        $this->seedWarehouseRoles();

        // 🌟 CRITICAL ENTERPRISE FIX:
        // Super Admin MUST receive 100% of all permissions with guard `api` in the database.
        // This ensures super_admin never loses any permission on re-seed, regardless of when
        // or where a permission was defined.
        $allApiPermissions = Permission::where('guard_name', 'api')->get();
        $roleSuperAdmin->syncPermissions($allApiPermissions);

        // Staff maintains its baseline + all granular target status transition permissions
        $roleStaff->syncPermissions(array_merge(
            $staffAndOnwner,
            $granularPermissionNames
        ));

        // Owner, Customer, Editor retain their grants + preserve existing granular statuses
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

        // Sync all existing Super Admin users with all system permissions
        try {
            $superAdmins = User::role('super_admin')->get();
            foreach ($superAdmins as $superAdmin) {
                $superAdmin->syncPermissions($allApiPermissions);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Demo super admin user (admin@demo.com)
        $adminUser = User::where('email', 'admin@demo.com')->first();
        if (! $adminUser && ! app()->isProduction()) {
            $adminUser = User::create([
                'name' => 'Shop Owner',
                'email' => 'admin@demo.com',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'is_active' => true,
                'phone_number' => '34567890',
                'email_verified_at' => now(),
                'type' => 'admin',
            ]);
        }

        if ($adminUser) {
            if (! $adminUser->hasRole('super_admin')) {
                $adminUser->assignRole('super_admin');
            }
            $adminUser->syncPermissions($allApiPermissions);
        }

        // Bust Spatie's permission cache so newly created/synced rows take effect immediately
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Granular `change-order-status.*` preservation for super_admin and staff.
     *
     * Ensures all catalog status codes and legacy slugs have explicit permission rows.
     *
     * @return array<int, string>
     */
    private function ensureTargetStatusPermissions(): array
    {
        $names = [];

        // 1. Sync through canonical OrderFlowService if available
        try {
            if (class_exists(OrderFlowService::class)) {
                $names = (array) app(OrderFlowService::class)->syncTargetStatusPermissions();
            }
        } catch (\Throwable $e) {
            report($e);
            $names = [];
        }

        // 2. Ensure default catalog status codes always exist
        $defaultStatusCodes = [
            'pending', 'processing', 'packed', 'shipped', 'in_transit',
            'arrived_at_destination_country', 'customs_clearance', 'customs_hold',
            'customs_cleared', 'export_processing', 'import_processing', 'local_carrier',
            'out_for_delivery', 'delivered', 'failed_delivery', 'returned', 'completed',
            'cancelled', 'confirmed', 'ready_to_ship', 'ready_for_pickup', 'picked_up',
            'order-pending', 'order-processing', 'order-completed', 'order-cancelled',
            'order-refunded', 'order-failed', 'order-at-local-facility', 'order-out-for-delivery',
        ];

        foreach ($defaultStatusCodes as $code) {
            $permName = "change-order-status.{$code}";
            try {
                Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'api']);
                $names[] = $permName;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // 3. Merge any additional status permissions present in the database
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
     * Warehouse staff roles and comprehensive WMS permission assignment.
     */
    private function seedWarehouseRoles(): array
    {
        $allWmsPermissions = [
            // General Warehouse & Locations
            'view-warehouse',
            'manage-warehouse',
            'view-location',
            'manage-location',

            // Fulfillments
            'view-fulfillment',
            'manage-fulfillment',
            'fulfillment-override',
            'fulfillment.create',
            'fulfillment.cancel',

            // Operations & Inventory
            'picking-execute',
            'packing-execute',
            'inventory-adjust',
            'picking.claim',
            'picking.complete',
            'packing.complete',
            'batch.manage',
            'batch.operate',
            'order.cancel-during-fulfillment',
            'barcode.scan',

            // Warehouse Workflow
            'manage-warehouse-workflow',
            'warehouse-workflow.show',
            'warehouse-workflow.history',
            'warehouse-workflow.publish',

            // Granular Warehouses
            'warehouses.index',
            'warehouses.show',
            'warehouses.create',
            'warehouses.update',
            'warehouses.set-default',
            'warehouses.activate',
            'warehouses.deactivate',
            'warehouses.delete',
            'warehouses.users.view',
            'warehouses.users.assign',
            'warehouses.users.remove-bulk',
            'warehouses.users.remove',

            // Granular Locations
            'locations.index',
            'locations.show',
            'locations.check-occupancy',
            'locations.create',
            'locations.update',
            'locations.activate',
            'locations.deactivate',
            'locations.delete',
            'locations.empty-products',

            // Granular Fulfillments
            'fulfillments.index',
            'fulfillments.show',
            'fulfillments.assign',
            'fulfillments.create-tasks',
            'fulfillments.complete-picking',
            'fulfillment-items.assign-placement',
            'fulfillments.create-packing-tasks',
            'fulfillments.create-packing-task',

            // Granular Picking Tasks
            'picking-tasks.index',
            'picking-tasks.show',
            'picking-tasks.claim',
            'picking-tasks.release',
            'picking-tasks.confirm',
            'picking-tasks.record-pick',
            'picking-tasks.skip',
            'picking-tasks.reallocate',

            // Granular Packing Tasks
            'packing-tasks.index',
            'packing-tasks.show',
            'packing-tasks.assign',
            'packing-tasks.start',
            'packing-tasks.pack',
            'packing-tasks.verify',
            'packing-tasks.cancel',
            'packing-tasks.stations',

            // Granular Packing Stations
            'packing-stations.index',
            'packing-stations.show',
            'packing-stations.create',
            'packing-stations.update',
            'packing-stations.delete',

            // Granular Packages
            'packages.index',
            'packages.show',
            'packages.create',
            'packages.add-item',
            'packages.seal',
            'packages.void',

            // Granular WMS Shipments
            'wms-shipments.index',
            'wms-shipments.show',
            'wms-shipments.create',
            'wms-shipments.bulk-create',
            'wms-shipments.bulk-dispatch',
            'wms-shipments.bulk-deliver',
            'wms-shipments.dispatch',
            'wms-shipments.deliver',
            'wms-shipments.cancel',

            // Granular Inventory Operations
            'inventory.receive',
            'inventory.transfer',
            'inventory.product-placements',
            'inventory.overview',
            'inventory.operations.index',
            'inventory.operations.show',

            // Granular Batches
            'batch.index',
            'batch.show',
            'batch.pending-fulfillments',
            'batch.next-task',
            'batch.create',
            'batch.assign',
            'batch.start',
            'batch.refresh-progress',
            'batch.complete',
            'batch.confirm-location',
            'batch.cancel',
            'batch.retry',
        ];

        foreach ($allWmsPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $picker = Role::firstOrCreate(
            ['name' => 'picker', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Picker', 'ar' => 'عامل التقاط']]
        );
        $picker->syncPermissions([
            'view-warehouse',
            'view-location',
            'view-fulfillment',
            'picking-execute',
            'picking.claim',
            'picking.complete',
            'barcode.scan',
            'warehouses.show',
            'locations.show',
        ]);

        $packer = Role::firstOrCreate(
            ['name' => 'packer', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Packer', 'ar' => 'عامل تغليف']]
        );
        $packer->syncPermissions([
            'view-warehouse',
            'view-location',
            'view-fulfillment',
            'packing-execute',
            'packing.complete',
            'barcode.scan',
            'warehouses.show',
            'locations.show',
        ]);

        $supervisor = Role::firstOrCreate(
            ['name' => 'warehouse-supervisor', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Warehouse Supervisor', 'ar' => 'مشرف مستودع']]
        );
        $supervisor->syncPermissions([
            'view-warehouse',
            'view-location',
            'view-fulfillment',
            'manage-fulfillment',
            'picking-execute',
            'packing-execute',
            'fulfillment-override',
            'fulfillment.create',
            'fulfillment.cancel',
            'picking.claim',
            'picking.complete',
            'packing.complete',
            'batch.manage',
            'batch.operate',
            'order.cancel-during-fulfillment',
            'barcode.scan',
            'warehouses.show',
            'locations.show',
        ]);

        $manager = Role::firstOrCreate(
            ['name' => 'warehouse-manager', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Warehouse Manager', 'ar' => 'مدير مستودع']]
        );
        $manager->syncPermissions($allWmsPermissions);

        return $allWmsPermissions;
    }
}
