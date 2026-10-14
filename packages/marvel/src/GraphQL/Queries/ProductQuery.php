<?php


namespace Marvel\GraphQL\Queries;

use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Marvel\Facades\Shop;

class ProductQuery
{
    public function relatedProducts($rootValue, array $args, GraphQLContext $context)
    {
        $args['slug'] = $rootValue->slug;
        return Shop::call('Marvel\Http\Controllers\ProductController@relatedProducts', $args);
    }
    public function fetchProducts($rootValue, array $args, GraphQLContext $context)
    {
        return Shop::call('Marvel\Http\Controllers\ProductController@fetchProducts', $args);
    }
    public function fetchProductStock($rootValue, array $args, GraphQLContext $context)
    {
        return Shop::call('Marvel\Http\Controllers\ProductController@fetchProductStock', $args);
    }
    public function fetchDraftedProducts($rootValue, array $args, GraphQLContext $context)
    {
        return Shop::call('Marvel\Http\Controllers\ProductController@fetchDraftedProducts', $args);
    }
}
