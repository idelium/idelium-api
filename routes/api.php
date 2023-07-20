<?php

use App\Http\Controllers\SideBarController;
use App\Http\Controllers\HeaderController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\CostumerController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\PluginController;
use App\Http\Controllers\StepController;
use App\Http\Controllers\ImportSeleniumController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\TestCycleController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PerformedTestCycleController;
use App\Http\Controllers\PerformedTestController;
use App\Http\Controllers\PerformedStepController;
use App\Http\Controllers\IdeliumClController;
use App\Http\Controllers\IdeliumInsertClController;
use App\Http\Controllers\TestLauncherController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TypeController;
use App\Http\Controllers\OsController;
use App\Http\Controllers\VersionOsController;
use App\Http\Controllers\BrowserController;
use App\Http\Controllers\VersionBrowserController;
use App\Http\Controllers\BrandDeviceController;
use App\Http\Controllers\ModelDeviceController;
use App\Http\Controllers\LocationController;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->name('csrf.show');
Route::post('login', [LoginController::class, 'login'])
    ->name('login');;
Route::get('logout', [LoginController::class, 'logout'])
    ->name('logout');;

Route::middleware('auth:sanctum')->group(function () {
    /* menu */
    Route::get('menu/sidebar', [SideBarController::class, 'index'])
        ->name('sidebar.index');
    Route::get('menu/header', [HeaderController::class, 'index'])
        ->name('header.index');
    Route::put('menu/header/{idCostumer}', [HeaderController::class, 'changeCostumer'])
        ->name('header.changeCostumer');
    /* roles */
    Route::get('admin/roles', [RoleController::class, 'index'])
        ->name('roles.index');
    /* profile */
    Route::get('admin/profile', [UserController::class, 'getuser'])
        ->name('accounts.getuser');
    Route::put('admin/profile', [UserController::class, 'updatePasswordUser'])
        ->name('accounts.updatePasswordUser');
    /* accounts */
    Route::get('admin/accounts', [UserController::class, 'index'])
        ->name('accounts.index');
    Route::post('admin/accounts', [UserController::class, 'store'])
        ->name('accounts.store');
    Route::put('admin/accounts/{idUser}', [UserController::class, 'update'])
        ->name('accounts.update');
    Route::delete('admin/accounts/{idUser}', [UserController::class, 'destroy'])
        ->name('accounts.destroy');
    /* costumers */
    Route::get('admin/costumers', [CostumerController::class, 'index'])
        ->name('costumers.index');
    Route::post('admin/costumers', [CostumerController::class, 'store'])
        ->name('costumers.store');
    Route::put('admin/costumers/{idCostumer}', [CostumerController::class, 'update'])
        ->name('costumers.update');
    Route::delete('admin/costumers/{idCostumer}', [CostumerController::class, 'destroy'])
        ->name('costumers.destroy');
    /* apikey */
    Route::get('admin/apikey', [CostumerController::class, 'getKey'])
        ->name('costumers.getKey');
    Route::put('admin/apikey', [CostumerController::class, 'updateKey'])
        ->name('costumers.updateKey');
    /* projects */
    Route::resource('admin/projects', ProjectController::class);
    /* testlauncher */
    Route::post('admin/launchtest', [TestLauncherController::class, 'launchTest'])
        ->name('testlauncher.launchTest');
    /* testcycles */
    Route::get('admin/testcycles/{idProject}', [TestCycleController::class, 'index'])
        ->name('testcycles.index');
    Route::get('admin/testcycles/{idProject}/{testcycle}', [TestCycleController::class, 'show'])
        ->name('testcycles.show');
    Route::put('admin/testcycles/{idProject}/{testcycle}', [TestCycleController::class, 'update'])
        ->name('testcycles.update');
    Route::post('admin/testcycles', [TestCycleController::class, 'store'])
        ->name('testcycles.store');
    /* importtest */
    Route::post('admin/importtest', [ImportSeleniumController::class, 'store'])
        ->name('importselenium.store');
    /* tests */
    Route::get('admin/tests/{idProject}', [TestController::class, 'index'])
        ->name('tests.index');
    Route::get('admin/tests/{idProject}/{test}', [TestController::class, 'show'])
        ->name('tests.show');
    Route::put('admin/tests/{idProject}/{test}', [TestController::class, 'update'])
        ->name('tests.update');
    Route::post('admin/tests', [TestController::class, 'store'])
        ->name('tests.store');
    /* steps */
    Route::post('admin/steps', [StepController::class, 'store'])
        ->name('steps.store');
    Route::get('admin/steps/{idProject}', [StepController::class, 'index'])
        ->name('steps.index');
    Route::get('admin/steps/{idProject}/{step}', [StepController::class, 'show'])
        ->name('steps.show');
    Route::put('admin/steps/{idProject}/{step}', [StepController::class, 'update'])
        ->name('steps.update');
    Route::delete('admin/steps/{idProject}/{environment}', [StepController::class, 'destroy'])
        ->name('steps.destroy');
    Route::post('admin/steps/{idProject}/updateorder', [StepController::class, 'updateorder'])
        ->name('steps.updateorder');
    /* plugins */
    Route::get('admin/plugins/{idProject}/{plugin}', [PluginController::class, 'show'])
        ->name('plugins.show');
    Route::get('admin/plugins/{idProject}', [PluginController::class, 'index'])
        ->name('plugins.index');
    Route::delete('admin/plugins/{idProject}/{plugin}', [PluginController::class, 'destroy'])
        ->name('plugins.destroy');
    Route::post('admin/plugins', [PluginController::class, 'store'])
        ->name('plugins.store');
    Route::put('admin/plugins/{idProject}/{step}', [PluginController::class, 'update'])
        ->name('plugins.update');
    /* environments */
    Route::get('admin/environments/{idProject}', [EnvironmentController::class, 'index'])
        ->name('environments.index');
    Route::get('admin/environments/{idProject}/{environment}', [EnvironmentController::class, 'show'])
        ->name('environments.show');
    Route::delete('admin/environments/{idProject}/{environment}', [EnvironmentController::class, 'destroy'])
        ->name('environments.destroy');
    Route::put('admin/environments/{idProject}/{environment}', [EnvironmentController::class, 'update'])
        ->name('environments.update');
    Route::post('admin/environments', [EnvironmentController::class, 'store'])
        ->name('environments.store');
        /* performed testcycles */;
    Route::get('admin/testcyclesperfomed/{idTestCyclePerformed}', [PerformedTestCycleController::class, 'index'])
        ->name('testcyclesperfomed.index');
        /* performed test */;
    Route::get('admin/testsperfomed/{idTestPerformed}', [PerformedTestController::class, 'index'])
        ->name('testsperfomed.index');
        /* performed step */;
    Route::get('admin/stepsperfomed/{idTestPerformed}', [PerformedStepController::class, 'index'])
        ->name('testsperfomed.index');
        /* platforms */;
    Route::get('admin/platforms/manageplatforms/{type}', [PlatformController::class, 'index'])
        ->name('platform.index');
    Route::post('admin/platforms/manageplatforms', [PlatformController::class, 'store'])
        ->name('platform.store');
    Route::put('admin/platforms/manageplatforms', [PlatformController::class, 'update'])
        ->name('platform.update');
    Route::delete('admin/platforms/manageplatforms/{type}/{id}', [PlatformController::class, 'delete'])
        ->name('platform.delete');
    /* platforms-status */
    Route::get('admin/platforms/status', [StatusController::class, 'index'])
        ->name('status.index');
    /* platforms-types */
    Route::get('admin/platforms/types', [TypeController::class, 'index'])
        ->name('types.index');
    /* platforms-os */
    Route::get('admin/platforms/os/{idType}', [OsController::class, 'index'])
        ->name('os.index');
    Route::post('admin/platforms/os', [OsController::class, 'store'])
        ->name('os.store');
    Route::put('admin/platforms/os', [OsController::class, 'update'])
        ->name('os.update');
    /* platforms-osversion */
    Route::get('admin/platforms/osversion/{idOs}', [VersionOsController::class, 'index'])
        ->name('osversion.index');
    Route::post('admin/platforms/osversion', [VersionOsController::class, 'store'])
        ->name('osversion.store');
    Route::put('admin/platforms/osversion', [VersionOsController::class, 'update'])
        ->name('osversion.update');
    /* platforms-browsers */
    Route::get('admin/platforms/browsers/{idOs}', [BrowserController::class, 'index'])
        ->name('browser.index');
    Route::post('admin/platforms/browsers', [BrowserController::class, 'store'])
        ->name('browser.store');
    Route::put('admin/platforms/browsers', [BrowserController::class, 'update'])
        ->name('browser.update');
    /* platforms-browserversions */
    Route::get('admin/platforms/browserversions/{idBrowser}', [VersionBrowserController::class, 'index'])
        ->name('versionbrowser.index');
    Route::post('admin/platforms/browserversions', [VersionBrowserController::class, 'store'])
        ->name('versionbrowser.store');
    Route::put('admin/platforms/browserversions', [VersionBrowserController::class, 'update'])
        ->name('versionbrowser.update');
    /* platforms-brands */
    Route::get('admin/platforms/brands', [BrandDeviceController::class, 'index'])
        ->name('brandevice.index');
    Route::post('admin/platforms/brands', [BrandDeviceController::class, 'store'])
        ->name('brandevice.store');
    Route::put('admin/platforms/brands', [BrandDeviceController::class, 'update'])
        ->name('brandevice.update');
    /* platforms-models */
    Route::get('admin/platforms/models/{idBrand}', [ModelDeviceController::class, 'index'])
        ->name('model.index');
    Route::post('admin/platforms/models', [ModelDeviceController::class, 'store'])
        ->name('model.store');
    Route::put('admin/platforms/models', [ModelDeviceController::class, 'update'])
        ->name('model.update');
    /* platforms-locations */
    Route::get('admin/platforms/locations', [LocationController::class, 'index'])
        ->name('location.index');
    Route::post('admin/platforms/locations', [LocationController::class, 'store'])
        ->name('location.store');
    Route::put('admin/platforms/locations', [LocationController::class, 'update'])
        ->name('location.update');
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/* command line api */
Route::get('ideliumcl/testcycle/{idTestCycle}', [IdeliumClController::class, 'getTestCycle'])
    ->name('cl.getTestCycle');
Route::get('ideliumcl/test/{idTest}', [IdeliumClController::class, 'getTest'])
    ->name('cl.getTest');
Route::get('ideliumcl/step/{idStep}', [IdeliumClController::class, 'getStep'])
    ->name('cl.getStep');
Route::get('ideliumcl/plugins/{idProject}', [IdeliumClController::class, 'getPlugins'])
    ->name('cl.getPlugins');
Route::get('ideliumcl/plugin/{idPlugin}', [IdeliumClController::class, 'getPlugin'])
    ->name('cl.getPlugin');
Route::get('ideliumcl/environments/{idProject}', [IdeliumClController::class, 'getEnvironments'])
    ->name('cl.getEnvironments');
Route::get('ideliumcl/environment/{idEnvironment}', [IdeliumClController::class, 'getEnvironment'])
    ->name('cl.getEnvironment');

Route::post('ideliumcl/testcycle', [IdeliumInsertClController::class, 'createFolder'])
    ->name('cl.createFolder');
Route::post('ideliumcl/test', [IdeliumInsertClController::class, 'createTest'])
    ->name('cl.createTest');
Route::put('ideliumcl/test', [IdeliumInsertClController::class, 'updateTest'])
    ->name('cl.updateTest');
Route::post('ideliumcl/step', [IdeliumInsertClController::class, 'createStep'])
    ->name('cl.createStep');
Route::put('ideliumcl/step', [IdeliumInsertClController::class, 'updateStep'])
    ->name('cl.updateStep');
