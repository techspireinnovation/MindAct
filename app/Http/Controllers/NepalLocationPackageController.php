<?php

namespace App\Http\Controllers;
use App\Helpers\NepalLocationHelper;
use Illuminate\Http\Request;

class NepalLocationPackageController extends Controller
{
    public function Province(){
       
        $provincesData = NepalLocationHelper::getListProvince();
        return response()->json($provincesData);

    }


    public function ProvincewithDistrict(){
       
        $provincesData = NepalLocationHelper::getListProvinceWithDistrict();
        return response()->json($provincesData);

    }

    public function ProvincewithDistrictandMunicipality(){
       
        $provincesData = NepalLocationHelper::getListProvinceWithDistrictsWithMunicipality();
        return response()->json($provincesData);

    }
}
