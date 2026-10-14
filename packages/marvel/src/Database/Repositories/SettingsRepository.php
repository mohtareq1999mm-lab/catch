<?php


namespace Marvel\Database\Repositories;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Settings;
use Marvel\Traits\MediaManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SettingsRepository extends BaseRepository
{
    use MediaManager;
    /**
     * Configure the Model
     **/
    public function model()
    {
        return Settings::class;
    }

    public function getApplicationSettings()
    {
        return $this->getAppSettingsData();
    }

    

    public function updateSetting($data, $id)
    {
        try {
            DB::beginTransaction();
            $this->skipCache()->resetModel();
            $setting = $this->first();
            $setting->update($data->except('logo', 'favicon'));
            if (isset($data['logo'])) {
                if (!$this->updateSingleImage($data, 'logo', $setting, 'logo-setting', 'settings')) {
                    throw new HttpException(422, __('message.ERROR.LOGO_UPLOAD_FAILED'));
                }
            }
            if (isset($data['favicon'])) {
                if (!$this->updateSingleImage($data, 'favicon', $setting, 'favicon-setting', 'settings')) {
                    throw new HttpException(422, __('message.ERROR.FAVICON_UPLOAD_FAILED'));
                }
            }
            DB::commit();
            return $setting;

        } catch (HttpException $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new HttpException(500, 'Settings update failed, please try again.');
        }
    }

}