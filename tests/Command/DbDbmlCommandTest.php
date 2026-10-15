<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\ResetDatabase;

final class DbDbmlCommandTest extends KernelTestCase
{
    use ResetDatabase;

    public function testPrintsDbmlOnStdout(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Table poi {', $tester->getDisplay());
    }

    public function testWritesDbmlToTheOutputFile(): void
    {
        $file = sys_get_temp_dir().'/'.uniqid('schema_', true).'.dbml';
        $tester = $this->tester();

        $tester->execute(['--output' => $file]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Table poi {', (string) file_get_contents($file));
        unlink($file);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('app:db:dbml'));
    }
}
