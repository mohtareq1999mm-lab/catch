<?php


namespace Marvel\Http\Controllers;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Marvel\Database\Repositories\NotifyLogsRepository;
use Marvel\Database\Repositories\UserRepository;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\NotifyLogsReadAllRequest;
use Marvel\Http\Requests\NotifyLogsReadRequest;
use Marvel\Http\Resources\NotifyLogsResource;

class NotifyLogsController extends CoreController
{
    public $repository;

    public $userRepository;

    public function __construct(NotifyLogsRepository $repository, UserRepository $userRepository)
    {
        $this->repository = $repository;
        $this->userRepository = $userRepository;
    }

    /**
     * index
     *
     * @param  Request $request
     * @return Collection|NotifyLogs[]
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->limit ? $request->limit : 10;
            $notify_logs = $this->fetchNotifyLogs($request)->paginate($limit)->withQueryString();
            $notify_logs->getCollection()->transform(fn ($item) => new NotifyLogsResource($item));
            return $notify_logs;
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $th->getMessage());
        }
    }

    /**
     * fetchNotifyLogs
     *
     * @param  Request $request
     * @return object
     */
    public function fetchNotifyLogs(Request $request)
    {
        $user = $request->user();
        $notify_log_query = $this->repository->with(['sender_user'])->where('receiver', '=', $user->id);

        if (isset($request->notify_type) && !empty($request->notify_type)) {
            $notify_log_query = $notify_log_query->where('notify_type', "=", $request->notify_type);
        }

        return $notify_log_query;
    }

    /**
     * Display the specified resource.
     *
     * @param $slug
     * @return object
     */
    public function show(Request $request, $id)
    {
        try {
            $request['id'] = $id;
            return new NotifyLogsResource($this->fetchNotifyLog($request));
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param $slug
     * @return object
     */
    public function fetchNotifyLog(Request $request)
    {
        try {
            $id = $request['id'];
            return $this->repository->with(['sender_user'])
                ->where('id', '=', $id)
                ->where('receiver', '=', $request->user()->id)
                ->firstOrFail();
        } catch (Exception $th) {
            throw new HttpException(404, NOT_FOUND);
        }
    }



    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy($id, Request $request)
    {
        try {
            $request['id'] = $id;
            return $this->deleteNotifyLogs($request);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $th->getMessage());
        }
    }

    public function deleteNotifyLogs(Request $request)
    {
        try {
            if ($request->user()->hasRole(Role::SUPER_ADMIN)) {
                return $this->repository->findOrFail($request->id)->delete();
            }
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_AUTHORIZED, $th->getMessage());
        }
    }

    /**
     * readNotifyLogs
     *
     * @param  NotifyLogsReadRequest $request
     * @return void
     */
    public function readNotifyLogs(NotifyLogsReadRequest $request)
    {
        try {
            $notify_log = $this->repository->with(['sender_user'])
                ->where('id', '=', $request->id)
                ->where('receiver', '=', $request->user()->id)
                ->firstOrFail();
            $notify_log->is_read = true;
            $notify_log->save();
            return new NotifyLogsResource($notify_log);
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_AUTHORIZED, $th->getMessage());
        }
    }


    /**
     * readAllNotifyLogs
     *
     * @param  NotifyLogsReadAllRequest $request
     * @return void
     */
    public function readAllNotifyLogs(NotifyLogsReadAllRequest $request)
    {
        try {
            $user = $request->user();
            $notify_logs_query = $this->repository->with(['sender_user'])
                ->where('receiver', '=', $user->id);

            if ($request->filled('notify_type')) {
                $notify_logs_query = $notify_logs_query->where('notify_type', '=', $request->notify_type);
            }

            $notify_logs = $notify_logs_query->get();

            foreach ($notify_logs as $notify_log) {
                $notify_log->is_read = true;
                $notify_log->save();
            }

            return $notify_logs->map(fn ($item) => new NotifyLogsResource($item));
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_AUTHORIZED, $th->getMessage());
        }
    }
}
