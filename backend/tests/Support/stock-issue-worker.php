<?php

declare(strict_types=1);

use App\Domain\Inventory\Services\StockService;
use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

/**
 * Worker untuk test konkurensi pengeluaran stok.
 *
 * Dijalankan sebagai proses PHP terpisah agar lockForUpdate benar-benar
 * diuji di level database.
 *
 * Pemakaian: php stock-issue-worker.php <ITEM_ID> <WAREHOUSE_ID> <QTY> <DOC_ID> <YYYY-MM-DD>
 */
require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$itemId = (int) ($argv[1] ?? 0);
$warehouseId = (int) ($argv[2] ?? 0);
$qty = (string) ($argv[3] ?? '0');
$docId = (int) ($argv[4] ?? 0);
$date = (string) ($argv[5] ?? now()->toDateString());

try {
    app(StockService::class)->issue(
        $itemId,
        $warehouseId,
        $qty,
        ['type' => 'DO', 'id' => $docId],
        CarbonImmutable::parse($date),
    );

    echo "OK\n";
} catch (Throwable $exception) {
    $message = $exception instanceof ApiException
        ? $exception->errorCode().': '.$exception->getMessage()
        : $exception::class.': '.$exception->getMessage();

    fwrite(STDERR, $message."\n");
    exit(1);
}
