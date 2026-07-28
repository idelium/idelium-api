# API Reference

This page is generated from the Laravel route table in `routes/api.php` and is
intended to be the human-readable index of every public API endpoint exposed by
Idelium API. The `/api` prefix is shown explicitly because Docker deployments
publish the API behind the same HTTPS origin used by Idelium Web, for example
`https://localhost/api/...` in the local quick-start stack.

The route table is grouped by product capability. Request and response examples,
validation rules, and operational policies are described in the domain pages
linked from the wiki sidebar.

## Contract conventions

- Browser/admin routes use Laravel Sanctum session authentication and CSRF.
- CLI control-plane routes use `Idelium-Key` while the service-account and run-token migration is completed.
- Runner data-plane routes use short-lived `Idelium-Run-Token` and `Idelium-Worker-Token` credentials.
- Tenant-owned resources are always scoped by the authenticated customer context.
- Error responses use standard HTTP status codes. Legacy non-standard status codes must not be used by new clients.
- Sensitive values, authorization headers, session identifiers, API keys, and token secrets must never be logged or serialized.


## Authentication and identity entrypoints

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `POST` | `/api/login` | `login` | `LoginController@login` | api |
| `POST` | `/api/logout` | `logout` | `LoginController@logout` | api, auth:sanctum, tenant.context |
| `POST` | `/api/oidc/token-exchange` | `oidc.token-exchange` | `OidcWorkloadIdentityController@exchange` | api |
| `GET|HEAD` | `/api/sanctum/csrf-cookie` | `csrf.show` | `Laravel\Sanctum\Http\Controllers\CsrfCookieController@show` | api |
| `POST` | `/api/sso/{identityProvider}/oidc/callback` | `sso.oidcCallback` | `SsoAuthenticationController@oidcCallback` | api |
| `POST` | `/api/sso/{identityProvider}/saml/callback` | `sso.samlCallback` | `SsoAuthenticationController@samlCallback` | api |
| `POST` | `/api/sso/{identityProvider}/start` | `sso.start` | `SsoAuthenticationController@start` | api |


## Web navigation support

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `GET|HEAD` | `/api/menu/header` | `header.index` | `HeaderController@index` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/menu/header/{idCostumer}` | `header.changeCostumer` | `HeaderController@changeCostumer` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/menu/sidebar` | `sidebar.index` | `SideBarController@index` | api, auth:sanctum, tenant.context |


## Administration, accounts, capabilities, and agents

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `GET|HEAD` | `/api/admin/accounts` | `accounts.index` | `UserController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/accounts` | `accounts.store` | `UserController@store` | api, auth:sanctum, tenant.context |
| `DELETE` | `/api/admin/accounts/{idUser}` | `accounts.destroy` | `UserController@destroy` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/accounts/{idUser}` | `accounts.update` | `UserController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/agents` | `agents.index` | `AgentRegistrationController@index` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/agents/{agentRegistration}/status` | `agents.updateStatus` | `AgentRegistrationController@updateStatus` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/apikey` | `costumers.getKey` | `CostumerController@getKey` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/apikey` | `costumers.updateKey` | `CostumerController@updateKey` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/costumers` | `costumers.index` | `CostumerController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/costumers` | `costumers.store` | `CostumerController@store` | api, auth:sanctum, tenant.context |
| `DELETE` | `/api/admin/costumers/{idCostumer}` | `costumers.destroy` | `CostumerController@destroy` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/costumers/{idCostumer}` | `costumers.update` | `CostumerController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/profile` | `accounts.getuser` | `UserController@getuser` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/profile` | `accounts.updatePasswordUser` | `UserController@updatePasswordUser` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/profile/mfa/confirm` | `mfa.confirm` | `MfaController@confirm` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/profile/mfa/enroll` | `mfa.enroll` | `MfaController@enroll` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/profile/mfa/step-up` | `mfa.stepUp` | `MfaController@stepUp` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/roles` | `roles.index` | `RoleController@index` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/service-accounts` | `service-accounts.index` | `ServiceAccountController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/service-accounts` | `service-accounts.store` | `ServiceAccountController@store` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/service-accounts/{serviceAccount}/revoke` | `service-accounts.revoke` | `ServiceAccountController@revoke` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/me/capabilities` | `capabilities.me` | `CapabilityController@me` | api, auth:sanctum, tenant.context |


