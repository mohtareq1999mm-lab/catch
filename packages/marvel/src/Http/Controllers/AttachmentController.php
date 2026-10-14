<?php


namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Attachment;
use Marvel\Database\Repositories\AttachmentRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\AttachmentRequest;
use Prettus\Validator\Exceptions\ValidatorException;

/**
 * @OA\Tag(
 *     name="Attachments",
 *     description="File upload and management endpoints"
 * )
 */
class AttachmentController extends CoreController
{
    public $repository;

    public function __construct(AttachmentRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Display a listing of the resource.
     *
     * @OA\Get(
     *     path="/attachments",
     *     operationId="getAttachments",
     *     tags={"Attachments"},
     *     summary="Get list of attachments",
     *     description="Returns paginated list of all uploaded attachments",
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number for pagination",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of items per page",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="original", type="string", example="https://example.com/storage/uploads/image.jpg"),
     *                     @OA\Property(property="thumbnail", type="string", example="https://example.com/storage/uploads/conversions/image-thumbnail.jpg"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             ),
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="per_page", type="integer"),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     *
     * @param Request $request
     * @return Collection|Attachment[]
     */
    public function index(Request $request)
    {
        return $this->repository->paginate();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @OA\Post(
     *     path="/attachments",
     *     operationId="storeAttachment",
     *     tags={"Attachments"},
     *     summary="Upload new attachment(s)",
     *     description="Upload one or multiple files. Rate limited to 10 uploads per minute per user. Supports images, documents, and other file types. Images automatically generate thumbnails.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="File(s) to upload",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"attachment[]"},
     *                 @OA\Property(
     *                     property="attachment[]",
     *                     type="array",
     *                     description="Array of files to upload (supports multiple files)",
     *                     @OA\Items(type="string", format="binary")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="File(s) uploaded successfully",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=123),
     *                 @OA\Property(property="original", type="string", example="https://example.com/storage/uploads/file.jpg"),
     *                 @OA\Property(property="thumbnail", type="string", example="https://example.com/storage/uploads/thumb_file.jpg", description="Only for images")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error - Invalid file type or size"
     *     ),
     *     @OA\Response(
     *         response=429,
     *         description="Too Many Requests - Rate limit exceeded (10/min)"
     *     )
     * )
     *
     * @param AttachmentRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(AttachmentRequest $request)
    {
        $urls = [];
        foreach ($request->attachment as $media) {
            $attachment = new Attachment;
            $attachment->save();
            $attachment->addMedia($media)->toMediaCollection();
            foreach ($attachment->getMedia() as $media) {
                if (strpos($media->mime_type, 'image/') !== false) {
                    $converted_url = [
                        'thumbnail' => $media->getUrl('thumbnail'),
                        'original' => $media->getUrl(),
                        'id' => $attachment->id
                    ];
                } else {
                    $converted_url = [
                        'thumbnail' => '',
                        'original' => $media->getUrl(),
                        'id' => $attachment->id
                    ];
                }
            }
            $urls[] = $converted_url;
        }
        return $urls;
    }

    /**
     * Display the specified resource.
     *
     * @OA\Get(
     *     path="/attachments/{id}",
     *     operationId="getAttachmentById",
     *     tags={"Attachments"},
     *     summary="Get attachment by ID",
     *     description="Returns a single attachment with its details",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Attachment ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="original", type="string", example="https://example.com/storage/uploads/image.jpg"),
     *             @OA\Property(property="thumbnail", type="string", example="https://example.com/storage/uploads/conversions/image-thumbnail.jpg"),
     *             @OA\Property(property="created_at", type="string", format="date-time"),
     *             @OA\Property(property="updated_at", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Attachment not found"
     *     )
     * )
     *
     * @param int $id
     * @return JsonResponse
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
     * Update the specified resource in storage.
     *
     * @OA\Put(
     *     path="/attachments/{id}",
     *     operationId="updateAttachment",
     *     tags={"Attachments"},
     *     summary="Update attachment (Not implemented)",
     *     description="This endpoint is currently not implemented and will return false",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Attachment ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Returns false (not implemented)",
     *         @OA\JsonContent(type="boolean", example=false)
     *     )
     * )
     *
     * @param AttachmentRequest $request
     * @param int $id
     * @return bool
     */
    public function update(AttachmentRequest $request, $id)
    {
        return false;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @OA\Delete(
     *     path="/attachments/{id}",
     *     operationId="deleteAttachment",
     *     tags={"Attachments"},
     *     summary="Delete attachment",
     *     description="Permanently delete an attachment and its associated files from storage. Rate limited to 10 requests per minute.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Attachment ID to delete",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Attachment deleted successfully",
     *         @OA\JsonContent(type="boolean", example=true)
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Attachment not found"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated"
     *     ),
     *     @OA\Response(
     *         response=429,
     *         description="Too Many Requests - Rate limit exceeded"
     *     )
     * )
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        try {
            return $this->repository->findOrFail($id)->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
}
