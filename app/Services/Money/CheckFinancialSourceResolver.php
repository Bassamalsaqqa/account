<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\Check;
use App\Services\Money\Contracts\CheckFinancialSourceAdapter;
use App\Services\Money\Sources\CustomerPaymentCheckSourceAdapter;
use App\Services\Money\Sources\EmployeeAdvanceCheckSourceAdapter;
use App\Services\Money\Sources\ExpenseCheckSourceAdapter;
use App\Services\Money\Sources\SalaryPaymentCheckSourceAdapter;
use App\Services\Money\Sources\VendorPaymentCheckSourceAdapter;
use InvalidArgumentException;
use LogicException;

final class CheckFinancialSourceResolver
{
    public function resolve(Check $check): CheckFinancialSourceAdapter
    {
        /** @var list<CheckFinancialSourceAdapter> $sources */
        $sources = [];

        $customerPayment = $check->customerPayment()->first();
        if ($customerPayment !== null) {
            $sources[] = new CustomerPaymentCheckSourceAdapter($customerPayment);
        }

        $vendorPayment = $check->vendorPayment()->first();
        if ($vendorPayment !== null) {
            $sources[] = new VendorPaymentCheckSourceAdapter($vendorPayment);
        }

        $expense = $check->expense()->first();
        if ($expense !== null) {
            $sources[] = new ExpenseCheckSourceAdapter($expense);
        }

        $advance = $check->employeeAdvance()->first();
        if ($advance !== null) {
            $sources[] = new EmployeeAdvanceCheckSourceAdapter($advance);
        }

        $salaryPayment = $check->salaryPayment()->first();
        if ($salaryPayment !== null) {
            $sources[] = new SalaryPaymentCheckSourceAdapter($salaryPayment);
        }

        if (count($sources) === 0) {
            throw new InvalidArgumentException("Check [{$check->id}] has no valid linked financial source.");
        }

        if (count($sources) > 1) {
            throw new LogicException("Check [{$check->id}] has multiple conflicting linked financial sources.");
        }

        $adapter = $sources[0];

        if ((int) $adapter->sourceModel()->getAttribute('company_id') !== (int) $check->company_id) {
            throw new LogicException("Check [{$check->id}] source belongs to a different company.");
        }

        if ($check->direction !== $adapter->direction()) {
            throw new LogicException("Check [{$check->id}] direction [{$check->direction}] does not match source direction [{$adapter->direction()}].");
        }

        return $adapter;
    }
}