## Identity lifecycle and audit

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `PUT` | `/api/admin/identity/accounts/{user}/break-glass` | `identity.updateBreakGlass` | `IdentityLifecycleController@updateBreakGlass` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/identity/accounts/{user}/break-glass/test` | `identity.recordBreakGlassTest` | `IdentityLifecycleController@recordBreakGlassTest` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/identity/providers` | `identity.providers` | `IdentityLifecycleController@providers` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/identity/providers` | `identity.storeProvider` | `IdentityLifecycleController@storeProvider` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/identity/providers/{identityProvider}/scim/users` | `identity.scimUpsertUser` | `IdentityLifecycleController@scimUpsertUser` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/audit-events` | `audit-events.index` | `AuditEventController@index` | api, auth:sanctum, tenant.context |


## Test design and execution setup

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `POST` | `/api/admin/environments` | `environments.store` | `EnvironmentController@store` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/environments/{idProject}` | `environments.index` | `EnvironmentController@index` | api, auth:sanctum, tenant.context |
| `DELETE` | `/api/admin/environments/{idProject}/{environment}` | `environments.destroy` | `EnvironmentController@destroy` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/environments/{idProject}/{environment}` | `environments.show` | `EnvironmentController@show` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/environments/{idProject}/{environment}` | `environments.update` | `EnvironmentController@update` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/importtest` | `importselenium.store` | `ImportSeleniumController@store` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/launchtest` | `testlauncher.launchTest` | `TestLauncherController@launchTest` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/plugins` | `plugins.store` | `PluginController@store` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/plugins/{idProject}` | `plugins.index` | `PluginController@index` | api, auth:sanctum, tenant.context |
| `DELETE` | `/api/admin/plugins/{idProject}/{plugin}` | `plugins.destroy` | `PluginController@destroy` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/plugins/{idProject}/{plugin}` | `plugins.show` | `PluginController@show` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/plugins/{idProject}/{step}` | `plugins.update` | `PluginController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects` | `projects.index` | `ProjectController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects` | `projects.store` | `ProjectController@store` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects/create` | `projects.create` | `ProjectController@create` | api, auth:sanctum, tenant.context |
| `DELETE` | `/api/admin/projects/{project}` | `projects.destroy` | `ProjectController@destroy` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects/{project}` | `projects.show` | `ProjectController@show` | api, auth:sanctum, tenant.context |
| `PUT|PATCH` | `/api/admin/projects/{project}` | `projects.update` | `ProjectController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects/{project}/edit` | `projects.edit` | `ProjectController@edit` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/steps` | `steps.store` | `StepController@store` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/steps/{idProject}` | `steps.index` | `StepController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/steps/{idProject}/updateorder` | `steps.updateorder` | `StepController@updateorder` | api, auth:sanctum, tenant.context |
| `DELETE` | `/api/admin/steps/{idProject}/{environment}` | `steps.destroy` | `StepController@destroy` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/steps/{idProject}/{step}` | `steps.show` | `StepController@show` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/steps/{idProject}/{step}` | `steps.update` | `StepController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/stepsperfomed/{idTestPerformed}` | `testsperfomed.index` | `PerformedStepController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/testcycles` | `testcycles.store` | `TestCycleController@store` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/testcycles/{idProject}` | `testcycles.index` | `TestCycleController@index` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/testcycles/{idProject}/{testcycle}` | `testcycles.show` | `TestCycleController@show` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/testcycles/{idProject}/{testcycle}` | `testcycles.update` | `TestCycleController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/testcyclesperfomed/{idTestCyclePerformed}` | `testcyclesperfomed.index` | `PerformedTestCycleController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/tests` | `tests.store` | `TestController@store` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/tests/{idProject}` | `tests.index` | `TestController@index` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/tests/{idProject}/{test}` | `tests.show` | `TestController@show` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/tests/{idProject}/{test}` | `tests.update` | `TestController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/testsperfomed/{idTestPerformed}` | `testsperfomed.index` | `PerformedTestController@index` | api, auth:sanctum, tenant.context |


