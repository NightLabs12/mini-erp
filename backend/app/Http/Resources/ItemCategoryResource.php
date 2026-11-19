<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Master\Models\ItemCategory;
use Illuminate\Http\Request;

final class ItemCategoryResource extends ApiResource
{
    private readonly ItemCategory $category;

    public function __construct(ItemCategory $category)
    {
        parent::__construct($category);

        $this->category = $category;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->category->getKey(),
            'code' => $this->category->code,
            'name' => $this->category->name,
            'item_type' => $this->category->item_type,
            'inventory_account_id' => $this->category->inventory_account_id,
            'cogs_account_id' => $this->category->cogs_account_id,
            'sales_account_id' => $this->category->sales_account_id,
            ...$this->timestamps(),
        ];
    }
}
