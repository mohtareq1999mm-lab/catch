<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Tag;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ProductType;
use Marvel\Services\Pricing\ProductPricingService;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $discountTypes = DiscountType::getValues();
            $pricingService = app(ProductPricingService::class);

            $productImages = collect(File::files(public_path('images/products')));
            $productImagesCount = $productImages->count();

            $weeklyFlashSales = FlashSale::query()
                ->valid()
                ->whereBetween('start_date', [now()->startOfWeek(), now()->endOfWeek()])
                ->get();

            $weeklyFlashSalesCount = $weeklyFlashSales->count();

            $allCategories = Category::all()->keyBy(function ($cat) {
                return $cat->getTranslation('name', 'en');
            });
            $allCategoriesById = Category::all()->keyBy('id');
            $couponIds = Coupon::pluck('id')->toArray();
            $allTags = Tag::pluck('id')->toArray();
            $tagsCount = count($allTags);

            $skuCategoryMap = [
                'FAC' => 'Face',
                'EYE' => 'Eyes',
                'LIP' => 'Lips',
                'CHK' => 'Cheeks',
                'TLS' => 'Makeup Tools',
                'NAI' => 'Nail',
                'SKN' => 'Skincare',
                'ACC' => 'Beauty Accessories',
            ];

            $exceptionCategoryMap = [
                // Face
                'Maybelline Fit Me Foundation' => 'Foundation',
                "L'Oréal True Match Foundation" => 'Foundation',
                "NYX Can't Stop Won't Stop Foundation" => 'Foundation',
                'MAC Studio Fix Foundation' => 'Foundation',
                'Huda Beauty Faux Filter Foundation' => 'Foundation',
                'Maybelline Instant Age Rewind Concealer' => 'Concealer',
                'Tarte Shape Tape Concealer' => 'Concealer',
                'NYX Bare With Me Concealer' => 'Concealer',
                'e.l.f Hydrating Concealer' => 'Concealer',
                'Laura Mercier Loose Powder' => 'Powder',
                'Maybelline Fit Me Powder' => 'Powder',
                'Huda Easy Bake Powder' => 'Powder',
                'NYX HD Finishing Powder' => 'Powder',
                'Pore Minimizing Primer' => 'Primer',
                'Illuminating Primer' => 'Primer',
                'Hydrating Primer' => 'Primer',
                'Primer Spray' => 'Primer',
                'Matte Setting Spray' => 'Setting Spray',
                'Dewy Setting Spray' => 'Setting Spray',
                'BB Cream' => 'Foundation',
                'CC Cream' => 'Foundation',
                'Color Correcting Palette' => 'Concealer',
                'Face SPF Primer' => 'Primer',
                // Eyes
                'Sky High Mascara' => 'Mascara',
                'Lash Paradise Mascara' => 'Mascara',
                'Better Than Sex Mascara' => 'Mascara',
                'Telescopic Mascara' => 'Mascara',
                'Voluminous Mascara' => 'Mascara',
                'NYX Epic Ink Eyeliner' => 'Eyeliner',
                'Maybelline Hyper Easy Eyeliner' => 'Eyeliner',
                'Huda Beauty Life Liner' => 'Eyeliner',
                'Gel Eyeliner Pot' => 'Eyeliner',
                'Felt Tip Eyeliner' => 'Eyeliner',
                'Nude Eyeshadow Palette' => 'Eyeshadow Palette',
                'Rose Gold Eyeshadow Palette' => 'Eyeshadow Palette',
                'Smokey Eyeshadow Palette' => 'Eyeshadow Palette',
                'Warm Nude Palette' => 'Eyeshadow Palette',
                'Cut Crease Palette' => 'Eyeshadow Palette',
                'Neutral Matte Palette' => 'Eyeshadow Palette',
                'Glitter Eyeshadow Palette' => 'Eyeshadow Palette',
                'Eyebrow Pencil' => 'Eyebrow',
                'Eyebrow Gel' => 'Eyebrow',
                'Eyebrow Pomade' => 'Eyebrow',
                'Microblading Pen' => 'Eyebrow',
                'Under Eye Patches' => 'Eyeshadow Palette',
                'Lash Growth Serum' => 'Mascara',
                // Lips
                'Matte Liquid Lipstick' => 'Lipstick',
                'Velvet Lipstick' => 'Lipstick',
                'Satin Lipstick' => 'Lipstick',
                'Cream Lipstick' => 'Lipstick',
                'Longwear Lipstick' => 'Lipstick',
                'Clear Lip Gloss' => 'Lip Gloss',
                'Tinted Lip Gloss' => 'Lip Gloss',
                'Sparkle Lip Gloss' => 'Lip Gloss',
                'Lip Liner Nude' => 'Lip Liner',
                'Lip Liner Red' => 'Lip Liner',
                'Lip Liner Pink' => 'Lip Liner',
                'Lip Liner Brown' => 'Lip Liner',
                'Tinted Lip Balm' => 'Lip Balm',
                'Lip Balm Stick' => 'Lip Balm',
                'Lip Scrub' => 'Lip Balm',
                'Lip Sleeping Mask' => 'Lip Balm',
                'Lip Plumper' => 'Lip Gloss',
                'Lip Oil' => 'Lip Gloss',
                'Lip Stain' => 'Lipstick',
                'Matte Lip Gloss' => 'Lip Gloss',
                'Lip Treatment Balm' => 'Lip Balm',
                // Cheeks
                'Powder Blush' => 'Blush',
                'Cream Blush' => 'Blush',
                'Liquid Blush' => 'Blush',
                'Bronzer Powder' => 'Bronzer',
                'Bronzer Stick' => 'Bronzer',
                'Matte Bronzer' => 'Bronzer',
                'Powder Highlighter' => 'Highlighter',
                'Liquid Highlighter' => 'Highlighter',
                'Stick Highlighter' => 'Highlighter',
                'Contour Kit' => 'Bronzer',
                'Contour Stick' => 'Bronzer',
                'Face Palette Blush Bronzer Highlighter' => 'Blush',
                // Makeup Tools
                'Beauty Blender Original' => 'Beauty Blender',
                'Beauty Blender Micro' => 'Beauty Blender',
                'Brush Set 5 Piece' => 'Brush Sets',
                'Brush Set 10 Piece' => 'Brush Sets',
                'Brush Set 15 Piece' => 'Brush Sets',
                'Makeup Sponge Set' => 'Beauty Blender',
                // Nail
                'Red Nail Polish' => 'Nail Polish',
                'Pink Nail Polish' => 'Nail Polish',
                'Nude Nail Polish' => 'Nail Polish',
                'French Manicure Kit' => 'Nail Care',
                'Base Coat' => 'Nail Care',
                'Top Coat' => 'Nail Care',
                'Matte Top Coat' => 'Nail Care',
                'Quick Dry Drops' => 'Nail Care',
                'Nail Polish Remover' => 'Nail Care',
                'Cuticle Oil' => 'Nail Care',
                'Nail File Set' => 'Nail Care',
                'Nail Buffer' => 'Nail Care',
                'Nail Strengthener' => 'Nail Care',
                'Dip Powder Nail Kit' => 'Nail Polish',
                'Nail Art Stickers' => 'Nail Polish',
                // Skincare
                'Gentle Foaming Cleanser' => 'Cleanser',
                'Hydrating Gel Cleanser' => 'Cleanser',
                'Cream Cleanser' => 'Cleanser',
                'Oil Cleanser' => 'Cleanser',
                'Alcohol Free Toner' => 'Toner',
                'Hydrating Rose Toner' => 'Toner',
                'Vitamin C Serum' => 'Serum',
                'Niacinamide Serum' => 'Serum',
                'Hyaluronic Acid Serum' => 'Serum',
                'Retinol Serum' => 'Serum',
                'Salicylic Acid Serum' => 'Serum',
                'Peptide Serum' => 'Serum',
                'Day Moisturizer' => 'Moisturizer',
                'Night Moisturizer' => 'Moisturizer',
                'Gel Moisturizer' => 'Moisturizer',
                'SPF50 Sunscreen' => 'Sunscreen',
                'SPF30 Sunscreen' => 'Sunscreen',
                'Eye Cream' => 'Moisturizer',
                'Sheet Mask Set' => 'Cleanser',
                'Clay Mask' => 'Cleanser',
                'Sleeping Mask' => 'Moisturizer',
                'Cleansing Balm' => 'Cleanser',
                'Micellar Water' => 'Cleanser',
                'Makeup Remover Wipes' => 'Cleanser',
                'Face Mist' => 'Toner',
                'Exfoliating Face Scrub' => 'Cleanser',
                'AHA BHA Exfoliant' => 'Toner',
                'Vitamin C Moisturizer' => 'Moisturizer',
                'Brightening Eye Cream' => 'Moisturizer',
                'Peptide Eye Cream' => 'Moisturizer',
                'Retinol Night Cream' => 'Moisturizer',
                'Niacinamide Moisturizer' => 'Moisturizer',
                'Hyaluronic Acid Moisturizer' => 'Moisturizer',
                'SPF50 Face Mist' => 'Sunscreen',
                'Glycolic Acid Toner' => 'Toner',
                'Pore Tightening Mask' => 'Cleanser',
            ];

            $variablePrefixes = ['FAC', 'LIP', 'NAI', 'EYE', 'CHK'];

            $productDimensions = [
                // Face Foundations
                'Maybelline Fit Me Foundation' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 120],
                "L'Oréal True Match Foundation" => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 120],
                "NYX Can't Stop Won't Stop Foundation" => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 120],
                'MAC Studio Fix Foundation' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 120],
                'Huda Beauty Faux Filter Foundation' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 120],
                // Face Concealers
                'Maybelline Instant Age Rewind Concealer' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 35],
                'Tarte Shape Tape Concealer' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 35],
                'NYX Bare With Me Concealer' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 35],
                'e.l.f Hydrating Concealer' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 35],
                // Face Powders
                'Laura Mercier Loose Powder' => ['h' => 9, 'w' => 9, 'l' => 4, 'wt' => 50],
                'Maybelline Fit Me Powder' => ['h' => 8, 'w' => 8, 'l' => 3, 'wt' => 40],
                'Huda Easy Bake Powder' => ['h' => 9, 'w' => 9, 'l' => 4, 'wt' => 50],
                'NYX HD Finishing Powder' => ['h' => 8, 'w' => 8, 'l' => 3, 'wt' => 40],
                // Face Primers
                'Pore Minimizing Primer' => ['h' => 10, 'w' => 4, 'l' => 4, 'wt' => 60],
                'Illuminating Primer' => ['h' => 10, 'w' => 4, 'l' => 4, 'wt' => 60],
                'Hydrating Primer' => ['h' => 10, 'w' => 4, 'l' => 4, 'wt' => 60],
                'Primer Spray' => ['h' => 14, 'w' => 4, 'l' => 4, 'wt' => 100],
                'Face SPF Primer' => ['h' => 10, 'w' => 4, 'l' => 4, 'wt' => 60],
                // Setting Sprays
                'Matte Setting Spray' => ['h' => 14, 'w' => 4, 'l' => 4, 'wt' => 100],
                'Dewy Setting Spray' => ['h' => 14, 'w' => 4, 'l' => 4, 'wt' => 100],
                // Face other
                'BB Cream' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 60],
                'CC Cream' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 60],
                'Color Correcting Palette' => ['h' => 14, 'w' => 8, 'l' => 2, 'wt' => 80],
                // Eye Mascaras
                'Sky High Mascara' => ['h' => 14, 'w' => 2, 'l' => 2, 'wt' => 20],
                'Lash Paradise Mascara' => ['h' => 14, 'w' => 2, 'l' => 2, 'wt' => 20],
                'Better Than Sex Mascara' => ['h' => 14, 'w' => 2, 'l' => 2, 'wt' => 20],
                'Telescopic Mascara' => ['h' => 14, 'w' => 2, 'l' => 2, 'wt' => 20],
                'Voluminous Mascara' => ['h' => 14, 'w' => 2, 'l' => 2, 'wt' => 20],
                // Eye Eyeliners
                'NYX Epic Ink Eyeliner' => ['h' => 13, 'w' => 1, 'l' => 1, 'wt' => 10],
                'Maybelline Hyper Easy Eyeliner' => ['h' => 13, 'w' => 1, 'l' => 1, 'wt' => 10],
                'Huda Beauty Life Liner' => ['h' => 13, 'w' => 1, 'l' => 1, 'wt' => 10],
                'Gel Eyeliner Pot' => ['h' => 4, 'w' => 4, 'l' => 4, 'wt' => 25],
                'Felt Tip Eyeliner' => ['h' => 13, 'w' => 1, 'l' => 1, 'wt' => 10],
                // Eye Palettes
                'Nude Eyeshadow Palette' => ['h' => 18, 'w' => 12, 'l' => 2, 'wt' => 300],
                'Rose Gold Eyeshadow Palette' => ['h' => 18, 'w' => 12, 'l' => 2, 'wt' => 300],
                'Smokey Eyeshadow Palette' => ['h' => 18, 'w' => 12, 'l' => 2, 'wt' => 300],
                'Warm Nude Palette' => ['h' => 18, 'w' => 12, 'l' => 2, 'wt' => 300],
                'Cut Crease Palette' => ['h' => 18, 'w' => 12, 'l' => 2, 'wt' => 300],
                'Neutral Matte Palette' => ['h' => 18, 'w' => 12, 'l' => 2, 'wt' => 300],
                'Glitter Eyeshadow Palette' => ['h' => 18, 'w' => 12, 'l' => 2, 'wt' => 300],
                // Eye Brows
                'Eyebrow Pencil' => ['h' => 14, 'w' => 1, 'l' => 1, 'wt' => 8],
                'Eyebrow Gel' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 20],
                'Eyebrow Pomade' => ['h' => 5, 'w' => 5, 'l' => 2, 'wt' => 25],
                'Microblading Pen' => ['h' => 14, 'w' => 1, 'l' => 1, 'wt' => 10],
                // Eye other
                'Eye Primer' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 20],
                'Eyeshadow Brush' => ['h' => 20, 'w' => 2, 'l' => 2, 'wt' => 15],
                'Blending Brush' => ['h' => 20, 'w' => 2, 'l' => 2, 'wt' => 15],
                'Eyelash Curler' => ['h' => 10, 'w' => 5, 'l' => 3, 'wt' => 40],
                'False Eyelashes' => ['h' => 8, 'w' => 5, 'l' => 1, 'wt' => 5],
                'Eyelash Glue' => ['h' => 6, 'w' => 2, 'l' => 2, 'wt' => 10],
                'Under Eye Patches' => ['h' => 10, 'w' => 6, 'l' => 1, 'wt' => 20],
                'Lash Growth Serum' => ['h' => 8, 'w' => 2, 'l' => 2, 'wt' => 15],
                // Lip Lipsticks
                'Matte Liquid Lipstick' => ['h' => 8, 'w' => 2, 'l' => 2, 'wt' => 25],
                'Velvet Lipstick' => ['h' => 8, 'w' => 2, 'l' => 2, 'wt' => 25],
                'Satin Lipstick' => ['h' => 8, 'w' => 2, 'l' => 2, 'wt' => 25],
                'Cream Lipstick' => ['h' => 8, 'w' => 2, 'l' => 2, 'wt' => 25],
                'Longwear Lipstick' => ['h' => 8, 'w' => 2, 'l' => 2, 'wt' => 25],
                // Lip Gloss
                'Clear Lip Gloss' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                'Tinted Lip Gloss' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                'Sparkle Lip Gloss' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                'Matte Lip Gloss' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                // Lip Liners
                'Lip Liner Nude' => ['h' => 14, 'w' => 1, 'l' => 1, 'wt' => 8],
                'Lip Liner Red' => ['h' => 14, 'w' => 1, 'l' => 1, 'wt' => 8],
                'Lip Liner Pink' => ['h' => 14, 'w' => 1, 'l' => 1, 'wt' => 8],
                'Lip Liner Brown' => ['h' => 14, 'w' => 1, 'l' => 1, 'wt' => 8],
                // Lip Balms
                'Tinted Lip Balm' => ['h' => 6, 'w' => 2, 'l' => 2, 'wt' => 10],
                'Lip Balm Stick' => ['h' => 7, 'w' => 2, 'l' => 2, 'wt' => 10],
                'Lip Treatment Balm' => ['h' => 6, 'w' => 2, 'l' => 2, 'wt' => 10],
                // Lip Treatments
                'Lip Scrub' => ['h' => 6, 'w' => 3, 'l' => 3, 'wt' => 20],
                'Lip Sleeping Mask' => ['h' => 6, 'w' => 6, 'l' => 3, 'wt' => 30],
                'Lip Plumper' => ['h' => 8, 'w' => 2, 'l' => 2, 'wt' => 15],
                'Lip Oil' => ['h' => 8, 'w' => 2, 'l' => 2, 'wt' => 15],
                'Lip Stain' => ['h' => 8, 'w' => 2, 'l' => 2, 'wt' => 20],
                // Cheeks Blush
                'Powder Blush' => ['h' => 7, 'w' => 7, 'l' => 2, 'wt' => 25],
                'Cream Blush' => ['h' => 6, 'w' => 6, 'l' => 2, 'wt' => 30],
                'Liquid Blush' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 20],
                // Cheeks Bronzer
                'Bronzer Powder' => ['h' => 8, 'w' => 8, 'l' => 2, 'wt' => 30],
                'Bronzer Stick' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 35],
                'Matte Bronzer' => ['h' => 8, 'w' => 8, 'l' => 2, 'wt' => 30],
                'Contour Kit' => ['h' => 10, 'w' => 8, 'l' => 2, 'wt' => 50],
                'Contour Stick' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 35],
                // Cheeks Highlighter
                'Powder Highlighter' => ['h' => 7, 'w' => 7, 'l' => 2, 'wt' => 28],
                'Liquid Highlighter' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 20],
                'Stick Highlighter' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 30],
                // Cheeks Palettes
                'Face Palette Blush Bronzer Highlighter' => ['h' => 15, 'w' => 10, 'l' => 2, 'wt' => 120],
                // Tools Brushes
                'Foundation Brush' => ['h' => 22, 'w' => 3, 'l' => 3, 'wt' => 40],
                'Powder Brush' => ['h' => 24, 'w' => 4, 'l' => 4, 'wt' => 55],
                'Blush Brush' => ['h' => 22, 'w' => 3, 'l' => 3, 'wt' => 45],
                'Concealer Brush' => ['h' => 20, 'w' => 2, 'l' => 2, 'wt' => 25],
                'Lip Brush' => ['h' => 18, 'w' => 1, 'l' => 1, 'wt' => 15],
                'Angled Brush' => ['h' => 22, 'w' => 3, 'l' => 3, 'wt' => 40],
                'Fan Brush' => ['h' => 22, 'w' => 3, 'l' => 1, 'wt' => 30],
                'Kabuki Brush' => ['h' => 20, 'w' => 4, 'l' => 4, 'wt' => 50],
                // Tools Blenders
                'Beauty Blender Original' => ['h' => 8, 'w' => 5, 'l' => 5, 'wt' => 20],
                'Beauty Blender Micro' => ['h' => 6, 'w' => 4, 'l' => 4, 'wt' => 12],
                'Makeup Sponge Set' => ['h' => 8, 'w' => 6, 'l' => 4, 'wt' => 30],
                'Silicone Face Brush' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 40],
                // Tools Brush Sets
                'Brush Set 5 Piece' => ['h' => 25, 'w' => 10, 'l' => 5, 'wt' => 200],
                'Brush Set 10 Piece' => ['h' => 28, 'w' => 12, 'l' => 6, 'wt' => 350],
                'Brush Set 15 Piece' => ['h' => 30, 'w' => 14, 'l' => 6, 'wt' => 450],
                // Tools other
                'Brush Cleanser' => ['h' => 14, 'w' => 4, 'l' => 4, 'wt' => 120],
                'Brush Holder' => ['h' => 18, 'w' => 10, 'l' => 10, 'wt' => 300],
                'Mixing Palette' => ['h' => 14, 'w' => 8, 'l' => 1, 'wt' => 50],
                // Nail Polish
                'Red Nail Polish' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                'Pink Nail Polish' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                'Nude Nail Polish' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                'Dip Powder Nail Kit' => ['h' => 12, 'w' => 10, 'l' => 8, 'wt' => 300],
                // Nail Care
                'French Manicure Kit' => ['h' => 12, 'w' => 8, 'l' => 3, 'wt' => 100],
                'Base Coat' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                'Top Coat' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                'Matte Top Coat' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                'Quick Dry Drops' => ['h' => 6, 'w' => 3, 'l' => 3, 'wt' => 20],
                'Nail Polish Remover' => ['h' => 12, 'w' => 5, 'l' => 5, 'wt' => 120],
                'Cuticle Oil' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 25],
                'Nail File Set' => ['h' => 18, 'w' => 5, 'l' => 1, 'wt' => 20],
                'Nail Buffer' => ['h' => 10, 'w' => 5, 'l' => 2, 'wt' => 15],
                'Nail Strengthener' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                // Skincare Cleansers
                'Gentle Foaming Cleanser' => ['h' => 16, 'w' => 6, 'l' => 6, 'wt' => 200],
                'Hydrating Gel Cleanser' => ['h' => 16, 'w' => 6, 'l' => 6, 'wt' => 200],
                'Cream Cleanser' => ['h' => 14, 'w' => 6, 'l' => 6, 'wt' => 180],
                'Oil Cleanser' => ['h' => 16, 'w' => 6, 'l' => 6, 'wt' => 200],
                'Cleansing Balm' => ['h' => 10, 'w' => 6, 'l' => 6, 'wt' => 100],
                'Micellar Water' => ['h' => 16, 'w' => 6, 'l' => 6, 'wt' => 200],
                'Makeup Remover Wipes' => ['h' => 18, 'w' => 10, 'l' => 5, 'wt' => 150],
                'Exfoliating Face Scrub' => ['h' => 14, 'w' => 5, 'l' => 5, 'wt' => 150],
                'Pore Tightening Mask' => ['h' => 10, 'w' => 6, 'l' => 6, 'wt' => 100],
                // Skincare Toners
                'Alcohol Free Toner' => ['h' => 14, 'w' => 5, 'l' => 5, 'wt' => 150],
                'Hydrating Rose Toner' => ['h' => 14, 'w' => 5, 'l' => 5, 'wt' => 150],
                'Face Mist' => ['h' => 14, 'w' => 4, 'l' => 4, 'wt' => 100],
                'AHA BHA Exfoliant' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 100],
                'Glycolic Acid Toner' => ['h' => 14, 'w' => 5, 'l' => 5, 'wt' => 150],
                // Skincare Serums
                'Vitamin C Serum' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 30],
                'Niacinamide Serum' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 30],
                'Hyaluronic Acid Serum' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 30],
                'Retinol Serum' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 30],
                'Salicylic Acid Serum' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 30],
                'Peptide Serum' => ['h' => 10, 'w' => 3, 'l' => 3, 'wt' => 30],
                // Skincare Moisturizers
                'Day Moisturizer' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 80],
                'Night Moisturizer' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 80],
                'Gel Moisturizer' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 80],
                'Vitamin C Moisturizer' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 80],
                'Retinol Night Cream' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 80],
                'Niacinamide Moisturizer' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 80],
                'Hyaluronic Acid Moisturizer' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 80],
                'Eye Cream' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 20],
                'Brightening Eye Cream' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 20],
                'Peptide Eye Cream' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 20],
                // Skincare Sunscreen
                'SPF50 Sunscreen' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 100],
                'SPF30 Sunscreen' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 100],
                'SPF50 Face Mist' => ['h' => 14, 'w' => 4, 'l' => 4, 'wt' => 100],
                // Skincare Masks
                'Sheet Mask Set' => ['h' => 15, 'w' => 10, 'l' => 1, 'wt' => 30],
                'Clay Mask' => ['h' => 10, 'w' => 6, 'l' => 6, 'wt' => 100],
                'Sleeping Mask' => ['h' => 10, 'w' => 6, 'l' => 6, 'wt' => 100],
                // Accessories
                'Cosmetic Bag' => ['h' => 20, 'w' => 14, 'l' => 5, 'wt' => 200],
                'Makeup Train Case' => ['h' => 30, 'w' => 20, 'l' => 15, 'wt' => 1500],
                'Travel Makeup Bag' => ['h' => 18, 'w' => 12, 'l' => 5, 'wt' => 150],
                'Vanity Mirror with Lights' => ['h' => 40, 'w' => 30, 'l' => 5, 'wt' => 2000],
                'Compact Mirror' => ['h' => 10, 'w' => 8, 'l' => 1, 'wt' => 50],
                'Magnifying Mirror' => ['h' => 15, 'w' => 12, 'l' => 2, 'wt' => 200],
                'Cotton Rounds Pack' => ['h' => 15, 'w' => 8, 'l' => 8, 'wt' => 100],
                'Cotton Swabs Pack' => ['h' => 12, 'w' => 6, 'l' => 6, 'wt' => 80],
                'Acrylic Makeup Organizer' => ['h' => 25, 'w' => 15, 'l' => 15, 'wt' => 500],
                'Makeup Spatula Set' => ['h' => 16, 'w' => 3, 'l' => 1, 'wt' => 20],
                'Dual Pencil Sharpener' => ['h' => 4, 'w' => 3, 'l' => 3, 'wt' => 15],
                'Precision Tweezers' => ['h' => 10, 'w' => 1, 'l' => 1, 'wt' => 20],
                'Eyelash Applicator' => ['h' => 12, 'w' => 1, 'l' => 1, 'wt' => 5],
                'Mini Makeup Fridge' => ['h' => 25, 'w' => 20, 'l' => 20, 'wt' => 3000],
                'Stainless Face Roller' => ['h' => 20, 'w' => 5, 'l' => 5, 'wt' => 80],
                'Jade Face Roller' => ['h' => 18, 'w' => 5, 'l' => 5, 'wt' => 100],
                'Gua Sha Stone' => ['h' => 10, 'w' => 5, 'l' => 2, 'wt' => 40],
                'Scented Beauty Candle' => ['h' => 10, 'w' => 8, 'l' => 8, 'wt' => 300],
                'Velvet Hair Band' => ['h' => 15, 'w' => 8, 'l' => 1, 'wt' => 15],
                'Makeup Cape' => ['h' => 20, 'w' => 15, 'l' => 2, 'wt' => 100],
                'Face Mask Spatula' => ['h' => 14, 'w' => 2, 'l' => 1, 'wt' => 10],
                'Silicone Mixing Palette' => ['h' => 16, 'w' => 10, 'l' => 1, 'wt' => 40],
                'Makeup Brush Cleaning Mat' => ['h' => 20, 'w' => 12, 'l' => 1, 'wt' => 80],
                'Travel Bottles Set' => ['h' => 12, 'w' => 8, 'l' => 4, 'wt' => 100],
                'Makeup Sponge Egg' => ['h' => 6, 'w' => 4, 'l' => 4, 'wt' => 10],
                'Foundation Mixing Palette' => ['h' => 14, 'w' => 8, 'l' => 1, 'wt' => 50],
                'Magnetic False Lashes' => ['h' => 8, 'w' => 5, 'l' => 1, 'wt' => 6],
                'LED Makeup Mirror' => ['h' => 35, 'w' => 25, 'l' => 5, 'wt' => 1500],
                'Silicone Brush Set' => ['h' => 20, 'w' => 8, 'l' => 4, 'wt' => 120],
            ];

            $categoryDimensionDefaults = [
                'FAC' => ['h' => 12, 'w' => 4, 'l' => 4, 'wt' => 120],
                'EYE' => ['h' => 14, 'w' => 3, 'l' => 2, 'wt' => 30],
                'LIP' => ['h' => 8, 'w' => 2, 'l' => 2, 'wt' => 20],
                'CHK' => ['h' => 7, 'w' => 7, 'l' => 2, 'wt' => 30],
                'TLS' => ['h' => 22, 'w' => 5, 'l' => 3, 'wt' => 80],
                'NAI' => ['h' => 8, 'w' => 3, 'l' => 3, 'wt' => 15],
                'SKN' => ['h' => 14, 'w' => 5, 'l' => 5, 'wt' => 150],
                'ACC' => ['h' => 18, 'w' => 12, 'l' => 4, 'wt' => 200],
            ];

            $products = [
                // ===== FACE (FAC) - Foundations, Concealers, Powders, Primers, Setting Sprays =====
                ['name' => ['en' => 'Maybelline Fit Me Foundation', 'ar' => 'ميبيلين فيت مي فاونديشن'], 'price' => 29.99, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => "L'Oréal True Match Foundation", 'ar' => 'لوريال ترو ماتش فاونديشن'], 'price' => 34.99, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => "NYX Can't Stop Won't Stop Foundation", 'ar' => 'نيكس كانت ستوب فاونديشن'], 'price' => 24.99, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'MAC Studio Fix Foundation', 'ar' => 'ماك ستوديو فيكس فاونديشن'], 'price' => 45.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Huda Beauty Faux Filter Foundation', 'ar' => 'هدا بيوتي فاو فيلتر فاونديشن'], 'price' => 49.99, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Maybelline Instant Age Rewind Concealer', 'ar' => 'ميبيلين كونسيلر'], 'price' => 19.99, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Tarte Shape Tape Concealer', 'ar' => 'تارت شيب تيب كونسيلر'], 'price' => 39.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'NYX Bare With Me Concealer', 'ar' => 'نيكس كونسيلر'], 'price' => 17.99, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'e.l.f Hydrating Concealer', 'ar' => 'إي إل إف كونسيلر مرطب'], 'price' => 14.99, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Laura Mercier Loose Powder', 'ar' => 'لورا ميرسيه بودرة حرة'], 'price' => 55.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Maybelline Fit Me Powder', 'ar' => 'ميبيلين فيت مي بودرة'], 'price' => 18.99, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Huda Easy Bake Powder', 'ar' => 'هدا إيزي بيك بودرة'], 'price' => 42.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'NYX HD Finishing Powder', 'ar' => 'نيكس إتش دي بودرة'], 'price' => 22.99, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Pore Minimizing Primer', 'ar' => 'برايمر لتقليل المسام'], 'price' => 25.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Illuminating Primer', 'ar' => 'برايمر مضيء'], 'price' => 28.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Hydrating Primer', 'ar' => 'برايمر مرطب'], 'price' => 24.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Primer Spray', 'ar' => 'بخاخ برايمر'], 'price' => 22.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Matte Setting Spray', 'ar' => 'بخاخ تثبيت مات'], 'price' => 18.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Dewy Setting Spray', 'ar' => 'بخاخ تثبيت ديوي'], 'price' => 18.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'BB Cream', 'ar' => 'بي بي كريم'], 'price' => 22.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'CC Cream', 'ar' => 'سي سي كريم'], 'price' => 24.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Color Correcting Palette', 'ar' => 'لوحة تصحيح الألوان'], 'price' => 32.00, 'sku_prefix' => 'FAC'],
                ['name' => ['en' => 'Face SPF Primer', 'ar' => 'برايمر وجه مع حماية شمسية'], 'price' => 28.00, 'sku_prefix' => 'FAC'],

                // ===== EYES (EYE) - Mascaras, Eyeliners, Palettes, Eyebrows, Tools =====
                ['name' => ['en' => 'Sky High Mascara', 'ar' => 'ماسكارا سكاي هاي'], 'price' => 24.99, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Lash Paradise Mascara', 'ar' => 'ماسكارا لاش بارادايس'], 'price' => 28.99, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Better Than Sex Mascara', 'ar' => 'ماسكارا بيتر ذان سيكس'], 'price' => 35.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Telescopic Mascara', 'ar' => 'ماسكارا تيليسكوبيك'], 'price' => 19.99, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Voluminous Mascara', 'ar' => 'ماسكارا فوليمينوس'], 'price' => 18.99, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'NYX Epic Ink Eyeliner', 'ar' => 'آيلاينر نيكس إيبك إنك'], 'price' => 18.99, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Maybelline Hyper Easy Eyeliner', 'ar' => 'آيلاينر ميبيلين هايبر إيزي'], 'price' => 16.99, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Huda Beauty Life Liner', 'ar' => 'آيلاينر هدا بيوتي'], 'price' => 22.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Gel Eyeliner Pot', 'ar' => 'آيلاينر جل'], 'price' => 24.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Felt Tip Eyeliner', 'ar' => 'آيلاينر فيلت'], 'price' => 15.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Nude Eyeshadow Palette', 'ar' => 'لوحة ظلال عيون نود'], 'price' => 45.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Rose Gold Eyeshadow Palette', 'ar' => 'لوحة ظلال روز جولد'], 'price' => 55.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Smokey Eyeshadow Palette', 'ar' => 'لوحة ظلال سموكي'], 'price' => 48.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Warm Nude Palette', 'ar' => 'لوحة وارم نود'], 'price' => 50.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Cut Crease Palette', 'ar' => 'لوحة كات كريز'], 'price' => 52.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Neutral Matte Palette', 'ar' => 'لوحة نيوترال مات'], 'price' => 42.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Glitter Eyeshadow Palette', 'ar' => 'لوحة ظلال جليتر'], 'price' => 58.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Eyebrow Pencil', 'ar' => 'قلم حاجب'], 'price' => 14.99, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Eyebrow Gel', 'ar' => 'جل حاجب'], 'price' => 18.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Eyebrow Pomade', 'ar' => 'بوميد حاجب'], 'price' => 20.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Microblading Pen', 'ar' => 'قلم ميكروبليدينج'], 'price' => 25.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Eye Primer', 'ar' => 'برايمر عيون'], 'price' => 18.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Eyeshadow Brush', 'ar' => 'فرشاة ظلال عيون'], 'price' => 12.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Blending Brush', 'ar' => 'فرشاة دمج'], 'price' => 14.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Eyelash Curler', 'ar' => 'مثني رموش'], 'price' => 15.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'False Eyelashes', 'ar' => 'رموش صناعية'], 'price' => 12.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Eyelash Glue', 'ar' => 'غراء رموش'], 'price' => 8.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Under Eye Patches', 'ar' => 'لصقات تحت العين'], 'price' => 22.00, 'sku_prefix' => 'EYE'],
                ['name' => ['en' => 'Lash Growth Serum', 'ar' => 'سيروم تطويل الرموش'], 'price' => 35.00, 'sku_prefix' => 'EYE'],

                // ===== LIPS (LIP) - Lipsticks, Gloss, Liners, Balms, Treatments =====
                ['name' => ['en' => 'Matte Liquid Lipstick', 'ar' => 'أحمر شفاه سائل مات'], 'price' => 22.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Velvet Lipstick', 'ar' => 'أحمر شفاه فيلفيت'], 'price' => 26.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Satin Lipstick', 'ar' => 'أحمر شفاه ساتان'], 'price' => 24.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Cream Lipstick', 'ar' => 'أحمر شفاه كريمي'], 'price' => 22.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Longwear Lipstick', 'ar' => 'أحمر شفاه طويل الثبات'], 'price' => 28.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Clear Lip Gloss', 'ar' => 'ملمع شفاه شفاف'], 'price' => 14.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Tinted Lip Gloss', 'ar' => 'ملمع شفاه ملون'], 'price' => 16.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Sparkle Lip Gloss', 'ar' => 'ملمع شفاه لماع'], 'price' => 16.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Matte Lip Gloss', 'ar' => 'ملمع شفاه مات'], 'price' => 16.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Lip Liner Nude', 'ar' => 'محدد شفاه نود'], 'price' => 12.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Lip Liner Red', 'ar' => 'محدد شفاه أحمر'], 'price' => 12.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Lip Liner Pink', 'ar' => 'محدد شفاه وردي'], 'price' => 12.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Lip Liner Brown', 'ar' => 'محدد شفاه بني'], 'price' => 12.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Tinted Lip Balm', 'ar' => 'بلسم شفاه ملون'], 'price' => 10.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Lip Balm Stick', 'ar' => 'عصا بلسم الشفاه'], 'price' => 8.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Lip Treatment Balm', 'ar' => 'بلسم علاجي للشفاه'], 'price' => 12.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Lip Scrub', 'ar' => 'مقشر شفاه'], 'price' => 14.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Lip Sleeping Mask', 'ar' => 'قناع شفاه ليلي'], 'price' => 25.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Lip Plumper', 'ar' => 'ممتلئ شفاه'], 'price' => 20.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Lip Oil', 'ar' => 'زيت شفاه'], 'price' => 18.00, 'sku_prefix' => 'LIP'],
                ['name' => ['en' => 'Lip Stain', 'ar' => 'صبغة شفاه'], 'price' => 20.00, 'sku_prefix' => 'LIP'],

                // ===== CHEEKS (CHK) - Blush, Bronzer, Highlighter, Contour =====
                ['name' => ['en' => 'Powder Blush', 'ar' => 'بلاش بودرة'], 'price' => 25.00, 'sku_prefix' => 'CHK'],
                ['name' => ['en' => 'Cream Blush', 'ar' => 'بلاش كريمي'], 'price' => 28.00, 'sku_prefix' => 'CHK'],
                ['name' => ['en' => 'Liquid Blush', 'ar' => 'بلاش سائل'], 'price' => 30.00, 'sku_prefix' => 'CHK'],
                ['name' => ['en' => 'Bronzer Powder', 'ar' => 'برونزر بودرة'], 'price' => 35.00, 'sku_prefix' => 'CHK'],
                ['name' => ['en' => 'Bronzer Stick', 'ar' => 'برونزر ستيك'], 'price' => 38.00, 'sku_prefix' => 'CHK'],
                ['name' => ['en' => 'Matte Bronzer', 'ar' => 'برونزر مات'], 'price' => 32.00, 'sku_prefix' => 'CHK'],
                ['name' => ['en' => 'Powder Highlighter', 'ar' => 'هايلايتر بودرة'], 'price' => 32.00, 'sku_prefix' => 'CHK'],
                ['name' => ['en' => 'Liquid Highlighter', 'ar' => 'هايلايتر سائل'], 'price' => 35.00, 'sku_prefix' => 'CHK'],
                ['name' => ['en' => 'Stick Highlighter', 'ar' => 'هايلايتر ستيك'], 'price' => 34.00, 'sku_prefix' => 'CHK'],
                ['name' => ['en' => 'Contour Kit', 'ar' => 'طقم تحديد الوجه'], 'price' => 42.00, 'sku_prefix' => 'CHK'],
                ['name' => ['en' => 'Contour Stick', 'ar' => 'ستيك تحديد الوجه'], 'price' => 30.00, 'sku_prefix' => 'CHK'],
                ['name' => ['en' => 'Face Palette Blush Bronzer Highlighter', 'ar' => 'باليتة وجه بلاش برونزر هايلايتر'], 'price' => 55.00, 'sku_prefix' => 'CHK'],

                // ===== MAKEUP TOOLS (TLS) - Brushes, Blenders, Sets =====
                ['name' => ['en' => 'Foundation Brush', 'ar' => 'فرشاة فاونديشن'], 'price' => 18.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Powder Brush', 'ar' => 'فرشاة بودرة'], 'price' => 22.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Blush Brush', 'ar' => 'فرشاة بلاش'], 'price' => 20.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Concealer Brush', 'ar' => 'فرشاة كونسيلر'], 'price' => 15.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Lip Brush', 'ar' => 'فرشاة شفاه'], 'price' => 12.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Angled Brush', 'ar' => 'فرشاة مائلة'], 'price' => 16.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Fan Brush', 'ar' => 'فرشاة مروحية'], 'price' => 18.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Kabuki Brush', 'ar' => 'فرشاة كابوكي'], 'price' => 24.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Beauty Blender Original', 'ar' => 'بيوتي بلندر أصلي'], 'price' => 22.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Beauty Blender Micro', 'ar' => 'بيوتي بلندر مايكرو'], 'price' => 18.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Makeup Sponge Set', 'ar' => 'طقم إسفنج مكياج'], 'price' => 25.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Brush Set 5 Piece', 'ar' => 'طقم فرش 5 قطع'], 'price' => 45.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Brush Set 10 Piece', 'ar' => 'طقم فرش 10 قطع'], 'price' => 75.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Brush Set 15 Piece', 'ar' => 'طقم فرش 15 قطعة'], 'price' => 120.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Brush Cleanser', 'ar' => 'منظف فرش'], 'price' => 16.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Brush Holder', 'ar' => 'حامل فرش'], 'price' => 28.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Silicone Face Brush', 'ar' => 'فرشاة وجه سيليكون'], 'price' => 12.00, 'sku_prefix' => 'TLS'],
                ['name' => ['en' => 'Mixing Palette', 'ar' => 'لوحة خلط'], 'price' => 15.00, 'sku_prefix' => 'TLS'],

                // ===== NAIL (NAI) - Nail Polish, Base & Top Coat, Care =====
                ['name' => ['en' => 'Red Nail Polish', 'ar' => 'طلاء أظافر أحمر'], 'price' => 12.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Pink Nail Polish', 'ar' => 'طلاء أظافر وردي'], 'price' => 12.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Nude Nail Polish', 'ar' => 'طلاء أظافر نود'], 'price' => 12.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'French Manicure Kit', 'ar' => 'طقم أظافر فرنسي'], 'price' => 28.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Base Coat', 'ar' => 'طبقة أساس'], 'price' => 14.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Top Coat', 'ar' => 'طبقة علوية'], 'price' => 14.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Matte Top Coat', 'ar' => 'طبقة علوية مات'], 'price' => 16.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Quick Dry Drops', 'ar' => 'قطرات تجفيف سريع'], 'price' => 18.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Nail Polish Remover', 'ar' => 'مزيل طلاء أظافر'], 'price' => 10.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Cuticle Oil', 'ar' => 'زيت بشرة الأظافر'], 'price' => 12.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Nail File Set', 'ar' => 'طقم مبارد أظافر'], 'price' => 8.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Nail Buffer', 'ar' => 'ملمع أظافر'], 'price' => 6.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Nail Strengthener', 'ar' => 'مقوي أظافر'], 'price' => 15.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Dip Powder Nail Kit', 'ar' => 'طقم أظافر ديب باودر'], 'price' => 45.00, 'sku_prefix' => 'NAI'],
                ['name' => ['en' => 'Nail Art Stickers', 'ar' => 'ملصقات فن الأظافر'], 'price' => 8.00, 'sku_prefix' => 'NAI'],

                // ===== SKINCARE (SKN) - Cleansers, Toners, Serums, Moisturizers, Sunscreen, Masks =====
                ['name' => ['en' => 'Gentle Foaming Cleanser', 'ar' => 'منظف رغوي لطيف'], 'price' => 22.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Hydrating Gel Cleanser', 'ar' => 'منظف جل مرطب'], 'price' => 24.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Cream Cleanser', 'ar' => 'منظف كريمي'], 'price' => 26.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Oil Cleanser', 'ar' => 'منظف زيتي'], 'price' => 28.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Cleansing Balm', 'ar' => 'بلسم منظف'], 'price' => 26.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Micellar Water', 'ar' => 'ماء ميسيلار'], 'price' => 18.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Makeup Remover Wipes', 'ar' => 'مناديل مزيلة للمكياج'], 'price' => 15.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Exfoliating Face Scrub', 'ar' => 'مقشر وجه'], 'price' => 20.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Pore Tightening Mask', 'ar' => 'قناع شد المسام'], 'price' => 22.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Clay Mask', 'ar' => 'قناع طيني'], 'price' => 24.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Sheet Mask Set', 'ar' => 'طقم أقنعة ورقية'], 'price' => 20.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Alcohol Free Toner', 'ar' => 'تونر خال من الكحول'], 'price' => 20.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Hydrating Rose Toner', 'ar' => 'تونر الورد المرطب'], 'price' => 22.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Face Mist', 'ar' => 'بخاخ وجه'], 'price' => 18.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Glycolic Acid Toner', 'ar' => 'تونر حمض الجليكوليك'], 'price' => 25.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'AHA BHA Exfoliant', 'ar' => 'مقشر AHA BHA'], 'price' => 35.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Vitamin C Serum', 'ar' => 'سيروم فيتامين سي'], 'price' => 35.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Niacinamide Serum', 'ar' => 'سيروم نياسينامايد'], 'price' => 30.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Hyaluronic Acid Serum', 'ar' => 'سيروم حمض الهيالورونيك'], 'price' => 32.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Retinol Serum', 'ar' => 'سيروم ريتينول'], 'price' => 40.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Salicylic Acid Serum', 'ar' => 'سيروم حمض الساليسيليك'], 'price' => 28.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Peptide Serum', 'ar' => 'سيروم ببتيد'], 'price' => 38.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Day Moisturizer', 'ar' => 'مرطب نهاري'], 'price' => 30.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Night Moisturizer', 'ar' => 'مرطب ليلي'], 'price' => 35.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Gel Moisturizer', 'ar' => 'مرطب جل'], 'price' => 28.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Vitamin C Moisturizer', 'ar' => 'مرطب فيتامين سي'], 'price' => 34.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Niacinamide Moisturizer', 'ar' => 'مرطب نياسينامايد'], 'price' => 30.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Hyaluronic Acid Moisturizer', 'ar' => 'مرطب حمض الهيالورونيك'], 'price' => 32.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Retinol Night Cream', 'ar' => 'كريم ليلي ريتينول'], 'price' => 42.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Sleeping Mask', 'ar' => 'قناع نوم'], 'price' => 28.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Eye Cream', 'ar' => 'كريم عيون'], 'price' => 32.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Brightening Eye Cream', 'ar' => 'كريم عيون مضيء'], 'price' => 34.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'Peptide Eye Cream', 'ar' => 'كريم عيون ببتيد'], 'price' => 36.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'SPF50 Sunscreen', 'ar' => 'واقي شمس SPF50'], 'price' => 25.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'SPF30 Sunscreen', 'ar' => 'واقي شمس SPF30'], 'price' => 22.00, 'sku_prefix' => 'SKN'],
                ['name' => ['en' => 'SPF50 Face Mist', 'ar' => 'بخاخ وجه SPF50'], 'price' => 22.00, 'sku_prefix' => 'SKN'],

                // ===== ACCESSORIES (ACC) - Bags, Mirrors, Organizers, Tools =====
                ['name' => ['en' => 'Cosmetic Bag', 'ar' => 'حقيبة مكياج'], 'price' => 25.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Makeup Train Case', 'ar' => 'حقيبة مكياج كبيرة'], 'price' => 55.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Travel Makeup Bag', 'ar' => 'حقيبة مكياج سفر'], 'price' => 30.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Vanity Mirror with Lights', 'ar' => 'مرآة تسريح مع إضاءة'], 'price' => 65.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Compact Mirror', 'ar' => 'مرآة صغيرة'], 'price' => 15.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Magnifying Mirror', 'ar' => 'مرآة مكبرة'], 'price' => 22.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'LED Makeup Mirror', 'ar' => 'مرآة مكياج LED'], 'price' => 55.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Cotton Rounds Pack', 'ar' => 'عبوة قطن دائري'], 'price' => 8.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Cotton Swabs Pack', 'ar' => 'عبوة أعواد قطنية'], 'price' => 6.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Acrylic Makeup Organizer', 'ar' => 'منظم مكياج أكريليك'], 'price' => 35.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Makeup Spatula Set', 'ar' => 'طقم سباتولا مكياج'], 'price' => 8.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Dual Pencil Sharpener', 'ar' => 'مبراة أقلام مزدوجة'], 'price' => 6.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Precision Tweezers', 'ar' => 'ملقط دقيق'], 'price' => 10.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Eyelash Applicator', 'ar' => 'أداة تركيب الرموش'], 'price' => 8.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Magnetic False Lashes', 'ar' => 'رموش مغناطيسية'], 'price' => 22.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Mini Makeup Fridge', 'ar' => 'ثلاجة مكياج مصغرة'], 'price' => 85.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Stainless Face Roller', 'ar' => 'أسطوانة وجه ستانلس'], 'price' => 18.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Jade Face Roller', 'ar' => 'أسطوانة وجه يشم'], 'price' => 25.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Gua Sha Stone', 'ar' => 'حجر غوا شا'], 'price' => 20.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Scented Beauty Candle', 'ar' => 'شمعة جمال معطرة'], 'price' => 28.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Velvet Hair Band', 'ar' => 'عصابة رأس مخملية'], 'price' => 10.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Makeup Cape', 'ar' => 'كيب مكياج'], 'price' => 15.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Face Mask Spatula', 'ar' => 'سباتولا قناع الوجه'], 'price' => 8.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Silicone Mixing Palette', 'ar' => 'لوحة خلط سيليكون'], 'price' => 12.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Makeup Brush Cleaning Mat', 'ar' => 'سجادة تنظيف فرش المكياج'], 'price' => 18.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Travel Bottles Set', 'ar' => 'طقم زجاجات سفر'], 'price' => 14.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Makeup Sponge Egg', 'ar' => 'إسفنجة مكياج بيضاوية'], 'price' => 15.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Foundation Mixing Palette', 'ar' => 'لوحة خلط فاونديشن'], 'price' => 10.00, 'sku_prefix' => 'ACC'],
                ['name' => ['en' => 'Silicone Brush Set', 'ar' => 'طقم فرش سيليكون'], 'price' => 16.00, 'sku_prefix' => 'ACC'],
            ];

            foreach ($products as $i => $productData) {
                $productNameEn = $productData['name']['en'];
                $productNameAr = $productData['name']['ar'];
                $basePrice = $productData['price'];
                $skuPrefix = $productData['sku_prefix'];

                $productType = in_array($skuPrefix, $variablePrefixes, true)
                    ? ProductType::VARIABLE
                    : ProductType::SIMPLE;

                $hasFlashSale = $weeklyFlashSalesCount > 0 && $this->randomBool(30);

                $hasDiscount = $this->randomBool(30);
                $discountType = $hasDiscount ? $this->randomElement($discountTypes) : DiscountType::PERCENTAGE;
                $discountPercent = $hasDiscount ? random_int(5, 30) : 0;
                $discountAmount = $hasDiscount ? round($basePrice * $discountPercent / 100, 2) : 0;
                $startDate = $hasDiscount ? now()->subDays(random_int(0, 15))->toDateString() : null;
                $endDate = $hasDiscount ? now()->addDays(random_int(15, 60))->toDateString() : null;

                $dims = $productDimensions[$productNameEn] ?? $categoryDimensionDefaults[$skuPrefix] ?? [];
                $product = Product::create([
                    'name' => [
                        'en' => $productNameEn,
                        'ar' => $productNameAr,
                    ],
                    'slug' => Str::slug($productNameEn) . '-' . Str::random(5),
                    'description' => [
                        'en' => 'Professional long-lasting ' . $productNameEn . ' with smooth coverage and premium ingredients for a flawless look.',
                        'ar' => $productNameAr . ' احترافي طويل الثبات بتغطية ناعمة ومكونات ممتازة للحصول على إطلالة خالية من العيوب.',
                    ],
                    'price' => $basePrice,
                    'product_type' => $productType,
                    'sku' => $skuPrefix . '-' . strtoupper(Str::random(8)),
                    'stock_quantity' => random_int(10, 200),
                    'reserved_quantity' => 0,
                    'pieces' => 1,
                    'sold_quantity' => random_int(0, 50),
                    'in_stock' => true,
                    'status' => 1,
                    'height' => (string) $dims['h'],
                    'width' => (string) $dims['w'],
                    'length' => (string) $dims['l'],
                    'weight' => (string) $dims['wt'],
                    'has_flash_sale' => $hasFlashSale,
                    'has_discount' => $hasDiscount,
                    'discount_type' => $discountType,
                    'discount_amount' => $discountAmount,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'price_after_discount' => null,
                    'price_after_flash_sale' => null,
                    'is_fast_shipping_available' => random_int(0, 1) == 1,
                ]);

                // images
                if ($productImagesCount > 0) {
                    for ($j = 0; $j < min(4, $productImagesCount); $j++) {
                        $image = $productImages[($i + $j) % $productImagesCount];
                        $product
                            ->addMedia($image->getPathname())
                            ->preservingOriginal()
                            ->usingFileName(Str::uuid() . '.' . $image->getExtension())
                            ->toMediaCollection('products', 'products');
                    }
                }

                // flash sale
                $flashSale = ($hasFlashSale && $weeklyFlashSalesCount > 0)
                    ? $weeklyFlashSales[$i % $weeklyFlashSalesCount]
                    : null;

                $product->update([
                    'has_flash_sale' => (bool) $flashSale,
                ]);

                if ($flashSale) {
                    $product->flash_sales()->attach($flashSale->id);
                }

                // coupon assignment
                if (!empty($couponIds)) {
                    $couponCount = rand(1, min(3, count($couponIds)));
                    $attachedCoupons = (array) array_rand(array_flip($couponIds), $couponCount);
                    $product->coupons()->attach($attachedCoupons);
                }

                // category assignment - match to specific sub-category + all ancestors
                $categoryName = $exceptionCategoryMap[$productNameEn] ?? $skuCategoryMap[$skuPrefix] ?? null;
                if ($categoryName && isset($allCategories[$categoryName])) {
                    $targetCategory = $allCategories[$categoryName];
                    $catIds = [$targetCategory->id];
                    $current = $targetCategory;
                    while ($current->parent_id !== null) {
                        $parent = $allCategoriesById->get($current->parent_id);
                        if (!$parent) break;
                        $catIds[] = $parent->id;
                        $current = $parent;
                    }
                    $product->categories()->attach(array_unique($catIds));
            } elseif (!empty($allCategories)) {
                    $fallback = $allCategories->random();
                    $product->categories()->attach($fallback->id);
                }

                // tag assignment - attach 1-4 random tags per product
                if ($tagsCount > 0) {
                    $tagCount = rand(1, min(4, $tagsCount));
                    $attachedTags = (array) array_rand(array_flip($allTags), $tagCount);
                    $product->tags()->attach($attachedTags);
                }

                // pricing
                $pricing = $pricingService->calculateProductPricing($product, $flashSale);

                $product->update([
                    'price_after_discount' => $pricing['price_after_discount'] ?? null,
                    'price_after_flash_sale' => $pricing['price_after_flash_sale'] ?? null,
                ]);
            }

            $this->command->info('ProductSeeder completed successfully. Created ' . count($products) . ' products.');

        } catch (\Exception $e) {
            $this->command->error('ProductSeeder failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function randomElement(array $items)
    {
        return empty($items) ? null : $items[array_rand($items)];
    }

    private function randomBool(int $truePercent): bool
    {
        return random_int(1, 100) <= $truePercent;
    }

}