## Platform catalogues

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `GET|HEAD` | `/api/admin/platforms/brands` | `brandevice.index` | `BrandDeviceController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/platforms/brands` | `brandevice.store` | `BrandDeviceController@store` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/platforms/brands` | `brandevice.update` | `BrandDeviceController@update` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/platforms/browsers` | `browser.store` | `BrowserController@store` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/platforms/browsers` | `browser.update` | `BrowserController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/platforms/browsers/{idOs}` | `browser.index` | `BrowserController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/platforms/browserversions` | `versionbrowser.store` | `VersionBrowserController@store` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/platforms/browserversions` | `versionbrowser.update` | `VersionBrowserController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/platforms/browserversions/{idBrowser}` | `versionbrowser.index` | `VersionBrowserController@index` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/platforms/locations` | `location.index` | `LocationController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/platforms/locations` | `location.store` | `LocationController@store` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/platforms/locations` | `location.update` | `LocationController@update` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/platforms/manageplatforms` | `platform.store` | `PlatformController@store` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/platforms/manageplatforms` | `platform.update` | `PlatformController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/platforms/manageplatforms/{type}` | `platform.index` | `PlatformController@index` | api, auth:sanctum, tenant.context |
| `DELETE` | `/api/admin/platforms/manageplatforms/{type}/{id}` | `platform.delete` | `PlatformController@delete` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/platforms/models` | `model.store` | `ModelDeviceController@store` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/platforms/models` | `model.update` | `ModelDeviceController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/platforms/models/{idBrand}` | `model.index` | `ModelDeviceController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/platforms/os` | `os.store` | `OsController@store` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/platforms/os` | `os.update` | `OsController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/platforms/os/{idType}` | `os.index` | `OsController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/platforms/osversion` | `osversion.store` | `VersionOsController@store` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/platforms/osversion` | `osversion.update` | `VersionOsController@update` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/platforms/osversion/{idOs}` | `osversion.index` | `VersionOsController@index` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/platforms/status` | `status.index` | `StatusController@index` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/platforms/types` | `types.index` | `TypeController@index` | api, auth:sanctum, tenant.context |


## Parallel execution scheduling

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `GET|HEAD` | `/api/admin/projects/{idProject}/parallel-runs` | `parallelruns.index` | `ParallelRunScheduleController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/parallel-runs` | `parallelruns.store` | `ParallelRunScheduleController@store` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/parallel-runs/matrix` | `parallelruns.storeMatrix` | `ParallelRunScheduleController@storeMatrix` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects/{idProject}/parallel-runs/{parallelRun}` | `parallelruns.show` | `ParallelRunScheduleController@show` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/parallel-runs/{parallelRun}/cancel` | `parallelruns.cancel` | `ParallelRunScheduleController@cancel` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/parallel-runs/{parallelRun}/claim` | `parallelruns.claimWorker` | `ParallelRunScheduleController@claimWorker` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects/{idProject}/parallel-runs/{parallelRun}/results` | `parallelruns.results` | `ParallelRunScheduleController@results` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/projects/{idProject}/parallel-runs/{parallelRun}/workers/{workerId}` | `parallelruns.updateWorker` | `ParallelRunScheduleController@updateWorker` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/parallel-runs/{parallelRun}/workers/{workerId}/heartbeat` | `parallelruns.heartbeatWorker` | `ParallelRunScheduleController@heartbeatWorker` | api, auth:sanctum, tenant.context |


