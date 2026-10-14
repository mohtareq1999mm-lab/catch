<?php


namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Conversation;
use Marvel\Database\Models\Shop;
use Marvel\Database\Repositories\ConversationRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\ConversationCreateRequest;
use Prettus\Validator\Exceptions\ValidatorException;


/**
 * @OA\Tag(name="Conversations", description="Direct messaging between customers and shops")
 *
 * @OA\Schema(
 *     schema="Conversation",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="shop_id", type="integer", example=2),
 *     @OA\Property(property="user_id", type="integer", example=10),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="shop", ref="#/components/schemas/Shop"),
 *     @OA\Property(property="user", ref="#/components/schemas/User"),
 *     @OA\Property(property="latest_message", type="object", nullable=true)
 * )
 */
class ConversationController extends CoreController
{
    public $repository;

    public function __construct(ConversationRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @OA\Get(
     *     path="/conversations",
     *     operationId="getConversations",
     *     tags={"Conversations"},
     *     summary="List Conversations",
     *     description="Get a paginated list of conversations for the current user (as customer or shop owner/staff).",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *     @OA\Response(
     *         response=200,
     *         description="Conversations retrieved",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Conversation")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $conversation = $this->fetchConversations($request);

        return $conversation->paginate($limit);
    }

    /**
     * @OA\Get(
     *     path="/conversations/{id}",
     *     operationId="getConversation",
     *     tags={"Conversations"},
     *     summary="Get Conversation Details",
     *     description="Retrieve details and messages of a single conversation.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Conversation retrieved", @OA\JsonContent(ref="#/components/schemas/Conversation")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Conversation not found")
     * )
     */
    public function show($conversation_id)
    {
        $user = Auth::user();
        $conversation = $this->repository->with(['shop', 'user.profile'])->findOrFail($conversation_id);
        abort_unless($user->shop_id === $conversation->shop_id || in_array($conversation->shop_id, $user->shops->pluck('id')->toArray()) || $user->id === $conversation->user_id, 404, 'Unauthorized');

        return $conversation;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Query|Conversation[]
     */
    public function fetchConversations(Request $request)
    {
        return $this->repository->where(function ($query) {
            $user = Auth::user();
            $query->where('user_id', $user->id);
            $query->orWhereIn('shop_id', $user->shops->pluck('id'));
            $query->orWhere('shop_id', $user->shop_id);
            $query->orderBy('updated_at', 'desc');
        })->with(['user.profile', 'shop']);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param ConversationCreateRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(ConversationCreateRequest $request)
    {
        $user = $request->user();
        if (empty($user)) {
            throw new MarvelException(NOT_AUTHORIZED);
        }

        $shop = Shop::findOrFail($request->shop_id);
        if ($shop->owner_id === $request->user()->id) {
            throw new MarvelException(YOU_CAN_NOT_SEND_MESSAGE_TO_YOUR_OWN_SHOP);
        }
        if ($request->shop_id === $request->user()->shop_id) {
            throw new MarvelException(YOU_CAN_NOT_SEND_MESSAGE_TO_YOUR_OWN_SHOP);
        }
        return $this->repository->firstOrCreate([
            'user_id' => $user->id,
            'shop_id' => $request->shop_id
        ]);
    }
}
