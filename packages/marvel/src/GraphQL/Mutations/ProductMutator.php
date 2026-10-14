<?php


namespace Marvel\GraphQL\Mutation;


use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Marvel\Exports\src\Facades\Shop;

class ProductMutator
{
    public function store($rootValue, array $args, GraphQLContext $context)
    {
        $this->authorize($context, Permission::CREATE_PRODUCT);
        return Shop::call('Marvel\Http\Controllers\ProductController@ProductStore', $args);
    }

    public function updateProduct($rootValue, array $args, GraphQLContext $context)
    {
        $this->authorize($context, Permission::UPDATE_PRODUCT);
        return Shop::call('Marvel\Http\Controllers\ProductController@updateProduct', $args);
    }

    public function importProducts($rootValue, array $args, GraphQLContext $context)
    {
        $this->authorize($context, Permission::IMPORT_PRODUCT);
        return Shop::call('Marvel\Http\Controllers\ProductController@importProducts', $args);
    }
    public function importVariationOptions($rootValue, array $args, GraphQLContext $context)
    {
        $this->authorize($context, Permission::IMPORT_PRODUCT);
        return Shop::call('Marvel\Http\Controllers\ProductController@importVariationOptions', $args);
    }
    public function calculateRentalPrice($rootValue, array $args, GraphQLContext $context)
    {
        return Shop::call('Marvel\Http\Controllers\ProductController@calculateRentalPrice', $args);
    }
    public function destroy($rootValue, array $args, GraphQLContext $context)
    {
        $this->authorize($context, Permission::DELETE_PRODUCT);
        return Shop::call('Marvel\Http\Controllers\ProductController@destroyProduct', $args);
    }

    private function authorize(GraphQLContext $context, string $permission): void
    {
        $user = $context->user();

        if (!$user) {
            throw new AuthorizationException('Unauthenticated.');
        }

        $allowed = $user->hasRole(Role::SUPER_ADMIN) || $user->hasPermissionTo($permission);

        if (!$allowed) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
