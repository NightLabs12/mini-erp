<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Mengambil primary key dari route model binding tanpa mengasumsikan tipe.
 *
 * Saat validasi berjalan, route sudah di-bind ke model. Namun pada beberapa
 * konteks (misalnya request yang dipanggil langsung di test) nilai route bisa
 * berupa id mentah, sehingga keduanya perlu ditangani.
 */
trait ResolvesRouteModel
{
    protected function routeModelId(string $parameter): ?int
    {
        $value = $this->route($parameter);

        if ($value instanceof Model) {
            return (int) $value->getKey();
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
