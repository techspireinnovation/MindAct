<?php

namespace App\Helpers;

use Sagautam5\LocalStateNepal\Entities\Province;

class NepalLocationHelper
{
    public static function getListProvince()
    {
        $province = new Province('np');
        return $province->allProvinces();
    }

    public static function getListProvincewithDistrict()
    {
        $province = new Province('np');
        return $province->getProvincesWithDistricts();
    }

    public static function getListProvinceWithDistrictsWithMunicipality()
    {
        $province = new Province('np');
        return $province->getProvincesWithDistrictsWithMunicipalities();
    }
}
