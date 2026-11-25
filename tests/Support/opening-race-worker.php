<?php

declare(strict_types=1);
use App\Actions\Accounting\PostOpeningBalancesAction;
use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Domain\Accounting\Exceptions\LegacyGlOpeningOverlapException;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
[$script, $companyId, $actorId, $side, $partyId, $mode, $marker] = $argv;
$company = Company::findOrFail($companyId);
$actor = User::findOrFail($actorId);
app(CompanyContext::class)->setCompany($company, $actor);
auth()->setUser($actor);
setPermissionsTeamId($company->id);
file_put_contents($marker, 'ready');
try {
    if ($mode === 'trade') {
        app(PostOpeningTradeItemAction::class)->execute(company: $company, side: $side, partyId: (int) $partyId, openingDate: '2026-01-01', amount: '100', currencyCode: 'ILS', actor: $actor, idempotencyKey: 'race-trade-'.$side);
    } else {
        $account = LedgerAccount::where('company_id', $company->id)->where('system_key', $side === 'customer' ? 'accounts_receivable' : 'accounts_payable')->firstOrFail();
        app(PostOpeningBalancesAction::class)->execute(company: $company, date: Carbon::parse('2026-01-01'), assetBalances: $side === 'customer' ? [['account_id' => $account->id, 'amount' => '100']] : [], liabilityBalances: $side === 'vendor' ? [['account_id' => $account->id, 'amount' => '100']] : [], postedBy: $actor, idempotencyKey: 'race-generic-'.$side);
    }
    echo 'posted';
} catch (LegacyGlOpeningOverlapException|InvalidArgumentException $e) {
    echo 'blocked:'.$e->getMessage();
}
