<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Accounting\Models\Account;
use Illuminate\Http\Request;

final class AccountResource extends ApiResource
{
    private readonly Account $account;

    public function __construct(Account $account)
    {
        parent::__construct($account);

        $this->account = $account;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->account->getKey(),
            'code' => $this->account->code,
            'name' => $this->account->name,
            'type' => $this->account->type,
            'normal_balance' => $this->account->normal_balance,
            'parent_id' => $this->account->parent_id,
            'level' => $this->account->level,
            'is_postable' => $this->account->is_postable,
            'is_active' => $this->account->is_active,
        ];
    }
}