## CLI control plane and reporting

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `POST` | `/api/ideliumcl/agents/register` | `cl.agents.register` | `AgentRegistrationController@register` | api, AuthenticateIdeliumKey |
| `GET|HEAD` | `/api/ideliumcl/environment/{idEnvironment}` | `cl.getEnvironment` | `IdeliumClController@getEnvironment` | api, AuthenticateIdeliumKey |
| `GET|HEAD` | `/api/ideliumcl/environments/{idProject}` | `cl.getEnvironments` | `IdeliumClController@getEnvironments` | api, AuthenticateIdeliumKey |
| `GET|HEAD` | `/api/ideliumcl/plugin/{idPlugin}` | `cl.getPlugin` | `IdeliumClController@getPlugin` | api, AuthenticateIdeliumKey |
| `GET|HEAD` | `/api/ideliumcl/plugins/{idProject}` | `cl.getPlugins` | `IdeliumClController@getPlugins` | api, AuthenticateIdeliumKey |
| `GET|HEAD` | `/api/ideliumcl/projects/{idProject}/parallel-runs` | `cl.parallelruns.index` | `ParallelRunScheduleController@index` | api, AuthenticateIdeliumKey |
| `POST` | `/api/ideliumcl/projects/{idProject}/parallel-runs` | `cl.parallelruns.store` | `ParallelRunScheduleController@store` | api, AuthenticateIdeliumKey |
| `POST` | `/api/ideliumcl/projects/{idProject}/parallel-runs/matrix` | `cl.parallelruns.storeMatrix` | `ParallelRunScheduleController@storeMatrix` | api, AuthenticateIdeliumKey |
| `GET|HEAD` | `/api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}` | `cl.parallelruns.show` | `ParallelRunScheduleController@show` | api, AuthenticateIdeliumKey |
| `POST` | `/api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/cancel` | `cl.parallelruns.cancel` | `ParallelRunScheduleController@cancel` | api, AuthenticateIdeliumKey |
| `POST` | `/api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/claim` | `cl.parallelruns.claimWorker` | `ParallelRunScheduleController@claimWorker` | api, AuthenticateIdeliumKey |
| `GET|HEAD` | `/api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/results` | `cl.parallelruns.results` | `ParallelRunScheduleController@results` | api, AuthenticateIdeliumKey |
| `POST` | `/api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/tokens` | `cl.parallelruns.issueRunToken` | `ParallelRunScheduleController@issueRunToken` | api, AuthenticateIdeliumKey |
| `POST` | `/api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/tokens/{tokenId}/revoke` | `cl.parallelruns.revokeRunToken` | `ParallelRunScheduleController@revokeRunToken` | api, AuthenticateIdeliumKey |
| `PUT` | `/api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/workers/{workerId}` | `cl.parallelruns.updateWorker` | `ParallelRunScheduleController@updateWorker` | api, AuthenticateIdeliumKey |
| `POST` | `/api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/workers/{workerId}/heartbeat` | `cl.parallelruns.heartbeatWorker` | `ParallelRunScheduleController@heartbeatWorker` | api, AuthenticateIdeliumKey |
| `POST` | `/api/ideliumcl/step` | `cl.createStep` | `IdeliumInsertClController@createStep` | api, AuthenticateIdeliumKey |
| `PUT` | `/api/ideliumcl/step` | `cl.updateStep` | `IdeliumInsertClController@updateStep` | api, AuthenticateIdeliumKey |
| `GET|HEAD` | `/api/ideliumcl/step/{idStep}` | `cl.getStep` | `IdeliumClController@getStep` | api, AuthenticateIdeliumKey |
| `POST` | `/api/ideliumcl/test` | `cl.createTest` | `IdeliumInsertClController@createTest` | api, AuthenticateIdeliumKey |
| `PUT` | `/api/ideliumcl/test` | `cl.updateTest` | `IdeliumInsertClController@updateTest` | api, AuthenticateIdeliumKey |
| `GET|HEAD` | `/api/ideliumcl/test/{idTest}` | `cl.getTest` | `IdeliumClController@getTest` | api, AuthenticateIdeliumKey |
| `POST` | `/api/ideliumcl/testcycle` | `cl.createFolder` | `IdeliumInsertClController@createFolder` | api, AuthenticateIdeliumKey |
| `GET|HEAD` | `/api/ideliumcl/testcycle/{idTestCycle}` | `cl.getTestCycle` | `IdeliumClController@getTestCycle` | api, AuthenticateIdeliumKey |


