<?php

namespace Tests\Unit;

use App\Services\DockerCommandLauncher;
use PHPUnit\Framework\TestCase;

class DockerCommandLauncherTest extends TestCase
{
    public function test_existing_inactive_membership_is_refreshed_with_quoted_arguments(): void
    {
        $command = ['docker', 'run', '--rm', '-v', "/tmp/business owner's files:/data", 'gosom/google-maps-scraper:latest'];

        $result = (new DockerCommandLauncher)->forMembership($command, 'operator', ['gid' => 117, 'members' => ['operator']], [1000]);

        $this->assertSame(['/usr/bin/sg', 'docker', '-c', "'docker' 'run' '--rm' '-v' '/tmp/business owner'\\''s files:/data' 'gosom/google-maps-scraper:latest'"], $result);
    }

    public function test_active_membership_keeps_the_original_command(): void
    {
        $command = ['docker', 'run', '--rm', 'gosom/google-maps-scraper:latest'];

        $result = (new DockerCommandLauncher)->forMembership($command, 'operator', ['gid' => 117, 'members' => ['operator']], [1000, 117]);

        $this->assertSame($command, $result);
    }

    public function test_nonmember_does_not_receive_a_group_switch_command(): void
    {
        $command = ['docker', 'run', '--rm', 'gosom/google-maps-scraper:latest'];

        $result = (new DockerCommandLauncher)->forMembership($command, 'operator', ['gid' => 117, 'members' => ['someone_else']], [1000]);

        $this->assertSame($command, $result);
    }

    public function test_missing_group_and_native_executables_keep_their_original_commands(): void
    {
        $launcher = new DockerCommandLauncher;
        $docker = ['docker', 'run', '--rm', 'gosom/google-maps-scraper:latest'];
        $native = ['/opt/google-maps-scraper', '-input', '/tmp/queries.txt'];

        $this->assertSame($docker, $launcher->forMembership($docker, 'operator', null, [1000]));
        $this->assertSame($native, $launcher->forMembership($native, 'operator', ['gid' => 117, 'members' => ['operator']], [1000]));
    }
}
