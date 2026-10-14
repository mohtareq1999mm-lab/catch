<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\Contact;
use Marvel\Database\Repositories\ContactRepository;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\ContactCreateReplayRequest;
use Marvel\Http\Requests\ContactCreateRequest;
use Marvel\Http\Resources\ContactCollection;
use Marvel\Http\Resources\ContactResource;
use Marvel\Traits\ApiResponse;

class ContactController extends CoreController
{
    use ApiResponse;

    public $repository;

    public function __construct(ContactRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware('permission:' . Permission::VIEW_CONTACTS, ['only' => ['index']]);
        $this->middleware('permission:' . Permission::UPDATE_CONTACT, ['only' => ['show', 'sendReply']]);
        $this->middleware('permission:' . Permission::DELETE_CONTACT, ['only' => ['destroy', 'deleteAll']]);
        $this->middleware('permission:' . Permission::DELETE_READ_CONTACTS, ['only' => ['deleteAllReadContacts']]);
    }

    public function index(Request $request)
    {
        $limit = $request->limit ?? 15;

        $query = Contact::query();

        if ($request->boolean('read')) {
            $query->read();
        }
        if ($request->boolean('unread')) {
            $query->unread();
        }
        if ($request->boolean('replay')) {
            $query->replay();
        }

        $contacts = $query->paginate($limit);
        $data = new ContactCollection($contacts);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $data);
    }

    public function store(ContactCreateRequest $request)
    {
        try {
            $contact = $this->repository->saveContact($request);
            return $this->apiResponse(CONTACT_CREATED_SUCCESSFULLY, 201, true, ContactResource::make($contact));
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    public function show($id)
    {
        try {
            $contact = $this->repository->markAsRead($id);

            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ContactResource::make($contact));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    public function sendReply(ContactCreateReplayRequest $request, $id)
    {
        try {
            $contact = $this->repository->ReplayContact($request, $id);

            return $this->apiResponse(REPLAY_SENT_SUCCESSFULLY, 200, true, ContactResource::make($contact));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }


    public function destroy($id)
    {
        try {
            $this->repository->findOrFail($id)->delete();

            return $this->apiResponse(CONTACT_DELETED_SUCCESSFULLY, 200, true);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }


    public function deleteAll()
    {
        try {
            $this->repository->deleteAllContacts();

            return $this->apiResponse(ALL_CONTACTS_DELETED_SUCCESSFULLY, 200, true);
        } catch (\Exception $e) {
            return $this->apiResponse(COULD_NOT_DELETE_THE_RESOURCE, 500, false);
        }
    }
    public function deleteAllReadContacts()
    {
        try {
            $this->repository->deleteAllReadContacts();

            return $this->apiResponse(ALL_READ_CONTACTS_DELETED_SUCCESSFULLY, 200, true);
        } catch (\Exception $e) {
            return $this->apiResponse(COULD_NOT_DELETE_THE_RESOURCE, 500, false);
        }
    }
}