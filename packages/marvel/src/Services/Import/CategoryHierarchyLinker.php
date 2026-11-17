<?php

namespace Marvel\Services\Import;

use App\Services\General\CategoryHierarchyService;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Category;
use Throwable;

/**
 * Two-phase parent assignment for the categories sheet: resolves each row's
 * parent name against the identity maps owned by CategoryRowWriter, then
 * assigns parent_id with the hierarchy validation (self-parent / cycle /
 * ambiguous-parent rules). Missing/ambiguous parents are non-fatal domain
 * warnings: the category persists as root. Orphan removal keeps the identity
 * maps consistent with soft-deleted newly created categories.
 */
final class CategoryHierarchyLinker
{
    public function __construct(
        private readonly CategoryRowWriter $rows,
        private readonly CategoryHierarchyService $hierarchy,
        private readonly \Closure $onRowProcessed,
    ) {
    }

    public function assignParents(array &$pending): void
    {
        foreach ($pending as $index => $row) {
            if (!empty($row['errors']) || $row['target'] === null) {
                continue;
            }

            $parentName = $row['parent_name'];
            $parentId = null;
            $parentError = null;

            if ($parentName !== '') {
                $parentKey = $this->rows->normalizeKey($parentName);
                $matches = $this->rows->matchByName($parentKey);

                if (count($matches) === 0) {
                    $parentError = __('message.IMPORT.CATEGORY.MISSING_PARENT');
                } elseif (count($matches) > 1) {
                    $parentError = __('message.IMPORT.CATEGORY.AMBIGUOUS_PARENT');
                } else {
                    $parentId = (int) $matches[0]->id;
                }
            }

            if ($parentError !== null) {
                // Non-fatal domain contract: missing/ambiguous parent does not fail the category.
                // Persist as root (parent_id = null) and keep row as success candidate.
                // Log warning for forensic audit, do not increment failedRows.
                report(new \RuntimeException("Category '{$row['name_en']}' parent warning ({$parentError}): parent_name_en='{$row['parent_name']}'"));
                try {
                    $row['target']->parent_id = null;
                    $row['target']->save();
                } catch (\Throwable $e) {
                    $this->rows->failPendingRow($pending, $index, $row, $e->getMessage());
                    if (!empty($row['is_new']) && $row['target'] !== null) {
                        $this->removeOrphanCategory($pending, $index, $row);
                    } else {
                        $this->rows->cleanupTempImages($row);
                    }
                    continue;
                }
                $pending[$index] = $row;
                ($this->onRowProcessed)();
                continue;
            }

            try {
                if ($parentId !== null) {
                    $this->hierarchy->ensureHierarchyIsValid($row['target'], $parentId);
                }

                $row['target']->parent_id = $parentId;
                $row['target']->save();

                // Success is counted authoritatively in attachImages after media handling.
                // Parent assignment success only persists the hierarchy; not counted yet.
                $pending[$index] = $row;
            } catch (ValidationException $e) {
                $messages = collect($e->errors())->flatten()->implode(' ');
                $message = $messages ?: __('message.IMPORT.CATEGORY.INVALID_PARENT');
                $this->rows->failPendingRow($pending, $index, $row, $message);
                // Hierarchy validation failure (self/cycle) - keep newly created category as root
                // per matrix: self parent stays root level 1, do not delete orphan.
                $this->rows->cleanupTempImages($row);
            } catch (Throwable $e) {
                $this->rows->failPendingRow($pending, $index, $row, $e->getMessage());
                if (!empty($row['is_new']) && $row['target'] !== null) {
                    $this->removeOrphanCategory($pending, $index, $row);
                } else {
                    $this->rows->cleanupTempImages($row);
                }
            }

            ($this->onRowProcessed)();
        }
    }

    protected function removeOrphanCategory(array &$pending, int $index, array $row): void
    {
        $category = $row['target'] ?? null;

        if (!$category) {
            return;
        }

        try {
            // Soft-delete the orphaned newly created category to preserve audit trail
            $category->delete();
        } catch (Throwable $e) {
            report($e);
        }

        // Remove from in-memory indexes to prevent later parent lookups finding the orphan
        $this->rows->forgetCreatedCategory($category, $row['name_en'] ?? '');

        // Ensure temp images for this orphan row are cleaned
        $this->rows->cleanupTempImages($row);
        // Mark pending row as failed state without target to prevent later attach
        $pending[$index]['target'] = null;
    }
}
