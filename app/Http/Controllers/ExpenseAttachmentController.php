<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\Expenses\ExpenseAttachment;
use App\Services\Money\MoneyActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ExpenseAttachmentController extends Controller
{
    public function download(Request $request, string $publicId, CompanyContext $context): Response
    {
        abort_unless(auth()->check(), 401);
        abort_unless($context->hasCompany(), 403);

        $company = $context->company();
        $expense = Expense::where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        app(MoneyActorGuard::class)->authorize((int) $company->id, 'money.expense.view');

        if ($expense->classification === Expense::CLASSIFICATION_LANDED_COST) {
            app(MoneyActorGuard::class)->authorize((int) $company->id, 'purchasing.cost.view');
        }

        if (empty($expense->attachment_path) || ! Storage::disk('local')->exists($expense->attachment_path)) {
            abort(404, 'Attachment not found.');
        }

        try {
            app(ExpenseAttachment::class)->assertPath((int) $company->id, $expense->attachment_path);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        return Storage::disk('local')->download(
            $expense->attachment_path,
            $expense->attachment_name ?? basename($expense->attachment_path)
        );
    }
}
