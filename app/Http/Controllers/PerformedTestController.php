<?php

namespace App\Http\Controllers;

use App\Models\PerformedTest;


class PerformedTestController extends Controller
{
    public function index($id)
    {
        return PerformedTest::where('testId', $id)
                ->get();
    }

}
