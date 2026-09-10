<?php

// =====================================================================
// ZEROTRUST RESET — remove harness artifacts so re-runs stay clean
// (must run BEFORE the start snapshot; safe on a fresh seed too)
// =====================================================================

// module pages created by the harness
$harnessSlugs = [
    'note', 'electronics', 'storefront', 'cache-home', 'cache-temp',
    'az-del-page', 'az-new', 'mass-page', 'vis-page', 'scenario-store',
    'debugpage',
];
$harnessPages = DB::table('content_pages')->whereIn('slug', $harnessSlugs)->pluck('id');

// harness sections: attached to harness pages OR orphan (seed sections are all on 'home')
DB::table('sections')->whereIn('content_page_id', $harnessPages)->delete();
DB::table('sections')->whereNull('content_page_id')->delete();
DB::table('content_pages')->whereIn('id', $harnessPages)->delete();

// harness section types + their settings
$harnessTypes = ['new-tag', 'new-tag-renamed', 'az-del', 'az-new', 'sc-banners', 'sc-products'];
$cacheTypes = DB::table('section_types')->where('type', 'like', 'cache-type-%')->pluck('id');
$cacheTypes2 = DB::table('section_types')->where('type', 'like', 'renamed-cache-type-%')->pluck('id');
$typeIds = array_merge(
    DB::table('section_types')->whereIn('type', $harnessTypes)->pluck('id')->all(),
    $cacheTypes->all(),
    $cacheTypes2->all(),
);
DB::table('section_type_settings')->whereIn('section_type_id', $typeIds)->delete();
DB::table('section_types')->whereIn('id', $typeIds)->delete();

// harness entities
DB::table('tags')->whereIn('slug', ['home-decor', 'deals'])->delete();
DB::table('categories')->where('slug', 'electronics')->delete();
DB::table('products')->where('slug', 'like', 'headphones-%')->orWhere('slug', 'scenario-product')->delete();
DB::table('banners')->whereIn('title', ['Mega Sale Banner', 'Scenario Banner'])->delete();
DB::table('sliders')->where('slug', 'spring')->delete();
DB::table('promotions')->where('slug', 'ramadan')->delete();
DB::table('flash_sales')->where('slug', 'flash')->delete();
DB::table('brands')->where('slug', 'samsung')->delete();
DB::table('coupons')->where('slug', 'zt-coupon')->delete();

// auth artifacts
$ztUsers = DB::table('users')->whereIn('email', ['zt-viewer@meem.test', 'zt-plain@meem.test', 'zt-admin@meem.test'])->pluck('id');
DB::table('personal_access_tokens')->whereIn('tokenable_id', $ztUsers)->where('tokenable_type', 'Marvel\Database\Models\User')->delete();
DB::table('model_has_roles')->whereIn('model_id', $ztUsers)->delete();
DB::table('users')->whereIn('id', $ztUsers)->delete();
DB::table('role_has_permissions')->whereIn('role_id', [9001, 9002])->delete();
DB::table('model_has_roles')->whereIn('role_id', [9001, 9002])->delete();
DB::table('roles')->whereIn('id', [9001, 9002])->delete();

// clear the public-facing caches so subsequent runs measure real hits
Cache::tags(['content_pages', 'products', 'banners', 'sliders', 'tags', 'categories', 'brands', 'coupons', 'promotions', 'flash_sales', 'settings'])->flush();

ev('  [reset] harness artifacts removed; content_pages=' . DB::table('content_pages')->count() . ' sections=' . DB::table('sections')->count() . ' users=' . DB::table('users')->count());