<?php

// W5 translation installer (temporary tooling; removed after run).

$keys = [
    'en' => [
        'ERROR.DIGITAL_ASSET_INVALID_URL' => 'The external URL must be a valid HTTPS address.',
        'ERROR.DIGITAL_ASSET_URL_BLOCKED' => 'This URL host is not allowed.',
        'ERROR.DIGITAL_ASSET_URL_UNRESOLVABLE' => 'This URL host could not be resolved.',
        'ERROR.DIGITAL_LICENSE_NOT_ALLOCATED' => 'No license key has been allocated for this item yet. Please try again later.',
        'ERROR.DIGITAL_LICENSE_ALREADY_REVEALED' => 'This license key has already been revealed and cannot be shown again.',
        'ERROR.DIGITAL_ACCESS_SECRET_REQUIRED' => 'An access credential is required for this asset type.',
        'ERROR.DIGITAL_LICENSE_POOL_ONLY' => 'License keys can only be added to license pool assets.',
    ],
    'ar' => [
        'ERROR.DIGITAL_ASSET_INVALID_URL' => 'يجب أن يكون الرابط الخارجي عنوان HTTPS صالحاً.',
        'ERROR.DIGITAL_ASSET_URL_BLOCKED' => 'مضيف هذا الرابط غير مسموح به.',
        'ERROR.DIGITAL_ASSET_URL_UNRESOLVABLE' => 'تعذر حل اسم مضيف هذا الرابط.',
        'ERROR.DIGITAL_LICENSE_NOT_ALLOCATED' => 'لم يتم تخصيص مفتاح ترخيص لهذا العنصر بعد. يرجى المحاولة لاحقاً.',
        'ERROR.DIGITAL_LICENSE_ALREADY_REVEALED' => 'تم كشف مفتاح الترخيص هذا مسبقاً ولا يمكن عرضه مرة أخرى.',
        'ERROR.DIGITAL_ACCESS_SECRET_REQUIRED' => 'بيانات اعتماد الوصول مطلوبة لهذا النوع من الأصول.',
        'ERROR.DIGITAL_LICENSE_POOL_ONLY' => 'لا يمكن إضافة مفاتيح الترخيص إلا لأصول مجموعة التراخيص.',
    ],
    'de' => [
        'ERROR.DIGITAL_ASSET_INVALID_URL' => 'Die externe URL muss eine gültige HTTPS-Adresse sein.',
        'ERROR.DIGITAL_ASSET_URL_BLOCKED' => 'Dieser URL-Host ist nicht erlaubt.',
        'ERROR.DIGITAL_ASSET_URL_UNRESOLVABLE' => 'Der Host dieser URL konnte nicht aufgelöst werden.',
        'ERROR.DIGITAL_LICENSE_NOT_ALLOCATED' => 'Für dieses Element wurde noch kein Lizenzschlüssel zugewiesen. Bitte später erneut versuchen.',
        'ERROR.DIGITAL_LICENSE_ALREADY_REVEALED' => 'Dieser Lizenzschlüssel wurde bereits aufgedeckt und kann nicht erneut angezeigt werden.',
        'ERROR.DIGITAL_ACCESS_SECRET_REQUIRED' => 'Für diesen Asset-Typ ist ein Zugriffs-Credential erforderlich.',
        'ERROR.DIGITAL_LICENSE_POOL_ONLY' => 'Lizenzschlüssel können nur zu Lizenzpool-Assets hinzugefügt werden.',
    ],
];

foreach ($keys as $locale => $map) {
    $file = realpath(__DIR__ . '/../../resources/lang/' . $locale . '/message.php');
    $source = file_get_contents($file);

    $anchor = "'ERROR.DIGITAL_ASSET_INVALID_MIME'";
    $pos = strpos($source, $anchor);
    if ($pos === false) {
        echo "{$locale}: anchor missing\n";
        continue;
    }
    $eol = strpos($source, "\n", $pos);
    $insert = '';
    foreach ($map as $k => $v) {
        if (str_contains($source, "'{$k}'")) {
            continue; // idempotent
        }
        $escaped = str_replace("'", "\\'", $v);
        $insert .= "\n    '{$k}' => '{$escaped}',";
    }
    if ($insert === '') {
        echo "{$locale}: nothing to insert\n";
        continue;
    }
    $source = substr($source, 0, $eol + 1) . ltrim($insert) . substr($source, $eol + 1);
    file_put_contents($file, $source);
    echo "{$locale}: inserted\n";
}
