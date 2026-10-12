<?php

namespace Database\Seeders;

use App\Enums\DigitalAssetType;
use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use App\Models\DigitalLicenseKey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\ItemType;
use Marvel\Enums\ProductType;

class DigitalUserTestSeeder extends Seeder
{
    public const TEST_USER_EMAIL = 'test@gmail.com';
    public const TEST_USER_PASSWORD = 'password123';

    public function run(): void
    {
        $this->command->info('===> Seeding 4 SEPARATE Products (One asset per product) for ALL users...');

        // 1. Ensure test@gmail.com exists
        $primaryUser = User::firstOrCreate(
            ['email' => self::TEST_USER_EMAIL],
            [
                'name' => 'Test User',
                'password' => Hash::make(self::TEST_USER_PASSWORD),
                'email_verified_at' => now(),
                'is_active' => true,
                'type' => 'customer',
            ]
        );
        $primaryUser->forceFill([
            'password' => Hash::make(self::TEST_USER_PASSWORD),
            'is_active' => true,
        ])->save();

        $token = $primaryUser->createToken('seeder-test-token')->plainTextToken;

        // 2. Prepare physical dummy file for the FILE asset type on 'private' disk
        $dummyPdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF\n";
        Storage::disk('private')->put('digital-assets/the-complete-developer-guide.pdf', $dummyPdf);

        // Clean up previous bundle product if exists
        Product::where('slug', 'all-in-one-digital-master-bundle')->delete();

        // ------------------------------------------------------------------
        // PRODUCT 1: كتاب إلكتروني فقط (FILE Asset ONLY)
        // ------------------------------------------------------------------
        $bookProduct = Product::firstOrCreate(
            ['slug' => 'developer-guide-ebook-pdf'],
            [
                'name' => [
                    'en' => 'The Complete Developer Guide (E-Book)',
                    'ar' => 'دليل المطور الشامل (كتاب إلكتروني)'
                ],
                'description' => ['en' => 'PDF E-Book downloadable directly via signed URL.'],
                'price' => 25.00,
                'product_type' => ProductType::SIMPLE,
                'item_type' => ItemType::DIGITAL,
                'status' => 1,
                'in_stock' => true,
                'stock_quantity' => 9999,
            ]
        );

        $fileAsset = DigitalAsset::firstOrCreate(
            ['product_id' => $bookProduct->id, 'type' => DigitalAssetType::FILE->value],
            [
                'uuid' => (string) Str::uuid(),
                'disk' => 'private',
                'path' => 'digital-assets/the-complete-developer-guide.pdf',
                'original_name' => 'The Complete Developer Guide.pdf',
                'mime' => 'application/pdf',
                'extension' => 'pdf',
                'size' => strlen($dummyPdf),
                'status' => DigitalAsset::STATUS_ACTIVE,
                'sort_order' => 1,
            ]
        );

        // ------------------------------------------------------------------
        // PRODUCT 2: دورة / عنوان خارجي فقط (URL Asset ONLY)
        // ------------------------------------------------------------------
        $courseProduct = Product::firstOrCreate(
            ['slug' => 'vip-masterclass-live-course'],
            [
                'name' => [
                    'en' => 'VIP Live Masterclass (Online Course)',
                    'ar' => 'دورة ماستر كلاس التفاعلية (رابط خارجي)'
                ],
                'description' => ['en' => 'Live course link redirected to Zoom or private community.'],
                'price' => 50.00,
                'product_type' => ProductType::SIMPLE,
                'item_type' => ItemType::DIGITAL,
                'status' => 1,
                'in_stock' => true,
                'stock_quantity' => 9999,
            ]
        );

        $urlAsset = DigitalAsset::firstOrCreate(
            ['product_id' => $courseProduct->id, 'type' => DigitalAssetType::URL->value],
            [
                'uuid' => (string) Str::uuid(),
                'disk' => 'private',
                'path' => null,
                'original_name' => 'VIP Live Masterclass Zoom Room',
                'mime' => 'application/octet-stream',
                'size' => 0,
                'external_url' => 'https://example.com/masterclass-live',
                'status' => DigitalAsset::STATUS_ACTIVE,
                'sort_order' => 1,
            ]
        );

        // ------------------------------------------------------------------
        // PRODUCT 3: سيريال تفعيل ويندوز فقط (LICENSE Asset ONLY)
        // ------------------------------------------------------------------
        $licenseProduct = Product::firstOrCreate(
            ['slug' => 'windows-11-pro-license-key'],
            [
                'name' => [
                    'en' => 'Windows 11 Pro License Key',
                    'ar' => 'مفتاح تفعيل ويندوز 11 برو (سيريال)'
                ],
                'description' => ['en' => 'Genuine software license serial revealed once.'],
                'price' => 30.00,
                'product_type' => ProductType::SIMPLE,
                'item_type' => ItemType::DIGITAL,
                'status' => 1,
                'in_stock' => true,
                'stock_quantity' => 9999,
            ]
        );

        $licenseAsset = DigitalAsset::firstOrCreate(
            ['product_id' => $licenseProduct->id, 'type' => DigitalAssetType::LICENSE->value],
            [
                'uuid' => (string) Str::uuid(),
                'disk' => 'private',
                'path' => null,
                'original_name' => 'Windows 11 Pro Serial Key',
                'mime' => 'application/octet-stream',
                'size' => 0,
                'status' => DigitalAsset::STATUS_ACTIVE,
                'sort_order' => 1,
            ]
        );

        // ------------------------------------------------------------------
        // PRODUCT 4: بيانات دخول وسر دائم فقط (ACCESS Asset ONLY)
        // ------------------------------------------------------------------
        $accessProduct = Product::firstOrCreate(
            ['slug' => 'cloud-server-vip-access-pass'],
            [
                'name' => [
                    'en' => 'Cloud Server VIP Access Token',
                    'ar' => 'بيانات دخول السيرفر الخاص (توكن وباسوورد)'
                ],
                'description' => ['en' => 'Permanent access secret token revealed anytime.'],
                'price' => 40.00,
                'product_type' => ProductType::SIMPLE,
                'item_type' => ItemType::DIGITAL,
                'status' => 1,
                'in_stock' => true,
                'stock_quantity' => 9999,
            ]
        );

        $accessAsset = DigitalAsset::firstOrCreate(
            ['product_id' => $accessProduct->id, 'type' => DigitalAssetType::ACCESS->value],
            [
                'uuid' => (string) Str::uuid(),
                'disk' => 'private',
                'path' => null,
                'original_name' => 'Server VIP Token Credential',
                'mime' => 'application/octet-stream',
                'size' => 0,
                'secret' => 'SECRET-VIP-ACCESS-TOKEN-8888',
                'status' => DigitalAsset::STATUS_ACTIVE,
                'sort_order' => 1,
            ]
        );
        $accessAsset->forceFill([
            'secret' => 'SECRET-VIP-ACCESS-TOKEN-8888',
            'status' => DigitalAsset::STATUS_ACTIVE,
        ])->save();

        // 5. Seed an order for each of the 4 products for ALL users
        $users = User::all();
        $this->command->info("Creating 4 distinct product entitlements for all {$users->count()} users...");

        $productsData = [
            ['product' => $bookProduct, 'asset' => $fileAsset, 'type' => 'FILE', 'name' => 'The Complete Developer Guide (E-Book)'],
            ['product' => $courseProduct, 'asset' => $urlAsset, 'type' => 'URL', 'name' => 'VIP Live Masterclass (Online Course)'],
            ['product' => $licenseProduct, 'asset' => $licenseAsset, 'type' => 'LICENSE', 'name' => 'Windows 11 Pro License Key'],
            ['product' => $accessProduct, 'asset' => $accessAsset, 'type' => 'ACCESS', 'name' => 'Cloud Server VIP Access Token'],
        ];

        foreach ($users as $user) {
            foreach ($productsData as $index => $item) {
                $p = $item['product'];
                $a = $item['asset'];

                $order = Order::firstOrCreate(
                    ['order_number' => "ORD-DIGITAL-U{$user->id}-P{$p->id}"],
                    [
                        'user_id' => $user->id,
                        'status' => 'completed',
                        'payment_status' => 'payment-success',
                        'fulfillment_status' => 'completed',
                        'payment_method' => 'online',
                        'total_price' => $p->price,
                    ]
                );

                $orderProduct = OrderProduct::firstOrCreate(
                    ['order_id' => $order->id, 'product_id' => $p->id],
                    [
                        'product_name' => $item['name'],
                        'product_quantity' => 1,
                        'product_price' => $p->price,
                        'product_total_price' => $p->price,
                        'item_type' => ItemType::DIGITAL,
                    ]
                );

                $entitlement = DigitalEntitlement::firstOrCreate(
                    ['order_product_id' => $orderProduct->id],
                    [
                        'order_id' => $order->id,
                        'user_id' => $user->id,
                        'status' => DigitalEntitlement::STATUS_DELIVERED,
                        'delivered_at' => now(),
                        'download_limit' => 10,
                        'download_count' => 0,
                    ]
                );
                $entitlement->forceFill([
                    'status' => DigitalEntitlement::STATUS_DELIVERED,
                    'expires_at' => null,
                ])->save();

                // If LICENSE type, allocate a fresh key
                if ($item['type'] === 'LICENSE') {
                    $userKey = 'WIN11-PRO-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));
                    $licenseKey = DigitalLicenseKey::firstOrCreate(
                        ['allocated_entitlement_id' => $entitlement->id, 'asset_id' => $a->id],
                        [
                            'uuid' => (string) Str::uuid(),
                            'encrypted_key' => $userKey,
                            'status' => DigitalLicenseKey::STATUS_ASSIGNED,
                            'assigned_at' => now(),
                            'revealed_at' => null,
                        ]
                    );
                    $licenseKey->forceFill(['revealed_at' => null])->save();
                }
            }
        }

        // Clean up test user's old bundle entitlement
        $primaryUserEntitlements = DigitalEntitlement::where('user_id', $primaryUser->id)->get();
        foreach ($primaryUserEntitlements as $e) {
            if ($e->orderItem?->product_id === null || in_array($e->orderItem?->product_id, [5241, 5242], true)) {
                DigitalLicenseKey::where('allocated_entitlement_id', $e->id)->delete();
                $e->delete();
            }
        }

        // 6. Summary for test@gmail.com
        $this->command->newLine();
        $this->command->info('================================================================================');
        $this->command->info('        4 DISTINCT PRODUCTS SEEDED (1 ASSET PER PRODUCT) FOR ALL USERS          ');
        $this->command->info('================================================================================');
        $this->command->line("<fg=yellow>Test User Email:</> " . self::TEST_USER_EMAIL);
        $this->command->line("<fg=yellow>Password:</>        " . self::TEST_USER_PASSWORD);
        $this->command->line("<fg=yellow>Bearer Token:</>    {$token}");
        $this->command->newLine();

        $this->command->table(
            ['المنتج (Product)', 'النوع (Asset Type)', 'محتوى المنتج', 'الـ Path'],
            [
                ['The Complete Developer Guide', 'FILE', 'كتاب PDF فقط للتحميل المباشر', 'download'],
                ['VIP Live Masterclass', 'URL', 'رابط Zoom فقط للتحويل التلقائي', 'url'],
                ['Windows 11 Pro License Key', 'LICENSE', 'سيريال ويندوز فقط للكشف', 'license'],
                ['Cloud Server VIP Access Token', 'ACCESS', 'توكن سري فقط للكشف الدائم', 'license'],
            ]
        );
        $this->command->info('================================================================================');
    }
}
