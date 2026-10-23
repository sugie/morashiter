<?php

use App\Services\Crawling\OfficialDomainResearch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'mrst_test'
    || getenv('DB_HOST') !== 'db' || getenv('DB_CONNECTION') !== 'pgsql') {
    throw new RuntimeException('Concurrency worker requires the dedicated test database.');
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'mrst_test'
    || config('database.connections.pgsql.host') !== 'db' || ! empty(config('database.connections.pgsql.url'))) {
    throw new RuntimeException('Unexpected database configuration in concurrency worker.');
}
config()->set(['domain_research.enabled' => false, 'app.debug' => false]);
Http::preventStrayRequests();
DB::select("select set_config('application_name', ?, false)", [$argv[1]]);
DB::statement("SET statement_timeout = '20000ms'");

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
try {
    app(OfficialDomainResearch::class)->decide($input['data'], $input['admin_id']);
    $result = ['status' => 'registered'];
} catch (HttpExceptionInterface $exception) {
    $result = ['status' => 'rejected', 'http_status' => $exception->getStatusCode()];
} catch (Throwable $exception) {
    $result = ['status' => 'failed', 'exception' => get_class($exception), 'code' => (string) $exception->getCode()];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
