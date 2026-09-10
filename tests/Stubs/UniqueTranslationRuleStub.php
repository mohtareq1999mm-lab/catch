<?php

namespace CodeZero\UniqueTranslation;

use Illuminate\Contracts\Validation\Rule;

class UniqueTranslationRule implements Rule
{
    private string $table;

    private $ignoredId = null;

    public static function for(string $table): self
    {
        return new self($table);
    }

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function ignore($id): self
    {
        $this->ignoredId = $id;
        return $this;
    }

    public function passes($attribute, $value): bool
    {
        return true;
    }

    public function message(): string
    {
        return 'The :attribute has already been taken.';
    }
}
