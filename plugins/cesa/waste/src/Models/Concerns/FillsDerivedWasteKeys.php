<?php

namespace Cesa\Waste\Models\Concerns;

trait FillsDerivedWasteKeys
{
    protected static function bootFillsDerivedWasteKeys(): void
    {
        static::saving(function (self $model): void {
            $model->fillDerivedWasteKeys();
        });
    }

    abstract public function fillDerivedWasteKeys(): void;
}
