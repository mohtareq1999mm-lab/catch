<?php


namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Conversation;
use Marvel\Database\Models\Message;
use Marvel\Database\Models\Participant;
use Marvel\Database\Repositories\ConversationRepository;
use Marvel\Database\Repositories\MessageRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\MessageCreateRequest;
use Prettus\Validator\Exceptions\ValidatorException;


/**
 * @OA\Tag(name="Messages", description="Messages within a conversation")
 *
 * @OA\Schema(
 *     schema="Message",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="conversation_id", type="integer", example=1),
 *     @OA\Property(property="sender_id", type="integer", example=10),
 *     @OA\Property(property="message", type="string", example="Hello, I have a question about my order."),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class MessageController extends CoreController
{
    public $repository;
    public $conversationRepository;

    public function __construct(MessageRepository $repository, ConversationRepository $conversationRepository)
    {
        $this->repository = $repository;
        $this->conversationRepository = $conversationRepository;
    }

    /**
     * @OA\Get(
     *     path="/messages/{conversation_id}",
     *     operationId="getMessages",
     *     tags={"Messages"},
     *     summary="List Messages",
     *     description="Get a paginated list of messages for a specific conversation.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="conversation_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *     @OA\Response(
     *         response=200,
     *         description="Messages retrieved",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Message")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - not a participant")
     * )
     */
    public function index(Request $request, $conversation_id)
    {
        $request->conversation_id = $conversation_id;

        $user = Auth::user();
        $conversation = $this->conversationRepository->findOrFail($conversation_id);
        abort_unless($user->shop_id === $conversation->shop_id || in_array( $conversation->shop_id, $user->shops->pluck('id')->toArray()) || $user->id === $conversation->user_id, 404, 'Unauthorized');

        $messages = $this->fetchMessages($request);

        $limit = $request->limit ? $request->limit : 15;
        return $messages->paginate($limit);

    }

    /**
     * @OA\Post(
     *     path="/messages/seen",
     *     operationId="seenMessage",
     *     tags={"Messages"},
     *     summary="Mark Messages as Seen",
     *     description="Mark all messages in a conversation as read for the current user.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"conversation_id"},
     *             @OA\Property(property="conversation_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Messages marked as read"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function seenMessage(Request $request)
    {
        return $this->seen($request->conversation_id);
    }

    public function seen($conversation_id)
    {
        $participant = Participant::where('conversation_id', $conversation_id)
            ->whereNull('last_read')
            ->where(function($query){
                $query->where('user_id', auth()->user()->id);
                $query->where('type', 'user');
            })
            ->update(['last_read' => new Carbon()]);

        if(0 === $participant) {
            $participant = Participant::where('conversation_id', $conversation_id)
                ->whereNull('last_read')
                ->where(function($query){
                    $query->whereIn('shop_id', auth()->user()->shops->pluck('id'));
                    $query->orWhere('shop_id', auth()->user()->shop_id);
                    $query->where('type', 'shop');
                })
                ->update(['last_read' => new Carbon()]);
        }

        return $participant;
    }

    public function fetchMessages(Request $request)
    {

        $user = $request->user();
        $conversation_id = $request->conversation_id;

        try {
            $conversation = Conversation::where('id', $conversation_id)
                ->where('user_id', $user->id)
                ->orWhereIn('shop_id', $user->shops()->pluck('id'))
                ->orWhere('shop_id', $user->shop_id)
                ->with(['user', 'shop'])->first();

            if(empty($conversation)) {
                throw new MarvelException(NOT_AUTHORIZED);
            }

            return $this->repository->where('conversation_id', $conversation_id)
                ->with(['conversation.shop', 'conversation.user.profile'])
                ->orderBy('id', 'DESC');
        } catch (\Exception $e) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
    }

    /**
     * @OA\Post(
     *     path="/messages/{conversation_id}",
     *     operationId="sendMessage",
     *     tags={"Messages"},
     *     summary="Send Message",
     *     description="Send a new message in a conversation.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="conversation_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"message"},
     *             @OA\Property(property="message", type="string", example="Hello!"),
     *             @OA\Property(property="type", type="string", enum={"shop", "user"}, example="user")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Message sent", @OA\JsonContent(ref="#/components/schemas/Message")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function store(MessageCreateRequest $request, $conversation_id)
    {
        $request->conversation_id = $conversation_id;

        return $this->storeMessage($request);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return mixed
     * @throws ValidatorException
     */
    public function storeMessage(Request $request)
    {
        return $this->repository->storeMessage($request);
    }
}
