<?php

namespace App\Services\General\ProductEngine\Contract;

interface ProductTypeStrategy
{
    public function getProducts($limit);
}