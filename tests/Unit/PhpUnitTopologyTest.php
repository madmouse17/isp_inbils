<?php

namespace Tests\Unit;

use Tests\TestCase;

class PhpUnitTopologyTest extends TestCase
{
    public function test_phpunit_configuration_discovers_root_and_module_test_trees(): void
    {
        $configuration = simplexml_load_file(base_path('phpunit.xml'));

        $this->assertNotFalse($configuration);

        $directories = [];
        foreach ($configuration->testsuites->testsuite as $suite) {
            foreach ($suite->directory as $directory) {
                $directories[] = (string) $directory;
            }
        }

        $this->assertContains('tests/Unit', $directories);
        $this->assertContains('tests/Feature', $directories);
        $this->assertContains('Modules', $directories);
    }
}
