<?php

namespace App\Services;

class DockerCommandLauncher
{
    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    public function prepare(array $command): array
    {
        if (($command[0] ?? null) !== 'docker' || PHP_OS_FAMILY !== 'Linux'
            || ! function_exists('posix_getgrnam') || ! function_exists('posix_getpwuid')
            || ! function_exists('posix_geteuid') || ! function_exists('posix_getgroups')
            || ! is_executable('/usr/bin/sg')) {
            return $command;
        }

        $user = posix_getpwuid(posix_geteuid());
        if (! $user) {
            return $command;
        }

        return $this->forMembership($command, $user['name'], posix_getgrnam('docker') ?: null, posix_getgroups() ?: []);
    }

    /**
     * Refresh an existing membership without granting access to nonmembers.
     *
     * @param  list<string>  $command
     * @param  array{gid: int, members: list<string>}|null  $dockerGroup
     * @param  list<int>  $activeGroups
     * @return list<string>
     */
    public function forMembership(array $command, string $username, ?array $dockerGroup, array $activeGroups): array
    {
        if (($command[0] ?? null) !== 'docker' || ! $dockerGroup
            || ! in_array($username, $dockerGroup['members'], true)
            || in_array($dockerGroup['gid'], $activeGroups, true)) {
            return $command;
        }

        return ['/usr/bin/sg', 'docker', '-c', implode(' ', array_map('escapeshellarg', $command))];
    }
}
