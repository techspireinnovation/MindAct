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
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
