<?php

namespace App\Services\General;

use Marvel\Database\Models\Settings;

class SettingService {
    public function getSetting()
    {
        $setting = Settings::first();
        return $setting;
    }
}
