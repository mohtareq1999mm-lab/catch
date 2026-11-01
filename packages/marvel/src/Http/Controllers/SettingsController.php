<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Audit\ActivityAuditService;
use App\Services\Currency\CurrencyService;
use App\Services\Payment\GatewaySettingsService;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
    private GatewaySettingsService $gateways;

    public function __construct(SettingsRepository $repository, GatewaySettingsService $gateways)
    {
        $this->repository = $repository;
        $this->gateways = $gateways;
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

        $oldAttributes = $settings->getAttributes();

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

        // Gateway toggles submitted through the Site Settings endpoint.
        // Validated (incl. unknown codes) BEFORE anything is persisted so a
        // bad gateway block can never partly save.
        $gatewayPatches = $this->gatewayPatchesFromRequest($request);

        // Options round-trip protection: `options` is replaced verbatim
        // below, so a settings save that sends its own options subset (a
        // typical dashboard payload) must not silently drop stored gateway
        // toggles.
        if (is_array($data['options'] ?? null)
            && !array_key_exists('payment_gateways', $data['options'])) {
            $existingOptions = $settings->options;
            if (is_array($existingOptions) && array_key_exists('payment_gateways', $existingOptions)) {
                $data['options']['payment_gateways'] = $existingOptions['payment_gateways'];
            }
        }

        $settings->update($data);

        // Apply the gateway patches through the canonical write service
        // (allowlist {enabled, ...}, lockForUpdate transaction). The audit
        // record and the cache flush below then observe the final state.
        foreach ($gatewayPatches as $code => $enabled) {
            $this->gateways->updateOverride($code, ['enabled' => $enabled]);
        }

        // Flush BEFORE the media-handling blocks below: an image failure
        // throws after the row/toggles persisted, and the tagged cache must
        // reflect persisted state the moment anything is committed.
        $this->flushTag(FrontendResource::SETTINGS->value);

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
        $settings = Settings::first();

        ActivityAuditService::recordModel(
            $settings,
            'settings_updated',
            'settings',
            __('activity.settings_updated'),
            old: $oldAttributes,
            new: $settings->getAttributes(),
        );

        return $this->apiResponse(SETTINGS_UPDATED_SUCCESSFULLY, 200, true, SettingResource::make($settings));
    }

    /**
     * payment_gateways may be toggled from PUT /api/v1/settings using the
     * same shape the settings GET responses expose, minus the read-only
     * verdicts: {"payment_gateways": {"tap": {"enabled": true}}}.
     *
     * Only {enabled} is honored (write allowlist; `available` is computed
     * and never writable, unknown keys inside a per-gateway entry are
     * ignored). Unknown gateway codes raise a validation error BEFORE any
     * settings field is persisted, so the save is all-or-nothing from the
     * caller's perspective.
     *
     * @return array<string, bool> code => enabled
     */
    private function gatewayPatchesFromRequest(Request $request): array
    {
        $payload = $request->input('payment_gateways');

        if ($payload === null) {
            return [];
        }

        if (!is_array($payload)) {
            throw new HttpException(422, 'payment_gateways must be an object keyed by gateway code.');
        }

        $patches = [];
        foreach ($payload as $code => $state) {
            $code = (string) $code;

            if (!is_array($state) || !array_key_exists('enabled', $state)) {
                throw new HttpException(422, "payment_gateways.{$code}.enabled must be a boolean.");
            }

            if ($this->gateways->adminRow($code) === null) {
                throw ValidationException::withMessages([
                    "payment_gateways.{$code}" => ['Unknown payment gateway.'],
                ]);
            }

            $patches[$code] = (bool) $state['enabled'];
        }

        return $patches;
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
