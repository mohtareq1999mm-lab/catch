<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum quantity per cart line (F-03)
    |--------------------------------------------------------------------------
    |
    | Upper bound enforced on the *resulting* cart-line quantity across every
    | cart mutation path (create/add, update/set, bulk add, and the
    | CartInventoryService choke point). Stock availability is still validated
    | atomically at checkout; this limit only rejects obviously unreasonable
    | quantities early. Override via CART_MAX_ITEM_QUANTITY.
    |
    */
    'max_item_quantity' => (int) env('CART_MAX_ITEM_QUANTITY', 100),
];
