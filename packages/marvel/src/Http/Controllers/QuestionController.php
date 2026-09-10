<?php


namespace Marvel\Http\Controllers;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Question;
use Marvel\Database\Models\Settings;
use Marvel\Database\Repositories\QuestionRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\QuestionCreateRequest;
use Marvel\Http\Requests\QuestionUpdateRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @OA\Tag(name="Questions", description="Product Q&A [STORE_OWNER, CUSTOMER]")
 *
 * @OA\Schema(
 *     schema="Question",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="question", type="string", example="Does this come in blue?"),
 *     @OA\Property(property="answer", type="string", nullable=true, example="Yes, it does."),
 *     @OA\Property(property="user_id", type="integer", example=1),
 *     @OA\Property(property="product_id", type="integer", example=10),
 *     @OA\Property(property="shop_id", type="integer", example=2),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="product", ref="#/components/schemas/Product"),
 *     @OA\Property(property="user", ref="#/components/schemas/User")
 * )
 */
class QuestionController extends CoreController
{
    public $repository;

    public function __construct(QuestionRepository $repository)
    {
        $this->repository = $repository;
    }


    /**
     * @OA\Get(
     *     path="/questions",
     *     operationId="getQuestions",
     *     tags={"Questions"},
     *     summary="List Questions",
     *     description="List questions. Filter by product_id to see questions for a product.",
     *     @OA\Parameter(name="product_id", in="query", description="Filter by Product ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="limit", in="query", description="Items per page", @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="page", in="query", description="Page number", @OA\Schema(type="integer", default=1)),
     *     @OA\Response(
     *         response=200,
     *         description="Questions retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Question")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $productId = $request['product_id'];

        if (isset($productId) && !empty($productId)) {
            if (null !== $request->user()) {
                $request->user()->id;
            }
            return $this->repository->where([
                ['product_id', '=', $productId],
                ['answer', '!=', null]
            ])->paginate($limit);
        }
        if (isset($request['answer']) && $request['answer'] === 'null') {
            return $this->repository->paginate($limit);
        }
        return $this->repository->where('answer', '!=', null)->paginate($limit);
    }

    /**
     * @OA\Post(
     *     path="/questions",
     *     operationId="createQuestion",
     *     tags={"Questions"},
     *     summary="Ask a Question",
     *     description="Submit a question for a product. Requires CUSTOMER permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"question", "product_id", "shop_id"},
     *             @OA\Property(property="question", type="string", example="Is this waterproof?"),
     *             @OA\Property(property="product_id", type="integer", example=10),
     *             @OA\Property(property="shop_id", type="integer", example=2)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Question submitted", @OA\JsonContent(ref="#/components/schemas/Question")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=400, description="Limit exceeded")
     * )
     */
    public function store(QuestionCreateRequest $request): Question
    {
        try {
            $productQuestionCount = $this->repository->where([
                'product_id' => $request['product_id'],
                'user_id' => $request->user()->id,
                'shop_id' => $request['shop_id']
            ])->count();

            $settings = Settings::getData();
            $maximumQuestionLimit = isset($settings['options']['maximumQuestionLimit']) ? $settings['options']['maximumQuestionLimit'] : 5;

            if ($maximumQuestionLimit <= $productQuestionCount) {
                throw new HttpException(400, MAXIMUM_QUESTION_LIMIT_EXCEEDED);
            }

            return $this->repository->storeQuestion($request);
        } catch (MarvelException $e) {
            throw new MarvelException(MAXIMUM_QUESTION_LIMIT_EXCEEDED);
        }
    }

    /**
     * @OA\Get(
     *     path="/questions/{id}",
     *     operationId="getQuestion",
     *     tags={"Questions"},
     *     summary="Get Question Details",
     *     description="Get a single question details",
     *     @OA\Parameter(name="id", in="path", required=true, description="Question ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Question details", @OA\JsonContent(ref="#/components/schemas/Question")),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show($id)
    {
        try {
            return $this->repository->findOrFail($id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Put(
     *     path="/questions/{id}",
     *     operationId="updateQuestion",
     *     tags={"Questions"},
     *     summary="Answer a Question",
     *     description="Update question (usually to add an answer). Requires STORE_OWNER permission for the shop.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Question ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"answer", "shop_id"},
     *             @OA\Property(property="answer", type="string", example="Yes, it is waterproof."),
     *             @OA\Property(property="shop_id", type="integer", example=2)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Question updated", @OA\JsonContent(ref="#/components/schemas/Question")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function update(QuestionUpdateRequest $request, $id)
    {
        $request->id = $id;
        return $this->updateQuestion($request, $id);
    }

    public function updateQuestion(Request $request)
    {
        try {
            if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
                $id = $request->id;
                return $this->repository->updateQuestion($request, $id);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Delete(
     *     path="/questions/{id}",
     *     operationId="deleteQuestion",
     *     tags={"Questions"},
     *     summary="Delete a Question",
     *     description="Delete a question. Requires permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Question ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Question deleted"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function destroy($id)
    {
        try {
            return $this->repository->findOrFail($id)->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Get(
     *     path="/my-questions",
     *     operationId="getMyQuestions",
     *     tags={"Questions"},
     *     summary="My Questions",
     *     description="List questions asked by the authenticated user.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="limit", in="query", description="Items per page", @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="page", in="query", description="Page number", @OA\Schema(type="integer", default=1)),
     *     @OA\Response(
     *         response=200,
     *         description="My questions retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Question")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function myQuestions(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;

        return $this->repository->where('user_id', auth()->user()->id)->with('product')->paginate($limit);
    }
}
