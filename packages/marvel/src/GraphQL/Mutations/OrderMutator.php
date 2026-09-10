<?php


namespace Marvel\GraphQL\Mutation;

use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Marvel\Exceptions\MarvelException;
use Marvel\Facades\Shop;

/**
 * D2: legacy GraphQL order creation (store) and payment submission
 * (createOrderPayment) were removed. The REST checkout flow is the single
 * authoritative Order creation + inventory reservation path.
 */
class OrderMutator
{

    public function update($rootValue, array $args, GraphQLContext $context)
    {
        return Shop::call('Marvel\Http\Controllers\OrderController@updateOrder', $args);
    }
}
