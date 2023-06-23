<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SideBarController extends Controller
{
    public function index(Request $request)
    {
        $role = Auth::user()->role;
        $json = '[
            {
                "icon": "vials",
                "name": "testsperformed",
                "link": "testsperformed",
                "class": "",
                "isActiveEmptyDb": false
            },
            {
                "icon": "rocket",
                "name": "testlauncher",
                "link": "testlauncher",
                "class": "",
                "isActiveEmptyDb": false
            },
            {
                "icon": "sync",
                "name": "testcycles",
                "link": "testcycles",
                "class": "",
                "isActiveEmptyDb": false
            },
            {
                "icon": "vial",
                "name": "tests",
                "link": "tests",
                "class": "",
                "isActiveEmptyDb": false
            },
            {
                "icon": "shoe-prints",
                "name": "steps",
                "link": "steps",
                "class": "fa-rotate-270",
                "isActiveEmptyDb": false
            },
            {
                "icon": "plug",
                "name": "plugins",
                "link": "plugins",
                "class": "",
                "isActiveEmptyDb": false
            },
            {
                "icon": "leaf",
                "name": "environments",
                "link": "environments",
                "class": "",
                "isActiveEmptyDb": false
            },
            {
                "icon": "project-diagram",
                "name": "projects",
                "link": "projects",
                "class": "",
                "isActiveEmptyDb": true
            }
        ]';
        $sidebar = json_decode($json);
        if ($role < 3) {
            $sidebar[] = json_decode(
                '{
                "icon": "users",
                "name": "account",
                "link": "account",
                "class": "",
                "isActiveEmptyDb": true
                }'
            );
            $sidebar[] = json_decode(
                '{
                "icon": "key",
                "name": "apikey",
                "link": "apikey",
                "class": "",
                "isActiveEmptyDb": true
                }'
            );
        }
        if ($role == 1) {
            $sidebar[] = json_decode(
                '{
                "icon": "building",
                "name": "costumers",
                "link": "costumers",
                "class": "",
                "isActiveEmptyDb": true
                }
                '
            );
            $sidebar[] = json_decode(
                '{
                "icon": "laptop",
                "name": "platforms",
                "link": "platforms",
                "class": "",
                "isActiveEmptyDb": true
                }
                '
            );
        }
        return response()->json($sidebar);
    }
}
