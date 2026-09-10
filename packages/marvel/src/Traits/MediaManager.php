<?php

namespace Marvel\Traits;

use Illuminate\Support\Str;

trait MediaManager
{
    public function uploadImages($request, $inputName, $model, $collectionName, $disk)
    {
        try {
            if (!$request->hasFile($inputName)) {
                return false;
            }
            foreach ($request->file($inputName) as $file) {

                $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $model->addMedia($file)
                    ->usingFileName($fileName)
                    ->toMediaCollection($collectionName, $disk);
            }
            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function uploadSingleImage($request, $nameInput, $model, $collectionName, $disk)
    {
        if (!$request->hasFile($nameInput)) {
            return false;
        }
        $fileName = Str::uuid() . '.' . $request->file($nameInput)->getClientOriginalExtension();
        $model->addMedia($request->file($nameInput))
            ->usingFileName($fileName)
            ->toMediaCollection($collectionName, $disk);
        return true;
    }

    public function updateSingleImage($request, $nameInput, $model, $collectionName, $disk)
    {
        if (!$request->hasFile($nameInput)) {
            return false;
        }

        if ($model->hasMedia($collectionName)) {
            $model->clearMediaCollection($collectionName);
        }

        $fileName = Str::uuid() . '.' . $request->file($nameInput)->getClientOriginalExtension();

        $model->addMedia($request->file($nameInput))
            ->usingFileName($fileName)
            ->toMediaCollection($collectionName, $disk);

        return true;
    }

    public function updateImages($request, $inputName, $model, $collectionName, $disk)
    {
        if ($model->hasMedia($collectionName)) {
            $model->clearMediaCollection($collectionName);
        }
        return $this->uploadImages($request, $inputName, $model, $collectionName, $disk);
    }

    public function deleteFile($request, $model, $collectionName)
    {
        $media = $model->getMedia($collectionName)->find($request->id);
        $media->delete();
        return true;
    }
}
