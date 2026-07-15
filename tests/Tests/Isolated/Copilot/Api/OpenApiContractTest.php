<?php

/**
 * FROZEN acceptance tests — TRO-48: the API surface is spec'd, the spec is
 * contract-tested against the implementation, and a runnable Bruno
 * collection closes the Week 1 collection debt.
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract: docs/openapi.yaml at the module root is a valid OpenAPI 3.x
 * document whose /api/copilot paths agree with the module's registered
 * routes in BOTH directions — drift fails. The route inventory is read from
 * Bootstrap.php's register('METHOD /path', ...) literals, the same
 * default-deny registrar every live route passes through (S5), so this
 * parity check runs in the isolated CI lane with no database. The Bruno
 * collection at bruno/ carries one request per route plus an environment
 * template, so the flows (upload, extraction/turn, evidence click-to-source,
 * health/readiness) are runnable end-to-end against any deployment.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class OpenApiContractTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot';

    /**
     * Registered "METHOD /api/copilot/..." specs parsed from Bootstrap.php.
     *
     * @return list<string>
     */
    private static function registeredRouteSpecs(): array
    {
        $source = file_get_contents(self::MODULE_DIR . '/src/Bootstrap.php');
        self::assertIsString($source);

        $matches = [];
        preg_match_all("/register\\(\\s*'((?:GET|POST|PUT|DELETE|PATCH) \\/api\\/copilot\\/[^']+)'/", $source, $matches);
        $specs = array_values(array_unique($matches[1]));
        self::assertNotSame([], $specs, 'route literals must be discoverable in Bootstrap.php — the registrar is the single route source (S5)');

        return $specs;
    }

    /**
     * "METHOD /path" specs declared by the OpenAPI document.
     *
     * @return array{list<string>, array<string, mixed>}
     */
    private static function specRouteSpecs(): array
    {
        $path = self::MODULE_DIR . '/docs/openapi.yaml';
        self::assertFileExists($path, 'the OpenAPI spec is a committed module artifact');
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $document = Yaml::parse($raw);
        self::assertIsArray($document);

        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);

        $specs = [];
        foreach ($paths as $route => $operations) {
            self::assertIsString($route);
            self::assertIsArray($operations);
            foreach (array_keys($operations) as $method) {
                self::assertIsString($method);
                if (in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], true)) {
                    $specs[] = strtoupper($method) . ' ' . $route;
                }
            }
        }

        return [$specs, $document];
    }

    public function testSpecIsOpenApiThree(): void
    {
        [, $document] = self::specRouteSpecs();

        $version = $document['openapi'] ?? null;
        $this->assertIsString($version);
        $this->assertStringStartsWith('3.', $version);

        $info = $document['info'] ?? null;
        $this->assertIsArray($info);
        $this->assertArrayHasKey('title', $info);
        $this->assertArrayHasKey('version', $info);
    }

    public function testEveryRegisteredRouteIsSpecified(): void
    {
        [$specRoutes] = self::specRouteSpecs();

        foreach (self::registeredRouteSpecs() as $registered) {
            $this->assertContains($registered, $specRoutes, "registered route '{$registered}' is missing from openapi.yaml — the spec drifted");
        }
    }

    public function testEverySpecifiedRouteIsRegistered(): void
    {
        [$specRoutes] = self::specRouteSpecs();
        $registered = self::registeredRouteSpecs();

        foreach ($specRoutes as $specified) {
            if (!str_contains($specified, '/api/copilot/')) {
                continue;
            }
            $this->assertContains($specified, $registered, "spec route '{$specified}' does not exist in Bootstrap.php — the spec invents surface");
        }
    }

    public function testBrunoCollectionCoversEveryRoute(): void
    {
        $brunoDir = self::MODULE_DIR . '/bruno';
        $this->assertFileExists($brunoDir . '/bruno.json', 'the collection manifest exists');

        $environments = glob($brunoDir . '/environments/*.bru');
        $this->assertIsArray($environments);
        $this->assertNotSame([], $environments, 'an environment template (base url + token placeholders) ships with the collection');

        $requestFiles = glob($brunoDir . '/*.bru');
        $this->assertIsArray($requestFiles);

        $corpus = '';
        foreach ($requestFiles as $file) {
            $raw = file_get_contents($file);
            $this->assertIsString($raw);
            $corpus .= $raw . "\n";
        }

        foreach (self::registeredRouteSpecs() as $registered) {
            [$method, $route] = explode(' ', $registered, 2);
            $this->assertStringContainsString($route, $corpus, "the Bruno collection exercises {$route}");
            $this->assertStringContainsStringIgnoringCase(strtolower($method) . ' ', $corpus, "the collection uses the {$method} verb");
        }
    }
}
