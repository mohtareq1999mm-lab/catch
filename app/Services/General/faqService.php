<?php

namespace App\Services\General;

use Marvel\Database\Models\Faqs;

class faqService
{

    public function getfaqs()
    {
        return Faqs::active()->get();
    }
}
