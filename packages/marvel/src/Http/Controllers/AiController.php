<?php

namespace Marvel\Http\Controllers;

use Marvel\Exceptions\MarvelException;
use Marvel\Facades\Ai;
use Marvel\Http\Requests\AiDescriptionRequest;

/**
 * @OA\Tag(name="AI", description="AI powered features - content generation and assistance")
 */
class AiController extends CoreController
{

    /**
     * @OA\Post(
     *     path="/generate-descriptions",
     *     operationId="generateAiDescription",
     *     tags={"AI"},
     *     summary="Generate product description with AI",
     *     description="Generate a creative product description using OpenAI based on product name and keywords. Requires STORE_OWNER or STAFF permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"product_name"},
     *             @OA\Property(property="product_name", type="string", example="Wireless Noise Cancelling Headphones"),
     *             @OA\Property(property="keywords", type="array", @OA\Items(type="string"), example={"premium", "long battery life", "sleek design"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Generated description",
     *         @OA\JsonContent(
     *             @OA\Property(property="description", type="string", example="Experience pure sound with these Premium Wireless Noise Cancelling Headphones...")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=500, description="AI service error")
     * )
     */
    public function generateDescription(AiDescriptionRequest $request): mixed
    {
        try {
            return Ai::generateDescription($request);
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }
}
