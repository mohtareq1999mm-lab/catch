<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $categories = DB::table('categories')
            ->select('id', 'parent_id')
            ->get()
            ->keyBy('id');

        $resolvedLevels = [];

        $resolveLevel = function (int $categoryId, array $trail = []) use (&$resolveLevel, &$resolvedLevels, $categories): int {
            if (isset($resolvedLevels[$categoryId])) {
                return $resolvedLevels[$categoryId];
            }

            if (in_array($categoryId, $trail, true)) {
                return 1;
            }

            $category = $categories->get($categoryId);

            if (!$category || $category->parent_id === null) {
                return $resolvedLevels[$categoryId] = 1;
            }

            $parentId = (int) $category->parent_id;

            return $resolvedLevels[$categoryId] = $resolveLevel($parentId, array_merge($trail, [$categoryId])) + 1;
        };

        foreach ($categories as $category) {
            DB::table('categories')
                ->where('id', $category->id)
                ->update(['level' => $resolveLevel((int) $category->id)]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
