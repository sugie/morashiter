<?php

use App\Models\AdminUser;
use App\Models\OfficialDomainInvestigation;
use App\Models\OfficialLinkCandidate;
use Illuminate\Contracts\Console\Kernel;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'mrst_test' || config('database.connections.pgsql.host') !== 'db') {
    throw new RuntimeException('Test database only');
}
config()->set('domain_research.enabled', false);
AdminUser::factory()->active()->create(['name' => 'ブラウザ確認担当', 'email' => 'browser-reviewer@example.test', 'password' => 'BrowserFixture-2026-only!']);
foreach (['alpha' => '株式会社アルファ', 'beta' => '株式会社ベータ'] as $key => $name) {
    $host = $key.'.example';
    OfficialLinkCandidate::factory()->create(['host' => $host, 'url' => 'http://localhost:6558/__browser-review-'.$key.'.html', 'status' => 'pending', 'anchor_text' => $name]);
    OfficialDomainInvestigation::factory()->create(['host' => $host, 'generation' => 1, 'status' => 'completed', 'recommendation' => 'recommend', 'organization_name' => $name, 'reason' => '固定データの会社概要で運営主体を確認', 'evidence' => [['url' => 'http://localhost:6558/__browser-review-'.$key.'.html', 'quote' => '本サイトは'.$name.'が運営しています。']], 'final_url' => 'http://localhost:6558/__browser-review-'.$key.'.html', 'completed_at' => now(), 'raw_proposal' => ['organization_name' => $name]]);
}
echo "Two synthetic hosts prepared in mrst_test.\n";