## Runner token data plane

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `POST` | `/api/ideliumrunner/claim` | `runner.claim` | `ParallelRunScheduleController@claimWorkerWithRunToken` | api |
| `POST` | `/api/ideliumrunner/heartbeat` | `runner.heartbeat` | `ParallelRunScheduleController@heartbeatWorkerWithToken` | api |
| `PUT` | `/api/ideliumrunner/worker` | `runner.updateWorker` | `ParallelRunScheduleController@updateWorkerWithToken` | api |


## Execution artifacts and result exports

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `GET|HEAD` | `/api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts` | `artifacts.index` | `ArtifactDescriptorController@index` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}` | `artifacts.show` | `ArtifactDescriptorController@show` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}/archive` | `artifacts.archive` | `ArtifactDescriptorController@archive` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}/delete-marker` | `artifacts.markDeleted` | `ArtifactDescriptorController@markDeleted` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}/impact` | `artifacts.impact` | `ArtifactDescriptorController@impact` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}/legal-hold` | `artifacts.legalHold` | `ArtifactDescriptorController@legalHold` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}/restore` | `artifacts.restore` | `ArtifactDescriptorController@restore` | api, auth:sanctum, tenant.context |


## Asset governance and versioning

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `GET|HEAD` | `/api/admin/projects/{idProject}/asset-impact/{assetType}/{assetId}` | `assetimpact.show` | `AssetImpactController@show` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects/{idProject}/asset-versions/{assetType}/{assetId}` | `assetversions.index` | `AssetVersionController@index` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects/{idProject}/asset-versions/{assetVersion}` | `assetversions.show` | `AssetVersionController@show` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/asset-versions/{assetVersion}/review-events` | `assetversions.transitionReview` | `AssetVersionController@transitionReview` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects/{idProject}/asset-versions/{fromVersion}/diff/{toVersion}` | `assetversions.diff` | `AssetVersionController@diff` | api, auth:sanctum, tenant.context |


## Integration endpoints and deliveries

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `GET|HEAD` | `/api/admin/projects/{idProject}/integration-deliveries` | `integrations.deliveries` | `IntegrationEndpointController@deliveries` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/integration-deliveries/{integrationDelivery}/replay` | `integrations.replay` | `IntegrationEndpointController@replay` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/projects/{idProject}/integrations` | `integrations.index` | `IntegrationEndpointController@index` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/integrations` | `integrations.store` | `IntegrationEndpointController@store` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/integrations/{integrationEndpoint}/rotate-secret` | `integrations.rotateSecret` | `IntegrationEndpointController@rotateSecret` | api, auth:sanctum, tenant.context |
| `PUT` | `/api/admin/projects/{idProject}/integrations/{integrationEndpoint}/status` | `integrations.updateStatus` | `IntegrationEndpointController@updateStatus` | api, auth:sanctum, tenant.context |
| `POST` | `/api/admin/projects/{idProject}/integrations/{integrationEndpoint}/test` | `integrations.test` | `IntegrationEndpointController@test` | api, auth:sanctum, tenant.context |


## Other API routes

| Method | URI | Name | Controller action | Auth / middleware |
| --- | --- | --- | --- | --- |
| `POST` | `/api/admin/result-exports` | `result-exports.store` | `ResultExportController@store` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/result-exports/{resultExport}` | `result-exports.show` | `ResultExportController@show` | api, auth:sanctum, tenant.context |
| `GET|HEAD` | `/api/admin/result-exports/{resultExport}/download` | `result-exports.download` | `ResultExportController@download` | api, auth:sanctum, tenant.context |
