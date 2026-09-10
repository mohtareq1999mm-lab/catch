<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Services\Currency\CurrencyService;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Repositories\SettingsRepository;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Database\Models\Settings;
use Marvel\Http\Requests\SettingsRequest;
use Marvel\Http\Resources\SettingResource;
use Marvel\Traits\ApiResponse;
use Marvel\Traits\MediaManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SettingsController extends CoreController
{
    use ApiResponse,  MediaManager, HasCache;
    public $repository;

    public function __construct(SettingsRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware("permission:" . Permission::VIEW_SETTINGS, ["only" => ["index"]]);
        $this->middleware("permission:" . Permission::UPDATE_SETTINGS, ["only" => ["update"]]);
    }

    public function index(Request $request)
    {
        $settings = Settings::first();
        $settingCache = $this->remember(FrontendResource::SETTINGS->value, md5($request->fullUrl()), $settings);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SettingResource::make($settingCache));
    }



    public function update(SettingsRequest $request)
    {
        $settings = Settings::first();


        $data = $request->only([
            'site_name',
            'site_desc',
            'meta_desc',
            'site_copy_right',
            'site_email',
            'email_support',
            'facebook',
            'instagram',
            'linkedin',
            'promotion_video_url',
            'youtube',
            'tiktok',
            'snapchat',
            'phone',
            'fast_shipping_page_publish',
            'options',
            "minimum_order_amount",
            'order_tax_enabled',
            'order_tax_rate',
        ]);

        if ($request->has('currency_selection_enabled')) {
            $options = array_merge($settings->options ?? [], $data['options'] ?? []);
            $options['currency_selection_enabled'] = $request->boolean('currency_selection_enabled');
            $data['options'] = $options;
        }

        $settings->update($data);

        if ($request->has('currency_selection_enabled')) {
            app(CurrencyService::class)->forgetEffectiveCode();
        }


        if ($request->has('logo')) {
            if (!$this->updateSingleImage($request, 'logo', $settings, 'logo-setting', 'settings')) {
                throw new HttpException(422, __('message.ERROR.LOGO_UPLOAD_FAILED'));
            }
        }
        if ($request->has('footer_logo')) {
            if (!$this->updateSingleImage($request, 'footer_logo', $settings, 'footer_logo-setting', 'settings')) {
                throw new HttpException(422, __('message.ERROR.FOOTER_LOGO_UPLOAD_FAILED'));
            }
        }
        if ($request->has('favicon')) {
            if (!$this->updateSingleImage($request, 'favicon', $settings, 'favicon-setting', 'settings')) {
                throw new HttpException(422, __('message.ERROR.FAVICON_UPLOAD_FAILED'));
            }
        }
        $this->flushTag(FrontendResource::SETTINGS->value);
        $settings = Settings::first();
        return $this->apiResponse(SETTINGS_UPDATED_SUCCESSFULLY, 200, true, SettingResource::make($settings));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return array
     */
    public function destroy($id)
    {
        throw new MarvelException(ACTION_NOT_VALID);
    }
}
