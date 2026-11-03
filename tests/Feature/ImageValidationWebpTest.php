<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Marvel\Http\Requests\BannerCreateRequest;
use Marvel\Http\Requests\BannerUpdateRequest;
use Marvel\Http\Requests\BrandCreateRequest;
use Marvel\Http\Requests\BrandUpdateRequest;
use Marvel\Http\Requests\ProductCreateRequest;
use Marvel\Http\Requests\ProductUpdateRequest;
use Marvel\Http\Requests\SettingsRequest;
use Marvel\Http\Requests\SliderCreateRequest;
use Marvel\Http\Requests\SliderUpdateRequest;
use Marvel\Http\Requests\UserCreateRequest;
use Marvel\Http\Requests\UserUpdateRequest;
use Tests\TestCase;

class ImageValidationWebpTest extends TestCase
{
    public function test_product_requests_accept_webp_images(): void
    {
        $webpImage = UploadedFile::fake()->image('test_product.webp');
        $pdfFile = UploadedFile::fake()->create('document.pdf', 100);

        // ProductCreateRequest
        $rules = (new ProductCreateRequest())->rules();
        $vWebp = Validator::make(['images' => [$webpImage]], [
            'images' => $rules['images'],
            'images.*' => $rules['images.*'],
        ]);
        $this->assertFalse($vWebp->fails(), 'ProductCreateRequest must accept webp images: ' . json_encode($vWebp->errors()->all()));

        $vPdf = Validator::make(['images' => [$pdfFile]], [
            'images' => $rules['images'],
            'images.*' => $rules['images.*'],
        ]);
        $this->assertTrue($vPdf->fails(), 'ProductCreateRequest must reject pdf files.');

        // ProductUpdateRequest
        $updateRules = (new ProductUpdateRequest())->rules();
        $vUpdate = Validator::make(['images' => [$webpImage]], [
            'images' => $updateRules['images'],
            'images.*' => $updateRules['images.*'],
        ]);
        $this->assertFalse($vUpdate->fails(), 'ProductUpdateRequest must accept webp images: ' . json_encode($vUpdate->errors()->all()));
    }

    public function test_banner_requests_accept_webp_images(): void
    {
        $webpImage = UploadedFile::fake()->image('banner_desktop.webp');

        // BannerCreateRequest
        $createRules = (new BannerCreateRequest())->rules();
        $vCreateDesk = Validator::make(['image_desktop' => $webpImage], ['image_desktop' => $createRules['image_desktop']]);
        $this->assertFalse($vCreateDesk->fails(), 'BannerCreateRequest image_desktop must accept webp.');

        $vCreateMob = Validator::make(['image_mobile' => $webpImage], ['image_mobile' => $createRules['image_mobile']]);
        $this->assertFalse($vCreateMob->fails(), 'BannerCreateRequest image_mobile must accept webp.');

        // BannerUpdateRequest
        $updateRules = (new BannerUpdateRequest())->rules();
        $vUpdateDesk = Validator::make(['image_desktop' => $webpImage], ['image_desktop' => $updateRules['image_desktop']]);
        $this->assertFalse($vUpdateDesk->fails(), 'BannerUpdateRequest image_desktop must accept webp.');

        $vUpdateMob = Validator::make(['image_mobile' => $webpImage], ['image_mobile' => $updateRules['image_mobile']]);
        $this->assertFalse($vUpdateMob->fails(), 'BannerUpdateRequest image_mobile must accept webp.');
    }

    public function test_brand_requests_accept_webp_images(): void
    {
        $webpImage = UploadedFile::fake()->image('brand_logo.webp');

        $createRules = (new BrandCreateRequest())->rules();
        $vCreate = Validator::make(['image-desktop' => $webpImage], ['image-desktop' => $createRules['image-desktop']]);
        $this->assertFalse($vCreate->fails(), 'BrandCreateRequest image-desktop must accept webp.');

        $updateRules = (new BrandUpdateRequest())->rules();
        $vUpdate = Validator::make(['image-desktop' => $webpImage], ['image-desktop' => $updateRules['image-desktop']]);
        $this->assertFalse($vUpdate->fails(), 'BrandUpdateRequest image-desktop must accept webp.');
    }

    public function test_slider_requests_accept_webp_images(): void
    {
        $webpImage = UploadedFile::fake()->image('slide.webp');

        $createRules = (new SliderCreateRequest())->rules();
        $vCreate = Validator::make(['image_desktop' => $webpImage], ['image_desktop' => $createRules['image_desktop']]);
        $this->assertFalse($vCreate->fails(), 'SliderCreateRequest image_desktop must accept webp.');

        $updateRules = (new SliderUpdateRequest())->rules();
        $vUpdate = Validator::make(['image_desktop' => $webpImage], ['image_desktop' => $updateRules['image_desktop']]);
        $this->assertFalse($vUpdate->fails(), 'SliderUpdateRequest image_desktop must accept webp.');
    }

    public function test_settings_request_accepts_webp(): void
    {
        $webpImage = UploadedFile::fake()->image('logo.webp');

        $rules = (new SettingsRequest())->rules();
        $vLogo = Validator::make(['logo' => $webpImage], ['logo' => $rules['logo']]);
        $this->assertFalse($vLogo->fails(), 'SettingsRequest logo must accept webp.');

        $vFooter = Validator::make(['footer_logo' => $webpImage], ['footer_logo' => $rules['footer_logo']]);
        $this->assertFalse($vFooter->fails(), 'SettingsRequest footer_logo must accept webp.');

        $vFav = Validator::make(['favicon' => $webpImage], ['favicon' => $rules['favicon']]);
        $this->assertFalse($vFav->fails(), 'SettingsRequest favicon must accept webp.');
    }

    public function test_user_requests_accept_webp_avatar(): void
    {
        $webpImage = UploadedFile::fake()->image('avatar.webp');

        $createRules = (new UserCreateRequest())->rules();
        $vCreate = Validator::make(['avatar' => $webpImage], ['avatar' => $createRules['avatar']]);
        $this->assertFalse($vCreate->fails(), 'UserCreateRequest avatar must accept webp.');

        $updateRules = (new UserUpdateRequest())->rules();
        $vUpdate = Validator::make(['avatar' => $webpImage], ['avatar' => $updateRules['avatar']]);
        $this->assertFalse($vUpdate->fails(), 'UserUpdateRequest avatar must accept webp.');
    }
}
