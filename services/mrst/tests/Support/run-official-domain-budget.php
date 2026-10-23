<?php

use App\Services\Crawling\HostResolver;
use App\Services\Crawling\OfficialDomainResearch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InMemoryHostResolver;
use Tests\Support\OfficialDomainResearchFixtures as F;

require __DIR__.'/../../vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'mrst_test'
    || getenv('DB_HOST') !== 'db' || getenv('DB_CONNECTION') !== 'pgsql') {
    throw new RuntimeException('Budget worker requires the dedicated test database.');
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'mrst_test'
    || config('database.connections.pgsql.host') !== 'db' || ! empty(config('database.connections.pgsql.url'))) {
    throw new RuntimeException('Unexpected database configuration in budget worker.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config()->set([
    'domain_research.enabled' => true, 'domain_research.daily_limit' => $input['limit'],
    'domain_research.max_pages' => 3, 'domain_research.max_attempts' => 2,
    'ai.provider' => 'sakura', 'ai.sakura.base_url' => 'https://ai.example/v1',
    'ai.sakura.api_key' => 'fixture-token', 'ai.sakura.model' => 'fixture-model',
    'app.debug' => false,
]);
Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'Asia/Tokyo'));
DB::select("select set_config('application_name', ?, false)", [$argv[1]]);
DB::statement("SET statement_timeout = '20000ms'");
$host = $input['host'];
$app->instance(HostResolver::class, new InMemoryHostResolver([$host => ['93.184.216.34']]));
Storage::persistentFake('local');
Http::preventStrayRequests();
Http::fake([
    'https://'.$host.'/' => Http::response(F::home(), 200, ['Content-Type' => 'text/html']),
    'https://'.$host.'/about' => Http::response(F::about(), 200, ['Content-Type' => 'text/html']),
    'https://'.$host.'/profile' => Http::response(F::about(), 200, ['Content-Type' => 'text/html']),
    'https://ai.example/v1/chat/completions' => Http::response(F::response(array_replace(F::proposal(), [
        'evidence' => [['url' => 'https://'.$host.'/about', 'quote' => F::proposal()['evidence'][0]['quote']]],
    ]))),
]);
try {
    app(OfficialDomainResearch::class)->run($input['id']);
    $requests = Http::recorded(fn (Request $request): bool => $request->url() === 'https://ai.example/v1/chat/completions');
    echo json_encode(['ai_requests' => $requests->count()], JSON_THROW_ON_ERROR);
} finally {
    Storage::disk('local')->deleteDirectory('domain-research/'.$input['id']);
    Carbon::setTestNow();
}
