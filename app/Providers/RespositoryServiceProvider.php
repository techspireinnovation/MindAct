<?php

namespace App\Providers;



use App\Models\ProductCategory;
use Illuminate\Support\ServiceProvider;

use App\Interfaces\ProductCategoryRepositoryInterface;
use App\Repositories\ProductCategoryRepository;

use App\Interfaces\BrandRepositoryInterface;
use App\Repositories\BrandRepository;

use App\Interfaces\CashRepositoryInterface;
use App\Repositories\CashRepository;

use App\Interfaces\ProductTypeRepositoryInterface;
use App\Repositories\ProductTypeRepository;

use App\Interfaces\BranchRepositoryInterface;
use App\Repositories\BranchRepository;

use App\Interfaces\SalesmanRepositoryInterface;
use App\Repositories\SalesmanRepository;

use App\Interfaces\StoreRepositoryInterface;
use App\Repositories\StoreRepository;

use App\Interfaces\LocationRepositoryInterface;
use App\Repositories\LocationRepository;

use App\Interfaces\MeasureUnitRepositoryInterface;
use App\Repositories\MeasureUnitRepository;


use App\Interfaces\ProductRepositoryInterface;
use App\Repositories\ProductRepository;

use App\Interfaces\PartyRepositoryInterface;
use App\Repositories\PartyRepository;


use App\Interfaces\MeasureUnitConversionRepositoryInterface;
use App\Repositories\MeasureUnitConversionRepository;

use App\Interfaces\AreaRepositoryInterface;
use App\Repositories\AreaRepository;


use App\Interfaces\BankRepositoryInterface;
use App\Repositories\BankRepository;


use App\Interfaces\FiscalYearRepositoryInterface;
use App\Repositories\FiscalYearRepository;

use App\Interfaces\StockRepositoryInterface;
use App\Repositories\StockRepository;

use App\Interfaces\StockPurchaseRepositoryInterface;
use App\Repositories\StockPurchaseRepository;

use App\Interfaces\StockPurchaseReturnRepositoryInterface;
use App\Repositories\StockPurchaseReturnRepository;

use App\Interfaces\StockPurchaseReturnItemWiseRepositoryInterface;
use App\Repositories\StockPurchaseReturnItemWiseRepository;

use App\Interfaces\StockSaleRepositoryInterface;
use App\Repositories\StockSaleRepository;


class RespositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(ProductCategoryRepositoryInterface::class, ProductCategoryRepository::class);
        $this->app->bind(BrandRepositoryInterface::class, BrandRepository::class);
        $this->app->bind(CashRepositoryInterface::class, CashRepository::class);
        $this->app->bind(ProductTypeRepositoryInterface::class, ProductTypeRepository::class);
        $this->app->bind(BranchRepositoryInterface::class, BranchRepository::class);
        $this->app->bind(SalesmanRepositoryInterface::class, SalesmanRepository::class);
        $this->app->bind(StoreRepositoryInterface::class, StoreRepository::class);
        $this->app->bind(LocationRepositoryInterface::class, LocationRepository::class);
        $this->app->bind(MeasureUnitRepositoryInterface::class, MeasureUnitRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, ProductRepository::class);
        $this->app->bind(PartyRepositoryInterface::class, PartyRepository::class);
        $this->app->bind(MeasureUnitConversionRepositoryInterface::class, MeasureUnitConversionRepository::class);
        $this->app->bind(AreaRepositoryInterface::class, AreaRepository::class);
        $this->app->bind(BankRepositoryInterface::class, BankRepository::class);
        $this->app->bind(FiscalYearRepositoryInterface::class, FiscalYearRepository::class);
        $this->app->bind(StockRepositoryInterface::class, StockRepository::class);
        $this->app->bind(StockPurchaseRepositoryInterface::class, StockPurchaseRepository::class);
        $this->app->bind(StockPurchaseReturnRepositoryInterface::class, StockPurchaseReturnRepository::class);
        $this->app->bind(StockPurchaseReturnItemWiseRepositoryInterface::class, StockPurchaseReturnItemWiseRepository::class);
        $this->app->bind(StockSaleRepositoryInterface::class, StockSaleRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
