<?php

namespace Tests;

use App\Jobs\ExecuteScrapeRun;
use App\Services\PublicDnsResolver;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    private ?string $isolatedStorage = null;

    private array $previousEnvironment = [];

    public function createApplication()
    {
        if ($this->isolatedStorage === null) {
            $this->isolatedStorage = sys_get_temp_dir().'/crt-crm-test-'.bin2hex(random_bytes(12));
            foreach (['app/private', 'app/public', 'app/scrapes', 'framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $directory) {
                (new Filesystem)->ensureDirectoryExists($this->isolatedStorage.'/'.$directory);
            }
            foreach (['LARAVEL_STORAGE_PATH' => $this->isolatedStorage, 'VIEW_COMPILED_PATH' => $this->isolatedStorage.'/framework/views', 'APP_CONFIG_CACHE' => $this->isolatedStorage.'/config.php'] as $key => $value) {
                $this->previousEnvironment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
                putenv($key.'='.$value);
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        $app = parent::createApplication();
        $app['config']->set(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
            'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync']);
        Http::preventStrayRequests();
        Queue::fake([ExecuteScrapeRun::class]);
        if (class_exists(PublicDnsResolver::class)) {
            $app->instance(PublicDnsResolver::class, Mockery::mock(PublicDnsResolver::class)->shouldReceive('resolve')->byDefault()->andReturn(['93.184.216.34'])->getMock());
        }

        return $app;
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->isolatedStorage !== null) {
                (new Filesystem)->deleteDirectory($this->isolatedStorage);
                $this->isolatedStorage = null;
            }
            foreach ($this->previousEnvironment as $key => [$process, $env, $server]) {
                putenv($process === false ? $key : $key.'='.$process);
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
            }
            $this->previousEnvironment = [];
        }
    }
}
