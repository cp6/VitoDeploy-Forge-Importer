<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import;

class EnvironmentValues
{
    public function get(?string $content, string $key): string
    {
        if (! is_string($content) || ! preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.*)$/m', $content, $matches)) {
            return '';
        }

        $value = trim($matches[1]);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            return stripcslashes(substr($value, 1, -1));
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $value));
    }

    /** @param array<string, string|int> $values */
    public function replace(string $content, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.'='.$this->encode((string) $value);
            $pattern = '/^\s*'.preg_quote($key, '/').'\s*=.*$/m';
            if (preg_match($pattern, $content)) {
                $content = (string) preg_replace_callback($pattern, fn () => $line, $content, 1);
            } else {
                $content = rtrim($content).PHP_EOL.$line.PHP_EOL;
            }
        }

        return $content;
    }

    private function encode(string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9_.:\/@+-]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$', "\n", "\r"], ['\\\\', '\\"', '\\$', '\\n', ''], $value).'"';
    }
}
