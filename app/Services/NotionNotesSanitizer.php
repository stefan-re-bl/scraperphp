<?php

namespace App\Services;

class NotionNotesSanitizer
{
    public function containsSensitive(string $text): bool
    {
        return (bool) preg_match('/(?:contrase[nñ]a|password|passwd|\bpass\b|\bpwd\b|credencial|\btoken\b|api[ _-]?key|clave|secret|usuario|username|\blogin\b|\baccesos?\b|\buser\b|wp-admin|cpanel|ftp\b|ssh\b|private[ _-]?key|bearer\s|[a-z]+:\/\/[^\s\/]+:[^\s@]+@|[?&](?:key|auth|token|password)=|[A-Za-z0-9_+\/=-]{32,}|```)/iu', $text);
    }

    /** @return array{notes: ?string, discarded: int} */
    public function sanitize(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        if ($this->containsSensitive($markdown)) {
            return ['notes' => null, 'discarded' => 1];
        }
        $lines = explode("\n", $markdown);
        $body = [];
        $inProperties = true;
        foreach ($lines as $line) {
            if ($inProperties && (preg_match('/^#\s/', $line) || trim($line) === '' || preg_match('/^[^:#\n]{1,80}:\s*/u', $line))) {
                continue;
            }
            $inProperties = false;
            $body[] = $line;
        }
        $body = trim(implode("\n", $body));
        if ($body === '') {
            return ['notes' => null, 'discarded' => 0];
        }

        return ['notes' => $body, 'discarded' => 0];
    }
}
