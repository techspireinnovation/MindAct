<?php

namespace App\Http\Controllers;

use App\Models\BankVoucher;
use App\Models\JournalVoucher;
use App\Models\PaymentVoucher;
use App\Models\Purchase;
use App\Models\User;
use App\Models\PurchaseReturn;
use App\Models\ReceiptVoucher;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StockAdjustment;
use Illuminate\Http\Request;
use Pratiksh\Nepalidate\Services\NepaliDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class GenerateCodeController extends Controller
{
    /**
     * Generate purchase bill number based on Nepali fiscal year and branch
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generatePurchaseBillNumber(Request $request)
    {
        try {
            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

            $currentBsYear = (int) $currentBsYear;
            $currentBsMonth = (int) $currentBsMonth;

            $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;
            $fiscalYearCode = substr($fiscalYear, 2, 2) . substr($fiscalYear + 1, 2, 2);

            $userId = $request->user_id;
            $branchId = $request->branch_id;

            if (!$userId || !$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User or branch not provided'
                ], 400);
            }

            $user = User::on('mysql')->with('roles')->find($userId);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: User not found'
                ], 401);
            }

            // Get last purchase record for this branch & fiscal year
            $lastPurchase = Purchase::where('purchase_bill_number', 'like', "P{$fiscalYearCode}-{$branchId}-%")
                ->orderBy('id', 'desc')
                ->first();

            $lastNumber = $lastPurchase ? (int) substr($lastPurchase->purchase_bill_number, -6) : 0;
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            $purchaseBillNumber = "P{$fiscalYearCode}-{$branchId}-{$newNumber}";

            return response()->json([
                'status' => 'success',
                'data' => [
                    'purchase_bill_number' => $purchaseBillNumber,
                    'fiscal_year' => $fiscalYearCode,
                    'branch_id' => $branchId
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error generating purchase bill number: ' . $e->getMessage()
            ], 400);
        }
    }




    public function generatePurchaseReturnBillNumber(Request $request)
    {
        try {
            // Get current BS date


            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            $bsDateParts = explode('-', $bsDate);
            $currentBsYear = (int) $bsDateParts[0];
            $currentBsMonth = (int) $bsDateParts[1];

            // Determine fiscal year
            $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;
            $fiscalYearCode = substr($fiscalYear, 2, 2) . substr($fiscalYear + 1, 2, 2);

            // Get authenticated user
            $userId = $request->user_id;
            $branchId = $request->branch_id;
            $user = \App\Models\User::on('mysql')->with('roles')->find($userId);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: User not found'
                ], 401);
            }

            // Get the current token's abilities


            // Extract branch ID from token abilities


            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No branch associated with the user'
                ], 200);
            }

            // Get last purchase record for the current fiscal year and branch
            $lastPurchasereturn = PurchaseReturn::where('purchase_bill_number', 'like', "PR{$fiscalYearCode}-{$branchId}-%")
                ->orderBy('id', 'desc')
                ->first();

            // Generate sequential number
            $lastNumber = $lastPurchasereturn ? (int) substr($lastPurchasereturn->purchase_bill_number, -6) : 0;
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            // Generate purchase bill number
            $purchaseReturnBillNumber = "PR{$fiscalYearCode}-{$branchId}-{$newNumber}";

            return response()->json([
                'status' => 'success',
                'data' => [
                    'purchase_bill_number' => $purchaseReturnBillNumber,
                    'fiscal_year' => $fiscalYearCode,
                    'branch_id' => $branchId
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error generating purchase return bill number: ' . $e->getMessage()
            ], 400);
        }
    }


    public function generateSalesBillNumber(Request $request)
    {
        try {
            // Get current BS date


            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            $bsDateParts = explode('-', $bsDate);
            $currentBsYear = (int) $bsDateParts[0];
            $currentBsMonth = (int) $bsDateParts[1];

            // Determine fiscal year
            $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;
            $fiscalYearCode = substr($fiscalYear, 2, 2) . substr($fiscalYear + 1, 2, 2);

            // Get authenticated user
            $userId = $request->user_id;

            $user = User::on('mysql')->where('id', $userId)->first();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: User not authenticated'
                ], 401);
            }

            // Get the current token's abilities
            // $token = $user->currentAccessToken();
            // if (!$token) {
            //     return response()->json([
            //         'status' => 'error',
            //         'message' => 'No valid token found'
            //     ], 200);
            // }

            // Extract branch ID from token abilities
            $branchId = $request->branch_id;


            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No branch associated with the user'
                ], 200);
            }


            $lastSale = Sale::where('invoice_number', 'like', "S{$fiscalYearCode}-{$branchId}-%")
                ->orderBy('id', 'desc')
                ->first();

            // Generate sequential number
            $lastNumber = $lastSale ? (int) substr($lastSale->invoice_number, -6) : 0;
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            // Generate purchase bill number
            $salesBillNumber = "S{$fiscalYearCode}-{$branchId}-{$newNumber}";

            return response()->json([
                'status' => 'success',
                'data' => [
                    'invoice_number' => $salesBillNumber,
                    'fiscal_year' => $fiscalYearCode,
                    'branch_id' => $branchId
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error generating sales invoice number: ' . $e->getMessage()
            ], 400);
        }
    }


    public function generateSalesReturnBillNumber(Request $request)
    {
        try {
            // Get current BS date


            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            $bsDateParts = explode('-', $bsDate);
            $currentBsYear = (int) $bsDateParts[0];
            $currentBsMonth = (int) $bsDateParts[1];

            // Determine fiscal year
            $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;
            $fiscalYearCode = substr($fiscalYear, 2, 2) . substr($fiscalYear + 1, 2, 2);

            // Get authenticated user
            $userId = $request->user_id;
            $user = User::on('mysql')->with('roles')->find($userId);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: User not authenticated'
                ], 401);
            }

            // Get the current token's abilities
            $token = $user->currentAccessToken();
            if (!$token) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No valid token found'
                ], 200);
            }

            // Extract branch ID from token abilities
            $branchId = $request->branch_id;
            foreach ($token->abilities as $ability) {
                if (strpos($ability, 'branch:') === 0) {
                    $branchId = (int) str_replace('branch:', '', $ability);
                    break;
                }
            }

            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No branch associated with the user'
                ], 200);
            }


            $lastSalesreturn = SalesReturn::where('invoice_number', 'like', "SR{$fiscalYearCode}-{$branchId}-%")
                ->orderBy('id', 'desc')
                ->first();

            // Generate sequential number
            $lastNumber = $lastSalesreturn ? (int) substr($lastSalesreturn->invoice_number, -6) : 0;
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            // Generate purchase bill number
            $salesreturnBillNumber = "SR{$fiscalYearCode}-{$branchId}-{$newNumber}";

            return response()->json([
                'status' => 'success',
                'data' => [
                    'invoice_number' => $salesreturnBillNumber,
                    'fiscal_year' => $fiscalYearCode,
                    'branch_id' => $branchId
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error generating sales return invoice number: ' . $e->getMessage()
            ], 400);
        }
    }


    public function generateJournalVoucherBillNumber(Request $request)
    {
        try {
            // Get current BS date


            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            $bsDateParts = explode('-', $bsDate);
            $currentBsYear = (int) $bsDateParts[0];
            $currentBsMonth = (int) $bsDateParts[1];

            // Determine fiscal year
            $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;
            $fiscalYearCode = substr($fiscalYear, 2, 2) . substr($fiscalYear + 1, 2, 2);

            // Get authenticated user
            $userId = $request->user_id;
            $user = User::on('mysql')->with('roles')->find($userId);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: User not authenticated'
                ], 401);
            }

            // Get the current token's abilities
            $token = $user->currentAccessToken();
            if (!$token) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No valid token found'
                ], 200);
            }

            // Extract branch ID from token abilities
            $branchId = $request->branch_id;
            foreach ($token->abilities as $ability) {
                if (strpos($ability, 'branch:') === 0) {
                    $branchId = (int) str_replace('branch:', '', $ability);
                    break;
                }
            }

            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No branch associated with the user'
                ], 200);
            }


            $lastVoucherNumber = JournalVoucher::where('voucher_number', 'like', "JV{$fiscalYearCode}-{$branchId}-%")
                ->orderBy('id', 'desc')
                ->first();

            // Generate sequential number
            $lastNumber = $lastVoucherNumber ? (int) substr($lastVoucherNumber->voucher_number, -6) : 0;
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            // Generate purchase bill number
            $JournalVoucherNumber = "JV{$fiscalYearCode}-{$branchId}-{$newNumber}";

            return response()->json([
                'status' => 'success',
                'data' => [
                    'voucher_number' => $JournalVoucherNumber,
                    'fiscal_year' => $fiscalYearCode,
                    'branch_id' => $branchId
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error generating journal voucher number: ' . $e->getMessage()
            ], 400);
        }
    }



    public function generatePaymentVoucherBillNumber(Request $request)
    {
        try {
            // Get current BS date


            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            $bsDateParts = explode('-', $bsDate);
            $currentBsYear = (int) $bsDateParts[0];
            $currentBsMonth = (int) $bsDateParts[1];

            // Determine fiscal year
            $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;
            $fiscalYearCode = substr($fiscalYear, 2, 2) . substr($fiscalYear + 1, 2, 2);

            // Get authenticated user
            $user = $request->user_id;
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: User not authenticated'
                ], 401);
            }

            // Get the current token's abilities
            $token = $user->currentAccessToken();
            if (!$token) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No valid token found'
                ], 200);
            }

            // Extract branch ID from token abilities
            $branchId = $request->branch_id;
            foreach ($token->abilities as $ability) {
                if (strpos($ability, 'branch:') === 0) {
                    $branchId = (int) str_replace('branch:', '', $ability);
                    break;
                }
            }

            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No branch associated with the user'
                ], 200);
            }


            $lastPaymentVoucherNumber = PaymentVoucher::where('payment_voucher_number', 'like', "PV{$fiscalYearCode}-{$branchId}-%")
                ->orderBy('id', 'desc')
                ->first();

            // Generate sequential number
            $lastNumber = $lastPaymentVoucherNumber ? (int) substr($lastPaymentVoucherNumber->payment_voucher_number, -6) : 0;
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            // Generate purchase bill number
            $PaymentVoucherNumber = "PV{$fiscalYearCode}-{$branchId}-{$newNumber}";

            return response()->json([
                'status' => 'success',
                'data' => [
                    'payment_voucher_number' => $PaymentVoucherNumber,
                    'fiscal_year' => $fiscalYearCode,
                    'branch_id' => $branchId
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error generating payment voucher number: ' . $e->getMessage()
            ], 400);
        }
    }

    public function generateReceiptVoucherBillNumber(Request $request)
    {
        try {
            // Get current BS date


            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            $bsDateParts = explode('-', $bsDate);
            $currentBsYear = (int) $bsDateParts[0];
            $currentBsMonth = (int) $bsDateParts[1];

            // Determine fiscal year
            $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;
            $fiscalYearCode = substr($fiscalYear, 2, 2) . substr($fiscalYear + 1, 2, 2);

            // Get authenticated user
            $userId = $request->user_id;
            $user = User::on('mysql')->with('roles')->find($userId);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: User not authenticated'
                ], 401);
            }

            // Get the current token's abilities
            $token = $user->currentAccessToken();
            if (!$token) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No valid token found'
                ], 200);
            }

            // Extract branch ID from token abilities
            $branchId = $request->branch_id;
            foreach ($token->abilities as $ability) {
                if (strpos($ability, 'branch:') === 0) {
                    $branchId = (int) str_replace('branch:', '', $ability);
                    break;
                }
            }

            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No branch associated with the user'
                ], 200);
            }


            $lastReceiptVoucherNumber = ReceiptVoucher::where('receipt_voucher_number', 'like', "RV{$fiscalYearCode}-{$branchId}-%")
                ->orderBy('id', 'desc')
                ->first();

            // Generate sequential number
            $lastNumber = $lastReceiptVoucherNumber ? (int) substr($lastReceiptVoucherNumber->receipt_voucher_number, -6) : 0;
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            // Generate purchase bill number
            $ReceiptVoucherNumber = "RV{$fiscalYearCode}-{$branchId}-{$newNumber}";

            return response()->json([
                'status' => 'success',
                'data' => [
                    'receipt_voucher_number' => $ReceiptVoucherNumber,
                    'fiscal_year' => $fiscalYearCode,
                    'branch_id' => $branchId
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error generating receipt voucher number: ' . $e->getMessage()
            ], 400);
        }
    }


    public function generateBankVoucherBillNumber(Request $request)
    {
        try {
            // Get current BS date


            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            $bsDateParts = explode('-', $bsDate);
            $currentBsYear = (int) $bsDateParts[0];
            $currentBsMonth = (int) $bsDateParts[1];

            // Determine fiscal year
            $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;
            $fiscalYearCode = substr($fiscalYear, 2, 2) . substr($fiscalYear + 1, 2, 2);

            // Get authenticated user
            $userId = $request->user_id;
            $user = User::on('mysql')->with('roles')->find($userId);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: User not authenticated'
                ], 401);
            }

            // Get the current token's abilities
            $token = $user->currentAccessToken();
            if (!$token) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No valid token found'
                ], 200);
            }

            // Extract branch ID from token abilities
            $branchId = $request->branch_id;
            foreach ($token->abilities as $ability) {
                if (strpos($ability, 'branch:') === 0) {
                    $branchId = (int) str_replace('branch:', '', $ability);
                    break;
                }
            }

            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No branch associated with the user'
                ], 200);
            }


            $lastBankVoucherNumber = BankVoucher::where('voucher_number', 'like', "BV{$fiscalYearCode}-{$branchId}-%")
                ->orderBy('id', 'desc')
                ->first();

            // Generate sequential number
            $lastNumber = $lastBankVoucherNumber ? (int) substr($lastBankVoucherNumber->voucher_number, -6) : 0;
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            // Generate purchase bill number
            $BankVoucherNumber = "BV{$fiscalYearCode}-{$branchId}-{$newNumber}";

            return response()->json([
                'status' => 'success',
                'data' => [
                    'voucher_number' => $BankVoucherNumber,
                    'fiscal_year' => $fiscalYearCode,
                    'branch_id' => $branchId
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error generating bank voucher number: ' . $e->getMessage()
            ], 400);
        }
    }


    public function generateStockAdjustmentBillNumber(Request $request)
    {
        try {
            // Get current BS date


            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            $bsDateParts = explode('-', $bsDate);
            $currentBsYear = (int) $bsDateParts[0];
            $currentBsMonth = (int) $bsDateParts[1];

            // Determine fiscal year
            $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;
            $fiscalYearCode = substr($fiscalYear, 2, 2) . substr($fiscalYear + 1, 2, 2);

            // Get authenticated user
            $userId = $request->user_id;
            $user = User::on('mysql')->with('roles')->find($userId);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: User not authenticated'
                ], 401);
            }

            // Get the current token's abilities
            $token = $user->currentAccessToken();
            if (!$token) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No valid token found'
                ], 200);
            }

            // Extract branch ID from token abilities
            $branchId = $request->branch_id;
            foreach ($token->abilities as $ability) {
                if (strpos($ability, 'branch:') === 0) {
                    $branchId = (int) str_replace('branch:', '', $ability);
                    break;
                }
            }

            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No branch associated with the user'
                ], 200);
            }


            $lastAdjustmentVoucherNumber = StockAdjustment::where('reference_no', 'like', "Adj{$fiscalYearCode}-{$branchId}-%")
                ->orderBy('id', 'desc')
                ->first();

            // Generate sequential number
            $lastNumber = $lastAdjustmentVoucherNumber ? (int) substr($lastAdjustmentVoucherNumber->reference_no, -6) : 0;
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            // Generate purchase bill number
            $AdjustmentVoucherNumber = "Adj{$fiscalYearCode}-{$branchId}-{$newNumber}";

            return response()->json([
                'status' => 'success',
                'data' => [
                    'reference_no' => $AdjustmentVoucherNumber,
                    'fiscal_year' => $fiscalYearCode,
                    'branch_id' => $branchId
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error generating stock adjustment number: ' . $e->getMessage()
            ], 400);
        }
    }




    // public function generatePurchaseBillNumber(Request $request)
    // {
    //     try {
    //         // Get date from request or use current date
    //         $inputDate = $request->input('date', Carbon::now()->toDateString());
    //         $carbonDate = Carbon::parse($inputDate);

    //         // Convert to BS date
    //         $bsDate = NepaliDate::create($carbonDate)->toBS();
    //         $bsDateParts = explode('-', $bsDate);
    //         $currentBsYear = (int)$bsDateParts[0];
    //         $currentBsMonth = (int)$bsDateParts[1];

    //         // Determine fiscal year
    //         $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;
    //         $fiscalYearCode = substr($fiscalYear, 2, 2) . substr($fiscalYear + 1, 2, 2);

    //         // Get authenticated user
    //         $user = Auth::guard('api')->user();
    //         if (!$user) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => 'Unauthorized: User not authenticated'
    //             ], 401);
    //         }

    //         // Get the current token's abilities
    //         $token = $user->currentAccessToken();
    //         if (!$token) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => 'No valid token found'
    //             ], 200);
    //         }

    //         // Extract branch ID from token abilities
    //         $branchId = null;
    //         foreach ($token->abilities as $ability) {
    //             if (strpos($ability, 'branch:') === 0) {
    //                 $branchId = (int) str_replace('branch:', '', $ability);
    //                 break;
    //             }
    //         }

    //         if (!$branchId) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => 'No branch associated with the user'
    //             ], 200);
    //         }

    //         // Get last purchase record for the current fiscal year and branch
    //         $lastPurchase = Purchase::where('purchase_bill_number', 'like', "P{$fiscalYearCode}-{$branchId}-%")
    //             ->orderBy('id', 'desc')
    //             ->first();

    //         // Generate sequential number
    //         $lastNumber = $lastPurchase ? (int)substr($lastPurchase->purchase_bill_number, -6) : 0;
    //         $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

    //         // Generate purchase bill number
    //         $purchaseBillNumber = "P{$fiscalYearCode}-{$branchId}-{$newNumber}";

    //         return response()->json([
    //             'status' => 'success',
    //             'data' => [
    //                 'purchase_bill_number' => $purchaseBillNumber,
    //                 'fiscal_year' => $fiscalYearCode,
    //                 'branch_id' => $branchId
    //             ]
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Error generating purchase bill number: ' . $e->getMessage()
    //         ], 400);
    //     }
    // }
}