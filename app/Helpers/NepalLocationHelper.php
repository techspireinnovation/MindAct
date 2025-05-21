<?php

namespace App\Helpers;

use Sagautam5\LocalStateNepal\Entities\Province;

class NepalLocationHelper
{
    public static function getListProvince()
    {
        $province = new Province('en');
        return $province->allProvinces();
    }

    public static function getListProvincewithDistrict()
    {
        $province = new Province('en');
        return $province->getProvincesWithDistricts();
    }

    public static function getListProvinceWithDistrictsWithMunicipality()
    {
        $province = new Province('en');
        return $province->getProvincesWithDistrictsWithMunicipalities();
    }
}
