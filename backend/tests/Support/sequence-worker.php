<?php

declare(strict_types=1);

use App\Domain\Core\Services\DocumentSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

/**
 * Worker untuk test konkurensi penomoran dokumen.
 *
 * Dijalankan sebagai proses PHP terpisah agar benar-benar paralel di level
 * database, bukan sekadar sekuensial di dalam satu proses.
 *
 * Pemakaian: php sequence-worker.php <DOC_TYPE> <YYYY-MM-DD>
 */
require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$type = $argv[1] ?? 'PO';
$date = $argv[2] ?? '2099-12-01';

echo app(DocumentSequenceService::class)->next($type, CarbonImmutable::parse($date));
