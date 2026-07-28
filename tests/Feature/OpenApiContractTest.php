<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    public function test_openapi_v1_contract_is_valid_yaml_with_unique_operation_ids(): void
    {
        $contract = Yaml::parseFile(base_path('docs/api/openapi-v1.yaml'));

        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('Idelium API', $contract['info']['title']);
        $this->assertArrayHasKey('/admin/projects/{idProject}/parallel-runs/matrix', $contract['paths']);
        $this->assertArrayHasKey('/ideliumcl/agents/register', $contract['paths']);
        $this->assertArrayHasKey('/admin/projects/{idProject}/asset-impact/{assetType}/{assetId}', $contract['paths']);

        $operationIds = [];
        foreach ($contract['paths'] as $path) {
            foreach ($path as $operation) {
                if (! is_array($operation) || ! isset($operation['operationId'])) {
                    continue;
                }

                $operationIds[] = $operation['operationId'];
            }
        }

        $this->assertSame($operationIds, array_values(array_unique($operationIds)));
    }
}
