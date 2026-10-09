<?php

use App\Models\Shop;
use App\Models\User;
use App\Services\ProductionService;
use App\Services\SaleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.default' => 'pgsql', 'database.connections.pgsql.url' => getenv('NILEBIT_TEST_DATABASE_URL'), 'database.connections.pgsql.port' => 5432]);
    $data = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $shop = Shop::findOrFail($data['shop']);
    $user = User::findOrFail($data['user']);
    while (microtime(true) < $data['start']) {
        usleep(1000);
    }
    try {
        if ($data['operation'] === 'sale') {
            $result = app(SaleService::class)->create($shop, $user, $data['payload']);
        } else {
            $result = app(ProductionService::class)->complete($shop, $user, $data['batch'], $data['payload']);
        }
        echo 'NILEBIT_RESULT='.json_encode(['id' => $result->id]);
    } catch (ValidationException $e) {
        echo 'NILEBIT_RESULT='.json_encode(['validation' => $e->errors()]);
    }

} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).PHP_EOL);
    exit(1);
}
